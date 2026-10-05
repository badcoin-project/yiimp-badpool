<?php

require_once(dirname(__FILE__).'/BadpoolMultiWalletProductionPreflight.php');

/** The only production transport which can invoke sendmany. */
final class BadpoolFixedWalletSendmanyTransport
{
	private $runner,$timeout,$registry;
	public function __construct($runner=null,$timeout=30,$registry=null){if($runner!==null&&!is_callable($runner))throw new InvalidArgumentException('Send process runner must be callable.');$this->runner=$runner;$this->timeout=max(1,intval($timeout));$this->registry=$registry?:new BadpoolLivePaymentLaneRegistry();}
	public function send($operation)
	{
		$lane=$this->registry->get($operation['lane_id']);
		$expected=array($lane->get('rpc_config_identity'),$lane->get('wallet_datadir_identity'),$lane->get('wallet_source_account'),$lane->get('wallet_binding_identity'));
		$actual=array($operation['rpc_config_identity'],$operation['wallet_datadir_identity'],$operation['source_account_identity'],$operation['wallet_binding_identity']);
		if($actual!==$expected)throw new RuntimeException('Send operation does not match the fixed lane identity.');
		$destination=$this->destinationJson($operation['recipients']);
		$argv=array('/usr/bin/sudo','-n','-u','badcoin','/opt/badcoin/mainnet/bin/badcoin-cli','-conf='.$expected[0],'-datadir='.$expected[1],'sendmany',$expected[2],$destination);
		$result=$this->runner===null?$this->run($argv):call_user_func($this->runner,$argv,$this->timeout);
		if(!is_array($result)||!empty($result['timed_out'])||intval(isset($result['status'])?$result['status']:-1)!==0)return array('status'=>'uncertain','error'=>'Wallet send transport did not return a definite result.');
		$out=trim(isset($result['stdout'])?$result['stdout']:'');$err=trim(isset($result['stderr'])?$result['stderr']:'');
		if($err!==''||!preg_match('/^[a-fA-F0-9]{64}$/D',$out))return array('status'=>'uncertain','error'=>'Wallet send transport returned a malformed txid.');
		return array('status'=>'txid','txid'=>strtolower($out));
	}
	private function destinationJson($recipients){if(!is_array($recipients)||!count($recipients))throw new RuntimeException('Approved recipients are required.');$items=array();foreach($recipients as$address=>$amount){if(!is_string($address)||$address===''||!is_string($amount)||!preg_match('/^(?:0|[1-9][0-9]*)\.[0-9]{8}$/D',$amount))throw new RuntimeException('Destination requires an exact eight-decimal amount.');$key=json_encode($address,JSON_UNESCAPED_SLASHES);if($key===false)throw new RuntimeException('Destination address cannot be serialized.');$items[]=$key.':'.$amount;}return'{'.implode(',',$items).'}';}
	private function run($argv){if(PHP_VERSION_ID<70400||!function_exists('proc_open'))throw new RuntimeException('Safe send process execution is unavailable.');$d=array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w'));$p=array();$proc=@proc_open($argv,$d,$p,null,null,array('bypass_shell'=>true,'suppress_errors'=>true));if(!is_resource($proc))throw new RuntimeException('Safe send process execution is unavailable.');fclose($p[0]);stream_set_blocking($p[1],false);stream_set_blocking($p[2],false);$o='';$e='';$start=microtime(true);$timed=false;$code=null;do{$o.=stream_get_contents($p[1]);$e.=stream_get_contents($p[2]);$s=proc_get_status($proc);if(!$s['running']){$code=$s['exitcode'];break;}if(microtime(true)-$start>=$this->timeout){$timed=true;proc_terminate($proc);break;}usleep(10000);}while(true);$o.=stream_get_contents($p[1]);$e.=stream_get_contents($p[2]);fclose($p[1]);fclose($p[2]);$closed=proc_close($proc);if($code===null||$code<0)$code=$closed;return array('status'=>$code,'stdout'=>$o,'stderr'=>$e,'timed_out'=>$timed);}
}

final class BadpoolProductionPerWalletOperationGateway implements BadpoolPerWalletOperationGateway
{
	private $inspector,$transport,$reserveResolver;
	public function __construct($inspector=null,$transport=null,$reserveResolver=null){$this->inspector=$inspector?:new BadpoolConfiguredReadOnlyWalletInspector();$this->transport=$transport?:new BadpoolFixedWalletSendmanyTransport();$this->reserveResolver=$reserveResolver;}
	public function preflightApprovedWalletOperation($op){$seen=$this->inspector->inspect($op);$reserve=is_callable($this->reserveResolver)?call_user_func($this->reserveResolver,$op['coin_id']):BadpoolWalletFundingGuard::configuredReserve($op['coin_id']);$funding=BadpoolWalletFundingGuard::evaluate(isset($seen['available_balance'])?$seen['available_balance']:null,$op['wallet_send_total'],$reserve);$ready=!empty($seen['daemon_reachable'])&&!empty($seen['readiness_reachable'])&&$funding['funding_classification']==='PASS / WALLET FUNDING SUFFICIENT';return array_merge($funding,array('ready'=>$ready,'reason'=>$ready?null:(isset($seen['reason'])?$seen['reason']:$funding['funding_classification'])));}
	public function sendApprovedWalletOperation($op,$capability){if(!($capability instanceof BadpoolWalletOperationCapability))throw new RuntimeException('Exact wallet capability required.');$capability->claim($op['approval_checksum'],$op);return$this->transport->send($op);}
}

/** Validates retained evidence and constructs execution authority from fresh state. */
final class BadpoolMultiWalletSendApply
{
	const CONFIRMATION='execute_exact_approved_multi_wallet_scope_no_retry';
	private $repository,$gateway,$journal,$reportRoot,$registry;
	public function __construct($repository,$gateway=null,$journal=null,$reportRoot=null,$registry=null){$this->repository=$repository;$this->gateway=$gateway?:new BadpoolProductionPerWalletOperationGateway();$this->journal=$journal?:new BadpoolDurableMultiWalletSendJournal();$this->reportRoot=rtrim($reportRoot?:dirname(__FILE__).'/../../../../runtime/badpool-multi-wallet-preflights','/\\');$this->registry=$registry?:new BadpoolLivePaymentLaneRegistry();}
	public static function parseOptions($args){$allowed=array('preflight-report','preflight-report-checksum','approval-checksum','selected-payout-ids','operator-confirms-multi-wallet-send','format');$out=array();foreach($args as$arg){if(!preg_match('/^--([^=]+)=(.*)$/D',$arg,$m)||!in_array($m[1],$allowed,true))throw new InvalidArgumentException('Unknown or malformed apply argument.');if(array_key_exists($m[1],$out))throw new InvalidArgumentException('Duplicate apply argument: --'.$m[1]);$out[$m[1]]=$m[2];}foreach($allowed as$k)if(!array_key_exists($k,$out)||$out[$k]==='')throw new InvalidArgumentException('Missing required --'.$k.'.');if($out['format']!=='json')throw new InvalidArgumentException('--format=json is required.');if($out['operator-confirms-multi-wallet-send']!==self::CONFIRMATION)throw new InvalidArgumentException('Exact operator confirmation is required.');$out['ids']=BadpoolMultiWalletProductionPreflight::parseSelectedPayoutIds($out['selected-payout-ids']);$sorted=$out['ids'];sort($sorted,SORT_NUMERIC);if($sorted!==$out['ids']||implode(',',$out['ids'])!==$out['selected-payout-ids'])throw new InvalidArgumentException('Selected payout IDs must be unique and strictly ascending.');foreach(array('preflight-report-checksum','approval-checksum')as$k)if(!preg_match('/^[a-f0-9]{64}$/D',$out[$k]))throw new InvalidArgumentException('Invalid --'.$k.'.');return$out;}
	public function execute($options)
	{
		$report=$this->loadReport($options['preflight-report'],$options['preflight-report-checksum']);$this->validateReport($report,$options);
		$freshApproval=$report['approval_object'];$executionApproval=$freshApproval;$executionApproval['human_approved']=true;$executionChecksum=BadpoolMultiWalletApprovalPlanner::checksum($executionApproval);
		$executor=new BadpoolGuardedMultiWalletSend($this->repository,$this->gateway,$this->journal,$this->registry);
		if($this->journal->exists($executionChecksum)&&$this->journal->load($executionChecksum)['global_state']==='RECONCILED'){$result=$executor->execute($executionApproval);$result['command']='multi-wallet-send-apply';$result['preflight_approval_checksum']=$options['approval-checksum'];$result['journal_path']=$this->journal->path($executionChecksum);$result['manual_recovery_required']=false;return$result;}
		$planner=new BadpoolMultiWalletApprovalPlanner($this->repository,$this->registry);$freshPlan=$planner->buildPreflightProposal($freshApproval);
		if($freshPlan['rows']!==$report['resolved_payout_inventory']||$freshPlan['operations']!==$this->stripInitialState($report['state_machine_plan']['operations']))throw new RuntimeException('Fresh authoritative payout or operation state differs from retained evidence.');
		$executionPlan=$planner->build($executionApproval);$existing=$this->journal->exists($executionChecksum)?$this->journal->load($executionChecksum):null;
		if($existing&&$existing['plan_checksum']!==$executionPlan['plan_checksum'])throw new RuntimeException('Existing execution journal plan mismatch.');
		foreach($executionPlan['operations']as$op){
			// A recorded txid needs reconciliation, not a new funding check or send.
			if($existing&&($existing['global_state']==='HOLD_MANUAL_RECOVERY'||in_array($existing['operations'][$op['operation_id']]['state'],array('TXID_RETURNED','RECONCILED','SEND_ATTEMPT_STARTED'),true)))continue;
			$ready=$this->gateway->preflightApprovedWalletOperation($op);if(!is_array($ready)||@$ready['ready']!==true)throw new RuntimeException('Fresh wallet funding or readiness failed.');
		}
		$result=$executor->execute($executionApproval);$result['command']='multi-wallet-send-apply';$result['preflight_approval_checksum']=$options['approval-checksum'];$result['journal_path']=$this->journal->path($executionChecksum);$result['manual_recovery_required']=$result['status']==='hold';return$result;
	}
	private function loadReport($path,$checksum){if(!is_dir($this->reportRoot)||is_link($this->reportRoot))throw new RuntimeException('Authoritative preflight directory is missing or unsafe.');$root=realpath($this->reportRoot);if(!is_string($path)||$path===''||is_link($path)||!is_file($path))throw new RuntimeException('Retained preflight report must be a regular non-symlink file.');$real=realpath($path);if($real===false||strpos($real,$root.DIRECTORY_SEPARATOR)!==0)throw new RuntimeException('Retained preflight report is outside the authoritative directory.');if(!hash_equals($checksum,hash_file('sha256',$real)))throw new RuntimeException('Retained preflight report SHA256 mismatch.');$r=json_decode(file_get_contents($real),true);if(!is_array($r)||json_last_error()!==JSON_ERROR_NONE)throw new RuntimeException('Malformed retained preflight JSON.');return$r;}
	private function validateReport($r,$o){foreach(array('schema','status','classification','read_only','human_approved','selected_payout_ids','approval_object','checksums','state_machine_plan','funding_readiness')as$k)if(!array_key_exists($k,$r))throw new RuntimeException('Retained report is missing '.$k.'.');if($r['schema']!==BadpoolMultiWalletProductionPreflight::SCHEMA||$r['status']!=='pass'||$r['classification']!=='PASS'||$r['read_only']!==true||$r['human_approved']!==false)throw new RuntimeException('Retained report is not an exact non-authorizing PASS.');foreach(array('wallet_sends','db_mutations','payout_mutations','execution_journal_created','send_attempt_started','apply_handler_available')as$k)if(!array_key_exists($k,$r)||$r[$k]!==false)throw new RuntimeException('Retained report safety declaration mismatch: '.$k);if($r['selected_payout_ids']!==$o['ids'])throw new RuntimeException('Retained selected payout scope mismatch.');$actual=BadpoolMultiWalletApprovalPlanner::checksum($r['approval_object']);if(!hash_equals($o['approval-checksum'],$actual)||!isset($r['checksums']['approval_object']['value'])||!hash_equals($actual,$r['checksums']['approval_object']['value']))throw new RuntimeException('Retained approval checksum mismatch.');$this->validateFundingSet($r);foreach($r['funding_readiness']as$f)if(@$f['funding_status']!=='sufficient'||@$f['classification']!=='PASS / WALLET FUNDING SUFFICIENT')throw new RuntimeException('Retained funding evidence is not PASS.');}
	/** Bind retained observations to the retained plan; execute also rebuilds that plan. */
	private function validateFundingSet($report)
	{
		$plan=$report['state_machine_plan'];
		if(!is_array($plan)||!isset($plan['operations'])||!is_array($plan['operations'])||!count($plan['operations']))throw new RuntimeException('Malformed retained wallet plan.');
		$planned=array();$wallets=array();foreach($plan['operations'] as $op){
			if(!is_array($op)||!isset($op['operation_id'],$op['wallet_binding_identity'])||isset($planned[$op['operation_id']])||isset($wallets[$op['wallet_binding_identity']]))throw new RuntimeException('Duplicate or malformed retained wallet operation.');
			$planned[$op['operation_id']]=$op;$wallets[$op['wallet_binding_identity']]=true;
		}
		$seen=array();if(!is_array($report['funding_readiness']))throw new RuntimeException('Malformed retained funding results.');
		foreach($report['funding_readiness'] as $funding){
			$id=arraySafeVal($funding,'operation_id');
			if(!is_string($id)||!isset($planned[$id])||isset($seen[$id]))throw new RuntimeException('Missing, extra or duplicate retained funding wallet.');
			foreach(array('lane_id','coin_id','wallet_binding_identity','source_account_identity') as $key)if(!array_key_exists($key,$funding)||$funding[$key]!==$planned[$id][$key])throw new RuntimeException('Retained funding wallet identity mismatch.');
			if(arraySafeVal($funding,'projected_send_total')!==$planned[$id]['wallet_send_total'])throw new RuntimeException('Retained funding total mismatch.');
			$seen[$id]=true;
		}
		if(count($seen)!==count($planned))throw new RuntimeException('Missing retained funding wallet.');
	}
	private function stripInitialState($ops){foreach($ops as&$op)unset($op['initial_state']);unset($op);return$ops;}
}
