<?php
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolMultiWalletProductionPreflight.php');
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolGuardContext.php');

$fixture=json_decode(file_get_contents(dirname(__FILE__).'/badpool_multi_lane_wallet_send_fixture.json'),true);$checks=0;$failures=array();$roots=array();
function pf_ok($v,$m){global$checks,$failures;$checks++;if(!$v)$failures[]=$m;}
function pf_throws($f){try{$f();return false;}catch(Exception$e){return true;}}
function pf_root($label){global$roots;$p=sys_get_temp_dir().'/badpool-preflight-'.$label.'-'.bin2hex(random_bytes(5));mkdir($p,0770,true);$roots[]=$p;return$p;}
function pf_clean($p){if(is_link($p)){unlink($p);return;}if(!is_dir($p))return;foreach(scandir($p)as$f)if($f!=='.'&&$f!=='..'){$c=$p.'/'.$f;if(is_dir($c)&&!is_link($c))pf_clean($c);else unlink($c);}rmdir($p);}
function pf_rows($fixture){$r=array();foreach($fixture['payouts']as$row)$r[$row['payout_id']]=$row;return$r;}
class PreflightRepo implements BadpoolExactMultiWalletPayoutRepository{public$rows,$loads=0,$reconciles=0,$extra=false;function __construct($rows){$this->rows=$rows;}function loadExactPayouts($ids){$this->loads++;$out=array();foreach($ids as$id)if(isset($this->rows[$id]))$out[]=$this->rows[$id];if($this->extra)$out[]=$this->rows[529];return$out;}function reconcileExactWithTransactionIds($r,$m){$this->reconciles++;throw new RuntimeException('mutation forbidden');}}
class PreflightInspector implements BadpoolReadOnlyWalletReadinessInspector{public$calls=array(),$answers=array();function inspect($op){$w=$op['wallet_binding_identity'];$this->calls[]=$w;return isset($this->answers[$w])?$this->answers[$w]:array('daemon_reachable'=>true,'readiness_reachable'=>true,'available_balance'=>'999999.00000000','balance_scope'=>'exact source account '.$op['source_account_identity'],'balance_semantics'=>'fixture exact named account','reason'=>null);}}
function pf_system($rows,$label='ok'){$repo=new PreflightRepo($rows);$inspector=new PreflightInspector();$store=new BadpoolMultiWalletPreflightReportStore(pf_root($label));$reserve=function($coin){return array('configured'=>true,'value'=>'10.00000000','error'=>null);};$p=new BadpoolMultiWalletProductionPreflight($repo,$inspector,$store,null,$reserve);return array($p,$repo,$inspector,$store);}

