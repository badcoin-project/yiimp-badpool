<?php
$command = file_get_contents(__DIR__.'/../web/yaamp/commands/BadpoolGuardCommand.php');
$failures = 0;

function check_true($name, $ok) {
    global $failures;
    echo ($ok ? "PASS: " : "FAIL: ").$name."\n";
    if (!$ok) $failures++;
}

check_true('approval package action registered', strpos($command, "'block-accounting-approval-package'") !== false);
check_true('single approval package switch case', substr_count($command, "case 'block-accounting-approval-package':") === 1);
check_true('approval package report method exists', strpos($command, 'private function blockAccountingApprovalPackageReport($args)') !== false);
check_true('approval package option parser exists', strpos($command, 'blockAccountingApprovalPackageContextArgs') !== false);
check_true('approval package schema exists', strpos($command, 'badpool.block-accounting-approval-package.v1') !== false);
check_true('binds dryrun schema', strpos($command, 'badpool.block-accounting-dryrun.v1') !== false);
check_true('requires passing dryrun', strpos($command, 'dryrun report must have pass status') !== false);
check_true('coin mismatch refused', strpos($command, 'dryrun report coin-id mismatch') !== false);
check_true('forward selector cannot be packaged', strpos($command, 'only backlog selector dryrun reports can be packaged') !== false);
check_true('50 row package cap retained', strpos($command, 'approval package refuses more than 50 block rows') !== false);
check_true('all dryrun checks are rebound', strpos($command, 'dryrun check failed:') !== false);
check_true('approval package checksum emitted', strpos($command, 'approval_package_checksum') !== false);
check_true('selected scope checksum bound', strpos($command, 'selected_scope_checksum') !== false);
check_true('candidate rows checksum bound', strpos($command, 'candidate_rows_checksum') !== false);
check_true('dryrun report checksum bound', strpos($command, 'dryrun_report_checksum') !== false);
check_true('no operator confirmation embedded', strpos($command, "'operator_confirmation_embedded' => false") !== false);
check_true('apply command shape is explicitly future only', strpos($command, 'block-accounting-apply --approval-package=<path>') !== false);
check_true('database mutation disabled', strpos($command, "'db_mutations' => false") !== false);
check_true('wallet command disabled', strpos($command, "'wallet_sends' => false") !== false);
check_true('service action disabled', strpos($command, "'service_actions' => false") !== false);

echo $failures ? "badpool block accounting approval package harness: FAIL failures=$failures\n" : "badpool block accounting approval package harness: PASS\n";