<?php

class BadpoolGuardAutomationContracts
{
	const CONTRACT_SCHEMA = 'badpool.guardrail.automation_contract.v1';

	public static function hardenReport($report)
	{
		if (!is_array($report)) {
			return $report;
		}

		$command = self::arrayValue($report, 'command');
		$report['automation_contract'] = self::commandContract($command);

		if ($command === 'payout-candidates-preview') {
			$report = self::hardenPayoutCandidatesPreview($report);
		}

		if (!isset($report['closeout_minimum_fields'])) {
			$report['closeout_minimum_fields'] = self::closeoutMinimumFields();
		}

		return $report;
	}

	public static function commandContract($command)
	{
		$contracts = array(
			'payout-candidates-preview' => array(
				'stable_fields' => array('schema', 'command', 'generated_at', 'scope', 'summary.candidate_count', 'summary.candidate_gate', 'summary.candidate_amount_required', 'summary.amount_validation_source', 'report_checksum'),
				'semantics' => array(
					'gate' => 'count_or_existence_only',
					'candidate_amount_required' => false,
					'amount_validation_source' => 'payout-row-approval-package',
				),
				'mutating' => false,
			),
			'payout-row-approval-package' => array(
				'stable_fields' => array('schema', 'command', 'generated_at', 'scope', 'summary.selected_account_count', 'summary.projected_payout_total', 'approval_package_checksum', 'selected_scope_checksum', 'projected_payout_row_checksum', 'projected_account_debit_checksum', 'report_checksum'),
				'selected_id_fields' => array('selected_account_ids'),
				'mutating' => false,
			),
			'payout-row-apply' => array(
				'stable_fields' => array('schema', 'command', 'generated_at', 'scope', 'summary.apply_status', 'summary.selected_account_ids', 'summary.payout_rows_inserted', 'summary.wallet_sends', 'summary.backend_loops_run', 'summary.shares_deleted', 'report_checksum'),
				'selected_id_fields' => array('selected_account_ids'),
				'mutating' => true,
				'requires_operator_confirmation' => true,
			),
			'wallet-send-approval-package' => array(
				'stable_fields' => array('schema', 'command', 'generated_at', 'scope', 'summary.selected_payout_ids', 'summary.selected_payout_count', 'summary.projected_total', 'summary.wallet_send_total', 'approval_package_checksum', 'row_inventory_checksum', 'destination_plan_checksum', 'projected_total_checksum', 'wallet_send_total_checksum', 'wallet_send_destination_plan_checksum', 'report_checksum'),
				'selected_id_fields' => array('selected_payout_ids'),
				'mutating' => false,
			),
			'wallet-send-apply' => array(
				'stable_fields' => array('schema', 'command', 'generated_at', 'scope', 'summary.apply_status', 'summary.selected_payout_ids', 'summary.wallet_rpc_send_performed', 'summary.wallet_send_success', 'summary.db_completion_success', 'summary.completed_payout_ids', 'summary.txid', 'summary.exact_total_sent', 'summary.manual_reconciliation_required', 'report_checksum'),
				'selected_id_fields' => array('selected_payout_ids'),
				'mutating' => true,
				'requires_operator_confirmation' => true,
			),
			'closeout-proof' => array(
				'stable_fields' => self::closeoutMinimumFields(),
				'mutating' => false,
			),
		);

		$contract = array(
			'schema' => self::CONTRACT_SCHEMA,
			'command' => $command,
			'known_contract' => isset($contracts[$command]),
			'closeout_minimum_fields' => self::closeoutMinimumFields(),
		);

		if (isset($contracts[$command])) {
			foreach ($contracts[$command] as $key => $value) {
				$contract[$key] = $value;
			}
		}

		return $contract;
	}

	private static function hardenPayoutCandidatesPreview($report)
	{
		if (!isset($report['summary']) || !is_array($report['summary'])) {
			$report['summary'] = array();
		}

		$report['summary']['candidate_gate'] = 'count_or_existence_only';
		$report['summary']['candidate_amount_required'] = false;
		$report['summary']['amount_validation_source'] = 'payout-row-approval-package';

		if (!array_key_exists('candidate_count', $report['summary'])) {
			$report['summary']['candidate_count'] = null;
		}

		return $report;
	}

	public static function closeoutMinimumFields()
	{
		return array(
			'final_classification',
			'run_dir',
			'mutation_boundary',
			'do_not_rerun',
			'next_safe_lane_or_STOP',
			'fix_items',
		);
	}

