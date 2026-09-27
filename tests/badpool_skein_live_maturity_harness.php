<?php
function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}
require_once dirname(__DIR__).'/web/yaamp/core/backend/BadpoolLiveBlockMaturity.php';
class CConsoleCommand {}
require_once dirname(__DIR__).'/web/yaamp/commands/LiveBlockMaturityCommand.php';

$fail=array();$checks=0;
function skein_maturity_ok($value,$message){global $fail,$checks;$checks++;if(!$value)$fail[]=$message;}

class SkeinMaturityDaemon implements BadpoolLiveBlockMaturityDaemon
{
	public $results=array(),$calls=array();
	public function inspect($candidate){$id=$candidate['block_id'];$this->calls[]=$id;return arraySafeVal($this->results,$id,array('state'=>'immature','confirmations'=>1));}
}

class SkeinMaturityStore implements BadpoolLiveBlockMaturityStore
{
	public $rows=array(),$selected=array(),$states=array(),$accountCredits=0,$payoutMutations=0,$walletCalls=0,$earningCreates=0;
	public function candidates($coin,$algo,$after,$limit)
	{
		$out=array();
		foreach($this->rows as $row){
			if($row['block_id']<=$after||$row['candidate_coin_id']!==$coin||$row['candidate_algo']!==$algo||$row['block_category']!=='immature')continue;
			$status0=true;foreach($row['earnings'] as $earning)if(intval($earning['status'])!==0)$status0=false;
			if(!$status0)continue;
			$out[]=$row;
		}
		usort($out,function($a,$b){return $a['block_id']-$b['block_id'];});$this->selected=array_slice($out,0,$limit);return $this->selected;
	}
	public function apply($candidate,$result)
	{
		$id=$candidate['block_id'];$earnings=$candidate['earnings'];
		if($result['state']==='generate')foreach($earnings as &$earning){$earning['status']=1;$earning['mature_time']='matured';}
		elseif($result['state']==='orphan')foreach($earnings as &$earning){$earning['status']=-1;$earning['mature_time']=null;}
		$this->states[$id]=array('category'=>$result['state'],'confirmations'=>$result['confirmations'],'earnings'=>$earnings);return array('status'=>'applied');
	}
}

function skein_maturity_earning($id,$block,$coin=1268,$status=0){return array('id'=>$id,'userid'=>75,'coinid'=>$coin,'blockid'=>$block,'amount'=>'50.00000000','price'=>'0.01000000','status'=>$status,'mature_time'=>$status===0?null:'already-mature');}
function skein_maturity_row($id,$changes=array())
{
	$lane=(new BadpoolLivePaymentLaneRegistry())->get('live-skein-v1');$earnings=array(skein_maturity_earning(100000+$id,$id));
	$row=array('block_id'=>$id,'candidate_coin_id'=>1268,'candidate_algo'=>'skein','candidate_blockhash'=>'hash'.$id,'block_coin_id'=>1268,'block_algo'=>'skein','block_blockhash'=>'hash'.$id,'block_txhash'=>'tx'.$id,'block_category'=>'immature','lane_ownership'=>$lane->ownershipEnvelope(),'earnings'=>$earnings,'earning_count'=>1,'status0_count'=>1,'earning_inventory_checksum'=>BadpoolLiveBlockMaturity::earningInventoryChecksum($earnings));
	return array_merge($row,$changes);
}
function run_skein_maturity($rows,$results,$limit=10)
{
	$lane=(new BadpoolLivePaymentLaneRegistry())->get('live-skein-v1');$store=new SkeinMaturityStore();$store->rows=$rows;$daemon=new SkeinMaturityDaemon();$daemon->results=$results;
	$result=(new BadpoolLiveBlockMaturity($store,$daemon,$lane))->run(1268,'skein',31812,$limit);return array($result,$store,$daemon);
}

