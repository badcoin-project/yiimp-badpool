<?php

$root = dirname(__DIR__);
$commandPath = $root.'/web/yaamp/commands/BadpoolGuardCommand.php';
$command = is_file($commandPath) ? file_get_contents($commandPath) : '';
$failures = array();

function contract_fail($label, $message, &$failures) { $failures[] = "$label: $message"; }
function expect_contains_contract($label, $haystack, $needle, &$failures) { if (strpos($haystack, $needle) === false) contract_fail($label, "missing expected text: $needle", $failures); }

function validate_contract($package, $expectedType, $requiredRecordFields)
{
	foreach (array('schema','package_type','coin_id','selected_count','selected_records','checksums') as $field) {
		if (!array_key_exists($field, $package)) return "missing $field";
	}
	if (!array_key_exists('apply_command', $package) && !array_key_exists('apply_command_args', $package)) return 'missing apply command';
	if ($package['package_type'] !== $expectedType) return 'wrong package_type';
	if (!is_array($package['selected_records'])) return 'selected_records not array';
	if (intval($package['selected_count']) !== count($package['selected_records'])) return 'selected_count mismatch';
	if (intval($package['selected_count']) > 0 && count($package['selected_records']) === 0) return 'empty selected_records with nonzero selected_count';
	if (!is_array($package['checksums']) || empty($package['checksums'])) return 'missing checksums';
	foreach ($package['selected_records'] as $record) {
		foreach ($requiredRecordFields as $field) {
			if (!array_key_exists($field, $record) || $record[$field] === null || $record[$field] === '') return "missing record field $field";
		}
	}
	return true;
}

if ($command === '') contract_fail('command source', "unable to read $commandPath", $failures);

foreach (array('schema','package_type','selected_records','checksums','apply_command_args','standardizeApprovalPackageContract','approvalPackageSelectedRecords','stage1ApprovalSelectedRecords') as $needle) {
	expect_contains_contract("source exposes top-level contract: $needle", $command, $needle, $failures);
}

$stage1 = array('schema'=>'badpool.approval_package.v1','package_type'=>'forward-catchup-stage1-apply','coin_id'=>1,'selected_count'=>1,'selected_amount'=>'1.000000000000','selected_records'=>array(array('block_id'=>10,'height'=>100,'current_state'=>'new','current_category'=>'new','projected_earning_amount'=>'1.000000000000')),'checksums'=>array('approval_package_checksum'=>'abc'),'apply_command_args'=>array('php','yaamp/yiic.php','badpoolguard'));
$maturity = array('schema'=>'badpool.approval_package.v1','package_type'=>'earnings-maturity-transition','coin_id'=>1,'selected_count'=>1,'selected_amount'=>'2.000000000000','selected_records'=>array(array('earning_id'=>20,'block_id'=>10,'height'=>100,'account_id'=>7,'amount'=>'2.000000000000','current_earning_status'=>0,'current_block_category'=>'immature','expected_post_apply_earning_status'=>1,'expected_post_apply_block_category'=>'generate')),'checksums'=>array('approval_package_checksum'=>'abc'),'apply_command'=>'php yaamp/yiic.php badpoolguard earnings-maturity-transition-apply');
$account = array('schema'=>'badpool.approval_package.v1','package_type'=>'account-credit-clear','coin_id'=>1,'selected_count'=>1,'selected_amount'=>'3.000000000000','selected_records'=>array(array('earning_id'=>30,'account_id'=>7,'amount'=>'3.000000000000','current_status'=>1,'expected_post_apply_status'=>2,'expected_post_apply_account_delta'=>'3.000000000000')),'checksums'=>array('approval_package_checksum'=>'abc'),'apply_command'=>'php yaamp/yiic.php badpoolguard account-credit-clear-apply');

$cases = array(
	array('stage1 valid', $stage1, 'forward-catchup-stage1-apply', array('block_id','height','current_state','projected_earning_amount')),
	array('maturity valid', $maturity, 'earnings-maturity-transition', array('earning_id','block_id','height','account_id','amount','current_earning_status','current_block_category','expected_post_apply_earning_status','expected_post_apply_block_category')),
	array('account valid', $account, 'account-credit-clear', array('earning_id','account_id','amount','current_status','expected_post_apply_status','expected_post_apply_account_delta')),
);
foreach ($cases as $case) {
	$result = validate_contract($case[1], $case[2], $case[3]);
	if ($result !== true) contract_fail($case[0], $result, $failures);
}

$negativeCases = array(
	array('missing selected_records', array_diff_key($stage1, array('selected_records'=>true)), 'forward-catchup-stage1-apply', array('block_id','height'), 'missing selected_records'),
	array('empty selected_records nonzero count', array_merge($stage1, array('selected_records'=>array())), 'forward-catchup-stage1-apply', array('block_id','height'), 'selected_count mismatch'),
	array('selected_count mismatch', array_merge($stage1, array('selected_count'=>2)), 'forward-catchup-stage1-apply', array('block_id','height'), 'selected_count mismatch'),
	array('missing checksums', array_diff_key($stage1, array('checksums'=>true)), 'forward-catchup-stage1-apply', array('block_id','height'), 'missing checksums'),
	array('missing apply command', array_diff_key($stage1, array('apply_command_args'=>true,'apply_command'=>true)), 'forward-catchup-stage1-apply', array('block_id','height'), 'missing apply command'),
	array('wrong package_type', array_merge($stage1, array('package_type'=>'wrong')), 'forward-catchup-stage1-apply', array('block_id','height'), 'wrong package_type'),
	array('stage1 missing height', array_merge($stage1, array('selected_records'=>array(array('block_id'=>10,'current_state'=>'new')))), 'forward-catchup-stage1-apply', array('block_id','height'), 'missing record field height'),
	array('maturity missing IDs heights', array_merge($maturity, array('selected_records'=>array(array('earning_id'=>20,'amount'=>'2.0')))), 'earnings-maturity-transition', array('earning_id','block_id','height'), 'missing record field block_id'),
);
foreach ($negativeCases as $case) {
	$result = validate_contract($case[1], $case[2], $case[3]);
	if ($result !== $case[4]) contract_fail($case[0], "expected {$case[4]}, got ".var_export($result, true), $failures);
}

if (!empty($failures)) {
	echo "Badpool approval package contract harness FAILED\n";
	foreach ($failures as $failure) echo " - $failure\n";
	exit(1);
}

echo "Badpool approval package contract harness passed\n";
