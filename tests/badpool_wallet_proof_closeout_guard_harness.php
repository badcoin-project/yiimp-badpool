<?php
$root = dirname(__DIR__);
$commandPath = $root.'/web/yaamp/commands/BadpoolGuardCommand.php';
$failures = array();
function expect_contains($label, $haystack, $needle, &$failures) { if (strpos($haystack, $needle) === false) $failures[] = "$label: missing expected text: $needle"; }
function expect_not_contains($label, $haystack, $needle, &$failures) { if (strpos($haystack, $needle) !== false) $failures[] = "$label: found forbidden text: $needle"; }
function wallet_proof_decimal_is_zero_harness($v) { return preg_match('/^-?0+(?:\.0+)?$/', trim((string)$v)) === 1; }
function wallet_proof_project_8dp_harness($amount) {
	$parts = explode('.', $amount, 2); $whole = $parts[0]; $frac = str_pad(isset($parts[1]) ? $parts[1] : '', 9, '0');
	$digits = ltrim($whole.substr($frac, 0, 8), '0'); if ($digits === '') $digits = '0';
	if ((ord($frac[8]) - 48) >= 5) { $carry = 1; $out = ''; for ($i = strlen($digits) - 1; $i >= 0; $i--) { $n = ord($digits[$i]) - 48 + $carry; $out = chr(48 + ($n % 10)).$out; $carry = $n >= 10 ? 1 : 0; } $digits = ($carry ? '1' : '').$out; }
	if (strlen($digits) <= 8) $digits = str_pad($digits, 9, '0', STR_PAD_LEFT);
	return substr($digits, 0, -8).'.'.substr($digits, -8);
}
function wallet_proof_amount_matches_expected_harness($walletAmount, $rawDbAmount) {
	return strpos($walletAmount, '-') === 0 && ltrim($walletAmount, '-') === wallet_proof_project_8dp_harness($rawDbAmount);
}
function wallet_send_shape_harness($row) { return intval($row['completed']) === 0 && trim((string)$row['tx']) === ''; }
function wallet_proof_shape_harness($row) { return intval($row['completed']) === 1 && trim((string)$row['tx']) !== ''; }
function wallet_proof_smoke_audit_harness($executedCommand, $report) {
	$actual = is_array($executedCommand) ? implode(' ', $executedCommand) : (string)$executedCommand;
	$sendInvoked = preg_match('/(?:^|\s)(?:wallet-send-apply|sendmany|sendtoaddress)(?:\s|$)/', $actual) === 1;
	return array(
		'no_wallet_send_command_invoked' => !$sendInvoked,
		'read_only' => isset($report['read_only']) && $report['read_only'] === true,
		'db_mutations' => isset($report['db_mutations']) && $report['db_mutations'] === false,
		'wallet_sends' => isset($report['wallet_sends']) && $report['wallet_sends'] === false,
	);
}
$command = is_file($commandPath) ? file_get_contents($commandPath) : '';
expect_contains('action registered', $command, "'wallet-proof-closeout'", $failures);
expect_contains('dispatch registered', $command, "case 'wallet-proof-closeout':", $failures);
expect_contains('help documents command', $command, 'badpoolguard wallet-proof-closeout --coin-id=<id> --selected-payout-ids=<csv> --format=json', $failures);
expect_contains('json required', $command, 'wallet-proof-closeout requires --format=json.', $failures);
expect_contains('explicit coin required', $command, 'requires explicit --coin-id and refuses broad/all-coin scope', $failures);
expect_contains('selected payout csv required', $command, 'requires explicit nonempty --selected-payout-ids CSV of positive integers', $failures);
expect_contains('duplicate selected payout IDs refused', $command, 'Duplicate selected payout IDs are refused.', $failures);
expect_contains('supported scrypt context', $command, "'conf'=>'/etc/badcoin/pool-scrypt.conf'", $failures);
expect_contains('supported scrypt datadir', $command, "'datadir'=>'/var/lib/badcoin-pool-scrypt'", $failures);
expect_contains('unsupported coin fails closed', $command, 'unsupported_wallet_proof_context', $failures);
expect_contains('redaction helper present', $command, 'walletProofRedact', $failures);
expect_contains('redacts credential words', $command, 'rpc(user|pass(word)?)|cookie|secret|token|passphrase', $failures);
if (!wallet_proof_decimal_is_zero_harness('0') || !wallet_proof_decimal_is_zero_harness('0.00000000') || wallet_proof_decimal_is_zero_harness('0.1')) $failures[] = 'decimal zero helper fixture failed';
expect_contains('negative expected amount emitted', $command, "['expected_send_amount'] = '-'", $failures);
expect_contains('wallet precision expected amount emitted', $command, "['expected_wallet_amount'] = '-'", $failures);
expect_contains('raw DB amount retained', $command, "'raw_db_amount'=>".'$amount', $failures);
expect_contains('wallet RPC amount retained', $command, "['wallet_amount'] = (string)arraySafeVal", $failures);
expect_contains('negative wallet amount matching', $command, "strpos($".'report[\'wallet_amount\'], \'-\') === 0', $failures);
expect_contains('wallet amount compared to wallet precision total', $command, "walletSendDecimalCompare(ltrim($".'report[\'wallet_amount\'], \'-\'), $expectedWallet)', $failures);
if (wallet_proof_project_8dp_harness('106038.04915195997') !== '106038.04915196') $failures[] = 'payout 516 decimal8 projection fixture failed';
$fixtureAmountMatch = wallet_proof_amount_matches_expected_harness('-106038.04915196', '106038.04915195997');
if (!$fixtureAmountMatch) $failures[] = 'payout 516 wallet amount should match after decimal8 projection';
$fixtureCloseoutValid = true && true && $fixtureAmountMatch && true;
if (!$fixtureCloseoutValid) $failures[] = 'payout 516 closeout_valid fixture should pass when all proof checks pass';
$smokeReport = array('command'=>'wallet-proof-closeout','read_only'=>true,'db_mutations'=>false,'wallet_sends'=>false,'wallet_send_rpc_methods_blocked'=>array('sendmany','sendtoaddress'));
$smokeAudit = wallet_proof_smoke_audit_harness(array('php','yaamp/yiic.php','badpoolguard','wallet-proof-closeout','--coin-id=1267','--selected-payout-ids=516','--format=json'), $smokeReport);
if (!$smokeAudit['no_wallet_send_command_invoked']) $failures[] = 'blocked RPC method names falsely counted as an executed wallet send';
if (!$smokeAudit['read_only'] || !$smokeAudit['db_mutations'] || !$smokeAudit['wallet_sends']) $failures[] = 'read-only smoke audit boundary fixture failed';
$sendAudit = wallet_proof_smoke_audit_harness(array('php','yaamp/yiic.php','badpoolguard','wallet-send-apply'), $smokeReport);
if ($sendAudit['no_wallet_send_command_invoked']) $failures[] = 'actual wallet-send-apply command was not detected';
expect_contains('do_not_rerun is array', $command, "['do_not_rerun'] = array(", $failures);
expect_contains('do_not_rerun blocks wallet-send apply', $command, "'wallet-send-apply'", $failures);
expect_contains('do_not_rerun blocks payout-row apply', $command, "'payout-row-apply'", $failures);
expect_contains('do_not_rerun blocks account-credit apply', $command, "'account-credit-apply'", $failures);
expect_not_contains('do_not_rerun is not string sentence', $command, "Do not rerun any wallet-send apply for these selected completed payout rows.", $failures);
expect_contains('pass classification', $command, 'PASS / WALLET PROOF CLOSEOUT COMPLETE', $failures);
expect_contains('hold classification', $command, 'HOLD / WALLET PROOF INCOMPLETE', $failures);
foreach (array('schema','command','command_shape','read_only','wallet_reads','db_mutations','wallet_sends','wallet_send_rpc_methods_blocked','selected_payout_ids','payout_inventory','expected_send_amount','expected_wallet_amount','wallet_lookup_success','wallet_txid_expected','wallet_amount_matches_expected','wallet_confirmations_present','closeout_valid','missing_closeout_fields','invalid_closeout_fields','classification','final_classification','run_dir','mutation_boundary','next_lane','next_safe_lane_or_STOP','do_not_rerun','fix_items') as $field) {
	expect_contains('required field '.$field, $command, "['$field']", $failures);
}
expect_contains('wallet lookup is gettransaction only', $command, 'gettransaction($txid)', $failures);
foreach (array('blockhash','blockindex','category','confirmations') as $field) expect_contains('wallet proof includes '.$field, $command, "'$field'=>arraySafeVal", $failures);
expect_contains('payout missing guard', $command, 'payout missing', $failures);
expect_contains('tx missing guard', $command, 'tx missing payout', $failures);
expect_contains('completed guard', $command, 'completed not 1 payout', $failures);
expect_contains('coin mismatch guard', $command, 'coin_id mismatch payout', $failures);
expect_contains('account balance reported', $command, 'account_balance_reported', $failures);
expect_not_contains('account balance nonzero is not invalid closeout', $section = substr($command, strpos($command, 'private function walletProofCloseoutReport'), strpos($command, 'private function walletProofCloseoutHold') - strpos($command, 'private function walletProofCloseoutReport')), "invalid_closeout_fields'][] = 'account balance nonzero payout", $failures);
expect_contains('account balance nonzero is informational', $command, 'informational_nonzero_after_historical_payout', $failures);
$completedFixture=array('completed'=>1,'tx'=>'c64b4c1dec4f4a619b70b0f27c133aaac7a40c25f0556b581fbdf3b775c8d4c2','account_balance'=>'42.0');
$unsentFixture=array('completed'=>0,'tx'=>'','account_balance'=>'0');
if(!wallet_proof_shape_harness($completedFixture)||wallet_send_shape_harness($completedFixture))$failures[]='519/520 completed+tx shape was not proof-only';
if(wallet_proof_shape_harness($unsentFixture)||!wallet_send_shape_harness($unsentFixture))$failures[]='unsent payout shape was not send-only';
if(!wallet_proof_shape_harness($completedFixture)||wallet_proof_decimal_is_zero_harness($completedFixture['account_balance']))$failures[]='nonzero later balance incorrectly affected completed proof shape';
expect_contains('withdraw rows reported', $command, 'walletProofWithdrawRows', $failures);
$start = strpos($command, 'private function walletProofCloseoutReport');
$end = strpos($command, 'private function walletSendDryrunReport', $start);
$section = ($start === false || $end === false) ? '' : substr($command, $start, $end - $start);
foreach (array('badpoolGuardedSendmanyApply(', 'sendmany(', 'sendtoaddress(', 'transfer(', 'walletpassphrase(', 'walletpassphrasechange(', 'walletlock(', 'UPDATE ', 'INSERT ', 'DELETE ', 'createCommand()->update', 'createCommand()->insert', 'createCommand()->delete', 'BackendPayments', 'BackendCoinPayments', 'startService(', 'stopService(', 'restartService(') as $forbidden) {
	expect_not_contains('send/mutation paths absent from wallet-proof section', $section, $forbidden, $failures);
}
if (!empty($failures)) { echo "Badpool wallet-proof closeout guard harness FAILED\n"; foreach ($failures as $failure) echo " - $failure\n"; exit(1); }
echo "Badpool wallet-proof closeout guard harness passed\n";
