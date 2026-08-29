<?php

/**
 * Read-only reconciliation for a batch whose payout rows crossed the wallet
 * boundary outside the batch ledger.  This class deliberately has no apply
 * mode: it reads the ledger and payout rows, then delegates wallet reads to
 * wallet-proof-closeout.
 */
class BadpoolCompletedPayoutBatchCloseout
{
	const SCHEMA = 'badpool.payment_batch.completed_payout_closeout.v1';
	private $adapter;
	private $proofExecutor;
	private $root;

	public function __construct($adapter, $proofExecutor, $root=null)
	{
		$this->adapter=$adapter;
		$this->proofExecutor=$proofExecutor;
		$this->root=$root ?: dirname(__FILE__).'/../../../../runtime/badpool-payment-batches';
	}

	public function preview($batchId)
	{
		$r=$this->base($batchId);
		if(!preg_match('/^[A-Za-z0-9._-]+$/',(string)$batchId))return $this->hold($r,'invalid_batch_id');
		$path=$this->root.'/'.$batchId.'/ledger.json';
		$ledger=is_file($path)?json_decode(file_get_contents($path),true):null;
		if(!is_array($ledger)||arraySafeVal($ledger,'batch_id')!==$batchId)return $this->hold($r,'batch_ledger_missing_or_invalid');
		$r['ledger_path']=$path;$r['batch_state']=arraySafeVal($ledger,'batch_state');$r['current_phase']=intval(arraySafeVal($ledger,'current_phase',0));
		if(!in_array($r['batch_state'],array('READY_FOR_WALLET_APPROVAL','HOLD_COMPLETED_PAYOUT_RECONCILIATION'),true)||$r['current_phase']!==6)return $this->hold($r,'batch_not_at_wallet_boundary');
		$ids=$this->positiveIds(arraySafeVal($ledger,'created_payout_ids'));
		if($ids===null||empty($ids))return $this->hold($r,'created_payout_ids_missing_or_ambiguous');
		$r['created_payout_ids']=$ids;
		if($this->walletSendApplyReportExists($ledger,$path)){$r['wallet_send_apply_report_exists']=true;return $this->hold($r,'wallet_send_apply_report_exists_unexpectedly');}
		if(!is_object($this->adapter)||!is_callable(array($this->adapter,'inspectCreatedPayoutRows')))return $this->hold($r,'payout_row_reader_unavailable');
		$rows=call_user_func(array($this->adapter,'inspectCreatedPayoutRows'),$ids);
		if(!is_array($rows)||count($rows)!==count($ids))return $this->hold($r,'created_payout_ids_missing_or_ambiguous');
		$byId=array();foreach($rows as $row){$id=intval(arraySafeVal($row,'id'));if($id<=0||isset($byId[$id]))return $this->hold($r,'created_payout_ids_missing_or_ambiguous');$byId[$id]=$row;}
		$byCoin=array();foreach($ids as $id){
			if(!isset($byId[$id]))return $this->hold($r,'created_payout_ids_missing_or_ambiguous');
			$row=$byId[$id];if(intval(arraySafeVal($row,'completed',0))!==1)return $this->hold($r,'created_payout_not_completed');
			if(trim((string)arraySafeVal($row,'tx',''))==='')return $this->hold($r,'created_payout_tx_missing');
			$coin=intval(arraySafeVal($row,'idcoin',0));if($coin<=0)return $this->hold($r,'created_payout_coin_missing');$byCoin[$coin][]=$id;
		}
		if(!is_callable($this->proofExecutor))return $this->hold($r,'wallet_proof_executor_unavailable');
		foreach($byCoin as $coin=>$coinIds){
			$proof=call_user_func($this->proofExecutor,$coin,$coinIds);
			$r['wallet_proof_reports'][]=$proof;
			$bindingError=$this->proofBindingError($proof,$coin,$coinIds);
			if($bindingError!==null)return $this->hold($r,$bindingError);
			if(arraySafeVal($proof,'closeout_valid')!==true||arraySafeVal($proof,'wallet_lookup_success')!==true||arraySafeVal($proof,'wallet_txid_expected')!==true||arraySafeVal($proof,'wallet_amount_matches_expected')!==true||arraySafeVal($proof,'wallet_confirmations_present')!==true)return $this->hold($r,'wallet_proof_missing_or_invalid');
			if(arraySafeVal($proof,'wallet_sends')!==false||arraySafeVal($proof,'db_mutations')!==false)return $this->hold($r,'wallet_proof_report_not_read_only');
		}
		$r['status']='pass';$r['classification']='PASS / COMPLETED-PAYOUT RECONCILIATION PROOF COMPLETE';$r['final_classification']=$r['classification'];$r['next_safe_lane_or_STOP']='STOP';
		return $r;
	}