$rows=pf_rows($fixture);$ids=array(526,527,528,529);
pf_ok(pf_throws(function(){BadpoolMultiWalletProductionPreflight::parseSelectedPayoutIds(null); }),'1 explicit IDs mandatory');
pf_ok(pf_throws(function(){BadpoolMultiWalletProductionPreflight::parseSelectedPayoutIds(''); }),'2 empty list rejected');
pf_ok(pf_throws(function(){BadpoolMultiWalletProductionPreflight::parseSelectedPayoutIds('526,x'); }),'3 malformed ID rejected');
pf_ok(pf_throws(function(){BadpoolMultiWalletProductionPreflight::parseSelectedPayoutIds('526,526'); }),'4 duplicate ID rejected');
pf_ok(BadpoolMultiWalletProductionPreflight::parseSelectedPayoutIds('526,527,528,529')===$ids,'5 exact CSV parsed');
$context=BadpoolGuardContext::fromArgs('multi-wallet-send-preflight',array('--selected-payout-ids=526,527,528,529','--format=json'));pf_ok($context->isValid()&&$context->getScope()['authority']==='explicit_payout_ids_only'&&!$context->isAllCoinsPreview(),'5b command context has no implicit coin scope');
$broad=BadpoolGuardContext::fromArgs('multi-wallet-send-preflight',array('--selected-payout-ids=526','--all-coins-preview','--format=json'));pf_ok(!$broad->isValid(),'5c broad scope option rejected');
list($p,$repo,$inspector,$store)=pf_system($rows,'main');$report=$p->run($ids);
pf_ok($report['status']==='pass'&&$report['classification']==='PASS','6 ready preflight passes');
pf_ok(array_column($report['resolved_payout_inventory'],'payout_id')===$ids,'7 exact inventory');
pf_ok($report['resolved_payout_inventory'][0]['lane_id']==='live-scrypt-v1','8 payout 526 Scrypt');
pf_ok($report['resolved_payout_inventory'][1]['lane_id']==='live-groestl-v1','9 payout 527 Groestl');
pf_ok($report['resolved_payout_inventory'][2]['lane_id']==='live-yescrypt-v1','10 payout 528 Yescrypt');
pf_ok($report['resolved_payout_inventory'][3]['lane_id']==='live-skein-v1','11 payout 529 Skein');
pf_ok($report['resolved_payout_inventory'][1]['account_id']===76&&$report['resolved_payout_inventory'][3]['account_id']===76,'12 account 76 overlap present');
pf_ok($report['state_machine_plan']['operations'][1]['operation_id']!==$report['state_machine_plan']['operations'][3]['operation_id'],'13 account overlap does not merge');
pf_ok($report['resolved_payout_inventory'][0]['recipient']==='BAD-scrypt-account-79','14 authoritative recipient');
pf_ok($report['resolved_payout_inventory'][3]['amount']==='57981.11592853001','15 raw amount exact');
pf_ok(array_column($report['resolved_payout_inventory'],'coin_id')===array(1267,1269,1266,1268),'16 exact coin ownership');
pf_ok(count(array_filter(array_column($report['lane_batch_ownership_evidence'],'batch_id')))===4,'17 exact batch membership');
pf_ok(count(array_filter(array_column($report['lane_batch_ownership_evidence'],'active_coordinator_match')))===4,'17b active coordinator ownership proven');
pf_ok(count(array_unique(array_column($report['lane_batch_ownership_evidence'],'batch_state')))==1&&$report['lane_batch_ownership_evidence'][0]['batch_state']==='READY_FOR_WALLET_APPROVAL','18 ready state required');
pf_ok(array_column($report['state_machine_plan']['operations'],'wallet_binding_identity')===array('scrypt','groestl','yescrypt','skein'),'19 four wallet partition');
pf_ok(array_column($report['state_machine_plan']['operations'],'sequence')===array(1,2,3,4),'20 operation ordering');
$operationIds=array_column($report['state_machine_plan']['operations'],'operation_id');pf_ok(count(array_unique($operationIds))===4,'21 operation IDs unique');
$report2=$p->run($ids);pf_ok(array_column($report2['state_machine_plan']['operations'],'operation_id')===$operationIds,'22 operation IDs deterministic');
pf_ok(array_column($report['state_machine_plan']['operations'],'payout_ids')===array(array(526),array(527),array(528),array(529)),'23 payout cannot migrate');
pf_ok(array_column($report['state_machine_plan']['operations'],'raw_total')===array('54111.530811649995','55875.65007076999','33063.28546626001','57981.11592853001'),'24 wallet raw totals');
pf_ok(array_column($report['state_machine_plan']['operations'],'wallet_send_total')===array('54111.53081165','55875.65007077','33063.28546626','57981.11592853'),'25 wallet projections');
pf_ok($report['state_machine_plan']['raw_total']==='201031.582277210005','26 global raw total');
pf_ok($report['state_machine_plan']['wallet_projected_total']==='201031.58227721','27 global projected total');
pf_ok(array_column($report['state_machine_plan']['operations'],'initial_state')===array('PENDING','PENDING','PENDING','PENDING'),'28 initial PENDING');
pf_ok($report['state_machine_plan']['journal_created']===false&&$report['state_machine_plan']['prepared_state_created']===false,'29 no PREPARED journal');
pf_ok(array_column($report['funding_readiness'],'configured_reserve')===array('10','10','10','10'),'30 reserve per wallet');
pf_ok(array_column($report['funding_readiness'],'funding_status')===array('sufficient','sufficient','sufficient','sufficient'),'31 sufficient funding');
pf_ok($inspector->calls===array('scrypt','groestl','yescrypt','skein','scrypt','groestl','yescrypt','skein'),'32 read-only inspector only');
pf_ok($repo->reconciles===0,'33 no DB reconciliation mutation');
pf_ok($report['db_mutations']===false&&$report['payout_mutations']===false,'34 no DB or payout mutation');
pf_ok($report['wallet_sends']===false&&$report['wallet_rpc_send_performed']===false,'35 no wallet send');
pf_ok($report['approval_object']['schema']==='badpool.wallet_send.multi_lane_approval.v1'&&$report['approval_object']['human_approved']===false,'36 proposed approval non-authorizing');
pf_ok(array_column($report['approval_object']['entries'],'payout_id')===$ids,'37 approval exact and sorted');
pf_ok($report['approval_object']['entries'][3]['expected_tx']===null&&$report['approval_object']['entries'][3]['expected_completed']===0,'38 expected payout state exact');
foreach(array('selected_payout_ids','resolved_payout_inventory','lane_batch_ownership','recipient_plan','wallet_partition','per_wallet_amount_plan','funding_readiness','approval_object')as$key)pf_ok(isset($report['checksums'][$key]['value'])&&preg_match('/^[a-f0-9]{64}$/',$report['checksums'][$key]['value'])&&strlen($report['checksums'][$key]['purpose'])>20,'checksum '.$key.' stable and purposed');
pf_ok(preg_match('/^[a-f0-9]{64}$/',$report['report_checksum']['value'])&&strpos($report['report_checksum']['purpose'],'never authorization')!==false,'47 complete report checksum');
pf_ok($report2['report_checksum']['value']===$report['report_checksum']['value'],'48 report checksum stable');
pf_ok(is_file($report['retained_report']['path'])&&strpos($report['retained_report']['path'],'badpool-preflight-main-')!==false,'49 retained JSON exists');
$retained=file_get_contents($report['retained_report']['path']);pf_ok(strpos($retained,'rpcpassword')===false&&strpos($retained,'rpcuser')===false,'50 retained report contains no credentials');
pf_ok($report['retained_report']['execution_journal']===false&&strpos($retained,'SEND_ATTEMPT_STARTED')!==false&&$report['send_attempt_started']===false,'51 retained report cannot be execution evidence');
pf_ok($report['state_machine_plan']['operations'][3]['rpc_config_identity']==='/etc/badcoin/pool-skein.conf'&&$report['state_machine_plan']['operations'][3]['wallet_datadir_identity']==='/var/lib/badcoin-pool-skein','52 Skein RPC identities exact');
pf_ok(!$report['apply_handler_available']&&in_array('multi-wallet-send-apply',$report['blocked_actions'],true),'53 no apply handler');
pf_ok(strpos($report['authorization'],'do not authorize wallet sends')!==false,'54 explicit non-authorization');

