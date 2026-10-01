<?php

require_once(dirname(__FILE__).'/BadpoolLivePaymentLaneConfiguration.php');

interface BadpoolExactMultiWalletPayoutRepository
{
	public function loadExactPayouts($payoutIds);
	/** Reconcile every approved payout, with its wallet-specific txid, or none. */
	public function reconcileExactWithTransactionIds($approvedRows,$txidByPayoutId);
}

interface BadpoolPerWalletOperationGateway
{
	/** Read-only readiness check. No send method may be called from this method. */
	public function preflightApprovedWalletOperation($operation);
	/** Exactly one call for exactly one durable, capability-bound wallet operation. */
	public function sendApprovedWalletOperation($operation,$capability);
}

/**
 * One-use value object for a future narrow WalletRPC bypass.  It deliberately
 * does not alter wallet-send-guard.php and provides no RPC implementation.
 */
final class BadpoolWalletOperationCapability
{
	private $binding;
	private $consumed=false;

	public function __construct($approvalChecksum,$operation)
	{
		$this->binding=self::binding($approvalChecksum,$operation);
	}

	public function claim($approvalChecksum,$operation)
	{
		if($this->consumed)throw new RuntimeException('Wallet operation capability has already been consumed.');
		if(!hash_equals($this->binding,self::binding($approvalChecksum,$operation)))throw new RuntimeException('Wallet operation capability binding mismatch.');
		$this->consumed=true;
		return true;
	}

	private static function binding($approvalChecksum,$operation)
	{
		$bound=array(
			'approval_checksum'=>$approvalChecksum,
			'operation_id'=>isset($operation['operation_id'])?$operation['operation_id']:null,
			'wallet_binding_identity'=>isset($operation['wallet_binding_identity'])?$operation['wallet_binding_identity']:null,
			'source_account_identity'=>isset($operation['source_account_identity'])?$operation['source_account_identity']:null,
			'payout_ids'=>isset($operation['payout_ids'])?$operation['payout_ids']:null,
			'recipients'=>isset($operation['recipients'])?$operation['recipients']:null,
		);
		return hash('sha256',json_encode(BadpoolMultiWalletApprovalPlanner::canonicalize($bound),JSON_UNESCAPED_SLASHES));
	}
}

/** Validates one global approval and partitions it into deterministic wallets. */
class BadpoolMultiWalletApprovalPlanner
{
	const APPROVAL_SCHEMA='badpool.wallet_send.multi_lane_approval.v1';
	const PLAN_SCHEMA='badpool.wallet_send.multi_wallet_plan.v1';
	const READY_STATE='READY_FOR_WALLET_APPROVAL';
	private $repository,$registry;

	public function __construct($repository,$registry=null)
	{
		if(!($repository instanceof BadpoolExactMultiWalletPayoutRepository))throw new InvalidArgumentException('An exact multi-wallet payout repository is required.');
		$this->repository=$repository;$this->registry=$registry?:new BadpoolLivePaymentLaneRegistry();
	}

	public function build($approval)
	{
		return $this->buildInternal($approval,true);
	}

	/** Build a deterministic proposal without granting execution authority. */
	public function buildPreflightProposal($approval)
	{
		return $this->buildInternal($approval,false);
	}

