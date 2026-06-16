<?php

class BadpoolGuardReport
{
	const CHECKSUM_ALGORITHM = 'sha256';

	public static function render($report, $format)
	{
		if ($format == 'text') {
			self::renderText($report);
			return;
		}

		echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
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
