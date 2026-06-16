<?php

require_once(dirname(__FILE__).'/../core/backend/BadpoolGuardContext.php');

class BadpoolGuardCommand extends CConsoleCommand
{
	private $guard;

	private $actions = array(
		'overview',
		'blocks-preview',
		'earnings-preview',
		'account-credit-preview',
		'payout-candidates-preview',
		'safety-scan',
		'guard-context',
	);

	public function run($args)
	{
		if (empty($args) || arraySafeVal($args, 0) == 'help') {
			echo $this->getHelp();
			return 1;
		}

		$action = array_shift($args);
		if (!in_array($action, $this->actions, true)) {
			$this->guard = new BadpoolGuardContext($action);
			$this->guard->addError("Unknown badpoolguard action: $action");
			$this->guard->emit($this->guard->refusalReport());
			return 2;
		}

		$this->guard = BadpoolGuardContext::fromArgs($action, $args);
		if (!$this->guard->isValid()) {
			$this->guard->emit($this->guard->refusalReport());
			return 2;
		}

		switch ($action) {
			case 'overview':
				$report = $this->overviewReport();
				break;
			case 'blocks-preview':
				$report = $this->blocksPreviewReport();
				break;
			case 'earnings-preview':
				$report = $this->earningsPreviewReport();
				break;
			case 'account-credit-preview':
				$report = $this->accountCreditPreviewReport();
				break;
			case 'payout-candidates-preview':
				$report = $this->payoutCandidatesPreviewReport();
				break;
			case 'safety-scan':
				$report = $this->safetyScanReport();
				break;
			case 'guard-context':
				$report = $this->guardContextReport();
				break;
			default:
				$this->guard->addError("Unhandled action: $action");
				$report = $this->guard->refusalReport();
		}

		$this->guard->emit($report);
		return $this->guard->isValid() ? 0 : 2;
	}

	public function getHelp()
	{
		return "Yiimp badpoolguard command\n".
			"Run from the web directory, for example: cd web\n".
			"Usage: php yaamp/yiic.php badpoolguard overview --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard blocks-preview --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard earnings-preview --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard account-credit-preview --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard payout-candidates-preview --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard safety-scan --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard guard-context --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard overview --all-coins-preview [--format=json|text]\n\n".
			"Read-only preview only. No wallet reads, wallet sends, DB writes, share cleanup, payout retry/delete, or service actions are available.\n";
	}

	private function overviewReport()
	{
		$report = $this->guard->baseReport();
		$report['summary']['coins'] = $this->coinRowsSummary();
		$report['summary']['blocks_by_category'] = $this->groupSummary('blocks', 'category', $this->guard->coinWhere('blocks', 'coin_id'));
		$report['summary']['earnings_by_status'] = $this->groupSummary('earnings', 'status', $this->guard->coinWhere('earnings', 'coinid'));
		$report['summary']['accounts'] = $this->accountsSummary();
		$report['summary']['payouts'] = $this->payoutsSummary();
		$report['summary']['withdraws'] = $this->withdrawsSummary();
		$report['summary']['shares'] = $this->sharesSummary();
		$report['summary']['share_delete_guard'] = $this->shareDeleteGuardSummary();
		return $this->guard->finalizeReport($report);
	}

	private function blocksPreviewReport()
	{
		$report = $this->guard->baseReport();
		$where = $this->guard->coinWhere('blocks', 'coin_id');
		$report['summary']['blocks_by_category'] = $this->groupSummary('blocks', 'category', $where);
		$report['summary']['backend_touch_candidates'] = $this->blockTouchCandidates();
		$report['summary']['share_delete_guard'] = $this->shareDeleteGuardSummary();
		return $this->guard->finalizeReport($report);
	}

	private function earningsPreviewReport()
	{
		$report = $this->guard->baseReport();
		$where = $this->guard->coinWhere('earnings', 'coinid');
		$report['summary']['earnings_by_status'] = $this->groupSummary('earnings', 'status', $where);
		$report['summary']['unsettled_candidates'] = $this->earningsCandidates();
		return $this->guard->finalizeReport($report);
	}

