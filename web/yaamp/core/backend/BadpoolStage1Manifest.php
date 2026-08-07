<?php

class BadpoolStage1Manifest
{
	const SCHEMA = 'badpool.stage1_drain_manifest.v3';
	const LEGACY_SCHEMA = 'badpool.stage1_drain_manifest.v2';
	const PACKAGE_TYPE = 'forward-catchup-stage1-drain';
	const COMMAND = 'forward-catchup-stage1-drain-approval-package';
	const PROGRESS_SCHEMA = 'badpool.stage1_drain_progress.v1';
	const DEFAULT_BATCH_SIZE = 25;
	const MAX_BATCH_SIZE = 50;
	const MAX_SELECTION_LIMIT = 1000000;
	const AMOUNT_SCALE = 12;
	const CONFIRMATION_SUFFIX = 'stage1_only_no_later_accounting_no_wallet';
	const APPLY_COMMAND = 'forward-catchup-stage1-drain-apply';

	/** The single authoritative apply option contract used by generation, parsing and help. */
	public static function applyOptionContract()
	{
		return array(
			'coin-id' => array('required'=>true, 'source'=>'manifest', 'field_ref'=>'/coin_id'),
			'manifest-file' => array('required'=>true, 'source'=>'runtime', 'value_type'=>'absolute_json_path', 'purpose'=>'path_to_this_exact_manifest'),
			'progress-file' => array('required'=>true, 'source'=>'runtime', 'value_type'=>'absolute_json_path', 'purpose'=>'resumable_progress_path'),
			'package-checksum' => array('required'=>true, 'source'=>'manifest', 'field_ref'=>'/package_checksum/value'),
			'operator-confirms-stage1-drain' => array('required'=>true, 'source'=>'manifest', 'field_ref'=>'/exact_operator_confirmation'),
			'format' => array('required'=>true, 'source'=>'literal', 'value'=>'json'),
		);
	}

	public static function applyCommandShape()
	{
		$shape = array('php', 'yaamp/yiic.php', 'badpoolguard', self::APPLY_COMMAND);
		foreach (self::applyOptionContract() as $name => $definition) {
			if ($definition['source'] === 'runtime') $value = '<runtime:'.$definition['purpose'].'>';
			elseif ($definition['source'] === 'manifest') $value = '<manifest:'.$definition['field_ref'].'>';
			else $value = $definition['value'];
			$shape[] = '--'.$name.'='.$value;
		}
		return $shape;
	}

	public static function renderApplyArgv($manifest, $runtimeValues)
	{
		$validation = self::validate($manifest);
		if (self::value($validation, 'status') !== 'pass') throw new InvalidArgumentException('Cannot render argv from an invalid Stage1 manifest.');
		if (self::value($manifest, 'schema') !== self::SCHEMA) throw new InvalidArgumentException('Legacy v2 manifests do not contain a structured apply contract.');
		$argv = array('php', 'yaamp/yiic.php', 'badpoolguard', self::value($manifest, 'apply_command'));
		foreach (self::value($manifest, 'apply_command_args', array()) as $name => $definition) {
			if (self::value($definition, 'source') === 'runtime') {
				if (!array_key_exists($name, $runtimeValues)) throw new InvalidArgumentException('Missing runtime value for --'.$name.'.');
				$value = $runtimeValues[$name];
			} elseif (self::value($definition, 'source') === 'manifest') {
				$value = self::jsonPointerValue($manifest, self::value($definition, 'field_ref'));
			} else {
				$value = self::value($definition, 'value');
			}
			$argv[] = '--'.$name.'='.$value;
		}
		return $argv;
	}

	public static function parseApplyOptions($args)
	{
		$options = array();
		$allowed = array_keys(self::applyOptionContract());
		foreach ($args as $arg) {
			if (!preg_match('/^--([^=]+)=(.*)$/', $arg, $matches)) {
				$options['__parse_error'] = 'Option requires an explicit value: '.$arg;
				continue;
			}
			$name = strtolower($matches[1]);
			if (!in_array($name, $allowed, true)) $options['__parse_error'] = 'Unknown option refused: --'.$matches[1];
			elseif (array_key_exists($name, $options)) $options['__parse_error'] = 'Duplicate option refused: --'.$matches[1];
			else $options[$name] = $matches[2];
		}
		return $options;
	}

	public static function validateApplyOptions($options)
	{
		if (isset($options['__parse_error'])) return array('status'=>'fail', 'reason'=>'invalid_option', 'message'=>$options['__parse_error']);
		$missingReasons = array(
			'coin-id'=>'coin_id_required',
			'manifest-file'=>'manifest_file_required',
			'progress-file'=>'progress_file_required',
			'package-checksum'=>'package_checksum_required',
			'operator-confirms-stage1-drain'=>'operator_confirmation_required',
			'format'=>'json_format_required',
		);
		foreach (self::applyOptionContract() as $name => $definition) {
			if (self::value($definition, 'required') && (!isset($options[$name]) || $options[$name] === '')) return array('status'=>'fail', 'reason'=>$missingReasons[$name], 'message'=>'Missing required --'.$name.'.');
		}
		if (!preg_match('/^[0-9]+$/', (string)$options['coin-id']) || intval($options['coin-id']) <= 0) return array('status'=>'fail', 'reason'=>'invalid_coin_scope', 'message'=>'--coin-id must be a positive integer.');
		if ((string)$options['format'] !== 'json') return array('status'=>'fail', 'reason'=>'json_format_required', 'message'=>'Stage1 drain apply supports --format=json only.');
		if (!self::isAbsoluteJsonPath($options['manifest-file'])) return array('status'=>'fail', 'reason'=>'invalid_manifest_file', 'message'=>'--manifest-file must be an absolute JSON path.');
		if (!self::isAbsoluteJsonPath($options['progress-file'])) return array('status'=>'fail', 'reason'=>'invalid_progress_file', 'message'=>'--progress-file must be an absolute JSON path.');
		if ($options['manifest-file'] === $options['progress-file']) return array('status'=>'fail', 'reason'=>'artifact_path_collision', 'message'=>'Manifest and progress files must be different paths.');
		if (!preg_match('/^[a-f0-9]{64}$/i', (string)$options['package-checksum'])) return array('status'=>'fail', 'reason'=>'invalid_package_checksum', 'message'=>'--package-checksum must be a SHA-256 hex value.');
		return array('status'=>'pass', 'reason'=>null, 'message'=>null);
	}

