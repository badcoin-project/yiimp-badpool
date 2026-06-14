<?php
/**
 * Read-only BadPool backend/payment preview reports.
 *
 * These commands intentionally use SELECT-only database access and do not call
 * backend, payment, payout, wallet, or service-control code.
 */
class BadpoolGuardCommand extends CConsoleCommand
{
	protected $basePath;
	private $format = 'json';
	private $scope = array();
	private $warnings = array();

	private $actions = array(
		'overview',
		'blocks-preview',
		'earnings-preview',
		'account-credit-preview',
		'payout-candidates-preview',
		'safety-scan',
	);

	private $allowedOptions = array(
		'coin-id',
		'all-coins-preview',
		'format',
	);

	private $dangerousOptions = array(
		'execute',
		'apply',
		'send',
		'delete',
		'retry',
		'wallet',
		'wallet-read',
		'wallet-send',
		'share-delete',
		'payout-retry',
		'payout-delete',
		'create-payouts',
		'mutate',
	);

	public function run($args)
	{
		$root = realpath(Yii::app()->getBasePath().DIRECTORY_SEPARATOR.'..');
		$this->basePath = str_replace(DIRECTORY_SEPARATOR, '/', $root);

		if (empty($args) || arraySafeVal($args, 0) == 'help') {
			echo $this->getHelp();
			return 1;
		}

		$action = array_shift($args);
		if (!in_array($action, $this->actions, true)) {
			return $this->refuse("Unknown badpoolguard action: $action");
		}

		$options = $this->parseOptions($args);
		if (isset($options['error'])) {
			return $this->refuse($options['error']);
		}

		if (isset($options['format'])) {
			$this->format = strtolower($options['format']);
			if (!in_array($this->format, array('json', 'text'), true)) {
				return $this->refuse('Unsupported --format value. Use --format=json or --format=text.');
			}
		}

		$scope = $this->resolveScope($options);
		if (isset($scope['error'])) {
			return $this->refuse($scope['error']);
		}
		$this->scope = $scope;

		switch ($action) {
			case 'overview':
				$report = $this->overviewReport($action);
				break;
			case 'blocks-preview':
				$report = $this->blocksPreviewReport($action);
				break;
			case 'earnings-preview':
				$report = $this->earningsPreviewReport($action);
				break;
			case 'account-credit-preview':
				$report = $this->accountCreditPreviewReport($action);
				break;
			case 'payout-candidates-preview':
				$report = $this->payoutCandidatesPreviewReport($action);
				break;
			case 'safety-scan':
				$report = $this->safetyScanReport($action);
				break;
			default:
				return $this->refuse("Unhandled action: $action");
		}

		$this->emitReport($report);
		return 0;
	}

	public function getHelp()
	{
		return "Yiimp badpoolguard command\n".
			"Usage: php web/yaamp/yiic.php badpoolguard overview --coin-id=<id> [--format=json|text]\n".
			"       php web/yaamp/yiic.php badpoolguard blocks-preview --coin-id=<id> [--format=json|text]\n".
			"       php web/yaamp/yiic.php badpoolguard earnings-preview --coin-id=<id> [--format=json|text]\n".
			"       php web/yaamp/yiic.php badpoolguard account-credit-preview --coin-id=<id> [--format=json|text]\n".
			"       php web/yaamp/yiic.php badpoolguard payout-candidates-preview --coin-id=<id> [--format=json|text]\n".
			"       php web/yaamp/yiic.php badpoolguard safety-scan --coin-id=<id> [--format=json|text]\n".
			"       php web/yaamp/yiic.php badpoolguard overview --all-coins-preview [--format=json|text]\n\n".
			"Read-only preview only. This command does not read wallets, send wallets, mutate DB rows, delete shares, create payouts, or manage services.\n";
	}

	private function parseOptions($args)
	{
		$options = array();
		foreach ($args as $arg) {
			if (!preg_match('/^--([^=]+)(=(.*))?$/', $arg, $matches)) {
				return array('error' => "Unknown argument refused: $arg");
			}

			$name = $matches[1];
			$value = isset($matches[3]) ? $matches[3] : true;
			$lower = strtolower($name);

			if (in_array($lower, $this->dangerousOptions, true)) {
				return array('error' => "Dangerous option refused in read-only preview command: --$name");
			}
			if (!in_array($lower, $this->allowedOptions, true)) {
				return array('error' => "Unknown option refused: --$name");
			}
			if (isset($options[$lower])) {
				return array('error' => "Duplicate option refused: --$name");
			}
			$options[$lower] = $value;
		}

		return $options;
	}

