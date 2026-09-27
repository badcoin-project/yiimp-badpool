<?php
function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}
require_once dirname(__DIR__).'/web/yaamp/core/backend/BadpoolLiveBlockMaturity.php';
class CConsoleCommand {}
require_once dirname(__DIR__).'/web/yaamp/commands/LiveBlockMaturityCommand.php';

$fail=array();
function yescrypt_maturity_ok($value,$message){global $fail;if(!$value)$fail[]=$message;}

class YescryptMaturityDaemon implements BadpoolLiveBlockMaturityDaemon
{
	public $results=array(),$calls=array();
	public function inspect($candidate){$id=$candidate['block_id'];$this->calls[]=$id;return arraySafeVal($this->results,$id,array('state'=>'immature','confirmations'=>1));}
}

class YescryptMaturityStore implements BadpoolLiveBlockMaturityStore
{
	public $rows=array(),$selected=array(),$states=array(),$accountCredits=0,$payouts=0,$walletSends=0;
	public function candidates($coin,$algo,$after,$limit)
	{
		$out=array();
		foreach($this->rows as $row)if($row['block_id']>$after&&$row['candidate_coin_id']===$coin&&$row['candidate_algo']===$algo&&$row['block_category']==='immature')$out[]=$row;
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

function yescrypt_maturity_earning($id,$block,$coin=1266){return array('id'=>$id,'userid'=>75,'coinid'=>$coin,'blockid'=>$block,'amount'=>'50.00000000','price'=>'0.01000000','status'=>0,'mature_time'=>null);}
function yescrypt_maturity_row($id,$changes=array())
{
	$lane=(new BadpoolLivePaymentLaneRegistry())->get('live-yescrypt-v1');$earnings=array(yescrypt_maturity_earning(100000+$id,$id));
	$row=array('block_id'=>$id,'candidate_coin_id'=>1266,'candidate_algo'=>'yescrypt','candidate_blockhash'=>'hash'.$id,'block_coin_id'=>1266,'block_algo'=>'yescrypt','block_blockhash'=>'hash'.$id,'block_txhash'=>'tx'.$id,'block_category'=>'immature','lane_ownership'=>$lane->ownershipEnvelope(),'earnings'=>$earnings,'earning_count'=>1,'status0_count'=>1,'earning_inventory_checksum'=>BadpoolLiveBlockMaturity::earningInventoryChecksum($earnings));
	return array_merge($row,$changes);
}
function run_yescrypt_maturity($rows,$results,$limit=10)
{
	$lane=(new BadpoolLivePaymentLaneRegistry())->get('live-yescrypt-v1');$store=new YescryptMaturityStore();$store->rows=$rows;$daemon=new YescryptMaturityDaemon();$daemon->results=$results;
	$result=(new BadpoolLiveBlockMaturity($store,$daemon,$lane))->run(1266,'yescrypt',31284,$limit);return array($result,$store,$daemon);
}

$registry=new BadpoolLivePaymentLaneRegistry();$lane=$registry->get('live-yescrypt-v1');
yescrypt_maturity_ok($lane->laneId()==='live-yescrypt-v1'&&$lane->coinId()===1266&&$lane->dbAlgo()==='yescrypt'&&$lane->operationalAlgo()==='yescrypt'&&$lane->blockBoundary()===31284,'Yescrypt maturity lane identity or boundary changed');
yescrypt_maturity_ok($lane->isAccountingCommissioned()&&$lane->isMaturityCommissioned()&&$lane->isPayoutPreparationCommissioned()&&!$lane->isWalletSendCommissioned()&&$lane->isCommissioned(),'Yescrypt staged commissioning predicates are incorrect');
yescrypt_maturity_ok($lane->maturityBlockLimit()===10&&$lane->batchLimit()===25,'Yescrypt maturity or payout limit is incorrect');

list($result,$store,$daemon)=run_yescrypt_maturity(array(yescrypt_maturity_row(31284),yescrypt_maturity_row(31285),yescrypt_maturity_row(31286)),array(31285=>array('state'=>'generate','confirmations'=>520),31286=>array('state'=>'immature','confirmations'=>12)));
yescrypt_maturity_ok(array_map(function($row){return $row['block_id'];},$store->selected)===array(31285,31286)&&$daemon->calls===array(31285,31286),'Yescrypt maturity crossed the exclusive boundary or missed eligible forward blocks');
yescrypt_maturity_ok($result['matured']===1&&$store->states[31285]['category']==='generate'&&$store->states[31285]['earnings'][0]['status']===1&&$store->states[31285]['earnings'][0]['mature_time']==='matured','Mature generated Yescrypt earning did not become status 1 with mature_time');
yescrypt_maturity_ok($result['refreshed_immature']===1&&$store->states[31286]['category']==='immature'&&$store->states[31286]['earnings'][0]['status']===0&&$store->states[31286]['earnings'][0]['mature_time']===null,'Immature Yescrypt earning changed maturity state');
yescrypt_maturity_ok(!isset($store->states[31284]),'Boundary block 31284 was mutated');

list($result,$store)=run_yescrypt_maturity(array(yescrypt_maturity_row(31287)),array(31287=>array('state'=>'orphan','confirmations'=>-1)));
yescrypt_maturity_ok($result['orphaned']===1&&$store->states[31287]['category']==='orphan'&&$store->states[31287]['earnings'][0]['status']===-1&&$store->states[31287]['earnings'][0]['mature_time']===null,'Yescrypt orphan path did not retain a non-payable earning');

$wrongRows=array(
	yescrypt_maturity_row(31300,array('candidate_coin_id'=>1267)),
	yescrypt_maturity_row(31301,array('candidate_algo'=>'scrypt')),
	yescrypt_maturity_row(31302,array('block_coin_id'=>1267)),
	yescrypt_maturity_row(31303,array('block_coin_id'=>1268,'block_algo'=>'skein')),
	yescrypt_maturity_row(31304,array('block_coin_id'=>1269,'block_algo'=>'badcoin-groestl')),
	yescrypt_maturity_row(31305,array('block_coin_id'=>1270,'block_algo'=>'sha256')),
	yescrypt_maturity_row(31306,array('block_algo'=>'scrypt')),
	yescrypt_maturity_row(31307,array('block_blockhash'=>'different')),
	yescrypt_maturity_row(31308,array('lane_ownership'=>$registry->get('live-scrypt-v1')->ownershipEnvelope())),
);
list($result,$store,$daemon)=run_yescrypt_maturity($wrongRows,array());
yescrypt_maturity_ok($store->states===array()&&$daemon->calls===array()&&$result['apply_failed']===7,'Wrong coin, algorithm, hash lineage, or lane ownership reached Yescrypt maturity apply');

foreach(array(array(1267,'yescrypt',31284,2),array(1266,'scrypt',31284,2),array(1266,'yescrypt',31283,2),array(1266,'yescrypt',31284,11)) as $args){$probe=new YescryptMaturityStore();try{(new BadpoolLiveBlockMaturity($probe,new YescryptMaturityDaemon(),$lane))->run($args[0],$args[1],$args[2],$args[3]);yescrypt_maturity_ok(false,'Mismatched Yescrypt maturity request was accepted');}catch(InvalidArgumentException $e){yescrypt_maturity_ok($probe->selected===array(),'Mismatched request reached Yescrypt selection');}}
$validated=LiveBlockMaturityCommand::configurationForRequest(1266,'yescrypt',31284,2,'live-yescrypt-v1');yescrypt_maturity_ok($validated->toArray()===$lane->toArray(),'Future production Yescrypt maturity command shape was rejected');

list($bounded,$boundedStore,$boundedDaemon)=run_yescrypt_maturity(array(yescrypt_maturity_row(31400),yescrypt_maturity_row(31401),yescrypt_maturity_row(31402)),array(),2);
yescrypt_maturity_ok($bounded['selected']===2&&count($boundedStore->states)===2&&$boundedDaemon->calls===array(31400,31401),'Yescrypt maturity invocation was not independently bounded');
yescrypt_maturity_ok($boundedStore->accountCredits===0&&$boundedStore->payouts===0&&$boundedStore->walletSends===0,'Yescrypt maturity performed a forbidden downstream financial action');
yescrypt_maturity_ok($registry->fromOwnershipEnvelope($lane->ownershipEnvelope())===$lane,'Yescrypt payout-preparation ownership was not resolved exactly');

$scrypt=$registry->get('live-scrypt-v1');$groestl=$registry->get('live-groestl-v1');
yescrypt_maturity_ok($scrypt->coinId()===1267&&$scrypt->dbAlgo()==='scrypt'&&$scrypt->blockBoundary()===29242&&$scrypt->maturityBlockLimit()===10&&$scrypt->isWalletSendCommissioned(),'Scrypt behavior changed');
yescrypt_maturity_ok($groestl->coinId()===1269&&$groestl->dbAlgo()==='badcoin-groestl'&&$groestl->blockBoundary()===31212&&$groestl->maturityBlockLimit()===10&&$groestl->batchLimit()===25&&$groestl->isPayoutPreparationCommissioned()&&!$groestl->isWalletSendCommissioned(),'Groestl behavior changed');
yescrypt_maturity_ok($lane->statePath()!==$scrypt->statePath()&&$lane->statePath()!==$groestl->statePath()&&$lane->lockPath()!==$scrypt->lockPath()&&$lane->lockPath()!==$groestl->lockPath(),'Yescrypt state or lock identity collides with another lane');

$source=file_get_contents(dirname(__DIR__).'/web/yaamp/core/backend/BadpoolLiveBlockMaturity.php');
yescrypt_maturity_ok(strpos($source,'FROM live_block_candidates C INNER JOIN blocks B ON B.id=C.block_id')!==false,'Maturity selection no longer requires live candidate lineage');
yescrypt_maturity_ok(strpos($source,'C.block_id=B.id AND C.coin_id=B.coin_id AND C.blockhash=B.blockhash AND C.algo=:algo')!==false,'Locked maturity apply no longer requires exact candidate/block lineage');
foreach(array('UPDATE accounts','INSERT INTO payouts','sendmany','sendtoaddress') as $needle)yescrypt_maturity_ok(stripos($source,$needle)===false,'Forbidden maturity side effect present: '.$needle);

if($fail){echo "FAIL Yescrypt live maturity harness\n - ".implode("\n - ",$fail)."\n";exit(1);}
echo "PASS Yescrypt live maturity harness\n";
