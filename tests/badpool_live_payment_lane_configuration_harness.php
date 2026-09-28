<?php
function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolLivePaymentCoordinator.php');
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolPaymentBatchPhaseAdapter.php');
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolGuardContext.php');
$fail=0; function lane_ok($v,$m){global $fail;if(!$v){echo "FAIL: $m\n";$fail++;}}
function lane_throws($callable){try{$callable();return false;}catch(InvalidArgumentException $e){return true;}}

$registry=new BadpoolLivePaymentLaneRegistry();$scrypt=$registry->get('live-scrypt-v1');
lane_ok($scrypt->get('schema')==='badpool.live_payment_lane_configuration.v1'&&$scrypt->get('version')===1,'configuration schema/version changed');
lane_ok($scrypt->laneId()==='live-scrypt-v1'&&$scrypt->coinId()===1267&&$scrypt->dbAlgo()==='scrypt'&&$scrypt->operationalAlgo()==='scrypt','Scrypt identity changed');
lane_ok($scrypt->blockBoundary()===29242&&$scrypt->batchLimit()===25&&$scrypt->maturityBlockLimit()===10,'Scrypt activation values changed');
lane_ok($scrypt->isAccountingCommissioned()&&$scrypt->isMaturityCommissioned()&&$scrypt->isPayoutPreparationCommissioned()&&$scrypt->isWalletSendCommissioned()&&$scrypt->isCommissioned(),'Scrypt is no longer fully commissioned');
lane_ok(basename($scrypt->statePath())==='live-scrypt-coordinator.json'&&basename($scrypt->lockPath())==='live-scrypt-coordinator.lock','Scrypt state/lock binding changed');
lane_ok($scrypt->get('wallet_binding_identity')==='scrypt'&&$scrypt->get('wallet_source_account')==='pool-scrypt','Scrypt wallet binding changed');

$valid=$scrypt->toArray();
$off=array_merge($valid,array('block_id_gt'=>null,'maturity_max_blocks'=>null,'batch_max_earnings'=>null,'accounting_enabled'=>false,'maturity_enabled'=>false,'payout_preparation_enabled'=>false,'wallet_send_enabled'=>false,'human_approved_wallet_send_enabled'=>false));
$accounting=array_merge($off,array('block_id_gt'=>1,'accounting_enabled'=>true));
$maturity=array_merge($accounting,array('maturity_max_blocks'=>1,'maturity_enabled'=>true));
$payout=array_merge($maturity,array('batch_max_earnings'=>1,'payout_preparation_enabled'=>true));
$wallet=array_merge($payout,array('wallet_send_enabled'=>true));
foreach(array('accounting'=>$accounting,'maturity'=>$maturity,'payout'=>$payout,'wallet'=>$wallet) as $stage=>$values){try{$lane=new BadpoolLivePaymentLaneConfiguration($values);lane_ok(true,$stage.' stage rejected');}catch(InvalidArgumentException $e){lane_ok(false,$stage.' stage rejected: '.$e->getMessage());continue;}lane_ok($lane->isAccountingCommissioned(),$stage.' lost accounting');lane_ok($lane->isMaturityCommissioned()===in_array($stage,array('maturity','payout','wallet'),true),$stage.' maturity predicate wrong');lane_ok($lane->isPayoutPreparationCommissioned()===in_array($stage,array('payout','wallet'),true),$stage.' payout predicate wrong');lane_ok($lane->isWalletSendCommissioned()===($stage==='wallet'),$stage.' wallet predicate wrong');lane_ok($lane->isCommissioned()===$lane->isPayoutPreparationCommissioned(),$stage.' compatibility alias changed meaning');}

