<?php
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolGuardedMultiLaneWalletSend.php');

$fixture=json_decode(file_get_contents(dirname(__FILE__).'/badpool_multi_lane_wallet_send_fixture.json'),true);
if(!is_array($fixture))throw new RuntimeException('fixture unreadable');
$checks=0;$failures=array();
function mw_check($condition,$label){global$checks,$failures;$checks++;if(!$condition)$failures[]=$label;}
function mw_rows($fixture){$out=array();foreach($fixture['payouts']as$row)$out[$row['payout_id']]=$row;return$out;}
function mw_entry($row){return array('payout_id'=>$row['payout_id'],'lane_id'=>$row['lane_id'],'coin_id'=>$row['coin_id'],'account_id'=>$row['account_id'],'amount'=>$row['amount'],'recipient'=>$row['recipient'],'wallet_binding_identity'=>$row['wallet_binding_identity'],'source_account_identity'=>$row['source_account_identity'],'expected_completed'=>0,'expected_tx'=>null,'expected_batch_state'=>'READY_FOR_WALLET_APPROVAL');}
function mw_approval($rows,$ids,$human=true){$entries=array();foreach($ids as$id)$entries[]=mw_entry($rows[$id]);return array('schema'=>BadpoolGuardedMultiLaneWalletSend::APPROVAL_SCHEMA,'human_approved'=>$human,'entries'=>$entries);}

class MultiWalletFixtureRepository implements BadpoolExactPayoutRepository{
	public $rows,$extra=false,$fail=false,$reconcileCalls=0,$lastIds=array();
	function __construct($rows){$this->rows=$rows;}
	function loadExactPayouts($ids){$out=array();foreach($ids as$id)if(isset($this->rows[$id]))$out[]=$this->rows[$id];if($this->extra)$out[]=$this->rows[529];return$out;}
	function reconcileExact($approved,$txid){$this->reconcileCalls++;$ids=array();foreach($approved as$row){$id=$row['payout_id'];$ids[]=$id;if(!isset($this->rows[$id])||$this->rows[$id]['completed']!==0||$this->rows[$id]['tx']!==null)throw new RuntimeException('guarded precondition mismatch');}if($this->fail)throw new RuntimeException('synthetic transactional reconciliation failure');foreach($ids as$id){$this->rows[$id]['completed']=1;$this->rows[$id]['tx']=$txid;}$this->lastIds=$ids;return count($ids);}
}
class MultiWalletFixtureGateway implements BadpoolApprovedScopeWalletGateway{
	public $calls=0,$fail=false,$invalid=false,$bindings,$recipients,$ids;
	function sendmanyApprovedScope($bindings,$recipients,$ids){$this->calls++;$this->bindings=$bindings;$this->recipients=$recipients;$this->ids=$ids;if($this->fail)throw new RuntimeException('synthetic wallet failure');return$this->invalid?'not-a-txid':str_repeat('a',64);}
}
class MultiWalletFixtureEvidence implements BadpoolWalletSendRecoveryEvidence{
	public $reports=array();function retain($report){$this->reports[]=$report;}function hasPossibleSend($ids){foreach($this->reports as$r)if($r['approved_payout_ids']===$ids)return true;return false;}
}
function mw_system($rows,$evidence=null){$repo=new MultiWalletFixtureRepository($rows);$wallet=new MultiWalletFixtureGateway();$evidence=$evidence?:new MultiWalletFixtureEvidence();$exec=new BadpoolGuardedMultiLaneWalletSend($repo,$wallet,null,$evidence);return array($exec,$repo,$wallet);}
function mw_refused($approval,$rows,$mutator=null){list($exec,$repo,$wallet)=mw_system($rows);if($mutator)$mutator($repo,$wallet);$result=$exec->execute($approval);return array($result,$repo,$wallet);}

