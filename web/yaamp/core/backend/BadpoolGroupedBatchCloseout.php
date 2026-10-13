<?php

require_once(dirname(__FILE__).'/BadpoolOperationWalletProof.php');

/** Bounded ledger-only coordinator. Wallet proof and batch resume remain authoritative. */
class BadpoolGroupedBatchCloseout
{
	const SCHEMA='badpool.payment_batch.grouped_closeout.v1';
	const CONFIRMATION='reconcile_exact_grouped_batches_ledger_only';
	private $proof,$resume,$runner,$root,$batchRoot;

	public function __construct($proof,$resume,$runner,$root=null,$batchRoot=null)
	{
		if(!is_object($proof)||!is_callable(array($proof,'preview'))||!is_callable($resume)||!is_object($runner)||!is_callable(array($runner,'inspectCompletedPayoutContract')))throw new InvalidArgumentException('Grouped closeout authorities required.');
		$this->proof=$proof;$this->resume=$resume;$this->runner=$runner;
		$this->root=rtrim($root?:dirname(__FILE__).'/../../../../runtime/badpool-grouped-closeouts','/\\');
		$this->batchRoot=rtrim($batchRoot?:dirname(__FILE__).'/../../../../runtime/badpool-payment-batches','/\\');
	}

	public function preview($journalPath,$journalChecksum)
	{
		$p=$this->proof->preview($journalPath,$journalChecksum);
		$r=array('schema'=>self::SCHEMA,'command'=>'grouped-batch-closeout-preview','status'=>'hold','read_only'=>true,'wallet_sends'=>false,'db_mutations'=>false,'batch_count'=>0,'batch_ids'=>array(),'operation_proof_hashes'=>array(),'batch_proof_hashes'=>array(),'journal_path'=>$journalPath,'journal_sha256'=>$journalChecksum,'proof'=>$p,'errors'=>array());
		if(!is_array($p)||arraySafeVal($p,'status')!=='pass'){$r['errors'][]='operation_wallet_proof_incomplete';return $r;}
		foreach($p['operations'] as $op)$r['operation_proof_hashes'][$op['operation_id']]=$op['proof_sha256'];
		foreach($p['batches'] as $b){$r['batch_ids'][]=$b['batch_id'];$r['batch_proof_hashes'][$b['batch_id']]=$b['allocation_sha256'];}
		sort($r['batch_ids'],SORT_STRING);if(!$r['batch_ids']||count($r['batch_ids'])>BadpoolOperationWalletProof::MAX_PAYOUTS){$r['errors'][]='batch_scope_invalid';return $r;}
		ksort($r['operation_proof_hashes'],SORT_STRING);ksort($r['batch_proof_hashes'],SORT_STRING);$r['batch_count']=count($r['batch_ids']);$r['status']='pass';$r['classification']='PASS / GROUPED CLOSEOUT PREVIEW';return $r;
	}

