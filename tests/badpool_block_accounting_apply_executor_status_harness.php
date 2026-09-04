<?php
$command = file_get_contents(__DIR__.'/../web/yaamp/commands/BadpoolGuardCommand.php');
$failures = 0;

function check_true($name, $ok) {
    global $failures;
    echo ($ok ? "PASS: " : "FAIL: ").$name."\n";
    if (!$ok) $failures++;
}

check_true('mutation authorization is explicit false', strpos($command, "'mutation_authorized' => false") !== false);
check_true('executor readiness is explicit false', strpos($command, "'executor_ready' => false") !== false);
check_true('future executor status is explicit', strpos($command, "'apply_executor_status' => 'future-executor-not-implemented'") !== false);
check_true('db mutations remain false', strpos($command, "'db_mutations' => false") !== false);
check_true('backend execute remains false', strpos($command, "'backend_execute_mode' => false") !== false);
check_true('wallet send remains false', strpos($command, "'wallet_rpc_send_performed' => false") !== false);
check_true('backend callbacks remain false', strpos($command, "'backend_callbacks_invoked' => false") !== false);
check_true('daemon rpc remains false', strpos($command, "'daemon_rpc_invoked' => false") !== false);
check_true('BackendBlockNew remains uncalled', strpos($command, 'BackendBlockNew(') === false);
check_true('BackendBlockFind1 remains uncalled', strpos($command, 'BackendBlockFind1(') === false);

echo $failures ? "badpool block accounting apply executor status harness: FAIL failures=$failures\n" : "badpool block accounting apply executor status harness: PASS\n";