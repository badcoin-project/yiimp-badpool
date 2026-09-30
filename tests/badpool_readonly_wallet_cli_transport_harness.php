<?php
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolMultiWalletProductionPreflight.php');

$checks=0;$failures=array();
function cli_ok($value,$message){global$checks,$failures;$checks++;if(!$value)$failures[]=$message;}
function cli_throws($callable){try{$callable();return false;}catch(Exception$e){return true;}}
function cli_identity($lane){return array('config'=>$lane->get('rpc_config_identity'),'datadir'=>$lane->get('wallet_datadir_identity'),'source_account'=>$lane->get('wallet_source_account'),'wallet'=>$lane->get('wallet_binding_identity'),'coin_id'=>$lane->coinId());}
function cli_result($stdout,$status=0,$stderr='',$timedOut=false){return array('status'=>$status,'stdout'=>$stdout,'stderr'=>$stderr,'timed_out'=>$timedOut);}
function cli_clean($path){if(!is_dir($path))return;foreach(scandir($path)as$file)if($file!=='.'&&$file!=='..'){$child=$path.DIRECTORY_SEPARATOR.$file;if(is_dir($child))cli_clean($child);else unlink($child);}rmdir($path);}

$calls=array();$runner=function($argv,$timeout)use(&$calls){$calls[]=$argv;$method=$argv[7];return cli_result($method==='getbalance'?"55111.530811650000\n":"{}\n");};
$transport=new BadpoolReadOnlyWalletCliTransport(7,$runner);$registry=new BadpoolLivePaymentLaneRegistry();
foreach(array('live-scrypt-v1','live-groestl-v1','live-yescrypt-v1','live-skein-v1')as$laneId){$identity=cli_identity($registry->get($laneId));$transport->getNetworkInfo($identity);$transport->getBlockchainInfo($identity);$transport->getWalletInfo($identity);cli_ok($transport->getBalance($identity)==='55111.530811650000',$laneId.' preserves exact decimal balance');}
cli_ok(count($calls)===16,'four wallets perform four independent CLI reads each');
$expected=array(
	array('/etc/badcoin/pool-scrypt.conf','/var/lib/badcoin-pool-scrypt','pool-scrypt'),
	array('/etc/badcoin/pool-groestl.conf','/var/lib/badcoin-pool-groestl','pool-groestl'),
	array('/etc/badcoin/pool-yescrypt.conf','/var/lib/badcoin-pool-yescrypt','pool-yescrypt'),
	array('/etc/badcoin/pool-skein.conf','/var/lib/badcoin-pool-skein','pool-skein'),
);
foreach($expected as$i=>$lane){$base=$i*4;foreach(array('getnetworkinfo','getblockchaininfo','getwalletinfo','getbalance')as$j=>$method){$argv=$calls[$base+$j];cli_ok(array_slice($argv,0,5)===array('/usr/bin/sudo','-n','-u','badcoin','/opt/badcoin/mainnet/bin/badcoin-cli'),'sudo, non-interactive flag, user, and CLI binary are fixed');cli_ok($argv[5]==='-conf='.$lane[0]&&$argv[6]==='-datadir='.$lane[1]&&$argv[7]===$method,'authoritative config, datadir, and read method are exact');if($method==='getbalance')cli_ok(array_slice($argv,8)===array($lane[2],'1'),'getbalance source account and minconf are exact');else cli_ok(count($argv)===8,$method.' accepts no extra arguments');}}

$reflection=new ReflectionClass('BadpoolReadOnlyWalletCliTransport');$public=array();foreach($reflection->getMethods(ReflectionMethod::IS_PUBLIC)as$method)$public[]=$method->getName();sort($public);cli_ok($public===array('__construct','getBalance','getBlockchainInfo','getNetworkInfo','getWalletInfo'),'transport exposes four named reads and no generic execution method');
$execute=$reflection->getMethod('execute');if(PHP_VERSION_ID<80100)$execute->setAccessible(true);$identity=cli_identity($registry->get('live-scrypt-v1'));
foreach(array('sendmany','sendtoaddress','sendrawtransaction','walletpassphrase','getnewaddress')as$method)cli_ok(cli_throws(function()use($execute,$transport,$identity,$method){$execute->invoke($transport,$identity,$method,array());}),$method.' is rejected');
cli_ok(cli_throws(function()use($execute,$transport,$identity){$execute->invoke($transport,$identity,'getnetworkinfo',array('--arbitrary'));}),'arbitrary extra CLI arguments are rejected');

$metacharIdentity=$identity;$metacharIdentity['config']='/tmp/pool;touch PWNED.conf';$metacharIdentity['datadir']='/tmp/wallet$(touch PWNED)';$metaCalls=array();$meta=new BadpoolReadOnlyWalletCliTransport(1,function($argv)use(&$metaCalls){$metaCalls[]=$argv;return cli_result('{}');});$meta->getNetworkInfo($metacharIdentity);cli_ok($metaCalls[0][5]==='-conf=/tmp/pool;touch PWNED.conf'&&$metaCalls[0][6]==='-datadir=/tmp/wallet$(touch PWNED)'&&count($metaCalls[0])===8,'shell metacharacters remain inert single argv elements');

