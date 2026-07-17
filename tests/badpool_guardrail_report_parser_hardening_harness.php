<?php

require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolGuardAutomationContracts.php');

$failures = array();

function assert_true($name, $condition)
{
	global $failures;
	if (!$condition) {
		$failures[] = $name;
		echo "FAIL $name\n";
		return;
	}
	echo "PASS $name\n";
}

echo "============================================================\n";
echo "BADPOOL GUARDRAIL REPORT/PARSER HARDENING HARNESS\n";
echo "============================================================\n";

$preview = array(
	'command' => 'payout-candidates-preview',
	'summary' => array(
		'preview_status' => 'ok',
		'candidate_count' => 1,
	),
);
$hardened = BadpoolGuardAutomationContracts::hardenReport($preview);
assert_true(
	'payout-candidates-preview is count/existence gate',
	isset($hardened['summary']['candidate_gate']) && $hardened['summary']['candidate_gate'] === 'count_or_existence_only'
);
assert_true(
	'payout-candidates-preview does not require candidate_amount',
	array_key_exists('candidate_amount_required', $hardened['summary']) && $hardened['summary']['candidate_amount_required'] === false
);
assert_true(
	'payout-candidates-preview delegates amount validation to package',
	$hardened['summary']['amount_validation_source'] === 'payout-row-approval-package'
);

$payload = array(
	'coin_id' => 1267,
	'account_id' => 79,
	'selected_earning_ids' => array(12727, '12728', '12729'),
	'nested' => array(
		'coin_id' => 9999,
		'selected_payout_ids' => '516,517',
		'items' => array(
			array('account_id' => 999),
			array('selected_account_ids' => array('79')),
		),
	),
);
assert_true(
	'selected_earning_ids ignores unrelated coin_id/account_id integers',
	BadpoolGuardAutomationContracts::selectedEarningIds($payload) === array(12727, 12728, 12729)
);
assert_true(
	'selected_payout_ids schema-aware extraction',
	BadpoolGuardAutomationContracts::selectedPayoutIds($payload) === array(516, 517)
);
assert_true(
	'selected_account_ids schema-aware extraction',
	BadpoolGuardAutomationContracts::selectedAccountIds($payload) === array(79)
);

$before = array(
	'target_account_balance' => array('1', '0'),
	'selected_payout' => array('516', '79', '1267'),
);
$afterMismatch = array(
	'target_account_balance' => array('1', '0'),
	'selected_payout_after' => array('516', '79', '1267'),
);
$cmp = BadpoolGuardAutomationContracts::compareSnapshotLabels($before, $afterMismatch);
assert_true(
	'mismatched snapshot labels fail without allowlist',
	$cmp['ok'] === false
);
$cmpAllowed = BadpoolGuardAutomationContracts::compareSnapshotLabels($before, $afterMismatch, array('selected_payout', 'selected_payout_after'));
assert_true(
	'mismatched snapshot labels pass only with explicit allowlist',
	$cmpAllowed['ok'] === true
);

$help = "Yiimp badpoolguard command\nUsage: php yaamp/yiic.php badpoolguard payout-candidates-preview --coin-id=<id>\n       php yaamp/yiic.php badpoolguard wallet-send-apply --coin-id=<id>\n";
$discovery = BadpoolGuardAutomationContracts::parseHelpDiscovery($help, 1);
assert_true(
	'help rc=1 with useful output is discovery-usable',
	$discovery['usable_for_discovery'] === true && in_array('payout-candidates-preview', $discovery['commands'], true)
);

$closeoutGood = array(
	'final_classification' => 'PASS / EXAMPLE',
	'run_dir' => '/tmp/example',
	'mutation_boundary' => 'read-only',
	'do_not_rerun' => array('wallet-send-apply'),
	'next_safe_lane_or_STOP' => 'STOP',
	'fix_items' => array(),
);
assert_true(
	'closeout report minimum fields complete',
	BadpoolGuardAutomationContracts::missingCloseoutFields($closeoutGood) === array()
);

$closeoutBad = array(
	'final_classification' => 'PASS / EXAMPLE',
);
$missing = BadpoolGuardAutomationContracts::missingCloseoutFields($closeoutBad);
assert_true(
	'closeout report minimum fields detect missing values',
	in_array('run_dir', $missing, true) && in_array('fix_items', $missing, true)
);

$repoRoot = dirname(__FILE__).'/..';
$sourceFiles = array(
	$repoRoot.'/web/yaamp/commands/BadpoolGuardCommand.php',
	$repoRoot.'/web/yaamp/core/backend/BadpoolGuardReport.php',
	$repoRoot.'/web/yaamp/core/backend/BadpoolGuardAutomationContracts.php',
);
$badSchemaReference = false;
foreach ($sourceFiles as $file) {
	if (is_file($file) && strpos(file_get_contents($file), 'accounts.userid') !== false) {
		$badSchemaReference = true;
	}
}
assert_true(
	'guardrail source does not reference accounts.userid',
	$badSchemaReference === false
);

if (!empty($failures)) {
	echo "============================================================\n";
	echo "RESULT: FAIL\n";
	echo "FAILED_CHECKS=".implode(',', $failures)."\n";
	exit(1);
}

echo "============================================================\n";
echo "RESULT: PASS\n";
echo "============================================================\n";
exit(0);
