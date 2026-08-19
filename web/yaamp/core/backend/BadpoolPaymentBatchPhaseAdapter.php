<?php

/**
 * Production phase adapter for the guarded payment batch coordinator.
 *
 * Every mutation is delegated to an existing checksum-bound guard command. The
 * adapter never exposes, or calls, the wallet-send command family.
 */
class BadpoolPaymentBatchPhaseAdapter
{
	private $guard;
	private $execute;

	public function __construct($guard, $execute)
	{
		$this->guard = $guard;
		$this->execute = $execute;
	}

	public function safetyCheck($ledger, $options)
	{
		$coins = $this->coins($options);
		if (!$coins) return $this->hold('No supported active payout coins were found for this batch scope.');
		$reports = array();
		foreach ($coins as $coin) {
			$r = $this->command('safety-scan', array('--coin-id='.$coin['id'], '--format=json'));
			if (!$this->passed($r)) return $this->hold('Safety scan did not pass for coin '.$coin['id'].'.', $reports);
			$reports[] = $r;
		}
		return $this->artifact($ledger, 0, 'safety-check-report.json', $reports, array('selected_coin_scope'=>$coins));
	}

	public function selectEligibleWork($ledger, $options)
	{
		$reports=array(); $earnings=array(); $blocks=array(); $byCoin=array();
		$remaining=intval(arraySafeVal($options,'batch_size',0));
		foreach((array)$ledger['selected_coin_scope'] as $coin){
			$r=$this->command('earnings-maturity-transition-dryrun',array('--coin-id='.$coin['id'],'--format=json'));
			if(!$this->passed($r))return $this->hold('Maturity selection did not pass for coin '.$coin['id'].'.',$reports);
			$chosen=array(); $used=0;
			foreach($this->items($r,'linked_blocks') as $row){
				$count=count((array)arraySafeVal($row,'linked_earning_ids',array()));
				if($count>$remaining-$used)continue;
				$chosen[]=intval(arraySafeVal($row,'block_id')); $used+=$count;
			}
			if($chosen){
				$r=$this->command('earnings-maturity-transition-dryrun',array('--coin-id='.$coin['id'],'--selected-block-ids='.implode(',',$chosen),'--format=json'));
				if(!$this->passed($r))return $this->hold('Bounded maturity selection did not pass for coin '.$coin['id'].'.',$reports);
			}else{$r['items']['selected_earnings']=array();$r['items']['linked_blocks']=array();}
			$coinEarnings=array();$coinBlocks=array();
			foreach($this->items($r,'selected_earnings') as $row){$id=intval(arraySafeVal($row,'earning_id'));$coinEarnings[]=$id;$earnings[]=$id;}
			foreach($this->items($r,'linked_blocks') as $row){$id=intval(arraySafeVal($row,'block_id'));$coinBlocks[]=$id;$blocks[]=$id;}
			$remaining-=count($coinEarnings);$reports[]=$r;
			$byCoin[(string)$coin['id']]=array('earning_ids'=>$coinEarnings,'block_ids'=>$coinBlocks);
		}
		return $this->artifact($ledger,1,'eligible-work-report.json',$reports,array('selected_earning_ids'=>$earnings,'selected_block_ids'=>$blocks,'selected_work_by_coin'=>$byCoin));
	}

	public function packageMaturity($ledger, $options) { return $this->packages($ledger,2,'earnings-maturity-transition-approval-package','maturity-packages.json','maturity'); }
	public function applyMaturity($ledger, $options) { return $this->applyPackages($ledger,2,3,'earnings-maturity-transition-apply','maturity-apply-report.json'); }

	public function paymentDelayCheck($ledger, $options)
	{
		$reports=array(); foreach((array)$ledger['selected_coin_scope'] as $coin){
			$r=$this->command('account-credit-clear-dryrun',array('--coin-id='.$coin['id'],'--format=json'));
			if(!$this->passed($r))return $this->hold('Payment delay check did not pass for coin '.$coin['id'].'.',$reports); $reports[]=$r;
		}
		return $this->artifact($ledger,4,'payment-delay-report.json',$reports);
	}

	public function creditAccounts($ledger, $options)
	{
		$packaged=$this->packages($ledger,5,'account-credit-clear-approval-package','account-credit-packages.json','credit'); if(!$this->passed($packaged))return $packaged;
		$applied=$this->applyPackages($ledger,5,5,'account-credit-clear-apply','account-credit-apply-report.json',$packaged['package_path']); if(!$this->passed($applied))return $applied;
		$accounts=array(); foreach($this->readArtifact($packaged['package_path']) as $package)foreach($this->items($package,'selected_earnings') as $row)$accounts[]=intval(arraySafeVal($row,'account_id',arraySafeVal($row,'userid')));
		$applied['selected_account_ids']=array_values(array_unique($accounts)); return $applied;
	}

	public function preparePayoutRows($ledger, $options)
	{
		$packaged=$this->packages($ledger,6,'payout-row-approval-package','payout-row-packages.json','payout'); if(!$this->passed($packaged))return $packaged;
		$applied=$this->applyPackages($ledger,6,6,'payout-row-apply','payout-row-apply-report.json',$packaged['package_path']); if(!$this->passed($applied))return $applied;
		$ids=array(); foreach($this->readArtifact($applied['report_path']) as $report)foreach((array)arraySafeVal($report,'created_payout_ids',array()) as $id)$ids[]=intval($id);
		$applied['created_payout_ids']=$ids; return $applied;
	}