$missing=$rows;unset($missing[529]);list($q)=pf_system($missing,'missing');pf_ok(pf_throws(function()use($q,$ids){$q->run($ids); }),'55 unknown payout rejected');
$completed=$rows;$completed[526]['completed']=1;list($q)=pf_system($completed,'completed');pf_ok(pf_throws(function()use($q,$ids){$q->run($ids); }),'56 completed payout rejected');
$tx=$rows;$tx[526]['tx']=str_repeat('a',64);list($q)=pf_system($tx,'tx');pf_ok(pf_throws(function()use($q,$ids){$q->run($ids); }),'57 payout tx rejected');
$state=$rows;$state[529]['batch_state']='WAITING_PAYMENT_DELAY';list($q)=pf_system($state,'state');pf_ok(pf_throws(function()use($q,$ids){$q->run($ids); }),'58 old Skein waiting fixture rejected');
$wrong=$rows;$wrong[527]['coin_id']=1268;list($q)=pf_system($wrong,'coin');pf_ok(pf_throws(function()use($q,$ids){$q->run($ids); }),'59 coin mismatch rejected');
$wrong=$rows;$wrong[527]['wallet_binding_identity']='skein';list($q)=pf_system($wrong,'wallet');pf_ok(pf_throws(function()use($q,$ids){$q->run($ids); }),'60 wallet binding mismatch rejected');
$wrong=$rows;$wrong[527]['source_account_identity']='pool-skein';list($q)=pf_system($wrong,'source');pf_ok(pf_throws(function()use($q,$ids){$q->run($ids); }),'61 source account mismatch rejected');
list($q,$qr,$qi)=pf_system($rows,'insufficient');$qi->answers['skein']=array('daemon_reachable'=>true,'readiness_reachable'=>true,'available_balance'=>'1','reason'=>null);$hold=$q->run($ids);pf_ok($hold['status']==='hold'&&$hold['funding_readiness'][3]['funding_status']==='insufficient','62 insufficient funding HOLD');
list($q,$qr,$qi)=pf_system($rows,'unknown');$qi->answers['groestl']=array('daemon_reachable'=>false,'readiness_reachable'=>false,'available_balance'=>null,'reason'=>'daemon unavailable');$hold=$q->run($ids);pf_ok($hold['status']==='hold'&&$hold['funding_readiness'][1]['funding_status']==='unknown','63 unknown funding HOLD');
list($q,$qr)=pf_system($rows,'extra');$qr->extra=true;pf_ok(pf_throws(function()use($q,$ids){$q->run(array(526,527,528)); }),'64 unexpected payout returned rejected');
$registry=new BadpoolLivePaymentLaneRegistry();pf_ok(!$registry->get('live-sha256d-v1')->isPayoutPreparationCommissioned()&&!$registry->get('live-sha256d-v1')->isHumanApprovedWalletSendEligible(),'65 SHA256d excluded');
pf_ok(!$registry->get('live-scrypt-v1')->isWalletSendCommissioned()===false&&(!$registry->get('live-groestl-v1')->isWalletSendCommissioned())&&(!$registry->get('live-yescrypt-v1')->isWalletSendCommissioned())&&(!$registry->get('live-skein-v1')->isWalletSendCommissioned()),'66 recurring non-Scrypt lanes wallet-blocked');
$guard=file_get_contents(dirname(__FILE__).'/../web/yaamp/core/rpc/wallet-send-guard.php');pf_ok(strpos($guard,"'sendmany' => true")!==false&&strpos($guard,'runtime activation switch')!==false,'67 hard guard active');
$core=file_get_contents(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolMultiWalletProductionPreflight.php');pf_ok(strpos($core,'sendtoaddress')===false&&strpos($core,'sendmany')===false,'68 preflight has no send primitive');
$command=file_get_contents(dirname(__FILE__).'/../web/yaamp/commands/BadpoolGuardCommand.php');pf_ok(strpos($command,"case 'multi-wallet-send-preflight':")!==false&&strpos($command,"case 'multi-wallet-send-apply':")===false,'69 command wired without multi-wallet apply');
$coord=file_get_contents(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolLivePaymentCoordinator.php');pf_ok(strpos($coord,'BadpoolReadOnlyWalletReadinessInspector')===false&&strpos($coord,'sendApprovedWalletOperation')===false,'70 recurring coordinator has no wallet path');
$target=pf_root('link-target').'/report.json';file_put_contents($target,"{}\n");$linkRoot=pf_root('link-root');$linkStore=new BadpoolMultiWalletPreflightReportStore($linkRoot);$selected=BadpoolMultiWalletApprovalPlanner::checksum($ids);$link=$linkStore->path($selected);$linked=function_exists('symlink')&&@symlink($target,$link);pf_ok(!$linked||pf_throws(function()use($linkStore,$selected){$linkStore->write($selected,array('safe'=>true)); }),'71 symlink target refused');

foreach($roots as$r)pf_clean($r);if($failures){echo"FAIL multi-wallet production preflight harness ($checks checks)\n - ".implode("\n - ",$failures)."\n";exit(1);}echo"PASS multi-wallet production preflight harness ($checks checks)\n";