	public function apply($previewPath,$previewChecksum,$confirmation)
	{
		$r=array('schema'=>self::SCHEMA,'command'=>'grouped-batch-closeout-apply','status'=>'hold','ledger_mutation_only'=>true,'wallet_sends'=>false,'db_mutations'=>false,'preview_sha256'=>$previewChecksum,'progress_path'=>null,'batch_results'=>array(),'errors'=>array());
		try{
			if(!hash_equals(self::CONFIRMATION,(string)$confirmation))throw new RuntimeException('operator_confirmation_mismatch');
			if(!is_string($previewChecksum)||!preg_match('/^[a-f0-9]{64}$/D',$previewChecksum)||!is_string($previewPath)||!is_file($previewPath)||is_link($previewPath)||filesize($previewPath)>1048576)throw new RuntimeException('preview_file_invalid');
			$bytes=file_get_contents($previewPath);if(!hash_equals($previewChecksum,hash('sha256',$bytes)))throw new RuntimeException('preview_checksum_mismatch');
			$approved=json_decode($bytes,true);if(!is_array($approved)||arraySafeVal($approved,'schema')!==self::SCHEMA||arraySafeVal($approved,'command')!=='grouped-batch-closeout-preview'||arraySafeVal($approved,'status')!=='pass'||arraySafeVal($approved,'read_only')!==true||arraySafeVal($approved,'wallet_sends')!==false||arraySafeVal($approved,'db_mutations')!==false)throw new RuntimeException('approved_preview_invalid');
			$ids=arraySafeVal($approved,'batch_ids');if(!$this->batchIds($ids)||arraySafeVal($approved,'batch_count')!==count($ids))throw new RuntimeException('approved_batch_scope_invalid');
			$fresh=$this->preview(arraySafeVal($approved,'journal_path'),arraySafeVal($approved,'journal_sha256'));
			if(!$this->sameProof($approved,$fresh))throw new RuntimeException('approved_proof_changed');
			if(is_link($this->root)||(!is_dir($this->root)&&!mkdir($this->root,0770,true)))throw new RuntimeException('progress_root_unsafe');
			$path=$this->root.'/'.$previewChecksum.'.json';$r['progress_path']=$path;$lockPath=$this->root.'/group-closeout.lock';if(is_link($lockPath)||is_link($path))throw new RuntimeException('progress_path_unsafe');
			$lock=fopen($lockPath,'c');if(!$lock)throw new RuntimeException('group_closeout_already_active');if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new RuntimeException('group_closeout_already_active');}
			try{
				$exists=is_file($path);$progress=$exists?json_decode(file_get_contents($path),true):null;
				if($exists&&!is_array($progress))throw new RuntimeException('progress_journal_malformed');
				if($progress===null){$states=array();foreach($ids as $id)$states[$id]='PENDING';$progress=array('schema'=>self::SCHEMA,'preview_sha256'=>$previewChecksum,'journal_sha256'=>$approved['journal_sha256'],'batch_ids'=>$ids,'batch_proof_hashes'=>$approved['batch_proof_hashes'],'states'=>$states,'updated_at'=>gmdate('c'));$this->write($path,$progress);}
				if(!is_array($progress)||arraySafeVal($progress,'schema')!==self::SCHEMA||arraySafeVal($progress,'preview_sha256')!==$previewChecksum||arraySafeVal($progress,'journal_sha256')!==$approved['journal_sha256']||arraySafeVal($progress,'batch_ids')!==$ids||arraySafeVal($progress,'batch_proof_hashes')!==$approved['batch_proof_hashes'])throw new RuntimeException('progress_identity_mismatch');
				if(!is_array(arraySafeVal($progress,'states'))||array_keys($progress['states'])!==$ids)throw new RuntimeException('progress_state_scope_mismatch');
				$activeId=null;
				try{foreach($ids as $id){
					$activeId=$id;
					$fresh=$this->preview($approved['journal_path'],$approved['journal_sha256']);
					if(!$this->sameProof($approved,$fresh))throw new RuntimeException('approved_proof_changed_before_batch_'.$id);
					$state=arraySafeVal($progress['states'],$id);if(!in_array($state,array('PENDING','APPLYING','VERIFIED','HOLD'),true))throw new RuntimeException('progress_state_invalid');
					$batch=$this->batchProof($fresh['proof']['batches'],$id);if(!$batch)throw new RuntimeException('batch_proof_missing');
					if($state==='VERIFIED'){if(!$this->terminal($id,$batch))throw new RuntimeException('verified_batch_changed_'.$id);$r['batch_results'][$id]='already_verified';continue;}
					if($state==='HOLD')throw new RuntimeException('group_on_manual_hold_'.$id);
					$check=$this->runner->inspectCompletedPayoutContract($id);if(arraySafeVal($check,'status')!=='pass')throw new RuntimeException('batch_contract_changed_'.$id);
					if($check['batch_state']==='RECONCILED'){
						if(!$this->terminal($id,$batch))throw new RuntimeException('applying_batch_terminal_mismatch_'.$id);
						$progress['states'][$id]='VERIFIED';$this->write($path,$progress);$r['batch_results'][$id]='recovered_verified';continue;
					}
					if($state==='PENDING'){$progress['states'][$id]='APPLYING';$this->write($path,$progress);}
					if($check['batch_state']==='READY_FOR_WALLET_APPROVAL'){
						$resumed=call_user_func($this->resume,$id,$check['operational_algo']);
						if(!is_array($resumed)||arraySafeVal($resumed,'batch_state')!=='HOLD_COMPLETED_PAYOUT_RECONCILIATION')throw new RuntimeException('batch_resume_boundary_failed_'.$id);
						$check=$this->runner->inspectCompletedPayoutContract($id);
						if(arraySafeVal($check,'status')!=='pass'||$check['batch_state']!=='HOLD_COMPLETED_PAYOUT_RECONCILIATION')throw new RuntimeException('batch_resume_not_verified_'.$id);
					}
					if($check['batch_state']!=='HOLD_COMPLETED_PAYOUT_RECONCILIATION')throw new RuntimeException('batch_not_at_closeout_boundary_'.$id);
					$fresh=$this->preview($approved['journal_path'],$approved['journal_sha256']);
					if(!$this->sameProof($approved,$fresh))throw new RuntimeException('approved_proof_changed_after_resume_'.$id);
					$proofPath=$this->root.'/'.$previewChecksum.'.'.$id.'.proof.json';if(is_link($proofPath))throw new RuntimeException('batch_proof_path_unsafe');$proofBytes=json_encode($batch,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
					if(is_file($proofPath)){if(file_get_contents($proofPath)!==$proofBytes)throw new RuntimeException('batch_proof_file_changed');}else $this->writeBytes($proofPath,$proofBytes);
					$proofSha=hash('sha256',$proofBytes);$this->applyLedger($id,$batch,$proofSha);
					if(!$this->terminal($id,$batch,$proofSha))throw new RuntimeException('batch_ledger_closeout_not_verified_'.$id);
					$progress['states'][$id]='VERIFIED';$this->write($path,$progress);$r['batch_results'][$id]='verified';
				}}catch(Exception $e){if($activeId!==null&&arraySafeVal($progress['states'],$activeId)==='APPLYING'){$progress['states'][$activeId]='HOLD';$progress['hold_reason']=$e->getMessage();$this->write($path,$progress);}throw $e;}
				$r['status']='pass';$r['classification']='PASS / GROUPED LEDGERS RECONCILED';
			}finally{flock($lock,LOCK_UN);fclose($lock);}
		}catch(Exception $e){$r['errors'][]=$e->getMessage();$r['classification']='HOLD / GROUPED CLOSEOUT INCOMPLETE';}
		return $r;
	}

