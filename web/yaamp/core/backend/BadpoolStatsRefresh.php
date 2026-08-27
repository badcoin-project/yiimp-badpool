<?php

/**
 * Persist the mining history needed by BadPool without running the legacy
 * statistics, rental, cleanup, or accounting cycles.
 */
class BadpoolStatsRefresh
{
	const SCHEMA = 'badpool.stats-refresh.v1';
	const POOL_BUCKET_SECONDS = 300;
	const USER_BUCKET_SECONDS = 300;
	const LONG_TERM_BUCKET_SECONDS = 3600;

	private static $algorithms = array('sha256', 'scrypt', 'groestl', 'skein', 'yescrypt');
	private $operations;
	private $clock;

	public function __construct($operations = null, $clock = null)
	{
		$this->operations = $operations ?: self::productionOperations();
		$this->clock = $clock ?: function() { return time(); };
	}

	public function run()
	{
		$started = call_user_func($this->clock);
		$report = array(
			'schema' => self::SCHEMA,
			'status' => 'success',
			'started_at_utc' => gmdate('Y-m-d\TH:i:s\Z', $started),
			'completed_at_utc' => null,
			'algorithms' => self::$algorithms,
			'pool_bucket_seconds' => self::POOL_BUCKET_SECONDS,
			'user_bucket_seconds' => self::USER_BUCKET_SECONDS,
			'long_term_bucket_seconds' => self::LONG_TERM_BUCKET_SECONDS,
			'hashrate_rows_created' => 0,
			'hashrate_rows_updated' => 0,
			'hashstats_rows_created' => 0,
			'hashstats_rows_updated' => 0,
			'hashuser_rows_created' => 0,
			'hashuser_rows_updated' => 0,
			'db_tables_written' => array('hashrate', 'hashstats', 'hashuser'),
			'wallet_rpc_used' => false,
			'backend_accounting_used' => false,
			'shares_deleted' => false,
			'earnings_deleted' => false,
			'services_changed' => false,
			'errors' => array(),
		);

		try {
			$poolBucket = floor($started / self::POOL_BUCKET_SECONDS) * self::POOL_BUCKET_SECONDS;
			$hourBucket = floor($started / self::LONG_TERM_BUCKET_SECONDS) * self::LONG_TERM_BUCKET_SECONDS;

			foreach (self::$algorithms as $algo) {
				$this->upsertPoolRate($report, $algo, $poolBucket);
				$this->upsertLongTermRate($report, $algo, $hourBucket);
			}

			$pairs = call_user_func($this->operations['active_user_pairs'], $poolBucket, self::$algorithms);
			foreach ($pairs as $pair) {
				$algo = isset($pair['algo']) ? $pair['algo'] : null;
				$userid = isset($pair['userid']) ? (int) $pair['userid'] : 0;
				if (!$userid || !in_array($algo, self::$algorithms, true)) continue;
				$this->upsertUserRate($report, $userid, $algo, $poolBucket);
			}
		}
		catch (Throwable $e) {
			$report['status'] = 'failed';
			$report['errors'][] = $e->getMessage();
		}

		$report['completed_at_utc'] = gmdate('Y-m-d\TH:i:s\Z', call_user_func($this->clock));
		return $report;
	}

	private function upsertPoolRate(&$report, $algo, $bucket)
	{
		list($row, $created) = $this->row('hashrate', array('time' => $bucket, 'algo' => $algo));
		$row->time = $bucket;
		$row->algo = $algo;
		$row->hashrate = call_user_func($this->operations['pool_rate'], $algo);
		$row->hashrate_bad = call_user_func($this->operations['pool_rate_bad'], $algo);
		$this->save('hashrate', $row);
		$report[$created ? 'hashrate_rows_created' : 'hashrate_rows_updated']++;
	}

	private function upsertLongTermRate(&$report, $algo, $bucket)
	{
		list($row, $created) = $this->row('hashstats', array('time' => $bucket, 'algo' => $algo));
		$row->time = $bucket;
		$row->algo = $algo;
		$row->hashrate = call_user_func($this->operations['pool_rate'], $algo);
		$row->earnings = call_user_func($this->operations['block_earnings'], $algo, $bucket);
		$this->save('hashstats', $row);
		$report[$created ? 'hashstats_rows_created' : 'hashstats_rows_updated']++;
	}

	private function upsertUserRate(&$report, $userid, $algo, $bucket)
	{
		list($row, $created) = $this->row('hashuser', array('time' => $bucket, 'algo' => $algo, 'userid' => $userid));
		$row->userid = $userid;
		$row->time = $bucket;
		$row->algo = $algo;
		// Raw samples keep a five-minute point responsive; legacy 20% smoothing was
		// intended for the coarser 15-minute history and delayed changes excessively.
		$row->hashrate = call_user_func($this->operations['user_rate'], $userid, $algo);
		$row->hashrate_bad = call_user_func($this->operations['user_rate_bad'], $userid, $algo);
		$this->save('hashuser', $row);
		$report[$created ? 'hashuser_rows_created' : 'hashuser_rows_updated']++;
	}

	private function row($table, $key)
	{
		$row = call_user_func($this->operations['find'], $table, $key);
		if ($row) return array($row, false);
		return array(call_user_func($this->operations['create'], $table), true);
	}

	private function save($table, $row)
	{
		if (!call_user_func($this->operations['save'], $table, $row))
			throw new RuntimeException("Unable to save $table statistics row");
	}

	private static function productionOperations()
	{
		$models = array('hashrate' => 'db_hashrate', 'hashstats' => 'db_hashstats', 'hashuser' => 'db_hashuser');
		return array(
			'find' => function($table, $key) use ($models) {
				$clauses = array(); $params = array();
				foreach ($key as $name => $value) { $clauses[] = "$name=:$name"; $params[":$name"] = $value; }
				return getdbosql($models[$table], implode(' and ', $clauses), $params);
			},
			'create' => function($table) use ($models) { $class = $models[$table]; return new $class; },
			'save' => function($table, $row) { return $row->save(); },
			'pool_rate' => function($algo) { return yaamp_pool_rate($algo); },
			'pool_rate_bad' => function($algo) { return yaamp_pool_rate_bad($algo); },
			'user_rate' => function($userid, $algo) { return yaamp_user_rate($userid, $algo); },
			'user_rate_bad' => function($userid, $algo) { return yaamp_user_rate_bad($userid, $algo); },
			'block_earnings' => function($algo, $hour) {
				$value = dboscalar("SELECT SUM(amount*price) FROM blocks WHERE algo=:algo AND time>:time AND category!='orphan'",
					array(':algo' => $algo, ':time' => $hour));
				return $value === null ? null : bitcoinvaluetoa($value);
			},
			'active_user_pairs' => function($bucket, $algorithms) {
				$params = array(':time' => $bucket); $holders = array();
				foreach ($algorithms as $index => $algo) { $name = ':algo'.$index; $holders[] = $name; $params[$name] = $algo; }
				return dbolist('SELECT userid, algo FROM shares WHERE time>=:time AND algo IN ('.implode(',', $holders).') GROUP BY userid, algo', $params);
			},
		);
	}
}
