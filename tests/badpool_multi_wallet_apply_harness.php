<?php
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolMultiWalletSendApply.php');
if(!function_exists('arraySafeVal')){function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}}
$checks=0;$failures=array();$roots=array();
function ap_ok($v,$m){global$checks,$failures;$checks++;if(!$v)$failures[]=$m;}
function ap_throws($f,$contains=''){try{$f();return false;}catch(Exception$e){return$contains===''||strpos($e->getMessage(),$contains)!==false;}}
function ap_root($n){global$roots;$p=sys_get_temp_dir().'/badpool-apply-'.$n.'-'.bin2hex(random_bytes(4));mkdir($p,0770,true);$roots[]=$p;return$p;}
function ap_clean($p){if(is_link($p)){unlink($p);return;}if(!is_dir($p))return;foreach(scandir($p)as$f)if($f!=='.'&&$f!=='..'){$c=$p.'/'.$f;if(is_dir($c)&&!is_link($c))ap_clean($c);else unlink($c);}rmdir($p);}
function ap_rows(){return json_decode(file_get_contents(dirname(__FILE__).'/badpool_multi_lane_wallet_send_fixture.json'),true)['payouts'];}
class ApplyRepo implements BadpoolExactMultiWalletPayoutRepository{public$rows=array(),$calls=0,$maps=array();function __construct($rows){foreach($rows as$r)$this->rows[$r['payout_id']]=$r;}function loadExactPayouts($ids){$o=array();foreach($ids as$id)if(isset($this->rows[$id]))$o[]=$this->rows[$id];return$o;}function reconcileExactWithTransactionIds($rows,$map){$this->calls++;$this->maps=$map;foreach($rows as$r){$this->rows[$r['payout_id']]['completed']=1;$this->rows[$r['payout_id']]['tx']=$map[$r['payout_id']];}return count($rows);}}
class ApplyInspector implements BadpoolReadOnlyWalletReadinessInspector{public$balance='999999.00000000';function inspect($op){return array('daemon_reachable'=>true,'readiness_reachable'=>true,'available_balance'=>$this->balance,'balance_scope'=>'exact','balance_semantics'=>'decimal');}}
function ap_options($path,$report){return BadpoolMultiWalletSendApply::parseOptions(array('--preflight-report='.$path,'--preflight-report-checksum='.hash_file('sha256',$path),'--approval-checksum='.$report['checksums']['approval_object']['value'],'--selected-payout-ids=526,527,528,529','--operator-confirms-multi-wallet-send='.BadpoolMultiWalletSendApply::CONFIRMATION,'--format=json'));}

$command=file_get_contents(dirname(__FILE__).'/../web/yaamp/commands/BadpoolGuardCommand.php');
ap_ok(strpos($command,"case 'multi-wallet-send-apply':")!==false,'apply action exists');
ap_ok(ap_throws(function(){BadpoolMultiWalletSendApply::parseOptions(array());},'Missing required'),'explicit arguments required');
ap_ok(ap_throws(function(){BadpoolMultiWalletSendApply::parseOptions(array('--selected-payout-ids=527,526'));}),'missing contract rejected before unordered scope');
$base=array('--preflight-report=/x','--preflight-report-checksum='.str_repeat('a',64),'--approval-checksum='.str_repeat('b',64),'--selected-payout-ids=526,527,528,529','--operator-confirms-multi-wallet-send='.BadpoolMultiWalletSendApply::CONFIRMATION,'--format=json');
ap_ok(ap_throws(function()use($base){$x=$base;$x[3]='--selected-payout-ids=527,526,528,529';BadpoolMultiWalletSendApply::parseOptions($x);},'strictly ascending'),'reordered IDs rejected');
ap_ok(ap_throws(function()use($base){$x=$base;$x[3]='--selected-payout-ids=526,527,527,529';BadpoolMultiWalletSendApply::parseOptions($x);},'Duplicate'),'duplicate IDs rejected');
ap_ok(ap_throws(function()use($base){$x=$base;$x[4]='--operator-confirms-multi-wallet-send=yes';BadpoolMultiWalletSendApply::parseOptions($x);},'Exact operator'),'exact confirmation required');
ap_ok(ap_throws(function()use($base){$x=$base;$x[]='--format=json';BadpoolMultiWalletSendApply::parseOptions($x);},'Duplicate'),'duplicate arguments rejected');
ap_ok(ap_throws(function()use($base){$x=$base;$x[]='--coin-id=1267';BadpoolMultiWalletSendApply::parseOptions($x);},'Unknown'),'broad or unknown aliases rejected');

