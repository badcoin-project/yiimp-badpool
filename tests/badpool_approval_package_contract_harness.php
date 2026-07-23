<?php

$root = dirname(__DIR__);
$commandPath = $root.'/web/yaamp/commands/BadpoolGuardCommand.php';
$command = is_file($commandPath) ? file_get_contents($commandPath) : '';
$failures = array();

function contract_fail($label, $message, &$failures) { $failures[] = "$label: $message"; }
function expect_contains_contract($label, $haystack, $needle, &$failures) { if (strpos($haystack, $needle) === false) contract_fail($label, "missing expected text: $needle", $failures); }


function stable_contract_checksum($report, $keys)
{
	$in = array();
	foreach ($keys as $key) $in[$key] = array_key_exists($key, $report) ? $report[$key] : null;
	return hash('sha256', json_encode($in, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function standardize_contract_fixture(&$report, $packageType, $checksumFields)
{
	if (!isset($report['apply_command_args'])) $report['apply_command_args'] = isset($report['apply_command_shape']) ? $report['apply_command_shape'] : array();
	if (!isset($report['apply_command'])) $report['apply_command'] = implode(' ', $report['apply_command_args']);
	$records = array();
	foreach ($report['items']['selected_earnings'] as $item) {
		if ($packageType === 'earnings-maturity-transition') {
			$records[] = array('earning_id'=>$item['earning_id'],'block_id'=>$item['linked_block_id'],'height'=>$item['block_height'],'account_id'=>$item['userid'],'amount'=>$item['amount'],'current_earning_status'=>$item['current_earning_status'],'current_block_category'=>$item['current_block_category'],'expected_post_apply_earning_status'=>$item['projected_earnings_status'],'expected_post_apply_block_category'=>$item['projected_block_category']);
		} else {
			$records[] = array('earning_id'=>$item['earning_id'],'account_id'=>$item['account_id'],'amount'=>$item['amount'],'current_status'=>$item['current_earning_status'],'expected_post_apply_status'=>2,'expected_post_apply_account_delta'=>$item['projected_converted_credit_value']);
		}
	}
	$report['selected_records'] = $records;
	$report['selected_count'] = count($records);
	$total = 0.0;
	foreach ($records as $record) $total += floatval($packageType === 'account-credit-clear' ? $record['expected_post_apply_account_delta'] : $record['amount']);
	$report['selected_amount'] = sprintf('%.12F', $total);
	$checksums = array();
	foreach ($checksumFields as $field) {
		if (!array_key_exists($field, $report)) continue;
		$value = $report[$field];
		if (is_array($value) && array_key_exists('value', $value)) $value = $value['value'];
		if ($value !== null && $value !== '') $checksums[$field] = $value;
	}
	$report['checksums'] = $checksums;
}

function clear_finalized_contract_fixture(&$report)
{
	unset($report['approval_package_checksum']);
	unset($report['checksums']);
	unset($report['apply_command']);
	unset($report['apply_command_args']);
	unset($report['selected_records']);
	unset($report['selected_count']);
	unset($report['selected_amount']);
}

function replace_fixture_ids($command, $ids)
{
	$out = array();
	foreach ($command as $arg) $out[] = preg_match('/^--selected-earning-ids=/', $arg) ? '--selected-earning-ids='.implode(',', $ids) : $arg;
	return $out;
}

function checksum_value($value) { return array('value'=>hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))); }

function account_fixture_package($ids)
{
	$items = array();
	foreach ($ids as $id) $items[] = array('earning_id'=>$id,'userid'=>7,'coinid'=>1,'blockid'=>100 + $id,'amount'=>'1.500000000000','current_earning_status'=>1,'mature_time'=>123456,'coin_price'=>'1.000000000000','projected_converted_credit_value'=>'1.500000000000','account_id'=>7,'account_coinid'=>1,'current_account_balance'=>'10.000000000000','projected_account_balance'=>'11.500000000000');
	$report = array('approval_package_type'=>'account-credit-clear','scope'=>array('coin_id'=>1),'items'=>array('selected_earnings'=>$items),'apply_command_shape'=>array('php','yaamp/yiic.php','badpoolguard','account-credit-clear-apply','--coin-id=1','--selected-earning-ids='.implode(',', $ids),'--approval-package-checksum=<approval_package_checksum>'),'apply_scope_binding'=>'fixture');
	$report['selected_earnings_scope_checksum'] = checksum_value(array('coin_id'=>1,'earnings'=>$items));
	$report['projected_earnings_mutation_checksum'] = checksum_value(array_map(function($i){ return array('earning_id'=>$i['earning_id'],'from_status'=>1,'to_status'=>2,'price'=>$i['coin_price']); }, $items));
	$report['projected_account_credit_checksum'] = checksum_value(array_map(function($i){ return array('earning_id'=>$i['earning_id'],'account_id'=>$i['account_id'],'credit'=>$i['projected_converted_credit_value'],'from_balance'=>$i['current_account_balance'],'to_balance'=>$i['projected_account_balance']); }, $items));
	standardize_contract_fixture($report, 'account-credit-clear', array('approval_package_checksum','selected_earnings_scope_checksum','projected_earnings_mutation_checksum','projected_account_credit_checksum'));
	$report['approval_package_checksum'] = stable_contract_checksum($report, array('approval_package_type','scope','selected_earnings_scope_checksum','projected_earnings_mutation_checksum','projected_account_credit_checksum','items','apply_command_shape','apply_scope_binding','selected_records','checksums','apply_command_args'));
	standardize_contract_fixture($report, 'account-credit-clear', array('approval_package_checksum','selected_earnings_scope_checksum','projected_earnings_mutation_checksum','projected_account_credit_checksum'));
	return $report;
}

function maturity_fixture_package($ids)
{
	$items = array(); $blocks = array();
	foreach ($ids as $id) { $items[] = array('earning_id'=>$id,'linked_block_id'=>200 + $id,'block_height'=>1000 + $id,'userid'=>9,'coinid'=>1,'amount'=>'2.000000000000','current_earning_status'=>0,'current_block_category'=>'immature','projected_earnings_status'=>1,'projected_block_category'=>'generate'); $blocks[] = array('block_id'=>200 + $id,'height'=>1000 + $id,'coin_id'=>1,'current_category'=>'immature','projected_category'=>'generate'); }
	$report = array('approval_package_type'=>'earnings-maturity-transition','scope'=>array('coin_id'=>1),'items'=>array('selected_earnings'=>$items,'linked_blocks'=>$blocks),'apply_command_shape'=>array('php','yaamp/yiic.php','badpoolguard','earnings-maturity-transition-apply','--coin-id=1','--selected-earning-ids='.implode(',', $ids),'--approval-package-checksum=<approval_package_checksum>'),'apply_scope_binding'=>'fixture');
	$report['selected_scope_checksum'] = checksum_value(array('coin_id'=>1,'earnings'=>$items,'blocks'=>$blocks));
	$report['projected_block_mutation_checksum'] = checksum_value($blocks);
	$report['projected_earnings_mutation_checksum'] = checksum_value(array_map(function($i){ return array('earning_id'=>$i['earning_id'],'from_status'=>0,'to_status'=>1,'mature_time'=>'current_unix_timestamp_at_apply'); }, $items));
	standardize_contract_fixture($report, 'earnings-maturity-transition', array('approval_package_checksum','selected_scope_checksum','projected_block_mutation_checksum','projected_earnings_mutation_checksum'));
	$report['approval_package_checksum'] = stable_contract_checksum($report, array('approval_package_type','scope','selected_scope_checksum','projected_block_mutation_checksum','projected_earnings_mutation_checksum','items','apply_command_shape','apply_scope_binding','selected_records','checksums','apply_command_args'));
	standardize_contract_fixture($report, 'earnings-maturity-transition', array('approval_package_checksum','selected_scope_checksum','projected_block_mutation_checksum','projected_earnings_mutation_checksum'));
	return $report;
}

function recompute_account_fixture($full, $ids, $clear)
{
	$report = $full;
	if ($clear) clear_finalized_contract_fixture($report);
	$items = array_values(array_filter($report['items']['selected_earnings'], function($i) use ($ids) { return in_array($i['earning_id'], $ids, true); }));
	$report['items']['selected_earnings'] = $items;
	$report['apply_command_shape'] = replace_fixture_ids($report['apply_command_shape'], $ids);
	$report['selected_earnings_scope_checksum'] = checksum_value(array('coin_id'=>1,'earnings'=>$items));
	$report['projected_earnings_mutation_checksum'] = checksum_value(array_map(function($i){ return array('earning_id'=>$i['earning_id'],'from_status'=>1,'to_status'=>2,'price'=>$i['coin_price']); }, $items));
	$report['projected_account_credit_checksum'] = checksum_value(array_map(function($i){ return array('earning_id'=>$i['earning_id'],'account_id'=>$i['account_id'],'credit'=>$i['projected_converted_credit_value'],'from_balance'=>$i['current_account_balance'],'to_balance'=>$i['projected_account_balance']); }, $items));
	standardize_contract_fixture($report, 'account-credit-clear', array('approval_package_checksum','selected_earnings_scope_checksum','projected_earnings_mutation_checksum','projected_account_credit_checksum'));
	$report['approval_package_checksum'] = stable_contract_checksum($report, array('approval_package_type','scope','selected_earnings_scope_checksum','projected_earnings_mutation_checksum','projected_account_credit_checksum','items','apply_command_shape','apply_scope_binding','selected_records','checksums','apply_command_args'));
	standardize_contract_fixture($report, 'account-credit-clear', array('approval_package_checksum','selected_earnings_scope_checksum','projected_earnings_mutation_checksum','projected_account_credit_checksum'));
	return $report;
}

function recompute_maturity_fixture($full, $ids)
{
	$report = $full; clear_finalized_contract_fixture($report);
	$items = array_values(array_filter($report['items']['selected_earnings'], function($i) use ($ids) { return in_array($i['earning_id'], $ids, true); }));
	$blockIds = array(); foreach ($items as $item) $blockIds[$item['linked_block_id']] = true;
	$blocks = array_values(array_filter($report['items']['linked_blocks'], function($b) use ($blockIds) { return isset($blockIds[$b['block_id']]); }));
	$report['items']['selected_earnings'] = $items; $report['items']['linked_blocks'] = $blocks;
	$report['apply_command_shape'] = replace_fixture_ids($report['apply_command_shape'], $ids);
	$report['selected_scope_checksum'] = checksum_value(array('coin_id'=>1,'earnings'=>$items,'blocks'=>$blocks));
	$report['projected_block_mutation_checksum'] = checksum_value($blocks);
	$report['projected_earnings_mutation_checksum'] = checksum_value(array_map(function($i){ return array('earning_id'=>$i['earning_id'],'from_status'=>0,'to_status'=>1,'mature_time'=>'current_unix_timestamp_at_apply'); }, $items));
	standardize_contract_fixture($report, 'earnings-maturity-transition', array('approval_package_checksum','selected_scope_checksum','projected_block_mutation_checksum','projected_earnings_mutation_checksum'));
	$report['approval_package_checksum'] = stable_contract_checksum($report, array('approval_package_type','scope','selected_scope_checksum','projected_block_mutation_checksum','projected_earnings_mutation_checksum','items','apply_command_shape','apply_scope_binding','selected_records','checksums','apply_command_args'));
	standardize_contract_fixture($report, 'earnings-maturity-transition', array('approval_package_checksum','selected_scope_checksum','projected_block_mutation_checksum','projected_earnings_mutation_checksum'));
	return $report;
}

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

$stage1Start = strpos($command, 'private function forwardCatchupStage1ApplyApprovalPackageReport');
$stage1End = strpos($command, 'private function forwardCatchupStage1ApplyReport', $stage1Start);
$stage1Source = ($stage1Start === false || $stage1End === false) ? '' : substr($command, $stage1Start, $stage1End - $stage1Start);
$stage1ChecksumAssign = strpos($stage1Source, '$report[\'approval_package_checksum\'] = $this->forwardCatchupStage1StableApprovalPackageChecksum($report);');
$stage1ReportChecksumAssign = strpos($stage1Source, '$report[\'report_checksum\'] = BadpoolGuardReport::checksum($report);', $stage1ChecksumAssign);
$stage1AfterApprovalChecksum = ($stage1ChecksumAssign === false || $stage1ReportChecksumAssign === false) ? '' : substr($stage1Source, $stage1ChecksumAssign, $stage1ReportChecksumAssign - $stage1ChecksumAssign);
expect_contains_contract('Stage1 re-standardizes after final approval checksum assignment', $stage1AfterApprovalChecksum, 'standardizeApprovalPackageContract($report, \'forward-catchup-stage1-apply\'', $failures);
expect_contains_contract('Stage1 top-level checksums include final approval_package_checksum field', $stage1AfterApprovalChecksum, "'approval_package_checksum','batch_scope_checksum'", $failures);

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


foreach (array('earningsMaturityTransitionApprovalForIds','accountCreditClearApprovalForIds') as $helper) {
	$start = strpos($command, 'private function '.$helper);
	$end = strpos($command, 'private function ', $start + 1);
	$source = ($start === false || $end === false) ? '' : substr($command, $start, $end - $start);
	$clearPos = strpos($source, 'clearFinalizedApprovalPackageContractFields($report);');
	$standardizePos = strpos($source, 'standardizeApprovalPackageContract($report');
	if ($clearPos === false) contract_fail($helper.' clears stale finalized package fields', 'missing clearFinalizedApprovalPackageContractFields call', $failures);
	elseif ($standardizePos === false || $clearPos > $standardizePos) contract_fail($helper.' clears before standardize', 'clear must precede first standardizeApprovalPackageContract call', $failures);
}
$clearStart = strpos($command, 'private function clearFinalizedApprovalPackageContractFields');
$clearEnd = strpos($command, 'private function earningsMaturityTransitionApprovalForIds', $clearStart);
$clearSource = ($clearStart === false || $clearEnd === false) ? '' : substr($command, $clearStart, $clearEnd - $clearStart);
foreach (array('approval_package_checksum','checksums','apply_command','apply_command_args','selected_records','selected_count','selected_amount') as $field) expect_contains_contract('clear finalized field '.$field, $clearSource, "unset(\$report['$field']);", $failures);

$accountFull = account_fixture_package(array(30,31,32));
$accountRecomputed = recompute_account_fixture($accountFull, array(30,31,32), true);
if ($accountRecomputed['approval_package_checksum'] !== $accountFull['approval_package_checksum']) contract_fail('account-credit package/recompute equality', 'approval_package_checksum mismatch for unchanged selected IDs', $failures);
$maturityFull = maturity_fixture_package(array(20,21));
$maturityRecomputed = recompute_maturity_fixture($maturityFull, array(20,21));
if ($maturityRecomputed['approval_package_checksum'] !== $maturityFull['approval_package_checksum']) contract_fail('maturity package/recompute equality', 'approval_package_checksum mismatch for unchanged selected IDs', $failures);

$subsetFresh = account_fixture_package(array(30,31));
$staleFiltered = recompute_account_fixture($accountFull, array(30,31), false);
$cleanFiltered = recompute_account_fixture($accountFull, array(30,31), true);
if ($cleanFiltered['approval_package_checksum'] !== $subsetFresh['approval_package_checksum']) contract_fail('stale contract surface guard clean recompute', 'clean filtered recompute should match fresh subset package', $failures);
if ($staleFiltered['approval_package_checksum'] === $cleanFiltered['approval_package_checksum']) contract_fail('stale contract surface guard contamination proof', 'uncleared stale checksums/apply args should change fixture checksum', $failures);
if ($cleanFiltered['checksums']['approval_package_checksum'] !== $cleanFiltered['approval_package_checksum']) contract_fail('stale nested approval checksum refreshed', 'nested approval checksum was not refreshed', $failures);
if (strpos($cleanFiltered['apply_command'], '--selected-earning-ids=30,31') === false) contract_fail('command surface refresh apply_command', 'apply_command did not refresh selected IDs', $failures);
if (!in_array('--selected-earning-ids=30,31', $cleanFiltered['apply_command_args'], true)) contract_fail('command surface refresh apply_command_args', 'apply_command_args did not refresh selected IDs', $failures);
if ($staleFiltered['apply_command'] === $cleanFiltered['apply_command']) contract_fail('command surface stale proof', 'uncleared apply_command should remain stale in fixture', $failures);

$generatedArgs = $accountFull['apply_command_args'];
$generatedArgs = array_map(function($arg) use ($accountFull) { return $arg === '--approval-package-checksum=<approval_package_checksum>' ? '--approval-package-checksum='.$accountFull['approval_package_checksum'] : $arg; }, $generatedArgs);
$approvalArgOk = in_array('--approval-package-checksum='.$accountRecomputed['approval_package_checksum'], $generatedArgs, true);
$scopeArgOk = in_array('--selected-earning-ids=30,31,32', $generatedArgs, true);
if (!$approvalArgOk || !$scopeArgOk) contract_fail('account-credit apply checksum validation accepts fresh command fixture', 'generated apply command args do not match recomputed approval checksum and selected IDs', $failures);

if (!empty($failures)) {
	echo "Badpool approval package contract harness FAILED\n";
	foreach ($failures as $failure) echo " - $failure\n";
	exit(1);
}

echo "Badpool approval package contract harness passed\n";
