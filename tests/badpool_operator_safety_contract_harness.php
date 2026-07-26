<?php
$root = dirname(__DIR__);
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

// A successful commit is recorded before verification, rather than inferred from
// batches_applied (which intentionally remains zero until verification passes).
contract_expect(strpos($drain, '$tx->commit();') < strpos($drain, '$report[\'committed_batches\']++;'), 'commit count must follow commit', $failures);
contract_expect(strpos($drain, '$report[\'committed_batches\']++;') < strpos($drain, 'forwardCatchupStage1ApplyCloseoutVerification'), 'commit count must precede post-apply verification', $failures);
contract_expect(strpos($drain, "\$report['per_batch'][] = \$batch;") < strpos($drain, 'forwardCatchupStage1ApplyCloseoutVerification'), 'committed batch detail must be preserved before post-apply verification', $failures);
foreach (array("'selected_scope'", "'committed'", "'applied_generated_count'", "'applied_orphan_count'", "'inserted_earnings_count'", "'failure_phase'", "'verification_passed'", "'manual_verification_required'") as $needle) {
	contract_expect(strpos($command, $needle) !== false, "per-batch contract missing $needle", $failures);
}
foreach (array('approval_regeneration','preflight_validation','mutation_execution','post_apply_verification') as $phase) {
	contract_expect(strpos($drain, "'failure_phase'] = '$phase'") !== false, "missing failure phase $phase", $failures);
}
contract_expect(strpos($drain, "'failing_transaction_rolled_back'] = true") !== false, 'rollback confirmation missing', $failures);
contract_expect(strpos($drainFail, "? 'hold' : 'refused'") !== false, 'post-commit failure must be hold while pre-commit failure remains refused', $failures);
contract_expect(strpos($drainFail, "'db_mutations'] = \$committed") !== false, 'committed mutation boolean is not preserved', $failures);
contract_expect(strpos($drainFail, "'manual_verification_required'] = \$committed") !== false, 'manual verification must follow committed state', $failures);
contract_expect(strpos($drainFail, "'manual_reconciliation_required'] = \$committed") !== false, 'manual reconciliation must follow committed state', $failures);
contract_expect(strpos($drainFail, "'prior_commit_state'] = \$committed ? 'committed' : 'none'") !== false, 'explicit prior commit state is missing', $failures);
contract_expect(strpos($drainFail, "'apply_commands_executed'] = true") !== false, 'post-commit hold must record execution', $failures);

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
