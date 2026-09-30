<?php
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolMultiWalletProductionPreflight.php');

$checks=0;$failures=array();$roots=array();
function ca_ok($value,$message){global$checks,$failures;$checks++;if(!$value)$failures[]=$message;}
function ca_root($label){global$roots;$root=sys_get_temp_dir().DIRECTORY_SEPARATOR.'badpool-cookie-isolation-'.$label.'-'.bin2hex(random_bytes(5));mkdir($root,0770,true);$roots[]=$root;return$root;}
function ca_clean($path){if(is_link($path)){unlink($path);return;}if(!is_dir($path))return;foreach(scandir($path)as$file)if($file!=='.'&&$file!=='..'){$child=$path.DIRECTORY_SEPARATOR.$file;if(is_dir($child)&&!is_link($child))ca_clean($child);else unlink($child);}rmdir($path);}
function ca_lane($config,$datadir){$base=(new BadpoolLivePaymentLaneRegistry())->get('live-scrypt-v1')->toArray();$base['rpc_config_identity']=$config;$base['wallet_datadir_identity']=$datadir;return new BadpoolLivePaymentLaneConfiguration($base);}
function ca_operation($lane){return array('lane_id'=>$lane->laneId(),'coin_id'=>$lane->coinId(),'wallet_binding_identity'=>$lane->get('wallet_binding_identity'),'source_account_identity'=>$lane->get('wallet_source_account'),'rpc_config_identity'=>$lane->get('rpc_config_identity'),'wallet_datadir_identity'=>$lane->get('wallet_datadir_identity'));}

$root=ca_root('wallet');$datadir=$root.DIRECTORY_SEPARATOR.'wallet';mkdir($datadir);$config=$root.DIRECTORY_SEPARATOR.'pool.conf';file_put_contents($config,"rpcport=9332\n");$cookie='__cookie__:top-secret-cookie-value';file_put_contents($datadir.DIRECTORY_SEPARATOR.'.cookie',$cookie."\n");$lane=ca_lane($config,$datadir);$calls=array();
$runner=function($argv)use(&$calls){$calls[]=$argv;return array('status'=>0,'stdout'=>$argv[7]==='getbalance'?"55111.53081165\n":"{}\n",'stderr'=>'','timed_out'=>false);};
$transport=new BadpoolReadOnlyWalletCliTransport(1,$runner);$inspector=new BadpoolConfiguredReadOnlyWalletInspector($transport,new BadpoolLivePaymentLaneRegistry(array($lane)));$result=$inspector->inspect(ca_operation($lane));
ca_ok($result['daemon_reachable']&&$result['readiness_reachable']&&$result['available_balance']==='55111.53081165','native CLI readiness succeeds without PHP cookie access');
ca_ok(count($calls)===4&&array_column($calls,7)===array('getnetworkinfo','getblockchaininfo','getwalletinfo','getbalance'),'only fixed readiness methods are invoked');
ca_ok(strpos(json_encode($calls),$cookie)===false&&strpos(json_encode($calls),'top-secret-cookie-value')===false,'cookie content never enters process arguments');
ca_ok(strpos(json_encode($result),$cookie)===false&&strpos(json_encode($result),'top-secret-cookie-value')===false,'cookie content is absent from inspector output');

class CookieIsolationRepo implements BadpoolExactMultiWalletPayoutRepository{private$row;function __construct($row){$this->row=$row;}function loadExactPayouts($ids){return$ids===array(526)?array($this->row):array();}function reconcileExactWithTransactionIds($rows,$map){throw new RuntimeException('mutation forbidden');}}
$fixture=json_decode(file_get_contents(dirname(__FILE__).'/badpool_multi_lane_wallet_send_fixture.json'),true);$row=$fixture['payouts'][0];$row['rpc_config_identity']=$config;$row['wallet_datadir_identity']=$datadir;
$store=new BadpoolMultiWalletPreflightReportStore(ca_root('report'));$preflight=new BadpoolMultiWalletProductionPreflight(new CookieIsolationRepo($row),$inspector,$store,new BadpoolLivePaymentLaneRegistry(array($lane)),function(){return array('configured'=>true,'value'=>'1000','error'=>null);});$report=$preflight->run(array(526));$retained=file_get_contents($report['retained_report']['path']);
ca_ok(strpos($retained,$cookie)===false&&strpos($retained,'top-secret-cookie-value')===false,'cookie content is absent from retained report');
ca_ok(strpos(json_encode($report['checksums']),$cookie)===false&&strpos(json_encode($report['checksums']),'top-secret-cookie-value')===false,'cookie content is absent from report checksums');

$throwing=new BadpoolReadOnlyWalletCliTransport(1,function()use($cookie){throw new RuntimeException('runner exposed '.$cookie);});$failed=(new BadpoolConfiguredReadOnlyWalletInspector($throwing,new BadpoolLivePaymentLaneRegistry(array($lane))))->inspect(ca_operation($lane));ca_ok(!$failed['readiness_reachable']&&$failed['reason']==='Read-only wallet readiness failed closed.'&&strpos(json_encode($failed),$cookie)===false,'credential-free fixed failure hides runner details');
$source=file_get_contents(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolMultiWalletProductionPreflight.php');ca_ok(strpos($source,'/.cookie')===false&&strpos($source,'chmod')===false&&strpos($source,'chgrp')===false,'transport neither reads nor widens cookie permissions');

foreach($roots as$path)ca_clean($path);if($failures){echo"FAIL read-only wallet cookie isolation harness ($checks checks)\n - ".implode("\n - ",$failures)."\n";exit(1);}echo"PASS read-only wallet cookie isolation harness ($checks checks)\n";
