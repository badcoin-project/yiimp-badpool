<?php
function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}
require_once dirname(__DIR__).'/web/yaamp/core/backend/BadpoolLiveBlockAccounting.php';

$fail=array();$checks=0;
function skein_ok($value,$message){global $fail,$checks;$checks++;if(!$value)$fail[]=$message;}

class SkeinAccountingDaemon implements BadpoolLiveBlockDaemon
{
	public $calls=array();
	public function inspect($candidate){$this->calls[]=$candidate['block_id'];return array('category'=>'immature','txhash'=>'tx'.$candidate['block_id'],'amount'=>50,'confirmations'=>1);}
}

class SkeinAccountingStore implements BadpoolLiveBlockStore
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

function skein_candidate($id,$coin,$algo,$hash=null,$attribution=null)
{
	return array('block_id'=>$id,'coin_id'=>$coin,'blockhash'=>$hash===null?'h'.$id:$hash,'algo'=>$algo,'found_time'=>1000+$id,'price'=>0.01,'share_floor_id'=>100,'share_ceiling_id'=>110,'attribution'=>$attribution===null?array(75=>1):$attribution);
}
function add_skein_case($store,$id,$coin,$algo,$candidateHash=null,$blockHash=null,$attribution=null)
{
	$candidate=skein_candidate($id,$coin,$algo,$candidateHash,$attribution);$store->candidates[]=$candidate;
	$store->blocks[$id]=array('id'=>$id,'coin_id'=>$coin,'blockhash'=>$blockHash===null?$candidate['blockhash']:$blockHash,'category'=>'new');
}

$registry=new BadpoolLivePaymentLaneRegistry();$lane=$registry->get('live-skein-v1');
skein_ok($lane->laneId()==='live-skein-v1','Skein lane ID is not exact');
skein_ok($lane->coinId()===1268,'Skein coin ID is not 1268');
skein_ok($lane->dbAlgo()==='skein','Skein database algorithm is not exact');
skein_ok($lane->operationalAlgo()==='skein','Skein operational algorithm is not exact');
skein_ok($lane->blockBoundary()===31812,'Skein permanent boundary changed');
skein_ok($lane->isAccountingCommissioned(),'Skein accounting is not commissioned');
skein_ok($lane->isMaturityCommissioned(),'Skein maturity is not commissioned');
skein_ok($lane->isPayoutPreparationCommissioned(),'Skein payout preparation is not commissioned');
skein_ok(!$lane->isWalletSendCommissioned(),'Skein wallet send was commissioned');
skein_ok($lane->isCommissioned(),'Skein compatibility commissioning predicate is false');
skein_ok($lane->maturityBlockLimit()===10&&$lane->batchLimit()===25,'Skein maturity or payout-preparation limit is incorrect');
skein_ok(basename($lane->statePath())==='live-skein-coordinator.json','Skein state filename changed');
skein_ok(basename($lane->lockPath())==='live-skein-coordinator.lock','Skein lock filename changed');
skein_ok($lane->get('wallet_binding_identity')==='skein'&&$lane->get('wallet_source_account')==='pool-skein','Skein wallet identity metadata changed');
skein_ok($lane->get('rpc_config_identity')===null&&$lane->get('wallet_datadir_identity')===null&&$lane->get('service_timer_identity')===null,'Skein gained an RPC, wallet data, or service identity');

$states=array();$locks=array();foreach($registry->all() as $configured){$states[]=basename($configured->statePath());$locks[]=basename($configured->lockPath());}
skein_ok(count($states)===count(array_unique($states)),'Skein state filename is not unique');
skein_ok(count($locks)===count(array_unique($locks)),'Skein lock filename is not unique');
skein_ok($registry->fromOwnershipEnvelope($lane->ownershipEnvelope())===$lane,'Commissioned Skein payment ownership was not resolved');

$scrypt=$registry->get('live-scrypt-v1');$yescrypt=$registry->get('live-yescrypt-v1');$groestl=$registry->get('live-groestl-v1');$sha=$registry->get('live-sha256d-v1');
skein_ok($scrypt->coinId()===1267&&$scrypt->dbAlgo()==='scrypt'&&$scrypt->blockBoundary()===29242&&$scrypt->isWalletSendCommissioned(),'Existing Scrypt configuration changed');
skein_ok($yescrypt->coinId()===1266&&$yescrypt->dbAlgo()==='yescrypt'&&$yescrypt->blockBoundary()===31284&&$yescrypt->isPayoutPreparationCommissioned()&&!$yescrypt->isWalletSendCommissioned(),'Existing Yescrypt configuration changed');
skein_ok($groestl->coinId()===1269&&$groestl->dbAlgo()==='badcoin-groestl'&&$groestl->blockBoundary()===31212&&$groestl->isPayoutPreparationCommissioned()&&!$groestl->isWalletSendCommissioned(),'Existing Groestl configuration changed');
skein_ok($sha->coinId()===1270&&$sha->dbAlgo()==='sha256'&&$sha->operationalAlgo()==='sha256d'&&$sha->isAccountingCommissioned()&&!$sha->isMaturityCommissioned(),'SHA256d configuration changed');

