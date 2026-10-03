<?php
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolYiiExactMultiWalletPayoutRepository.php');

$checks=0;$failures=array();
function pr_ok($value,$message){global$checks,$failures;$checks++;if(!$value)$failures[]=$message;}
function pr_throws($callback){try{$callback();return false;}catch(Exception$e){return true;}}

class RepoFixtureCommand
{
	private$db,$sql;
	function __construct($db,$sql){$this->db=$db;$this->sql=$sql;}
	function queryAll($assoc,$params)
	{
		$this->db->selectSql[]=$this->sql;$ids=array_map('intval',array_values($params));$out=array();
		foreach($this->db->rows as$id=>$row){if(in_array($id,$ids,true)&&@$row['account_exists']!==false&&@$row['coin_exists']!==false)$out[]=$row;}
		if($this->db->extraReturnId!==null)$out[]=$this->db->rows[$this->db->extraReturnId];
		usort($out,function($a,$b){return$a['payout_id']-$b['payout_id'];});return$out;
	}
	function execute($params)
	{
		$id=intval($params[':id']);if($this->db->failUpdateId===$id)return 0;$row=&$this->db->rows[$id];
		if(!$row||$row['account_id']!==$params[':account_id']||$row['coin_id']!==$params[':coin_id']||$row['amount']!==$params[':amount']||$row['completed']!==0||$row['tx']!==null)return 0;
		$row['completed']=1;$row['tx']=$params[':txid'];return 1;
	}
}
class RepoFixtureTransaction{private$db,$snapshot;function __construct($db){$this->db=$db;$this->snapshot=$db->rows;}function commit(){$this->db->commits++;}function rollback(){$this->db->rows=$this->snapshot;$this->db->rollbacks++;}}
class RepoFixtureDb{public$rows=array(),$selectSql=array(),$failUpdateId=null,$extraReturnId=null,$commits=0,$rollbacks=0;function createCommand($sql){return new RepoFixtureCommand($this,$sql);}function beginTransaction(){return new RepoFixtureTransaction($this);}}

function pr_state($root,$lane,$batch)
{
	$state=array('schema'=>$lane->get('ownership_schema'),'version'=>1,'lane'=>$lane->laneId(),'coin_id'=>$lane->coinId(),'algo'=>$lane->dbAlgo(),'block_id_gt'=>$lane->blockBoundary(),'active_batch_id'=>$batch,'last_observed_batch_state'=>'READY_FOR_WALLET_APPROVAL');
	file_put_contents($lane->statePath($root),json_encode($state));
}
function pr_ledger($root,$lane,$batch,$ids)
{
	if(!is_dir($root.'/'.$batch))mkdir($root.'/'.$batch);$ledger=array('batch_id'=>$batch,'batch_state'=>'READY_FOR_WALLET_APPROVAL','coordinator_owner'=>$lane->ownershipEnvelope(),'created_payout_ids'=>$ids);
	file_put_contents($root.'/'.$batch.'/ledger.json',json_encode($ledger));
}
function pr_clean($path){if(!is_dir($path))return;foreach(scandir($path)as$file)if($file!=='.'&&$file!=='..'){$child=$path.'/'.$file;if(is_dir($child))pr_clean($child);else unlink($child);}rmdir($path);}
function pr_rejects_row_change($repo,$db,$id,$field,$value){$saved=$db->rows[$id][$field];$db->rows[$id][$field]=$value;$rejected=pr_throws(function()use($repo,$id){$repo->loadExactPayouts(array($id));});$db->rows[$id][$field]=$saved;return$rejected;}

$root=sys_get_temp_dir().'/badpool-production-repo-'.bin2hex(random_bytes(5));mkdir($root);$registry=new BadpoolLivePaymentLaneRegistry();
$lanes=array(526=>$registry->get('live-scrypt-v1'),527=>$registry->get('live-groestl-v1'),528=>$registry->get('live-yescrypt-v1'),529=>$registry->get('live-skein-v1'));
$batchIds=array(526=>'batch-526',527=>'batch-527',528=>'20260927T135815Z-f30c33bc6543',529=>'20260928T002836Z-dadc9cef85e9');
foreach($lanes as$id=>$lane){pr_state($root,$lane,$batchIds[$id]);pr_ledger($root,$lane,$batchIds[$id],array($id));}
$queuedScryptBatch='20261003T160000Z-535353535353';pr_ledger($root,$lanes[526],$queuedScryptBatch,array(530));