foreach(array(
	'maturity_without_accounting'=>array_merge($off,array('maturity_enabled'=>true,'maturity_max_blocks'=>1)),
	'payout_without_accounting'=>array_merge($off,array('payout_preparation_enabled'=>true,'batch_max_earnings'=>1)),
	'payout_without_maturity'=>array_merge($accounting,array('payout_preparation_enabled'=>true,'batch_max_earnings'=>1)),
	'wallet_without_payout'=>array_merge($maturity,array('wallet_send_enabled'=>true)),
	'accounting_boundary_missing'=>array_merge($accounting,array('block_id_gt'=>null)),
	'accounting_boundary_disabled'=>array_merge($off,array('block_id_gt'=>1)),
	'maturity_limit_missing'=>array_merge($maturity,array('maturity_max_blocks'=>null)),
	'maturity_limit_disabled'=>array_merge($accounting,array('maturity_max_blocks'=>1)),
	'payout_limit_missing'=>array_merge($payout,array('batch_max_earnings'=>null)),
	'payout_limit_disabled'=>array_merge($maturity,array('batch_max_earnings'=>1)),
) as $case=>$values)lane_ok(lane_throws(function()use($values){new BadpoolLivePaymentLaneConfiguration($values);}),$case.' invalid configuration was accepted');
try{new BadpoolLivePaymentLaneConfiguration($off);lane_ok(true,'fully disabled lane rejected');}catch(InvalidArgumentException $e){lane_ok(false,'fully disabled lane rejected');}

foreach(array(
	'lane_id'=>array('lane_id'=>''),'coin_id'=>array('coin_id'=>0),'db_algo'=>array('db_algo'=>''),
	'boundary'=>array('block_id_gt'=>0),'batch_limit'=>array('batch_max_earnings'=>0),'maturity_limit'=>array('maturity_max_blocks'=>0),
	'maturity_enabled'=>array('maturity_enabled'=>'yes'),'state_path'=>array('state_filename'=>'../state.json'),'lock_path'=>array('lock_filename'=>'../state.lock'),
	'wallet_binding'=>array('wallet_binding_identity'=>'sha256d'),'source_account'=>array('wallet_source_account'=>'pool-other'),'ownership_schema'=>array('ownership_schema'=>'badpool.live_payment_coordinator.v2'),
) as $case=>$changes)lane_ok(lane_throws(function()use($valid,$changes){new BadpoolLivePaymentLaneConfiguration(array_merge($valid,$changes));}),$case.' invalid configuration was accepted');

