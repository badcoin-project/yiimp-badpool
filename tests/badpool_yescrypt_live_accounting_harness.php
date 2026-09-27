<?php
function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}
require_once dirname(__DIR__).'/web/yaamp/core/backend/BadpoolLiveBlockAccounting.php';

$fail=array();
function yescrypt_ok($value,$message){global $fail;if(!$value)$fail[]=$message;}

class YescryptAccountingDaemon implements BadpoolLiveBlockDaemon
{
	public function inspect($candidate){return array('category'=>'immature','txhash'=>'tx'.$candidate['block_id'],'amount'=>50,'confirmations'=>1);}
}

class YescryptAccountingStore implements BadpoolLiveBlockStore
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

function yescrypt_candidate($id,$coin,$algo,$hash=null,$attribution=null)
{
	return array('block_id'=>$id,'coin_id'=>$coin,'blockhash'=>$hash===null?'h'.$id:$hash,'algo'=>$algo,'found_time'=>1000+$id,'price'=>0.01,'share_floor_id'=>100,'share_ceiling_id'=>110,'attribution'=>$attribution===null?array(75=>1):$attribution);
}
function add_yescrypt_case($store,$id,$coin,$algo,$candidateHash=null,$blockHash=null,$attribution=null)
{
	$candidate=yescrypt_candidate($id,$coin,$algo,$candidateHash,$attribution);$store->candidates[]=$candidate;
	$store->blocks[$id]=array('id'=>$id,'coin_id'=>$coin,'blockhash'=>$blockHash===null?$candidate['blockhash']:$blockHash,'category'=>'new');
}

$registry=new BadpoolLivePaymentLaneRegistry();$lane=$registry->get('live-yescrypt-v1');
yescrypt_ok($lane->coinId()===1266&&$lane->dbAlgo()==='yescrypt'&&$lane->blockBoundary()===31284,'Yescrypt lane scope is not exact');
yescrypt_ok($lane->isAccountingCommissioned()&&$lane->isMaturityCommissioned()&&$lane->isPayoutPreparationCommissioned()&&!$lane->isWalletSendCommissioned()&&$lane->isCommissioned(),'Yescrypt commissioning predicates are incorrect');

$store=new YescryptAccountingStore();
add_yescrypt_case($store,30000,1266,'yescrypt');
add_yescrypt_case($store,31284,1266,'yescrypt');
add_yescrypt_case($store,31285,1266,'yescrypt');
add_yescrypt_case($store,31619,1266,'yescrypt');
add_yescrypt_case($store,31620,1267,'scrypt');
add_yescrypt_case($store,31621,1268,'skein');
add_yescrypt_case($store,31622,1269,'badcoin-groestl');
add_yescrypt_case($store,31623,1270,'sha256');
add_yescrypt_case($store,31624,1266,'scrypt');
add_yescrypt_case($store,31625,1266,'yescrypt','candidate-hash','different-block-hash');
add_yescrypt_case($store,31626,1266,'yescrypt',null,null,array());
add_yescrypt_case($store,31627,1266,'yescrypt');$store->earnings[31627]=array('status'=>0);

$result=(new BadpoolLiveBlockAccounting($store,new YescryptAccountingDaemon(),$lane))->run(1266,'yescrypt',31284,10);
$selectedIds=array_map(function($row){return $row['block_id'];},$store->selected);
yescrypt_ok($selectedIds===array(31285,31619,31626),'Yescrypt selection crossed its boundary, coin, algo, lineage, or duplicate-earning scope');
yescrypt_ok($store->mutations===array(31285,31619)&&$result['selected']===3&&$result['immature']===2&&$result['apply_failed']===1,'Yescrypt accounting did not apply only attributed eligible candidates');
yescrypt_ok($store->blocks[31284]['category']==='new'&&$store->blocks[30000]['category']==='new','boundary or historical Yescrypt row changed');
yescrypt_ok($store->blocks[31620]['category']==='new'&&$store->blocks[31621]['category']==='new'&&$store->blocks[31622]['category']==='new'&&$store->blocks[31623]['category']==='new','cross-lane coin isolation failed');
yescrypt_ok($store->blocks[31624]['category']==='new'&&$store->blocks[31625]['category']==='new','wrong algorithm or mismatched block hash was accepted');
yescrypt_ok($store->blocks[31626]['category']==='new'&&!isset($store->earnings[31626]),'missing attribution was not fail-closed');
yescrypt_ok($store->blocks[31627]['category']==='new'&&$store->earnings[31627]['status']===0,'existing earning was duplicated or changed');

foreach(array(array(1267,'yescrypt',31284),array(1266,'scrypt',31284),array(1266,'yescrypt',31283)) as $wrong){
	$calls=$store->selectionCalls;
	try{(new BadpoolLiveBlockAccounting($store,new YescryptAccountingDaemon(),$lane))->run($wrong[0],$wrong[1],$wrong[2],1);yescrypt_ok(false,'mismatched commissioned scope executed');}
	catch(InvalidArgumentException $e){yescrypt_ok($store->selectionCalls===$calls,'mismatched commissioned scope reached selection');}
}

$source=file_get_contents(dirname(__DIR__).'/web/yaamp/core/backend/BadpoolLiveBlockAccounting.php');
yescrypt_ok(strpos($source,'C.coin_id=:coin AND C.algo=:algo AND C.block_id > :after')!==false,'production selection lost exact coin, algo, or exclusive boundary predicates');
yescrypt_ok(strpos($source,'C.blockhash=B.blockhash')!==false&&strpos($source,'C.coin_id=B.coin_id')!==false,'production selection lost candidate/block lineage joins');
yescrypt_ok(strpos($source,'live_block_attributions WHERE block_id=:id')!==false,'production apply lost durable attribution inventory');
yescrypt_ok(strpos($source,'NOT EXISTS (SELECT 1 FROM earnings E WHERE E.blockid=B.id)')!==false,'production selection lost duplicate-earning exclusion');

if($fail){echo "FAIL Yescrypt live accounting harness\n - ".implode("\n - ",$fail)."\n";exit(1);}
echo "PASS Yescrypt live accounting harness\n";
