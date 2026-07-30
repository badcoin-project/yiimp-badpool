<?php
require_once dirname(__FILE__).'/../web/yaamp/components/BackendCycleGuard.php';

$failures = array();
function expect_true($value, $message) { global $failures; if (!$value) $failures[] = $message; }
function cycle($route, $mode, $callbacks, $dir) { return BackendCycleGuard::runForTest($route, $mode, $callbacks, $dir); }
function safe_dir($path) { mkdir($path, 0700, true); chmod($path, 0700); }

$root = sys_get_temp_dir().'/badpool-entrypoint-'.getmypid();
safe_dir($root);
$blocks = $root.'/blocks'; $loop2 = $root.'/loop2'; safe_dir($blocks); safe_dir($loop2);

putenv('BADPOOL_BACKEND_BLOCKS_READY');
$calls = 0;
$report = cycle('blocks', 'execute', array('probe' => function() use (&$calls) { $calls++; }), $blocks);
expect_true($report['result'] === 'refused' && $calls === 0, 'readiness must fail closed before callbacks');
putenv('BADPOOL_BACKEND_BLOCKS_READY=wrong');
expect_true(cycle('blocks', 'execute', array(), $blocks)['readiness_gate'] === 'invalid', 'invalid readiness must refuse');
putenv('BADPOOL_BACKEND_LOOP2_READY=1');
expect_true(cycle('blocks', 'execute', array(), $blocks)['result'] === 'refused' && cycle('loop2', 'execute', array(), $loop2)['result'] === 'success', 'route readiness must be isolated');

$report = cycle('blocks', 'report-only', array('probe' => function() use (&$calls) { $calls++; }), $blocks);
expect_true($report['result'] === 'success' && $report['readiness_gate'] === 'not-required' && $calls === 0, 'report-only must need no readiness and run no callbacks');
expect_true($report['callbacks_started'] === array() && $report['instrumentation_available'] === false, 'report fields must truthfully deny instrumentation');
expect_true(json_decode(BackendCycleGuard::encode($report), true)['result'] === 'success', 'success report JSON must parse');

putenv('BADPOOL_BACKEND_BLOCKS_READY=1'); putenv('BADPOOL_BACKEND_LOOP2_READY=1');
$report = cycle('blocks', 'execute', array('one' => array('callback' => function() use (&$calls) { $calls++; }, 'effects' => array('test-only'))), $blocks);
expect_true($calls === 1 && $report['callbacks_started'] === array('one') && $report['callbacks_completed'] === array('one'), 'execute must perform at most one declared cycle and trace completion');
expect_true($report['declared_effect_classes'] === array('test-only'), 'declared effects must be reported without invented activity counts');

foreach (array('Exception', 'Error', 'TypeError') as $kind) {
	$thrower = function() use ($kind) {
		if ($kind === 'Exception') throw new Exception('expected');
		if ($kind === 'Error') throw new Error('expected');
		strlen(array()); // TypeError on supported PHP baselines
	};
	$failed = cycle('blocks', 'execute', array('throw-'.$kind => $thrower), $blocks);
	$again = cycle('blocks', 'execute', array(), $blocks);
	expect_true($failed['result'] === 'failed' && $failed['callbacks_started'] === array('throw-'.$kind) && $failed['callbacks_failed'] === array('throw-'.$kind), "$kind must remain traced");
	expect_true(json_decode(BackendCycleGuard::encode($failed), true)['result'] === 'failed', "$kind failure JSON must parse");
	expect_true($again['lock_acquired'] && $again['result'] === 'success', "$kind must release the lock immediately");
}

// A separate child holds the blocks lock while the parent proves non-blocking contention.
$signal = $root.'/held';
$pid = pcntl_fork();
if ($pid === 0) {
	cycle('blocks', 'execute', array('holder' => function() use ($signal) { file_put_contents($signal, '1'); usleep(900000); }), $blocks);
	exit(0);
}
for ($i=0; $i<100 && !file_exists($signal); $i++) usleep(10000);
$loserCalls = 0; $start = microtime(true);
$loser = cycle('blocks', 'execute', array('loser' => function() use (&$loserCalls) { $loserCalls++; }), $blocks);
$elapsed = microtime(true)-$start;
$otherRoute = cycle('loop2', 'execute', array(), $loop2);
pcntl_waitpid($pid, $status);
$reacquired = cycle('blocks', 'execute', array(), $blocks);
expect_true(!$loser['lock_acquired'] && $loserCalls === 0 && $elapsed < 0.5, 'same-route cross-process contention must be non-blocking and callback-free');
expect_true($otherRoute['lock_acquired'], 'blocks and loop2 must use separate lock identities');
expect_true($reacquired['lock_acquired'], 'lock must be immediately reacquirable after holder exits');

