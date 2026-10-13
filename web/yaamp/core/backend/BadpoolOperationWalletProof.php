<?php

require_once(dirname(__FILE__).'/BadpoolGuardedMultiWalletSend.php');
require_once(dirname(__FILE__).'/BadpoolPaymentBatchRunner.php');
require_once(dirname(__FILE__).'/BadpoolCompletedPayoutWalletProof.php');

/** Read-only v2 proof rooted in one immutable, fully reconciled send journal. */
class BadpoolOperationWalletProof
{
	const SCHEMA='badpool.wallet_send.operation_proof.v2';
	const BATCH_SCHEMA='badpool.payment_batch.completed_payout_closeout.v2';
	const MAX_PAYOUTS=25;
	private $rows,$wallet,$runner,$batchRoot,$journalRoot,$registry;

	public function __construct($rows,$wallet,$runner,$batchRoot=null,$journalRoot=null,$registry=null)
	{
		if(!is_callable($rows)||!is_object($wallet)||!is_callable(array($wallet,'getTransactionExact'))||!is_object($runner)||!is_callable(array($runner,'inspectCompletedPayoutContract')))throw new InvalidArgumentException('Exact read-only proof dependencies required.');
		$this->rows=$rows;$this->wallet=$wallet;$this->runner=$runner;
		$this->batchRoot=rtrim($batchRoot?:dirname(__FILE__).'/../../../../runtime/badpool-payment-batches','/\\');
		$this->journalRoot=rtrim($journalRoot?:dirname(__FILE__).'/../../../../runtime/badpool-wallet-send-journals','/\\');
		$this->registry=$registry?:new BadpoolLivePaymentLaneRegistry();
	}

