<?php
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolGuardedMultiWalletSend.php');

$fixture=json_decode(file_get_contents(dirname(__FILE__).'/badpool_multi_lane_wallet_send_fixture.json'),true);
$checks=0;$failures=array();$roots=array();
function sm_ok($v,$m){global$checks,$failures;$checks++;if(!$v)$failures[]=$m;}
function sm_throws($fn){try{$fn();return false;}catch(Exception$e){return true;}}
function sm_rows($fixture){$out=array();foreach($fixture['payouts']as$row)$out[$row['payout_id']]=$row;return$out;}
function sm_entry($r){return array('payout_id'=>$r['payout_id'],'lane_id'=>$r['lane_id'],'coin_id'=>$r['coin_id'],'account_id'=>$r['account_id'],'amount'=>$r['amount'],'recipient'=>$r['recipient'],'wallet_binding_identity'=>$r['wallet_binding_identity'],'source_account_identity'=>$r['source_account_identity'],'expected_completed'=>0,'expected_tx'=>null,'expected_batch_state'=>'READY_FOR_WALLET_APPROVAL');}
function sm_approval($rows,$ids=array(526,527,528)){sort($ids,SORT_NUMERIC);$e=array();foreach($ids as$id)$e[]=sm_entry($rows[$id]);return array('schema'=>'badpool.wallet_send.multi_lane_approval.v1','human_approved'=>true,'entries'=>$e);}
function sm_root($label){global$roots;$p=sys_get_temp_dir().'/badpool-multi-wallet-'.$label.'-'.bin2hex(random_bytes(5));mkdir($p,0770,true);$roots[]=$p;return$p;}
function sm_cleanup($p){if(is_link($p)){unlink($p);return;}if(!is_dir($p))return;foreach(scandir($p)as$f)if($f!=='.'&&$f!=='..'){$c=$p.'/'.$f;if(is_dir($c)&&!is_link($c))sm_cleanup($c);else unlink($c);}rmdir($p);}

class StateMachineFixtureRepository implements BadpoolExactMultiWalletPayoutRepository
{
	public $rows,$reconcileCalls=0,$reconciled=array(),$failReconcile=false,$extra=false;
	function __construct($rows){$this->rows=$rows;}
	function loadExactPayouts($ids){$out=array();foreach($ids as$id)if(isset($this->rows[$id]))$out[]=$this->rows[$id];if($this->extra)$out[]=$this->rows[529];return$out;}
	function reconcileExactWithTransactionIds($approved,$map){$this->reconcileCalls++;$ids=array();foreach($approved as$r){$id=$r['payout_id'];$ids[]=$id;if(!isset($this->rows[$id])||$this->rows[$id]['completed']!==0||$this->rows[$id]['tx']!==null||$this->rows[$id]['amount']!==$r['amount']||$this->rows[$id]['recipient']!==$r['recipient'])throw new RuntimeException('changed payout');}if($this->failReconcile)throw new RuntimeException('synthetic reconciliation failure');foreach($ids as$id){$this->rows[$id]['completed']=1;$this->rows[$id]['tx']=$map[$id];}$this->reconciled=$map;return count($ids);}
}
class StateMachineFixtureGateway implements BadpoolPerWalletOperationGateway
{
	public $journal,$preflights=array(),$calls=array(),$events=array(),$readiness=array(),$results=array(),$onSend=null,$startEvidence=true,$claimEvidence=true;
	function __construct($journal){$this->journal=$journal;}
	function preflightApprovedWalletOperation($op){$w=$op['wallet_binding_identity'];$this->preflights[]=$w;$this->events[]='preflight:'.$w;return isset($this->readiness[$w])?$this->readiness[$w]:array('ready'=>true,'balance'=>'999999.00000000','reserve'=>'1.00000000','wallet_locked'=>false);}
	function sendApprovedWalletOperation($op,$capability){$w=$op['wallet_binding_identity'];$j=$this->journal->load($op['approval_checksum']);$this->startEvidence=$this->startEvidence&&$j['operations'][$op['operation_id']]['state']==='SEND_ATTEMPT_STARTED';try{$capability->claim($op['approval_checksum'],$op);$claimed=true;}catch(Exception$e){$claimed=false;}$this->claimEvidence=$this->claimEvidence&&$claimed;$this->calls[]=$w;$this->events[]='send:'.$w;if(is_callable($this->onSend))call_user_func($this->onSend,$w,$this);if(isset($this->results[$w])){$r=$this->results[$w];if($r instanceof Exception)throw$r;return$r;}$hex=array('scrypt'=>'a','groestl'=>'b','yescrypt'=>'c');return array('status'=>'txid','txid'=>str_repeat($hex[$w],64));}
}
function sm_system($rows,$label){$repo=new StateMachineFixtureRepository($rows);$journal=new BadpoolDurableMultiWalletSendJournal(sm_root($label));$gateway=new StateMachineFixtureGateway($journal);$exec=new BadpoolGuardedMultiWalletSend($repo,$gateway,$journal);return array($exec,$repo,$gateway,$journal);}

