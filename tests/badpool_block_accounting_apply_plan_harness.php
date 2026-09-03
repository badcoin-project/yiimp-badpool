<?php
$command = file_get_contents(__DIR__.'/../web/yaamp/commands/BadpoolGuardCommand.php');
$failures = 0;

function check_true($name, $ok) {
    global $failures;
    echo ($ok ? "PASS: " : "FAIL: ").$name."\n";
    if (!$ok) $failures++;
}

check_true('apply plan method exists', strpos($command, 'private function blockAccountingApplyPlannedMutations($approval)') !== false);
check_true('apply report includes planned mutations', strpos($command, "'planned_mutations' => is_array(\$approval) ? \$this->blockAccountingApplyPlannedMutations(\$approval) : array()") !== false);
check_true('apply plan schema exists', strpos($command, 'badpool.block-accounting-apply-plan.v1') !== false);
check_true('executor remains unwired', strpos($command, "'executor_wired' => false") !== false);
check_true('wallet rpc remains disabled in plan', strpos($command, "'wallet_rpc_required' => false") !== false);
check_true('backend callbacks remain disabled in plan', strpos($command, "'backend_callbacks_required' => false") !== false);
check_true('future db mutation is explicit', strpos($command, "'database_mutation_required_for_future_apply' => true") !== false);
check_true('BackendBlockNew remains uncalled', strpos($command, 'BackendBlockNew(') === false);
check_true('BackendBlockFind1 remains uncalled', strpos($command, 'BackendBlockFind1(') === false);
check_true('planned updates checksum exists', strpos($command, "'block_updates_checksum'") !== false);
check_true('planned earnings checksum exists', strpos($command, "'earning_inserts_checksum'") !== false);

echo $failures ? "badpool block accounting apply plan harness: FAIL failures=$failures\n" : "badpool block accounting apply plan harness: PASS\n";