$root=sys_get_temp_dir().'/badpool-skein-accounting-'.bin2hex(random_bytes(5));mkdir($root);
$protected=array(
	'payout-526.json'=>array('payout_id'=>526,'batch'=>'20260920T120829Z-414141f5f7d0','amount'=>'54111.53081165','completed'=>0,'tx'=>null),
	'payout-527.json'=>array('payout_id'=>527,'batch'=>'20260927T003516Z-5d76dbf9c318','amount'=>'55875.65007076999','account_id'=>76,'completed'=>0,'tx'=>null),
	'yescrypt-20260927T135815Z-f30c33bc6543.json'=>array('batch_id'=>'20260927T135815Z-f30c33bc6543','lane'=>'live-yescrypt-v1','state'=>'WAITING_PAYMENT_DELAY'),
);
$before=array();foreach($protected as $file=>$data){file_put_contents($root.'/'.$file,json_encode($data));$before[$file]=hash_file('sha256',$root.'/'.$file);}

$store=new SkeinAccountingStore();
add_skein_case($store,4265,1268,'skein');
add_skein_case($store,31812,1268,'skein');
add_skein_case($store,31813,1268,'skein');
add_skein_case($store,31814,1268,'skein');
add_skein_case($store,31815,1268,'skein');
add_skein_case($store,31816,1266,'yescrypt');
add_skein_case($store,31817,1267,'scrypt');
add_skein_case($store,31818,1269,'badcoin-groestl');
add_skein_case($store,31819,1270,'sha256');
add_skein_case($store,31820,1268,'scrypt');
add_skein_case($store,31821,1268,'skein','candidate-hash','different-block-hash');
add_skein_case($store,31822,1268,'skein');$store->earnings[31822]=array('status'=>0);

$daemon=new SkeinAccountingDaemon();$result=(new BadpoolLiveBlockAccounting($store,$daemon,$lane))->run(1268,'skein',31812,2);
$selectedIds=array_map(function($row){return $row['block_id'];},$store->selected);
skein_ok($selectedIds===array(31813,31814)&&$result['selected']===2,'Skein bounded selection did not select the first two eligible forward candidates');
skein_ok($store->mutations===array(31813,31814)&&$result['immature']===2,'Skein accounting did not apply exactly the bounded selection');
skein_ok($store->blocks[31812]['category']==='new','Boundary block 31812 was not excluded');
skein_ok($store->blocks[31813]['category']==='immature','First included block 31813 was not eligible');
skein_ok($store->blocks[4265]['category']==='new','Historical Skein block 4265 entered forward accounting');
skein_ok($store->blocks[31816]['category']==='new'&&$store->blocks[31817]['category']==='new'&&$store->blocks[31818]['category']==='new'&&$store->blocks[31819]['category']==='new','Cross-lane candidate inventory entered Skein accounting');
skein_ok($store->blocks[31820]['category']==='new','Wrong database algorithm entered Skein accounting');
skein_ok($store->blocks[31821]['category']==='new','Candidate/block hash mismatch entered Skein accounting');
skein_ok($store->blocks[31822]['category']==='new'&&$store->earnings[31822]['status']===0,'Existing earning was duplicated or changed');

foreach(array(array(1267,'skein',31812),array(1268,'scrypt',31812),array(1268,'skein',31811)) as $wrong){
	$calls=$store->selectionCalls;
	try{(new BadpoolLiveBlockAccounting($store,$daemon,$lane))->run($wrong[0],$wrong[1],$wrong[2],1);skein_ok(false,'Mismatched Skein commissioned scope executed');}
	catch(InvalidArgumentException $e){skein_ok($store->selectionCalls===$calls,'Mismatched Skein scope reached selection');}
}
try{(new BadpoolLiveBlockAccounting($store,$daemon,$scrypt))->run(1268,'skein',31812,1);skein_ok(false,'Another lane accepted Skein inventory');}
catch(InvalidArgumentException $e){skein_ok(true,'Another lane rejected Skein inventory');}

skein_ok(hash_file('sha256',$root.'/payout-526.json')===$before['payout-526.json'],'Parked payout 526 fixture was mutated');
skein_ok(hash_file('sha256',$root.'/payout-527.json')===$before['payout-527.json'],'Parked payout 527 fixture was mutated');
skein_ok(hash_file('sha256',$root.'/yescrypt-20260927T135815Z-f30c33bc6543.json')===$before['yescrypt-20260927T135815Z-f30c33bc6543.json'],'Yescrypt owned delay batch fixture was mutated or adopted');

$source=file_get_contents(dirname(__DIR__).'/web/yaamp/core/backend/BadpoolLiveBlockAccounting.php');
skein_ok(strpos($source,'C.coin_id=:coin AND C.algo=:algo AND C.block_id > :after')!==false,'Production selection lost exact coin, algo, or exclusive boundary predicates');
skein_ok(strpos($source,'C.blockhash=B.blockhash')!==false&&strpos($source,'C.coin_id=B.coin_id')!==false,'Production selection lost candidate/block lineage joins');
skein_ok(strpos($source,'NOT EXISTS (SELECT 1 FROM earnings E WHERE E.blockid=B.id)')!==false,'Production selection lost duplicate-earning prevention');

foreach(array_keys($protected) as $file)unlink($root.'/'.$file);rmdir($root);
if($fail){echo "FAIL Skein live accounting harness ($checks checks)\n - ".implode("\n - ",$fail)."\n";exit(1);}
echo "PASS Skein live accounting harness ($checks checks)\n";