$registry=new BadpoolLivePaymentLaneRegistry();$lane=$registry->get('live-skein-v1');
skein_maturity_ok($lane->laneId()==='live-skein-v1','Skein maturity lane ID changed');
skein_maturity_ok($lane->coinId()===1268&&$lane->dbAlgo()==='skein'&&$lane->operationalAlgo()==='skein','Skein maturity coin or algorithm identity changed');
skein_maturity_ok($lane->blockBoundary()===31812,'Skein permanent maturity boundary changed');
skein_maturity_ok($lane->isAccountingCommissioned()&&$lane->isMaturityCommissioned()&&!$lane->isPayoutPreparationCommissioned()&&!$lane->isWalletSendCommissioned()&&!$lane->isCommissioned(),'Skein staged commissioning predicates are incorrect');
skein_maturity_ok($lane->maturityBlockLimit()===10&&$lane->batchLimit()===null,'Skein maturity or payout-preparation limit is incorrect');
skein_maturity_ok(basename($lane->statePath())==='live-skein-coordinator.json'&&basename($lane->lockPath())==='live-skein-coordinator.lock','Skein state or lock identity changed');

list($result,$store,$daemon)=run_skein_maturity(array(skein_maturity_row(31812),skein_maturity_row(31813),skein_maturity_row(31814)),array(31813=>array('state'=>'generate','confirmations'=>520),31814=>array('state'=>'immature','confirmations'=>12)));
skein_maturity_ok(array_map(function($row){return $row['block_id'];},$store->selected)===array(31813,31814)&&$daemon->calls===array(31813,31814),'Skein maturity crossed the exclusive boundary or missed the first forward blocks');
skein_maturity_ok($result['matured']===1&&$store->states[31813]['category']==='generate'&&$store->states[31813]['earnings'][0]['status']===1&&$store->states[31813]['earnings'][0]['mature_time']==='matured','Generated Skein earning did not become status 1 with mature_time');
skein_maturity_ok($result['refreshed_immature']===1&&$store->states[31814]['category']==='immature'&&$store->states[31814]['earnings'][0]['status']===0&&$store->states[31814]['earnings'][0]['mature_time']===null,'Immature Skein earning changed maturity state');
skein_maturity_ok(!isset($store->states[31812]),'Boundary block 31812 entered Skein maturity');

list($result,$store)=run_skein_maturity(array(skein_maturity_row(31815)),array(31815=>array('state'=>'orphan','confirmations'=>-1)));
skein_maturity_ok($result['orphaned']===1&&$store->states[31815]['category']==='orphan'&&$store->states[31815]['earnings'][0]['status']===-1&&$store->states[31815]['earnings'][0]['mature_time']===null,'Skein orphan path did not retain a non-payable earning');

$status1=skein_maturity_row(31816);$status1['earnings'][0]=skein_maturity_earning(131816,31816,1268,1);$status1['status0_count']=0;$status1['earning_inventory_checksum']=BadpoolLiveBlockMaturity::earningInventoryChecksum($status1['earnings']);
list($statusResult,$statusStore,$statusDaemon)=run_skein_maturity(array($status1),array());
skein_maturity_ok($statusResult['selected']===0&&$statusStore->states===array()&&$statusDaemon->calls===array(),'Status 1 Skein earning was selected as immature work');

$wrongRows=array(
	skein_maturity_row(31820,array('candidate_coin_id'=>1267)),
	skein_maturity_row(31821,array('candidate_algo'=>'scrypt')),
	skein_maturity_row(31822,array('block_coin_id'=>1266,'block_algo'=>'yescrypt')),
	skein_maturity_row(31823,array('block_coin_id'=>1267,'block_algo'=>'scrypt')),
	skein_maturity_row(31824,array('block_coin_id'=>1269,'block_algo'=>'badcoin-groestl')),
	skein_maturity_row(31825,array('block_coin_id'=>1270,'block_algo'=>'sha256')),
	skein_maturity_row(31826,array('block_algo'=>'scrypt')),
	skein_maturity_row(31827,array('block_blockhash'=>'different')),
	skein_maturity_row(31828,array('lane_ownership'=>$registry->get('live-yescrypt-v1')->ownershipEnvelope())),
);
list($wrongResult,$wrongStore,$wrongDaemon)=run_skein_maturity($wrongRows,array());
skein_maturity_ok($wrongStore->states===array()&&$wrongDaemon->calls===array()&&$wrongResult['apply_failed']===7,'Wrong coin, algorithm, hash lineage, or lane ownership reached Skein maturity apply');

