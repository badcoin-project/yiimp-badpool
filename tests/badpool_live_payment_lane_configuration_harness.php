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
$off=array_merge($valid,array('block_id_gt'=>null,'maturity_max_blocks'=>null,'batch_max_earnings'=>null,'accounting_enabled'=>false,'maturity_enabled'=>false,'payout_preparation_enabled'=>false,'wallet_send_enabled'=>false));
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

$disabled=array('uncommissioned-yescrypt','uncommissioned-skein','uncommissioned-sha256d');
foreach($disabled as $id){$lane=$registry->get($id);lane_ok(!$lane->isAccountingCommissioned()&&!$lane->isMaturityCommissioned()&&!$lane->isPayoutPreparationCommissioned()&&!$lane->isWalletSendCommissioned()&&!$lane->isCommissioned()&&$lane->blockBoundary()===null&&$lane->batchLimit()===null&&$lane->maturityBlockLimit()===null,$id.' was accidentally commissioned');}
$groestl=$registry->get('live-groestl-v1');
lane_ok($groestl->coinId()===1269&&$groestl->operationalAlgo()==='groestl'&&$groestl->dbAlgo()==='badcoin-groestl'&&$groestl->blockBoundary()===31212,'Groestl identity or boundary changed');
lane_ok($groestl->isAccountingCommissioned()&&$groestl->isMaturityCommissioned()&&$groestl->isPayoutPreparationCommissioned()&&!$groestl->isWalletSendCommissioned()&&$groestl->isCommissioned(),'Groestl stage predicates do not stop exactly before wallet send');
lane_ok($groestl->maturityBlockLimit()===10&&$groestl->batchLimit()===25,'Groestl maturity or payout-preparation limit changed');
lane_ok(basename($groestl->statePath())==='live-groestl-coordinator.json'&&basename($groestl->lockPath())==='live-groestl-coordinator.lock','Groestl state/lock binding is unsafe or unexpected');
$sha=$registry->get('uncommissioned-sha256d');lane_ok($sha->operationalAlgo()==='sha256d'&&$sha->dbAlgo()==='sha256','SHA256d operational/DB mapping collapsed');

$owner=$scrypt->ownershipEnvelope();lane_ok($registry->fromOwnershipEnvelope($owner)===$scrypt,'exact Scrypt ownership envelope was not resolved');
foreach(array('schema'=>'wrong','lane'=>'live-groestl-v1','coin_id'=>1269,'algo'=>'badcoin-groestl','block_id_gt'=>31212) as $key=>$value){$changed=$owner;$changed[$key]=$value;lane_ok($registry->fromOwnershipEnvelope($changed)===null,'ownership '.$key.' mismatch was accepted');}
lane_ok($registry->fromOwnershipEnvelope($groestl->ownershipEnvelope())===$groestl,'exact Groestl payment ownership envelope was not resolved');

foreach(array('lane'=>array('lane_id'=>$scrypt->laneId()),'coin'=>array('coin_id'=>$scrypt->coinId()),'state'=>array('state_filename'=>$scrypt->get('state_filename')),'lock'=>array('lock_filename'=>$scrypt->get('lock_filename')),'wallet'=>array('operational_algo'=>'scrypt','wallet_binding_identity'=>'scrypt','wallet_source_account'=>$scrypt->get('wallet_source_account'))) as $kind=>$changes){$duplicate=new BadpoolLivePaymentLaneConfiguration(array_merge($valid,array('lane_id'=>'duplicate-'.$kind,'coin_id'=>9990+strlen($kind),'state_filename'=>'duplicate-'.$kind.'.json','lock_filename'=>'duplicate-'.$kind.'.lock','wallet_binding_identity'=>'duplicate-'.$kind,'wallet_source_account'=>'pool-duplicate-'.$kind,'operational_algo'=>'duplicate-'.$kind),$changes));lane_ok(lane_throws(function()use($scrypt,$duplicate){new BadpoolLivePaymentLaneRegistry(array($scrypt,$duplicate));}),$kind.' collision was accepted');}

class CommissionedLaneGuard {public $calls=0,$params;public function selectAll($sql,$params){$this->calls++;$this->params=$params;return array();}}
$root=sys_get_temp_dir().'/badpool-groestl-lane-'.bin2hex(random_bytes(4));mkdir($root);$guard=new CommissionedLaneGuard();$executions=0;$adapter=new BadpoolPaymentBatchPhaseAdapter($guard,function()use(&$executions){$executions++;return array();});
$selection=$adapter->selectEligibleWork(array('mode'=>'auto','run_directory'=>$root),array('mode'=>'auto','batch_size'=>25,'lane_configuration'=>$groestl));
lane_ok($selection['status']==='pass'&&$guard->calls===1&&$guard->params===array(':coin'=>1269,':algo'=>'badcoin-groestl',':boundary'=>31212),'Groestl payment selection did not enter its exact commissioned scope');
lane_ok($scrypt->statePath()!==$groestl->statePath()&&$scrypt->lockPath()!==$groestl->lockPath(),'Scrypt and Groestl coordinator paths collide');
$command=file_get_contents(dirname(__FILE__).'/../web/yaamp/commands/BadpoolGuardCommand.php');$context=file_get_contents(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolGuardContext.php');
lane_ok(strpos($command,"getOption('lane-id','live-scrypt-v1')")!==false&&strpos($context,"'lane-id'")!==false,'lane-selectable coordinator entry point is missing or changed its Scrypt default');
lane_ok(!$groestl->isWalletSendCommissioned()&&strpos($command,'walletSendCommissionedLane')!==false&&strpos($command,'wallet_send_not_commissioned')!==false,'Groestl wallet-send path is not explicitly fail-closed');
$groestlContext=BadpoolGuardContext::fromArgs('live-payment-coordinator',array('--lane-id=live-groestl-v1','--format=json'));$wrongContext=BadpoolGuardContext::fromArgs('overview',array('--all-coins-preview','--lane-id=live-groestl-v1','--format=json'));
lane_ok($groestlContext->isValid()&&$groestlContext->getOption('lane-id')==='live-groestl-v1'&&!$wrongContext->isValid(),'coordinator lane option was rejected or leaked into unrelated commands');
foreach(scandir($root) as $file)if($file!=='.'&&$file!=='..')unlink($root.'/'.$file);rmdir($root);

echo $fail?"$fail lane configuration checks failed\n":"Badpool live payment lane configuration harness passed\n";exit($fail?1:0);