$rows=sm_rows($fixture);$approval=sm_approval($rows);
list($exec,$repo,$gateway,$journal)=sm_system($rows,'plan');$plan=$exec->preflight($approval);
sm_ok($plan['payout_ids']===array(526,527,528),'global exact scope');
sm_ok(count($plan['operations'])===3,'three wallet operations');
sm_ok(array_column($plan['operations'],'wallet_binding_identity')===array('scrypt','groestl','yescrypt'),'deterministic operation order');
sm_ok(count(array_unique(array_column($plan['operations'],'operation_id')))===3,'operation IDs unique');
$plan2=$exec->preflight($approval);sm_ok(array_column($plan['operations'],'operation_id')===array_column($plan2['operations'],'operation_id'),'operation IDs deterministic');
sm_ok((bool)preg_match('/^wallet-op-[a-f0-9]{24}$/',$plan['operations'][0]['operation_id']),'operation ID canonical format');
sm_ok($plan['approval_document']===$approval,'preflight retains exact approval document');
sm_ok($plan['read_only']===true&&$plan['db_mutations']===false&&$plan['wallet_sends']===false,'preflight is explicitly read-only');
sm_ok((bool)preg_match('/^[a-f0-9]{64}$/',$plan['report_checksum'])&&$plan['report_checksum']===$plan2['report_checksum'],'preflight report checksum deterministic');
sm_ok(in_array('existing_or_conflicting_journal',$plan['stop_conditions'],true),'preflight reports journal STOP condition');
sm_ok($plan['operations'][0]['payout_ids']===array(526),'Scrypt payout isolation');
sm_ok($plan['operations'][1]['payout_ids']===array(527),'Groestl payout isolation');
sm_ok($plan['operations'][2]['payout_ids']===array(528),'Yescrypt payout isolation');
sm_ok($plan['operations'][0]['source_account_identity']==='pool-scrypt','Scrypt source');
sm_ok($plan['operations'][1]['source_account_identity']==='pool-groestl','Groestl source');
sm_ok($plan['operations'][2]['source_account_identity']==='pool-yescrypt','Yescrypt source');
sm_ok($plan['operations'][0]['rpc_config_identity']==='/etc/badcoin/pool-scrypt.conf','Scrypt RPC identity');
sm_ok($plan['operations'][1]['rpc_config_identity']==='/etc/badcoin/pool-groestl.conf','Groestl RPC identity');
sm_ok($plan['operations'][2]['rpc_config_identity']==='/etc/badcoin/pool-yescrypt.conf','Yescrypt RPC identity');
sm_ok($plan['operations'][0]['wallet_datadir_identity']==='/var/lib/badcoin-pool-scrypt','Scrypt datadir');
sm_ok($plan['operations'][1]['wallet_datadir_identity']==='/var/lib/badcoin-pool-groestl','Groestl datadir');
sm_ok($plan['operations'][2]['wallet_datadir_identity']==='/var/lib/badcoin-pool-yescrypt','Yescrypt datadir');
sm_ok($plan['operations'][0]['raw_total']==='54111.530811649995','Scrypt raw amount');
sm_ok($plan['operations'][1]['raw_total']==='55875.65007076999','Groestl raw amount');
sm_ok($plan['operations'][2]['raw_total']==='33063.28546626001','Yescrypt raw amount');
sm_ok($plan['operations'][0]['wallet_send_total']==='54111.53081165','Scrypt projection');
sm_ok($plan['operations'][1]['wallet_send_total']==='55875.65007077','Groestl projection');
sm_ok($plan['operations'][2]['wallet_send_total']==='33063.28546626','Yescrypt projection');
sm_ok($plan['raw_total']==='143050.466348679995','global raw total');
sm_ok($plan['wallet_send_total']==='143050.46634868','global projected total');
foreach($plan['operations']as$op)sm_ok($op['approval_checksum']===$plan['approval_checksum'],'operation approval checksum binding '.$op['wallet_binding_identity']);
sm_ok(!in_array(529,$plan['payout_ids'],true),'unrelated payout excluded');

