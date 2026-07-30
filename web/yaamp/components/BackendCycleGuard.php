<?php

/** Fail-closed orchestration for the frozen legacy backend entrypoints. */
class BackendCycleGuard
{
	const SCHEMA = 'badpool.backend-cycle.v2';
	const PRODUCTION_LOCK_DIR = '/run/badpool';

	public static function run($route, $mode, array $callbacks)
	{
		return self::runInternal($route, $mode, $callbacks, self::PRODUCTION_LOCK_DIR, self::effectiveUid());
	}

	/** Test-only seam. Production callers must use run(). */
	public static function runForTest($route, $mode, array $callbacks, $lockDir, $serviceUid = null)
	{
		if ($serviceUid === null) $serviceUid = self::effectiveUid();
		return self::runInternal($route, $mode, $callbacks, $lockDir, intval($serviceUid));
	}

	private static function runInternal($route, $mode, array $callbacks, $lockDir, $serviceUid)
	{
		$report = self::newReport($route, $mode, $callbacks);
		if (!in_array($route, array('blocks', 'loop2'), true) || !in_array($mode, array('report-only', 'execute'), true))
			return self::refuse($report, 'invalid route or mode');

		// Report-only is deliberately readiness-independent and callback-free.
		if ($mode === 'report-only') {
			$report['readiness_gate'] = 'not-required';
			$report['result'] = 'success';
			return self::finish($report);
		}

		$ready = getenv('BADPOOL_BACKEND_'.strtoupper($route).'_READY');
		if ($ready !== '1') {
			$report['readiness_gate'] = ($ready === false || $ready === '') ? 'missing' : 'invalid';
			return self::refuse($report, 'execute readiness is not explicitly enabled');
		}
		$report['readiness_gate'] = 'enabled';

		$directoryError = self::validateDirectory($lockDir, $serviceUid);
		if ($directoryError !== null) return self::refuse($report, $directoryError);
		$lockPath = $lockDir.DIRECTORY_SEPARATOR.'badpool-backend-'.$route.'.lock';
		$directoryStat = @lstat($lockDir);
		$targetError = self::validateExistingTarget($lockPath, $directoryStat['uid']);
		if ($targetError !== null) return self::refuse($report, $targetError);

		// Open an immutable, pre-provisioned asset only. The validated parent chain
		// cannot be modified by the service UID, so it cannot substitute this path.
		$handle = @fopen($lockPath, 'r');
		if ($handle === false) return self::refuse($report, 'lock file open failed');
		$targetError = self::validateOpenTarget($lockPath, $handle, $directoryStat['uid']);
		if ($targetError !== null) {
			fclose($handle);
			return self::refuse($report, $targetError);
		}
		if (!flock($handle, LOCK_EX | LOCK_NB)) {
			fclose($handle);
			return self::refuse($report, 'exclusive route lock is already held');
		}

		$report['lock_acquired'] = true;
		try {
			foreach ($callbacks as $name => $declaration) {
				$callback = is_array($declaration) && isset($declaration['callback']) ? $declaration['callback'] : $declaration;
				$report['callbacks_started'][] = $name;
				call_user_func($callback);
				$report['callbacks_completed'][] = $name;
			}
			$report['result'] = 'success';
		}
		catch (Throwable $failure) {
			$report['callbacks_failed'][] = end($report['callbacks_started']);
			$report['result'] = 'failed';
			$report['errors'][] = get_class($failure).': '.$failure->getMessage();
		}
		finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
		return self::finish($report);
	}

	private static function newReport($route, $mode, array $callbacks)
	{
		$effects = array();
		foreach ($callbacks as $declaration) {
			if (is_array($declaration) && isset($declaration['effects']))
				$effects = array_merge($effects, (array) $declaration['effects']);
		}
		return array(
			'schema' => self::SCHEMA, 'route' => $route, 'mode' => $mode,
			'started_at_utc' => gmdate('c'), 'completed_at_utc' => null,
			'lock_acquired' => false, 'readiness_gate' => 'refused',
			'callbacks_started' => array(), 'callbacks_completed' => array(), 'callbacks_failed' => array(),
			'declared_effect_classes' => array_values(array_unique($effects)),
			'instrumentation_available' => false, 'result' => 'refused', 'errors' => array(),
		);
	}

	private static function validateDirectory($path, $serviceUid)
	{
		if (!is_string($path) || $path === '' || $path[0] !== '/') return 'lock directory must be absolute';
		$stat = @lstat($path);
		$real = @realpath($path);
		if ($stat === false || $real === false) return 'lock directory is missing';
		if (($stat['mode'] & 0170000) !== 0040000 || is_link($path)) return 'lock directory is not a real directory';
		if ($real !== $path) return 'lock directory canonical path mismatch';
		foreach (self::pathChain($path) as $component) {
			$componentStat = @lstat($component);
			if ($componentStat === false || ($componentStat['mode'] & 0170000) !== 0040000 || is_link($component))
				return 'lock directory parent chain is unsafe';
			if ($componentStat['uid'] === $serviceUid || ($componentStat['mode'] & 0022) !== 0)
				return 'lock directory is replaceable by the service account';
		}
		return null;
	}

	private static function pathChain($path)
	{
		$parts = explode('/', trim($path, '/'));
		$chain = array('/');
		$current = '';
		foreach ($parts as $part) {
			$current .= '/'.$part;
			$chain[] = $current;
		}
		return $chain;
	}

	private static function validateExistingTarget($path, $trustedUid)
	{
		$stat = @lstat($path);
		if ($stat === false) return 'required pre-provisioned lock file is missing';
		if (($stat['mode'] & 0170000) !== 0100000 || is_link($path)) return 'lock target is not a regular non-symlink file';
		if ($stat['uid'] !== $trustedUid || ($stat['mode'] & 0222) !== 0 || $stat['nlink'] !== 1)
			return 'lock target ownership, permissions, or link count is unsafe';
		return null;
	}

	private static function validateOpenTarget($path, $handle, $trustedUid)
	{
		$pathStat = @lstat($path);
		$openStat = @fstat($handle);
		if ($pathStat === false || $openStat === false) return 'lock target validation failed';
		if (($pathStat['mode'] & 0170000) !== 0100000 || is_link($path)) return 'lock target changed type';
		if ($pathStat['dev'] !== $openStat['dev'] || $pathStat['ino'] !== $openStat['ino']) return 'lock target changed during open';
		if ($openStat['uid'] !== $trustedUid || ($openStat['mode'] & 0222) !== 0 || $openStat['nlink'] !== 1)
			return 'open lock target is unsafe';
		return null;
	}

	private static function effectiveUid()
	{
		return function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
	}

	private static function refuse(array $report, $error)
	{
		$report['errors'][] = $error;
		return self::finish($report);
	}

	private static function finish(array $report)
	{
		$report['completed_at_utc'] = gmdate('c');
		return $report;
	}

	public static function encode(array $report)
	{
		$options = defined('JSON_UNESCAPED_SLASHES') ? JSON_UNESCAPED_SLASHES : 0;
		if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) $options |= JSON_PARTIAL_OUTPUT_ON_ERROR;
		$json = json_encode($report, $options);
		if ($json !== false) return $json;
		return '{"schema":"'.self::SCHEMA.'","route":"unknown","mode":"unknown","result":"failed","errors":["report JSON encoding failed"]}';
	}
}
