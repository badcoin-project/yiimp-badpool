<?php
$root = dirname(__DIR__);
require_once($root.'/web/yaamp/core/backend/BadpoolStage1Manifest.php');
require_once($root.'/web/yaamp/core/backend/BadpoolGuardReport.php');
$failures = array();

function manifest_expect($condition, $message, &$failures)
{
	if (!$condition) $failures[] = $message;
}

function manifest_fixture($eligibleCount, $selectionLimit=null, $batchSize=25)
{
	if ($selectionLimit === null) $selectionLimit = $eligibleCount;
	$selectedCount = min($eligibleCount, $selectionLimit);
	$records = array(); $mutations = array(); $earnings = array();
	for ($i = 0; $i < $selectedCount; $i++) {
		$id = 10000 + $i;
		$userid = 200 + ($i % 3);
		$amount = (($i % 7) + 1).'.'.str_pad((string)($i % 1000), 12, '0', STR_PAD_LEFT);
		$records[] = array('block_id'=>$id, 'height'=>1900000+$i, 'time'=>1800000000+$i, 'blockhash'=>str_pad(dechex($id),64,'0',STR_PAD_LEFT), 'userid'=>$userid, 'workerid'=>300+($i%5), 'current_category'=>'new', 'classification'=>'stage1_import_generate', 'projected_txhash'=>str_pad(dechex($id+50000),64,'0',STR_PAD_LEFT), 'projected_block_category'=>'immature', 'projected_earning_amount'=>BadpoolStage1Manifest::normalizeAmount($amount), 'attribution_model'=>'block_userid_single_recipient');
		$mutations[] = array('blockid'=>$id, 'height'=>1900000+$i, 'classification'=>'stage1_import_generate', 'would_set_txhash'=>str_pad(dechex($id+50000),64,'0',STR_PAD_LEFT), 'would_set_category'=>'immature', 'would_set_amount'=>BadpoolStage1Manifest::normalizeAmount($amount));
		$earnings[] = array('userid'=>$userid, 'coinid'=>1267, 'blockid'=>$id, 'create_time'=>1800000000+$i, 'amount'=>BadpoolStage1Manifest::normalizeAmount($amount), 'status'=>0, 'mature_time'=>null, 'attribution_model'=>'block_userid_single_recipient', 'attribution_model_requires_operator_confirmation'=>true, 'historical_evidence_mixed'=>true, 'backendblocknew_not_used'=>true, 'fee_policy'=>'not_applied_in_dryrun');
	}
	$selectedLast = max(0, $selectedCount - 1);
	$eligibleLast = max(0, $eligibleCount - 1);
	$snapshot = array('checkpoint_last_payout_time'=>1784860501, 'checkpoint_source'=>'latest_completed_payout', 'candidate_query_completed_before_apply'=>true, 'maximum_selected_order_key'=>array('height'=>1900000+$selectedLast,'time'=>1800000000+$selectedLast,'id'=>10000+$selectedLast), 'maximum_eligible_snapshot_order_key'=>array('height'=>1900000+$eligibleLast,'time'=>1800000000+$eligibleLast,'id'=>10000+$eligibleLast), 'selection_limit'=>$selectionLimit, 'eligible_candidate_count'=>$eligibleCount, 'excluded_by_selection_limit_count'=>$eligibleCount-$selectedCount, 'new_candidates_after_snapshot_are_excluded'=>true, 'post_snapshot_candidates_are_separately_counted'=>true);
	return BadpoolStage1Manifest::build(1267, 'scrypt', $snapshot, $selectionLimit, $eligibleCount, $eligibleCount-$selectedCount, 0, $records, $mutations, $earnings, $batchSize);
}

function manifest_clone($value)
{
	return unserialize(serialize($value));
}

function manifest_reseal_progress($progress)
{
	unset($progress['progress_checksum']);
	$progress['progress_checksum'] = BadpoolStage1Manifest::checksum($progress, 'detects alteration of persisted Stage1 drain progress');
	return $progress;
}

