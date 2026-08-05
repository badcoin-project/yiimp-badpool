<?php
require_once(dirname(__FILE__).'/BadpoolGuardAutomationContracts.php');
require_once(dirname(__FILE__).'/BadpoolStage1Manifest.php');

class BadpoolGuardReport
{
	const CHECKSUM_ALGORITHM = 'sha256';
	const APPLY_SCHEMA = 'badpool.guardrail.apply.v1';
	const APPLY_MODE = 'guarded-apply';
	const APPROVAL_PACKAGE_SCHEMA = 'badpool.approval_package.v1';
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
				$report['schema'] = self::APPROVAL_PACKAGE_SCHEMA;
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
		if (self::arrayValue($report, 'schema') !== self::APPROVAL_PACKAGE_SCHEMA) return false;
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