$reportRoot=ap_root('reports');$journalRoot=ap_root('journals');$repo=new ApplyRepo(ap_rows());$inspector=new ApplyInspector();$store=new BadpoolMultiWalletPreflightReportStore($reportRoot);$preflight=new BadpoolMultiWalletProductionPreflight($repo,$inspector,$store,null,function(){return array('configured'=>true,'value'=>'1000.00000000','error'=>null);});$report=$preflight->run(array(526,527,528,529));$path=$report['retained_report']['path'];
$sent=array();$runner=function($argv)use(&$sent){$sent[]=$argv;return array('status'=>0,'stdout'=>str_repeat(dechex(10+count($sent)-1),64)."\n",'stderr'=>'','timed_out'=>false);};$transport=new BadpoolFixedWalletSendmanyTransport($runner);$gateway=new BadpoolProductionPerWalletOperationGateway($inspector,$transport,function(){return array('configured'=>true,'value'=>'1000.00000000','error'=>null);});$journal=new BadpoolDurableMultiWalletSendJournal($journalRoot);$apply=new BadpoolMultiWalletSendApply($repo,$gateway,$journal,$reportRoot);$options=ap_options($path,$report);
$preChecksum=$options['approval-checksum'];$execution=$report['approval_object'];$execution['human_approved']=true;$executionChecksum=BadpoolMultiWalletApprovalPlanner::checksum($execution);
ap_ok($preChecksum!==$executionChecksum,'execution approval checksum changes');
$only=$execution;$only['human_approved']=false;ap_ok($only===$report['approval_object'],'execution approval flips only human_approved');
ap_ok($executionChecksum===BadpoolMultiWalletApprovalPlanner::checksum($execution),'execution approval checksum deterministic');
$result=$apply->execute($options);
ap_ok($result['status']==='pass'&&$result['journal_state']==='RECONCILED','all-four apply reconciles');
ap_ok(count($sent)===4,'exactly four wallet sends');
ap_ok($repo->calls===1&&count($repo->maps)===4,'single exact reconciliation after all txids');
ap_ok(is_file($journal->path($executionChecksum)),'journal keyed by execution approval');
ap_ok($result['journal_path']===$journal->path($executionChecksum),'journal path reported');
foreach($sent as$i=>$argv){ap_ok(array_slice($argv,0,5)===array('/usr/bin/sudo','-n','-u','badcoin','/opt/badcoin/mainnet/bin/badcoin-cli'),'fixed send boundary '.$i);ap_ok($argv[7]==='sendmany','only sendmany method '.$i);ap_ok(preg_match('/^\{"[^"]+":(?:0|[1-9][0-9]*)\.[0-9]{8}\}$/D',$argv[9])===1,'exact raw decimal destination '.$i);}
ap_ok($sent[0][8]==='pool-scrypt'&&$sent[1][8]==='pool-groestl'&&$sent[2][8]==='pool-yescrypt'&&$sent[3][8]==='pool-skein','fixed source accounts');
ap_ok(count(array_unique(array_values($repo->maps)))===4,'one txid per wallet');
ap_ok($repo->maps[526]!==$repo->maps[527]&&$repo->maps[527]!==$repo->maps[529],'shared account payouts not merged');
ap_ok($result['do_not_retry']===true,'completed execution reports do not retry');
$again=$apply->execute($options);ap_ok($again['reason']==='already_reconciled'&&count($sent)===4,'reconciled journal never resends');

