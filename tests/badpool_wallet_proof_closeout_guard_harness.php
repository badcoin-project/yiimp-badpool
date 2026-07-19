<?php
$root = dirname(__DIR__);
$commandPath = $root.'/web/yaamp/commands/BadpoolGuardCommand.php';
$failures = array();
function expect_contains($label, $haystack, $needle, &$failures) { if (strpos($haystack, $needle) === false) $failures[] = "$label: missing expected text: $needle"; }
function expect_not_contains($label, $haystack, $needle, &$failures) { if (strpos($haystack, $needle) !== false) $failures[] = "$label: found forbidden text: $needle"; }
function wallet_proof_decimal_is_zero_harness($v) { return preg_match('/^-?0+(?:\.0+)?$/', trim((string)$v)) === 1; }
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
expect_contains('negative wallet amount matching', $command, "strpos($".'report[\'wallet_amount\'], \'-\') === 0', $failures);
expect_contains('do_not_rerun is array', $command, "['do_not_rerun'] = array(", $failures);
expect_contains('do_not_rerun blocks wallet-send apply', $command, "'wallet-send-apply'", $failures);
expect_contains('do_not_rerun blocks payout-row apply', $command, "'payout-row-apply'", $failures);
expect_contains('do_not_rerun blocks account-credit apply', $command, "'account-credit-apply'", $failures);
expect_not_contains('do_not_rerun is not string sentence', $command, "Do not rerun any wallet-send apply for these selected completed payout rows.", $failures);
expect_contains('pass classification', $command, 'PASS / WALLET PROOF CLOSEOUT COMPLETE', $failures);
expect_contains('hold classification', $command, 'HOLD / WALLET PROOF INCOMPLETE', $failures);
foreach (array('schema','command','command_shape','read_only','wallet_reads','db_mutations','wallet_sends','wallet_send_rpc_methods_blocked','selected_payout_ids','payout_inventory','expected_send_amount','wallet_lookup_success','wallet_txid_expected','wallet_amount_matches_expected','wallet_confirmations_present','closeout_valid','missing_closeout_fields','invalid_closeout_fields','classification','final_classification','run_dir','mutation_boundary','next_lane','next_safe_lane_or_STOP','do_not_rerun','fix_items') as $field) {
	expect_contains('required field '.$field, $command, "['$field']", $failures);
}
expect_contains('wallet lookup is gettransaction only', $command, 'gettransaction($txid)', $failures);
foreach (array('blockhash','blockindex','category','confirmations') as $field) expect_contains('wallet proof includes '.$field, $command, "'$field'=>arraySafeVal", $failures);
expect_contains('payout missing guard', $command, 'payout missing', $failures);
expect_contains('tx missing guard', $command, 'tx missing payout', $failures);
expect_contains('completed guard', $command, 'completed not 1 payout', $failures);
expect_contains('coin mismatch guard', $command, 'coin_id mismatch payout', $failures);
expect_contains('account balance reported', $command, 'account_balance_reported', $failures);
expect_contains('account balance nonzero hold', $command, 'account balance nonzero payout', $failures);
expect_contains('withdraw rows reported', $command, 'walletProofWithdrawRows', $failures);
$start = strpos($command, 'private function walletProofCloseoutReport');
$end = strpos($command, 'private function walletSendDryrunReport', $start);
$section = ($start === false || $end === false) ? '' : substr($command, $start, $end - $start);
foreach (array('badpoolGuardedSendmanyApply(', 'sendmany(', 'sendtoaddress(', 'transfer(', 'walletpassphrase(', 'walletpassphrasechange(', 'walletlock(', 'UPDATE ', 'INSERT ', 'DELETE ', 'createCommand()->update', 'createCommand()->insert', 'createCommand()->delete', 'BackendPayments', 'BackendCoinPayments', 'startService(', 'stopService(', 'restartService(') as $forbidden) {
	expect_not_contains('send/mutation paths absent from wallet-proof section', $section, $forbidden, $failures);
}
if (!empty($failures)) { echo "Badpool wallet-proof closeout guard harness FAILED\n"; foreach ($failures as $failure) echo " - $failure\n"; exit(1); }
echo "Badpool wallet-proof closeout guard harness passed\n";