$db=new RepoFixtureDb();
foreach(array(526=>array(79,1267,'54111.530811649995','BAD-scrypt-account-79'),527=>array(76,1269,'55875.65007076999','BAD-groestl-account-76'),528=>array(75,1266,'33063.28546626001','BAD-yescrypt-account-75'),529=>array(76,1268,'57981.11592853001','BAD-skein-account-76'),530=>array(90,1267,'1.00000001','BAD-unapproved-account-90'))as$id=>$value){
	$db->rows[$id]=array('payout_id'=>$id,'account_id'=>$value[0],'joined_account_id'=>$value[0],'coin_id'=>$value[1],'amount'=>$value[2],'completed'=>0,'tx'=>null,'recipient'=>$value[3],'account_coin_id'=>1267,'joined_coin_id'=>$value[1],'account_exists'=>true,'coin_exists'=>true);
}
$repo=new BadpoolYiiExactMultiWalletPayoutRepository($db,$root,$registry);$before=$db->rows;$rows=$repo->loadExactPayouts(array(526,527,528,529));

pr_ok(array_column($rows,'payout_id')===array(526,527,528,529),'all four exact fixture IDs resolve');
pr_ok(count($rows)===4&&!in_array(530,array_column($rows,'payout_id'),true),'unrelated payout excluded');
pr_ok(strpos($db->selectSql[0],'WHERE P.id IN')!==false,'query authority is explicit payout IDs');
pr_ok(strpos($db->selectSql[0],'INNER JOIN accounts A ON A.id=P.account_id')!==false&&strpos($db->selectSql[0],'A.id AS joined_account_id')!==false,'recipient is joined through the exact payout account');
pr_ok(strpos($db->selectSql[0],'INNER JOIN coins C ON C.id=P.idcoin')!==false,'payout coin must resolve through coins.id');
pr_ok(strpos($db->selectSql[0],'A.coinid')===false,'accounts.coinid is not payout or lane authority');
pr_ok($db->rows[527]['account_coin_id']===1267&&$db->rows[529]['account_coin_id']===1267&&$db->rows[527]['coin_id']!==1267&&$db->rows[529]['coin_id']!==1267,'accounts.coinid mismatch alone does not reject valid cross-lane payouts');
pr_ok($db->rows===$before,'exact payout discovery performs no database mutation');
$twoScrypt=$repo->loadExactPayouts(array(526,530));pr_ok(array_column($twoScrypt,'batch_id')===array($batchIds[526],$queuedScryptBatch),'two queued READY batches in one lane did not remain independently addressable');
foreach(array(526,527,528,529)as$id)pr_ok($repo->loadExactPayouts(array($id))[0]['payout_id']===$id,'payout '.$id.' resolves independently');
pr_ok($repo->loadExactPayouts(array(527))[0]['account_id']===76&&$repo->loadExactPayouts(array(529))[0]['account_id']===76,'shared account 76 resolves in both lanes');
pr_ok($rows[1]['lane_id']==='live-groestl-v1'&&$rows[1]['coin_id']===1269&&$rows[1]['wallet_binding_identity']==='groestl'&&$rows[1]['source_account_identity']==='pool-groestl','payout 527 retains Groestl wallet ownership');
pr_ok($rows[3]['lane_id']==='live-skein-v1'&&$rows[3]['coin_id']===1268&&$rows[3]['wallet_binding_identity']==='skein'&&$rows[3]['source_account_identity']==='pool-skein','payout 529 retains Skein wallet ownership');
pr_ok($rows[1]['wallet_binding_identity']!==$rows[3]['wallet_binding_identity']&&$rows[1]['source_account_identity']!==$rows[3]['source_account_identity'],'shared account identity does not merge wallet ownership');
pr_ok($rows[1]['recipient']==='BAD-groestl-account-76'&&$rows[3]['recipient']==='BAD-skein-account-76','recipient comes from each exact joined account row');
pr_ok(pr_throws(function()use($repo){$repo->loadExactPayouts(array(526,526));}),'duplicate payout ID refused');
pr_ok(pr_throws(function()use($repo){$repo->loadExactPayouts(array(999));}),'nonexistent payout refused');

pr_ok(pr_rejects_row_change($repo,$db,527,'coin_id',1268),'payout.idcoin differing from lane coin refused');
pr_ok(pr_rejects_row_change($repo,$db,527,'joined_coin_id',1268),'joined coins.id differing from payout.idcoin refused');
pr_ok(pr_rejects_row_change($repo,$db,527,'joined_account_id',75),'wrong joined account row refused');
pr_ok(pr_rejects_row_change($repo,$db,527,'recipient',''),'missing recipient refused');
pr_ok(pr_rejects_row_change($repo,$db,526,'completed',1),'completed payout refused');
pr_ok(pr_rejects_row_change($repo,$db,526,'tx',str_repeat('a',64)),'tx-bearing payout refused');
$db->rows[527]['account_exists']=false;pr_ok(pr_throws(function()use($repo){$repo->loadExactPayouts(array(527));}),'missing account row refused');$db->rows[527]['account_exists']=true;
$db->rows[527]['coin_exists']=false;pr_ok(pr_throws(function()use($repo){$repo->loadExactPayouts(array(527));}),'missing payout coin refused');$db->rows[527]['coin_exists']=true;
$db->extraReturnId=530;pr_ok(pr_throws(function()use($repo){$repo->loadExactPayouts(array(526));}),'unexpected payout returned refused');$db->extraReturnId=null;

