<?php
$root = dirname(__DIR__);
require_once($root.'/web/yaamp/core/backend/BadpoolStage1Manifest.php');
require_once($root.'/web/yaamp/core/backend/BadpoolGuardReport.php');
$failures = array();

function stability_expect($condition, $message, &$failures)
{
	if (!$condition) $failures[] = $message;
}

function stability_fixture($eligibleCount, $selectionLimit=25, $lastBlockIdDelta=0, $lastAmount=null)
{
	$selectedCount = min($eligibleCount, $selectionLimit);
	$records = array(); $mutations = array(); $earnings = array();
	for ($i = 0; $i < $selectedCount; $i++) {
		$id = 20381 + $i;
		if ($i === $selectedCount - 1) $id += $lastBlockIdDelta;
		$userid = 400 + ($i % 3);
		$amount = BadpoolStage1Manifest::normalizeAmount($i === $selectedCount - 1 && $lastAmount !== null ? $lastAmount : (($i % 7) + 1).'.'.str_pad((string)($i % 1000), 12, '0', STR_PAD_LEFT));
		$records[] = array('block_id'=>$id, 'height'=>1877374+$i, 'time'=>1785922001+$i, 'blockhash'=>str_pad(dechex($id),64,'0',STR_PAD_LEFT), 'userid'=>$userid, 'workerid'=>500+($i%5), 'current_category'=>'new', 'classification'=>'stage1_import_generate', 'projected_txhash'=>str_pad(dechex($id+50000),64,'0',STR_PAD_LEFT), 'projected_block_category'=>'immature', 'projected_earning_amount'=>$amount, 'attribution_model'=>'block_userid_single_recipient');
		$mutations[] = array('blockid'=>$id, 'height'=>1877374+$i, 'classification'=>'stage1_import_generate', 'would_set_txhash'=>str_pad(dechex($id+50000),64,'0',STR_PAD_LEFT), 'would_set_category'=>'immature', 'would_set_amount'=>$amount);
		$earnings[] = array('userid'=>$userid, 'coinid'=>1267, 'blockid'=>$id, 'create_time'=>1785922001+$i, 'amount'=>$amount, 'status'=>0, 'mature_time'=>null, 'attribution_model'=>'block_userid_single_recipient', 'attribution_model_requires_operator_confirmation'=>true, 'historical_evidence_mixed'=>true, 'backendblocknew_not_used'=>true, 'fee_policy'=>'not_applied_in_dryrun');
	}
	$lastSelected = $records[$selectedCount - 1];
	$eligibleLast = 20381 + $eligibleCount - 1;
	$snapshot = array(
		'checkpoint_last_payout_time'=>1784860501,
		'checkpoint_source'=>'latest_completed_payout',
		'candidate_query_completed_before_apply'=>true,
		'maximum_selected_order_key'=>array('height'=>$lastSelected['height'],'time'=>$lastSelected['time'],'id'=>$lastSelected['block_id']),
		'maximum_eligible_snapshot_order_key'=>array('height'=>1877374+$eligibleCount-1,'time'=>1785922001+$eligibleCount-1,'id'=>$eligibleLast),
		'selection_limit'=>$selectionLimit,
		'eligible_candidate_count'=>$eligibleCount,
		'excluded_by_selection_limit_count'=>$eligibleCount-$selectedCount,
		'new_candidates_after_snapshot_are_excluded'=>true,
		'post_snapshot_candidates_are_separately_counted'=>true,
	);
	return BadpoolStage1Manifest::build(1267, 'scrypt', $snapshot, $selectionLimit, $eligibleCount, $eligibleCount-$selectedCount, 0, $records, $mutations, $earnings, 25);
}

$generationA = stability_fixture(401);
$generationB = stability_fixture(419);
$reportA = BadpoolGuardReport::finalize($generationA);
$reportB = BadpoolGuardReport::finalize($generationB);

