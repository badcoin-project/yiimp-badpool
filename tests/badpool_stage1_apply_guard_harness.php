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
expect_contains('apply parser allowed options omit limit', $command, "\$allowed = array('coin-id', 'format', 'approval-package-checksum', 'batch-scope-checksum', 'projected-mutation-checksum', 'projected-earnings-checksum', 'operator-confirms-attribution-model');", $failures);
expect_contains('missing checksum failure is preserved', $command, "'missing_required_checksum'", $failures);
expect_contains('checksum mismatch failure is preserved', $command, "'checksum_mismatch'", $failures);
expect_contains('attribution failure is preserved', $command, "'attribution_confirmation_required'", $failures);
expect_contains('unsupported attribution exact model check', $command, "block_userid_single_recipient", $failures);

expect_contains('approval apply command has approval checksum', $command, "--approval-package-checksum=<approval_package_checksum>", $failures);
$applyShapeStart = strpos($command, '$applyCommandShape = array(');
$applyShapeEnd = strpos($command, ');', $applyShapeStart);
$applyShape = ($applyShapeStart === false || $applyShapeEnd === false) ? '' : substr($command, $applyShapeStart, $applyShapeEnd - $applyShapeStart);
expect_not_contains('apply command shape must not include limit', $applyShape, '--limit', $failures);

expect_contains('docs dry-run limit only', $doc, '`--limit` is accepted only by Stage 1 dry-run and approval-package generation commands.', $failures);
expect_contains('docs apply scope checksum bound', $doc, 'The apply command does not accept a fresh `--limit`; apply scope is bound by the approved checksum set.', $failures);
expect_contains('docs pending earnings only', $doc, 'Stage 1 creates pending earnings only; it does not credit accounts, create payout rows, send wallet transactions, delete shares, run backend loops, or start the blocks service.', $failures);

if (!empty($failures)) {
	echo "Badpool Stage 1 apply guard harness FAILED\n";
	foreach ($failures as $failure) {
		echo " - $failure\n";
	}
	exit(1);
}

echo "Badpool Stage 1 apply guard harness passed\n";
