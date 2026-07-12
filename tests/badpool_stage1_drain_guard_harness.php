<?php
$root = dirname(__DIR__);
$commandPath = $root.'/web/yaamp/commands/BadpoolGuardCommand.php';
$docPath = $root.'/docs/badpool-stage1-drain-runner.md';
$failures = array();
function expect_contains($label,$haystack,$needle,&$failures){ if(strpos($haystack,$needle)===false) $failures[]="$label: missing expected text: $needle"; }
function expect_not_contains($label,$haystack,$needle,&$failures){ if(strpos($haystack,$needle)!==false) $failures[]="$label: found forbidden text: $needle"; }
function section_between($haystack,$start,$end){ $s=strpos($haystack,$start); if($s===false)return ''; $e=strpos($haystack,$end,$s+strlen($start)); if($e===false)$e=strlen($haystack); return substr($haystack,$s,$e-$s); }
$command = is_file($commandPath) ? file_get_contents($commandPath) : '';
$doc = is_file($docPath) ? file_get_contents($docPath) : '';
if($command==='') $failures[]='Missing command file';
if($doc==='') $failures[]='Missing drain runner doc';
$plan = section_between($command, 'private function forwardCatchupStage1DrainPlanReport', 'private function forwardCatchupStage1DrainApplyReport');
$apply = section_between($command, 'private function forwardCatchupStage1DrainApplyReport', 'private function forwardCatchupStage1DrainContextArgs');
$validate = section_between($command, 'private function forwardCatchupStage1DrainValidate', 'private function forwardCatchupStage1DrainBaseReport');
$base = section_between($command, 'private function forwardCatchupStage1DrainBaseReport', 'private function forwardCatchupStage1DrainBatchSummary');
expect_contains('plan action registered', $command, "'forward-catchup-stage1-drain-plan'", $failures);
expect_contains('apply action registered', $command, "'forward-catchup-stage1-drain-apply'", $failures);
expect_contains('safe max batches constant', $command, 'FORWARD_CATCHUP_STAGE1_DRAIN_SAFE_MAX_BATCHES = 10', $failures);
expect_contains('confirmation token constant', $command, "FORWARD_CATCHUP_STAGE1_DRAIN_CONFIRMATION = 'stage1_only_no_later_accounting_no_wallet'", $failures);
expect_contains('plan is read-only', $plan, "forwardCatchupStage1DrainBaseReport('forward-catchup-stage1-drain-plan', true", $failures);
expect_not_contains('plan must not call mutation helper', $plan, 'forwardCatchupStage1ApplyMutations', $failures);
expect_not_contains('plan must not open transaction', $plan, 'beginTransaction', $failures);
expect_contains('apply requires operator confirmation', $validate, 'operator-confirms-stage1-drain', $failures);
expect_contains('apply exact confirmation', $validate, 'self::FORWARD_CATCHUP_STAGE1_DRAIN_CONFIRMATION', $failures);
expect_contains('missing coin id refused', $validate, 'coin_id_required', $failures);
expect_contains('batch limit max 50 refused', $validate, 'limit_gt_50', $failures);
expect_contains('missing max batches refused', $validate, 'max-batches', $failures);
expect_contains('safe cap enforced', $validate, 'max_batches_safe_cap_exceeded', $failures);
expect_contains('guarded apply schema', $base, "\$report['schema'] = self::APPLY_SCHEMA;", $failures);
expect_contains('guarded apply mode', $base, "\$report['mode'] = \$readOnly ? 'stage1-drain-plan' : self::APPLY_MODE;", $failures);
foreach(array('account_credit','payout_rows_created','wallet_sends','backend_loops_run','shares_deleted') as $flag) expect_contains('blocked flag '.$flag, $base, "\$report['$flag'] = false", $failures);
expect_contains('per batch checksums', $command, 'projected_mutation_checksum', $failures);
expect_contains('per batch reconciliation', $apply, "\$batch['reconciliation_status']", $failures);
expect_contains('stops preview empty', $apply, "\$report['stop_reason'] = 'preview_empty'; break;", $failures);
expect_contains('stops selected mismatch', $apply, 'selected_count_mismatch', $failures);
expect_contains('stops approval refusal', $apply, 'approval_package_refusal', $failures);
expect_contains('stops apply refusal', $apply, 'apply_refusal', $failures);
expect_contains('stops post verification failure', $apply, 'post_apply_verification_failure', $failures);
expect_contains('summary totals helper', $command, 'private function forwardCatchupStage1DrainAddTotals', $failures);
expect_contains('totals selected from per batch', $command, "\$report['total_selected'] += intval(\$batch['selected_count']);", $failures);
expect_contains('docs plan shape', $doc, 'forward-catchup-stage1-drain-plan', $failures);
expect_contains('docs apply shape', $doc, 'forward-catchup-stage1-drain-apply', $failures);
expect_contains('docs hard boundary', $doc, 'no maturity transition', $failures);
foreach(array('BackendPayments','BackendCoinPayments','PayoutCommand','badpoolGuardedSendmanyApply','DELETE FROM shares','startService(','restartService(') as $bad) expect_not_contains('unsafe broad path in drain apply', $apply, $bad, $failures);
if($failures){ echo "Badpool Stage1 drain guard harness FAILED\n"; foreach($failures as $f) echo " - $f\n"; exit(1); }
echo "Badpool Stage1 drain guard harness passed\n";