	private function accountCreditPreviewReport()
	{
		$report = $this->guard->baseReport();
		$report['summary']['status_1_earnings'] = $this->statusOneEarningsSummary();
		$report['items']['projected_account_credits'] = $this->projectedAccountCredits();
		return $this->guard->finalizeReport($report);
	}

	private function payoutCandidatesPreviewReport()
	{
		$report = $this->guard->baseReport();
		$candidates = $this->buildReadOnlyPayoutCandidates();
		$report['summary']['threshold'] = $this->payoutThreshold();
		$report['summary']['execution_blocked'] = $this->payoutExecutionBlockedMetadata();
		$report['summary']['preview_limit'] = 200;
		$report['items']['candidates'] = $candidates;
		$report['summary']['candidate_count'] = count($report['items']['candidates']);
		$report['summary']['projected_total_payout_amount'] = $this->sumColumn($report['items']['candidates'], 'projected_payout_amount');
		$report['summary']['projected_total_remaining_balance'] = $this->sumColumn($report['items']['candidates'], 'projected_remaining_balance');
		$report['summary']['audit'] = $this->payoutPreviewAuditSummary($report);
		return $this->guard->finalizeReport($report);
	}

	private function safetyScanReport()
	{
		$report = $this->guard->baseReport();
		$report['summary']['pending_blocks'] = $this->pendingBlocksSummary();
		$report['summary']['unsettled_earnings'] = $this->earningsCandidates();
		$report['summary']['positive_accounts'] = $this->accountsSummary();
		$report['summary']['failed_or_empty_tx_payouts'] = $this->failedPayoutsSummary();
		$report['summary']['withdraws'] = $this->withdrawsSummary();
		$report['summary']['share_delete_guard'] = $this->shareDeleteGuardSummary();
		$report['summary']['service_state'] = array(
			'checked' => false,
			'message' => 'Repository preview command does not prove production service/process state. L3 must verify backend services remain frozen before any live restoration work.',
		);
		$this->guard->addWarning('Normal backend/payment loops remain unsafe to run until guardrails and execute-mode approvals are implemented separately.');
		return $this->guard->finalizeReport($report);
	}

	private function guardContextReport()
	{
		$report = $this->guard->baseReport();
		$report['summary']['parsed_scope'] = $this->guard->getScope();
		$report['summary']['format'] = $this->guard->getFormat();
		$report['summary']['note'] = 'Guard context only; no preview queries beyond coin scope validation.';
		return $this->guard->finalizeReport($report);
	}

	private function coinRowsSummary()
	{
		$where = $this->guard->coinWhere('coins', 'id');
		$sql = "SELECT ".$this->guard->selectColumns('coins', array(
			'id', 'symbol', 'symbol2', 'name', 'algo', 'enable', 'installed', 'visible', 'auto_ready',
			'rpcencoding', 'txfee', 'payout_min', 'balance', 'available', 'cleared', 'immature',
			'mature_blocks', 'block_height', 'target_height',
		))." FROM coins WHERE ".$where['sql']." ORDER BY id";
		return $this->guard->selectAll($sql, $where['params']);
	}

	private function accountsSummary()
	{
		if (!$this->guard->tableExists('accounts')) {
			return $this->guard->missingTable('accounts');
		}

		$where = $this->guard->coinWhere('accounts', 'coinid');
		$parts = array('COUNT(*) AS account_count');
		if ($this->guard->columnExists('accounts', 'balance')) {
			$parts[] = 'SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END) AS positive_balance_accounts';
			$parts[] = 'SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END) AS positive_balance_sum';
			$parts[] = 'MAX(balance) AS max_balance';
		}
		if ($this->guard->columnExists('accounts', 'last_earning')) {
			$parts[] = 'MAX(last_earning) AS latest_last_earning';
		}

		return $this->guard->selectRow("SELECT ".implode(', ', $parts)." FROM accounts WHERE ".$where['sql'], $where['params']);
	}