	private function resolveScope($options)
	{
		$hasCoinId = isset($options['coin-id']);
		$allCoins = isset($options['all-coins-preview']);

		if ($hasCoinId && $allCoins) {
			return array('error' => 'Use either --coin-id=<id> or --all-coins-preview, not both.');
		}
		if (!$hasCoinId && !$allCoins) {
			return array('error' => 'Refusing implicit all-coin preview. Pass --coin-id=<id> or --all-coins-preview.');
		}

		if ($allCoins) {
			if ($options['all-coins-preview'] !== true && !in_array(strtolower($options['all-coins-preview']), array('1', 'true', 'yes'), true)) {
				return array('error' => 'Use --all-coins-preview without a value, or with true/1/yes.');
			}
			return array(
				'all_coins_preview' => true,
				'coin_id' => null,
				'coin' => null,
			);
		}

		$coinId = $options['coin-id'];
		if (!preg_match('/^[0-9]+$/', (string)$coinId) || intval($coinId) <= 0) {
			return array('error' => 'Invalid --coin-id. Expected a positive integer.');
		}
		if (!$this->tableExists('coins') || !$this->columnExists('coins', 'id')) {
			return array('error' => 'Cannot resolve --coin-id because coins.id is not available.');
		}

		$coin = $this->selectRow("SELECT ".$this->selectColumns('coins', array(
			'id', 'symbol', 'symbol2', 'name', 'algo', 'enable', 'installed', 'visible', 'auto_ready',
			'rpcencoding', 'txfee', 'payout_min', 'balance', 'available', 'cleared', 'immature',
			'mature_blocks', 'block_height', 'target_height', 'price', 'price2',
		))." FROM coins WHERE id=:coin_id", array(':coin_id' => intval($coinId)));

		if (!$coin) {
			return array('error' => "No coin record found for --coin-id=$coinId.");
		}

		return array(
			'all_coins_preview' => false,
			'coin_id' => intval($coinId),
			'coin' => $coin,
		);
	}

	private function overviewReport($command)
	{
		$report = $this->baseReport($command);
		$report['summary']['coins'] = $this->coinRowsSummary();
		$report['summary']['blocks_by_category'] = $this->groupSummary('blocks', 'category', $this->coinWhere('blocks', 'coin_id'));
		$report['summary']['earnings_by_status'] = $this->groupSummary('earnings', 'status', $this->coinWhere('earnings', 'coinid'));
		$report['summary']['accounts'] = $this->accountsSummary();
		$report['summary']['payouts'] = $this->payoutsSummary();
		$report['summary']['withdraws'] = $this->withdrawsSummary();
		$report['summary']['shares'] = $this->sharesSummary();
		$report['warnings'] = $this->warnings;
		return $report;
	}

	private function blocksPreviewReport($command)
	{
		$report = $this->baseReport($command);
		$where = $this->coinWhere('blocks', 'coin_id');
		$report['summary']['blocks_by_category'] = $this->groupSummary('blocks', 'category', $where);
		$report['summary']['backend_touch_candidates'] = $this->blockTouchCandidates();
		$report['warnings'] = $this->warnings;
		return $report;
	}

	private function earningsPreviewReport($command)
	{
		$report = $this->baseReport($command);
		$where = $this->coinWhere('earnings', 'coinid');
		$report['summary']['earnings_by_status'] = $this->groupSummary('earnings', 'status', $where);
		$report['summary']['unsettled_candidates'] = $this->earningsCandidates();
		$report['warnings'] = $this->warnings;
		return $report;
	}

	private function accountCreditPreviewReport($command)
	{
		$report = $this->baseReport($command);
		$report['summary']['status_1_earnings'] = $this->statusOneEarningsSummary();
		$report['items']['projected_account_credits'] = $this->projectedAccountCredits();
		$report['warnings'] = $this->warnings;
		return $report;
	}

