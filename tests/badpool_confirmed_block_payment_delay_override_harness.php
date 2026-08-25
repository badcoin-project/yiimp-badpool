<?php

$root=dirname(__DIR__);
require_once($root.'/web/yaamp/core/backend/BadpoolGuardReport.php');
if(!function_exists('arraySafeVal')){function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}}
require_once($root.'/web/yaamp/core/backend/BadpoolConfirmedBlockPaymentDelayOverride.php');
require_once($root.'/web/yaamp/core/backend/BadpoolPaymentBatchPhaseAdapter.php');

class DelayOverrideGuardFixture { public $rows; public function __construct($rows){$this->rows=$rows;} public function selectAll($sql,$params=array()){return $this->rows;} }
function delay_expect($ok,$message,&$fail){if(!$ok)$fail[]=$message;}
function delay_package($path,$generated,$scope){$p=array_merge(array('schema'=>BadpoolConfirmedBlockPaymentDelayOverride::SCHEMA,'generated_at'=>gmdate('c',$generated)),$scope);$p['scope_checksum']=BadpoolGuardReport::checksum($p)['value'];file_put_contents($path,json_encode($p,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");return $p;}
function delay_options($path){return array('payment_delay_override_package'=>$path,'payment_delay_override_package_checksum'=>hash_file('sha256',$path),'operator_confirms_payment_delay_override'=>BadpoolConfirmedBlockPaymentDelayOverride::CONFIRMATION);}

$fail=array();$now=1777060800;$path=sys_get_temp_dir().'/badpool-delay-override-'.getmypid().'.json';
$scope=array('selected_coin_ids'=>array(1267),'selected_block_ids'=>array(44),'selected_account_ids'=>array(9),'selected_earning_ids'=>array(11,12));
$ledger=array('selected_coin_scope'=>array(array('id'=>1267)),'selected_block_ids'=>array(44),'selected_account_ids'=>array(9),'selected_earning_ids'=>array(11,12));
$rows=array(array('earning_id'=>11,'account_id'=>9,'coinid'=>1267,'blockid'=>44,'status'=>1,'category'=>'generate','confirmations'=>101,'mature_blocks'=>100),array('earning_id'=>12,'account_id'=>9,'coinid'=>1267,'blockid'=>44,'status'=>1,'category'=>'generate','confirmations'=>101,'mature_blocks'=>100));
delay_package($path,$now,$scope);$options=delay_options($path);$guard=new DelayOverrideGuardFixture($rows);

delay_expect(BadpoolConfirmedBlockPaymentDelayOverride::validateOptions(array())['status']==='absent','default behavior must not opt in',$fail);
delay_expect(BadpoolConfirmedBlockPaymentDelayOverride::validate($options,$ledger,$guard,$now)['status']==='pass','confirmed exact override should pass',$fail);
$bad=$options;$bad['payment_delay_override_package_checksum']=str_repeat('0',64);delay_expect(BadpoolConfirmedBlockPaymentDelayOverride::validate($bad,$ledger,$guard,$now)['reason']==='file_checksum_mismatch','file checksum mismatch not refused',$fail);
$bad=$options;$bad['operator_confirms_payment_delay_override']='yes';delay_expect(BadpoolConfirmedBlockPaymentDelayOverride::validate($bad,$ledger,$guard,$now)['reason']==='operator_confirmation_required','inexact confirmation not refused',$fail);
$bad=$options;unset($bad['payment_delay_override_package_checksum']);delay_expect(BadpoolConfirmedBlockPaymentDelayOverride::validate($bad,$ledger,$guard,$now)['reason']==='missing_option','missing option not refused',$fail);
delay_package($path,$now-901,$scope);$stale=delay_options($path);delay_expect(BadpoolConfirmedBlockPaymentDelayOverride::validate($stale,$ledger,$guard,$now)['reason']==='stale_package','stale package not refused',$fail);
delay_package($path,$now,$scope);$options=delay_options($path);$wrong=$ledger;$wrong['selected_account_ids']=array(10);delay_expect(BadpoolConfirmedBlockPaymentDelayOverride::validate($options,$wrong,$guard,$now)['reason']==='ledger_scope_mismatch','scope mismatch not refused',$fail);
$unconfirmed=$rows;$unconfirmed[0]['confirmations']=99;delay_expect(BadpoolConfirmedBlockPaymentDelayOverride::validate($options,$ledger,new DelayOverrideGuardFixture($unconfirmed),$now)['reason']==='unconfirmed_block','unconfirmed block not refused',$fail);
delay_expect(BadpoolConfirmedBlockPaymentDelayOverride::validate($options,$ledger,new DelayOverrideGuardFixture(array($rows[0])),$now)['reason']==='missing_evidence','missing evidence not refused',$fail);
$malformed=$options;$malformed['payment_delay_override_package_checksum']='ABC';delay_expect(BadpoolConfirmedBlockPaymentDelayOverride::validateOptions($malformed)['reason']==='malformed_checksum','malformed checksum not refused',$fail);
$duplicateScope=$scope;$duplicateScope['selected_earning_ids']=array(11,11);delay_package($path,$now,$duplicateScope);$duplicate=delay_options($path);delay_expect(BadpoolConfirmedBlockPaymentDelayOverride::validate($duplicate,$ledger,$guard,$now)['reason']==='malformed_scope','duplicate package IDs not refused',$fail);

$context=file_get_contents($root.'/web/yaamp/core/backend/BadpoolGuardContext.php');delay_expect(strpos($context,'Duplicate option refused: --')!==false,'CLI duplicate option refusal missing',$fail);
$runDir=sys_get_temp_dir().'/badpool-delay-phase-'.getmypid();@mkdir($runDir);
$phaseLedger=array_merge($ledger,array('run_directory'=>$runDir));
function delay_executor($earningIds){return function($command,$args)use($earningIds){$items=array();foreach($earningIds as $id)$items[]=array('earning_id'=>$id);return array('status'=>'pass','items'=>array('selected_earnings'=>$items));};}
$exactAdapter=new BadpoolPaymentBatchPhaseAdapter($guard,delay_executor(array(11,12)));$exact=$exactAdapter->paymentDelayCheck($phaseLedger,array());
delay_expect($exact['status']==='pass'&&$exact['payment_delay_override_used']===false,'default exact delayed eligibility did not pass without override',$fail);
$supersetAdapter=new BadpoolPaymentBatchPhaseAdapter($guard,delay_executor(array(10,11,12,13)));$superset=$supersetAdapter->paymentDelayCheck($phaseLedger,array());
delay_expect($superset['status']==='pass'&&$superset['payment_delay_override_used']===false,'default delayed eligibility superset did not pass without reading an override package',$fail);
$missingAdapter=new BadpoolPaymentBatchPhaseAdapter($guard,delay_executor(array(11)));$default=$missingAdapter->paymentDelayCheck($phaseLedger,array());
delay_expect($default['status']==='hold','default 12-hour delay did not hold when a selected earning was missing',$fail);
delay_package($path,time(),$scope);$liveOptions=delay_options($path);$override=$missingAdapter->paymentDelayCheck($phaseLedger,$liveOptions);
delay_expect($override['status']==='pass'&&$override['payment_delay_override_used']===true,'explicit confirmed-block override did not pass phase 4 when selected delayed eligibility was missing',$fail);
@unlink($runDir.'/payment-delay-report.json');@rmdir($runDir);
@unlink($path);
if($fail){echo "Badpool confirmed-block payment-delay override harness FAILED\n - ".implode("\n - ",$fail)."\n";exit(1);}echo "Badpool confirmed-block payment-delay override harness passed\n";
