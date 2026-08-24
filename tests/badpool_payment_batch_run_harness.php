<?php
class CConsoleCommand {}
function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}
require_once(dirname(__DIR__).'/web/yaamp/commands/BadpoolGuardCommand.php');

class FakeBatchAdapter {
	public $calls=array(); public $delay=false; public $holdPhase=null;
	protected function result($n,$extra=array()){$this->calls[]=$n;if($this->holdPhase===$n)$extra=array_merge($extra,array('status'=>'hold'));return array_merge(array('status'=>'pass','mutation_scope'=>array()),$extra);}
	public function safetyCheck($l,$o){return $this->result(0);}
	public function selectEligibleWork($l,$o){return $this->result(1,array('selected_coin_scope'=>array(array('id'=>1267,'algo'=>'scrypt')),'selected_earning_ids'=>array(11,12),'selected_block_ids'=>array(4)));}
	public function packageMaturity($l,$o){return $this->result(2,array('package_path'=>$l['run_directory'].'/maturity.json','checksums'=>array('maturity'=>'abc')));}
	public function applyMaturity($l,$o){return $this->result(3,array('report_path'=>$l['run_directory'].'/maturity-apply.json'));}
	public function paymentDelayCheck($l,$o){return $this->delay?$this->result(4,array('status'=>'hold','warnings'=>array('resume_after_utc=2099-01-01T00:00:00Z'))):$this->result(4);}
	public function creditAccounts($l,$o){return $this->result(5,array('selected_account_ids'=>array(9),'package_path'=>$l['run_directory'].'/credit.json','report_path'=>$l['run_directory'].'/credit-apply.json','checksums'=>array('credit'=>'def')));}
	public function preparePayoutRows($l,$o){return $this->result(6,array('created_payout_ids'=>array(77),'payout_rows_inserted'=>1,'package_path'=>$l['run_directory'].'/payout.json','report_path'=>$l['run_directory'].'/payout-apply.json','checksums'=>array('payout'=>'ghi')));}
}
class FakeBatchGuardCommand extends BadpoolGuardCommand {public static $adapter;protected function paymentBatchPhaseAdapter(){return self::$adapter;}}
function expect_batch($v,$m,&$f){if(!$v)$f[]=$m;}
$fail=array();$root=sys_get_temp_dir().'/badpool-batch-test-'.getmypid();$opts=array('mode'=>'auto','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>250);
$fake=new FakeBatchAdapter();$runner=new BadpoolPaymentBatchRunner($fake,$root);$r=$runner->run($opts);
expect_batch($r['schema']==='badpool.payment_batch.run.v1','schema',$fail);
expect_batch($r['status']==='pass'&&$r['batch_state']==='READY_FOR_WALLET_APPROVAL','phase 6 state',$fail);
expect_batch($r['stop_before_wallet_send']===true&&$r['wallet_boundary']==='blocked_human_required','wallet boundary',$fail);
expect_batch($r['created_payout_ids']===array(77)&&is_file($r['ledger_path']),'ledger/payout persistence',$fail);
expect_batch($r['selected_counts']['payouts']===1,'positive payout count reflected',$fail);
expect_batch(count($fake->calls)===7&&!in_array(7,$fake->calls,true),'wallet method reached',$fail);
expect_batch(strpos(json_encode($r),'Phase adapter is not configured')===false,'adapter configured warning',$fail);
expect_batch($fake->calls===array(0,1,2,3,4,5,6),'ordered phases',$fail);
expect_batch(count($r['phase_results'])===7&&$r['phase_results'][6]['phase_number']===6&&$r['phase_results'][6]['status']==='pass','ready only after phase 6',$fail);
$before=$fake->calls;$opts['resume_batch_id']=$r['batch_id'];$again=$runner->run($opts);expect_batch($fake->calls===$before&&$again['batch_state']==='READY_FOR_WALLET_APPROVAL','resume repeated phase',$fail);
$fake2=new FakeBatchAdapter();$fake2->delay=true;$hold=(new BadpoolPaymentBatchRunner($fake2,$root.'-hold'))->run(array('mode'=>'catchup','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>1));expect_batch($hold['batch_state']==='WAITING_PAYMENT_DELAY'&&!in_array(5,$fake2->calls,true),'delay did not hold',$fail);
$fake3=new FakeBatchAdapter();$fake3->holdPhase=3;$root3=$root.'-phase-hold';$runner3=new BadpoolPaymentBatchRunner($fake3,$root3);$hold3=$runner3->run(array('mode'=>'auto','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>2));expect_batch($fake3->calls===array(0,1,2,3)&&is_file($hold3['ledger_path'])&&$hold3['status']==='hold','hold preserves ledger and stops later phases',$fail);
$fake3->holdPhase=null;$resumed=$runner3->run(array('mode'=>'normal','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>999,'resume_batch_id'=>$hold3['batch_id']));expect_batch($fake3->calls===array(0,1,2,3,3,4,5,6)&&$resumed['batch_state']==='READY_FOR_WALLET_APPROVAL'&&$resumed['mode']==='auto'&&$resumed['batch_size']===2,'resume skips passed phases and preserves options',$fail);
$legacyRoot=$root.'-legacy';$legacy=(new BadpoolPaymentBatchRunner(null,$legacyRoot))->run(array('mode'=>'auto','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>3));$repairedAdapter=new FakeBatchAdapter();$repaired=(new BadpoolPaymentBatchRunner($repairedAdapter,$legacyRoot))->run(array('mode'=>'auto','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>3,'resume_batch_id'=>$legacy['batch_id']));expect_batch($repairedAdapter->calls===array(0,1,2,3,4,5,6)&&$repaired['batch_state']==='READY_FOR_WALLET_APPROVAL','legacy adapter hold resumes after repair',$fail);

class ZeroPayoutIdAdapter extends FakeBatchAdapter { public function preparePayoutRows($l,$o){return $this->result(6,array('created_payout_ids'=>array(0),'payout_rows_inserted'=>1,'package_path'=>$l['run_directory'].'/payout.json','report_path'=>$l['run_directory'].'/payout-apply.json','checksums'=>array('payout'=>'bad')));} }
$zero=(new BadpoolPaymentBatchRunner(new ZeroPayoutIdAdapter(),$root.'-zero-id'))->run(array('mode'=>'auto','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>250));
expect_batch($zero['status']==='hold'&&$zero['batch_state']==='HOLD','zero payout id must hold',$fail);
expect_batch($zero['created_payout_ids']===array()&&$zero['selected_counts']['payouts']===0,'zero payout id not persisted as selected payout',$fail);
expect_batch(strpos(json_encode($zero),'READY_FOR_WALLET_APPROVAL')===false,'zero payout id cannot reach wallet approval',$fail);

class ProductionFixtureGuard {public function selectAll($sql,$params){return array(array('id'=>1267,'symbol'=>'BAD','algo'=>'scrypt','enable'=>1,'installed'=>1,'visible'=>1,'auto_ready'=>1,'payout_min'=>null));}}
class ProductionFixtureExecutor {
	public $calls=array();
	public function run($command,$args){$this->calls[]=array($command,$args);$base=array('status'=>'pass','items'=>array());
		if($command==='safety-scan')return $base;
		if($command==='earnings-maturity-transition-dryrun')return array_merge($base,array('items'=>array('selected_earnings'=>array(array('earning_id'=>11,'userid'=>9),array('earning_id'=>12,'userid'=>9)),'linked_blocks'=>array(array('block_id'=>4,'linked_earning_ids'=>array(11,12))))));
		if($command==='earnings-maturity-transition-approval-package')return array_merge($base,array('items'=>array('selected_earnings'=>array(array('earning_id'=>11),array('earning_id'=>12))),'approval_package_checksum'=>array('value'=>str_repeat('a',64)),'apply_command_shape'=>array('php','badpoolguard','earnings-maturity-transition-apply','--approval-package-checksum=<approval_package_checksum>','--format=json')));
		if($command==='account-credit-clear-dryrun')return array_merge($base,array('items'=>array('selected_earnings'=>array(array('earning_id'=>11,'account_id'=>9),array('earning_id'=>12,'account_id'=>9)))));
		if($command==='account-credit-clear-approval-package')return array_merge($base,array('items'=>array('selected_earnings'=>array(array('earning_id'=>11,'account_id'=>9),array('earning_id'=>12,'account_id'=>9))),'approval_package_checksum'=>array('value'=>str_repeat('b',64)),'apply_command_args'=>array('badpoolguard','account-credit-clear-apply','--approval-package-checksum=<approval_package_checksum>','--format=json')));
		if($command==='payout-row-approval-package')return array_merge($base,array('items'=>array('selected_accounts'=>array(array('account_id'=>9,'account_coinid'=>1267,'projected_payout_row_amount'=>'12.50000000'))),'approval_package_checksum'=>array('value'=>str_repeat('c',64)),'apply_command_shape'=>array('badpoolguard','payout-row-apply','--approval-package-checksum=<approval_package_checksum>','--format=json')));
		if($command==='payout-row-apply')return array_merge($base,array('created_payout_ids'=>array(77),'payout_rows_inserted'=>1));return $base;}
}
$productionRoot=$root.'-production';$productionExec=new ProductionFixtureExecutor();$productionAdapter=new BadpoolPaymentBatchPhaseAdapter(new ProductionFixtureGuard(),array($productionExec,'run'));$production=(new BadpoolPaymentBatchRunner($productionAdapter,$productionRoot))->run(array('mode'=>'auto','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>2));$productionCommands=array_map(function($call){return $call[0];},$productionExec->calls);
expect_batch($production['batch_state']==='READY_FOR_WALLET_APPROVAL'&&$production['created_payout_ids']===array(77),'production adapter phases 1-6 complete',$fail);
expect_batch($production['selected_coin_scope']===array(array('coin_id'=>1267,'id'=>1267,'symbol'=>'BAD','algo'=>'scrypt')),'active coin with null payout_min selected',$fail);
expect_batch($production['selected_counts']['accounts']===1,'repeated credited account counted once',$fail);
$productionLedger=json_decode(file_get_contents($production['ledger_path']),true);
expect_batch($productionLedger['selected_account_ids']===array(9),'repeated credited account persisted once',$fail);
expect_batch($productionLedger['selected_accounts_by_coin']['1267']['account_ids']===array(9),'repeated credited per-coin account persisted once',$fail);
expect_batch(in_array('earnings-maturity-transition-apply',$productionCommands,true)&&in_array('account-credit-clear-apply',$productionCommands,true)&&in_array('payout-row-apply',$productionCommands,true),'guarded production applies invoked',$fail);
expect_batch(!in_array('wallet-send-apply',$productionCommands,true)&&count($production['phase_results'])===7,'production adapter wallet boundary',$fail);
$maturityPackageCall=array_values(array_filter($productionExec->calls,function($call){return $call[0]==='earnings-maturity-transition-approval-package';}));expect_batch(strpos(implode(' ',$maturityPackageCall[0][1]),'--selected-block-ids=4')!==false,'maturity package bound to selected blocks',$fail);


class StrictFixtureGuard extends ProductionFixtureGuard {
	public $rows;
	public function __construct($rows){$this->rows=$rows;}
	public function selectAll($sql,$params){$out=array();foreach($this->rows as $row){if(isset($params[':algo'])&&strtolower($row['algo'])!==strtolower($params[':algo']))continue;if(empty($row['enable'])||empty($row['installed'])||empty($row['visible'])||empty($row['auto_ready']))continue;$out[]=$row;}return $out;}
}
class StrictFixtureExecutor extends ProductionFixtureExecutor {
	public $mode=null;
	public function run($command,$args){
		$r=parent::run($command,$args);
		if($command==='earnings-maturity-transition-dryrun'&&strpos(implode(' ',$args),'--selected-block-ids=4')!==false){
			if($this->mode==='wrong_block')$r['items']['linked_blocks']=array(array('block_id'=>5,'linked_earning_ids'=>array(11),'coin_id'=>1267));
			if($this->mode==='wrong_coin'){$r['items']['selected_earnings'][0]['coin_id']=1268;$r['items']['linked_blocks'][0]['coin_id']=1268;}
			if($this->mode==='duplicate_earning')$r['items']['selected_earnings'][]=$r['items']['selected_earnings'][0];
		}
		if($command==='earnings-maturity-transition-approval-package'){
			if($this->mode==='empty_package')$r['items']['selected_earnings']=array();
			if($this->mode==='subset_package')$r['items']['selected_earnings']=array();
			if($this->mode==='widened_package')$r['items']['selected_earnings'][]=array('earning_id'=>12,'coin_id'=>1267);
			if($this->mode==='cross_coin_package')$r['items']['selected_earnings'][0]['coin_id']=1268;
			if($this->mode==='duplicate_package')$r['items']['selected_earnings'][]=$r['items']['selected_earnings'][0];
		}
		if($command==='account-credit-clear-approval-package'){
			if($this->mode==='account_widened')$r['items']['selected_earnings'][]=array('earning_id'=>12,'account_id'=>10,'coin_id'=>1267);
		}
		if($command==='payout-row-approval-package'){
			if($this->mode==='payout_empty')$r['items']['selected_accounts']=array();
			if($this->mode==='payout_widened')$r['items']['selected_accounts'][]=array('account_id'=>10,'account_coinid'=>1267);
			if($this->mode==='payout_wrong_account')$r['items']['selected_accounts']=array(array('account_id'=>10,'account_coinid'=>1267));
		}
		return $r;
	}
}
function run_strict_batch($mode,&$commands){
	global $root;
	$exec=new StrictFixtureExecutor();$exec->mode=$mode;
	$guard=new StrictFixtureGuard(array(
		array('id'=>1267,'symbol'=>'BAD','algo'=>'scrypt','enable'=>1,'installed'=>1,'visible'=>1,'auto_ready'=>1,'payout_min'=>null),
		array('id'=>1268,'symbol'=>'BAD','algo'=>'scrypt','enable'=>0,'installed'=>1,'visible'=>1,'auto_ready'=>0,'payout_min'=>null),
	));
	$r=(new BadpoolPaymentBatchRunner(new BadpoolPaymentBatchPhaseAdapter($guard,array($exec,'run')),$root.'-strict-'.($mode===null?'ok':$mode)))->run(array('mode'=>'auto','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>2));
	$commands=array_map(function($call){return $call[0];},$exec->calls);
	return $r;
}
foreach(array('wrong_block','wrong_coin','duplicate_earning','empty_package','subset_package','widened_package','cross_coin_package','duplicate_package','account_widened','payout_empty','payout_widened','payout_wrong_account') as $mode){
	$cmds=array();$x=run_strict_batch($mode,$cmds);
	expect_batch($x['status']==='hold'||$x['batch_state']==='HOLD',$mode.' did not hold',$fail);
}
$cmds=array();$ok=run_strict_batch(null,$cmds);
expect_batch($ok['batch_state']==='READY_FOR_WALLET_APPROVAL','strict happy path failed',$fail);
expect_batch(count($ok['selected_coin_scope'])===1&&$ok['selected_coin_scope'][0]['id']===1267,'inactive coin was not excluded',$fail);
$recoveryRoot=$root.'-recovery';$recoveryExec=new StrictFixtureExecutor();$recoveryExec->mode='payout_widened';$recoveryRunner=new BadpoolPaymentBatchRunner(new BadpoolPaymentBatchPhaseAdapter(new ProductionFixtureGuard(),array($recoveryExec,'run')),$recoveryRoot);$held=$recoveryRunner->run(array('mode'=>'auto','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>2));$heldLedger=json_decode(file_get_contents($held['ledger_path']),true);$heldLedger['selected_account_ids']=array();$heldLedger['selected_accounts_by_coin']['1267']['account_ids']=null;file_put_contents($held['ledger_path'],json_encode($heldLedger,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n",LOCK_EX);
$resumeExec=new StrictFixtureExecutor();$resumeRunner=new BadpoolPaymentBatchRunner(new BadpoolPaymentBatchPhaseAdapter(new ProductionFixtureGuard(),array($resumeExec,'run')),$recoveryRoot);$recovered=$resumeRunner->run(array('mode'=>'auto','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>2,'resume_batch_id'=>$held['batch_id']));$recoveredLedger=json_decode(file_get_contents($recovered['ledger_path']),true);
expect_batch($recovered['batch_state']==='READY_FOR_WALLET_APPROVAL','held null account scope did not recover',$fail);
expect_batch($recoveredLedger['selected_account_ids']===array(9)&&$recoveredLedger['selected_accounts_by_coin']['1267']['account_ids']===array(9),'recovered account scope was not persisted',$fail);

class ZeroArtifactExecutor extends ProductionFixtureExecutor {
	public function run($command,$args){$r=parent::run($command,$args);if($command==='payout-row-apply')$r=array_merge($r,array('created_payout_ids'=>array(0),'payout_rows_inserted'=>1,'created_count'=>1));return $r;}
}
class PayoutRecoveryGuard extends ProductionFixtureGuard {
	public $payoutRows;
	public function __construct($rows){$this->payoutRows=$rows;}
	public function selectAll($sql,$params){
		if(strpos($sql,'FROM payouts WHERE')!==false){
			$out=array();foreach($this->payoutRows as $row){$rowTime=array_key_exists('time',$row)&&$row['time']!=='window'?intval($row['time']):intval($params[':start_time']);if(intval($row['account_id'])!==intval($params[':account_id']))continue;if(intval($row['idcoin'])!==intval($params[':idcoin']))continue;if(strval($row['amount'])!==strval($params[':amount']))continue;if($rowTime<intval($params[':start_time'])||$rowTime>intval($params[':end_time']))continue;$out[]=array('id'=>$row['id']);}return $out;
		}
		return parent::selectAll($sql,$params);
	}
}
function run_payout_id_recovery_case($suffix,$rows,&$resumeCommands,$successfulApply=true){
	global $root;
	$caseRoot=$root.'-payout-id-recovery-'.$suffix;
	$firstExec=new ZeroArtifactExecutor();$guard=new PayoutRecoveryGuard($rows);
	$first=(new BadpoolPaymentBatchRunner(new BadpoolPaymentBatchPhaseAdapter($guard,array($firstExec,'run')),$caseRoot))->run(array('mode'=>'auto','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>2));
	$ledger=json_decode(file_get_contents($first['ledger_path']),true);$kept=array();foreach($ledger['phase_results'] as $entry)if(intval($entry['phase_number'])!==6)$kept[]=$entry;
	$package=$first['run_directory'].'/payout-row-packages.json';$report=$first['run_directory'].'/payout-row-apply-report.json';
	$kept[]=array('phase_number'=>6,'status'=>'hold','started_at'=>'2026-08-22T01:27:16+00:00','finished_at'=>'2026-08-22T01:27:16+00:00');
	if($successfulApply)$kept[]=array('phase_number'=>6,'status'=>'pass','started_at'=>'2026-08-23T00:08:16+00:00','finished_at'=>'2026-08-23T00:08:17+00:00','package_path'=>$package,'report_path'=>$report);
	$kept[]=array('phase_number'=>6,'status'=>'hold','started_at'=>'2026-08-23T22:21:57+00:00','finished_at'=>'2026-08-23T22:21:57+00:00');$ledger['phase_results']=$kept;file_put_contents($first['ledger_path'],json_encode($ledger,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n",LOCK_EX);
	$resumeExec=new ZeroArtifactExecutor();
	$resumed=(new BadpoolPaymentBatchRunner(new BadpoolPaymentBatchPhaseAdapter($guard,array($resumeExec,'run')),$caseRoot))->run(array('mode'=>'auto','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>2,'resume_batch_id'=>$first['batch_id']));
	$resumeCommands=array_map(function($call){return $call[0];},$resumeExec->calls);
	return $resumed;
}
$successfulWindow=strtotime('2026-08-23T00:08:16+00:00');
$resumeCommands=array();$one=run_payout_id_recovery_case('one',array(array('id'=>881,'account_id'=>9,'idcoin'=>1267,'time'=>$successfulWindow,'amount'=>'12.50000000')),$resumeCommands);
expect_batch($one['batch_state']==='READY_FOR_WALLET_APPROVAL'&&$one['created_payout_ids']===array(881),'strict one-match payout ID recovery failed',$fail);
expect_batch(!in_array('payout-row-apply',$resumeCommands,true)&&!in_array('wallet-send-apply',$resumeCommands,true),'recovery must not reapply payout rows or invoke wallet send',$fail);
$resumeCommands=array();$holds=run_payout_id_recovery_case('holds-only',array(array('id'=>880,'account_id'=>9,'idcoin'=>1267,'time'=>strtotime('2026-08-22T01:27:16+00:00'),'amount'=>'12.50000000')),$resumeCommands,false);
expect_batch($holds['status']==='hold'&&$holds['batch_state']==='HOLD','stale hold-only phase windows must not recover payout IDs',$fail);
expect_batch(!in_array('payout-row-apply',$resumeCommands,true),'hold-only recovery must not reapply payout rows',$fail);
$resumeCommands=array();$none=run_payout_id_recovery_case('none',array(),$resumeCommands);
expect_batch($none['status']==='hold'&&$none['batch_state']==='HOLD','zero-match payout ID recovery must hold',$fail);
expect_batch(!in_array('payout-row-apply',$resumeCommands,true),'zero-match recovery must not reapply payout rows',$fail);
$resumeCommands=array();$many=run_payout_id_recovery_case('many',array(array('id'=>882,'account_id'=>9,'idcoin'=>1267,'time'=>$successfulWindow,'amount'=>'12.50000000'),array('id'=>883,'account_id'=>9,'idcoin'=>1267,'time'=>$successfulWindow,'amount'=>'12.50000000')),$resumeCommands);
expect_batch($many['status']==='hold'&&$many['batch_state']==='HOLD','multiple-match payout ID recovery must hold',$fail);
expect_batch(!in_array('payout-row-apply',$resumeCommands,true),'multiple-match recovery must not reapply payout rows',$fail);

function command_batch($args,&$rc){$c=new FakeBatchGuardCommand();FakeBatchGuardCommand::$adapter=new FakeBatchAdapter();ob_start();$rc=$c->run(array_merge(array('batch-run'),$args));return ob_get_clean();}
$j=json_decode(command_batch(array('--format=json'),$rc),true);expect_batch(is_array($j)&&$j['scope']==='all-active-payout-coins'&&$j['batch_size']===250,'default/no coin id',$fail);expect_batch($j['stop_before_wallet_send']===true,'default stop',$fail);
$bad=json_decode(command_batch(array('--only=randomx','--format=json'),$rc),true);expect_batch($rc===2&&$bad['status']==='refused','invalid only',$fail);$bad=json_decode(command_batch(array('--batch-size=0','--format=json'),$rc),true);expect_batch($rc===2&&$bad['status']==='refused','invalid size',$fail);
$text=command_batch(array('--format=text'),$rc);foreach(array('CLASSIFICATION=','BATCH_STATE=','NEXT_ACTION=','run_directory=','ledger_path=','7      Send Wallet Payment          BLOCKED_HUMAN_REQUIRED','9      Batch Complete') as $needle)expect_batch(strpos($text,$needle)!==false,'text '.$needle,$fail);
$preview=new FakeBatchGuardCommand();ob_start();$previewRc=$preview->run(array('batch-run-preview','--format=json'));$previewJson=json_decode(ob_get_clean(),true);expect_batch($previewRc===0&&$previewJson['status']==='ok'&&$previewJson['read_only']===true&&$previewJson['db_mutations']===false,'preview remains read only',$fail);expect_batch($previewJson['wallet_sends']===false&&$previewJson['inferred_coin_scope']['coin_ids_required']===false,'preview wallet/default scope',$fail);
function rrmdir_batch($d){if(!is_dir($d))return;foreach(scandir($d) as $x)if($x!=='.'&&$x!=='..'){is_dir($d.'/'.$x)?rrmdir_batch($d.'/'.$x):unlink($d.'/'.$x);}rmdir($d);}foreach(array($root,$root.'-hold',$root3,$legacyRoot,$root.'-zero-id',$productionRoot,$recoveryRoot,$root.'-payout-id-recovery-one',$root.'-payout-id-recovery-holds-only',$root.'-payout-id-recovery-none',$root.'-payout-id-recovery-many') as $d)rrmdir_batch($d);
if($fail){echo "Badpool payment batch run harness FAILED\n - ".implode("\n - ",$fail)."\n";exit(1);}echo "Badpool payment batch run harness passed\n";