	private function payoutCandidatesPreviewReport($command)
	{
		$report = $this->baseReport($command);
		$threshold = $this->payoutThreshold();
		$report['summary']['threshold'] = $threshold;
		$report['items']['candidates'] = $this->payoutCandidates($threshold['minimum_payout']);
		$report['summary']['candidate_count'] = count($report['items']['candidates']);
		$report['summary']['projected_total_payout_amount'] = $this->sumColumn($report['items']['candidates'], 'projected_amount');
		$report['warnings'] = $this->warnings;
		return $report;
	}

	private function safetyScanReport($command)
	{
		$report = $this->baseReport($command);
		$report['summary']['pending_blocks'] = $this->pendingBlocksSummary();
		$report['summary']['unsettled_earnings'] = $this->earningsCandidates();
		$report['summary']['positive_accounts'] = $this->accountsSummary();
		$report['summary']['failed_or_empty_tx_payouts'] = $this->failedPayoutsSummary();
		$report['summary']['withdraws'] = $this->withdrawsSummary();
		$report['summary']['service_state'] = array(
			'checked' => false,
			'message' => 'Repository preview command does not prove production service/process state. L3 must verify backend services remain frozen before any live restoration work.',
		);
		$report['warnings'][] = 'Normal backend/payment loops remain unsafe to run until guardrails and execute-mode approvals are implemented separately.';
		$report['warnings'] = array_merge($report['warnings'], $this->warnings);
		return $report;
	}

	private function baseReport($command)
	{
		return array(
			'schema' => 'badpool.guardrail.preview.v1',
			'generated_at' => gmdate('c'),
			'command' => $command,
			'mode' => 'read-only-preview',
			'read_only' => true,
			'wallet_reads' => false,
			'wallet_sends' => false,
			'db_mutations' => false,
			'share_deletion' => false,
			'payout_retry_delete' => false,
			'service_actions' => false,
			'scope' => array(
				'all_coins_preview' => $this->scope['all_coins_preview'],
				'coin_id' => $this->scope['coin_id'],
				'coin' => $this->scope['coin'],
			),
			'summary' => array(),
			'items' => array(),
			'warnings' => array(),
			'blocked_actions' => array(
				'wallet_reads',
				'wallet_sends',
				'database_mutations',
				'share_deletion',
				'payout_row_creation',
				'withdraw_creation',
				'account_balance_mutation',
				'earnings_mutation',
				'block_mutation',
				'coin_mutation',
				'payout_retry_delete',
				'service_or_cron_changes',
			),
		);
	}

	private function coinRowsSummary()
	{
		$where = $this->coinWhere('coins', 'id');
		$sql = "SELECT ".$this->selectColumns('coins', array(
			'id', 'symbol', 'symbol2', 'name', 'algo', 'enable', 'installed', 'visible', 'auto_ready',
			'rpcencoding', 'txfee', 'payout_min', 'balance', 'available', 'cleared', 'immature',
			'mature_blocks', 'block_height', 'target_height',
		))." FROM coins WHERE ".$where['sql']." ORDER BY id";
		return $this->selectAll($sql, $where['params']);
	}

	private function accountsSummary()
	{
		if (!$this->tableExists('accounts')) {
			return $this->missingTable('accounts');
		}

		$where = $this->coinWhere('accounts', 'coinid');
		$parts = array(
			'COUNT(*) AS account_count',
		);
		if ($this->columnExists('accounts', 'balance')) {
			$parts[] = 'SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END) AS positive_balance_accounts';
			$parts[] = 'SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END) AS positive_balance_sum';
			$parts[] = 'MAX(balance) AS max_balance';
		}
		if ($this->columnExists('accounts', 'last_earning')) {
			$parts[] = 'MAX(last_earning) AS latest_last_earning';
		}

		return $this->selectRow("SELECT ".implode(', ', $parts)." FROM accounts WHERE ".$where['sql'], $where['params']);
	}

	private function payoutsSummary()
	{
		if (!$this->tableExists('payouts')) {
			return $this->missingTable('payouts');
		}

		$where = $this->payoutWhere('P');
		$completed = $this->columnExists('payouts', 'completed') ? 'P.completed' : 'NULL';
		$txState = $this->columnExists('payouts', 'tx') ? "CASE WHEN IFNULL(P.tx, '') = '' THEN 'empty_tx' ELSE 'has_tx' END" : "'tx_column_missing'";
		$amount = $this->columnExists('payouts', 'amount') ? 'SUM(P.amount)' : 'NULL';

		$sql = "SELECT $completed AS completed, $txState AS tx_state, COUNT(*) AS count, $amount AS amount_sum ".
			"FROM payouts P ".$where['join']." WHERE ".$where['sql']." GROUP BY completed, tx_state ORDER BY completed, tx_state";
		return $this->selectAll($sql, $where['params']);
	}

