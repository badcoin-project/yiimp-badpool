<?php
function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolPaymentBatchPhaseAdapter.php');
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolPaymentBatchRunner.php');
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolLivePaymentCoordinator.php');
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolGuardContext.php');

$fail=array();$checks=0;
function skein_payout_ok($value,$message){global $fail,$checks;$checks++;if(!$value)$fail[]=$message;}
function skein_payout_root($label){$root=sys_get_temp_dir().'/badpool-skein-payout-'.$label.'-'.bin2hex(random_bytes(5));mkdir($root,0770,true);return $root;}
function skein_payout_remove($path){if(!file_exists($path))return;if(is_dir($path)&&!is_link($path)){foreach(scandir($path) as $item)if($item!=='.'&&$item!=='..')skein_payout_remove($path.'/'.$item);rmdir($path);}else unlink($path);}

class SkeinSelectionGuard
{
	public $rows=array(),$sql,$params;
	public function selectAll($sql,$params)
	{
		$this->sql=$sql;$this->params=$params;$out=array();$coin=intval($params[':coin']);$algo=(string)$params[':algo'];$boundary=intval($params[':boundary']);
		foreach($this->rows as $row){
			if(intval($row['coin_id'])!==$coin||intval($row['status'])!==1||$row['mature_time']===null||intval($row['block_coin_id'])!==$coin||intval($row['block_id'])<=$boundary||$row['category']!=='generate'||empty($row['candidate'])||intval($row['candidate_coin_id'])!==$coin||$row['candidate_algo']!==$algo||$row['candidate_hash']!==$row['blockhash']||intval($row['account_coinid'])!==$coin)continue;
			$out[]=array('earning_id'=>$row['earning_id'],'block_id'=>$row['block_id'],'account_id'=>$row['account_id'],'coin_id'=>$row['coin_id']);
		}
		usort($out,function($a,$b){return $a['earning_id']-$b['earning_id'];});if(preg_match('/ LIMIT ([0-9]+)$/',$sql,$match))$out=array_slice($out,0,intval($match[1]));return $out;
	}
}
function skein_payout_row($id,$block=31813,$changes=array())
{
	$row=array('earning_id'=>$id,'block_id'=>$block,'account_id'=>88,'coin_id'=>1268,'status'=>1,'mature_time'=>100,'block_coin_id'=>1268,'category'=>'generate','candidate'=>true,'candidate_coin_id'=>1268,'candidate_algo'=>'skein','candidate_hash'=>'h'.$block,'blockhash'=>'h'.$block,'account_coinid'=>1268);
	return array_merge($row,$changes);
}