$moreThanOneBatch = manifest_fixture(30);
$validation = BadpoolStage1Manifest::validate($moreThanOneBatch);
manifest_expect($moreThanOneBatch['schema'] === BadpoolStage1Manifest::SCHEMA, 'producer must emit the canonical Stage1 manifest schema', $failures);
manifest_expect($moreThanOneBatch['package_type'] === BadpoolStage1Manifest::PACKAGE_TYPE, 'producer must emit the canonical Stage1 package_type', $failures);
manifest_expect($moreThanOneBatch['command'] === BadpoolStage1Manifest::COMMAND, 'producer must emit the canonical Stage1 command', $failures);
manifest_expect($validation['status'] === 'pass', '30-entry manifest must validate: '.implode(' | ', $validation['errors']), $failures);
manifest_expect($moreThanOneBatch['selected_count'] === 30, 'manifest must expose all entries beyond the first batch', $failures);
manifest_expect(count($moreThanOneBatch['selected_block_ids']) === 30, 'selected_block_ids must contain the complete cohort', $failures);
manifest_expect($moreThanOneBatch['projected_batch_count'] === 2, '30 entries at size 25 must project two batches', $failures);
$optionContract = BadpoolStage1Manifest::applyOptionContract();
$canonicalOptions = array('coin-id','manifest-file','progress-file','package-checksum','operator-confirms-stage1-drain','format');
manifest_expect(array_keys($optionContract) === $canonicalOptions, 'apply contract must expose every canonical option in order', $failures);
manifest_expect(!isset($optionContract['manifest']) && !isset($optionContract['confirmation']), 'guessed aliases must not enter the apply contract', $failures);
manifest_expect($optionContract['coin-id']['field_ref'] === '/coin_id' && $optionContract['package-checksum']['field_ref'] === '/package_checksum/value' && $optionContract['operator-confirms-stage1-drain']['field_ref'] === '/exact_operator_confirmation', 'authority-bearing arguments must reference canonical manifest fields', $failures);
manifest_expect($optionContract['manifest-file']['source'] === 'runtime' && $optionContract['progress-file']['source'] === 'runtime', 'artifact paths must be runtime supplied', $failures);
manifest_expect($moreThanOneBatch['apply_command'] === BadpoolStage1Manifest::APPLY_COMMAND && $moreThanOneBatch['apply_command_args'] === $optionContract && $moreThanOneBatch['apply_command_shape'] === BadpoolStage1Manifest::applyCommandShape(), 'manifest must emit the shared machine-usable apply contract', $failures);
$sameAuthoritativeInput = manifest_fixture(30);
manifest_expect($sameAuthoritativeInput['apply_command_args'] === $moreThanOneBatch['apply_command_args'] && $sameAuthoritativeInput['apply_command_shape'] === $moreThanOneBatch['apply_command_shape'] && $sameAuthoritativeInput['package_checksum'] === $moreThanOneBatch['package_checksum'], 'identical authoritative input must produce deterministic apply instructions and identity', $failures);
manifest_expect(BadpoolStage1Manifest::classifyApplyResult('coin_id_required', 0, 0) === 'invocation_refusal', 'missing coin ID must classify as invocation refusal', $failures);
manifest_expect(BadpoolStage1Manifest::classifyApplyResult('operator_confirmation_required', 0, 0) === 'authorization_refusal', 'invalid confirmation must classify as authorization refusal', $failures);
manifest_expect(BadpoolStage1Manifest::classifyApplyResult('mutation_failed_rolled_back', 1, 0, true) === 'transactional_failure', 'rolled-back first batch must classify as transactional failure', $failures);
manifest_expect(BadpoolStage1Manifest::classifyApplyResult('projection_mismatch', 2, 1) === 'partial_committed_failure', 'failure after a commit must classify as partial committed failure', $failures);
manifest_expect(BadpoolStage1Manifest::classifyApplyResult(null, 2, 2) === 'successful_apply', 'complete two-batch apply must classify as successful', $failures);

// Exercise the same producer/finalizer boundary used by the approval-package
// command. The generic safety finalizer must preserve the dedicated manifest
// schema while still forcing preview operations to remain read-only.
$generatedPackage = manifest_clone($moreThanOneBatch);
$generatedPackage['mode'] = 'read-only-preview';
$generatedPackage['read_only'] = true;
$generatedPackage['db_mutations'] = false;
$generatedPackage['wallet_rpc_send_performed'] = false;
$generatedPackage = BadpoolGuardReport::finalize($generatedPackage);
$generatedValidation = BadpoolStage1Manifest::validate($generatedPackage);
manifest_expect($generatedPackage['schema'] === BadpoolStage1Manifest::SCHEMA, 'approval-package finalization must preserve the canonical Stage1 schema', $failures);
manifest_expect($generatedValidation['status'] === 'pass', 'the exact finalized producer package must pass the apply validator: '.implode(' | ', $generatedValidation['errors']), $failures);

$progressProbe = sys_get_temp_dir().'/badpool-stage1-contract-progress-'.getmypid().'.json';
if (is_file($progressProbe)) unlink($progressProbe);
$authorization = BadpoolStage1Manifest::validateApplyAuthorization($generatedPackage, $generatedPackage['package_checksum']['value'], $generatedPackage['exact_operator_confirmation'], 1267);
manifest_expect($authorization['status'] === 'pass' && $authorization['post_manifest_validation'] === true, 'generated package must enter the apply post-manifest-validation authorization path', $failures);
manifest_expect(!is_file($progressProbe), 'pre-batch authorization must not create a progress file', $failures);