foreach(array(array(1267,'skein',31812,2),array(1268,'scrypt',31812,2),array(1268,'skein',31811,2),array(1268,'skein',31812,11)) as $args){$probe=new SkeinMaturityStore();try{(new BadpoolLiveBlockMaturity($probe,new SkeinMaturityDaemon(),$lane))->run($args[0],$args[1],$args[2],$args[3]);skein_maturity_ok(false,'Mismatched Skein maturity request was accepted');}catch(InvalidArgumentException $e){skein_maturity_ok($probe->selected===array(),'Mismatched Skein request reached selection');}}
$validated=LiveBlockMaturityCommand::configurationForRequest(1268,'skein',31812,2,'live-skein-v1');skein_maturity_ok($validated->toArray()===$lane->toArray(),'Explicit Skein maturity command contract was rejected');
foreach(array(null,'','live-scrypt-v1','live-yescrypt-v1','live-groestl-v1') as $wrongLane){try{LiveBlockMaturityCommand::configurationForRequest(1268,'skein',31812,2,$wrongLane);skein_maturity_ok(false,'Skein maturity accepted an omitted or wrong explicit lane');}catch(InvalidArgumentException $e){skein_maturity_ok(true,'Skein maturity rejected an omitted or wrong explicit lane');}}

list($bounded,$boundedStore,$boundedDaemon)=run_skein_maturity(array(skein_maturity_row(31900),skein_maturity_row(31901),skein_maturity_row(31902)),array(),2);
skein_maturity_ok($bounded['selected']===2&&count($boundedStore->states)===2&&$boundedDaemon->calls===array(31900,31901),'Skein maturity invocation was not independently bounded');
skein_maturity_ok($boundedStore->accountCredits===0&&$boundedStore->payoutMutations===0&&$boundedStore->walletCalls===0&&$boundedStore->earningCreates===0,'Skein maturity performed a forbidden downstream action or created an earning');
skein_maturity_ok($registry->fromOwnershipEnvelope($lane->ownershipEnvelope())===null,'Skein maturity lane gained payout-preparation ownership');

$scrypt=$registry->get('live-scrypt-v1');$yescrypt=$registry->get('live-yescrypt-v1');$groestl=$registry->get('live-groestl-v1');$sha=$registry->get('uncommissioned-sha256d');
skein_maturity_ok($scrypt->coinId()===1267&&$scrypt->dbAlgo()==='scrypt'&&$scrypt->blockBoundary()===29242&&$scrypt->maturityBlockLimit()===10&&$scrypt->batchLimit()===25&&$scrypt->isWalletSendCommissioned(),'Scrypt configuration changed');
skein_maturity_ok($yescrypt->coinId()===1266&&$yescrypt->dbAlgo()==='yescrypt'&&$yescrypt->blockBoundary()===31284&&$yescrypt->maturityBlockLimit()===10&&$yescrypt->batchLimit()===25&&$yescrypt->isPayoutPreparationCommissioned()&&!$yescrypt->isWalletSendCommissioned(),'Yescrypt configuration changed');
skein_maturity_ok($groestl->coinId()===1269&&$groestl->dbAlgo()==='badcoin-groestl'&&$groestl->blockBoundary()===31212&&$groestl->maturityBlockLimit()===10&&$groestl->batchLimit()===25&&$groestl->isPayoutPreparationCommissioned()&&!$groestl->isWalletSendCommissioned(),'Groestl configuration changed');
skein_maturity_ok($sha->coinId()===1270&&$sha->dbAlgo()==='sha256'&&!$sha->isMaturityCommissioned(),'SHA256d lane entered Skein maturity scope');
skein_maturity_ok($lane->statePath()!==$scrypt->statePath()&&$lane->statePath()!==$yescrypt->statePath()&&$lane->statePath()!==$groestl->statePath()&&$lane->lockPath()!==$scrypt->lockPath()&&$lane->lockPath()!==$yescrypt->lockPath()&&$lane->lockPath()!==$groestl->lockPath(),'Skein state or lock identity collides with another lane');