stability_expect($generationA['selected_block_ids'] === range(20381, 20405), 'Generation A selected IDs differ from the bounded fixture.', $failures);
stability_expect($generationA['selected_block_ids'] === $generationB['selected_block_ids'], 'Later candidates changed the selected block IDs.', $failures);
stability_expect($generationA['projected_block_mutations'] === $generationB['projected_block_mutations'], 'Later candidates changed projected block mutations.', $failures);
stability_expect($generationA['projected_earning_rows'] === $generationB['projected_earning_rows'], 'Later candidates changed projected earning rows.', $failures);
stability_expect($generationA['selection_checksum'] === $generationB['selection_checksum'], 'Later candidates changed selection_checksum.', $failures);
stability_expect($generationA['intended_mutation_checksum'] === $generationB['intended_mutation_checksum'], 'Later candidates changed intended_mutation_checksum.', $failures);
stability_expect($generationA['package_checksum'] === $generationB['package_checksum'], 'Later candidates changed package_checksum.', $failures);
stability_expect($reportA['report_checksum'] !== $reportB['report_checksum'], 'Snapshot-wide report_checksum did not reflect later backlog growth.', $failures);
stability_expect($generationA['eligible_candidate_count'] === 401 && $generationB['eligible_candidate_count'] === 419, 'Eligible population did not differ.', $failures);
stability_expect($generationA['excluded_by_selection_limit_count'] === 376 && $generationB['excluded_by_selection_limit_count'] === 394, 'Selection-limit exclusion count did not differ.', $failures);
stability_expect($generationA['snapshot_boundary']['maximum_eligible_snapshot_order_key'] !== $generationB['snapshot_boundary']['maximum_eligible_snapshot_order_key'], 'Maximum eligible snapshot order key did not differ.', $failures);
stability_expect($generationA['snapshot_boundary']['maximum_selected_order_key'] === $generationB['snapshot_boundary']['maximum_selected_order_key'], 'Maximum selected order key drifted.', $failures);
stability_expect(!in_array(20406, $generationB['selected_block_ids'], true), 'The first later candidate entered the bounded cohort.', $failures);

$selectionInputKeys = array('coin_id','selection_order','selected_block_ids','selected_cohort_identity_records');
$mutationInputKeys = array('coin_id','selection_checksum','projected_block_mutations','projected_earning_rows','projected_recipient_totals','projected_total_amount','approval_status');
$packageInputKeys = array('schema','package_type','command','coin_id','algo','selection_limit','selected_count','internal_batch_size','projected_batch_count','projected_earning_row_count','projected_recipient_count','projected_total_amount','approval_status','selection_checksum','intended_mutation_checksum','apply_command','apply_command_args','apply_command_shape','pipeline_boundary');
stability_expect(array_keys($generationB['canonical_checksum_inputs']['selection']) === $selectionInputKeys, 'Selection checksum input contains non-cohort metadata or omits cohort identity.', $failures);
stability_expect(array_keys($generationB['canonical_checksum_inputs']['intended_mutation']) === $mutationInputKeys, 'Intended-mutation checksum input differs from the canonical mutation payload.', $failures);
stability_expect(array_keys($generationB['canonical_checksum_inputs']['package']) === $packageInputKeys, 'Package checksum input differs from the canonical authorization payload.', $failures);

$authorizationB = BadpoolStage1Manifest::validateApplyAuthorization($generationB, $generationB['package_checksum']['value'], $generationB['exact_operator_confirmation'], 1267);
stability_expect($authorizationB['status'] === 'pass', 'Generation B package failed its own exact apply authorization.', $failures);

$changedSelection = stability_fixture(419, 25, 1000);
stability_expect($changedSelection['selection_checksum'] !== $generationB['selection_checksum'], 'Changing one selected block did not change selection_checksum.', $failures);
$changedSelectionAgainstPrior = BadpoolStage1Manifest::validateApplyAuthorization($changedSelection, $generationB['package_checksum']['value'], $generationB['exact_operator_confirmation'], 1267);
stability_expect($changedSelectionAgainstPrior['status'] === 'fail' && $changedSelectionAgainstPrior['stop_reason'] === 'package_checksum_mismatch', 'A changed selected block was not rejected against prior authorization.', $failures);

$changedMutation = stability_fixture(419, 25, 0, '999.000000000000');
stability_expect($changedMutation['selection_checksum'] === $generationB['selection_checksum'], 'Changing only an intended amount changed selection_checksum.', $failures);
stability_expect($changedMutation['intended_mutation_checksum'] !== $generationB['intended_mutation_checksum'], 'Changing one intended mutation did not change intended_mutation_checksum.', $failures);
$changedMutationAgainstPrior = BadpoolStage1Manifest::validateApplyAuthorization($changedMutation, $generationB['package_checksum']['value'], $generationB['exact_operator_confirmation'], 1267);
stability_expect($changedMutationAgainstPrior['status'] === 'fail' && $changedMutationAgainstPrior['stop_reason'] === 'package_checksum_mismatch', 'A changed intended mutation was not rejected against prior authorization.', $failures);

if ($failures) {
	echo "Badpool Stage1 checksum stability harness FAILED\n";
	foreach ($failures as $failure) echo " - $failure\n";
	exit(1);
}
echo "Badpool Stage1 checksum stability harness passed\n";
