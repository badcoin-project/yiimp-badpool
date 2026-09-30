<?php

require_once(dirname(__FILE__).'/BadpoolGuardedMultiWalletSend.php');

/**
 * Production exact-ID adapter. Payout authority comes only from the requested
 * IDs; durable coordinator ledgers prove which live lane created each row.
 */
class BadpoolYiiExactMultiWalletPayoutRepository implements BadpoolExactMultiWalletPayoutRepository
{
	private $db,$root,$registry;
	public function __construct($db,$runtimeRoot=null,$registry=null)
	{
		if(!is_object($db)||!is_callable(array($db,'createCommand'))||!is_callable(array($db,'beginTransaction')))throw new InvalidArgumentException('Yii database connection required.');
		$this->db=$db;$this->root=rtrim($runtimeRoot?:dirname(__FILE__).'/../../../../runtime/badpool-payment-batches','/\\');$this->registry=$registry?:new BadpoolLivePaymentLaneRegistry();
	}

	public function loadExactPayouts($payoutIds)
	{
		$ids=$this->ids($payoutIds);$rows=$this->queryRows($ids,false);return$this->bindOwnership($ids,$rows);
	}

	public function reconcileExactWithTransactionIds($approvedRows,$txidByPayoutId)
	{
		$ids=array();$expected=array();foreach((array)$approvedRows as$row){if(!is_array($row)||!isset($row['payout_id']))throw new InvalidArgumentException('Malformed approved payout row.');$id=intval($row['payout_id']);if($id<1||isset($expected[$id]))throw new InvalidArgumentException('Invalid or duplicate approved payout ID.');$ids[]=$id;$expected[$id]=$row;}
		$ids=$this->ids($ids);if(array_keys($txidByPayoutId)!==$ids){$actual=array_map('intval',array_keys((array)$txidByPayoutId));sort($actual,SORT_NUMERIC);if($actual!==$ids)throw new InvalidArgumentException('Transaction map must bind every exact payout ID.');}
		foreach($ids as$id)if(!isset($txidByPayoutId[$id])||!is_string($txidByPayoutId[$id])||!preg_match('/^[a-fA-F0-9]{64}$/',$txidByPayoutId[$id]))throw new InvalidArgumentException('Invalid transaction ID for payout '.$id.'.');
		$tx=$this->db->beginTransaction();try{$current=$this->bindOwnership($ids,$this->queryRows($ids,true));$byId=array();foreach($current as$row)$byId[$row['payout_id']]=$row;if(count($byId)!==count($ids))throw new RuntimeException('Exact payout rows changed before reconciliation.');$updated=0;
			foreach($ids as$id){$this->samePreconditions($expected[$id],$byId[$id]);$n=$this->db->createCommand('UPDATE payouts SET completed=1, tx=:txid WHERE id=:id AND account_id=:account_id AND idcoin=:coin_id AND amount=:amount AND IFNULL(completed,0)=0 AND tx IS NULL')->execute(array(':txid'=>strtolower($txidByPayoutId[$id]),':id'=>$id,':account_id'=>$expected[$id]['account_id'],':coin_id'=>$expected[$id]['coin_id'],':amount'=>$expected[$id]['amount']));if($n!==1)throw new RuntimeException('Exact payout reconciliation precondition failed for payout '.$id.'.');$updated++;}
			$tx->commit();return$updated;
		}catch(Exception$e){if(is_object($tx)&&is_callable(array($tx,'rollback')))try{$tx->rollback();}catch(Exception$ignored){}throw$e;}
	}

	private function queryRows($ids,$forUpdate)
	{
		$params=array();$marks=array();foreach($ids as$i=>$id){$key=':mw_payout_'.$i;$marks[]=$key;$params[$key]=$id;}
		$sql='SELECT P.id AS payout_id,P.account_id,A.id AS joined_account_id,P.idcoin AS coin_id,CAST(P.amount AS CHAR) AS amount,IFNULL(P.completed,0) AS completed,P.tx,A.username AS recipient,C.id AS joined_coin_id FROM payouts P INNER JOIN accounts A ON A.id=P.account_id INNER JOIN coins C ON C.id=P.idcoin WHERE P.id IN ('.implode(',',$marks).') ORDER BY P.id'.($forUpdate?' FOR UPDATE':'');
		$rows=$this->db->createCommand($sql)->queryAll(true,$params);if(!is_array($rows)||count($rows)!==count($ids))throw new RuntimeException('Every explicit payout ID must resolve with its account and coin.');return$rows;
	}