$root=sys_get_temp_dir().'/badpool-skein-maturity-'.bin2hex(random_bytes(5));mkdir($root);
$protected=array(
	'payout-526.json'=>array('payout_id'=>526,'batch'=>'20260920T120829Z-414141f5f7d0','completed'=>0,'tx'=>null),
	'payout-527.json'=>array('payout_id'=>527,'batch'=>'20260927T003516Z-5d76dbf9c318','completed'=>0,'tx'=>null),
	'yescrypt-20260927T135815Z-f30c33bc6543.json'=>array('batch_id'=>'20260927T135815Z-f30c33bc6543','lane'=>'live-yescrypt-v1','state'=>'WAITING_PAYMENT_DELAY'),
);
$before=array();foreach($protected as $file=>$data){file_put_contents($root.'/'.$file,json_encode($data));$before[$file]=hash_file('sha256',$root.'/'.$file);}
run_skein_maturity(array(skein_maturity_row(31910)),array(31910=>array('state'=>'generate','confirmations'=>520)),1);
skein_maturity_ok(hash_file('sha256',$root.'/payout-526.json')===$before['payout-526.json'],'Parked payout 526 fixture was mutated');
skein_maturity_ok(hash_file('sha256',$root.'/payout-527.json')===$before['payout-527.json'],'Parked payout 527 fixture was mutated');
skein_maturity_ok(hash_file('sha256',$root.'/yescrypt-20260927T135815Z-f30c33bc6543.json')===$before['yescrypt-20260927T135815Z-f30c33bc6543.json'],'Yescrypt owned batch fixture was mutated or adopted');
foreach(array_keys($protected) as $file)unlink($root.'/'.$file);rmdir($root);

$source=file_get_contents(dirname(__DIR__).'/web/yaamp/core/backend/BadpoolLiveBlockMaturity.php');
skein_maturity_ok(strpos($source,'C.coin_id=:coin AND C.algo=:algo AND C.block_id>:after')!==false&&strpos($source,"B.category='immature'")!==false,'Maturity selection lost exact coin, algorithm, boundary, or immature predicates');
skein_maturity_ok(strpos($source,'EXISTS (SELECT 1 FROM earnings E WHERE E.blockid=B.id AND E.coinid=C.coin_id AND E.status=0)')!==false&&strpos($source,'NOT EXISTS (SELECT 1 FROM earnings E WHERE E.blockid=B.id AND (E.coinid<>C.coin_id OR E.status<>0))')!==false,'Maturity selection does not exclude status-1 or mixed earning inventory');
skein_maturity_ok(strpos($source,'C.block_id=B.id AND C.coin_id=B.coin_id AND C.blockhash=B.blockhash AND C.algo=:algo')!==false,'Locked maturity apply lost exact candidate/block lineage');
skein_maturity_ok(strpos($source,'WHERE id=:earning AND blockid=:block AND coinid=:coin AND status=0')!==false&&strpos($source,'SET status=1,mature_time=UNIX_TIMESTAMP()')!==false,'Skein generate transition lost status-0 or mature_time bounds');
skein_maturity_ok(strpos($source,"SET category='orphan',confirmations=:conf")!==false&&strpos($source,'SET status=-1,mature_time=NULL')!==false,'Skein orphan handling is not retained');
foreach(array('INSERT INTO earnings','UPDATE accounts','INSERT INTO payouts','sendmany','sendtoaddress') as $needle)skein_maturity_ok(stripos($source,$needle)===false,'Forbidden maturity side effect present: '.$needle);

if($fail){echo "FAIL Skein live maturity harness ($checks checks)\n - ".implode("\n - ",$fail)."\n";exit(1);}
echo "PASS Skein live maturity harness ($checks checks)\n";