	private function payoutsSummary()
	{
		if (!$this->guard->tableExists('payouts')) {
			return $this->guard->missingTable('payouts');
		}

		$where = $this->payoutWhere('P');
		$completed = $this->guard->columnExists('payouts', 'completed') ? 'P.completed' : 'NULL';
		$txState = $this->guard->columnExists('payouts', 'tx') ? "CASE WHEN IFNULL(P.tx, '') = '' THEN 'empty_tx' ELSE 'has_tx' END" : "'tx_column_missing'";
		$amount = $this->guard->columnExists('payouts', 'amount') ? 'SUM(P.amount)' : 'NULL';

		$sql = "SELECT $completed AS completed, $txState AS tx_state, COUNT(*) AS count, $amount AS amount_sum ".
			"FROM payouts P ".$where['join']." WHERE ".$where['sql']." GROUP BY completed, tx_state ORDER BY completed, tx_state";
		return $this->guard->selectAll($sql, $where['params']);
	}

	private function withdrawsSummary()
	{
		if (!$this->guard->tableExists('withdraws')) {
			return $this->guard->missingTable('withdraws');
		}

		$parts = array('COUNT(*) AS withdraw_count');
		if ($this->guard->columnExists('withdraws', 'amount')) {
			$parts[] = 'SUM(amount) AS amount_sum';
			$parts[] = 'MAX(amount) AS max_amount';
		}
		if ($this->guard->columnExists('withdraws', 'time')) {
			$parts[] = 'MIN(time) AS first_time';
			$parts[] = 'MAX(time) AS last_time';
		}
		if ($this->guard->columnExists('withdraws', 'market')) {
			$rows = $this->guard->selectAll("SELECT market, ".implode(', ', $parts)." FROM withdraws GROUP BY market ORDER BY market");
			return array('note' => 'withdraws table is not coin-scoped in this repository schema', 'by_market' => $rows);
		}

		return array(
			'note' => 'withdraws table is not coin-scoped in this repository schema',
			'totals' => $this->guard->selectRow("SELECT ".implode(', ', $parts)." FROM withdraws"),
		);
	}

	private function sharesSummary()
	{
		if (!$this->guard->tableExists('shares')) {
			return $this->guard->missingTable('shares');
		}

		$scope = $this->guard->getScope();
		$where = array('sql' => '1=1', 'params' => array(), 'filter' => 'all shares');
		if (!$scope['all_coins_preview'] && $this->guard->columnExists('shares', 'coinid')) {
			$where = $this->guard->coinWhere('shares', 'coinid');
		} elseif (!$scope['all_coins_preview'] && isset($scope['coin']['algo']) && $this->guard->columnExists('shares', 'algo')) {
			$where = array('sql' => 'algo=:algo', 'params' => array(':algo' => $scope['coin']['algo']), 'filter' => 'algo');
		} elseif (!$scope['all_coins_preview']) {
			$this->guard->addWarning('shares table has neither coinid nor algo column available for selected coin scoping.');
		}

		$parts = array('COUNT(*) AS share_count');
		if ($this->guard->columnExists('shares', 'id')) {
			$parts[] = 'MAX(id) AS max_id';
			$parts[] = 'MIN(id) AS min_id';
		}
		if ($this->guard->columnExists('shares', 'time')) {
			$parts[] = 'MIN(time) AS first_time';
			$parts[] = 'MAX(time) AS last_time';
		}
		if ($this->guard->columnExists('shares', 'difficulty')) {
			$parts[] = 'SUM(difficulty) AS difficulty_sum';
		}

		$row = $this->guard->selectRow("SELECT ".implode(', ', $parts)." FROM shares WHERE ".$where['sql'], $where['params']);
		$row['filter'] = $where['filter'];
		return $row;
	}

	private function shareDeleteGuardSummary()
	{
		return array(
			'guarded' => true,
			'deletion_available' => false,
			'message' => 'Share deletion is guarded at the source level and remains disabled by default.',
			'candidate_preview' => $this->sharesSummary(),
		);
	}

	private function blockTouchCandidates()
	{
		if (!$this->guard->tableExists('blocks')) {
			return $this->guard->missingTable('blocks');
		}
		if (!$this->guard->columnExists('blocks', 'category')) {
			return array('error' => 'blocks.category column is missing');
		}

		$where = $this->guard->coinWhere('blocks', 'coin_id');
		$categoryColumn = $this->guard->qcol('category');
		$candidateWhere = $where['sql']." AND $categoryColumn IN ('new', 'immature', 'stake', 'orphan')";
		return $this->rangeSummary('blocks', $candidateWhere, $where['params'], 'category');
	}

