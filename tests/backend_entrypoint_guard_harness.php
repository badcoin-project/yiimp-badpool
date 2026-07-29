<?php
require_once dirname(__FILE__).'/../web/yaamp/components/BackendCycleGuard.php';

$failures = array();
function check($condition, $message) { global $failures; if (!$condition) $failures[] = $message; }
function run_cycle($route, $mode, $steps, $dir) { return BackendCycleGuard::run($route, $mode, $steps, $dir); }

$dir = sys_get_temp_dir().'/badpool-cycle-test-'.getmypid();
mkdir($dir);
putenv('BADPOOL_BACKEND_BLOCKS_READY');
$mutations = 0;
$report = run_cycle('blocks', 'execute', array('mutation' => function() use (&$mutations) { $mutations++; }), $dir);
check($report['result'] === 'refused' && $mutations === 0, 'disabled readiness must refuse before mutation');
check(json_decode(json_encode($report), true)['schema'] === BackendCycleGuard::SCHEMA, 'refusal report must parse');

putenv('BADPOOL_BACKEND_BLOCKS_READY=1');
$held = fopen($dir.'/badpool-backend-blocks.lock', 'c');
flock($held, LOCK_EX | LOCK_NB);
$report = run_cycle('blocks', 'execute', array('mutation' => function() use (&$mutations) { $mutations++; }), $dir);
check(!$report['lock_acquired'] && $mutations === 0, 'contention must refuse before mutation');
flock($held, LOCK_UN); fclose($held);

$cycles = 0;
$report = run_cycle('blocks', 'execute', array('once' => function() use (&$cycles) { $cycles++; }), $dir);
check($cycles === 1 && $report['result'] === 'success', 'one invocation must execute exactly one cycle');
check(json_decode(json_encode($report), true)['result'] === 'success', 'success report must parse');

$report = run_cycle('blocks', 'execute', array('throw' => function() { throw new Exception('test'); }), $dir);
$report = run_cycle('blocks', 'execute', array(), $dir);
check($report['lock_acquired'] && $report['result'] === 'success', 'exception must release lock');

$controller = file_get_contents(dirname(__FILE__).'/../web/yaamp/modules/thread/CronjobController.php');
$loop2 = substr($controller, strpos($controller, 'public function actionRunLoop2'), strpos($controller, 'public function actionRun()', strpos($controller, 'public function actionRunLoop2')) - strpos($controller, 'public function actionRunLoop2'));
check(strpos($loop2, 'BackendRentingPayout(') === false && strpos($loop2, 'BackendUpdateDeposit(') === false && strpos($loop2, 'BackendPayments(') === false, 'loop2 must not implicitly reach payment or wallet movement');
$blocks = substr($controller, strpos($controller, 'public function actionRunBlocks'), strpos($controller, 'public function actionRunLoop2') - strpos($controller, 'public function actionRunBlocks'));
check(strpos($blocks, 'BackendClearEarnings(') === false && strpos($blocks, 'BackendBlockFind1(') === false && strpos($blocks, 'BackendBlocksUpdate(') === false, 'legacy maturity and account-credit graph remains unreachable');

$guard = file_get_contents(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolShareDeleteGuard.php');
check(strpos($guard, 'Share deletion is disabled by default') !== false && strpos($guard, 'no source-level activation path') !== false, 'share delete hard guard remains present');
foreach (array('blocks.sh', 'loop2.sh') as $wrapper) {
	$shell = file_get_contents(dirname(__FILE__).'/../web/'.$wrapper);
	check(strpos($shell, 'while true') === false && strpos($shell, '--once') !== false, "$wrapper must be one-shot only");
}

@unlink($dir.'/badpool-backend-blocks.lock'); @rmdir($dir);
if ($failures) { foreach ($failures as $failure) fwrite(STDERR, "FAIL: $failure\n"); exit(1); }
echo "backend entrypoint guard harness: PASS\n";
