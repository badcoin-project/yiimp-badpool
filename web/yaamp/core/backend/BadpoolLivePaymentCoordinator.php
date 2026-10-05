<?php

require_once(dirname(__FILE__).'/BadpoolLivePaymentLaneConfiguration.php');

/** Durable, per-lane, single-flight controller for queued live payment batches. */
class BadpoolLivePaymentCoordinator
{
	const SCHEMA='badpool.live_payment_coordinator.v1';
	private $root, $runner, $lane;

	public function __construct($runner, $root=null, $lane=null) {
		$this->runner=$runner;
		$this->lane=$lane?:BadpoolLivePaymentLaneRegistry::scryptCompatibility();
		if(!$this->lane instanceof BadpoolLivePaymentLaneConfiguration)throw new InvalidArgumentException('A live-payment lane configuration is required.');
		$this->root=rtrim($root?:$this->lane->runtimeRoot(),'/\\');
	}
	public function run() {
		if(!$this->lane->isPayoutPreparationCommissioned())return $this->report('FAIL_CLOSED',null,null,'none',false,false,array('Lane is not commissioned for payout preparation.'),$this->emptyQueue());
		if(!is_dir($this->root)&&!@mkdir($this->root,0770,true))return $this->report('FAIL_CLOSED',null,null,'none',false,false,array('Cannot create coordinator runtime directory.'),$this->emptyQueue());
		$fh=@fopen($this->lane->lockPath($this->root),'c');
		if(!$fh||!flock($fh,LOCK_EX|LOCK_NB))return $this->report('FAIL_CLOSED',null,null,'none',false,false,array('Coordinator invocation already active.'),$this->emptyQueue());
		try{return $this->runLocked();}finally{flock($fh,LOCK_UN);fclose($fh);}
	}
	private function runLocked() {
		$state=$this->loadState();if($state===false)return $this->report('FAIL_CLOSED',null,null,'none',false,false,array('Coordinator state is malformed.'),$this->emptyQueue());
		$scan=$this->scanLedgers();if($scan['errors'])return $this->report('FAIL_CLOSED',null,null,'ledger_scan',false,false,$scan['errors'],$scan['queue']);
		if(is_array($state)&&$state['active_batch_id']!==null&&!isset($scan['all'][$state['active_batch_id']]))return $this->report('FAIL_CLOSED',$state['active_batch_id'],null,'state_reconstruction',false,false,array('Cached active ledger is missing or is not owned by this lane.'),$scan['queue']);
		if($scan['blocking']){$this->saveState(key($scan['blocking']),$scan['queue'],'blocked_by_exceptional_batch',$state);return $this->report('FAIL_CLOSED',key($scan['blocking']),current($scan['blocking']),'blocked_by_exceptional_batch',false,false,array('Unresolved coordinator batch is not safely parkable: '.key($scan['blocking']).' ('.(string)@current($scan['blocking'])['batch_state'].').'),$scan['queue']);}

		$resumed=false;$created=false;$action=array();$focusId=null;$focusLedger=null;
		if($scan['waiting']){
			$id=key($scan['waiting']);$before=$this->scope($scan['waiting'][$id]);
			$this->saveState($id,$scan['queue'],'resuming_waiting_batch',$state);
			$this->runner->run(array('mode'=>'auto','scope'=>'all-active-payout-coins','only'=>$this->lane->operationalAlgo(),'batch_size'=>$this->lane->batchLimit(),'resume_batch_id'=>$id,'lane_configuration'=>$this->lane));
			$l=$this->readLedger($id);$error=$l?$this->validate($l,$id):'Resumed ledger is missing or malformed.';
			if($error||$before!==$this->scope($l))return $this->report('FAIL_CLOSED',$id,$l,'resumed_batch',false,true,array($error?:'Resume changed durable earning, block, or account scope.'),$scan['queue']);
			$resumed=true;$action[]='resumed_oldest_waiting_batch';$focusId=$id;$focusLedger=$l;
			$scan=$this->scanLedgers();if($scan['blocking']&&!$scan['errors'])$this->saveState(key($scan['blocking']),$scan['queue'],'blocked_by_exceptional_batch',$state);if($scan['errors']||$scan['blocking'])return $this->report('FAIL_CLOSED',$id,$l,'resumed_batch',false,true,$scan['errors']?:array('Resumed batch entered a lane-blocking state.'),$scan['queue']);
		}

		$excluded=$this->claimedScope($scan['unresolved']);
		$this->saveState(null,$scan['queue'],'creating_fresh_batch',$state);
		$r=$this->runner->run(array('mode'=>'auto','scope'=>'all-active-payout-coins','only'=>$this->lane->operationalAlgo(),'batch_size'=>$this->lane->batchLimit(),'coordinator_owner'=>$this->lane->ownershipEnvelope(),'lane_configuration'=>$this->lane,'excluded_earning_ids'=>$excluded['earnings'],'excluded_block_ids'=>$excluded['blocks']));
		$id=isset($r['batch_id'])?$r['batch_id']:null;$l=$id?$this->readLedger($id):null;
		if(!$l)return $this->report('FAIL_CLOSED',$id,null,'created_batch',false,$resumed,array('Runner did not create a valid ledger.'),$scan['queue']);
		if(count((array)@$l['selected_earning_ids'])===0){
			if(!$this->removeEmpty($id,$l))return $this->report('FAIL_CLOSED',$id,$l,'created_batch',false,$resumed,array('Provably empty coordinator batch could not be safely removed.'),$scan['queue']);
			$action[]='no_unclaimed_eligible_work';$focusId=$resumed?$focusId:null;$focusLedger=$resumed?$focusLedger:null;
		}else{
			$error=$this->validate($l,$id);if($error)return $this->report('FAIL_CLOSED',$id,$l,'created_batch',false,$resumed,array($error),$scan['queue']);
			if($this->overlaps($l,$excluded))return $this->report('FAIL_CLOSED',$id,$l,'created_batch',false,$resumed,array('Fresh batch overlaps durable unresolved earning or block scope.'),$scan['queue']);
			$created=true;$action[]='created_fresh_batch';$focusId=$id;$focusLedger=$l;
		}
		$scan=$this->scanLedgers();if($scan['blocking']&&!$scan['errors'])$this->saveState(key($scan['blocking']),$scan['queue'],'blocked_by_exceptional_batch',$state);if($scan['errors']||$scan['blocking'])return $this->report('FAIL_CLOSED',$focusId,$focusLedger,implode('+',$action),$created,$resumed,$scan['errors']?:array('Fresh batch entered a lane-blocking state.'),$scan['queue']);
		$this->saveState(null,$scan['queue'],implode('+',$action),$state);
		$classification=$this->queueClassification($scan['queue'],$focusLedger);
		return $this->report($classification,null,$focusLedger,implode('+',$action),$created,$resumed,array(),$scan['queue']);
	}
	private function scanLedgers(){
		$all=array();$waiting=array();$ready=array();$reconciliation=array();$reconciled=array();$blocking=array();$errors=array();
		foreach((array)glob($this->root.'/*/ledger.json') as$p){
			$l=json_decode(@file_get_contents($p),true);if(!is_array($l))continue;
			$o=isset($l['coordinator_owner'])?$l['coordinator_owner']:null;if(!is_array($o)||@$o['lane']!==$this->lane->laneId())continue;
			$id=basename(dirname($p));$error=$this->validate($l,$id);if($error){$errors[]=$id.': '.$error;continue;}$all[$id]=$l;
			switch($l['batch_state']){case'WAITING_PAYMENT_DELAY':$waiting[$id]=$l;break;case'READY_FOR_WALLET_APPROVAL':$ready[$id]=$l;break;case'HOLD_COMPLETED_PAYOUT_RECONCILIATION':$reconciliation[$id]=$l;break;case'RECONCILED':$reconciled[$id]=$l;break;default:$blocking[$id]=$l;}
		}
		foreach(array(&$all,&$waiting,&$ready,&$reconciliation,&$reconciled,&$blocking)as&$set)ksort($set,SORT_STRING);unset($set);
		$unresolved=$waiting+$ready+$reconciliation+$blocking;$errors=array_merge($errors,$this->duplicateScopeErrors($unresolved));
		$queue=$this->queue($waiting,$ready,$reconciliation,$reconciled);
		return array('all'=>$all,'waiting'=>$waiting,'ready'=>$ready,'reconciliation'=>$reconciliation,'reconciled'=>$reconciled,'blocking'=>$blocking,'unresolved'=>$unresolved,'errors'=>$errors,'queue'=>$queue);
	}
	private function validate($l,$id){
		if(!preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/',(string)$id)||@$l['batch_id']!==$id)return'Invalid or mismatched batch ID.';
		$o=isset($l['coordinator_owner'])?$l['coordinator_owner']:array();if(!$this->lane->ownershipMatches($o))return'Coordinator ownership contract mismatch.';
		if(@$l['mode']!=='auto'||@$l['only']!==$this->lane->operationalAlgo()||!is_int(@$l['batch_size'])||$l['batch_size']<1||$l['batch_size']>$this->lane->batchLimit()||@$l['stop_before_wallet_send']!==true)return'Batch execution contract mismatch.';
		if(!is_array(@$l['selected_coin_scope']))return'Selected coin scope is malformed.';$coins=array();foreach($l['selected_coin_scope']as$coin){if(!is_array($coin)||intval(isset($coin['id'])?$coin['id']:0)!==$this->lane->coinId()||strtolower((string)@$coin['algo'])!==$this->lane->dbAlgo())return'Selected coin scope is not limited to the configured lane.';$coins[]=$this->lane->coinId();}if(count($coins)!==1)return'Selected coin scope must contain exactly the configured coin.';
		foreach(array('selected_earning_ids','selected_block_ids','selected_account_ids','created_payout_ids')as$k)if($this->ids(isset($l[$k])?$l[$k]:array())===false)return'Invalid or duplicate '.$k.'.';foreach($l['selected_block_ids']as$blockId)if((int)$blockId<=$this->lane->blockBoundary())return'Selected block violates the live boundary.';return null;
	}
	private function duplicateScopeErrors($ledgers){$seenE=array();$seenB=array();$seenP=array();$errors=array();foreach($ledgers as$id=>$l){foreach($l['selected_earning_ids']as$v){$v=(int)$v;if(isset($seenE[$v]))$errors[]='Earning '.$v.' is claimed by both '.$seenE[$v].' and '.$id.'.';else$seenE[$v]=$id;}foreach($l['selected_block_ids']as$v){$v=(int)$v;if(isset($seenB[$v]))$errors[]='Block '.$v.' is claimed by both '.$seenB[$v].' and '.$id.'.';else$seenB[$v]=$id;}foreach($l['created_payout_ids']as$v){$v=(int)$v;if(isset($seenP[$v]))$errors[]='Payout '.$v.' is claimed by both '.$seenP[$v].' and '.$id.'.';else$seenP[$v]=$id;}}return$errors;}
	private function claimedScope($ledgers){$e=array();$b=array();foreach($ledgers as$l){foreach($l['selected_earning_ids']as$id)$e[(int)$id]=1;foreach($l['selected_block_ids']as$id)$b[(int)$id]=1;}ksort($e,SORT_NUMERIC);ksort($b,SORT_NUMERIC);return array('earnings'=>array_keys($e),'blocks'=>array_keys($b));}
	private function overlaps($l,$scope){return(bool)array_intersect(array_map('intval',$l['selected_earning_ids']),$scope['earnings'])||(bool)array_intersect(array_map('intval',$l['selected_block_ids']),$scope['blocks']);}
	private function scope($l){return is_array($l)?array('earnings'=>$this->ids(@$l['selected_earning_ids']),'blocks'=>$this->ids(@$l['selected_block_ids']),'accounts'=>$this->ids(@$l['selected_account_ids'])):null;}
	private function ids($v){if(!is_array($v))return false;$out=array();foreach($v as$x){if(!(is_int($x)&&$x>0)&&!(is_string($x)&&preg_match('/^[1-9][0-9]*$/',$x)))return false;$x=(int)$x;if(isset($out[$x]))return false;$out[$x]=1;}return array_keys($out);}
	private function readLedger($id){$p=$this->root.'/'.$id.'/ledger.json';$l=is_file($p)?json_decode(file_get_contents($p),true):null;return is_array($l)?$l:null;}
	private function loadState(){$p=$this->lane->statePath($this->root);if(!is_file($p))return null;$s=json_decode(file_get_contents($p),true);if(!is_array($s)||!array_key_exists('active_batch_id',$s)||@$s['schema']!==$this->lane->get('ownership_schema')||!in_array(intval(@$s['version']),array(1,2),true)||@$s['lane']!==$this->lane->laneId()||intval(@$s['coin_id'])!==$this->lane->coinId()||@$s['algo']!==$this->lane->dbAlgo()||intval(@$s['block_id_gt'])!==$this->lane->blockBoundary())return false;if($s['active_batch_id']!==null&&(!is_string($s['active_batch_id'])||!preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/',$s['active_batch_id'])))return false;return$s;}
	private function saveState($active,$queue,$action,$old){$terminal=$queue['last_terminal_reconciled_batch_id'];$previous=is_array($old)&&isset($old['last_terminal_reconciled_batch_id'])?$old['last_terminal_reconciled_batch_id']:null;if(is_string($previous)&&($terminal===null||strcmp($previous,$terminal)>0))$terminal=$previous;$s=array('schema'=>$this->lane->get('ownership_schema'),'version'=>2,'lane'=>$this->lane->laneId(),'coin_id'=>$this->lane->coinId(),'algo'=>$this->lane->dbAlgo(),'block_id_gt'=>$this->lane->blockBoundary(),'active_batch_id'=>$active,'waiting_payment_delay_batch_ids'=>$queue['waiting_payment_delay_batch_ids'],'ready_for_wallet_approval_batch_ids'=>$queue['ready_for_wallet_approval_batch_ids'],'reconciliation_required_batch_ids'=>$queue['reconciliation_required_batch_ids'],'last_terminal_reconciled_batch_id'=>$terminal,'last_action'=>$action,'last_transition_at'=>gmdate('c'),'human_wallet_approval_required'=>$active===null&&count($queue['ready_for_wallet_approval_batch_ids'])>0,'blocking_batch_id'=>$active,'next_safe_action'=>$active!==null?'investigate_hold':(count($queue['ready_for_wallet_approval_batch_ids'])>0?'human_wallet_approval':'none'));$p=$this->lane->statePath($this->root);$t=$p.'.tmp.'.getmypid();file_put_contents($t,json_encode($s,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n",LOCK_EX);rename($t,$p);}
	private function queue($waiting,$ready,$reconciliation,$reconciled){$payouts=array();foreach($ready as$id=>$l)$payouts[$id]=array_values($l['created_payout_ids']);$reconciliationPayouts=array();foreach($reconciliation as$id=>$l)$reconciliationPayouts[$id]=array_values($l['created_payout_ids']);$terminalIds=array_keys($reconciled);$last=$terminalIds?$terminalIds[count($terminalIds)-1]:null;return array('waiting_payment_delay_batch_ids'=>array_keys($waiting),'ready_for_wallet_approval_batch_ids'=>array_keys($ready),'ready_for_wallet_approval_payout_ids_by_batch'=>$payouts,'reconciliation_required_batch_ids'=>array_keys($reconciliation),'reconciliation_required_payout_ids_by_batch'=>$reconciliationPayouts,'last_terminal_reconciled_batch_id'=>$last);}
	private function emptyQueue(){return array('waiting_payment_delay_batch_ids'=>array(),'ready_for_wallet_approval_batch_ids'=>array(),'ready_for_wallet_approval_payout_ids_by_batch'=>array(),'reconciliation_required_batch_ids'=>array(),'reconciliation_required_payout_ids_by_batch'=>array(),'last_terminal_reconciled_batch_id'=>null);}
	private function queueClassification($q,$focus){if(is_array($focus)){if(@$focus['batch_state']==='READY_FOR_WALLET_APPROVAL')return'HUMAN_WALLET_APPROVAL_REQUIRED';if(@$focus['batch_state']==='WAITING_PAYMENT_DELAY')return'WAITING_PAYMENT_DELAY';if(@$focus['batch_state']==='HOLD_COMPLETED_PAYOUT_RECONCILIATION')return'COMPLETED_PAYOUT_RECONCILIATION_REQUIRED';}if($q['waiting_payment_delay_batch_ids'])return'WAITING_PAYMENT_DELAY';if($q['ready_for_wallet_approval_batch_ids'])return'HUMAN_WALLET_APPROVAL_REQUIRED';if($q['reconciliation_required_batch_ids'])return'COMPLETED_PAYOUT_RECONCILIATION_REQUIRED';return'IDLE';}
	private function removeEmpty($id,$l){$o=isset($l['coordinator_owner'])?$l['coordinator_owner']:array();if(!$this->lane->ownershipMatches($o)||@$l['batch_id']!==$id)return false;foreach(array('selected_earning_ids','selected_block_ids','selected_account_ids','created_payout_ids','payment_delay_qualified_earning_ids')as$key)if(!empty($l[$key]))return false;$root=realpath($this->root);$dir=realpath($this->root.'/'.$id);if($root===false||$dir===false||dirname($dir)!==$root||is_link($dir))return false;$items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($items as$item)if($item->isLink())return false;foreach($items as$item){$p=$item->getPathname();if($item->isDir()){if(!@rmdir($p))return false;}elseif(!@unlink($p))return false;}return@rmdir($dir);}
	private function report($c,$id,$l,$action,$created,$resumed,$errors,$q){$human=count($q['ready_for_wallet_approval_batch_ids'])>0;$reconciliation=count($q['reconciliation_required_batch_ids'])>0;$runnerInvoked=$created||$resumed||$action==='created_batch'||strpos((string)$action,'no_unclaimed_eligible_work')!==false;return array('schema'=>$this->lane->get('ownership_schema'),'command'=>'live-payment-coordinator','status'=>$c==='IDLE'?'pass':($c==='FAIL_CLOSED'?'fail':'hold'),'classification'=>$c,'lane'=>$this->lane->laneId(),'coordinator_state'=>$this->lane->statePath($this->root),'active_batch_id'=>$id,'batch_state'=>$l?@$l['batch_state']:null,'current_phase'=>$l?@$l['current_phase']:null,'selected_earning_count'=>$l?count((array)@$l['selected_earning_ids']):0,'selected_account_count'=>$l?count((array)@$l['selected_account_ids']):0,'created_payout_ids'=>$l?(array)@$l['created_payout_ids']:array(),'waiting_payment_delay_count'=>count($q['waiting_payment_delay_batch_ids']),'waiting_payment_delay_batch_ids'=>$q['waiting_payment_delay_batch_ids'],'ready_for_wallet_approval_count'=>count($q['ready_for_wallet_approval_batch_ids']),'ready_for_wallet_approval_batch_ids'=>$q['ready_for_wallet_approval_batch_ids'],'ready_for_wallet_approval_payout_ids_by_batch'=>$q['ready_for_wallet_approval_payout_ids_by_batch'],'reconciliation_required_count'=>count($q['reconciliation_required_batch_ids']),'reconciliation_required_batch_ids'=>$q['reconciliation_required_batch_ids'],'reconciliation_required_payout_ids_by_batch'=>$q['reconciliation_required_payout_ids_by_batch'],'last_terminal_reconciled_batch_id'=>$q['last_terminal_reconciled_batch_id'],'action_taken'=>$action,'fresh_batch_created'=>$created,'existing_batch_resumed'=>$resumed,'human_action_required'=>$c==='FAIL_CLOSED'||$human||$reconciliation,'next_safe_action'=>$c==='FAIL_CLOSED'?'investigate_hold':($reconciliation?'read_only_wallet_proof_closeout':($human?'human_wallet_approval':($q['waiting_payment_delay_batch_ids']?'wait_then_resume':'none'))),'wallet_boundary'=>'blocked_human_required','db_mutations_by_coordinator'=>false,'mutation_capable_runner_invoked'=>$runnerInvoked,'wallet_rpc_used'=>false,'wallet_send_performed'=>false,'errors'=>$errors,'warnings'=>array());}
}
