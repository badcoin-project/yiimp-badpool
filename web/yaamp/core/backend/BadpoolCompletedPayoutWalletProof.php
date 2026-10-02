<?php

require_once(dirname(__FILE__).'/BadpoolLivePaymentLaneConfiguration.php');

/**
 * Fixed native-CLI reader for historical completed-payout transaction proof.
 *
 * The public API accepts only a registry coin ID and one exact transaction ID.
 * It cannot select an RPC method, executable, user, config, datadir, or extra
 * CLI argument. Native cookie authentication remains inside badcoin-cli.
 */
class BadpoolCompletedPayoutWalletProof
{
	const SUDO_BINARY='/usr/bin/sudo';
	const RUN_AS_USER='badcoin';
	const CLI_BINARY='/opt/badcoin/mainnet/bin/badcoin-cli';
	const RPC_METHOD='gettransaction';

	private $registry,$timeout,$runner;

	public function __construct($registry=null,$timeout=10,$runner=null)
	{
		if($registry!==null&&!($registry instanceof BadpoolLivePaymentLaneRegistry))throw new InvalidArgumentException('Live-payment lane registry required.');
		if($runner!==null&&!is_callable($runner))throw new InvalidArgumentException('Wallet-proof CLI process runner must be callable.');
		$this->registry=$registry?:new BadpoolLivePaymentLaneRegistry();$this->timeout=max(1,intval($timeout));$this->runner=$runner;
	}

	public function contextForCoin($coinId)
	{
		try{$lane=$this->eligibleLane($coinId);}
		catch(Exception $e){return array('supported'=>false,'reason'=>'unsupported_wallet_proof_context');}
		return array(
			'supported'=>true,
			'coin_id'=>$lane->coinId(),
			'lane_id'=>$lane->laneId(),
			'wallet_binding_identity'=>$lane->get('wallet_binding_identity'),
			'source_account_identity'=>$lane->get('wallet_source_account'),
			'conf'=>$lane->get('rpc_config_identity'),
			'datadir'=>$lane->get('wallet_datadir_identity'),
			'rpc_methods'=>array(self::RPC_METHOD),
			'human_approved_wallet_send_eligible'=>true,
		);
	}

	public function getTransaction($coinId,$txid)
	{
		$lane=$this->eligibleLane($coinId);
		if(!is_string($txid)||preg_match('/^[0-9a-fA-F]{64}$/D',$txid)!==1)throw new RuntimeException('Wallet-proof transaction ID is malformed.');
		$expectedTxid=strtolower($txid);
		$argv=array(
			self::SUDO_BINARY,'-n','-u',self::RUN_AS_USER,self::CLI_BINARY,
			'-conf='.$lane->get('rpc_config_identity'),
			'-datadir='.$lane->get('wallet_datadir_identity'),
			self::RPC_METHOD,$expectedTxid,
		);
		$result=$this->runner===null?$this->runProcess($argv):call_user_func($this->runner,$argv,$this->timeout);
		if(!is_array($result)||!empty($result['timed_out'])||!isset($result['status'])||intval($result['status'])!==0||!isset($result['stdout'])||!is_string($result['stdout'])||trim($result['stdout'])===''||!isset($result['stderr'])||!is_string($result['stderr'])||trim($result['stderr'])!=='')throw new RuntimeException('Completed-payout wallet proof CLI failed closed.');
		$raw=trim($result['stdout']);$decoded=json_decode($raw,true);
		if(!is_array($decoded)||json_last_error()!==JSON_ERROR_NONE)throw new RuntimeException('Completed-payout wallet proof returned malformed JSON.');
		if(!array_key_exists('txid',$decoded)||!is_string($decoded['txid'])||preg_match('/^[0-9a-fA-F]{64}$/D',$decoded['txid'])!==1)throw new RuntimeException('Completed-payout wallet proof returned no valid transaction ID.');
		$decoded['txid']=strtolower($decoded['txid']);
		if($decoded['txid']!==$expectedTxid)throw new RuntimeException('Completed-payout wallet proof transaction ID mismatch.');
		// json_decode converts JSON numbers to PHP floats. Preserve the exact
		// daemon decimal token so authorization comparison remains string-based.
		$exactAmount=$this->exactDecimalField($raw,'amount');if($exactAmount!==null)$decoded['amount']=$exactAmount;
		return $decoded;
	}

	private function exactDecimalField($json,$field)
	{
		$length=strlen($json);$depth=0;
		for($i=0;$i<$length;$i++){
			$char=$json[$i];if($char==='{'||$char==='['){$depth++;continue;}if($char==='}'||$char===']'){$depth--;continue;}if($char!=='"')continue;
			$previous=$i-1;while($previous>=0&&ctype_space($json[$previous]))$previous--;
			$end=$i+1;$escaped=false;for(;$end<$length;$end++){$candidate=$json[$end];if($escaped){$escaped=false;continue;}if($candidate==='\\'){$escaped=true;continue;}if($candidate==='"')break;}
			if($end>=$length)return null;
			$isKey=$depth===1&&$previous>=0&&($json[$previous]==='{'||$json[$previous]===',');
			if($isKey&&json_decode(substr($json,$i,$end-$i+1))===$field){$value=$end+1;while($value<$length&&ctype_space($json[$value]))$value++;if($value<$length&&$json[$value]===':'){$value++;$tail=substr($json,$value);if(preg_match('/^\s*(-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?)(?=\s*[,}])/',$tail,$match)===1)return$match[1];}}
			$i=$end;
		}
		return null;
	}

	private function eligibleLane($coinId)
	{
		if(!is_int($coinId)&&!(is_string($coinId)&&preg_match('/^[1-9][0-9]*$/D',$coinId)===1))throw new RuntimeException('Unsupported completed-payout wallet proof context.');
		$coinId=intval($coinId);$match=null;
		foreach($this->registry->all() as $lane)if($lane->coinId()===$coinId){$match=$lane;break;}
		if(!$match||!$match->isHumanApprovedWalletSendEligible())throw new RuntimeException('Unsupported completed-payout wallet proof context.');
		foreach(array('rpc_config_identity','wallet_datadir_identity','wallet_binding_identity','wallet_source_account') as $key)if(!is_string($match->get($key))||$match->get($key)==='')throw new RuntimeException('Unsupported completed-payout wallet proof context.');
		return $match;
	}

	private function runProcess($argv)
	{
		if(PHP_VERSION_ID<70400||!function_exists('proc_open'))throw new RuntimeException('Safe wallet-proof process execution is unavailable.');
		$descriptors=array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w'));$pipes=array();
		$process=@proc_open($argv,$descriptors,$pipes,null,null,array('bypass_shell'=>true,'suppress_errors'=>true));
		if(!is_resource($process))throw new RuntimeException('Safe wallet-proof process execution is unavailable.');
		fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$stdout='';$stderr='';$started=microtime(true);$timedOut=false;$exitCode=null;
		do{$stdout.=stream_get_contents($pipes[1]);$stderr.=stream_get_contents($pipes[2]);$status=proc_get_status($process);if(!$status['running']){$exitCode=$status['exitcode'];break;}if(microtime(true)-$started>=$this->timeout){$timedOut=true;proc_terminate($process);usleep(100000);$status=proc_get_status($process);if($status['running'])proc_terminate($process,9);break;}usleep(10000);}while(true);
		$stdout.=stream_get_contents($pipes[1]);$stderr.=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($process);if($exitCode===null||$exitCode<0)$exitCode=$closed;
		return array('status'=>$exitCode,'stdout'=>$stdout,'stderr'=>$stderr,'timed_out'=>$timedOut);
	}
}