$rows=mw_rows($fixture);
mw_check($fixture['schema']==='badpool.wallet_send.fixture.v1','fixture schema');
mw_check($fixture['skein_wallet_ready_batch']['batch_id']==='20260928T002836Z-dadc9cef85e9','Skein wallet-ready batch ID');
mw_check($fixture['skein_wallet_ready_batch']['batch_state']==='READY_FOR_WALLET_APPROVAL'&&$fixture['skein_wallet_ready_batch']['created_payout_ids']===array(529),'Skein wallet-ready payout retained');
foreach(array(526=>array('live-scrypt-v1',79,'54111.530811649995'),527=>array('live-groestl-v1',76,'55875.65007076999'),528=>array('live-yescrypt-v1',75,'33063.28546626001'),529=>array('live-skein-v1',76,'57981.11592853001'))as$id=>$expected)mw_check($rows[$id]['lane_id']===$expected[0]&&$rows[$id]['account_id']===$expected[1]&&$rows[$id]['amount']===$expected[2]&&$rows[$id]['completed']===0&&$rows[$id]['tx']===null,'protected payout fixture '.$id);

list($r,$repo,$wallet)=mw_refused(array(),$rows);mw_check($r['status']==='refused'&&$wallet->calls===0,'exact approval payload required');
$a=mw_approval($rows,array(526));$a['entries']=array();list($r,$repo,$wallet)=mw_refused($a,$rows);mw_check($r['status']==='refused'&&$wallet->calls===0,'empty approval rejected');
$a=mw_approval($rows,array(526));$a['entries'][0]['payout_id']=999;list($r,$repo,$wallet)=mw_refused($a,$rows);mw_check($r['status']==='refused'&&$wallet->calls===0,'unknown payout rejected');
$a=mw_approval($rows,array(526));$a['entries'][]=$a['entries'][0];list($r,$repo,$wallet)=mw_refused($a,$rows);mw_check($r['status']==='refused','duplicate payout rejected');
foreach(array('completed'=>1,'tx'=>str_repeat('b',64))as$field=>$value){$changed=$rows;$changed[526][$field]=$value;list($r,$repo,$wallet)=mw_refused(mw_approval($changed,array(526)),$changed);mw_check($r['status']==='refused'&&$wallet->calls===0,'stored '.$field.' replay rejected');}
foreach(array('account_id'=>999,'amount'=>'1.1','lane_id'=>'live-groestl-v1','coin_id'=>1269,'wallet_binding_identity'=>'other','source_account_identity'=>'pool-other')as$field=>$value){$a=mw_approval($rows,array(526));$a['entries'][0][$field]=$value;list($r,$repo,$wallet)=mw_refused($a,$rows);mw_check($r['status']==='refused'&&$wallet->calls===0,$field.' mismatch rejected');}

foreach(array(526,527,528)as$id){list($exec,$repo,$wallet)=mw_system($rows);try{$plan=$exec->validateApproval(mw_approval($rows,array($id)));$ok=$plan['payout_ids']===array($id);}catch(Exception$e){$ok=false;}mw_check($ok,'single protected payout validates '.$id);}
foreach(array(array(526,527),array(526,527,528))as$ids){list($exec,$repo,$wallet)=mw_system($rows);try{$plan=$exec->validateApproval(mw_approval($rows,$ids));$ok=$plan['payout_ids']===$ids;}catch(Exception$e){$ok=false;}mw_check($ok,'multi-lane scope validates '.implode('+',$ids));}
list($exec,$repo,$wallet)=mw_system($rows);$plan=$exec->validateApproval(mw_approval($rows,array(526,527,528)));
mw_check($plan['raw_total']==='143050.466348679995','aggregate raw amount exact');
mw_check($plan['wallet_send_total']==='143050.46634868','aggregate wallet amount exact');
mw_check($plan['recipients']===array('BAD-scrypt-account-79'=>'54111.53081165','BAD-groestl-account-76'=>'55875.65007077','BAD-yescrypt-account-75'=>'33063.28546626'),'recipient map exact');
mw_check($plan['payout_ids']===array(526,527,528),'deterministic payout ordering');
mw_check(!in_array(529,$plan['payout_ids'],true)&&!isset($plan['recipients']['BAD-unapproved-account-90']),'unapproved incomplete payout excluded');
mw_check(count($plan['rows'])===3&&count($plan['wallet_bindings'])===3,'no unrelated payout or lane included');
$changed=$rows;$changed[527]['recipient']=$changed[526]['recipient'];$a=mw_approval($changed,array(526,527));list($r,$repo,$wallet)=mw_refused($a,$changed);mw_check($r['status']==='refused'&&$wallet->calls===0,'duplicate destination is refused rather than implicitly combined');

