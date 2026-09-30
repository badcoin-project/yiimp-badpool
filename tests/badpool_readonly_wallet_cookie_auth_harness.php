<?php
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolMultiWalletProductionPreflight.php');

$checks=0;$failures=array();$roots=array();
function ca_ok($value,$message){global$checks,$failures;$checks++;if(!$value)$failures[]=$message;}
function ca_root($label){global$roots;$root=sys_get_temp_dir().DIRECTORY_SEPARATOR.'badpool-cookie-'.$label.'-'.bin2hex(random_bytes(5));mkdir($root,0770,true);$roots[]=$root;return$root;}
function ca_clean($path){if(is_link($path)){unlink($path);return;}if(!is_dir($path))return;foreach(scandir($path)as$file)if($file!=='.'&&$file!=='..'){$child=$path.DIRECTORY_SEPARATOR.$file;if(is_dir($child)&&!is_link($child))ca_clean($child);else unlink($child);}rmdir($path);}
function ca_lane($config,$datadir){$base=(new BadpoolLivePaymentLaneRegistry())->get('live-scrypt-v1')->toArray();$base['rpc_config_identity']=$config;$base['wallet_datadir_identity']=$datadir;return new BadpoolLivePaymentLaneConfiguration($base);}
function ca_operation($lane){return array('lane_id'=>$lane->laneId(),'coin_id'=>$lane->coinId(),'wallet_binding_identity'=>$lane->get('wallet_binding_identity'),'source_account_identity'=>$lane->get('wallet_source_account'),'rpc_config_identity'=>$lane->get('rpc_config_identity'),'wallet_datadir_identity'=>$lane->get('wallet_datadir_identity'));}
function ca_inspector($lane,$transport){return new BadpoolConfiguredReadOnlyWalletInspector(1,$transport,new BadpoolLivePaymentLaneRegistry(array($lane)));}
function ca_write_config($path,$extra=''){file_put_contents($path,"rpcport=9332\n".$extra);}

$root=ca_root('valid');$datadir=$root.DIRECTORY_SEPARATOR.'wallet';mkdir($datadir);$config=$root.DIRECTORY_SEPARATOR.'pool.conf';ca_write_config($config);$cookie='__cookie__:top-secret-cookie-value';file_put_contents($datadir.DIRECTORY_SEPARATOR.'.cookie',$cookie."\n");$lane=ca_lane($config,$datadir);$calls=array();
$transport=function($auth,$method,$params,$decimal)use(&$calls,$cookie){$calls[]=array($method,$params);if($auth['user'].':'.$auth['password']!==$cookie)throw new RuntimeException('auth mismatch');if($method==='getbalance')return'55111.53081165';return array();};
$inspector=ca_inspector($lane,$transport);$result=$inspector->inspect(ca_operation($lane));
ca_ok($result['daemon_reachable']&&$result['readiness_reachable']&&$result['available_balance']==='55111.53081165','valid native cookie authentication succeeds');
ca_ok(array_column($calls,0)===array('getnetworkinfo','getblockchaininfo','getwalletinfo','getbalance'),'fixed read-only methods are called in order');
ca_ok($calls[3][1]===array('pool-scrypt',1),'exact source account balance primitive is preserved');
ca_ok(strpos(json_encode($result),$cookie)===false&&strpos(json_encode($result),'top-secret-cookie-value')===false,'cookie value is absent from returned result');

$throwing=ca_inspector($lane,function()use($cookie){throw new RuntimeException('transport exposed '.$cookie);});$failed=$throwing->inspect(ca_operation($lane));
ca_ok(!$failed['readiness_reachable']&&$failed['reason']==='Read-only wallet readiness failed closed.'&&strpos(json_encode($failed),$cookie)===false,'cookie value is absent from exception-derived output');

class CookieRepo implements BadpoolExactMultiWalletPayoutRepository{private$row;function __construct($row){$this->row=$row;}function loadExactPayouts($ids){return$ids===array(526)?array($this->row):array();}function reconcileExactWithTransactionIds($rows,$map){throw new RuntimeException('mutation forbidden');}}
$fixture=json_decode(file_get_contents(dirname(__FILE__).'/badpool_multi_lane_wallet_send_fixture.json'),true);$row=$fixture['payouts'][0];$row['rpc_config_identity']=$config;$row['wallet_datadir_identity']=$datadir;
$store=new BadpoolMultiWalletPreflightReportStore(ca_root('report'));$preflight=new BadpoolMultiWalletProductionPreflight(new CookieRepo($row),$inspector,$store,new BadpoolLivePaymentLaneRegistry(array($lane)),function(){return array('configured'=>true,'value'=>'1000','error'=>null);});$report=$preflight->run(array(526));$retained=file_get_contents($report['retained_report']['path']);
ca_ok(strpos($retained,$cookie)===false&&strpos($retained,'top-secret-cookie-value')===false,'cookie value is absent from retained report');
ca_ok(strpos(json_encode($report['checksums']),$cookie)===false&&strpos(json_encode($report['checksums']),'top-secret-cookie-value')===false,'cookie value is absent from checksums');

