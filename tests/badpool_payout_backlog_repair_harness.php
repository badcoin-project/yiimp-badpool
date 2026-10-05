<?php
// Offline only: all database, funding and send boundaries below are test doubles.
class CConsoleCommand {}
function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}
require_once(__DIR__.'/../web/yaamp/commands/BadpoolGuardCommand.php');
$checks=0;$failures=array();$roots=array();
function br_ok($v,$label){global $checks,$failures;$checks++;if(!$v)$failures[]=$label;}
function br_throws($f){try{$f();return false;}catch(Exception $e){return true;}}
function br_call($o,$name,$args=array()){$m=new ReflectionMethod($o,$name);$m->setAccessible(true);return $m->invokeArgs($o,$args);}
function br_root(){global $roots;$p=sys_get_temp_dir().'/badpool-backlog-'.bin2hex(random_bytes(6));mkdir($p,0770,true);$roots[]=$p;return $p;}
function br_clean($p){if(is_dir($p)&&!is_link($p)){foreach(scandir($p) as $f)if($f!=='.'&&$f!=='..')br_clean($p.'/'.$f);rmdir($p);}else unlink($p);}

class BacklogDB {
 public $balance='4541.6627178399995',$rows=array(),$last=1000,$debitParams,$sql=array();
 function createCommand($sql){$this->sql[]=$sql;return new BacklogSQL($this,$sql);}
 function beginTransaction(){return new BacklogTransaction($this);}
 function getLastInsertID(){return (string)$this->last;}
}
class BacklogTransaction {
 public $active=true;private $db,$balance,$rows;
 function __construct($db){$this->db=$db;$this->balance=$db->balance;$this->rows=$db->rows;}
 function commit(){$this->active=false;}
 function rollback(){$this->db->balance=$this->balance;$this->db->rows=$this->rows;$this->active=false;}
}
class BacklogSQL {
 private $db,$sql;function __construct($db,$sql){$this->db=$db;$this->sql=$sql;}
 function execute($p){if(strpos($this->sql,'INSERT INTO payouts')===0){$this->db->rows[++$this->db->last]=$p;return 1;}
  if(strpos($this->sql,'UPDATE accounts')===0){$this->db->debitParams=$p;if($p[':old_balance_text']!==$this->db->balance)return 0;$this->db->balance=$p[':new_balance'];return 1;}throw new Exception('Unexpected offline SQL');}
 function queryRow($fetch,$p){$r=arraySafeVal($this->db->rows,$p[':id']);return $r&&$r[':amount']===$p[':amount']?array('id'=>$p[':id']):false;}
}
$testApp=(object)array('db'=>new BacklogDB());function app(){global $testApp;return $testApp;}
class BacklogGuard {
 public $sql=array(),$forceFloat=false;
 function tableExists($t){return true;}function columnExists($t,$c){return true;}
 function isAllCoinsPreview(){return false;}function getScope(){return array('coin_id'=>1267);}
 function getFormat(){return 'json';}function coinWhere($a,$c){return array('sql'=>'A.coinid=:coin','params'=>array(':coin'=>1267));}
 function baseReport($s='ok'){return array('status'=>$s,'scope'=>$this->getScope(),'summary'=>array(),'errors'=>array(),'warnings'=>array());}
 function finalizeReport($r){return BadpoolGuardReport::finalize($r);}
 function selectAll($sql,$params){$this->sql[]=$sql;if(app()->db->balance==='0')return array();return array(array('account_id'=>79,'coin_id'=>1267,'username'=>'offline','current_balance'=>$this->forceFloat?(float)app()->db->balance:app()->db->balance,'threshold'=>'0.001','projected_payout_amount'=>app()->db->balance,'projected_remaining_balance'=>'0'));}
 function selectRow($sql,$params){return array('account_id'=>79,'coinid'=>1267,'balance'=>app()->db->balance);}
}
$command=new BadpoolGuardCommand();$guard=new BacklogGuard();$property=new ReflectionProperty($command,'guard');$property->setAccessible(true);$property->setValue($command,$guard);
$approval=br_call($command,'payoutRowApprovalForIds',array(array(79)));
$approval=json_decode(json_encode($approval),true);
br_ok($approval['items']['selected_accounts'][0]['current_balance']==='4541.6627178399995','authoritative balance survives full approval JSON round trip');
br_ok($approval['selected_amount']==='4541.6627178399995','selected amount is exact');
br_ok(strpos($guard->sql[0],'CAST(A.balance AS CHAR) AS current_balance')!==false,'DB read explicitly returns balance text');
$args=array_slice($approval['apply_command_args'],array_search('payout-row-apply',$approval['apply_command_args'],true)+1);
foreach($args as &$arg)$arg=str_replace('<approval_package_checksum>',$approval['approval_package_checksum']['value'],$arg);unset($arg);
app()->db->balance='4541.6627178399994';
$adjacent=br_call($command,'payoutRowApplyReport',array($args));
br_ok($adjacent['abort_reason']==='checksum_mismatch'&&app()->db->rows===array(),'adjacent decimal refuses before mutation');
app()->db->balance='4541.6627178399995';$guard->forceFloat=true;
br_ok(br_throws(function()use($command){br_call($command,'payoutRowApprovalForIds',array(array(79)));}),'float accounting authority refused');$guard->forceFloat=false;
$applied=br_call($command,'payoutRowApplyReport',array($args));
br_ok($applied['status']==='pass'&&$applied['created_amount']==='4541.6627178399995','exact approval/apply round trip passes');
br_ok(app()->db->debitParams[':old_balance_text']==='4541.6627178399995'&&app()->db->debitParams[':old_balance']==='4541.6627178399995','both debit predicates bind exact string');
br_ok(count(app()->db->rows)===1&&app()->db->balance==='0','one payout inserted with exact debit');
$retry=br_call($command,'payoutRowApplyReport',array($args));br_ok($retry['status']!=='pass'&&count(app()->db->rows)===1,'direct apply retry cannot duplicate payout');
// Strong textual CAS remains distinct even when numeric storage comparisons collapse values.
app()->db->balance='4541.6627178399994';$cas=app()->db->createCommand('UPDATE accounts')->execute(array(':old_balance_text'=>'4541.6627178399995',':new_balance'=>'0'));
br_ok($cas===0,'adjacent decimal rejected by exact debit predicate');

