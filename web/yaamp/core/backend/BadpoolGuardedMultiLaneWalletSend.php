<?php

require_once(dirname(__FILE__).'/BadpoolLivePaymentLaneConfiguration.php');

interface BadpoolExactPayoutRepository
{
	public function loadExactPayouts($payoutIds);
	/** Transactionally reconcile every supplied precondition row or none of them. */
	public function reconcileExact($approvedRows,$txid);
}

interface BadpoolApprovedScopeWalletGateway
{
	/** One non-retrying gateway call for the fully validated approval scope. */
	public function sendmanyApprovedScope($walletBindings,$recipients,$payoutIds);
}

interface BadpoolWalletSendRecoveryEvidence
{
	public function retain($report);
	public function hasPossibleSend($payoutIds);
}

/**
 * Exact-ID, operator-approved wallet-send orchestration.
 *
 * The repository and wallet gateway are deliberately injected.  This class has
 * no database or RPC discovery path and cannot broaden an approved payout set.
 */
class BadpoolGuardedMultiLaneWalletSend
{
	const APPROVAL_SCHEMA = 'badpool.wallet_send.multi_lane_approval.v1';
	const READY_STATE = 'READY_FOR_WALLET_APPROVAL';

	private $repository, $wallet, $registry, $evidence;

	public function __construct($repository, $wallet, $registry=null, $evidence=null)
	{
		if(!($repository instanceof BadpoolExactPayoutRepository))throw new InvalidArgumentException('An exact-payout repository is required.');
		if(!($wallet instanceof BadpoolApprovedScopeWalletGateway))throw new InvalidArgumentException('An approved-scope wallet gateway is required.');
		if(!($evidence instanceof BadpoolWalletSendRecoveryEvidence))throw new InvalidArgumentException('A durable recovery evidence store is required.');
		$this->repository=$repository;
		$this->wallet=$wallet;
		$this->registry=$registry?:new BadpoolLivePaymentLaneRegistry();
		$this->evidence=$evidence;
	}