	public function preview($journalPath,$expectedChecksum)
	{
		$r=array('schema'=>self::SCHEMA,'command'=>'operation-wallet-proof','status'=>'hold','read_only'=>true,'wallet_reads'=>'gettransaction_only','wallet_sends'=>false,'db_mutations'=>false,'journal_sha256'=>null,'payout_ids'=>array(),'operations'=>array(),'batches'=>array(),'errors'=>array());
		try{
			if(!is_string($expectedChecksum)||!preg_match('/^[a-f0-9]{64}$/D',$expectedChecksum))throw new RuntimeException('journal_checksum_invalid');
			if(!is_string($journalPath)||is_link($journalPath)||!is_file($journalPath)||!is_dir($this->journalRoot)||is_link($this->journalRoot))throw new RuntimeException('journal_path_invalid');
			$root=realpath($this->journalRoot);$real=realpath($journalPath);
			if($real===false||dirname($real)!==$root||!preg_match('/^[a-f0-9]{64}\.json$/D',basename($real)))throw new RuntimeException('journal_path_outside_authority');
			if(filesize($real)>1048576)throw new RuntimeException('journal_too_large');
			$bytes=file_get_contents($real);if($bytes===false||!hash_equals($expectedChecksum,hash('sha256',$bytes)))throw new RuntimeException('journal_checksum_mismatch');
			$j=json_decode($bytes,true);if(!is_array($j)||json_last_error()!==JSON_ERROR_NONE)throw new RuntimeException('journal_malformed');
			$approval=substr(basename($real),0,64);
			if(arraySafeVal($j,'schema')!==BadpoolDurableMultiWalletSendJournal::SCHEMA||arraySafeVal($j,'approval_checksum')!==$approval||arraySafeVal($j,'global_state')!=='RECONCILED'||!$this->sha(arraySafeVal($j,'plan_checksum')))throw new RuntimeException('journal_not_exact_reconciled');
			$ids=$this->ids(arraySafeVal($j,'payout_ids'));if(!$ids||count($ids)>self::MAX_PAYOUTS)throw new RuntimeException('journal_payout_scope_invalid');
			$r['journal_sha256']=$expectedChecksum;$r['approval_checksum']=$approval;$r['plan_checksum']=$j['plan_checksum'];$r['payout_ids']=$ids;
			$evidence=$this->evidence(arraySafeVal($j,'payout_evidence'),$ids);
			$rows=call_user_func($this->rows,$ids);$actual=$this->evidence($rows,$ids);
			$owners=$this->owners($ids);
			foreach($ids as $id){
				$a=$actual[$id];$e=$evidence[$id];
				foreach(array('account_id','coin_id','amount','recipient') as $key)if(!array_key_exists($key,$e)||!array_key_exists($key,$a)||$a[$key]!==$e[$key])throw new RuntimeException('payout_evidence_mismatch_'.$id.'_'.$key);
				if(arraySafeVal($e,'batch_id')!==$owners[$id]||arraySafeVal($a,'completed')!==1||!$this->txid(arraySafeVal($a,'tx'))||!$this->eight(arraySafeVal($e,'wallet_send_amount'))||$this->projectEight($e['amount'])!==$e['wallet_send_amount'])throw new RuntimeException('payout_state_or_ownership_mismatch_'.$id);
			}
			$ops=arraySafeVal($j,'wallet_operation_evidence');$states=arraySafeVal($j,'operations');
			if(!is_array($ops)||!$ops||!is_array($states)||count($ops)!==count($states))throw new RuntimeException('journal_operation_evidence_missing');
			$seen=array();$operationById=array();
			foreach($ops as $op){
				if(!is_array($op)||!isset($op['operation_id'],$op['coin_id'],$op['lane_id'],$op['wallet_binding_identity'],$op['source_account_identity'],$op['recipients'],$op['payout_ids'])||isset($operationById[$op['operation_id']]))throw new RuntimeException('operation_identity_ambiguous');
				$opIds=$this->ids($op['payout_ids']);if(!$opIds)throw new RuntimeException('operation_payout_scope_invalid');
				$id=$op['operation_id'];$state=arraySafeVal($states,$id);
				if(!is_array($state)||arraySafeVal($state,'operation_id')!==$id||arraySafeVal($state,'state')!=='RECONCILED'||!$this->txid(arraySafeVal($state,'txid')))throw new RuntimeException('operation_not_reconciled');
				$expectedId='wallet-op-'.substr(BadpoolMultiWalletApprovalPlanner::checksum(array('approval_checksum'=>$approval,'wallet_binding_identity'=>$op['wallet_binding_identity'],'source_account_identity'=>$op['source_account_identity'],'payout_ids'=>$opIds,'recipients'=>$op['recipients'])),0,24);
				if($id!==$expectedId||arraySafeVal($op,'approval_checksum')!==$approval)throw new RuntimeException('operation_approval_binding_mismatch');
				$lane=$this->registry->get($op['lane_id']);$context=$this->wallet->contextForCoin($lane->coinId());
				if(!arraySafeVal($context,'supported')||$lane->coinId()!==intval($op['coin_id'])||arraySafeVal($context,'wallet_binding_identity')!==$op['wallet_binding_identity']||arraySafeVal($context,'source_account_identity')!==$op['source_account_identity'])throw new RuntimeException('operation_source_wallet_mismatch');
				$recipientTotals=array();$total=0;$rawTotal='0';
				foreach($opIds as $payoutId){
					if(!isset($evidence[$payoutId])||isset($seen[$payoutId]))throw new RuntimeException('missing_duplicate_or_unapproved_payout');
					$seen[$payoutId]=true;$p=$evidence[$payoutId];$a=$actual[$payoutId];
					if($p['coin_id']!==$lane->coinId()||arraySafeVal($p,'lane_id')!==$op['lane_id']||arraySafeVal($p,'wallet_binding_identity')!==$op['wallet_binding_identity']||arraySafeVal($p,'source_account_identity')!==$op['source_account_identity']||strtolower($a['tx'])!==$state['txid'])throw new RuntimeException('payout_operation_binding_mismatch_'.$payoutId);
					$amount=$this->sats($p['wallet_send_amount']);$to=$p['recipient'];if($amount>PHP_INT_MAX-$total||$amount>PHP_INT_MAX-(isset($recipientTotals[$to])?$recipientTotals[$to]:0))throw new RuntimeException('operation_amount_out_of_bounded_range');$recipientTotals[$to]=isset($recipientTotals[$to])?$recipientTotals[$to]+$amount:$amount;$total+=$amount;$rawTotal=$this->add($rawTotal,$p['amount']);
				}
				if($total<=0||$this->signedSats(arraySafeVal($op,'wallet_send_total'))!==$total||$this->add('0',arraySafeVal($op,'raw_total'))!==$rawTotal||!is_array($op['recipients'])||count($recipientTotals)!==count($op['recipients']))throw new RuntimeException('operation_total_mismatch');
				foreach($recipientTotals as $to=>$amount)if(!array_key_exists($to,$op['recipients'])||$this->format($amount)!==$op['recipients'][$to])throw new RuntimeException('operation_recipient_aggregation_mismatch');
				$tx=$this->wallet->getTransactionExact($lane->coinId(),$state['txid']);$wallet=$this->verifyWallet($tx,$state['txid'],$recipientTotals,$total);
				$proof=array('operation_id'=>$id,'coin_id'=>$lane->coinId(),'lane_id'=>$lane->laneId(),'wallet_binding_identity'=>$op['wallet_binding_identity'],'source_account_identity'=>$op['source_account_identity'],'payout_ids'=>$opIds,'txid'=>$state['txid'],'recipients'=>$op['recipients'],'wallet_amount'=>$wallet['amount'],'fee'=>$wallet['fee'],'confirmations'=>$wallet['confirmations'],'walletconflicts'=>array());
				$stable=$proof;unset($stable['confirmations']);$proof['proof_sha256']=BadpoolMultiWalletApprovalPlanner::checksum($stable);$r['operations'][]=$proof;$operationById[$id]=$proof;
			}
			$seenIds=array_keys($seen);sort($seenIds,SORT_NUMERIC);if(count($seen)!==count($ids)||$seenIds!==$ids||count($states)!==count($operationById))throw new RuntimeException('journal_operation_partition_incomplete');
			$batchIds=array_values(array_unique(array_values($owners)));sort($batchIds,SORT_STRING);
			foreach($batchIds as $batchId){
				$check=$this->runner->inspectCompletedPayoutContract($batchId);if(arraySafeVal($check,'status')!=='pass')throw new RuntimeException('batch_phase_evidence_invalid_'.$batchId.'_'.arraySafeVal($check,'reason','unknown'));
				$created=$this->ids($check['created_payout_ids']);$subset=array();foreach($ids as $id)if($owners[$id]===$batchId)$subset[]=$id;
				// One journal may close a batch only if it proves every sibling.
				if($created!==$subset||!$created)throw new RuntimeException('batch_sibling_not_proven_'.$batchId);
				foreach($created as $id)if($actual[$id]['completed']!==1)throw new RuntimeException('batch_sibling_unpaid_'.$batchId);
				$alloc=array();foreach($r['operations'] as $op){$covered=array_values(array_intersect($subset,$op['payout_ids']));if(!$covered)continue;$recipientTotals=array();$allocated=0;$payouts=array();foreach($covered as $id){$e=$evidence[$id];$amount=$this->sats($e['wallet_send_amount']);$recipientTotals[$e['recipient']]=isset($recipientTotals[$e['recipient']])?$recipientTotals[$e['recipient']]+$amount:$amount;$allocated+=$amount;$payouts[]=array('payout_id'=>$id,'recipient'=>$e['recipient'],'raw_amount'=>$e['amount'],'wallet_amount'=>$e['wallet_send_amount'],'txid'=>$op['txid']);}$recipientAmounts=array();foreach($recipientTotals as $recipient=>$amount)$recipientAmounts[$recipient]=$this->format($amount);$alloc[]=array('operation_id'=>$op['operation_id'],'operation_proof_sha256'=>$op['proof_sha256'],'payout_ids'=>$covered,'payouts'=>$payouts,'recipient_totals'=>$recipientAmounts,'allocated_wallet_total'=>$this->format($allocated),'txid'=>$op['txid']);}
				$batch=array('schema'=>self::BATCH_SCHEMA,'command'=>'completed-payout-batch-closeout','status'=>'pass','batch_id'=>$batchId,'created_payout_ids'=>$created,'journal_sha256'=>$expectedChecksum,'approval_checksum'=>$approval,'plan_checksum'=>$j['plan_checksum'],'phase_0_6_verified'=>true,'phase_checksums_sha256'=>BadpoolMultiWalletApprovalPlanner::checksum(arraySafeVal($check['ledger'],'checksums')),'lane_id'=>$check['lane_id'],'coin_id'=>$check['coin_id'],'allocations'=>$alloc,'read_only'=>true,'wallet_sends'=>false,'db_mutations'=>false);
				$batch['allocation_sha256']=BadpoolMultiWalletApprovalPlanner::checksum($batch);$r['batches'][]=$batch;
			}
			$r['status']='pass';$r['classification']='PASS / OPERATION-WIDE WALLET PROOF';
		}catch(Exception $e){$r['errors'][]=$e->getMessage();$r['classification']='HOLD / OPERATION-WIDE WALLET PROOF INCOMPLETE';}
		return $r;
	}