	private function pendingBlocksSummary()
	{
		if (!$this->guard->tableExists('blocks') || !$this->guard->columnExists('blocks', 'category')) {
			return $this->guard->missingTable('blocks');
		}
		$where = $this->guard->coinWhere('blocks', 'coin_id');
		return $this->rangeSummary('blocks', $where['sql']." AND ".$this->guard->qcol('category')." IN ('new', 'immature')", $where['params'], 'category');
	}

	private function earningsCandidates()
	{
		if (!$this->guard->tableExists('earnings') || !$this->guard->columnExists('earnings', 'status')) {
			return $this->guard->missingTable('earnings');
		}
		$where = $this->guard->coinWhere('earnings', 'coinid');
		return $this->rangeSummary('earnings', $where['sql']." AND ".$this->guard->qcol('status')." IN (0, 1)", $where['params'], 'status');
	}

	private function statusOneEarningsSummary()
	{
		if (!$this->guard->tableExists('earnings') || !$this->guard->columnExists('earnings', 'status')) {
			return $this->guard->missingTable('earnings');
		}
		$where = $this->guard->coinWhere('earnings', 'coinid');
		return $this->rangeSummary('earnings', $where['sql']." AND ".$this->guard->qcol('status')."=1", $where['params']);
	}

	private function projectedAccountCredits()
	{
		if (!$this->guard->tableExists('earnings') || !$this->guard->tableExists('accounts') || !$this->guard->tableExists('coins')) {
			return array();
		}
		if (!$this->guard->columnExists('earnings', 'userid') || !$this->guard->columnExists('earnings', 'coinid') || !$this->guard->columnExists('earnings', 'amount') || !$this->guard->columnExists('earnings', 'status')) {
			$this->guard->addWarning('Cannot project account credits because earnings columns are incomplete.');
			return array();
		}
		if (!$this->guard->columnExists('accounts', 'id') || !$this->guard->columnExists('accounts', 'coinid') || !$this->guard->columnExists('accounts', 'balance')) {
			$this->guard->addWarning('Cannot project account credits because accounts id/coinid/balance columns are incomplete.');
			return array();
		}

		$where = $this->guard->coinWhere('E', 'coinid');
		$hasUsername = $this->guard->columnExists('accounts', 'username');
		$hasPrice = $this->guard->columnExists('coins', 'price');
		$username = $hasUsername ? 'A.username' : 'NULL';
		$coinPrice = $hasPrice ? 'C.price' : 'NULL';
		$refPrice = $hasPrice ? 'R.price' : 'NULL';

		if ($hasPrice) {
			$projected = "SUM(CASE ".
				"WHEN A.coinid=E.coinid THEN E.amount ".
				"WHEN C.price IS NOT NULL AND R.price IS NOT NULL AND R.price > 0 THEN E.amount*C.price/R.price ".
				"ELSE 0 END)";
		} else {
			$projected = "SUM(CASE WHEN A.coinid=E.coinid THEN E.amount ELSE 0 END)";
			$this->guard->addWarning('coins.price column is unavailable; projected credits only include same-coin earnings.');
		}

		$groupBy = array('E.userid', 'A.coinid', 'A.balance');
		if ($hasUsername) {
			$groupBy[] = 'A.username';
		}
		if ($hasPrice) {
			$groupBy[] = 'C.price';
			$groupBy[] = 'R.price';
		}

		$sql = "SELECT E.userid AS account_id, $username AS username, A.coinid AS account_coin_id, ".
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

		$rows = $this->guard->selectAll($sql, $where['params']);
		foreach ($rows as &$row) {
			$row['username_fingerprint'] = $this->fingerprint(arraySafeVal($row, 'username'));
			unset($row['username']);
		}
		return $rows;
	}

