<?php
$root = dirname(__DIR__);
require_once($root.'/web/yaamp/core/backend/BadpoolStage1Manifest.php');
if (!class_exists('CConsoleCommand')) { class CConsoleCommand {} }
if (!function_exists('arraySafeVal')) { function arraySafeVal($array, $key, $default=null) { return is_array($array) && array_key_exists($key, $array) ? $array[$key] : $default; } }
require_once($root.'/web/yaamp/commands/BadpoolGuardCommand.php');
$commandSource = file_get_contents($root.'/web/yaamp/commands/BadpoolGuardCommand.php');
$failures = array();

class IntegrationGuardStub
{
	public $errors = array();
	public function addError($message) { $this->errors[] = $message; }
}

function integration_expect($condition, $message, &$failures)
{
	if (!$condition) $failures[] = $message;
}

function integration_fixture()
{
	$record = array('block_id'=>10000, 'height'=>1900000, 'time'=>1800000000, 'blockhash'=>str_repeat('a',64), 'userid'=>200, 'workerid'=>300, 'current_category'=>'new', 'classification'=>'stage1_import_generate', 'projected_txhash'=>str_repeat('b',64), 'projected_block_category'=>'immature', 'projected_earning_amount'=>'1.000000000000', 'attribution_model'=>'block_userid_single_recipient');
	$mutation = array('blockid'=>10000, 'height'=>1900000, 'classification'=>'stage1_import_generate', 'would_set_txhash'=>str_repeat('b',64), 'would_set_category'=>'immature', 'would_set_amount'=>'1.000000000000');
	$earning = array('userid'=>200, 'coinid'=>1267, 'blockid'=>10000, 'create_time'=>1800000000, 'amount'=>'1.000000000000', 'status'=>0, 'mature_time'=>null, 'attribution_model'=>'block_userid_single_recipient', 'attribution_model_requires_operator_confirmation'=>true, 'historical_evidence_mixed'=>true, 'backendblocknew_not_used'=>true, 'fee_policy'=>'not_applied_in_dryrun');
	$snapshot = array('checkpoint_last_payout_time'=>1784860501, 'checkpoint_source'=>'latest_completed_payout', 'candidate_query_completed_before_apply'=>true, 'maximum_selected_order_key'=>array('height'=>1900000,'time'=>1800000000,'id'=>10000), 'maximum_eligible_snapshot_order_key'=>array('height'=>1900000,'time'=>1800000000,'id'=>10000), 'selection_limit'=>1, 'eligible_candidate_count'=>1, 'excluded_by_selection_limit_count'=>0, 'new_candidates_after_snapshot_are_excluded'=>true, 'post_snapshot_candidates_are_separately_counted'=>true);
	return BadpoolStage1Manifest::build(1267, 'scrypt', $snapshot, 1, 1, 0, 0, array($record), array($mutation), array($earning), 25);
}

$manifest = integration_fixture();
$runtime = array('manifest-file'=>'/tmp/stage1-contract-fixture.json', 'progress-file'=>'/tmp/stage1-contract-progress.json');
$argv = BadpoolStage1Manifest::renderApplyArgv($manifest, $runtime);
integration_expect(array_slice($argv, 0, 4) === array('php','yaamp/yiic.php','badpoolguard',BadpoolStage1Manifest::APPLY_COMMAND), 'rendered argv command prefix differs from the structured manifest command', $failures);

$options = BadpoolStage1Manifest::parseApplyOptions(array_slice($argv, 4));
$optionValidation = BadpoolStage1Manifest::validateApplyOptions($options);
integration_expect(!isset($options['__parse_error']) && $optionValidation['status'] === 'pass', 'rendered structured argv must pass the real apply parser and required-option validation', $failures);
integration_expect(array_keys($options) === array_keys($manifest['apply_command_args']), 'every emitted option must be accepted in canonical order', $failures);
integration_expect(array_keys($manifest['apply_command_args']) === array_keys(BadpoolStage1Manifest::applyOptionContract()), 'every parser-required option must appear in the generated contract', $failures);
integration_expect($options['manifest-file'] === $runtime['manifest-file'] && $options['progress-file'] === $runtime['progress-file'], 'runtime paths must be supplied without entering manifest authority', $failures);
integration_expect($options['coin-id'] === (string)$manifest['coin_id'] && $options['package-checksum'] === $manifest['package_checksum']['value'] && $options['operator-confirms-stage1-drain'] === $manifest['exact_operator_confirmation'], 'authority values must resolve from canonical manifest fields', $failures);