$outside=ap_root('outside').'/x.json';copy($path,$outside);$bad=$options;$bad['preflight-report']=$outside;$bad['preflight-report-checksum']=hash_file('sha256',$outside);ap_ok(ap_throws(function()use($apply,$bad){$apply->execute($bad);},'outside'),'path traversal/outside rejected');
if(function_exists('symlink')){$link=$reportRoot.'/link.json';@symlink($path,$link);$bad=$options;$bad['preflight-report']=$link;ap_ok(ap_throws(function()use($apply,$bad){$apply->execute($bad);},'non-symlink'),'report symlink rejected');}
$bad=$options;$bad['preflight-report-checksum']=str_repeat('0',64);ap_ok(ap_throws(function()use($apply,$bad){$apply->execute($bad);},'SHA256'),'file SHA mismatch rejected');
function ap_variant($reportRoot,$report,$change){$p=$reportRoot.'/variant-'.bin2hex(random_bytes(3)).'.json';$change($report);file_put_contents($p,json_encode($report)."\n");return array($p,$report);}
foreach(array(
	'wrong schema'=>function(&$r){$r['schema']='wrong';},'non PASS'=>function(&$r){$r['status']='hold';},'human approved retained'=>function(&$r){$r['human_approved']=true;},'unsafe send evidence'=>function(&$r){$r['wallet_sends']=true;},'funding changed'=>function(&$r){$r['funding_readiness'][0]['funding_status']='insufficient';}
)as$name=>$change){list($p,$v)=ap_variant($reportRoot,$report,$change);$bad=$options;$bad['preflight-report']=$p;$bad['preflight-report-checksum']=hash_file('sha256',$p);ap_ok(ap_throws(function()use($apply,$bad){$apply->execute($bad);}),$name.' rejected');}
$repo2=new ApplyRepo(ap_rows());$repo2->rows[527]['recipient']='changed';$apply2=new BadpoolMultiWalletSendApply($repo2,$gateway,new BadpoolDurableMultiWalletSendJournal(ap_root('changed')),$reportRoot);ap_ok(ap_throws(function()use($apply2,$options){$apply2->execute($options);}), 'fresh recipient change rejected before journal');ap_ok(count(glob(ap_root('empty-check').'/*.json'))===0,'test environment has no incidental journals');
$low=new ApplyInspector();$low->balance='100.00000000';$lowGateway=new BadpoolProductionPerWalletOperationGateway($low,$transport,function(){return array('configured'=>true,'value'=>'1000.00000000','error'=>null);});$apply3=new BadpoolMultiWalletSendApply(new ApplyRepo(ap_rows()),$lowGateway,new BadpoolDurableMultiWalletSendJournal(ap_root('funding')),$reportRoot);ap_ok(ap_throws(function()use($apply3,$options){$apply3->execute($options);},'funding'),'fresh 1000 BAD reserve evaluated');
$capPlan=(new BadpoolMultiWalletApprovalPlanner(new ApplyRepo(ap_rows())))->build($execution);$cap=new BadpoolWalletOperationCapability($executionChecksum,$capPlan['operations'][0]);ap_ok(ap_throws(function()use($gateway,$cap,$capPlan){$gateway->sendApprovedWalletOperation($capPlan['operations'][1],$cap);},'binding'),'wrong capability refused before transport');
$guard=dirname(__FILE__).'/../web/yaamp/core/rpc/wallet-send-guard.php';ap_ok(hash_file('sha256',$guard)==='15d367873f673d04354695eba1ac6407752bf3789fd08629d3dfc1ba6ce8af80','hard wallet-send guard byte invariant');
$coord=file_get_contents(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolLivePaymentCoordinator.php');ap_ok(strpos($coord,'BadpoolProductionPerWalletOperationGateway')===false,'recurring coordinator cannot access send gateway');
foreach($roots as$r)ap_clean($r);
if($failures){echo"FAIL multi-wallet apply harness ($checks checks)\n - ".implode("\n - ",$failures)."\n";exit(1);}echo"PASS multi-wallet apply harness ($checks checks)\n";
