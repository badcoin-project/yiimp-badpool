<?php
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolGuardAutomationContracts.php');
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolGuardReport.php');

$failures = array();
function expect_true($name, $condition, &$failures) { if (!$condition) $failures[] = $name; }
function expect_error($name, $result, $error, &$failures) { if (!is_array($result) || !isset($result['error']) || $result['error'] !== $error) $failures[] = $name.' expected '.$error; }

$error = null;
expect_true('csv selected IDs pass', BadpoolGuardAutomationContracts::parseSelectedIds('1,2,30', $error) === array(1,2,30) && $error === null, $failures);
expect_true('flat integer selected IDs pass', BadpoolGuardAutomationContracts::parseSelectedIds(array(4,5), $error) === array(4,5) && $error === null, $failures);
foreach (array(array('coin_id'=>9,'account_id'=>10), array(array('id'=>1)), (object)array('id'=>1)) as $bad) {
	expect_true('malformed selected-ID structures rejected', BadpoolGuardAutomationContracts::parseSelectedIds($bad, $error) === null, $failures);
}
foreach (array('0','-1','1.5','1,true', array(0), array(-1), array(1.5), array(true), array(1,'2')) as $bad) {
	expect_true('invalid numeric ID values rejected', BadpoolGuardAutomationContracts::parseSelectedIds($bad, $error) === null, $failures);
}
expect_true('associative selected-ID arrays containing coin_id/account_id not absorbed', BadpoolGuardAutomationContracts::parseSelectedIds(array('coin_id'=>7,'account_id'=>8), $error) === null, $failures);

expect_error('unparseable snapshot entries rejected', BadpoolGuardAutomationContracts::compareSnapshotLabels(array(array('label'=>'a')), array(array('name'=>'a'))), 'snapshot_label_parse_error', $failures);
expect_error('duplicate snapshot labels rejected', BadpoolGuardAutomationContracts::compareSnapshotLabels(array(array('label'=>'a'),array('label'=>'a')), array(array('label'=>'a'))), 'snapshot_label_parse_error', $failures);
expect_error('label cardinality compared', BadpoolGuardAutomationContracts::compareSnapshotLabels(array(array('label'=>'a'),array('label'=>'b')), array(array('label'=>'a'),array('label'=>'c'))), 'snapshot_label_invariant_error', $failures);
expect_error('one-sided allowlists remain narrow', BadpoolGuardAutomationContracts::compareSnapshotLabels(array(array('label'=>'missing-ok')), array(array('label'=>'extra-bad')), array('missing'=>array('missing-ok'))), 'snapshot_label_invariant_error', $failures);
expect_error('overbroad allowlists ignored', BadpoolGuardAutomationContracts::compareSnapshotLabels(array(array('label'=>'a')), array(array('label'=>'b')), array('missing'=>array('*'), 'extra'=>array('*'))), 'snapshot_label_invariant_error', $failures);

expect_error('error-only help output rejected', BadpoolGuardAutomationContracts::discoverHelp(1, 'badpoolguard failed to initialize', ''), 'unstructured_help_output', $failures);
expect_error('empty help output rejected', BadpoolGuardAutomationContracts::discoverHelp(1, '', ''), 'empty_help_output', $failures);
expect_error('stderr-only failures rejected', BadpoolGuardAutomationContracts::discoverHelp(1, '', 'database unavailable'), 'unstructured_help_output', $failures);
expect_error('generic exception output rejected', BadpoolGuardAutomationContracts::discoverHelp(255, 'Exception: boom', ''), 'unstructured_help_output', $failures);
expect_true('structured help accepted', BadpoolGuardAutomationContracts::discoverHelp(1, "Yiimp badpoolguard command\nUsage: php yaamp/yiic.php badpoolguard overview --coin-id=<id>\n", '')['usable'] === true, $failures);

$validCloseout = array('classification'=>'pass','run_dir'=>'/tmp/run','mutation_boundary'=>'none','next_lane'=>'review','do_not_rerun'=>array('x'),'fix_items'=>array());
expect_true('valid closeout passes and empty fix_items allowed', BadpoolGuardAutomationContracts::validateCloseout($validCloseout)['closeout_valid'] === true, $failures);
foreach (array('classification','run_dir','mutation_boundary','next_lane','do_not_rerun','fix_items') as $field) {
	$case = $validCloseout; $case[$field] = null;
	expect_true('null closeout fields invalid '.$field, in_array($field, BadpoolGuardAutomationContracts::validateCloseout($case)['invalid_closeout_fields'], true), $failures);
}
foreach (array('classification','run_dir','mutation_boundary','next_lane') as $field) {
	$case = $validCloseout; $case[$field] = '';
	expect_true('empty-string closeout fields invalid '.$field, in_array($field, BadpoolGuardAutomationContracts::validateCloseout($case)['invalid_closeout_fields'], true), $failures);
}
foreach (array('do_not_rerun','fix_items') as $field) {
	$case = $validCloseout; $case[$field] = 'not-array';
	expect_true('wrong-type closeout fields invalid '.$field, in_array($field, BadpoolGuardAutomationContracts::validateCloseout($case)['invalid_closeout_fields'], true), $failures);
}
$missing = $validCloseout; unset($missing['classification']);
expect_true('missing closeout fields reported', in_array('classification', BadpoolGuardAutomationContracts::validateCloseout($missing)['missing_closeout_fields'], true), $failures);

$finalized = BadpoolGuardReport::finalize(array_merge($validCloseout, array('command'=>'payout-row-apply')));
expect_true('finalize path adds automation contract for known closeout', isset($finalized['automation_contract']['closeout_validation']['closeout_valid']) && $finalized['automation_contract']['closeout_validation']['closeout_valid'] === true, $failures);
$unknown = BadpoolGuardReport::finalize(array_merge($validCloseout, array('command'=>'unknown-command')));
expect_true('unknown-command/global schema behavior is restricted', !isset($unknown['automation_contract']), $failures);
expect_error('required source file unreadable/missing for accounts.userid scan', BadpoolGuardAutomationContracts::accountsUseridScanSource(dirname(__FILE__).'/missing-source.php'), 'required_source_file_unreadable', $failures);

if (!empty($failures)) {
	echo "FAIL\n".implode("\n", $failures)."\n";
	exit(1);
}
echo "PASS badpool guardrail report/parser hardening harness\n";