	/** Only the verified grouped flow calls this private ledger transition. */
	private function applyLedger($id,$batch,$proofSha)
	{
		$check=$this->runner->inspectCompletedPayoutContract($id);
		if(arraySafeVal($check,'status')!=='pass'||arraySafeVal($check,'batch_state')!=='HOLD_COMPLETED_PAYOUT_RECONCILIATION'||arraySafeVal($check,'created_payout_ids')!==arraySafeVal($batch,'created_payout_ids')||arraySafeVal($check,'lane_id')!==arraySafeVal($batch,'lane_id')||arraySafeVal($check,'coin_id')!==arraySafeVal($batch,'coin_id')||arraySafeVal($batch,'phase_0_6_verified')!==true||BadpoolMultiWalletApprovalPlanner::checksum(arraySafeVal($check['ledger'],'checksums'))!==arraySafeVal($batch,'phase_checksums_sha256'))throw new RuntimeException('batch_contract_changed_before_ledger_apply_'.$id);
		$dir=$this->batchRoot.'/'.$id;$path=$dir.'/ledger.json';
		if(is_link($this->batchRoot)||is_link($dir)||is_link($path)||!is_file($path)||realpath($this->batchRoot)!==dirname(realpath($dir)))throw new RuntimeException('batch_ledger_path_unsafe_'.$id);
		if(!hash_equals($check['ledger_sha256'],hash_file('sha256',$path)))throw new RuntimeException('batch_ledger_changed_before_apply_'.$id);
		$ledger=$check['ledger'];$ledger['batch_state']='RECONCILED';$ledger['reconciled_at']=gmdate('c');$ledger['reconciliation_proof_checksum']=$proofSha;$ledger['reconciled_payout_ids']=$batch['created_payout_ids'];
		$this->writeBytes($path,json_encode($ledger,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
	}

	private function terminal($id,$batch,$expectedSha=null)
	{
		$check=$this->runner->inspectCompletedPayoutContract($id);if(arraySafeVal($check,'status')!=='pass'||arraySafeVal($check,'batch_state')!=='RECONCILED')return false;
		$ledger=$check['ledger'];$ids=arraySafeVal($batch,'created_payout_ids');if(arraySafeVal($ledger,'reconciled_payout_ids')!==$ids)return false;
		$proofSha=hash('sha256',json_encode($batch,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
		return arraySafeVal($ledger,'reconciliation_proof_checksum')===$proofSha&&($expectedSha===null||$expectedSha===$proofSha);
	}
	private function batchProof($batches,$id){foreach((array)$batches as $batch)if(is_array($batch)&&arraySafeVal($batch,'batch_id')===$id)return $batch;return null;}
	private function sameProof($a,$b){return is_array($b)&&arraySafeVal($b,'status')==='pass'&&$this->stablePreview($a)===$this->stablePreview($b);}
	private function stablePreview($preview){if(!is_array($preview)||!is_array(arraySafeVal($preview,'proof'))||!is_array(arraySafeVal($preview['proof'],'operations')))return null;foreach($preview['proof']['operations'] as &$operation)if(is_array($operation))unset($operation['confirmations']);unset($operation);
        $fields=array(
            'schema','command','status','classification',
            'read_only','wallet_sends','db_mutations',
            'batch_count','batch_ids','operation_proof_hashes',
            'batch_proof_hashes','journal_path','journal_sha256',
            'proof','errors'
        );
        return array_intersect_key($preview,array_fill_keys($fields,true));}
	private function batchIds($ids){if(!is_array($ids)||!$ids||count($ids)>BadpoolOperationWalletProof::MAX_PAYOUTS)return false;$last='';foreach($ids as $id){if(!is_string($id)||!preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/D',$id)||$id<=$last)return false;$last=$id;}return true;}
	private function write($path,$data){$data['updated_at']=gmdate('c');$this->writeBytes($path,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");}
	private function writeBytes($path,$bytes){$tmp=$path.'.tmp.'.getmypid().'.'.bin2hex(random_bytes(8));if(!is_string($bytes)||is_link($path)||is_link($tmp))throw new RuntimeException('durable_group_write_failed');$h=@fopen($tmp,'x');if(!$h)throw new RuntimeException('durable_group_write_failed');$ok=fwrite($h,$bytes)===strlen($bytes)&&fflush($h)&&(!function_exists('fsync')||fsync($h));fclose($h);if(!$ok||!@rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('durable_group_write_failed');}}
}
