<?php

$root = dirname(__DIR__);
$command = file_get_contents($root . '/web/yaamp/commands/BadpoolGuardCommand.php');
$context = file_get_contents($root . '/web/yaamp/core/backend/BadpoolGuardContext.php');

$fail = 0;

function ok($cond, $msg)
{
	global $fail;
	if ($cond) {
		echo "PASS: $msg\n";
		return;
	}
	echo "FAIL: $msg\n";
	$fail++;
}

ok(strpos($command, "'retained-dryrun-report' => null") !== false, 'retained dryrun report option exists');
ok(strpos($command, 'Use either --dryrun-report or --retained-dryrun-report, not both.') !== false, 'mutually exclusive retained and direct dryrun reports');
ok(strpos($command, "\$opts['dryrun-report'] = \$opts['retained-dryrun-report'];") !== false, 'retained dryrun report aliases to dryrun report');
ok(preg_match('/blockAccountingApprovalPackageContextArgs\s*\([^)]*\).*retained-dryrun-report/s', $command) === 1, 'command context forwards retained dryrun report option');
ok(preg_match('/blockAccountingApprovalPackageContextArgs\s*\([^)]*\).*dryrun-report/s', $command) === 1, 'command context forwards direct dryrun report option');
ok(strpos($command, 'block-accounting-approval-package requires --dryrun-report=<path>.') !== false, 'original dryrun report requirement retained');
ok(strpos($command, "'operator_confirmation_embedded' => false") !== false, 'package still tracks no embedded operator confirmation');

ok(strpos($context, "'dryrun-report'") !== false, 'shared guard allows direct dryrun report option');
ok(strpos($context, "'retained-dryrun-report'") !== false, 'shared guard allows retained dryrun report option');

if ($fail) {
	echo "badpool block accounting retained package alias harness: FAIL failures=$fail\n";
	exit(1);
}

echo "badpool block accounting retained package alias harness: PASS\n";
