<?php
function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolPaymentBatchPhaseAdapter.php');
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolPaymentBatchRunner.php');
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolLivePaymentCoordinator.php');

$fail=array();
function yescrypt_payout_ok($value,$message){global $fail;if(!$value)$fail[]=$message;}
function yescrypt_payout_root($label){$root=sys_get_temp_dir().'/badpool-yescrypt-payout-'.$label.'-'.bin2hex(random_bytes(5));mkdir($root,0770,true);return $root;}
function yescrypt_payout_remove($path){if(!file_exists($path))return;if(is_dir($path)&&!is_link($path)){foreach(scandir($path) as $item)if($item!=='.'&&$item!=='..')yescrypt_payout_remove($path.'/'.$item);rmdir($path);}else unlink($path);}

class YescryptSelectionGuard
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
function yescrypt_payout_row($id,$block=31285,$changes=array())
{
	$row=array('earning_id'=>$id,'block_id'=>$block,'account_id'=>75,'coin_id'=>1266,'status'=>1,'mature_time'=>100,'block_coin_id'=>1266,'category'=>'generate','candidate'=>true,'candidate_coin_id'=>1266,'candidate_algo'=>'yescrypt','candidate_hash'=>'h'.$block,'blockhash'=>'h'.$block,'account_coinid'=>1266);
	return array_merge($row,$changes);
}

$registry=new BadpoolLivePaymentLaneRegistry();$lane=$registry->get('live-yescrypt-v1');$scrypt=$registry->get('live-scrypt-v1');$groestl=$registry->get('live-groestl-v1');
yescrypt_payout_ok($lane->laneId()==='live-yescrypt-v1'&&$lane->coinId()===1266&&$lane->dbAlgo()==='yescrypt'&&$lane->operationalAlgo()==='yescrypt'&&$lane->blockBoundary()===31284,'Yescrypt payout-preparation identity or boundary changed');
yescrypt_payout_ok($lane->isAccountingCommissioned()&&$lane->isMaturityCommissioned()&&$lane->isPayoutPreparationCommissioned()&&!$lane->isWalletSendCommissioned()&&$lane->isCommissioned(),'Yescrypt commissioning predicates are incorrect');
yescrypt_payout_ok($lane->maturityBlockLimit()===10&&$lane->batchLimit()===25,'Yescrypt maturity or payout batch limit is incorrect');
yescrypt_payout_ok(basename($lane->statePath())==='live-yescrypt-coordinator.json'&&basename($lane->lockPath())==='live-yescrypt-coordinator.lock','Yescrypt state or lock identity changed');
yescrypt_payout_ok($lane->statePath()!==$scrypt->statePath()&&$lane->statePath()!==$groestl->statePath()&&$lane->lockPath()!==$scrypt->lockPath()&&$lane->lockPath()!==$groestl->lockPath(),'Yescrypt state or lock identity collides with another commissioned lane');
yescrypt_payout_ok($scrypt->coinId()===1267&&$scrypt->dbAlgo()==='scrypt'&&$scrypt->operationalAlgo()==='scrypt'&&$scrypt->blockBoundary()===29242&&$scrypt->maturityBlockLimit()===10&&$scrypt->batchLimit()===25&&$scrypt->isAccountingCommissioned()&&$scrypt->isMaturityCommissioned()&&$scrypt->isPayoutPreparationCommissioned()&&$scrypt->isWalletSendCommissioned(),'Scrypt configuration changed');
yescrypt_payout_ok($groestl->coinId()===1269&&$groestl->dbAlgo()==='badcoin-groestl'&&$groestl->blockBoundary()===31212&&$groestl->batchLimit()===25&&$groestl->isPayoutPreparationCommissioned()&&!$groestl->isWalletSendCommissioned(),'Groestl configuration changed');

