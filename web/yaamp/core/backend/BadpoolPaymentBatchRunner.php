<?php

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
		$now = gmdate('c');
		$resume = isset($options['resume_batch_id']) ? $options['resume_batch_id'] : null;
		if ($resume) {
			$ledger = $this->load($resume);
			if (!$ledger) return $this->refusal($options, $resume, 'Batch ledger was not found or is invalid.');
			// The durable ledger, rather than new CLI defaults, is authoritative on resume.
			foreach (array('mode','scope','only','batch_size') as $key) $options[$key]=$ledger[$key];
		} else {
			$id = gmdate('Ymd\THis\Z').'-'.substr(hash('sha256', uniqid('', true)), 0, 12);
			$dir = $this->root.'/'.$id;
			$ledger = array('batch_id'=>$id, 'created_at'=>$now, 'updated_at'=>$now, 'command'=>'batch-run',
				'mode'=>$options['mode'], 'scope'=>$options['scope'], 'only'=>$options['only'],
				'batch_size'=>$options['batch_size'], 'stop_before_wallet_send'=>true,
				'current_phase'=>0, 'batch_state'=>'PREVIEWED', 'run_directory'=>$dir,
				'selected_coin_scope'=>array(), 'selected_earning_ids'=>array(), 'selected_block_ids'=>array(),
				'selected_account_ids'=>array(), 'selected_accounts_by_coin'=>array(), 'created_payout_ids'=>array(), 'selected_work_by_coin'=>array(), 'approval_package_paths'=>array(),
				'dryrun_report_paths'=>array(), 'checksums'=>array(), 'phase_results'=>array(), 'warnings'=>array(), 'errors'=>array());
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
			$entry = array('phase_number'=>$number, 'phase_name'=>$name, 'status'=>$status,
				'mutation_scope'=>$this->arrayValue($result, 'mutation_scope'), 'report_path'=>$this->scalarValue($result, 'report_path'),
				'package_path'=>$this->scalarValue($result, 'package_path'), 'checksum_summary'=>$this->arrayValue($result, 'checksums'),
				'started_at'=>$started, 'finished_at'=>gmdate('c'));
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
		return $this->report($ledger);
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
		foreach (array('selected_coin_scope','selected_earning_ids','selected_block_ids','selected_account_ids','selected_accounts_by_coin','created_payout_ids','selected_work_by_coin') as $key)
			if (isset($result[$key]) && is_array($result[$key])) $ledger[$key]=$result[$key];
		foreach (array('warnings','errors') as $key) if (isset($result[$key]) && is_array($result[$key])) $ledger[$key]=array_merge($ledger[$key],$result[$key]);
		if (isset($result['package_path']) && is_string($result['package_path']) && $result['package_path']!=='') $ledger['approval_package_paths'][]=$result['package_path'];
		if (isset($result['report_path']) && is_string($result['report_path']) && $result['report_path']!=='') $ledger['dryrun_report_paths'][]=$result['report_path'];
		if (isset($result['checksums']) && is_array($result['checksums'])) $ledger['checksums']=array_merge($ledger['checksums'],$result['checksums']);
	}

	private function phasePassed($ledger, $phase) { foreach ($ledger['phase_results'] as $r) if (is_array($r) && isset($r['phase_number'],$r['status']) && (int)$r['phase_number']===$phase && in_array($r['status'],array('ok','pass'),true)) return true; return false; }
	private function arrayValue($a,$k) { return isset($a[$k]) && is_array($a[$k]) ? $a[$k] : array(); }
	private function scalarValue($a,$k) { return isset($a[$k]) && is_string($a[$k]) ? $a[$k] : null; }
	private function path($id) { return $this->root.'/'.$id.'/ledger.json'; }
	private function load($id) { if (!preg_match('/^[A-Za-z0-9._-]+$/',$id)) return null; $p=$this->path($id); $v=is_file($p)?json_decode(file_get_contents($p),true):null; return is_array($v)&&isset($v['batch_id'])&&$v['batch_id']===$id?$v:null; }
	private function save(&$l) { $l['updated_at']=gmdate('c'); $tmp=$this->path($l['batch_id']).'.tmp'; file_put_contents($tmp,json_encode($l,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n",LOCK_EX); rename($tmp,$this->path($l['batch_id'])); }

	private function report($l)
	{
		$status=$l['batch_state']==='READY_FOR_WALLET_APPROVAL'?'pass':($l['batch_state']==='FAIL'?'fail':'hold');
		return array('schema'=>self::SCHEMA,'command'=>'batch-run','status'=>$status,'batch_id'=>$l['batch_id'],'batch_state'=>$l['batch_state'],
			'mode'=>$l['mode'],'scope'=>$l['scope'],'only'=>$l['only'],'batch_size'=>$l['batch_size'],'stop_before_wallet_send'=>true,
			'current_phase'=>$l['current_phase'],'phase_results'=>$l['phase_results'],'selected_coin_scope'=>$l['selected_coin_scope'],
			'selected_counts'=>array('earnings'=>count($l['selected_earning_ids']),'blocks'=>count($l['selected_block_ids']),'accounts'=>count($l['selected_account_ids']),'payouts'=>count($l['created_payout_ids'])),
			'selected_amounts'=>array(),'created_payout_ids'=>$l['created_payout_ids'],'wallet_boundary'=>'blocked_human_required',
			'run_directory'=>$l['run_directory'],'ledger_path'=>$this->path($l['batch_id']),'next_action'=>$l['batch_state']==='READY_FOR_WALLET_APPROVAL'?'human_wallet_approval':'resume_batch',
			'suggested_command'=>$l['batch_state']==='READY_FOR_WALLET_APPROVAL'?'bin/badpool-wallet-approve --batch-id='.$l['batch_id']:'bin/badpool-batch-run --resume-batch-id='.$l['batch_id'],
			'blocked_actions'=>array('wallet_rpc_send','wallet_send_apply','fund_transfer','payout_rows_marked_completed_by_wallet_send'),
			'warnings'=>$l['warnings'],'errors'=>$l['errors']);
	}

	private function refusal($o,$id,$message) { return array('schema'=>self::SCHEMA,'command'=>'batch-run','status'=>'refused','batch_id'=>$id,'batch_state'=>'HOLD','mode'=>$o['mode'],'scope'=>$o['scope'],'only'=>$o['only'],'batch_size'=>$o['batch_size'],'stop_before_wallet_send'=>true,'current_phase'=>0,'phase_results'=>array(),'selected_coin_scope'=>array(),'selected_counts'=>array(),'selected_amounts'=>array(),'created_payout_ids'=>array(),'wallet_boundary'=>'blocked_human_required','run_directory'=>null,'ledger_path'=>null,'next_action'=>'correct_input','suggested_command'=>null,'blocked_actions'=>array('wallet_rpc_send','wallet_send_apply','fund_transfer','payout_rows_marked_completed_by_wallet_send'),'warnings'=>array(),'errors'=>array($message)); }
}
