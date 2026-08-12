<?php
require_once(dirname(__FILE__).'/BadpoolGuardAutomationContracts.php');
require_once(dirname(__FILE__).'/BadpoolStage1Manifest.php');

class BadpoolGuardReport
{
	const CHECKSUM_ALGORITHM = 'sha256';
	const APPLY_SCHEMA = 'badpool.guardrail.apply.v1';
	const APPLY_MODE = 'guarded-apply';
	const APPROVAL_PACKAGE_SCHEMA = 'badpool.approval_package.v1';
	const BOUNDED_MATURITY_APPROVAL_PACKAGE_SCHEMA = 'badpool.approval_package.v2';
	const APPROVAL_PACKAGE_MODE = 'read-only-approval-package';
	const APPROVAL_CHECKSUM_PURPOSE = 'immutable approval binding; authorization requires fresh recomputation and exact match';
	const PREVIEW_SCHEMA = 'badpool.guardrail.preview.v1';
	const PREVIEW_MODE = 'read-only-preview';

	public static function render($report, $format)
	{
		$report = self::finalize($report);

		if ($format == 'text') {
			self::renderText($report);
			return;
		}

		echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
	}

	public static function finalize($report)
	{
		$report = self::normalizeOperatorSafety($report);
		$report = self::ensurePayoutAudit($report);
		$report = self::ensureAutomationContract($report);
		$report['report_checksum'] = self::checksum($report);
		return $report;
	}

	private static function normalizeOperatorSafety($report)
	{
		if (!is_array($report)) {
			return $report;
		}

		$command = (string)self::arrayValue($report, 'command', '');
		$isApply = preg_match('/-apply$/', $command) === 1;
		$isPreview = self::isPreviewCommand($command);
		if (!$isApply && !$isPreview) {
			return $report;
		}

		$originalDbMutations = self::arrayValue($report, 'db_mutations', false);
		if (!is_bool($originalDbMutations) && !isset($report['db_mutation_status'])) {
			$report['db_mutation_status'] = $originalDbMutations;
		}

		if ($isPreview) {
			if (self::isCanonicalApprovalPackage($report)) {
				if (self::arrayValue($report, 'schema') !== self::BOUNDED_MATURITY_APPROVAL_PACKAGE_SCHEMA) $report['schema'] = self::APPROVAL_PACKAGE_SCHEMA;
				$report['mode'] = self::APPROVAL_PACKAGE_MODE;
			}
			else {
				if (!self::isCanonicalStage1Manifest($report)) $report['schema'] = self::PREVIEW_SCHEMA;
				$report['mode'] = self::PREVIEW_MODE;
			}
			$report['read_only'] = true;
			$report['db_mutations'] = false;
			$report['wallet_rpc_send_performed'] = false;
			return $report;
		}

		$report['schema'] = self::APPLY_SCHEMA;
		$report['mode'] = self::APPLY_MODE;
		$report['read_only'] = false;
		$report['db_mutations'] = self::mutationBoolean($originalDbMutations);
		$report['wallet_rpc_send_performed'] = self::booleanValue(
			self::arrayValue($report, 'wallet_rpc_send_performed', false)
		);
		if (!isset($report['db_mutation_status'])) {
			$report['db_mutation_status'] = $report['db_mutations'] ? 'performed' : 'none';
		}
		return $report;
	}

	private static function isCanonicalStage1Manifest($report)
	{
		return self::arrayValue($report, 'schema') === BadpoolStage1Manifest::SCHEMA
			&& self::arrayValue($report, 'package_type') === BadpoolStage1Manifest::PACKAGE_TYPE
			&& self::arrayValue($report, 'command') === BadpoolStage1Manifest::COMMAND;
	}

	private static function isCanonicalApprovalPackage($report)
	{
		if (!in_array(self::arrayValue($report, 'schema'), array(self::APPROVAL_PACKAGE_SCHEMA, self::BOUNDED_MATURITY_APPROVAL_PACKAGE_SCHEMA), true)) return false;
		if (self::arrayValue($report, 'schema') === self::BOUNDED_MATURITY_APPROVAL_PACKAGE_SCHEMA && !self::isCompleteBoundedMaturityPackage($report)) return false;
		$packageType = (string)self::arrayValue($report, 'package_type', '');
		if ($packageType === '' || self::arrayValue($report, 'approval_package_type') !== $packageType) return false;
		if (self::arrayValue($report, 'approval_required') !== true) return false;
		$commands = array(
			'forward-catchup-stage1-apply' => 'forward-catchup-stage1-apply-approval-package',
			'earnings-maturity-transition' => 'earnings-maturity-transition-approval-package',
			'account-credit-clear' => 'account-credit-clear-approval-package',
			'payout-row-creation' => 'payout-row-approval-package',
			'wallet-send' => 'wallet-send-approval-package',
		);
		return isset($commands[$packageType]) && self::arrayValue($report, 'command') === $commands[$packageType];
	}

