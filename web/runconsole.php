<?php

if(php_sapi_name() != "cli") return;

require_once('serverconfig.php');
require_once('yaamp/defaultconfig.php');

require_once('framework/yii.php');
require_once('yaamp/include.php');

require_once('yaamp/components/CYiimpConsoleApp.php');

$config = require_once('yaamp/console.php');
$app = Yii::createApplication('CYiimpConsoleApp', $config);

try
{
	$app->runController($argv[1]);
}

catch(Throwable $e)
{
	debuglog($e, 5);

	$message = $e->getMessage();
	$route = isset($argv[1]) ? $argv[1] : '';
	if ($route === 'cronjob/runBlocks' || $route === 'cronjob/runLoop2') {
		require_once('yaamp/components/BackendCycleGuard.php');
		$name = $route === 'cronjob/runBlocks' ? 'blocks' : 'loop2';
		$report = array(
			'schema' => BackendCycleGuard::SCHEMA, 'route' => $name,
			'mode' => getenv('BADPOOL_BACKEND_MODE') ?: 'invalid',
			'started_at_utc' => gmdate('c'), 'completed_at_utc' => gmdate('c'),
			'lock_acquired' => false, 'readiness_gate' => 'unknown',
			'callbacks_started' => array(), 'callbacks_completed' => array(), 'callbacks_failed' => array(), 'declared_callbacks' => array(),
			'declared_effect_classes' => array(), 'instrumentation_available' => false,
			'result' => 'failed', 'errors' => array(get_class($e).': '.$message),
		);
		echo BackendCycleGuard::encode($report)."\n";
	} else {
		fwrite(STDERR, "exception: $message\n");
	}
	exit(1);
// 	send_email_alert('backend', "backend error", "$message");
}