	private function withdrawsSummary()
	{
		if (!$this->tableExists('withdraws')) {
			return $this->missingTable('withdraws');
		}

		$parts = array('COUNT(*) AS withdraw_count');
		if ($this->columnExists('withdraws', 'amount')) {
			$parts[] = 'SUM(amount) AS amount_sum';
			$parts[] = 'MAX(amount) AS max_amount';
		}
		if ($this->columnExists('withdraws', 'time')) {
			$parts[] = 'MIN(time) AS first_time';
			$parts[] = 'MAX(time) AS last_time';
		}
		if ($this->columnExists('withdraws', 'market')) {
			$rows = $this->selectAll("SELECT market, ".implode(', ', $parts)." FROM withdraws GROUP BY market ORDER BY market");
			return array('note' => 'withdraws table is not coin-scoped in this repository schema', 'by_market' => $rows);
		}

		return array(
			'note' => 'withdraws table is not coin-scoped in this repository schema',
			'totals' => $this->selectRow("SELECT ".implode(', ', $parts)." FROM withdraws"),
		);
	}

	private function sharesSummary()
	{
		if (!$this->tableExists('shares')) {
			return $this->missingTable('shares');
		}

		$where = array('sql' => '1=1', 'params' => array(), 'filter' => 'all shares');
		if (!$this->scope['all_coins_preview'] && $this->columnExists('shares', 'coinid')) {
			$where = $this->coinWhere('shares', 'coinid');
		} elseif (!$this->scope['all_coins_preview'] && isset($this->scope['coin']['algo']) && $this->columnExists('shares', 'algo')) {
			$where = array('sql' => 'algo=:algo', 'params' => array(':algo' => $this->scope['coin']['algo']), 'filter' => 'algo');
		} elseif (!$this->scope['all_coins_preview']) {
			$this->warnings[] = 'shares table has neither coinid nor algo column available for selected coin scoping.';
		}

		$parts = array('COUNT(*) AS share_count');
		if ($this->columnExists('shares', 'id')) {
			$parts[] = 'MAX(id) AS max_id';
			$parts[] = 'MIN(id) AS min_id';
		}
		if ($this->columnExists('shares', 'time')) {
			$parts[] = 'MIN(time) AS first_time';
			$parts[] = 'MAX(time) AS last_time';
		}
		if ($this->columnExists('shares', 'difficulty')) {
			$parts[] = 'SUM(difficulty) AS difficulty_sum';
		}

		$row = $this->selectRow("SELECT ".implode(', ', $parts)." FROM shares WHERE ".$where['sql'], $where['params']);
		$row['filter'] = $where['filter'];
		return $row;
	}

	private function blockTouchCandidates()
	{
		if (!$this->tableExists('blocks')) {
			return $this->missingTable('blocks');
		}
		if (!$this->columnExists('blocks', 'category')) {
			return array('error' => 'blocks.category column is missing');
		}

		$where = $this->coinWhere('blocks', 'coin_id');
		$categoryColumn = $this->qcol('category');
		$candidateWhere = $where['sql']." AND $categoryColumn IN ('new', 'immature', 'stake', 'orphan')";
		return $this->rangeSummary('blocks', $candidateWhere, $where['params'], 'category');
	}

	private function pendingBlocksSummary()
	{
		if (!$this->tableExists('blocks') || !$this->columnExists('blocks', 'category')) {
			return $this->missingTable('blocks');
		}
		$where = $this->coinWhere('blocks', 'coin_id');
		return $this->rangeSummary('blocks', $where['sql']." AND ".$this->qcol('category')." IN ('new', 'immature')", $where['params'], 'category');
	}

	private function earningsCandidates()
	{
		if (!$this->tableExists('earnings') || !$this->columnExists('earnings', 'status')) {
			return $this->missingTable('earnings');
		}
		$where = $this->coinWhere('earnings', 'coinid');
		return $this->rangeSummary('earnings', $where['sql']." AND ".$this->qcol('status')." IN (0, 1)", $where['params'], 'status');
	}

