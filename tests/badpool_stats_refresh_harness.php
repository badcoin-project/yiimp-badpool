<?php
require_once dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolStatsRefresh.php';

$failures = array();
function expect_stats($condition, $message) { global $failures; if (!$condition) $failures[] = $message; }

$rows = array('hashrate' => array(), 'hashstats' => array(), 'hashuser' => array());
$writes = array(); $reads = array(); $generation = 1;
$key = function($table, $values) {
	$parts = array(); foreach ($values as $name => $value) $parts[] = "$name=$value";
	return implode('|', $parts);
};
$operations = array(
	'find' => function($table, $values) use (&$rows, $key) { $id = $key($table, $values); return isset($rows[$table][$id]) ? $rows[$table][$id] : null; },
	'create' => function($table) { return new stdClass; },
	'save' => function($table, $row) use (&$rows, &$writes, $key) {
		$values = array('time' => $row->time, 'algo' => $row->algo);
		if ($table === 'hashuser') $values['userid'] = $row->userid;
		$rows[$table][$key($table, $values)] = $row; $writes[] = $table; return true;
	},
	'pool_rate' => function($algo) use (&$generation) { return $generation * 100 + strlen($algo); },
	'pool_rate_bad' => function($algo) use (&$generation) { return $generation * 10 + strlen($algo); },
	'user_rate' => function($userid, $algo) use (&$generation) { return $generation * 1000 + $userid; },
	'user_rate_bad' => function($userid, $algo) use (&$generation) { return $generation * 100 + $userid; },
	'block_earnings' => function($algo, $hour) use (&$reads) { $reads[] = array('blocks', $algo, $hour); return strlen($algo) / 100; },
	'active_user_pairs' => function($bucket, $algorithms) {
		return array(
			array('userid' => 79, 'algo' => 'scrypt'),
			array('userid' => 80, 'algo' => 'sha256'),
			array('userid' => 81, 'algo' => 'x11'),
		);
	},
);
$now = 1787832599; // not on either bucket boundary
$clock = function() use ($now) { return $now; };
$refresh = new BadpoolStatsRefresh($operations, $clock);

$first = $refresh->run();
$algorithms = array('sha256', 'scrypt', 'groestl', 'skein', 'yescrypt');
expect_stats($first['algorithms'] === $algorithms, 'the report must contain exactly the five canonical algorithms in order');
expect_stats(!in_array('sha256d', $first['algorithms'], true), 'sha256d must remain a display label, not a database key');
expect_stats(count($rows['hashrate']) === 5 && $first['hashrate_rows_created'] === 5, 'the first run must create five pool rows');
expect_stats(count($rows['hashstats']) === 5 && $first['hashstats_rows_created'] === 5, 'the first run must create five long-term rows');
expect_stats(count($rows['hashuser']) === 2 && $first['hashuser_rows_created'] === 2, 'only active canonical user/algo pairs must be created');
expect_stats($first['pool_bucket_seconds'] === 300 && $first['user_bucket_seconds'] === 300 && $first['long_term_bucket_seconds'] === 3600, 'bucket durations must be 5m/5m/1h');
$poolBucket = floor($now / 300) * 300; $hourBucket = floor($now / 3600) * 3600;
foreach ($algorithms as $algo) {
	$id = "time=$poolBucket|algo=$algo"; $longId = "time=$hourBucket|algo=$algo";
	expect_stats(isset($rows['hashrate'][$id]), "$algo must have a current pool bucket");
	expect_stats($rows['hashrate'][$id]->hashrate === 100 + strlen($algo) && $rows['hashrate'][$id]->hashrate_bad === 10 + strlen($algo), "$algo pool rates must use the live valid/rejected calculators");
	expect_stats(isset($rows['hashstats'][$longId]), "$algo must have a current hourly bucket");
}
expect_stats(count($reads) === 5, 'block-derived earnings must be read once for each canonical algorithm');

$generation = 2; $second = $refresh->run();
expect_stats(count($rows['hashrate']) === 5 && $second['hashrate_rows_updated'] === 5 && $second['hashrate_rows_created'] === 0, 'a same-bucket rerun must update pool rows without duplicates');
expect_stats(count($rows['hashstats']) === 5 && $second['hashstats_rows_updated'] === 5 && $second['hashstats_rows_created'] === 0, 'a same-hour rerun must update long-term rows without duplicates');
expect_stats(count($rows['hashuser']) === 2 && $second['hashuser_rows_updated'] === 2 && $second['hashuser_rows_created'] === 0, 'a same-bucket rerun must update user rows without duplicates');
$user = $rows['hashuser']["time=$poolBucket|algo=scrypt|userid=79"];
expect_stats($user->hashrate === 2079 && $user->hashrate_bad === 279, 'user history must store raw current rates without legacy smoothing or floors');
expect_stats($second['schema'] === 'badpool.stats-refresh.v1' && $second['status'] === 'success' && is_array($second['errors']), 'the structured result contract must be present');
expect_stats($second['db_tables_written'] === array('hashrate', 'hashstats', 'hashuser'), 'the report must declare exactly the three-table write boundary');
foreach (array('wallet_rpc_used', 'backend_accounting_used', 'shares_deleted', 'earnings_deleted', 'services_changed') as $field)
	expect_stats($second[$field] === false, "$field must remain false");
expect_stats(array_values(array_unique($writes)) === array('hashrate', 'hashstats', 'hashuser'), 'the implementation must save only the three allowed tables');

$source = file_get_contents(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolStatsRefresh.php');
expect_stats(!preg_match('/\b(DELETE|UPDATE|INSERT)\b/i', $source), 'the component must contain no direct modifying SQL');
foreach (array('BackendStatsUpdate(', 'BackendStatsUpdate2(', 'BackendUsersUpdate(', 'BackendPayments(', 'yaamp_convert_earnings_user(', 'systemctl', 'service ') as $term)
	expect_stats(strpos($source, $term) === false, "$term must be absent from the isolated component");
$controller = file_get_contents(dirname(__FILE__).'/../web/yaamp/modules/thread/CronjobController.php');
expect_stats((bool) preg_match("/actionRunLoop2\(\).*?runGuardedCycle\('loop2', array\(\)\);/s", $controller), 'runLoop2 must retain its empty callback freeze');
expect_stats((bool) preg_match("/actionRunBlocks\(\).*?runGuardedCycle\('blocks', array\(\)\);/s", $controller), 'runBlocks must retain its empty callback freeze');
$routeStart = strpos($controller, 'public function actionRunBadpoolStats');
$routeEnd = strpos($controller, 'private function runGuardedCycle', $routeStart);
$route = substr($controller, $routeStart, $routeEnd - $routeStart);
expect_stats(strpos($route, 'new BadpoolStatsRefresh') !== false && substr_count($route, 'echo ') === 1, 'the dedicated route must invoke the isolated component and emit one report');
foreach (array('BackendStatsUpdate', 'BackendStatsUpdate2', 'BackendUsersUpdate', 'BackendCoinsUpdate', 'BackendBlock', 'BackendPayments', 'BackendRenting', 'MonitorBTC') as $term)
	expect_stats(strpos($route, $term) === false, "$term must be absent from the dedicated route");

if ($failures) { foreach ($failures as $failure) fwrite(STDERR, "FAIL: $failure\n"); exit(1); }
echo "badpool stats refresh harness: PASS\n";