	public static function classifyApplyResult($reason, $batchesAttempted, $committedBatches, $transactionStarted=false, $rolledBack=false, $verifiedPrior=false, $fullyReconciled=false)
	{
		if ($reason === null && $fullyReconciled) return 'successful_apply';
		if (intval($committedBatches) > 0 || $verifiedPrior) return 'partial_committed_failure';
		if ($transactionStarted) return 'transactional_failure';
		$invocation = array('invalid_option','coin_id_required','invalid_coin_scope','json_format_required','manifest_file_required','progress_file_required','invalid_manifest_file','invalid_progress_file','artifact_path_collision');
		if (in_array($reason, $invocation, true)) return 'invocation_refusal';
		return 'authorization_refusal';
	}

	public static function build($coinId, $algo, $snapshot, $selectionLimit, $eligibleCandidateCount, $excludedBySelectionLimitCount, $excludedNewerCount, $selectedRecords, $projectedMutations, $projectedEarnings, $internalBatchSize=self::DEFAULT_BATCH_SIZE)
	{
		$selectionLimit = self::parseSelectionLimit($selectionLimit);
		$internalBatchSize = intval($internalBatchSize);
		if ($internalBatchSize <= 0 || $internalBatchSize > self::MAX_BATCH_SIZE) throw new InvalidArgumentException('internal_batch_size must be between 1 and '.self::MAX_BATCH_SIZE.'.');

		$records = array_values($selectedRecords);
		$mutations = array_values($projectedMutations);
		$earnings = array_values($projectedEarnings);
		$selectedIds = array();
		foreach ($records as $record) $selectedIds[] = intval(self::value($record, 'block_id'));
		$recipientTotals = self::recipientTotals($earnings);
		$projectedTotal = self::earningTotal($earnings);
		$projectedBatchCount = count($selectedIds) === 0 ? 0 : intval(ceil(count($selectedIds) / $internalBatchSize));
		$approvalStatus = 'pass';
		foreach ($mutations as $mutation) if (!in_array(self::value($mutation, 'classification'), array('stage1_import_generate','stage1_import_immature','stage1_import_reward','stage1_mark_orphan_no_earnings'), true)) $approvalStatus = 'blocked';
		if (count($selectedIds) === 0 || count($mutations) !== count($selectedIds)) $approvalStatus = 'blocked';

		$selectionInput = array(
			'coin_id' => intval($coinId),
			'snapshot_boundary' => $snapshot,
			'selection_limit' => $selectionLimit,
			'eligible_candidate_count' => intval($eligibleCandidateCount),
			'excluded_by_selection_limit_count' => intval($excludedBySelectionLimitCount),
			'selection_order' => 'height,time,id',
			'selected_block_ids' => $selectedIds,
			'selected_records' => $records,
		);
		$selectionChecksum = self::checksum($selectionInput, 'authorizes the exact bounded immutable Stage1 selected cohort');
		$mutationInput = array(
			'coin_id' => intval($coinId),
			'selection_checksum' => $selectionChecksum['value'],
			'projected_block_mutations' => $mutations,
			'projected_earning_rows' => $earnings,
			'projected_recipient_totals' => $recipientTotals,
			'projected_total_amount' => $projectedTotal,
			'approval_status' => $approvalStatus,
		);
		$mutationChecksum = self::checksum($mutationInput, 'authorizes the complete intended Stage1 mutation and attribution');
		$packageInput = array(
			'schema' => self::SCHEMA,
			'package_type' => self::PACKAGE_TYPE,
			'command' => self::COMMAND,
			'coin_id' => intval($coinId),
			'algo' => (string)$algo,
			'snapshot_boundary' => $snapshot,
			'selection_limit' => $selectionLimit,
			'eligible_candidate_count' => intval($eligibleCandidateCount),
			'selected_count' => count($selectedIds),
			'excluded_by_selection_limit_count' => intval($excludedBySelectionLimitCount),
			'excluded_newer_candidate_count' => intval($excludedNewerCount),
			'internal_batch_size' => $internalBatchSize,
			'projected_batch_count' => $projectedBatchCount,
			'projected_earning_row_count' => count($earnings),
			'projected_recipient_count' => count($recipientTotals),
			'projected_total_amount' => $projectedTotal,
			'approval_status' => $approvalStatus,
			'selection_checksum' => $selectionChecksum['value'],
			'intended_mutation_checksum' => $mutationChecksum['value'],
		);
		$packageChecksum = self::checksum($packageInput, 'operator authorization boundary for one exact bounded Stage1 drain manifest');

		$manifest = array(
			'schema' => self::SCHEMA,
			'package_type' => self::PACKAGE_TYPE,
			'command' => self::COMMAND,
			'generated_at' => gmdate('c'),
			'coin_id' => intval($coinId),
			'algo' => (string)$algo,
			'snapshot_boundary' => $snapshot,
			'selection_limit' => $selectionLimit,
			'eligible_candidate_count' => intval($eligibleCandidateCount),
			'selected_count' => count($selectedIds),
			'selected_block_ids' => $selectedIds,
			'excluded_by_selection_limit_count' => intval($excludedBySelectionLimitCount),
			'excluded_newer_candidate_count' => intval($excludedNewerCount),
			'selected_records' => $records,
			'projected_block_mutations' => $mutations,
			'projected_earning_rows' => $earnings,
			'projected_recipient_totals' => $recipientTotals,
			'projected_earning_row_count' => count($earnings),
			'projected_recipient_count' => count($recipientTotals),
			'projected_total_amount' => $projectedTotal,
			'approval_status' => $approvalStatus,
			'internal_batch_size' => $internalBatchSize,
			'projected_batch_count' => $projectedBatchCount,
			'canonical_checksum_inputs' => array(
				'selection' => $selectionInput,
				'intended_mutation' => $mutationInput,
				'package' => $packageInput,
			),
			'selection_checksum' => $selectionChecksum,
			'intended_mutation_checksum' => $mutationChecksum,
			'package_checksum' => $packageChecksum,
			'exact_operator_confirmation' => self::confirmationValue($packageChecksum['value']),
			'authorization_boundary' => 'One exact confirmation authorizes this bounded immutable Stage1 cohort. Internal batches require no additional confirmation.',
			'pipeline_boundary' => array(
				'stage1_only' => true,
				'maturity_transition' => false,
				'account_credit' => false,
				'payout_row_creation' => false,
				'wallet_reads' => false,
				'wallet_sends' => false,
				'backend_loops' => false,
				'service_actions' => false,
				'share_deletion' => false,
			),
		);
		$manifest['apply_command'] = self::APPLY_COMMAND;
		$manifest['apply_command_args'] = self::applyOptionContract();
		$manifest['apply_command_shape'] = self::applyCommandShape();
		return $manifest;
	}

