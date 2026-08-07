<?php
$root = dirname(__DIR__);
$command = file_get_contents($root.'/web/yaamp/commands/BadpoolGuardCommand.php');
$manifest = file_get_contents($root.'/web/yaamp/core/backend/BadpoolStage1Manifest.php');
$doc = file_get_contents($root.'/docs/badpool-stage1-drain-runner.md');
$failures = array();
function drain_expect($condition,$message,&$failures){ if(!$condition) $failures[]=$message; }
function drain_section($source,$start,$end){ $from=strpos($source,$start); if($from===false)return ''; $to=strpos($source,$end,$from+strlen($start)); return substr($source,$from,$to===false?strlen($source)-$from:$to-$from); }
$approval = drain_section($command, 'private function forwardCatchupStage1DrainApprovalPackageReport', 'private function forwardCatchupStage1DrainApplyReport');
$apply = drain_section($command, 'private function forwardCatchupStage1DrainApplyReport', 'private function forwardCatchupStage1DrainContextArgs');
$inspect = drain_section($command, 'private function forwardCatchupStage1DrainInspectBatch', 'private function forwardCatchupStage1DrainCompareBatchProjection');
$final = drain_section($command, 'private function forwardCatchupStage1DrainFinalReconciliation', 'private function forwardCatchupStage1ManifestCreateTime');

