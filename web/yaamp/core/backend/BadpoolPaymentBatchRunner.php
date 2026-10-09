<?php

require_once(dirname(__FILE__).'/BadpoolLivePaymentLaneConfiguration.php');

/**
 * Phase 0-6 payment coordinator.  Mutation-capable operations are deliberately
 * supplied by an adapter; the coordinator owns persistence, ordering and the
 * hard wallet boundary.
 */
class BadpoolPaymentBatchRunner
{
	const SCHEMA = 'badpool.payment_batch.run.v1';
	private $adapter;
	private $root;

	public function __construct($adapter, $root=null)
	{
		$this->adapter = $adapter;
		$this->root = $root ?: dirname(__FILE__).'/../../../../runtime/badpool-payment-batches';
	}

	public function run($options)
	{
		$resume=isset($options['resume_batch_id'])?$options['resume_batch_id']:null;
		if(!$resume)return $this->runUnlocked($options);
		if(!preg_match('/^[A-Za-z0-9._-]+$/',$resume)||!is_dir($this->root.'/'.$resume))return $this->refusal($options,$resume,'Batch directory is missing or invalid.');
		$lockPath=$this->root.'/'.$resume.'/resume.lock';if(is_link($lockPath))return $this->refusal($options,$resume,'Unsafe batch lock.');
		$lock=@fopen($lockPath,'c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);return $this->refusal($options,$resume,'Batch resume is already active.');}
		try{return $this->runUnlocked($options);}finally{flock($lock,LOCK_UN);fclose($lock);}
	}

	private function runUnlocked($options)
	{
		$now = gmdate('c');
		$resume = isset($options['resume_batch_id']) ? $options['resume_batch_id'] : null;
		if ($resume) {
			$ledger = $this->load($resume);
			if (!$ledger) return $this->refusal($options, $resume, 'Batch ledger was not found or is invalid.');
			// Owned wallet-boundary batches never enter the financial phase loop.
			$owned=array_key_exists('coordinator_owner',$ledger);
			$boundary=in_array($this->scalarValue($ledger,'batch_state'),array('READY_FOR_WALLET_APPROVAL','HOLD_COMPLETED_PAYOUT_RECONCILIATION','RECONCILED'),true)
				||!empty($ledger['created_payout_ids']);
			if(($boundary&&($owned||!empty($options['require_owned_wallet_boundary'])))||!empty($options['completed_payout_resume']))return $this->resumeCompletedPayout($ledger,$options);
			if($owned){
				$lane=(new BadpoolLivePaymentLaneRegistry())->fromOwnershipEnvelope($ledger['coordinator_owner']);
				if(!$lane||!$this->resumeLaneMatches($lane,$options))return $this->refusal($options,$resume,'Resume lane does not match commissioned durable ownership.');
			}
			// The durable ledger, rather than new CLI defaults, is authoritative on resume.
			foreach (array('mode','scope','only','batch_size') as $key) $options[$key]=$ledger[$key];
			// A phase-6 recovery must never walk backwards into account credit.
			if(intval($ledger['current_phase'])===6){
				for($phase=0;$phase<=5;$phase++)if(!$this->phasePassed($ledger,$phase))return $this->refusal($options,$resume,'Phase-6 recovery requires durable successful preceding phases and account-credit evidence; investigate without replaying credit.');
			}
		} else {
			$id = gmdate('Ymd\THis\Z').'-'.substr(hash('sha256', uniqid('', true)), 0, 12);
			$dir = $this->root.'/'.$id;
			$ledger = array('batch_id'=>$id, 'created_at'=>$now, 'updated_at'=>$now, 'command'=>'batch-run',
				'mode'=>$options['mode'], 'scope'=>$options['scope'], 'only'=>$options['only'],
				'batch_size'=>$options['batch_size'], 'stop_before_wallet_send'=>true,
				'current_phase'=>0, 'batch_state'=>'PREVIEWED', 'run_directory'=>$dir,
				'selected_coin_scope'=>array(), 'selected_earning_ids'=>array(), 'selected_block_ids'=>array(),
				'selected_account_ids'=>array(), 'selected_accounts_by_coin'=>array(), 'created_payout_ids'=>array(), 'selected_work_by_coin'=>array(), 'payment_delay_qualified_earning_ids'=>array(), 'approval_package_paths'=>array(),
				'dryrun_report_paths'=>array(), 'checksums'=>array(), 'phase_results'=>array(), 'warnings'=>array(), 'errors'=>array());
			if (isset($options['coordinator_owner'])) $ledger['coordinator_owner']=$options['coordinator_owner'];
			if (!is_dir($dir) && !@mkdir($dir, 0770, true)) return $this->refusal($options, $id, 'Unable to create batch run directory.');
			$this->save($ledger);
		}

		$phases = array(0=>'Safety Check',1=>'Select Eligible Work',2=>'Package Intent',3=>'Mature Earnings',4=>'Payment Delay Check',5=>'Credit Accounts',6=>'Prepare Payout Rows');
		foreach ($phases as $number=>$name) {
			if ($this->phasePassed($ledger, $number)) continue;
			$started = gmdate('c');
			$result = $this->invoke($number, $ledger, $options);
			if (!is_array($result)) $result = array('status'=>'fail', 'errors'=>array('Adapter returned a non-object result.'));
			$status = isset($result['status']) ? strtolower((string)$result['status']) : 'fail';
			if (!in_array($status, array('ok','pass','hold','refused','fail'), true)) $status = 'fail';
			if ($number === 6 && in_array($status, array('ok','pass'), true) && !$this->validPhaseSixPayoutIds($ledger, $result)) {
				$status = 'hold';
				$result['status'] = 'hold';
				$result['created_payout_ids'] = array();
				$result['warnings'][] = 'Phase 6 refused readiness because payout rows were inserted without one positive created payout ID per inserted row.';
			}
			$entry = array('phase_number'=>$number, 'phase_name'=>$name, 'status'=>$status,
				'mutation_scope'=>$this->arrayValue($result, 'mutation_scope'), 'report_path'=>$this->scalarValue($result, 'report_path'),
				'package_path'=>$this->scalarValue($result, 'package_path'), 'checksum_summary'=>$this->arrayValue($result, 'checksums'),
				'started_at'=>$started, 'finished_at'=>gmdate('c'), 'failure_evidence'=>$this->arrayValue($result,'failure_evidence'));
			$ledger['phase_results'][] = $entry;
			$this->mergeResult($ledger, $result);
			$ledger['current_phase'] = $number;
			$states=array(0=>'SAFETY_CHECKED',1=>'WORK_SELECTED',2=>'MATURITY_PACKAGED',3=>'MATURITY_APPLIED',4=>'MATURITY_APPLIED',5=>'ACCOUNT_CREDIT_APPLIED',6=>'READY_FOR_WALLET_APPROVAL');
			$ledger['batch_state']=$states[$number];
			if ($status === 'hold') $ledger['batch_state']=$number === 4 ? 'WAITING_PAYMENT_DELAY' : 'HOLD';
			if ($status === 'fail' || $status === 'refused') $ledger['batch_state']=$status === 'fail' ? 'FAIL' : 'HOLD';
			$this->save($ledger);
			if (!in_array($status, array('ok','pass'), true)) break;
		}
		$this->enforceCompletedPayoutBoundary($ledger);
		return $this->report($ledger);
	}

	/** The same completed-row boundary, with exact registry coin binding for owned resumes. */
	private function enforceCompletedPayoutBoundary(&$ledger, $lane=null)
	{
		$ids=$this->positiveIdList($this->arrayValue($ledger,'created_payout_ids'));
		if($ids===null||$ids===array()||!is_object($this->adapter)||!is_callable(array($this->adapter,'inspectCreatedPayoutRows')))return;
		$rows=call_user_func(array($this->adapter,'inspectCreatedPayoutRows'),$ids);
		if(!is_array($rows)||count($rows)!==count($ids))return;
		$completed=array();
		foreach($rows as $row){
			if($lane){
				if(!is_array($row)||!isset($row['id'],$row['idcoin'],$row['completed'],$row['tx']))return false;
				if($this->positiveIdList(array($row['id']))===null||$this->positiveIdList(array($row['idcoin']))!==array($lane->coinId()))return false;
				if(!in_array($row['completed'],array(1,'1'),true)||!is_string($row['tx']))return false;
			}
			$id=isset($row['id'])?intval($row['id']):0;$tx=trim((string)(isset($row['tx'])?$row['tx']:''));
			if($id>0&&intval(isset($row['completed'])?$row['completed']:0)===1&&$tx!=='')$completed[]=$id;
		}
		sort($completed,SORT_NUMERIC);if($completed!==$ids)return;
		$ledger['batch_state']='HOLD_COMPLETED_PAYOUT_RECONCILIATION';
		$ledger['reconciliation_classification']='HOLD / COMPLETED PAYOUT WALLET PROOF REQUIRED';
		$ledger['completed_payout_ids']=$completed;
		$warning='Created payout rows are already completed with transaction IDs; wallet send and mutation phases must not be rerun. Use read-only wallet-proof-closeout.';
		if(!in_array($warning,$ledger['warnings'],true))$ledger['warnings'][]=$warning;
		return $this->save($ledger);
	}

	private function resumeLaneMatches($lane,$options)
	{
		if(isset($options['only'])&&$options['only']!==null&&$options['only']!==$lane->operationalAlgo())return false;
		if(isset($options['coordinator_owner'])&&!$lane->ownershipMatches($options['coordinator_owner']))return false;
		if(isset($options['lane_configuration'])){
			$config=$options['lane_configuration'];
			if(!$config instanceof BadpoolLivePaymentLaneConfiguration||$config->ownershipEnvelope()!==$lane->ownershipEnvelope())return false;
		}
		return true;
	}

	private function resumeCompletedPayout($ledger,$options)
	{
		$id=$options['resume_batch_id'];$dir=$this->root.'/'.$id;
		if(!preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/',$id)||is_link($this->root)||is_link($dir)||is_link($this->path($id))||realpath($this->root)!==dirname(realpath($dir)))return $this->refusal($options,$id,'Unsafe completed-payout batch path.');
		$lockPath=$dir.'/resume.lock';if(is_link($lockPath))return $this->refusal($options,$id,'Unsafe batch resume lock.');
		// run() owns the batch lock for both financial and completed-payout resumes.
		try{
			if($this->load($id)!==$ledger)return $this->refusal($options,$id,'Batch changed before completed-payout resume.');
			$lane=(new BadpoolLivePaymentLaneRegistry())->fromOwnershipEnvelope(isset($ledger['coordinator_owner'])?$ledger['coordinator_owner']:null);
			if(!$lane||!$this->resumeLaneMatches($lane,$options))return $this->refusal($options,$id,'Resume lane does not match commissioned durable ownership.');
			$error=$this->completedPayoutContractError($ledger,$lane);
			if($error!==null)return $this->refusal($options,$id,$error);
			// No adapter phase or command executor is called here. Only exact SELECTs.
			if($this->enforceCompletedPayoutBoundary($ledger,$lane)!==true)return $this->refusal($options,$id,'Exact owned payout rows must all be completed with transaction IDs; boundary was not persisted.');
			return $this->report($ledger);
		}catch(Exception $e){return $this->refusal($options,$id,'Completed-payout evidence could not be read or persisted; no financial phase was invoked.');}
	}

	private function completedPayoutContractError($ledger,$lane)
	{
		if(!isset($ledger['current_phase'])||$ledger['current_phase']!==6||!in_array($this->scalarValue($ledger,'batch_state'),array('READY_FOR_WALLET_APPROVAL','HOLD_COMPLETED_PAYOUT_RECONCILIATION'),true))return 'Completed-payout resume requires the phase-6 wallet boundary.';
		if($this->scalarValue($ledger,'mode')!=='auto'||$this->scalarValue($ledger,'scope')!=='all-active-payout-coins'||$this->scalarValue($ledger,'only')!==$lane->operationalAlgo()||!isset($ledger['stop_before_wallet_send'])||$ledger['stop_before_wallet_send']!==true||!isset($ledger['batch_size'])||!is_int($ledger['batch_size'])||$ledger['batch_size']<1||$ledger['batch_size']>$lane->batchLimit())return 'Owned batch execution contract mismatch.';
		$coins=$this->arrayValue($ledger,'selected_coin_scope');
		if(count($coins)!==1)return 'Owned batch requires exactly one coin.';
		$coin=reset($coins);
		if(!isset($coin['id'],$coin['algo'])||$this->positiveIdList(array($coin['id']))!==array($lane->coinId())||$coin['algo']!==$lane->dbAlgo()||(isset($coin['coin_id'])&&$this->positiveIdList(array($coin['coin_id']))!==array($lane->coinId())))return 'Owned batch coin or database algorithm mismatch.';
		foreach(array('warnings','errors') as $key)if(!isset($ledger[$key])||!is_array($ledger[$key]))return 'Malformed batch diagnostics.';
		foreach(array('selected_earning_ids','selected_block_ids','selected_account_ids','created_payout_ids') as $key){
			if(!isset($ledger[$key])||!is_array($ledger[$key])||$this->positiveIdList($ledger[$key])===null||!$ledger[$key])return 'Owned batch scope is missing or malformed: '.$key.'.';
		}
		foreach($ledger['selected_block_ids'] as $block)if(intval($block)<=$lane->blockBoundary())return 'Owned batch violates the activation boundary.';
		$dir=realpath($this->root.'/'.$ledger['batch_id']);
		if($this->scalarValue($ledger,'run_directory')===null||realpath($ledger['run_directory'])!==$dir)return 'Owned batch run directory mismatch.';
		$phases=array();
		foreach($this->arrayValue($ledger,'phase_results') as $entry){
			if(!is_array($entry)||!isset($entry['phase_number'])||!is_int($entry['phase_number'])||$entry['phase_number']<0||$entry['phase_number']>6)return 'Malformed financial phase evidence.';
			$phases[$entry['phase_number']]=$entry;
		}
		for($phase=0;$phase<=6;$phase++){
			if(!isset($phases[$phase])||!in_array($this->scalarValue($phases[$phase],'status'),array('pass','ok'),true))return 'Completed-payout resume requires all financial phases to have passed.';
			$entry=$phases[$phase];$path=$this->scalarValue($entry,'report_path')?:$this->scalarValue($entry,'package_path');
			$key='phase_'.$phase.'_sha256';$checksums=$this->arrayValue($entry,'checksum_summary');$ledgerChecksums=$this->arrayValue($ledger,'checksums');
			if(!$path||!is_file($path)||is_link($path)||dirname(realpath($path))!==$dir||!isset($checksums[$key],$ledgerChecksums[$key])||!is_string($checksums[$key])||!preg_match('/^[a-f0-9]{64}$/',$checksums[$key])||$checksums[$key]!==$ledgerChecksums[$key]||!hash_equals($checksums[$key],hash_file('sha256',$path)))return 'Financial phase artifact checksum mismatch: '.$phase.'.';
			if($phase===6){
				$reports=json_decode(file_get_contents($path),true);$ids=array();
				if(!is_array($reports)||!$reports)return 'Payout creation evidence is missing.';
				foreach($reports as $report){
					if(!is_array($report)||!in_array($this->scalarValue($report,'status'),array('pass','ok'),true)||!isset($report['created_payout_ids'])||!is_array($report['created_payout_ids']))return 'Payout creation evidence is invalid.';
					$count=isset($report['payout_rows_inserted'])?$report['payout_rows_inserted']:(isset($report['created_count'])?$report['created_count']:null);
					if(!(is_int($count)&&$count>=0)&&!(is_string($count)&&preg_match('/^(0|[1-9][0-9]*)$/',$count)))return 'Payout creation count is invalid.';
					if(intval($count)!==count($report['created_payout_ids']))return 'Payout creation count mismatch.';
					$ids=array_merge($ids,$report['created_payout_ids']);
				}
				if($this->positiveIdList($ids)!==$this->positiveIdList($ledger['created_payout_ids']))return 'Created payout IDs changed from the financial phase evidence.';
			}
		}
		return null;
	}

	private function invoke($phase, $ledger, $options)
	{
		$methods=array('safetyCheck','selectEligibleWork','packageMaturity','applyMaturity','paymentDelayCheck','creditAccounts','preparePayoutRows');
		$method=$methods[$phase];
		if (!is_object($this->adapter) || !is_callable(array($this->adapter,$method))) return array('status'=>'hold','warnings'=>array('Phase adapter is not configured; no mutation was attempted.'));
		return call_user_func(array($this->adapter,$method), $ledger, $options);
	}

	private function mergeResult(&$ledger, $result)
	{
		foreach (array('selected_coin_scope','selected_earning_ids','selected_block_ids','selected_account_ids','selected_accounts_by_coin','created_payout_ids','selected_work_by_coin','payment_delay_qualified_earning_ids') as $key)
			if (isset($result[$key]) && is_array($result[$key])) $ledger[$key]=$result[$key];
		foreach (array('warnings','errors') as $key) if (isset($result[$key]) && is_array($result[$key])) $ledger[$key]=array_merge($ledger[$key],$result[$key]);
		if (isset($result['package_path']) && is_string($result['package_path']) && $result['package_path']!=='') $ledger['approval_package_paths'][]=$result['package_path'];
		if (isset($result['report_path']) && is_string($result['report_path']) && $result['report_path']!=='') $ledger['dryrun_report_paths'][]=$result['report_path'];
		if (isset($result['checksums']) && is_array($result['checksums'])) $ledger['checksums']=array_merge($ledger['checksums'],$result['checksums']);
	}

	private function phasePassed($ledger, $phase) { foreach ($ledger['phase_results'] as $r) if (is_array($r) && isset($r['phase_number'],$r['status']) && (int)$r['phase_number']===$phase && in_array($r['status'],array('ok','pass'),true)) return $phase!==6 || $this->ledgerHasPositivePayoutIds($ledger); return false; }
	private function validPhaseSixPayoutIds($ledger, $result) { $inserted=$this->positiveCount($result,'payout_rows_inserted'); if($inserted===0)$inserted=$this->positiveCount($result,'created_count'); $ids=$this->positiveIdList($this->arrayValue($result,'created_payout_ids')); if($inserted>0)return $ids!==null&&count($ids)===$inserted; if(count((array)$this->arrayValue($ledger,'selected_account_ids'))>0)return $ids!==null&&count($ids)>0; return $ids!==null; }
	private function ledgerHasPositivePayoutIds($ledger) { $ids=$this->positiveIdList($this->arrayValue($ledger,'created_payout_ids')); return $ids!==null&&count($ids)>0; }
	private function positiveCount($a,$key) { $v=isset($a[$key])?$a[$key]:0; return is_numeric($v)&&intval($v)>0?intval($v):0; }
	private function positiveIdList($ids) { $out=array(); foreach((array)$ids as $id){ if(is_int($id)&&$id>0)$pid=$id; elseif(is_string($id)&&preg_match('/^[1-9][0-9]*$/',$id))$pid=intval($id); else return null; if(in_array($pid,$out,true))return null; $out[]=$pid; } sort($out,SORT_NUMERIC); return $out; }
	private function arrayValue($a,$k) { return isset($a[$k]) && is_array($a[$k]) ? $a[$k] : array(); }
	private function scalarValue($a,$k) { return isset($a[$k]) && is_string($a[$k]) ? $a[$k] : null; }
	private function path($id) { return $this->root.'/'.$id.'/ledger.json'; }
	private function load($id) { if (!preg_match('/^[A-Za-z0-9._-]+$/',$id)) return null; $p=$this->path($id); $v=is_file($p)?json_decode(file_get_contents($p),true):null; return is_array($v)&&isset($v['batch_id'])&&$v['batch_id']===$id?$v:null; }
	private function save(&$l) { $l['updated_at']=gmdate('c'); $tmp=$this->path($l['batch_id']).'.tmp'; if(is_link($tmp))return false; if(file_put_contents($tmp,json_encode($l,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n",LOCK_EX)===false)return false; return rename($tmp,$this->path($l['batch_id'])); }

	private function report($l)
	{
		$status=$l['batch_state']==='READY_FOR_WALLET_APPROVAL'?'pass':($l['batch_state']==='FAIL'?'fail':'hold');
		$completedReconciliation=$l['batch_state']==='HOLD_COMPLETED_PAYOUT_RECONCILIATION';
		if($completedReconciliation)$status='hold';
		return array('schema'=>self::SCHEMA,'command'=>'batch-run','status'=>$status,'batch_id'=>$l['batch_id'],'batch_state'=>$l['batch_state'],
			'mode'=>$l['mode'],'scope'=>$l['scope'],'only'=>$l['only'],'batch_size'=>$l['batch_size'],'stop_before_wallet_send'=>true,
			'current_phase'=>$l['current_phase'],'phase_results'=>$l['phase_results'],'selected_coin_scope'=>$l['selected_coin_scope'],
			'selected_counts'=>array('earnings'=>count($l['selected_earning_ids']),'blocks'=>count($l['selected_block_ids']),'accounts'=>count($l['selected_account_ids']),'payouts'=>count($l['created_payout_ids'])),
			'selected_amounts'=>array(),'created_payout_ids'=>$l['created_payout_ids'],'wallet_boundary'=>'blocked_human_required',
			'run_directory'=>$l['run_directory'],'ledger_path'=>$this->path($l['batch_id']),'reconciliation_classification'=>isset($l['reconciliation_classification'])?$l['reconciliation_classification']:null,
			'next_action'=>$completedReconciliation?'read_only_wallet_proof_closeout':($l['batch_state']==='READY_FOR_WALLET_APPROVAL'?'human_wallet_approval':'resume_batch'),
			'suggested_command'=>$completedReconciliation?null:($l['batch_state']==='READY_FOR_WALLET_APPROVAL'?'bin/badpool-wallet-approve --batch-id='.$l['batch_id']:'bin/badpool-batch-run --resume-batch-id='.$l['batch_id']),
			'do_not_rerun'=>$completedReconciliation?array('wallet-send-apply','payout-row-apply','account-credit-apply'):array(),
			'blocked_actions'=>array('wallet_rpc_send','wallet_send_apply','fund_transfer','payout_rows_marked_completed_by_wallet_send'),
			'warnings'=>$l['warnings'],'errors'=>$l['errors']);
	}

	private function refusal($o,$id,$message) { return array('schema'=>self::SCHEMA,'command'=>'batch-run','status'=>'refused','batch_id'=>$id,'batch_state'=>'HOLD','mode'=>$o['mode'],'scope'=>$o['scope'],'only'=>$o['only'],'batch_size'=>$o['batch_size'],'stop_before_wallet_send'=>true,'current_phase'=>0,'phase_results'=>array(),'selected_coin_scope'=>array(),'selected_counts'=>array(),'selected_amounts'=>array(),'created_payout_ids'=>array(),'wallet_boundary'=>'blocked_human_required','run_directory'=>null,'ledger_path'=>null,'next_action'=>'correct_input','suggested_command'=>null,'blocked_actions'=>array('wallet_rpc_send','wallet_send_apply','fund_transfer','payout_rows_marked_completed_by_wallet_send'),'warnings'=>array(),'errors'=>array($message)); }
}
