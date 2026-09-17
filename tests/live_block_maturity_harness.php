<?php
function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}
require_once dirname(__DIR__).'/web/yaamp/core/backend/BadpoolLiveBlockMaturity.php';
$fail=array();function check($v,$m){global $fail;if(!$v)$fail[]=$m;}
function row($id=29273){return array('block_id'=>$id,'candidate_coin_id'=>1267,'candidate_algo'=>'scrypt','candidate_blockhash'=>'hash'.$id,'block_coin_id'=>1267,'block_algo'=>'scrypt','block_blockhash'=>'hash'.$id,'block_txhash'=>'tx'.$id,'block_category'=>'immature','earning_count'=>1,'status0_count'=>1);}
class MaturityDaemon implements BadpoolLiveBlockMaturityDaemon {public $result,$throws=false,$calls=0;function __construct($r){$this->result=$r;}function inspect($c){$this->calls++;if($this->throws)throw new RuntimeException('rpc');return $this->result;}}
class MaturityStore implements BadpoolLiveBlockMaturityStore {
	public $rows=array(),$applies=0,$last=null,$result=array('status'=>'applied'),$accountCredits=0,$payouts=0,$walletSends=0;
	function candidates($coin,$algo,$after,$limit){$out=array();foreach($this->rows as $r)if($r['block_id']>$after&&$r['candidate_coin_id']===$coin&&$r['candidate_algo']===$algo&&$r['block_category']==='immature')$out[]=$r;usort($out,function($a,$b){return $a['block_id']-$b['block_id'];});return array_slice($out,0,$limit);}
	function apply($c,$r){$this->applies++;$this->last=$r;return $this->result;}
}
function runCase($rows,$daemonResult,$after=29242,$limit=2){$s=new MaturityStore;$s->rows=$rows;$d=new MaturityDaemon($daemonResult);$p=new BadpoolLiveBlockMaturity($s,$d);return array($p->run(1267,'scrypt',$after,$limit),$s,$d);}