$selectionRoot=yescrypt_payout_root('selection');$guard=new YescryptSelectionGuard();$adapter=new BadpoolPaymentBatchPhaseAdapter($guard,function(){return array('status'=>'fail');});$ledger=array('mode'=>'auto','run_directory'=>$selectionRoot,'selected_coin_scope'=>array(array('id'=>1266)));
$guard->rows=array(
	yescrypt_payout_row(5000,31285),yescrypt_payout_row(5001,31286),yescrypt_payout_row(5100,31284),yescrypt_payout_row(5101,30000),
	yescrypt_payout_row(5102,31300,array('status'=>0)),yescrypt_payout_row(5103,31301,array('coin_id'=>1267)),yescrypt_payout_row(5104,31302,array('candidate_algo'=>'scrypt')),
	yescrypt_payout_row(5105,31303,array('coin_id'=>1268,'block_coin_id'=>1268,'candidate_coin_id'=>1268,'candidate_algo'=>'skein','account_coinid'=>1268)),
	yescrypt_payout_row(5106,31304,array('coin_id'=>1269,'block_coin_id'=>1269,'candidate_coin_id'=>1269,'candidate_algo'=>'badcoin-groestl','account_coinid'=>1269)),
	yescrypt_payout_row(5107,31305,array('coin_id'=>1270,'block_coin_id'=>1270,'candidate_coin_id'=>1270,'candidate_algo'=>'sha256','account_coinid'=>1270)),
	yescrypt_payout_row(5108,31306,array('category'=>'orphan')),yescrypt_payout_row(5109,31307,array('candidate'=>false)),yescrypt_payout_row(5110,31308,array('candidate_hash'=>'wrong')),
);
$selected=$adapter->selectEligibleWork($ledger,array('mode'=>'auto','batch_size'=>25,'lane_configuration'=>$lane));
yescrypt_payout_ok($selected['status']==='pass'&&$selected['selected_earning_ids']===array(5000,5001)&&$selected['selected_block_ids']===array(31285,31286),'Yescrypt selector admitted a boundary, historical, status-0, orphaned, wrong-coin, wrong-algorithm, or unowned earning');
yescrypt_payout_ok($guard->params===array(':coin'=>1266,':algo'=>'yescrypt',':boundary'=>31284),'Yescrypt selection escaped its exact coin/algo/boundary ownership');
foreach(array('E.status=1','E.mature_time IS NOT NULL','B.id>:boundary',"B.category='generate'",'C.block_id=B.id','C.coin_id=B.coin_id','C.algo=:algo','C.blockhash=B.blockhash','A.coinid=:coin','ORDER BY E.id','LIMIT 25') as $term)yescrypt_payout_ok(strpos($guard->sql,$term)!==false,'Yescrypt selection SQL is missing '.$term);
$guard->rows=array();for($i=0;$i<30;$i++)$guard->rows[]=yescrypt_payout_row(6000+$i,31300+$i);$bounded=$adapter->selectEligibleWork($ledger,array('mode'=>'auto','batch_size'=>25,'lane_configuration'=>$lane));
yescrypt_payout_ok($bounded['selected_earning_ids']===range(6000,6024)&&count($bounded['selected_earning_ids'])===25,'Yescrypt selection was not bounded to the first 25 exact earning IDs');

class YescryptDelayAdapter
{
	public $delayCalls=0,$creditCalls=0,$payoutCalls=0;
	public function safetyCheck($ledger,$options){return array('status'=>'pass','selected_coin_scope'=>array(array('id'=>1266,'algo'=>'yescrypt')));}
	public function selectEligibleWork($ledger,$options){$earnings=array(7001,7002);$blocks=array(31285,31286);if(array_intersect($earnings,(array)arraySafeVal($options,'excluded_earning_ids',array()))||array_intersect($blocks,(array)arraySafeVal($options,'excluded_block_ids',array())))$earnings=$blocks=array();return array('status'=>'pass','selected_earning_ids'=>$earnings,'selected_block_ids'=>$blocks,'selected_account_ids'=>$earnings?array(75):array(),'selected_work_by_coin'=>array('1266'=>array('selection_mode'=>'live_status1','earning_ids'=>$earnings,'block_ids'=>$blocks,'account_ids'=>$earnings?array(75):array())));}
	public function packageMaturity($ledger,$options){return array('status'=>'pass');}
	public function applyMaturity($ledger,$options){return array('status'=>'pass');}
	public function paymentDelayCheck($ledger,$options){$this->delayCalls++;return array('status'=>'hold','warnings'=>array('normal_payment_delay_active'));}
	public function creditAccounts($ledger,$options){$this->creditCalls++;return array('status'=>'fail');}
	public function preparePayoutRows($ledger,$options){$this->payoutCalls++;return array('status'=>'fail');}
	public function inspectCreatedPayoutRows($ids){return array();}
}
function yescrypt_payout_ledger($root,$id,$owner,$coin,$algo,$boundary,$state='WAITING_PAYMENT_DELAY',$payouts=array())
{
	$dir=$root.'/'.$id;if(!is_dir($dir))mkdir($dir,0770,true);$ledger=array('batch_id'=>$id,'mode'=>'auto','scope'=>'all-active-payout-coins','only'=>$owner['lane']==='live-groestl-v1'?'groestl':($owner['lane']==='live-scrypt-v1'?'scrypt':'yescrypt'),'batch_size'=>25,'stop_before_wallet_send'=>true,'coordinator_owner'=>$owner,'selected_coin_scope'=>array(array('id'=>$coin,'algo'=>$algo)),'selected_earning_ids'=>array(8001),'selected_block_ids'=>array($boundary+1),'selected_account_ids'=>array(75),'created_payout_ids'=>$payouts,'current_phase'=>$state==='READY_FOR_WALLET_APPROVAL'?6:4,'batch_state'=>$state);file_put_contents($dir.'/ledger.json',json_encode($ledger));return $ledger;
}
function yescrypt_payout_state($root,$lane,$id){file_put_contents($lane->statePath($root),json_encode(array('schema'=>$lane->get('ownership_schema'),'version'=>1,'lane'=>$lane->laneId(),'coin_id'=>$lane->coinId(),'algo'=>$lane->dbAlgo(),'block_id_gt'=>$lane->blockBoundary(),'active_batch_id'=>$id)));}

