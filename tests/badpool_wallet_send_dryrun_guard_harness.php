<?php
$root = dirname(__DIR__);
$commandPath = $root.'/web/yaamp/commands/BadpoolGuardCommand.php';
$contextPath = $root.'/web/yaamp/core/backend/BadpoolGuardContext.php';
$failures = array();
function expect_contains($label, $haystack, $needle, &$failures) { if (strpos($haystack, $needle) === false) $failures[] = "$label: missing expected text: $needle"; }
function expect_not_contains($label, $haystack, $needle, &$failures) { if (strpos($haystack, $needle) !== false) $failures[] = "$label: found forbidden text: $needle"; }
$command = is_file($commandPath) ? file_get_contents($commandPath) : '';
$context = is_file($contextPath) ? file_get_contents($contextPath) : '';
expect_contains('action registered', $command, "'wallet-send-dryrun'", $failures);
expect_contains('help documents command', $command, 'badpoolguard wallet-send-dryrun --coin-id=<id> --selected-payout-ids=<csv> --format=json', $failures);
expect_contains('selected payout option allowed', $context, "'selected-payout-ids'", $failures);
expect_contains('json required', $command, 'wallet-send-dryrun requires --format=json.', $failures);
expect_contains('coin scoped to Badpool 1267', $command, 'coin-id 1267 only', $failures);
expect_contains('non-empty selected payout ids', $command, 'wallet-send-dryrun requires non-empty --selected-payout-ids CSV', $failures);
expect_contains('duplicate payout IDs refused', $command, 'Duplicate selected payout IDs are refused.', $failures);
expect_contains('broad scope refused', $command, 'refuses broad/all-coin scope', $failures);
expect_contains('exact selected rows required', $command, 'Selected payout row count mismatch', $failures);
expect_contains('joins accounts', $command, 'INNER JOIN accounts A ON A.id=P.account_id', $failures);
expect_contains('joins coins', $command, 'INNER JOIN coins C ON C.id=P.idcoin', $failures);
expect_contains('account coin matches payout coin', $command, 'Joined account coinid must equal payout idcoin', $failures);
expect_contains('recipient from username', $command, "'recipient' => (string)$".'row[\'username\']', $failures);
expect_contains('projected sendmany', $command, "'projected_send_method'] = 'sendmany'", $failures);
expect_contains('row inventory checksum', $command, 'wallet_send_row_inventory_sha256', $failures);
expect_contains('destination plan checksum', $command, 'wallet_send_destination_plan_sha256', $failures);
foreach (array('wallet_rpc_send_performed','db_mutations','payout_rows_marked_completed','withdraw_rows_created','backend_loops_run','retry_delete_behavior','wallet_send_apply_available') as $flag) {
	expect_contains("blocked flag $flag", $command, "['$flag'] = false", $failures);
}
$start = strpos($command, 'private function walletSendDryrunReport');
$end = strpos($command, 'private function payoutRowApprovalPackageReport', $start);
$dryrun = ($start === false || $end === false) ? '' : substr($command, $start, $end - $start);
foreach (array('sendmany(', 'sendtoaddress', 'walletpassphrase', 'unlock', 'UPDATE payouts', 'INSERT INTO withdraws', 'DELETE FROM payouts', 'BackendPayments', 'BackendCoinPayments', 'service', 'createCommand()->update', 'createCommand()->insert', 'createCommand()->delete') as $forbidden) {
	expect_not_contains('wallet-send-dryrun mutation/send forbidden', $dryrun, $forbidden, $failures);
}
if (!empty($failures)) { echo "Badpool wallet-send dryrun guard harness FAILED\n"; foreach ($failures as $failure) echo " - $failure\n"; exit(1); }
echo "Badpool wallet-send dryrun guard harness passed\n";
