<?php

require_once(dirname(__FILE__).'/BadpoolGuardedMultiWalletSend.php');
require_once(dirname(__FILE__).'/BadpoolWalletFundingGuard.php');
require_once(dirname(__FILE__).'/BadpoolGuardReport.php');

interface BadpoolReadOnlyWalletReadinessInspector
{
	/** This interface deliberately exposes no wallet-send method. */
	public function inspect($operation);
}

/**
 * The transport deliberately exposes only the four readiness reads. There is
 * no public generic RPC or arbitrary CLI-argument method.
 */
interface BadpoolReadOnlyWalletTransport
{
	public function getNetworkInfo($identity);
	public function getBlockchainInfo($identity);
	public function getWalletInfo($identity);
	public function getBalance($identity);
}

/**
 * Production wallet-read transport. Authentication, including the native
 * .cookie, stays inside badcoin-cli running as badcoin.
 */
class BadpoolReadOnlyWalletCliTransport implements BadpoolReadOnlyWalletTransport
{
	const SUDO_BINARY='/usr/bin/sudo';
	const RUN_AS_USER='badcoin';
	const CLI_BINARY='/opt/badcoin/mainnet/bin/badcoin-cli';
	private $timeout,$runner;

	public function __construct($timeout=10,$runner=null)
	{
		if($runner!==null&&!is_callable($runner))throw new InvalidArgumentException('Read-only CLI process runner must be callable.');
		$this->timeout=max(1,intval($timeout));$this->runner=$runner;
	}

	public function getNetworkInfo($identity){return$this->executeJson($identity,'getnetworkinfo');}
	public function getBlockchainInfo($identity){return$this->executeJson($identity,'getblockchaininfo');}
	public function getWalletInfo($identity){return$this->executeJson($identity,'getwalletinfo');}
	public function getBalance($identity){return$this->executeDecimal($identity,'getbalance',array($identity['source_account'],'1'));}

	private function executeJson($identity,$method)
	{
		$raw=$this->execute($identity,$method,array());$trimmed=trim($raw);
		if($trimmed===''||substr($trimmed,0,1)!=='{'||substr($trimmed,-1)!=='}')throw new RuntimeException('Read-only wallet CLI returned malformed JSON.');
		$decoded=json_decode($trimmed,true);if(!is_array($decoded)||json_last_error()!==JSON_ERROR_NONE)throw new RuntimeException('Read-only wallet CLI returned malformed JSON.');return$decoded;
	}

	private function executeDecimal($identity,$method,$args)
	{
		$raw=trim($this->execute($identity,$method,$args));if(!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D',$raw))throw new RuntimeException('Read-only wallet CLI returned a malformed balance.');return$raw;
	}

	private function execute($identity,$method,$args)
	{
		$allowed=array('getnetworkinfo'=>array(),'getblockchaininfo'=>array(),'getwalletinfo'=>array(),'getbalance'=>array($identity['source_account'],'1'));
		if(!isset($allowed[$method])||$args!==$allowed[$method])throw new RuntimeException('Read-only wallet CLI method or arguments were refused.');
		$argv=array(self::SUDO_BINARY,'-n','-u',self::RUN_AS_USER,self::CLI_BINARY,'-conf='.$identity['config'],'-datadir='.$identity['datadir'],$method);foreach($args as$arg)$argv[]=$arg;
		$result=$this->runner===null?$this->runProcess($argv):call_user_func($this->runner,$argv,$this->timeout);
		if(!is_array($result)||!empty($result['timed_out'])||!isset($result['status'])||intval($result['status'])!==0||!isset($result['stdout'])||!is_string($result['stdout'])||trim($result['stdout'])===''||!isset($result['stderr'])||!is_string($result['stderr'])||trim($result['stderr'])!=='')throw new RuntimeException('Read-only wallet CLI invocation failed closed.');
		return$result['stdout'];
	}

	private function runProcess($argv)
	{
		if(PHP_VERSION_ID<70400||!function_exists('proc_open'))throw new RuntimeException('Safe process execution is unavailable.');
		$descriptors=array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w'));$pipes=array();$process=@proc_open($argv,$descriptors,$pipes,null,null,array('bypass_shell'=>true,'suppress_errors'=>true));if(!is_resource($process))throw new RuntimeException('Safe process execution is unavailable.');
		fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$stdout='';$stderr='';$started=microtime(true);$timedOut=false;$exitCode=null;
		do{$stdout.=stream_get_contents($pipes[1]);$stderr.=stream_get_contents($pipes[2]);$status=proc_get_status($process);if(!$status['running']){$exitCode=$status['exitcode'];break;}if(microtime(true)-$started>=$this->timeout){$timedOut=true;proc_terminate($process);usleep(100000);$status=proc_get_status($process);if($status['running'])proc_terminate($process,9);break;}usleep(10000);}while(true);
		$stdout.=stream_get_contents($pipes[1]);$stderr.=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($process);if($exitCode===null||$exitCode<0)$exitCode=$closed;
		return array('status'=>$exitCode,'stdout'=>$stdout,'stderr'=>$stderr,'timed_out'=>$timedOut);
	}
}

/** Exact lane identity validation remains outside the transport boundary. */
class BadpoolConfiguredReadOnlyWalletInspector implements BadpoolReadOnlyWalletReadinessInspector
{
	private $transport,$registry;
	public function __construct($transport=null,$registry=null)
	{
		if($transport!==null&&!($transport instanceof BadpoolReadOnlyWalletTransport))throw new InvalidArgumentException('Read-only wallet transport required.');
		if($registry!==null&&!($registry instanceof BadpoolLivePaymentLaneRegistry))throw new InvalidArgumentException('Live-payment lane registry required.');
		$this->transport=$transport?:new BadpoolReadOnlyWalletCliTransport();$this->registry=$registry?:new BadpoolLivePaymentLaneRegistry();
	}

