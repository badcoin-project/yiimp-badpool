<?php

require_once(dirname(__FILE__).'/BadpoolLivePaymentLaneConfiguration.php');

/** Explicit ledger-only authority which records a successfully proven closeout. */
class BadpoolCompletedPayoutBatchCloseoutApply
{
	const SCHEMA = 'badpool.payment_batch.completed_payout_closeout_apply.v1';
	const CONFIRMATION = 'reconcile_completed_payout_ledger_only';
	private $root;

	public function __construct($root=null)
	{
		$this->root=$root ?: dirname(__FILE__).'/../../../../runtime/badpool-payment-batches';
	}

	public function apply($batchId,$proofPath,$proofChecksum,$confirmation)
	{
		$r=$this->base($batchId);
		if(!preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/',(string)$batchId))return $this->fail($r,'invalid_batch_id');
		if(!is_string($proofChecksum)||!preg_match('/^[a-f0-9]{64}$/',$proofChecksum))return $this->fail($r,'invalid_proof_checksum');
		if(!hash_equals(self::CONFIRMATION,(string)$confirmation))return $this->fail($r,'operator_confirmation_mismatch');
		if(!is_string($proofPath)||!is_file($proofPath)||is_link($proofPath))return $this->fail($r,'proof_missing_or_invalid');
		$actual=hash_file('sha256',$proofPath);if(!hash_equals($proofChecksum,$actual))return $this->fail($r,'proof_checksum_mismatch');
		$proof=json_decode(file_get_contents($proofPath),true);
		if(!is_array($proof)||arraySafeVal($proof,'schema')!==BadpoolCompletedPayoutBatchCloseout::SCHEMA||arraySafeVal($proof,'command')!=='completed-payout-batch-closeout'||arraySafeVal($proof,'status')!=='pass')return $this->fail($r,'successful_closeout_proof_required');
		if(arraySafeVal($proof,'batch_id')!==$batchId||arraySafeVal($proof,'read_only')!==true||arraySafeVal($proof,'wallet_sends')!==false||arraySafeVal($proof,'db_mutations')!==false)return $this->fail($r,'proof_contract_mismatch');
		$proofIds=$this->positiveIds(arraySafeVal($proof,'created_payout_ids'));if($proofIds===null||empty($proofIds))return $this->fail($r,'proof_payout_ids_invalid');
		$path=$this->root.'/'.$batchId.'/ledger.json';$ledger=is_file($path)&&!is_link($path)?json_decode(file_get_contents($path),true):null;
		if(!is_array($ledger)||arraySafeVal($ledger,'batch_id')!==$batchId)return $this->fail($r,'batch_ledger_missing_or_invalid');
		$owner=arraySafeVal($ledger,'coordinator_owner',array());$lane=(new BadpoolLivePaymentLaneRegistry())->fromOwnershipEnvelope($owner);if(!$lane)return $this->fail($r,'coordinator_ownership_required');
		$ledgerIds=$this->positiveIds(arraySafeVal($ledger,'created_payout_ids'));if($ledgerIds===null||$ledgerIds!==$proofIds)return $this->fail($r,'proof_payout_ids_mismatch');
		if(arraySafeVal($ledger,'batch_state')==='RECONCILED'){
			if(arraySafeVal($ledger,'reconciliation_proof_checksum')!==$actual||$this->positiveIds(arraySafeVal($ledger,'reconciled_payout_ids'))!==$proofIds)return $this->fail($r,'reconciled_evidence_mismatch');
			return $this->pass($r,$actual,$proofIds,true);
		}
		if(arraySafeVal($ledger,'batch_state')!=='HOLD_COMPLETED_PAYOUT_RECONCILIATION'||intval(arraySafeVal($ledger,'current_phase',0))!==6)return $this->fail($r,'batch_not_reconciliation_ready');
		$ledger['batch_state']='RECONCILED';$ledger['reconciled_at']=gmdate('c');$ledger['reconciliation_proof_checksum']=$actual;$ledger['reconciled_payout_ids']=$proofIds;
		$tmp=$path.'.tmp.'.getmypid();$json=json_encode($ledger,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
		if(file_put_contents($tmp,$json,LOCK_EX)===false||!rename($tmp,$path)){@unlink($tmp);return $this->fail($r,'ledger_atomic_write_failed');}
		return $this->pass($r,$actual,$proofIds,false);
	}

	private function base($id){return array('schema'=>self::SCHEMA,'command'=>'completed-payout-batch-closeout-apply','status'=>'fail','classification'=>'FAIL_CLOSED','batch_id'=>$id,'canonical_terminal_state'=>'RECONCILED','ledger_mutation_only'=>true,'db_mutations'=>false,'wallet_rpc_used'=>false,'wallet_sends'=>false,'already_reconciled'=>false,'reconciliation_proof_checksum'=>null,'reconciled_payout_ids'=>array(),'errors'=>array());}
	private function fail($r,$e){$r['errors'][]=$e;return $r;}
	private function pass($r,$sum,$ids,$already){$r['status']='pass';$r['classification']=$already?'PASS / ALREADY RECONCILED':'PASS / LEDGER RECONCILED';$r['already_reconciled']=$already;$r['reconciliation_proof_checksum']=$sum;$r['reconciled_payout_ids']=$ids;return $r;}
	private function positiveIds($ids){if(!is_array($ids))return null;$out=array();foreach($ids as $id){if(is_int($id)&&$id>0)$n=$id;elseif(is_string($id)&&preg_match('/^[1-9][0-9]*$/',$id))$n=intval($id);else return null;if(isset($out[$n]))return null;$out[$n]=$n;}ksort($out,SORT_NUMERIC);return array_values($out);}
}