	public static function validate($manifest)
	{
		$errors = array();
		if (!is_array($manifest)) return array('status'=>'fail', 'errors'=>array('Manifest must be a JSON object.'));
		$schema = self::value($manifest, 'schema');
		if (!in_array($schema, array(self::LEGACY_SCHEMA, self::SCHEMA), true)) $errors[] = 'Unexpected manifest schema.';
		if (self::value($manifest, 'package_type') !== self::PACKAGE_TYPE) $errors[] = 'Unexpected manifest package_type.';
		if (self::value($manifest, 'command') !== self::COMMAND) $errors[] = 'Unexpected manifest command.';
		$structuredFields = array('apply_command','apply_command_args','apply_command_shape');
		$presentStructuredFields = array();
		foreach ($structuredFields as $field) if (array_key_exists($field, $manifest)) $presentStructuredFields[] = $field;
		if ($schema === self::LEGACY_SCHEMA && !empty($presentStructuredFields)) $errors[] = 'Legacy v2 manifests must not contain v3 structured apply fields.';
		if ($schema === self::SCHEMA) {
			foreach ($structuredFields as $field) if (!array_key_exists($field, $manifest)) $errors[] = 'Missing required v3 structured field: '.$field.'.';
			if (self::value($manifest, 'apply_command') !== self::APPLY_COMMAND) $errors[] = 'Unexpected apply_command.';
			if (self::value($manifest, 'apply_command_args', array()) !== self::applyOptionContract()) $errors[] = 'apply_command_args differs from the canonical apply option contract.';
			if (self::value($manifest, 'apply_command_shape', array()) !== self::applyCommandShape()) $errors[] = 'apply_command_shape differs from the canonical apply option contract.';
		}
		$ids = self::value($manifest, 'selected_block_ids', array());
		$records = self::value($manifest, 'selected_records', array());
		$mutations = self::value($manifest, 'projected_block_mutations', array());
		$earnings = self::value($manifest, 'projected_earning_rows', array());
		if (!is_array($ids) || !is_array($records) || !is_array($mutations) || !is_array($earnings)) $errors[] = 'Manifest cohort and projection fields must be arrays.';
		if (!empty($errors)) return array('status'=>'fail', 'errors'=>$errors);

		$normalizedIds = array();
		$seen = array();
		foreach ($ids as $id) {
			if (!is_int($id) && !ctype_digit((string)$id)) { $errors[] = 'selected_block_ids contains a non-integer value.'; continue; }
			$id = intval($id);
			if ($id <= 0) $errors[] = 'selected_block_ids contains a non-positive value.';
			if (isset($seen[$id])) $errors[] = 'selected_block_ids contains a duplicate ID: '.$id.'.';
			$seen[$id] = true;
			$normalizedIds[] = $id;
		}
		if (intval(self::value($manifest, 'selected_count', -1)) !== count($normalizedIds)) $errors[] = 'selected_count does not equal count(selected_block_ids).';
		if (count($records) !== count($normalizedIds)) $errors[] = 'selected_records count does not equal selected_count.';
		if (count($mutations) !== count($normalizedIds)) $errors[] = 'projected_block_mutations count does not equal selected_count.';
		foreach ($records as $index => $record) if (intval(self::value($record, 'block_id')) !== self::value($normalizedIds, $index)) $errors[] = 'selected_records ordering or block ID differs at index '.$index.'.';
		foreach ($mutations as $index => $mutation) if (intval(self::value($mutation, 'blockid')) !== self::value($normalizedIds, $index)) $errors[] = 'projected_block_mutations ordering or block ID differs at index '.$index.'.';
		$lastOrder = null;
		$earningsByBlock = array();
		foreach ($earnings as $earning) {
			$blockId = intval(self::value($earning, 'blockid'));
			if (isset($earningsByBlock[$blockId])) $errors[] = 'Multiple projected earnings exist for block '.$blockId.'.';
			$earningsByBlock[$blockId] = $earning;
			if (!isset($seen[$blockId])) $errors[] = 'Projected earning references a block outside selected_block_ids.';
			if (intval(self::value($earning, 'coinid')) !== intval(self::value($manifest, 'coin_id'))) $errors[] = 'Projected earning coin attribution mismatch for block '.$blockId.'.';
			if (intval(self::value($earning, 'status', -1)) !== 0) $errors[] = 'Projected earning status must be zero for block '.$blockId.'.';
		}
		foreach ($records as $index => $record) {
			$order = array(intval(self::value($record, 'height')), intval(self::value($record, 'time')), intval(self::value($record, 'block_id')));
			if ($lastOrder !== null && self::compareOrder($lastOrder, $order) > 0) $errors[] = 'Selected records are not deterministically ordered by height,time,id.';
			$lastOrder = $order;
			$mutation = self::value($mutations, $index, array());
			$class = self::value($record, 'classification');
			$mutationClass = self::value($mutation, 'classification');
			$classMatches = $class === $mutationClass || (in_array($class, array('stage1_import_generate','stage1_import_immature'), true) && $mutationClass === 'stage1_import_reward');
			if (!$classMatches) $errors[] = 'Record and mutation classification differ for block '.self::value($record, 'block_id').'.';
			if (self::value($record, 'current_category') !== 'new') $errors[] = 'Selected record is not category=new.';
			$blockId = intval(self::value($record, 'block_id'));
			if ($class === 'stage1_mark_orphan_no_earnings') {
				if (isset($earningsByBlock[$blockId])) $errors[] = 'Orphan projection contains an earning for block '.$blockId.'.';
			} elseif (in_array($class, array('stage1_import_generate','stage1_import_immature'), true)) {
				if (!isset($earningsByBlock[$blockId])) $errors[] = 'Generated projection is missing an earning for block '.$blockId.'.';
				else {
					if (intval(self::value($earningsByBlock[$blockId], 'userid')) !== intval(self::value($record, 'userid'))) $errors[] = 'Projected earning recipient differs from block userid for block '.$blockId.'.';
					try { if (self::normalizeAmount(self::value($earningsByBlock[$blockId], 'amount')) !== self::normalizeAmount(self::value($record, 'projected_earning_amount'))) $errors[] = 'Record and earning amount differ for block '.$blockId.'.'; } catch (InvalidArgumentException $e) { $errors[] = 'Invalid projected amount for block '.$blockId.'.'; }
				}
			} else $errors[] = 'Unapproved Stage1 classification for block '.$blockId.'.';
			if ((string)self::value($record, 'projected_txhash') !== (string)self::value($mutation, 'would_set_txhash')) $errors[] = 'Record and mutation txhash differ for block '.$blockId.'.';
			if ((string)self::value($record, 'projected_block_category') !== (string)self::value($mutation, 'would_set_category')) $errors[] = 'Record and mutation category differ for block '.$blockId.'.';
		}

		$batchSize = intval(self::value($manifest, 'internal_batch_size', 0));
		$selectionLimitValue = self::value($manifest, 'selection_limit');
		$eligibleCandidateCountValue = self::value($manifest, 'eligible_candidate_count');
		$selectedCountValue = self::value($manifest, 'selected_count');
		$excludedBySelectionLimitCountValue = self::value($manifest, 'excluded_by_selection_limit_count');
		$selectionLimit = is_int($selectionLimitValue) ? $selectionLimitValue : 0;
		$eligibleCandidateCount = is_int($eligibleCandidateCountValue) ? $eligibleCandidateCountValue : -1;
		$selectedCount = is_int($selectedCountValue) ? $selectedCountValue : -1;
		$excludedBySelectionLimitCount = is_int($excludedBySelectionLimitCountValue) ? $excludedBySelectionLimitCountValue : -1;
		$expectedBatchCount = $batchSize > 0 && count($normalizedIds) > 0 ? intval(ceil(count($normalizedIds) / $batchSize)) : 0;
		if ($batchSize <= 0 || $batchSize > self::MAX_BATCH_SIZE) $errors[] = 'internal_batch_size must be between 1 and '.self::MAX_BATCH_SIZE.'.';
		if (intval(self::value($manifest, 'projected_batch_count', -1)) !== $expectedBatchCount) $errors[] = 'projected_batch_count does not match selected_count/internal_batch_size.';
		if (!is_int($selectionLimitValue) || $selectionLimit <= 0 || $selectionLimit > self::MAX_SELECTION_LIMIT) $errors[] = 'selection_limit must be a bounded positive integer.';
		if (!is_int($eligibleCandidateCountValue) || $eligibleCandidateCount < 0) $errors[] = 'eligible_candidate_count must be a non-negative integer.';
		if (!is_int($selectedCountValue) || $selectedCount < 0) $errors[] = 'selected_count must be a non-negative integer.';
		if (!is_int($excludedBySelectionLimitCountValue) || $excludedBySelectionLimitCount < 0) $errors[] = 'excluded_by_selection_limit_count must be a non-negative integer.';
		$expectedSelectedCount = $selectionLimit > 0 && $eligibleCandidateCount >= 0 ? min($selectionLimit, $eligibleCandidateCount) : -1;
		if ($selectedCount !== $expectedSelectedCount) $errors[] = 'selected_count must equal min(selection_limit, eligible_candidate_count).';
		if ($excludedBySelectionLimitCount !== $eligibleCandidateCount - $selectedCount) $errors[] = 'excluded_by_selection_limit_count must equal eligible_candidate_count minus selected_count.';
		if (!is_int(self::value($manifest, 'excluded_newer_candidate_count')) || intval(self::value($manifest, 'excluded_newer_candidate_count', -1)) < 0) $errors[] = 'excluded_newer_candidate_count must be a non-negative integer.';

		$snapshot = self::value($manifest, 'snapshot_boundary', array());
		$selectedOrderKey = self::orderKey(self::value($snapshot, 'maximum_selected_order_key'));
		$eligibleOrderKey = self::orderKey(self::value($snapshot, 'maximum_eligible_snapshot_order_key'));
		if (!is_array($snapshot)) $errors[] = 'snapshot_boundary must be an object.';
		if (self::value($snapshot, 'candidate_query_completed_before_apply') !== true) $errors[] = 'snapshot candidate query must complete before apply.';
		if (self::value($snapshot, 'new_candidates_after_snapshot_are_excluded') !== true) $errors[] = 'post-snapshot candidates must remain excluded.';
		if (self::value($snapshot, 'post_snapshot_candidates_are_separately_counted') !== true) $errors[] = 'post-snapshot candidates must be counted separately.';
		if (self::value($snapshot, 'selection_limit') !== $selectionLimit) $errors[] = 'snapshot selection_limit mismatch.';
		if (self::value($snapshot, 'eligible_candidate_count') !== $eligibleCandidateCount) $errors[] = 'snapshot eligible_candidate_count mismatch.';
		if (self::value($snapshot, 'excluded_by_selection_limit_count') !== $excludedBySelectionLimitCount) $errors[] = 'snapshot excluded_by_selection_limit_count mismatch.';
		if ($selectedOrderKey === null || $eligibleOrderKey === null) $errors[] = 'snapshot order-key boundaries must contain integer height,time,id values.';
		if ($selectedOrderKey !== null && !empty($records)) {
			$lastRecord = $records[count($records) - 1];
			$lastSelectedOrderKey = array(intval(self::value($lastRecord, 'height')), intval(self::value($lastRecord, 'time')), intval(self::value($lastRecord, 'block_id')));
			if (self::compareOrder($selectedOrderKey, $lastSelectedOrderKey) !== 0) $errors[] = 'maximum_selected_order_key does not match the selected cohort boundary.';
		}
		if ($selectedOrderKey !== null && $eligibleOrderKey !== null) {
			$boundaryComparison = self::compareOrder($eligibleOrderKey, $selectedOrderKey);
			if ($excludedBySelectionLimitCount === 0 && $boundaryComparison !== 0) $errors[] = 'maximum eligible and selected order keys must match when no snapshot candidate is excluded by the limit.';
			if ($excludedBySelectionLimitCount > 0 && $boundaryComparison <= 0) $errors[] = 'maximum eligible order key must follow the selected order key when the limit excludes snapshot candidates.';
		}

		$recipientTotals = self::recipientTotals($earnings);
		$projectedTotal = self::earningTotal($earnings);
		if (self::canonicalJson(self::value($manifest, 'projected_recipient_totals', array())) !== self::canonicalJson($recipientTotals)) $errors[] = 'projected_recipient_totals mismatch.';
		if ((string)self::value($manifest, 'projected_total_amount') !== $projectedTotal) $errors[] = 'projected_total_amount mismatch.';
		if (intval(self::value($manifest, 'projected_earning_row_count', -1)) !== count($earnings)) $errors[] = 'projected_earning_row_count mismatch.';
		if (intval(self::value($manifest, 'projected_recipient_count', -1)) !== count($recipientTotals)) $errors[] = 'projected_recipient_count mismatch.';
		$boundary = self::value($manifest, 'pipeline_boundary', array());
		if (self::value($boundary, 'stage1_only') !== true) $errors[] = 'pipeline_boundary must authorize Stage1 only.';
		foreach (array('maturity_transition','account_credit','payout_row_creation','wallet_reads','wallet_sends','backend_loops','service_actions','share_deletion') as $field) if (self::value($boundary, $field) !== false) $errors[] = 'Forbidden pipeline boundary enabled: '.$field.'.';

		$inputs = self::value($manifest, 'canonical_checksum_inputs', array());
		$selectionInput = array('coin_id'=>intval(self::value($manifest,'coin_id')), 'snapshot_boundary'=>self::value($manifest,'snapshot_boundary'), 'selection_limit'=>$selectionLimit, 'eligible_candidate_count'=>$eligibleCandidateCount, 'excluded_by_selection_limit_count'=>$excludedBySelectionLimitCount, 'selection_order'=>'height,time,id', 'selected_block_ids'=>$normalizedIds, 'selected_records'=>$records);
		$selectionChecksum = self::checksum($selectionInput, 'authorizes the exact bounded immutable Stage1 selected cohort');
		$mutationInput = array('coin_id'=>intval(self::value($manifest,'coin_id')), 'selection_checksum'=>$selectionChecksum['value'], 'projected_block_mutations'=>$mutations, 'projected_earning_rows'=>$earnings, 'projected_recipient_totals'=>$recipientTotals, 'projected_total_amount'=>$projectedTotal, 'approval_status'=>self::value($manifest,'approval_status'));
		$mutationChecksum = self::checksum($mutationInput, 'authorizes the complete intended Stage1 mutation and attribution');
		$packageInput = array('schema'=>self::value($manifest,'schema'), 'package_type'=>self::value($manifest,'package_type'), 'command'=>self::value($manifest,'command'), 'coin_id'=>intval(self::value($manifest,'coin_id')), 'algo'=>(string)self::value($manifest,'algo'), 'snapshot_boundary'=>self::value($manifest,'snapshot_boundary'), 'selection_limit'=>$selectionLimit, 'eligible_candidate_count'=>$eligibleCandidateCount, 'selected_count'=>$selectedCount, 'excluded_by_selection_limit_count'=>$excludedBySelectionLimitCount, 'excluded_newer_candidate_count'=>intval(self::value($manifest,'excluded_newer_candidate_count')), 'internal_batch_size'=>$batchSize, 'projected_batch_count'=>intval(self::value($manifest,'projected_batch_count')), 'projected_earning_row_count'=>intval(self::value($manifest,'projected_earning_row_count')), 'projected_recipient_count'=>intval(self::value($manifest,'projected_recipient_count')), 'projected_total_amount'=>$projectedTotal, 'approval_status'=>self::value($manifest,'approval_status'), 'selection_checksum'=>$selectionChecksum['value'], 'intended_mutation_checksum'=>$mutationChecksum['value']);
		if (self::canonicalJson(self::value($inputs, 'selection', array())) !== self::canonicalJson($selectionInput)) $errors[] = 'Canonical selection input differs from visible manifest fields.';
		if (self::canonicalJson(self::value($inputs, 'intended_mutation', array())) !== self::canonicalJson($mutationInput)) $errors[] = 'Canonical intended mutation input differs from visible manifest fields.';
		if (self::canonicalJson(self::value($inputs, 'package', array())) !== self::canonicalJson($packageInput)) $errors[] = 'Canonical package input differs from visible manifest fields.';

		$checks = array(
			'selection_checksum' => $selectionChecksum,
			'intended_mutation_checksum' => $mutationChecksum,
			'package_checksum' => self::checksum($packageInput, 'operator authorization boundary for one exact bounded Stage1 drain manifest'),
		);
		foreach ($checks as $field => $expected) if ((string)self::checksumValue(self::value($manifest, $field)) !== $expected['value']) $errors[] = $field.' mismatch.';
		$expectedConfirmation = self::confirmationValue($checks['package_checksum']['value']);
		if ((string)self::value($manifest, 'exact_operator_confirmation') !== $expectedConfirmation) $errors[] = 'exact_operator_confirmation mismatch.';

		return array('status'=>empty($errors) ? 'pass' : 'fail', 'errors'=>$errors, 'package_checksum'=>$checks['package_checksum']['value']);
	}