	private function owners($ids)
	{
		if(!is_dir($this->batchRoot)||is_link($this->batchRoot))throw new RuntimeException('batch_root_invalid');$owners=array();
		foreach((array)glob($this->batchRoot.'/*/ledger.json') as $path){if(is_link($path)||is_link(dirname($path)))throw new RuntimeException('batch_ledger_path_unsafe');$batchId=basename(dirname($path));if(!preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/D',$batchId))continue;$ledger=json_decode(file_get_contents($path),true);if(!is_array($ledger)||arraySafeVal($ledger,'batch_id')!==$batchId)throw new RuntimeException('batch_ledger_invalid');$created=$this->ids(arraySafeVal($ledger,'created_payout_ids'),true);if($created===null)throw new RuntimeException('batch_payout_ids_invalid');foreach($created as $id)if(in_array($id,$ids,true)){if(isset($owners[$id]))throw new RuntimeException('ambiguous_payout_owner_'.$id);$owners[$id]=$batchId;}}
		foreach($ids as $id)if(!isset($owners[$id]))throw new RuntimeException('missing_payout_owner_'.$id);
		return $owners;
	}

	private function verifyWallet($tx,$txid,$recipients,$total)
	{
		if(!is_array($tx)||arraySafeVal($tx,'txid')!==$txid||!isset($tx['amount'],$tx['fee'],$tx['confirmations'],$tx['walletconflicts'],$tx['details'])||!is_array($tx['walletconflicts'])||$tx['walletconflicts']||!is_array($tx['details'])||!$tx['details']||arraySafeVal($tx,'abandoned',false)!==false)throw new RuntimeException('wallet_transaction_identity_or_conflict_invalid');
		$confirm=arraySafeVal($tx,'confirmations');if(!preg_match('/^[0-9]+$/D',(string)$confirm)||intval($confirm)<1)throw new RuntimeException('wallet_confirmation_policy_not_met');
		if($this->signedSats($tx['amount'])!==-$total||$this->signedSats($tx['fee'])>0)throw new RuntimeException('wallet_debit_or_fee_mismatch');
		$outputs=array();$outputTotal=0;foreach($tx['details'] as $detail){if(!is_array($detail)||arraySafeVal($detail,'category')!=='send'||!is_string(arraySafeVal($detail,'address'))||$detail['address']===''||!isset($detail['amount']))throw new RuntimeException('wallet_destination_detail_invalid');$amount=$this->signedSats($detail['amount']);if($amount>=0)throw new RuntimeException('wallet_destination_amount_invalid');$debit=-$amount;$to=$detail['address'];$prior=isset($outputs[$to])?$outputs[$to]:0;if($debit>PHP_INT_MAX-$prior||$debit>PHP_INT_MAX-$outputTotal)throw new RuntimeException('wallet_destination_sum_out_of_bounded_range');$outputs[$to]=$prior+$debit;$outputTotal+=$debit;}
		if($outputTotal!==$total)throw new RuntimeException('wallet_destination_total_mismatch');
		if(count($outputs)!==count($recipients))throw new RuntimeException('wallet_destination_count_mismatch');foreach($recipients as $to=>$amount)if(!isset($outputs[$to])||$outputs[$to]!==$amount)throw new RuntimeException('wallet_destination_mismatch');
		return array('amount'=>$tx['amount'],'fee'=>$tx['fee'],'confirmations'=>intval($confirm));
	}

	private function evidence($rows,$ids)
	{
		if(!is_array($rows)||count($rows)!==count($ids))throw new RuntimeException('payout_evidence_count_mismatch');$out=array();foreach($rows as $row){$id=arraySafeVal($row,'payout_id');if(!is_int($id)||!in_array($id,$ids,true)||isset($out[$id]))throw new RuntimeException('payout_evidence_identity_invalid');$out[$id]=$row;}ksort($out,SORT_NUMERIC);if(array_keys($out)!==$ids)throw new RuntimeException('payout_evidence_scope_mismatch');return $out;
	}
	private function ids($ids,$empty=false){if(!is_array($ids)||(!$empty&&!$ids))return null;$out=array();$prior=0;foreach($ids as $id){if(!is_int($id)||$id<1||$id<=$prior)return null;$out[]=$id;$prior=$id;}return $out;}
	private function sha($v){return is_string($v)&&preg_match('/^[a-f0-9]{64}$/D',$v)===1;}
	private function txid($v){return is_string($v)&&preg_match('/^[a-f0-9]{64}$/D',$v)===1;}
	private function eight($v){return is_string($v)&&preg_match('/^(?:0|[1-9][0-9]*)\.[0-9]{8}$/D',$v)===1;}
	private function sats($v){if(!$this->eight($v))throw new RuntimeException('noncanonical_eight_decimal_amount');$parts=explode('.',$v);if(strlen($parts[0])>10)throw new RuntimeException('amount_out_of_bounded_range');return intval($parts[0])*100000000+intval($parts[1]);}
	private function signedSats($v){if(!is_string($v)||!preg_match('/^(-?)(?:0|[1-9][0-9]*)(?:\.[0-9]{1,8})?$/D',$v,$m))throw new RuntimeException('wallet_amount_not_exact_decimal');$negative=$m[1]==='-';$absolute=$negative?substr($v,1):$v;$parts=explode('.',$absolute);$whole=$parts[0];$frac=str_pad(isset($parts[1])?$parts[1]:'',8,'0');if(strlen($whole)>10)throw new RuntimeException('wallet_amount_out_of_bounded_range');$amount=intval($whole)*100000000+intval($frac);return $negative?-$amount:$amount;}
	private function format($sats){return intdiv($sats,100000000).'.'.str_pad((string)($sats%100000000),8,'0',STR_PAD_LEFT);}
	private function projectEight($value){if(!is_string($value)||!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D',$value))throw new RuntimeException('raw_payout_amount_invalid');$parts=explode('.',$value,2);$frac=str_pad(isset($parts[1])?$parts[1]:'',9,'0');$whole=$parts[0];$eight=substr($frac,0,8);$n=$this->sats($whole.'.'.$eight);if((int)$frac[8]>=5)$n++;return $this->format($n);}
	private function add($a,$b){if(!is_string($a)||!is_string($b)||!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D',$a)||!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D',$b))throw new RuntimeException('raw_amount_invalid');$ap=explode('.',$a,2);$bp=explode('.',$b,2);$af=isset($ap[1])?$ap[1]:'';$bf=isset($bp[1])?$bp[1]:'';$scale=max(strlen($af),strlen($bf));$ad=$ap[0].str_pad($af,$scale,'0');$bd=$bp[0].str_pad($bf,$scale,'0');$carry=0;$out='';for($i=strlen($ad)-1,$j=strlen($bd)-1;$i>=0||$j>=0||$carry;$i--,$j--){$n=$carry+($i>=0?ord($ad[$i])-48:0)+($j>=0?ord($bd[$j])-48:0);$out=chr(48+$n%10).$out;$carry=intdiv($n,10);}if($scale){$out=str_pad($out,$scale+1,'0',STR_PAD_LEFT);$out=substr($out,0,-$scale).'.'.substr($out,-$scale);$out=rtrim(rtrim($out,'0'),'.');}$out=ltrim($out,'0');return $out===''?'0':($out[0]==='.'?'0'.$out:$out);}
}
