<?php

require_once(dirname(__FILE__).'/BadpoolLivePaymentLaneConfiguration.php');

/** Durable, single-flight controller for the post-boundary live Scrypt lane. */
class BadpoolLivePaymentCoordinator
{
	const SCHEMA='badpool.live_payment_coordinator.v1';
	private $root, $runner, $lane;

	public function __construct($runner, $root=null, $lane=null) {
		$this->runner=$runner;
		$this->lane=$lane?:BadpoolLivePaymentLaneRegistry::scryptCompatibility();
		if(!$this->lane instanceof BadpoolLivePaymentLaneConfiguration)throw new InvalidArgumentException('A live-payment lane configuration is required.');
		$this->root=$root?:$this->lane->runtimeRoot();
	}
	public function run() {
		if(!$this->lane->isPayoutPreparationCommissioned())return $this->report('FAIL_CLOSED',null,null,'none',array('Lane is not commissioned for payout preparation.'));
		if(!is_dir($this->root)&&!@mkdir($this->root,0770,true))return $this->report('FAIL_CLOSED',null,null,'none',array('Cannot create coordinator runtime directory.'));
		$fh=@fopen($this->lane->lockPath($this->root),'c');
		if(!$fh||!flock($fh,LOCK_EX|LOCK_NB))return $this->report('FAIL_CLOSED',null,null,'none',array('Coordinator invocation already active.'));
		try{return $this->runLocked();}finally{flock($fh,LOCK_UN);fclose($fh);}
	}
	private function runLocked() {
		$state=$this->loadState(); if($state===false)return $this->report('FAIL_CLOSED',null,null,'none',array('Coordinator state is malformed.'));
		$owned=$this->ownedLedgers();
		$id=$state?$state['active_batch_id']:null;
		if($id===null&&count($owned)>1)return $this->report('FAIL_CLOSED',null,null,'reconciled_state',array('Multiple unresolved coordinator-owned batches found.'));
		if($id===null&&count($owned)===1){$id=key($owned);$action='reconciled_state';}else $action='none';
		if($id!==null){
			// Durable state names the authoritative ledger even after it becomes terminal.
			$l=$this->readLedger($id);if(!$l)return $this->report('FAIL_CLOSED',$id,null,'reconciled_state',array('Active ledger missing or malformed.'));
			$error=$this->validate($l,$id);if($error)return $this->report('FAIL_CLOSED',$id,$l,'reconciled_state',array($error));
			foreach($owned as $ownedId=>$unused)if($ownedId!==$id)return $this->report('FAIL_CLOSED',$id,$l,'reconciled_state',array('Another unresolved coordinator-owned batch exists.'));
			$c=$this->classify($l);
			if($c==='READY_FOR_NEW_BATCH'){$this->saveState(null,$l,$c,$id);return $this->report($c,null,$l,'reconciled_state',array());}
			elseif($c==='WAITING_PAYMENT_DELAY'){$before=$l['selected_earning_ids'];$r=$this->runner->run(array('mode'=>'auto','scope'=>'all-active-payout-coins','only'=>$this->lane->operationalAlgo(),'batch_size'=>$this->lane->batchLimit(),'resume_batch_id'=>$id,'lane_configuration'=>$this->lane));$l=$this->readLedger($id);if(!$l||$before!==$l['selected_earning_ids'])return $this->report('FAIL_CLOSED',$id,$l,'resumed_batch',array('Resume changed durable earning scope.'));$action='resumed_batch';$c=$this->classify($l);}
			$this->saveState($id,$l,$c,null);return $this->report($c,$id,$l,$action,array());
		}
		$r=$this->runner->run(array('mode'=>'auto','scope'=>'all-active-payout-coins','only'=>$this->lane->operationalAlgo(),'batch_size'=>$this->lane->batchLimit(),'coordinator_owner'=>$this->lane->ownershipEnvelope(),'lane_configuration'=>$this->lane));
		$id=isset($r['batch_id'])?$r['batch_id']:null;$l=$id?$this->readLedger($id):null;
		if(!$l)return $this->report('FAIL_CLOSED',$id,null,'created_batch',array('Runner did not create a valid ledger.'));
		if(count($l['selected_earning_ids'])===0){if(!$this->removeEmpty($id,$l))return $this->report('FAIL_CLOSED',$id,$l,'created_batch',array('Provably empty coordinator batch could not be safely removed.'));$this->saveState(null,null,'IDLE',null);return $this->report('IDLE',null,null,'none',array());}
		$error=$this->validate($l,$id);if($error)return $this->report('FAIL_CLOSED',$id,$l,'created_batch',array($error));
		$c=$this->classify($l);$this->saveState($id,$l,$c,null);return $this->report($c,$id,$l,'created_batch',array());
	}
	private function classify($l){$s=$l['batch_state'];if($s==='WAITING_PAYMENT_DELAY')return 'WAITING_PAYMENT_DELAY';if($s==='READY_FOR_WALLET_APPROVAL')return 'HUMAN_WALLET_APPROVAL_REQUIRED';if($s==='HOLD_COMPLETED_PAYOUT_RECONCILIATION')return 'COMPLETED_PAYOUT_RECONCILIATION_REQUIRED';if($s==='RECONCILED')return 'READY_FOR_NEW_BATCH';return 'FAIL_CLOSED';}
	private function validate($l,$id){
		if(!preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/',$id)||$l['batch_id']!==$id)return 'Invalid or mismatched batch ID.';
		$o=isset($l['coordinator_owner'])?$l['coordinator_owner']:array();if(!$this->lane->ownershipMatches($o))return 'Coordinator ownership contract mismatch.';
		if(@$l['mode']!=='auto'||@$l['only']!==$this->lane->operationalAlgo()||!is_int($l['batch_size'])||$l['batch_size']<1||$l['batch_size']>$this->lane->batchLimit()||@$l['stop_before_wallet_send']!==true)return 'Batch execution contract mismatch.';
		$coins=array();if(!is_array(@$l['selected_coin_scope']))return 'Selected coin scope is malformed.';foreach($l['selected_coin_scope'] as $coin){if(!is_array($coin)||intval(isset($coin['id'])?$coin['id']:0)!==$this->lane->coinId()||strtolower((string)@$coin['algo'])!==$this->lane->dbAlgo())return 'Selected coin scope is not limited to the configured lane.';$coins[]=$this->lane->coinId();}if(count($coins)!==1)return 'Selected coin scope must contain exactly the configured coin.';
		foreach(array('selected_earning_ids','selected_block_ids','selected_account_ids') as $k)if($this->ids(@$l[$k])===false)return 'Invalid or duplicate '.$k.'.';foreach($l['selected_block_ids'] as $blockId)if((int)$blockId<=$this->lane->blockBoundary())return 'Selected block violates the live boundary.';return null;
	}
	private function ids($v){if(!is_array($v))return false;$out=array();foreach($v as $x){if(!(is_int($x)&&$x>0)&&!(is_string($x)&&preg_match('/^[1-9][0-9]*$/',$x)))return false;$x=(int)$x;if(isset($out[$x]))return false;$out[$x]=1;}return array_keys($out);}
	private function ownedLedgers(){ $out=array();foreach((array)glob($this->root.'/*/ledger.json') as $p){$l=json_decode(@file_get_contents($p),true);if(!is_array($l)||!isset($l['batch_id']))continue;$o=isset($l['coordinator_owner'])?$l['coordinator_owner']:null;if(is_array($o)&&@$o['lane']===$this->lane->laneId()&&@$l['batch_state']!=='RECONCILED')$out[$l['batch_id']]=$l;}return $out; }
	private function readLedger($id){$p=$this->root.'/'.$id.'/ledger.json';$l=is_file($p)?json_decode(file_get_contents($p),true):null;return is_array($l)?$l:null;}
	private function loadState(){$p=$this->lane->statePath($this->root);if(!is_file($p))return null;$s=json_decode(file_get_contents($p),true);return is_array($s)&&array_key_exists('active_batch_id',$s)&&@$s['schema']===$this->lane->get('ownership_schema')&&@$s['version']===1&&@$s['lane']===$this->lane->laneId()&&@$s['coin_id']===$this->lane->coinId()&&@$s['algo']===$this->lane->dbAlgo()&&@$s['block_id_gt']===$this->lane->blockBoundary()?$s:false;}
	private function saveState($id,$l,$c,$terminal){$old=$this->loadState();if($terminal===null&&is_array($old))$terminal=isset($old['last_terminal_reconciled_batch_id'])?$old['last_terminal_reconciled_batch_id']:null;$s=array('schema'=>$this->lane->get('ownership_schema'),'version'=>1,'lane'=>$this->lane->laneId(),'coin_id'=>$this->lane->coinId(),'algo'=>$this->lane->dbAlgo(),'block_id_gt'=>$this->lane->blockBoundary(),'active_batch_id'=>$id,'last_observed_batch_state'=>$l?@$l['batch_state']:null,'last_observed_phase'=>$l?@$l['current_phase']:null,'last_transition_at'=>gmdate('c'),'human_wallet_approval_required'=>$c==='HUMAN_WALLET_APPROVAL_REQUIRED','last_terminal_reconciled_batch_id'=>$terminal);$p=$this->lane->statePath($this->root);$t=$p.'.tmp.'.getmypid();file_put_contents($t,json_encode($s,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n",LOCK_EX);rename($t,$p);}
	private function removeEmpty($id,$l){
		$o=isset($l['coordinator_owner'])?$l['coordinator_owner']:array();if(!$this->lane->ownershipMatches($o)||@$l['batch_id']!==$id)return false;
		foreach(array('selected_earning_ids','selected_block_ids','selected_account_ids','created_payout_ids','payment_delay_qualified_earning_ids') as $key)if(!empty($l[$key]))return false;
		$root=realpath($this->root);$dir=realpath($this->root.'/'.$id);if($root===false||$dir===false||dirname($dir)!==$root||is_link($dir))return false;
		$items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
		foreach($items as $item)if($item->isLink())return false;
		foreach($items as $item){$p=$item->getPathname();if($item->isDir()){if(!@rmdir($p))return false;}elseif(!@unlink($p))return false;}
		return @rmdir($dir);
	}
	private function report($c,$id,$l,$action,$errors){$human=$c==='HUMAN_WALLET_APPROVAL_REQUIRED';return array('schema'=>$this->lane->get('ownership_schema'),'command'=>'live-payment-coordinator','status'=>$c==='IDLE'||$c==='READY_FOR_NEW_BATCH'?'pass':($c==='FAIL_CLOSED'?'fail':'hold'),'classification'=>$c,'lane'=>$this->lane->laneId(),'coordinator_state'=>$this->lane->statePath($this->root),'active_batch_id'=>$id,'batch_state'=>$l?@$l['batch_state']:null,'current_phase'=>$l?@$l['current_phase']:null,'selected_earning_count'=>$l?count((array)@$l['selected_earning_ids']):0,'selected_account_count'=>$l?count((array)@$l['selected_account_ids']):0,'created_payout_ids'=>$l?(array)@$l['created_payout_ids']:array(),'action_taken'=>$action,'human_action_required'=>$human||$c==='COMPLETED_PAYOUT_RECONCILIATION_REQUIRED','next_safe_action'=>$human?'human_wallet_approval':($c==='COMPLETED_PAYOUT_RECONCILIATION_REQUIRED'?'read_only_wallet_proof_closeout':($c==='WAITING_PAYMENT_DELAY'?'wait_then_resume':'none')),'wallet_boundary'=>'blocked_human_required','db_mutations_by_coordinator'=>false,'wallet_rpc_used'=>false,'wallet_send_performed'=>false,'errors'=>$errors,'warnings'=>array());}
}
