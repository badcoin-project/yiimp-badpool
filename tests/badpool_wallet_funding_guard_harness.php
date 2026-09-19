<?php
function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}
require_once dirname(__DIR__).'/web/yaamp/core/backend/BadpoolWalletFundingGuard.php';
$fail=array(); function ok($v,$m){global $fail;if(!$v)$fail[]=$m;}
function funding($balance,$send,$reserve,$configured=true){return BadpoolWalletFundingGuard::evaluate($balance,$send,array('configured'=>$configured,'value'=>$reserve,'error'=>'fixture'));}
$greater=funding('100.00000000','20.12345678','10'); ok($greater['funding_classification']==='PASS / WALLET FUNDING SUFFICIENT','greater balance');
$equal=funding('30.12345678','20.12345678','10');ok($equal['reserve_preserved']&&$equal['projected_post_send_balance']==='10','equal balance');
$reserveHold=funding('25','20','10');ok(!$reserveHold['reserve_preserved']&&$reserveHold['sufficient_for_send'],'reserve-only hold');
$below=funding('19.99999999','20','0');ok(!$below['sufficient_for_send']&&$below['funding_classification']==='HOLD / WALLET FUNDING INSUFFICIENT','below payout');
$rpcFail=funding(false,'20','1');ok(!$rpcFail['wallet_balance_read_success']&&$rpcFail['funding_classification']==='HOLD / WALLET BALANCE UNAVAILABLE','rpc failure');
$malformed=funding('1e9','20','1');ok(!$malformed['wallet_balance_read_success'],'malformed balance');
$missing=funding('100','20',null,false);ok($missing['funding_classification']==='HOLD / WALLET RESERVE POLICY UNCONFIGURED','missing reserve');
$badReserve=funding('100','20','-1');ok($badReserve['funding_classification']==='HOLD / WALLET RESERVE POLICY MALFORMED','negative reserve');
$zero=funding('20','20','0');ok($zero['reserve_preserved'],'explicit zero reserve');
ok(BadpoolWalletFundingGuard::add('99999999999999999999.99999999','0.00000001')==='100000000000000000000','large exact add');
ok(BadpoolWalletFundingGuard::subtract('57728046.75500000','4331.75610541')==='57723714.99889459','exact projected balance');
$src=file_get_contents(dirname(__DIR__).'/web/yaamp/commands/BadpoolGuardCommand.php');
ok(strpos($src,"'wallet-funding-preflight'")!==false,'preflight registered');
ok(strpos($src,'walletSendBuildReadOnlyPackage(false)')!==false,'preflight reuses send projection');
ok(strpos($src,'if (count($ids) !== count(array_unique($ids)))')!==false,'duplicate payout IDs refused');
ok(strpos($src,"parseCsvIds(\$this->guard->getOption('selected-payout-ids'))")!==false,'preflight uses selected payout parser authority');
$apply=substr($src,strpos($src,'private function walletSendApplyReport'),strpos($src,'private function parseWalletSendApplyOptions')-strpos($src,'private function walletSendApplyReport'));
$read=strpos($apply,'walletFundingCheck');$send=strpos($apply,'badpoolGuardedSendmanyApply');ok($read!==false&&$send!==false&&$read<$send,'fresh funding read precedes send');
ok(strpos($apply,"funding_classification'] !== 'PASS / WALLET FUNDING SUFFICIENT'")<$send,'funding hold precedes send');
ok(strpos($apply,'beginTransaction()')>$send,'no DB mutation before funding/send');
$rpc=file_get_contents(dirname(__DIR__).'/web/yaamp/core/rpc/wallet-rpc.php');ok(strpos($rpc,"getbalance('*',1)")!==false,'confirmed spendable primitive');
if($fail){echo "Badpool wallet funding guard harness FAILED\n";foreach($fail as$f)echo" - $f\n";exit(1);}echo"Badpool wallet funding guard harness passed\n";