$authorization = BadpoolStage1Manifest::validateApplyAuthorization($manifest, $options['package-checksum'], $options['operator-confirms-stage1-drain'], intval($options['coin-id']));
integration_expect($authorization['status'] === 'pass' && $authorization['post_manifest_validation'] === true, 'real parsed argv must reach and pass manifest validation without database or wallet access', $failures);

foreach (array('--manifest=/tmp/guessed.json','--confirmation=guessed') as $alias) {
	$aliasOptions = BadpoolStage1Manifest::parseApplyOptions(array_merge(array_slice($argv, 4), array($alias)));
	$aliasValidation = BadpoolStage1Manifest::validateApplyOptions($aliasOptions);
	integration_expect($aliasValidation['status'] === 'fail' && $aliasValidation['reason'] === 'invalid_option', 'guessed alias must be rejected as invalid_option: '.$alias, $failures);
}

$missingExpected = array('manifest-file'=>'manifest_file_required', 'progress-file'=>'progress_file_required', 'package-checksum'=>'package_checksum_required', 'operator-confirms-stage1-drain'=>'operator_confirmation_required');
foreach ($missingExpected as $name => $reason) {
	$missing = $options; unset($missing[$name]);
	$result = BadpoolStage1Manifest::validateApplyOptions($missing);
	integration_expect($result['status'] === 'fail' && $result['reason'] === $reason, 'missing --'.$name.' must retain its stable refusal reason', $failures);
	$expectedClassification = in_array($name, array('manifest-file','progress-file'), true) ? 'invocation_refusal' : 'authorization_refusal';
	integration_expect(BadpoolStage1Manifest::classifyApplyResult($result['reason'], 0, 0) === $expectedClassification, 'missing --'.$name.' classification mismatch', $failures);
}

$missingCoin = $options; unset($missingCoin['coin-id']);
$missingCoinResult = BadpoolStage1Manifest::validateApplyOptions($missingCoin);
integration_expect($missingCoinResult['reason'] === 'coin_id_required' && BadpoolStage1Manifest::classifyApplyResult($missingCoinResult['reason'], 0, 0) === 'invocation_refusal', 'missing --coin-id must be invocation_refusal', $failures);
$invalidFormat = $options; $invalidFormat['format'] = 'text';
$invalidFormatResult = BadpoolStage1Manifest::validateApplyOptions($invalidFormat);
integration_expect($invalidFormatResult['reason'] === 'json_format_required' && BadpoolStage1Manifest::classifyApplyResult($invalidFormatResult['reason'], 0, 0) === 'invocation_refusal', 'invalid output format must be invocation_refusal', $failures);
$invalidChecksum = $options; $invalidChecksum['package-checksum'] = 'nope';
$invalidChecksumResult = BadpoolStage1Manifest::validateApplyOptions($invalidChecksum);
integration_expect($invalidChecksumResult['reason'] === 'invalid_package_checksum' && BadpoolStage1Manifest::classifyApplyResult($invalidChecksumResult['reason'], 0, 0) === 'authorization_refusal', 'invalid package checksum must be authorization_refusal', $failures);