	public static function validateApplyAuthorization($manifest, $providedPackageChecksum, $providedConfirmation, $coinId)
	{
		$validation = self::validate($manifest);
		$result = array('status'=>'fail', 'stop_reason'=>'manifest_validation_failed', 'error'=>implode(' ', self::value($validation, 'errors', array())), 'manifest_validation'=>$validation, 'package_checksum'=>null);
		if (self::value($validation, 'status') !== 'pass') return $result;

		$packageChecksum = self::checksumValue(self::value($manifest, 'package_checksum'));
		$result['package_checksum'] = $packageChecksum;
		if ((string)$providedPackageChecksum !== (string)$packageChecksum) {
			$result['stop_reason'] = 'package_checksum_mismatch';
			$result['error'] = 'Provided package checksum does not match the independently verified manifest.';
			return $result;
		}
		if (intval(self::value($manifest, 'coin_id')) !== intval($coinId)) {
			$result['stop_reason'] = 'coin_scope_mismatch';
			$result['error'] = 'Manifest coin_id differs from command scope.';
			return $result;
		}
		if (self::value($manifest, 'approval_status') !== 'pass') {
			$result['stop_reason'] = 'manifest_not_approved';
			$result['error'] = 'Manifest approval_status is not pass.';
			return $result;
		}
		if ((string)$providedConfirmation !== (string)self::value($manifest, 'exact_operator_confirmation')) {
			$result['stop_reason'] = 'operator_confirmation_required';
			$result['error'] = 'Missing exact operator confirmation from the approved manifest.';
			return $result;
		}

		$result['status'] = 'pass';
		$result['stop_reason'] = null;
		$result['error'] = null;
		$result['post_manifest_validation'] = true;
		return $result;
	}