$registry=new BadpoolLivePaymentLaneRegistry();$lane=$registry->get('live-skein-v1');$scrypt=$registry->get('live-scrypt-v1');$yescrypt=$registry->get('live-yescrypt-v1');$groestl=$registry->get('live-groestl-v1');$sha=$registry->get('live-sha256d-v1');
skein_payout_ok($lane->laneId()==='live-skein-v1','Skein payout-preparation lane identity changed');
skein_payout_ok($lane->coinId()===1268,'Skein payout-preparation coin changed');
skein_payout_ok($lane->dbAlgo()==='skein','Skein payout-preparation database algorithm changed');
skein_payout_ok($lane->operationalAlgo()==='skein','Skein payout-preparation operational algorithm changed');
skein_payout_ok($lane->blockBoundary()===31812,'Skein payout-preparation permanent boundary changed');
skein_payout_ok($lane->isAccountingCommissioned(),'Skein accounting predicate is false');
skein_payout_ok($lane->isMaturityCommissioned(),'Skein maturity predicate is false');
skein_payout_ok($lane->isPayoutPreparationCommissioned(),'Skein payout-preparation predicate is false');
skein_payout_ok(!$lane->isWalletSendCommissioned(),'Skein wallet-send predicate is true');
skein_payout_ok($lane->isCommissioned(),'Skein compatibility commissioning predicate is false');
skein_payout_ok($lane->maturityBlockLimit()===10,'Skein maturity block limit changed');
skein_payout_ok($lane->batchLimit()===25,'Skein payout batch limit changed');
skein_payout_ok($lane->get('wallet_binding_identity')==='skein'&&$lane->get('wallet_source_account')==='pool-skein'&&$lane->get('rpc_config_identity')==='/etc/badcoin/pool-skein.conf'&&$lane->get('wallet_datadir_identity')==='/var/lib/badcoin-pool-skein'&&$lane->get('service_timer_identity')===null,'Skein guarded RPC identity or recurring payment service separation changed');
skein_payout_ok(basename($lane->statePath())==='live-skein-coordinator.json'&&basename($lane->lockPath())==='live-skein-coordinator.lock','Skein state or lock identity changed');
foreach(array($scrypt,$yescrypt,$groestl) as $other)skein_payout_ok($lane->statePath()!==$other->statePath()&&$lane->lockPath()!==$other->lockPath(),'Skein state or lock identity collides with '.$other->laneId());
skein_payout_ok($scrypt->coinId()===1267&&$scrypt->dbAlgo()==='scrypt'&&$scrypt->operationalAlgo()==='scrypt'&&$scrypt->blockBoundary()===29242&&$scrypt->maturityBlockLimit()===10&&$scrypt->batchLimit()===25&&$scrypt->isAccountingCommissioned()&&$scrypt->isMaturityCommissioned()&&$scrypt->isPayoutPreparationCommissioned()&&$scrypt->isWalletSendCommissioned(),'Scrypt configuration changed');
skein_payout_ok($yescrypt->coinId()===1266&&$yescrypt->dbAlgo()==='yescrypt'&&$yescrypt->operationalAlgo()==='yescrypt'&&$yescrypt->blockBoundary()===31284&&$yescrypt->maturityBlockLimit()===10&&$yescrypt->batchLimit()===25&&$yescrypt->isPayoutPreparationCommissioned()&&!$yescrypt->isWalletSendCommissioned(),'Yescrypt configuration changed');
skein_payout_ok($groestl->coinId()===1269&&$groestl->dbAlgo()==='badcoin-groestl'&&$groestl->operationalAlgo()==='groestl'&&$groestl->blockBoundary()===31212&&$groestl->maturityBlockLimit()===10&&$groestl->batchLimit()===25&&$groestl->isPayoutPreparationCommissioned()&&!$groestl->isWalletSendCommissioned(),'Groestl configuration changed');
skein_payout_ok($sha->coinId()===1270&&$sha->dbAlgo()==='sha256'&&$sha->operationalAlgo()==='sha256d'&&!$sha->isCommissioned(),'SHA256d configuration changed');

$selectionRoot=skein_payout_root('selection');$guard=new SkeinSelectionGuard();$adapter=new BadpoolPaymentBatchPhaseAdapter($guard,function(){return array('status'=>'fail');});$ledger=array('mode'=>'auto','run_directory'=>$selectionRoot,'selected_coin_scope'=>array(array('id'=>1268)));
$guard->rows=array(
	skein_payout_row(1001,31813),skein_payout_row(1002,31814,array('account_id'=>89)),skein_payout_row(1100,31812),skein_payout_row(1101,4265),
	skein_payout_row(1102,31815,array('status'=>0)),skein_payout_row(1103,31816,array('status'=>2)),skein_payout_row(1104,31817,array('coin_id'=>1267)),
	skein_payout_row(1105,31818,array('candidate_algo'=>'scrypt')),skein_payout_row(1106,31819,array('candidate_algo'=>'yescrypt')),skein_payout_row(1107,31820,array('candidate_algo'=>'badcoin-groestl')),skein_payout_row(1108,31821,array('candidate_algo'=>'sha256')),
	skein_payout_row(1109,31822,array('category'=>'orphan')),skein_payout_row(1110,31823,array('candidate'=>false)),skein_payout_row(1111,31824,array('candidate_hash'=>'wrong')),skein_payout_row(1112,31825,array('account_coinid'=>1267))
);
$selected=$adapter->selectEligibleWork($ledger,array('mode'=>'auto','batch_size'=>25,'lane_configuration'=>$lane));
skein_payout_ok($selected['status']==='pass'&&$selected['selected_earning_ids']===array(1001,1002)&&$selected['selected_block_ids']===array(31813,31814),'Skein selector admitted boundary, recovery, status-0/2, wrong-coin, wrong-algorithm, or unowned work');
skein_payout_ok($selected['selected_account_ids']===array(88,89)&&$selected['selected_work_by_coin']['1268']['earning_ids']===array(1001,1002)&&$selected['selected_work_by_coin']['1268']['account_ids']===array(88,89),'Exact Skein earning IDs or account ownership were not preserved');
skein_payout_ok($guard->params===array(':coin'=>1268,':algo'=>'skein',':boundary'=>31812),'Skein selection escaped its exact coin/algo/boundary ownership');
foreach(array('E.status=1','E.mature_time IS NOT NULL','B.id>:boundary',"B.category='generate'",'C.algo=:algo','A.coinid=:coin','ORDER BY E.id','LIMIT 25') as $term)skein_payout_ok(strpos($guard->sql,$term)!==false,'Skein selection SQL is missing '.$term);
$guard->rows=array();for($i=0;$i<30;$i++)$guard->rows[]=skein_payout_row(2000+$i,31900+$i,array('account_id'=>88+($i%2)));$bounded=$adapter->selectEligibleWork($ledger,array('mode'=>'auto','batch_size'=>25,'lane_configuration'=>$lane));
skein_payout_ok($bounded['selected_earning_ids']===range(2000,2024)&&count($bounded['selected_earning_ids'])===25,'Skein selection was not bounded to the first 25 exact earning IDs');