list($exec,$repo,$gateway,$journal)=sm_system($rows,'success');$r=$exec->execute($approval);
sm_ok($r['status']==='pass'&&$r['journal_state']==='RECONCILED','all wallet success reconciles');
sm_ok($r['db_reconciliation_status']==='complete'&&$r['db_completion_success']===true,'successful reconciliation completion metadata');
sm_ok($r['db_mutations']===true&&$r['db_mutation_status']==='guarded_transaction_committed','successful reconciliation mutation metadata');
sm_ok($gateway->calls===array('scrypt','groestl','yescrypt'),'one call per wallet in order');
sm_ok(array_slice($gateway->events,0,3)===array('preflight:scrypt','preflight:groestl','preflight:yescrypt'),'all funding checks before first send');
sm_ok($gateway->startEvidence,'SEND_ATTEMPT_STARTED visible before every call');
sm_ok($gateway->claimEvidence,'one-use bound capabilities accepted');
sm_ok($repo->reconcileCalls===1&&array_keys($repo->reconciled)===array(526,527,528),'one exact reconciliation');
sm_ok($repo->reconciled[526]===str_repeat('a',64),'Scrypt txid mapped to 526');
sm_ok($repo->reconciled[527]===str_repeat('b',64),'Groestl txid mapped to 527');
sm_ok($repo->reconciled[528]===str_repeat('c',64),'Yescrypt txid mapped to 528');
sm_ok($repo->rows[529]['completed']===0,'unrelated payout untouched');
$again=$exec->execute($approval);sm_ok($again['status']==='pass'&&count($gateway->calls)===3,'reconciled restart never resends');sm_ok($again['db_reconciliation_status']==='complete'&&$again['db_completion_success']===true&&$again['db_mutations']===false&&$again['db_mutation_status']==='none','already-reconciled restart reports no mutation this run');

foreach(array('scrypt','groestl','yescrypt')as$wallet){list($exec,$repo,$gateway,$journal)=sm_system($rows,'fund-'.$wallet);$gateway->readiness[$wallet]=array('ready'=>false,'reason'=>$wallet.' insufficient balance');$r=$exec->execute($approval);sm_ok($r['reason']==='wallet_preflight_failed'&&count($gateway->calls)===0,$wallet.' funding failure blocks all sends');}
list($exec,$repo,$gateway,$journal)=sm_system($rows,'reserve');$gateway->readiness['groestl']=array('ready'=>false,'reason'=>'reserve requirement');$r=$exec->execute($approval);sm_ok(count($gateway->calls)===0&&strpos($r['error'],'reserve')!==false,'reserve enforcement blocks all sends');
foreach(array('daemon unavailable','wallet locked','wrong RPC config','wrong source account','wrong wallet binding')as$i=>$reason){list($exec,$repo,$gateway,$journal)=sm_system($rows,'ready-'.$i);$gateway->readiness['yescrypt']=array('ready'=>false,'reason'=>$reason);$r=$exec->execute($approval);sm_ok($r['status']==='refused'&&count($gateway->calls)===0,$reason.' blocks all sends');}

list($exec,$repo,$gateway,$journal)=sm_system($rows,'definite');$gateway->results['groestl']=array('status'=>'definite_failure','error'=>'definite reject');$r=$exec->execute($approval);$j=$journal->load(BadpoolMultiWalletApprovalPlanner::checksum($approval));sm_ok($r['reason']==='wallet_definite_failure'&&$gateway->calls===array('scrypt','groestl'),'definite failure stops execution');sm_ok($j['operations'][$plan['operations'][0]['operation_id']]['txid']===str_repeat('a',64),'first txid retained before definite failure');sm_ok($j['operations'][$plan['operations'][2]['operation_id']]['state']==='PENDING','third wallet not attempted after failure');sm_ok($repo->reconcileCalls===0,'definite failure performs no DB completion');

list($exec,$repo,$gateway,$journal)=sm_system($rows,'uncertain');$gateway->results['groestl']=array('status'=>'uncertain','error'=>'timeout');$r=$exec->execute($approval);$j=$journal->load(BadpoolMultiWalletApprovalPlanner::checksum($approval));sm_ok($r['reason']==='wallet_outcome_uncertain'&&$j['global_state']==='HOLD_MANUAL_RECOVERY','uncertain result enters HOLD');sm_ok($j['operations'][$plan['operations'][0]['operation_id']]['state']==='TXID_RETURNED','partial success txid preserved');sm_ok($j['operations'][$plan['operations'][1]['operation_id']]['state']==='UNCERTAIN','uncertain operation preserved');sm_ok($j['operations'][$plan['operations'][2]['operation_id']]['state']==='PENDING','later wallet remains pending');$retry=$exec->execute($approval);sm_ok($retry['reason']==='manual_recovery_required'&&count($gateway->calls)===2,'uncertainty blocks retry');

