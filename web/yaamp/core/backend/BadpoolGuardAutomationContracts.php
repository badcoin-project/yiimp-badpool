<?php

class BadpoolGuardAutomationContracts
{
	private static $contractCommands = array(
		'payout-row-apply',
		'wallet-send-apply',
		'forward-catchup-stage1-apply',
		'forward-catchup-stage1-drain-apply',
		'earnings-maturity-transition-apply',
		'account-credit-clear-apply',
	);

	private static $supportedCommands = array(
		'overview','blocks-preview','earnings-preview','account-credit-preview','payout-candidates-preview',
		'payout-row-preflight-preview','payout-row-dryrun-plan','payout-row-approval-package','payout-row-apply',
		'wallet-send-dryrun','wallet-send-approval-package','wallet-send-apply','payable-source-reconciliation-preview',
		'account-credit-transition-preview','earnings-credit-readiness-preview','block-category-maturity-preview',
		'earnings-block-reconciliation-preview','maturity-source-verification-preview','forward-catchup-preview',
		'forward-catchup-approval-package','forward-catchup-stage1-apply-dryrun','forward-catchup-stage1-apply-approval-package',
		'forward-catchup-stage1-apply','forward-catchup-stage1-drain-plan','forward-catchup-stage1-drain-apply',
		'earnings-maturity-transition-dryrun','earnings-maturity-transition-approval-package','earnings-maturity-transition-apply',
		'account-credit-clear-dryrun','account-credit-clear-approval-package','account-credit-clear-apply','safety-scan','guard-context','status-runner'
	);

	public static function parseSelectedIds($value, &$error=null)
	{
		$error = null;
		$ids = array();
		if (is_string($value)) {
			if ($value === '' || !preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/', $value)) {
				$error = 'invalid_selected_id_csv';
				return null;
			}
			foreach (explode(',', $value) as $part) $ids[] = intval($part);
			return $ids;
		}
		if (!is_array($value) || !self::isList($value)) {
			$error = 'invalid_selected_id_structure';
			return null;
		}
		foreach ($value as $item) {
			if (!is_int($item) || $item <= 0) {
				$error = 'invalid_selected_id_value';
				return null;
			}
			$ids[] = $item;
		}
		return $ids;
	}

	public static function compareSnapshotLabels($expected, $actual, $allowlist=array())
	{
		$errors = array();
		$expectedCounts = self::snapshotLabelCounts($expected, 'expected', $errors);
		$actualCounts = self::snapshotLabelCounts($actual, 'actual', $errors);
		$allowedMissing = self::allowlistLabels($allowlist, 'missing');
		$allowedExtra = self::allowlistLabels($allowlist, 'extra');
		if (!empty($errors)) return array('status'=>'error', 'error'=>'snapshot_label_parse_error', 'errors'=>$errors);
		foreach ($expectedCounts as $label => $count) {
			$actualCount = isset($actualCounts[$label]) ? $actualCounts[$label] : 0;
			if ($actualCount !== $count && !in_array($label, $allowedMissing, true)) $errors[] = 'missing_or_cardinality_mismatch:'.$label;
		}
		foreach ($actualCounts as $label => $count) {
			$expectedCount = isset($expectedCounts[$label]) ? $expectedCounts[$label] : 0;
			if ($expectedCount !== $count && !in_array($label, $allowedExtra, true)) $errors[] = 'extra_or_cardinality_mismatch:'.$label;
		}
		return array('status'=>empty($errors) ? 'pass' : 'error', 'error'=>empty($errors) ? null : 'snapshot_label_invariant_error', 'errors'=>$errors);
	}

	public static function discoverHelp($rc, $stdout, $stderr)
	{
		$output = trim((string)$stdout."\n".(string)$stderr);
		if ($output === '') return array('usable'=>false, 'error'=>'empty_help_output', 'commands'=>array());
		if (!preg_match('/(^|\n)\s*(Usage:|Yiimp badpoolguard command|Commands?:)/i', $output)) return array('usable'=>false, 'error'=>'unstructured_help_output', 'commands'=>array());
		preg_match_all('/badpoolguard\s+([a-z0-9-]+)(?=\s|$)/', $output, $matches);
		$commands = array_values(array_unique($matches[1]));
		$commands = array_values(array_filter($commands, function($cmd) { return $cmd !== 'command'; }));
		foreach ($commands as $cmd) if (!in_array($cmd, self::$supportedCommands, true)) return array('usable'=>false, 'error'=>'unsupported_help_command', 'commands'=>$commands);
		if (empty($commands)) return array('usable'=>false, 'error'=>'no_help_commands', 'commands'=>array());
		return array('usable'=>true, 'error'=>null, 'commands'=>$commands, 'rc'=>$rc);
	}

	public static function validateCloseout($report)
	{
		$requiredStrings = array('classification','run_dir','mutation_boundary','next_lane');
		$requiredArrays = array('do_not_rerun','fix_items');
		$missing = array(); $invalid = array();
		foreach (array_merge($requiredStrings, $requiredArrays) as $field) if (!is_array($report) || !array_key_exists($field, $report)) $missing[] = $field;
		foreach ($requiredStrings as $field) if (is_array($report) && array_key_exists($field, $report) && (!is_string($report[$field]) || trim($report[$field]) === '')) $invalid[] = $field;
		foreach ($requiredArrays as $field) if (is_array($report) && array_key_exists($field, $report) && !is_array($report[$field])) $invalid[] = $field;
		return array('closeout_valid'=>empty($missing) && empty($invalid), 'missing_closeout_fields'=>$missing, 'invalid_closeout_fields'=>$invalid);
	}

	public static function shouldAttachAutomationContract($command, $report)
	{
		return is_string($command) && in_array($command, self::$contractCommands, true) && is_array($report) && self::validateCloseout($report)['closeout_valid'];
	}

	public static function accountsUseridScanSource($path)
	{
		if (!is_string($path) || $path === '' || !is_readable($path)) return array('status'=>'error', 'error'=>'required_source_file_unreadable');
		$contents = file_get_contents($path);
		if ($contents === false) return array('status'=>'error', 'error'=>'required_source_file_unreadable');
		return array('status'=>'pass', 'uses_accounts_userid'=>strpos($contents, 'accounts.userid') !== false);
	}

	private static function snapshotLabelCounts($entries, $side, &$errors)
	{
		$counts = array();
		if (!is_array($entries)) { $errors[] = $side.':snapshot_not_array'; return $counts; }
		foreach ($entries as $idx => $entry) {
			if (!is_array($entry) || !array_key_exists('label', $entry) || !is_string($entry['label']) || trim($entry['label']) === '') { $errors[] = $side.':invalid_label:'.$idx; continue; }
			$label = $entry['label'];
			$counts[$label] = isset($counts[$label]) ? $counts[$label] + 1 : 1;
			if ($counts[$label] > 1) $errors[] = $side.':duplicate_label:'.$label;
		}
		return $counts;
	}

	private static function allowlistLabels($allowlist, $key)
	{
		if (!is_array($allowlist) || !isset($allowlist[$key]) || !is_array($allowlist[$key])) return array();
		$labels = array();
		foreach ($allowlist[$key] as $label) if (is_string($label) && preg_match('/^[a-z0-9_.-]+$/i', $label)) $labels[] = $label;
		return $labels;
	}

	private static function isList($value)
	{
		$i = 0;
		foreach (array_keys($value) as $key) { if ($key !== $i) return false; $i++; }
		return true;
	}
}