	public function inspect($operation)
	{
		$base=array('daemon_reachable'=>false,'readiness_reachable'=>false,'available_balance'=>null,'balance_scope'=>'exact legacy named source account','balance_semantics'=>'getbalance(source_account,1); exact CLI decimal token; decoded floating point is not used','reason'=>null,'rpc_methods'=>array('getnetworkinfo','getblockchaininfo','getwalletinfo','getbalance'));
		try{$identity=$this->authoritativeIdentity($operation);$network=$this->transport->getNetworkInfo($identity);$chain=$this->transport->getBlockchainInfo($identity);$wallet=$this->transport->getWalletInfo($identity);$balance=$this->transport->getBalance($identity);}
		catch(Exception$e){$base['reason']='Read-only wallet readiness failed closed.';return$base;}
		if(!is_array($network)||!is_array($chain)||!is_array($wallet)||!is_string($balance)||!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D',$balance)){$base['reason']='Read-only wallet response was incomplete or malformed.';return$base;}
		if(array_key_exists('unlocked_until',$wallet)&&intval($wallet['unlocked_until'])<=0){$base['daemon_reachable']=true;$base['available_balance']=$balance;$base['reason']='Wallet is locked.';return$base;}
		$base['daemon_reachable']=true;$base['readiness_reachable']=true;$base['available_balance']=$balance;$base['reason']=null;return$base;
	}

	private function authoritativeIdentity($operation)
	{
		if(!is_array($operation)||!isset($operation['lane_id']))throw new RuntimeException('Wallet operation identity is unavailable.');
		$lane=$this->registry->get($operation['lane_id']);$expected=array('config'=>$lane->get('rpc_config_identity'),'datadir'=>$lane->get('wallet_datadir_identity'),'source_account'=>$lane->get('wallet_source_account'),'wallet'=>$lane->get('wallet_binding_identity'),'coin_id'=>$lane->coinId());
		$actual=array('config'=>isset($operation['rpc_config_identity'])?$operation['rpc_config_identity']:null,'datadir'=>isset($operation['wallet_datadir_identity'])?$operation['wallet_datadir_identity']:null,'source_account'=>isset($operation['source_account_identity'])?$operation['source_account_identity']:null,'wallet'=>isset($operation['wallet_binding_identity'])?$operation['wallet_binding_identity']:null,'coin_id'=>isset($operation['coin_id'])?$operation['coin_id']:null);
		if($actual!==$expected)throw new RuntimeException('Wallet operation identity does not match its configured lane.');
		if(!$this->isAbsolutePath($expected['datadir']))throw new RuntimeException('Wallet datadir identity must be absolute.');
		if(is_link($expected['datadir'])||!is_dir($expected['datadir']))throw new RuntimeException('Wallet datadir identity is missing or unsafe.');
		if(!is_string($expected['config'])||$expected['config']===''||is_link($expected['config'])||!is_file($expected['config']))throw new RuntimeException('RPC config identity is missing or unsafe.');
		return$expected;
	}

	private function isAbsolutePath($path){return is_string($path)&&($path!==''&&($path[0]==='/'||preg_match('/^[A-Za-z]:[\\\\\/]/',$path)===1||substr($path,0,2)==='\\\\'));}
}

/** Atomic, symlink-refusing storage separate from execution journals. */
class BadpoolMultiWalletPreflightReportStore
{
	private $root;
	public function __construct($root=null){$this->root=rtrim($root?:dirname(__FILE__).'/../../../../runtime/badpool-multi-wallet-preflights','/\\');}
	public function path($selectedChecksum){if(!preg_match('/^[a-f0-9]{64}$/',$selectedChecksum))throw new InvalidArgumentException('Invalid selected-ID checksum.');return$this->root.'/multi-wallet-preflight-'.$selectedChecksum.'.json';}
	public function write($selectedChecksum,$report){$this->ensureRoot();$path=$this->path($selectedChecksum);if(is_link($path))throw new RuntimeException('Preflight report symlink target refused.');$tmp=$this->root.'/.preflight-'.getmypid().'-'.substr(hash('sha256',uniqid('',true)),0,12).'.tmp';if(is_link($tmp))throw new RuntimeException('Preflight temporary symlink refused.');$json=json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";$h=@fopen($tmp,'x');if(!$h)throw new RuntimeException('Unable to create preflight report temporary file.');$ok=fwrite($h,$json)===strlen($json)&&fflush($h);fclose($h);if(!$ok||!@rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Unable to atomically retain preflight report.');}return$path;}
	private function ensureRoot(){$this->assertNoSymlinkComponents($this->root);if(is_link($this->root))throw new RuntimeException('Preflight report root symlink refused.');if(!is_dir($this->root)&&!@mkdir($this->root,0770,true))throw new RuntimeException('Unable to create preflight report root.');$this->assertNoSymlinkComponents($this->root);if(is_link($this->root)||!is_dir($this->root))throw new RuntimeException('Unsafe preflight report root.');}
	private function assertNoSymlinkComponents($path){$probe=$path;while(is_string($probe)&&$probe!==''&&dirname($probe)!==$probe){if(is_link($probe))throw new RuntimeException('Preflight report path symlink traversal refused.');$probe=dirname($probe);}if(is_string($probe)&&$probe!==''&&is_link($probe))throw new RuntimeException('Preflight report path symlink traversal refused.');}
}

class BadpoolMultiWalletProductionPreflight
{
	const SCHEMA='badpool.wallet_send.multi_wallet_preflight.v1';
	private $repository,$registry,$inspector,$store,$reserveResolver;
	public function __construct($repository,$inspector,$store=null,$registry=null,$reserveResolver=null){if(!($repository instanceof BadpoolExactMultiWalletPayoutRepository))throw new InvalidArgumentException('Exact payout repository required.');if(!($inspector instanceof BadpoolReadOnlyWalletReadinessInspector))throw new InvalidArgumentException('Read-only wallet inspector required.');$this->repository=$repository;$this->inspector=$inspector;$this->store=$store?:new BadpoolMultiWalletPreflightReportStore();$this->registry=$registry?:new BadpoolLivePaymentLaneRegistry();$this->reserveResolver=$reserveResolver;}

