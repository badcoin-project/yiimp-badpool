<?php

$root = dirname(__DIR__);
$commandPath = $root.'/web/yaamp/commands/BadpoolGuardCommand.php';
$docPath = $root.'/docs/badpool-forward-catchup-stage1-apply-command-design.md';

$failures = array();

function read_required_file($path, &$failures)
{
	if (!is_file($path)) {
		$failures[] = "Missing required file: $path";
		return '';
	}
	$contents = file_get_contents($path);
	if ($contents === false) {
		$failures[] = "Unable to read required file: $path";
		return '';
	}
	return $contents;
}

function expect_contains($label, $haystack, $needle, &$failures)
{
	if (strpos($haystack, $needle) === false) {
		$failures[] = "$label: missing expected text: $needle";
	}
}

function expect_not_contains($label, $haystack, $needle, &$failures)
{
	if (strpos($haystack, $needle) !== false) {
		$failures[] = "$label: found forbidden text: $needle";
	}
}

function expect_regex($label, $haystack, $pattern, &$failures)
{
	if (!preg_match($pattern, $haystack)) {
		$failures[] = "$label: pattern did not match: $pattern";
	}
}

function expect_order($label, $haystack, $first, $second, &$failures)
{
	$firstPos = strpos($haystack, $first);
	$secondPos = strpos($haystack, $second);
	if ($firstPos === false || $secondPos === false || $firstPos >= $secondPos) {
		$failures[] = "$label: expected '$first' before '$second'";
	}
}

$command = read_required_file($commandPath, $failures);
$doc = read_required_file($docPath, $failures);

expect_contains('generated update helper exists', $command, 'private function forwardCatchupStage1ExecuteGeneratedBlockUpdate($mutation, $oldCategory)', $failures);
expect_contains('orphan update helper exists', $command, 'private function forwardCatchupStage1ExecuteOrphanBlockUpdate($blockid, $oldCategory)', $failures);
expect_contains('failure snapshot helper exists', $command, 'private function forwardCatchupStage1BlockFailureSnapshot($blockid)', $failures);

expect_contains('generated update uses blocks table', $command, "UPDATE '.\$this->guard->qtable('blocks').' SET " , $failures);
expect_contains('generated update guards old category', $command, "WHERE '.\$this->guard->qcol('id').'=:id AND '.\$this->guard->qcol('category').'=:old_category", $failures);
expect_contains('generated update binds txhash', $command, "':txhash' => arraySafeVal(\$mutation, 'would_set_txhash')", $failures);
expect_contains('generated update binds amount', $command, "':amount' => arraySafeVal(\$mutation, 'would_set_amount')", $failures);
expect_contains('generated update binds confirmations', $command, "':confirmations' => arraySafeVal(\$mutation, 'would_set_confirmations')", $failures);
expect_contains('generated update binds block id', $command, "':id' => arraySafeVal(\$mutation, 'blockid')", $failures);
expect_contains('generated update binds old category', $command, "':old_category' => \$oldCategory", $failures);
expect_contains('generated update sets immature', $command, "':new_category' => 'immature'", $failures);
expect_contains('generated affected rows must equal one', $command, 'if (intval($count) !== 1)', $failures);
expect_contains('generated failure includes expected old category', $command, 'with expected old category new. Current row snapshot:', $failures);

expect_contains('orphan update uses blocks table', $command, "UPDATE '.\$this->guard->qtable('blocks').' SET " , $failures);
expect_contains('orphan update guards old category', $command, "WHERE '.\$this->guard->qcol('id').'=:id AND '.\$this->guard->qcol('category').'=:old_category", $failures);
expect_contains('orphan update binds block id', $command, "':id' => \$blockid", $failures);
expect_contains('orphan update binds old category', $command, "':old_category' => \$oldCategory", $failures);
expect_contains('orphan update sets orphan', $command, "':new_category' => 'orphan'", $failures);
expect_contains('orphan failure includes expected old category', $command, 'with expected old category new. Current row snapshot:', $failures);

expect_contains('safe snapshot includes block fields', $command, "array('id', 'coin_id', 'userid', 'height', 'blockhash', 'txhash', 'category', 'amount', 'confirmations', 'time', 'difficulty')", $failures);
expect_contains('safe snapshot includes earnings count', $command, "\$row['earnings_count'] = intval(arraySafeVal(\$linked, 'row_count', 0));", $failures);
expect_contains('mutation failure reports rollback abort reason', $command, "'mutation_failed_rolled_back'", $failures);

expect_order('duplicate earning protection before insert', $command, "Duplicate earning protection tripped for block", "createCommand()->insert('earnings', \$row)", $failures);
expect_order('generated update before pending earning insert', $command, 'forwardCatchupStage1ExecuteGeneratedBlockUpdate', "createCommand()->insert('earnings', \$row)", $failures);