$yescrypt=$registry->get('live-yescrypt-v1');
lane_ok($yescrypt->coinId()===1266&&$yescrypt->operationalAlgo()==='yescrypt'&&$yescrypt->dbAlgo()==='yescrypt'&&$yescrypt->blockBoundary()===31284,'Yescrypt identity or boundary is incorrect');
lane_ok($yescrypt->isAccountingCommissioned()&&$yescrypt->isMaturityCommissioned()&&$yescrypt->isPayoutPreparationCommissioned()&&!$yescrypt->isWalletSendCommissioned()&&$yescrypt->isCommissioned(),'Yescrypt stage predicates do not stop exactly before wallet send');
lane_ok($yescrypt->maturityBlockLimit()===10&&$yescrypt->batchLimit()===25,'Yescrypt maturity or payout-preparation limit is incorrect');
lane_ok(basename($yescrypt->statePath())==='live-yescrypt-coordinator.json'&&basename($yescrypt->lockPath())==='live-yescrypt-coordinator.lock','Yescrypt state/lock binding is unsafe or unexpected');
lane_ok($yescrypt->get('wallet_binding_identity')==='yescrypt'&&$yescrypt->get('wallet_source_account')==='pool-yescrypt','Yescrypt wallet identity changed');
lane_ok($yescrypt->get('rpc_config_identity')==='/etc/badcoin/pool-yescrypt.conf'&&$yescrypt->get('wallet_datadir_identity')==='/var/lib/badcoin-pool-yescrypt'&&$yescrypt->get('service_timer_identity')===null,'Yescrypt guarded RPC identity or service separation changed');
lane_ok($yescrypt->isHumanApprovedWalletSendEligible()&&!$yescrypt->isWalletSendCommissioned(),'Yescrypt human-approved eligibility was not kept separate from recurring wallet commissioning');
$skein=$registry->get('live-skein-v1');
lane_ok($skein->coinId()===1268&&$skein->operationalAlgo()==='skein'&&$skein->dbAlgo()==='skein'&&$skein->blockBoundary()===31812,'Skein identity or boundary is incorrect');
lane_ok($skein->isAccountingCommissioned()&&$skein->isMaturityCommissioned()&&$skein->isPayoutPreparationCommissioned()&&!$skein->isWalletSendCommissioned()&&$skein->isCommissioned(),'Skein stage predicates do not stop exactly before wallet send');
lane_ok($skein->maturityBlockLimit()===10&&$skein->batchLimit()===25,'Skein maturity or payout-preparation limit is incorrect');
lane_ok(basename($skein->statePath())==='live-skein-coordinator.json'&&basename($skein->lockPath())==='live-skein-coordinator.lock','Skein state/lock binding is unsafe or unexpected');
lane_ok($skein->get('wallet_binding_identity')==='skein'&&$skein->get('wallet_source_account')==='pool-skein','Skein wallet identity changed');
lane_ok($skein->get('rpc_config_identity')==='/etc/badcoin/pool-skein.conf'&&$skein->get('wallet_datadir_identity')==='/var/lib/badcoin-pool-skein'&&$skein->get('service_timer_identity')===null,'Skein guarded RPC identity or service separation changed');
lane_ok($skein->isHumanApprovedWalletSendEligible()&&!$skein->isWalletSendCommissioned(),'Skein human approval eligibility must not enable recurring wallet send');
$sha=$registry->get('live-sha256d-v1');
lane_ok($sha->coinId()===1270&&$sha->operationalAlgo()==='sha256d'&&$sha->dbAlgo()==='sha256'&&$sha->blockBoundary()===32014,'SHA256d identity or boundary is incorrect');
lane_ok($sha->isAccountingCommissioned()&&!$sha->isMaturityCommissioned()&&!$sha->isPayoutPreparationCommissioned()&&!$sha->isWalletSendCommissioned()&&!$sha->isCommissioned(),'SHA256d stages do not stop exactly after accounting');
lane_ok($sha->maturityBlockLimit()===null&&$sha->batchLimit()===null,'SHA256d gained a maturity or payout-preparation limit');
lane_ok(basename($sha->statePath())==='live-sha256d-coordinator.json'&&basename($sha->lockPath())==='live-sha256d-coordinator.lock','SHA256d state/lock binding is unsafe or unexpected');
lane_ok($sha->get('wallet_binding_identity')==='sha256d'&&$sha->get('wallet_source_account')==='pool-sha256d','SHA256d wallet identity changed');
lane_ok($sha->get('rpc_config_identity')===null&&$sha->get('wallet_datadir_identity')===null&&$sha->get('service_timer_identity')===null,'SHA256d gained an RPC, wallet data, or service identity');
lane_ok(!$sha->isHumanApprovedWalletSendEligible(),'SHA256d accounting-only lane became human-send eligible');
$groestl=$registry->get('live-groestl-v1');
lane_ok($groestl->coinId()===1269&&$groestl->operationalAlgo()==='groestl'&&$groestl->dbAlgo()==='badcoin-groestl'&&$groestl->blockBoundary()===31212,'Groestl identity or boundary changed');
lane_ok($groestl->isAccountingCommissioned()&&$groestl->isMaturityCommissioned()&&$groestl->isPayoutPreparationCommissioned()&&!$groestl->isWalletSendCommissioned()&&$groestl->isCommissioned(),'Groestl stage predicates do not stop exactly before wallet send');
lane_ok($groestl->maturityBlockLimit()===10&&$groestl->batchLimit()===25,'Groestl maturity or payout-preparation limit changed');
lane_ok($groestl->isHumanApprovedWalletSendEligible()&&!$groestl->isWalletSendCommissioned(),'Groestl human-approved eligibility was not kept separate from recurring wallet commissioning');
lane_ok(basename($groestl->statePath())==='live-groestl-coordinator.json'&&basename($groestl->lockPath())==='live-groestl-coordinator.lock','Groestl state/lock binding is unsafe or unexpected');
lane_ok($groestl->get('rpc_config_identity')==='/etc/badcoin/pool-groestl.conf'&&$groestl->get('wallet_datadir_identity')==='/var/lib/badcoin-pool-groestl'&&$groestl->get('service_timer_identity')===null,'Groestl guarded RPC identity or service separation changed');
lane_ok($sha->operationalAlgo()==='sha256d'&&$sha->dbAlgo()==='sha256','SHA256d operational/DB mapping collapsed');

