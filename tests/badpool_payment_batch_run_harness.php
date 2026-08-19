<?php
class CConsoleCommand {}
function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}
require_once(dirname(__DIR__).'/web/yaamp/commands/BadpoolGuardCommand.php');

class FakeBatchAdapter {
	public $calls=array(); public $delay=false; public $holdPhase=null;
	private function result($n,$extra=array()){$this->calls[]=$n;if($this->holdPhase===$n)$extra=array_merge($extra,array('status'=>'hold'));return array_merge(array('status'=>'pass','mutation_scope'=>array()),$extra);}
	public function safetyCheck($l,$o){return $this->result(0);}
	public function selectEligibleWork($l,$o){return $this->result(1,array('selected_coin_scope'=>array(array('id'=>1267,'algo'=>'scrypt')),'selected_earning_ids'=>array(11,12),'selected_block_ids'=>array(4)));}
	public function packageMaturity($l,$o){return $this->result(2,array('package_path'=>$l['run_directory'].'/maturity.json','checksums'=>array('maturity'=>'abc')));}
	public function applyMaturity($l,$o){return $this->result(3,array('report_path'=>$l['run_directory'].'/maturity-apply.json'));}
	public function paymentDelayCheck($l,$o){return $this->delay?$this->result(4,array('status'=>'hold','warnings'=>array('resume_after_utc=2099-01-01T00:00:00Z'))):$this->result(4);}
	public function creditAccounts($l,$o){return $this->result(5,array('selected_account_ids'=>array(9),'package_path'=>$l['run_directory'].'/credit.json','report_path'=>$l['run_directory'].'/credit-apply.json','checksums'=>array('credit'=>'def')));}
	public function preparePayoutRows($l,$o){return $this->result(6,array('created_payout_ids'=>array(77),'package_path'=>$l['run_directory'].'/payout.json','report_path'=>$l['run_directory'].'/payout-apply.json','checksums'=>array('payout'=>'ghi')));}
}
class FakeBatchGuardCommand extends BadpoolGuardCommand {public static $adapter;protected function paymentBatchPhaseAdapter(){return self::$adapter;}}
function expect_batch($v,$m,&$f){if(!$v)$f[]=$m;}
$fail=array();$root=sys_get_temp_dir().'/badpool-batch-test-'.getmypid();$opts=array('mode'=>'auto','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>250);
$fake=new FakeBatchAdapter();$runner=new BadpoolPaymentBatchRunner($fake,$root);$r=$runner->run($opts);
expect_batch($r['schema']==='badpool.payment_batch.run.v1','schema',$fail);
expect_batch($r['status']==='pass'&&$r['batch_state']==='READY_FOR_WALLET_APPROVAL','phase 6 state',$fail);
expect_batch($r['stop_before_wallet_send']===true&&$r['wallet_boundary']==='blocked_human_required','wallet boundary',$fail);
expect_batch($r['created_payout_ids']===array(77)&&is_file($r['ledger_path']),'ledger/payout persistence',$fail);
expect_batch(count($fake->calls)===7&&!in_array(7,$fake->calls,true),'wallet method reached',$fail);
expect_batch(strpos(json_encode($r),'Phase adapter is not configured')===false,'adapter configured warning',$fail);
expect_batch($fake->calls===array(0,1,2,3,4,5,6),'ordered phases',$fail);
expect_batch(count($r['phase_results'])===7&&$r['phase_results'][6]['phase_number']===6&&$r['phase_results'][6]['status']==='pass','ready only after phase 6',$fail);
$before=$fake->calls;$opts['resume_batch_id']=$r['batch_id'];$again=$runner->run($opts);expect_batch($fake->calls===$before&&$again['batch_state']==='READY_FOR_WALLET_APPROVAL','resume repeated phase',$fail);
$fake2=new FakeBatchAdapter();$fake2->delay=true;$hold=(new BadpoolPaymentBatchRunner($fake2,$root.'-hold'))->run(array('mode'=>'catchup','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>1));expect_batch($hold['batch_state']==='WAITING_PAYMENT_DELAY'&&!in_array(5,$fake2->calls,true),'delay did not hold',$fail);
$fake3=new FakeBatchAdapter();$fake3->holdPhase=3;$root3=$root.'-phase-hold';$runner3=new BadpoolPaymentBatchRunner($fake3,$root3);$hold3=$runner3->run(array('mode'=>'auto','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>2));expect_batch($fake3->calls===array(0,1,2,3)&&is_file($hold3['ledger_path'])&&$hold3['status']==='hold','hold preserves ledger and stops later phases',$fail);
$fake3->holdPhase=null;$resumed=$runner3->run(array('mode'=>'normal','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>999,'resume_batch_id'=>$hold3['batch_id']));expect_batch($fake3->calls===array(0,1,2,3,3,4,5,6)&&$resumed['batch_state']==='READY_FOR_WALLET_APPROVAL'&&$resumed['mode']==='auto'&&$resumed['batch_size']===2,'resume skips passed phases and preserves options',$fail);
$legacyRoot=$root.'-legacy';$legacy=(new BadpoolPaymentBatchRunner(null,$legacyRoot))->run(array('mode'=>'auto','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>3));$repairedAdapter=new FakeBatchAdapter();$repaired=(new BadpoolPaymentBatchRunner($repairedAdapter,$legacyRoot))->run(array('mode'=>'auto','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>3,'resume_batch_id'=>$legacy['batch_id']));expect_batch($repairedAdapter->calls===array(0,1,2,3,4,5,6)&&$repaired['batch_state']==='READY_FOR_WALLET_APPROVAL','legacy adapter hold resumes after repair',$fail);

class ProductionFixtureGuard {public function selectAll($sql,$params){return array(array('id'=>1267,'symbol'=>'BAD','algo'=>'scrypt'));}}
class ProductionFixtureExecutor {
	public $calls=array();
	public function run($command,$args){$this->calls[]=array($command,$args);$base=array('status'=>'pass','items'=>array());
		if($command==='safety-scan')return $base;
		if($command==='earnings-maturity-transition-dryrun')return array_merge($base,array('items'=>array('selected_earnings'=>array(array('earning_id'=>11,'userid'=>9)),'linked_blocks'=>array(array('block_id'=>4,'linked_earning_ids'=>array(11))))));
		if($command==='earnings-maturity-transition-approval-package')return array_merge($base,array('items'=>array('selected_earnings'=>array(array('earning_id'=>11))),'approval_package_checksum'=>array('value'=>str_repeat('a',64)),'apply_command_shape'=>array('php','badpoolguard','earnings-maturity-transition-apply','--approval-package-checksum=<approval_package_checksum>','--format=json')));
		if($command==='account-credit-clear-dryrun')return array_merge($base,array('items'=>array('selected_earnings'=>array(array('earning_id'=>11,'account_id'=>9)))));
		if($command==='account-credit-clear-approval-package')return array_merge($base,array('items'=>array('selected_earnings'=>array(array('earning_id'=>11,'account_id'=>9))),'approval_package_checksum'=>array('value'=>str_repeat('b',64)),'apply_command_args'=>array('badpoolguard','account-credit-clear-apply','--approval-package-checksum=<approval_package_checksum>','--format=json')));
		if($command==='payout-row-approval-package')return array_merge($base,array('items'=>array('selected_accounts'=>array(array('account_id'=>9))),'approval_package_checksum'=>array('value'=>str_repeat('c',64)),'apply_command_shape'=>array('badpoolguard','payout-row-apply','--approval-package-checksum=<approval_package_checksum>','--format=json')));
		if($command==='payout-row-apply')return array_merge($base,array('created_payout_ids'=>array(77)));return $base;}
}
$productionRoot=$root.'-production';$productionExec=new ProductionFixtureExecutor();$productionAdapter=new BadpoolPaymentBatchPhaseAdapter(new ProductionFixtureGuard(),array($productionExec,'run'));$production=(new BadpoolPaymentBatchRunner($productionAdapter,$productionRoot))->run(array('mode'=>'auto','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>1));$productionCommands=array_map(function($call){return $call[0];},$productionExec->calls);
expect_batch($production['batch_state']==='READY_FOR_WALLET_APPROVAL'&&$production['created_payout_ids']===array(77),'production adapter phases 1-6 complete',$fail);
expect_batch(in_array('earnings-maturity-transition-apply',$productionCommands,true)&&in_array('account-credit-clear-apply',$productionCommands,true)&&in_array('payout-row-apply',$productionCommands,true),'guarded production applies invoked',$fail);
expect_batch(!in_array('wallet-send-apply',$productionCommands,true)&&count($production['phase_results'])===7,'production adapter wallet boundary',$fail);
$maturityPackageCall=array_values(array_filter($productionExec->calls,function($call){return $call[0]==='earnings-maturity-transition-approval-package';}));expect_batch(strpos(implode(' ',$maturityPackageCall[0][1]),'--selected-block-ids=4')!==false,'maturity package bound to selected blocks',$fail);

function command_batch($args,&$rc){$c=new FakeBatchGuardCommand();FakeBatchGuardCommand::$adapter=new FakeBatchAdapter();ob_start();$rc=$c->run(array_merge(array('batch-run'),$args));return ob_get_clean();}
$j=json_decode(command_batch(array('--format=json'),$rc),true);expect_batch(is_array($j)&&$j['scope']==='all-active-payout-coins'&&$j['batch_size']===250,'default/no coin id',$fail);expect_batch($j['stop_before_wallet_send']===true,'default stop',$fail);
$bad=json_decode(command_batch(array('--only=randomx','--format=json'),$rc),true);expect_batch($rc===2&&$bad['status']==='refused','invalid only',$fail);$bad=json_decode(command_batch(array('--batch-size=0','--format=json'),$rc),true);expect_batch($rc===2&&$bad['status']==='refused','invalid size',$fail);
$text=command_batch(array('--format=text'),$rc);foreach(array('CLASSIFICATION=','BATCH_STATE=','NEXT_ACTION=','run_directory=','ledger_path=','7      Send Wallet Payment          BLOCKED_HUMAN_REQUIRED','9      Batch Complete') as $needle)expect_batch(strpos($text,$needle)!==false,'text '.$needle,$fail);
$preview=new FakeBatchGuardCommand();ob_start();$previewRc=$preview->run(array('batch-run-preview','--format=json'));$previewJson=json_decode(ob_get_clean(),true);expect_batch($previewRc===0&&$previewJson['status']==='ok'&&$previewJson['read_only']===true&&$previewJson['db_mutations']===false,'preview remains read only',$fail);expect_batch($previewJson['wallet_sends']===false&&$previewJson['inferred_coin_scope']['coin_ids_required']===false,'preview wallet/default scope',$fail);
function rrmdir_batch($d){if(!is_dir($d))return;foreach(scandir($d) as $x)if($x!=='.'&&$x!=='..'){is_dir($d.'/'.$x)?rrmdir_batch($d.'/'.$x):unlink($d.'/'.$x);}rmdir($d);}foreach(array($root,$root.'-hold',$root3,$legacyRoot,$productionRoot) as $d)rrmdir_batch($d);
if($fail){echo "Badpool payment batch run harness FAILED\n - ".implode("\n - ",$fail)."\n";exit(1);}echo "Badpool payment batch run harness passed\n";