	public static function isCompleteBoundedMaturityPackage($report)
	{
		if (!is_array($report) || self::arrayValue($report, 'schema') !== self::BOUNDED_MATURITY_APPROVAL_PACKAGE_SCHEMA) return false;
		if (self::arrayValue($report, 'package_type') !== 'earnings-maturity-transition' || self::arrayValue($report, 'approval_package_type') !== 'earnings-maturity-transition') return false;
		if (intval(self::arrayValue($report, 'approval_package_version')) !== 2 || self::arrayValue($report, 'selection_mode') !== 'exact-blocks') return false;
		$requested = self::arrayValue($report, 'requested_block_ids');
		$earnings = self::arrayValue($report, 'selected_earning_ids');
		$linked = self::arrayValue($report, 'selected_linked_block_ids');
		$without = self::arrayValue($report, 'requested_blocks_without_selected_earnings');
		if (!self::canonicalPositiveIds($requested, false) || !self::uniquePositiveIds($earnings, true) || !self::canonicalPositiveIds($linked, true) || !self::canonicalPositiveIds($without, true)) return false;
		if (intval(self::arrayValue($report, 'requested_block_count', -1)) !== count($requested) || intval(self::arrayValue($report, 'selected_earning_count', -1)) !== count($earnings) || intval(self::arrayValue($report, 'selected_linked_block_count', -1)) !== count($linked)) return false;
		if (array_values(array_intersect($linked, $without)) || array_values(array_diff(array_merge($linked, $without), $requested))) return false;
		foreach (array('scope_checksum','selected_scope_checksum','projected_block_mutation_checksum','projected_earnings_mutation_checksum','approval_package_checksum') as $field) {
			$value = self::arrayValue($report, $field);
			if (!is_array($value) || !preg_match('/^[a-f0-9]{64}$/', (string)self::arrayValue($value, 'value'))) return false;
		}
		return true;
	}

	private static function canonicalPositiveIds($ids, $emptyAllowed)
	{
		if (!is_array($ids) || (!$emptyAllowed && !$ids)) return false;
		$previous = 0;
		foreach ($ids as $id) {
			if (!is_int($id) || $id <= $previous) return false;
			$previous = $id;
		}
		return true;
	}

	private static function uniquePositiveIds($ids, $emptyAllowed)
	{
		if (!is_array($ids) || (!$emptyAllowed && !$ids)) return false;
		$seen = array();
		foreach ($ids as $id) {
			if (!is_int($id) || $id <= 0 || isset($seen[$id])) return false;
			$seen[$id] = true;
		}
		return true;
	}

	private static function isPreviewCommand($command)
	{
		foreach (array('preview', 'dryrun', 'plan', 'approval-package') as $marker) {
			if (strpos($command, $marker) !== false) {
				return true;
			}
		}
		return false;
	}

