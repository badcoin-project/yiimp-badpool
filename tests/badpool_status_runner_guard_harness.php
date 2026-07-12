<?php
$root = dirname(__DIR__);
$commandPath = $root.'/web/yaamp/commands/BadpoolGuardCommand.php';
$contextPath = $root.'/web/yaamp/core/backend/BadpoolGuardContext.php';
$command = file_get_contents($commandPath);
$context = file_get_contents($contextPath);
$failures = array();
function expect_contains($label, $haystack, $needle, &$failures) { if (strpos($haystack, $needle) === false) $failures[] = $label.' missing: '.$needle; }
function expect_not_contains($label, $haystack, $needle, &$failures) { if (strpos($haystack, $needle) !== false) $failures[] = $label.' forbidden: '.$needle; }
function section_between($text, $start, $end) { $s = strpos($text, $start); if ($s === false) return ''; $e = strpos($text, $end, $s + strlen($start)); if ($e === false) $e = strlen($text); return substr($text, $s, $e - $s); }

expect_contains('action registered', $command, "'status-runner'", $failures);
expect_contains('help shape', $command, 'badpoolguard status-runner [--coin-id=<id>] [--algo=<algo>] --format=json', $failures);
expect_contains('context permits algo filter', $context, "'algo'", $failures);
expect_contains('implicit all five scope', $command, "--all-coins-preview", $failures);
expect_contains('fixed five coin ids', $command, '$ids = array(1266, 1267, 1268, 1269, 1270);', $failures);
expect_contains('coin id filter supported', $command, "id=:coin_id", $failures);
expect_contains('algo filter supported', $command, "LOWER(algo)=LOWER(:algo)", $failures);

$status = section_between($command, 'private function statusRunnerReport', 'private function overviewReport');
foreach (array('coin_id','symbol','algo','blocks_total','latest_block_height','stage1_selected_count','stage1_pending_amount','maturity_selected_count','maturity_selected_amount','account_credit_selected_count','account_credit_projected_total','payout_candidate_count','payout_candidate_amount','account_balance_count','account_balance_total','payout_rows_count','max_payout_id','withdraw_rows_count','blocked_reason','next_safe_action') as $field) {
	expect_contains('required field '.$field, $status, "'$field'", $failures);
}
foreach (array('no_blocks','no_current_action','stage1_ready','maturity_wait','maturity_ready','payment_delay_wait','account_credit_ready','payout_row_ready','wallet_send_ready','unresolved_attribution','hold_unknown') as $reason) {
	expect_contains('blocked_reason value '.$reason, $status, $reason, $failures);
}
foreach (array('none','run_stage1_package','run_maturity_package','wait_payment_delay','run_account_credit_package','run_payout_row_package','run_wallet_send_package','investigate_hold') as $action) {
	expect_contains('next_safe_action value '.$action, $status, $action, $failures);
}
expect_contains('read only true', $status, "'read_only'] = true", $failures);
expect_contains('no apply commands executed', $status, "'apply_commands_executed'] = false", $failures);
expect_contains('no wallet sends', $status, "'wallet_sends'] = false", $failures);
expect_contains('no DB mutations', $status, "'db_mutations'] = false", $failures);
expect_contains('no account credits', $status, "'account_credits_created'] = false", $failures);
expect_contains('no payout rows', $status, "'payout_rows_created'] = false", $failures);
expect_contains('no backend loops', $status, "'backend_loops_run'] = false", $failures);
expect_contains('no share deletion', $status, "'shares_deleted'] = false", $failures);
expect_contains('conservative multiple stage hold', $status, '$signals > 1', $failures);
expect_contains('ambiguous hold_unknown maps investigate', $status, "'blocked_reason'=>'hold_unknown', 'next_safe_action'=>'investigate_hold'", $failures);
foreach (array('UPDATE ', 'INSERT ', 'DELETE ', 'sendmany', 'sendtoaddress', 'badpoolGuardedSendmanyApply', 'BackendPayments', 'BackendCoinPayments', 'startService(', 'stopService(', 'restartService(') as $forbidden) {
	expect_not_contains('status runner read-only section', $status, $forbidden, $failures);
}
if ($failures) { echo "Badpool status runner guard harness FAILED\n"; foreach ($failures as $f) echo " - $f\n"; exit(1); }
echo "Badpool status runner guard harness passed\n";