	public static function missingCloseoutFields($report)
	{
		$missing = array();
		foreach (self::closeoutMinimumFields() as $field) {
			if (!is_array($report) || !array_key_exists($field, $report)) {
				$missing[] = $field;
			}
		}
		return $missing;
	}

	public static function selectedEarningIds($payload)
	{
		return self::selectedIdsForField($payload, 'selected_earning_ids');
	}

	public static function selectedAccountIds($payload)
	{
		return self::selectedIdsForField($payload, 'selected_account_ids');
	}

	public static function selectedPayoutIds($payload)
	{
		return self::selectedIdsForField($payload, 'selected_payout_ids');
	}

	public static function selectedIdsForField($payload, $field)
	{
		$values = self::findExactFieldValues($payload, $field);
		$out = array();

		foreach ($values as $value) {
			foreach (self::normalizeIdList($value) as $id) {
				if (!in_array($id, $out, true)) {
					$out[] = $id;
				}
			}
		}

		sort($out, SORT_NUMERIC);
		return $out;
	}

	private static function findExactFieldValues($payload, $field)
	{
		$values = array();

		if (!is_array($payload)) {
			return $values;
		}

		foreach ($payload as $key => $value) {
			if ((string)$key === $field) {
				$values[] = $value;
				continue;
			}
			if (is_array($value)) {
				$values = array_merge($values, self::findExactFieldValues($value, $field));
			}
		}

		return $values;
	}

	private static function normalizeIdList($value)
	{
		$out = array();

		if (is_array($value)) {
			foreach ($value as $item) {
				$out = array_merge($out, self::normalizeIdList($item));
			}
			return $out;
		}

		if (is_string($value) && strpos($value, ',') !== false) {
			foreach (explode(',', $value) as $part) {
				$out = array_merge($out, self::normalizeIdList(trim($part)));
			}
			return $out;
		}

		if (is_int($value) || (is_string($value) && preg_match('/^[0-9]+$/', $value))) {
			$out[] = intval($value);
		}

		return $out;
	}

	public static function compareSnapshotLabels($before, $after, $allowlist=array())
	{
		$beforeLabels = self::snapshotLabels($before);
		$afterLabels = self::snapshotLabels($after);

		$missingAfter = array_values(array_diff($beforeLabels, $afterLabels));
		$extraAfter = array_values(array_diff($afterLabels, $beforeLabels));

		$unexpectedMissing = array_values(array_diff($missingAfter, $allowlist));
		$unexpectedExtra = array_values(array_diff($extraAfter, $allowlist));

		return array(
			'ok' => count($unexpectedMissing) === 0 && count($unexpectedExtra) === 0,
			'before_labels' => $beforeLabels,
			'after_labels' => $afterLabels,
			'missing_after' => $missingAfter,
			'extra_after' => $extraAfter,
			'allowlist' => array_values($allowlist),
			'unexpected_missing_after' => $unexpectedMissing,
			'unexpected_extra_after' => $unexpectedExtra,
		);
	}

	private static function snapshotLabels($snapshot)
	{
		if (!is_array($snapshot)) {
			return array();
		}

		$labels = array();

		foreach ($snapshot as $key => $value) {
			if (is_int($key) && is_array($value)) {
				if (isset($value['label'])) {
					$labels[] = (string)$value['label'];
				}
				elseif (isset($value[0])) {
					$labels[] = (string)$value[0];
				}
			}
			else {
				$labels[] = (string)$key;
			}
		}

		sort($labels, SORT_STRING);
		return $labels;
	}

	public static function parseHelpDiscovery($output, $rc)
	{
		$output = (string)$output;
		$commands = array();

		if (preg_match_all('/badpoolguard[[:space:]]+([a-z0-9-]+)/', $output, $matches)) {
			foreach ($matches[1] as $command) {
				if ($command !== 'help' && !in_array($command, $commands, true)) {
					$commands[] = $command;
				}
			}
		}

		sort($commands, SORT_STRING);

		return array(
			'rc' => intval($rc),
			'has_useful_output' => strpos($output, 'badpoolguard') !== false && strpos($output, 'Usage:') !== false,
			'usable_for_discovery' => count($commands) > 0 || (strpos($output, 'badpoolguard') !== false && strpos($output, 'Usage:') !== false),
			'commands' => $commands,
			'rc_note' => intval($rc) === 0 ? 'success' : 'nonzero_rc_does_not_alone_mean_unavailable',
		);
	}

	private static function arrayValue($array, $key, $default=null)
	{
		return is_array($array) && array_key_exists($key, $array) ? $array[$key] : $default;
	}
}