	public static function batches($manifest)
	{
		$size = intval(self::value($manifest, 'internal_batch_size', 0));
		if ($size <= 0) return array();
		return array_chunk(self::value($manifest, 'selected_block_ids', array()), $size);
	}

	public static function initialProgress($manifest)
	{
		$ids = array_values(self::value($manifest, 'selected_block_ids', array()));
		return self::sealProgress(array(
			'schema' => self::PROGRESS_SCHEMA,
			'package_checksum' => self::checksumValue(self::value($manifest, 'package_checksum')),
			'selected_block_ids' => $ids,
			'completed_batch_count' => 0,
			'completed_block_ids' => array(),
			'remaining_block_ids' => $ids,
			'completed_batches' => array(),
			'cumulative_rows_created' => 0,
			'cumulative_amount' => self::zeroAmount(),
			'failure_point' => null,
			'failure_reason' => null,
			'retry_safe' => true,
			'same_manifest_confirmation_required' => true,
			'updated_at' => gmdate('c'),
		));
	}

	public static function validateProgress($manifest, $progress)
	{
		$errors = array();
		if (!is_array($progress) || self::value($progress, 'schema') !== self::PROGRESS_SCHEMA) $errors[] = 'Unexpected progress schema.';
		$checksum = self::value($progress, 'progress_checksum', array());
		$checksumInput = is_array($progress) ? $progress : array();
		unset($checksumInput['progress_checksum']);
		$expectedChecksum = self::checksum($checksumInput, 'detects alteration of persisted Stage1 drain progress');
		if ((string)self::checksumValue($checksum) !== $expectedChecksum['value']) $errors[] = 'progress_checksum mismatch.';
		if ((string)self::value($progress, 'package_checksum') !== (string)self::checksumValue(self::value($manifest, 'package_checksum'))) $errors[] = 'Progress belongs to a different or altered manifest.';
		$selected = array_values(self::value($manifest, 'selected_block_ids', array()));
		$completed = array_values(self::value($progress, 'completed_block_ids', array()));
		$remaining = array_values(self::value($progress, 'remaining_block_ids', array()));
		if (self::canonicalJson(self::value($progress, 'selected_block_ids', array())) !== self::canonicalJson($selected)) $errors[] = 'Progress selected cohort differs from manifest.';
		if (self::canonicalJson(array_slice($selected, 0, count($completed))) !== self::canonicalJson($completed)) $errors[] = 'Completed IDs are not the deterministic manifest prefix.';
		if (self::canonicalJson(array_slice($selected, count($completed))) !== self::canonicalJson($remaining)) $errors[] = 'Remaining IDs are not the deterministic manifest suffix.';

		$batches = self::batches($manifest);
		$completedBatchCount = intval(self::value($progress, 'completed_batch_count', -1));
		$completedBatches = self::value($progress, 'completed_batches', array());
		if (!is_array($completedBatches)) { $errors[] = 'completed_batches must be an array.'; $completedBatches = array(); }
		if ($completedBatchCount < 0 || $completedBatchCount > count($batches)) $errors[] = 'completed_batch_count is outside the manifest batch range.';
		if (count($completedBatches) !== $completedBatchCount) $errors[] = 'completed_batches count differs from completed_batch_count.';
		$expectedCompleted = array();
		$expectedRows = 0;
		$expectedAmount = self::zeroAmount();
		$earnings = self::value($manifest, 'projected_earning_rows', array());
		for ($index = 0; $index < $completedBatchCount && isset($batches[$index]); $index++) {
			$batchIds = array_values($batches[$index]);
			$expectedCompleted = array_merge($expectedCompleted, $batchIds);
			$batchRows = 0;
			$batchAmount = self::zeroAmount();
			$wanted = array();
			foreach ($batchIds as $id) $wanted[intval($id)] = true;
			foreach ($earnings as $earning) if (isset($wanted[intval(self::value($earning, 'blockid'))])) {
				$batchRows++;
				$batchAmount = self::addAmounts($batchAmount, self::value($earning, 'amount', '0'));
			}
			$expectedRows += $batchRows;
			$expectedAmount = self::addAmounts($expectedAmount, $batchAmount);
			$entry = self::value($completedBatches, $index, array());
			if (intval(self::value($entry, 'batch_number')) !== $index + 1) $errors[] = 'Completed batch number differs at index '.$index.'.';
			if (self::canonicalJson(self::value($entry, 'block_ids', array())) !== self::canonicalJson($batchIds)) $errors[] = 'Completed batch IDs differ at index '.$index.'.';
			if (intval(self::value($entry, 'rows_created', -1)) !== $batchRows) $errors[] = 'Completed batch row count differs at index '.$index.'.';
			try { if (self::normalizeAmount(self::value($entry, 'amount')) !== $batchAmount) $errors[] = 'Completed batch amount differs at index '.$index.'.'; }
			catch (InvalidArgumentException $e) { $errors[] = 'Completed batch amount is invalid at index '.$index.'.'; }
		}
		if (self::canonicalJson($completed) !== self::canonicalJson($expectedCompleted)) $errors[] = 'Completed IDs do not equal the declared complete batch prefix.';
		if (intval(self::value($progress, 'cumulative_rows_created', -1)) !== $expectedRows) $errors[] = 'cumulative_rows_created differs from completed manifest batches.';
		try { if (self::normalizeAmount(self::value($progress, 'cumulative_amount')) !== $expectedAmount) $errors[] = 'cumulative_amount differs from completed manifest batches.'; }
		catch (InvalidArgumentException $e) { $errors[] = 'cumulative_amount is invalid.'; }
		if (self::value($progress, 'retry_safe') !== true) $errors[] = 'retry_safe must remain true.';
		if (self::value($progress, 'same_manifest_confirmation_required') !== true) $errors[] = 'same_manifest_confirmation_required must remain true.';
		return array('status'=>empty($errors) ? 'pass' : 'fail', 'errors'=>$errors);
	}