	private function buildInternal($approval,$requireHumanApproval)
	{
		$this->exactKeys($approval,array('schema','human_approved','entries'),'approval');
		if($approval['schema']!==self::APPROVAL_SCHEMA)throw new InvalidArgumentException('Unsupported approval schema.');
		if($requireHumanApproval&&$approval['human_approved']!==true)throw new InvalidArgumentException('Explicit human approval is required.');
		if(!$requireHumanApproval&&$approval['human_approved']!==false)throw new InvalidArgumentException('Preflight proposal must remain non-authorizing.');
		if(!is_array($approval['entries'])||!count($approval['entries']))throw new InvalidArgumentException('Approval entries must be non-empty.');
		$required=array('payout_id','lane_id','coin_id','account_id','amount','recipient','wallet_binding_identity','source_account_identity','expected_completed','expected_tx','expected_batch_state');
		$entries=array();$previous=0;
		foreach($approval['entries'] as $index=>$entry){
			$this->exactKeys($entry,$required,'approval entry '.$index);$id=$this->positiveInt($entry['payout_id'],'payout_id');
			if(isset($entries[$id]))throw new InvalidArgumentException('Duplicate payout ID: '.$id);
			if($id<=$previous)throw new InvalidArgumentException('Approval entries must be in ascending payout-ID order.');$previous=$id;
			foreach(array('lane_id','recipient','wallet_binding_identity','source_account_identity')as$field)if(!is_string($entry[$field])||trim($entry[$field])==='')throw new InvalidArgumentException('Invalid '.$field.' for payout '.$id.'.');
			$entry['payout_id']=$id;$entry['coin_id']=$this->positiveInt($entry['coin_id'],'coin_id');$entry['account_id']=$this->positiveInt($entry['account_id'],'account_id');$entry['amount']=$this->decimal($entry['amount'],'amount');
			if($entry['expected_completed']!==0||$entry['expected_tx']!==null||$entry['expected_batch_state']!==self::READY_STATE)throw new InvalidArgumentException('Payout '.$id.' is not approved at the wallet boundary.');
			$entries[$id]=$entry;
		}
		$rows=$this->repository->loadExactPayouts(array_keys($entries));if(!is_array($rows))throw new RuntimeException('Payout repository returned a malformed result.');
		$byId=array();foreach($rows as$row){if(!is_array($row)||!isset($row['payout_id']))throw new RuntimeException('Payout repository returned a malformed row.');$id=$this->positiveInt($row['payout_id'],'stored payout_id');if(!isset($entries[$id]))throw new RuntimeException('Repository returned an unapproved payout: '.$id);if(isset($byId[$id]))throw new RuntimeException('Repository returned duplicate payout: '.$id);$byId[$id]=$row;}
		if(count($byId)!==count($entries))throw new RuntimeException('One or more explicitly approved payouts are unknown.');

		$approvalChecksum=self::checksum($approval);$rawTotal='0';$walletTotal='0';$validated=array();$groups=array();
		foreach($entries as$id=>$entry){
			$row=$byId[$id];$lane=$this->lane($entry['lane_id']);
			if(!$lane->isPayoutPreparationCommissioned()||!$lane->isHumanApprovedWalletSendEligible())throw new RuntimeException('Lane is not eligible for human-approved wallet send: '.$entry['lane_id']);
			if(!$lane->get('rpc_config_identity')||!$lane->get('wallet_datadir_identity'))throw new RuntimeException('Lane lacks an explicit production RPC identity: '.$entry['lane_id']);
			$this->same($lane->coinId(),$entry['coin_id'],'approval coin/lane mismatch',$id);$this->same($lane->get('wallet_binding_identity'),$entry['wallet_binding_identity'],'approval wallet/lane mismatch',$id);$this->same($lane->get('wallet_source_account'),$entry['source_account_identity'],'approval source/lane mismatch',$id);
			foreach(array('lane_id','coin_id','account_id','amount','recipient','wallet_binding_identity','source_account_identity','completed','tx','batch_state')as$field)if(!array_key_exists($field,$row))throw new RuntimeException('Stored payout missing '.$field.' for payout '.$id.'.');
			$this->same($entry['lane_id'],(string)$row['lane_id'],'lane mismatch',$id);$this->same($entry['coin_id'],$this->positiveInt($row['coin_id'],'stored coin_id'),'coin mismatch',$id);$this->same($entry['account_id'],$this->positiveInt($row['account_id'],'stored account_id'),'account mismatch',$id);$this->same($entry['amount'],$this->decimal($row['amount'],'stored amount'),'amount mismatch',$id);$this->same($entry['recipient'],(string)$row['recipient'],'recipient mismatch',$id);$this->same($entry['wallet_binding_identity'],(string)$row['wallet_binding_identity'],'wallet mismatch',$id);$this->same($entry['source_account_identity'],(string)$row['source_account_identity'],'source mismatch',$id);
			if((int)$row['completed']!==0||$row['tx']!==null||(string)$row['batch_state']!==self::READY_STATE)throw new RuntimeException('Stored payout precondition changed for payout '.$id.'.');
			$walletAmount=$this->projectEight($entry['amount']);$row['wallet_send_amount']=$walletAmount;$validated[]=$row;$rawTotal=$this->add($rawTotal,$entry['amount']);$walletTotal=$this->add($walletTotal,$walletAmount);
			$key=$entry['wallet_binding_identity']."\n".$entry['source_account_identity'];if(!isset($groups[$key]))$groups[$key]=array('lane_id'=>$entry['lane_id'],'coin_id'=>$entry['coin_id'],'wallet_binding_identity'=>$entry['wallet_binding_identity'],'source_account_identity'=>$entry['source_account_identity'],'rpc_config_identity'=>$lane->get('rpc_config_identity'),'wallet_datadir_identity'=>$lane->get('wallet_datadir_identity'),'payout_ids'=>array(),'recipients'=>array(),'raw_total'=>'0','wallet_send_total'=>'0');
			if(isset($groups[$key]['recipients'][$entry['recipient']]))throw new RuntimeException('Duplicate recipient inside one wallet operation is refused: '.$entry['recipient']);
			$groups[$key]['payout_ids'][]=$id;$groups[$key]['recipients'][$entry['recipient']]=$walletAmount;$groups[$key]['raw_total']=$this->add($groups[$key]['raw_total'],$entry['amount']);$groups[$key]['wallet_send_total']=$this->add($groups[$key]['wallet_send_total'],$walletAmount);
		}
		$operations=array_values($groups);usort($operations,function($a,$b){$x=$a['payout_ids'][0];$y=$b['payout_ids'][0];if($x===$y)return strcmp($a['wallet_binding_identity'],$b['wallet_binding_identity']);return$x<$y?-1:1;});
		foreach($operations as$i=>&$operation){$operation['sequence']=$i+1;$operation['approval_checksum']=$approvalChecksum;$operation['operation_id']='wallet-op-'.substr(self::checksum(array('approval_checksum'=>$approvalChecksum,'wallet_binding_identity'=>$operation['wallet_binding_identity'],'source_account_identity'=>$operation['source_account_identity'],'payout_ids'=>$operation['payout_ids'],'recipients'=>$operation['recipients'])),0,24);}unset($operation);
		$plan=array('schema'=>self::PLAN_SCHEMA,'approval_checksum'=>$approvalChecksum,'approval_document'=>$approval,'payout_ids'=>array_keys($entries),'rows'=>$validated,'operations'=>$operations,'raw_total'=>$rawTotal,'wallet_send_total'=>$walletTotal);
		$plan['plan_checksum']=self::checksum($plan);return$plan;
	}

