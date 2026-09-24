<?php
function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}
require_once dirname(__DIR__).'/web/yaamp/core/backend/BadpoolLiveBlockMaturity.php';
$fail=array();function inv_ok($v,$m){global $fail;if(!$v)$fail[]=$m;}

class InventoryTx {public $active=true,$db,$snapshot;function __construct($db){$this->db=$db;$this->snapshot=array($db->block,$db->earnings);}function commit(){$this->active=false;}function rollback(){list($this->db->block,$this->db->earnings)=$this->snapshot;$this->active=false;}}
class InventoryCommand {
	private $db,$sql;function __construct($db,$sql){$this->db=$db;$this->sql=$sql;}
	function queryAll($fetch,$params){
		if(strpos($this->sql,'SELECT C.block_id')===0)return array($this->db->candidate());
		if(strpos($this->sql,'SELECT id,userid,coinid,blockid,amount,price,status,mature_time FROM earnings')===0){$rows=array_values($this->db->earnings);usort($rows,function($a,$b){return $a['id']-$b['id'];});return $rows;}
		throw new RuntimeException('unexpected queryAll');
	}
	function queryRow($fetch,$params){if(strpos($this->sql,'SELECT B.category,B.confirmations')===0)return array('category'=>$this->db->block['category'],'confirmations'=>$this->db->block['confirmations']);throw new RuntimeException('unexpected queryRow');}
	function execute($params){
		if(strpos($this->sql,'UPDATE blocks SET')===0){if($this->db->block['category']!=='immature')return 0;$this->db->block['confirmations']=$params[':conf'];if(strpos($this->sql,"category='generate'")!==false)$this->db->block['category']='generate';if(strpos($this->sql,"category='orphan'")!==false)$this->db->block['category']='orphan';return 1;}
		if(strpos($this->sql,'UPDATE earnings SET')===0){$id=intval($params[':earning']);if(!isset($this->db->earnings[$id])||intval($this->db->earnings[$id]['status'])!==0||intval($this->db->earnings[$id]['blockid'])!==intval($params[':block'])||intval($this->db->earnings[$id]['coinid'])!==intval($params[':coin']))return 0;if(strpos($this->sql,'status=1')!==false){$this->db->earnings[$id]['status']=1;$this->db->earnings[$id]['mature_time']='now';}else{$this->db->earnings[$id]['status']=-1;$this->db->earnings[$id]['mature_time']=null;}return 1;}
		throw new RuntimeException('unexpected execute');
	}
}
class InventoryDb {
	public $block,$earnings;function __construct($earnings){$this->block=array('id'=>29273,'coin_id'=>1267,'category'=>'immature','confirmations'=>0,'blockhash'=>'hash29273');$this->earnings=array();foreach($earnings as $e)$this->earnings[$e['id']]=$e;}
	function createCommand($sql=null){return new InventoryCommand($this,(string)$sql);}function beginTransaction(){return new InventoryTx($this);}
	function candidate(){return array('block_id'=>29273,'candidate_coin_id'=>1267,'candidate_algo'=>'scrypt','candidate_blockhash'=>'hash29273','block_coin_id'=>1267,'block_algo'=>'scrypt','block_blockhash'=>'hash29273','block_txhash'=>'tx29273','block_category'=>'immature');}
}
function inv_earning($id,$user,$amount){return array('id'=>$id,'userid'=>$user,'coinid'=>1267,'blockid'=>29273,'amount'=>$amount,'price'=>'1.00000000','status'=>0,'mature_time'=>null);}
function inventory_case($earnings){$db=new InventoryDb($earnings);$store=new BadpoolYiiLiveBlockMaturityStore($db);$candidate=$store->candidates(1267,'scrypt',29242,10);return array($db,$store,$candidate[0]);}

