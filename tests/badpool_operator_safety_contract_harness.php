<?php

$root = dirname(__DIR__);
$commandPath = $root.'/web/yaamp/commands/BadpoolGuardCommand.php';
$contextPath = $root.'/web/yaamp/core/backend/BadpoolGuardContext.php';
$reportPath = $root.'/web/yaamp/core/backend/BadpoolGuardReport.php';
$command = is_file($commandPath) ? file_get_contents($commandPath) : '';
$context = is_file($contextPath) ? file_get_contents($contextPath) : '';
$failures = array();

require_once($reportPath);

function operator_safety_fail($label, $message, &$failures)
{
	$failures[] = $label.': '.$message;
}

function operator_safety_expect($condition, $label, $message, &$failures)
{
	if (!$condition) operator_safety_fail($label, $message, $failures);
}

function operator_safety_method_source($source, $method)
{
	$start = strpos($source, 'private function '.$method);
	if ($start === false) return '';
	$end = strpos($source, 'private function ', $start + strlen('private function '.$method));
	return $end === false ? substr($source, $start) : substr($source, $start, $end - $start);
}

function operator_safety_assert_metadata($report, $expected, $label, &$failures)
{
	foreach ($expected as $field => $value) {
		if (!array_key_exists($field, $report)) {
			operator_safety_fail($label, 'missing '.$field, $failures);
			continue;
		}
		if ($report[$field] !== $value) operator_safety_fail($label, $field.' expected '.var_export($value, true).', got '.var_export($report[$field], true), $failures);
	}
}

operator_safety_expect($command !== '', 'command source', 'unable to read '.$commandPath, $failures);
operator_safety_expect($context !== '', 'context source', 'unable to read '.$contextPath, $failures);
operator_safety_expect(strpos($command, "const OPERATOR_WEB_CWD = '/srv/badpool/yiimp-badpool/web';") !== false, 'operator web cwd constant', 'missing canonical production web path', $failures);
operator_safety_expect(strpos($command, "\$report['apply_command'] = implode(' ', \$report['apply_command_args']);") !== false, 'operator apply command string', 'apply_command is not derived from apply_command_args', $failures);

$safePrefix = "/array\\s*\\(\\s*'cd'\\s*,\\s*self::OPERATOR_WEB_CWD\\s*,\\s*'&&'\\s*,\\s*'php'\\s*,\\s*'yaamp\\/yiic\\.php'/";
foreach (array(
	'forwardCatchupStage1ApplyApprovalPackageReport',
	'payoutRowApprovalPackageReport',
	'walletSendApprovalPackageReport',
	'earningsMaturityTransitionApprovalPackageReport',
	'accountCreditClearApprovalPackageReport',
) as $method) {
	$source = operator_safety_method_source($command, $method);
	operator_safety_expect($source !== '', $method, 'method source not found', $failures);
	operator_safety_expect(preg_match($safePrefix, $source) === 1, $method, 'generated apply command does not begin with cd /srv/badpool/yiimp-badpool/web && php yaamp/yiic.php', $failures);
}

operator_safety_expect(strpos($context, "const SCHEMA = 'badpool.guardrail.preview.v1';") !== false, 'preview schema source', 'canonical preview schema changed or disappeared', $failures);
operator_safety_expect(strpos($context, "const MODE = 'read-only-preview';") !== false, 'preview mode source', 'canonical preview mode changed or disappeared', $failures);
operator_safety_expect(strpos($context, "'read_only' => true") !== false || strpos($context, "'read_only'=>true") !== false, 'preview read_only source', 'base preview report is not explicitly read-only', $failures);

$payoutApply = BadpoolGuardReport::finalize(array(
	'schema'=>'badpool.guardrail.preview.v1', 'mode'=>'read-only-preview', 'command'=>'payout-row-apply',
	'status'=>'pass', 'read_only'=>true, 'db_mutations'=>'guarded_transaction_committed', 'wallet_sends'=>false,
));
operator_safety_assert_metadata($payoutApply, array(
	'schema'=>'badpool.guardrail.apply.v1', 'mode'=>'guarded-apply', 'read_only'=>false,
	'db_mutations'=>true, 'wallet_rpc_send_performed'=>false,
), 'payout-row apply metadata', $failures);
operator_safety_expect(isset($payoutApply['db_mutation_status']) && $payoutApply['db_mutation_status'] === 'guarded_transaction_committed', 'payout-row mutation detail', 'descriptive DB mutation status was not preserved', $failures);