class SkeinDelayAdapter
{
	public $delayCalls=0,$creditCalls=0,$payoutCalls=0;
	public function safetyCheck($ledger,$options){return array('status'=>'pass','selected_coin_scope'=>array(array('id'=>1268,'algo'=>'skein')));}
	public function selectEligibleWork($ledger,$options){return array('status'=>'pass','selected_earning_ids'=>array(3001,3002),'selected_block_ids'=>array(31813,31814),'selected_account_ids'=>array(88,89),'selected_work_by_coin'=>array('1268'=>array('selection_mode'=>'live_status1','earning_ids'=>array(3001,3002),'block_ids'=>array(31813,31814),'account_ids'=>array(88,89))));}
	public function packageMaturity($ledger,$options){return array('status'=>'pass');}
	public function applyMaturity($ledger,$options){return array('status'=>'pass');}
	public function paymentDelayCheck($ledger,$options){$this->delayCalls++;return array('status'=>'hold','warnings'=>array('normal_payment_delay_active'));}
	public function creditAccounts($ledger,$options){$this->creditCalls++;return array('status'=>'fail');}
	public function preparePayoutRows($ledger,$options){$this->payoutCalls++;return array('status'=>'fail');}
	public function inspectCreatedPayoutRows($ids){return array();}
}
function skein_payout_ledger($root,$id,$lane,$state,$earnings,$accounts,$payouts=array())
{
	$dir=$root.'/'.$id;if(!is_dir($dir))mkdir($dir,0770,true);$ledger=array('batch_id'=>$id,'mode'=>'auto','scope'=>'all-active-payout-coins','only'=>$lane->operationalAlgo(),'batch_size'=>25,'stop_before_wallet_send'=>true,'coordinator_owner'=>$lane->ownershipEnvelope(),'selected_coin_scope'=>array(array('id'=>$lane->coinId(),'algo'=>$lane->dbAlgo())),'selected_earning_ids'=>$earnings,'selected_block_ids'=>array($lane->blockBoundary()+1),'selected_account_ids'=>$accounts,'created_payout_ids'=>$payouts,'payment_delay_qualified_earning_ids'=>array(),'current_phase'=>$state==='READY_FOR_WALLET_APPROVAL'?6:4,'batch_state'=>$state);file_put_contents($dir.'/ledger.json',json_encode($ledger));return $ledger;
}
function skein_payout_state($root,$lane,$id){file_put_contents($lane->statePath($root),json_encode(array('schema'=>$lane->get('ownership_schema'),'version'=>1,'lane'=>$lane->laneId(),'coin_id'=>$lane->coinId(),'algo'=>$lane->dbAlgo(),'block_id_gt'=>$lane->blockBoundary(),'active_batch_id'=>$id)));}