unlink($datadir.DIRECTORY_SEPARATOR.'.cookie');ca_ok(!ca_inspector($lane,$transport)->inspect(ca_operation($lane))['readiness_reachable'],'missing cookie fails closed without alternate auth');
foreach(array('malformed'=>'not-separated','empty-user'=>':password','empty-password'=>'user:')as$case=>$value){file_put_contents($datadir.DIRECTORY_SEPARATOR.'.cookie',$value);ca_ok(!ca_inspector($lane,$transport)->inspect(ca_operation($lane))['readiness_reachable'],$case.' cookie fails closed');}
file_put_contents($datadir.DIRECTORY_SEPARATOR.'.cookie',$cookie);

$target=$root.DIRECTORY_SEPARATOR.'cookie-target';file_put_contents($target,$cookie);unlink($datadir.DIRECTORY_SEPARATOR.'.cookie');$cookieLinked=function_exists('symlink')&&@symlink($target,$datadir.DIRECTORY_SEPARATOR.'.cookie');ca_ok(!$cookieLinked||!ca_inspector($lane,$transport)->inspect(ca_operation($lane))['readiness_reachable'],'symlinked cookie is rejected');if($cookieLinked)unlink($datadir.DIRECTORY_SEPARATOR.'.cookie');file_put_contents($datadir.DIRECTORY_SEPARATOR.'.cookie',$cookie);
$realDatadir=$root.DIRECTORY_SEPARATOR.'real-wallet';mkdir($realDatadir);file_put_contents($realDatadir.DIRECTORY_SEPARATOR.'.cookie',$cookie);$linkedDatadir=$root.DIRECTORY_SEPARATOR.'linked-wallet';$datadirLinked=function_exists('symlink')&&@symlink($realDatadir,$linkedDatadir);if($datadirLinked){$linkedLane=ca_lane($config,$linkedDatadir);ca_ok(!ca_inspector($linkedLane,$transport)->inspect(ca_operation($linkedLane))['readiness_reachable'],'symlinked datadir is rejected');}else ca_ok(strpos(file_get_contents(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolMultiWalletProductionPreflight.php'),"is_link(\$expected['datadir'])")!==false,'symlinked datadir rejection is enforced');

$relativeLane=ca_lane($config,'relative-wallet');ca_ok(!ca_inspector($relativeLane,$transport)->inspect(ca_operation($relativeLane))['readiness_reachable'],'relative datadir is rejected');
$mismatch=ca_operation($lane);$mismatch['wallet_datadir_identity']=$realDatadir;ca_ok(!ca_inspector($lane,$transport)->inspect($mismatch)['readiness_reachable'],'mismatched configured datadir is rejected');
ca_write_config($config,"rpcconnect=192.0.2.10\n");ca_ok(!ca_inspector($lane,$transport)->inspect(ca_operation($lane))['readiness_reachable'],'unsafe RPC endpoint is rejected');
ca_write_config($config,"rpcport=70000\n");ca_ok(!ca_inspector($lane,$transport)->inspect(ca_operation($lane))['readiness_reachable'],'invalid RPC port is rejected');
ca_write_config($config,"rpcuser=explicit-user\nrpcpassword=explicit-password\n");unlink($datadir.DIRECTORY_SEPARATOR.'.cookie');$explicit=ca_inspector($lane,function($auth,$method){if($auth['user']!=='explicit-user'||$auth['password']!=='explicit-password')throw new RuntimeException('bad explicit auth');return$method==='getbalance'?'1':array();})->inspect(ca_operation($lane));ca_ok($explicit['readiness_reachable'],'safe explicit RPC credentials remain a secondary authentication mode');

$reflection=new ReflectionClass('BadpoolConfiguredReadOnlyWalletInspector');$public=array();foreach($reflection->getMethods(ReflectionMethod::IS_PUBLIC)as$method)$public[]=$method->getName();sort($public);ca_ok($public===array('__construct','inspect'),'preflight exposes no generic RPC or wallet-send method');
$rpc=$reflection->getMethod('rpc');if(PHP_VERSION_ID<80100)$rpc->setAccessible(true);foreach(array('sendmany','sendtoaddress','sendrawtransaction','walletpassphrase','send','sendfrom','move','walletlock','importprivkey','dumpprivkey','settxfee','backupwallet')as$method){$refused=false;try{$rpc->invoke($inspector,array(),$method,array(),false);}catch(Exception$e){$refused=true;}ca_ok($refused,$method.' is refused by the private allowlist');}
ca_ok(!method_exists($inspector,'sendmany')&&!method_exists($inspector,'sendtoaddress')&&!method_exists($inspector,'sendrawtransaction')&&!method_exists($inspector,'walletpassphrase'),'mutation methods are unreachable from the preflight object graph');

foreach($roots as$path)ca_clean($path);if($failures){echo"FAIL read-only wallet cookie auth harness ($checks checks)\n - ".implode("\n - ",$failures)."\n";exit(1);}echo"PASS read-only wallet cookie auth harness ($checks checks)\n";