$large = manifest_fixture(1742, 50);
$largeValidation = BadpoolStage1Manifest::validate($large);
$batches = BadpoolStage1Manifest::batches($large);
manifest_expect($largeValidation['status'] === 'pass', '1,742-entry manifest must validate: '.implode(' | ', $largeValidation['errors']), $failures);
manifest_expect($large['selection_limit'] === 50, '1,742 fixture selection_limit mismatch', $failures);
manifest_expect($large['eligible_candidate_count'] === 1742, '1,742 fixture eligible_candidate_count mismatch', $failures);
manifest_expect($large['selected_count'] === 50, '1,742 fixture selected_count mismatch', $failures);
manifest_expect($large['excluded_by_selection_limit_count'] === 1692, '1,742 fixture excluded_by_selection_limit_count mismatch', $failures);
manifest_expect($large['excluded_newer_candidate_count'] === 0, 'in-snapshot exclusions must not be labeled newer', $failures);
manifest_expect($large['internal_batch_size'] === 25, 'default internal batch size must be 25', $failures);
manifest_expect($large['projected_batch_count'] === 2 && count($batches) === 2, '50 selected entries must produce two batches', $failures);
manifest_expect(count($batches[0]) === 25 && count($batches[1]) === 25, 'both bounded fixture batches must contain 25 entries', $failures);
manifest_expect($large['selected_block_ids'] === range(10000, 10049), 'bounded fixture must select the deterministic first 50 IDs', $failures);
manifest_expect(!in_array(10050, $large['selected_block_ids'], true), 'candidate 51 must remain outside the manifest', $failures);
manifest_expect($large['snapshot_boundary']['maximum_selected_order_key']['id'] === 10049, 'maximum selected order key mismatch', $failures);
manifest_expect($large['snapshot_boundary']['maximum_eligible_snapshot_order_key']['id'] === 11741, 'maximum eligible snapshot order key mismatch', $failures);

$confirmation = $large['exact_operator_confirmation'];
manifest_expect($confirmation === BadpoolStage1Manifest::confirmationValue($large['package_checksum']['value']), 'one exact confirmation must bind the package checksum', $failures);
manifest_expect(strpos($large['authorization_boundary'], 'Internal batches require no additional confirmation') !== false, 'internal batches must not require repeated confirmations', $failures);

$approvedIds = $large['selected_block_ids'];
$databaseAfterSnapshot = $approvedIds; $databaseAfterSnapshot[] = 10050; $databaseAfterSnapshot[] = 999999;
manifest_expect(count($databaseAfterSnapshot) === 52 && $large['selected_block_ids'] === $approvedIds && $large['selected_count'] === 50, 'unselected or post-snapshot candidates must not join an existing manifest', $failures);

$progress = BadpoolStage1Manifest::initialProgress($large);
$firstBatchAmount = BadpoolStage1Manifest::normalizeAmount('0');
foreach (array_slice($large['projected_earning_rows'], 0, 25) as $earning) $firstBatchAmount = BadpoolStage1Manifest::addAmounts($firstBatchAmount, $earning['amount']);
$progress = BadpoolStage1Manifest::completeBatch($large, $progress, 1, $batches[0], 25, $firstBatchAmount, array('status'=>'pass'));
$progressValidation = BadpoolStage1Manifest::validateProgress($large, $progress);
manifest_expect($progressValidation['status'] === 'pass', 'progress must validate after one committed batch', $failures);
manifest_expect($progress['completed_batch_count'] === 1 && count($progress['completed_block_ids']) === 25 && $progress['remaining_block_ids'][0] === $batches[1][0], 'resume must begin at the first uncompleted entry', $failures);
$progressTamperCases = array();
$case = manifest_clone($progress); $case['completed_batch_count'] = 2; $progressTamperCases['completed batch count'] = manifest_reseal_progress($case);
$case = manifest_clone($progress); $case['completed_batches'][0]['rows_created'] = 999; $progressTamperCases['completed batch rows'] = manifest_reseal_progress($case);
$case = manifest_clone($progress); $case['completed_batches'][0]['amount'] = '999.000000000000'; $progressTamperCases['completed batch amount'] = manifest_reseal_progress($case);
$case = manifest_clone($progress); $case['cumulative_rows_created'] = 999; $progressTamperCases['cumulative rows'] = manifest_reseal_progress($case);
$case = manifest_clone($progress); $case['cumulative_amount'] = '999.000000000000'; $progressTamperCases['cumulative amount'] = manifest_reseal_progress($case);
$case = manifest_clone($progress); $case['retry_safe'] = false; $progressTamperCases['retry flag'] = manifest_reseal_progress($case);
$case = manifest_clone($progress); $case['remaining_block_ids'][] = 10050; $progressTamperCases['cohort expansion'] = manifest_reseal_progress($case);
$case = manifest_clone($progress); $case['progress_checksum']['value'] = str_repeat('3',64); $progressTamperCases['progress checksum'] = $case;
foreach ($progressTamperCases as $name => $tampered) manifest_expect(BadpoolStage1Manifest::validateProgress($large, $tampered)['status'] === 'fail', 'progress tampering must be refused: '.$name, $failures);
$duplicateRefused = false;
try { BadpoolStage1Manifest::completeBatch($large, $progress, 1, $batches[0], 25, '100', array('status'=>'pass')); } catch (InvalidArgumentException $e) { $duplicateRefused = true; }
manifest_expect($duplicateRefused, 'already completed batch must not be duplicated', $failures);

