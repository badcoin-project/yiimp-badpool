<?php

require_once(dirname(__FILE__).'/BadpoolConfirmedBlockPaymentDelayOverride.php');

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
		$reports=array(); $earnings=array(); $blocks=array(); $accounts=array(); $accountsByCoin=array(); $byCoin=array();
		$remaining=intval(arraySafeVal($options,'batch_size',0));
		foreach((array)$ledger['selected_coin_scope'] as $coin){
			$r=$this->command('earnings-maturity-transition-dryrun',array('--coin-id='.$coin['id'],'--format=json'));
			if(!$this->passed($r))return $this->hold('Maturity selection did not pass for coin '.$coin['id'].'.',$reports);
			$chosen=array(); $used=0;
			foreach($this->items($r,'linked_blocks') as $row){
				$blockId=$this->positiveId(arraySafeVal($row,'block_id'));
				if($blockId===null)return $this->hold('Maturity dry-run returned a malformed block ID for coin '.$coin['id'].'.',$reports);
				$count=count((array)arraySafeVal($row,'linked_earning_ids',array()));
				if($count>$remaining-$used)continue;
				$chosen[]=$blockId; $used+=$count;
			}
			if($chosen){
				$r=$this->command('earnings-maturity-transition-dryrun',array('--coin-id='.$coin['id'],'--selected-block-ids='.implode(',',$this->normalizeIdList($chosen)),'--format=json'));
				if(!$this->passed($r))return $this->hold('Bounded maturity selection did not pass for coin '.$coin['id'].'.',$reports);
				if(!$this->validateBoundedDryrun($r,$coin,$chosen,$remaining))return $this->hold('Bounded maturity selection did not match the requested durable scope for coin '.$coin['id'].'.',$reports);
			}else{$r['items']['selected_earnings']=array();$r['items']['linked_blocks']=array();}
			$coinEarnings=array();$coinBlocks=array();
			foreach($this->items($r,'selected_earnings') as $row){$id=$this->positiveId(arraySafeVal($row,'earning_id'));if($id===null)return $this->hold('Bounded maturity selection returned a malformed earning ID for coin '.$coin['id'].'.',$reports);$accountId=$this->positiveId(arraySafeVal($row,'account_id',arraySafeVal($row,'userid')));if($accountId===null)return $this->hold('Bounded maturity selection returned a malformed account ID for coin '.$coin['id'].'.',$reports);$coinEarnings[]=$id;$earnings[]=$id;$accounts[]=$accountId;$accountsByCoin[(string)$coin['id']][]=$accountId;}
			foreach($this->items($r,'linked_blocks') as $row){$id=$this->positiveId(arraySafeVal($row,'block_id'));if($id===null)return $this->hold('Bounded maturity selection returned a malformed block ID for coin '.$coin['id'].'.',$reports);$coinBlocks[]=$id;$blocks[]=$id;}
			$remaining-=count($coinEarnings);$reports[]=$r;
			$byCoin[(string)$coin['id']]=array('earning_ids'=>$this->normalizeIdList($coinEarnings),'block_ids'=>$this->normalizeIdList($coinBlocks));
		}
		foreach($accountsByCoin as $coinId=>$ids)$accountsByCoin[$coinId]=array('account_ids'=>$this->uniqueIdList($ids));
		return $this->artifact($ledger,1,'eligible-work-report.json',$reports,array('selected_earning_ids'=>$this->normalizeIdList($earnings),'selected_block_ids'=>$this->normalizeIdList($blocks),'selected_account_ids'=>$this->uniqueIdList($accounts),'selected_accounts_by_coin'=>$accountsByCoin,'selected_work_by_coin'=>$byCoin));
	}

	public function packageMaturity($ledger, $options) { return $this->packages($ledger,2,'earnings-maturity-transition-approval-package','maturity-packages.json','maturity'); }
	public function applyMaturity($ledger, $options) { return $this->applyPackages($ledger,2,3,'earnings-maturity-transition-apply','maturity-apply-report.json'); }

	public function paymentDelayCheck($ledger, $options)
	{
		$reports=array(); $eligible=array(); foreach((array)$ledger['selected_coin_scope'] as $coin){
			$r=$this->command('account-credit-clear-dryrun',array('--coin-id='.$coin['id'],'--format=json'));
			if(!$this->passed($r))return $this->hold('Payment delay check did not pass for coin '.$coin['id'].'.',$reports); $reports[]=$r;
			foreach($this->items($r,'selected_earnings') as $row){$id=$this->positiveId(arraySafeVal($row,'earning_id'));if($id!==null)$eligible[]=$id;}
		}
		$expected=$this->normalizeIdList((array)arraySafeVal($ledger,'selected_earning_ids',array()));$eligible=$this->normalizeIdList($eligible);
		if($expected!==null&&$eligible!==null&&array_diff($expected,$eligible)===array())return $this->artifact($ledger,4,'payment-delay-report.json',$reports,array('payment_delay_override_used'=>false));
		$override=BadpoolConfirmedBlockPaymentDelayOverride::validate($options,$ledger,$this->guard);
		if(arraySafeVal($override,'status')!=='pass')return $this->hold('The default payment delay remains active; selected earnings are not all older than the 12-hour threshold.',$reports,(array)arraySafeVal($override,'errors',array()));
		$reports[]=array('status'=>'pass','read_only'=>true,'payment_delay_override'=>$override);
		return $this->artifact($ledger,4,'payment-delay-report.json',$reports,array('payment_delay_override_used'=>true,'payment_delay_override_reason'=>arraySafeVal($override,'reason')));
	}

	public function creditAccounts($ledger, $options)
	{
		$packaged=$this->packages($ledger,5,'account-credit-clear-approval-package','account-credit-packages.json','credit'); if(!$this->passed($packaged))return $packaged;
		$applied=$this->applyPackages($ledger,5,5,'account-credit-clear-apply','account-credit-apply-report.json',$packaged['package_path'],$packaged['checksums']); if(!$this->passed($applied))return $applied;
		$accounts=array(); $byCoin=array(); foreach($this->readArtifact($packaged['package_path']) as $package){$coinId=$this->packageCoinId($package);foreach($this->items($package,'selected_earnings') as $row){$id=$this->positiveId(arraySafeVal($row,'account_id',arraySafeVal($row,'userid')));if($id!==null){$accounts[]=$id;if($coinId!==null)$byCoin[(string)$coinId][]=$id;}}}
		foreach($byCoin as $coinId=>$ids)$byCoin[$coinId]=array('account_ids'=>$this->uniqueIdList($ids));
		$applied['selected_account_ids']=$this->uniqueIdList($accounts); $applied['selected_accounts_by_coin']=$byCoin; return $applied;
	}

	public function preparePayoutRows($ledger, $options)
	{
		$recovered=$this->recoverAccountScope($ledger);
		if($recovered!==null){$ledger['selected_account_ids']=$recovered['selected_account_ids'];$ledger['selected_accounts_by_coin']=$recovered['selected_accounts_by_coin'];}
		$existing=$this->recoverExistingPayoutApply($ledger);
		if($existing!==null){if($recovered!==null){$existing['selected_account_ids']=$recovered['selected_account_ids'];$existing['selected_accounts_by_coin']=$recovered['selected_accounts_by_coin'];}return $existing;}
		$packaged=$this->packages($ledger,6,'payout-row-approval-package','payout-row-packages.json','payout'); if(!$this->passed($packaged))return $packaged;
		$applied=$this->applyPackages($ledger,6,6,'payout-row-apply','payout-row-apply-report.json',$packaged['package_path'],$packaged['checksums']); if(!$this->passed($applied))return $applied;
		$ids=array();$inserted=0; foreach($this->readArtifact($applied['report_path']) as $report){$inserted+=intval(arraySafeVal($report,'payout_rows_inserted',arraySafeVal($report,'created_count',0)));foreach((array)arraySafeVal($report,'created_payout_ids',array()) as $id){$pid=$this->positiveId($id);if($pid!==null)$ids[]=$pid;}}
		$ids=$this->normalizeIdList($ids);if($inserted>0&&($ids===null||count($ids)!==$inserted))return $this->hold('Payout-row apply did not return one positive created payout ID per inserted row.');
		if($recovered!==null){$applied['selected_account_ids']=$recovered['selected_account_ids'];$applied['selected_accounts_by_coin']=$recovered['selected_accounts_by_coin'];}
		$applied['created_payout_ids']=$ids;$applied['payout_rows_inserted']=$inserted; return $applied;
	}

	/** Read-only classification at the wallet boundary; never dispatches a guard apply command. */
	public function inspectCreatedPayoutRows($ids)
	{
		$ids=$this->normalizeIdList($ids);if($ids===null||$ids===array())return array();
		$params=array();$placeholders=array();foreach($ids as $i=>$id){$key=':payout_boundary_'.$i;$params[$key]=$id;$placeholders[]=$key;}
		return $this->guard->selectAll("SELECT id, idcoin, IFNULL(completed,0) AS completed, tx FROM payouts WHERE id IN (".implode(',',$placeholders).") ORDER BY id",$params);
	}

	private function coins($options)
	{
		$ids=array(1266,1267,1268,1269,1270);$params=array();$p=array();foreach($ids as $i=>$id){$key=':id'.$i;$p[]=$key;$params[$key]=$id;}
		$sql='SELECT id,symbol,algo FROM coins WHERE id IN ('.implode(',',$p).') AND IFNULL(enable,0)=1 AND IFNULL(installed,0)=1 AND IFNULL(visible,0)=1 AND IFNULL(auto_ready,0)=1';
		$only=arraySafeVal($options,'only'); if($only){$sql.=' AND LOWER(algo)=LOWER(:algo)';$params[':algo']=$only;}
		$sql.=' ORDER BY id';$rows=$this->guard->selectAll($sql,$params);$out=array();
		foreach($rows as $r)$out[]=array('coin_id'=>intval($r['id']),'id'=>intval($r['id']),'symbol'=>(string)$r['symbol'],'algo'=>(string)$r['algo']);return $out;
	}

	private function packages($ledger,$phase,$command,$file,$kind)
	{
		$reports=array();foreach((array)$ledger['selected_coin_scope'] as $coin){
			$args=array('--coin-id='.$coin['id']);if($kind==='maturity'){$work=(array)arraySafeVal((array)arraySafeVal($ledger,'selected_work_by_coin',array()),(string)$coin['id'],array());$ids=(array)arraySafeVal($work,'block_ids',array());if(!$ids)continue;$args[]='--selected-block-ids='.implode(',',$ids);}
			if($kind==='credit'){$work=(array)arraySafeVal((array)arraySafeVal($ledger,'selected_work_by_coin',array()),(string)$coin['id'],array());$ids=$this->normalizeIdList((array)arraySafeVal($work,'earning_ids',array()));if(!$ids)continue;$args[]='--selected-earning-ids='.implode(',',$ids);}
			$args[]='--format=json';$r=$this->command($command,$args);if(!$this->passed($r))return $this->hold('Approval package generation did not pass for coin '.$coin['id'].'.',$reports);
			$r['batch_coin_id']=intval($coin['id']);
			if(!$this->packageWithinLedgerScope($r,$ledger,$kind,$coin))return $this->hold('Approval package did not exactly match the durable per-coin batch selection for coin '.$coin['id'].'.',$reports);
			if($kind==='payout'&&!$this->items($r,'selected_accounts'))continue;
			$reports[]=$r;
		}return $this->artifact($ledger,$phase,$file,$reports,array(),true);
	}

	private function applyPackages($ledger,$sourcePhase,$phase,$command,$file,$path=null,$checksums=null)
	{
		if($path===null)$path=$this->phaseArtifact($ledger,$sourcePhase,'package_path');
		$verify=$this->verifyArtifactChecksum($ledger,$sourcePhase,$path,$checksums); if($verify!==true)return $this->hold($verify);
		$packages=$this->readArtifact($path);if(!is_array($packages))return $this->hold('Required approval package artifact is missing or invalid.');
		$reports=array();foreach($packages as $package){$args=$this->applyArgs($package,$command);if($args===null)return $this->hold('Approval package has no compatible guarded apply command.',$reports);$r=$this->command($command,$args);if(!$this->passed($r))return $this->hold('Guarded apply did not pass.',$reports,arraySafeVal($r,'errors',array()));$reports[]=$r;}return $this->artifact($ledger,$phase,$file,$reports);
	}

	private function applyArgs($package,$command)
	{
		$args=(array)arraySafeVal($package,'apply_command_args',array());if(!$args)$args=(array)arraySafeVal($package,'apply_command_shape',array());$at=array_search($command,$args,true);if($at===false)return null;$args=array_slice($args,$at+1);$checksum=(string)arraySafeVal(arraySafeVal($package,'approval_package_checksum',array()),'value');if(!preg_match('/^[a-f0-9]{64}$/i',$checksum))return null;foreach($args as &$arg)if($arg==='--approval-package-checksum=<approval_package_checksum>')$arg='--approval-package-checksum='.$checksum;return $args;
	}

	private function packageWithinLedgerScope($package,$ledger,$kind,$coin)
	{
		$coinId=(string)$coin['id'];$expected=array();$key=$kind==='payout'?'selected_accounts':'selected_earnings';$field=$kind==='payout'?'account_id':'earning_id';
		if($kind==='payout'){$expected=$this->normalizeIdList((array)arraySafeVal((array)arraySafeVal((array)arraySafeVal($ledger,'selected_accounts_by_coin',array()),$coinId,array()),'account_ids',array()));}
		else{$expected=$this->normalizeIdList((array)arraySafeVal((array)arraySafeVal((array)arraySafeVal($ledger,'selected_work_by_coin',array()),$coinId,array()),'earning_ids',array()));}
		$items=$this->items($package,$key);$actual=$this->idsFromRows($items,$field);if($actual===null)return false;foreach($items as $row){$rowCoin=$this->rowCoinId($row);if($rowCoin!==null&&$rowCoin!==intval($coin['id']))return false;}
		if($kind==='credit'){$expectedAccounts=$this->normalizeIdList((array)arraySafeVal((array)arraySafeVal((array)arraySafeVal($ledger,'selected_accounts_by_coin',array()),$coinId,array()),'account_ids',array()));$actualAccounts=array();foreach($items as $row){$accountId=$this->positiveId(arraySafeVal($row,'account_id',arraySafeVal($row,'userid')));if($accountId===null)return false;$actualAccounts[]=$accountId;}$actualAccounts=$this->uniqueIdList($actualAccounts);if($expectedAccounts===null||$actualAccounts===null||$actualAccounts!==$expectedAccounts)return false;}
		if($expected&&!$actual)return false;return $actual===$expected;
	}

	private function validateBoundedDryrun($report,$coin,$requestedBlocks,$remaining)
	{
		$requested=$this->normalizeIdList($requestedBlocks);$blocks=array();$linked=array();$earnings=array();
		foreach($this->items($report,'linked_blocks') as $row){$bid=$this->positiveId(arraySafeVal($row,'block_id'));if($bid===null)return false;$rowCoin=$this->rowCoinId($row);if($rowCoin!==null&&$rowCoin!==intval($coin['id']))return false;$blocks[]=$bid;foreach((array)arraySafeVal($row,'linked_earning_ids',array()) as $eid){$id=$this->positiveId($eid);if($id===null)return false;$linked[]=$id;}}
		foreach($this->items($report,'selected_earnings') as $row){$eid=$this->positiveId(arraySafeVal($row,'earning_id'));if($eid===null)return false;$rowCoin=$this->rowCoinId($row);if($rowCoin!==null&&$rowCoin!==intval($coin['id']))return false;$earnings[]=$eid;}
		$blocks=$this->normalizeIdList($blocks);$earnings=$this->normalizeIdList($earnings);$linked=$this->normalizeIdList($linked);if($blocks===null||$earnings===null||$linked===null)return false;
		if($blocks!==$requested)return false;if(count($earnings)>intval($remaining))return false;foreach($earnings as $id)if(!in_array($id,$linked,true))return false;return true;
	}

	private function verifyArtifactChecksum($ledger,$phase,$path,$checksums=null)
	{
		if(!is_string($path)||$path===''||!is_file($path))return 'Required approval package artifact is missing.';$key='phase_'.$phase.'_sha256';$expected=null;
		if(is_array($checksums))$expected=arraySafeVal($checksums,$key);if($expected===null)$expected=arraySafeVal((array)arraySafeVal($ledger,'checksums',array()),$key);
		if($expected===null)foreach((array)arraySafeVal($ledger,'phase_results',array()) as $r)if(intval(arraySafeVal($r,'phase_number',-1))===$phase)$expected=arraySafeVal((array)arraySafeVal($r,'checksum_summary',array()),$key);
		if(!is_string($expected)||!preg_match('/^[a-f0-9]{64}$/i',$expected))return 'Required approval package artifact checksum is missing or malformed.';$actual=hash_file('sha256',$path);if(!hash_equals(strtolower($expected),strtolower($actual)))return 'Approval package artifact checksum mismatch for phase '.$phase.'.';return true;
	}

	private function recoverAccountScope($ledger)
	{
		$current=$this->normalizeIdList((array)arraySafeVal($ledger,'selected_account_ids',array()));
		$byCoin=(array)arraySafeVal($ledger,'selected_accounts_by_coin',array());
		$needsRecovery=$current===array();
		foreach((array)arraySafeVal($ledger,'selected_coin_scope',array()) as $coin){$coinId=(string)arraySafeVal($coin,'id');$ids=arraySafeVal((array)arraySafeVal($byCoin,$coinId,array()),'account_ids',array());if(!is_array($ids)||$ids===array())$needsRecovery=true;}
		if(!$needsRecovery)return null;
		$path=$this->phaseArtifact($ledger,5,'package_path');if(!is_string($path)||$path==='')$path=arraySafeVal($ledger,'run_directory').'/account-credit-packages.json';$packages=$this->readArtifact($path);if(!is_array($packages))return null;
		$accounts=array();$recoveredByCoin=array();
		foreach($packages as $package){$coinId=$this->packageCoinId($package);foreach($this->items($package,'selected_earnings') as $row){$id=$this->positiveId(arraySafeVal($row,'account_id',arraySafeVal($row,'userid')));if($id!==null){$accounts[]=$id;if($coinId!==null)$recoveredByCoin[(string)$coinId][]=$id;}}}
		$accounts=$this->uniqueIdList($accounts);if($accounts===null||$accounts===array())return null;
		foreach($recoveredByCoin as $coinId=>$ids){$ids=$this->uniqueIdList($ids);if($ids===null)return null;$recoveredByCoin[$coinId]=array('account_ids'=>$ids);}
		return array('selected_account_ids'=>$accounts,'selected_accounts_by_coin'=>$recoveredByCoin);
	}

	private function recoverExistingPayoutApply($ledger)
	{
		$apply=$this->successfulPhaseApply($ledger,6);$reportPath=arraySafeVal((array)$apply,'report_path');$reports=arraySafeVal((array)$apply,'reports');
		if($apply===null){
			$paths=array(arraySafeVal($ledger,'run_directory').'/payout-row-apply-report.json');foreach((array)arraySafeVal($ledger,'phase_results',array()) as $r)if(intval(arraySafeVal($r,'phase_number',-1))===6)$paths[]=arraySafeVal($r,'report_path');
			foreach(array_unique($paths) as $path)if(is_string($path)&&$path!==''&&$this->artifactInsertCount($this->readArtifact($path))>0)return $this->hold('Cannot recover payout IDs: no successful phase 6 apply entry matches the existing payout-row apply artifact.');
			return null;
		}
		$inserted=0;$artifactIds=array();$hasBadId=false;foreach($reports as $report){$count=intval(arraySafeVal($report,'payout_rows_inserted',arraySafeVal($report,'created_count',0)));$inserted+=$count;$raw=(array)arraySafeVal($report,'created_payout_ids',array());foreach($raw as $id){$pid=$this->positiveId($id);if($pid===null)$hasBadId=true;else $artifactIds[]=$pid;}if($count>0&&count($raw)!==$count)$hasBadId=true;}
		$artifactIds=$this->normalizeIdList($artifactIds);if(!$hasBadId&&$artifactIds!==null&&count($artifactIds)===$inserted)return array('status'=>'pass','mutation_scope'=>array('payout_row_recovery'=>'existing_positive_artifact_ids'),'report_path'=>$reportPath,'created_payout_ids'=>$artifactIds,'payout_rows_inserted'=>$inserted,'warnings'=>array('Recovered phase 6 from existing payout-row apply artifact; no payout rows were created.'));
		$packagePath=arraySafeVal($apply,'package_path');if(!is_string($packagePath)||$packagePath==='')$packagePath=arraySafeVal($ledger,'run_directory').'/payout-row-packages.json';
		$packages=$this->readArtifact($packagePath);if(!is_array($packages)||count($packages)!==count($reports))return $this->hold('Cannot recover payout IDs: payout package/apply artifact pairing is missing or mismatched.');
		$window=$apply['window'];
		$ids=array();$expected=0;foreach($packages as $idx=>$package){$items=$this->items($package,'selected_accounts');$report=$reports[$idx];$count=intval(arraySafeVal($report,'payout_rows_inserted',arraySafeVal($report,'created_count',0)));if($count!==count($items))return $this->hold('Cannot recover payout IDs: payout apply count does not match approved selected accounts.');foreach($items as $item){$id=$this->strictPayoutIdMatch($item,$window[0],$window[1]);if($id===null)return $this->hold('Cannot recover payout IDs: exact matching payout row was not unique for account '.arraySafeVal($item,'account_id').'.');$ids[]=$id;$expected++;}}
		$ids=$this->normalizeIdList($ids);if($ids===null||count($ids)!==$expected)return $this->hold('Cannot recover payout IDs: recovered ID list is malformed or duplicated.');
		return array('status'=>'pass','mutation_scope'=>array('payout_row_recovery'=>'strict_read_only_existing_artifact'),'package_path'=>$packagePath,'report_path'=>$reportPath,'created_payout_ids'=>$ids,'payout_rows_inserted'=>$expected,'warnings'=>array('Recovered payout IDs from existing [0] payout-row apply artifact by strict DB matching; no payout rows were created and no wallet action was invoked.'));
	}

	private function successfulPhaseApply($ledger,$phase)
	{
		$match=null;foreach((array)arraySafeVal($ledger,'phase_results',array()) as $r){
			if(intval(arraySafeVal($r,'phase_number',-1))!==$phase||!in_array(strtolower((string)arraySafeVal($r,'status','')),array('pass','ok'),true))continue;
			$path=arraySafeVal($r,'report_path');if(!is_string($path)||$path==='')continue;$reports=$this->readArtifact($path);if($this->artifactInsertCount($reports)<=0)continue;
			$window=$this->phaseTimeWindow($r);if($window===null)continue;$match=array('report_path'=>$path,'package_path'=>arraySafeVal($r,'package_path'),'reports'=>$reports,'window'=>$window);
		}return $match;
	}

	private function artifactInsertCount($reports){if(!is_array($reports))return 0;$count=0;foreach($reports as $report)$count+=intval(arraySafeVal($report,'payout_rows_inserted',arraySafeVal($report,'created_count',0)));return $count;}

	private function strictPayoutIdMatch($item,$start,$end)
	{
		$rows=$this->guard->selectAll("SELECT id FROM payouts WHERE account_id=:account_id AND idcoin=:idcoin AND time BETWEEN :start_time AND :end_time AND amount=:amount AND IFNULL(completed,0)=0 AND (tx IS NULL OR tx='') ORDER BY id",array(':account_id'=>arraySafeVal($item,'account_id'),':idcoin'=>arraySafeVal($item,'account_coinid',arraySafeVal($item,'idcoin')),':start_time'=>$start,':end_time'=>$end,':amount'=>arraySafeVal($item,'projected_payout_row_amount',arraySafeVal($item,'amount'))));
		if(!is_array($rows)||count($rows)!==1)return null;return $this->positiveId(arraySafeVal($rows[0],'id'));
	}

	private function phaseTimeWindow($r)
	{
		$start=strtotime((string)arraySafeVal($r,'started_at',''));$end=strtotime((string)arraySafeVal($r,'finished_at',''));if($start===false||$end===false||$end<$start)return null;return array($start,$end);
	}

	private function rowCoinId($row){foreach(array('coin_id','coinid','account_coinid','idcoin') as $k){$id=$this->positiveId(arraySafeVal($row,$k));if($id!==null)return $id;}return null;}
	private function packageCoinId($package){$id=$this->positiveId(arraySafeVal($package,'batch_coin_id'));if($id!==null)return $id;$id=$this->positiveId(arraySafeVal(arraySafeVal($package,'scope_binding',array()),'coin_id'));if($id!==null)return $id;foreach(array('selected_earnings','selected_accounts') as $key)foreach($this->items($package,$key) as $row){$id=$this->rowCoinId($row);if($id!==null)return $id;}return null;}
	private function positiveId($value){if(is_int($value)&&$value>0)return $value;if(is_string($value)&&preg_match('/^[1-9][0-9]*$/',$value))return intval($value);return null;}
	private function idsFromRows($rows,$field){$ids=array();foreach((array)$rows as $row){$id=$this->positiveId(arraySafeVal($row,$field));if($id===null||in_array($id,$ids,true))return null;$ids[]=$id;}sort($ids,SORT_NUMERIC);return $ids;}
	private function normalizeIdList($ids){$out=array();foreach((array)$ids as $id){$id=$this->positiveId($id);if($id===null||in_array($id,$out,true))return null;$out[]=$id;}sort($out,SORT_NUMERIC);return $out;}
	private function uniqueIdList($ids){$out=array();foreach((array)$ids as $id){$id=$this->positiveId($id);if($id===null)return null;if(!in_array($id,$out,true))$out[]=$id;}sort($out,SORT_NUMERIC);return $out;}
	private function command($command,$args){return call_user_func($this->execute,$command,$args);}
	private function passed($r){return is_array($r)&&in_array(strtolower((string)arraySafeVal($r,'status')),array('ok','pass'),true);}
	private function items($r,$key){return (array)arraySafeVal(arraySafeVal($r,'items',array()),$key,array());}
	private function hold($warning,$reports=array(),$errors=array()){return array('status'=>'hold','mutation_scope'=>array(),'warnings'=>array($warning),'errors'=>(array)$errors,'reports'=>$reports);}
	private function phaseArtifact($ledger,$phase,$field){foreach((array)$ledger['phase_results'] as $r)if(intval(arraySafeVal($r,'phase_number',-1))===$phase)return arraySafeVal($r,$field);return null;}
	private function readArtifact($path){$v=is_string($path)&&is_file($path)?json_decode(file_get_contents($path),true):null;return is_array($v)?$v:null;}
	private function artifact($ledger,$phase,$file,$data,$extra=array(),$package=false){$path=$ledger['run_directory'].'/'.$file;$json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";$tmp=$path.'.tmp';if(file_put_contents($tmp,$json,LOCK_EX)===false||!rename($tmp,$path))return $this->hold('Unable to persist phase artifact.');$key=$package?'package_path':'report_path';return array_merge(array('status'=>'pass','mutation_scope'=>array(),'checksums'=>array('phase_'.$phase.'_sha256'=>hash_file('sha256',$path)),$key=>$path),$extra);}
}
