<?php

class BadpoolGuardReport
{
	const CHECKSUM_ALGORITHM = 'sha256';

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
		$report = self::ensurePayoutAudit($report);
		$report['report_checksum'] = self::checksum($report);
		return $report;
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
