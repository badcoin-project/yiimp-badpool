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
 * Minimal JSON-RPC reader with a fixed method allowlist. It neither subclasses
 * WalletRPC nor exposes a generic call surface, so send capability cannot be
 * reached through the production preflight object graph.
 */
class BadpoolConfiguredReadOnlyWalletInspector implements BadpoolReadOnlyWalletReadinessInspector
{
	private $timeout;
	public function __construct($timeout=10){$this->timeout=max(1,intval($timeout));}

	public function inspect($operation)
	{
		$base=array('daemon_reachable'=>false,'readiness_reachable'=>false,'available_balance'=>null,'balance_scope'=>'exact legacy named source account','balance_semantics'=>'getbalance(source_account,1); decoded floating point is not used','reason'=>null,'rpc_methods'=>array('getnetworkinfo','getblockchaininfo','getwalletinfo','getbalance'));
		$path=isset($operation['rpc_config_identity'])?$operation['rpc_config_identity']:null;
		if(!is_string($path)||$path===''||is_link($path)||!is_file($path)){$base['reason']='RPC config is missing or unsafe.';return$base;}
		try{$config=$this->config($path);$network=$this->rpc($config,'getnetworkinfo',array(),false);$chain=$this->rpc($config,'getblockchaininfo',array(),false);$wallet=$this->rpc($config,'getwalletinfo',array(),false);$balance=$this->rpc($config,'getbalance',array($operation['source_account_identity'],1),true);}
		catch(Exception$e){$base['reason']='Read-only wallet readiness failed: '.$this->redact($e->getMessage());return$base;}
		if(!is_array($network)||!is_array($chain)||!is_array($wallet)||!is_string($balance)||!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/',$balance)){$base['reason']='Read-only wallet response was incomplete or malformed.';return$base;}
		if(array_key_exists('unlocked_until',$wallet)&&intval($wallet['unlocked_until'])<=0){$base['daemon_reachable']=true;$base['available_balance']=$balance;$base['reason']='Wallet is locked.';return$base;}
		$base['daemon_reachable']=true;$base['readiness_reachable']=true;$base['available_balance']=$balance;$base['reason']=null;return$base;
	}

	private function config($path)
	{
		$values=array();foreach(@file($path,FILE_IGNORE_NEW_LINES)?:array()as$line){$line=trim($line);if($line===''||$line[0]==='#'||$line[0]===';')continue;if(strpos($line,'=')===false)continue;list($k,$v)=array_map('trim',explode('=',$line,2));$values[strtolower($k)]=$v;}
		foreach(array('rpcuser','rpcpassword','rpcport')as$key)if(!isset($values[$key])||$values[$key]==='')throw new RuntimeException('Required RPC configuration field is unavailable.');
		$host=isset($values['rpcconnect'])?$values['rpcconnect']:(isset($values['rpchost'])?$values['rpchost']:'127.0.0.1');
		if(!preg_match('/^(?:127\.0\.0\.1|localhost|::1)$/',$host))throw new RuntimeException('Preflight refuses non-loopback RPC endpoints.');
		if(!preg_match('/^[1-9][0-9]{0,4}$/',$values['rpcport'])||intval($values['rpcport'])>65535)throw new RuntimeException('RPC port is invalid.');
		return array('user'=>$values['rpcuser'],'password'=>$values['rpcpassword'],'url'=>'http://'.$host.':'.$values['rpcport'].'/');
	}

	private function rpc($config,$method,$params,$decimal)
	{
		$allowed=array('getnetworkinfo'=>true,'getblockchaininfo'=>true,'getwalletinfo'=>true,'getbalance'=>true);if(!isset($allowed[$method]))throw new RuntimeException('RPC method is not read-only allowlisted.');
		if(!function_exists('curl_init'))throw new RuntimeException('cURL is unavailable.');
		$payload=json_encode(array('jsonrpc'=>'1.0','id'=>'badpool-read-only-preflight','method'=>$method,'params'=>$params));$h=curl_init($config['url']);curl_setopt_array($h,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_USERPWD=>$config['user'].':'.$config['password'],CURLOPT_HTTPHEADER=>array('Content-Type: application/json'),CURLOPT_CONNECTTIMEOUT=>$this->timeout,CURLOPT_TIMEOUT=>$this->timeout));$raw=curl_exec($h);$error=curl_error($h);$status=intval(curl_getinfo($h,CURLINFO_HTTP_CODE));curl_close($h);
		if(!is_string($raw)||$raw===''||$status!==200)throw new RuntimeException($error!==''?'RPC transport error.':'RPC returned a non-success response.');
		$decoded=json_decode($raw,true);if(!is_array($decoded)||!array_key_exists('result',$decoded)||!empty($decoded['error']))throw new RuntimeException('RPC method failed.');
		if(!$decimal)return$decoded['result'];
		if(!preg_match('/"result"\s*:\s*(-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?)/',$raw,$m))throw new RuntimeException('RPC decimal result is unavailable.');return ltrim($m[1],'+');
	}

	private function redact($message){return preg_replace('/(rpcuser|rpcpassword|password|user|cookie|token)\s*[^ ]*/i','$1=REDACTED',(string)$message);}
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