$coordinatorRoot=yescrypt_payout_root('coordinator');$delayAdapter=new YescryptDelayAdapter();$runner=new BadpoolPaymentBatchRunner($delayAdapter,$coordinatorRoot);$coordinator=new BadpoolLivePaymentCoordinator($runner,$coordinatorRoot,$lane);
$first=$coordinator->run();$batch=$first['waiting_payment_delay_batch_ids'][0];$firstLedger=json_decode(file_get_contents($coordinatorRoot.'/'.$batch.'/ledger.json'),true);$firstIds=$firstLedger['selected_earning_ids'];
yescrypt_payout_ok($first['classification']==='WAITING_PAYMENT_DELAY'&&$first['action_taken']==='created_fresh_batch'&&$firstIds===array(7001,7002),'New Yescrypt batch did not retain exact selected-ID ownership at the payment delay');
yescrypt_payout_ok($delayAdapter->delayCalls===1&&$delayAdapter->creditCalls===0&&$delayAdapter->payoutCalls===0&&$first['created_payout_ids']===array(),'Account credit or payout preparation ran before the normal payment delay passed');
$second=$coordinator->run();$secondLedger=json_decode(file_get_contents($coordinatorRoot.'/'.$batch.'/ledger.json'),true);
yescrypt_payout_ok($second['classification']==='WAITING_PAYMENT_DELAY'&&$second['active_batch_id']===null&&$second['existing_batch_resumed']===true&&$secondLedger['selected_earning_ids']===$firstIds,'Payment-delay resume did not use the same Yescrypt batch and selected earning IDs');
yescrypt_payout_ok($delayAdapter->delayCalls===3&&$delayAdapter->creditCalls===0&&$delayAdapter->payoutCalls===0,'Payment-delay resume or empty fresh-work probe reached account credit or payout creation');
$otherId='20260927T020000Z-121212121212';yescrypt_payout_ledger($coordinatorRoot,$otherId,$lane->ownershipEnvelope(),1266,'yescrypt',31284);$beforeDelayCalls=$delayAdapter->delayCalls;$blocked=$coordinator->run();
yescrypt_payout_ok($blocked['classification']==='FAIL_CLOSED'&&$delayAdapter->delayCalls===$beforeDelayCalls,'Overlapping block ownership across two Yescrypt batches did not fail closed');

$isolationRoot=yescrypt_payout_root('isolation');$scryptId='20260920T120829Z-414141f5f7d0';$groestlId='20260927T003516Z-5d76dbf9c318';yescrypt_payout_ledger($isolationRoot,$scryptId,$scrypt->ownershipEnvelope(),1267,'scrypt',29242,'READY_FOR_WALLET_APPROVAL',array(526));yescrypt_payout_ledger($isolationRoot,$groestlId,$groestl->ownershipEnvelope(),1269,'badcoin-groestl',31212);$scryptHash=hash_file('sha256',$isolationRoot.'/'.$scryptId.'/ledger.json');$groestlHash=hash_file('sha256',$isolationRoot.'/'.$groestlId.'/ledger.json');
$isolationAdapter=new YescryptDelayAdapter();$isolationResult=(new BadpoolLivePaymentCoordinator(new BadpoolPaymentBatchRunner($isolationAdapter,$isolationRoot),$isolationRoot,$lane))->run();
yescrypt_payout_ok($isolationResult['classification']==='WAITING_PAYMENT_DELAY'&&$isolationResult['active_batch_id']!==$scryptId&&$isolationResult['active_batch_id']!==$groestlId,'Yescrypt adopted another lane\'s batch');
yescrypt_payout_ok(hash_file('sha256',$isolationRoot.'/'.$scryptId.'/ledger.json')===$scryptHash&&hash_file('sha256',$isolationRoot.'/'.$groestlId.'/ledger.json')===$groestlHash,'Yescrypt mutated Scrypt payout 526 or the Groestl delay-held batch');

$walletRoot=yescrypt_payout_root('wallet');$walletId='20260927T030000Z-343434343434';yescrypt_payout_ledger($walletRoot,$walletId,$lane->ownershipEnvelope(),1266,'yescrypt',31284,'READY_FOR_WALLET_APPROVAL',array(901));yescrypt_payout_state($walletRoot,$lane,$walletId);$walletAdapter=new YescryptDelayAdapter();$walletResult=(new BadpoolLivePaymentCoordinator(new BadpoolPaymentBatchRunner($walletAdapter,$walletRoot),$walletRoot,$lane))->run();
yescrypt_payout_ok($walletResult['ready_for_wallet_approval_batch_ids']===array($walletId)&&$walletResult['wallet_boundary']==='blocked_human_required'&&$walletResult['wallet_rpc_used']===false&&$walletResult['wallet_send_performed']===false,'Yescrypt coordinator crossed the human wallet-approval boundary');

yescrypt_payout_remove($selectionRoot);yescrypt_payout_remove($coordinatorRoot);yescrypt_payout_remove($isolationRoot);yescrypt_payout_remove($walletRoot);
if($fail){echo "Badpool Yescrypt payout preparation harness FAILED\n - ".implode("\n - ",$fail)."\n";exit(1);}echo "Badpool Yescrypt payout preparation harness passed\n";
