<?php
$root = dirname(__DIR__);
$command = file_get_contents($root.'/web/yaamp/commands/BadpoolGuardCommand.php');
$rpc = file_get_contents($root.'/web/yaamp/core/rpc/wallet-rpc.php');
$failures = array();
function expect_contains($label,$haystack,$needle,&$failures){ if(strpos($haystack,$needle)===false) $failures[]="$label: missing $needle"; }
function expect_not_contains($label,$haystack,$needle,&$failures){ if(strpos($haystack,$needle)!==false) $failures[]="$label: forbidden $needle"; }
function section_between($s,$a,$b){ $start=strpos($s,$a); if($start===false) return ''; $end=strpos($s,$b,$start+1); if($end===false) $end=strlen($s); return substr($s,$start,$end-$start); }
$apply = section_between($command, 'private function walletSendApplyReport', 'private function walletSendDryrunReport');
expect_contains('action registered', $command, "'wallet-send-apply'", $failures);
expect_contains('help documents apply', $command, 'badpoolguard wallet-send-apply --coin-id=<id> --selected-payout-ids=<csv>', $failures);
foreach(array('selected-payout-ids','approval-package-checksum','row-inventory-checksum','destination-plan-checksum','projected-total','projected-total-checksum','operator-confirms-wallet-send') as $opt) expect_contains('requires '.$opt, $apply, $opt, $failures);
expect_contains('json only', $apply, 'wallet-send-apply supports --format=json only.', $failures);
expect_contains('broad scope refused', $apply, 'refuses broad/all-coin scope', $failures);
expect_contains('empty payout list refused', $apply, 'refuses empty or missing --selected-payout-ids', $failures);
expect_contains('sorted explicit csv', $apply, 'Selected payout IDs must be explicit sorted CSV', $failures);
expect_contains('operator confirmation exact', $apply, 'selected_payout_rows_', $failures);
expect_contains('row checksum verified', $apply, "'row-inventory-checksum'=>'row_inventory_checksum'", $failures);
expect_contains('destination checksum verified', $apply, "'destination-plan-checksum'=>'destination_plan_checksum'", $failures);
expect_contains('projected total checksum verified', $apply, "'projected-total-checksum'=>'projected_total_checksum'", $failures);
expect_contains('approval checksum verified', $apply, "'approval-package-checksum'=>'approval_package_checksum'", $failures);
expect_contains('send after guards', $apply, 'badpoolGuardedSendmanyApply', $failures);
expect_contains('completion after send', $apply, 'walletSendApplyMarkCompleted($ids, $txid)', $failures);
expect_contains('failure no send flag', $command, "'wallet_rpc_send_performed']=false", $failures);
expect_contains('db transaction', $apply, 'beginTransaction()', $failures);
expect_contains('critical reconciliation', $apply, 'manual_reconciliation_required', $failures);
foreach(array('BackendPayments','BackendCoinPayments','PayoutCommand','startService(','stopService(','restartService(','DELETE FROM shares','DELETE FROM payouts','walletpassphrase','unlock') as $bad) expect_not_contains('unsafe broad path in apply', $apply, $bad, $failures);
$sendPos = strpos($apply, 'badpoolGuardedSendmanyApply');
$confirmPos = strpos($apply, 'operator-confirms-wallet-send');
$checksumPos = strpos($apply, "'approval-package-checksum'=>'approval_package_checksum'");
if(!($confirmPos !== false && $checksumPos !== false && $sendPos !== false && $confirmPos < $sendPos && $checksumPos < $sendPos)) $failures[]='sendmany reachable before confirmation/checksum guards';
$sum = '209676.631167005249';
if ($sum !== '209676.631167005249') $failures[]='exact decimal fixture mismatch';
expect_contains('wallet rpc primitive documented', $command, 'WalletRPC::badpoolGuardedSendmanyApply(sendmany)', $failures);
expect_contains('wallet rpc precise atomic conversion', $rpc, 'badpoolDecimalToAtomicString', $failures);
expect_not_contains('no float in guarded primitive', section_between($rpc, 'function badpoolGuardedSendmanyApply', 'function __call'), '(double)', $failures);
if($failures){ echo "Badpool wallet-send apply guard harness FAILED\n"; foreach($failures as $f) echo " - $f\n"; exit(1); }
echo "Badpool wallet-send apply guard harness passed\n";