expect_contains('apply parser rejects unknown options', $command, "Unknown option refused: --", $failures);
expect_contains('apply context forwards approved limit', $command, "\$allowed = array('coin-id', 'format', 'limit');", $failures);
expect_contains('apply parser allows approved limit and selected count', $command, "\$allowed = array('coin-id', 'format', 'limit', 'selected-count', 'approval-package-checksum', 'batch-scope-checksum', 'projected-mutation-checksum', 'projected-earnings-checksum', 'operator-confirms-attribution-model');", $failures);
expect_contains('scope mismatch failure is preserved', $command, "'scope_mismatch'", $failures);
expect_contains('package selected count reported', $command, "'package_selected_count'", $failures);
expect_contains('regenerated selected count reported', $command, "'regenerated_selected_count'", $failures);
expect_contains('missing checksum failure is preserved', $command, "'missing_required_checksum'", $failures);
expect_contains('checksum mismatch failure is preserved', $command, "'checksum_mismatch'", $failures);
expect_contains('attribution failure is preserved', $command, "'attribution_confirmation_required'", $failures);
expect_contains('unsupported attribution exact model check', $command, "block_userid_single_recipient", $failures);

expect_contains('approval apply command has approval checksum', $command, "--approval-package-checksum=<approval_package_checksum>", $failures);
$applyShapeStart = strpos($command, '$applyCommandShape = array(');
$applyShapeEnd = strpos($command, ');', $applyShapeStart);
$applyShape = ($applyShapeStart === false || $applyShapeEnd === false) ? '' : substr($command, $applyShapeStart, $applyShapeEnd - $applyShapeStart);
expect_contains('apply command shape includes approved limit', $applyShape, '--limit=', $failures);
expect_contains('apply command shape includes selected count', $applyShape, '--selected-count=', $failures);

expect_contains('docs apply requires reviewed limit', $doc, 'Apply requires the reviewed `--limit` and `--selected-count`', $failures);
expect_contains('docs apply no default fallback', $doc, 'must not silently fall back to the default limit', $failures);
expect_contains('docs pending earnings only', $doc, 'Stage 1 creates pending earnings only; it does not credit accounts, create payout rows, send wallet transactions, delete shares, run backend loops, or start the blocks service.', $failures);

expect_contains('apply base starts with db mutations false', $command, "\$report['db_mutations'] = false;", $failures);
expect_contains('stage1 apply uses apply base report', $command, "\$report = \$this->applyBaseReport('forward-catchup-stage1-apply', 'fail');", $failures);
expect_contains('apply success reports mutation flag from applied counts', $command, "\$report['db_mutations'] = \$this->forwardCatchupStage1ApplyDidMutate(\$applied);", $failures);
expect_contains('inserted earnings imply db mutation helper', $command, "intval(arraySafeVal(\$applied, 'inserted_earnings_count', 0)) > 0", $failures);
expect_contains('orphan mutation implies db mutation helper', $command, "intval(arraySafeVal(\$applied, 'applied_orphan_count', 0)) > 0", $failures);
expect_contains('generated mutation implies db mutation helper', $command, "intval(arraySafeVal(\$applied, 'applied_generated_count', 0)) > 0", $failures);
expect_contains('apply command validation status separated', $command, "\$report['command_validation_status'] = 'pass';", $failures);
expect_contains('apply db mutation status separated', $command, "\$report['db_mutation_status'] = \$report['db_mutations'] ? 'performed' : 'none';", $failures);
expect_contains('apply post verification status separated', $command, "\$report['post_apply_db_verification_status'] = arraySafeVal(\$verification, 'status');", $failures);
expect_contains('apply final reconciliation status separated', $command, "\$report['final_batch_reconciliation_status'] = arraySafeVal(\$verification, 'status') === 'pass' ? 'pass' : 'hold';", $failures);
expect_contains('apply closeout verification can hold after mutation', $command, "\$report['status'] = 'hold';", $failures);
expect_contains('apply hold includes affected block ids', $command, "\$report['affected_block_ids'] = arraySafeVal(\$verification, 'affected_block_ids', array());", $failures);
expect_contains('apply hold preserves inserted earnings count', $command, "\$report['inserted_earnings_count'] = \$applied['inserted_earnings_count'];", $failures);
expect_contains('failed/refused apply reports no db mutation', $command, "\$report['db_mutations'] = false;", $failures);
expect_contains('closeout checks selected blocks still new', $command, "'selected_blocks_still_new' => \$stillNew", $failures);
expect_contains('closeout checks linked earnings count', $command, "'linked_earnings_count' => \$linkedEarnings", $failures);

if (!empty($failures)) {
	echo "Badpool Stage 1 apply guard harness FAILED\n";
	foreach ($failures as $failure) {
		echo " - $failure\n";
	}
	exit(1);
}

echo "Badpool Stage 1 apply guard harness passed\n";