$unsafe = $root.'/unsafe'; safe_dir($unsafe); chmod($unsafe, 0777);
expect_true(cycle('blocks', 'execute', array(), $unsafe)['result'] === 'refused', 'world-writable directory must refuse');
$real = $root.'/real'; safe_dir($real); symlink($real, $root.'/dir-link');
expect_true(cycle('blocks', 'execute', array(), $root.'/dir-link')['result'] === 'refused', 'symlink directory must refuse');
symlink($root.'/target', $real.'/badpool-backend-blocks.lock');
expect_true(cycle('blocks', 'execute', array(), $real)['result'] === 'refused', 'symlink lock target must refuse');
unlink($real.'/badpool-backend-blocks.lock'); file_put_contents($real.'/badpool-backend-blocks.lock', ''); chmod($real.'/badpool-backend-blocks.lock', 0666);
expect_true(cycle('blocks', 'execute', array(), $real)['result'] === 'refused', 'unsafe lock target permissions must refuse');
expect_true(cycle('blocks', 'execute', array(), $root.'/missing')['result'] === 'refused', 'missing directory must refuse');

putenv('BADPOOL_BACKEND_LOCK_DIR='.$blocks); chdir('/');
expect_true(BackendCycleGuard::PRODUCTION_LOCK_DIR === '/run/badpool', 'environment and cwd cannot change production lock identity');

$controller = file_get_contents(dirname(__FILE__).'/../web/yaamp/modules/thread/CronjobController.php');
foreach (array('BackendProcessList(', 'BackendStatsUpdate(', 'BackendUsersUpdate(', 'BackendStatsUpdate2(', 'BackendClearEarnings(', 'BackendRentingPayout(', 'BackendPayments(', 'BackendUpdateDeposit(', 'BackendUpdateServices(') as $prohibited)
	expect_true(strpos(substr($controller, strpos($controller, 'public function actionRunBlocks'), strpos($controller, 'public function actionRun()')-strpos($controller, 'public function actionRunBlocks')), $prohibited) === false, "$prohibited must remain unreachable");
expect_true(substr_count($controller, "runGuardedCycle('blocks'") === 1 && substr_count($controller, "runGuardedCycle('loop2'") === 1, 'direct controller actions must each cross the guard once');

// Wrapper argument matrix uses a harmless fake executable and never loads Yii or callbacks.
chdir(dirname(__FILE__).'/..');
$fake = $root.'/fake-php'; $log = $root.'/wrapper.log';
file_put_contents($fake, "#!/bin/sh\nprintf 'call\\n' >> '".$log."'\nprintf '{\"result\":\"success\"}\\n'\n"); chmod($fake, 0700);
$invalid = array('', '--once', '--mode=execute --once', '--once --mode=', '--once --mode=bad', '--once --mode=execute extra', '--once --mode=execute --mode=execute', 'positional --mode=execute');
foreach (array('web/blocks.sh', 'web/loop2.sh') as $wrapper) {
	foreach ($invalid as $args) { exec('PHP_CLI='.escapeshellarg($fake).' '.escapeshellarg($wrapper).' '.$args.' >/dev/null 2>&1', $out, $rc); expect_true($rc !== 0, "$wrapper must reject [$args]"); }
	foreach (array('--once --mode=report-only', '--once --mode=execute') as $args) { exec('PHP_CLI='.escapeshellarg($fake).' '.escapeshellarg($wrapper).' '.$args.' >/dev/null 2>&1', $out, $rc); expect_true($rc === 0, "$wrapper must accept exactly [$args]"); }
}
expect_true(count(file($log)) === 4, 'each accepted wrapper invocation must execute PHP exactly once');

exec('rm -rf '.escapeshellarg($root));
if ($failures) { foreach ($failures as $failure) fwrite(STDERR, "FAIL: $failure\n"); exit(1); }
echo "backend entrypoint guard harness: PASS\n";