$statePath=$lanes[527]->statePath($root);$savedState=file_get_contents($statePath);$queuedState=json_decode($savedState,true);$queuedState['version']=2;$queuedState['active_batch_id']=null;$queuedState['ready_for_wallet_approval_batch_ids']=array($batchIds[527]);file_put_contents($statePath,json_encode($queuedState));pr_ok($repo->loadExactPayouts(array(527))[0]['batch_id']===$batchIds[527],'queued READY ownership incorrectly depended on the active execution slot');file_put_contents($statePath,$savedState);
$ledgerPath=$root.'/'.$batchIds[528].'/ledger.json';$savedLedger=file_get_contents($ledgerPath);$badLedger=json_decode($savedLedger,true);$badLedger['batch_state']='WAITING_PAYMENT_DELAY';file_put_contents($ledgerPath,json_encode($badLedger));pr_ok(pr_throws(function()use($repo){$repo->loadExactPayouts(array(528));}),'non-ready durable batch refused');file_put_contents($ledgerPath,$savedLedger);
$skeinLedgerPath=$root.'/'.$batchIds[529].'/ledger.json';$savedSkeinLedger=file_get_contents($skeinLedgerPath);$badLedger=json_decode($savedSkeinLedger,true);$badLedger['coordinator_owner']['coin_id']=1269;file_put_contents($skeinLedgerPath,json_encode($badLedger));pr_ok(pr_throws(function()use($repo){$repo->loadExactPayouts(array(529));}),'wrong durable lane owner refused');file_put_contents($skeinLedgerPath,$savedSkeinLedger);
$groestlLedgerPath=$root.'/'.$batchIds[527].'/ledger.json';$savedGroestlLedger=file_get_contents($groestlLedgerPath);$badLedger=json_decode($savedGroestlLedger,true);$badLedger['created_payout_ids']=array();file_put_contents($groestlLedgerPath,json_encode($badLedger));pr_ok(pr_throws(function()use($repo){$repo->loadExactPayouts(array(527));}),'payout missing from created_payout_ids refused');file_put_contents($groestlLedgerPath,$savedGroestlLedger);
$scryptLedgerPath=$root.'/'.$batchIds[526].'/ledger.json';$savedScryptLedger=file_get_contents($scryptLedgerPath);$badLedger=json_decode($savedScryptLedger,true);$badLedger['created_payout_ids']=array(526,527);file_put_contents($scryptLedgerPath,json_encode($badLedger));pr_ok(pr_throws(function()use($repo){$repo->loadExactPayouts(array(527));}),'ambiguous durable ownership refused');file_put_contents($scryptLedgerPath,$savedScryptLedger);

$approved=$repo->loadExactPayouts(array(526,527,528,529));$map=array(526=>str_repeat('a',64),527=>str_repeat('b',64),528=>str_repeat('c',64),529=>str_repeat('d',64));$count=$repo->reconcileExactWithTransactionIds($approved,$map);
pr_ok($count===4&&$db->commits===1,'exact reconciliation committed once in fixture transaction');
pr_ok($db->rows[526]['tx']===$map[526]&&$db->rows[527]['tx']===$map[527]&&$db->rows[528]['tx']===$map[528]&&$db->rows[529]['tx']===$map[529],'wallet-specific fixture txids mapped exactly');
pr_ok($db->rows[530]['completed']===0&&$db->rows[530]['tx']===null,'unrelated incomplete payout untouched');
foreach(array(526,527,528,529)as$id){$db->rows[$id]['completed']=0;$db->rows[$id]['tx']=null;}$db->failUpdateId=527;
pr_ok(pr_throws(function()use($repo,$approved,$map){$repo->reconcileExactWithTransactionIds($approved,$map); }),'reconciliation failure reported');
pr_ok($db->rollbacks===1&&$db->rows[526]['completed']===0&&$db->rows[527]['completed']===0&&$db->rows[528]['completed']===0&&$db->rows[529]['completed']===0,'fixture reconciliation rolls back all-or-none');

pr_clean($root);
if($failures){echo"FAIL multi-wallet production repository harness ($checks checks)\n - ".implode("\n - ",$failures)."\n";exit(1);}
echo"PASS multi-wallet production repository harness ($checks checks)\n";