$coordinatorRoot=skein_payout_root('coordinator');$delayAdapter=new SkeinDelayAdapter();$coordinator=new BadpoolLivePaymentCoordinator(new BadpoolPaymentBatchRunner($delayAdapter,$coordinatorRoot),$coordinatorRoot,$lane);
$first=$coordinator->run();$batch=$first['active_batch_id'];$firstLedger=json_decode(file_get_contents($coordinatorRoot.'/'.$batch.'/ledger.json'),true);$firstIds=$firstLedger['selected_earning_ids'];
skein_payout_ok($first['classification']==='WAITING_PAYMENT_DELAY'&&$first['action_taken']==='created_batch'&&$firstIds===array(3001,3002)&&$firstLedger['selected_account_ids']===array(88,89),'New Skein batch did not retain exact earning/account ownership at the payment delay');
skein_payout_ok(is_file($lane->statePath($coordinatorRoot))&&is_file($lane->lockPath($coordinatorRoot))&&!is_file($scrypt->statePath($coordinatorRoot))&&!is_file($yescrypt->statePath($coordinatorRoot))&&!is_file($groestl->statePath($coordinatorRoot)),'Skein coordinator used or created another lane state/lock identity');
skein_payout_ok($delayAdapter->delayCalls===1&&$delayAdapter->creditCalls===0&&$delayAdapter->payoutCalls===0&&$first['created_payout_ids']===array(),'Account credit or payout preparation ran before the normal payment delay passed');
$second=$coordinator->run();$secondLedger=json_decode(file_get_contents($coordinatorRoot.'/'.$batch.'/ledger.json'),true);
skein_payout_ok($second['classification']==='WAITING_PAYMENT_DELAY'&&$second['active_batch_id']===$batch&&$second['action_taken']==='resumed_batch'&&$secondLedger['selected_earning_ids']===$firstIds,'Selected-ID payment-delay resume did not retain the same Skein batch and exact earning IDs');
skein_payout_ok($delayAdapter->delayCalls===2&&$delayAdapter->creditCalls===0&&$delayAdapter->payoutCalls===0,'Payment-delay resume reached account credit or payout creation');

$isolationRoot=skein_payout_root('isolation');$scryptId='20260920T120829Z-414141f5f7d0';$groestlId='20260927T003516Z-5d76dbf9c318';$yescryptId='20260927T135815Z-f30c33bc6543';
skein_payout_ledger($isolationRoot,$scryptId,$scrypt,'READY_FOR_WALLET_APPROVAL',array(4001),array(79),array(526));skein_payout_ledger($isolationRoot,$groestlId,$groestl,'READY_FOR_WALLET_APPROVAL',array(4002),array(76),array(527));skein_payout_ledger($isolationRoot,$yescryptId,$yescrypt,'WAITING_PAYMENT_DELAY',array(4003),array(75));
$protected=array();foreach(array($scryptId,$groestlId,$yescryptId) as $id)$protected[$id]=hash_file('sha256',$isolationRoot.'/'.$id.'/ledger.json');
$isolationAdapter=new SkeinDelayAdapter();$isolationResult=(new BadpoolLivePaymentCoordinator(new BadpoolPaymentBatchRunner($isolationAdapter,$isolationRoot),$isolationRoot,$lane))->run();
skein_payout_ok($isolationResult['classification']==='WAITING_PAYMENT_DELAY'&&!in_array($isolationResult['active_batch_id'],array($scryptId,$groestlId,$yescryptId),true),'Skein adopted a parked payout or the owned Yescrypt batch');
foreach($protected as $id=>$hash)skein_payout_ok(hash_file('sha256',$isolationRoot.'/'.$id.'/ledger.json')===$hash,'Skein mutated protected batch '.$id);

class SkeinWalletBoundaryRunner{public $calls=0;public function run($options){$this->calls++;return array();}}
$walletRoot=skein_payout_root('wallet');$walletId='20260927T150000Z-343434343434';skein_payout_ledger($walletRoot,$walletId,$lane,'READY_FOR_WALLET_APPROVAL',array(5001),array(88),array(900));skein_payout_state($walletRoot,$lane,$walletId);$walletRunner=new SkeinWalletBoundaryRunner();$walletResult=(new BadpoolLivePaymentCoordinator($walletRunner,$walletRoot,$lane))->run();
skein_payout_ok($walletRunner->calls===0&&$walletResult['classification']==='HUMAN_WALLET_APPROVAL_REQUIRED'&&$walletResult['wallet_rpc_used']===false&&$walletResult['wallet_send_performed']===false,'Wallet execution remained reachable with Skein wallet send disabled');
$context=BadpoolGuardContext::fromArgs('live-payment-coordinator',array('--lane-id=live-skein-v1','--format=json'));skein_payout_ok($context->isValid()&&$context->getOption('lane-id')==='live-skein-v1','The explicit Skein coordinator command contract was rejected');

skein_payout_remove($selectionRoot);skein_payout_remove($coordinatorRoot);skein_payout_remove($isolationRoot);skein_payout_remove($walletRoot);
if($fail){echo "Badpool Skein payout preparation harness FAILED ($checks checks)\n - ".implode("\n - ",$fail)."\n";exit(1);}echo "Badpool Skein payout preparation harness passed ($checks checks)\n";