$a=mw_approval($rows,array(526,527));$a['entries'][1]['amount']='1.0';list($r,$repo,$wallet)=mw_refused($a,$rows);mw_check($wallet->calls===0&&$repo->reconcileCalls===0,'one invalid entry prevents wallet and DB');
list($r,$repo,$wallet)=mw_refused(mw_approval($rows,array(526,527)),$rows,function($repo,$wallet){$wallet->fail=true;});mw_check($r['reason']==='wallet_rpc_failed'&&$wallet->calls===1&&$repo->reconcileCalls===0,'wallet failure prevents reconciliation');
mw_check($repo->rows[526]['completed']===0&&$repo->rows[527]['completed']===0,'wallet failure leaves all payouts unchanged');
list($exec,$repo,$wallet)=mw_system($rows);$r=$exec->execute(mw_approval($rows,array(526,527,528)));mw_check($r['status']==='pass'&&$wallet->calls===1&&$r['txid']===str_repeat('a',64),'wallet success produces one txid');
mw_check($repo->lastIds===array(526,527,528)&&$repo->rows[529]['completed']===0,'reconciliation updates exact approved IDs only');
mw_check($repo->rows[526]['tx']===$repo->rows[527]['tx']&&$repo->rows[527]['tx']===$repo->rows[528]['tx'],'identical txid reconciled to approved rows');
mw_check($repo->rows[526]['completed']===1&&$repo->rows[527]['completed']===1&&$repo->rows[528]['completed']===1,'no partial completion on success');
$again=$exec->execute(mw_approval($rows,array(526,527,528)));mw_check($again['status']==='refused'&&$wallet->calls===1,'post-send replay rejected before wallet');