// Exercise the real phase adapter and durable runner from a committed phase-5 ledger.
$root=br_root();$batch='20261004T072007Z-d73ffc159420';$dir=$root.'/'.$batch;mkdir($dir);
$phaseResults=array();for($i=0;$i<=5;$i++)$phaseResults[]=array('phase_number'=>$i,'status'=>'pass');
$ledger=array('batch_id'=>$batch,'mode'=>'auto','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>2,'current_phase'=>6,'batch_state'=>'HOLD','run_directory'=>$dir,'stop_before_wallet_send'=>true,'selected_coin_scope'=>array(array('id'=>1267,'algo'=>'scrypt')),'selected_earning_ids'=>array(20144,20145),'selected_block_ids'=>array(29243,29244),'selected_account_ids'=>array(79),'selected_accounts_by_coin'=>array('1267'=>array('account_ids'=>array(79))),'created_payout_ids'=>array(),'phase_results'=>$phaseResults,'approval_package_paths'=>array(),'dryrun_report_paths'=>array(),'checksums'=>array(),'warnings'=>array(),'errors'=>array(),'coordinator_owner'=>(new BadpoolLivePaymentLaneRegistry())->get('live-scrypt-v1')->ownershipEnvelope());
file_put_contents($dir.'/ledger.json',json_encode($ledger));$calls=array();$earnings=array(20144=>2,20145=>2);$history=2;$refuse=true;
app()->db->balance='4541.6627178399995';app()->db->rows=array();
$adapter=new BadpoolPaymentBatchPhaseAdapter($guard,function($action,$options)use(&$calls,&$refuse,$command){$calls[]=$action;
 if($action==='payout-row-approval-package')return br_call($command,'payoutRowApprovalForIds',array(array(79)));
 if($action==='payout-row-apply'){if($refuse)return array('status'=>'refused','abort_reason'=>'checksum_mismatch','errors'=>array('Exact balance changed; rpcpassword=fixture-secret'),'db_mutations'=>false);return br_call($command,'payoutRowApplyReport',array($options));}
 throw new Exception('Unexpected mutation phase '.$action);
});
$options=array('mode'=>'auto','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>2,'resume_batch_id'=>$batch);
$failed=(new BadpoolPaymentBatchRunner($adapter,$root))->run($options);$failedLedger=json_decode(file_get_contents($dir.'/ledger.json'),true);$e=end($failedLedger['phase_results'])['failure_evidence'];
br_ok($failed['batch_state']==='HOLD'&&$e['batch_id']===$batch&&$e['phase']===6&&$e['refusal_classification']==='checksum_mismatch','HOLD retains exact phase and refusal classification');
br_ok(is_file($e['report_path'])&&$e['report_checksum']===hash_file('sha256',$e['report_path'])&&$e['mutation_status']==='none'&&isset($e['timestamp']),'atomic refusal artifact and ledger checksum retained');
br_ok(strpos(file_get_contents($dir.'/ledger.json'),'fixture-secret')===false&&strpos(file_get_contents($e['report_path']),'fixture-secret')===false,'secret redacted from durable diagnostics');
$refuse=false;$recovered=(new BadpoolPaymentBatchRunner($adapter,$root))->run($options);
br_ok($recovered['batch_state']==='READY_FOR_WALLET_APPROVAL'&&count(app()->db->rows)===1,'held phase-6 recovers with one payout');
br_ok(array_unique($calls)===array('payout-row-approval-package','payout-row-apply')&&$earnings===array(20144=>2,20145=>2)&&$history===2,'recovery invokes only payout preparation; no credit, status reset or history insertion');
$prior=$calls;(new BadpoolPaymentBatchRunner($adapter,$root))->run($options);
br_ok($calls===$prior&&count(app()->db->rows)===1,'runner restart remains idempotent');
$bad=$ledger;$bad['phase_results']=array_slice($phaseResults,0,5);file_put_contents($dir.'/ledger.json',json_encode($bad));$prior=$calls;
$unknown=(new BadpoolPaymentBatchRunner($adapter,$root))->run($options);br_ok($unknown['status']==='refused'&&$calls===$prior,'phase-6 without durable credit evidence refuses all mutation');
// Rebuild a stale queue beside another ready batch without changing either ledger.
file_put_contents($dir.'/ledger.json',json_encode($ledger));$ready=$ledger;$readyId='20261004T080000Z-aaaaaaaaaaaa';$ready['batch_id']=$readyId;$ready['selected_earning_ids']=array(22000);$ready['selected_block_ids']=array(29245);$ready['batch_state']='READY_FOR_WALLET_APPROVAL';$ready['created_payout_ids']=array(999);mkdir($root.'/'.$readyId);file_put_contents($root.'/'.$readyId.'/ledger.json',json_encode($ready));
$lane=(new BadpoolLivePaymentLaneRegistry())->get('live-scrypt-v1');$hash=hash_file('sha256',$dir.'/ledger.json');
$coordinator=(new BadpoolLivePaymentCoordinator(null,$root,$lane))->run();$index=json_decode(file_get_contents($lane->statePath($root)),true);
br_ok($coordinator['classification']==='FAIL_CLOSED'&&$coordinator['batch_state']==='HOLD'&&$coordinator['next_safe_action']==='investigate_hold','HOLD overrides other READY wallet next action');
br_ok($index['blocking_batch_id']===$batch&&!$index['human_wallet_approval_required']&&!in_array($batch,$index['ready_for_wallet_approval_batch_ids'],true)&&!in_array($batch,$index['waiting_payment_delay_batch_ids'],true),'index rebuilt from authoritative HOLD');
br_ok(hash_file('sha256',$dir.'/ledger.json')===$hash,'index repair does not edit durable ledger');
$lock=fopen($dir.'/resume.lock','c');flock($lock,LOCK_EX|LOCK_NB);$prior=$calls;$locked=(new BadpoolPaymentBatchRunner($adapter,$root))->run($options);flock($lock,LOCK_UN);fclose($lock);
br_ok($locked['status']==='refused'&&$calls===$prior,'concurrent phase-6 resume refuses before mutation');
$exceptionRoot=br_root();$exceptionLedger=$ledger;$exceptionLedger['run_directory']=$exceptionRoot;$packagePath=$exceptionRoot.'/package.json';file_put_contents($packagePath,json_encode(array($approval)));
$exceptionCalls=0;$exceptionAdapter=new BadpoolPaymentBatchPhaseAdapter($guard,function()use(&$exceptionCalls){$exceptionCalls++;throw new Exception('Exact debit refused; "rpcpassword":"quoted-secret"');});
$exceptionResult=br_call($exceptionAdapter,'applyPackages',array($exceptionLedger,6,6,'payout-row-apply','payout-row-apply-report.json',$packagePath,array('phase_6_sha256'=>hash_file('sha256',$packagePath))));
br_ok($exceptionResult['failure_evidence']['refusal_classification']==='apply_exception'&&$exceptionResult['failure_evidence']['mutation_status']==='unknown'&&strpos($exceptionResult['failure_evidence']['refusal_reason'],'Exact debit refused')!==false,'apply exception retains underlying diagnostic and uncertain mutation status');
br_ok(strpos(file_get_contents($exceptionResult['report_path']),'quoted-secret')===false,'quoted credential is redacted from exception evidence');
br_ok(is_file($exceptionRoot.'/payout-row-apply-attempt.json'),'unknown payout apply outcome retains durable attempt barrier');
$heldRestart=$exceptionAdapter->preparePayoutRows($exceptionLedger,$options);
br_ok($heldRestart['status']==='hold'&&$exceptionCalls===1,'unknown payout apply restart cannot dispatch another mutation');
$checksumRefusal=br_call($exceptionAdapter,'applyPackages',array($exceptionLedger,6,6,'payout-row-apply','checksum-refusal.json',$packagePath,array('phase_6_sha256'=>str_repeat('0',64))));
br_ok($checksumRefusal['failure_evidence']['refusal_classification']==='approval_artifact_invalid'&&is_file($checksumRefusal['report_path']),'pre-dispatch checksum refusal also retains durable evidence');

class BacklogRepo implements BadpoolExactMultiWalletPayoutRepository {
 public $rows=array(),$map=array(),$reconciles=0;
 function __construct($rows){foreach($rows as $row)$this->rows[$row['payout_id']]=$row;}
 function loadExactPayouts($ids){$out=array();foreach($ids as $id)if(isset($this->rows[$id]))$out[]=$this->rows[$id];return $out;}
 function reconcileExactWithTransactionIds($rows,$map){$this->reconciles++;$this->map=$map;foreach($rows as $r){$this->rows[$r['payout_id']]['completed']=1;$this->rows[$r['payout_id']]['tx']=$map[$r['payout_id']];}return count($rows);}
}
class BacklogInspector implements BadpoolReadOnlyWalletReadinessInspector {function inspect($op){return array('daemon_reachable'=>true,'readiness_reachable'=>true,'available_balance'=>'9999999.00000000');}}
class BacklogGateway implements BadpoolPerWalletOperationGateway {
 public $calls=0,$uncertain=false,$exception=false,$funded=true,$preflights=0;
 function preflightApprovedWalletOperation($op){$this->preflights++;return array('ready'=>$this->funded);}
 function sendApprovedWalletOperation($op,$capability){$this->calls++;$capability->claim($op['approval_checksum'],$op);if($this->exception)throw new Exception('offline wallet exception');return $this->uncertain?array('status'=>'uncertain'):array('status'=>'txid','txid'=>str_repeat(dechex($this->calls),64));}
}
function br_wallet($rows,$ids){$repo=new BacklogRepo($rows);$root=br_root();$journal=new BadpoolDurableMultiWalletSendJournal(br_root());$gateway=new BacklogGateway();$policy=function(){return array('configured'=>true,'value'=>'1.00000000','error'=>null);};$preflight=new BadpoolMultiWalletProductionPreflight($repo,new BacklogInspector(),new BadpoolMultiWalletPreflightReportStore($root),null,$policy);$report=$preflight->run($ids);$path=$report['retained_report']['path'];$options=array('preflight-report'=>$path,'preflight-report-checksum'=>hash_file('sha256',$path),'approval-checksum'=>$report['checksums']['approval_object']['value'],'ids'=>$ids);return array(new BadpoolMultiWalletSendApply($repo,$gateway,$journal,$root),$repo,$gateway,$journal,$report,$options);}
$rows=json_decode(file_get_contents(__DIR__.'/badpool_multi_lane_wallet_send_fixture.json'),true)['payouts'];
for($n=1;$n<=4;$n++){list($apply,$repo,$gateway,$journal,$report,$opts)=br_wallet($rows,array_slice(array(526,527,528,529),0,$n));$result=$apply->execute($opts);br_ok($result['status']==='pass'&&$result['journal_state']==='RECONCILED'&&$gateway->calls===$n&&count($repo->map)===$n,$n.' wallet exact scope passes end to end');$again=$apply->execute($opts);br_ok($again['reason']==='already_reconciled'&&$gateway->calls===$n,$n.' wallet reconciled retry skips send');}
foreach(array('missing','extra','duplicate','identity','amount') as $kind){list($apply,$repo,$gateway,$journal,$report,$opts)=br_wallet($rows,array(526,528,529));
 if($kind==='missing')array_pop($report['funding_readiness']);
 if($kind==='extra'){$extra=$report['funding_readiness'][0];$extra['operation_id']='wallet-op-'.str_repeat('f',24);$extra['wallet_binding_identity']='groestl';$report['funding_readiness'][]=$extra;}
 if($kind==='duplicate')$report['funding_readiness'][]=$report['funding_readiness'][0];
 if($kind==='identity')$report['funding_readiness'][0]['source_account_identity']='other';
 if($kind==='amount')$report['funding_readiness'][0]['projected_send_total']='0';
 file_put_contents($opts['preflight-report'],json_encode($report));$opts['preflight-report-checksum']=hash_file('sha256',$opts['preflight-report']);
 br_ok(br_throws(function()use($apply,$opts){$apply->execute($opts);})&&$gateway->calls===0,$kind.' funding evidence refuses before send');
}
// Synthetic production shape: seven selected payouts, three wallets, repeated destinations.
$backlog=array();foreach(array(0,0,0,2,2,3,3) as $i=>$template){$row=$rows[$template];$row['payout_id']=530+$i;$row['amount']=$i===0?'0.000000005':'1.234567895';$row['recipient']='same-exact-destination';$backlog[]=$row;}
$outside=$backlog[0];$outside['payout_id']=537;$backlog[]=$outside;
list($apply,$repo,$gateway,$journal,$report,$opts)=br_wallet($backlog,range(530,536));$ops=$report['state_machine_plan']['operations'];
br_ok(count($ops)===3&&$ops[0]['recipients']===array('same-exact-destination'=>'2.46913581'),'Scrypt duplicate destination sums per-payout rounded projections');
br_ok($ops[0]['payout_ids']===array(530,531,532),'all contributing payout IDs preserved');
br_ok(count($ops[1]['recipients'])===1&&count($ops[2]['recipients'])===1&&$ops[1]['wallet_binding_identity']!==$ops[2]['wallet_binding_identity'],'same destination remains isolated across wallet identities');
$inventory=$report['resolved_payout_inventory'];$sum=br_call($command,'walletSendDryrunDecimalAdd',array($inventory[0]['wallet_send_amount'],$inventory[1]['wallet_send_amount']));$sum=br_call($command,'walletSendDryrunDecimalAdd',array($sum,$inventory[2]['wallet_send_amount']));
br_ok($sum===$ops[0]['wallet_send_total'],'aggregate equals contributing projected sum and wallet total');
$result=$apply->execute($opts);br_ok($result['status']==='pass'&&count($repo->map)===7&&$gateway->calls===3,'530-536 scope reconciles every selected row using three fake sends');
br_ok($repo->rows[537]['completed']===0&&!isset($repo->map[537]),'unselected payout sharing destination is never added');
br_ok($repo->map[530]===$repo->map[531]&&$repo->map[530]!==$repo->map[533],'contributing payouts get their wallet txid');
$j=$journal->load($result['approval_checksum']);br_ok(count($j['payout_evidence'])===7&&$j['payout_evidence'][0]['amount']==='0.000000005'&&$j['payout_evidence'][0]['wallet_send_amount']==='0.00000001','durable journal retains raw amounts and individual projections');
foreach(array('ownership','funding') as $bad){list($apply,$repo,$gateway,$journal,$report,$opts)=br_wallet($backlog,range(530,536));if($bad==='ownership')$repo->rows[530]['batch_state']='HOLD';else{$report['funding_readiness'][0]['funding_status']='insufficient';file_put_contents($opts['preflight-report'],json_encode($report));$opts['preflight-report-checksum']=hash_file('sha256',$opts['preflight-report']);}br_ok(br_throws(function()use($apply,$opts){$apply->execute($opts);})&&$gateway->calls===0,'530-536 invalid '.$bad.' remains fail closed');}
foreach(array('uncertain','exception') as $kind){list($apply,$repo,$gateway,$journal,$report,$opts)=br_wallet($backlog,range(530,536));$gateway->$kind=true;$held=$apply->execute($opts);$again=$apply->execute($opts);br_ok($held['journal_state']==='HOLD_MANUAL_RECOVERY'&&$again['status']==='hold'&&$gateway->calls===1,'wallet '.$kind.' blocks every retry');}
list($apply,$repo,$gateway,$journal,$report,$opts)=br_wallet($backlog,range(530,536));$approval=$report['approval_object'];$approval['human_approved']=true;$plan=(new BadpoolMultiWalletApprovalPlanner($repo))->build($approval);$journal->prepare($plan);foreach($plan['operations'] as $op){$journal->startAttempt($plan['approval_checksum'],$op['operation_id']);$journal->recordTxid($plan['approval_checksum'],$op['operation_id'],str_repeat('a',64));}$gateway->funded=false;$restart=$apply->execute($opts);
br_ok($restart['journal_state']==='RECONCILED'&&$gateway->calls===0&&$gateway->preflights===0&&count($repo->map)===7,'txid-returned restart only reconciles with depleted funding, never sends');
$inventory213=array();foreach(array(0=>201,3=>4,2=>8) as $template=>$count)for($i=0;$i<$count;$i++){$row=$rows[$template];$row['payout_id']=6000+count($inventory213);$row['amount']='1.234567895';$row['recipient']='shared-backlog-destination';$inventory213[]=$row;}
list($apply,$repo,$gateway,$journal,$report,$opts)=br_wallet($inventory213,range(6000,6212));
br_ok(array_map('count',array_column($report['state_machine_plan']['operations'],'payout_ids'))===array(201,4,8),'213-row scope partitions into exact reported Scrypt, Skein and Yescrypt counts');
$result=$apply->execute($opts);br_ok($result['journal_state']==='RECONCILED'&&count($repo->map)===213&&$gateway->calls===3,'213-row synthetic backlog structurally supported without Groestl');
foreach($roots as $p)br_clean($p);
if($failures){echo 'FAIL backlog repair ('.$checks." checks)\n";foreach($failures as $f)echo ' - '.$f."\n";exit(1);}echo 'PASS backlog repair ('.$checks." checks)\n";
