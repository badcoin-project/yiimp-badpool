<?php
$source = file_get_contents(__DIR__.'/../web/yaamp/commands/BadpoolGuardCommand.php');

function harness_fail($message)
{
	echo "FAIL: ".$message."\n";
	return 1;
}

$failed = 0;
$globalChecks = array(
	'action registered' => "'block-accounting-dryrun'",
	'single block accounting switch case' => "case 'block-accounting-dryrun'",
	'old narrow command removed' => "scrypt-backlog-dryrun",
);
foreach ($globalChecks as $label => $needle) {
	if ($label === 'single block accounting switch case')
		$pass = substr_count($source, $needle) === 1;
	elseif ($label === 'old narrow command removed')
		$pass = strpos($source, $needle) === false;
	else
		$pass = strpos($source, $needle) !== false;
	echo ($pass ? "PASS: " : "FAIL: ").$label."\n";
	if (!$pass)
		$failed++;
}

$start = strpos($source, "\tprivate function blockAccountingDryrunReport(");
$next = $start === false ? false : strpos($source, "\n\tprivate function blockAccountingDryrunContextArgs(", $start);
$method = ($start !== false && $next !== false) ? substr($source, $start, $next - $start) : '';

$methodChecks = array(
	'shared method exists' => "function blockAccountingDryrunReport",
	'backlog selector exists' => "selector !== 'backlog'",
	'forward selector exists' => "selector !== 'forward'",
	'forward mapped but blocked' => "forward selector is mapped but not enabled",
	'50 row cap exists' => "--max-rows must be between 1 and 50",
	'auto backlog window exists' => "auto-oldest-new-window",
	'auto backlog selects new rows' => "category='new' ORDER BY id LIMIT",
	'half backlog bounds refused' => "requires both --first-block-id and --last-block-id when either is supplied",
	'category new scope exists' => "'category' => 'new'",
	'earnings overlap check exists' => "no_earnings_overlap",
	'duplicate height check exists' => "no_duplicate_heights",
	'callback-free check exists' => "no_backend_callbacks_invoked",
	'daemon-free check exists' => "no_daemon_rpc_invoked",
	'mutation-free check exists' => "no_database_mutation_invoked",
	'scope checksum exists' => "selected_scope_checksum",
	'candidate checksum exists' => "candidate_rows_checksum",
	'report checksum exists' => "dryrun_report_checksum",
	'does not finalize custom report away' => "return BadpoolGuardReport::finalize(\$report);",
	'does not call missing hasErrors API' => "->hasErrors()",
	'does not call missing errors API' => "->errors()",
	'publishes local errors through guard' => "blockAccountingPublishLocalErrors",
);
foreach ($methodChecks as $label => $needle) {
	if ($label === 'does not finalize custom report away' || $label === 'does not call missing hasErrors API' || $label === 'does not call missing errors API')
		$pass = strpos($method, $needle) === false;
	else
		$pass = strpos($method, $needle) !== false;
	echo ($pass ? "PASS: " : "FAIL: ").$label."\n";
	if (!$pass)
		$failed++;
}

$parserPresent = strpos($source, "function parseBlockAccountingDryrunOptions") !== false;
echo ($parserPresent ? "PASS: " : "FAIL: ")."option parser exists\n";
if (!$parserPresent)
	$failed++;

if ($failed)
	echo "badpool block accounting dryrun harness: FAIL failures=$failed\n";
else
	echo "badpool block accounting dryrun harness: PASS\n";
?>
