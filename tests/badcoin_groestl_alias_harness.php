<?php
$sourcePath = __DIR__ . '/../stratum/stratum.cpp';
$source = file_get_contents($sourcePath);

if ($source === false) {
    fwrite(STDERR, "Badcoin Groestl alias harness FAILED\n - unable to read stratum/stratum.cpp\n");
    exit(1);
}

$failures = array();
$expected = array(
    'badcoin-groestl' => 'groestlmyriad_hash',
    'groestl' => 'groestl_hash',
    'skein' => 'skein_hash',
);

foreach ($expected as $algorithm => $hashFunction) {
    $pattern = '/^\s*\{"' . preg_quote($algorithm, '/') . '"\s*,\s*([A-Za-z_][A-Za-z0-9_]*)\s*,/m';
    $matches = array();
    $count = preg_match_all($pattern, $source, $matches);

    if ($count !== 1) {
        $failures[] = sprintf('%s must appear exactly once in stratum hash dispatch', $algorithm);
        continue;
    }

    if ($matches[1][0] !== $hashFunction) {
        $failures[] = sprintf('%s must map to %s (found %s)', $algorithm, $hashFunction, $matches[1][0]);
    }
}

if ($failures) {
    echo "Badcoin Groestl alias harness FAILED\n";
    foreach ($failures as $failure) echo " - $failure\n";
    exit(1);
}

echo "Badcoin Groestl alias harness PASSED\n";