	private function statusOneEarningsSummary()
	{
		if (!$this->tableExists('earnings') || !$this->columnExists('earnings', 'status')) {
			return $this->missingTable('earnings');
		}
		$where = $this->coinWhere('earnings', 'coinid');
		return $this->rangeSummary('earnings', $where['sql']." AND ".$this->qcol('status')."=1", $where['params']);
	}

	private function projectedAccountCredits()
	{
		if (!$this->tableExists('earnings') || !$this->tableExists('accounts') || !$this->tableExists('coins')) {
			return array();
		}
		if (!$this->columnExists('earnings', 'userid') || !$this->columnExists('earnings', 'coinid') || !$this->columnExists('earnings', 'amount') || !$this->columnExists('earnings', 'status')) {
			$this->warnings[] = 'Cannot project account credits because earnings columns are incomplete.';
			return array();
		}
		if (!$this->columnExists('accounts', 'id') || !$this->columnExists('accounts', 'coinid') || !$this->columnExists('accounts', 'balance')) {
			$this->warnings[] = 'Cannot project account credits because accounts id/coinid/balance columns are incomplete.';
			return array();
		}

		$where = $this->coinWhere('E', 'coinid');
		$hasUsername = $this->columnExists('accounts', 'username');
		$hasPrice = $this->columnExists('coins', 'price');
		$username = $hasUsername ? 'A.username' : 'NULL';
		$userCoin = 'A.coinid';
		$coinPrice = $hasPrice ? 'C.price' : 'NULL';
		$refPrice = $hasPrice ? 'R.price' : 'NULL';

		if ($hasPrice) {
			$projected = "SUM(CASE ".
				"WHEN A.coinid=E.coinid THEN E.amount ".
				"WHEN C.price IS NOT NULL AND R.price IS NOT NULL AND R.price > 0 THEN E.amount*C.price/R.price ".
				"ELSE 0 END)";
		} else {
			$projected = "SUM(CASE WHEN A.coinid=E.coinid THEN E.amount ELSE 0 END)";
			$this->warnings[] = 'coins.price column is unavailable; projected credits only include same-coin earnings.';
		}

		$groupBy = array('E.userid', 'A.coinid', 'A.balance');
		if ($hasUsername) {
			$groupBy[] = 'A.username';
		}
		if ($hasPrice) {
			$groupBy[] = 'C.price';
			$groupBy[] = 'R.price';
		}

		$sql = "SELECT E.userid AS account_id, $username AS username, $userCoin AS account_coin_id, ".
			"COUNT(*) AS earning_count, SUM(E.amount) AS earning_amount_sum, A.balance AS current_balance, ".
			"$projected AS projected_credit, ".
			"(A.balance + $projected) AS projected_balance, ".
			"$coinPrice AS earning_coin_price, $refPrice AS account_coin_price ".
			"FROM earnings E INNER JOIN accounts A ON A.id=E.userid ".
			"INNER JOIN coins C ON C.id=E.coinid ".
			"LEFT JOIN coins R ON R.id=A.coinid ".
			"WHERE E.status=1 AND ".$where['sql']." ".
			"GROUP BY ".implode(', ', $groupBy)." ".
			"ORDER BY projected_credit DESC LIMIT 100";

		$rows = $this->selectAll($sql, $where['params']);
		foreach ($rows as &$row) {
			$row['username_fingerprint'] = $this->fingerprint(arraySafeVal($row, 'username'));
			unset($row['username']);
		}
		return $rows;
	}

	private function payoutThreshold()
	{
		$coin = $this->scope['coin'];
		$mini = defined('YAAMP_PAYMENTS_MINI') ? floatval(YAAMP_PAYMENTS_MINI) : null;
		$payoutMin = $coin && isset($coin['payout_min']) ? floatval($coin['payout_min']) : null;
		$txFee = $coin && isset($coin['txfee']) ? floatval($coin['txfee']) : null;
		$values = array();
		foreach (array($mini, $payoutMin, $txFee) as $value) {
			if ($value !== null) {
				$values[] = $value;
			}
		}

		return array(
			'minimum_payout' => empty($values) ? 0 : max($values),
			'YAAMP_PAYMENTS_MINI' => $mini,
			'coin_payout_min' => $payoutMin,
			'coin_txfee' => $txFee,
			'note' => 'Wallet balance and network fee checks are not performed in read-only preview mode.',
		);
	}