	private function base($id){return array('schema'=>self::SCHEMA,'command'=>'completed-payout-batch-closeout','status'=>'hold','classification'=>'HOLD / COMPLETED-PAYOUT RECONCILIATION INCOMPLETE','final_classification'=>'HOLD / COMPLETED-PAYOUT RECONCILIATION INCOMPLETE','batch_id'=>$id,'batch_state'=>null,'current_phase'=>null,'ledger_path'=>null,'created_payout_ids'=>array(),'completed_payout_reconciliation'=>true,'read_only'=>true,'wallet_reads'=>'proof_only','wallet_sends'=>false,'db_mutations'=>false,'ledger_only_apply_mode_available'=>false,'mutation_policy'=>'No DB or ledger mutations; a future ledger-only apply mode must be explicit.','wallet_send_apply_report_exists'=>false,'wallet_proof_reports'=>array(),'do_not_rerun'=>array('wallet-send-apply','payout-row-apply','account-credit-apply'),'next_safe_lane_or_STOP'=>'HOLD','errors'=>array());}
	private function hold($r,$reason){$r['errors'][]=$reason;return $r;}
	private function positiveIds($ids){if(!is_array($ids))return null;$out=array();foreach($ids as $id){if(is_int($id)&&$id>0)$n=$id;elseif(is_string($id)&&preg_match('/^[1-9][0-9]*$/',$id))$n=intval($id);else return null;if(isset($out[$n]))return null;$out[$n]=$n;}ksort($out,SORT_NUMERIC);return array_values($out);}
	private function proofBindingError($proof,$coin,$ids){
		if(!is_array($proof))return 'wallet_proof_missing_or_invalid';
		$proofIds=$this->positiveIds(arraySafeVal($proof,'selected_payout_ids'));
		if($proofIds===null||$proofIds!==$ids)return 'wallet_proof_payout_ids_mismatch';
		$proofCoins=array();
		$scope=arraySafeVal($proof,'scope',array());if(is_array($scope)&&array_key_exists('coin_id',$scope)&&$scope['coin_id']!==null)$proofCoins[]=intval($scope['coin_id']);
		$context=arraySafeVal($proof,'wallet_proof_context',array());if(is_array($context)&&array_key_exists('coin_id',$context))$proofCoins[]=intval($context['coin_id']);
		foreach((array)arraySafeVal($proof,'payout_inventory',array()) as $item)if(is_array($item)&&array_key_exists('coin_id',$item))$proofCoins[]=intval($item['coin_id']);
		foreach($proofCoins as $proofCoin)if($proofCoin!==intval($coin))return 'wallet_proof_coin_id_mismatch';
		return null;
	}
	private function walletSendApplyReportExists($ledger,$ledgerPath){
		foreach(array('wallet_send_apply_report','wallet_send_apply_report_path','wallet_send_apply_report_exists') as $key)if(!empty($ledger[$key]))return true;
		$dir=dirname($ledgerPath);foreach(array('wallet-send-apply-report.json','wallet_send_apply_report.json') as $name)if(is_file($dir.'/'.$name))return true;
		return false;
	}
}