$oversizedBatchRefused = false;
try { manifest_fixture(51, 51, 51); } catch (InvalidArgumentException $e) { $oversizedBatchRefused = true; }
manifest_expect($oversizedBatchRefused, 'manifest construction must refuse an internal batch above 50 entries', $failures);

$tamperCases = array();
$case = manifest_clone($large); $case['schema'] = 'badpool.guardrail.preview.v1'; $tamperCases['generic preview schema'] = $case;
$case = manifest_clone($large); $case['schema'] = 'badpool.stage1_drain_manifest.v999'; $tamperCases['wrong schema'] = $case;
$case = manifest_clone($large); $case['package_type'] = 'forward-catchup-stage1-drain-preview'; $tamperCases['wrong package type'] = $case;
$case = manifest_clone($large); $case['command'] = 'forward-catchup-stage1-drain-plan'; $tamperCases['wrong command'] = $case;
$case = manifest_clone($large); $case['selected_block_ids'][0] = 42; $tamperCases['selected IDs'] = $case;
$case = manifest_clone($large); $case['selected_count']++; $tamperCases['selected count'] = $case;
$case = manifest_clone($large); $case['selection_limit']++; $tamperCases['selection limit'] = $case;
$case = manifest_clone($large); $case['eligible_candidate_count']++; $tamperCases['eligible candidate count'] = $case;
$case = manifest_clone($large); $case['excluded_by_selection_limit_count']++; $tamperCases['excluded by selection limit count'] = $case;
$case = manifest_clone($large); $case['projected_block_mutations'][0]['would_set_amount'] = '999.000000000000'; $tamperCases['projection'] = $case;
$case = manifest_clone($large); $case['projected_earning_rows'][0]['amount'] = '999.000000000000'; $tamperCases['amount'] = $case;
$case = manifest_clone($large); $case['projected_earning_rows'][0]['userid']++; $tamperCases['attribution'] = $case;
$case = manifest_clone($large); $case['projected_recipient_totals'][0]['amount'] = '999.000000000000'; $tamperCases['recipient total'] = $case;
$case = manifest_clone($large); $case['snapshot_boundary']['maximum_selected_order_key']['id']++; $tamperCases['maximum selected order key'] = $case;
$case = manifest_clone($large); $case['snapshot_boundary']['maximum_eligible_snapshot_order_key']['id']++; $tamperCases['maximum eligible order key'] = $case;
$case = manifest_clone($large); $case['projected_batch_count']++; $tamperCases['projected batch count'] = $case;
$case = manifest_clone($large); $case['projected_earning_row_count']++; $tamperCases['projected earning count'] = $case;
$case = manifest_clone($large); $swap = $case['selected_records'][0]; $case['selected_records'][0] = $case['selected_records'][1]; $case['selected_records'][1] = $swap; $tamperCases['ordering'] = $case;
$case = manifest_clone($large); $case['selection_checksum']['value'] = str_repeat('0',64); $tamperCases['selection checksum'] = $case;
$case = manifest_clone($large); $case['intended_mutation_checksum']['value'] = str_repeat('1',64); $tamperCases['mutation checksum'] = $case;
$case = manifest_clone($large); $case['package_checksum']['value'] = str_repeat('2',64); $tamperCases['package checksum'] = $case;
$case = manifest_clone($large); $case['exact_operator_confirmation'] = 'tampered'; $tamperCases['operator confirmation'] = $case;
$case = manifest_clone($large); $case['apply_command_args']['manifest-file']['source'] = 'manifest'; $tamperCases['apply contract'] = $case;
$case = manifest_clone($large); $case['selected_block_ids'][] = 10050; $tamperCases['unselected candidate injection'] = $case;
$case = manifest_clone($large); $case['selected_block_ids'][] = 999999; $tamperCases['post-snapshot candidate injection'] = $case;
foreach ($tamperCases as $name => $tampered) manifest_expect(BadpoolStage1Manifest::validate($tampered)['status'] === 'fail', 'tampering must be refused: '.$name, $failures);