	public static function completeBatch($manifest, $progress, $batchNumber, $blockIds, $rowsCreated, $amount, $verification)
	{
		$validation = self::validateProgress($manifest, $progress);
		if (self::value($validation, 'status') !== 'pass') throw new InvalidArgumentException('Existing progress is invalid: '.implode(' ', self::value($validation, 'errors', array())));
		$expectedBatches = self::batches($manifest);
		$expected = self::value($expectedBatches, intval($batchNumber) - 1, array());
		if (self::canonicalJson(array_values($blockIds)) !== self::canonicalJson(array_values($expected))) throw new InvalidArgumentException('Completed batch does not match deterministic manifest batch.');
		$already = array_values(self::value($progress, 'completed_block_ids', array()));
		if (count($already) !== (intval($batchNumber) - 1) * intval(self::value($manifest, 'internal_batch_size'))) throw new InvalidArgumentException('Completed batch is not the first uncompleted manifest batch.');
		$progress['completed_block_ids'] = array_merge($already, array_values($blockIds));
		$progress['remaining_block_ids'] = array_slice(self::value($manifest, 'selected_block_ids', array()), count($progress['completed_block_ids']));
		$progress['completed_batch_count'] = intval($batchNumber);
		$progress['completed_batches'][] = array('batch_number'=>intval($batchNumber), 'block_ids'=>array_values($blockIds), 'rows_created'=>intval($rowsCreated), 'amount'=>self::normalizeAmount($amount), 'verification'=>$verification);
		$progress['cumulative_rows_created'] = intval(self::value($progress, 'cumulative_rows_created', 0)) + intval($rowsCreated);
		$progress['cumulative_amount'] = self::addAmounts(self::value($progress, 'cumulative_amount', self::zeroAmount()), $amount);
		$progress['failure_point'] = null;
		$progress['failure_reason'] = null;
		$progress['retry_safe'] = true;
		$progress['updated_at'] = gmdate('c');
		$progress = self::sealProgress($progress);
		$validation = self::validateProgress($manifest, $progress);
		if (self::value($validation, 'status') !== 'pass') throw new InvalidArgumentException('Completed progress is invalid: '.implode(' ', self::value($validation, 'errors', array())));
		return $progress;
	}