	private static function mutationBoolean($value)
	{
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value) || is_float($value)) {
			return $value != 0;
		}
		if (!is_string($value)) {
			return false;
		}
		return in_array(strtolower(trim($value)), array(
			'true',
			'1',
			'performed',
			'committed',
			'guarded_transaction_committed',
			'guarded_transaction_committed_partial_drain',
		), true);
	}

	private static function booleanValue($value)
	{
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value) || is_float($value)) {
			return $value != 0;
		}
		if (!is_string($value)) {
			return false;
		}
		return in_array(strtolower(trim($value)), array('true', '1', 'yes', 'performed', 'sent'), true);
	}

	public static function classifyMaturityApplyResult($commandRc, $report, $authorization)
	{
		$failed = array();
		if (self::canonicalCount($commandRc) !== '0') $failed[] = 'command_rc';
		if (!is_array($report)) $failed[] = 'report';
		if (!is_array($authorization)) $failed[] = 'authorization';

		if (is_array($report)) {
			$exact = array(
				'schema'=>self::APPLY_SCHEMA,
				'mode'=>self::APPLY_MODE,
				'command'=>'earnings-maturity-transition-apply',
				'status'=>'pass',
				'db_mutation_status'=>'guarded_transaction_committed',
			);
			foreach ($exact as $field=>$expected) if (self::arrayValue($report, $field) !== $expected) $failed[] = $field;
			$flags = array(
				'read_only'=>false,
				'db_mutations'=>true,
				'no_account_credit'=>true,
				'no_payout_rows'=>true,
				'payout_rows_created'=>false,
				'wallet_sends'=>false,
				'wallet_rpc_send_performed'=>false,
				'backend_loops_run'=>false,
				'shares_deleted'=>false,
			);
			foreach ($flags as $field=>$expected) {
				if (!array_key_exists($field, $report) || $report[$field] !== $expected) $failed[] = $field;
			}
			if (!array_key_exists('errors', $report) || !is_array($report['errors']) || count($report['errors']) !== 0) $failed[] = 'errors';
		}

		$countBindings = array(
			'selected_count'=>'selected_count',
			'applied_block_count'=>'block_count',
			'applied_earning_count'=>'earning_count',
		);
		if (is_array($report) && is_array($authorization)) {
			foreach ($countBindings as $reportField=>$authorizationField) {
				$actual = self::canonicalCount(self::arrayValue($report, $reportField));
				$expected = self::canonicalCount(self::arrayValue($authorization, $authorizationField));
				if ($actual === null || $expected === null || $actual !== $expected) $failed[] = $reportField;
			}
		}

		$projected = is_array($report) ? self::coinAmountAtPrecision(self::arrayValue($report, 'projected_amount_total')) : null;
		$applied = is_array($report) ? self::coinAmountAtPrecision(self::arrayValue($report, 'applied_amount_total')) : null;
		$authorized = is_array($authorization) ? self::coinAmountAtPrecision(self::arrayValue($authorization, 'amount_total')) : null;
		if ($projected === null || $applied === null || $projected !== $applied) $failed[] = 'projected_amount_total';
		if ($authorized === null || $applied === null || $authorized !== $applied) $failed[] = 'authorized_amount_total';

		$failed = array_values(array_unique($failed));
		return array(
			'classification'=>empty($failed) ? 'pass' : 'hold',
			'reason'=>empty($failed) ? null : 'apply_result_contract_failed',
			'failed_checks'=>$failed,
			'coin_precision'=>8,
			'authorized_amount_at_coin_precision'=>$authorized,
			'projected_amount_at_coin_precision'=>$projected,
			'applied_amount_at_coin_precision'=>$applied,
		);
	}

	private static function canonicalCount($value)
	{
		if (is_int($value)) return $value >= 0 ? (string)$value : null;
		if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/', $value)) return null;
		return $value;
	}

	private static function coinAmountAtPrecision($value)
	{
		$value = is_int($value) ? (string)$value : (is_string($value) ? trim($value) : null);
		if ($value === null || !preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?$/', $value, $matches)) return null;
		$negative = $matches[1] === '-';
		$whole = ltrim($matches[2], '0');
		if ($whole === '') $whole = '0';
		$fraction = isset($matches[3]) ? $matches[3] : '';
		$scale = 8;
		$kept = substr(str_pad($fraction, $scale, '0'), 0, $scale);
		if (strlen($fraction) > $scale && intval($fraction[$scale]) >= 5) {
			$digits = $whole.$kept;
			$carry = 1;
			for ($i = strlen($digits) - 1; $i >= 0 && $carry; $i--) {
				$digit = intval($digits[$i]) + $carry;
				$digits[$i] = (string)($digit % 10);
				$carry = $digit >= 10 ? 1 : 0;
			}
			if ($carry) $digits = '1'.$digits;
			$digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
			$whole = substr($digits, 0, -$scale);
			$kept = substr($digits, -$scale);
		}
		$isZero = $whole === '0' && trim($kept, '0') === '';
		return ($negative && !$isZero ? '-' : '').$whole.'.'.$kept;
	}

	public static function checksum($report)
	{
		$canonical = self::canonicalizeForChecksum($report);
		return array(
			'algorithm' => self::CHECKSUM_ALGORITHM,
			'value' => hash(self::CHECKSUM_ALGORITHM, json_encode($canonical, JSON_UNESCAPED_SLASHES)),
			'excludes' => array(
				'generated_at',
				'report_checksum',
			),
			'purpose' => 'preview audit comparison only; not payout authorization',
		);
	}

	public static function approvalChecksum($report)
	{
		$checksum = self::checksum($report);
		$checksum['purpose'] = self::APPROVAL_CHECKSUM_PURPOSE;
		return $checksum;
	}

	public static function markApprovalChecksum($checksum)
	{
		if (!is_array($checksum) || !array_key_exists('value', $checksum)) return $checksum;
		if (!isset($checksum['algorithm'])) $checksum['algorithm'] = self::CHECKSUM_ALGORITHM;
		$checksum['purpose'] = self::APPROVAL_CHECKSUM_PURPOSE;
		return $checksum;
	}

	private static function ensurePayoutAudit($report)
	{
		if (!is_array($report) || !self::isPayoutAuditCommand(self::arrayValue($report, 'command'))) {
			return $report;
		}
		if (!isset($report['summary']) || !is_array($report['summary'])) {
			$report['summary'] = array();
		}
		if (isset($report['summary']['audit']) && is_array($report['summary']['audit'])) {
			if (!isset($report['summary']['audit']['checksum_purpose'])) {
				$report['summary']['audit']['checksum_purpose'] = 'preview audit comparison only; not payout authorization';
			}
			return $report;
		}

		$scope = self::arrayValue($report, 'scope', array());
		$coin = is_array($scope) ? self::arrayValue($scope, 'coin', array()) : array();
		$executionBlocked = self::arrayValue($report['summary'], 'execution_blocked', array());
		$report['summary']['audit'] = array(
			'command' => self::arrayValue($report, 'command'),
			'coin_id' => is_array($scope) ? self::arrayValue($scope, 'coin_id') : null,
			'coin_symbol' => is_array($coin) ? self::arrayValue($coin, 'symbol') : null,
			'coin_algo' => is_array($coin) ? self::arrayValue($coin, 'algo') : null,
			'candidate_count' => self::arrayValue($report['summary'], 'candidate_count', 0),
			'projected_total_payout_amount' => self::arrayValue($report['summary'], 'projected_total_payout_amount', 0),
			'blocked_actions' => is_array($executionBlocked) ? self::arrayValue($executionBlocked, 'blocked_actions', array()) : array(),
			'checksum_note' => 'See top-level report_checksum; generated_at is excluded from checksum input.',
			'checksum_purpose' => 'preview audit comparison only; not payout authorization',
		);

		return $report;
	}

	private static function isPayoutAuditCommand($command)
	{
		return in_array($command, array('payout-candidates-preview', 'payout-row-preflight-preview', 'payout-row-dryrun-plan', 'payable-source-reconciliation-preview', 'account-credit-transition-preview', 'earnings-credit-readiness-preview', 'block-category-maturity-preview', 'earnings-block-reconciliation-preview', 'maturity-source-verification-preview', 'forward-catchup-preview', 'forward-catchup-approval-package'), true);
	}

	private static function ensureAutomationContract($report)
	{
		if (!is_array($report)) {
			return $report;
		}
		$command = self::arrayValue($report, 'command');
		if (!BadpoolGuardAutomationContracts::shouldAttachAutomationContract($command, $report)) {
			return $report;
		}
		if (!isset($report['automation_contract']) || !is_array($report['automation_contract'])) {
			$report['automation_contract'] = array(
				'command' => $command,
				'closeout_minimum_fields' => array('classification', 'run_dir', 'mutation_boundary', 'next_lane', 'do_not_rerun', 'fix_items'),
				'closeout_validation' => BadpoolGuardAutomationContracts::validateCloseout($report),
			);
		}
		return $report;
	}

	private static function canonicalizeForChecksum($value, $keyName=null)
	{
		if ($keyName === 'generated_at' || $keyName === 'report_checksum') {
			return null;
		}
		if (!is_array($value)) {
			return $value;
		}

		$result = array();
		foreach ($value as $key => $item) {
			if ($key === 'generated_at' || $key === 'report_checksum') {
				continue;
			}
			$result[$key] = self::canonicalizeForChecksum($item, $key);
		}

		if (!self::isList($result)) {
			ksort($result);
		}

		return $result;
	}

	private static function isList($value)
	{
		if (!is_array($value)) {
			return false;
		}

		$index = 0;
		foreach (array_keys($value) as $key) {
			if ($key !== $index) {
				return false;
			}
			$index++;
		}
		return true;
	}

	private static function arrayValue($array, $key, $default=null)
	{
		return is_array($array) && array_key_exists($key, $array) ? $array[$key] : $default;
	}

	private static function renderText($value, $indent=0)
	{
		$prefix = str_repeat('  ', $indent);
		if (!is_array($value)) {
			echo $prefix.$value."\n";
			return;
		}

		foreach ($value as $key => $item) {
			if (is_array($item)) {
				echo $prefix.$key.":\n";
				self::renderText($item, $indent + 1);
			} else {
				echo $prefix.$key.": ".$item."\n";
			}
		}
	}
}