	private function bindOwnership($ids,$rows)
	{
		$owners=array();foreach($this->registry->all()as$lane){$state=$this->readJson($lane->statePath($this->root));if(!$this->validActiveState($state,$lane))continue;$batchId=$state['active_batch_id'];if(!is_string($batchId)||!preg_match('/^[A-Za-z0-9._-]+$/',$batchId))continue;$ledger=$this->readJson($this->root.'/'.$batchId.'/ledger.json');if(!is_array($ledger)||@$ledger['batch_id']!==$batchId||@$ledger['batch_state']!==BadpoolMultiWalletApprovalPlanner::READY_STATE||!$lane->ownershipMatches(isset($ledger['coordinator_owner'])?$ledger['coordinator_owner']:null))continue;$created=$this->ids(isset($ledger['created_payout_ids'])?$ledger['created_payout_ids']:array(),true);foreach($created as$id){if(!in_array($id,$ids,true))continue;if(isset($owners[$id]))throw new RuntimeException('Payout '.$id.' has ambiguous durable lane ownership.');$owners[$id]=array($lane,$batchId);}}
		$out=array();$seen=array();foreach($rows as$row){$id=intval($row['payout_id']);if(!in_array($id,$ids,true)||isset($seen[$id]))throw new RuntimeException('Repository returned an unexpected or duplicate payout.');$seen[$id]=true;if(!isset($owners[$id]))throw new RuntimeException('Payout '.$id.' lacks active READY_FOR_WALLET_APPROVAL ledger ownership.');list($lane,$batchId)=$owners[$id];if(intval($row['account_id'])<1||intval($row['joined_account_id'])!==intval($row['account_id']))throw new RuntimeException('Payout '.$id.' account ownership mismatch.');if((string)$row['recipient']==='')throw new RuntimeException('Payout '.$id.' recipient is missing.');if(intval($row['coin_id'])!==$lane->coinId()||intval($row['joined_coin_id'])!==intval($row['coin_id']))throw new RuntimeException('Payout '.$id.' coin/lane ownership mismatch.');if(intval($row['completed'])!==0||$row['tx']!==null)throw new RuntimeException('Payout '.$id.' is no longer unpaid.');$out[]=array('payout_id'=>$id,'account_id'=>intval($row['account_id']),'amount'=>(string)$row['amount'],'completed'=>0,'tx'=>null,'recipient'=>(string)$row['recipient'],'lane_id'=>$lane->laneId(),'coin_id'=>$lane->coinId(),'wallet_binding_identity'=>$lane->get('wallet_binding_identity'),'source_account_identity'=>$lane->get('wallet_source_account'),'batch_id'=>$batchId,'batch_state'=>BadpoolMultiWalletApprovalPlanner::READY_STATE);}
		if(count($out)!==count($ids))throw new RuntimeException('Exact payout ownership count mismatch.');return$out;
	}

	private function validActiveState($state,$lane){return is_array($state)&&@$state['schema']===$lane->get('ownership_schema')&&intval(@$state['version'])===1&&@$state['lane']===$lane->laneId()&&intval(@$state['coin_id'])===$lane->coinId()&&@$state['algo']===$lane->dbAlgo()&&intval(@$state['block_id_gt'])===$lane->blockBoundary()&&@$state['last_observed_batch_state']===BadpoolMultiWalletApprovalPlanner::READY_STATE&&is_string(@$state['active_batch_id'])&&@$state['active_batch_id']!=='';}
	private function readJson($path){if(is_link($this->root)||is_link($path)||!is_file($path))return null;$v=json_decode(@file_get_contents($path),true);return is_array($v)?$v:null;}
	private function ids($values,$emptyAllowed=false){if(!is_array($values)||(!$emptyAllowed&&!count($values)))throw new InvalidArgumentException('Explicit payout IDs are required.');$out=array();foreach($values as$v){if(is_int($v)&&$v>0)$id=$v;elseif(is_string($v)&&preg_match('/^[1-9][0-9]*$/',$v))$id=intval($v);else throw new InvalidArgumentException('Invalid payout ID.');if(in_array($id,$out,true))throw new InvalidArgumentException('Duplicate payout ID: '.$id);$out[]=$id;}sort($out,SORT_NUMERIC);return$out;}
	private function samePreconditions($expected,$actual){foreach(array('payout_id','account_id','amount','completed','tx','recipient','lane_id','coin_id','wallet_binding_identity','source_account_identity','batch_id','batch_state')as$key)if(!array_key_exists($key,$expected)||!array_key_exists($key,$actual)||$expected[$key]!==$actual[$key])throw new RuntimeException('Payout reconciliation precondition changed: '.$key.'.');if($actual['completed']!==0||$actual['tx']!==null||$actual['batch_state']!==BadpoolMultiWalletApprovalPlanner::READY_STATE)throw new RuntimeException('Payout is no longer reconcilable.');}
}
