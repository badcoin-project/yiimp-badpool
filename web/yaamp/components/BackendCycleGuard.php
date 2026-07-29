<?php

/** Fail-closed, process-local orchestration for the two legacy backend cycles. */
class BackendCycleGuard
{
	const SCHEMA = 'badpool.backend-cycle.v1';

	public static function run($route, $mode, array $steps, $lockDir = null)
	{
		$started = gmdate('c');
		$report = array(
			'schema' => self::SCHEMA, 'route' => $route, 'mode' => $mode,
			'started_at_utc' => $started, 'completed_at_utc' => null,
			'lock_acquired' => false, 'readiness_gate' => 'refused',
			'functions_executed' => array(), 'mutation_counts' => array('database' => 0, 'accounting' => 0),
			'wallet_rpc_calls' => 0, 'payment_calls' => 0, 'share_delete_attempts' => 0,
			'result' => 'refused', 'errors' => array(),
		);
		$key = strtoupper($route);
		if (!in_array($route, array('blocks', 'loop2'), true) || !in_array($mode, array('report-only', 'execute'), true)) {
			$report['errors'][] = 'invalid route or mode';
			return self::finish($report);
		}
		$ready = getenv('BADPOOL_BACKEND_'.$key.'_READY');
		if ($ready !== '1') {
			$report['readiness_gate'] = $ready === false || $ready === '' ? 'missing' : 'invalid';
			$report['errors'][] = 'automation readiness is not explicitly enabled';
			return self::finish($report);
		}
		$report['readiness_gate'] = 'enabled';
		$lockDir = $lockDir ?: (getenv('BADPOOL_BACKEND_LOCK_DIR') ?: sys_get_temp_dir());
		$handle = @fopen(rtrim($lockDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'badpool-backend-'.$route.'.lock', 'c');
		if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
			if ($handle) fclose($handle);
			$report['errors'][] = 'exclusive route lock is already held or unavailable';
			return self::finish($report);
		}
		$report['lock_acquired'] = true;
		try {
			if ($mode === 'report-only') {
				$report['result'] = 'success';
				return self::finish($report);
			}
			foreach ($steps as $name => $step) {
				call_user_func($step);
				$report['functions_executed'][] = $name;
				$report['mutation_counts']['database']++;
			}
			$report['result'] = 'success';
		}
		catch (Exception $e) {
			$report['result'] = 'failed';
			$report['errors'][] = $e->getMessage();
		}
		finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
		return self::finish($report);
	}

	private static function finish(array $report)
	{
		$report['completed_at_utc'] = gmdate('c');
		return $report;
	}
}
