<?php

$cases = array(
	array(32, '206a0000', '1fffe000', '1110e000', '317ae000', '3110e000'),
	array(33, '206a0000', '1fffe000', '0b91c000', '2bfbc000', '2b91c000'),
	array(34, '206a0000', '1fffe000', '08952000', '28ff2000', '28952000'),
	array(35, '206a0000', '1fffe000', '08906000', '28fa6000', '28906000'),
	array(36, '206a0000', '1fffe000', '0f104000', '2f7a4000', '2f104000'),
	array(37, '206a0000', '1fffe000', '1090c000', '30fac000', '3090c000'),
	array(38, '206a0000', '1fffe000', '08942000', '28fe2000', '28942000'),
);

$failures = array();

function hex_u32($value)
{
	return intval(hexdec($value));
}

function fmt_u32($value)
{
	return sprintf('%08x', $value & 0xffffffff);
}

foreach ($cases as $case) {
	list($submitId, $templateVersionHex, $versionMaskHex, $versionBitsHex, $expectedEffectiveHex, $oldEffectiveHex) = $case;
	$templateVersion = hex_u32($templateVersionHex);
	$versionMask = hex_u32($versionMaskHex);
	$versionBits = hex_u32($versionBitsHex);

	$effective = fmt_u32($templateVersion | ($versionBits & $versionMask));
	$oldEffective = fmt_u32(($templateVersion & (~$versionMask)) | ($versionBits & $versionMask));

	if ($effective !== $expectedEffectiveHex) {
		$failures[] = "submit_id $submitId: effective version $effective != expected $expectedEffectiveHex";
	}
	if ($oldEffective !== $oldEffectiveHex) {
		$failures[] = "submit_id $submitId: replay old effective $oldEffective != expected old $oldEffectiveHex";
	}
	if ($effective === $oldEffective) {
		$failures[] = "submit_id $submitId: new calculation unexpectedly equals old replacement-style result";
	}
}

$templateVersion = hex_u32('206a0000');
$versionMask = hex_u32('1fffe000');
$versionBitsInsideOnly = hex_u32('08942000');
$versionBitsWithOutsideBits = $versionBitsInsideOnly | hex_u32('c0001fff');
$insideEffective = fmt_u32($templateVersion | ($versionBitsInsideOnly & $versionMask));
$outsideEffective = fmt_u32($templateVersion | ($versionBitsWithOutsideBits & $versionMask));

if ($outsideEffective !== $insideEffective) {
	$failures[] = "outside-mask guard: $outsideEffective != inside-only $insideEffective";
}

if (!empty($failures)) {
	echo "SHA256D version rolling replay FAILED\n";
	foreach ($failures as $failure) {
		echo " - $failure\n";
	}
	exit(1);
}

echo "SHA256D version rolling replay passed\n";