	private static function sealProgress($progress)
	{
		unset($progress['progress_checksum']);
		$progress['progress_checksum'] = self::checksum($progress, 'detects alteration of persisted Stage1 drain progress');
		return $progress;
	}

	public static function checksumValue($checksum)
	{
		return is_array($checksum) ? self::value($checksum, 'value') : $checksum;
	}

	public static function parseSelectionLimit($value)
	{
		$text = (string)$value;
		$maximum = (string)self::MAX_SELECTION_LIMIT;
		if (!preg_match('/^[1-9][0-9]*$/', $text)) throw new InvalidArgumentException('selection_limit must be a canonical positive integer.');
		if (strlen($text) > strlen($maximum) || (strlen($text) === strlen($maximum) && strcmp($text, $maximum) > 0)) throw new InvalidArgumentException('selection_limit exceeds the supported maximum of '.self::MAX_SELECTION_LIMIT.'.');
		return intval($text);
	}

	public static function checksum($value, $purpose)
	{
		return array('algorithm'=>'sha256', 'value'=>hash('sha256', self::canonicalJson($value)), 'purpose'=>$purpose);
	}

	public static function confirmationValue($packageChecksum)
	{
		return 'stage1_manifest_'.strtolower((string)$packageChecksum).'_'.self::CONFIRMATION_SUFFIX;
	}

