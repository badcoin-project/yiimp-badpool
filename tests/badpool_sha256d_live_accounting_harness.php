<?php
function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}
require_once dirname(__DIR__).'/web/yaamp/core/backend/BadpoolLiveBlockAccounting.php';

$fail=array();$checks=0;
function sha256d_ok($value,$message){global $fail,$checks;$checks++;if(!$value)$fail[]=$message;}

class Sha256dAccountingDaemon implements BadpoolLiveBlockDaemon
{
	public $calls=array();
	public function inspect($candidate){$this->calls[]=$candidate['block_id'];return array('category'=>'immature','txhash'=>'tx'.$candidate['block_id'],'amount'=>50,'confirmations'=>1);}
}

class Sha256dAccountingStore implements BadpoolLiveBlockStore
{
	public $blocks=array(),$candidates=array(),$earnings=array(),$selected=array(),$mutations=array(),$selectionCalls=0;
	public function candidates($coin,$algo,$after,$limit)
	{
		$this->selectionCalls++;$out=array();
		foreach($this->candidates as $candidate){
			$id=$candidate['block_id'];$block=arraySafeVal($this->blocks,$id);
			if(!is_array($block)||$candidate['coin_id']!==$coin||$candidate['algo']!==$algo||$id<=$after)continue;
			if($block['id']!==$id||$block['coin_id']!==$candidate['coin_id']||$block['blockhash']!==$candidate['blockhash']||$block['category']!=='new'||isset($this->earnings[$id]))continue;
			$out[]=$candidate;
		}
		usort($out,function($a,$b){return $a['block_id']-$b['block_id'];});$out=array_slice($out,0,$limit);$this->selected=$out;return $out;
	}
	public function apply($candidate,$classification)
	{
		$id=$candidate['block_id'];$block=arraySafeVal($this->blocks,$id);
		if(!is_array($block)||$block['coin_id']!==$candidate['coin_id']||$block['blockhash']!==$candidate['blockhash']||$block['category']!=='new'||isset($this->earnings[$id]))return array('status'=>'skipped','reason'=>'no_longer_eligible');
		if(empty($candidate['attribution']))return array('status'=>'failed','reason'=>'transaction_exception');
		$this->blocks[$id]=array_merge($block,$classification);$this->earnings[$id]=array('status'=>0,'attribution'=>$candidate['attribution']);$this->mutations[]=$id;return array('status'=>'applied');
	}
}

function sha256d_candidate($id,$coin,$algo,$hash=null,$attribution=null)
{
	return array('block_id'=>$id,'coin_id'=>$coin,'blockhash'=>$hash===null?'h'.$id:$hash,'algo'=>$algo,'found_time'=>1000+$id,'price'=>0.01,'share_floor_id'=>100,'share_ceiling_id'=>110,'attribution'=>$attribution===null?array(75=>1):$attribution);
}
function add_sha256d_case($store,$id,$coin,$algo,$candidateHash=null,$blockHash=null,$attribution=null)
{
	$candidate=sha256d_candidate($id,$coin,$algo,$candidateHash,$attribution);$store->candidates[]=$candidate;
	$store->blocks[$id]=array('id'=>$id,'coin_id'=>$coin,'blockhash'=>$blockHash===null?$candidate['blockhash']:$blockHash,'category'=>'new');
}

$registry=new BadpoolLivePaymentLaneRegistry();$lane=$registry->get('live-sha256d-v1');
sha256d_ok($lane->laneId()==='live-sha256d-v1','SHA256d lane ID is not exact');
sha256d_ok($lane->coinId()===1270,'SHA256d coin ID is not 1270');
sha256d_ok($lane->dbAlgo()==='sha256','SHA256d database algorithm is not sha256');
sha256d_ok($lane->operationalAlgo()==='sha256d','SHA256d operational algorithm is not sha256d');
sha256d_ok($lane->dbAlgo()!==$lane->operationalAlgo(),'SHA256d database and operational identities were collapsed');
sha256d_ok($lane->blockBoundary()===32014,'SHA256d permanent boundary changed');
sha256d_ok($lane->isAccountingCommissioned(),'SHA256d accounting is not commissioned');
sha256d_ok(!$lane->isMaturityCommissioned(),'SHA256d maturity was commissioned');
sha256d_ok(!$lane->isPayoutPreparationCommissioned(),'SHA256d payout preparation was commissioned');
sha256d_ok(!$lane->isWalletSendCommissioned(),'SHA256d wallet send was commissioned');
sha256d_ok(!$lane->isCommissioned(),'SHA256d compatibility commissioning predicate became true');
sha256d_ok($lane->maturityBlockLimit()===null&&$lane->batchLimit()===null,'SHA256d maturity or payout limit is not null');
sha256d_ok(basename($lane->statePath())==='live-sha256d-coordinator.json','SHA256d state filename changed');
sha256d_ok(basename($lane->lockPath())==='live-sha256d-coordinator.lock','SHA256d lock filename changed');
sha256d_ok($lane->get('wallet_binding_identity')==='sha256d'&&$lane->get('wallet_source_account')==='pool-sha256d','SHA256d wallet identity metadata changed');
sha256d_ok($lane->get('rpc_config_identity')===null&&$lane->get('wallet_datadir_identity')===null&&$lane->get('service_timer_identity')===null,'SHA256d gained an RPC, wallet data, or service identity');
sha256d_ok($registry->fromOwnershipEnvelope($lane->ownershipEnvelope())===null,'Accounting-only SHA256d lane was accepted for payment ownership');

