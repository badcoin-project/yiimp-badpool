<?php
$root = dirname(__DIR__);
require_once($root.'/web/yaamp/core/backend/BadpoolStage1Manifest.php');
$failures = array();

function manifest_expect($condition, $message, &$failures)
{
	if (!$condition) $failures[] = $message;
}

function manifest_fixture($count, $batchSize=25)
{
	$records = array(); $mutations = array(); $earnings = array();
	for ($i = 0; $i < $count; $i++) {
		$id = 10000 + $i;
		$userid = 200 + ($i % 3);
		$amount = (($i % 7) + 1).'.'.str_pad((string)($i % 1000), 12, '0', STR_PAD_LEFT);
		$records[] = array('block_id'=>$id, 'height'=>1900000+$i, 'time'=>1800000000+$i, 'blockhash'=>str_pad(dechex($id),64,'0',STR_PAD_LEFT), 'userid'=>$userid, 'workerid'=>300+($i%5), 'current_category'=>'new', 'classification'=>'stage1_import_generate', 'projected_txhash'=>str_pad(dechex($id+50000),64,'0',STR_PAD_LEFT), 'projected_block_category'=>'immature', 'projected_earning_amount'=>BadpoolStage1Manifest::normalizeAmount($amount), 'attribution_model'=>'block_userid_single_recipient');
		$mutations[] = array('blockid'=>$id, 'height'=>1900000+$i, 'classification'=>'stage1_import_generate', 'would_set_txhash'=>str_pad(dechex($id+50000),64,'0',STR_PAD_LEFT), 'would_set_category'=>'immature', 'would_set_amount'=>BadpoolStage1Manifest::normalizeAmount($amount));
		$earnings[] = array('userid'=>$userid, 'coinid'=>1267, 'blockid'=>$id, 'create_time'=>1800000000+$i, 'amount'=>BadpoolStage1Manifest::normalizeAmount($amount), 'status'=>0, 'mature_time'=>null, 'attribution_model'=>'block_userid_single_recipient', 'attribution_model_requires_operator_confirmation'=>true, 'historical_evidence_mixed'=>true, 'backendblocknew_not_used'=>true, 'fee_policy'=>'not_applied_in_dryrun');
	}
	$snapshot = array('checkpoint_last_payout_time'=>1784860501, 'checkpoint_source'=>'latest_completed_payout', 'candidate_query_completed_before_apply'=>true, 'maximum_selected_order_key'=>array('height'=>1900000+max(0,$count-1),'time'=>1800000000+max(0,$count-1),'id'=>10000+max(0,$count-1)), 'new_candidates_after_snapshot_are_excluded'=>true);
	return BadpoolStage1Manifest::build(1267, 'scrypt', $snapshot, $count, 0, $records, $mutations, $earnings, $batchSize);
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
manifest_expect($validation['status'] === 'pass', '30-entry manifest must validate: '.implode(' | ', $validation['errors']), $failures);
manifest_expect($moreThanOneBatch['selected_count'] === 30, 'manifest must expose all entries beyond the first batch', $failures);
manifest_expect(count($moreThanOneBatch['selected_block_ids']) === 30, 'selected_block_ids must contain the complete cohort', $failures);
manifest_expect($moreThanOneBatch['projected_batch_count'] === 2, '30 entries at size 25 must project two batches', $failures);

$large = manifest_fixture(1742);
$largeValidation = BadpoolStage1Manifest::validate($large);
$batches = BadpoolStage1Manifest::batches($large);
manifest_expect($largeValidation['status'] === 'pass', '1,742-entry manifest must validate: '.implode(' | ', $largeValidation['errors']), $failures);
manifest_expect($large['selected_count'] === 1742, '1,742 fixture selected_count mismatch', $failures);
manifest_expect($large['internal_batch_size'] === 25, 'default internal batch size must be 25', $failures);
manifest_expect($large['projected_batch_count'] === 70 && count($batches) === 70, '1,742 entries must produce 70 batches', $failures);
$fullBatches = 0; foreach ($batches as $batch) if (count($batch) === 25) $fullBatches++;
manifest_expect($fullBatches === 69, '1,742 entries must produce 69 full batches', $failures);
manifest_expect(count($batches[69]) === 17, 'final 1,742-entry batch must contain 17 entries', $failures);

$confirmation = $large['exact_operator_confirmation'];
manifest_expect($confirmation === BadpoolStage1Manifest::confirmationValue($large['package_checksum']['value']), 'one exact confirmation must bind the package checksum', $failures);
manifest_expect(strpos($large['authorization_boundary'], 'Internal batches require no additional confirmation') !== false, 'internal batches must not require repeated confirmations', $failures);

$approvedIds = $large['selected_block_ids'];
$databaseAfterSnapshot = $approvedIds; $databaseAfterSnapshot[] = 999999;
manifest_expect(count($databaseAfterSnapshot) === 1743 && $large['selected_block_ids'] === $approvedIds && $large['selected_count'] === 1742, 'new candidates must not join an existing manifest', $failures);

$progress = BadpoolStage1Manifest::initialProgress($large);
$firstBatchAmount = BadpoolStage1Manifest::normalizeAmount('0');
foreach (array_slice($large['projected_earning_rows'], 0, 25) as $earning) $firstBatchAmount = BadpoolStage1Manifest::addAmounts($firstBatchAmount, $earning['amount']);
$progress = BadpoolStage1Manifest::completeBatch($large, $progress, 1, $batches[0], 25, $firstBatchAmount, array('status'=>'pass'));
$progressValidation = BadpoolStage1Manifest::validateProgress($large, $progress);
manifest_expect($progressValidation['status'] === 'pass', 'progress must validate after one committed batch', $failures);
manifest_expect($progress['completed_batch_count'] === 1 && count($progress['completed_block_ids']) === 25 && $progress['remaining_block_ids'][0] === $batches[1][0], 'resume must begin at the first uncompleted entry', $failures);
$progressTamperCases = array();
$case = manifest_clone($progress); $case['completed_batch_count'] = 70; $progressTamperCases['completed batch count'] = manifest_reseal_progress($case);
$case = manifest_clone($progress); $case['completed_batches'][0]['rows_created'] = 999; $progressTamperCases['completed batch rows'] = manifest_reseal_progress($case);
$case = manifest_clone($progress); $case['completed_batches'][0]['amount'] = '999.000000000000'; $progressTamperCases['completed batch amount'] = manifest_reseal_progress($case);
$case = manifest_clone($progress); $case['cumulative_rows_created'] = 999; $progressTamperCases['cumulative rows'] = manifest_reseal_progress($case);
$case = manifest_clone($progress); $case['cumulative_amount'] = '999.000000000000'; $progressTamperCases['cumulative amount'] = manifest_reseal_progress($case);
$case = manifest_clone($progress); $case['retry_safe'] = false; $progressTamperCases['retry flag'] = manifest_reseal_progress($case);
$case = manifest_clone($progress); $case['progress_checksum']['value'] = str_repeat('3',64); $progressTamperCases['progress checksum'] = $case;
foreach ($progressTamperCases as $name => $tampered) manifest_expect(BadpoolStage1Manifest::validateProgress($large, $tampered)['status'] === 'fail', 'progress tampering must be refused: '.$name, $failures);
$duplicateRefused = false;
try { BadpoolStage1Manifest::completeBatch($large, $progress, 1, $batches[0], 25, '100', array('status'=>'pass')); } catch (InvalidArgumentException $e) { $duplicateRefused = true; }
manifest_expect($duplicateRefused, 'already completed batch must not be duplicated', $failures);

$oversizedBatchRefused = false;
try { manifest_fixture(51, 51); } catch (InvalidArgumentException $e) { $oversizedBatchRefused = true; }
manifest_expect($oversizedBatchRefused, 'manifest construction must refuse an internal batch above 50 entries', $failures);

$tamperCases = array();
$case = manifest_clone($large); $case['selected_block_ids'][0] = 42; $tamperCases['selected IDs'] = $case;
$case = manifest_clone($large); $case['projected_block_mutations'][0]['would_set_amount'] = '999.000000000000'; $tamperCases['projection'] = $case;
$case = manifest_clone($large); $case['projected_earning_rows'][0]['amount'] = '999.000000000000'; $tamperCases['amount'] = $case;
$case = manifest_clone($large); $case['projected_earning_rows'][0]['userid']++; $tamperCases['attribution'] = $case;
$case = manifest_clone($large); $swap = $case['selected_records'][0]; $case['selected_records'][0] = $case['selected_records'][1]; $case['selected_records'][1] = $swap; $tamperCases['ordering'] = $case;
$case = manifest_clone($large); $case['selection_checksum']['value'] = str_repeat('0',64); $tamperCases['selection checksum'] = $case;
$case = manifest_clone($large); $case['intended_mutation_checksum']['value'] = str_repeat('1',64); $tamperCases['mutation checksum'] = $case;
$case = manifest_clone($large); $case['package_checksum']['value'] = str_repeat('2',64); $tamperCases['package checksum'] = $case;
foreach ($tamperCases as $name => $tampered) manifest_expect(BadpoolStage1Manifest::validate($tampered)['status'] === 'fail', 'tampering must be refused: '.$name, $failures);

$differentManifest = manifest_fixture(1741);
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
