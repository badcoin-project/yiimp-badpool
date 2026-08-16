<?php
class CConsoleCommand {}
function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}
require_once(dirname(__DIR__).'/web/yaamp/commands/BadpoolGuardCommand.php');

class FakeBatchAdapter {
	public $calls=array(); public $delay=false;
	private function result($n,$extra=array()){ $this->calls[]=$n; return array_merge(array('status'=>'pass','mutation_scope'=>array()),$extra); }
	public function safetyCheck($l,$o){return $this->result(0);}
	public function selectEligibleWork($l,$o){return $this->result(1,array('selected_coin_scope'=>array('algo'=>'scrypt'),'selected_earning_ids'=>array(11,12),'selected_block_ids'=>array(4)));}
	public function packageMaturity($l,$o){return $this->result(2,array('package_path'=>$l['run_directory'].'/maturity.json','checksums'=>array('maturity'=>'abc')));}
	public function applyMaturity($l,$o){return $this->result(3,array('report_path'=>$l['run_directory'].'/maturity-apply.json'));}
	public function paymentDelayCheck($l,$o){return $this->delay?$this->result(4,array('status'=>'hold','warnings'=>array('resume_after_utc=2099-01-01T00:00:00Z'))):$this->result(4);}
	public function creditAccounts($l,$o){return $this->result(5,array('selected_account_ids'=>array(9),'package_path'=>$l['run_directory'].'/credit.json','report_path'=>$l['run_directory'].'/credit-apply.json','checksums'=>array('credit'=>'def')));}
	public function preparePayoutRows($l,$o){return $this->result(6,array('created_payout_ids'=>array(77),'package_path'=>$l['run_directory'].'/payout.json','report_path'=>$l['run_directory'].'/payout-apply.json','checksums'=>array('payout'=>'ghi')));}
}
function expect_batch($v,$m,&$f){if(!$v)$f[]=$m;}
$fail=array(); $root=sys_get_temp_dir().'/badpool-batch-test-'.getmypid(); $opts=array('mode'=>'auto','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>250);
$fake=new FakeBatchAdapter(); $runner=new BadpoolPaymentBatchRunner($fake,$root); $r=$runner->run($opts);
expect_batch($r['schema']==='badpool.payment_batch.run.v1','schema',$fail);
expect_batch($r['status']==='pass'&&$r['batch_state']==='READY_FOR_WALLET_APPROVAL','phase 6 state',$fail);
expect_batch($r['stop_before_wallet_send']===true&&$r['wallet_boundary']==='blocked_human_required','wallet boundary',$fail);
expect_batch($r['created_payout_ids']===array(77)&&is_file($r['ledger_path']),'ledger/payout persistence',$fail);
expect_batch(count($fake->calls)===7&&!in_array(7,$fake->calls,true),'wallet method reached',$fail);
$before=$fake->calls; $opts['resume_batch_id']=$r['batch_id']; $again=$runner->run($opts);
expect_batch($fake->calls===$before&&$again['batch_state']==='READY_FOR_WALLET_APPROVAL','resume repeated phase',$fail);
$fake2=new FakeBatchAdapter();$fake2->delay=true;$hold=(new BadpoolPaymentBatchRunner($fake2,$root.'-hold'))->run(array('mode'=>'catchup','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>1));
expect_batch($hold['batch_state']==='WAITING_PAYMENT_DELAY'&&!in_array(5,$fake2->calls,true),'delay did not hold',$fail);

function command_batch($args,&$rc){$c=new BadpoolGuardCommand();ob_start();$rc=$c->run(array_merge(array('batch-run'),$args));return ob_get_clean();}
$j=json_decode(command_batch(array('--format=json'),$rc),true);
expect_batch(is_array($j)&&$j['scope']==='all-active-payout-coins'&&$j['batch_size']===250,'default/no coin id',$fail);
expect_batch($j['stop_before_wallet_send']===true,'default stop',$fail);
$bad=json_decode(command_batch(array('--only=randomx','--format=json'),$rc),true);expect_batch($rc===2&&$bad['status']==='refused','invalid only',$fail);
$bad=json_decode(command_batch(array('--batch-size=0','--format=json'),$rc),true);expect_batch($rc===2&&$bad['status']==='refused','invalid size',$fail);
$text=command_batch(array('--format=text'),$rc);foreach(array('CLASSIFICATION=','BATCH_STATE=','NEXT_ACTION=','run_directory=','ledger_path=','7      Send Wallet Payment          BLOCKED_HUMAN_REQUIRED','9      Batch Complete') as $needle)expect_batch(strpos($text,$needle)!==false,'text '.$needle,$fail);

function rrmdir_batch($d){if(!is_dir($d))return;foreach(scandir($d) as $x)if($x!=='.'&&$x!=='..'){is_dir($d.'/'.$x)?rrmdir_batch($d.'/'.$x):unlink($d.'/'.$x);}rmdir($d);}rrmdir_batch($root);rrmdir_batch($root.'-hold');
if($fail){echo "Badpool payment batch run harness FAILED\n - ".implode("\n - ",$fail)."\n";exit(1);}echo "Badpool payment batch run harness passed\n";