	private function coins($options)
	{
		$ids=array(1266,1267,1268,1269,1270);$params=array();$p=array();foreach($ids as $i=>$id){$key=':id'.$i;$p[]=$key;$params[$key]=$id;}
		$sql='SELECT id,symbol,algo FROM coins WHERE id IN ('.implode(',',$p).')';$only=arraySafeVal($options,'only');
		$sql.=' AND LOWER(algo)=LOWER(:algo)';$params[':algo']=$only?:'scrypt';$sql.=' ORDER BY id';$rows=$this->guard->selectAll($sql,$params);$out=array();
		foreach($rows as $r)$out[]=array('coin_id'=>intval($r['id']),'id'=>intval($r['id']),'symbol'=>(string)$r['symbol'],'algo'=>(string)$r['algo']);return $out;
	}

	private function packages($ledger,$phase,$command,$file,$kind)
	{
		$reports=array();foreach((array)$ledger['selected_coin_scope'] as $coin){
			$args=array('--coin-id='.$coin['id']);if($kind==='maturity'){$work=(array)arraySafeVal((array)arraySafeVal($ledger,'selected_work_by_coin',array()),(string)$coin['id'],array());$ids=(array)arraySafeVal($work,'block_ids',array());if(!$ids)continue;$args[]='--selected-block-ids='.implode(',',$ids);}
			$args[]='--format=json';$r=$this->command($command,$args);if(!$this->passed($r))return $this->hold('Approval package generation did not pass for coin '.$coin['id'].'.',$reports);
			if(!$this->packageWithinLedgerScope($r,$ledger,$kind))return $this->hold('Approval package exceeded the durable batch selection for coin '.$coin['id'].'.',$reports);$reports[]=$r;
		}return $this->artifact($ledger,$phase,$file,$reports,array(),true);
	}

	private function applyPackages($ledger,$sourcePhase,$phase,$command,$file,$path=null)
	{
		if($path===null)$path=$this->phaseArtifact($ledger,$sourcePhase,'package_path');$packages=$this->readArtifact($path);if(!is_array($packages))return $this->hold('Required approval package artifact is missing or invalid.');
		$reports=array();foreach($packages as $package){$args=$this->applyArgs($package,$command);if($args===null)return $this->hold('Approval package has no compatible guarded apply command.',$reports);$r=$this->command($command,$args);if(!$this->passed($r))return $this->hold('Guarded apply did not pass.',$reports,arraySafeVal($r,'errors',array()));$reports[]=$r;}return $this->artifact($ledger,$phase,$file,$reports);
	}

	private function applyArgs($package,$command)
	{
		$args=(array)arraySafeVal($package,'apply_command_args',array());if(!$args)$args=(array)arraySafeVal($package,'apply_command_shape',array());$at=array_search($command,$args,true);if($at===false)return null;$args=array_slice($args,$at+1);$checksum=(string)arraySafeVal(arraySafeVal($package,'approval_package_checksum',array()),'value');foreach($args as &$arg)if($arg==='--approval-package-checksum=<approval_package_checksum>')$arg='--approval-package-checksum='.$checksum;return $args;
	}

	private function packageWithinLedgerScope($package,$ledger,$kind)
	{
		$allowed=$kind==='payout'?(array)arraySafeVal($ledger,'selected_account_ids',array()):(array)arraySafeVal($ledger,'selected_earning_ids',array());$key=$kind==='payout'?'selected_accounts':'selected_earnings';$field=$kind==='payout'?'account_id':'earning_id';$items=$this->items($package,$key);foreach($items as $row)if(!in_array(intval(arraySafeVal($row,$field)),$allowed,true))return false;return count($items)<=intval(arraySafeVal($ledger,'batch_size',0));
	}

	private function command($command,$args){return call_user_func($this->execute,$command,$args);}
	private function passed($r){return is_array($r)&&in_array(strtolower((string)arraySafeVal($r,'status')),array('ok','pass'),true);}
	private function items($r,$key){return (array)arraySafeVal(arraySafeVal($r,'items',array()),$key,array());}
	private function hold($warning,$reports=array(),$errors=array()){return array('status'=>'hold','mutation_scope'=>array(),'warnings'=>array($warning),'errors'=>(array)$errors,'reports'=>$reports);}
	private function phaseArtifact($ledger,$phase,$field){foreach((array)$ledger['phase_results'] as $r)if(intval(arraySafeVal($r,'phase_number',-1))===$phase)return arraySafeVal($r,$field);return null;}
	private function readArtifact($path){$v=is_string($path)&&is_file($path)?json_decode(file_get_contents($path),true):null;return is_array($v)?$v:null;}
	private function artifact($ledger,$phase,$file,$data,$extra=array(),$package=false){$path=$ledger['run_directory'].'/'.$file;$json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";$tmp=$path.'.tmp';if(file_put_contents($tmp,$json,LOCK_EX)===false||!rename($tmp,$path))return $this->hold('Unable to persist phase artifact.');$key=$package?'package_path':'report_path';return array_merge(array('status'=>'pass','mutation_scope'=>array(),'checksums'=>array('phase_'.$phase.'_sha256'=>hash_file('sha256',$path)),$key=>$path),$extra);}
}
