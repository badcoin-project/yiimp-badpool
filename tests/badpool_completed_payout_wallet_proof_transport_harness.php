<?php
require_once(dirname(__DIR__).'/web/yaamp/core/backend/BadpoolCompletedPayoutWalletProof.php');

$fail=array();$checks=0;
function proof_cli_ok($condition,$message){global$fail,$checks;$checks++;if(!$condition)$fail[]=$message;}
function proof_cli_result($stdout,$status=0,$stderr='',$timedOut=false){return array('status'=>$status,'stdout'=>$stdout,'stderr'=>$stderr,'timed_out'=>$timedOut);}
function proof_cli_json($txid,$extra=array()){return json_encode(array_merge(array('txid'=>$txid,'amount'=>'-1.00000000','confirmations'=>1),$extra));}

$txid=str_repeat('a',64);$registry=new BadpoolLivePaymentLaneRegistry();$calls=array();
$reader=new BadpoolCompletedPayoutWalletProof($registry,7,function($argv,$timeout)use(&$calls,$txid){$calls[]=array($argv,$timeout);return proof_cli_result(proof_cli_json(strtoupper($txid)));});
$expected=array(
	1266=>array('live-yescrypt-v1','yescrypt','pool-yescrypt','/etc/badcoin/pool-yescrypt.conf','/var/lib/badcoin-pool-yescrypt'),
	1267=>array('live-scrypt-v1','scrypt','pool-scrypt','/etc/badcoin/pool-scrypt.conf','/var/lib/badcoin-pool-scrypt'),
	1268=>array('live-skein-v1','skein','pool-skein','/etc/badcoin/pool-skein.conf','/var/lib/badcoin-pool-skein'),
	1269=>array('live-groestl-v1','groestl','pool-groestl','/etc/badcoin/pool-groestl.conf','/var/lib/badcoin-pool-groestl'),
);
foreach($expected as$coin=>$identity){
	$context=$reader->contextForCoin($coin);
	proof_cli_ok($context['supported']===true&&$context['coin_id']===$coin&&$context['lane_id']===$identity[0],$coin.' lane context unsupported');
	proof_cli_ok($context['wallet_binding_identity']===$identity[1]&&$context['source_account_identity']===$identity[2],$coin.' wallet/source identity mismatch');
	proof_cli_ok($context['conf']===$identity[3]&&$context['datadir']===$identity[4],$coin.' config/datadir identity mismatch');
	proof_cli_ok($context['rpc_methods']===array('gettransaction')&&$context['human_approved_wallet_send_eligible']===true,$coin.' proof method/eligibility mismatch');
	$transaction=$reader->getTransaction($coin,$txid);$argv=$calls[count($calls)-1][0];
	proof_cli_ok(array_slice($argv,0,5)===array('/usr/bin/sudo','-n','-u','badcoin','/opt/badcoin/mainnet/bin/badcoin-cli'),$coin.' fixed sudo/user/CLI boundary changed');
	proof_cli_ok($argv===array('/usr/bin/sudo','-n','-u','badcoin','/opt/badcoin/mainnet/bin/badcoin-cli','-conf='.$identity[3],'-datadir='.$identity[4],'gettransaction',$txid),$coin.' argv is not exact');
	proof_cli_ok($calls[count($calls)-1][1]===7&&$transaction['txid']===$txid,$coin.' timeout or lowercase txid normalization changed');
}
proof_cli_ok($reader->contextForCoin(1270)===array('supported'=>false,'reason'=>'unsupported_wallet_proof_context'),'SHA256d was not rejected');
proof_cli_ok($reader->contextForCoin(9999)===array('supported'=>false,'reason'=>'unsupported_wallet_proof_context'),'unknown coin was not rejected');
proof_cli_ok($reader->contextForCoin('1267junk')===array('supported'=>false,'reason'=>'unsupported_wallet_proof_context'),'malformed coin ID was coerced into a supported lane');

$ineligibleData=$registry->get('live-scrypt-v1')->toArray();$ineligibleData['human_approved_wallet_send_enabled']=false;
$ineligible=new BadpoolCompletedPayoutWalletProof(new BadpoolLivePaymentLaneRegistry(array(new BadpoolLivePaymentLaneConfiguration($ineligibleData))),1,function(){throw new RuntimeException('must not execute');});
proof_cli_ok($ineligible->contextForCoin(1267)['supported']===false,'human-approved wallet-send eligibility was not required');
try{$ineligible->getTransaction(1267,$txid);proof_cli_ok(false,'ineligible lane executed');}catch(Exception$e){proof_cli_ok($e->getMessage()==='Unsupported completed-payout wallet proof context.','ineligible lane did not fail closed');}

$public=array();foreach((new ReflectionClass('BadpoolCompletedPayoutWalletProof'))->getMethods(ReflectionMethod::IS_PUBLIC)as$m)if($m->class==='BadpoolCompletedPayoutWalletProof')$public[]=$m->name;sort($public);
proof_cli_ok($public===array('__construct','contextForCoin','getTransaction'),'transport exposes a generic method or arbitrary arguments');

$before=count($calls);try{$reader->getTransaction(1267,'not-a-txid');proof_cli_ok(false,'malformed txid accepted');}catch(Exception$e){proof_cli_ok(count($calls)===$before&&$e->getMessage()==='Wallet-proof transaction ID is malformed.','malformed txid reached process execution');}
$failureCases=array(
	'nonzero exit'=>proof_cli_result('',2,'',false),
	'timeout'=>proof_cli_result('',0,'',true),
	'stderr'=>proof_cli_result('{}',0,'rpcpassword=do-not-leak',false),
	'empty stdout'=>proof_cli_result('',0,'',false),
	'malformed JSON'=>proof_cli_result('{broken',0,'',false),
	'missing txid'=>proof_cli_result('{"amount":"-1.00000000"}',0,'',false),
	'mismatched txid'=>proof_cli_result(proof_cli_json(str_repeat('b',64)),0,'',false),
);
foreach($failureCases as$name=>$result){
	$failureReader=new BadpoolCompletedPayoutWalletProof($registry,1,function()use($result){return$result;});
	try{$failureReader->getTransaction(1267,$txid);proof_cli_ok(false,$name.' passed');}
	catch(Exception$e){proof_cli_ok(strpos($e->getMessage(),'do-not-leak')===false&&stripos($e->getMessage(),'rpcpassword')===false,$name.' leaked credential/process detail');}
}
$valid=new BadpoolCompletedPayoutWalletProof($registry,1,function()use($txid){return proof_cli_result('{"txid":"'.$txid.'","amount":-2.50000000,"confirmations":0}');});
$value=$valid->getTransaction(1267,$txid);
proof_cli_ok($value['txid']===$txid&&$value['amount']==='-2.50000000'&&array_key_exists('confirmations',$value),'valid response did not preserve exact debit token and confirmations presence');

if($fail){echo "Badpool completed-payout wallet proof transport harness FAILED ($checks checks)\n - ".implode("\n - ",$fail)."\n";exit(1);}
echo "Badpool completed-payout wallet proof transport harness passed ($checks checks)\n";