$failureCases=array(
	'non-zero CLI status'=>cli_result('{}',1),
	'sudo non-interactive failure'=>cli_result('',1,'sudo: a password is required'),
	'unexpected stderr'=>cli_result('{}',0,'warning'),
	'timeout'=>cli_result('{}',0,'',true),
	'empty output'=>cli_result(''),
);
foreach($failureCases as$label=>$response){$failing=new BadpoolReadOnlyWalletCliTransport(1,function()use($response){return$response;});cli_ok(cli_throws(function()use($failing,$identity){$failing->getNetworkInfo($identity);}),$label.' fails closed');}
foreach(array('', '[]', '{broken', 'null', '"text"')as$bad){$malformed=new BadpoolReadOnlyWalletCliTransport(1,function()use($bad){return cli_result($bad);});cli_ok(cli_throws(function()use($malformed,$identity){$malformed->getWalletInfo($identity);}),'malformed JSON fails closed');}
foreach(array('', '-1','+1','1e3','1.2.3','  ')as$bad){$malformed=new BadpoolReadOnlyWalletCliTransport(1,function()use($bad){return cli_result($bad);});cli_ok(cli_throws(function()use($malformed,$identity){$malformed->getBalance($identity);}),'malformed balance fails closed');}

$source=file_get_contents(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolMultiWalletProductionPreflight.php');
cli_ok(strpos($source,'proc_open($argv')!==false&&strpos($source,"'bypass_shell'=>true")!==false,'process API receives an argv array with shell bypass');
cli_ok(strpos($source,'/.cookie')===false&&strpos($source,"file_get_contents(\$path)")===false,'PHP CLI transport never opens a wallet cookie');
$command=file_get_contents(dirname(__FILE__).'/../web/yaamp/commands/BadpoolGuardCommand.php');cli_ok(strpos($command,'new BadpoolReadOnlyWalletCliTransport()')!==false&&strpos($command,'new BadpoolConfiguredReadOnlyWalletInspector($transport)')!==false,'production preflight explicitly injects CLI transport');
cli_ok(strpos($source,'sendmany')===false&&strpos($source,'sendtoaddress')===false&&strpos($source,'sendrawtransaction')===false&&strpos($source,'walletpassphrase')===false,'production transport contains no wallet mutation primitive');

$root=sys_get_temp_dir().DIRECTORY_SEPARATOR.'badpool-cli-identity-'.bin2hex(random_bytes(5));$wallet=$root.DIRECTORY_SEPARATOR.'wallet';mkdir($root,0770,true);mkdir($wallet);$config=$root.DIRECTORY_SEPARATOR.'pool.conf';file_put_contents($config,"rpcport=9332\n");$laneData=$registry->get('live-scrypt-v1')->toArray();$laneData['rpc_config_identity']=$config;$laneData['wallet_datadir_identity']=$wallet;$testLane=new BadpoolLivePaymentLaneConfiguration($laneData);$testRegistry=new BadpoolLivePaymentLaneRegistry(array($testLane));$identityCalls=array();$identityTransport=new BadpoolReadOnlyWalletCliTransport(1,function($argv)use(&$identityCalls){$identityCalls[]=$argv;return cli_result($argv[7]==='getbalance'?'1.00000000':'{}');});$inspector=new BadpoolConfiguredReadOnlyWalletInspector($identityTransport,$testRegistry);$operation=array('lane_id'=>$testLane->laneId(),'coin_id'=>$testLane->coinId(),'wallet_binding_identity'=>$testLane->get('wallet_binding_identity'),'source_account_identity'=>$testLane->get('wallet_source_account'),'rpc_config_identity'=>$config,'wallet_datadir_identity'=>$wallet);
cli_ok($inspector->inspect($operation)['readiness_reachable']===true,'exact configured operation identity reaches CLI reads');
foreach(array('lane_id'=>'unknown-lane','coin_id'=>1268,'wallet_binding_identity'=>'skein','source_account_identity'=>'pool-skein','rpc_config_identity'=>$config.'.other','wallet_datadir_identity'=>$wallet.DIRECTORY_SEPARATOR.'other')as$key=>$bad){$changed=$operation;$changed[$key]=$bad;$before=count($identityCalls);$observed=$inspector->inspect($changed);cli_ok(!$observed['readiness_reachable']&&count($identityCalls)===$before,$key.' mismatch fails before CLI invocation');}cli_clean($root);

if($failures){echo"FAIL read-only wallet CLI transport harness ($checks checks)\n - ".implode("\n - ",$failures)."\n";exit(1);}echo"PASS read-only wallet CLI transport harness ($checks checks)\n";