foreach(array('live-scrypt-v1'=>array(527,528),'live-groestl-v1'=>array(526,528),'live-yescrypt-v1'=>array(526,527),'live-skein-v1'=>array(526,527,528),'live-sha256d-v1'=>array(526,527,528))as$laneId=>$ids){foreach($ids as$id){$a=mw_approval($rows,array($id));$a['entries'][0]['lane_id']=$laneId;list($r,$repo,$wallet)=mw_refused($a,$rows);mw_check($r['status']==='refused'&&$wallet->calls===0,$laneId.' cannot adopt payout '.$id);}}
$registry=new BadpoolLivePaymentLaneRegistry();mw_check($registry->get('live-skein-v1')->isHumanApprovedWalletSendEligible()&&!$registry->get('live-skein-v1')->isWalletSendCommissioned(),'Skein is human-send eligible while recurring send stays disabled');
mw_check(!$registry->get('live-sha256d-v1')->isHumanApprovedWalletSendEligible()&&!$registry->get('live-sha256d-v1')->isPayoutPreparationCommissioned(),'SHA256d accounting-only lane is not wallet-ready');
mw_check($registry->get('live-groestl-v1')->isHumanApprovedWalletSendEligible()&&!$registry->get('live-groestl-v1')->isWalletSendCommissioned(),'human execution eligibility is separate from recurring Groestl flag');
mw_check($registry->get('live-yescrypt-v1')->isHumanApprovedWalletSendEligible()&&!$registry->get('live-yescrypt-v1')->isWalletSendCommissioned(),'human execution eligibility is separate from recurring Yescrypt flag');
$a=mw_approval($rows,array(526),false);list($r,$repo,$wallet)=mw_refused($a,$rows);mw_check($r['status']==='refused'&&$wallet->calls===0,'human approval mandatory');
$coordinator=file_get_contents(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolLivePaymentCoordinator.php');mw_check(strpos($coordinator,"case'READY_FOR_WALLET_APPROVAL'")!==false&&strpos($coordinator,"return'HUMAN_WALLET_APPROVAL_REQUIRED'")!==false,'coordinator still parks phase 6 for human approval');
mw_check(strpos($coordinator,'sendmanyApprovedScope')===false&&strpos($coordinator,'badpoolGuardedSendmanyApply')===false,'coordinator has no automatic wallet call');

$a=mw_approval($rows,array(526));$a['unexpected']=true;list($r,$repo,$wallet)=mw_refused($a,$rows);mw_check($r['status']==='refused','malformed approval payload rejected');
$a=mw_approval($rows,array(526));unset($a['entries'][0]['amount']);list($r,$repo,$wallet)=mw_refused($a,$rows);mw_check($r['status']==='refused','missing approval field rejected');
list($r,$repo,$wallet)=mw_refused(mw_approval($rows,array(526)),$rows,function($repo,$wallet){$repo->extra=true;});mw_check($r['status']==='refused'&&$wallet->calls===0,'unexpected extra payout rejected');
mw_check($plan['rows'][0]['amount']==='54111.530811649995'&&$plan['rows'][2]['amount']==='33063.28546626001','amount precision preserved in validated rows');
$a=mw_approval($rows,array(527,526));list($r,$repo,$wallet)=mw_refused($a,$rows);mw_check($r['status']==='refused','nondeterministic input ordering rejected');
$changed=$rows;$changed[526]['batch_state']='WAITING_PAYMENT_DELAY';list($r,$repo,$wallet)=mw_refused(mw_approval($rows,array(526)),$changed);mw_check($r['status']==='refused'&&$wallet->calls===0,'state ledger reconciliation remains lane-safe');
$changed=$rows;$changed[526]['completed']=1;$changed[526]['tx']=str_repeat('c',64);list($r,$repo,$wallet)=mw_refused(mw_approval($rows,array(526)),$changed);mw_check($r['status']==='refused'&&$wallet->calls===0,'completed payout with tx cannot resend');
$changed=$rows;$changed[526]['tx']=str_repeat('d',64);list($r,$repo,$wallet)=mw_refused(mw_approval($rows,array(526)),$changed);mw_check($r['status']==='refused'&&strpos($r['error'],'reconciliation')!==false,'tx with incomplete state requires reconciliation');
$changed=$rows;$changed[526]['tx']='';list($r,$repo,$wallet)=mw_refused(mw_approval($rows,array(526)),$changed);mw_check($r['status']==='refused'&&$wallet->calls===0,'empty-string tx cannot masquerade as expected null');

$evidence=new MultiWalletFixtureEvidence();list($exec,$repo,$wallet)=mw_system($rows,$evidence);$repo->fail=true;$r=$exec->execute(mw_approval($rows,array(526,527)));mw_check($r['status']==='hold'&&$wallet->calls===1&&$r['do_not_retry']===true,'post-send DB failure returns hold and no-retry');
mw_check(count($evidence->reports)===1&&$evidence->reports[0]['txid']===str_repeat('a',64),'manual recovery evidence retained');
$retry=$exec->execute(mw_approval($rows,array(526,527)));mw_check($retry['reason']==='manual_recovery_required'&&$wallet->calls===1,'possible successful send blocks automatic retry');
mw_check($repo->rows[526]['completed']===0&&$repo->rows[527]['completed']===0,'failed transaction produces no partial completion');
list($exec,$repo,$wallet)=mw_system($rows);$single=$exec->execute(mw_approval($rows,array(526)));mw_check($single['status']==='pass'&&$single['approved_payout_ids']===array(526),'single-lane compatibility send supported');
mw_check($wallet->calls===1&&$wallet->ids===array(526),'single-lane uses one guarded gateway call');
list($r,$repo,$wallet)=mw_refused(mw_approval($rows,array(526)),$rows,function($repo,$wallet){$wallet->invalid=true;});mw_check($r['status']==='refused'&&$repo->reconcileCalls===0,'invalid returned txid cannot mutate payouts');

if($failures){echo"FAIL multi-lane wallet-send harness ($checks checks)\n - ".implode("\n - ",$failures)."\n";exit(1);}echo"PASS multi-lane wallet-send harness ($checks checks)\n";
