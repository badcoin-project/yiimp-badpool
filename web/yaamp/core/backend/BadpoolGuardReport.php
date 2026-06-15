<?php

class BadpoolGuardReport
{
	public static function render($report, $format)
	{
		if ($format == 'text') {
			self::renderText($report);
			return;
		}

		echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
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