$states=array();$locks=array();foreach($registry->all() as $configured){$states[]=basename($configured->statePath());$locks[]=basename($configured->lockPath());}
sha256d_ok(count($states)===count(array_unique($states)),'SHA256d state filename is not unique');
sha256d_ok(count($locks)===count(array_unique($locks)),'SHA256d lock filename is not unique');

$scrypt=$registry->get('live-scrypt-v1');$yescrypt=$registry->get('live-yescrypt-v1');$skein=$registry->get('live-skein-v1');$groestl=$registry->get('live-groestl-v1');
sha256d_ok($scrypt->coinId()===1267&&$scrypt->dbAlgo()==='scrypt'&&$scrypt->blockBoundary()===29242&&$scrypt->isWalletSendCommissioned(),'Existing Scrypt configuration changed');
sha256d_ok($yescrypt->coinId()===1266&&$yescrypt->dbAlgo()==='yescrypt'&&$yescrypt->blockBoundary()===31284&&$yescrypt->isPayoutPreparationCommissioned()&&!$yescrypt->isWalletSendCommissioned(),'Existing Yescrypt configuration changed');
sha256d_ok($skein->coinId()===1268&&$skein->dbAlgo()==='skein'&&$skein->blockBoundary()===31812&&$skein->isPayoutPreparationCommissioned()&&!$skein->isWalletSendCommissioned(),'Existing Skein configuration changed');
sha256d_ok($groestl->coinId()===1269&&$groestl->dbAlgo()==='badcoin-groestl'&&$groestl->blockBoundary()===31212&&$groestl->isPayoutPreparationCommissioned()&&!$groestl->isWalletSendCommissioned(),'Existing Groestl configuration changed');

$root=sys_get_temp_dir().'/badpool-sha256d-accounting-'.bin2hex(random_bytes(5));mkdir($root);
$protected=array(
	'payout-526.json'=>array('payout_id'=>526,'completed'=>0,'tx'=>null),
	'payout-527.json'=>array('payout_id'=>527,'completed'=>0,'tx'=>null),
	'yescrypt-20260927T135815Z-f30c33bc6543.json'=>array('batch_id'=>'20260927T135815Z-f30c33bc6543','lane'=>'live-yescrypt-v1'),
	'skein-20260928T002836Z-dadc9cef85e9.json'=>array('batch_id'=>'20260928T002836Z-dadc9cef85e9','lane'=>'live-skein-v1'),
);
$before=array();foreach($protected as $file=>$data){file_put_contents($root.'/'.$file,json_encode($data));$before[$file]=hash_file('sha256',$root.'/'.$file);}

$store=new Sha256dAccountingStore();
add_sha256d_case($store,8199,1270,'sha256');
add_sha256d_case($store,32014,1270,'sha256');
add_sha256d_case($store,32015,1270,'sha256');
add_sha256d_case($store,32016,1270,'sha256');
add_sha256d_case($store,32017,1270,'sha256');
add_sha256d_case($store,32018,1267,'scrypt');
add_sha256d_case($store,32019,1266,'yescrypt');
add_sha256d_case($store,32020,1268,'skein');
add_sha256d_case($store,32021,1269,'badcoin-groestl');
add_sha256d_case($store,32022,1270,'sha256d');
add_sha256d_case($store,32023,1270,'sha256','candidate-hash','different-block-hash');
add_sha256d_case($store,32024,1270,'sha256');$store->earnings[32024]=array('status'=>0);