	private function payoutThreshold()
	{
		$coin = $this->guard->getCoin();
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

	private function buildReadOnlyPayoutCandidates()
	{
		if (!$this->guard->tableExists('accounts') || !$this->guard->tableExists('coins')) {
			$this->guard->addWarning('Cannot project payout candidates because accounts or coins table is unavailable.');
			return array();
		}
		if (!$this->guard->columnExists('accounts', 'id') || !$this->guard->columnExists('accounts', 'coinid') || !$this->guard->columnExists('accounts', 'balance')) {
			$this->guard->addWarning('Cannot project payout candidates because accounts id/coinid/balance columns are incomplete.');
			return array();
		}
		if (!$this->guard->columnExists('coins', 'id')) {
			$this->guard->addWarning('Cannot project payout candidates because coins.id column is unavailable.');
			return array();
		}

		$where = $this->guard->coinWhere('A', 'coinid');
		$username = $this->guard->columnExists('accounts', 'username') ? 'A.username' : 'NULL';
		$coinSymbol = $this->guard->columnExists('coins', 'symbol') ? 'C.symbol' : 'NULL';
		$coinAlgo = $this->guard->columnExists('coins', 'algo') ? 'C.algo' : 'NULL';
		$payoutMinExpr = $this->guard->columnExists('coins', 'payout_min') ? 'IFNULL(C.payout_min, 0)' : '0';
		$txFeeExpr = $this->guard->columnExists('coins', 'txfee') ? 'IFNULL(C.txfee, 0)' : '0';
		$paymentsMinimum = sprintf('%.12f', defined('YAAMP_PAYMENTS_MINI') ? floatval(YAAMP_PAYMENTS_MINI) : 0.0);
		$thresholdExpr = "GREATEST($paymentsMinimum, $payoutMinExpr, $txFeeExpr)";

		$sql = "SELECT A.id AS account_id, $username AS username, A.coinid AS coin_id, ".
			"$coinSymbol AS coin_symbol, $coinAlgo AS coin_algo, ".
			"A.balance AS current_balance, $payoutMinExpr AS payout_min, $txFeeExpr AS txfee, ".
			"$thresholdExpr AS threshold, A.balance AS projected_payout_amount, ".
			"0 AS projected_remaining_balance, ".
			"CASE WHEN A.balance > $thresholdExpr THEN 1 ELSE 0 END AS above_threshold ".
			"FROM accounts A INNER JOIN coins C ON C.id=A.coinid ".
			"WHERE ".$where['sql']." AND A.balance > $thresholdExpr ".
			"ORDER BY A.balance DESC LIMIT 200";
		$params = $where['params'];
		$rows = $this->guard->selectAll($sql, $params);
		$blocked = $this->payoutExecutionBlockedMetadata();

		foreach ($rows as &$row) {
			$row['username_fingerprint'] = $this->fingerprint(arraySafeVal($row, 'username'));
			$row['above_threshold'] = intval(arraySafeVal($row, 'above_threshold', 0)) === 1;
			$row['blocked_actions'] = $blocked['blocked_actions'];
			$row['preview_note'] = 'Candidate only; no payout row, account debit, wallet RPC call, or send is performed.';
			unset($row['username']);
		}
		return $rows;
	}

	private function payoutExecutionBlockedMetadata()
	{
		return array(
			'status' => 'blocked',
			'blocked_actions' => array(
				'payout_row_creation',
				'account_debit',
				'wallet_rpc_read',
				'wallet_send',
				'payout_retry_delete',
			),
			'wallet_rpc_used' => false,
			'message' => 'Read-only payout preview only. Execution requires a separate approved task.',
		);
	}

	private function payoutPreviewAuditSummary($report)
	{
		$scope = $this->guard->getScope();
		$coin = arraySafeVal($scope, 'coin', array());
		return array(
			'command' => arraySafeVal($report, 'command'),
			'coin_id' => arraySafeVal($scope, 'coin_id'),
			'coin_symbol' => arraySafeVal($coin, 'symbol'),
			'coin_algo' => arraySafeVal($coin, 'algo'),
			'candidate_count' => arraySafeVal($report['summary'], 'candidate_count', 0),
			'projected_total_payout_amount' => arraySafeVal($report['summary'], 'projected_total_payout_amount', 0),
			'blocked_actions' => arraySafeVal($report['summary']['execution_blocked'], 'blocked_actions', array()),
			'checksum_note' => 'See top-level report_checksum; generated_at is excluded from checksum input.',
		);
	}

	private function failedPayoutsSummary()
	{
		if (!$this->guard->tableExists('payouts')) {
			return $this->guard->missingTable('payouts');
		}
		if (!$this->guard->columnExists('payouts', 'tx')) {
			return array('error' => 'payouts.tx column is missing');
		}

		$where = $this->payoutWhere('P');
		$amount = $this->guard->columnExists('payouts', 'amount') ? 'SUM(P.amount)' : 'NULL';
		$completed = $this->guard->columnExists('payouts', 'completed') ? 'P.completed' : 'NULL';
		$sql = "SELECT $completed AS completed, COUNT(*) AS count, $amount AS amount_sum, MIN(P.id) AS min_id, MAX(P.id) AS max_id ".
			"FROM payouts P ".$where['join']." WHERE ".$where['sql']." AND IFNULL(P.tx, '') = '' GROUP BY completed ORDER BY completed";
		return $this->guard->selectAll($sql, $where['params']);
	}

	private function groupSummary($table, $groupColumn, $where)
	{
		if (!$this->guard->tableExists($table)) {
			return $this->guard->missingTable($table);
		}
		if (!$this->guard->columnExists($table, $groupColumn)) {
			return array('error' => "$table.$groupColumn column is missing");
		}

		return $this->rangeSummary($table, $where['sql'], $where['params'], $groupColumn);
	}

	private function rangeSummary($table, $whereSql, $params=array(), $groupColumn=null)
	{
		$parts = array();
		if ($groupColumn !== null) {
			$parts[] = $this->guard->qcol($groupColumn).' AS group_value';
		}
		$parts[] = 'COUNT(*) AS count';
		foreach (array('amount', 'balance') as $sumColumn) {
			if ($this->guard->columnExists($table, $sumColumn)) {
				$parts[] = 'SUM('.$this->guard->qcol($sumColumn).') AS '.$sumColumn.'_sum';
			}
		}
		foreach (array('id', 'height', 'time', 'create_time', 'mature_time') as $rangeColumn) {
			if ($this->guard->columnExists($table, $rangeColumn)) {
				$parts[] = 'MIN('.$this->guard->qcol($rangeColumn).') AS min_'.$rangeColumn;
				$parts[] = 'MAX('.$this->guard->qcol($rangeColumn).') AS max_'.$rangeColumn;
			}
		}

		$sql = 'SELECT '.implode(', ', $parts).' FROM '.$this->guard->qtable($table).' WHERE '.$whereSql;
		if ($groupColumn !== null) {
			$sql .= ' GROUP BY '.$this->guard->qcol($groupColumn).' ORDER BY '.$this->guard->qcol($groupColumn);
		}

		return $this->guard->selectAll($sql, $params);
	}

	private function payoutWhere($alias)
	{
		if ($this->guard->isAllCoinsPreview()) {
			return array('join' => '', 'sql' => '1=1', 'params' => array());
		}
		$scope = $this->guard->getScope();
		if ($this->guard->columnExists('payouts', 'idcoin')) {
			return array('join' => '', 'sql' => $alias.'.'.$this->guard->qcol('idcoin').'=:coin_id', 'params' => array(':coin_id' => $scope['coin_id']));
		}
		if ($this->guard->tableExists('accounts') && $this->guard->columnExists('accounts', 'id') && $this->guard->columnExists('accounts', 'coinid') && $this->guard->columnExists('payouts', 'account_id')) {
			return array(
				'join' => 'INNER JOIN accounts A ON A.id='.$alias.'.'.$this->guard->qcol('account_id'),
				'sql' => 'A.'.$this->guard->qcol('coinid').'=:coin_id',
				'params' => array(':coin_id' => $scope['coin_id']),
			);
		}

		$this->guard->addWarning('Cannot coin-scope payouts because neither payouts.idcoin nor accounts.coinid is available.');
		return array('join' => '', 'sql' => '1=0', 'params' => array());
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
}
