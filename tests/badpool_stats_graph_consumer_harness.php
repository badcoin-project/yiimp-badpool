<?php

$failures = array();
function expect_graph($condition, $message) { global $failures; if (!$condition) $failures[] = $message; }

class GraphTestUser { public $id = 79; }
class GraphTestState { public function getState($name) { return 'scrypt'; } }

$graphUser = new GraphTestUser;
$graphState = new GraphTestState;
$now = time();
$bucket = floor($now / 300) * 300;
$graphRows = array();
foreach (array(4 => 1, 3 => 2, 2 => 3) as $age => $rate) {
	$row = new stdClass;
	$row->time = $bucket - $age*300;
	$row->hashrate = $rate*1000000;
	$row->hashrate_bad = $rate*100000;
	$graphRows[] = $row;
}

function getparam($name) { return $name === 'address' ? 'test-wallet' : 'scrypt'; }
function getuserparam($address) { global $graphUser; return $graphUser; }
function user() { global $graphState; return $graphState; }
function yaamp_hashrate_constant($algo) { return 1; }
function yaamp_user_rate($userid, $algo) { return 9000000; }
function yaamp_user_rate_bad($userid, $algo) { return 900000; }
function getdbolist($model, $condition, $params) { global $graphRows; return $graphRows; }

ob_start();
require dirname(__FILE__).'/../web/yaamp/modules/site/results/graph_user_results.php';
$output = ob_get_clean();
$series = json_decode($output, true);
expect_graph(json_last_error() === JSON_ERROR_NONE, 'the actual graph consumer must emit valid JSON');
expect_graph(count($series) === 3, 'the graph must retain valid, smoothed, and rejected series');
expect_graph(count($series[0]) >= 287 && count($series[0]) <= 288, 'the graph must retain approximately 24 hours of five-minute points');

$valid = array_map(function($point) { return $point[1]; }, $series[0]);
$rejected = array_map(function($point) { return $point[1]; }, $series[2]);
foreach (array(1.0, 2.0, 3.0) as $sample)
	expect_graph(in_array($sample, $valid), "the consumer must render the $sample MH/s five-minute sample");
foreach (array(0.1, 0.2, 0.3) as $sample)
	expect_graph(in_array($sample, $rejected, true), "the consumer must render the $sample MH/s rejected sample");
expect_graph($valid[count($valid)-1] == 9.0, 'the newest interval must use the live valid-rate calculation');
expect_graph($rejected[count($rejected)-1] === 0.9, 'the newest interval must use the live rejected-rate calculation');
expect_graph(count($series[1]) === count($series[0]), 'the smoothed display series must cover every valid-rate point');

$poolSource = file_get_contents(dirname(__FILE__).'/../web/yaamp/modules/site/results/graph_hashrate_results.php');
expect_graph(strpos($poolSource, '$step = 5*60;') !== false, 'pool history padding must use the five-minute producer cadence');
expect_graph(strpos($poolSource, '$historyPoints = 24*60*60/$step;') !== false, 'pool history padding must retain a full 24-hour range');

if ($failures) { foreach ($failures as $failure) fwrite(STDERR, "FAIL: $failure\n"); exit(1); }
echo "badpool stats graph consumer harness: PASS\n";