	public static function normalizeAmount($value)
	{
		$value = trim((string)$value);
		if (!preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?$/', $value, $m)) throw new InvalidArgumentException('Invalid decimal amount.');
		$negative = $m[1] === '-';
		$whole = ltrim($m[2], '0');
		if ($whole === '') $whole = '0';
		$fraction = isset($m[3]) ? $m[3] : '';
		if (strlen($fraction) > self::AMOUNT_SCALE && trim(substr($fraction, self::AMOUNT_SCALE), '0') !== '') throw new InvalidArgumentException('Decimal amount exceeds supported precision.');
		$fraction = substr(str_pad($fraction, self::AMOUNT_SCALE, '0'), 0, self::AMOUNT_SCALE);
		if ($negative && ($whole !== '0' || trim($fraction, '0') !== '')) return '-'.$whole.'.'.$fraction;
		return $whole.'.'.$fraction;
	}

	public static function addAmounts($left, $right)
	{
		$a = self::amountInteger($left);
		$b = self::amountInteger($right);
		$sum = self::signedIntegerAdd($a, $b);
		return self::integerAmount($sum);
	}

	public static function recipientTotalsForEarnings($earnings)
	{
		return self::recipientTotals($earnings);
	}

	private static function recipientTotals($earnings)
	{
		$totals = array();
		foreach ($earnings as $earning) {
			$id = intval(self::value($earning, 'userid'));
			$key = (string)$id;
			if (!isset($totals[$key])) $totals[$key] = self::zeroAmount();
			$totals[$key] = self::addAmounts($totals[$key], self::value($earning, 'amount', '0'));
		}
		ksort($totals, SORT_NUMERIC);
		$result = array();
		foreach ($totals as $id => $amount) $result[] = array('userid'=>intval($id), 'amount'=>$amount, 'attribution_model'=>'block_userid_single_recipient');
		return $result;
	}

	private static function earningTotal($earnings)
	{
		$total = self::zeroAmount();
		foreach ($earnings as $earning) $total = self::addAmounts($total, self::value($earning, 'amount', '0'));
		return $total;
	}

	private static function zeroAmount()
	{
		return '0.'.str_repeat('0', self::AMOUNT_SCALE);
	}

	private static function amountInteger($value)
	{
		$normalized = self::normalizeAmount($value);
		$negative = substr($normalized, 0, 1) === '-';
		if ($negative) $normalized = substr($normalized, 1);
		$integer = ltrim(str_replace('.', '', $normalized), '0');
		if ($integer === '') $integer = '0';
		return $negative && $integer !== '0' ? '-'.$integer : $integer;
	}

	private static function integerAmount($integer)
	{
		$negative = substr($integer, 0, 1) === '-';
		if ($negative) $integer = substr($integer, 1);
		$integer = str_pad($integer, self::AMOUNT_SCALE + 1, '0', STR_PAD_LEFT);
		$whole = substr($integer, 0, -self::AMOUNT_SCALE);
		$fraction = substr($integer, -self::AMOUNT_SCALE);
		return ($negative && trim($integer, '0') !== '' ? '-' : '').ltrim($whole, '0').($whole === str_repeat('0', strlen($whole)) ? '0' : '').'.'.$fraction;
	}

	private static function signedIntegerAdd($left, $right)
	{
		$leftNegative = substr($left, 0, 1) === '-';
		$rightNegative = substr($right, 0, 1) === '-';
		$a = $leftNegative ? substr($left, 1) : $left;
		$b = $rightNegative ? substr($right, 1) : $right;
		if ($leftNegative === $rightNegative) return ($leftNegative ? '-' : '').self::unsignedAdd($a, $b);
		$cmp = self::unsignedCompare($a, $b);
		if ($cmp === 0) return '0';
		if ($cmp > 0) return ($leftNegative ? '-' : '').self::unsignedSubtract($a, $b);
		return ($rightNegative ? '-' : '').self::unsignedSubtract($b, $a);
	}

	private static function unsignedAdd($a, $b)
	{
		$carry = 0; $out = ''; $i = strlen($a) - 1; $j = strlen($b) - 1;
		while ($i >= 0 || $j >= 0 || $carry) { $sum = ($i >= 0 ? intval($a[$i--]) : 0) + ($j >= 0 ? intval($b[$j--]) : 0) + $carry; $out = ($sum % 10).$out; $carry = intval($sum / 10); }
		return ltrim($out, '0') === '' ? '0' : ltrim($out, '0');
	}

	private static function unsignedSubtract($a, $b)
	{
		$borrow = 0; $out = ''; $i = strlen($a) - 1; $j = strlen($b) - 1;
		while ($i >= 0) { $digit = intval($a[$i--]) - $borrow - ($j >= 0 ? intval($b[$j--]) : 0); if ($digit < 0) { $digit += 10; $borrow = 1; } else $borrow = 0; $out = $digit.$out; }
		$out = ltrim($out, '0'); return $out === '' ? '0' : $out;
	}

	private static function unsignedCompare($a, $b)
	{
		$a = ltrim($a, '0'); $b = ltrim($b, '0'); if ($a === '') $a = '0'; if ($b === '') $b = '0';
		if (strlen($a) !== strlen($b)) return strlen($a) > strlen($b) ? 1 : -1;
		return strcmp($a, $b) === 0 ? 0 : (strcmp($a, $b) > 0 ? 1 : -1);
	}

	private static function compareOrder($left, $right)
	{
		for ($i = 0; $i < 3; $i++) if ($left[$i] !== $right[$i]) return $left[$i] > $right[$i] ? 1 : -1;
		return 0;
	}

	private static function orderKey($value)
	{
		if (!is_array($value)) return null;
		$result = array();
		foreach (array('height','time','id') as $field) {
			$item = self::value($value, $field);
			if (!is_int($item)) return null;
			$result[] = $item;
		}
		return $result;
	}

	private static function canonicalJson($value)
	{
		return json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
	}

	private static function canonicalize($value)
	{
		if (!is_array($value)) return $value;
		$result = array();
		foreach ($value as $key => $item) $result[$key] = self::canonicalize($item);
		if (!self::isList($result)) ksort($result);
		return $result;
	}

	private static function isList($value)
	{
		$index = 0;
		foreach (array_keys($value) as $key) if ($key !== $index++) return false;
		return true;
	}

	private static function isAbsoluteJsonPath($value)
	{
		return is_string($value) && preg_match('#^/[^\x00]*\.json$#', $value) === 1;
	}

	private static function jsonPointerValue($document, $pointer)
	{
		if (!is_string($pointer) || substr($pointer, 0, 1) !== '/') throw new InvalidArgumentException('Invalid manifest field_ref.');
		$value = $document;
		foreach (explode('/', substr($pointer, 1)) as $segment) {
			$segment = str_replace(array('~1','~0'), array('/','~'), $segment);
			if (!is_array($value) || !array_key_exists($segment, $value)) throw new InvalidArgumentException('Manifest field_ref does not resolve: '.$pointer.'.');
			$value = $value[$segment];
		}
		if (is_array($value) || is_object($value)) throw new InvalidArgumentException('Manifest field_ref must resolve to a scalar value.');
		return $value;
	}

	private static function value($array, $key, $default=null)
	{
		return is_array($array) && array_key_exists($key, $array) ? $array[$key] : $default;
	}
}