foreach (array("'forward-catchup-stage1-drain-approval-package'", "'forward-catchup-stage1-drain-apply'", 'BadpoolStage1Manifest.php') as $needle) drain_expect(strpos($command,$needle)!==false,'missing aggregate drain contract: '.$needle,$failures);
foreach (array('selection_limit','eligible_candidate_count','selected_count','selected_block_ids','excluded_by_selection_limit_count','excluded_newer_candidate_count','projected_earning_rows','projected_recipient_totals','projected_total_amount','canonical_checksum_inputs','selection_checksum','intended_mutation_checksum','package_checksum','internal_batch_size','projected_batch_count','exact_operator_confirmation') as $field) drain_expect(strpos($manifest,"'$field'")!==false,'manifest missing field '.$field,$failures);
drain_expect(strpos($approval, '$eligibleCandidates = $this->forwardCatchupStage1DryrunCandidates($lastPayoutTime, null)')!==false, 'approval must query the complete eligible cohort before limiting',$failures);
drain_expect(strpos($approval, '$candidates = array_slice($eligibleCandidates, 0, $selectionLimit)')!==false, 'approval must classify only the deterministic bounded prefix',$failures);
drain_expect(strpos($approval, 'count($eligibleCandidates)')!==false && strpos($approval, '$excludedBySelectionLimit')!==false, 'approval must retain full eligibility and bounded exclusion counts',$failures);
drain_expect(strpos($command, "'maximum_eligible_snapshot_order_key'")!==false && strpos($command, "'maximum_selected_order_key'")!==false, 'snapshot must distinguish selected and eligible boundaries',$failures);
drain_expect(strpos($command, 'forwardCatchupStage1DrainCandidatesAfterSnapshot')!==false && strpos($command, 'forwardCatchupStage1DrainCompareOrderKeys')!==false, 'newer-candidate reporting must compare against the eligibility snapshot boundary',$failures);
drain_expect(strpos($command, '$candidateWhere = $this->forwardCatchupImportCandidateWhere();')!==false && strpos($command, 'AND NOT EXISTS (SELECT 1 FROM')!==false, 'manifest selection must exclude linked or otherwise ineligible candidates',$failures);
drain_expect(strpos($approval, 'BadpoolStage1Manifest::build')!==false, 'approval must build immutable manifest',$failures);
drain_expect(strpos($approval, "\$manifest['command'] =")===false, 'approval must not rewrite the builder-owned manifest command after checksum construction',$failures);
drain_expect(strpos($approval, "'wallet_reads'] = false")!==false, 'approval must report no wallet reads',$failures);
drain_expect(strpos($approval, 'forwardCatchupStage1ApplyMutations')===false, 'approval package must not mutate',$failures);
drain_expect(strpos($apply, 'forwardCatchupStage1ApplyApprovalPackageReport')===false, 'apply must not regenerate moving 25-entry approval packages',$failures);
drain_expect(strpos($apply, 'BadpoolStage1Manifest::validateApplyAuthorization($manifest')!==false, 'apply must use the shared producer/consumer authorization contract',$failures);
drain_expect(strpos($apply, 'validateApplyAuthorization') < strpos($apply, 'forwardCatchupStage1DrainPopulateManifestReport'), 'manifest authorization must pass before the post-validation apply path',$failures);
drain_expect(strpos($apply, 'validateApplyAuthorization') < strpos($apply, 'forwardCatchupStage1DrainWriteProgress'), 'manifest authorization failure must occur before progress creation',$failures);
drain_expect(strpos($apply, 'validateApplyAuthorization') < strpos($apply, '$tx = app()->db->beginTransaction()'), 'manifest authorization failure must occur before any database transaction',$failures);
drain_expect(strpos($apply, "'package-checksum'")!==false && strpos($manifest, "'exact_operator_confirmation'")!==false && strpos($manifest, 'providedConfirmation')!==false, 'apply must bind checksum and exact confirmation through the shared authorization contract',$failures);
drain_expect(strpos($apply, 'BadpoolStage1Manifest::batches($manifest)')!==false, 'apply must use deterministic internal manifest batches',$failures);
drain_expect(strpos($apply, 'forwardCatchupStage1DrainInspectBatch')!==false, 'apply must inspect pending/completed database state',$failures);
drain_expect(strpos($apply, 'projection_reverification')!==false && strpos($apply, 'forwardCatchupStage1DrainCompareBatchProjection')!==false, 'apply must reverify authority-bearing projections before mutation',$failures);
drain_expect(strpos($apply, '$tx->commit();')!==false && strpos($apply, 'post_apply_verification')!==false, 'each batch must independently commit then verify',$failures);
drain_expect(strpos($apply, 'forwardCatchupStage1DrainWriteProgress')!==false, 'apply must persist resumable progress after verified batches',$failures);
drain_expect(strpos($manifest, "'progress_checksum'")!==false && strpos($manifest, 'cumulative_rows_created differs from completed manifest batches')!==false, 'persisted progress must be sealed and semantically reconciled before resume',$failures);
drain_expect(strpos($manifest, 'MAX_BATCH_SIZE = 50')!==false && strpos($manifest, 'internal_batch_size must be between 1 and')!==false, 'manifest validation must enforce the bounded transaction ceiling',$failures);
drain_expect(strpos($apply, 'additional_operator_confirmation')===false, 'apply loop must not request per-batch confirmation',$failures);
drain_expect(strpos($command, "array('coin-id','format','selection-limit','internal-batch-size')")!==false, 'generation must accept selection-limit',$failures);
drain_expect(strpos($command, 'return BadpoolStage1Manifest::parseApplyOptions($args)')!==false, 'apply parser must consume the shared executable option contract',$failures);
drain_expect(strpos($command, 'BadpoolStage1Manifest::validateApplyOptions($options)')!==false, 'apply required-option validation must consume the shared executable contract',$failures);
drain_expect(strpos($command, "implode(' ', BadpoolStage1Manifest::applyCommandShape())")!==false, 'help must consume the shared command shape',$failures);
foreach(array('apply_command','apply_command_args','apply_command_shape','field_ref','runtime') as $field) drain_expect(strpos($manifest,$field)!==false,'machine-usable apply contract missing '.$field,$failures);
drain_expect(strpos($manifest, "const SCHEMA = 'badpool.stage1_drain_manifest.v3'")!==false && strpos($manifest, "const LEGACY_SCHEMA = 'badpool.stage1_drain_manifest.v2'")!==false, 'manifest must separate v3 generation from authentic legacy v2 validation',$failures);
drain_expect(strpos($manifest,"'manifest' =>")===false && strpos($manifest,"'confirmation' =>")===false,'guessed aliases must remain absent',$failures);
drain_expect(strpos($command, 'Duplicate option refused: --')!==false, 'duplicate selection-limit must be refused',$failures);
drain_expect(strpos($command, 'Missing required --selection-limit.')!==false && strpos($command, 'invalid_selection_limit')!==false, 'missing and invalid selection limits must be refused',$failures);
drain_expect(strpos($manifest, 'MAX_SELECTION_LIMIT = 1000000')!==false && strpos($manifest, "'/^[1-9][0-9]*$/'")!==false, 'selection limit parser must be canonical and bounded',$failures);
drain_expect(strpos($apply, 'forwardCatchupStage1DrainNewerCandidateCount($manifest)')!==false, 'apply must report newer excluded candidates',$failures);
drain_expect(strpos($apply, 'forwardCatchupStage1DrainFinalReconciliation')!==false, 'apply must perform selected-cohort final reconciliation',$failures);
drain_expect(strpos($command, '$this->guard->addError($message);')!==false, 'refusals and holds must produce a nonzero command result with preserved errors',$failures);
drain_expect(strpos($command.$manifest, "'artifact_path_collision'")!==false, 'manifest/progress path collision must remain rejected',$failures);
drain_expect(strpos($inspect, "'status'=>'pending'")!==false && strpos($inspect, "'status'=>'completed'")!==false && strpos($inspect, "'status'=>'mismatch'")!==false, 'resume inspection must distinguish pending, completed, and mismatch states',$failures);
drain_expect(strpos($inspect, "arraySafeVal(\$row, 'category') === 'new' && count(\$linked) === 0")!==false, 'pending state must require no already-linked earnings',$failures);
drain_expect(strpos($inspect, 'mixed pending/completed state and fails closed')!==false, 'partially completed or newly ineligible batch must fail closed',$failures);
drain_expect(strpos($inspect, "arraySafeVal(\$actual, 'status')")!==false, 'completed earning status must be verified',$failures);
drain_expect(strpos($final, "'rows_match'")!==false && strpos($final, "'recipients_match'")!==false && strpos($final, "'actual_total_amount'")!==false, 'final reconciliation must cover rows, recipients, and amount',$failures);
foreach(array('BackendPayments','BackendCoinPayments','PayoutCommand','badpoolGuardedSendmanyApply','DELETE FROM shares','startService(','restartService(') as $bad) drain_expect(strpos($apply,$bad)===false,'unsafe path present in drain apply: '.$bad,$failures);
drain_expect(strpos($command, 'gettransaction($txid)')===false || strpos(drain_section($command,'private function forwardCatchupStage1DryrunClassify','private function forwardCatchupStage1DryrunPlan'),'gettransaction(')===false, 'Stage1 classification must not use wallet gettransaction',$failures);
drain_expect(strpos($command, 'getrawtransaction($txid, 1)')!==false, 'Stage1 must use chain transaction reads',$failures);
foreach(array('no maturity transition','no account credit','no payout-row creation','no wallet read','no wallet send','no backend loop','no service action','no share deletion') as $boundary) drain_expect(stripos($doc,$boundary)!==false,'documentation missing boundary: '.$boundary,$failures);
foreach(array('badpool.stage1_drain_manifest.v3','legacy v2','never silently reinterpreted','separately generated operator authorization','authorization_refusal','transactional_failure','partial_committed_failure','successful_apply') as $contract) drain_expect(stripos($doc,$contract)!==false,'documentation missing compatibility/classification contract: '.$contract,$failures);

if($failures){ echo "Badpool Stage1 drain guard harness FAILED\n"; foreach($failures as $f) echo " - $f\n"; exit(1); }
echo "Badpool Stage1 drain guard harness passed\n";