$owner=$scrypt->ownershipEnvelope();lane_ok($registry->fromOwnershipEnvelope($owner)===$scrypt,'exact Scrypt ownership envelope was not resolved');
foreach(array('schema'=>'wrong','lane'=>'live-groestl-v1','coin_id'=>1269,'algo'=>'badcoin-groestl','block_id_gt'=>31212) as $key=>$value){$changed=$owner;$changed[$key]=$value;lane_ok($registry->fromOwnershipEnvelope($changed)===null,'ownership '.$key.' mismatch was accepted');}
lane_ok($registry->fromOwnershipEnvelope($groestl->ownershipEnvelope())===$groestl,'exact Groestl payment ownership envelope was not resolved');
lane_ok($registry->fromOwnershipEnvelope($yescrypt->ownershipEnvelope())===$yescrypt,'exact Yescrypt payment ownership envelope was not resolved');
lane_ok($registry->fromOwnershipEnvelope($skein->ownershipEnvelope())===$skein,'exact Skein payment ownership envelope was not resolved');
lane_ok($registry->fromOwnershipEnvelope($sha->ownershipEnvelope())===null,'Accounting-only SHA256d ownership was accepted for payment coordination');

foreach(array('lane'=>array('lane_id'=>$scrypt->laneId()),'coin'=>array('coin_id'=>$scrypt->coinId()),'state'=>array('state_filename'=>$scrypt->get('state_filename')),'lock'=>array('lock_filename'=>$scrypt->get('lock_filename')),'wallet'=>array('operational_algo'=>'scrypt','wallet_binding_identity'=>'scrypt','wallet_source_account'=>$scrypt->get('wallet_source_account'))) as $kind=>$changes){$duplicate=new BadpoolLivePaymentLaneConfiguration(array_merge($valid,array('lane_id'=>'duplicate-'.$kind,'coin_id'=>9990+strlen($kind),'state_filename'=>'duplicate-'.$kind.'.json','lock_filename'=>'duplicate-'.$kind.'.lock','wallet_binding_identity'=>'duplicate-'.$kind,'wallet_source_account'=>'pool-duplicate-'.$kind,'operational_algo'=>'duplicate-'.$kind),$changes));lane_ok(lane_throws(function()use($scrypt,$duplicate){new BadpoolLivePaymentLaneRegistry(array($scrypt,$duplicate));}),$kind.' collision was accepted');}