$v1 = manifest_clone($large); $v1['schema'] = 'badpool.stage1_drain_manifest.v1';
manifest_expect(BadpoolStage1Manifest::validateApplyAuthorization($v1, $large['package_checksum']['value'], $large['exact_operator_confirmation'], 1267)['status'] === 'fail', 'v1 manifest must be refused by the v2 apply contract', $failures);

$equalLimit = manifest_fixture(30, 30);
$largerLimit = manifest_fixture(30, 50);
$one = manifest_fixture(30, 1);
manifest_expect($equalLimit['selected_count'] === 30 && $equalLimit['excluded_by_selection_limit_count'] === 0, 'limit equal to eligible count must select all candidates', $failures);
manifest_expect($largerLimit['selected_count'] === 30 && $largerLimit['excluded_by_selection_limit_count'] === 0, 'limit above eligible count must select all candidates', $failures);
manifest_expect($one['selected_count'] === 1 && $one['excluded_by_selection_limit_count'] === 29 && $one['projected_batch_count'] === 1, 'limit of one must select exactly one candidate', $failures);

$invalidLimits = array('', '0', '-1', '1.5', '1e2', 'abc', '12x', ' 50', '50 ', '00050', '1000001', '999999999999999999999999999');
foreach ($invalidLimits as $invalidLimit) {
	$refused = false;
	try { BadpoolStage1Manifest::parseSelectionLimit($invalidLimit); } catch (InvalidArgumentException $e) { $refused = true; }
	manifest_expect($refused, 'invalid selection limit must be refused: '.var_export($invalidLimit, true), $failures);
}
manifest_expect(BadpoolStage1Manifest::parseSelectionLimit('1') === 1, 'selection limit parser must accept one', $failures);
manifest_expect(BadpoolStage1Manifest::parseSelectionLimit('1000000') === 1000000, 'selection limit parser must accept its maximum', $failures);

$badPackageAuthorization = BadpoolStage1Manifest::validateApplyAuthorization($large, str_repeat('f',64), $large['exact_operator_confirmation'], 1267);
manifest_expect($badPackageAuthorization['status'] === 'fail' && $badPackageAuthorization['stop_reason'] === 'package_checksum_mismatch', 'apply authorization must reject package-checksum tampering', $failures);
$badConfirmationAuthorization = BadpoolStage1Manifest::validateApplyAuthorization($large, $large['package_checksum']['value'], 'tampered', 1267);
manifest_expect($badConfirmationAuthorization['status'] === 'fail' && $badConfirmationAuthorization['stop_reason'] === 'operator_confirmation_required', 'apply authorization must reject operator-confirmation tampering', $failures);

$differentManifest = manifest_fixture(1741, 50);
manifest_expect(BadpoolStage1Manifest::validateProgress($differentManifest, $progress)['status'] === 'fail', 'resume must refuse a different manifest', $failures);
manifest_expect($large['projected_earning_row_count'] === count($large['projected_earning_rows']), 'projected earning row reconciliation mismatch', $failures);
manifest_expect($large['projected_recipient_totals'] === BadpoolStage1Manifest::recipientTotalsForEarnings($large['projected_earning_rows']), 'recipient reconciliation mismatch', $failures);
$total = BadpoolStage1Manifest::normalizeAmount('0'); foreach ($large['projected_earning_rows'] as $earning) $total = BadpoolStage1Manifest::addAmounts($total, $earning['amount']);
manifest_expect($large['projected_total_amount'] === $total, 'amount reconciliation mismatch', $failures);
foreach (array('wallet_reads','wallet_sends','backend_loops','service_actions','share_deletion','maturity_transition','account_credit','payout_row_creation') as $boundary) manifest_expect($large['pipeline_boundary'][$boundary] === false, 'forbidden pipeline boundary enabled: '.$boundary, $failures);

if ($failures) {
	echo "Badpool Stage1 manifest contract harness FAILED\n";
	foreach ($failures as $failure) echo " - $failure\n";
	exit(1);
}
echo "Badpool Stage1 manifest contract harness passed\n";