list($exec,$repo,$gateway,$journal)=sm_system($rows,'throw');$gateway->results['scrypt']=new RuntimeException('connection dropped');$r=$exec->execute($approval);sm_ok($r['reason']==='wallet_outcome_uncertain'&&count($gateway->calls)===1,'gateway exception is uncertain and stops');

list($exec,$repo,$gateway,$journal)=sm_system($rows,'changed-initial');$repo->rows[527]['amount']='1.0';$r=$exec->execute($approval);sm_ok($r['reason']==='pre_send_validation_failed'&&count($gateway->calls)===0,'changed payout blocks first RPC');
list($exec,$repo,$gateway,$journal)=sm_system($rows,'changed-between');$gateway->onSend=function($wallet)use($repo){if($wallet==='scrypt')$repo->rows[527]['recipient']='changed-destination';};$r=$exec->execute($approval);sm_ok($r['reason']==='payout_changed_between_wallet_operations'&&$gateway->calls===array('scrypt'),'changed payout between operations holds safely');sm_ok($journal->load(BadpoolMultiWalletApprovalPlanner::checksum($approval))['global_state']==='HOLD_MANUAL_RECOVERY','changed-between journal holds');

list($exec,$repo,$gateway,$journal)=sm_system($rows,'db-fail');$repo->failReconcile=true;$r=$exec->execute($approval);$j=$journal->load(BadpoolMultiWalletApprovalPlanner::checksum($approval));sm_ok($r['reason']==='database_reconciliation_failed'&&$j['global_state']==='HOLD_MANUAL_RECOVERY','DB failure holds');$txCount=0;foreach($j['operations']as$o)if($o['txid'])$txCount++;sm_ok($txCount===3,'DB failure preserves all txids');$retry=$exec->execute($approval);sm_ok(count($gateway->calls)===3&&$retry['reason']==='manual_recovery_required','DB failure blocks resend');sm_ok($repo->rows[526]['completed']===0&&$repo->rows[527]['completed']===0&&$repo->rows[528]['completed']===0,'failed reconciliation is all-or-none');
sm_ok($r['db_reconciliation_status']==='failed'&&$r['db_completion_success']===false&&$r['db_mutations']===false&&$r['db_mutation_status']==='none','rolled-back DB failure reports no mutation');

list($exec,$repo,$gateway,$journal)=sm_system($rows,'restart-safe');$plan=$exec->preflight($approval);$journal->prepare($plan);$op=$plan['operations'][0];$journal->startAttempt($plan['approval_checksum'],$op['operation_id']);$journal->recordTxid($plan['approval_checksum'],$op['operation_id'],str_repeat('a',64));$r=$exec->execute($approval);sm_ok($r['status']==='pass'&&$gateway->calls===array('groestl','yescrypt'),'restart resumes after recorded txid without resend');
list($exec,$repo,$gateway,$journal)=sm_system($rows,'restart-uncertain');$plan=$exec->preflight($approval);$journal->prepare($plan);$journal->startAttempt($plan['approval_checksum'],$plan['operations'][0]['operation_id']);$r=$exec->execute($approval);sm_ok($r['reason']==='uncertain_prior_attempt'&&count($gateway->calls)===0,'restart after attempt without txid requires recovery');

$same=$rows;$same[527]['recipient']=$same[526]['recipient'];list($exec,$repo,$gateway,$journal)=sm_system($same,'cross-recipient');$cross=$exec->preflight(sm_approval($same));sm_ok(count($cross['operations'])===3,'same recipient across independent wallets is safe');
$sameWallet=$rows;$sameWallet[529]=$rows[526];$sameWallet[529]['payout_id']=529;$sameWallet[529]['account_id']=90;$sameWallet[529]['recipient']=$rows[526]['recipient'];list($exec,$repo,$gateway,$journal)=sm_system($sameWallet,'duplicate-recipient');$aggregate=$exec->preflight(sm_approval($sameWallet,array(526,529)));sm_ok($aggregate['operations'][0]['recipients']===array($rows[526]['recipient']=>'108223.06162330')&&$aggregate['operations'][0]['payout_ids']===array(526,529),'duplicate recipient aggregates with exact payout identities');