class CommissionedLaneGuard {public $calls=0,$params;public function selectAll($sql,$params){$this->calls++;$this->params=$params;return array();}}
$root=sys_get_temp_dir().'/badpool-groestl-lane-'.bin2hex(random_bytes(4));mkdir($root);$guard=new CommissionedLaneGuard();$executions=0;$adapter=new BadpoolPaymentBatchPhaseAdapter($guard,function()use(&$executions){$executions++;return array();});
$selection=$adapter->selectEligibleWork(array('mode'=>'auto','run_directory'=>$root),array('mode'=>'auto','batch_size'=>25,'lane_configuration'=>$groestl));
lane_ok($selection['status']==='pass'&&$guard->calls===1&&$guard->params===array(':coin'=>1269,':algo'=>'badcoin-groestl',':boundary'=>31212),'Groestl payment selection did not enter its exact commissioned scope');
lane_ok($scrypt->statePath()!==$groestl->statePath()&&$scrypt->lockPath()!==$groestl->lockPath(),'Scrypt and Groestl coordinator paths collide');
lane_ok($yescrypt->statePath()!==$scrypt->statePath()&&$yescrypt->statePath()!==$groestl->statePath()&&$yescrypt->lockPath()!==$scrypt->lockPath()&&$yescrypt->lockPath()!==$groestl->lockPath(),'Yescrypt coordinator paths collide with another lane');
lane_ok($skein->statePath()!==$scrypt->statePath()&&$skein->statePath()!==$groestl->statePath()&&$skein->statePath()!==$yescrypt->statePath()&&$skein->lockPath()!==$scrypt->lockPath()&&$skein->lockPath()!==$groestl->lockPath()&&$skein->lockPath()!==$yescrypt->lockPath(),'Skein coordinator paths collide with another lane');
lane_ok(!in_array($sha->statePath(),array($scrypt->statePath(),$yescrypt->statePath(),$skein->statePath(),$groestl->statePath()),true)&&!in_array($sha->lockPath(),array($scrypt->lockPath(),$yescrypt->lockPath(),$skein->lockPath(),$groestl->lockPath()),true),'SHA256d coordinator paths collide with another lane');
$skeinRoot=sys_get_temp_dir().'/badpool-skein-lane-'.bin2hex(random_bytes(4));mkdir($skeinRoot);$skeinGuard=new CommissionedLaneGuard();$skeinExecutions=0;$skeinAdapter=new BadpoolPaymentBatchPhaseAdapter($skeinGuard,function()use(&$skeinExecutions){$skeinExecutions++;return array();});
$skeinSelection=$skeinAdapter->selectEligibleWork(array('mode'=>'auto','run_directory'=>$skeinRoot),array('mode'=>'auto','batch_size'=>25,'lane_configuration'=>$skein));
lane_ok($skeinSelection['status']==='pass'&&$skeinGuard->calls===1&&$skeinGuard->params===array(':coin'=>1268,':algo'=>'skein',':boundary'=>31812)&&$skeinExecutions===0,'Skein payment selection did not enter its exact commissioned scope');
$yescryptRoot=sys_get_temp_dir().'/badpool-yescrypt-lane-'.bin2hex(random_bytes(4));mkdir($yescryptRoot);$yescryptGuard=new CommissionedLaneGuard();$yescryptExecutions=0;$yescryptAdapter=new BadpoolPaymentBatchPhaseAdapter($yescryptGuard,function()use(&$yescryptExecutions){$yescryptExecutions++;return array();});
$yescryptSelection=$yescryptAdapter->selectEligibleWork(array('mode'=>'auto','run_directory'=>$yescryptRoot),array('mode'=>'auto','batch_size'=>1,'lane_configuration'=>$yescrypt));
lane_ok($yescryptSelection['status']==='pass'&&$yescryptGuard->calls===1&&$yescryptGuard->params===array(':coin'=>1266,':algo'=>'yescrypt',':boundary'=>31284)&&$yescryptExecutions===0,'Yescrypt payment selection did not enter its exact commissioned scope');
$command=file_get_contents(dirname(__FILE__).'/../web/yaamp/commands/BadpoolGuardCommand.php');$context=file_get_contents(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolGuardContext.php');
lane_ok(strpos($command,"getOption('lane-id','live-scrypt-v1')")!==false&&strpos($context,"'lane-id'")!==false,'lane-selectable coordinator entry point is missing or changed its Scrypt default');
lane_ok(strpos($command,'live-scrypt-v1|live-yescrypt-v1|live-skein-v1|live-groestl-v1')!==false,'coordinator help does not advertise every commissioned lane');
lane_ok(!$groestl->isWalletSendCommissioned()&&!$yescrypt->isWalletSendCommissioned()&&!$skein->isWalletSendCommissioned()&&strpos($command,'walletSendCommissionedLane')!==false&&strpos($command,'wallet_send_not_commissioned')!==false,'A payout-preparation-only wallet-send path is not explicitly fail-closed');
$groestlContext=BadpoolGuardContext::fromArgs('live-payment-coordinator',array('--lane-id=live-groestl-v1','--format=json'));$wrongContext=BadpoolGuardContext::fromArgs('overview',array('--all-coins-preview','--lane-id=live-groestl-v1','--format=json'));
lane_ok($groestlContext->isValid()&&$groestlContext->getOption('lane-id')==='live-groestl-v1'&&!$wrongContext->isValid(),'coordinator lane option was rejected or leaked into unrelated commands');
$yescryptContext=BadpoolGuardContext::fromArgs('live-payment-coordinator',array('--lane-id=live-yescrypt-v1','--format=json'));
lane_ok($yescryptContext->isValid()&&$yescryptContext->getOption('lane-id')==='live-yescrypt-v1','Yescrypt coordinator lane option was rejected');
$skeinContext=BadpoolGuardContext::fromArgs('live-payment-coordinator',array('--lane-id=live-skein-v1','--format=json'));
lane_ok($skeinContext->isValid()&&$skeinContext->getOption('lane-id')==='live-skein-v1','Skein coordinator lane option was rejected');
foreach(scandir($root) as $file)if($file!=='.'&&$file!=='..')unlink($root.'/'.$file);rmdir($root);
foreach(scandir($yescryptRoot) as $file)if($file!=='.'&&$file!=='..')unlink($yescryptRoot.'/'.$file);rmdir($yescryptRoot);
foreach(scandir($skeinRoot) as $file)if($file!=='.'&&$file!=='..')unlink($skeinRoot.'/'.$file);rmdir($skeinRoot);

echo $fail?"$fail lane configuration checks failed\n":"Badpool live payment lane configuration harness passed\n";exit($fail?1:0);