$badConfirmation = BadpoolStage1Manifest::validateApplyAuthorization($manifest, $manifest['package_checksum']['value'], 'wrong', 1267);
integration_expect($badConfirmation['stop_reason'] === 'operator_confirmation_required' && BadpoolStage1Manifest::classifyApplyResult($badConfirmation['stop_reason'], 0, 0) === 'authorization_refusal', 'invalid confirmation must be authorization_refusal', $failures);
$coinMismatch = BadpoolStage1Manifest::validateApplyAuthorization($manifest, $manifest['package_checksum']['value'], $manifest['exact_operator_confirmation'], 9999);
integration_expect($coinMismatch['stop_reason'] === 'coin_scope_mismatch' && BadpoolStage1Manifest::classifyApplyResult($coinMismatch['stop_reason'], 0, 0) === 'authorization_refusal', 'manifest/CLI coin authority mismatch must be authorization_refusal', $failures);
$tamperedManifest = unserialize(serialize($manifest)); $tamperedManifest['apply_command'] = 'tampered';
$tamperedAuthorization = BadpoolStage1Manifest::validateApplyAuthorization($tamperedManifest, $manifest['package_checksum']['value'], $manifest['exact_operator_confirmation'], 1267);
integration_expect($tamperedAuthorization['stop_reason'] === 'manifest_validation_failed' && BadpoolStage1Manifest::classifyApplyResult($tamperedAuthorization['stop_reason'], 0, 0) === 'authorization_refusal', 'altered manifest must be authorization_refusal before a transaction', $failures);
integration_expect(BadpoolStage1Manifest::classifyApplyResult('mutation_failed_rolled_back', 1, 0, true, true) === 'transactional_failure', 'rolled-back first-batch failure must be transactional_failure', $failures);
integration_expect(BadpoolStage1Manifest::classifyApplyResult('post_apply_verification_failure', 2, 1, true) === 'partial_committed_failure', 'failure after a committed batch must be partial_committed_failure', $failures);
integration_expect(BadpoolStage1Manifest::classifyApplyResult(null, 2, 2, true, false, false, true) === 'successful_apply', 'fully reconciled completion must be successful_apply', $failures);
integration_expect(BadpoolStage1Manifest::classifyApplyResult('package_checksum_mismatch', 1, 0, true, true) !== 'authorization_refusal', 'authorization_refusal must never be reported after a transaction begins', $failures);

$realCommand = new BadpoolGuardCommand();
$helpOutput = $realCommand->getHelp();
integration_expect(strpos($helpOutput, implode(' ', BadpoolStage1Manifest::applyCommandShape())) !== false, 'runtime help output must render the exact shared command shape', $failures);
$helpOptionCount = 0; foreach (array_keys(BadpoolStage1Manifest::applyOptionContract()) as $name) if (strpos($helpOutput, '--'.$name.'=') !== false) $helpOptionCount++;
integration_expect($helpOptionCount === count(BadpoolStage1Manifest::applyOptionContract()), 'runtime help must expose every parser-required option', $failures);
$missingCoinArgv = array(BadpoolStage1Manifest::APPLY_COMMAND);
foreach (array_slice($argv, 4) as $arg) if (strpos($arg, '--coin-id=') !== 0) $missingCoinArgv[] = $arg;
ob_start(); $missingCoinRc = $realCommand->run($missingCoinArgv); $missingCoinOutput = ob_get_clean();
$missingCoinReport = json_decode($missingCoinOutput, true);
integration_expect($missingCoinRc === 2 && is_array($missingCoinReport) && $missingCoinReport['apply_classification'] === 'invocation_refusal' && $missingCoinReport['failure_reason'] === 'coin_id_required', 'real command path must classify missing --coin-id as invocation_refusal', $failures);
integration_expect($missingCoinReport['batches_attempted'] === 0 && $missingCoinReport['committed_batches'] === 0 && $missingCoinReport['db_mutations'] === false, 'real missing-coin refusal must prove zero transaction and mutation state', $failures);
$aliasCommand = new BadpoolGuardCommand();
$aliasArgv = $missingCoinArgv; $aliasArgv[] = '--manifest=/tmp/guessed.json';
ob_start(); $aliasRc = $aliasCommand->run($aliasArgv); $aliasOutput = ob_get_clean();
$aliasReport = json_decode($aliasOutput, true);
integration_expect($aliasRc === 2 && is_array($aliasReport) && $aliasReport['apply_classification'] === 'invocation_refusal' && $aliasReport['failure_reason'] === 'invalid_option', 'real command path must classify --manifest as invalid_option invocation_refusal', $failures);