foreach(array(0,'0',-1,'',null,1.2) as $bad){try{runCase(array(),array('state'=>'immature','confirmations'=>1),$bad);check(false,'bad boundary accepted');}catch(InvalidArgumentException $e){}}
try{(new BadpoolLiveBlockMaturity(new MaturityStore,new MaturityDaemon(array())))->run(1,'sha256',29242,2);check(false,'wrong coin/algo accepted');}catch(InvalidArgumentException $e){}
$before=row(29242);list($r,$s)=runCase(array($before,row(29243)),array('state'=>'immature','confirmations'=>20));check($r['selected']===1&&$s->applies===1,'exclusive boundary/pre-boundary exclusion failed');
list($r,$s)=runCase(array(row()),array('state'=>'immature','confirmations'=>518));check($r['refreshed_immature']===1&&$s->last['confirmations']===518&&$s->last['state']==='immature','immature confirmation refresh failed');
list($r,$s)=runCase(array(row()),array('state'=>'generate','confirmations'=>520));check($r['matured']===1&&$s->last['state']==='generate','mature classification failed');
$bad=row();$bad['candidate_blockhash']='other';list($r,$s,$d)=runCase(array($bad),array('state'=>'generate','confirmations'=>520));check($r['apply_failed']===1&&$s->applies===0&&$d->calls===0,'identity mismatch mutated state');
foreach(array(array('earning_count'=>0,'status0_count'=>0),array('earning_count'=>2,'status0_count'=>2),array('earning_count'=>1,'status0_count'=>0)) as $change){$x=array_merge(row(),$change);list($r,$s)=runCase(array($x),array('state'=>'generate','confirmations'=>520));check($r['apply_failed']===1&&$s->applies===0,'ambiguous earning accepted');}
$missing=new MaturityStore;$d=new MaturityDaemon(array('state'=>'generate','confirmations'=>1));$r=(new BadpoolLiveBlockMaturity($missing,$d))->run(1267,'scrypt',29242,2);check($r['selected']===0&&$d->calls===0,'missing candidate entered scope');
$s=new MaturityStore;$s->rows=array(row());$d=new MaturityDaemon(array());$d->throws=true;$r=(new BadpoolLiveBlockMaturity($s,$d))->run(1267,'scrypt',29242,2);check($r['daemon_failed']===1&&$s->applies===0,'RPC failure mutated state');
list($r,$s)=runCase(array(row()),array('state'=>'orphan','confirmations'=>-7));check($r['orphaned']===1&&$s->applies===1&&$s->last['confirmations']===-7,'orphan disposition was not applied with daemon confirmations');
list($r,$s)=runCase(array(row(29243),row(29244),row(29245)),array('state'=>'immature','confirmations'=>1),29242,2);check($r['selected']===2&&$s->applies===2,'limit not enforced');
$s=new MaturityStore;$s->rows=array(row());$s->result=array('status'=>'skipped','reason'=>'already_mature');$r=(new BadpoolLiveBlockMaturity($s,new MaturityDaemon(array('state'=>'generate','confirmations'=>520))))->run(1267,'scrypt',29242,2);check($r['skipped']===1&&$r['apply_failed']===0,'concurrent maturity not benign');
foreach(array('block_update_failure','earning_update_failure') as $reason){$s=new MaturityStore;$s->rows=array(row());$s->result=array('status'=>'failed','reason'=>$reason);$r=(new BadpoolLiveBlockMaturity($s,new MaturityDaemon(array('state'=>'generate','confirmations'=>520))))->run(1267,'scrypt',29242,2);check($r['apply_failed']===1,'transaction failure not reported');}
check(LiveSafety::verify(),'unreachable downstream path contract failed');
class LiveSafety {static function verify(){ $source=file_get_contents(dirname(__DIR__).'/web/yaamp/core/backend/BadpoolLiveBlockMaturity.php');foreach(array('BackendUserUpdate','BackendPayments','sendmany','sendtoaddress','account balance') as $needle)if(strpos($source,$needle)!==false)return false;return true;}}
// The selector and locked update target only status-0 earnings for the selected block; unrelated status-1 rows have no mutation statement.
$source=file_get_contents(dirname(__DIR__).'/web/yaamp/core/backend/BadpoolLiveBlockMaturity.php');
check(strpos($source,'WHERE id=:earning AND blockid=:block AND coinid=:coin AND status=0')!==false,'earning mutation is not exact/status-0 bounded');
check(strpos($source,"SET category='orphan',confirmations=:conf")!==false,'orphan block transition/daemon evidence missing');
check(strpos($source,'SET status=-1,mature_time=NULL')!==false,'orphan earning is not retained as non-payable');
check(strpos($source,"already_orphaned")!==false&&strpos($source,"already_mature")!==false,'idempotent/concurrent terminal states are not benign skips');
check(strpos($source,'ambiguous block/earning terminal state')!==false,'inconsistent terminal status combinations do not fail closed');
check(strpos($source,"SELECT id,status,mature_time FROM earnings WHERE blockid=:id FOR UPDATE")!==false,'exact linked earnings are not locked');
check(strpos($source,"category='immature'")!==false&&strpos($source,'C.block_id>:after')!==false,'selector is not immature/boundary bounded');
foreach(array('DELETE FROM earnings','DELETE earnings','UPDATE accounts','INSERT INTO payouts','sendmany','sendtoaddress') as $needle)check(stripos($source,$needle)===false,'forbidden maturity side effect present: '.$needle);
$stats=file_get_contents(dirname(__DIR__).'/web/yaamp/core/backend/stats.php');check(strpos($stats,'yaamp_convert_earnings_user($user, "status IN (0,1)")')!==false,'user pending aggregate includes invalid earnings');
$api=file_get_contents(dirname(__DIR__).'/web/yaamp/modules/api/ApiController.php');check(strpos($api,'yaamp_convert_earnings_user($user, "status!=2")')===false,'API pending aggregate includes invalid earnings');
$earningsView=file_get_contents(dirname(__DIR__).'/web/yaamp/modules/site/earning_results.php');check(strpos($earningsView,'status IN (0,1)')!==false,'user earnings view presents invalid earnings as pending');
$coinView=file_get_contents(dirname(__DIR__).'/web/yaamp/modules/site/coin_results.php');check(strpos($coinView,'status!=2')===false,'coin aggregate includes invalid earnings');
$sell=file_get_contents(dirname(__DIR__).'/web/yaamp/core/backend/sell.php');check(strpos($sell,'where status IN (0,1)')!==false,'sell reserve includes invalid earnings');
$system=file_get_contents(dirname(__DIR__).'/web/yaamp/core/backend/system.php');check(strpos($system,"delete from earnings where status!=-1 and blockid in")!==false,'legacy cleanup can delete retained invalid evidence');
$user=file_get_contents(dirname(__DIR__).'/web/yaamp/commands/UserCommand.php');check(strpos($user,"B.category!='orphan'")!==false&&strpos($user,'status=0"')!==false,'user coin reassignment can restore invalid orphan earnings');
$guard=file_get_contents(dirname(__DIR__).'/web/yaamp/commands/BadpoolGuardCommand.php');check(strpos($guard,'E.status=1 AND E.mature_time<:delay')!==false&&strpos($guard,'AND status=1 AND mature_time=:mt')!==false,'guarded account credit is not status-1 bounded');
$clear=file_get_contents(dirname(__DIR__).'/web/yaamp/core/backend/clear.php');check(strpos($clear,'status=1 AND mature_time<$delay')!==false,'legacy account credit is not status-1 bounded');
if($fail){echo "FAIL live block maturity harness\n - ".implode("\n - ",$fail)."\n";exit(1);}echo "PASS live block maturity harness\n";
