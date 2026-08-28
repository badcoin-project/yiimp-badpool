<?php

$failures = array();
function legacy_guard_expect($ok, $message) { global $failures; if (!$ok) $failures[] = $message; }
function legacy_guard_section($source, $start, $end) {
	$from = strpos($source, $start); $to = strpos($source, $end, $from + strlen($start));
	if ($from === false) return '';
	return $to === false ? substr($source, $from) : substr($source, $from, $to - $from);
}

$root = dirname(__FILE__).'/..';
require_once($root.'/web/yaamp/core/backend/BadpoolManagedPayoutGuard.php');
$payment = file_get_contents($root.'/web/yaamp/core/backend/payment.php');
$payout = file_get_contents($root.'/web/yaamp/commands/PayoutCommand.php');
$guarded = file_get_contents($root.'/web/yaamp/commands/BadpoolGuardCommand.php');
$runner = file_get_contents($root.'/web/yaamp/core/backend/BadpoolPaymentBatchRunner.php');
$adapter = file_get_contents($root.'/web/yaamp/core/backend/BadpoolPaymentBatchPhaseAdapter.php');
$proof = file_get_contents($root.'/tests/badpool_wallet_proof_closeout_guard_harness.php');

legacy_guard_expect(BadpoolManagedPayoutGuard::coinIds() === array(1266,1267,1268,1269,1270), 'managed coin scope changed');
foreach (range(1266,1270) as $id) legacy_guard_expect(BadpoolManagedPayoutGuard::isManagedCoinId($id), "managed coin $id is not guarded");
legacy_guard_expect(!BadpoolManagedPayoutGuard::isManagedCoinId(1265), 'non-BadPool coin 1265 was blocked');
legacy_guard_expect(!BadpoolManagedPayoutGuard::isManagedCoinId(1271), 'non-BadPool coin 1271 was blocked');

$coinPayment = legacy_guard_section($payment, 'function BackendCoinPayments', 'function ');
legacy_guard_expect(strpos($coinPayment, 'refuseLegacyOperation($coin->id') < strpos($coinPayment, 'new WalletRPC'), 'BackendCoinPayments guard does not precede WalletRPC construction');
foreach (array('sendmany(', 'sendtoaddress(', '$payout->completed = 1', '$payout->tx = $tx', '$payout->delete()') as $surface)
	legacy_guard_expect(strpos($coinPayment, $surface) !== false, "legacy backend investigation lost expected surface: $surface");

$check = legacy_guard_section($payout, 'public function checkPayouts', 'function checkCoinSwaps');
legacy_guard_expect(strpos($check, 'refuseLegacyOperation($coin->id') < strpos($check, 'new WalletRPC'), 'payout check guard does not precede wallet access');
foreach (array('$payout->completed = 1', '$payout->tx = $txid') as $surface)
	legacy_guard_expect(strpos($check, $surface) !== false, "payout check investigation lost mutation surface: $surface");
$redo = legacy_guard_section($payout, 'protected function redoTransaction', 'protected function checkPayoutsConfirmations');
legacy_guard_expect(strpos($redo, 'refuseLegacyOperation($coin->id') < strpos($redo, 'new WalletRPC'), 'redotx guard does not precede wallet construction');
foreach (array('sendmany(', '$p->completed = 1', '$p->tx = $new_txid', "tx='orphaned'") as $surface)
	legacy_guard_expect(strpos($redo, $surface) !== false, "redotx investigation lost send/mutation surface: $surface");

legacy_guard_expect(substr_count($guarded, '$remote->badpoolGuardedSendmanyApply(') === 1, 'guarded send primitive must have exactly one BadpoolGuardCommand call site');
legacy_guard_expect(strpos($guarded, 'walletSendApplyMarkCompleted($ids,$txid') !== false, 'guarded apply completion path missing');
legacy_guard_expect(strpos($runner, "'wallet_boundary'=>'blocked_human_required'") !== false, 'batch runner wallet boundary is not blocked');
legacy_guard_expect(strpos($runner, "'suggested_command'=>\$completedReconciliation?null") !== false, 'completed reconciliation suggests a command');
foreach (array('wallet-send-apply','sendmany(','sendtoaddress(','BackendPayments','BackendCoinPayments') as $surface)
	legacy_guard_expect(strpos($adapter, $surface) === false, "batch adapter exposes unguarded wallet surface: $surface");
legacy_guard_expect(strpos($proof, "'read_only'=>true") !== false && strpos($proof, "'wallet_sends'=>false") !== false, 'wallet-proof-closeout read-only assertions missing');
legacy_guard_expect(strpos($proof, 'c64b4c1dec4f4a619b70b0f27c133aaac7a40c25f0556b581fbdf3b775c8d4c2') !== false, '519-style historical completed fixture missing');

if ($failures) { foreach ($failures as $failure) fwrite(STDERR, "FAIL: $failure\n"); exit(1); }
echo "PASS: legacy BadPool payout wallet-send and mutation paths fail closed\n";
