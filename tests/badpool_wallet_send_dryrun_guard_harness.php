<?php
$root = dirname(__DIR__);
$commandPath = $root.'/web/yaamp/commands/BadpoolGuardCommand.php';
$contextPath = $root.'/web/yaamp/core/backend/BadpoolGuardContext.php';
$failures = array();
function expect_contains($label, $haystack, $needle, &$failures) { if (strpos($haystack, $needle) === false) $failures[] = "$label: missing expected text: $needle"; }
function expect_not_contains($label, $haystack, $needle, &$failures) { if (strpos($haystack, $needle) !== false) $failures[] = "$label: found forbidden text: $needle"; }
function wallet_send_dryrun_exact_decimal_add_harness($a, $b) {
	$a = trim((string)$a); $b = trim((string)$b);
	if ($a === '') $a = '0'; if ($b === '') $b = '0';
	if (!preg_match('/^\d+(?:\.\d+)?$/', $a) || !preg_match('/^\d+(?:\.\d+)?$/', $b)) throw new Exception('invalid decimal');
	$ap = explode('.', $a, 2); $bp = explode('.', $b, 2);
	$af = isset($ap[1]) ? $ap[1] : ''; $bf = isset($bp[1]) ? $bp[1] : '';
	$scale = max(strlen($af), strlen($bf));
	$ad = ltrim($ap[0].str_pad($af, $scale, '0'), '0'); $bd = ltrim($bp[0].str_pad($bf, $scale, '0'), '0');
	if ($ad === '') $ad = '0'; if ($bd === '') $bd = '0';
	$carry = 0; $out = ''; $ai = strlen($ad) - 1; $bi = strlen($bd) - 1;
	while ($ai >= 0 || $bi >= 0 || $carry > 0) {
		$sum = $carry; if ($ai >= 0) $sum += ord($ad[$ai--]) - 48; if ($bi >= 0) $sum += ord($bd[$bi--]) - 48;
		$out = chr(48 + ($sum % 10)).$out; $carry = intdiv($sum, 10);
	}
	if ($scale > 0) { if (strlen($out) <= $scale) $out = str_pad($out, $scale + 1, '0', STR_PAD_LEFT); $out = substr($out, 0, -$scale).'.'.substr($out, -$scale); $out = rtrim(rtrim($out, '0'), '.'); }
	$out = ltrim($out, '0'); if ($out === '') return '0'; if ($out[0] === '.') $out = '0'.$out; return $out;
}
$command = is_file($commandPath) ? file_get_contents($commandPath) : '';
$context = is_file($contextPath) ? file_get_contents($contextPath) : '';
expect_contains('action registered', $command, "'wallet-send-dryrun'", $failures);
expect_contains('approval package action registered', $command, "'wallet-send-approval-package'", $failures);
expect_contains('help documents command', $command, 'badpoolguard wallet-send-dryrun --coin-id=<id> --selected-payout-ids=<csv> --format=json', $failures);
expect_contains('help documents approval package', $command, 'badpoolguard wallet-send-approval-package --coin-id=<id> --selected-payout-ids=<csv>', $failures);
expect_not_contains('approval package help must not accept operator confirmation input', $command, 'badpoolguard wallet-send-approval-package --coin-id=<id> --selected-payout-ids=<csv> --operator-confirms-wallet-send', $failures);
expect_contains('operator confirmation retained as future apply placeholder', $command, '--operator-confirms-wallet-send', $failures);
expect_contains('selected payout option allowed', $context, "'selected-payout-ids'", $failures);
expect_contains('json required', $command, 'wallet-send-dryrun requires --format=json.', $failures);
expect_contains('approval json required', $command, 'wallet-send-approval-package requires --format=json.', $failures);
expect_contains('coin scoped to Badpool 1267', $command, 'coin-id 1267 only', $failures);
expect_contains('non-empty selected payout ids', $command, 'wallet-send-dryrun requires non-empty --selected-payout-ids CSV', $failures);
expect_contains('approval non-empty selected payout ids', $command, 'wallet-send-approval-package requires non-empty --selected-payout-ids CSV', $failures);
expect_contains('duplicate payout IDs refused', $command, 'Duplicate selected payout IDs are refused.', $failures);
expect_contains('numeric selected payout ordering enforced', $command, 'sort($ids, SORT_NUMERIC);', $failures);
expect_contains('wallet-send-dryrun exact decimal helper present', $command, 'private function walletSendDryrunDecimalAdd', $failures);
expect_contains('wallet-send-dryrun decimal parts helper present', $command, 'private function walletSendDryrunDecimalParts', $failures);
expect_contains('broad scope refused', $command, 'refuses broad/all-coin scope', $failures);
expect_contains('exact selected rows required', $command, 'Selected payout row count mismatch', $failures);
expect_contains('joins accounts', $command, 'INNER JOIN accounts A ON A.id=P.account_id', $failures);
expect_contains('joins coins', $command, 'INNER JOIN coins C ON C.id=P.idcoin', $failures);
expect_contains('account coin matches payout coin', $command, 'Joined account coinid must equal payout idcoin', $failures);
expect_contains('recipient from username', $command, "'recipient' => (string)$".'row[\'username\']', $failures);
expect_contains('projected sendmany', $command, "'projected_send_method'] = 'sendmany'", $failures);
expect_contains('row inventory checksum', $command, 'wallet_send_row_inventory_sha256', $failures);
expect_contains('destination plan checksum', $command, 'wallet_send_destination_plan_sha256', $failures);
expect_contains('approval row inventory checksum', $command, 'row_inventory_checksum', $failures);
expect_contains('approval destination plan checksum', $command, 'destination_plan_checksum', $failures);
expect_contains('approval package checksum', $command, 'approval_package_checksum', $failures);
expect_contains('approval exact total sample', $command, 'selected_payout_rows_', $failures);
expect_contains('approval checksum uses deterministic scope binding', $command, "'approval_package_type', 'scope_binding', 'selected_payout_ids', 'row_inventory_checksum'", $failures);
expect_contains('approval package scope binding present', $command, "'source' => 'walletSendBuildReadOnlyPackage'", $failures);
expect_contains('approval apply shape includes projected total option', $command, "'--projected-total='."."$"."report['projected_total']", $failures);
expect_contains('approval apply shape includes projected total checksum option', $command, "'--projected-total-checksum='.arraySafeVal("."$"."report['projected_total_checksum'], 'value')", $failures);
expect_not_contains('approval checksum must not bind full context scope', $command, "'approval_package_type', 'scope', 'selected_payout_ids', 'row_inventory_checksum'", $failures);
foreach (array('wallet_rpc_send_performed','db_mutations','payout_rows_marked_completed','withdraw_rows_created','backend_loops_run','retry_delete_behavior','wallet_send_apply_available') as $flag) {
	expect_contains("blocked flag $flag", $command, "['$flag'] = false", $failures);
}
$start = strpos($command, 'private function walletSendBuildReadOnlyPackage');
$end = strpos($command, 'private function payoutRowApprovalPackageReport', $start);
$dryrun = ($start === false || $end === false) ? '' : substr($command, $start, $end - $start);
$exactSum = wallet_send_dryrun_exact_decimal_add_harness('209676.33670359474', '0.294463415249');
if ($exactSum !== '209676.631167009989') $failures[] = 'exact decimal addition mismatch: expected 209676.631167009989 got '.$exactSum;
$productionExactSum = wallet_send_dryrun_exact_decimal_add_harness('209676.33670359', '0.294463415249');
if ($productionExactSum !== '209676.631167005249') $failures[] = 'PR59 production exact total mismatch: expected 209676.631167005249 got '.$productionExactSum;
expect_contains('wallet-send-dryrun sums with exact helper', $dryrun, '$total = $this->walletSendDryrunDecimalAdd($total, $amount);', $failures);
expect_not_contains('wallet-send-dryrun must not use float decimalAdd for projected_total_amount', $dryrun, '$total = $this->decimalAdd($total, $amount);', $failures);
foreach (array('sendmany(', 'sendtoaddress', 'walletpassphrase', 'unlock', 'UPDATE payouts', 'INSERT INTO withdraws', 'DELETE FROM payouts', 'BackendPayments', 'BackendCoinPayments', 'startService(', 'stopService(', 'restartService(', 'createCommand()->update', 'createCommand()->insert', 'createCommand()->delete') as $forbidden) {
	expect_not_contains('wallet-send shared builder mutation/send forbidden', $dryrun, $forbidden, $failures);
}
$approvalStart = strpos($command, 'private function walletSendApprovalPackageReport');
$approvalEnd = strpos($command, 'private function walletSendBuildReadOnlyPackage', $approvalStart);
$approval = ($approvalStart === false || $approvalEnd === false) ? '' : substr($command, $approvalStart, $approvalEnd - $approvalStart);
foreach (array('does not send wallet funds','does not mutate DB','does not mark payouts completed','does not create withdraw rows','does not run backend loops','does not change services') as $warning) {
	expect_contains('approval warning '.$warning, $approval, $warning, $failures);
}
expect_contains('dryrun and approval share builder', $command, '$report = $this->walletSendBuildReadOnlyPackage(false);', $failures);
expect_contains('approval uses shared builder', $command, '$report = $this->walletSendBuildReadOnlyPackage(true);', $failures);
if (!empty($failures)) { echo "Badpool wallet-send dryrun guard harness FAILED\n"; foreach ($failures as $failure) echo " - $failure\n"; exit(1); }
echo "Badpool wallet-send dryrun guard harness passed\n";
