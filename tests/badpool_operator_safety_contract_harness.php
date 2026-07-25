<?php
$root = dirname(__DIR__);
$command = file_get_contents($root.'/web/yaamp/commands/BadpoolGuardCommand.php');
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

// A successful commit is recorded before verification, rather than inferred from
// batches_applied (which intentionally remains zero until verification passes).
contract_expect(strpos($drain, '$tx->commit();') < strpos($drain, '$report[\'committed_batches\']++;'), 'commit count must follow commit', $failures);
contract_expect(strpos($drain, '$report[\'committed_batches\']++;') < strpos($drain, 'forwardCatchupStage1ApplyCloseoutVerification'), 'commit count must precede post-apply verification', $failures);
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
foreach (array("'status'=>'hold'", "'wallet_rpc_send_performed'=>true", "'db_mutations'=>'failed_or_partial_rolled_back'", "'manual_reconciliation_required'=>true", "'do_not_retry_wallet_send_apply'=>true") as $needle) {
	contract_expect(strpos($walletFail, $needle) !== false, "wallet post-send contract missing $needle", $failures);
}

if ($failures) {
	echo "Badpool operator safety contract harness FAILED\n";
	foreach ($failures as $failure) echo " - $failure\n";
	exit(1);
}
echo "Badpool operator safety contract harness passed\n";