	public static function checksum($value){return hash('sha256',json_encode(self::canonicalize($value),JSON_UNESCAPED_SLASHES));}
	public static function canonicalize($value){if(!is_array($value))return$value;$list=array_keys($value)===range(0,count($value)-1);$out=array();foreach($value as$k=>$v)$out[$k]=self::canonicalize($v);if(!$list)ksort($out,SORT_STRING);return$out;}
	private function lane($id){try{return$this->registry->get($id);}catch(Exception$e){throw new RuntimeException('Unknown lane: '.$id);}}
	private function exactKeys($v,$keys,$label){if(!is_array($v))throw new InvalidArgumentException('Malformed '.$label.'.');$a=array_keys($v);$e=$keys;sort($a);sort($e);if($a!==$e)throw new InvalidArgumentException('Missing or unexpected field in '.$label.'.');}
	private function positiveInt($v,$field){if(is_int($v)&&$v>0)return$v;if(is_string($v)&&preg_match('/^[1-9][0-9]*$/',$v))return(int)$v;throw new InvalidArgumentException('Invalid positive integer '.$field.'.');}
	private function decimal($v,$field){if(!is_string($v)||!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/',$v)||preg_match('/^0(?:\.0+)?$/',$v))throw new InvalidArgumentException('Invalid positive decimal '.$field.'.');return$v;}
	private function same($e,$a,$label,$id){if($e!==$a)throw new RuntimeException($label.' for payout '.$id.'.');}
	private function add($a,$b){$ap=explode('.',$a,2);$bp=explode('.',$b,2);$af=isset($ap[1])?$ap[1]:'';$bf=isset($bp[1])?$bp[1]:'';$s=max(strlen($af),strlen($bf));$ad=ltrim($ap[0].str_pad($af,$s,'0'),'0');$bd=ltrim($bp[0].str_pad($bf,$s,'0'),'0');if($ad==='')$ad='0';if($bd==='')$bd='0';$c=0;$o='';for($i=strlen($ad)-1,$j=strlen($bd)-1;$i>=0||$j>=0||$c;$i--,$j--){$n=$c+($i>=0?ord($ad[$i])-48:0)+($j>=0?ord($bd[$j])-48:0);$o=chr(48+$n%10).$o;$c=intdiv($n,10);}if($s){if(strlen($o)<=$s)$o=str_pad($o,$s+1,'0',STR_PAD_LEFT);$o=rtrim(rtrim(substr($o,0,-$s).'.'.substr($o,-$s),'0'),'.');}$o=ltrim($o,'0');return$o===''?'0':($o[0]==='.'?'0'.$o:$o);}
	private function projectEight($a){$p=explode('.',$a,2);$whole=$p[0];$frac=str_pad(isset($p[1])?$p[1]:'',9,'0');$d=ltrim($whole.substr($frac,0,8),'0');if($d==='')$d='0';if((ord($frac[8])-48)>=5){$c=1;$o='';for($i=strlen($d)-1;$i>=0;$i--){$n=ord($d[$i])-48+$c;$o=chr(48+$n%10).$o;$c=$n>=10?1:0;}$d=($c?'1':'').$o;}if(strlen($d)<=8)$d=str_pad($d,9,'0',STR_PAD_LEFT);return substr($d,0,-8).'.'.substr($d,-8);}
}

/** Durable, atomic, symlink-refusing journal keyed by global approval checksum. */
class BadpoolDurableMultiWalletSendJournal
{
	const SCHEMA='badpool.wallet_send.multi_wallet_journal.v1';
	private $root;
	public function __construct($root=null){$root=$root?:dirname(__FILE__).'/../../../../runtime/badpool-wallet-send-journals';if(!is_string($root)||trim($root)==='')throw new InvalidArgumentException('Journal root required.');$this->root=rtrim($root,'/\\');$this->ensureRoot();}
	public function path($checksum){$this->safeChecksum($checksum);return$this->root.'/'.$checksum.'.json';}
	public function exists($checksum){$p=$this->path($checksum);if(is_link($p))throw new RuntimeException('Journal symlink refused.');return is_file($p);}
	public function prepare($plan){$checksum=$plan['approval_checksum'];return$this->locked($checksum,function()use($checksum,$plan){if($this->exists($checksum)){$j=$this->loadUnlocked($checksum);if($j['plan_checksum']!==$plan['plan_checksum'])throw new RuntimeException('Conflicting journal already exists for approval scope.');return$j;}$ops=array();foreach($plan['operations']as$op)$ops[$op['operation_id']]=array('operation_id'=>$op['operation_id'],'state'=>'PENDING','attempt_started_at'=>null,'txid'=>null,'updated_at'=>gmdate('c'),'error'=>null);$j=array('schema'=>self::SCHEMA,'approval_checksum'=>$checksum,'plan_checksum'=>$plan['plan_checksum'],'global_state'=>'PREPARED','payout_ids'=>$plan['payout_ids'],'operations'=>$ops,'created_at'=>gmdate('c'),'updated_at'=>gmdate('c'),'hold_reason'=>null);$this->write($checksum,$j,false);return$j;});}
	public function load($checksum){return$this->locked($checksum,function()use($checksum){return$this->loadUnlocked($checksum);});}
	public function startAttempt($checksum,$operationId){return$this->transition($checksum,$operationId,array('PENDING'),'SEND_ATTEMPT_STARTED',function(&$o){$o['attempt_started_at']=gmdate('c');});}
	public function recordTxid($checksum,$operationId,$txid){if(!is_string($txid)||!preg_match('/^[a-fA-F0-9]{64}$/',$txid))throw new InvalidArgumentException('Invalid wallet txid.');return$this->transition($checksum,$operationId,array('SEND_ATTEMPT_STARTED'),'TXID_RETURNED',function(&$o)use($txid){$o['txid']=strtolower($txid);});}
	public function recordUncertain($checksum,$operationId,$error){return$this->transition($checksum,$operationId,array('SEND_ATTEMPT_STARTED'),'UNCERTAIN',function(&$o)use($error){$o['error']=(string)$error;});}
	public function recordDefiniteFailure($checksum,$operationId,$error){return$this->transition($checksum,$operationId,array('SEND_ATTEMPT_STARTED'),'DEFINITE_FAILURE',function(&$o)use($error){$o['error']=(string)$error;});}
	public function readyForReconciliation($checksum){return$this->mutate($checksum,function(&$j){foreach($j['operations']as$o)if($o['state']!=='TXID_RETURNED')throw new RuntimeException('All wallet txids are required before reconciliation.');$j['global_state']='READY_FOR_RECONCILIATION';});}
	public function reconciled($checksum){return$this->mutate($checksum,function(&$j){if($j['global_state']!=='READY_FOR_RECONCILIATION')throw new RuntimeException('Journal is not ready for reconciliation.');foreach($j['operations']as&$o){$o['state']='RECONCILED';$o['updated_at']=gmdate('c');}unset($o);$j['global_state']='RECONCILED';});}
	public function hold($checksum,$reason){return$this->mutate($checksum,function(&$j)use($reason){$j['global_state']='HOLD_MANUAL_RECOVERY';$j['hold_reason']=(string)$reason;});}
	private function transition($checksum,$id,$from,$to,$change){return$this->mutate($checksum,function(&$j)use($id,$from,$to,$change){if(!isset($j['operations'][$id]))throw new RuntimeException('Unknown wallet operation.');if(!in_array($j['operations'][$id]['state'],$from,true))throw new RuntimeException('Wallet operation transition refused from '.$j['operations'][$id]['state'].'.');$change($j['operations'][$id]);$j['operations'][$id]['state']=$to;$j['operations'][$id]['updated_at']=gmdate('c');$j['global_state']=in_array($to,array('UNCERTAIN','DEFINITE_FAILURE'),true)?'HOLD_MANUAL_RECOVERY':'IN_PROGRESS';if($j['global_state']==='HOLD_MANUAL_RECOVERY')$j['hold_reason']=strtolower($to);});}
	private function mutate($checksum,$change){return$this->locked($checksum,function()use($checksum,$change){$j=$this->loadUnlocked($checksum);$change($j);$j['updated_at']=gmdate('c');$this->write($checksum,$j,true);return$j;});}
	private function loadUnlocked($checksum){$p=$this->path($checksum);if(is_link($p)||!is_file($p))throw new RuntimeException('Journal missing or unsafe.');$j=json_decode(file_get_contents($p),true);$valid=is_array($j)&&isset($j['schema'],$j['approval_checksum'],$j['plan_checksum'],$j['global_state'],$j['payout_ids'],$j['operations'])&&$j['schema']===self::SCHEMA&&$j['approval_checksum']===$checksum&&preg_match('/^[a-f0-9]{64}$/',$j['plan_checksum'])&&in_array($j['global_state'],array('PREPARED','IN_PROGRESS','HOLD_MANUAL_RECOVERY','READY_FOR_RECONCILIATION','RECONCILED'),true)&&is_array($j['payout_ids'])&&count($j['payout_ids'])>0&&is_array($j['operations'])&&count($j['operations'])>0;if(!$valid)throw new RuntimeException('Malformed wallet-send journal.');$previous=0;foreach($j['payout_ids']as$id){if(!is_int($id)||$id<=$previous)throw new RuntimeException('Malformed journal payout scope.');$previous=$id;}foreach($j['operations']as$id=>$o){$state=is_array($o)&&isset($o['state'])?$o['state']:null;if(!is_array($o)||@$o['operation_id']!==$id||!preg_match('/^wallet-op-[a-f0-9]{24}$/',$id)||!in_array($state,array('PENDING','SEND_ATTEMPT_STARTED','TXID_RETURNED','UNCERTAIN','DEFINITE_FAILURE','RECONCILED'),true))throw new RuntimeException('Malformed wallet operation journal entry.');$hasTxid=is_string(@$o['txid'])&&preg_match('/^[a-f0-9]{64}$/',$o['txid']);if(in_array($state,array('TXID_RETURNED','RECONCILED'),true)!==$hasTxid)throw new RuntimeException('Malformed wallet operation txid evidence.');}if($j['global_state']==='RECONCILED')foreach($j['operations']as$o)if($o['state']!=='RECONCILED')throw new RuntimeException('Malformed reconciled journal.');return$j;}
	private function write($checksum,$journal,$replace){$p=$this->path($checksum);if(is_link($p))throw new RuntimeException('Journal symlink refused.');if(!$replace&&file_exists($p))throw new RuntimeException('Conflicting journal already exists.');$tmp=$this->root.'/.'.$checksum.'.tmp.'.getmypid().'.'.substr(hash('sha256',uniqid('',true)),0,8);if(is_link($tmp))throw new RuntimeException('Journal temporary symlink refused.');$h=@fopen($tmp,'x');if(!$h)throw new RuntimeException('Unable to create atomic journal temporary file.');$json=json_encode($journal,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";$ok=fwrite($h,$json)===strlen($json)&&fflush($h);fclose($h);if(!$ok){@unlink($tmp);throw new RuntimeException('Unable to persist complete journal.');}if(!@rename($tmp,$p)){@unlink($tmp);throw new RuntimeException('Unable to atomically publish journal.');}}
	private function locked($checksum,$callable){$this->safeChecksum($checksum);$lock=$this->root.'/'.$checksum.'.lock';if(is_link($lock))throw new RuntimeException('Journal lock symlink refused.');$h=@fopen($lock,'c');if(!$h||!flock($h,LOCK_EX)){if($h)fclose($h);throw new RuntimeException('Unable to lock wallet-send journal.');}try{$r=$callable();flock($h,LOCK_UN);fclose($h);return$r;}catch(Exception$e){flock($h,LOCK_UN);fclose($h);throw$e;}}
	private function ensureRoot(){if(is_link($this->root))throw new RuntimeException('Journal root symlink refused.');if(!is_dir($this->root)&&!@mkdir($this->root,0770,true))throw new RuntimeException('Unable to create journal root.');if(is_link($this->root)||!is_dir($this->root))throw new RuntimeException('Unsafe journal root.');}
	private function safeChecksum($v){if(!is_string($v)||!preg_match('/^[a-f0-9]{64}$/',$v))throw new InvalidArgumentException('Invalid approval checksum.');}
}

/** Orchestrates independent wallets behind a conservative global DB barrier. */
class BadpoolGuardedMultiWalletSend
{
	const OPERATOR_CONFIRMATION='execute_exact_approved_multi_wallet_scope_no_retry';
	private $repository,$gateway,$planner,$journal;
	public function __construct($repository,$gateway,$journal,$registry=null){if(!($repository instanceof BadpoolExactMultiWalletPayoutRepository))throw new InvalidArgumentException('Exact repository required.');if(!($gateway instanceof BadpoolPerWalletOperationGateway))throw new InvalidArgumentException('Per-wallet gateway required.');if(!($journal instanceof BadpoolDurableMultiWalletSendJournal))throw new InvalidArgumentException('Durable journal required.');$this->repository=$repository;$this->gateway=$gateway;$this->journal=$journal;$this->planner=new BadpoolMultiWalletApprovalPlanner($repository,$registry);}
	public function preflight($approval){$plan=$this->planner->build($approval);$readiness=array();foreach($plan['operations']as$op){$r=$this->gateway->preflightApprovedWalletOperation($op);$readiness[$op['operation_id']]=$r;if(!is_array($r)||@$r['ready']!==true)throw new RuntimeException('Wallet readiness failed for '.$op['operation_id'].'.');}$plan['funding_readiness']=$readiness;$plan['read_only']=true;$plan['db_mutations']=false;$plan['wallet_sends']=false;$plan['stop_conditions']=array('approval_or_report_checksum_mismatch','payout_precondition_changed','ownership_changed','wallet_identity_changed','funding_or_readiness_failed','existing_or_conflicting_journal');$plan['report_checksum']=BadpoolMultiWalletApprovalPlanner::checksum($plan);return$plan;}
	public function execute($approval)
	{
		$checksum=BadpoolMultiWalletApprovalPlanner::checksum($approval);$hadJournal=false;try{$hadJournal=$this->journal->exists($checksum);if($hadJournal){$existing=$this->journal->load($checksum);if($existing['global_state']==='RECONCILED')return$this->result('pass','already_reconciled',null,null,$existing);}$plan=$this->planner->build($approval);}catch(Exception$e){if($hadJournal){try{$this->journal->hold($checksum,'payout_precondition_changed');}catch(Exception$ignored){}return$this->result('hold','payout_precondition_changed',null,$e->getMessage());}return$this->result('refused','pre_send_validation_failed',null,$e->getMessage());}
		try{$journal=$this->journal->prepare($plan);}catch(Exception$e){return$this->result('hold','journal_prepare_failed',$plan,$e->getMessage());}
		if($journal['global_state']==='RECONCILED')return$this->result('pass','already_reconciled',$plan,null,$journal);
		if($journal['global_state']==='HOLD_MANUAL_RECOVERY')return$this->result('hold','manual_recovery_required',$plan,$journal['hold_reason'],$journal);
		foreach($journal['operations']as$id=>$o)if($o['state']==='SEND_ATTEMPT_STARTED'){try{$journal=$this->journal->recordUncertain($checksum,$id,'Process restarted after durable send-attempt barrier without a recorded txid.');}catch(Exception$e){}return$this->result('hold','uncertain_prior_attempt',$plan,null,$journal);}
		foreach($plan['operations']as$op){$state=$journal['operations'][$op['operation_id']]['state'];if($state==='TXID_RETURNED'||$state==='RECONCILED')continue;try{$ready=$this->gateway->preflightApprovedWalletOperation($op);}catch(Exception$e){return$this->result('refused','wallet_preflight_failed',$plan,$e->getMessage(),$journal);}if(!is_array($ready)||@$ready['ready']!==true)return$this->result('refused','wallet_preflight_failed',$plan,isset($ready['reason'])?$ready['reason']:'wallet not ready',$journal);}
		foreach($plan['operations']as$op){$id=$op['operation_id'];$journal=$this->journal->load($checksum);$state=$journal['operations'][$id]['state'];if($state==='TXID_RETURNED'||$state==='RECONCILED')continue;if($state!=='PENDING')return$this->result('hold','manual_recovery_required',$plan,'Unsafe operation state '.$state,$journal);
			try{$fresh=$this->planner->build($approval);if($fresh['plan_checksum']!==$plan['plan_checksum'])throw new RuntimeException('Approved payout scope changed between wallet operations.');}catch(Exception$e){$this->journal->hold($checksum,'payout_changed_between_wallet_operations');return$this->result('hold','payout_changed_between_wallet_operations',$plan,$e->getMessage(),$this->journal->load($checksum));}
			try{$journal=$this->journal->startAttempt($checksum,$id);}catch(Exception$e){return$this->result('hold','send_attempt_barrier_failed',$plan,$e->getMessage());}
			$capability=new BadpoolWalletOperationCapability($checksum,$op);
			try{$sent=$this->gateway->sendApprovedWalletOperation($op,$capability);}catch(Exception$e){$journal=$this->journal->recordUncertain($checksum,$id,$e->getMessage());return$this->result('hold','wallet_outcome_uncertain',$plan,$e->getMessage(),$journal);}
			$status=is_array($sent)&&isset($sent['status'])?$sent['status']:'uncertain';
			if($status==='definite_failure'){$journal=$this->journal->recordDefiniteFailure($checksum,$id,isset($sent['error'])?$sent['error']:'definite wallet failure');return$this->result('hold','wallet_definite_failure',$plan,isset($sent['error'])?$sent['error']:null,$journal);}
			if($status!=='txid'||!isset($sent['txid'])||!preg_match('/^[a-fA-F0-9]{64}$/',$sent['txid'])){$journal=$this->journal->recordUncertain($checksum,$id,isset($sent['error'])?$sent['error']:'wallet result was not a definite txid');return$this->result('hold','wallet_outcome_uncertain',$plan,isset($sent['error'])?$sent['error']:null,$journal);}
			$journal=$this->journal->recordTxid($checksum,$id,$sent['txid']);
		}
		$dbMutations=false;
		try{$journal=$this->journal->readyForReconciliation($checksum);$fresh=$this->planner->build($approval);if($fresh['plan_checksum']!==$plan['plan_checksum'])throw new RuntimeException('Approved payouts changed before reconciliation.');$txids=array();foreach($plan['operations']as$op){$txid=$journal['operations'][$op['operation_id']]['txid'];foreach($op['payout_ids']as$id)$txids[$id]=$txid;}$updated=$this->repository->reconcileExactWithTransactionIds($plan['rows'],$txids);$dbMutations=$updated>0;if($updated!==count($plan['payout_ids']))throw new RuntimeException('Exact payout reconciliation count mismatch.');$journal=$this->journal->reconciled($checksum);return$this->result('pass','reconciled',$plan,null,$journal,$txids,$dbMutations);}catch(Exception$e){try{$journal=$this->journal->hold($checksum,'database_reconciliation_failed');}catch(Exception$ignored){}return$this->result('hold','database_reconciliation_failed',$plan,$e->getMessage(),isset($journal)?$journal:null,array(),$dbMutations);}
	}
	private function result($status,$reason,$plan=null,$error=null,$journal=null,$txids=array(),$dbMutations=false){$states=$journal&&isset($journal['operations'])?$journal['operations']:array();$completed=$status==='pass'&&$reason==='reconciled'&&$plan?$plan['payout_ids']:array();return array('status'=>$status,'reason'=>$reason,'error'=>$error,'errors'=>$error===null?array():array($error),'warnings'=>array(),'approval_checksum'=>$plan?$plan['approval_checksum']:($journal?$journal['approval_checksum']:null),'plan_checksum'=>$plan?$plan['plan_checksum']:($journal?$journal['plan_checksum']:null),'approved_payout_ids'=>$plan?$plan['payout_ids']:($journal?$journal['payout_ids']:array()),'operation_ids'=>$plan?array_column($plan['operations'],'operation_id'):array_keys($states),'wallet_operations'=>$plan?$plan['operations']:array(),'per_wallet_state'=>$states,'txid_by_payout_id'=>$txids,'journal_state'=>$journal&&isset($journal['global_state'])?$journal['global_state']:null,'wallet_sends_attempted'=>$journal?$this->attempted($journal):false,'wallet_rpc_send_performed'=>$journal?$this->attempted($journal):false,'db_reconciliation_status'=>$status==='pass'&&in_array($reason,array('reconciled','already_reconciled'),true)?'complete':($reason==='database_reconciliation_failed'?'failed':'not_started'),'db_completion_success'=>$status==='pass'&&in_array($reason,array('reconciled','already_reconciled'),true),'db_mutations'=>$dbMutations,'db_mutation_status'=>$dbMutations?'guarded_transaction_committed':'none','completed_payout_ids'=>$completed,'manual_recovery_required'=>$journal&&$journal['global_state']==='HOLD_MANUAL_RECOVERY','do_not_retry'=>$journal&&in_array($journal['global_state'],array('IN_PROGRESS','HOLD_MANUAL_RECOVERY','READY_FOR_RECONCILIATION','RECONCILED'),true));}
	private function attempted($j){foreach($j['operations']as$o)if($o['state']!=='PENDING')return true;return false;}
}
