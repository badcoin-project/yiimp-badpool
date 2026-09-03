<?php
$command = file_get_contents(__DIR__.'/../web/yaamp/commands/BadpoolGuardCommand.php');
$failures = 0;

function check_true($name, $ok) {
    global $failures;
    echo ($ok ? "PASS: " : "FAIL: ").$name."\n";
    if (!$ok) $failures++;
}

check_true('apply action registered', strpos($command, "'block-accounting-apply'") !== false);
check_true('single apply switch case', substr_count($command, "case 'block-accounting-apply':") === 1);
check_true('apply report method exists', strpos($command, 'private function blockAccountingApplyReport($args)') !== false);
check_true('apply context parser exists', strpos($command, 'blockAccountingApplyContextArgs') !== false);
check_true('apply schema exists', strpos($command, 'badpool.block-accounting-apply.v1') !== false);
check_true('requires approval package path', strpos($command, 'block-accounting-apply requires --approval-package=<path>') !== false);
check_true('requires operator confirmation', strpos($command, 'operator-confirms-block-accounting=apply_selected_block_accounting_package') !== false);
check_true('binds approval package schema', strpos($command, 'badpool.block-accounting-approval-package.v1') !== false);
check_true('requires pass approval package', strpos($command, 'approval package must have pass status') !== false);
check_true('requires no embedded operator confirmation', strpos($command, 'approval package must not embed operator confirmation') !== false);
check_true('50 row apply cap retained', strpos($command, 'block-accounting-apply refuses more than 50 block rows') !== false);
check_true('scope checksum required', strpos($command, 'selected_scope_checksum') !== false);
check_true('candidate rows checksum required', strpos($command, 'candidate_rows_checksum') !== false);
check_true('dryrun report checksum required', strpos($command, 'dryrun_report_checksum') !== false);
check_true('approval package checksum required', strpos($command, 'approval_package_checksum') !== false);
check_true('no backend callback invocation marker', strpos($command, "'backend_callbacks_invoked' => false") !== false);
check_true('no daemon rpc invocation marker', strpos($command, "'daemon_rpc_invoked' => false") !== false);
check_true('wallet send remains false', strpos($command, "'wallet_rpc_send_performed' => false") !== false);
check_true('mutation executor intentionally not wired', strpos($command, 'mutation executor is intentionally not wired') !== false);
check_true('does not call BackendBlockNew', strpos($command, 'BackendBlockNew(') === false);
check_true('does not call BackendBlockFind1', strpos($command, 'BackendBlockFind1(') === false);

echo $failures ? "badpool block accounting apply harness: FAIL failures=$failures\n" : "badpool block accounting apply harness: PASS\n";