	public static function parseSelectedPayoutIds($csv)
	{
		if(!is_string($csv)||$csv==='')throw new InvalidArgumentException('Explicit --selected-payout-ids is required and must be non-empty.');$parts=explode(',',$csv);$ids=array();foreach($parts as$part){if(!preg_match('/^[1-9][0-9]*$/',$part))throw new InvalidArgumentException('Malformed payout ID: '.$part);$id=intval($part);if(isset($ids[$id]))throw new InvalidArgumentException('Duplicate payout ID: '.$id);$ids[$id]=true;}return array_keys($ids);
	}

	public function run($ids)
	{
		$ids=$this->validateIds($ids);$rows=$this->repository->loadExactPayouts($ids);if(!is_array($rows))throw new RuntimeException('Exact payout repository returned a malformed result.');usort($rows,function($a,$b){return$a['payout_id']-$b['payout_id'];});$returned=array();foreach($rows as$row){if(!is_array($row)||!isset($row['payout_id'])||!in_array($row['payout_id'],$ids,true)||isset($returned[$row['payout_id']]))throw new RuntimeException('Exact payout repository returned an unexpected or duplicate payout.');$returned[$row['payout_id']]=true;}if(array_keys($returned)!==$ids)throw new RuntimeException('Every explicit payout ID must resolve exactly once.');$entries=array();foreach($rows as$row)$entries[]=$this->entry($row);
		$approval=array('schema'=>BadpoolMultiWalletApprovalPlanner::APPROVAL_SCHEMA,'human_approved'=>false,'entries'=>$entries);$planner=new BadpoolMultiWalletApprovalPlanner($this->repository,$this->registry);$plan=$planner->buildPreflightProposal($approval);
		$readiness=array();$holds=array();$ops=array();foreach($plan['operations']as$op){$observed=$this->inspector->inspect($op);$policy=$this->reserve($op['coin_id']);$funding=BadpoolWalletFundingGuard::evaluate(isset($observed['available_balance'])?$observed['available_balance']:null,$op['wallet_send_total'],$policy);$ready=isset($observed['daemon_reachable'])&&$observed['daemon_reachable']===true&&isset($observed['readiness_reachable'])&&$observed['readiness_reachable']===true&&$funding['funding_classification']==='PASS / WALLET FUNDING SUFFICIENT';$fundingError=isset($funding['funding_error'])?$funding['funding_error']:$funding['funding_classification'];$item=array('operation_id'=>$op['operation_id'],'lane_id'=>$op['lane_id'],'coin_id'=>$op['coin_id'],'wallet_binding_identity'=>$op['wallet_binding_identity'],'source_account_identity'=>$op['source_account_identity'],'daemon_reachable'=>!empty($observed['daemon_reachable']),'readiness_reachable'=>!empty($observed['readiness_reachable']),'available_balance'=>$funding['wallet_balance'],'balance_scope'=>isset($observed['balance_scope'])?$observed['balance_scope']:'unknown','balance_semantics'=>isset($observed['balance_semantics'])?$observed['balance_semantics']:'unknown','projected_send_total'=>$op['wallet_send_total'],'configured_reserve'=>$funding['minimum_wallet_reserve'],'required_wallet_balance'=>$funding['required_wallet_balance'],'required_post_send_reserve'=>$funding['minimum_wallet_reserve'],'projected_post_send_balance'=>$funding['projected_post_send_balance'],'funding_status'=>$ready?'sufficient':($funding['funding_classification']==='HOLD / WALLET FUNDING INSUFFICIENT'?'insufficient':'unknown'),'classification'=>$funding['funding_classification'],'reason'=>$ready?null:(isset($observed['reason'])&&$observed['reason']!==null?$observed['reason']:$fundingError));$readiness[]=$item;if(!$ready)$holds[]='wallet '.$op['wallet_binding_identity'].': '.($item['reason']?:$item['classification']);$op['initial_state']='PENDING';$ops[]=$op;}
		$selectedChecksum=self::checksum($ids);$path=$this->store->path($selectedChecksum);$inventory=$plan['rows'];$ownership=array();$recipients=array();foreach($inventory as$row){$lane=$this->registry->get($row['lane_id']);$ownership[]=array('payout_id'=>$row['payout_id'],'lane_id'=>$row['lane_id'],'batch_id'=>$row['batch_id'],'durable_batch_membership'=>true,'batch_state'=>$row['batch_state'],'active_coordinator_match'=>true,'coordinator_owner'=>$lane->ownershipEnvelope(),'coin_id'=>$row['coin_id'],'wallet_binding_identity'=>$row['wallet_binding_identity'],'source_account_identity'=>$row['source_account_identity']);$recipients[]=array('payout_id'=>$row['payout_id'],'account_id'=>$row['account_id'],'recipient'=>$row['recipient'],'amount'=>$row['amount']);}
		$report=array('schema'=>self::SCHEMA,'command'=>'multi-wallet-send-preflight','mode'=>'read-only-production-preflight','generated_at'=>gmdate('c'),'status'=>count($holds)?'hold':'pass','classification'=>count($holds)?'HOLD':'PASS','read_only'=>true,'human_approved'=>false,'authorization'=>'NONE; this report and every checksum are review evidence only and do not authorize wallet sends.','selected_payout_ids'=>$ids,'resolved_payout_inventory'=>$inventory,'lane_batch_ownership_evidence'=>$ownership,'recipient_plan'=>$recipients,'approval_object'=>$approval,'state_machine_plan'=>array('schema'=>BadpoolMultiWalletApprovalPlanner::PLAN_SCHEMA,'operations'=>$ops,'raw_total'=>$plan['raw_total'],'wallet_projected_total'=>$plan['wallet_send_total'],'journal_created'=>false,'prepared_state_created'=>false),'funding_readiness'=>$readiness,'hold_reasons'=>$holds,'retained_report'=>array('path'=>$path,'format'=>'JSON','atomic_write'=>true,'symlink_targets_refused'=>true,'execution_journal'=>false),'wallet_reads'=>count($readiness),'wallet_sends'=>false,'wallet_rpc_send_performed'=>false,'db_mutations'=>false,'payout_mutations'=>false,'execution_journal_created'=>false,'send_attempt_started'=>false,'apply_handler_available'=>false,'blocked_actions'=>array('wallet_send','database_mutation','payout_mutation','execution_journal','SEND_ATTEMPT_STARTED','multi-wallet-send-apply'),'warnings'=>array(),'errors'=>array());
		$partition=array();$amounts=array();foreach($ops as$op){$partition[]=array('operation_id'=>$op['operation_id'],'wallet_binding_identity'=>$op['wallet_binding_identity'],'source_account_identity'=>$op['source_account_identity'],'payout_ids'=>$op['payout_ids']);$amounts[]=array('operation_id'=>$op['operation_id'],'raw_total'=>$op['raw_total'],'wallet_send_total'=>$op['wallet_send_total']);}
		$report['checksums']=array('selected_payout_ids'=>$this->check($ids,'Binds only the explicit discovery authority.'),'resolved_payout_inventory'=>$this->check($inventory,'Binds exact current payout rows; not send authorization.'),'lane_batch_ownership'=>$this->check($ownership,'Binds durable lane, batch, coordinator, wallet, and source ownership evidence.'),'recipient_plan'=>$this->check($recipients,'Binds authoritative account destinations and exact raw amounts.'),'wallet_partition'=>$this->check($partition,'Binds payout-to-wallet isolation and deterministic operation IDs.'),'per_wallet_amount_plan'=>$this->check($amounts,'Binds raw and eight-decimal wallet projections.'),'funding_readiness'=>$this->check($readiness,'Binds read-only readiness, reserve, and funding observations.'),'approval_object'=>$this->check($approval,'Binds the non-authorizing proposed approval object.'));
		$report['report_checksum']=BadpoolGuardReport::checksum($report);$report['report_checksum']['purpose']='Binds the complete retained preflight report except generated_at and report_checksum; review evidence only, never authorization.';$this->store->write($selectedChecksum,$report);return$report;
	}