	private function payoutCandidates($minimum)
	{
		if (!$this->tableExists('accounts') || !$this->columnExists('accounts', 'id') || !$this->columnExists('accounts', 'coinid') || !$this->columnExists('accounts', 'balance')) {
			$this->warnings[] = 'Cannot project payout candidates because accounts id/coinid/balance columns are incomplete.';
			return array();
		}

		$where = $this->coinWhere('accounts', 'coinid');
		$username = $this->columnExists('accounts', 'username') ? 'username' : 'NULL AS username';
		$sql = "SELECT id AS account_id, $username, coinid AS account_coin_id, balance AS projected_amount ".
			"FROM accounts WHERE ".$where['sql']." AND balance>:minimum ORDER BY balance DESC LIMIT 200";
		$params = $where['params'];
		$params[':minimum'] = $minimum;
		$rows = $this->selectAll($sql, $params);

		foreach ($rows as &$row) {
			$row['username_fingerprint'] = $this->fingerprint(arraySafeVal($row, 'username'));
			$row['threshold'] = $minimum;
			unset($row['username']);
		}
		return $rows;
	}

	private function failedPayoutsSummary()
	{
		if (!$this->tableExists('payouts')) {
			return $this->missingTable('payouts');
		}
		if (!$this->columnExists('payouts', 'tx')) {
			return array('error' => 'payouts.tx column is missing');
		}

		$where = $this->payoutWhere('P');
		$amount = $this->columnExists('payouts', 'amount') ? 'SUM(P.amount)' : 'NULL';
		$completed = $this->columnExists('payouts', 'completed') ? 'P.completed' : 'NULL';
		$sql = "SELECT $completed AS completed, COUNT(*) AS count, $amount AS amount_sum, MIN(P.id) AS min_id, MAX(P.id) AS max_id ".
			"FROM payouts P ".$where['join']." WHERE ".$where['sql']." AND IFNULL(P.tx, '') = '' GROUP BY completed ORDER BY completed";
		return $this->selectAll($sql, $where['params']);
	}

	private function groupSummary($table, $groupColumn, $where)
	{
		if (!$this->tableExists($table)) {
			return $this->missingTable($table);
		}
		if (!$this->columnExists($table, $groupColumn)) {
			return array('error' => "$table.$groupColumn column is missing");
		}

		return $this->rangeSummary($table, $where['sql'], $where['params'], $groupColumn);
	}

	private function rangeSummary($table, $whereSql, $params=array(), $groupColumn=null)
	{
		$parts = array();
		if ($groupColumn !== null) {
			$parts[] = $this->qcol($groupColumn).' AS group_value';
		}
		$parts[] = 'COUNT(*) AS count';
		foreach (array('amount', 'balance') as $sumColumn) {
			if ($this->columnExists($table, $sumColumn)) {
				$parts[] = 'SUM('.$this->qcol($sumColumn).') AS '.$sumColumn.'_sum';
			}
		}
		foreach (array('id', 'height', 'time', 'create_time', 'mature_time') as $rangeColumn) {
			if ($this->columnExists($table, $rangeColumn)) {
				$parts[] = 'MIN('.$this->qcol($rangeColumn).') AS min_'.$rangeColumn;
				$parts[] = 'MAX('.$this->qcol($rangeColumn).') AS max_'.$rangeColumn;
			}
		}

		$sql = 'SELECT '.implode(', ', $parts).' FROM '.$this->qtable($table).' WHERE '.$whereSql;
		if ($groupColumn !== null) {
			$sql .= ' GROUP BY '.$this->qcol($groupColumn).' ORDER BY '.$this->qcol($groupColumn);
		}

		return $this->selectAll($sql, $params);
	}

	private function coinWhere($tableOrAlias, $column)
	{
		if ($this->scope['all_coins_preview']) {
			return array('sql' => '1=1', 'params' => array(), 'filter' => 'all_coins_preview');
		}

		$prefix = preg_match('/^[A-Z]$/', $tableOrAlias) ? $tableOrAlias.'.' : '';
		return array(
			'sql' => $prefix.$this->qcol($column).'=:coin_id',
			'params' => array(':coin_id' => $this->scope['coin_id']),
			'filter' => 'coin_id',
		);
	}