	public function validateApproval($approval)
	{
		$this->requireExactKeys($approval,array('schema','human_approved','entries'),'approval');
		if($approval['schema']!==self::APPROVAL_SCHEMA)throw new InvalidArgumentException('Unsupported approval schema.');
		if($approval['human_approved']!==true)throw new InvalidArgumentException('Explicit human approval is required.');
		if(!is_array($approval['entries'])||count($approval['entries'])===0)throw new InvalidArgumentException('Approval entries must be non-empty.');

		$required=array('payout_id','lane_id','coin_id','account_id','amount','recipient','wallet_binding_identity','source_account_identity','expected_completed','expected_tx','expected_batch_state');
		$entries=array();$ids=array();$previous=0;
		foreach($approval['entries'] as $index=>$entry){
			$this->requireExactKeys($entry,$required,'approval entry '.$index);
			$id=$this->positiveInt($entry['payout_id'],'payout_id');
			if(isset($ids[$id]))throw new InvalidArgumentException('Duplicate payout ID: '.$id);
			if($id<=$previous)throw new InvalidArgumentException('Approval entries must be in deterministic ascending payout-ID order.');
			$previous=$id;$ids[$id]=true;
			foreach(array('lane_id','wallet_binding_identity','source_account_identity','recipient') as $field)if(!is_string($entry[$field])||trim($entry[$field])==='')throw new InvalidArgumentException('Invalid '.$field.' for payout '.$id.'.');
			$entry['payout_id']=$id;
			$entry['coin_id']=$this->positiveInt($entry['coin_id'],'coin_id');
			$entry['account_id']=$this->positiveInt($entry['account_id'],'account_id');
			$entry['amount']=$this->decimal($entry['amount'],'amount');
			if($entry['expected_completed']!==0)throw new InvalidArgumentException('Expected completed state must be integer 0 for payout '.$id.'.');
			if($entry['expected_tx']!==null)throw new InvalidArgumentException('Expected tx state must be null for payout '.$id.'.');
			if($entry['expected_batch_state']!==self::READY_STATE)throw new InvalidArgumentException('Approval entry is not at the human wallet boundary for payout '.$id.'.');
			$entries[$id]=$entry;
		}

		$rows=$this->repository->loadExactPayouts(array_keys($ids));
		if(!is_array($rows))throw new RuntimeException('Payout repository returned a malformed result.');
		$rowById=array();
		foreach($rows as $row){
			if(!is_array($row)||!array_key_exists('payout_id',$row))throw new RuntimeException('Payout repository returned a malformed row.');
			$id=$this->positiveInt($row['payout_id'],'stored payout_id');
			if(!isset($ids[$id]))throw new RuntimeException('Payout repository returned an unapproved payout: '.$id);
			if(isset($rowById[$id]))throw new RuntimeException('Payout repository returned duplicate payout: '.$id);
			$rowById[$id]=$row;
		}
		if(count($rowById)!==count($entries))throw new RuntimeException('One or more explicitly approved payouts are unknown.');

		$validated=array();$recipients=array();$rawTotal='0';$walletTotal='0';$bindings=array();
		foreach($entries as $id=>$entry){
			$row=$rowById[$id];
			$lane=$this->lane($entry['lane_id']);
			if(!$lane->isPayoutPreparationCommissioned()||!$lane->isHumanApprovedWalletSendEligible())throw new RuntimeException('Lane is not eligible for explicit human-approved wallet send: '.$entry['lane_id']);
			$this->same($lane->coinId(),$entry['coin_id'],'approval coin/lane mismatch',$id);
			$this->same($lane->get('wallet_binding_identity'),$entry['wallet_binding_identity'],'approval wallet binding/lane mismatch',$id);
			$this->same($lane->get('wallet_source_account'),$entry['source_account_identity'],'approval source account/lane mismatch',$id);
			foreach(array('lane_id','coin_id','account_id','amount','recipient','wallet_binding_identity','source_account_identity','completed','tx','batch_state') as $field)if(!array_key_exists($field,$row))throw new RuntimeException('Stored payout is missing '.$field.' for payout '.$id.'.');
			$this->same($entry['lane_id'],$row['lane_id'],'lane mismatch',$id);
			$this->same($entry['coin_id'],$this->positiveInt($row['coin_id'],'stored coin_id'),'coin mismatch',$id);
			$this->same($entry['account_id'],$this->positiveInt($row['account_id'],'stored account_id'),'account mismatch',$id);
			$this->same($entry['amount'],$this->decimal($row['amount'],'stored amount'),'amount mismatch',$id);
			$this->same($entry['recipient'],(string)$row['recipient'],'recipient mismatch',$id);
			$this->same($entry['wallet_binding_identity'],(string)$row['wallet_binding_identity'],'wallet binding mismatch',$id);
			$this->same($entry['source_account_identity'],(string)$row['source_account_identity'],'source account mismatch',$id);
			if((int)$row['completed']!==0)throw new RuntimeException('Payout is already completed: '.$id);
			if($row['tx']!==null)throw new RuntimeException('Payout tx state no longer matches expected null; reconciliation is required before send: '.$id);
			if((string)$row['batch_state']!==self::READY_STATE)throw new RuntimeException('Payout is not owned by a wallet-ready batch: '.$id);
			if(isset($recipients[$entry['recipient']]))throw new RuntimeException('Duplicate recipient combination is not supported: '.$entry['recipient']);
			$walletAmount=$this->projectEight($entry['amount']);
			$recipients[$entry['recipient']]=$walletAmount;
			$rawTotal=$this->add($rawTotal,$entry['amount']);$walletTotal=$this->add($walletTotal,$walletAmount);
			$bindings[$entry['lane_id']]=array('lane_id'=>$entry['lane_id'],'coin_id'=>$entry['coin_id'],'wallet_binding_identity'=>$entry['wallet_binding_identity'],'source_account_identity'=>$entry['source_account_identity']);
			$row['wallet_send_amount']=$walletAmount;$validated[]=$row;
		}
		return array('schema'=>'badpool.wallet_send.validated_scope.v1','human_approved'=>true,'payout_ids'=>array_keys($entries),'rows'=>$validated,'recipients'=>$recipients,'raw_total'=>$rawTotal,'wallet_send_total'=>$walletTotal,'wallet_bindings'=>array_values($bindings));
	}