	private function reserve($coinId){return is_callable($this->reserveResolver)?call_user_func($this->reserveResolver,$coinId):BadpoolWalletFundingGuard::configuredReserve($coinId);}
	private function check($value,$purpose){return array('algorithm'=>'sha256','value'=>self::checksum($value),'purpose'=>$purpose);}
	private static function checksum($value){return BadpoolMultiWalletApprovalPlanner::checksum($value);}
	private function validateIds($ids){if(!is_array($ids)||!count($ids))throw new InvalidArgumentException('Explicit payout IDs are required.');$seen=array();foreach($ids as$id){if(!is_int($id)||$id<1)throw new InvalidArgumentException('Malformed payout ID.');if(isset($seen[$id]))throw new InvalidArgumentException('Duplicate payout ID: '.$id);$seen[$id]=true;}$out=array_keys($seen);sort($out,SORT_NUMERIC);return$out;}
	private function entry($row){return array('payout_id'=>$row['payout_id'],'lane_id'=>$row['lane_id'],'coin_id'=>$row['coin_id'],'account_id'=>$row['account_id'],'amount'=>$row['amount'],'recipient'=>$row['recipient'],'wallet_binding_identity'=>$row['wallet_binding_identity'],'source_account_identity'=>$row['source_account_identity'],'expected_completed'=>0,'expected_tx'=>null,'expected_batch_state'=>BadpoolMultiWalletApprovalPlanner::READY_STATE);}
}
