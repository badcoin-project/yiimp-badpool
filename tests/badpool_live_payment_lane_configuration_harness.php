<?php
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolLivePaymentCoordinator.php');
$fail=0; function lane_ok($v,$m){global $fail;if(!$v){echo "FAIL: $m\n";$fail++;}}
function lane_throws($callable){try{$callable();return false;}catch(InvalidArgumentException $e){return true;}}

$registry=new BadpoolLivePaymentLaneRegistry();$scrypt=$registry->get('live-scrypt-v1');
lane_ok($scrypt->get('schema')==='badpool.live_payment_lane_configuration.v1'&&$scrypt->get('version')===1,'configuration schema/version changed');
lane_ok($scrypt->laneId()==='live-scrypt-v1'&&$scrypt->coinId()===1267&&$scrypt->dbAlgo()==='scrypt'&&$scrypt->operationalAlgo()==='scrypt','Scrypt identity changed');
lane_ok($scrypt->blockBoundary()===29242&&$scrypt->batchLimit()===25,'Scrypt boundary or batch ceiling changed');
lane_ok(basename($scrypt->statePath())==='live-scrypt-coordinator.json'&&basename($scrypt->lockPath())==='live-scrypt-coordinator.lock','Scrypt state/lock binding changed');
lane_ok($scrypt->get('wallet_binding_identity')==='scrypt'&&$scrypt->get('wallet_source_account')==='pool-scrypt','Scrypt wallet binding changed');

$disabled=array('uncommissioned-yescrypt','uncommissioned-skein','uncommissioned-groestl','uncommissioned-sha256d');
foreach($disabled as $id){$lane=$registry->get($id);lane_ok(!$lane->isCommissioned()&&$lane->blockBoundary()===null&&$lane->batchLimit()===null&&!$lane->get('accounting_enabled')&&!$lane->get('payout_preparation_enabled')&&!$lane->get('wallet_send_enabled'),$id.' was accidentally commissioned');}
$groestl=$registry->get('uncommissioned-groestl');lane_ok($groestl->operationalAlgo()==='groestl'&&$groestl->dbAlgo()==='badcoin-groestl','Groestl operational/DB mapping collapsed');
$sha=$registry->get('uncommissioned-sha256d');lane_ok($sha->operationalAlgo()==='sha256d'&&$sha->dbAlgo()==='sha256','SHA256d operational/DB mapping collapsed');

$valid=$scrypt->toArray();
foreach(array(
	'lane_id'=>array('lane_id'=>''),
	'coin_id'=>array('coin_id'=>0),
	'db_algo'=>array('db_algo'=>''),
	'boundary'=>array('block_id_gt'=>0),
	'batch_limit'=>array('batch_max_earnings'=>0),
	'state_path'=>array('state_filename'=>'../state.json'),
	'lock_path'=>array('lock_filename'=>'../state.lock'),
	'wallet_binding'=>array('wallet_binding_identity'=>'sha256d'),
	'source_account'=>array('wallet_source_account'=>'pool-other'),
	'ownership_schema'=>array('ownership_schema'=>'badpool.live_payment_coordinator.v2'),
) as $case=>$changes)lane_ok(lane_throws(function()use($valid,$changes){new BadpoolLivePaymentLaneConfiguration(array_merge($valid,$changes));}),$case.' invalid configuration was accepted');

$duplicate=new BadpoolLivePaymentLaneConfiguration(array_merge($valid,array('lane_id'=>'duplicate-scrypt','coin_id'=>9999)));
lane_ok(lane_throws(function()use($scrypt,$duplicate){new BadpoolLivePaymentLaneRegistry(array($scrypt,$duplicate));}),'state/lock/wallet collision was accepted');
class DisabledLaneRunner {public $calls=0;public function run($o){$this->calls++;return array();}}
$runner=new DisabledLaneRunner();$root=sys_get_temp_dir().'/badpool-disabled-lane-'.bin2hex(random_bytes(4));$report=(new BadpoolLivePaymentCoordinator($runner,$root,$groestl))->run();
lane_ok($report['status']==='fail'&&$report['classification']==='FAIL_CLOSED'&&$runner->calls===0&&!is_dir($root),'disabled lane executed or created runtime state');

echo $fail?"$fail lane configuration checks failed\n":"Badpool live payment lane configuration harness passed\n";exit($fail?1:0);