$registry=new BadpoolLivePaymentLaneRegistry();sm_ok($registry->get('live-skein-v1')->isHumanApprovedWalletSendEligible()&&!$registry->get('live-skein-v1')->isWalletSendCommissioned(),'Skein wallet-ready lane is operator eligible while recurring send stays disabled');sm_ok(!$registry->get('live-sha256d-v1')->isHumanApprovedWalletSendEligible()&&!$registry->get('live-sha256d-v1')->isPayoutPreparationCommissioned(),'SHA256d excluded');sm_ok(!$registry->get('live-groestl-v1')->isWalletSendCommissioned()&&!$registry->get('live-yescrypt-v1')->isWalletSendCommissioned(),'recurring wallet flags remain disabled');
$coordinator=file_get_contents(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolLivePaymentCoordinator.php');sm_ok(strpos($coordinator,'BadpoolPerWalletOperationGateway')===false&&strpos($coordinator,'sendApprovedWalletOperation')===false,'recurring coordinator cannot reach gateway');
$guard=file_get_contents(dirname(__FILE__).'/../web/yaamp/core/rpc/wallet-send-guard.php');sm_ok(strpos($guard,"'sendmany' => true")!==false&&strpos($guard,'runtime activation switch')!==false,'generic WalletRPC send remains guarded');

list($fourExec,$fourRepo,$fourGateway,$fourJournal)=sm_system($rows,'four-wallet-plan');$four=$fourExec->preflight(sm_approval($rows,array(526,527,528,529)));
sm_ok(count($four['operations'])===4&&array_column($four['operations'],'wallet_binding_identity')===array('scrypt','groestl','yescrypt','skein'),'four deterministic wallet operations include Skein');
sm_ok($four['operations'][1]['payout_ids']===array(527)&&$four['operations'][3]['payout_ids']===array(529),'shared account 76 cannot merge Groestl and Skein');
sm_ok($four['operations'][3]['rpc_config_identity']==='/etc/badcoin/pool-skein.conf'&&$four['operations'][3]['wallet_datadir_identity']==='/var/lib/badcoin-pool-skein','Skein operational identity is exact source evidence');
sm_ok($four['raw_total']==='201031.582277210005'&&$four['wallet_send_total']==='201031.58227721','four-wallet totals exact');

list($exec,$repo,$gateway,$journal)=sm_system($rows,'capability');$plan=$exec->preflight($approval);$cap=new BadpoolWalletOperationCapability($plan['approval_checksum'],$plan['operations'][0]);sm_ok($cap->claim($plan['approval_checksum'],$plan['operations'][0])===true,'capability exact binding');sm_ok(sm_throws(function()use($cap,$plan){$cap->claim($plan['approval_checksum'],$plan['operations'][0]);}),'capability one use only');$cap=new BadpoolWalletOperationCapability($plan['approval_checksum'],$plan['operations'][0]);sm_ok(sm_throws(function()use($cap,$plan){$cap->claim($plan['approval_checksum'],$plan['operations'][1]);}),'operation cannot adopt capability');

$journal->prepare($plan);sm_ok(sm_throws(function()use($journal,$plan){$journal->recordTxid($plan['approval_checksum'],$plan['operations'][1]['operation_id'],str_repeat('a',64));}),'operation cannot adopt another txid before attempt');$conflict=$plan;$conflict['plan_checksum']=str_repeat('f',64);sm_ok(sm_throws(function()use($journal,$conflict){$journal->prepare($conflict); }),'conflicting existing journal refused');
list($exec,$repo,$gateway,$malformed)=sm_system($rows,'malformed');$plan=$exec->preflight($approval);$malformed->prepare($plan);file_put_contents($malformed->path($plan['approval_checksum']),"{}\n");sm_ok(sm_throws(function()use($malformed,$plan){$malformed->load($plan['approval_checksum']);}),'malformed journal rejected');
if(function_exists('symlink')){list($exec,$repo,$gateway,$linkJournal)=sm_system($rows,'symlink');$plan=$exec->preflight($approval);$target=sm_root('symlink-target').'/evidence.json';file_put_contents($target,"{}\n");$path=$linkJournal->path($plan['approval_checksum']);$linked=@symlink($target,$path);sm_ok(!$linked||(is_link($path)&&sm_throws(function()use($linkJournal,$plan){$linkJournal->load($plan['approval_checksum']);})),'journal symlink refused');}

foreach($roots as$p)sm_cleanup($p);
if($failures){echo"FAIL multi-wallet state-machine harness ($checks checks)\n - ".implode("\n - ",$failures)."\n";exit(1);}echo"PASS multi-wallet state-machine harness ($checks checks)\n";