$daemon=new Sha256dAccountingDaemon();$result=(new BadpoolLiveBlockAccounting($store,$daemon,$lane))->run(1270,'sha256',32014,2);
$selectedIds=array_map(function($row){return $row['block_id'];},$store->selected);
sha256d_ok($selectedIds===array(32015,32016)&&$result['selected']===2,'SHA256d bounded selection did not select the first two eligible forward candidates');
sha256d_ok($store->mutations===array(32015,32016)&&$result['immature']===2,'SHA256d accounting did not apply exactly the bounded selection');
sha256d_ok($store->blocks[32014]['category']==='new','Boundary block 32014 was not excluded');
sha256d_ok($store->blocks[32015]['category']==='immature','First included block 32015 was not eligible');
sha256d_ok($store->blocks[8199]['category']==='new','Historical SHA256d block 8199 entered forward accounting');
sha256d_ok($store->blocks[32018]['category']==='new'&&$store->blocks[32019]['category']==='new'&&$store->blocks[32020]['category']==='new'&&$store->blocks[32021]['category']==='new','Cross-lane candidate inventory entered SHA256d accounting');
sha256d_ok($store->blocks[32022]['category']==='new','Operational SHA256d spelling was silently accepted as the database algorithm');
sha256d_ok($store->blocks[32023]['category']==='new','Candidate/block hash mismatch entered SHA256d accounting');
sha256d_ok($store->blocks[32024]['category']==='new'&&$store->earnings[32024]['status']===0,'Existing earning was duplicated or changed');

$emptyStore=new Sha256dAccountingStore();add_sha256d_case($emptyStore,32025,1270,'sha256',null,null,array());
$emptyResult=(new BadpoolLiveBlockAccounting($emptyStore,$daemon,$lane))->run(1270,'sha256',32014,1);
sha256d_ok($emptyResult['apply_failed']===1&&$emptyStore->mutations===array()&&$emptyStore->blocks[32025]['category']==='new','Missing attribution did not fail closed');

foreach(array(array(1269,'sha256',32014),array(1270,'sha256d',32014),array(1270,'sha256',32013)) as $wrong){
	$calls=$store->selectionCalls;
	try{(new BadpoolLiveBlockAccounting($store,$daemon,$lane))->run($wrong[0],$wrong[1],$wrong[2],1);sha256d_ok(false,'Mismatched SHA256d commissioned scope executed');}
	catch(InvalidArgumentException $e){sha256d_ok($store->selectionCalls===$calls,'Mismatched SHA256d scope reached selection');}
}
foreach(array($scrypt,$yescrypt,$skein,$groestl) as $other){
	try{(new BadpoolLiveBlockAccounting($store,$daemon,$other))->run(1270,'sha256',32014,1);sha256d_ok(false,'Another lane accepted SHA256d inventory');}
	catch(InvalidArgumentException $e){sha256d_ok(true,'Another lane rejected SHA256d inventory');}
}

foreach($before as $file=>$hash)sha256d_ok(hash_file('sha256',$root.'/'.$file)===$hash,$file.' was mutated or adopted');

$source=file_get_contents(dirname(__DIR__).'/web/yaamp/core/backend/BadpoolLiveBlockAccounting.php');
$command=file_get_contents(dirname(__DIR__).'/web/yaamp/commands/LiveBlockAccountingCommand.php');
sha256d_ok(strpos($source,'C.coin_id=:coin AND C.algo=:algo AND C.block_id > :after')!==false,'Production selection lost exact coin, algo, or exclusive boundary predicates');
sha256d_ok(strpos($source,'C.blockhash=B.blockhash')!==false&&strpos($source,'C.coin_id=B.coin_id')!==false,'Production selection lost candidate/block lineage joins');
sha256d_ok(strpos($source,'live_block_attributions WHERE block_id=:id')!==false&&strpos($source,'empty discovery attribution')!==false,'Production apply lost required attribution evidence');
sha256d_ok(strpos($source,'NOT EXISTS (SELECT 1 FROM earnings E WHERE E.blockid=B.id)')!==false,'Production selection lost duplicate-earning prevention');
sha256d_ok(strpos($command,'$lane=\'live-scrypt-v1\'')!==false&&strpos($command,'get((string)$lane)')!==false,'Non-Scrypt accounting no longer requires explicit lane selection');

foreach(array_keys($protected) as $file)unlink($root.'/'.$file);rmdir($root);
if($fail){echo "FAIL SHA256d live accounting harness ($checks checks)\n - ".implode("\n - ",$fail)."\n";exit(1);}
echo "PASS SHA256d live accounting harness ($checks checks)\n";