$commandReflection = new ReflectionClass('BadpoolGuardCommand');
$command = $commandReflection->newInstanceWithoutConstructor();
$guardProperty = $commandReflection->getProperty('guard'); $guardProperty->setAccessible(true); $guardProperty->setValue($command, new IntegrationGuardStub());
$failMethod = $commandReflection->getMethod('forwardCatchupStage1DrainFail'); $failMethod->setAccessible(true);
$preTransactionReasons = array(
	'coin_id_required'=>'invocation_refusal',
	'invalid_option'=>'invocation_refusal',
	'manifest_file_required'=>'invocation_refusal',
	'progress_file_required'=>'invocation_refusal',
	'package_checksum_required'=>'authorization_refusal',
	'operator_confirmation_required'=>'authorization_refusal',
	'package_checksum_mismatch'=>'authorization_refusal',
	'manifest_validation_failed'=>'authorization_refusal',
);
foreach ($preTransactionReasons as $reason => $classification) {
	$base = array('command'=>BadpoolStage1Manifest::APPLY_COMMAND, 'status'=>'refused', 'batches_attempted'=>0, 'committed_batches'=>0, 'transactions_started'=>0, 'db_mutations'=>false, 'failing_transaction_rolled_back'=>false, 'completed_block_ids'=>array(), 'failure_phase'=>null);
	$report = $failMethod->invoke($command, $base, $reason, 'fixture refusal');
	integration_expect($report['apply_classification'] === $classification, $reason.' report classification mismatch', $failures);
	integration_expect($report['batches_attempted'] === 0 && $report['committed_batches'] === 0 && $report['db_mutations'] === false && $report['db_mutation_status'] === 'none', $reason.' pre-transaction refusal must prove zero attempts, commits, and database mutation', $failures);
}
$rolledBackBase = array('command'=>BadpoolStage1Manifest::APPLY_COMMAND, 'status'=>'refused', 'batches_attempted'=>1, 'committed_batches'=>0, 'transactions_started'=>1, 'db_mutations'=>false, 'failing_transaction_rolled_back'=>true, 'completed_block_ids'=>array(), 'failure_phase'=>'mutation_execution');
$rolledBackReport = $failMethod->invoke($command, $rolledBackBase, 'mutation_failed_rolled_back', 'fixture rollback');
integration_expect($rolledBackReport['apply_classification'] === 'transactional_failure' && $rolledBackReport['db_mutations'] === false, 'actual failure reporter must classify a rolled-back first transaction as transactional_failure with no committed mutation', $failures);
$partialBase = array('command'=>BadpoolStage1Manifest::APPLY_COMMAND, 'status'=>'hold', 'batches_attempted'=>2, 'committed_batches'=>1, 'transactions_started'=>2, 'db_mutations'=>true, 'failing_transaction_rolled_back'=>true, 'completed_block_ids'=>range(10000,10024), 'failure_phase'=>'mutation_execution');
$partialReport = $failMethod->invoke($command, $partialBase, 'mutation_failed_rolled_back', 'fixture later rollback');
integration_expect($partialReport['apply_classification'] === 'partial_committed_failure' && $partialReport['committed_batches'] === 1 && $partialReport['db_mutations'] === true, 'actual failure reporter must classify a later failure after a commit as partial_committed_failure', $failures);

integration_expect(strpos($commandSource, 'return BadpoolStage1Manifest::parseApplyOptions($args);') !== false, 'Stage1 command must use the shared runtime-tested parser', $failures);
integration_expect(strpos($commandSource, 'BadpoolStage1Manifest::validateApplyOptions($options)') !== false, 'Stage1 command must use the shared runtime-tested required-option validator', $failures);
integration_expect(strpos($commandSource, "if (\$action === 'forward-catchup-stage1-drain-apply')") !== false && strpos($commandSource, "'invalid_coin_scope'") !== false, 'context/parser failures must enter the classified apply report path', $failures);
integration_expect(strpos($commandSource, "implode(' ', BadpoolStage1Manifest::applyCommandShape())") !== false, 'help output must use the same shared command shape', $failures);
integration_expect($manifest['apply_command_shape'] === BadpoolStage1Manifest::applyCommandShape(), 'generated command shape must remain synchronized with help and parser definitions', $failures);

if ($failures) {
	echo "Badpool Stage1 apply contract integration harness FAILED\n";
	foreach ($failures as $failure) echo " - $failure\n";
	exit(1);
}
echo "Badpool Stage1 apply contract integration harness passed\n";