$walletApply = BadpoolGuardReport::finalize(array(
	'schema'=>'badpool.guardrail.preview.v1', 'mode'=>'read-only-preview', 'command'=>'wallet-send-apply',
	'status'=>'pass', 'read_only'=>true, 'db_mutations'=>'guarded_transaction_committed',
	'wallet_sends'=>true, 'wallet_rpc_send_performed'=>true,
));
operator_safety_assert_metadata($walletApply, array(
	'schema'=>'badpool.guardrail.apply.v1', 'mode'=>'guarded-apply', 'read_only'=>false,
	'db_mutations'=>true, 'wallet_rpc_send_performed'=>true,
), 'wallet-send apply metadata', $failures);

$refusedApply = BadpoolGuardReport::finalize(array(
	'schema'=>'badpool.guardrail.preview.v1', 'mode'=>'read-only-preview', 'command'=>'payout-row-apply',
	'status'=>'refused', 'read_only'=>true, 'db_mutations'=>'guarded_transaction_only', 'wallet_sends'=>false,
));
operator_safety_assert_metadata($refusedApply, array(
	'schema'=>'badpool.guardrail.apply.v1', 'mode'=>'guarded-apply', 'read_only'=>false,
	'db_mutations'=>false, 'wallet_rpc_send_performed'=>false,
), 'refused apply metadata', $failures);

$postSendHold = BadpoolGuardReport::finalize(array(
	'schema'=>'badpool.guardrail.preview.v1', 'mode'=>'read-only-preview', 'command'=>'wallet-send-apply',
	'status'=>'hold', 'read_only'=>true, 'db_mutations'=>'failed_or_partial_rolled_back',
	'wallet_sends'=>true, 'wallet_rpc_send_performed'=>true,
));
operator_safety_assert_metadata($postSendHold, array(
	'schema'=>'badpool.guardrail.apply.v1', 'mode'=>'guarded-apply', 'read_only'=>false,
	'db_mutations'=>false, 'wallet_rpc_send_performed'=>true,
), 'post-send HOLD metadata', $failures);
operator_safety_expect(isset($postSendHold['db_mutation_status']) && $postSendHold['db_mutation_status'] === 'failed_or_partial_rolled_back', 'post-send HOLD mutation detail', 'failure/rollback detail was not preserved', $failures);

$dryrun = BadpoolGuardReport::finalize(array(
	'schema'=>'badpool.guardrail.apply.v1', 'mode'=>'guarded-apply', 'command'=>'wallet-send-dryrun',
	'status'=>'ok', 'read_only'=>false, 'db_mutations'=>true, 'wallet_rpc_send_performed'=>true,
));
operator_safety_assert_metadata($dryrun, array(
	'schema'=>'badpool.guardrail.preview.v1', 'mode'=>'read-only-preview', 'read_only'=>true,
	'db_mutations'=>false, 'wallet_rpc_send_performed'=>false,
), 'wallet-send dryrun metadata', $failures);

$drainPlan = BadpoolGuardReport::finalize(array(
	'schema'=>'badpool.guardrail.apply.v1', 'mode'=>'stage1-drain-plan', 'command'=>'forward-catchup-stage1-drain-plan',
	'status'=>'pass', 'read_only'=>true, 'db_mutations'=>false,
));
operator_safety_assert_metadata($drainPlan, array(
	'schema'=>'badpool.guardrail.preview.v1', 'mode'=>'read-only-preview', 'read_only'=>true,
	'db_mutations'=>false, 'wallet_rpc_send_performed'=>false,
), 'Stage1 drain plan metadata', $failures);

$drainApply = BadpoolGuardReport::finalize(array(
	'schema'=>'badpool.guardrail.preview.v1', 'mode'=>'read-only-preview', 'command'=>'forward-catchup-stage1-drain-apply',
	'status'=>'pass', 'read_only'=>true, 'db_mutations'=>false,
	'apply_commands_executed'=>true, 'batches_applied'=>2, 'wallet_sends'=>false,
));
operator_safety_assert_metadata($drainApply, array(
	'schema'=>'badpool.guardrail.apply.v1', 'mode'=>'guarded-apply', 'read_only'=>false,
	'db_mutations'=>true, 'wallet_rpc_send_performed'=>false,
), 'Stage1 drain apply metadata', $failures);

$approval = BadpoolGuardReport::finalize(array(
	'schema'=>'badpool.approval_package.v1', 'mode'=>'read-only-preview', 'command'=>'wallet-send-approval-package',
	'status'=>'ok', 'read_only'=>true, 'db_mutations'=>false, 'wallet_rpc_send_performed'=>false,
));
operator_safety_assert_metadata($approval, array(
	'schema'=>'badpool.approval_package.v1', 'mode'=>'read-only-preview', 'read_only'=>true,
	'db_mutations'=>false, 'wallet_rpc_send_performed'=>false,
), 'approval package metadata preservation', $failures);

if (!empty($failures)) {
	echo "Badpool operator safety contract harness FAILED\n";
	foreach ($failures as $failure) echo ' - '.$failure."\n";
	exit(1);
}

echo "Badpool operator safety contract harness passed\n";
