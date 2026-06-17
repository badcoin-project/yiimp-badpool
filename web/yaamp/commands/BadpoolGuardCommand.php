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
		'payout-row-preflight-preview',
		'payout-row-dryrun-plan',
		'payable-source-reconciliation-preview',
		'account-credit-transition-preview',
		'earnings-credit-readiness-preview',
		'block-category-maturity-preview',
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
			$this->emitFinalReport($this->guard->refusalReport());
			return 2;
		}

		$this->guard = BadpoolGuardContext::fromArgs($action, $args);
		if (!$this->guard->isValid()) {
			$this->emitFinalReport($this->guard->refusalReport());
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
			case 'payout-row-preflight-preview':
				$report = $this->payoutRowPreflightPreviewReport();
				break;
			case 'payout-row-dryrun-plan':
				$report = $this->payoutRowDryRunPlanReport();
				break;
			case 'payable-source-reconciliation-preview':
				$report = $this->payableSourceReconciliationPreviewReport();
				break;
			case 'account-credit-transition-preview':
				$report = $this->accountCreditTransitionPreviewReport();
				break;
			case 'earnings-credit-readiness-preview':
				$report = $this->earningsCreditReadinessPreviewReport();
				break;
			case 'block-category-maturity-preview':
				$report = $this->blockCategoryMaturityPreviewReport();
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

		$this->emitFinalReport($report);
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
			"       php yaamp/yiic.php badpoolguard payout-row-preflight-preview --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard payout-row-dryrun-plan --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard payable-source-reconciliation-preview --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard account-credit-transition-preview --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard earnings-credit-readiness-preview --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard block-category-maturity-preview --coin-id=<id> [--format=json|text]\n".
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
		$report = $this->guard->finalizeReport($report);
		$report = $this->ensurePayoutPreviewAuditFields($report);
		$report['report_checksum'] = BadpoolGuardReport::checksum($report);
		return $report;
	}

	private function payoutRowPreflightPreviewReport()
	{
		if ($this->guard->isAllCoinsPreview()) {
			$this->guard->addError('payout-row-preflight-preview requires --coin-id and refuses all-coin scope.');
			return $this->guard->refusalReport();
		}

		$report = $this->guard->baseReport();
		$candidates = $this->buildReadOnlyPayoutCandidates();
		$threshold = $this->payoutThreshold();
		$candidateCount = count($candidates);
		$projectedTotal = $this->sumColumn($candidates, 'projected_payout_amount');
		$projectedRemaining = $this->sumColumn($candidates, 'projected_remaining_balance');

		$report['summary']['threshold'] = $threshold;
		$report['summary']['execution_blocked'] = $this->payoutRowPreflightBlockedMetadata();
		$report['summary']['preview_limit'] = 200;
		$report['summary']['candidate_count'] = $candidateCount;
		$report['summary']['projected_total_payout_amount'] = $projectedTotal;
		$report['summary']['projected_total_remaining_balance'] = $projectedRemaining;
		$report['summary']['payout_row_preflight'] = $this->payoutRowPreflightSummary($threshold, $candidateCount, $projectedTotal);
		$report['summary']['audit'] = $this->payoutPreviewAuditSummary($report);
		$report['items']['candidate_preview'] = $candidates;

		$report = $this->guard->finalizeReport($report);
		$report = $this->ensurePayoutPreviewAuditFields($report);
		$report['report_checksum'] = BadpoolGuardReport::checksum($report);
		return $report;
	}

	private function payoutRowDryRunPlanReport()
	{
		if ($this->guard->isAllCoinsPreview()) {
			$this->guard->addError('payout-row-dryrun-plan requires --coin-id and refuses all-coin scope.');
			return $this->guard->refusalReport();
		}

		$report = $this->guard->baseReport();
		$candidates = $this->buildReadOnlyPayoutCandidates();
		$threshold = $this->payoutThreshold();
		$candidateCount = count($candidates);
		$projectedTotal = $this->sumColumn($candidates, 'projected_payout_amount');
		$projectedRemaining = $this->sumColumn($candidates, 'projected_remaining_balance');

		$report['summary']['threshold'] = $threshold;
		$report['summary']['execution_blocked'] = $this->payoutRowDryRunBlockedMetadata();
		$report['summary']['preview_limit'] = 200;
		$report['summary']['candidate_count'] = $candidateCount;
		$report['summary']['projected_total_payout_amount'] = $projectedTotal;
		$report['summary']['projected_total_remaining_balance'] = $projectedRemaining;
		$report['summary']['payout_row_dryrun_plan'] = $this->payoutRowDryRunPlanSummary($threshold, $candidateCount, $projectedTotal);
		$report['summary']['audit'] = $this->payoutPreviewAuditSummary($report);
		$report['items']['candidate_preview'] = $candidates;

		$report = $this->guard->finalizeReport($report);
		$report = $this->ensurePayoutPreviewAuditFields($report);
		$report['report_checksum'] = BadpoolGuardReport::checksum($report);
		return $report;
	}

	private function payableSourceReconciliationPreviewReport()
	{
		if ($this->guard->isAllCoinsPreview()) {
			$this->guard->addError('payable-source-reconciliation-preview requires --coin-id and refuses all-coin scope.');
			return $this->guard->refusalReport();
		}

		$report = $this->guard->baseReport();
		$scope = $this->guard->getScope();
		$coin = arraySafeVal($scope, 'coin', array());
		$candidates = $this->buildReadOnlyPayoutCandidates();
		$accountSummary = $this->payableAccountBalanceSummary($candidates);
		$earningsSummary = $this->payableEarningsByStatusSummary();
		$blockSummary = $this->payableBlocksByCategorySummary();
		$assessment = $this->payableSourceAssessment($accountSummary, $earningsSummary, $blockSummary);

		$report['summary']['coin'] = array(
			'coin_id' => arraySafeVal($scope, 'coin_id'),
			'symbol' => arraySafeVal($coin, 'symbol'),
			'algo' => arraySafeVal($coin, 'algo'),
		);
		$report['summary']['account_balances'] = $accountSummary;
		$report['summary']['earnings_by_status'] = $earningsSummary;
		$report['summary']['blocks_by_category'] = $blockSummary;
		$report['summary']['payable_source_state'] = $assessment['state'];
		$report['summary']['next_required_stage'] = $assessment['next_required_stage'];
		$report['summary']['execution_blocked'] = $this->payableSourceBlockedMetadata();
		$report['summary']['audit'] = $this->payableSourceAuditSummary($report);
		$report['items']['candidate_preview'] = $candidates;

		$report = $this->guard->finalizeReport($report);
		$report = $this->ensurePayoutPreviewAuditFields($report);
		$report['report_checksum'] = BadpoolGuardReport::checksum($report);
		return $report;
	}

	private function accountCreditTransitionPreviewReport()
	{
		if ($this->guard->isAllCoinsPreview()) {
			$this->guard->addError('account-credit-transition-preview requires --coin-id and refuses all-coin scope.');
			return $this->guard->refusalReport();
		}

		$report = $this->guard->baseReport();
		$scope = $this->guard->getScope();
		$coin = arraySafeVal($scope, 'coin', array());
		$accountState = $this->accountCreditTransitionAccountState();
		$earningsState = $this->accountCreditTransitionEarningsState();
		$blockState = $this->accountCreditTransitionBlockState();

		$report['summary']['coin'] = array(
			'coin_id' => arraySafeVal($scope, 'coin_id'),
			'symbol' => arraySafeVal($coin, 'symbol'),
			'algo' => arraySafeVal($coin, 'algo'),
		);
		$report['summary']['account_balance_state'] = $accountState;
		$report['summary']['earnings_transition_state'] = $earningsState;
		$report['summary']['block_accounting_backlog_state'] = $blockState;
		$report['summary']['proposed_future_transition_stages'] = $this->accountCreditTransitionStages();
		$report['summary']['payout_rows_blocked_reason'] = $this->accountCreditPayoutRowsBlockedReason($accountState);
		$report['summary']['execution_blocked'] = $this->accountCreditTransitionBlockedMetadata();
		$report['summary']['audit'] = $this->accountCreditTransitionAuditSummary($report);

		$report = $this->guard->finalizeReport($report);
		$report = $this->ensurePayoutPreviewAuditFields($report);
		$report['report_checksum'] = BadpoolGuardReport::checksum($report);
		return $report;
	}

	private function earningsCreditReadinessPreviewReport()
	{
		if ($this->guard->isAllCoinsPreview()) {
			$this->guard->addError('earnings-credit-readiness-preview requires --coin-id and refuses all-coin scope.');
			return $this->guard->refusalReport();
		}

		$report = $this->guard->baseReport();
		$scope = $this->guard->getScope();
		$coin = arraySafeVal($scope, 'coin', array());
		$earningsSummary = $this->payableEarningsByStatusSummary();
		$readinessSignals = $this->earningsCreditReadinessSignals();
		$blockLinkage = $this->earningsCreditReadinessBlockLinkageSummary();
		$duplicateRisk = $this->earningsCreditReadinessDuplicateRiskSummary();
		$classification = $this->earningsCreditReadinessClassification();

		$report['summary']['coin'] = array(
			'coin_id' => arraySafeVal($scope, 'coin_id'),
			'symbol' => arraySafeVal($coin, 'symbol'),
			'algo' => arraySafeVal($coin, 'algo'),
		);
		$report['summary']['earnings_by_status'] = $earningsSummary;
		$report['summary']['status_0_1_readiness_signals'] = $readinessSignals;
		$report['summary']['block_linkage'] = $blockLinkage;
		$report['summary']['duplicate_risk'] = $duplicateRisk;
		$report['summary']['credit_readiness_classification'] = $classification;
		$report['summary']['readiness_blockers'] = $this->earningsCreditReadinessBlockers($classification, $readinessSignals, $blockLinkage, $duplicateRisk);
		$report['summary']['proposed_future_stages'] = $this->earningsCreditReadinessStages();
		$report['summary']['execution_blocked'] = $this->earningsCreditReadinessBlockedMetadata();
		$report['summary']['audit'] = $this->earningsCreditReadinessAuditSummary($report);

		$report = $this->guard->finalizeReport($report);
		$report = $this->ensurePayoutPreviewAuditFields($report);
		$report['report_checksum'] = BadpoolGuardReport::checksum($report);
		return $report;
	}

	private function blockCategoryMaturityPreviewReport()
	{
		if ($this->guard->isAllCoinsPreview()) {
			$this->guard->addError('block-category-maturity-preview requires --coin-id and refuses all-coin scope.');
			return $this->guard->refusalReport();
		}

		$report = $this->guard->baseReport();
		$scope = $this->guard->getScope();
		$coin = arraySafeVal($scope, 'coin', array());
		$coinReference = $this->blockCategoryMaturityCoinReference();
		$linkedEarnings = $this->blockCategoryMaturityLinkedEarningsSummary($coinReference);
		$staleIndicators = $this->blockCategoryMaturityStaleIndicators($coinReference);
		$classification = $this->blockCategoryMaturityClassification($linkedEarnings, $staleIndicators, $coinReference);

		$report['summary']['coin'] = array(
			'coin_id' => arraySafeVal($scope, 'coin_id'),
			'symbol' => arraySafeVal($coin, 'symbol'),
			'algo' => arraySafeVal($coin, 'algo'),
		);
		$report['summary']['freeze_assumptions'] = $this->blockCategoryMaturityFreezeAssumptions();
		$report['summary']['coin_maturity_reference'] = $coinReference;
		$report['summary']['blocks_by_category'] = $this->payableBlocksByCategorySummary();
		$report['summary']['linked_earnings_blocks'] = $linkedEarnings;
		$report['summary']['frozen_stale_category_indicators'] = $staleIndicators;
		$report['summary']['conservative_classification'] = $classification;
		$report['summary']['blockers'] = $this->blockCategoryMaturityBlockers($classification, $coinReference, $staleIndicators);
		$report['summary']['proposed_future_stages'] = $this->blockCategoryMaturityStages();
		$report['summary']['execution_blocked'] = $this->blockCategoryMaturityBlockedMetadata();
		$report['summary']['audit'] = $this->blockCategoryMaturityAuditSummary($report);

		$report = $this->guard->finalizeReport($report);
		$report = $this->ensurePayoutPreviewAuditFields($report);
		$report['report_checksum'] = BadpoolGuardReport::checksum($report);
		return $report;
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

	private function payoutRowPreflightBlockedMetadata()
	{
		$blocked = $this->payoutExecutionBlockedMetadata();
		$blocked['stage'] = 'payout_row_creation_preflight';
		$blocked['payout_row_creation_status'] = 'blocked';
		$blocked['backup_status'] = 'blocked_not_run';
		$blocked['mutation_log_status'] = 'blocked_not_run';
		$blocked['message'] = 'Read-only payout-row preflight only. Row creation remains unavailable and requires a separate approved task.';
		return $blocked;
	}

	private function payoutRowPreflightSummary($threshold, $candidateCount, $projectedTotal)
	{
		$scope = $this->guard->getScope();
		return array(
			'coin_id' => arraySafeVal($scope, 'coin_id'),
			'report_checksum_input' => array(
				'required_for_future_row_creation' => true,
				'source' => 'approved payout-candidates-preview top-level report_checksum.value',
				'accepted_by_this_command' => false,
				'status' => 'blocked_not_run',
				'note' => 'This preview reports the required checksum input but does not authorize or perform row creation.',
			),
			'candidate_count' => $candidateCount,
			'projected_payout_total' => $projectedTotal,
			'payout_threshold_used' => arraySafeVal($threshold, 'minimum_payout', 0),
			'backup_status' => array(
				'required' => true,
				'status' => 'blocked_not_run',
				'message' => 'Backup and snapshot verification must be completed outside this read-only preview before any future row creation task.',
			),
			'mutation_log_status' => array(
				'required' => true,
				'status' => 'blocked_not_run',
				'message' => 'Mutation log setup is only reported here and is not opened or written by this preview.',
			),
			'execution_stage_status' => array(
				'stage' => 'payout_row_creation',
				'status' => 'blocked',
				'message' => 'No mutation-capable payout-row stage exists in this patch.',
			),
			'payout_row_creation_status' => array(
				'status' => 'blocked',
				'creates_rows' => false,
				'message' => 'No payout rows are created by this preview.',
			),
			'blocked_action_metadata' => $this->payoutRowPreflightBlockedMetadata(),
		);
	}

	private function payoutRowDryRunBlockedMetadata()
	{
		$blocked = $this->payoutExecutionBlockedMetadata();
		$blocked['stage'] = 'payout_row_creation_dryrun_plan';
		$blocked['dryrun_plan_status'] = 'read_only';
		$blocked['payout_row_creation_status'] = 'blocked';
		$blocked['account_debit_status'] = 'blocked';
		$blocked['wallet_send_status'] = 'blocked';
		$blocked['mutation_log_status'] = 'blocked_not_run';
		$blocked['backup_snapshot_status'] = 'blocked_not_run';
		$blocked['post_execution_verification_status'] = 'blocked_not_run';
		$blocked['message'] = 'Read-only payout-row dry-run plan only. Row creation, account debit, wallet send, and verification writes remain unavailable.';
		return $blocked;
	}

	private function payoutRowDryRunPlanSummary($threshold, $candidateCount, $projectedTotal)
	{
		$scope = $this->guard->getScope();
		$coin = arraySafeVal($scope, 'coin', array());
		return array(
			'coin_id' => arraySafeVal($scope, 'coin_id'),
			'coin_symbol' => arraySafeVal($coin, 'symbol'),
			'coin_algo' => arraySafeVal($coin, 'algo'),
			'candidate_count' => $candidateCount,
			'projected_payout_total' => $projectedTotal,
			'threshold_used' => arraySafeVal($threshold, 'minimum_payout', 0),
			'required_source_preview_checksum_input' => array(
				'required' => true,
				'source' => 'approved payout-candidates-preview top-level report_checksum.value',
				'accepted_by_this_command' => false,
				'status' => 'blocked_not_run',
				'note' => 'Dry-run planning reports the checksum requirement but does not accept it as authorization.',
			),
			'proposed_payout_row_stage_name' => 'payout_row_creation',
			'proposed_mutation_log' => array(
				'path' => null,
				'status' => 'blocked_not_run',
				'message' => 'No mutation log is opened or written by this read-only plan.',
			),
			'proposed_backup_snapshot' => array(
				'status' => 'blocked_not_run',
				'message' => 'Backup and snapshot verification is a future operator preflight and is not performed by this command.',
			),
			'idempotency_rerun_status' => array(
				'idempotency_status' => 'blocked',
				'rerun_status' => 'not_applicable',
				'message' => 'No idempotency key is generated because no mutation stage exists in this patch.',
			),
			'payout_row_creation' => array(
				'status' => 'blocked',
				'creates_rows' => false,
			),
			'account_debit' => array(
				'status' => 'blocked',
				'debits_accounts' => false,
			),
			'wallet_send' => array(
				'status' => 'blocked',
				'calls_wallet_rpc' => false,
				'sends_coins' => false,
			),
			'post_execution_verification_checklist' => array(
				'status' => 'blocked_not_run',
				'items' => array(
					'payout_rows',
					'account_balances',
					'balance_history_if_used',
					'withdraws_if_used',
					'wallet_txid_if_used',
					'miner_visible_balance_payment_state',
				),
			),
			'blocked_action_metadata' => $this->payoutRowDryRunBlockedMetadata(),
		);
	}

	private function payableAccountBalanceSummary($candidates)
	{
		$candidateCount = count($candidates);
		$candidateTotal = $this->sumColumn($candidates, 'projected_payout_amount');

		if (!$this->guard->tableExists('accounts')) {
			$summary = $this->guard->missingTable('accounts');
			$summary['payout_candidate_count'] = $candidateCount;
			$summary['projected_payout_candidate_total'] = $candidateTotal;
			return $summary;
		}
		if (!$this->guard->columnExists('accounts', 'coinid') || !$this->guard->columnExists('accounts', 'balance')) {
			return array(
				'error' => 'accounts.coinid or accounts.balance column is missing',
				'payout_candidate_count' => $candidateCount,
				'projected_payout_candidate_total' => $candidateTotal,
			);
		}

		$where = $this->guard->coinWhere('accounts', 'coinid');
		$row = $this->guard->selectRow(
			"SELECT COUNT(*) AS account_count, ".
			"SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END) AS positive_account_count, ".
			"SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END) AS total_positive_account_balance, ".
			"MAX(balance) AS max_account_balance ".
			"FROM accounts WHERE ".$where['sql'],
			$where['params']
		);
		$row['payout_candidate_count'] = $candidateCount;
		$row['projected_payout_candidate_total'] = $candidateTotal;
		$row['note'] = 'Payout candidates require already credited positive account balances.';
		return $row;
	}

	private function payableEarningsByStatusSummary()
	{
		if (!$this->guard->tableExists('earnings')) {
			return $this->guard->missingTable('earnings');
		}
		if (!$this->guard->columnExists('earnings', 'status')) {
			return array('error' => 'earnings.status column is missing');
		}

		$where = $this->payableEarningsWhere();
		$parts = array(
			$this->guard->qcol('status').' AS status',
			'COUNT(*) AS row_count',
		);
		if ($this->guard->columnExists('earnings', 'amount')) {
			$parts[] = 'SUM('.$this->guard->qcol('amount').') AS amount_sum';
		}
		if ($this->guard->columnExists('earnings', 'create_time')) {
			$parts[] = 'MIN('.$this->guard->qcol('create_time').') AS min_create_time';
			$parts[] = 'MAX('.$this->guard->qcol('create_time').') AS max_create_time';
		}

		$sql = 'SELECT '.implode(', ', $parts).' FROM earnings WHERE '.$where['sql'].
			' GROUP BY '.$this->guard->qcol('status').' ORDER BY '.$this->guard->qcol('status');
		return array(
			'filter' => $where['filter'],
			'rows' => $this->guard->selectAll($sql, $where['params']),
		);
	}

	private function payableEarningsWhere()
	{
		$scope = $this->guard->getScope();
		if ($this->guard->columnExists('earnings', 'coinid')) {
			return $this->guard->coinWhere('earnings', 'coinid');
		}
		if ($this->guard->columnExists('earnings', 'algo') && isset($scope['coin']['algo'])) {
			return array(
				'sql' => $this->guard->qcol('algo').'=:algo',
				'params' => array(':algo' => $scope['coin']['algo']),
				'filter' => 'algo',
			);
		}

		$this->guard->addWarning('Cannot coin-scope earnings because neither earnings.coinid nor earnings.algo is available.');
		return array('sql' => '1=0', 'params' => array(), 'filter' => 'unavailable');
	}

	private function payableBlocksByCategorySummary()
	{
		if (!$this->guard->tableExists('blocks')) {
			return $this->guard->missingTable('blocks');
		}
		if (!$this->guard->columnExists('blocks', 'category')) {
			return array('error' => 'blocks.category column is missing');
		}

		$where = $this->payableBlocksWhere();
		$parts = array(
			$this->guard->qcol('category').' AS category',
			'COUNT(*) AS block_count',
		);
		if ($this->guard->columnExists('blocks', 'amount')) {
			$parts[] = 'SUM('.$this->guard->qcol('amount').') AS amount_sum';
		}
		if ($this->guard->columnExists('blocks', 'height')) {
			$parts[] = 'MIN('.$this->guard->qcol('height').') AS min_height';
			$parts[] = 'MAX('.$this->guard->qcol('height').') AS max_height';
		}
		if ($this->guard->columnExists('blocks', 'time')) {
			$parts[] = 'MIN('.$this->guard->qcol('time').') AS min_time';
			$parts[] = 'MAX('.$this->guard->qcol('time').') AS max_time';
		}

		$sql = 'SELECT '.implode(', ', $parts).' FROM blocks WHERE '.$where['sql'].
			' GROUP BY '.$this->guard->qcol('category').' ORDER BY '.$this->guard->qcol('category');
		$rows = $this->guard->selectAll($sql, $where['params']);
		return array(
			'filter' => $where['filter'],
			'rows' => $rows,
		);
	}

	private function payableBlocksWhere()
	{
		$scope = $this->guard->getScope();
		if ($this->guard->columnExists('blocks', 'coin_id')) {
			return $this->guard->coinWhere('blocks', 'coin_id');
		}
		if ($this->guard->columnExists('blocks', 'algo') && isset($scope['coin']['algo'])) {
			return array(
				'sql' => $this->guard->qcol('algo').'=:algo',
				'params' => array(':algo' => $scope['coin']['algo']),
				'filter' => 'algo',
			);
		}

		$this->guard->addWarning('Cannot coin-scope blocks because neither blocks.coin_id nor blocks.algo is available.');
		return array('sql' => '1=0', 'params' => array(), 'filter' => 'unavailable');
	}

	private function payableSourceAssessment($accountSummary, $earningsSummary, $blockSummary)
	{
		$positiveBalance = floatval(arraySafeVal($accountSummary, 'total_positive_account_balance', 0));
		$payoutCandidates = intval(arraySafeVal($accountSummary, 'payout_candidate_count', 0));
		$earningsRows = is_array($earningsSummary) && isset($earningsSummary['rows']) ? $earningsSummary['rows'] : array();
		$totalEarningsRows = $this->sumRows($earningsRows, 'row_count');
		$creditReadyEarnings = $this->sumRowsByValue($earningsRows, 'status', '1', 'row_count');
		$uncreditedEarnings = $this->sumRowsByValues($earningsRows, 'status', array('0', '1'), 'row_count');
		$blockRows = is_array($blockSummary) && isset($blockSummary['rows']) ? $blockSummary['rows'] : array();
		$accountingBlockRows = $this->sumRowsByValues($blockRows, 'category', array('new', 'generate', 'immature', 'mature'), 'block_count');
		$indeterminate = $this->summaryHasError($accountSummary) || $this->summaryHasError($earningsSummary) || $this->summaryHasError($blockSummary);

		$state = array(
			'already_credited_to_accounts' => $positiveBalance > 0,
			'payout_candidates_ready' => $payoutCandidates > 0,
			'present_in_earnings_but_not_credited' => $uncreditedEarnings > 0,
			'present_in_mature_or_new_blocks_needing_accounting' => $accountingBlockRows > 0,
			'absent' => !$indeterminate && $positiveBalance <= 0 && $totalEarningsRows <= 0 && $accountingBlockRows <= 0,
			'indeterminate' => $indeterminate,
			'evidence_counts' => array(
				'positive_account_balance_sum' => $positiveBalance,
				'payout_candidate_count' => $payoutCandidates,
				'earnings_row_count' => $totalEarningsRows,
				'credit_ready_earning_rows' => $creditReadyEarnings,
				'uncredited_earning_rows' => $uncreditedEarnings,
				'accounting_block_rows' => $accountingBlockRows,
			),
		);

		return array(
			'state' => $state,
			'next_required_stage' => $this->payableNextRequiredStage($state),
		);
	}

	private function payableNextRequiredStage($state)
	{
		if (arraySafeVal($state, 'indeterminate')) {
			return 'indeterminate: review schema warnings and run targeted read-only previews before choosing a next stage';
		}
		if (arraySafeVal($state, 'payout_candidates_ready')) {
			return 'payout candidates exist from credited balances; payout-row creation remains disabled and requires the approval package';
		}
		if (arraySafeVal($state, 'already_credited_to_accounts')) {
			return 'credited account balances exist but no payout candidates are above threshold; payout rows should not be created until candidates exist';
		}
		$counts = arraySafeVal($state, 'evidence_counts', array());
		if (intval(arraySafeVal($counts, 'credit_ready_earning_rows', 0)) > 0) {
			return 'account-credit-preview needed; no payout rows should be created until account balances exist';
		}
		if (arraySafeVal($state, 'present_in_earnings_but_not_credited')) {
			return 'earnings-preview and account-credit-preview needed; backend accounting remains frozen';
		}
		if (arraySafeVal($state, 'present_in_mature_or_new_blocks_needing_accounting')) {
			return 'blocks-preview and earnings-preview needed; backend block accounting remains frozen';
		}
		if (arraySafeVal($state, 'absent')) {
			return 'no payable source data detected by this DB-only reconciliation preview';
		}
		return 'indeterminate: no safe next stage selected by this read-only preview';
	}

	private function payableSourceBlockedMetadata()
	{
		return array(
			'status' => 'blocked',
			'blocked_actions' => array(
				'payout_row_creation',
				'account_debit',
				'wallet_rpc_read',
				'wallet_send',
				'earnings_mutation',
				'block_mutation',
				'coin_mutation',
				'share_deletion',
				'payout_retry_delete',
				'service_or_cron_changes',
			),
			'wallet_rpc_used' => false,
			'message' => 'Read-only payable-source reconciliation only. No payout rows, account debits, backend accounting, wallet calls, or service actions are performed.',
		);
	}

	private function payableSourceAuditSummary($report)
	{
		$scope = $this->guard->getScope();
		$coin = arraySafeVal($scope, 'coin', array());
		$summary = arraySafeVal($report, 'summary', array());
		$accountSummary = arraySafeVal($summary, 'account_balances', array());
		$sourceState = arraySafeVal($summary, 'payable_source_state', array());
		$executionBlocked = arraySafeVal($summary, 'execution_blocked', array());
		return array(
			'command' => arraySafeVal($report, 'command'),
			'coin_id' => arraySafeVal($scope, 'coin_id'),
			'coin_symbol' => arraySafeVal($coin, 'symbol'),
			'coin_algo' => arraySafeVal($coin, 'algo'),
			'positive_account_count' => intval(arraySafeVal($accountSummary, 'positive_account_count', 0)),
			'total_positive_account_balance' => floatval(arraySafeVal($accountSummary, 'total_positive_account_balance', 0)),
			'payout_candidate_count' => intval(arraySafeVal($accountSummary, 'payout_candidate_count', 0)),
			'projected_payout_candidate_total' => floatval(arraySafeVal($accountSummary, 'projected_payout_candidate_total', 0)),
			'payable_source_state' => $sourceState,
			'blocked_actions' => arraySafeVal($executionBlocked, 'blocked_actions', array()),
			'checksum_note' => 'See top-level report_checksum; generated_at is excluded from checksum input.',
			'checksum_purpose' => 'preview audit comparison only; not payout authorization',
		);
	}

	private function accountCreditTransitionAccountState()
	{
		$summary = $this->payableAccountBalanceSummary(array());
		unset($summary['payout_candidate_count']);
		unset($summary['projected_payout_candidate_total']);
		$summary['note'] = 'Payout rows require positive credited account balances. This command only reports the current state.';
		return $summary;
	}

	private function accountCreditTransitionEarningsState()
	{
		$summary = $this->payableEarningsByStatusSummary();
		$rows = is_array($summary) && isset($summary['rows']) ? $summary['rows'] : array();
		$statusZeroRows = $this->sumRowsByValue($rows, 'status', '0', 'row_count');
		$statusOneRows = $this->sumRowsByValue($rows, 'status', '1', 'row_count');
		$statusZeroAmount = $this->sumRowsFloatByValues($rows, 'status', array('0'), 'amount_sum');
		$statusOneAmount = $this->sumRowsFloatByValues($rows, 'status', array('1'), 'amount_sum');

		return array(
			'earnings_by_status' => $summary,
			'uncredited_statuses_considered' => array(0, 1),
			'uncredited_earnings_rows' => $statusZeroRows + $statusOneRows,
			'uncredited_earnings_amount' => $statusZeroAmount + $statusOneAmount,
			'credit_ready_rows' => $statusOneRows,
			'credit_ready_amount' => $statusOneAmount,
			'not_ready_rows' => $statusZeroRows,
			'not_ready_amount' => $statusZeroAmount,
			'account_credit_stage_status' => array(
				'status' => 'blocked_not_run',
				'credits_accounts' => false,
				'message' => 'Account-credit mutation is not available in this preview.',
			),
			'status_note' => 'Existing account-credit preview projects from status=1 rows when distinguishable; status=0 rows remain source backlog for separate inspection.',
		);
	}

	private function accountCreditTransitionBlockState()
	{
		$summary = $this->payableBlocksByCategorySummary();
		$rows = is_array($summary) && isset($summary['rows']) ? $summary['rows'] : array();
		$categories = array('generate', 'new', 'immature', 'orphan');
		$counts = array();
		$needsInspection = array();
		foreach ($categories as $category) {
			$count = $this->sumRowsByValue($rows, 'category', $category, 'block_count');
			$counts[$category] = $count;
			if ($count > 0) {
				$needsInspection[] = $category;
			}
		}

		return array(
			'blocks_by_category' => $summary,
			'category_counts' => $counts,
			'categories_needing_accounting_inspection' => $needsInspection,
			'inspection_stage_status' => array(
				'status' => 'blocked_not_run',
				'runs_backend_accounting' => false,
				'message' => 'Backend block accounting remains frozen; this preview only reports backlog categories.',
			),
		);
	}

	private function accountCreditTransitionStages()
	{
		return array(
			array(
				'stage' => 'block_accounting_inspection',
				'status' => 'blocked',
				'message' => 'Requires separate approved review before any backend accounting processing.',
			),
			array(
				'stage' => 'earnings_credit_readiness_verification',
				'status' => 'blocked',
				'message' => 'Requires separate approved review before account-credit mutation is considered.',
			),
			array(
				'stage' => 'account_credit_mutation',
				'status' => 'blocked',
				'message' => 'No account-credit mutation path is added by this command.',
			),
			array(
				'stage' => 'post_credit_account_balance_verification',
				'status' => 'blocked_not_run',
				'message' => 'Verification can only happen after a separately approved credit stage.',
			),
			array(
				'stage' => 'payout_candidate_regeneration',
				'status' => 'blocked_not_run',
				'message' => 'Payout candidates should be regenerated only after credited balances exist.',
			),
		);
	}

	private function accountCreditPayoutRowsBlockedReason($accountState)
	{
		$positiveBalance = floatval(arraySafeVal($accountState, 'total_positive_account_balance', 0));
		if ($positiveBalance > 0) {
			return 'Payout-row creation remains blocked even though credited balances exist; candidate preview and approval package review are still required.';
		}
		return 'Payout rows remain blocked because payout candidates require positive credited account balances. Existing payable source data in earnings or blocks must be reviewed before any future credit stage.';
	}

	private function accountCreditTransitionBlockedMetadata()
	{
		return array(
			'status' => 'blocked',
			'blocked_actions' => array(
				'backend_accounting_processing',
				'account_credit_mutation',
				'earnings_mutation',
				'block_mutation',
				'coin_mutation',
				'payout_row_creation',
				'account_debit',
				'wallet_rpc_read',
				'wallet_send',
				'share_deletion',
				'payout_retry_delete',
				'service_or_cron_changes',
			),
			'wallet_rpc_used' => false,
			'message' => 'Read-only account-credit transition preview only. No accounting, crediting, payout, wallet, share, service, or cron action is performed.',
		);
	}

	private function accountCreditTransitionAuditSummary($report)
	{
		$scope = $this->guard->getScope();
		$coin = arraySafeVal($scope, 'coin', array());
		$summary = arraySafeVal($report, 'summary', array());
		$accountState = arraySafeVal($summary, 'account_balance_state', array());
		$earningsState = arraySafeVal($summary, 'earnings_transition_state', array());
		$blockState = arraySafeVal($summary, 'block_accounting_backlog_state', array());
		$executionBlocked = arraySafeVal($summary, 'execution_blocked', array());
		return array(
			'command' => arraySafeVal($report, 'command'),
			'coin_id' => arraySafeVal($scope, 'coin_id'),
			'coin_symbol' => arraySafeVal($coin, 'symbol'),
			'coin_algo' => arraySafeVal($coin, 'algo'),
			'account_count' => intval(arraySafeVal($accountState, 'account_count', 0)),
			'positive_account_count' => intval(arraySafeVal($accountState, 'positive_account_count', 0)),
			'total_positive_account_balance' => floatval(arraySafeVal($accountState, 'total_positive_account_balance', 0)),
			'uncredited_earnings_rows' => intval(arraySafeVal($earningsState, 'uncredited_earnings_rows', 0)),
			'uncredited_earnings_amount' => floatval(arraySafeVal($earningsState, 'uncredited_earnings_amount', 0)),
			'credit_ready_rows' => intval(arraySafeVal($earningsState, 'credit_ready_rows', 0)),
			'categories_needing_accounting_inspection' => arraySafeVal($blockState, 'categories_needing_accounting_inspection', array()),
			'blocked_actions' => arraySafeVal($executionBlocked, 'blocked_actions', array()),
			'checksum_note' => 'See top-level report_checksum; generated_at is excluded from checksum input.',
			'checksum_purpose' => 'preview audit comparison only; not payout authorization',
		);
	}

	private function earningsCreditReadinessSignals()
	{
		if (!$this->guard->tableExists('earnings')) {
			return $this->guard->missingTable('earnings');
		}
		if (!$this->guard->columnExists('earnings', 'status')) {
			return array('error' => 'earnings.status column is missing');
		}

		$where = $this->earningsCreditReadinessWhere('E');
		$schema = $this->earningsCreditReadinessSchema();
		$joins = '';
		$parts = array(
			'E.'.$this->guard->qcol('status').' AS status',
			'COUNT(*) AS row_count',
		);

		if ($schema['has_amount']) {
			$amount = 'E.'.$this->guard->qcol('amount');
			$parts[] = "SUM($amount) AS amount_sum";
			$parts[] = "SUM(CASE WHEN $amount > 0 THEN 1 ELSE 0 END) AS positive_amount_count";
			$parts[] = "SUM(CASE WHEN $amount <= 0 OR $amount IS NULL THEN 1 ELSE 0 END) AS non_positive_amount_count";
		}
		if ($schema['has_create_time']) {
			$createTime = 'E.'.$this->guard->qcol('create_time');
			$parts[] = "MIN($createTime) AS min_create_time";
			$parts[] = "MAX($createTime) AS max_create_time";
		}
		if ($schema['has_mature_time']) {
			$matureTime = 'E.'.$this->guard->qcol('mature_time');
			$parts[] = "MIN($matureTime) AS min_mature_time";
			$parts[] = "MAX($matureTime) AS max_mature_time";
		}
		if ($schema['has_userid']) {
			$userid = 'E.'.$this->guard->qcol('userid');
			$parts[] = "SUM(CASE WHEN $userid IS NOT NULL AND $userid > 0 THEN 1 ELSE 0 END) AS user_id_present_count";
			$parts[] = "SUM(CASE WHEN $userid IS NULL OR $userid <= 0 THEN 1 ELSE 0 END) AS user_id_missing_count";
		}
		if ($schema['can_join_accounts']) {
			$joins .= ' LEFT JOIN accounts A ON A.'.$this->guard->qcol('id').'=E.'.$this->guard->qcol('userid');
			$parts[] = 'SUM(CASE WHEN A.'.$this->guard->qcol('id').' IS NOT NULL THEN 1 ELSE 0 END) AS matching_account_count';
			$parts[] = 'SUM(CASE WHEN A.'.$this->guard->qcol('id').' IS NULL THEN 1 ELSE 0 END) AS missing_account_count';
		}
		if ($schema['has_coinid']) {
			$coinid = 'E.'.$this->guard->qcol('coinid');
			$parts[] = "SUM(CASE WHEN $coinid=:coin_id THEN 1 ELSE 0 END) AS coin_id_match_count";
			$parts[] = "SUM(CASE WHEN $coinid!=:coin_id OR $coinid IS NULL THEN 1 ELSE 0 END) AS coin_id_mismatch_count";
		} elseif ($schema['has_algo']) {
			$algo = 'E.'.$this->guard->qcol('algo');
			$parts[] = "SUM(CASE WHEN $algo=:algo THEN 1 ELSE 0 END) AS algo_match_count";
			$parts[] = "SUM(CASE WHEN $algo!=:algo OR $algo IS NULL THEN 1 ELSE 0 END) AS algo_mismatch_count";
		}
		if ($schema['has_blockid']) {
			$blockid = 'E.'.$this->guard->qcol('blockid');
			$parts[] = "SUM(CASE WHEN $blockid IS NOT NULL AND $blockid > 0 THEN 1 ELSE 0 END) AS block_id_present_count";
			$parts[] = "SUM(CASE WHEN $blockid IS NULL OR $blockid <= 0 THEN 1 ELSE 0 END) AS block_id_missing_count";
		}
		if ($schema['can_join_blocks']) {
			$joins .= ' LEFT JOIN blocks B ON B.'.$this->guard->qcol('id').'=E.'.$this->guard->qcol('blockid');
			$parts[] = 'SUM(CASE WHEN B.'.$this->guard->qcol('id').' IS NOT NULL THEN 1 ELSE 0 END) AS matching_block_count';
			$parts[] = 'SUM(CASE WHEN B.'.$this->guard->qcol('id').' IS NULL THEN 1 ELSE 0 END) AS missing_block_count';
			if ($schema['blocks_has_category']) {
				$category = 'B.'.$this->guard->qcol('category');
				foreach (array('generate', 'new', 'immature', 'orphan') as $value) {
					$parts[] = "SUM(CASE WHEN $category='$value' THEN 1 ELSE 0 END) AS block_category_".$value."_count";
				}
			}
			if ($schema['blocks_has_height']) {
				$height = 'B.'.$this->guard->qcol('height');
				$parts[] = "MIN($height) AS min_block_height";
				$parts[] = "MAX($height) AS max_block_height";
			}
			if ($schema['blocks_has_time']) {
				$time = 'B.'.$this->guard->qcol('time');
				$parts[] = "MIN($time) AS min_block_time";
				$parts[] = "MAX($time) AS max_block_time";
			}
		}

		$sql = 'SELECT '.implode(', ', $parts).' FROM earnings E'.$joins.
			' WHERE '.$where['sql'].' AND E.'.$this->guard->qcol('status').' IN (0, 1)'.
			' GROUP BY E.'.$this->guard->qcol('status').' ORDER BY E.'.$this->guard->qcol('status');

		return array(
			'filter' => $where['filter'],
			'schema' => $schema,
			'rows' => $this->guard->selectAll($sql, $where['params']),
			'note' => 'Status 0 rows are source backlog for inspection, not automatically credit-ready.',
		);
	}

	private function earningsCreditReadinessBlockLinkageSummary()
	{
		if (!$this->guard->tableExists('earnings')) {
			return $this->guard->missingTable('earnings');
		}
		if (!$this->guard->columnExists('earnings', 'status')) {
			return array('error' => 'earnings.status column is missing');
		}

		$schema = $this->earningsCreditReadinessSchema();
		if (!$schema['can_join_blocks']) {
			return array(
				'status' => 'indeterminate',
				'error' => 'earnings.blockid or blocks.id linkage is unavailable',
				'schema' => $schema,
			);
		}

		$where = $this->earningsCreditReadinessWhere('E');
		$amountSum = $schema['has_amount'] ? 'SUM(E.'.$this->guard->qcol('amount').')' : 'NULL';
		$orphanAmount = ($schema['has_amount'] && $schema['blocks_has_category'])
			? "SUM(CASE WHEN B.".$this->guard->qcol('category')."='orphan' THEN E.".$this->guard->qcol('amount')." ELSE 0 END)"
			: 'NULL';
		$parts = array(
			'COUNT(*) AS inspected_earning_rows',
			'SUM(CASE WHEN B.'.$this->guard->qcol('id').' IS NOT NULL THEN 1 ELSE 0 END) AS earnings_with_matching_block',
			'SUM(CASE WHEN B.'.$this->guard->qcol('id').' IS NULL THEN 1 ELSE 0 END) AS earnings_without_matching_block',
			"$amountSum AS inspected_amount_sum",
			"$orphanAmount AS orphan_linked_amount",
		);
		if ($schema['blocks_has_category']) {
			$category = 'B.'.$this->guard->qcol('category');
			foreach (array('generate', 'new', 'immature', 'orphan') as $value) {
				$parts[] = "SUM(CASE WHEN $category='$value' THEN 1 ELSE 0 END) AS category_".$value."_count";
			}
		}
		if ($schema['blocks_has_height']) {
			$height = 'B.'.$this->guard->qcol('height');
			$parts[] = "MIN($height) AS min_block_height";
			$parts[] = "MAX($height) AS max_block_height";
		}
		if ($schema['blocks_has_time']) {
			$time = 'B.'.$this->guard->qcol('time');
			$parts[] = "MIN($time) AS min_block_time";
			$parts[] = "MAX($time) AS max_block_time";
		}

		$sql = 'SELECT '.implode(', ', $parts).
			' FROM earnings E LEFT JOIN blocks B ON B.'.$this->guard->qcol('id').'=E.'.$this->guard->qcol('blockid').
			' WHERE '.$where['sql'].' AND E.'.$this->guard->qcol('status').' IN (0, 1)';
		$summary = $this->guard->selectRow($sql, $where['params']);
		$categories = array();
		if ($schema['blocks_has_category']) {
			$categorySql = 'SELECT B.'.$this->guard->qcol('category').' AS category, COUNT(*) AS row_count, '.$amountSum.' AS amount_sum '.
				'FROM earnings E INNER JOIN blocks B ON B.'.$this->guard->qcol('id').'=E.'.$this->guard->qcol('blockid').
				' WHERE '.$where['sql'].' AND E.'.$this->guard->qcol('status').' IN (0, 1)'.
				' GROUP BY B.'.$this->guard->qcol('category').' ORDER BY B.'.$this->guard->qcol('category');
			$categories = $this->guard->selectAll($categorySql, $where['params']);
		}

		return array(
			'filter' => $where['filter'],
			'summary' => $summary,
			'matching_block_categories' => $categories,
		);
	}

	private function earningsCreditReadinessDuplicateRiskSummary()
	{
		if (!$this->guard->tableExists('earnings') || !$this->guard->columnExists('earnings', 'status')) {
			return array('status' => 'indeterminate', 'error' => 'earnings table or earnings.status is unavailable');
		}

		$schema = $this->earningsCreditReadinessSchema();
		$required = array('has_userid', 'has_blockid', 'has_coinid');
		foreach ($required as $key) {
			if (!$schema[$key]) {
				return array(
					'status' => 'indeterminate',
					'error' => 'duplicate-risk grouping requires earnings userid, blockid, and coinid columns',
					'schema' => $schema,
				);
			}
		}

		$where = $this->earningsCreditReadinessWhere('E');
		$groupColumns = array(
			'E.'.$this->guard->qcol('status'),
			'E.'.$this->guard->qcol('userid'),
			'E.'.$this->guard->qcol('blockid'),
			'E.'.$this->guard->qcol('coinid'),
		);
		if ($schema['has_amount']) {
			$groupColumns[] = 'E.'.$this->guard->qcol('amount');
		}

		$sql = 'SELECT COUNT(*) AS duplicate_group_count, SUM(row_count) AS duplicate_row_count FROM ('.
			'SELECT '.implode(', ', $groupColumns).', COUNT(*) AS row_count FROM earnings E '.
			'WHERE '.$where['sql'].' AND E.'.$this->guard->qcol('status').' IN (0, 1) '.
			'GROUP BY '.implode(', ', $groupColumns).' HAVING COUNT(*) > 1'.
			') D';
		$duplicates = $this->guard->selectRow($sql, $where['params']);

		$previousCredit = array('status_0_1_rows_with_cleared_match' => null, 'matched_amount' => null);
		if ($schema['has_amount']) {
			$amount = 'E.'.$this->guard->qcol('amount');
			$previousCreditSql = 'SELECT '.
				'SUM(CASE WHEN EXISTS ('.
					'SELECT 1 FROM earnings C WHERE C.'.$this->guard->qcol('status').'=2 '.
					'AND C.'.$this->guard->qcol('userid').'=E.'.$this->guard->qcol('userid').' '.
					'AND C.'.$this->guard->qcol('blockid').'=E.'.$this->guard->qcol('blockid').' '.
					'AND C.'.$this->guard->qcol('coinid').'=E.'.$this->guard->qcol('coinid').' '.
					'AND C.'.$this->guard->qcol('amount').'=E.'.$this->guard->qcol('amount').
				') THEN 1 ELSE 0 END) AS status_0_1_rows_with_cleared_match, '.
				'SUM(CASE WHEN EXISTS ('.
					'SELECT 1 FROM earnings C WHERE C.'.$this->guard->qcol('status').'=2 '.
					'AND C.'.$this->guard->qcol('userid').'=E.'.$this->guard->qcol('userid').' '.
					'AND C.'.$this->guard->qcol('blockid').'=E.'.$this->guard->qcol('blockid').' '.
					'AND C.'.$this->guard->qcol('coinid').'=E.'.$this->guard->qcol('coinid').' '.
					'AND C.'.$this->guard->qcol('amount').'=E.'.$this->guard->qcol('amount').
				') THEN '.$amount.' ELSE 0 END) AS matched_amount '.
				'FROM earnings E WHERE '.$where['sql'].' AND E.'.$this->guard->qcol('status').' IN (0, 1)';
			$previousCredit = $this->guard->selectRow($previousCreditSql, $where['params']);
		}

		return array(
			'status' => 'inspected',
			'duplicate_groups' => $duplicates,
			'previous_credit_uncertainty' => $previousCredit,
			'note' => 'Duplicate-risk indicators are read-only hints for operator review; they do not prove or authorize crediting.',
		);
	}

	private function earningsCreditReadinessClassification()
	{
		if (!$this->guard->tableExists('earnings')) {
			return $this->guard->missingTable('earnings');
		}
		if (!$this->guard->columnExists('earnings', 'status')) {
			return array('error' => 'earnings.status column is missing');
		}

		$schema = $this->earningsCreditReadinessSchema();
		$where = $this->earningsCreditReadinessWhere('E');
		$joins = '';
		$ready = array('E.'.$this->guard->qcol('status').'=1');
		$notReady = array('E.'.$this->guard->qcol('status').'=0');
		$indeterminate = array();

		if ($schema['has_amount']) {
			$amount = 'E.'.$this->guard->qcol('amount');
			$ready[] = "$amount > 0";
			$notReady[] = "$amount <= 0";
		} else {
			$indeterminate[] = 'amount column unavailable';
		}
		if ($schema['has_userid']) {
			$userid = 'E.'.$this->guard->qcol('userid');
			$ready[] = "$userid IS NOT NULL";
			$ready[] = "$userid > 0";
			$indeterminate[] = 'missing user/account linkage requires review';
		} else {
			$indeterminate[] = 'userid column unavailable';
		}
		if ($schema['can_join_accounts']) {
			$joins .= ' LEFT JOIN accounts A ON A.'.$this->guard->qcol('id').'=E.'.$this->guard->qcol('userid');
			$ready[] = 'A.'.$this->guard->qcol('id').' IS NOT NULL';
		} else {
			$indeterminate[] = 'account record join unavailable';
		}
		if ($schema['has_coinid']) {
			$ready[] = 'E.'.$this->guard->qcol('coinid').'=:coin_id';
		} elseif ($schema['has_algo']) {
			$ready[] = 'E.'.$this->guard->qcol('algo').'=:algo';
		} else {
			$indeterminate[] = 'coin or algo scope column unavailable';
		}
		if ($schema['has_blockid']) {
			$blockid = 'E.'.$this->guard->qcol('blockid');
			$ready[] = "$blockid IS NOT NULL";
			$ready[] = "$blockid > 0";
		} else {
			$indeterminate[] = 'block linkage column unavailable';
		}
		if ($schema['can_join_blocks']) {
			$joins .= ' LEFT JOIN blocks B ON B.'.$this->guard->qcol('id').'=E.'.$this->guard->qcol('blockid');
			$ready[] = 'B.'.$this->guard->qcol('id').' IS NOT NULL';
			if ($schema['blocks_has_category']) {
				$category = 'B.'.$this->guard->qcol('category');
				$ready[] = "$category='generate'";
				$notReady[] = "$category IN ('new', 'immature', 'orphan')";
			} else {
				$indeterminate[] = 'block category unavailable';
			}
		} else {
			$indeterminate[] = 'block record join unavailable';
		}

		$classExpr = "CASE WHEN (".implode(' OR ', $notReady).") THEN 'not_ready' ".
			"WHEN (".implode(' AND ', $ready).") THEN 'ready_to_credit' ".
			"ELSE 'indeterminate' END";
		$amountExpr = $schema['has_amount'] ? 'SUM(amount)' : 'NULL';
		$sql = "SELECT readiness_class, COUNT(*) AS row_count, $amountExpr AS amount_sum FROM (".
			"SELECT $classExpr AS readiness_class, ".($schema['has_amount'] ? 'E.'.$this->guard->qcol('amount').' AS amount' : 'NULL AS amount').
			" FROM earnings E".$joins.
			' WHERE '.$where['sql'].' AND E.'.$this->guard->qcol('status').' IN (0, 1)'.
			') R GROUP BY readiness_class ORDER BY readiness_class';
		$rows = $this->guard->selectAll($sql, $where['params']);

		return $this->earningsCreditReadinessClassificationTotals($rows, $indeterminate);
	}

	private function earningsCreditReadinessClassificationTotals($rows, $indeterminateReasons)
	{
		$result = array(
			'ready_to_credit_count' => 0,
			'ready_to_credit_amount' => 0.0,
			'not_ready_count' => 0,
			'not_ready_amount' => 0.0,
			'indeterminate_count' => 0,
			'indeterminate_amount' => 0.0,
			'rows' => $rows,
			'indeterminate_reason_hints' => array_values(array_unique($indeterminateReasons)),
			'note' => 'Classification is conservative and read-only. Status 0 rows are not treated as credit-ready.',
		);
		foreach ($rows as $row) {
			$class = arraySafeVal($row, 'readiness_class');
			$count = intval(arraySafeVal($row, 'row_count', 0));
			$amount = floatval(arraySafeVal($row, 'amount_sum', 0));
			if ($class == 'ready_to_credit') {
				$result['ready_to_credit_count'] = $count;
				$result['ready_to_credit_amount'] = $amount;
			} elseif ($class == 'not_ready') {
				$result['not_ready_count'] = $count;
				$result['not_ready_amount'] = $amount;
			} elseif ($class == 'indeterminate') {
				$result['indeterminate_count'] = $count;
				$result['indeterminate_amount'] = $amount;
			}
		}
		return $result;
	}

	private function earningsCreditReadinessBlockers($classification, $signals, $blockLinkage, $duplicateRisk)
	{
		$indeterminateHints = arraySafeVal($classification, 'indeterminate_reason_hints', array());
		$blockers = array(
			'status_not_credit_ready' => array(
				'present' => intval(arraySafeVal($classification, 'not_ready_count', 0)) > 0,
				'message' => 'Status 0 rows and rows with unsafe block categories are not credit-ready in this preview.',
			),
			'immature_or_new_block_backlog' => array(
				'present' => $this->earningsCreditReadinessCategoryCount($blockLinkage, array('new', 'immature')) > 0,
				'message' => 'New or immature block-linked rows require block accounting inspection before crediting.',
			),
			'orphan_risk' => array(
				'present' => $this->earningsCreditReadinessCategoryCount($blockLinkage, array('orphan')) > 0,
				'message' => 'Orphan-linked earnings must not be credited without separate repair review.',
			),
			'missing_linkage' => array(
				'present' => $this->earningsCreditReadinessMissingLinkagePresent($signals, $blockLinkage),
				'message' => 'Missing account, block id, or block record linkage prevents automatic readiness.',
			),
			'duplicate_previous_credit_uncertainty' => array(
				'present' => $this->earningsCreditReadinessDuplicateRiskPresent($duplicateRisk),
				'message' => 'Duplicate groups or prior cleared matches require operator review before crediting.',
			),
			'schema_limitation' => array(
				'present' => !empty($indeterminateHints) || $this->summaryHasError($signals) || $this->summaryHasError($blockLinkage) || $this->summaryHasError($duplicateRisk),
				'message' => 'Schema limitations make at least one readiness signal indeterminate.',
			),
		);
		return $blockers;
	}

	private function earningsCreditReadinessStages()
	{
		return array(
			array('stage' => 'resolve_block_accounting_categories', 'status' => 'blocked', 'message' => 'Resolve generate/new/immature/orphan source categories in a separate approved review.'),
			array('stage' => 'verify_non_orphan_mature_earnings', 'status' => 'blocked', 'message' => 'Verify linked earnings are non-orphan and mature before any credit package.'),
			array('stage' => 'prepare_account_credit_approval_package', 'status' => 'blocked_not_run', 'message' => 'Approval package preparation is future review work only.'),
			array('stage' => 'account_credit_mutation', 'status' => 'blocked', 'message' => 'No account-credit mutation path is added by this preview.'),
			array('stage' => 'post_credit_verification', 'status' => 'blocked_not_run', 'message' => 'Verification can only happen after a separately approved credit stage.'),
			array('stage' => 'payout_candidate_regeneration', 'status' => 'blocked_not_run', 'message' => 'Payout candidates should be regenerated only after credited balances exist.'),
		);
	}

	private function earningsCreditReadinessBlockedMetadata()
	{
		return array(
			'status' => 'blocked',
			'blocked_actions' => array(
				'backend_accounting_processing',
				'account_credit_mutation',
				'earnings_mutation',
				'block_mutation',
				'coin_mutation',
				'payout_row_creation',
				'account_debit',
				'wallet_rpc_read',
				'wallet_send',
				'share_deletion',
				'payout_retry_delete',
				'service_or_cron_changes',
			),
			'wallet_rpc_used' => false,
			'message' => 'Read-only earnings credit-readiness preview only. It reports blockers and does not change earnings, accounts, blocks, payouts, wallets, shares, services, or cron state.',
		);
	}

	private function earningsCreditReadinessAuditSummary($report)
	{
		$scope = $this->guard->getScope();
		$coin = arraySafeVal($scope, 'coin', array());
		$summary = arraySafeVal($report, 'summary', array());
		$classification = arraySafeVal($summary, 'credit_readiness_classification', array());
		$executionBlocked = arraySafeVal($summary, 'execution_blocked', array());
		return array(
			'command' => arraySafeVal($report, 'command'),
			'coin_id' => arraySafeVal($scope, 'coin_id'),
			'coin_symbol' => arraySafeVal($coin, 'symbol'),
			'coin_algo' => arraySafeVal($coin, 'algo'),
			'ready_to_credit_count' => intval(arraySafeVal($classification, 'ready_to_credit_count', 0)),
			'ready_to_credit_amount' => floatval(arraySafeVal($classification, 'ready_to_credit_amount', 0)),
			'not_ready_count' => intval(arraySafeVal($classification, 'not_ready_count', 0)),
			'not_ready_amount' => floatval(arraySafeVal($classification, 'not_ready_amount', 0)),
			'indeterminate_count' => intval(arraySafeVal($classification, 'indeterminate_count', 0)),
			'indeterminate_amount' => floatval(arraySafeVal($classification, 'indeterminate_amount', 0)),
			'blocked_actions' => arraySafeVal($executionBlocked, 'blocked_actions', array()),
			'checksum_note' => 'See top-level report_checksum; generated_at is excluded from checksum input.',
			'checksum_purpose' => 'preview audit comparison only; not payout authorization',
		);
	}

	private function earningsCreditReadinessWhere($alias)
	{
		$scope = $this->guard->getScope();
		$prefix = $alias ? $alias.'.' : '';
		if ($this->guard->columnExists('earnings', 'coinid')) {
			return array(
				'sql' => $prefix.$this->guard->qcol('coinid').'=:coin_id',
				'params' => array(':coin_id' => arraySafeVal($scope, 'coin_id')),
				'filter' => 'coin_id',
			);
		}
		if ($this->guard->columnExists('earnings', 'algo') && isset($scope['coin']['algo'])) {
			return array(
				'sql' => $prefix.$this->guard->qcol('algo').'=:algo',
				'params' => array(':algo' => $scope['coin']['algo']),
				'filter' => 'algo',
			);
		}

		$this->guard->addWarning('Cannot coin-scope earnings readiness because neither earnings.coinid nor earnings.algo is available.');
		return array('sql' => '1=0', 'params' => array(), 'filter' => 'unavailable');
	}

	private function earningsCreditReadinessSchema()
	{
		return array(
			'has_status' => $this->guard->columnExists('earnings', 'status'),
			'has_amount' => $this->guard->columnExists('earnings', 'amount'),
			'has_create_time' => $this->guard->columnExists('earnings', 'create_time'),
			'has_mature_time' => $this->guard->columnExists('earnings', 'mature_time'),
			'has_userid' => $this->guard->columnExists('earnings', 'userid'),
			'has_coinid' => $this->guard->columnExists('earnings', 'coinid'),
			'has_algo' => $this->guard->columnExists('earnings', 'algo'),
			'has_blockid' => $this->guard->columnExists('earnings', 'blockid'),
			'has_accounts_table' => $this->guard->tableExists('accounts'),
			'accounts_has_id' => $this->guard->columnExists('accounts', 'id'),
			'has_blocks_table' => $this->guard->tableExists('blocks'),
			'blocks_has_id' => $this->guard->columnExists('blocks', 'id'),
			'blocks_has_category' => $this->guard->columnExists('blocks', 'category'),
			'blocks_has_height' => $this->guard->columnExists('blocks', 'height'),
			'blocks_has_time' => $this->guard->columnExists('blocks', 'time'),
			'can_join_accounts' => $this->guard->tableExists('accounts') && $this->guard->columnExists('accounts', 'id') && $this->guard->columnExists('earnings', 'userid'),
			'can_join_blocks' => $this->guard->tableExists('blocks') && $this->guard->columnExists('blocks', 'id') && $this->guard->columnExists('earnings', 'blockid'),
		);
	}

	private function earningsCreditReadinessCategoryCount($blockLinkage, $categories)
	{
		$summary = arraySafeVal($blockLinkage, 'summary', array());
		$total = 0;
		foreach ($categories as $category) {
			$total += intval(arraySafeVal($summary, 'category_'.$category.'_count', 0));
		}
		return $total;
	}

	private function earningsCreditReadinessMissingLinkagePresent($signals, $blockLinkage)
	{
		$rows = arraySafeVal($signals, 'rows', array());
		foreach ($rows as $row) {
			if (intval(arraySafeVal($row, 'user_id_missing_count', 0)) > 0 ||
				intval(arraySafeVal($row, 'missing_account_count', 0)) > 0 ||
				intval(arraySafeVal($row, 'block_id_missing_count', 0)) > 0 ||
				intval(arraySafeVal($row, 'missing_block_count', 0)) > 0) {
				return true;
			}
		}
		$summary = arraySafeVal($blockLinkage, 'summary', array());
		return intval(arraySafeVal($summary, 'earnings_without_matching_block', 0)) > 0;
	}

	private function earningsCreditReadinessDuplicateRiskPresent($duplicateRisk)
	{
		$duplicates = arraySafeVal($duplicateRisk, 'duplicate_groups', array());
		$previousCredit = arraySafeVal($duplicateRisk, 'previous_credit_uncertainty', array());
		return intval(arraySafeVal($duplicates, 'duplicate_group_count', 0)) > 0 ||
			intval(arraySafeVal($previousCredit, 'status_0_1_rows_with_cleared_match', 0)) > 0;
	}

	private function blockCategoryMaturityFreezeAssumptions()
	{
		return array(
			'backend_block_accounting_services_invoked' => false,
			'systemd_state_checked' => false,
			'systemd_state_changed' => false,
			'stale_category_suspicion_source' => 'database preview only',
			'message' => 'This command does not check or change service state. It only reports DB evidence that categories may be frozen or stale while backend block/accounting services remain outside this preview.',
		);
	}

	private function blockCategoryMaturityCoinReference()
	{
		if (!$this->guard->tableExists('coins')) {
			return $this->guard->missingTable('coins');
		}

		$scope = $this->guard->getScope();
		$columns = array(
			'id', 'symbol', 'symbol2', 'name', 'algo', 'block_height', 'target_height',
			'mature_blocks', 'block_time', 'cleared', 'immature', 'available', 'balance',
			'minted', 'reward', 'lastblock', 'last_block', 'confirmations',
		);
		$row = $this->guard->selectRow(
			'SELECT '.$this->guard->selectColumns('coins', $columns).' FROM coins WHERE '.$this->guard->qcol('id').'=:coin_id',
			array(':coin_id' => arraySafeVal($scope, 'coin_id'))
		);
		if (!$row) {
			return array('error' => 'selected coin is unavailable');
		}

		$currentHeight = $this->blockCategoryMaturityNumericInput($row, array('block_height', 'target_height'));
		$matureBlocks = $this->blockCategoryMaturityNumericInput($row, array('mature_blocks'));
		$blockTime = $this->blockCategoryMaturityNumericInput($row, array('block_time'));
		$missing = array();
		if ($currentHeight['value'] === null) {
			$missing[] = 'current_height';
		}
		if ($matureBlocks['value'] === null) {
			$missing[] = 'mature_blocks';
		}
		if ($blockTime['value'] === null) {
			$missing[] = 'block_time';
		}

		return array(
			'coin_fields' => $row,
			'derived_maturity_inputs' => array(
				'current_height' => $currentHeight['value'],
				'current_height_source' => $currentHeight['source'],
				'current_height_numeric' => $currentHeight['value'] !== null,
				'mature_blocks' => $matureBlocks['value'],
				'mature_blocks_source' => $matureBlocks['source'],
				'mature_blocks_numeric' => $matureBlocks['value'] !== null,
				'block_time' => $blockTime['value'],
				'block_time_source' => $blockTime['source'],
				'block_time_numeric' => $blockTime['value'] !== null,
				'missing_maturity_source_fields' => $missing,
			),
		);
	}

	private function blockCategoryMaturityLinkedEarningsSummary($coinReference)
	{
		if (!$this->guard->tableExists('earnings')) {
			return $this->guard->missingTable('earnings');
		}
		if (!$this->guard->tableExists('blocks')) {
			return $this->guard->missingTable('blocks');
		}

		$schema = $this->blockCategoryMaturitySchema();
		if (!$schema['can_join_earnings_blocks']) {
			return array(
				'status' => 'indeterminate',
				'error' => 'earnings.blockid or blocks.id linkage is unavailable',
				'schema' => $schema,
			);
		}
		if (!$schema['earnings_has_status']) {
			return array('error' => 'earnings.status column is missing');
		}

		$where = $this->earningsCreditReadinessWhere('E');
		$params = $where['params'];
		$heightInputs = $this->blockCategoryMaturityHeightInputs($coinReference);
		if ($heightInputs['can_determine']) {
			$params[':current_height'] = $heightInputs['current_height'];
			$params[':mature_blocks'] = $heightInputs['mature_blocks'];
		}

		$amount = $schema['earnings_has_amount'] ? 'SUM(E.'.$this->guard->qcol('amount').')' : 'NULL';
		$summaryParts = array(
			'COUNT(*) AS earnings_row_count',
			"$amount AS earnings_amount_sum",
			'SUM(CASE WHEN B.'.$this->guard->qcol('id').' IS NOT NULL THEN 1 ELSE 0 END) AS earnings_with_matching_block',
			'SUM(CASE WHEN B.'.$this->guard->qcol('id').' IS NULL THEN 1 ELSE 0 END) AS earnings_without_matching_block',
			'COUNT(DISTINCT B.'.$this->guard->qcol('id').') AS linked_block_count',
		);
		if ($schema['blocks_has_height']) {
			$height = 'B.'.$this->guard->qcol('height');
			$summaryParts[] = "MIN($height) AS min_block_height";
			$summaryParts[] = "MAX($height) AS max_block_height";
			if ($heightInputs['can_determine']) {
				$summaryParts[] = "MIN(:current_height - $height) AS min_height_delta";
				$summaryParts[] = "MAX(:current_height - $height) AS max_height_delta";
				$summaryParts[] = "SUM(CASE WHEN (:current_height - $height) >= :mature_blocks THEN 1 ELSE 0 END) AS earning_rows_potentially_mature_by_height";
				$summaryParts[] = "COUNT(DISTINCT CASE WHEN (:current_height - $height) >= :mature_blocks THEN B.".$this->guard->qcol('id').' ELSE NULL END) AS blocks_potentially_mature_by_height';
			}
		}
		if ($schema['blocks_has_time']) {
			$time = 'B.'.$this->guard->qcol('time');
			$summaryParts[] = "MIN($time) AS min_block_time";
			$summaryParts[] = "MAX($time) AS max_block_time";
		}

		$sql = 'SELECT '.implode(', ', $summaryParts).
			' FROM earnings E LEFT JOIN blocks B ON B.'.$this->guard->qcol('id').'=E.'.$this->guard->qcol('blockid').
			' WHERE '.$where['sql'].' AND E.'.$this->guard->qcol('status').' IN (0, 1)';
		$summary = $this->guard->selectRow($sql, $params);

		$byCategory = array();
		if ($schema['blocks_has_category']) {
			$categoryParts = array(
				'B.'.$this->guard->qcol('category').' AS category',
				'COUNT(*) AS earnings_row_count',
				"$amount AS earnings_amount_sum",
				'COUNT(DISTINCT B.'.$this->guard->qcol('id').') AS block_count',
			);
			if ($schema['blocks_has_height']) {
				$height = 'B.'.$this->guard->qcol('height');
				$categoryParts[] = "MIN($height) AS min_block_height";
				$categoryParts[] = "MAX($height) AS max_block_height";
				if ($heightInputs['can_determine']) {
					$categoryParts[] = "MIN(:current_height - $height) AS min_height_delta";
					$categoryParts[] = "MAX(:current_height - $height) AS max_height_delta";
					$categoryParts[] = "SUM(CASE WHEN (:current_height - $height) >= :mature_blocks THEN 1 ELSE 0 END) AS earning_rows_potentially_mature_by_height";
					$categoryParts[] = "COUNT(DISTINCT CASE WHEN (:current_height - $height) >= :mature_blocks THEN B.".$this->guard->qcol('id').' ELSE NULL END) AS blocks_potentially_mature_by_height';
				}
			}
			if ($schema['blocks_has_time']) {
				$time = 'B.'.$this->guard->qcol('time');
				$categoryParts[] = "MIN($time) AS min_block_time";
				$categoryParts[] = "MAX($time) AS max_block_time";
			}

			$categorySql = 'SELECT '.implode(', ', $categoryParts).
				' FROM earnings E INNER JOIN blocks B ON B.'.$this->guard->qcol('id').'=E.'.$this->guard->qcol('blockid').
				' WHERE '.$where['sql'].' AND E.'.$this->guard->qcol('status').' IN (0, 1)'.
				' GROUP BY B.'.$this->guard->qcol('category').' ORDER BY B.'.$this->guard->qcol('category');
			$byCategory = $this->guard->selectAll($categorySql, $params);
		}

		return array(
			'filter' => $where['filter'],
			'maturity_determination' => $heightInputs,
			'summary' => $summary,
			'by_category' => $byCategory,
		);
	}

	private function blockCategoryMaturityStaleIndicators($coinReference)
	{
		$schema = $this->blockCategoryMaturitySchema();
		if (!$this->guard->tableExists('blocks')) {
			return $this->guard->missingTable('blocks');
		}
		if (!$schema['blocks_has_category']) {
			return array('error' => 'blocks.category column is missing');
		}

		$where = $this->blockCategoryMaturityBlocksWhere('B');
		$heightInputs = $this->blockCategoryMaturityHeightInputs($coinReference);
		$timeCutoff = $this->blockCategoryMaturityOldTimeCutoff($coinReference);
		$missing = arraySafeVal(arraySafeVal($coinReference, 'derived_maturity_inputs', array()), 'missing_maturity_source_fields', array());

		$indicators = array(
			'missing_maturity_source_fields' => $missing,
			'old_time_reference_source' => arraySafeVal($timeCutoff, 'reference_source'),
			'old_time_reference_time' => arraySafeVal($timeCutoff, 'reference_time'),
			'old_time_threshold_seconds' => arraySafeVal($timeCutoff, 'threshold_seconds'),
			'old_time_cutoff' => arraySafeVal($timeCutoff, 'cutoff_time'),
			'immature_blocks_with_old_block_time' => array('status' => 'not_evaluated'),
			'immature_blocks_far_below_current_height' => array('status' => 'not_evaluated'),
			'new_blocks_with_old_block_time' => array('status' => 'not_evaluated'),
			'generate_blocks_represented_in_earnings' => $this->blockCategoryMaturityLinkedCategoryAggregate('generate'),
			'orphan_blocks_linked_to_earnings' => $this->blockCategoryMaturityLinkedCategoryAggregate('orphan'),
		);

		if ($timeCutoff['can_determine'] && $schema['blocks_has_time']) {
			$params = $where['params'];
			$params[':old_cutoff'] = $timeCutoff['cutoff_time'];
			$indicators['immature_blocks_with_old_block_time'] = $this->blockCategoryMaturityBlockAggregate(
				$where['sql'].' AND B.'.$this->guard->qcol('category')."='immature' AND B.".$this->guard->qcol('time').'<:old_cutoff',
				$params
			);
			$indicators['new_blocks_with_old_block_time'] = $this->blockCategoryMaturityBlockAggregate(
				$where['sql'].' AND B.'.$this->guard->qcol('category')."='new' AND B.".$this->guard->qcol('time').'<:old_cutoff',
				$params
			);
		}

		if ($heightInputs['can_determine'] && $schema['blocks_has_height']) {
			$params = $where['params'];
			$params[':current_height'] = $heightInputs['current_height'];
			$params[':mature_blocks'] = $heightInputs['mature_blocks'];
			$indicators['immature_blocks_far_below_current_height'] = $this->blockCategoryMaturityBlockAggregate(
				$where['sql'].' AND B.'.$this->guard->qcol('category')."='immature' AND (:current_height - B.".$this->guard->qcol('height').') >= :mature_blocks',
				$params
			);
		}

		return $indicators;
	}

	private function blockCategoryMaturityClassification($linkedEarnings, $staleIndicators, $coinReference)
	{
		$heightInputs = $this->blockCategoryMaturityHeightInputs($coinReference);
		$linkedImmature = $this->blockCategoryMaturityLinkedBlockCount($linkedEarnings, 'immature');
		$linkedNew = $this->blockCategoryMaturityLinkedBlockCount($linkedEarnings, 'new');
		$linkedOrphan = $this->blockCategoryMaturityLinkedBlockCount($linkedEarnings, 'orphan');
		$farBelow = intval(arraySafeVal(arraySafeVal($staleIndicators, 'immature_blocks_far_below_current_height', array()), 'block_count', 0));
		$oldImmature = intval(arraySafeVal(arraySafeVal($staleIndicators, 'immature_blocks_with_old_block_time', array()), 'block_count', 0));
		$orphanLinked = intval(arraySafeVal(arraySafeVal(arraySafeVal($staleIndicators, 'orphan_blocks_linked_to_earnings', array()), 'summary', array()), 'block_count', 0));
		$missing = arraySafeVal(arraySafeVal($coinReference, 'derived_maturity_inputs', array()), 'missing_maturity_source_fields', array());

		$potentiallyMature = $heightInputs['can_determine'] ? $farBelow : 0;
		$likelyStale = max($farBelow, $oldImmature);
		$stillUnknown = $heightInputs['can_determine'] ? max(0, $linkedImmature - $potentiallyMature) + $linkedNew : $linkedImmature + $linkedNew;
		$indeterminate = empty($missing) ? 0 : $linkedImmature + $linkedNew;

		return array(
			'likely_stale_immature_category_count' => $likelyStale,
			'potentially_mature_but_untransitioned_count' => $potentiallyMature,
			'still_immature_or_unknown_count' => $stillUnknown,
			'orphan_risk_count' => max($linkedOrphan, $orphanLinked),
			'indeterminate_count' => $indeterminate,
			'classification_note' => 'Classification is conservative and DB-only. It does not validate chain state, service state, or category transition safety.',
		);
	}

	private function blockCategoryMaturityBlockers($classification, $coinReference, $staleIndicators)
	{
		$inputs = arraySafeVal($coinReference, 'derived_maturity_inputs', array());
		return array(
			'backend_updater_frozen' => array(
				'present' => true,
				'message' => 'Backend block/accounting services are not invoked by this preview and must remain separately verified.',
			),
			'missing_or_null_mature_blocks' => array(
				'present' => !arraySafeVal($inputs, 'mature_blocks_numeric', false),
				'message' => 'mature_blocks is required before height-based maturity can be trusted.',
			),
			'missing_current_height' => array(
				'present' => !arraySafeVal($inputs, 'current_height_numeric', false),
				'message' => 'Current coin height is required before height-based maturity can be trusted.',
			),
			'orphan_category_risk' => array(
				'present' => intval(arraySafeVal($classification, 'orphan_risk_count', 0)) > 0,
				'message' => 'Orphan-linked rows must not proceed without separate repair review.',
			),
			'category_transition_not_validated' => array(
				'present' => true,
				'message' => 'This preview does not validate or perform block category/status transition logic.',
			),
			'account_credit_still_blocked' => array(
				'present' => true,
				'message' => 'Account-credit remains blocked until a separate approved transition and readiness recheck exist.',
			),
			'old_time_source_missing' => array(
				'present' => arraySafeVal($staleIndicators, 'old_time_threshold_seconds') === null,
				'message' => 'Old-time stale category checks require numeric mature_blocks and block_time values.',
			),
		);
	}

	private function blockCategoryMaturityStages()
	{
		return array(
			array('stage' => 'verify_maturity_threshold_source', 'status' => 'blocked', 'message' => 'Confirm mature_blocks and any maturity policy source in a separate approved review.'),
			array('stage' => 'verify_current_chain_height_source', 'status' => 'blocked', 'message' => 'Confirm current height source without using this preview as proof of chain state.'),
			array('stage' => 'inspect_backend_block_category_transition_logic', 'status' => 'blocked', 'message' => 'Review category transition logic separately before any future transition task.'),
			array('stage' => 'prepare_block_category_transition_approval_package', 'status' => 'blocked_not_run', 'message' => 'Approval package preparation is future review work only.'),
			array('stage' => 'block_category_status_transition_mutation', 'status' => 'blocked', 'message' => 'No category or earnings status mutation path is added by this preview.'),
			array('stage' => 'post_transition_earnings_credit_readiness_recheck', 'status' => 'blocked_not_run', 'message' => 'Rerun earnings credit-readiness only after a separately approved transition.'),
			array('stage' => 'account_credit_approval_package', 'status' => 'blocked_not_run', 'message' => 'Account-credit approval remains future work after readiness is rechecked.'),
		);
	}

	private function blockCategoryMaturityBlockedMetadata()
	{
		return array(
			'status' => 'blocked',
			'blocked_actions' => array(
				'backend_accounting_processing',
				'block_category_mutation',
				'earnings_status_mutation',
				'account_credit_mutation',
				'block_mutation',
				'earnings_mutation',
				'coin_mutation',
				'payout_row_creation',
				'account_debit',
				'wallet_rpc_read',
				'wallet_send',
				'share_deletion',
				'payout_retry_delete',
				'service_or_cron_changes',
			),
			'wallet_rpc_used' => false,
			'message' => 'Read-only block category maturity preview only. It does not invoke backend accounting, mature blocks, change categories, change earnings, credit accounts, create payout rows, call wallets, delete shares, or change services.',
		);
	}

	private function blockCategoryMaturityAuditSummary($report)
	{
		$scope = $this->guard->getScope();
		$coin = arraySafeVal($scope, 'coin', array());
		$summary = arraySafeVal($report, 'summary', array());
		$classification = arraySafeVal($summary, 'conservative_classification', array());
		$executionBlocked = arraySafeVal($summary, 'execution_blocked', array());
		return array(
			'command' => arraySafeVal($report, 'command'),
			'coin_id' => arraySafeVal($scope, 'coin_id'),
			'coin_symbol' => arraySafeVal($coin, 'symbol'),
			'coin_algo' => arraySafeVal($coin, 'algo'),
			'likely_stale_immature_category_count' => intval(arraySafeVal($classification, 'likely_stale_immature_category_count', 0)),
			'potentially_mature_but_untransitioned_count' => intval(arraySafeVal($classification, 'potentially_mature_but_untransitioned_count', 0)),
			'still_immature_or_unknown_count' => intval(arraySafeVal($classification, 'still_immature_or_unknown_count', 0)),
			'orphan_risk_count' => intval(arraySafeVal($classification, 'orphan_risk_count', 0)),
			'indeterminate_count' => intval(arraySafeVal($classification, 'indeterminate_count', 0)),
			'blocked_actions' => arraySafeVal($executionBlocked, 'blocked_actions', array()),
			'checksum_note' => 'See top-level report_checksum; generated_at is excluded from checksum input.',
			'checksum_purpose' => 'preview audit comparison only; not payout authorization',
		);
	}

	private function blockCategoryMaturityBlocksWhere($alias)
	{
		$scope = $this->guard->getScope();
		$prefix = $alias ? $alias.'.' : '';
		if ($this->guard->columnExists('blocks', 'coin_id')) {
			return array(
				'sql' => $prefix.$this->guard->qcol('coin_id').'=:coin_id',
				'params' => array(':coin_id' => arraySafeVal($scope, 'coin_id')),
				'filter' => 'coin_id',
			);
		}
		if ($this->guard->columnExists('blocks', 'algo') && isset($scope['coin']['algo'])) {
			return array(
				'sql' => $prefix.$this->guard->qcol('algo').'=:algo',
				'params' => array(':algo' => $scope['coin']['algo']),
				'filter' => 'algo',
			);
		}

		$this->guard->addWarning('Cannot coin-scope block maturity preview because neither blocks.coin_id nor blocks.algo is available.');
		return array('sql' => '1=0', 'params' => array(), 'filter' => 'unavailable');
	}

	private function blockCategoryMaturitySchema()
	{
		return array(
			'earnings_has_status' => $this->guard->columnExists('earnings', 'status'),
			'earnings_has_amount' => $this->guard->columnExists('earnings', 'amount'),
			'earnings_has_blockid' => $this->guard->columnExists('earnings', 'blockid'),
			'blocks_has_id' => $this->guard->columnExists('blocks', 'id'),
			'blocks_has_category' => $this->guard->columnExists('blocks', 'category'),
			'blocks_has_amount' => $this->guard->columnExists('blocks', 'amount'),
			'blocks_has_height' => $this->guard->columnExists('blocks', 'height'),
			'blocks_has_time' => $this->guard->columnExists('blocks', 'time'),
			'can_join_earnings_blocks' => $this->guard->tableExists('earnings') && $this->guard->tableExists('blocks') &&
				$this->guard->columnExists('earnings', 'blockid') && $this->guard->columnExists('blocks', 'id'),
		);
	}

	private function blockCategoryMaturityBlockAggregate($whereSql, $params)
	{
		$schema = $this->blockCategoryMaturitySchema();
		$parts = array('COUNT(*) AS block_count');
		if ($schema['blocks_has_amount']) {
			$parts[] = 'SUM(B.'.$this->guard->qcol('amount').') AS amount_sum';
		}
		if ($schema['blocks_has_height']) {
			$height = 'B.'.$this->guard->qcol('height');
			$parts[] = "MIN($height) AS min_height";
			$parts[] = "MAX($height) AS max_height";
		}
		if ($schema['blocks_has_time']) {
			$time = 'B.'.$this->guard->qcol('time');
			$parts[] = "MIN($time) AS min_time";
			$parts[] = "MAX($time) AS max_time";
		}
		return $this->guard->selectRow('SELECT '.implode(', ', $parts).' FROM blocks B WHERE '.$whereSql, $params);
	}

	private function blockCategoryMaturityLinkedCategoryAggregate($category)
	{
		$schema = $this->blockCategoryMaturitySchema();
		if (!$schema['can_join_earnings_blocks'] || !$schema['blocks_has_category'] || !$schema['earnings_has_status']) {
			return array('status' => 'indeterminate', 'error' => 'linked earnings/block category inspection is unavailable');
		}

		$where = $this->earningsCreditReadinessWhere('E');
		$params = $where['params'];
		$params[':category'] = $category;
		$amount = $schema['earnings_has_amount'] ? 'SUM(E.'.$this->guard->qcol('amount').')' : 'NULL';
		$sql = 'SELECT COUNT(*) AS earnings_row_count, '.$amount.' AS earnings_amount_sum, '.
			'COUNT(DISTINCT B.'.$this->guard->qcol('id').') AS block_count '.
			'FROM earnings E INNER JOIN blocks B ON B.'.$this->guard->qcol('id').'=E.'.$this->guard->qcol('blockid').
			' WHERE '.$where['sql'].' AND E.'.$this->guard->qcol('status').' IN (0, 1)'.
			' AND B.'.$this->guard->qcol('category').'=:category';
		return array(
			'filter' => $where['filter'],
			'category' => $category,
			'summary' => $this->guard->selectRow($sql, $params),
		);
	}

	private function blockCategoryMaturityHeightInputs($coinReference)
	{
		$inputs = arraySafeVal($coinReference, 'derived_maturity_inputs', array());
		$currentHeight = arraySafeVal($inputs, 'current_height');
		$matureBlocks = arraySafeVal($inputs, 'mature_blocks');
		$canDetermine = $currentHeight !== null && $matureBlocks !== null;
		return array(
			'can_determine' => $canDetermine,
			'current_height' => $currentHeight,
			'current_height_source' => arraySafeVal($inputs, 'current_height_source'),
			'mature_blocks' => $matureBlocks,
			'mature_blocks_source' => arraySafeVal($inputs, 'mature_blocks_source'),
			'message' => $canDetermine ? 'Height-based maturity can be approximated from DB fields.' : 'Height-based maturity cannot be determined from available DB fields.',
		);
	}

	private function blockCategoryMaturityOldTimeCutoff($coinReference)
	{
		$inputs = arraySafeVal($coinReference, 'derived_maturity_inputs', array());
		$matureBlocks = arraySafeVal($inputs, 'mature_blocks');
		$blockTime = arraySafeVal($inputs, 'block_time');
		if ($matureBlocks === null || $blockTime === null || $matureBlocks <= 0 || $blockTime <= 0) {
			return array('can_determine' => false, 'reference_source' => null, 'reference_time' => null, 'threshold_seconds' => null, 'cutoff_time' => null);
		}
		if (!$this->guard->tableExists('blocks') || !$this->guard->columnExists('blocks', 'time')) {
			return array('can_determine' => false, 'reference_source' => null, 'reference_time' => null, 'threshold_seconds' => null, 'cutoff_time' => null);
		}

		$where = $this->blockCategoryMaturityBlocksWhere('B');
		$reference = $this->guard->selectRow(
			'SELECT MAX(B.'.$this->guard->qcol('time').') AS reference_time FROM blocks B WHERE '.$where['sql'],
			$where['params']
		);
		$referenceTime = arraySafeVal($reference, 'reference_time');
		if (!is_numeric($referenceTime)) {
			return array('can_determine' => false, 'reference_source' => 'max_selected_block_time', 'reference_time' => null, 'threshold_seconds' => null, 'cutoff_time' => null);
		}
		$threshold = intval($matureBlocks * $blockTime);
		return array(
			'can_determine' => true,
			'reference_source' => 'max_selected_block_time',
			'reference_time' => floatval($referenceTime),
			'threshold_seconds' => $threshold,
			'cutoff_time' => floatval($referenceTime) - $threshold,
		);
	}

	private function blockCategoryMaturityLinkedBlockCount($linkedEarnings, $category)
	{
		$rows = arraySafeVal($linkedEarnings, 'by_category', array());
		foreach ($rows as $row) {
			if (arraySafeVal($row, 'category') == $category) {
				return intval(arraySafeVal($row, 'block_count', 0));
			}
		}
		return 0;
	}

	private function blockCategoryMaturityNumericInput($row, $columns)
	{
		foreach ($columns as $column) {
			if (array_key_exists($column, $row) && is_numeric($row[$column])) {
				return array('value' => floatval($row[$column]), 'source' => $column);
			}
		}
		return array('value' => null, 'source' => null);
	}

	private function payoutPreviewAuditSummary($report)
	{
		$scope = $this->guard->getScope();
		$coin = arraySafeVal($scope, 'coin', array());
		$summary = arraySafeVal($report, 'summary', array());
		$executionBlocked = arraySafeVal($summary, 'execution_blocked', array());
		return array(
			'command' => arraySafeVal($report, 'command'),
			'coin_id' => arraySafeVal($scope, 'coin_id'),
			'coin_symbol' => arraySafeVal($coin, 'symbol'),
			'coin_algo' => arraySafeVal($coin, 'algo'),
			'candidate_count' => arraySafeVal($summary, 'candidate_count', 0),
			'projected_total_payout_amount' => arraySafeVal($summary, 'projected_total_payout_amount', 0),
			'blocked_actions' => arraySafeVal($executionBlocked, 'blocked_actions', array()),
			'checksum_note' => 'See top-level report_checksum; generated_at is excluded from checksum input.',
			'checksum_purpose' => 'preview audit comparison only; not payout authorization',
		);
	}

	private function emitFinalReport($report)
	{
		$report = $this->finalizeCommandReport($report);

		if ($this->guard->getFormat() == 'json') {
			// Finalization must stay immediately before output so runtime JSON includes checksum/audit fields.
			echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
			return;
		}

		BadpoolGuardReport::render($report, $this->guard->getFormat());
	}

	private function finalizeCommandReport($report)
	{
		$report = $this->guard->finalizeReport($report);
		if ($this->isPayoutAuditCommand(arraySafeVal($report, 'command'))) {
			$report = $this->ensurePayoutPreviewAuditFields($report);
		}
		$report['report_checksum'] = BadpoolGuardReport::checksum($report);
		return $report;
	}

	private function isPayoutAuditCommand($command)
	{
		return in_array($command, array('payout-candidates-preview', 'payout-row-preflight-preview', 'payout-row-dryrun-plan', 'payable-source-reconciliation-preview', 'account-credit-transition-preview', 'earnings-credit-readiness-preview', 'block-category-maturity-preview'), true);
	}

	private function ensurePayoutPreviewAuditFields($report)
	{
		if (!isset($report['summary']) || !is_array($report['summary'])) {
			$report['summary'] = array();
		}
		if (!isset($report['summary']['audit']) || !is_array($report['summary']['audit'])) {
			$report['summary']['audit'] = $this->payoutPreviewAuditSummary($report);
		}
		if (!isset($report['summary']['audit']['checksum_note'])) {
			$report['summary']['audit']['checksum_note'] = 'See top-level report_checksum; generated_at is excluded from checksum input.';
		}
		if (!isset($report['summary']['audit']['checksum_purpose'])) {
			$report['summary']['audit']['checksum_purpose'] = 'preview audit comparison only; not payout authorization';
		}
		return $report;
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

	private function sumRows($rows, $column)
	{
		if (!is_array($rows)) {
			return 0;
		}
		$total = 0;
		foreach ($rows as $row) {
			if (is_array($row)) {
				$total += intval(arraySafeVal($row, $column, 0));
			}
		}
		return $total;
	}

	private function sumRowsByValue($rows, $keyColumn, $keyValue, $sumColumn)
	{
		return $this->sumRowsByValues($rows, $keyColumn, array($keyValue), $sumColumn);
	}

	private function sumRowsByValues($rows, $keyColumn, $keyValues, $sumColumn)
	{
		if (!is_array($rows)) {
			return 0;
		}
		$allowed = array();
		foreach ($keyValues as $value) {
			$allowed[(string)$value] = true;
		}

		$total = 0;
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$value = (string)arraySafeVal($row, $keyColumn, '');
			if (isset($allowed[$value])) {
				$total += intval(arraySafeVal($row, $sumColumn, 0));
			}
		}
		return $total;
	}

	private function sumRowsFloatByValues($rows, $keyColumn, $keyValues, $sumColumn)
	{
		if (!is_array($rows)) {
			return 0.0;
		}
		$allowed = array();
		foreach ($keyValues as $value) {
			$allowed[(string)$value] = true;
		}

		$total = 0.0;
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$value = (string)arraySafeVal($row, $keyColumn, '');
			if (isset($allowed[$value])) {
				$total += floatval(arraySafeVal($row, $sumColumn, 0));
			}
		}
		return $total;
	}

	private function summaryHasError($value)
	{
		if (!is_array($value)) {
			return false;
		}
		if (isset($value['error'])) {
			return true;
		}
		foreach ($value as $item) {
			if ($this->summaryHasError($item)) {
				return true;
			}
		}
		return false;
	}

	private function fingerprint($value)
	{
		if ($value === null || $value === '') {
			return null;
		}
		return substr(hash('sha256', $value), 0, 16);
	}
}