	public function execute($approval)
	{
		try{$plan=$this->validateApproval($approval);}catch(Exception $e){return $this->failure('pre_send_validation_failed',$e->getMessage());}
		try{if($this->evidence->hasPossibleSend($plan['payout_ids']))return $this->failure('manual_recovery_required','Prior possible wallet success evidence blocks automatic retry.',$plan);}catch(Exception $e){return $this->failure('recovery_evidence_unavailable',$e->getMessage(),$plan);}
		try{$txid=$this->wallet->sendmanyApprovedScope($plan['wallet_bindings'],$plan['recipients'],$plan['payout_ids']);}
		catch(Exception $e){return $this->failure('wallet_rpc_failed',$e->getMessage(),$plan);}
		if(!is_string($txid)||!preg_match('/^[a-fA-F0-9]{64}$/',$txid))return $this->failure('wallet_rpc_failed','Wallet gateway returned no valid transaction ID.',$plan);
		$txid=strtolower($txid);
		$possible=array('status'=>'hold','reason'=>'wallet_txid_returned_reconciliation_pending','wallet_rpc_send_performed'=>true,'wallet_send_success'=>true,'db_completion_success'=>false,'txid'=>$txid,'approved_payout_ids'=>$plan['payout_ids'],'manual_recovery_required'=>true,'do_not_retry'=>true,'recovery_evidence'=>array('validated_scope'=>$plan));
		try{$this->evidence->retain($possible);}catch(Exception $e){$possible['reason']='recovery_evidence_retention_failed';$possible['recovery_evidence']['error']=$e->getMessage();return$possible;}
		try{
			$updated=$this->repository->reconcileExact($plan['rows'],$txid);
			if($updated!==count($plan['payout_ids']))throw new RuntimeException('Exact payout reconciliation count mismatch.');
		}catch(Exception $e){
			$report=$possible;$report['reason']='post_send_reconciliation_failed';$report['recovery_evidence']['error']=$e->getMessage();
			return $report;
		}
		return array('status'=>'pass','reason'=>null,'wallet_rpc_send_performed'=>true,'wallet_send_success'=>true,'db_completion_success'=>true,'txid'=>$txid,'approved_payout_ids'=>$plan['payout_ids'],'completed_payout_ids'=>$plan['payout_ids'],'raw_total'=>$plan['raw_total'],'wallet_send_total'=>$plan['wallet_send_total'],'recipients'=>$plan['recipients'],'do_not_retry'=>true);
	}

	private function failure($reason,$message,$plan=null){return array('status'=>'refused','reason'=>$reason,'error'=>$message,'wallet_rpc_send_performed'=>false,'wallet_send_success'=>false,'db_completion_success'=>false,'db_mutations'=>false,'approved_payout_ids'=>$plan?$plan['payout_ids']:array(),'do_not_retry'=>false);}
	private function lane($id){try{return $this->registry->get($id);}catch(Exception $e){throw new RuntimeException('Unknown lane: '.$id);}}
	private function same($expected,$actual,$label,$id){if($expected!==$actual)throw new RuntimeException($label.' for payout '.$id.'.');}
	private function positiveInt($value,$field){if(is_int($value)&&$value>0)return$value;if(is_string($value)&&preg_match('/^[1-9][0-9]*$/',$value))return(int)$value;throw new InvalidArgumentException('Invalid positive integer '.$field.'.');}
	private function decimal($value,$field){if(!is_string($value)||!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/',$value)||preg_match('/^0(?:\.0+)?$/',$value))throw new InvalidArgumentException('Invalid positive decimal '.$field.'.');return$value;}
	private function requireExactKeys($value,$keys,$label){if(!is_array($value))throw new InvalidArgumentException('Malformed '.$label.'.');$actual=array_keys($value);sort($actual);$expected=$keys;sort($expected);if($actual!==$expected)throw new InvalidArgumentException('Missing or unexpected field in '.$label.'.');}
	private function add($a,$b){$ap=explode('.',$a,2);$bp=explode('.',$b,2);$af=isset($ap[1])?$ap[1]:'';$bf=isset($bp[1])?$bp[1]:'';$scale=max(strlen($af),strlen($bf));$ad=ltrim($ap[0].str_pad($af,$scale,'0'),'0');$bd=ltrim($bp[0].str_pad($bf,$scale,'0'),'0');if($ad==='')$ad='0';if($bd==='')$bd='0';$carry=0;$out='';for($i=strlen($ad)-1,$j=strlen($bd)-1;$i>=0||$j>=0||$carry;$i--,$j--){$sum=$carry+($i>=0?ord($ad[$i])-48:0)+($j>=0?ord($bd[$j])-48:0);$out=chr(48+$sum%10).$out;$carry=intdiv($sum,10);}if($scale){if(strlen($out)<=$scale)$out=str_pad($out,$scale+1,'0',STR_PAD_LEFT);$out=rtrim(rtrim(substr($out,0,-$scale).'.'.substr($out,-$scale),'0'),'.');}$out=ltrim($out,'0');return$out===''?'0':($out[0]==='.'?'0'.$out:$out);}
	private function projectEight($amount){$parts=explode('.',$amount,2);$whole=$parts[0];$frac=str_pad(isset($parts[1])?$parts[1]:'',9,'0');$digits=ltrim($whole.substr($frac,0,8),'0');if($digits==='')$digits='0';if((ord($frac[8])-48)>=5){$carry=1;$out='';for($i=strlen($digits)-1;$i>=0;$i--){$n=ord($digits[$i])-48+$carry;$out=chr(48+($n%10)).$out;$carry=$n>=10?1:0;}$digits=($carry?'1':'').$out;}if(strlen($digits)<=8)$digits=str_pad($digits,9,'0',STR_PAD_LEFT);return substr($digits,0,-8).'.'.substr($digits,-8);}
}