	private function payoutWhere($alias)
	{
		if ($this->scope['all_coins_preview']) {
			return array('join' => '', 'sql' => '1=1', 'params' => array());
		}
		if ($this->columnExists('payouts', 'idcoin')) {
			return array('join' => '', 'sql' => $alias.'.'.$this->qcol('idcoin').'=:coin_id', 'params' => array(':coin_id' => $this->scope['coin_id']));
		}
		if ($this->tableExists('accounts') && $this->columnExists('accounts', 'id') && $this->columnExists('accounts', 'coinid') && $this->columnExists('payouts', 'account_id')) {
			return array(
				'join' => 'INNER JOIN accounts A ON A.id='.$alias.'.'.$this->qcol('account_id'),
				'sql' => 'A.'.$this->qcol('coinid').'=:coin_id',
				'params' => array(':coin_id' => $this->scope['coin_id']),
			);
		}

		$this->warnings[] = 'Cannot coin-scope payouts because neither payouts.idcoin nor accounts.coinid is available.';
		return array('join' => '', 'sql' => '1=0', 'params' => array());
	}

	private function selectColumns($table, $columns)
	{
		$selected = array();
		foreach ($columns as $column) {
			if ($this->columnExists($table, $column)) {
				$selected[] = $this->qcol($column);
			}
		}
		if (empty($selected)) {
			return '1 AS no_safe_columns_available';
		}
		return implode(', ', $selected);
	}

	private function selectAll($sql, $params=array())
	{
		$this->assertSelectOnly($sql);
		$command = app()->db->createCommand($sql);
		foreach ($params as $name => $value) {
			$command->bindValue($name, $value);
		}
		return $command->queryAll();
	}

	private function selectRow($sql, $params=array())
	{
		$this->assertSelectOnly($sql);
		$command = app()->db->createCommand($sql);
		foreach ($params as $name => $value) {
			$command->bindValue($name, $value);
		}
		return $command->queryRow();
	}

	private function assertSelectOnly($sql)
	{
		$trimmed = ltrim($sql);
		if (stripos($trimmed, 'SELECT ') !== 0) {
			throw new CException('BadpoolGuardCommand refused non-SELECT SQL.');
		}
	}

	private function tableExists($table)
	{
		return app()->db->schema->getTable($table, true) !== null;
	}

	private function columnExists($table, $column)
	{
		$schema = app()->db->schema->getTable($table, true);
		return $schema !== null && isset($schema->columns[$column]);
	}

	private function qtable($table)
	{
		return '`'.str_replace('`', '``', $table).'`';
	}

	private function qcol($column)
	{
		return '`'.str_replace('`', '``', $column).'`';
	}

	private function missingTable($table)
	{
		$this->warnings[] = "Table '$table' is not available in this database/schema.";
		return array('error' => "missing table: $table");
	}

	private function sumColumn($rows, $column)
	{
		$total = 0.0;
		foreach ($rows as $row) {
			$total += floatval(arraySafeVal($row, $column, 0));
		}
		return $total;
	}

	private function fingerprint($value)
	{
		if ($value === null || $value === '') {
			return null;
		}
		return substr(hash('sha256', $value), 0, 16);
	}

	private function emitReport($report)
	{
		if ($this->format == 'text') {
			$this->emitText($report);
			return;
		}
		echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
	}

	private function emitText($value, $indent=0)
	{
		$prefix = str_repeat('  ', $indent);
		if (!is_array($value)) {
			echo $prefix.$value."\n";
			return;
		}
		foreach ($value as $key => $item) {
			if (is_array($item)) {
				echo $prefix.$key.":\n";
				$this->emitText($item, $indent + 1);
			} else {
				echo $prefix.$key.": ".$item."\n";
			}
		}
	}

	private function refuse($message)
	{
		$report = array(
			'schema' => 'badpool.guardrail.preview.v1',
			'generated_at' => gmdate('c'),
			'status' => 'refused',
			'read_only' => true,
			'message' => $message,
		);
		$this->emitReport($report);
		return 2;
	}
}
