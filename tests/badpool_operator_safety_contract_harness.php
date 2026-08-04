<?php
$root = dirname(__DIR__);
require_once($root.'/web/yaamp/core/backend/BadpoolStage1Manifest.php');
require_once($root.'/web/yaamp/core/backend/BadpoolGuardReport.php');
$command = file_get_contents($root.'/web/yaamp/commands/BadpoolGuardCommand.php');
$reportSource = file_get_contents($root.'/web/yaamp/core/backend/BadpoolGuardReport.php');
$failures = array();

function contract_expect($condition, $message, &$failures)
{
	if (!$condition) $failures[] = $message;
}

function contract_section($source, $start, $end)
{
	$from = strpos($source, $start);
	if ($from === false) return '';
	$to = strpos($source, $end, $from + strlen($start));
	return substr($source, $from, $to === false ? strlen($source) - $from : $to - $from);
}

$drain = contract_section($command, 'private function forwardCatchupStage1DrainApplyReport', 'private function forwardCatchupStage1DrainContextArgs');
$drainFail = contract_section($command, 'private function forwardCatchupStage1DrainFail', 'private function earningsMaturityTransitionDryrunReport');
$walletFail = contract_section($command, 'private function walletSendApplyPostSendDbFailureReport', 'private function walletProofCloseoutPreviewReport');

foreach (array('normalizeOperatorSafety', 'badpool.guardrail.apply.v1', 'guarded-apply', 'badpool.guardrail.preview.v1', 'read-only-preview', 'db_mutation_status') as $needle) {
	contract_expect(strpos($reportSource, $needle) !== false, "central report normalization missing $needle", $failures);
}

$applyReport = BadpoolGuardReport::finalize(array('command'=>'payout-row-apply','schema'=>'badpool.guardrail.preview.v1','mode'=>'read-only-preview','read_only'=>true,'db_mutations'=>'guarded_transaction_committed','wallet_rpc_send_performed'=>0));
contract_expect($applyReport['schema'] === 'badpool.guardrail.apply.v1', 'apply schema was not normalized', $failures);
contract_expect($applyReport['mode'] === 'guarded-apply', 'apply mode was not normalized', $failures);
contract_expect($applyReport['read_only'] === false, 'apply read_only was not normalized', $failures);
contract_expect($applyReport['db_mutations'] === true, 'committed apply db_mutations is not boolean true', $failures);
contract_expect($applyReport['db_mutation_status'] === 'guarded_transaction_committed', 'apply mutation detail was not preserved', $failures);
contract_expect($applyReport['wallet_rpc_send_performed'] === false, 'apply wallet RPC flag is not boolean false', $failures);

$walletHold = BadpoolGuardReport::finalize(array('command'=>'wallet-send-apply','db_mutations'=>'failed_or_partial_rolled_back','wallet_rpc_send_performed'=>true));
contract_expect($walletHold['db_mutations'] === false, 'rolled-back wallet DB failure must normalize to boolean false', $failures);
contract_expect($walletHold['db_mutation_status'] === 'failed_or_partial_rolled_back', 'wallet DB failure detail was not preserved', $failures);
contract_expect($walletHold['wallet_rpc_send_performed'] === true, 'wallet post-send flag must remain boolean true', $failures);

foreach (array('payout-row-preflight-preview','wallet-send-dryrun','forward-catchup-stage1-drain-plan','wallet-send-approval-package') as $previewCommand) {
	$previewReport = BadpoolGuardReport::finalize(array('command'=>$previewCommand,'schema'=>'wrong','mode'=>'wrong','read_only'=>false,'db_mutations'=>'guarded_transaction_committed','wallet_rpc_send_performed'=>true));
	contract_expect($previewReport['schema'] === 'badpool.guardrail.preview.v1', "$previewCommand schema was not normalized", $failures);
	contract_expect($previewReport['mode'] === 'read-only-preview', "$previewCommand mode was not normalized", $failures);
	contract_expect($previewReport['read_only'] === true, "$previewCommand read_only was not normalized", $failures);
	contract_expect($previewReport['db_mutations'] === false, "$previewCommand db_mutations was not forced false", $failures);
	contract_expect($previewReport['wallet_rpc_send_performed'] === false, "$previewCommand wallet RPC flag was not forced false", $failures);
}