list($db,$store,$candidate)=inventory_case(array(inv_earning(11,79,'1.25000000')));$result=$store->apply($candidate,array('state'=>'generate','confirmations'=>520));inv_ok($result['status']==='applied'&&$db->block['category']==='generate'&&$db->earnings[11]['status']===1,'single earning did not mature');
list($db,$store,$candidate)=inventory_case(array(inv_earning(11,79,'1.25000000'),inv_earning(12,80,'2.50000000')));$result=$store->apply($candidate,array('state'=>'generate','confirmations'=>520));inv_ok($result['status']==='applied'&&$db->earnings[11]['status']===1&&$db->earnings[12]['status']===1,'two-earning block did not mature atomically');inv_ok($db->earnings[11]['userid']===79&&$db->earnings[12]['userid']===80&&$db->earnings[11]['amount']==='1.25000000'&&$db->earnings[12]['amount']==='2.50000000','users or amounts were merged/recomputed');

foreach(array('removed','added','status','amount','wrong_coin','wrong_block') as $case){
	list($db,$store,$candidate)=inventory_case(array(inv_earning(11,79,'1.25000000'),inv_earning(12,80,'2.50000000')));
	if($case==='removed')unset($db->earnings[12]);
	if($case==='added')$db->earnings[13]=inv_earning(13,81,'3.75000000');
	if($case==='status')$db->earnings[12]['status']=1;
	if($case==='amount')$db->earnings[12]['amount']='999.00000000';
	if($case==='wrong_coin')$db->earnings[12]['coinid']=1268;
	if($case==='wrong_block')$db->earnings[12]['blockid']=99999;
	$result=$store->apply($candidate,array('state'=>'generate','confirmations'=>520));inv_ok($result['status']==='failed'&&$db->block['category']==='immature'&&$db->earnings[11]['status']===0,$case.' preview/apply drift did not fail closed and roll back');
}

$registry=new BadpoolLivePaymentLaneRegistry();foreach(array('uncommissioned-yescrypt','uncommissioned-skein','uncommissioned-groestl','uncommissioned-sha256d') as $id){$lane=$registry->get($id);try{(new BadpoolLiveBlockMaturity(new class implements BadpoolLiveBlockMaturityStore{function candidates($c,$a,$b,$l){throw new RuntimeException('executed');}function apply($c,$r){throw new RuntimeException('executed');}},new class implements BadpoolLiveBlockMaturityDaemon{function inspect($c){throw new RuntimeException('executed');}},$lane))->run($lane->coinId(),$lane->dbAlgo(),1,1);inv_ok(false,$id.' maturity executed');}catch(InvalidArgumentException $e){}}
inv_ok($registry->get('uncommissioned-groestl')->operationalAlgo()==='groestl'&&$registry->get('uncommissioned-groestl')->dbAlgo()==='badcoin-groestl','Groestl mapping changed');
inv_ok($registry->get('uncommissioned-sha256d')->operationalAlgo()==='sha256d'&&$registry->get('uncommissioned-sha256d')->dbAlgo()==='sha256','SHA256d mapping changed');

class CConsoleCommand {}
require_once dirname(__DIR__).'/web/yaamp/commands/LiveBlockMaturityCommand.php';
$method=new ReflectionMethod('LiveBlockMaturityCommand','actionIndex');$parameters=$method->getParameters();inv_ok(count($parameters)===5&&$parameters[4]->isDefaultValueAvailable()&&$parameters[4]->getDefaultValue()==='live-scrypt-v1','existing command does not default safely to the Scrypt lane');
foreach(array('uncommissioned-yescrypt','uncommissioned-skein','uncommissioned-groestl','uncommissioned-sha256d') as $id){$lane=$registry->get($id);try{(new LiveBlockMaturityCommand())->actionIndex($lane->coinId(),$lane->dbAlgo(),1,1,$id);inv_ok(false,$id.' command executed');}catch(InvalidArgumentException $e){}}

if($fail){echo "FAIL live maturity inventory harness\n - ".implode("\n - ",$fail)."\n";exit(1);}echo "PASS live maturity inventory harness\n";
