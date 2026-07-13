<?php
$failures = array();
function expect_true($name, $value, &$failures) { if (!$value) $failures[] = $name; }
function expect_contains($name, $text, $needle, &$failures) { expect_true($name, strpos($text, $needle) !== false, $failures); }

function compact_target_hex($nbits)
{
    if (!is_string($nbits) || strlen($nbits) !== 8 || !ctype_xdigit($nbits)) return null;
    $compact = hexdec($nbits);
    $size = ($compact >> 24) & 0xff;
    $word = $compact & 0x007fffff;
    if ($size <= 0 || $size > 32 || $word === 0) return null;
    if ($size <= 3) {
        $word >>= 8 * (3 - $size);
        $payload = str_pad(dechex($word), $size * 2, '0', STR_PAD_LEFT);
    } else {
        $payload = str_pad(dechex($word), 6, '0', STR_PAD_LEFT) . str_repeat('00', $size - 3);
    }
    return str_pad(strtolower($payload), 64, '0', STR_PAD_LEFT);
}
function hash_meets_target($hash, $target)
{
    if (!is_string($hash) || !is_string($target) || strlen($hash) !== 64 || strlen($target) !== 64) return false;
    return strcmp(strtolower($hash), strtolower($target)) <= 0;
}

$target = compact_target_hex('1d00ffff');
$expected = '00000000ffff0000000000000000000000000000000000000000000000000000';
expect_true('target hex emitted correctly', $target === $expected, $failures);
expect_true('hash equal target', hash_meets_target($expected, $target), $failures);
expect_true('hash below target', hash_meets_target('00000000fffeffffffffffffffffffffffffffffffffffffffffffffffffffff', $target), $failures);
expect_true('hash above target', !hash_meets_target('0000000100000000000000000000000000000000000000000000000000000000', $target), $failures);
expect_true('leading zero handling', hash_meets_target(str_repeat('0', 64), $target), $failures);
expect_true('uppercase hash input', hash_meets_target(strtoupper($expected), $target), $failures);
expect_true('invalid compact target length', compact_target_hex('1d00fff') === null, $failures);
expect_true('invalid compact target zero mantissa', compact_target_hex('1d000000') === null, $failures);
expect_true('invalid compact target exponent', compact_target_hex('2100ffff') === null, $failures);

$client = file_get_contents(__DIR__ . '/../stratum/client_submit.cpp');
$coind = file_get_contents(__DIR__ . '/../stratum/coind_submit.cpp');
$coindHeader = file_get_contents(__DIR__ . '/../stratum/coind.h');
$stratum = file_get_contents(__DIR__ . '/../stratum/stratum.cpp');
foreach (array(
    'STRATUM_BLOCK_TARGET_DIAG',
    'STRATUM_BLOCK_CANDIDATE_DETECTED',
    'STRATUM_COIND_SUBMIT_BEGIN',
    'STRATUM_COIND_SUBMIT_RETURNED',
    'STRATUM_BLOCK_ADD_BEGIN',
    'STRATUM_BLOCK_ADD_RETURNED',
    'STRATUM_BLOCK_PATH_SUMMARY'
) as $marker) expect_contains('marker ' . $marker, $client, $marker, $failures);
foreach (array('schema=%s','trace_id=%s','algo=%s','coin_id=%d','height=%d','jobid=%x','userid=%d','workerid=%d') as $field)
    expect_contains('common field ' . $field, $client, $field, $failures);
expect_contains('authoritative target gate preserved', $client, 'hash_meets_target_from_nbits(submitvalues->hash_be, templ->nbits, block_target_hex)', $failures);
expect_contains('target compare labeled', $client, 'target_compare=nbits_256', $failures);
expect_contains('legacy hash labeled', $client, 'hash_int_legacy=', $failures);
expect_contains('legacy coin target labeled', $client, 'coin_target_legacy=', $failures);
expect_contains('trace id process sequence', $client, '"%d-%llu"', $failures);
expect_contains('block add no db implication', $client, 'db_write_performed=false', $failures);
expect_contains('in-memory queue state', $client, 'queue_state=appended_in_memory', $failures);
expect_contains('block path config', $stratum, 'DEBUGLOG:block_path', $failures);
expect_contains('verbose block path config', $stratum, 'DEBUGLOG:block_path_verbose', $failures);
expect_contains('yescrypt dump gated', $client, 'g_debuglog_hash || g_debuglog_block_path_verbose', $failures);
expect_true('generic pool hash not unconditional', strpos($client, "\n\tdebuglog(\"POOL hash_hex") === false, $failures);
expect_contains('sha256 version rolling preserved', $client, 'apply_sha256_version_rolling', $failures);
expect_contains('sha256 error trace preserved', $client, 'SHA256D_ERROR26_SUBMIT_TRACE', $failures);
expect_contains('observation overload', $coindHeader, 'STRATUM_COIND_SUBMIT_OBSERVATION *observation', $failures);
expect_contains('submitblock route', $coind, '"submitblock"', $failures);
expect_contains('submitblocktemplate route', $coind, '"submitblocktemplate"', $failures);
expect_contains('getwork route', $coind, '"getwork"', $failures);
expect_contains('no response observed', $coind, 'rpc_call_returned_no_response', $failures);
expect_contains('allocation failure observed', $coind, 'submit_params_allocation_failed', $failures);
expect_contains('daemon control chars sanitized', $coind, 'c <= 0x20 || c >= 0x7f', $failures);
expect_contains('daemon strings truncated', $coind, 'o < 160', $failures);
expect_true('no full block hex marker', strpos($client, 'block_hex=%s') === false, $failures);
foreach (array('rpc_password','rpcpass','private_key','wallet_passphrase','beta_token','miner_password') as $secret)
    expect_true('no secret marker field ' . $secret, strpos($client, $secret . '=') === false, $failures);

if ($failures) {
    echo "Cross-algo block-path diagnostic harness FAILED\n";
    foreach ($failures as $failure) echo " - $failure\n";
    exit(1);
}
echo "Cross-algo block-path diagnostic harness PASSED\n";