$canonicalManifestSurface = BadpoolGuardReport::finalize(array('schema'=>BadpoolStage1Manifest::SCHEMA,'package_type'=>BadpoolStage1Manifest::PACKAGE_TYPE,'command'=>BadpoolStage1Manifest::COMMAND,'mode'=>'read-only-preview','read_only'=>true,'db_mutations'=>false));
contract_expect($canonicalManifestSurface['schema'] === BadpoolStage1Manifest::SCHEMA, 'canonical Stage1 manifest schema must survive generic report finalization', $failures);
contract_expect(BadpoolStage1Manifest::validate($canonicalManifestSurface)['status'] === 'fail', 'schema/package_type/command alone must not make a generic preview report an apply manifest', $failures);
$genericPreviewSurface = BadpoolGuardReport::finalize(array('schema'=>BadpoolStage1Manifest::SCHEMA,'package_type'=>'unrelated-preview','command'=>BadpoolStage1Manifest::COMMAND,'mode'=>'wrong','read_only'=>false,'db_mutations'=>true));
contract_expect($genericPreviewSurface['schema'] === 'badpool.guardrail.preview.v1', 'unrelated preview report must not inherit the Stage1 manifest schema', $failures);

// A successful commit is recorded before verification, rather than inferred from
// batches_applied (which intentionally remains zero until verification passes).
contract_expect(strpos($drain, '$tx->commit();') < strpos($drain, '$report[\'committed_batches\']++;'), 'commit count must follow commit', $failures);
contract_expect(strpos($drain, '$report[\'committed_batches\']++;') < strpos($drain, 'forwardCatchupStage1ApplyCloseoutVerification'), 'commit count must precede post-apply verification', $failures);
$completeProgress = strpos($drain, 'BadpoolStage1Manifest::completeBatch');
$writeAfterComplete = $completeProgress === false ? false : strpos($drain, 'forwardCatchupStage1DrainWriteProgress', $completeProgress);
contract_expect($completeProgress !== false && $writeAfterComplete !== false && $completeProgress < $writeAfterComplete, 'verified batch progress must be assembled before atomic progress publication', $failures);
foreach (array("'block_ids'", "'committed'", "'rows_created'", "'amount'", "'completion_mode'", "'verification_passed'", "'reconciliation_status'", "'additional_operator_confirmation_required'") as $needle) {
	contract_expect(strpos($command, $needle) !== false, "per-batch contract missing $needle", $failures);
}
foreach (array('resume_preflight','projection_reverification','preflight_validation','mutation_execution','post_apply_verification') as $phase) {
	contract_expect(strpos($drain, "'failure_phase'] = '$phase'") !== false, "missing failure phase $phase", $failures);
}
contract_expect(strpos($drain, "'failing_transaction_rolled_back'] = true") !== false, 'rollback confirmation missing', $failures);
contract_expect(strpos($drainFail, "? 'hold' : 'refused'") !== false, 'post-commit failure must be hold while pre-commit failure remains refused', $failures);
contract_expect(strpos($drainFail, "'db_mutations'] = \$committedThisRun") !== false, 'current-run mutation boolean is not preserved', $failures);
contract_expect(strpos($drainFail, "'manual_verification_required'] = false") !== false, 'verified committed batches must remain automatically resumable', $failures);
contract_expect(strpos($drainFail, "'manual_reconciliation_required'] = false") !== false, 'verified committed batches must not force manual reconciliation', $failures);
contract_expect(strpos($drainFail, "'retry_safe'] = true") !== false, 'same-manifest retry safety must be explicit', $failures);
contract_expect(strpos($drainFail, "'same_manifest_confirmation_required'] = true") !== false, 'resume must require the same manifest confirmation', $failures);
contract_expect(strpos($drainFail, "'verified_prior_manifest_progress'") !== false && strpos($drainFail, "'committed_this_run'") !== false, 'explicit current/prior commit state is missing', $failures);
contract_expect(strpos($drainFail, "if (\$committedThisRun) \$report['apply_commands_executed'] = true") !== false, 'current-run commit must record execution without misreporting resume-only checks', $failures);

// Preserve the independent wallet boundary: a completed send followed by a rolled
// back DB completion is a hold with no committed DB mutation and no safe retry.
foreach (array("'status'=>'hold'", "'wallet_rpc_send_performed'=>true", "'db_mutations'=>'failed_or_partial_rolled_back'", "'manual_reconciliation_required'=>true", "'do_not_retry_wallet_send_apply'=>true", 'DO NOT RETRY wallet-send-apply') as $needle) {
	contract_expect(strpos($walletFail, $needle) !== false, "wallet post-send contract missing $needle", $failures);
}

if ($failures) {
	echo "Badpool operator safety contract harness FAILED\n";
	foreach ($failures as $failure) echo " - $failure\n";
	exit(1);
}
echo "Badpool operator safety contract harness passed\n";
