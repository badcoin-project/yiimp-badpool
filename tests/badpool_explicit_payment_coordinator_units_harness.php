<?php
function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}
require_once dirname(__DIR__).'/web/yaamp/core/backend/BadpoolLivePaymentCoordinator.php';

$failures=array();$checks=0;
function unit_ok($value,$message){global $failures,$checks;$checks++;if(!$value)$failures[]=$message;}

$root=dirname(__DIR__);$unitRoot=$root.'/ops/systemd';$registry=new BadpoolLivePaymentLaneRegistry();
$expected=array(
	'live-scrypt-v1'=>array(1267,'scrypt','scrypt','live-scrypt-coordinator.json','live-scrypt-coordinator.lock','scrypt'),
	'live-groestl-v1'=>array(1269,'groestl','badcoin-groestl','live-groestl-coordinator.json','live-groestl-coordinator.lock','groestl'),
	'live-yescrypt-v1'=>array(1266,'yescrypt','yescrypt','live-yescrypt-coordinator.json','live-yescrypt-coordinator.lock','yescrypt'),
	'live-skein-v1'=>array(1268,'skein','skein','live-skein-coordinator.json','live-skein-coordinator.lock','skein'),
	'live-sha256d-v1'=>array(1270,'sha256d','sha256','live-sha256d-coordinator.json','live-sha256d-coordinator.lock','sha256d'),
);

$services=array();$timers=array();
foreach($expected as $laneId=>$identity){
	$lane=$registry->get($laneId);$algo=$identity[5];
	unit_ok($lane->coinId()===$identity[0]&&$lane->operationalAlgo()===$identity[1]&&$lane->dbAlgo()===$identity[2],$laneId.' coin/algo identity mismatch');
	unit_ok(basename($lane->statePath())===$identity[3]&&basename($lane->lockPath())===$identity[4],$laneId.' coordinator ownership paths mismatch');
	unit_ok($lane->isMaturityPipelinePrepared()&&$lane->isPayoutPreparationPipelinePrepared()&&$lane->isRecurringPaymentCoordinatorPrepared(),$laneId.' prepared pipeline metadata missing');
	$service=$unitRoot.'/badpool-live-payment-'.$algo.'.service';$timer=$unitRoot.'/badpool-live-payment-'.$algo.'.timer';
	$services[]=$service;$timers[]=$timer;
	unit_ok(is_file($service)&&is_file($timer),$laneId.' payment service/timer missing');
	$serviceText=is_file($service)?file_get_contents($service):'';$timerText=is_file($timer)?file_get_contents($timer):'';
	$expectedExec='ExecStart=/usr/bin/php yaamp/yiic.php badpoolguard live-payment-coordinator --lane-id='.$laneId.' --format=json';
	unit_ok(substr_count($serviceText,'ExecStart=')===1&&strpos($serviceText,$expectedExec)!==false,$laneId.' payment service is not bound to its exact lane');
	unit_ok(substr_count($serviceText,'--lane-id=')===1,$laneId.' payment service does not contain exactly one explicit lane ID');
	unit_ok(strpos($serviceText,'User=zako')!==false&&strpos($serviceText,'WorkingDirectory=/srv/badpool/yiimp-badpool/web')!==false&&strpos($serviceText,'StandardOutput=journal')!==false&&strpos($serviceText,'NoNewPrivileges=true')!==false,$laneId.' payment service lost execution/logging/hardening conventions');
	unit_ok(strpos($timerText,'OnCalendar=*:0/5')!==false&&strpos($timerText,'Persistent=true')!==false&&strpos($timerText,'Unit='.basename($service))!==false,$laneId.' payment timer cadence or service identity mismatch');
	unit_ok($lane->get('payment_coordinator_service_identity')===basename($service)&&$lane->get('payment_coordinator_timer_identity')===basename($timer)&&$lane->get('service_timer_identity')===basename($timer),$laneId.' registry payment unit metadata mismatch');
	unit_ok(!preg_match('/\b(?:multi-wallet-send-apply|wallet-send-apply|sendmany|sendtoaddress)\b/i',$serviceText),$laneId.' recurring service contains a wallet-send command');
}
unit_ok(count(array_unique(array_map('basename',$services)))===5&&count(array_unique(array_map('basename',$timers)))===5,'payment unit identities are not independent');
unit_ok(!is_file($unitRoot.'/badpool-live-payment.service')&&!is_file($unitRoot.'/badpool-live-payment.timer'),'ambiguous generic Scrypt driver is source-managed alongside explicit units');

$sha=$registry->get('live-sha256d-v1');
unit_ok($sha->isAccountingCommissioned()&&!$sha->isMaturityCommissioned()&&!$sha->isPayoutPreparationCommissioned()&&!$sha->isWalletSendCommissioned()&&!$sha->isHumanApprovedWalletSendEligible(),'SHA256d downstream production commissioning gate opened');
$shaMaturityService=$unitRoot.'/'.$sha->get('maturity_service_identity');$shaMaturityTimer=$unitRoot.'/'.$sha->get('maturity_timer_identity');
unit_ok(is_file($shaMaturityService)&&is_file($shaMaturityTimer),'SHA256d maturity unit plumbing missing');
$shaMaturityText=is_file($shaMaturityService)?file_get_contents($shaMaturityService):'';
unit_ok(strpos($shaMaturityText,'liveblockmaturity --coin=1270 --algo=sha256 --after=32014 --limit=10 --lane=live-sha256d-v1')!==false,'SHA256d maturity unit scope is not exact');
unit_ok(strpos(file_get_contents($shaMaturityTimer),'Do not enable until the documented SHA256d production gate passes.')!==false&&strpos(file_get_contents($unitRoot.'/badpool-live-payment-sha256d.timer'),'Do not enable until the documented SHA256d production gate passes.')!==false,'SHA256d source units do not carry the disabled-until-gate warning');

$runner=new class {public $calls=0;public function run($options){$this->calls++;throw new RuntimeException('SHA256d payout runner must not execute');}};
$report=(new BadpoolLivePaymentCoordinator($runner,sys_get_temp_dir().'/badpool-sha256d-gated-'.bin2hex(random_bytes(4)),$sha))->run();
unit_ok($report['classification']==='FAIL_CLOSED'&&$report['wallet_boundary']==='blocked_human_required'&&$report['wallet_rpc_used']===false&&$report['wallet_send_performed']===false&&$runner->calls===0,'SHA256d gated coordinator did not fail before payout preparation');

$coordinatorSource=file_get_contents($root.'/web/yaamp/core/backend/BadpoolLivePaymentCoordinator.php');
unit_ok(strpos($coordinatorSource,"'wallet_boundary'=>'blocked_human_required'")!==false&&strpos($coordinatorSource,"'wallet_rpc_used'=>false")!==false&&strpos($coordinatorSource,"'wallet_send_performed'=>false")!==false,'human wallet boundary reporting changed');
$guardBytes=file_get_contents($root.'/web/yaamp/core/rpc/wallet-send-guard.php');
unit_ok(hash('sha256',str_replace("\r\n","\n",$guardBytes))==='15d367873f673d04354695eba1ac6407752bf3789fd08629d3dfc1ba6ce8af80','canonical wallet guard hash changed');

if($failures){echo "FAIL explicit payment coordinator units harness ($checks checks)\n - ".implode("\n - ",$failures)."\n";exit(1);}
echo "PASS explicit payment coordinator units harness ($checks checks)\n";
