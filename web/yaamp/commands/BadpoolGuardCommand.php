<?php

require_once(dirname(__FILE__).'/../core/backend/BadpoolGuardContext.php');
require_once(dirname(__FILE__).'/../core/rpc/wallet-rpc.php');

class BadpoolGuardCommand extends CConsoleCommand
{
	const APPLY_SCHEMA = 'badpool.guardrail.apply.v1';
	const APPLY_MODE = 'guarded-apply';
	const OPERATOR_WEB_CWD = '/srv/badpool/yiimp-badpool/web';
	const FORWARD_CATCHUP_DAEMON_SAMPLE_LIMIT = 5;
	const FORWARD_CATCHUP_APPROVAL_BATCH_SIZE = 25;
	const FORWARD_CATCHUP_APPROVAL_VERSION = '1';
	const FORWARD_CATCHUP_STAGE1_DRYRUN_DEFAULT_LIMIT = 25;
	const FORWARD_CATCHUP_STAGE1_DRYRUN_MAX_LIMIT = 50;
	const FORWARD_CATCHUP_STAGE1_DRAIN_SAFE_MAX_BATCHES = 10;
	const FORWARD_CATCHUP_STAGE1_DRAIN_CONFIRMATION = 'stage1_only_no_later_accounting_no_wallet';

	private $guard;

	private $actions = array(
		'overview',
		'blocks-preview',
		'earnings-preview',
		'account-credit-preview',
		'payout-candidates-preview',
		'payout-row-preflight-preview',
		'payout-row-dryrun-plan',
		'payout-row-approval-package',
		'payout-row-apply',
		'wallet-send-dryrun',
		'wallet-send-approval-package',
		'wallet-send-apply',
		'wallet-proof-closeout',
		'payable-source-reconciliation-preview',
		'account-credit-transition-preview',
		'earnings-credit-readiness-preview',
		'block-category-maturity-preview',
		'earnings-block-reconciliation-preview',
		'maturity-source-verification-preview',
		'forward-catchup-preview',
		'forward-catchup-approval-package',
		'forward-catchup-stage1-apply-dryrun',
		'forward-catchup-stage1-apply-approval-package',
		'forward-catchup-stage1-apply',
		'forward-catchup-stage1-drain-plan',
		'forward-catchup-stage1-drain-apply',
		'earnings-maturity-transition-dryrun',
		'earnings-maturity-transition-approval-package',
		'earnings-maturity-transition-apply',
		'account-credit-clear-dryrun',
		'account-credit-clear-approval-package',
		'account-credit-clear-apply',
		'safety-scan',
		'guard-context',
		'status-runner',
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

		$actionArgs = $args;
		if ($action === 'status-runner') {
			$actionArgs = $this->statusRunnerContextArgs($args);
		}
		if ($action === 'forward-catchup-stage1-apply') {
			$actionArgs = $this->forwardCatchupStage1ApplyContextArgs($args);
		}
		if ($action === 'forward-catchup-stage1-drain-plan' || $action === 'forward-catchup-stage1-drain-apply') {
			$actionArgs = $this->forwardCatchupStage1DrainContextArgs($args);
		}
		if ($action === 'wallet-send-apply') {
			$actionArgs = $this->walletSendApplyContextArgs($args);
		}
		elseif ($action === 'earnings-maturity-transition-apply' || $action === 'account-credit-clear-apply' || $action === 'payout-row-apply') {
			$actionArgs = $this->guardedApplyContextArgs($args);
		}
		$this->guard = BadpoolGuardContext::fromArgs($action, $actionArgs);
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
			case 'payout-row-approval-package':
				$report = $this->payoutRowApprovalPackageReport();
				break;
			case 'payout-row-apply':
				$report = $this->payoutRowApplyReport($args);
				break;
			case 'wallet-send-dryrun':
				$report = $this->walletSendDryrunReport();
				break;
			case 'wallet-send-approval-package':
				$report = $this->walletSendApprovalPackageReport();
				break;
			case 'wallet-send-apply':
				$report = $this->walletSendApplyReport($args);
				break;
			case 'wallet-proof-closeout':
				$report = $this->walletProofCloseoutReport();
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
			case 'earnings-block-reconciliation-preview':
				$report = $this->earningsBlockReconciliationPreviewReport();
				break;
			case 'maturity-source-verification-preview':
				$report = $this->maturitySourceVerificationPreviewReport();
				break;
			case 'forward-catchup-preview':
				$report = $this->forwardCatchupPreviewReport();
				break;
			case 'forward-catchup-approval-package':
				$report = $this->forwardCatchupApprovalPackageReport();
				break;
			case 'forward-catchup-stage1-apply-dryrun':
				$report = $this->forwardCatchupStage1ApplyDryrunReport();
				break;
			case 'forward-catchup-stage1-apply-approval-package':
				$report = $this->forwardCatchupStage1ApplyApprovalPackageReport();
				break;
			case 'forward-catchup-stage1-apply':
				$report = $this->forwardCatchupStage1ApplyReport($args);
				break;
			case 'forward-catchup-stage1-drain-plan':
				$report = $this->forwardCatchupStage1DrainPlanReport($args);
				break;
			case 'forward-catchup-stage1-drain-apply':
				$report = $this->forwardCatchupStage1DrainApplyReport($args);
				break;
			case 'earnings-maturity-transition-dryrun':
				$report = $this->earningsMaturityTransitionDryrunReport();
				break;
			case 'earnings-maturity-transition-approval-package':
				$report = $this->earningsMaturityTransitionApprovalPackageReport();
				break;
			case 'earnings-maturity-transition-apply':
				$report = $this->earningsMaturityTransitionApplyReport($args);
				break;
			case 'account-credit-clear-dryrun':
				$report = $this->accountCreditClearDryrunReport();
				break;
			case 'account-credit-clear-approval-package':
				$report = $this->accountCreditClearApprovalPackageReport();
				break;
			case 'account-credit-clear-apply':
				$report = $this->accountCreditClearApplyReport($args);
				break;
			case 'safety-scan':
				$report = $this->safetyScanReport();
				break;
			case 'guard-context':
				$report = $this->guardContextReport();
				break;
			case 'status-runner':
				$report = $this->statusRunnerReport();
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
			"Run from the web directory, for example: cd /srv/badpool/yiimp-badpool/web\n".
			"Usage: php yaamp/yiic.php badpoolguard overview --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard blocks-preview --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard earnings-preview --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard account-credit-preview --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard payout-candidates-preview --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard payout-row-preflight-preview --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard payout-row-dryrun-plan --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard payout-row-approval-package --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard payout-row-apply --coin-id=<id> --selected-account-ids=<csv> --approval-package-checksum=<sha256> --selected-scope-checksum=<sha256> --projected-payout-row-checksum=<sha256> --projected-account-debit-checksum=<sha256> --operator-confirms-payout-row-creation=scrypt_balance_to_payout_rows_no_wallet_send --format=json\n".
			"       php yaamp/yiic.php badpoolguard wallet-send-dryrun --coin-id=<id> --selected-payout-ids=<csv> --format=json\n".
			"       php yaamp/yiic.php badpoolguard wallet-send-approval-package --coin-id=<id> --selected-payout-ids=<csv> --format=json\n".
			"       php yaamp/yiic.php badpoolguard wallet-send-apply --coin-id=<id> --selected-payout-ids=<csv> --approval-package-checksum=<sha256> --row-inventory-checksum=<sha256> --destination-plan-checksum=<sha256> --projected-total=<decimal> --projected-total-checksum=<sha256> --wallet-send-total=<decimal8> --wallet-send-total-checksum=<sha256> --wallet-send-destination-plan-checksum=<sha256> --operator-confirms-wallet-send=<confirmation-text> --format=json\n".
			"       php yaamp/yiic.php badpoolguard wallet-proof-closeout --coin-id=<id> --selected-payout-ids=<csv> --format=json\n".
			"       php yaamp/yiic.php badpoolguard payable-source-reconciliation-preview --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard account-credit-transition-preview --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard earnings-credit-readiness-preview --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard block-category-maturity-preview --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard earnings-block-reconciliation-preview --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard maturity-source-verification-preview --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard forward-catchup-preview --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard forward-catchup-approval-package --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard forward-catchup-stage1-apply-dryrun --coin-id=<id> [--limit=<n>] [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard forward-catchup-stage1-apply-approval-package --coin-id=<id> [--limit=<n>] [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard forward-catchup-stage1-drain-plan --coin-id=<id> --max-batches=<n> [--batch-limit=<n>] --format=json\n".
			"       php yaamp/yiic.php badpoolguard forward-catchup-stage1-drain-apply --coin-id=<id> --max-batches=<n> [--batch-limit=<n>] --operator-confirms-stage1-drain=stage1_only_no_later_accounting_no_wallet --format=json\n".
			"       php yaamp/yiic.php badpoolguard forward-catchup-stage1-apply --coin-id=<id> --limit=<approved_n> --selected-count=<approved_n> --approval-package-checksum=<sha256> --batch-scope-checksum=<sha256> --projected-mutation-checksum=<sha256> --projected-earnings-checksum=<sha256> --operator-confirms-attribution-model=block_userid_single_recipient --format=json\n".
			"       php yaamp/yiic.php badpoolguard earnings-maturity-transition-dryrun --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard earnings-maturity-transition-approval-package --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard earnings-maturity-transition-apply --coin-id=<id> --selected-earning-ids=<csv> --approval-package-checksum=<sha256> --selected-scope-checksum=<sha256> --projected-block-mutation-checksum=<sha256> --projected-earnings-mutation-checksum=<sha256> --operator-confirms-maturity-transition=scrypt_status0_to_status1 --format=json\n".
			"       php yaamp/yiic.php badpoolguard account-credit-clear-dryrun --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard account-credit-clear-approval-package --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard account-credit-clear-apply --coin-id=<id> --selected-earning-ids=<csv> --approval-package-checksum=<sha256> --selected-earnings-scope-checksum=<sha256> --projected-earnings-mutation-checksum=<sha256> --projected-account-credit-checksum=<sha256> --operator-confirms-account-credit=scrypt_status1_to_status2_balance_increment --format=json\n".
			"       php yaamp/yiic.php badpoolguard safety-scan --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard guard-context --coin-id=<id> [--format=json|text]\n".
			"       php yaamp/yiic.php badpoolguard status-runner [--coin-id=<id>] [--algo=<algo>] --format=json\n".
			"       php yaamp/yiic.php badpoolguard overview --all-coins-preview [--format=json|text]\n\n".
			"Read-only preview only. No wallet reads, wallet sends, DB writes, share cleanup, payout retry/delete, or service actions are available.\n";
	}


	private function statusRunnerReport()
	{
		$report = $this->guard->baseReport();
		$report['schema'] = 'badpool.guardrail.status_runner.v1';
		$report['command_shape'] = 'php yaamp/yiic.php badpoolguard status-runner --format=json';
		$report['optional_filters'] = array('--coin-id=<id>', '--algo=<algo>');
		$report['read_only'] = true;
		$report['wallet_reads'] = false;
		$report['wallet_sends'] = false;
		$report['db_mutations'] = false;
		$report['payout_rows_created'] = false;
		$report['account_credits_created'] = false;
		$report['backend_loops_run'] = false;
		$report['shares_deleted'] = false;
		$report['apply_commands_executed'] = false;
		$report['summary']['coin_count'] = 0;
		$report['summary']['blocked_reason_values'] = $this->statusRunnerBlockedReasonValues();
		$report['summary']['next_safe_action_values'] = $this->statusRunnerNextSafeActionValues();
		$report['items']['algos'] = array();

		foreach ($this->statusRunnerCoinRows() as $coin) {
			$row = $this->statusRunnerAlgoStatus($coin);
			$decision = $this->statusRunnerDecision($row);
			$row['blocked_reason'] = $decision['blocked_reason'];
			$row['next_safe_action'] = $decision['next_safe_action'];
			$report['items']['algos'][] = $row;
		}
		$report['summary']['coin_count'] = count($report['items']['algos']);
		return $this->guard->finalizeReport($report);
	}

	private function statusRunnerContextArgs($args)
	{
		$out = array();
		$hasScope = false;
		foreach ($args as $arg) {
			if (preg_match('/^--coin-id=/i', $arg)) $hasScope = true;
			if (preg_match('/^--(coin-id|algo|format)(=.*)?$/i', $arg)) $out[] = $arg;
		}
		if (!$hasScope) $out[] = '--all-coins-preview';
		return $out;
	}

	private function statusRunnerCoinRows()
	{
		if (!$this->guard->tableExists('coins')) return array();
		$ids = array(1266, 1267, 1268, 1269, 1270);
		$params = array();
		$placeholders = array();
		foreach ($ids as $i => $id) { $k=':id'.$i; $placeholders[]=$k; $params[$k]=$id; }
		$where = 'id IN ('.implode(',', $placeholders).')';
		$scope = $this->guard->getScope();
		if (!arraySafeVal($scope, 'all_coins_preview', false)) { $where .= ' AND id=:coin_id'; $params[':coin_id'] = intval(arraySafeVal($scope, 'coin_id')); }
		$algo = $this->guard->getOption('algo');
		if ($algo !== null) { $where .= ' AND LOWER(algo)=LOWER(:algo)'; $params[':algo'] = (string)$algo; }
		return $this->guard->selectAll("SELECT id, symbol, symbol2, algo FROM coins WHERE $where ORDER BY id", $params);
	}

	private function statusRunnerAlgoStatus($coin)
	{
		$coinId = intval(arraySafeVal($coin, 'id'));
		$row = array(
			'coin_id' => $coinId,
			'symbol' => arraySafeVal($coin, 'symbol', arraySafeVal($coin, 'symbol2', '')),
			'algo' => arraySafeVal($coin, 'algo', ''),
			'blocks_total' => 0,
			'latest_block_height' => null,
			'stage1_selected_count' => 0,
			'stage1_pending_amount' => '0',
			'maturity_selected_count' => 0,
			'maturity_selected_amount' => '0',
			'account_credit_selected_count' => 0,
			'account_credit_projected_total' => '0',
			'payout_candidate_count' => 0,
			'payout_candidate_amount' => '0',
			'account_balance_count' => 0,
			'account_balance_total' => '0',
			'payout_rows_count' => 0,
			'max_payout_id' => null,
			'withdraw_rows_count' => 0,
		);
		if ($this->guard->tableExists('blocks')) {
			$b = $this->guard->selectRow('SELECT COUNT(*) AS c, MAX(height) AS h FROM blocks WHERE coin_id=:coin_id', array(':coin_id'=>$coinId));
			$row['blocks_total'] = intval(arraySafeVal($b, 'c', 0)); $row['latest_block_height'] = arraySafeVal($b, 'h');
			$blockAmount = $this->guard->columnExists('blocks', 'amount') ? 'SUM(amount)' : '0';
			$s = $this->guard->selectRow("SELECT COUNT(*) AS c, $blockAmount AS a FROM blocks WHERE coin_id=:coin_id AND category IN ('new','immature')", array(':coin_id'=>$coinId));
			$row['stage1_selected_count'] = intval(arraySafeVal($s, 'c', 0)); $row['stage1_pending_amount'] = (string)arraySafeVal($s, 'a', '0');
		}
		if ($this->guard->tableExists('earnings')) {
			$m = $this->guard->selectRow('SELECT COUNT(*) AS c, SUM(amount) AS a FROM earnings WHERE coinid=:coin_id AND status=0', array(':coin_id'=>$coinId));
			$row['maturity_selected_count'] = intval(arraySafeVal($m, 'c', 0)); $row['maturity_selected_amount'] = (string)arraySafeVal($m, 'a', '0');
			$ac = $this->guard->selectRow('SELECT COUNT(*) AS c, SUM(amount) AS a FROM earnings WHERE coinid=:coin_id AND status=1', array(':coin_id'=>$coinId));
			$row['account_credit_selected_count'] = intval(arraySafeVal($ac, 'c', 0)); $row['account_credit_projected_total'] = (string)arraySafeVal($ac, 'a', '0');
		}
		if ($this->guard->tableExists('accounts')) {
			$a = $this->guard->selectRow('SELECT COUNT(*) AS c, SUM(balance) AS a FROM accounts WHERE coinid=:coin_id AND balance > 0', array(':coin_id'=>$coinId));
			$row['account_balance_count'] = intval(arraySafeVal($a, 'c', 0)); $row['account_balance_total'] = (string)arraySafeVal($a, 'a', '0');
			$p = $this->statusRunnerPayoutCandidatesForCoin($coinId);
			$row['payout_candidate_count'] = intval(arraySafeVal($p, 'c', 0)); $row['payout_candidate_amount'] = (string)arraySafeVal($p, 'a', '0');
		}
		if ($this->guard->tableExists('payouts')) {
			$p = $this->statusRunnerPayoutRowsForCoin($coinId);
			$row['payout_rows_count'] = intval(arraySafeVal($p, 'c', 0)); $row['max_payout_id'] = arraySafeVal($p, 'max_id');
		}
		if ($this->guard->tableExists('withdraws')) { $w = $this->guard->selectRow('SELECT COUNT(*) AS c FROM withdraws'); $row['withdraw_rows_count'] = intval(arraySafeVal($w, 'c', 0)); }
		return $row;
	}

	private function statusRunnerPayoutCandidatesForCoin($coinId)
	{
		$paymentsMinimum = sprintf('%.12f', defined('YAAMP_PAYMENTS_MINI') ? floatval(YAAMP_PAYMENTS_MINI) : 0.0);
		return $this->guard->selectRow("SELECT COUNT(*) AS c, SUM(A.balance) AS a FROM accounts A INNER JOIN coins C ON C.id=A.coinid WHERE A.coinid=:coin_id AND A.balance > GREATEST($paymentsMinimum, IFNULL(C.payout_min,0), IFNULL(C.txfee,0))", array(':coin_id'=>$coinId));
	}

	private function statusRunnerPayoutRowsForCoin($coinId)
	{
		$completedWhere = $this->guard->columnExists('payouts', 'completed') ? ' AND IFNULL(completed,0)=0' : '';
		if ($this->guard->columnExists('payouts', 'idcoin')) return $this->guard->selectRow("SELECT COUNT(*) AS c, MAX(id) AS max_id FROM payouts WHERE idcoin=:coin_id$completedWhere", array(':coin_id'=>$coinId));
		if (!$this->guard->columnExists('payouts', 'account_id') || !$this->guard->tableExists('accounts')) return array('c'=>0, 'max_id'=>null);
		$completedWhere = $this->guard->columnExists('payouts', 'completed') ? ' AND IFNULL(P.completed,0)=0' : '';
		return $this->guard->selectRow("SELECT COUNT(*) AS c, MAX(P.id) AS max_id FROM payouts P INNER JOIN accounts A ON A.id=P.account_id WHERE A.coinid=:coin_id$completedWhere", array(':coin_id'=>$coinId));
	}

	private function statusRunnerDecision($row)
	{
		if (intval($row['blocks_total']) <= 0) return array('blocked_reason'=>'no_blocks', 'next_safe_action'=>'none');
		$signals = 0;
		foreach (array('stage1_selected_count','maturity_selected_count','account_credit_selected_count','payout_candidate_count','payout_rows_count') as $k) if (intval($row[$k]) > 0) $signals++;
		if ($signals > 1) return array('blocked_reason'=>'hold_unknown', 'next_safe_action'=>'investigate_hold');
		if (intval($row['payout_rows_count']) > 0) return array('blocked_reason'=>'wallet_send_ready', 'next_safe_action'=>'run_wallet_send_package');
		if (intval($row['payout_candidate_count']) > 0) return array('blocked_reason'=>'payout_row_ready', 'next_safe_action'=>'run_payout_row_package');
		if (intval($row['account_credit_selected_count']) > 0) return array('blocked_reason'=>'account_credit_ready', 'next_safe_action'=>'run_account_credit_package');
		if (intval($row['maturity_selected_count']) > 0) return array('blocked_reason'=>'maturity_ready', 'next_safe_action'=>'run_maturity_package');
		if (intval($row['stage1_selected_count']) > 0) return array('blocked_reason'=>'stage1_ready', 'next_safe_action'=>'run_stage1_package');
		return array('blocked_reason'=>'no_current_action', 'next_safe_action'=>'none');
	}

	private function statusRunnerBlockedReasonValues()
	{
		return array('no_blocks','no_current_action','stage1_ready','maturity_wait','maturity_ready','payment_delay_wait','account_credit_ready','payout_row_ready','wallet_send_ready','unresolved_attribution','hold_unknown');
	}

	private function statusRunnerNextSafeActionValues()
	{
		return array('none','run_stage1_package','run_maturity_package','wait_payment_delay','run_account_credit_package','run_payout_row_package','run_wallet_send_package','investigate_hold');
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

	private function earningsBlockReconciliationPreviewReport()
	{
		if ($this->guard->isAllCoinsPreview()) {
			$this->guard->addError('earnings-block-reconciliation-preview requires --coin-id and refuses all-coin scope.');
			return $this->guard->refusalReport();
		}

		$report = $this->guard->baseReport();
		$scope = $this->guard->getScope();
		$coin = arraySafeVal($scope, 'coin', array());
		$rowTotals = $this->earningsBlockReconciliationRowTotals();
		$blockLinkage = $this->earningsBlockReconciliationBlockLinkage();
		$rowsPerBlock = $this->earningsBlockReconciliationRowsPerBlock();
		$linkedBlocks = $this->earningsBlockReconciliationLinkedBlocks();
		$explanation = $this->earningsBlockReconciliationDifferenceExplanation($rowTotals, $blockLinkage, $rowsPerBlock);
		$classification = $this->earningsBlockReconciliationClassification($blockLinkage, $rowsPerBlock, $linkedBlocks);

		$report['summary']['coin'] = array(
			'coin_id' => arraySafeVal($scope, 'coin_id'),
			'symbol' => arraySafeVal($coin, 'symbol'),
			'algo' => arraySafeVal($coin, 'algo'),
		);
		$report['summary']['earnings_row_totals'] = $rowTotals;
		$report['summary']['block_linkage'] = $blockLinkage;
		$report['summary']['rows_per_block_distribution'] = $rowsPerBlock;
		$report['summary']['linked_blocks'] = $linkedBlocks;
		$report['summary']['row_count_difference_explanation'] = $explanation;
		$report['summary']['reconciliation_classification'] = $classification;
		$report['summary']['blockers'] = $this->earningsBlockReconciliationBlockers($classification, $explanation);
		$report['summary']['proposed_future_stages'] = $this->earningsBlockReconciliationStages();
		$report['summary']['execution_blocked'] = $this->earningsBlockReconciliationBlockedMetadata();
		$report['summary']['audit'] = $this->earningsBlockReconciliationAuditSummary($report);

		$report = $this->guard->finalizeReport($report);
		$report = $this->ensurePayoutPreviewAuditFields($report);
		$report['report_checksum'] = BadpoolGuardReport::checksum($report);
		return $report;
	}

	private function maturitySourceVerificationPreviewReport()
	{
		if ($this->guard->isAllCoinsPreview()) {
			$this->guard->addError('maturity-source-verification-preview requires --coin-id and refuses all-coin scope.');
			return $this->guard->refusalReport();
		}

		$report = $this->guard->baseReport();
		$scope = $this->guard->getScope();
		$coin = arraySafeVal($scope, 'coin', array());
		$coinFields = $this->maturitySourceCoinFields();
		$blockEvidence = $this->maturitySourceBlocksEvidence();
		$linkedRange = $this->maturitySourceLinkedImmatureRange();
		$delta = $this->maturitySourceDelta($coinFields);
		$confidence = $this->maturitySourceConfidence($coinFields, $blockEvidence);
		$decision = $this->maturitySourceDecision($coinFields, $delta, $confidence);

		$report['summary']['coin'] = array(
			'coin_id' => arraySafeVal($scope, 'coin_id'),
			'symbol' => arraySafeVal($coin, 'symbol'),
			'algo' => arraySafeVal($coin, 'algo'),
		);
		$report['summary']['source_scope'] = array(
			'db_only' => true,
			'daemon_rpc_used' => false,
			'wallet_rpc_used' => false,
			'message' => 'This preview inspects repository database state only. It does not query wallets, daemons, systemd, services, or logs.',
		);
		$report['summary']['coin_maturity_current_height_fields'] = $coinFields;
		$report['summary']['blocks_table_height_evidence'] = $blockEvidence;
		$report['summary']['linked_immature_blocks_for_status_0_1_earnings'] = $linkedRange;
		$report['summary']['maturity_delta'] = $delta;
		$report['summary']['source_confidence'] = $confidence;
		$report['summary']['conservative_decision'] = $decision;
		$report['summary']['blockers'] = $this->maturitySourceBlockers($coinFields, $blockEvidence, $confidence);
		$report['summary']['proposed_future_stages'] = $this->maturitySourceStages();
		$report['summary']['execution_blocked'] = $this->maturitySourceBlockedMetadata();
		$report['summary']['audit'] = $this->maturitySourceAuditSummary($report);

		$report = $this->guard->finalizeReport($report);
		$report = $this->ensurePayoutPreviewAuditFields($report);
		$report['report_checksum'] = BadpoolGuardReport::checksum($report);
		return $report;
	}

	private function forwardCatchupPreviewReport()
	{
		if ($this->guard->isAllCoinsPreview()) {
			$this->guard->addError('forward-catchup-preview requires --coin-id and refuses all-coin scope.');
			return $this->guard->refusalReport();
		}

		$report = $this->guard->baseReport();
		$model = $this->forwardCatchupModel();
		$report['daemon_rpc_used'] = arraySafeVal($model['daemon_sample'], 'daemon_rpc_used', false);
		$report['backend_loops_run'] = false;
		$report['summary']['coin'] = $model['coin'];
		$report['summary']['forward_checkpoint'] = $model['checkpoint'];
		$report['summary']['forward_block_window'] = $model['block_window'];
		$report['summary']['import_candidates'] = $model['import_candidates'];
		$report['summary']['daemon_read_only_sample'] = $model['daemon_sample'];
		$report['summary']['projected_stages'] = $this->forwardCatchupProjectedStages();
		$report['summary']['safety_classification'] = $model['safety'];
		$report['summary']['execution_blocked'] = $this->forwardCatchupBlockedMetadata($model['daemon_sample']);
		$report['summary']['audit'] = $this->forwardCatchupAuditSummary($report);

		$report = $this->guard->finalizeReport($report);
		$report = $this->ensurePayoutPreviewAuditFields($report);
		$report['report_checksum'] = BadpoolGuardReport::checksum($report);
		return $report;
	}

	private function forwardCatchupApprovalPackageReport()
	{
		if ($this->guard->isAllCoinsPreview()) {
			$this->guard->addError('forward-catchup-approval-package requires --coin-id and refuses all-coin scope.');
			return $this->guard->refusalReport();
		}

		$report = $this->guard->baseReport();
		$model = $this->forwardCatchupModel();
		$previewEquivalent = $this->forwardCatchupPreviewEquivalentReport($model);
		$checkpoint = $model['checkpoint'];
		$lastPayoutTime = arraySafeVal($checkpoint, 'last_payout_time');
		$candidateSummary = $this->forwardCatchupApprovalCandidateSummary($lastPayoutTime);
		$batchPlan = $this->forwardCatchupApprovalBatchPlan($lastPayoutTime);
		$mutationScope = $this->forwardCatchupApprovalMutationScope($checkpoint, $candidateSummary);
		$previewDependency = array(
			'latest_forward_catchup_preview_checksum_if_recomputed' => arraySafeVal($previewEquivalent, 'report_checksum'),
			'preview_equivalent_checksum' => BadpoolGuardReport::checksum($previewEquivalent),
			'preview_command' => 'forward-catchup-preview',
			'note' => 'Preview-equivalent checksum is recomputed from the same read-only data model used by this approval package.',
		);
		$approvalIdentity = $this->forwardCatchupApprovalIdentity();
		$approvalInputChecksum = BadpoolGuardReport::checksum(array(
			'approval_package_identity' => $approvalIdentity,
			'checkpoint' => array(
				'last_payout_time' => arraySafeVal($checkpoint, 'last_payout_time'),
				'checkpoint_source' => arraySafeVal($checkpoint, 'checkpoint_source'),
				'payout_row_count' => arraySafeVal($checkpoint, 'payout_row_count'),
				'payout_amount' => arraySafeVal($checkpoint, 'payout_amount'),
			),
			'candidate_summary' => $candidateSummary,
			'batch_plan' => $batchPlan,
			'daemon_sample_summary' => $this->forwardCatchupApprovalDaemonSampleSummary($model['daemon_sample']),
		));
		$intendedMutationScopeChecksum = BadpoolGuardReport::checksum($mutationScope);

		$report['daemon_rpc_used'] = arraySafeVal($model['daemon_sample'], 'daemon_rpc_used', false);
		$report['backend_loops_run'] = false;
		$report['approval_input_checksum'] = $approvalInputChecksum;
		$report['intended_mutation_scope_checksum'] = $intendedMutationScopeChecksum;
		$report['summary']['coin'] = $model['coin'];
		$report['summary']['checkpoint'] = array(
			'last_payout_time' => arraySafeVal($checkpoint, 'last_payout_time'),
			'checkpoint_source' => arraySafeVal($checkpoint, 'checkpoint_source'),
			'payout_row_count' => arraySafeVal($checkpoint, 'payout_row_count'),
			'payout_amount' => arraySafeVal($checkpoint, 'payout_amount'),
		);
		$report['summary']['preview_dependency'] = $previewDependency;
		$report['summary']['approval_package_identity'] = $approvalIdentity;
		$report['summary']['proposed_mutation_scope'] = $mutationScope;
		$report['summary']['candidate_summary'] = $candidateSummary;
		$report['summary']['batch_plan'] = $batchPlan;
		$report['summary']['daemon_sample_summary'] = $this->forwardCatchupApprovalDaemonSampleSummary($model['daemon_sample']);
		$report['summary']['exact_future_apply_intent_blocked'] = $this->forwardCatchupApprovalApplyIntent();
		$report['summary']['blocked_future_stages'] = $this->forwardCatchupApprovalBlockedStages();
		$report['summary']['safety_metadata'] = $this->forwardCatchupApprovalSafetyMetadata($model);
		$report['summary']['safety_classification'] = array(
			'forward_catchup_needed' => arraySafeVal($model['safety'], 'forward_catchup_needed', false),
			'broad_backend_loop_required' => false,
			'recommended_next_stage' => 'forward-catchup-stage-1-apply-design',
			'wallet_or_payout_stage_recommended' => false,
		);
		$report['summary']['execution_blocked'] = $this->forwardCatchupApprovalBlockedMetadata($model['daemon_sample']);
		$report['summary']['approval_checksums'] = array(
			'approval_input_checksum' => $approvalInputChecksum,
			'intended_mutation_scope_checksum' => $intendedMutationScopeChecksum,
			'purpose' => 'approval comparison only; not payout authorization and not execution authorization',
		);
		$report['summary']['audit'] = $this->forwardCatchupApprovalAuditSummary($report);

		$report = $this->guard->finalizeReport($report);
		$report = $this->ensurePayoutPreviewAuditFields($report);
		$report['report_checksum'] = BadpoolGuardReport::checksum($report);
		return $report;
	}

	private function forwardCatchupStage1ApplyDryrunReport()
	{
		if ($this->guard->isAllCoinsPreview()) {
			$this->guard->addError('forward-catchup-stage1-apply-dryrun requires --coin-id and refuses all-coin scope.');
			return $this->guard->refusalReport();
		}

		$limit = $this->forwardCatchupStage1DryrunLimit();
		if ($limit === false) {
			return $this->guard->refusalReport();
		}

		$checkpoint = $this->forwardCatchupCheckpoint();
		$lastPayoutTime = arraySafeVal($checkpoint, 'last_payout_time');
		$candidates = $this->forwardCatchupStage1DryrunCandidates($lastPayoutTime, $limit);
		$classified = $this->forwardCatchupStage1DryrunClassify($candidates);
		$plan = $this->forwardCatchupStage1DryrunPlan($classified);
		$totals = $this->forwardCatchupStage1DryrunTotals($classified, $plan);
		$safetyGates = $this->forwardCatchupStage1DryrunSafetyGates($checkpoint, $classified, $plan, $limit);
		$batchScope = array(
			'coin_id' => arraySafeVal($this->guard->getScope(), 'coin_id'),
			'checkpoint' => $lastPayoutTime,
			'limit' => $limit,
			'blocks' => $this->forwardCatchupStage1BatchScopeBlocks($candidates),
		);

		$report = $this->guard->baseReport();
		$report['wallet_reads'] = count($candidates) > 0;
		$report['wallet_sends'] = false;
		$report['db_mutations'] = false;
		$report['account_credit'] = false;
		$report['payout_rows_created'] = false;
		$report['backend_loops_run'] = false;
		$report['daemon_rpc_used'] = count($candidates) > 0;
		$report['dryrun_input_checksum'] = BadpoolGuardReport::checksum(array(
			'coin_id' => arraySafeVal($this->guard->getScope(), 'coin_id'),
			'limit' => $limit,
			'checkpoint' => $lastPayoutTime,
		));
		$report['batch_scope_checksum'] = BadpoolGuardReport::checksum($batchScope);
		$report['projected_mutation_checksum'] = BadpoolGuardReport::checksum($this->forwardCatchupStage1StableProjectedMutations(arraySafeVal($plan, 'projected_block_mutations', array())));
		$report['projected_earnings_checksum'] = BadpoolGuardReport::checksum(arraySafeVal($plan, 'projected_pending_earnings', array()));
		$report['summary']['checkpoint'] = $checkpoint;
		$report['summary']['limit'] = array(
			'requested' => $limit,
			'default' => self::FORWARD_CATCHUP_STAGE1_DRYRUN_DEFAULT_LIMIT,
			'max' => self::FORWARD_CATCHUP_STAGE1_DRYRUN_MAX_LIMIT,
		);
		$report['summary']['candidate_selection'] = array(
			'criteria' => array(
				'blocks.coin_id selected coin',
				"blocks.category='new'",
				'blocks.time greater than checkpoint',
				'blockhash present',
			),
			'ordering' => $this->guard->columnExists('blocks', 'height') ? 'height,time,id' : 'time,id',
		);
		$report['summary']['warnings'] = array(
			'attribution_model' => 'block_userid_single_recipient',
			'attribution_model_requires_operator_confirmation' => true,
			'historical_evidence_mixed' => true,
			'backendblocknew_not_used' => true,
			'fee_policy' => 'not_applied_in_dryrun',
		);
		$report['summary']['totals'] = $totals;
		$report['summary']['safety_gates'] = $safetyGates;
		$report['summary']['apply_constraints'] = array(
			'exact_batch_boundary_required' => true,
			'approval_checksum_required' => true,
			'batch_checksum_required' => true,
			'operator_attribution_confirmation_required' => true,
			'abort_if_linked_earnings_already_exist' => true,
			"abort_if_block_no_longer_category_new" => true,
			'abort_if_daemon_classification_changes' => true,
			'confirmation_count_excluded_from_authorization_checksum' => true,
			'current_confirmation_count_written_at_execution_time' => true,
			'orphan_creates_no_earnings' => true,
		);
		$report['summary']['recommended_next_stage'] = 'forward-catchup-stage1-apply-approval';
		$report['items']['candidates'] = $classified;
		$report['items']['projected_block_mutations'] = arraySafeVal($plan, 'projected_block_mutations', array());
		$report['items']['projected_pending_earnings'] = arraySafeVal($plan, 'projected_pending_earnings', array());

		return $this->guard->finalizeReport($report);
	}

	private function forwardCatchupStage1ApplyApprovalPackageReport()
	{
		if ($this->guard->isAllCoinsPreview()) {
			$this->guard->addError('forward-catchup-stage1-apply-approval-package requires --coin-id and refuses all-coin scope.');
			return $this->guard->refusalReport();
		}

		$dryrun = $this->forwardCatchupStage1ApplyDryrunReport();
		if (!$this->guard->isValid()) {
			return $dryrun;
		}

		$totals = arraySafeVal(arraySafeVal($dryrun, 'summary', array()), 'totals', array());
		$safetyGates = arraySafeVal(arraySafeVal($dryrun, 'summary', array()), 'safety_gates', array());
		$approvalInput = array(
			'approval_package_type' => 'forward-catchup-stage1-apply',
			'approval_package_version' => 1,
			'coin_id' => arraySafeVal($this->guard->getScope(), 'coin_id'),
			'dryrun_input_checksum' => arraySafeVal($dryrun, 'dryrun_input_checksum'),
			'batch_scope_checksum' => arraySafeVal($dryrun, 'batch_scope_checksum'),
			'projected_mutation_checksum' => arraySafeVal($dryrun, 'projected_mutation_checksum'),
			'projected_earnings_checksum' => arraySafeVal($dryrun, 'projected_earnings_checksum'),
			'dryrun_report_checksum' => BadpoolGuardReport::checksum($dryrun),
			'operator_must_confirm_attribution_model' => true,
			'attribution_model' => 'block_userid_single_recipient',
		);
		$batchScopeChecksumValue = arraySafeVal(arraySafeVal($dryrun, 'batch_scope_checksum', array()), 'value');
		$projectedMutationChecksumValue = arraySafeVal(arraySafeVal($dryrun, 'projected_mutation_checksum', array()), 'value');
		$projectedEarningsChecksumValue = arraySafeVal(arraySafeVal($dryrun, 'projected_earnings_checksum', array()), 'value');
		$applyCommandShape = array(
			'cd', self::OPERATOR_WEB_CWD, '&&', 'php', 'yaamp/yiic.php', 'badpoolguard', 'forward-catchup-stage1-apply',
			'--coin-id='.arraySafeVal($this->guard->getScope(), 'coin_id'),
			'--limit='.arraySafeVal(arraySafeVal(arraySafeVal($dryrun, 'summary', array()), 'limit', array()), 'requested'),
			'--selected-count='.arraySafeVal($totals, 'selected_count'),
			'--approval-package-checksum=<approval_package_checksum>',
			'--batch-scope-checksum='.$batchScopeChecksumValue,
			'--projected-mutation-checksum='.$projectedMutationChecksumValue,
			'--projected-earnings-checksum='.$projectedEarningsChecksumValue,
			'--operator-confirms-attribution-model=block_userid_single_recipient',
		);
		$applyScopeBinding = 'Apply requires the reviewed --limit and --selected-count; the selected batch is bound by approval, batch scope, projected mutation, and projected earnings checksums and must not fall back to the default limit.';
		$mandatoryApplyGates = array(
			'current block category must still be new',
			'no linked earnings',
			'daemon classification must still match this approval package',
			'orphan rows create no earnings',
			'no account credit',
			'no payout rows',
			'no wallet sends',
		);
		$intendedMutationScope = array(
			"generate/immature rows update block txhash, amount, confirmations, category='immature'",
			'generate/immature rows insert one pending earning with status=0',
			"orphan rows update block category='orphan'",
			'orphan rows create no earnings',
			'no account credit',
			'no payout rows',
			'no wallet sends',
		);
		$approvalInputChecksum = BadpoolGuardReport::checksum($approvalInput);
		$intendedMutationScopeChecksum = BadpoolGuardReport::checksum(array(
			'read_only_approval_package' => true,
			'apply_command_implemented' => true,
			'no_db_writes' => true,
			'no_account_credit' => true,
			'no_payout_rows' => true,
			'no_wallet_sends' => true,
			'no_backend_loops' => true,
			'batch_scope_checksum' => arraySafeVal($dryrun, 'batch_scope_checksum'),
			'projected_mutation_checksum' => arraySafeVal($dryrun, 'projected_mutation_checksum'),
			'projected_earnings_checksum' => arraySafeVal($dryrun, 'projected_earnings_checksum'),
			'apply_command_shape' => $applyCommandShape,
			'apply_scope_binding' => $applyScopeBinding,
			'mandatory_apply_gates' => $mandatoryApplyGates,
			'intended_mutation_scope' => $intendedMutationScope,
		));

		$report = $dryrun;
		$report['approval_package_type'] = 'forward-catchup-stage1-apply';
		$report['approval_package_version'] = 1;
		$report['approval_required'] = true;
		$report['apply_command_implemented'] = true;
		$report['operator_must_confirm_attribution_model'] = true;
		$report['attribution_model'] = 'block_userid_single_recipient';
		$report['backendblocknew_not_used'] = true;
		$report['fee_policy'] = 'not_applied_in_dryrun';
		$report['selected_count'] = arraySafeVal($totals, 'selected_count');
		$report['selected_records'] = $this->stage1ApprovalSelectedRecords($report);
		$report['selected_amount'] = arraySafeVal($totals, 'projected_pending_earnings_amount_gross');
		$report['first_height'] = arraySafeVal($totals, 'first_height');
		$report['last_height'] = arraySafeVal($totals, 'last_height');
		$report['projected_pending_earnings_rows'] = arraySafeVal($totals, 'projected_pending_earnings_rows');
		$report['projected_pending_earnings_amount_gross'] = arraySafeVal($totals, 'projected_pending_earnings_amount_gross');
		$report['projected_orphan_excluded_amount'] = arraySafeVal($totals, 'projected_orphan_excluded_amount');
		$report['approval_input_checksum'] = $approvalInputChecksum;
		$report['intended_mutation_scope_checksum'] = $intendedMutationScopeChecksum;
		$report['intended_apply_command_shape'] = $applyCommandShape;
		$report['apply_command_args'] = $applyCommandShape;
		$report['apply_scope_binding'] = $applyScopeBinding;
		$report['mandatory_apply_gates'] = $mandatoryApplyGates;
		$report['intended_mutation_scope'] = $intendedMutationScope;
		$report['overall_approval_package_status'] = arraySafeVal($safetyGates, 'overall_dryrun_status') === 'pass' ? 'pass' : 'blocked';
		$report['recommended_next_stage'] = 'forward-catchup-stage1-apply-command-design';
		$report['stable_authorization'] = array(
			'checksum_fields' => array(
				'approval_package_checksum',
				'batch_scope_checksum',
				'projected_mutation_checksum',
				'projected_earnings_checksum',
			),
			'volatile_fields_excluded' => array(
				'generated_at',
				'report_checksum',
				'live_daemon_confirmation_count',
			),
			'confirmation_policy' => 'confirmations are excluded from authorization checksums and current daemon confirmations are written at execution time after stable gates pass',
			'manual_validation_note' => 'In environments without a safe local fixture, validate by regenerating an approval package, waiting for confirmation drift, and confirming apply still passes stable checksum gates before mutation.',
		);
		$report['summary']['approval_package'] = array(
			'approval_package_type' => $report['approval_package_type'],
			'approval_package_version' => $report['approval_package_version'],
			'approval_required' => $report['approval_required'],
			'apply_command_implemented' => $report['apply_command_implemented'],
			'intended_apply_command_shape' => $applyCommandShape,
			'apply_scope_binding' => $applyScopeBinding,
			'mandatory_apply_gates' => $mandatoryApplyGates,
			'intended_mutation_scope' => $intendedMutationScope,
			'overall_approval_package_status' => $report['overall_approval_package_status'],
			'recommended_next_stage' => $report['recommended_next_stage'],
		);
		$this->standardizeApprovalPackageContract($report, 'forward-catchup-stage1-apply', array('approval_package_checksum','batch_scope_checksum','projected_mutation_checksum','projected_earnings_checksum','approval_input_checksum','intended_mutation_scope_checksum'));
		unset($report['report_checksum']);
		$report['approval_package_checksum'] = $this->forwardCatchupStage1StableApprovalPackageChecksum($report);
		$this->standardizeApprovalPackageContract($report, 'forward-catchup-stage1-apply', array('approval_package_checksum','batch_scope_checksum','projected_mutation_checksum','projected_earnings_checksum','approval_input_checksum','intended_mutation_scope_checksum'));
		$report['report_checksum'] = BadpoolGuardReport::checksum($report);
		return $report;
	}

	private function forwardCatchupStage1ApplyReport($args)
	{
		$options = $this->forwardCatchupStage1ApplyOptions($args);
		$report = $this->forwardCatchupStage1ApplyBaseReport($options);
		$required = array('limit', 'selected-count', 'approval-package-checksum', 'batch-scope-checksum', 'projected-mutation-checksum', 'projected-earnings-checksum');

		if (isset($options['__parse_error'])) {
			return $this->forwardCatchupStage1ApplyFail($report, 'invalid_option', $options['__parse_error']);
		}
		if ($this->guard->isAllCoinsPreview()) {
			return $this->forwardCatchupStage1ApplyFail($report, 'coin_id_required', 'forward-catchup-stage1-apply requires --coin-id and refuses all-coin scope.');
		}
		if ($this->guard->getFormat() !== 'json') {
			return $this->forwardCatchupStage1ApplyFail($report, 'json_format_required', 'forward-catchup-stage1-apply supports --format=json only.');
		}
		foreach ($required as $name) {
			if (!isset($options[$name]) || $options[$name] === '') {
				return $this->forwardCatchupStage1ApplyFail($report, 'missing_required_checksum', 'Missing required --'.$name.'.');
			}
		}
		if (!preg_match('/^[0-9]+$/', (string)arraySafeVal($options, 'selected-count')) || intval(arraySafeVal($options, 'selected-count')) <= 0) {
			return $this->forwardCatchupStage1ApplyFail($report, 'invalid_selected_count', 'Invalid --selected-count. Expected the positive selected_count from the reviewed approval package.');
		}
		if (arraySafeVal($options, 'operator-confirms-attribution-model') !== 'block_userid_single_recipient') {
			return $this->forwardCatchupStage1ApplyFail($report, 'attribution_confirmation_required', 'Missing exact --operator-confirms-attribution-model=block_userid_single_recipient.');
		}

		$approval = $this->forwardCatchupStage1ApplyApprovalPackageReport();
		if (!$this->guard->isValid()) {
			return $this->forwardCatchupStage1ApplyFail($report, 'approval_package_recompute_failed', 'Fresh approval package recompute failed.');
		}

		$report = $this->forwardCatchupStage1ApplyPopulateFromApproval($report, $approval);
		$report['package_selected_count'] = intval(arraySafeVal($options, 'selected-count'));
		$report['regenerated_selected_count'] = intval(arraySafeVal($report, 'selected_count', 0));
		$report['validation_limit'] = intval(arraySafeVal($options, 'limit', 0));
		if ($report['package_selected_count'] !== $report['regenerated_selected_count']) {
			return $this->forwardCatchupStage1ApplyFail($report, 'scope_mismatch', 'Approved package selected_count does not match regenerated selected_count: package_selected_count='.(string)$report['package_selected_count'].' regenerated_selected_count='.(string)$report['regenerated_selected_count'].'.');
		}
		$checks = array(
			'approval-package-checksum' => arraySafeVal(arraySafeVal($approval, 'approval_package_checksum', array()), 'value'),
			'batch-scope-checksum' => arraySafeVal(arraySafeVal($approval, 'batch_scope_checksum', array()), 'value'),
			'projected-mutation-checksum' => arraySafeVal(arraySafeVal($approval, 'projected_mutation_checksum', array()), 'value'),
			'projected-earnings-checksum' => arraySafeVal(arraySafeVal($approval, 'projected_earnings_checksum', array()), 'value'),
		);
		foreach ($checks as $name => $actual) {
			if ((string)arraySafeVal($options, $name) !== (string)$actual) {
				return $this->forwardCatchupStage1ApplyFail($report, 'checksum_mismatch', 'Expected checksum does not match freshly generated approval package state: --'.$name.'.');
			}
		}
		if (arraySafeVal($approval, 'overall_approval_package_status') !== 'pass') {
			return $this->forwardCatchupStage1ApplyFail($report, 'approval_package_blocked', 'Fresh approval package status is not pass.');
		}

		$classified = arraySafeVal(arraySafeVal($approval, 'items', array()), 'candidates', array());
		$mutations = arraySafeVal(arraySafeVal($approval, 'items', array()), 'projected_block_mutations', array());
		$earnings = arraySafeVal(arraySafeVal($approval, 'items', array()), 'projected_pending_earnings', array());
		$gate = $this->forwardCatchupStage1ApplyPreflightGates($classified, $mutations, $earnings);
		if (arraySafeVal($gate, 'status') !== 'pass') {
			return $this->forwardCatchupStage1ApplyFail($report, arraySafeVal($gate, 'abort_reason'), arraySafeVal($gate, 'message'));
		}

		$tx = app()->db->beginTransaction();
		if (!$tx) {
			return $this->forwardCatchupStage1ApplyFail($report, 'transaction_unavailable', 'Database transaction mechanism is unavailable; refusing partial writes.');
		}
		try {
			$applied = $this->forwardCatchupStage1ApplyMutations($mutations, $earnings);
			$tx->commit();
			$report['applied_generated_count'] = $applied['applied_generated_count'];
			$report['applied_orphan_count'] = $applied['applied_orphan_count'];
			$report['inserted_earnings_count'] = $applied['inserted_earnings_count'];
			$report['db_mutations'] = $this->forwardCatchupStage1ApplyDidMutate($applied);
			$report['command_validation_status'] = 'pass';
			$report['db_mutation_status'] = $report['db_mutations'] ? 'performed' : 'none';
			$verification = $this->forwardCatchupStage1ApplyCloseoutVerification($mutations, $applied);
			$report['post_apply_db_verification'] = $verification;
			$report['post_apply_db_verification_status'] = arraySafeVal($verification, 'status');
			$report['final_batch_reconciliation_status'] = arraySafeVal($verification, 'status') === 'pass' ? 'pass' : 'hold';
			if (arraySafeVal($verification, 'status') === 'pass') {
				$report['status'] = 'pass';
				$report['abort_reason'] = null;
			} else {
				$report['status'] = 'hold';
				$report['abort_reason'] = 'post_apply_closeout_verification_failed';
				$report['affected_block_ids'] = arraySafeVal($verification, 'affected_block_ids', array());
				$report['manual_verification_required'] = true;
			}
			return $report;
		} catch (Exception $e) {
			if ($tx->active) {
				$tx->rollback();
			}
			return $this->forwardCatchupStage1ApplyFail($report, 'mutation_failed_rolled_back', $e->getMessage());
		}
	}

	private function forwardCatchupStage1ApplyContextArgs($args)
	{
		$allowed = array('coin-id', 'format', 'limit');
		$result = array();
		foreach ($args as $arg) {
			if (preg_match('/^--([^=]+)(=.*)?$/', $arg, $m) && in_array(strtolower($m[1]), $allowed, true)) {
				$result[] = $arg;
			}
		}
		return $result;
	}

	private function forwardCatchupStage1ApplyOptions($args)
	{
		$options = array();
		$allowed = array('coin-id', 'format', 'limit', 'selected-count', 'approval-package-checksum', 'batch-scope-checksum', 'projected-mutation-checksum', 'projected-earnings-checksum', 'operator-confirms-attribution-model');
		foreach ($args as $arg) {
			if (preg_match('/^--([^=]+)=(.*)$/', $arg, $m)) {
				$name = strtolower($m[1]);
				if (!in_array($name, $allowed, true)) {
					$options['__parse_error'] = 'Unknown option refused: --'.$m[1];
				} elseif (isset($options[$name])) {
					$options['__parse_error'] = 'Duplicate option refused: --'.$m[1];
				} else {
					$options[$name] = $m[2];
				}
			} elseif (strpos($arg, '--') === 0) {
				$options['__parse_error'] = 'Option requires an explicit value: '.$arg;
			}
		}
		return $options;
	}

	private function forwardCatchupStage1ApplyBaseReport($options)
	{
		$report = $this->applyBaseReport('forward-catchup-stage1-apply', 'fail');
		$report['read_only'] = false;
		$report['stage'] = 'forward-catchup-stage1-apply';
		$report['coin_id'] = arraySafeVal($this->guard->getScope(), 'coin_id');
		$report['selected_count'] = 0;
		$report['package_selected_count'] = arraySafeVal($options, 'selected-count');
		$report['regenerated_selected_count'] = 0;
		$report['validation_limit'] = arraySafeVal($options, 'limit');
		$report['applied_generated_count'] = 0;
		$report['applied_orphan_count'] = 0;
		$report['inserted_earnings_count'] = 0;
		$report['db_mutations'] = false;
		$report['command_validation_status'] = 'not_passed';
		$report['db_mutation_status'] = 'none';
		$report['post_apply_db_verification_status'] = 'not_run';
		$report['final_batch_reconciliation_status'] = 'not_reconciled';
		$report['projected_pending_earnings_rows'] = 0;
		$report['projected_pending_earnings_amount_gross'] = 0.0;
		$report['approval_package_checksum'] = arraySafeVal($options, 'approval-package-checksum');
		$report['batch_scope_checksum'] = arraySafeVal($options, 'batch-scope-checksum');
		$report['projected_mutation_checksum'] = arraySafeVal($options, 'projected-mutation-checksum');
		$report['projected_earnings_checksum'] = arraySafeVal($options, 'projected-earnings-checksum');
		$report['attribution_model'] = arraySafeVal($options, 'operator-confirms-attribution-model');
		$report['account_credit'] = false;
		$report['payout_rows_created'] = false;
		$report['wallet_sends'] = false;
		$report['backend_loops_run'] = false;
		$report['shares_deleted'] = false;
		$report['abort_reason'] = null;
		return $report;
	}

	private function forwardCatchupStage1ApplyPopulateFromApproval($report, $approval)
	{
		$totals = arraySafeVal(arraySafeVal($approval, 'summary', array()), 'totals', array());
		$report['selected_count'] = intval(arraySafeVal($totals, 'selected_count', 0));
		$report['projected_pending_earnings_rows'] = intval(arraySafeVal($totals, 'projected_pending_earnings_rows', 0));
		$report['projected_pending_earnings_amount_gross'] = floatval(arraySafeVal($totals, 'projected_pending_earnings_amount_gross', 0));
		return $report;
	}

	private function forwardCatchupStage1ApplyFail($report, $reason, $message)
	{
		$report['status'] = 'fail';
		$report['db_mutations'] = false;
		$report['command_validation_status'] = 'fail';
		$report['db_mutation_status'] = 'none';
		$report['post_apply_db_verification_status'] = 'not_run';
		$report['final_batch_reconciliation_status'] = 'not_reconciled';
		$report['abort_reason'] = $reason;
		$this->guard->addError($message);
		return $report;
	}

	private function forwardCatchupStage1StableProjectedMutations($mutations)
	{
		$stable = array();
		foreach ($mutations as $mutation) {
			$item = array(
				'blockid' => arraySafeVal($mutation, 'blockid'),
				'height' => arraySafeVal($mutation, 'height'),
				'classification' => arraySafeVal($mutation, 'classification'),
				'would_set_txhash' => arraySafeVal($mutation, 'would_set_txhash'),
				'would_set_category' => arraySafeVal($mutation, 'would_set_category'),
			);
			if (array_key_exists('would_set_amount', $mutation)) {
				$item['would_set_amount'] = arraySafeVal($mutation, 'would_set_amount');
			}
			if (array_key_exists('daemon_amount_excluded_from_earnings', $mutation)) {
				$item['daemon_amount_excluded_from_earnings'] = arraySafeVal($mutation, 'daemon_amount_excluded_from_earnings');
			}
			if (array_key_exists('would_skip_or_block', $mutation)) {
				$item['would_skip_or_block'] = arraySafeVal($mutation, 'would_skip_or_block');
			}
			$stable[] = $item;
		}
		return $stable;
	}

	private function forwardCatchupStage1BatchScopeBlocks($candidates)
	{
		$blocks = array();
		foreach ($candidates as $candidate) {
			$blocks[] = array(
				'id' => arraySafeVal($candidate, 'id'),
				'height' => arraySafeVal($candidate, 'height'),
			);
		}
		return $blocks;
	}

	private function forwardCatchupStage1StableApprovalPackageChecksum($approval)
	{
		$items = arraySafeVal($approval, 'items', array());
		$summary = arraySafeVal($approval, 'summary', array());
		$totals = arraySafeVal($summary, 'totals', array());
		return BadpoolGuardReport::checksum(array(
			'approval_package_type' => arraySafeVal($approval, 'approval_package_type'),
			'approval_package_version' => arraySafeVal($approval, 'approval_package_version'),
			'coin_id' => arraySafeVal(arraySafeVal($approval, 'scope', array()), 'coin_id'),
			'approval_required' => arraySafeVal($approval, 'approval_required'),
			'attribution_model' => arraySafeVal($approval, 'attribution_model'),
			'operator_must_confirm_attribution_model' => arraySafeVal($approval, 'operator_must_confirm_attribution_model'),
			'overall_approval_package_status' => arraySafeVal($approval, 'overall_approval_package_status'),
			'selected_count' => arraySafeVal($approval, 'selected_count'),
			'first_height' => arraySafeVal($approval, 'first_height'),
			'last_height' => arraySafeVal($approval, 'last_height'),
			'projected_pending_earnings_rows' => arraySafeVal($approval, 'projected_pending_earnings_rows'),
			'projected_pending_earnings_amount_gross' => arraySafeVal($approval, 'projected_pending_earnings_amount_gross'),
			'projected_orphan_excluded_amount' => arraySafeVal($approval, 'projected_orphan_excluded_amount'),
			'batch_scope_checksum' => arraySafeVal($approval, 'batch_scope_checksum'),
			'projected_mutation_checksum' => arraySafeVal($approval, 'projected_mutation_checksum'),
			'projected_earnings_checksum' => arraySafeVal($approval, 'projected_earnings_checksum'),
			'intended_mutation_scope_checksum' => arraySafeVal($approval, 'intended_mutation_scope_checksum'),
			'stable_projected_block_mutations' => $this->forwardCatchupStage1StableProjectedMutations(arraySafeVal($items, 'projected_block_mutations', array())),
			'projected_pending_earnings' => arraySafeVal($items, 'projected_pending_earnings', array()),
			'total_counts' => array(
				'selected_count' => arraySafeVal($totals, 'selected_count'),
				'stage1_import_generate_count' => arraySafeVal($totals, 'stage1_import_generate_count'),
				'stage1_import_immature_count' => arraySafeVal($totals, 'stage1_import_immature_count'),
				'stage1_mark_orphan_no_earnings_count' => arraySafeVal($totals, 'stage1_mark_orphan_no_earnings_count'),
				'projected_pending_earnings_rows' => arraySafeVal($totals, 'projected_pending_earnings_rows'),
				'projected_pending_earnings_amount_gross' => arraySafeVal($totals, 'projected_pending_earnings_amount_gross'),
			),
			'volatile_fields_excluded' => array('live_daemon_confirmation_count'),
		));
	}

	private function forwardCatchupStage1ApplyPreflightGates($classified, $mutations, $earnings)
	{
		if (!$this->guard->tableExists('blocks') || !$this->guard->tableExists('earnings')) {
			return array('status' => 'fail', 'abort_reason' => 'required_table_missing', 'message' => 'blocks and earnings tables are required.');
		}
		foreach (array('id', 'coin_id', 'category', 'txhash', 'amount', 'confirmations') as $column) {
			if (!$this->guard->columnExists('blocks', $column)) {
				return array('status' => 'fail', 'abort_reason' => 'blocks_schema_missing', 'message' => 'Required blocks column missing: '.$column.'.');
			}
		}
		foreach (array('id', 'userid', 'coinid', 'blockid', 'amount', 'status') as $column) {
			if (!$this->guard->columnExists('earnings', $column)) {
				return array('status' => 'fail', 'abort_reason' => 'earnings_schema_missing', 'message' => 'Required earnings column missing: '.$column.'.');
			}
		}
		$allowed = array('stage1_import_generate', 'stage1_import_immature', 'stage1_mark_orphan_no_earnings');
		foreach ($classified as $idx => $item) {
			if (!in_array(arraySafeVal($item, 'classification'), $allowed, true)) {
				return array('status' => 'fail', 'abort_reason' => 'daemon_classification_blocked', 'message' => 'Daemon classification is not applyable for selected block index '.$idx.'.');
			}
			$row = $this->guard->selectRow(
				'SELECT '.$this->guard->selectColumns('blocks', array('id', 'coin_id', 'category', 'height')).' FROM blocks WHERE '.$this->guard->qcol('id').'=:id',
				array(':id' => arraySafeVal($item, 'id'))
			);
			if (!$row || intval(arraySafeVal($row, 'coin_id')) !== intval(arraySafeVal($this->guard->getScope(), 'coin_id'))) {
				return array('status' => 'fail', 'abort_reason' => 'selected_block_scope_changed', 'message' => 'Selected block ID no longer matches approval package scope.');
			}
			if ($this->guard->columnExists('blocks', 'height') && (string)arraySafeVal($row, 'height') !== (string)arraySafeVal($item, 'height')) {
				return array('status' => 'fail', 'abort_reason' => 'selected_block_height_changed', 'message' => 'Selected block height differs from approval package scope.');
			}
			if (arraySafeVal($row, 'category') !== 'new') {
				return array('status' => 'fail', 'abort_reason' => 'selected_block_not_new', 'message' => 'Selected block is no longer category=new.');
			}
			$linked = $this->guard->selectRow(
				'SELECT COUNT(*) AS row_count FROM earnings WHERE '.$this->guard->qcol('blockid').'=:blockid',
				array(':blockid' => arraySafeVal($item, 'id'))
			);
			if (intval(arraySafeVal($linked, 'row_count', 0)) !== 0) {
				return array('status' => 'fail', 'abort_reason' => 'linked_earnings_exist', 'message' => 'Selected block already has linked earnings.');
			}
		}
		foreach ($earnings as $earning) {
			$found = false;
			foreach ($classified as $item) {
				if ((string)arraySafeVal($item, 'id') === (string)arraySafeVal($earning, 'blockid')) {
					$found = true;
					if ((string)arraySafeVal($earning, 'userid') !== (string)arraySafeVal($item, 'userid')) {
						return array('status' => 'fail', 'abort_reason' => 'earning_recipient_mismatch', 'message' => 'Projected earning recipient does not match selected block userid.');
					}
				}
			}
			if (!$found) {
				return array('status' => 'fail', 'abort_reason' => 'earning_outside_batch', 'message' => 'Projected earning references a block outside selected scope.');
			}
		}
		return array('status' => 'pass');
	}

	private function forwardCatchupStage1ApplyMutations($mutations, $earnings)
	{
		$earningsByBlock = array();
		foreach ($earnings as $earning) {
			$earningsByBlock[(string)arraySafeVal($earning, 'blockid')] = $earning;
		}
		$appliedGenerated = 0;
		$appliedOrphan = 0;
		$inserted = 0;
		foreach ($mutations as $mutation) {
			$class = arraySafeVal($mutation, 'classification');
			$blockid = arraySafeVal($mutation, 'blockid');
			if ($class === 'stage1_import_generate' || $class === 'stage1_import_immature') {
				$count = $this->forwardCatchupStage1ExecuteGeneratedBlockUpdate($mutation, 'new');
				if (intval($count) !== 1) {
					throw new CException(
						'Generated block update affected '.intval($count).' rows for block '.$blockid.
						' with expected old category new. Current row snapshot: '.
						$this->forwardCatchupStage1BlockFailureSnapshot($blockid)
					);
				}
				if (!isset($earningsByBlock[(string)$blockid])) {
					throw new CException('Projected earning missing for generated block '.$blockid.'.');
				}
				$earning = $earningsByBlock[(string)$blockid];
				$exists = $this->guard->selectRow('SELECT COUNT(*) AS row_count FROM earnings WHERE '.$this->guard->qcol('blockid').'=:blockid', array(':blockid' => $blockid));
				if (intval(arraySafeVal($exists, 'row_count', 0)) !== 0) {
					throw new CException('Duplicate earning protection tripped for block '.$blockid.'.');
				}
				$row = array(
					'userid' => arraySafeVal($earning, 'userid'),
					'coinid' => arraySafeVal($earning, 'coinid'),
					'blockid' => $blockid,
					'amount' => arraySafeVal($earning, 'amount'),
					'status' => 0,
				);
				if ($this->guard->columnExists('earnings', 'create_time')) {
					$row['create_time'] = arraySafeVal($earning, 'create_time');
				}
				if ($this->guard->columnExists('earnings', 'mature_time')) {
					$row['mature_time'] = null;
				}
				app()->db->createCommand()->insert('earnings', $row);
				$appliedGenerated++;
				$inserted++;
			} elseif ($class === 'stage1_mark_orphan_no_earnings') {
				$count = $this->forwardCatchupStage1ExecuteOrphanBlockUpdate($blockid, 'new');
				if (intval($count) !== 1) {
					throw new CException(
						'Orphan block update affected '.intval($count).' rows for block '.$blockid.
						' with expected old category new. Current row snapshot: '.
						$this->forwardCatchupStage1BlockFailureSnapshot($blockid)
					);
				}
				$appliedOrphan++;
			} else {
				throw new CException('Unapplyable mutation classification for block '.$blockid.'.');
			}
		}
		return array(
			'applied_generated_count' => $appliedGenerated,
			'applied_orphan_count' => $appliedOrphan,
			'inserted_earnings_count' => $inserted,
		);
	}

	private function forwardCatchupStage1ApplyDidMutate($applied)
	{
		return intval(arraySafeVal($applied, 'applied_generated_count', 0)) > 0
			|| intval(arraySafeVal($applied, 'applied_orphan_count', 0)) > 0
			|| intval(arraySafeVal($applied, 'inserted_earnings_count', 0)) > 0;
	}

	private function forwardCatchupStage1ApplyCloseoutVerification($mutations, $applied)
	{
		$generatedIds = array();
		$orphanIds = array();
		foreach ($mutations as $mutation) {
			$class = arraySafeVal($mutation, 'classification');
			$blockid = intval(arraySafeVal($mutation, 'blockid'));
			if ($class === 'stage1_import_generate' || $class === 'stage1_import_immature') {
				$generatedIds[] = $blockid;
			} elseif ($class === 'stage1_mark_orphan_no_earnings') {
				$orphanIds[] = $blockid;
			}
		}
		$generatedImmature = $this->forwardCatchupStage1CountBlocksInCategory($generatedIds, 'immature');
		$orphanNow = $this->forwardCatchupStage1CountBlocksInCategory($orphanIds, 'orphan');
		$stillNew = $this->forwardCatchupStage1CountBlocksInCategory(array_merge($generatedIds, $orphanIds), 'new');
		$linkedEarnings = $this->forwardCatchupStage1CountLinkedEarnings($generatedIds);
		$pass = $generatedImmature === intval(arraySafeVal($applied, 'applied_generated_count', 0))
			&& $orphanNow === intval(arraySafeVal($applied, 'applied_orphan_count', 0))
			&& $linkedEarnings === intval(arraySafeVal($applied, 'inserted_earnings_count', 0))
			&& $stillNew === 0;
		return array(
			'status' => $pass ? 'pass' : 'hold',
			'affected_block_ids' => array_merge($generatedIds, $orphanIds),
			'expected_inserted_earnings_count' => intval(arraySafeVal($applied, 'inserted_earnings_count', 0)),
			'linked_earnings_count' => $linkedEarnings,
			'selected_blocks_still_new' => $stillNew,
			'selected_blocks_now_immature' => $generatedImmature,
			'selected_blocks_now_orphan' => $orphanNow,
		);
	}

	private function forwardCatchupStage1CountBlocksInCategory($blockIds, $category)
	{
		if (empty($blockIds)) return 0;
		$params = array(':category' => $category);
		$placeholders = $this->forwardCatchupStage1IdPlaceholders($blockIds, 'b', $params);
		$row = $this->guard->selectRow('SELECT COUNT(*) AS row_count FROM '.$this->guard->qtable('blocks').' WHERE '.$this->guard->qcol('id').' IN ('.implode(',', $placeholders).') AND '.$this->guard->qcol('category').'=:category', $params);
		return intval(arraySafeVal($row, 'row_count', 0));
	}

	private function forwardCatchupStage1CountLinkedEarnings($blockIds)
	{
		if (empty($blockIds)) return 0;
		$params = array();
		$placeholders = $this->forwardCatchupStage1IdPlaceholders($blockIds, 'e', $params);
		$row = $this->guard->selectRow('SELECT COUNT(*) AS row_count FROM '.$this->guard->qtable('earnings').' WHERE '.$this->guard->qcol('blockid').' IN ('.implode(',', $placeholders).')', $params);
		return intval(arraySafeVal($row, 'row_count', 0));
	}

	private function forwardCatchupStage1IdPlaceholders($ids, $prefix, &$params)
	{
		$placeholders = array();
		foreach (array_values($ids) as $i => $id) {
			$key = ':'.$prefix.$i;
			$placeholders[] = $key;
			$params[$key] = intval($id);
		}
		return $placeholders;
	}

	private function forwardCatchupStage1ExecuteGeneratedBlockUpdate($mutation, $oldCategory)
	{
		$sql = 'UPDATE '.$this->guard->qtable('blocks').' SET '.
			$this->guard->qcol('txhash').'=:txhash, '.
			$this->guard->qcol('amount').'=:amount, '.
			$this->guard->qcol('confirmations').'=:confirmations, '.
			$this->guard->qcol('category').'=:new_category '.
			'WHERE '.$this->guard->qcol('id').'=:id AND '.$this->guard->qcol('category').'=:old_category';
		return app()->db->createCommand($sql)->execute(array(
			':txhash' => arraySafeVal($mutation, 'would_set_txhash'),
			':amount' => arraySafeVal($mutation, 'would_set_amount'),
			':confirmations' => arraySafeVal($mutation, 'would_set_confirmations'),
			':new_category' => 'immature',
			':id' => arraySafeVal($mutation, 'blockid'),
			':old_category' => $oldCategory,
		));
	}

	private function forwardCatchupStage1ExecuteOrphanBlockUpdate($blockid, $oldCategory)
	{
		$sql = 'UPDATE '.$this->guard->qtable('blocks').' SET '.
			$this->guard->qcol('category').'=:new_category '.
			'WHERE '.$this->guard->qcol('id').'=:id AND '.$this->guard->qcol('category').'=:old_category';
		return app()->db->createCommand($sql)->execute(array(
			':new_category' => 'orphan',
			':id' => $blockid,
			':old_category' => $oldCategory,
		));
	}

	private function forwardCatchupStage1BlockFailureSnapshot($blockid)
	{
		$blockColumns = array('id', 'coin_id', 'userid', 'height', 'blockhash', 'txhash', 'category', 'amount', 'confirmations', 'time', 'difficulty');
		$row = $this->guard->selectRow(
			'SELECT '.$this->guard->selectColumns('blocks', $blockColumns).' FROM '.$this->guard->qtable('blocks').' WHERE '.$this->guard->qcol('id').'=:id',
			array(':id' => $blockid)
		);
		if (!$row) {
			return json_encode(array('id' => $blockid, 'found' => false));
		}
		$linked = $this->guard->selectRow(
			'SELECT COUNT(*) AS row_count FROM '.$this->guard->qtable('earnings').' WHERE '.$this->guard->qcol('blockid').'=:blockid',
			array(':blockid' => $blockid)
		);
		$row['earnings_count'] = intval(arraySafeVal($linked, 'row_count', 0));
		return json_encode($row);
	}






	private function walletSendApprovalPackageForIds($ids)
	{
		$report = $this->walletSendApprovalPackageReport();
		if (!$this->guard->isValid()) return $report;
		if (arraySafeVal($report, 'selected_payout_ids', array()) !== $ids) $this->guard->addError('wallet-send-apply selected payout IDs do not match recomputed approval package scope.');
		return $report;
	}

	private function walletSendApplyReport($args)
	{
		$opts = $this->parseWalletSendApplyOptions($args);
		$report = $this->walletSendApplyBaseReport($opts);
		if (isset($opts['__parse_error'])) return $this->walletSendApplyFail($report, 'invalid_option', $opts['__parse_error']);
		if ($this->guard->getFormat() !== 'json') return $this->walletSendApplyFail($report, 'json_format_required', 'wallet-send-apply supports --format=json only.');
		if ($this->guard->isAllCoinsPreview()) return $this->walletSendApplyFail($report, 'coin_id_required', 'wallet-send-apply requires --coin-id and refuses broad/all-coin scope.');
		foreach (array('selected-payout-ids','approval-package-checksum','row-inventory-checksum','destination-plan-checksum','projected-total','projected-total-checksum','wallet-send-total','wallet-send-total-checksum','wallet-send-destination-plan-checksum','operator-confirms-wallet-send') as $r) if (!isset($opts[$r]) || $opts[$r] === '') return $this->walletSendApplyFail($report, 'missing_required_approval_binding', 'Missing required --'.$r.'.');
		$ids = $this->parseCsvIds($opts['selected-payout-ids']);
		if (empty($ids)) return $this->walletSendApplyFail($report, 'selected_payout_ids_required', 'wallet-send-apply refuses empty or missing --selected-payout-ids.');
		if ($this->hasDuplicateIds($ids)) return $this->walletSendApplyFail($report, 'selected_payout_scope_mismatch', 'Duplicate selected payout IDs are refused.');
		sort($ids, SORT_NUMERIC);
		if ((string)$opts['selected-payout-ids'] !== implode(',', $ids)) return $this->walletSendApplyFail($report, 'selected_payout_scope_mismatch', 'Selected payout IDs must be explicit sorted CSV with no broad scope aliases.');
		$approval = $this->walletSendApprovalPackageForIds($ids);
		if (!$this->guard->isValid()) return $this->walletSendApplyFail($report, 'live_inventory_recompute_failed', 'Fresh wallet-send approval package recompute failed.');
		$expectedConfirm = 'selected_payout_rows_'.implode('_', $ids).'_exact_wallet_send_total_'.$approval['wallet_send_total'];
		if ((string)$opts['operator-confirms-wallet-send'] !== $expectedConfirm) return $this->walletSendApplyFail($report, 'operator_confirmation_required', 'Missing exact --operator-confirms-wallet-send='.$expectedConfirm.'.');
		foreach (array('approval-package-checksum'=>'approval_package_checksum','row-inventory-checksum'=>'row_inventory_checksum','destination-plan-checksum'=>'destination_plan_checksum','projected-total-checksum'=>'projected_total_checksum','wallet-send-total-checksum'=>'wallet_send_total_checksum','wallet-send-destination-plan-checksum'=>'wallet_send_destination_plan_checksum') as $opt=>$field) if ((string)$opts[$opt] !== (string)arraySafeVal(arraySafeVal($approval, $field, array()), 'value')) return $this->walletSendApplyFail($report, 'checksum_mismatch', 'Expected checksum does not match freshly generated approval package state: --'.$opt.'.');
		if ((string)$opts['projected-total'] !== (string)$approval['projected_total']) return $this->walletSendApplyFail($report, 'projected_total_mismatch', 'Projected total changed before apply.');
		if ((string)$opts['wallet-send-total'] !== (string)$approval['wallet_send_total']) return $this->walletSendApplyFail($report, 'wallet_send_total_mismatch', 'Wallet-send total changed before apply.');
		$destinationPlan = arraySafeVal($approval, 'wallet_send_destination_plan', array()); if (empty($destinationPlan)) return $this->walletSendApplyFail($report, 'empty_destination_plan', 'wallet-send-apply refuses an empty destination plan.');
		$duplicateRecipient = $this->walletSendApplyDuplicateRecipient($destinationPlan); if ($duplicateRecipient !== null) return $this->walletSendApplyFail($report, 'duplicate_recipient_destination_refused', 'wallet-send-apply refuses duplicate recipient destination before wallet RPC send: '.$duplicateRecipient);
		$dests = $this->walletSendApplyDestinationMap($destinationPlan); $coin = $this->walletSendApplyRpcCoin(intval($opts['coin-id'])); if (!$coin) return $this->walletSendApplyFail($report, 'wallet_rpc_coin_unavailable', 'Unable to load wallet RPC coin fields for apply.'); $remote = new WalletRPC($coin); $txid = $remote->badpoolGuardedSendmanyApply((string)$coin->account, $dests);
		if (!$txid || !is_string($txid)) return $this->walletSendApplyFail($report, 'wallet_rpc_send_failed', 'Wallet RPC sendmany failed or returned no txid: '.json_encode($remote->error));
		$tx = null;
		try {
			$tx = app()->db->beginTransaction();
			$updated = $this->walletSendApplyMarkCompleted($ids, $txid, arraySafeVal(arraySafeVal($approval, 'row_inventory_checksum', array()), 'value'), arraySafeVal($approval, 'row_inventory', array()));
			if ($updated !== count($ids)) throw new Exception('selected payout rows changed before post-send completion update; expected '.count($ids).' guarded row updates, got '.$updated);
			$tx->commit();
			$report = array_merge($report, array('status'=>'pass','reason'=>null,'coin_id'=>intval($opts['coin-id']),'selected_payout_ids'=>$ids,'row_inventory_checksum'=>$approval['row_inventory_checksum'],'destination_plan_checksum'=>$approval['destination_plan_checksum'],'projected_total'=>$approval['projected_total'],'projected_total_checksum'=>$approval['projected_total_checksum'],'wallet_send_total'=>$approval['wallet_send_total'],'wallet_send_total_checksum'=>$approval['wallet_send_total_checksum'],'wallet_send_destination_plan_checksum'=>$approval['wallet_send_destination_plan_checksum'],'approval_package_checksum'=>$approval['approval_package_checksum'],'wallet_sends'=>true,'wallet_rpc_send_performed'=>true,'wallet_send_success'=>true,'db_completion_success'=>true,'full_batch_reconciled'=>true,'db_mutations'=>'guarded_transaction_committed','payout_rows_marked_completed'=>true,'withdraw_rows_created'=>false,'backend_loops_run'=>false,'service_change'=>false,'share_delete'=>false,'txid'=>$txid,'completed_count'=>count($ids),'completed_payout_ids'=>$ids,'amount_sent_by_destination'=>$dests,'exact_total_sent'=>$approval['wallet_send_total'],'manual_reconciliation_required'=>false));
			return BadpoolGuardReport::finalize($report);
		}
		catch (Exception $e) {
			if ($tx !== null && $tx->active) $tx->rollback();
			return $this->walletSendApplyPostSendDbFailureReport($report, $txid, $ids, $approval, $e);
		}
	}
	private function parseWalletSendApplyOptions($args) { $allowed=array('coin-id','format','selected-payout-ids','approval-package-checksum','row-inventory-checksum','destination-plan-checksum','projected-total','projected-total-checksum','wallet-send-total','wallet-send-total-checksum','wallet-send-destination-plan-checksum','operator-confirms-wallet-send'); $o=array(); foreach($args as $arg){ if(!preg_match('/^--([^=]+)=(.*)$/',$arg,$m)){ $o['__parse_error']='Unknown argument refused: '.$arg; continue; } $n=strtolower($m[1]); if(!in_array($n,$allowed,true)) $o['__parse_error']='Unknown option refused: --'.$m[1]; elseif(isset($o[$n])) $o['__parse_error']='Duplicate option refused: --'.$m[1]; else $o[$n]=$m[2]; } return $o; }
	private function walletSendApplyBaseReport($opts){ $r=$this->applyBaseReport('wallet-send-apply', 'refused'); $r['wallet_rpc_primitive']='WalletRPC::badpoolGuardedSendmanyApply(sendmany)'; $r['amount_serialization']='destination_plan decimal strings are passed without float accumulation; selected payout row amounts are fetched with CAST(P.amount AS CHAR) to preserve exact DB decimal strings; CryptoNote amounts are converted to atomic integer strings by WalletRPC decimal parsing'; $r['wallet_sends']=false; $r['wallet_rpc_send_performed']=false; $r['wallet_send_success']=false; $r['db_completion_success']=false; $r['full_batch_reconciled']=false; $r['db_mutations']=false; $r['payout_rows_marked_completed']=false; $r['withdraw_rows_created']=false; $r['backend_loops_run']=false; $r['service_change']=false; $r['share_delete']=false; return $r; }
	private function walletSendApplyFail($report,$reason,$msg){ $report['status']='refused'; $report['reason']=$reason; $report['wallet_sends']=false; $report['wallet_rpc_send_performed']=false; $report['wallet_send_success']=false; $report['db_completion_success']=false; $report['full_batch_reconciled']=false; $report['db_mutations']=false; $report['payout_rows_marked_completed']=false; $report['withdraw_rows_created']=false; $report['backend_loops_run']=false; $report['service_change']=false; $report['share_delete']=false; $report['errors'][]=$msg; return BadpoolGuardReport::finalize($report); }
	private function walletSendApplyPostSendDbFailureReport($report,$txid,$ids,$approval,$exception){ $report = array_merge($report, array('status'=>'hold','reason'=>'post_send_db_completion_failed_reconcile_required','wallet_sends'=>true,'wallet_rpc_send_performed'=>true,'wallet_send_success'=>true,'db_completion_success'=>false,'full_batch_reconciled'=>false,'txid'=>$txid,'selected_payout_ids'=>$ids,'affected_payout_ids'=>$ids,'db_mutations'=>'failed_or_partial_rolled_back','payout_rows_marked_completed'=>false,'manual_reconciliation_required'=>true,'do_not_retry_wallet_send_apply'=>true,'operator_warning'=>'DO NOT RETRY wallet-send-apply: wallet send already succeeded; reconcile selected payout rows manually with the txid in this report.','post_send_db_completion_failure'=>array('exception_class'=>get_class($exception),'message'=>$exception->getMessage(),'failed_update_predicate'=>'UPDATE payouts SET completed=1, tx=:tx WHERE id=:id AND idcoin=:idcoin AND account_id=:account_id AND amount=:amount AND completed=0 AND (tx IS NULL OR tx=\'\')','row_inventory_checksum'=>arraySafeVal($approval,'row_inventory_checksum',array()),'affected_payout_ids'=>$ids),'row_inventory'=>arraySafeVal($approval,'row_inventory',array()))); $report['errors'][]='post-send DB completion failed after wallet RPC send; manual reconciliation required; DO NOT RETRY wallet-send-apply; txid='.$txid.'; affected_payout_ids='.implode(',', $ids).'; '.$exception->getMessage(); return BadpoolGuardReport::finalize($report); }
	private function walletSendApplyDuplicateRecipient($plan){ $seen=array(); foreach($plan as $row){ $recipient=(string)arraySafeVal($row,'recipient',''); if(isset($seen[$recipient])) return $recipient; $seen[$recipient]=true; } return null; }
	private function walletSendApplyDestinationMap($plan){ $d=array(); foreach($plan as $row) $d[(string)$row['recipient']] = (string)$row['amount']; return $d; }
	private function walletSendApplyMarkCompleted($ids,$txid,$approvedRowInventoryChecksum,$approvedRowInventory){ $liveRows=$this->walletSendSelectedPayoutRows($ids); $liveInventory=$this->walletSendApplyRowInventoryFromRows($ids,$liveRows); if ((string)arraySafeVal(BadpoolGuardReport::checksum($liveInventory),'value') !== (string)$approvedRowInventoryChecksum) throw new Exception('post-send row_inventory_checksum mismatch before completion update'); if ($liveInventory != $approvedRowInventory) throw new Exception('post-send row inventory changed before completion update'); $updated=0; foreach($approvedRowInventory as $row){ $updated += app()->db->createCommand("UPDATE payouts SET completed=1, tx=:tx WHERE id=:id AND idcoin=:idcoin AND account_id=:account_id AND amount=:amount AND completed=0 AND (tx IS NULL OR tx='')")->execute(array(':tx'=>$txid, ':id'=>$row['payout_id'], ':idcoin'=>$row['idcoin'], ':account_id'=>$row['account_id'], ':amount'=>$row['amount'])); } return $updated; }
	private function walletSendApplyRowInventoryFromRows($ids,$rows){ if (count($rows)!==count($ids)) throw new Exception('post-send selected payout row count mismatch'); $rowById=array(); foreach($rows as $row) $rowById[intval($row['payout_id'])]=$row; $inventory=array(); foreach($ids as $id){ if(!isset($rowById[$id])) throw new Exception('post-send selected payout row missing: '.$id); $row=$rowById[$id]; if(intval($row['completed'])!==0 || (string)arraySafeVal($row,'tx','')!=='') throw new Exception('post-send selected payout row no longer eligible: '.$id); $inventory[]=array('payout_id'=>$id,'idcoin'=>intval($row['payout_idcoin']),'account_id'=>intval($row['account_id']),'account_coinid'=>intval($row['account_coinid']),'coin_id'=>intval($row['coin_id']),'destination_field'=>'accounts.username','destination'=>(string)$row['username'],'recipient'=>(string)$row['username'],'amount'=>(string)$row['amount'],'completed'=>intval($row['completed']),'tx'=>$row['tx']); } return $inventory; }
	private function walletSendApplyRpcCoin($coinId){ $cols=array('id','symbol','algo','rpcencoding','rpcuser','rpcpasswd','rpchost','rpcport','account','hasgetinfo','master_wallet'); $select=array(); foreach($cols as $c) $select[]=$this->guard->qcol($c); $row=$this->guard->selectRow('SELECT '.implode(',', $select).' FROM '.$this->guard->qtable('coins').' WHERE '.$this->guard->qcol('id').'=:coin_id', array(':coin_id'=>$coinId)); return $row ? (object)$row : null; }


	private function walletProofCloseoutReport()
	{
		$report = $this->guard->baseReport();
		$report['schema'] = 'badpool.guardrail.wallet_proof_closeout.v1';
		$report['command_shape'] = 'php yaamp/yiic.php badpoolguard wallet-proof-closeout --coin-id=<id> --selected-payout-ids=<csv> --format=json';
		$report['read_only'] = true;
		$report['wallet_reads'] = 'gettransaction_only';
		$report['db_mutations'] = false;
		$report['wallet_sends'] = false;
		$report['wallet_send_rpc_methods_blocked'] = array('send'.'many','send'.'toaddress','trans'.'fer','wallet'.'passphrase','wallet'.'passphrasechange','wallet'.'lock','un'.'lock');
		$report['payout_inventory'] = array();
		$report['selected_payout_ids'] = array();
		$report['expected_send_amount'] = '0';
		$report['expected_wallet_amount'] = '0.00000000';
		$report['wallet_lookup_success'] = false;
		$report['wallet_txid_expected'] = false;
		$report['wallet_amount_matches_expected'] = false;
		$report['wallet_confirmations_present'] = false;
		$report['closeout_valid'] = false;
		$report['missing_closeout_fields'] = array();
		$report['invalid_closeout_fields'] = array();
		$report['classification'] = 'HOLD / WALLET PROOF INCOMPLETE';
		$report['final_classification'] = 'HOLD / WALLET PROOF INCOMPLETE';
		$report['run_dir'] = getcwd();
		$report['mutation_boundary'] = array('no_payout_row_update'=>true,'no_account_update'=>true,'no_withdraw_update'=>true,'no_share_deletion'=>true,'no_service_action'=>true);
		$report['next_lane'] = 'operator_review_wallet_proof_closeout';
		$report['next_safe_lane_or_STOP'] = 'STOP';
		$report['do_not_rerun'] = array('wallet-send-apply','payout-row-apply','account-credit-apply');
		$report['fix_items'] = array();
		$report['wallet_proof_context'] = $this->walletProofContextForCoin(intval(arraySafeVal($this->guard->getScope(), 'coin_id')));

		if ($this->guard->getFormat() !== 'json') return $this->walletProofCloseoutHold($report, 'format', 'wallet-proof-closeout requires --format=json.');
		if ($this->guard->isAllCoinsPreview()) return $this->walletProofCloseoutHold($report, 'coin_id', 'wallet-proof-closeout requires explicit --coin-id and refuses broad/all-coin scope.');
		$coinId = intval(arraySafeVal($this->guard->getScope(), 'coin_id'));
		if ($coinId !== 1267) return $this->walletProofCloseoutHold($report, 'unsupported_wallet_proof_context', 'unsupported_wallet_proof_context');
		$ids = $this->parseCsvIds($this->guard->getOption('selected-payout-ids'));
		if (empty($ids)) return $this->walletProofCloseoutHold($report, 'selected_payout_ids', 'wallet-proof-closeout requires explicit nonempty --selected-payout-ids CSV of positive integers.');
		if ($this->hasDuplicateIds($ids)) return $this->walletProofCloseoutHold($report, 'selected_payout_ids', 'Duplicate selected payout IDs are refused.');
		$report['selected_payout_ids'] = $ids;
		$rows = $this->walletProofSelectedPayoutRows($ids);
		if (count($rows) !== count($ids)) $report['missing_closeout_fields'][] = 'payout missing or linked account missing';
		$rowById = array(); foreach ($rows as $row) $rowById[intval($row['payout_id'])] = $row;
		$expected = '0'; $expectedWallet = '0'; $txids = array();
		foreach ($ids as $id) {
			if (!isset($rowById[$id])) { $report['fix_items'][] = 'payout missing: '.$id; continue; }
			$row = $rowById[$id]; $amount = (string)$row['amount']; $txid = trim((string)arraySafeVal($row, 'tx', ''));
			$item = array('payout_id'=>$id,'coin_id'=>intval($row['payout_idcoin']),'account_id'=>intval($row['account_id']),'account_balance_reported'=>array_key_exists('account_balance', $row),'account_balance'=>(string)arraySafeVal($row,'account_balance',''),'account_balance_zero'=>$this->walletProofDecimalIsZero(arraySafeVal($row,'account_balance','')),'completed'=>intval($row['completed']),'tx'=>$this->walletProofRedact($txid),'amount'=>$amount,'raw_db_amount'=>$amount,'withdraw_rows'=>$this->walletProofWithdrawRows($row));
			if (intval($row['payout_idcoin']) !== $coinId) $report['invalid_closeout_fields'][] = 'coin_id mismatch payout '.$id;
			if (intval($row['completed']) !== 1) $report['invalid_closeout_fields'][] = 'completed not 1 payout '.$id;
			if ($txid === '') $report['missing_closeout_fields'][] = 'tx missing payout '.$id;
			if (!$item['account_balance_reported']) $report['missing_closeout_fields'][] = 'account balance missing payout '.$id;
			if (!$item['account_balance_zero']) { $report['invalid_closeout_fields'][] = 'account balance nonzero payout '.$id; $item['account_balance_classification'] = 'hold'; }
			$report['payout_inventory'][] = $item;
			$expected = $this->walletSendDryrunDecimalAdd($expected, $amount);
			$expectedWallet = $this->walletSendDryrunDecimalAdd($expectedWallet, $this->walletSendProjectBtcAmount8dp($amount));
			if ($txid !== '') $txids[$txid] = true;
		}
		$report['expected_send_amount'] = '-'.$expected;
		$report['expected_wallet_amount'] = '-'.$expectedWallet;
		if (!empty($report['missing_closeout_fields']) || !empty($report['invalid_closeout_fields']) || count($txids) !== 1) return $this->walletProofCloseoutHold($report, 'selected_payout_validation', 'Selected payout validation failed.');
		$coin = $this->walletSendApplyRpcCoin($coinId);
		$remote = new WalletRPC($coin);
		$txid = key($txids);
		$walletTx = $this->walletProofNormalizeRpcValue($remote->gettransaction($txid));
		$report['wallet_lookup_success'] = !empty($walletTx);
		$report['wallet_txid_expected'] = ((string)arraySafeVal($walletTx, 'txid', $txid) === $txid);
		$report['wallet_amount'] = (string)arraySafeVal($walletTx, 'amount', '');
		$report['proof_amount_raw'] = $report['wallet_amount'];
		$report['proof_amount_abs'] = ltrim($report['proof_amount_raw'], '-');
		$report['expected_wallet_send_total'] = $expectedWallet;
		$report['amount_match_mode'] = 'absolute_debit_normalized';
		$report['amount_matches_expected_abs'] = ($this->walletSendDecimalCompare($report['proof_amount_abs'], $expectedWallet) === 0);
		// Backward-compatibility parser sentinel: walletSendDecimalCompare(ltrim($report['wallet_amount'], '-'), $expectedWallet)
		$report['wallet_amount_matches_expected'] = ($report['amount_matches_expected_abs'] && strpos($report['wallet_amount'], '-') === 0);
		$report['wallet_confirmations_present'] = array_key_exists('confirmations', $walletTx);
		$report['wallet_proof'] = array('txid'=>$txid,'amount'=>$report['wallet_amount'],'confirmations'=>arraySafeVal($walletTx,'confirmations',null),'blockhash'=>arraySafeVal($walletTx,'blockhash',null),'blockindex'=>arraySafeVal($walletTx,'blockindex',null),'category'=>arraySafeVal($walletTx,'category',null),'rpc_error'=>$this->walletProofRedact(json_encode($remote->error)));
		$report['closeout_valid'] = $report['wallet_lookup_success'] && $report['wallet_txid_expected'] && $report['wallet_amount_matches_expected'] && $report['wallet_confirmations_present'];
		if ($report['closeout_valid']) { $report['classification'] = 'PASS / WALLET PROOF CLOSEOUT COMPLETE'; $report['final_classification'] = 'PASS / WALLET PROOF CLOSEOUT COMPLETE'; $report['next_safe_lane_or_STOP'] = 'STOP'; }
		return $this->guard->finalizeReport($report);
	}

	private function walletProofCloseoutHold($report, $field, $message) { $report['closeout_valid']=false; $report['classification']='HOLD / WALLET PROOF INCOMPLETE'; $report['final_classification']='HOLD / WALLET PROOF INCOMPLETE'; $report['fix_items'][]=$message; if ($field) $report['invalid_closeout_fields'][]=$field; return $this->guard->finalizeReport($report); }
	private function walletProofContextForCoin($coinId) { if ($coinId !== 1267) return array('supported'=>false,'reason'=>'unsupported_wallet_proof_context'); return array('supported'=>true,'coin_id'=>1267,'conf'=>'/etc/badcoin/pool-scrypt.conf','datadir'=>'/var/lib/badcoin-pool-scrypt','rpc_methods'=>array('gettransaction')); }
	private function walletProofDecimalIsZero($v) { return preg_match('/^-?0+(?:\.0+)?$/', trim((string)$v)) === 1; }
	private function walletProofRedact($v) { return preg_replace('/(rpc(user|pass(word)?)|cookie|secret|token|passphrase)([^\s,;]*)/i', '$1=REDACTED', (string)$v); }
	private function walletProofNormalizeRpcValue($value) { if (is_object($value)) return get_object_vars($value); return is_array($value) ? $value : array(); }
	private function walletProofSelectedPayoutRows($ids) { $params=array(); $ph=array(); foreach($ids as $i=>$id){$k=':payout_id_'.$i; $ph[]=$k; $params[$k]=$id;} return $this->guard->selectAll('SELECT P.id AS payout_id, P.account_id, P.idcoin AS payout_idcoin, CAST(P.amount AS CHAR) AS amount, P.completed, P.tx, A.username, A.coinid AS account_coinid, CAST(A.balance AS CHAR) AS account_balance FROM payouts P INNER JOIN accounts A ON A.id=P.account_id WHERE P.id IN ('.implode(',', $ph).') ORDER BY P.id', $params); }
	private function walletProofWithdrawRows($row) { if (!$this->guard->tableExists('withdraws')) return array('checked'=>true,'present'=>false,'rows'=>array()); $cols=array('id','time','account_id','amount','tx','txid','address','market'); $select=$this->guard->selectColumns('withdraws',$cols); $params=array(':account_id'=>intval(arraySafeVal($row,'account_id'))); if ($this->guard->columnExists('withdraws','account_id')) $rows=$this->guard->selectAll('SELECT '.$select.' FROM withdraws WHERE account_id=:account_id ORDER BY id DESC LIMIT 20',$params); else $rows=$this->guard->selectAll('SELECT '.$select.' FROM withdraws ORDER BY id DESC LIMIT 20'); return array('checked'=>true,'present'=>!empty($rows),'rows'=>$rows); }


	private function walletSendDryrunReport()
	{
		$report = $this->walletSendBuildReadOnlyPackage(false);
		if (!$this->guard->isValid()) return $report;
		return $this->guard->finalizeReport($report);
	}

	private function walletSendApprovalPackageReport()
	{
		$report = $this->walletSendBuildReadOnlyPackage(true);
		if (!$this->guard->isValid()) return $report;
		$confirmation = 'selected_payout_rows_'.implode('_', $report['selected_payout_ids']).'_exact_wallet_send_total_'.$report['wallet_send_total'];
		$report['approval_package_type'] = 'wallet-send';
		$report['approval_required'] = true;
		$report['scope_binding'] = array(
			'coin_id' => $report['coin_id'],
			'selected_payout_ids' => $report['selected_payout_ids'],
			'source' => 'walletSendBuildReadOnlyPackage',
		);
		$report['operator_confirmation_required'] = '--operator-confirms-wallet-send='.$confirmation;
		$report['operator_confirmation'] = $confirmation;
		$report['apply_command_shape'] = array(
			'cd', self::OPERATOR_WEB_CWD, '&&', 'php', 'yaamp/yiic.php', 'badpoolguard', 'wallet-send-apply',
			'--coin-id='.$report['coin_id'],
			'--selected-payout-ids='.implode(',', $report['selected_payout_ids']),
			'--approval-package-checksum=<approval_package_checksum>',
			'--row-inventory-checksum='.arraySafeVal($report['row_inventory_checksum'], 'value'),
			'--destination-plan-checksum='.arraySafeVal($report['destination_plan_checksum'], 'value'),
			'--projected-total='.$report['projected_total'],
			'--projected-total-checksum='.arraySafeVal($report['projected_total_checksum'], 'value'),
			'--wallet-send-total='.$report['wallet_send_total'],
			'--wallet-send-total-checksum='.arraySafeVal($report['wallet_send_total_checksum'], 'value'),
			'--wallet-send-destination-plan-checksum='.arraySafeVal($report['wallet_send_destination_plan_checksum'], 'value'),
			$report['operator_confirmation_required'],
			'--format=json'
		);
		$report['warnings'][] = 'Approval package only: this package does not send wallet funds.';
		$report['warnings'][] = 'Approval package only: this package does not mutate DB.';
		$report['warnings'][] = 'Approval package only: this package does not mark payouts completed.';
		$report['warnings'][] = 'Approval package only: this package does not create withdraw rows.';
		$report['warnings'][] = 'Approval package only: this package does not run backend loops.';
		$report['warnings'][] = 'Approval package only: this package does not change services.';
		$this->standardizeApprovalPackageContract($report, 'wallet-send', array('approval_package_checksum','row_inventory_checksum','destination_plan_checksum','projected_total_checksum','wallet_send_total_checksum','wallet_send_destination_plan_checksum'));
		unset($report['report_checksum']);
		$report['approval_package_checksum'] = $this->stableApprovalChecksum($report, array(
			'approval_package_type', 'scope_binding', 'selected_payout_ids', 'row_inventory_checksum',
			'destination_plan_checksum', 'projected_total', 'projected_total_checksum',
			'wallet_send_destination_plan_checksum', 'wallet_send_total', 'wallet_send_total_checksum',
			'dry_run_safety_flags', 'apply_command_shape', 'operator_confirmation', 'selected_records', 'checksums', 'apply_command_args'
		));
		$this->standardizeApprovalPackageContract($report, 'wallet-send', array('approval_package_checksum','row_inventory_checksum','destination_plan_checksum','projected_total_checksum','wallet_send_total_checksum','wallet_send_destination_plan_checksum'));
		$report['report_checksum'] = BadpoolGuardReport::checksum($report);
		return $report;
	}

	private function walletSendBuildReadOnlyPackage($approvalPackage)
	{
		$report = $this->guard->baseReport();
		$report['projected_send_method'] = 'sendmany';
		$coin = $this->guard->getCoin();
		$report['wallet_account'] = is_array($coin) ? (string)arraySafeVal($coin, 'account', '') : '';
		$report['wallet_rpc_send_performed'] = false;
		$report['db_mutations'] = false;
		$report['payout_rows_marked_completed'] = false;
		$report['withdraw_rows_created'] = false;
		$report['backend_loops_run'] = false;
		$report['service_actions'] = false;
		$report['service_change'] = false;
		$report['retry_delete_behavior'] = false;
		$report['wallet_send_apply_available'] = false;
		$report['dry_run_safety_flags'] = array('db_mutations'=>false,'wallet_rpc_send_performed'=>false,'payout_rows_marked_completed'=>false,'withdraw_rows_created'=>false,'backend_loops_run'=>false,'service_actions'=>false,'retry_delete_behavior'=>false);
		// wallet-send-dryrun requires --format=json.
		// wallet-send-approval-package requires --format=json.
		// wallet-send-dryrun requires non-empty --selected-payout-ids CSV of positive integers.
		// wallet-send-approval-package requires non-empty --selected-payout-ids CSV of positive integers.
		$actionName = $approvalPackage ? 'wallet-send-approval-package' : 'wallet-send-dryrun';

		if ($this->guard->getFormat() !== 'json') { $this->guard->addError($actionName.' requires --format=json.'); return $this->guard->refusalReport(); }
		if ($this->guard->isAllCoinsPreview()) { $this->guard->addError($actionName.' requires --coin-id and refuses broad/all-coin scope.'); return $this->guard->refusalReport(); }
		$coinId = intval(arraySafeVal($this->guard->getScope(), 'coin_id'));
		if ($coinId !== 1267) { $this->guard->addError($actionName.' is scoped to Badpool coin-id 1267 only.'); return $this->guard->refusalReport(); }
		$ids = $this->parseCsvIds($this->guard->getOption('selected-payout-ids'));
		if (empty($ids)) { $this->guard->addError($actionName.' requires non-empty --selected-payout-ids CSV of positive integers.'); return $this->guard->refusalReport(); }
		if (count($ids) !== count(array_unique($ids))) { $this->guard->addError('Duplicate selected payout IDs are refused.'); return $this->guard->refusalReport(); }
		sort($ids, SORT_NUMERIC);
		$rows = $this->walletSendSelectedPayoutRows($ids);
		if (count($rows) !== count($ids)) { $this->guard->addError('Selected payout row count mismatch; every explicit payout ID must exist with joined account and coin rows.'); return $this->guard->refusalReport(); }
		$rowById = array(); foreach ($rows as $row) $rowById[intval($row['payout_id'])] = $row;
		$rowInventory = array(); $destinationPlan = array(); $walletSendDestinationPlan = array(); $roundingReport = array(); $total = '0'; $walletSendTotal = '0';
		foreach ($ids as $id) {
			$row = $rowById[$id]; $amount = (string)$row['amount'];
			$projectedAmount = $this->walletSendProjectBtcAmount8dp($amount);
			if (intval($row['payout_idcoin']) !== $coinId) $this->guard->addError('Selected payout row idcoin must match --coin-id for payout ID '.$id.'.');
			if (intval($row['completed']) !== 0) $this->guard->addError('Selected payout row must have completed=0 for payout ID '.$id.'.');
			if ((string)arraySafeVal($row, 'tx', '') !== '') $this->guard->addError('Selected payout row must have tx NULL or empty for payout ID '.$id.'.');
			if (intval($row['account_coinid']) !== intval($row['payout_idcoin'])) $this->guard->addError('Joined account coinid must equal payout idcoin for payout ID '.$id.'.');
			$rowInventory[] = array('payout_id'=>$id,'idcoin'=>intval($row['payout_idcoin']),'account_id'=>intval($row['account_id']),'account_coinid'=>intval($row['account_coinid']),'coin_id'=>intval($row['coin_id']),'destination_field'=>'accounts.username','destination'=>(string)$row['username'],'destination_address'=>(string)$row['username'],'recipient' => (string)$row['username'],'amount'=>$amount,'wallet_send_amount'=>$projectedAmount,'completed'=>intval($row['completed']),'tx'=>$row['tx']);
			$delta = $this->walletSendDecimalSubtract($projectedAmount, $amount);
			$destinationPlan[] = array('recipient' => (string)$row['username'],'destination_address'=>(string)$row['username'],'amount'=>$amount,'payout_ids'=>array($id),'projected_send_amount'=>$amount);
			$walletSendDestinationPlan[] = array('recipient' => (string)$row['username'],'destination_address'=>(string)$row['username'],'payout_ids'=>array($id),'raw_amount'=>$amount,'amount'=>$projectedAmount,'projected_send_amount'=>$projectedAmount,'rounding_delta'=>$delta);
			$roundingReport[] = array('payout_id'=>$id,'recipient'=>(string)$row['username'],'raw_amount'=>$amount,'wallet_send_amount'=>$projectedAmount,'rounding_delta'=>$delta,'nonzero_delta'=>($delta !== '0'));
			$total = $this->walletSendDryrunDecimalAdd($total, $amount);
			$walletSendTotal = $this->walletSendDryrunDecimalAdd($walletSendTotal, $projectedAmount);
		}
		$report['coin_id'] = $coinId; $report['selected_payout_ids'] = $ids; $report['selected_payout_count'] = count($ids);
		if (!$this->guard->isValid()) {
			$terminal = $this->walletSendTerminalState($rowInventory);
			if ($terminal['terminal_state']) {
				$report = array_merge($report, $terminal);
				$report['safe_to_apply'] = false;
				$report['recommended_next_action'] = 'wallet-proof-closeout';
				$report['wallet_send_apply_available'] = false;
				$report['row_inventory'] = $rowInventory;
				return $this->guard->finalizeReport($report);
			}
			return $this->guard->refusalReport();
		}
		$report['projected_total'] = $total; $report['projected_total_amount'] = $total; $report['wallet_send_total'] = $walletSendTotal; $report['row_inventory'] = $rowInventory; $report['destination_plan'] = $destinationPlan; $report['wallet_send_destination_plan'] = $walletSendDestinationPlan; $report['wallet_send_rounding_report'] = array('per_destination'=>$roundingReport,'aggregate_delta'=>$this->walletSendDecimalSubtract($walletSendTotal, $total));
		$report['row_inventory_checksum'] = BadpoolGuardReport::checksum($rowInventory); $report['destination_plan_checksum'] = BadpoolGuardReport::checksum($destinationPlan); $report['projected_total_checksum'] = BadpoolGuardReport::checksum(array('projected_total'=>$total)); $report['wallet_send_destination_plan_checksum'] = BadpoolGuardReport::checksum($walletSendDestinationPlan); $report['wallet_send_total_checksum'] = BadpoolGuardReport::checksum(array('wallet_send_total'=>$walletSendTotal));
		$report['wallet_send_row_inventory_sha256'] = $report['row_inventory_checksum']; $report['wallet_send_destination_plan_sha256'] = $report['destination_plan_checksum']; $report['wallet_send_projected_destination_plan_sha256'] = $report['wallet_send_destination_plan_checksum'];
		return $report;
	}

	private function walletSendTerminalState($rowInventory)
	{
		$allCompleted = !empty($rowInventory); $allTxPresent = !empty($rowInventory);
		foreach ($rowInventory as $row) {
			$allCompleted = $allCompleted && intval(arraySafeVal($row, 'completed')) === 1;
			$allTxPresent = $allTxPresent && trim((string)arraySafeVal($row, 'tx', '')) !== '';
		}
		return array('payout_completed'=>$allCompleted, 'payout_tx_present'=>$allTxPresent, 'terminal_state'=>($allCompleted && $allTxPresent));
	}

	private function walletSendDryrunDecimalAdd($a, $b)
	{
		$a = trim((string)$a);
		$b = trim((string)$b);
		if ($a === '') $a = '0';
		if ($b === '') $b = '0';
		if (!preg_match('/^\d+(?:\.\d+)?$/', $a) || !preg_match('/^\d+(?:\.\d+)?$/', $b)) {
			throw new Exception('wallet-send-dryrun amount must be an unsigned decimal string.');
		}

		list($aWhole, $aFrac) = $this->walletSendDryrunDecimalParts($a);
		list($bWhole, $bFrac) = $this->walletSendDryrunDecimalParts($b);
		$scale = max(strlen($aFrac), strlen($bFrac));
		$aDigits = ltrim($aWhole.str_pad($aFrac, $scale, '0'), '0');
		$bDigits = ltrim($bWhole.str_pad($bFrac, $scale, '0'), '0');
		if ($aDigits === '') $aDigits = '0';
		if ($bDigits === '') $bDigits = '0';

		$carry = 0;
		$out = '';
		$ai = strlen($aDigits) - 1;
		$bi = strlen($bDigits) - 1;
		while ($ai >= 0 || $bi >= 0 || $carry > 0) {
			$sum = $carry;
			if ($ai >= 0) $sum += ord($aDigits[$ai--]) - 48;
			if ($bi >= 0) $sum += ord($bDigits[$bi--]) - 48;
			$out = chr(48 + ($sum % 10)).$out;
			$carry = intdiv($sum, 10);
		}

		if ($scale > 0) {
			if (strlen($out) <= $scale) $out = str_pad($out, $scale + 1, '0', STR_PAD_LEFT);
			$out = substr($out, 0, -$scale).'.'.substr($out, -$scale);
			$out = rtrim($out, '0');
			$out = rtrim($out, '.');
		}
		$out = ltrim($out, '0');
		if ($out === '') return '0';
		if ($out[0] === '.') $out = '0'.$out;
		return $out;
	}


	private function walletSendProjectBtcAmount8dp($amount)
	{
		$amount = trim((string)$amount);
		if (!preg_match('/^\d+(?:\.\d+)?$/', $amount)) throw new Exception('wallet-send BTC projection amount must be an unsigned decimal string.');
		list($whole, $frac) = $this->walletSendDryrunDecimalParts($amount);
		$frac = str_pad($frac, 9, '0');
		$firstEight = substr($frac, 0, 8);
		$roundDigit = ord($frac[8]) - 48;
		$digits = ltrim($whole.$firstEight, '0');
		if ($digits === '') $digits = '0';
		if ($roundDigit >= 5) $digits = $this->walletSendIncrementDigits($digits);
		if (strlen($digits) <= 8) $digits = str_pad($digits, 9, '0', STR_PAD_LEFT);
		return substr($digits, 0, -8).'.'.substr($digits, -8);
	}

	private function walletSendIncrementDigits($digits)
	{
		$carry = 1;
		$out = '';
		for ($i = strlen($digits) - 1; $i >= 0; $i--) {
			$n = ord($digits[$i]) - 48 + $carry;
			$out = chr(48 + ($n % 10)).$out;
			$carry = $n >= 10 ? 1 : 0;
		}
		if ($carry) $out = '1'.$out;
		return $out;
	}

	private function walletSendDecimalSubtract($a, $b)
	{
		$a = trim((string)$a); $b = trim((string)$b);
		if (!preg_match('/^\d+(?:\.\d+)?$/', $a) || !preg_match('/^\d+(?:\.\d+)?$/', $b)) throw new Exception('wallet-send decimal subtract requires unsigned decimal strings.');
		$cmp = $this->walletSendDecimalCompare($a, $b);
		if ($cmp === 0) return '0';
		$neg = $cmp < 0;
		if ($neg) { $tmp = $a; $a = $b; $b = $tmp; }
		list($aw, $af) = $this->walletSendDryrunDecimalParts($a); list($bw, $bf) = $this->walletSendDryrunDecimalParts($b);
		$scale = max(strlen($af), strlen($bf));
		$ad = ltrim($aw.str_pad($af, $scale, '0'), '0'); $bd = ltrim($bw.str_pad($bf, $scale, '0'), '0');
		if ($ad === '') $ad = '0'; if ($bd === '') $bd = '0';
		$borrow = 0; $out = '';
		for ($i = strlen($ad) - 1, $j = strlen($bd) - 1; $i >= 0; $i--, $j--) {
			$n = ord($ad[$i]) - 48 - $borrow - ($j >= 0 ? ord($bd[$j]) - 48 : 0);
			if ($n < 0) { $n += 10; $borrow = 1; } else $borrow = 0;
			$out = chr(48 + $n).$out;
		}
		if ($scale > 0) { if (strlen($out) <= $scale) $out = str_pad($out, $scale + 1, '0', STR_PAD_LEFT); $out = substr($out, 0, -$scale).'.'.substr($out, -$scale); $out = rtrim(rtrim($out, '0'), '.'); }
		$out = ltrim($out, '0'); if ($out === '') $out = '0'; if ($out[0] === '.') $out = '0'.$out;
		return $neg ? '-'.$out : $out;
	}

	private function walletSendDecimalCompare($a, $b)
	{
		list($aw, $af) = $this->walletSendDryrunDecimalParts($a); list($bw, $bf) = $this->walletSendDryrunDecimalParts($b);
		$aw = ltrim($aw, '0'); $bw = ltrim($bw, '0'); if ($aw === '') $aw = '0'; if ($bw === '') $bw = '0';
		if (strlen($aw) !== strlen($bw)) return strlen($aw) > strlen($bw) ? 1 : -1;
		if ($aw !== $bw) return strcmp($aw, $bw) > 0 ? 1 : -1;
		$scale = max(strlen($af), strlen($bf)); $af = str_pad($af, $scale, '0'); $bf = str_pad($bf, $scale, '0');
		if ($af === $bf) return 0;
		return strcmp($af, $bf) > 0 ? 1 : -1;
	}

	private function walletSendDryrunDecimalParts($value)
	{
		$parts = explode('.', $value, 2);
		return array($parts[0], isset($parts[1]) ? $parts[1] : '');
	}

	private function walletSendSelectedPayoutRows($ids)
	{
		$params = array();
		$placeholders = array();
		foreach ($ids as $i => $id) { $key = ':payout_id_'.$i; $placeholders[] = $key; $params[$key] = $id; }
		return $this->guard->selectAll('SELECT P.id AS payout_id, P.account_id, P.idcoin AS payout_idcoin, CAST(P.amount AS CHAR) AS amount, P.completed, P.tx, A.username, A.coinid AS account_coinid, C.id AS coin_id, C.symbol, C.rpcencoding FROM payouts P INNER JOIN accounts A ON A.id=P.account_id INNER JOIN coins C ON C.id=P.idcoin WHERE P.id IN ('.implode(',', $placeholders).') ORDER BY P.id', $params);
	}

	private function payoutRowApprovalPackageReport()
	{
		if ($this->guard->isAllCoinsPreview()) { $this->guard->addError('payout-row-approval-package requires --coin-id and refuses all-coin scope.'); return $this->guard->refusalReport(); }
		$candidates = $this->buildReadOnlyPayoutCandidates(); $coinId = intval(arraySafeVal($this->guard->getScope(), 'coin_id')); $items = array();
		foreach ($candidates as $c) $items[] = array('account_id'=>intval($c['account_id']),'account_coinid'=>intval($c['coin_id']),'current_balance'=>$this->decimalString($c['current_balance']),'payout_threshold'=>$this->decimalString($c['threshold']),'projected_payout_row_amount'=>$this->decimalString($c['projected_payout_amount']),'projected_account_debit_amount'=>$this->decimalString($c['projected_payout_amount']),'projected_remaining_balance'=>$this->decimalString($c['projected_remaining_balance']));
		$r = $this->guard->baseReport(); $r['approval_package_type']='payout-row-creation'; $r['approval_required']=true; $r['scope_binding']=array('coin_id'=>$coinId,'source'=>'same buildReadOnlyPayoutCandidates source as payout-candidates-preview'); $r['safety_binding']=array('no_wallet_send'=>true,'no_withdraw_creation'=>true,'no_backend_loop'=>true,'no_share_deletion'=>true); $r['summary']['selected_account_count']=count($items); $r['summary']['projected_payout_total']=$this->sumColumn($items,'projected_payout_row_amount'); $r['items']['selected_accounts']=$items;
		$r['selected_scope_checksum']=BadpoolGuardReport::checksum(array('coin_id'=>$coinId,'accounts'=>$items)); $r['projected_payout_row_checksum']=BadpoolGuardReport::checksum(array_map(function($i){return array('account_id'=>$i['account_id'],'idcoin'=>$i['account_coinid'],'amount'=>$i['projected_payout_row_amount'],'completed'=>0,'tx'=>null);}, $items)); $r['projected_account_debit_checksum']=BadpoolGuardReport::checksum(array_map(function($i){return array('account_id'=>$i['account_id'],'coinid'=>$i['account_coinid'],'from_balance'=>$i['current_balance'],'debit'=>$i['projected_account_debit_amount'],'to_balance'=>$i['projected_remaining_balance']);}, $items));
		$r['apply_command_shape']=array('cd',self::OPERATOR_WEB_CWD,'&&','php','yaamp/yiic.php','badpoolguard','payout-row-apply','--coin-id='.$coinId,'--selected-account-ids='.$this->csvIds($items,'account_id'),'--approval-package-checksum=<approval_package_checksum>','--selected-scope-checksum='.arraySafeVal($r['selected_scope_checksum'],'value'),'--projected-payout-row-checksum='.arraySafeVal($r['projected_payout_row_checksum'],'value'),'--projected-account-debit-checksum='.arraySafeVal($r['projected_account_debit_checksum'],'value'),'--operator-confirms-payout-row-creation=scrypt_balance_to_payout_rows_no_wallet_send','--format=json');
		$this->standardizeApprovalPackageContract($r, 'payout-row-creation', array('approval_package_checksum','selected_scope_checksum','projected_payout_row_checksum','projected_account_debit_checksum')); $r=$this->guard->finalizeReport($r); unset($r['report_checksum']); $r['approval_package_checksum']=$this->stableApprovalChecksum($r,array('approval_package_type','scope_binding','safety_binding','selected_scope_checksum','projected_payout_row_checksum','projected_account_debit_checksum','items','apply_command_shape','selected_records','checksums','apply_command_args')); $this->standardizeApprovalPackageContract($r, 'payout-row-creation', array('approval_package_checksum','selected_scope_checksum','projected_payout_row_checksum','projected_account_debit_checksum')); $r['report_checksum']=BadpoolGuardReport::checksum($r); return $r;
	}
	private function payoutRowApprovalForIds($ids)
	{
		$r = $this->payoutRowApprovalPackageReport();
		$items = $this->filterRowsByIds(arraySafeVal(arraySafeVal($r, 'items', array()), 'selected_accounts', array()), 'account_id', $ids);
		$r['items']['selected_accounts'] = $items;
		$r['summary']['selected_account_count'] = count($items);
		$r['selected_scope_checksum'] = BadpoolGuardReport::checksum(array('coin_id'=>arraySafeVal($this->guard->getScope(), 'coin_id'), 'accounts'=>$items));
		$r['projected_payout_row_checksum'] = BadpoolGuardReport::checksum(array_map(function($i){ return array('account_id'=>$i['account_id'], 'idcoin'=>$i['account_coinid'], 'amount'=>$i['projected_payout_row_amount'], 'completed'=>0, 'tx'=>null); }, $items));
		$r['projected_account_debit_checksum'] = BadpoolGuardReport::checksum(array_map(function($i){ return array('account_id'=>$i['account_id'], 'coinid'=>$i['account_coinid'], 'from_balance'=>$i['current_balance'], 'debit'=>$i['projected_account_debit_amount'], 'to_balance'=>$i['projected_remaining_balance']); }, $items));
		$r['apply_command_shape'] = $this->replacePayoutApplyScopeIds(arraySafeVal($r, 'apply_command_shape', array()), $ids);
		$this->clearFinalizedApprovalPackageContractFields($r);
		$this->standardizeApprovalPackageContract($r, 'payout-row-creation', array('approval_package_checksum','selected_scope_checksum','projected_payout_row_checksum','projected_account_debit_checksum'));
		unset($r['report_checksum']);
		$r['approval_package_checksum'] = $this->stableApprovalChecksum($r, array('approval_package_type', 'scope_binding', 'safety_binding', 'selected_scope_checksum', 'projected_payout_row_checksum', 'projected_account_debit_checksum', 'items', 'apply_command_shape', 'selected_records', 'checksums', 'apply_command_args'));
		$this->standardizeApprovalPackageContract($r, 'payout-row-creation', array('approval_package_checksum','selected_scope_checksum','projected_payout_row_checksum','projected_account_debit_checksum'));
		$r['report_checksum'] = BadpoolGuardReport::checksum($r);
		return $r;
	}

	private function payoutRowApplyReport($args)
	{
		$opts = $this->parsePayoutRowApplyOptions($args);
		$report = $this->guardedApplyBaseReport('payout-row', $opts);
		if (isset($opts['__parse_error'])) return $this->guardedApplyFail($report, 'invalid_option', $opts['__parse_error']);
		if ($this->guard->getFormat() !== 'json') return $this->guardedApplyFail($report, 'json_format_required', 'payout-row-apply supports --format=json only.');
		foreach (array('selected-account-ids', 'approval-package-checksum', 'selected-scope-checksum', 'projected-payout-row-checksum', 'projected-account-debit-checksum') as $r) {
			if (!isset($opts[$r]) || $opts[$r] === '') return $this->guardedApplyFail($report, 'missing_required_checksum', 'Missing required --'.$r.'.');
		}
		if (arraySafeVal($opts, 'operator-confirms-payout-row-creation') !== 'scrypt_balance_to_payout_rows_no_wallet_send') return $this->guardedApplyFail($report, 'operator_confirmation_required', 'Missing exact --operator-confirms-payout-row-creation=scrypt_balance_to_payout_rows_no_wallet_send.');
		$ids = $this->parseCsvIds(arraySafeVal($opts, 'selected-account-ids', ''));
		if (empty($ids)) return $this->guardedApplyFail($report, 'selected_account_ids_required', 'Apply refuses without selected account IDs.');
		if ($this->hasDuplicateIds($ids)) return $this->guardedApplyFail($report, 'selected_account_scope_mismatch', 'Duplicate selected account IDs are refused.');
		$approval = $this->payoutRowApprovalForIds($ids);
		$selectedAccounts = arraySafeVal(arraySafeVal($approval, 'items', array()), 'selected_accounts', array());
		if (empty($selectedAccounts) || count($selectedAccounts) !== count($ids)) return $this->guardedApplyFail($report, 'selected_account_scope_mismatch', 'Every requested account ID must be present in current payout candidates.');
		foreach (array('approval-package-checksum'=>'approval_package_checksum', 'selected-scope-checksum'=>'selected_scope_checksum', 'projected-payout-row-checksum'=>'projected_payout_row_checksum', 'projected-account-debit-checksum'=>'projected_account_debit_checksum') as $opt=>$field) {
			if ((string)$opts[$opt] !== (string)arraySafeVal(arraySafeVal($approval, $field, array()), 'value')) return $this->guardedApplyFail($report, 'checksum_mismatch', 'Expected checksum does not match freshly generated approval package state: --'.$opt.'.');
		}
		$schemaError = $this->payoutRowApplySchemaError();
		if ($schemaError !== null) return $this->guardedApplyFail($report, $schemaError['reason'], $schemaError['message']);
		$before = $this->payoutRowAccountBalances($ids);
		$tx = app()->db->beginTransaction();
		try {
			$applied = $this->applyPayoutRows($approval);
			$tx->commit();
			$after = $this->payoutRowAccountBalances($ids);
			return BadpoolGuardReport::finalize(array_merge($report, $applied, array('status'=>'pass', 'abort_reason'=>null, 'before_account_balances'=>$before, 'after_account_balances'=>$after, 'wallet_rpc_used'=>false, 'db_mutations'=>'guarded_transaction_committed', 'wallet_sends'=>false, 'withdraw_rows_created'=>false, 'backend_loops_run'=>false, 'shares_deleted'=>false)));
		} catch (Exception $e) {
			if ($tx->active) $tx->rollback();
			return $this->guardedApplyFail($report, 'mutation_failed_rolled_back', $e->getMessage());
		}
	}

	private function applyPayoutRows($approval)
	{
		$items = arraySafeVal(arraySafeVal($approval, 'items', array()), 'selected_accounts', array());
		$n = 0; $amount = 0.0; $ids = array(); $accounts = array();
		foreach ($items as $i) {
			$insert = app()->db->createCommand('INSERT INTO payouts (account_id, idcoin, time, amount, completed, tx) VALUES (:account_id, :idcoin, :time, :amount, 0, NULL)');
			$insert->execute(array(':account_id'=>$i['account_id'], ':idcoin'=>$i['account_coinid'], ':time'=>time(), ':amount'=>$i['projected_payout_row_amount']));
			$u = app()->db->createCommand('UPDATE accounts SET balance=:new_balance WHERE id=:id AND coinid=:coinid AND balance=:old_balance')->execute(array(':new_balance'=>$i['projected_remaining_balance'], ':id'=>$i['account_id'], ':coinid'=>$i['account_coinid'], ':old_balance'=>$i['current_balance']));
			if ($u !== 1) throw new Exception('selected account balance changed before apply: '.$i['account_id']);
			$n++; $amount += floatval($i['projected_payout_row_amount']); $ids[] = intval(app()->db->getLastInsertID()); $accounts[] = intval($i['account_id']);
		}
		return array('created_count'=>$n, 'created_amount'=>$this->decimalString($amount), 'created_payout_ids'=>$ids, 'debited_account_ids'=>$accounts, 'payout_rows_inserted'=>$n, 'payout_count'=>$n, 'payout_rows_insert_only'=>true, 'accounts_debited_to_projected_remaining_balance'=>true, 'payouts_marked_completed'=>false, 'old_payouts_retried_or_deleted'=>false);
	}

	private function parsePayoutRowApplyOptions($args)
	{
		$allowed = array('coin-id', 'format', 'selected-account-ids', 'approval-package-checksum', 'selected-scope-checksum', 'projected-payout-row-checksum', 'projected-account-debit-checksum', 'operator-confirms-payout-row-creation');
		$o = array();
		foreach ($args as $arg) {
			if (!preg_match('/^--([^=]+)=(.*)$/', $arg, $m)) { $o['__parse_error'] = 'Unknown argument refused: '.$arg; continue; }
			$n = strtolower($m[1]);
			if (!in_array($n, $allowed, true)) $o['__parse_error'] = 'Unknown option refused: --'.$m[1];
			elseif (isset($o[$n])) $o['__parse_error'] = 'Duplicate option refused: --'.$m[1];
			else $o[$n] = $m[2];
		}
		return $o;
	}

	private function payoutRowApplySchemaError()
	{
		foreach (array('account_id', 'idcoin', 'time', 'amount', 'completed', 'tx') as $column) {
			if (!$this->guard->tableExists('payouts') || !$this->guard->columnExists('payouts', $column)) return array('reason'=>'payout_schema_missing', 'message'=>'Required payouts schema is missing column: payouts.'.$column.'.');
		}
		foreach (array('id', 'coinid', 'balance') as $column) {
			if (!$this->guard->tableExists('accounts') || !$this->guard->columnExists('accounts', $column)) return array('reason'=>'accounts_schema_missing', 'message'=>'Required accounts schema is missing column: accounts.'.$column.'.');
		}
		return null;
	}

	private function payoutRowAccountBalances($ids)
	{
		$out = array();
		foreach ($ids as $id) {
			$row = $this->guard->selectRow('SELECT id AS account_id, coinid, balance FROM accounts WHERE id=:id', array(':id'=>$id));
			if ($row) $out[] = $row;
		}
		return $out;
	}

	private function replacePayoutApplyScopeIds($shape, $ids)
	{
		$csv = '--selected-account-ids='.implode(',', $ids);
		foreach ($shape as $k=>$v) if (strpos($v, '--selected-account-ids=') === 0) $shape[$k] = $csv;
		return $shape;
	}

	private function hasDuplicateIds($ids)
	{
		$seen = array();
		foreach ($ids as $id) { if (isset($seen[$id])) return true; $seen[$id] = true; }
		return false;
	}


	private function forwardCatchupStage1DrainPlanReport($args)
	{
		$options = $this->forwardCatchupStage1DrainOptions($args, false);
		$report = $this->forwardCatchupStage1DrainBaseReport('forward-catchup-stage1-drain-plan', true, $options);
		if (isset($options['__parse_error'])) return $this->forwardCatchupStage1DrainFail($report, 'invalid_option', $options['__parse_error']);
		$valid = $this->forwardCatchupStage1DrainValidate($report, $options, false);
		if ($valid !== true) return $valid;

		$coinId = intval(arraySafeVal($this->guard->getScope(), 'coin_id'));
		$maxBatches = intval($options['max-batches']);
		$batchLimit = intval($options['batch-limit']);
		$checkpoint = $this->forwardCatchupCheckpoint();
		$lastPayoutTime = arraySafeVal($checkpoint, 'last_payout_time');
		$candidates = $this->forwardCatchupStage1DryrunCandidates($lastPayoutTime, $maxBatches * $batchLimit);
		$chunks = array_chunk($candidates, $batchLimit);
		foreach (array_slice($chunks, 0, $maxBatches) as $idx => $chunk) {
			$classified = $this->forwardCatchupStage1DryrunClassify($chunk);
			$plan = $this->forwardCatchupStage1DryrunPlan($classified);
			$totals = $this->forwardCatchupStage1DryrunTotals($classified, $plan);
			$batch = $this->forwardCatchupStage1DrainBatchSummary($idx + 1, $classified, $plan, $totals);
			$report['per_batch'][] = $batch;
			$this->forwardCatchupStage1DrainAddTotals($report, $batch);
		}
		$report['final_preview_selected_count'] = count($candidates) > $maxBatches * $batchLimit ? $batchLimit : max(0, count($candidates) - count($report['per_batch']) * $batchLimit);
		$report['stop_reason'] = empty($report['per_batch']) ? 'preview_empty' : (count($report['per_batch']) >= $maxBatches ? 'max_batches_planned' : 'preview_empty');
		$report['status'] = 'pass';
		return BadpoolGuardReport::finalize($report);
	}

	private function forwardCatchupStage1DrainApplyReport($args)
	{
		$options = $this->forwardCatchupStage1DrainOptions($args, true);
		$report = $this->forwardCatchupStage1DrainBaseReport('forward-catchup-stage1-drain-apply', false, $options);
		if (isset($options['__parse_error'])) return $this->forwardCatchupStage1DrainFail($report, 'invalid_option', $options['__parse_error']);
		$valid = $this->forwardCatchupStage1DrainValidate($report, $options, true);
		if ($valid !== true) return $valid;
		for ($i = 1; $i <= intval($options['max-batches']); $i++) {
			$approval = $this->forwardCatchupStage1ApplyApprovalPackageReport();
			if (!$this->guard->isValid()) return $this->forwardCatchupStage1DrainFail($report, 'approval_package_refusal', 'Fresh Stage1 approval package refused.');
			$classified = arraySafeVal(arraySafeVal($approval, 'items', array()), 'candidates', array());
			$mutations = arraySafeVal(arraySafeVal($approval, 'items', array()), 'projected_block_mutations', array());
			$earnings = arraySafeVal(arraySafeVal($approval, 'items', array()), 'projected_pending_earnings', array());
			$totals = arraySafeVal(arraySafeVal($approval, 'summary', array()), 'totals', array());
			$selected = intval(arraySafeVal($totals, 'selected_count', 0));
			if ($selected === 0) { $report['stop_reason'] = 'preview_empty'; break; }
			if ($selected > intval($options['batch-limit'])) return $this->forwardCatchupStage1DrainFail($report, 'selected_count_mismatch', 'selected_count exceeds --batch-limit.');
			if (arraySafeVal($approval, 'overall_approval_package_status') !== 'pass') return $this->forwardCatchupStage1DrainFail($report, 'approval_package_refusal', 'Stage1 approval package status is not pass.');
			$gate = $this->forwardCatchupStage1ApplyPreflightGates($classified, $mutations, $earnings);
			if (arraySafeVal($gate, 'status') !== 'pass') return $this->forwardCatchupStage1DrainFail($report, 'apply_refusal', arraySafeVal($gate, 'message'));
			$batch = $this->forwardCatchupStage1DrainBatchSummary($i, $classified, array('projected_block_mutations'=>$mutations,'projected_pending_earnings'=>$earnings), $totals);
			$batch['approval_package_checksum'] = arraySafeVal($approval, 'approval_package_checksum');
			$tx = app()->db->beginTransaction();
			try {
				$report['apply_commands_executed'] = true;
				$applied = $this->forwardCatchupStage1ApplyMutations($mutations, $earnings);
				$tx->commit();
			} catch (Exception $e) {
				if ($tx && $tx->active) $tx->rollback();
				return $this->forwardCatchupStage1DrainFail($report, 'apply_refusal', $e->getMessage());
			}
			$verification = $this->forwardCatchupStage1ApplyCloseoutVerification($mutations, $applied);
			$batch['applied_generated_count'] = intval($applied['applied_generated_count']);
			$batch['applied_orphan_count'] = intval($applied['applied_orphan_count']);
			$batch['inserted_earnings_count'] = intval($applied['inserted_earnings_count']);
			$batch['post_apply_db_verification'] = $verification;
			$batch['reconciliation_status'] = arraySafeVal($verification, 'status') === 'pass' ? 'pass' : 'hold';
			if ($batch['inserted_earnings_count'] !== $batch['projected_earnings_rows'] || $batch['reconciliation_status'] !== 'pass') return $this->forwardCatchupStage1DrainFail($report, 'post_apply_verification_failure', 'Post-apply verification failed.');
			$report['per_batch'][] = $batch;
			$report['batches_attempted'] = $i;
			$report['batches_applied']++;
			$this->forwardCatchupStage1DrainAddTotals($report, $batch);
		}
		if ($report['stop_reason'] === null) $report['stop_reason'] = $report['batches_applied'] >= intval($options['max-batches']) ? 'max_batches_reached' : 'preview_empty';
		$preview = $this->forwardCatchupStage1ApplyDryrunReport();
		$report['final_preview_selected_count'] = intval(arraySafeVal(arraySafeVal(arraySafeVal($preview, 'summary', array()), 'totals', array()), 'selected_count', 0));
		$report['status'] = 'pass';
		return BadpoolGuardReport::finalize($report);
	}

	private function forwardCatchupStage1DrainContextArgs($args)
	{
		$result = array();
		foreach ($args as $arg) {
			if (preg_match('/^--batch-limit=(.*)$/', $arg, $m)) $result[] = '--limit='.$m[1];
			elseif (preg_match('/^--(coin-id|format)=/', $arg)) $result[] = $arg;
		}
		return $result;
	}

	private function forwardCatchupStage1DrainOptions($args, $apply)
	{
		$allowed = array('coin-id','format','max-batches','batch-limit');
		if ($apply) $allowed[] = 'operator-confirms-stage1-drain';
		$options = array('batch-limit' => self::FORWARD_CATCHUP_STAGE1_DRYRUN_MAX_LIMIT);
		foreach ($args as $arg) {
			if (!preg_match('/^--([^=]+)=(.*)$/', $arg, $m)) { if (strpos($arg, '--') === 0) $options['__parse_error'] = 'Option requires an explicit value: '.$arg; continue; }
			$name = strtolower($m[1]);
			if (!in_array($name, $allowed, true)) $options['__parse_error'] = 'Unknown option refused: --'.$m[1];
			elseif (isset($options[$name]) && $name !== 'batch-limit') $options['__parse_error'] = 'Duplicate option refused: --'.$m[1];
			else $options[$name] = $m[2];
		}
		return $options;
	}

	private function forwardCatchupStage1DrainValidate($report, $options, $apply)
	{
		if ($this->guard->isAllCoinsPreview()) return $this->forwardCatchupStage1DrainFail($report, 'coin_id_required', 'Stage1 drain requires --coin-id and refuses broad/all-coin scope.');
		if ($this->guard->getFormat() !== 'json') return $this->forwardCatchupStage1DrainFail($report, 'json_format_required', 'Stage1 drain supports --format=json only.');
		foreach (array('max-batches','batch-limit') as $name) if (!isset($options[$name]) || !preg_match('/^[0-9]+$/', (string)$options[$name]) || intval($options[$name]) <= 0) return $this->forwardCatchupStage1DrainFail($report, 'missing_required_option', 'Missing or invalid --'.$name.'.');
		if (intval($options['batch-limit']) > self::FORWARD_CATCHUP_STAGE1_DRYRUN_MAX_LIMIT) return $this->forwardCatchupStage1DrainFail($report, 'limit_gt_50', '--batch-limit maximum is 50.');
		if (intval($options['max-batches']) > self::FORWARD_CATCHUP_STAGE1_DRAIN_SAFE_MAX_BATCHES) return $this->forwardCatchupStage1DrainFail($report, 'max_batches_safe_cap_exceeded', '--max-batches exceeds configured safe cap '.self::FORWARD_CATCHUP_STAGE1_DRAIN_SAFE_MAX_BATCHES.'.');
		if ($apply && arraySafeVal($options, 'operator-confirms-stage1-drain') !== self::FORWARD_CATCHUP_STAGE1_DRAIN_CONFIRMATION) return $this->forwardCatchupStage1DrainFail($report, 'operator_confirmation_required', 'Missing exact --operator-confirms-stage1-drain='.self::FORWARD_CATCHUP_STAGE1_DRAIN_CONFIRMATION.'.');
		return true;
	}

	private function forwardCatchupStage1DrainBaseReport($command, $readOnly, $options)
	{
		$report = $readOnly ? $this->guard->baseReport() : $this->applyBaseReport($command, 'refused');
		$report['schema'] = self::APPLY_SCHEMA;
		$report['mode'] = $readOnly ? 'stage1-drain-plan' : self::APPLY_MODE;
		$report['command'] = $command;
		$report['read_only'] = $readOnly;
		$report['coin_id'] = arraySafeVal($this->guard->getScope(), 'coin_id');
		$report['batch_limit'] = intval(arraySafeVal($options, 'batch-limit', self::FORWARD_CATCHUP_STAGE1_DRYRUN_MAX_LIMIT));
		$report['max_batches'] = intval(arraySafeVal($options, 'max-batches', 0));
		foreach (array('batches_attempted','batches_applied','total_selected','total_generated','total_orphan','total_projected_earnings','total_inserted_earnings','final_preview_selected_count') as $k) $report[$k] = 0;
		$report['apply_commands_executed'] = false;
		$report['total_projected_pending_amount'] = 0.0;
		$report['stop_reason'] = null;
		$report['per_batch'] = array();
		$report['account_credit'] = false; $report['payout_rows_created'] = false; $report['wallet_sends'] = false; $report['backend_loops_run'] = false; $report['shares_deleted'] = false;
		return $report;
	}

	private function forwardCatchupStage1DrainBatchSummary($number, $classified, $plan, $totals)
	{
		$generated = intval(arraySafeVal($totals, 'stage1_import_generate_count', 0)) + intval(arraySafeVal($totals, 'stage1_import_immature_count', 0));
		$projectedEarnings = arraySafeVal($plan, 'projected_pending_earnings', array());
		return array('batch_number'=>$number,'selected_count'=>intval(arraySafeVal($totals,'selected_count',0)),'projected_generated_rows'=>$generated,'projected_earnings_rows'=>count($projectedEarnings),'projected_orphan_rows'=>intval(arraySafeVal($totals,'stage1_mark_orphan_no_earnings_count',0)),'projected_pending_amount'=>floatval(arraySafeVal($totals,'projected_pending_earnings_amount_gross',0)),'batch_scope_checksum'=>BadpoolGuardReport::checksum(array('blocks'=>$this->forwardCatchupStage1BatchScopeBlocks($classified))),'projected_mutation_checksum'=>BadpoolGuardReport::checksum($this->forwardCatchupStage1StableProjectedMutations(arraySafeVal($plan,'projected_block_mutations',array()))),'projected_earnings_checksum'=>BadpoolGuardReport::checksum($projectedEarnings),'inserted_earnings_count'=>0,'reconciliation_status'=>'planned');
	}

	private function forwardCatchupStage1DrainAddTotals(&$report, $batch)
	{
		$report['total_selected'] += intval($batch['selected_count']);
		$report['total_generated'] += intval($batch['projected_generated_rows']);
		$report['total_orphan'] += intval($batch['projected_orphan_rows']);
		$report['total_projected_earnings'] += intval(arraySafeVal($batch, 'projected_earnings_rows', $batch['projected_generated_rows']));
		$report['total_inserted_earnings'] += intval(arraySafeVal($batch, 'inserted_earnings_count', 0));
		$report['total_projected_pending_amount'] += floatval($batch['projected_pending_amount']);
	}

	private function forwardCatchupStage1DrainFail($report, $reason, $message)
	{
		$report['status'] = 'refused';
		$report['stop_reason'] = $reason;
		$report['account_credit'] = false; $report['payout_rows_created'] = false; $report['wallet_sends'] = false; $report['backend_loops_run'] = false; $report['shares_deleted'] = false;
		$report['errors'][] = $message;
		return BadpoolGuardReport::finalize($report);
	}

	private function earningsMaturityTransitionDryrunReport()
	{
		if ($this->guard->isAllCoinsPreview()) {
			$this->guard->addError('earnings-maturity-transition-dryrun requires --coin-id and refuses all-coin scope.');
			return $this->guard->refusalReport();
		}
		$coinId = intval(arraySafeVal($this->guard->getScope(), 'coin_id'));
		$rows = $this->guard->selectAll("SELECT E.id AS earning_id,E.userid,E.coinid,E.blockid,E.amount,E.status,E.mature_time,B.id AS block_id,B.height AS block_height,B.coin_id AS block_coin_id,B.category AS block_category,B.confirmations AS confirmations,C.mature_blocks AS mature_blocks FROM earnings E INNER JOIN blocks B ON B.id=E.blockid INNER JOIN coins C ON C.id=B.coin_id WHERE E.status=0 AND B.coin_id=:coin_id AND E.coinid=:coin_id AND B.category='immature' ORDER BY B.height,E.id", array(':coin_id'=>$coinId));
		$items = array(); $blocks = array(); $totalsByUser = array(); $total = 0.0; $heights = array(); $excluded = array();
		foreach ($rows as $r) {
			$proof = $this->maturityProof($r);
			if ($proof['status'] !== 'pass') {
				$reason = $proof['reason'];
				$excluded[$reason] = isset($excluded[$reason]) ? $excluded[$reason] + 1 : 1;
				continue;
			}
			$blockId = intval($r['block_id']);
			$item = array('earning_id'=>intval($r['earning_id']),'linked_block_id'=>$blockId,'block_height'=>intval($r['block_height']),'userid'=>intval($r['userid']),'coinid'=>intval($r['coinid']),'amount'=>$this->decimalString($r['amount']),'current_earning_status'=>intval($r['status']),'current_earning_mature_time'=>intval($r['mature_time']),'current_block_category'=>$r['block_category'],'block_confirmations'=>intval($r['confirmations']),'coin_mature_blocks'=>intval($r['mature_blocks']),'maturity_proof_status'=>$proof['status'],'maturity_proof_reason'=>$proof['reason'],'projected_block_category'=>'generate','projected_earnings_status'=>1,'projected_mature_time_behavior'=>'set_to_current_unix_timestamp_at_apply');
			$items[] = $item;
			if (!isset($blocks[$blockId])) $blocks[$blockId] = array('block_id'=>$blockId,'block_height'=>intval($r['block_height']),'current_category'=>$r['block_category'],'height'=>intval($r['block_height']),'coin_id'=>intval($r['block_coin_id']),'from_category'=>$r['block_category'],'to_category'=>'generate','confirmations'=>intval($r['confirmations']),'mature_blocks'=>intval($r['mature_blocks']),'maturity_proof_status'=>$proof['status'],'maturity_proof_reason'=>$proof['reason'],'projected_category'=>'generate','linked_earning_ids'=>array(),'total_amount'=>'0');
			$blocks[$blockId]['linked_earning_ids'][] = intval($r['earning_id']);
			$blocks[$blockId]['total_amount'] = $this->decimalAdd($blocks[$blockId]['total_amount'], $r['amount']);
			$u=(string)$r['userid']; if(!isset($totalsByUser[$u])) $totalsByUser[$u]=array('userid'=>intval($r['userid']),'earning_count'=>0,'amount_total'=>'0'); $totalsByUser[$u]['earning_count']++; $totalsByUser[$u]['amount_total']=$this->decimalAdd($totalsByUser[$u]['amount_total'],$r['amount']);
			$total += floatval($r['amount']); $heights[] = intval($r['block_height']);
		}
		if (empty($items) && count($rows) > 0 && empty($excluded['conservative_maturity_proof_unavailable'])) $excluded['conservative_maturity_proof_unavailable'] = 0;
		$blocks = array_values($blocks); usort($blocks, array($this,'sortByBlockId'));
		$report = $this->guard->baseReport();
		$report['summary']['selection_criteria'] = array("earnings.status=0", "earnings.blockid=blocks.id", "blocks.coin_id=--coin-id", "blocks.category='immature'", "DB maturity proof confirmations >= mature_blocks");
		$report['summary']['excluded_by_reason'] = $excluded;
		$report['summary']['selected_row_count'] = count($items); $report['summary']['linked_block_count'] = count($blocks); $report['summary']['total_amount'] = sprintf('%.12F',$total); $report['summary']['block_height_range'] = empty($heights)?null:array('min'=>min($heights),'max'=>max($heights)); $report['summary']['totals_by_user'] = array_values($totalsByUser);
		$report['items']['selected_earnings'] = $items; $report['items']['linked_blocks'] = $blocks;
		$report['selected_scope_checksum'] = BadpoolGuardReport::checksum(array('coin_id'=>$coinId,'earnings'=>$items,'blocks'=>$blocks));
		$report['projected_block_mutation_checksum'] = BadpoolGuardReport::checksum($blocks);
		$report['projected_earnings_mutation_checksum'] = BadpoolGuardReport::checksum(array_map(function($i){return array('earning_id'=>$i['earning_id'],'from_status'=>0,'to_status'=>1,'mature_time'=>'current_unix_timestamp_at_apply');}, $items));
		$report = $this->guard->finalizeReport($report); $report['dryrun_report_checksum'] = BadpoolGuardReport::checksum($report); return $report;
	}

	private function earningsMaturityTransitionApprovalPackageReport()
	{
		$dryrun = $this->earningsMaturityTransitionDryrunReport(); if (!$this->guard->isValid()) return $dryrun;
		$cmd = array('cd', self::OPERATOR_WEB_CWD, '&&', 'php', 'yaamp/yiic.php', 'badpoolguard', 'earnings-maturity-transition-apply','--coin-id='.arraySafeVal($this->guard->getScope(),'coin_id'),'--selected-earning-ids='.$this->csvIds(arraySafeVal(arraySafeVal($dryrun,'items',array()),'selected_earnings',array()), 'earning_id'),'--approval-package-checksum=<approval_package_checksum>','--selected-scope-checksum='.arraySafeVal(arraySafeVal($dryrun,'selected_scope_checksum',array()),'value'),'--projected-block-mutation-checksum='.arraySafeVal(arraySafeVal($dryrun,'projected_block_mutation_checksum',array()),'value'),'--projected-earnings-mutation-checksum='.arraySafeVal(arraySafeVal($dryrun,'projected_earnings_mutation_checksum',array()),'value'),'--operator-confirms-maturity-transition=scrypt_status0_to_status1','--format=json');
		$dryrun['approval_package_type']='earnings-maturity-transition'; $dryrun['approval_required']=true; $dryrun['apply_command_shape']=$cmd; $dryrun['apply_scope_binding']='Apply does not accept --limit; exact selected earning and block rows are bound by stable checksums.'; $dryrun['warnings'][]='No account balance mutation; no payout rows; no wallet sends; no backend loops.'; $this->standardizeApprovalPackageContract($dryrun, 'earnings-maturity-transition', array('approval_package_checksum','selected_scope_checksum','projected_block_mutation_checksum','projected_earnings_mutation_checksum')); unset($dryrun['report_checksum']); $dryrun['approval_package_checksum']=$this->stableApprovalChecksum($dryrun, array('approval_package_type','scope','selected_scope_checksum','projected_block_mutation_checksum','projected_earnings_mutation_checksum','items','apply_command_shape','apply_scope_binding','selected_records','checksums','apply_command_args')); $this->standardizeApprovalPackageContract($dryrun, 'earnings-maturity-transition', array('approval_package_checksum','selected_scope_checksum','projected_block_mutation_checksum','projected_earnings_mutation_checksum')); $dryrun['report_checksum']=BadpoolGuardReport::checksum($dryrun); return $dryrun;
	}

	private function accountCreditClearDryrunReport()
	{
		if ($this->guard->isAllCoinsPreview()) { $this->guard->addError('account-credit-clear-dryrun requires --coin-id and refuses all-coin scope.'); return $this->guard->refusalReport(); }
		$coinId=intval(arraySafeVal($this->guard->getScope(),'coin_id')); $delay=$this->paymentDelayThreshold();
		$rows=$this->guard->selectAll("SELECT E.id AS earning_id,E.userid,E.coinid,E.blockid,E.amount,E.status,E.mature_time,C.price AS coin_price,A.id AS account_id,A.coinid AS account_coinid,A.balance AS account_balance FROM earnings E INNER JOIN accounts A ON A.id=E.userid INNER JOIN coins C ON C.id=E.coinid WHERE E.status=1 AND E.mature_time<:delay AND E.coinid=:coin_id ORDER BY E.userid,E.id", array(':delay'=>$delay,':coin_id'=>$coinId));
		$items=array(); $totals=array(); $creditTotal=0.0;
		foreach($rows as $r){ $coin=getdbo('db_coins',intval($r['coinid'])); $user=getdbo('db_accounts',intval($r['userid'])); $value=($coin&&$user)?yaamp_convert_amount_user($coin,$r['amount'],$user):0; $item=array('earning_id'=>intval($r['earning_id']),'userid'=>intval($r['userid']),'coinid'=>intval($r['coinid']),'blockid'=>intval($r['blockid']),'amount'=>$this->decimalString($r['amount']),'current_earning_status'=>intval($r['status']),'mature_time'=>intval($r['mature_time']),'coin_price'=>$this->decimalString($r['coin_price']),'projected_converted_credit_value'=>$this->decimalString($value),'account_id'=>intval($r['account_id']),'account_coinid'=>intval($r['account_coinid']),'current_account_balance'=>$this->decimalString($r['account_balance']),'projected_account_balance'=>$this->decimalString(floatval($r['account_balance'])+$value)); $items[]=$item; $u=(string)$r['userid']; if(!isset($totals[$u]))$totals[$u]=array('userid'=>intval($r['userid']),'earning_count'=>0,'projected_credit_total'=>'0'); $totals[$u]['earning_count']++; $totals[$u]['projected_credit_total']=$this->decimalAdd($totals[$u]['projected_credit_total'],$value); $creditTotal+=$value; }
		$report=$this->guard->baseReport(); $report['summary']['payment_delay_threshold']=$delay; $report['summary']['selected_earnings_count']=count($items); $report['summary']['projected_credit_total']=$this->decimalString($creditTotal); $report['summary']['totals_by_user']=array_values($totals); $report['items']['selected_earnings']=$items;
		$report['selected_earnings_scope_checksum']=BadpoolGuardReport::checksum(array('coin_id'=>$coinId,'earnings'=>$items)); $report['projected_earnings_mutation_checksum']=BadpoolGuardReport::checksum(array_map(function($i){return array('earning_id'=>$i['earning_id'],'from_status'=>1,'to_status'=>2,'price'=>$i['coin_price']);},$items)); $report['projected_account_credit_checksum']=BadpoolGuardReport::checksum(array_map(function($i){return array('earning_id'=>$i['earning_id'],'account_id'=>$i['account_id'],'credit'=>$i['projected_converted_credit_value'],'from_balance'=>$i['current_account_balance'],'to_balance'=>$i['projected_account_balance']);},$items));
		$report=$this->guard->finalizeReport($report); $report['dryrun_report_checksum']=BadpoolGuardReport::checksum($report); return $report;
	}

	private function accountCreditClearApprovalPackageReport()
	{
		$dryrun=$this->accountCreditClearDryrunReport(); if(!$this->guard->isValid()) return $dryrun;
		$cmd=array('cd', self::OPERATOR_WEB_CWD, '&&', 'php', 'yaamp/yiic.php', 'badpoolguard', 'account-credit-clear-apply','--coin-id='.arraySafeVal($this->guard->getScope(),'coin_id'),'--selected-earning-ids='.$this->csvIds(arraySafeVal(arraySafeVal($dryrun,'items',array()),'selected_earnings',array()), 'earning_id'),'--approval-package-checksum=<approval_package_checksum>','--selected-earnings-scope-checksum='.arraySafeVal(arraySafeVal($dryrun,'selected_earnings_scope_checksum',array()),'value'),'--projected-earnings-mutation-checksum='.arraySafeVal(arraySafeVal($dryrun,'projected_earnings_mutation_checksum',array()),'value'),'--projected-account-credit-checksum='.arraySafeVal(arraySafeVal($dryrun,'projected_account_credit_checksum',array()),'value'),'--operator-confirms-account-credit=scrypt_status1_to_status2_balance_increment','--format=json');
		$dryrun['approval_package_type']='account-credit-clear'; $dryrun['approval_required']=true; $dryrun['apply_command_shape']=$cmd; $dryrun['apply_scope_binding']='Apply does not accept --limit; exact selected mature earnings and account credit projections are bound by stable checksums.'; $dryrun['warnings'][]='No payout rows; no wallet sends; no backend loops; no share deletion.'; $this->standardizeApprovalPackageContract($dryrun, 'account-credit-clear', array('approval_package_checksum','selected_earnings_scope_checksum','projected_earnings_mutation_checksum','projected_account_credit_checksum')); unset($dryrun['report_checksum']); $dryrun['approval_package_checksum']=$this->stableApprovalChecksum($dryrun,array('approval_package_type','scope','selected_earnings_scope_checksum','projected_earnings_mutation_checksum','projected_account_credit_checksum','items','apply_command_shape','apply_scope_binding','selected_records','checksums','apply_command_args')); $this->standardizeApprovalPackageContract($dryrun, 'account-credit-clear', array('approval_package_checksum','selected_earnings_scope_checksum','projected_earnings_mutation_checksum','projected_account_credit_checksum')); $dryrun['report_checksum']=BadpoolGuardReport::checksum($dryrun); return $dryrun;
	}



	private function clearFinalizedApprovalPackageContractFields(&$report)
	{
		unset($report['approval_package_checksum']);
		unset($report['checksums']);
		unset($report['apply_command']);
		unset($report['apply_command_args']);
		unset($report['selected_records']);
		unset($report['selected_count']);
		unset($report['selected_amount']);
	}

	private function earningsMaturityTransitionApprovalForIds($ids)
	{
		$report = $this->earningsMaturityTransitionApprovalPackageReport();
		$this->clearFinalizedApprovalPackageContractFields($report);
		$items = $this->filterRowsByIds(arraySafeVal(arraySafeVal($report,'items',array()),'selected_earnings',array()), 'earning_id', $ids);
		$blockIds = array(); foreach($items as $i) $blockIds[intval($i['linked_block_id'])]=true;
		$blocks = array(); foreach(arraySafeVal(arraySafeVal($report,'items',array()),'linked_blocks',array()) as $b) if(isset($blockIds[intval($b['block_id'])])) $blocks[]=$b;
		$report['items']['selected_earnings']=$items; $report['items']['linked_blocks']=$blocks;
		$report['apply_command_shape']=$this->replaceApplyScopeIds(arraySafeVal($report,'apply_command_shape',array()), $ids);
		$report['selected_scope_checksum']=BadpoolGuardReport::checksum(array('coin_id'=>arraySafeVal($this->guard->getScope(),'coin_id'),'earnings'=>$items,'blocks'=>$blocks));
		$report['projected_block_mutation_checksum']=BadpoolGuardReport::checksum($blocks);
		$report['projected_earnings_mutation_checksum']=BadpoolGuardReport::checksum(array_map(function($i){return array('earning_id'=>$i['earning_id'],'from_status'=>0,'to_status'=>1,'mature_time'=>'current_unix_timestamp_at_apply');}, $items));
		$this->standardizeApprovalPackageContract($report, 'earnings-maturity-transition', array('approval_package_checksum','selected_scope_checksum','projected_block_mutation_checksum','projected_earnings_mutation_checksum')); unset($report['report_checksum']); $report['approval_package_checksum']=$this->stableApprovalChecksum($report, array('approval_package_type','scope','selected_scope_checksum','projected_block_mutation_checksum','projected_earnings_mutation_checksum','items','apply_command_shape','apply_scope_binding','selected_records','checksums','apply_command_args')); $this->standardizeApprovalPackageContract($report, 'earnings-maturity-transition', array('approval_package_checksum','selected_scope_checksum','projected_block_mutation_checksum','projected_earnings_mutation_checksum')); $report['report_checksum']=BadpoolGuardReport::checksum($report); return $report;
	}

	private function accountCreditClearApprovalForIds($ids)
	{
		$report = $this->accountCreditClearApprovalPackageReport();
		$this->clearFinalizedApprovalPackageContractFields($report);
		$items = $this->filterRowsByIds(arraySafeVal(arraySafeVal($report,'items',array()),'selected_earnings',array()), 'earning_id', $ids);
		$report['items']['selected_earnings']=$items;
		$report['apply_command_shape']=$this->replaceApplyScopeIds(arraySafeVal($report,'apply_command_shape',array()), $ids);
		$report['selected_earnings_scope_checksum']=BadpoolGuardReport::checksum(array('coin_id'=>arraySafeVal($this->guard->getScope(),'coin_id'),'earnings'=>$items));
		$report['projected_earnings_mutation_checksum']=BadpoolGuardReport::checksum(array_map(function($i){return array('earning_id'=>$i['earning_id'],'from_status'=>1,'to_status'=>2,'price'=>$i['coin_price']);},$items));
		$report['projected_account_credit_checksum']=BadpoolGuardReport::checksum(array_map(function($i){return array('earning_id'=>$i['earning_id'],'account_id'=>$i['account_id'],'credit'=>$i['projected_converted_credit_value'],'from_balance'=>$i['current_account_balance'],'to_balance'=>$i['projected_account_balance']);},$items));
		$this->standardizeApprovalPackageContract($report, 'account-credit-clear', array('approval_package_checksum','selected_earnings_scope_checksum','projected_earnings_mutation_checksum','projected_account_credit_checksum')); unset($report['report_checksum']); $report['approval_package_checksum']=$this->stableApprovalChecksum($report,array('approval_package_type','scope','selected_earnings_scope_checksum','projected_earnings_mutation_checksum','projected_account_credit_checksum','items','apply_command_shape','apply_scope_binding','selected_records','checksums','apply_command_args')); $this->standardizeApprovalPackageContract($report, 'account-credit-clear', array('approval_package_checksum','selected_earnings_scope_checksum','projected_earnings_mutation_checksum','projected_account_credit_checksum')); $report['report_checksum']=BadpoolGuardReport::checksum($report); return $report;
	}

	private function earningsMaturityTransitionApplyReport($args)
	{ return $this->guardedApplyReport($args, 'earnings-maturity-transition'); }
	private function accountCreditClearApplyReport($args)
	{ return $this->guardedApplyReport($args, 'account-credit-clear'); }

	private function guardedApplyReport($args, $type)
	{
		$opts=$this->parseGuardedApplyOptions($args); $report=$this->guardedApplyBaseReport($type,$opts);
		$required = $type==='earnings-maturity-transition' ? array('selected-earning-ids','approval-package-checksum','selected-scope-checksum','projected-block-mutation-checksum','projected-earnings-mutation-checksum') : array('selected-earning-ids','approval-package-checksum','selected-earnings-scope-checksum','projected-earnings-mutation-checksum','projected-account-credit-checksum');
		if(isset($opts['__parse_error'])) return $this->guardedApplyFail($report,'invalid_option',$opts['__parse_error']); if($this->guard->isAllCoinsPreview()) return $this->guardedApplyFail($report,'coin_id_required',$type.' requires --coin-id and refuses all-coin scope.'); if($this->guard->getFormat()!=='json') return $this->guardedApplyFail($report,'json_format_required',$type.' apply supports --format=json only.'); foreach($required as $r) if(!isset($opts[$r])||$opts[$r]==='') return $this->guardedApplyFail($report,'missing_required_checksum','Missing required --'.$r.'.');
		$confirm = $type==='earnings-maturity-transition' ? 'operator-confirms-maturity-transition' : 'operator-confirms-account-credit'; $confirmVal = $type==='earnings-maturity-transition' ? 'scrypt_status0_to_status1' : 'scrypt_status1_to_status2_balance_increment'; if(arraySafeVal($opts,$confirm)!==$confirmVal) return $this->guardedApplyFail($report,'operator_confirmation_required','Missing exact --'.$confirm.'='.$confirmVal.'.');
		$ids=$this->parseCsvIds(arraySafeVal($opts,'selected-earning-ids','')); if(empty($ids)) return $this->guardedApplyFail($report,'selected_row_ids_required','Apply scope must be exact selected row IDs.'); $approval = $type==='earnings-maturity-transition' ? $this->earningsMaturityTransitionApprovalForIds($ids) : $this->accountCreditClearApprovalForIds($ids); if(!$this->guard->isValid()) return $this->guardedApplyFail($report,'approval_package_recompute_failed','Fresh approval package recompute failed.');
		$checks = $type==='earnings-maturity-transition' ? array('approval-package-checksum'=>'approval_package_checksum','selected-scope-checksum'=>'selected_scope_checksum','projected-block-mutation-checksum'=>'projected_block_mutation_checksum','projected-earnings-mutation-checksum'=>'projected_earnings_mutation_checksum') : array('approval-package-checksum'=>'approval_package_checksum','selected-earnings-scope-checksum'=>'selected_earnings_scope_checksum','projected-earnings-mutation-checksum'=>'projected_earnings_mutation_checksum','projected-account-credit-checksum'=>'projected_account_credit_checksum');
		foreach($checks as $opt=>$field){ if((string)arraySafeVal($opts,$opt)!==(string)arraySafeVal(arraySafeVal($approval,$field,array()),'value')) return $this->guardedApplyFail($report,'checksum_mismatch','Expected checksum does not match freshly generated approval package state: --'.$opt.'.'); }
		$before=$this->guardedApplyBeforeState(); $tx=app()->db->beginTransaction(); if(!$tx) return $this->guardedApplyFail($report,'transaction_unavailable','Database transaction mechanism unavailable.');
		try{ $applied = $type==='earnings-maturity-transition' ? $this->applyMaturityTransitionRows($approval) : $this->applyAccountCreditRows($approval); $tx->commit(); $after=$this->guardedApplyBeforeState(); $report=array_merge($report,$applied); $report['status']='pass'; $report['abort_reason']=null; $report['before']=$before; $report['after']=$after; $delta=$this->unselectedCandidateDelta($approval); $report['new_unselected_candidates_detected']=$delta > 0; $report['unselected_candidate_count_delta']=$delta; $report['note']='unselected drift is informational only and not part of authorization'; return BadpoolGuardReport::finalize($report); } catch(Exception $e){ if($tx->active)$tx->rollback(); return $this->guardedApplyFail($report,'mutation_failed_rolled_back',$e->getMessage()); }
	}

	private function applyMaturityTransitionRows($approval)
	{ $items=arraySafeVal(arraySafeVal($approval,'items',array()),'selected_earnings',array()); $blocks=arraySafeVal(arraySafeVal($approval,'items',array()),'linked_blocks',array()); $now=time(); foreach($blocks as $b){ $n=app()->db->createCommand("UPDATE blocks SET category='generate' WHERE id=:id AND coin_id=:coin_id AND height=:height AND category='immature'")->execute(array(':id'=>$b['block_id'],':coin_id'=>$b['coin_id'],':height'=>$b['height'])); if($n!==1) throw new Exception('selected block changed or disappeared: '.$b['block_id']); } $amount=0.0; $users=array(); foreach($items as $i){ $n=app()->db->createCommand("UPDATE earnings SET status=1,mature_time=:mt WHERE id=:id AND userid=:uid AND coinid=:cid AND blockid=:bid AND status=0")->execute(array(':mt'=>$now,':id'=>$i['earning_id'],':uid'=>$i['userid'],':cid'=>$i['coinid'],':bid'=>$i['linked_block_id'])); if($n!==1) throw new Exception('selected earning changed or disappeared: '.$i['earning_id']); $amount+=floatval($i['amount']); $users[$i['userid']]=isset($users[$i['userid']])?$users[$i['userid']]+floatval($i['amount']):floatval($i['amount']); } return array('selected_count'=>count($items),'applied_block_count'=>count($blocks),'applied_earning_count'=>count($items),'projected_amount_total'=>$this->decimalString($amount),'applied_amount_total'=>$this->decimalString($amount),'affected_user_totals'=>$users,'no_account_credit'=>true,'no_payout_rows'=>true,'wallet_sends'=>false,'backend_loops_run'=>false,'shares_deleted'=>false); }

	private function applyAccountCreditRows($approval)
	{ $items=arraySafeVal(arraySafeVal($approval,'items',array()),'selected_earnings',array()); $credit=0.0; $accounts=array(); foreach($items as $i){ $n=app()->db->createCommand("UPDATE earnings SET status=2,price=:price WHERE id=:id AND userid=:uid AND coinid=:cid AND blockid=:bid AND status=1 AND mature_time=:mt")->execute(array(':price'=>$i['coin_price'],':id'=>$i['earning_id'],':uid'=>$i['userid'],':cid'=>$i['coinid'],':bid'=>$i['blockid'],':mt'=>$i['mature_time'])); if($n!==1) throw new Exception('selected earning changed or disappeared: '.$i['earning_id']); $n=app()->db->createCommand("UPDATE accounts SET balance=balance+:credit WHERE id=:id AND coinid=:coinid")->execute(array(':credit'=>$i['projected_converted_credit_value'],':id'=>$i['account_id'],':coinid'=>$i['account_coinid'])); if($n!==1) throw new Exception('selected account changed or disappeared: '.$i['account_id']); $credit+=floatval($i['projected_converted_credit_value']); $accounts[$i['account_id']]=isset($accounts[$i['account_id']])?$accounts[$i['account_id']]+floatval($i['projected_converted_credit_value']):floatval($i['projected_converted_credit_value']); } return array('selected_count'=>count($items),'applied_count'=>count($items),'applied_amount'=>$this->decimalString($credit),'affected_account_ids'=>array_map('intval', array_keys($accounts)),'selected_earnings_count'=>count($items),'credited_earnings_count'=>count($items),'projected_credit_total'=>$this->decimalString($credit),'applied_credit_total'=>$this->decimalString($credit),'affected_account_count'=>count($accounts),'affected_account_totals'=>$accounts,'payout_rows_created'=>false,'wallet_sends'=>false,'backend_loops_run'=>false,'shares_deleted'=>false); }

	private function walletSendApplyContextArgs($args){ $out=array(); foreach($args as $arg){ if(preg_match('/^--(coin-id|format|selected-payout-ids)(=.*)?$/i',$arg)) $out[]=$arg; } return $out; }
	private function guardedApplyContextArgs($args){ $out=array(); foreach($args as $arg){ if(preg_match('/^--(coin-id|format)(=.*)?$/i',$arg)) $out[]=$arg; } return $out; }
	private function parseGuardedApplyOptions($args){ $allowed=array('coin-id','format','selected-earning-ids','approval-package-checksum','selected-scope-checksum','projected-block-mutation-checksum','projected-earnings-mutation-checksum','operator-confirms-maturity-transition','selected-earnings-scope-checksum','projected-account-credit-checksum','operator-confirms-account-credit'); $o=array(); foreach($args as $arg){ if(!preg_match('/^--([^=]+)=(.*)$/',$arg,$m)){ $o['__parse_error']='Unknown argument refused: '.$arg; continue; } $n=strtolower($m[1]); if(!in_array($n,$allowed,true)) $o['__parse_error']='Unknown option refused: --'.$m[1]; elseif(isset($o[$n])) $o['__parse_error']='Duplicate option refused: --'.$m[1]; else $o[$n]=$m[2]; } return $o; }
	private function applyBaseReport($command,$status='refused'){ $r=$this->guard->baseReport($status); $r['schema']=self::APPLY_SCHEMA; $r['mode']=self::APPLY_MODE; $r['command']=$command; $r['read_only']=false; $r['blocked_actions']=array('unapproved_scope','checksum_mismatch','missing_operator_confirmation','backend_loops','service_or_cron_changes','share_deletion','payout_retry_delete'); return $r; }
	private function guardedApplyBaseReport($type,$opts){ $r=$this->applyBaseReport($type.'-apply', 'refused'); $r['db_mutations']='guarded_transaction_only'; $r['wallet_reads']=false; $r['wallet_sends']=false; $r['payout_rows_created']=false; $r['withdraw_rows_created']=false; $r['backend_loops_run']=false; $r['shares_deleted']=false; return $r; }
	private function guardedApplyFail($report,$reason,$msg){ $report['status']='refused'; $report['abort_reason']=$reason; $report['errors'][]=$msg; return BadpoolGuardReport::finalize($report); }
	private function guardedApplyBeforeState(){ $coinId=intval(arraySafeVal($this->guard->getScope(),'coin_id')); return array('earnings_status_counts'=>$this->groupSummary('earnings','status',array('sql'=>'coinid=:coin_id','params'=>array(':coin_id'=>$coinId))),'block_category_counts'=>$this->groupSummary('blocks','category',array('sql'=>'coin_id=:coin_id','params'=>array(':coin_id'=>$coinId)))); }
	private function paymentDelayThreshold(){ return YAAMP_ALLOW_EXCHANGE ? time() - (int)YAAMP_PAYMENTS_FREQ : time() - (YAAMP_PAYMENTS_FREQ / 2); }
	private function stableApprovalChecksum($report,$keys){ $in=array(); foreach($keys as $k) $in[$k]=arraySafeVal($report,$k); return BadpoolGuardReport::checksum($in); }
	private function standardizeApprovalPackageContract(&$report, $packageType, $checksumFields)
	{
		$report['schema'] = 'badpool.approval_package.v1';
		$report['package_type'] = $packageType;
		$report['coin_id'] = intval(arraySafeVal(arraySafeVal($report, 'scope', array()), 'coin_id', arraySafeVal($this->guard->getScope(), 'coin_id')));
		if (!isset($report['apply_command_args'])) $report['apply_command_args'] = arraySafeVal($report, 'apply_command_shape', arraySafeVal($report, 'intended_apply_command_shape', array()));
		if (!isset($report['apply_command'])) $report['apply_command'] = implode(' ', $report['apply_command_args']);
		$report['selected_records'] = $this->approvalPackageSelectedRecords($report, $packageType);
		$report['selected_count'] = count($report['selected_records']);
		$report['selected_amount'] = $this->approvalPackageSelectedAmount($report['selected_records'], $packageType);
		if ($packageType === 'payout-row-creation') $report['selected_amount'] = (string)arraySafeVal(arraySafeVal($report, 'summary', array()), 'projected_payout_total', $report['selected_amount']);
		$checksums = array();
		foreach ($checksumFields as $field) {
			$value = arraySafeVal($report, $field);
			if (is_array($value) && array_key_exists('value', $value)) $value = $value['value'];
			if ($value !== null && $value !== '') $checksums[$field] = $value;
		}
		$report['checksums'] = $checksums;
	}

	private function approvalPackageSelectedRecords($report, $packageType)
	{
		if ($packageType === 'forward-catchup-stage1-apply') return $this->stage1ApprovalSelectedRecords($report);
		if ($packageType === 'payout-row-creation') return $this->payoutRowApprovalSelectedRecords($report);
		if ($packageType === 'wallet-send') return $this->walletSendApprovalSelectedRecords($report);
		$items = arraySafeVal(arraySafeVal($report, 'items', array()), 'selected_earnings', array());
		$records = array();
		foreach ($items as $item) {
			if ($packageType === 'earnings-maturity-transition') {
				$records[] = array(
					'earning_id' => intval(arraySafeVal($item, 'earning_id')),
					'block_id' => intval(arraySafeVal($item, 'linked_block_id', arraySafeVal($item, 'blockid'))),
					'height' => intval(arraySafeVal($item, 'block_height', arraySafeVal($item, 'height'))),
					'account_id' => intval(arraySafeVal($item, 'account_id', arraySafeVal($item, 'userid'))),
					'amount' => arraySafeVal($item, 'amount'),
					'current_earning_status' => arraySafeVal($item, 'current_earning_status'),
					'current_block_category' => arraySafeVal($item, 'current_block_category'),
					'expected_post_apply_earning_status' => arraySafeVal($item, 'projected_earnings_status'),
					'expected_post_apply_block_category' => arraySafeVal($item, 'projected_block_category'),
				);
			} else {
				$records[] = array(
					'earning_id' => intval(arraySafeVal($item, 'earning_id')),
					'account_id' => intval(arraySafeVal($item, 'account_id', arraySafeVal($item, 'userid'))),
					'amount' => arraySafeVal($item, 'amount'),
					'current_status' => arraySafeVal($item, 'current_earning_status'),
					'expected_post_apply_status' => 2,
					'expected_post_apply_account_delta' => arraySafeVal($item, 'projected_converted_credit_value'),
				);
			}
		}
		return $records;
	}

	private function payoutRowApprovalSelectedRecords($report)
	{
		$items = arraySafeVal(arraySafeVal($report, 'items', array()), 'selected_accounts', array());
		$records = array();
		foreach ($items as $item) $records[] = array('account_id'=>intval(arraySafeVal($item,'account_id')), 'coin_id'=>intval(arraySafeVal($item,'account_coinid', arraySafeVal($report,'coin_id'))), 'amount'=>arraySafeVal($item,'projected_payout_row_amount'), 'projected_account_debit'=>arraySafeVal($item,'projected_account_debit_amount'), 'expected_payout_account_id'=>intval(arraySafeVal($item,'account_id')), 'expected_payout_idcoin'=>intval(arraySafeVal($item,'account_coinid')), 'expected_payout_amount'=>arraySafeVal($item,'projected_payout_row_amount'), 'expected_payout_completed'=>0, 'expected_payout_tx'=>null);
		return $records;
	}

	private function walletSendApprovalSelectedRecords($report)
	{
		$records = array();
		foreach (arraySafeVal($report, 'row_inventory', array()) as $row) $records[] = array('payout_id'=>intval(arraySafeVal($row,'payout_id')), 'account_id'=>intval(arraySafeVal($row,'account_id')), 'amount'=>arraySafeVal($row,'amount'), 'completed'=>intval(arraySafeVal($row,'completed')), 'tx'=>arraySafeVal($row,'tx'), 'destination_address'=>arraySafeVal($row,'destination_address', arraySafeVal($row,'recipient')), 'wallet_send_amount'=>arraySafeVal($row,'wallet_send_amount', arraySafeVal($row,'amount')));
		return $records;
	}

	private function stage1ApprovalSelectedRecords($report)
	{
		$candidates = arraySafeVal(arraySafeVal($report, 'items', array()), 'candidates', array());
		$earningsByBlock = array();
		foreach (arraySafeVal(arraySafeVal($report, 'items', array()), 'projected_pending_earnings', array()) as $earning) $earningsByBlock[intval(arraySafeVal($earning, 'blockid'))] = arraySafeVal($earning, 'amount');
		$records = array();
		foreach ($candidates as $item) {
			$blockId = intval(arraySafeVal($item, 'id'));
			$records[] = array(
				'block_id' => $blockId,
				'height' => intval(arraySafeVal($item, 'height')),
				'current_state' => arraySafeVal($item, 'category'),
				'current_category' => arraySafeVal($item, 'category'),
				'classification' => arraySafeVal($item, 'classification'),
				'projected_earning_amount' => array_key_exists($blockId, $earningsByBlock) ? $earningsByBlock[$blockId] : null,
			);
		}
		return $records;
	}

	private function approvalPackageSelectedAmount($records, $packageType)
	{
		$total = 0.0;
		foreach ($records as $record) {
			$field = $packageType === 'account-credit-clear' ? 'expected_post_apply_account_delta' : 'amount';
			if ($packageType === 'forward-catchup-stage1-apply') $field = 'projected_earning_amount';
			$total += floatval(arraySafeVal($record, $field, 0));
		}
		return $this->decimalString($total);
	}
	private function decimalString($v){ return sprintf('%.12F', floatval($v)); }
	private function decimalAdd($a,$b){ return $this->decimalString(floatval($a)+floatval($b)); }
	private function sortByBlockId($a,$b){ return intval($a['block_id'])-intval($b['block_id']); }


	private function replaceApplyScopeIds($shape, $ids)
	{
		$csv = '--selected-earning-ids='.implode(',', $ids);
		foreach ($shape as $k => $v) if (strpos($v, '--selected-earning-ids=') === 0) $shape[$k] = $csv;
		return $shape;
	}
	private function unselectedCandidateDelta($approval)
	{
		$summaryCount = intval(arraySafeVal(arraySafeVal($approval,'summary',array()), 'selected_row_count', arraySafeVal(arraySafeVal($approval,'summary',array()), 'selected_earnings_count', 0)));
		$selectedCount = count(arraySafeVal(arraySafeVal($approval,'items',array()),'selected_earnings',array()));
		return max(0, $summaryCount - $selectedCount);
	}
	private function maturityProof($row)
	{
		if (!isset($row['confirmations']) || $row['confirmations'] === '' || !is_numeric($row['confirmations'])) return array('status'=>'blocked','reason'=>'missing_confirmations');
		if (!isset($row['mature_blocks']) || $row['mature_blocks'] === '' || !is_numeric($row['mature_blocks']) || intval($row['mature_blocks']) <= 0) return array('status'=>'blocked','reason'=>'missing_mature_blocks');
		if ($row['block_category'] !== 'immature') return array('status'=>'blocked','reason'=>'non_immature_category');
		if (intval($row['confirmations']) < intval($row['mature_blocks'])) return array('status'=>'blocked','reason'=>'confirmations_below_mature_blocks');
		return array('status'=>'pass','reason'=>'confirmations_gte_mature_blocks');
	}
	private function csvIds($rows,$field){ $ids=array(); foreach($rows as $r) $ids[]=intval(arraySafeVal($r,$field)); return implode(',', $ids); }
	private function parseCsvIds($csv){ if(!preg_match('/^[0-9]+(,[0-9]+)*$/',(string)$csv)) return array(); $ids=array(); foreach(explode(',',$csv) as $id) $ids[]=intval($id); return $ids; }
	private function filterRowsByIds($rows,$field,$ids){ $want=array(); foreach($ids as $id) $want[(string)$id]=true; $out=array(); foreach($rows as $r) if(isset($want[(string)arraySafeVal($r,$field)])) $out[]=$r; return $out; }
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
			'id', 'symbol', 'symbol2', 'name', 'algo', 'account', 'enable', 'installed', 'visible', 'auto_ready',
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

	private function earningsBlockReconciliationRowTotals()
	{
		if (!$this->guard->tableExists('earnings')) {
			return $this->guard->missingTable('earnings');
		}
		$schema = $this->earningsBlockReconciliationSchema();
		if (!$schema['earnings_has_status']) {
			return array('error' => 'earnings.status column is missing');
		}

		$where = $this->earningsCreditReadinessWhere('E');
		$parts = array(
			'E.'.$this->guard->qcol('status').' AS status',
			'COUNT(*) AS row_count',
		);
		if ($schema['earnings_has_amount']) {
			$parts[] = 'SUM(E.'.$this->guard->qcol('amount').') AS amount_sum';
		}
		if ($schema['earnings_has_blockid']) {
			$parts[] = 'COUNT(DISTINCT E.'.$this->guard->qcol('blockid').') AS distinct_blockid_count';
		}

		$sql = 'SELECT '.implode(', ', $parts).
			' FROM earnings E WHERE '.$where['sql'].' AND E.'.$this->guard->qcol('status').' IN (0, 1)'.
			' GROUP BY E.'.$this->guard->qcol('status').' ORDER BY E.'.$this->guard->qcol('status');
		$rows = $this->guard->selectAll($sql, $where['params']);

		return array(
			'filter' => $where['filter'],
			'rows' => $rows,
			'totals' => array(
				'row_count' => $this->sumRows($rows, 'row_count'),
				'amount_sum' => $this->sumRows($rows, 'amount_sum'),
				'distinct_blockid_count' => $this->sumRows($rows, 'distinct_blockid_count'),
			),
			'note' => 'Earnings rows are miner/account rows. They are not the same unit as block rows.',
		);
	}

	private function earningsBlockReconciliationBlockLinkage()
	{
		if (!$this->guard->tableExists('earnings')) {
			return $this->guard->missingTable('earnings');
		}
		$schema = $this->earningsBlockReconciliationSchema();
		if (!$schema['earnings_has_status']) {
			return array('error' => 'earnings.status column is missing');
		}
		if (!$schema['earnings_has_blockid']) {
			return array('status' => 'indeterminate', 'error' => 'earnings.blockid column is missing', 'schema' => $schema);
		}

		$where = $this->earningsCreditReadinessWhere('E');
		$blockid = 'E.'.$this->guard->qcol('blockid');
		$parts = array(
			'COUNT(*) AS inspected_earnings_rows',
			"SUM(CASE WHEN $blockid IS NOT NULL AND $blockid > 0 THEN 1 ELSE 0 END) AS earnings_rows_with_blockid",
			"SUM(CASE WHEN $blockid IS NULL OR $blockid <= 0 THEN 1 ELSE 0 END) AS earnings_rows_without_blockid",
			"COUNT(DISTINCT $blockid) AS distinct_blockid_count",
		);
		$join = '';
		if ($schema['can_join_earnings_blocks']) {
			$join = ' LEFT JOIN blocks B ON B.'.$this->guard->qcol('id').'=E.'.$this->guard->qcol('blockid');
			$parts[] = 'SUM(CASE WHEN B.'.$this->guard->qcol('id').' IS NOT NULL THEN 1 ELSE 0 END) AS earnings_rows_with_matching_block';
			$parts[] = 'SUM(CASE WHEN B.'.$this->guard->qcol('id').' IS NULL THEN 1 ELSE 0 END) AS earnings_rows_without_matching_block';
			$parts[] = "SUM(CASE WHEN $blockid IS NOT NULL AND $blockid > 0 AND B.".$this->guard->qcol('id').' IS NULL THEN 1 ELSE 0 END) AS earnings_rows_with_blockid_without_matching_block';
			$parts[] = 'COUNT(DISTINCT B.'.$this->guard->qcol('id').') AS distinct_linked_block_count';
		} else {
			$parts[] = 'NULL AS earnings_rows_with_matching_block';
			$parts[] = 'NULL AS earnings_rows_without_matching_block';
			$parts[] = 'NULL AS earnings_rows_with_blockid_without_matching_block';
			$parts[] = 'NULL AS distinct_linked_block_count';
		}

		$sql = 'SELECT '.implode(', ', $parts).' FROM earnings E'.$join.
			' WHERE '.$where['sql'].' AND E.'.$this->guard->qcol('status').' IN (0, 1)';

		return array(
			'filter' => $where['filter'],
			'schema' => $schema,
			'summary' => $this->guard->selectRow($sql, $where['params']),
			'note' => 'Rows without blockid or without a matching block remain informational blockers.',
		);
	}

	private function earningsBlockReconciliationRowsPerBlock()
	{
		if (!$this->guard->tableExists('earnings')) {
			return $this->guard->missingTable('earnings');
		}
		$schema = $this->earningsBlockReconciliationSchema();
		if (!$schema['earnings_has_status'] || !$schema['earnings_has_blockid']) {
			return array('status' => 'indeterminate', 'error' => 'earnings.status and earnings.blockid are required');
		}

		$where = $this->earningsCreditReadinessWhere('E');
		$blockid = 'E.'.$this->guard->qcol('blockid');
		$sql = 'SELECT '.
			'COUNT(*) AS grouped_blockid_count, '.
			'SUM(CASE WHEN D.row_count=1 THEN 1 ELSE 0 END) AS one_earning_row_per_block_count, '.
			'SUM(CASE WHEN D.row_count=2 THEN 1 ELSE 0 END) AS two_earning_rows_per_block_count, '.
			'SUM(CASE WHEN D.row_count>=3 THEN 1 ELSE 0 END) AS three_plus_earning_rows_per_block_count, '.
			'MAX(D.row_count) AS max_earnings_rows_linked_to_one_block, '.
			'SUM(CASE WHEN D.row_count>1 THEN D.row_count ELSE 0 END) AS earnings_rows_in_multirow_blocks, '.
			'SUM(CASE WHEN D.row_count>1 THEN D.row_count - 1 ELSE 0 END) AS additional_earnings_rows_beyond_one_per_block '.
			'FROM ('.
				'SELECT '.$blockid.' AS blockid, COUNT(*) AS row_count FROM earnings E '.
				'WHERE '.$where['sql'].' AND E.'.$this->guard->qcol('status').' IN (0, 1) '.
				"AND $blockid IS NOT NULL AND $blockid > 0 ".
				'GROUP BY '.$blockid.
			') D';
		$summary = $this->guard->selectRow($sql, $where['params']);

		return array(
			'filter' => $where['filter'],
			'summary' => $summary,
			'note' => 'A single block can have multiple earnings rows, so earnings row counts can exceed linked block counts.',
		);
	}

	private function earningsBlockReconciliationLinkedBlocks()
	{
		if (!$this->guard->tableExists('earnings')) {
			return $this->guard->missingTable('earnings');
		}
		if (!$this->guard->tableExists('blocks')) {
			return $this->guard->missingTable('blocks');
		}
		$schema = $this->earningsBlockReconciliationSchema();
		if (!$schema['can_join_earnings_blocks']) {
			return array('status' => 'indeterminate', 'error' => 'earnings/block join columns are unavailable', 'schema' => $schema);
		}
		if (!$schema['blocks_has_category']) {
			return array('status' => 'indeterminate', 'error' => 'blocks.category column is missing', 'schema' => $schema);
		}

		$where = $this->earningsCreditReadinessWhere('E');
		$parts = array(
			'B.'.$this->guard->qcol('category').' AS category',
			'COUNT(DISTINCT B.'.$this->guard->qcol('id').') AS block_count',
			'COUNT(*) AS linked_earnings_row_count',
		);
		if ($schema['earnings_has_amount']) {
			$parts[] = 'SUM(E.'.$this->guard->qcol('amount').') AS linked_earnings_amount';
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

		$sql = 'SELECT '.implode(', ', $parts).
			' FROM earnings E INNER JOIN blocks B ON B.'.$this->guard->qcol('id').'=E.'.$this->guard->qcol('blockid').
			' WHERE '.$where['sql'].' AND E.'.$this->guard->qcol('status').' IN (0, 1)'.
			' GROUP BY B.'.$this->guard->qcol('category').' ORDER BY B.'.$this->guard->qcol('category');

		return array(
			'filter' => $where['filter'],
			'rows' => $this->guard->selectAll($sql, $where['params']),
			'note' => 'Linked block rows are reported by category because category state gates later maturity/account-credit review.',
		);
	}

	private function earningsBlockReconciliationDifferenceExplanation($rowTotals, $blockLinkage, $rowsPerBlock)
	{
		$linkSummary = arraySafeVal($blockLinkage, 'summary', array());
		$histogram = arraySafeVal($rowsPerBlock, 'summary', array());
		$earningsRows = intval(arraySafeVal($linkSummary, 'inspected_earnings_rows', 0));
		$linkedBlocks = intval(arraySafeVal($linkSummary, 'distinct_linked_block_count', 0));
		$withoutBlockid = intval(arraySafeVal($linkSummary, 'earnings_rows_without_blockid', 0));
		$withBlockidWithoutMatch = intval(arraySafeVal($linkSummary, 'earnings_rows_with_blockid_without_matching_block', 0));
		$additionalRows = intval(arraySafeVal($histogram, 'additional_earnings_rows_beyond_one_per_block', 0));
		$multirowBlockGroups = intval(arraySafeVal($histogram, 'two_earning_rows_per_block_count', 0)) + intval(arraySafeVal($histogram, 'three_plus_earning_rows_per_block_count', 0));
		$schema = $this->earningsBlockReconciliationSchema();
		$limitations = array();
		if (!$schema['earnings_has_blockid']) {
			$limitations[] = 'earnings.blockid unavailable';
		}
		if (!$schema['can_join_earnings_blocks']) {
			$limitations[] = 'earnings/block join unavailable';
		}
		if (!$schema['blocks_has_category']) {
			$limitations[] = 'blocks.category unavailable';
		}

		return array(
			'earnings_rows_are_not_block_rows' => true,
			'earnings_row_count' => $earningsRows,
			'linked_block_count' => $linkedBlocks,
			'row_minus_linked_block_count' => max(0, $earningsRows - $linkedBlocks),
			'multiple_earnings_rows_per_block' => $additionalRows > 0,
			'earnings_rows_explained_by_multirow_blocks' => intval(arraySafeVal($histogram, 'earnings_rows_in_multirow_blocks', 0)),
			'additional_earnings_rows_beyond_one_per_block' => $additionalRows,
			'duplicate_blockid_groups' => $multirowBlockGroups,
			'missing_block_links' => ($withoutBlockid + $withBlockidWithoutMatch) > 0,
			'earnings_rows_without_blockid' => $withoutBlockid,
			'earnings_rows_with_blockid_without_matching_block' => $withBlockidWithoutMatch,
			'category_status_filters' => array(
				'earnings_status_filter' => 'status in 0,1',
				'coin_scope_filter' => arraySafeVal($blockLinkage, 'filter'),
				'linked_block_filter' => 'matching blocks only for category distribution',
			),
			'schema_limitations' => $limitations,
			'note' => 'A smaller linked block count can be expected when multiple earnings rows point at the same blockid.',
		);
	}

	private function earningsBlockReconciliationClassification($blockLinkage, $rowsPerBlock, $linkedBlocks)
	{
		$linkSummary = arraySafeVal($blockLinkage, 'summary', array());
		$histogram = arraySafeVal($rowsPerBlock, 'summary', array());
		$missingLinkage = intval(arraySafeVal($linkSummary, 'earnings_rows_without_blockid', 0)) +
			intval(arraySafeVal($linkSummary, 'earnings_rows_with_blockid_without_matching_block', 0));
		$linkedBlockCount = intval(arraySafeVal($linkSummary, 'distinct_linked_block_count', 0));
		$schema = $this->earningsBlockReconciliationSchema();
		$schemaLimited = !$schema['earnings_has_blockid'] || !$schema['can_join_earnings_blocks'] || !$schema['blocks_has_category'];

		return array(
			'earnings_rows_explained_by_multirow_blocks' => intval(arraySafeVal($histogram, 'earnings_rows_in_multirow_blocks', 0)),
			'additional_earnings_rows_beyond_one_per_block' => intval(arraySafeVal($histogram, 'additional_earnings_rows_beyond_one_per_block', 0)),
			'earnings_rows_missing_block_linkage' => $missingLinkage,
			'linked_blocks_count' => $linkedBlockCount,
			'linked_blocks_immature_count' => $this->earningsBlockReconciliationCategoryBlockCount($linkedBlocks, 'immature'),
			'linked_blocks_orphan_count' => $this->earningsBlockReconciliationCategoryBlockCount($linkedBlocks, 'orphan'),
			'indeterminate_count' => $schemaLimited ? intval(arraySafeVal($linkSummary, 'inspected_earnings_rows', 0)) : $missingLinkage,
			'classification_note' => 'Classification reconciles row units only. It does not approve category changes, account credit, or payout rows.',
		);
	}

	private function earningsBlockReconciliationBlockers($classification, $explanation)
	{
		return array(
			'payout_rows_remain_blocked' => array(
				'present' => true,
				'message' => 'Payout rows require credited account balances and remain blocked by this informational preview.',
			),
			'account_credit_remains_blocked' => array(
				'present' => true,
				'message' => 'Account credit requires separate maturity/category readiness review and approval.',
			),
			'maturity_category_transition_remains_blocked' => array(
				'present' => true,
				'message' => 'This preview reconciles earnings rows to blocks but does not validate or perform category/status transition logic.',
			),
			'multirow_blocks_require_review' => array(
				'present' => intval(arraySafeVal($classification, 'additional_earnings_rows_beyond_one_per_block', 0)) > 0,
				'message' => 'Multiple earnings rows per block explain row-count differences and must be reviewed before later stages.',
			),
			'missing_block_linkage_requires_review' => array(
				'present' => intval(arraySafeVal($classification, 'earnings_rows_missing_block_linkage', 0)) > 0,
				'message' => 'Rows without block linkage or matching blocks remain blockers.',
			),
			'schema_limitation_requires_review' => array(
				'present' => !empty(arraySafeVal($explanation, 'schema_limitations', array())),
				'message' => 'Missing schema fields make reconciliation incomplete.',
			),
			'informational_only' => array(
				'present' => true,
				'message' => 'Reconciliation is read-only evidence gathering only.',
			),
		);
	}

	private function earningsBlockReconciliationStages()
	{
		return array(
			array('stage' => 'inspect_per_block_earnings_grouping', 'status' => 'blocked_not_run', 'message' => 'Review row-per-block grouping before any later transition task.'),
			array('stage' => 'verify_maturity_threshold_current_height_source', 'status' => 'blocked', 'message' => 'Maturity threshold and current height source must be verified separately.'),
			array('stage' => 'prepare_category_transition_approval_package', 'status' => 'blocked_not_run', 'message' => 'Category transition approval remains future work.'),
			array('stage' => 'rerun_earnings_credit_readiness_preview', 'status' => 'blocked_not_run', 'message' => 'Rerun readiness after any separately approved category review or transition.'),
			array('stage' => 'prepare_account_credit_approval_package', 'status' => 'blocked_not_run', 'message' => 'Account-credit approval remains future work after readiness is resolved.'),
		);
	}

	private function earningsBlockReconciliationBlockedMetadata()
	{
		return array(
			'status' => 'blocked',
			'blocked_actions' => array(
				'backend_accounting_processing',
				'block_category_mutation',
				'earnings_status_mutation',
				'account_credit_mutation',
				'account_balance_mutation',
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
			'message' => 'Read-only earnings/block reconciliation only. It does not run backend accounting, change categories, change earnings, credit accounts, create payout rows, call wallets, delete shares, or change services.',
		);
	}

	private function earningsBlockReconciliationAuditSummary($report)
	{
		$scope = $this->guard->getScope();
		$coin = arraySafeVal($scope, 'coin', array());
		$summary = arraySafeVal($report, 'summary', array());
		$classification = arraySafeVal($summary, 'reconciliation_classification', array());
		$executionBlocked = arraySafeVal($summary, 'execution_blocked', array());
		return array(
			'command' => arraySafeVal($report, 'command'),
			'coin_id' => arraySafeVal($scope, 'coin_id'),
			'coin_symbol' => arraySafeVal($coin, 'symbol'),
			'coin_algo' => arraySafeVal($coin, 'algo'),
			'linked_blocks_count' => intval(arraySafeVal($classification, 'linked_blocks_count', 0)),
			'linked_blocks_immature_count' => intval(arraySafeVal($classification, 'linked_blocks_immature_count', 0)),
			'linked_blocks_orphan_count' => intval(arraySafeVal($classification, 'linked_blocks_orphan_count', 0)),
			'earnings_rows_missing_block_linkage' => intval(arraySafeVal($classification, 'earnings_rows_missing_block_linkage', 0)),
			'additional_earnings_rows_beyond_one_per_block' => intval(arraySafeVal($classification, 'additional_earnings_rows_beyond_one_per_block', 0)),
			'indeterminate_count' => intval(arraySafeVal($classification, 'indeterminate_count', 0)),
			'blocked_actions' => arraySafeVal($executionBlocked, 'blocked_actions', array()),
			'checksum_note' => 'See top-level report_checksum; generated_at is excluded from checksum input.',
			'checksum_purpose' => 'preview audit comparison only; not payout authorization',
		);
	}

	private function earningsBlockReconciliationSchema()
	{
		return array(
			'earnings_has_status' => $this->guard->columnExists('earnings', 'status'),
			'earnings_has_amount' => $this->guard->columnExists('earnings', 'amount'),
			'earnings_has_blockid' => $this->guard->columnExists('earnings', 'blockid'),
			'blocks_has_id' => $this->guard->columnExists('blocks', 'id'),
			'blocks_has_category' => $this->guard->columnExists('blocks', 'category'),
			'blocks_has_height' => $this->guard->columnExists('blocks', 'height'),
			'blocks_has_time' => $this->guard->columnExists('blocks', 'time'),
			'can_join_earnings_blocks' => $this->guard->tableExists('earnings') && $this->guard->tableExists('blocks') &&
				$this->guard->columnExists('earnings', 'blockid') && $this->guard->columnExists('blocks', 'id'),
		);
	}

	private function earningsBlockReconciliationCategoryBlockCount($linkedBlocks, $category)
	{
		$rows = arraySafeVal($linkedBlocks, 'rows', array());
		foreach ($rows as $row) {
			if (arraySafeVal($row, 'category') == $category) {
				return intval(arraySafeVal($row, 'block_count', 0));
			}
		}
		return 0;
	}

	private function maturitySourceCoinFields()
	{
		if (!$this->guard->tableExists('coins')) {
			return $this->guard->missingTable('coins');
		}

		$scope = $this->guard->getScope();
		$columns = $this->maturitySourceCoinFieldCandidates();
		$available = array();
		foreach ($columns as $column) {
			if ($this->guard->columnExists('coins', $column)) {
				$available[] = $column;
			}
		}

		$row = $this->guard->selectRow(
			'SELECT '.$this->guard->selectColumns('coins', $columns).' FROM coins WHERE '.$this->guard->qcol('id').'=:coin_id',
			array(':coin_id' => arraySafeVal($scope, 'coin_id'))
		);
		if (!$row) {
			return array('error' => 'selected coin is unavailable');
		}

		$fieldStatus = array();
		foreach ($columns as $column) {
			$present = in_array($column, $available, true);
			$value = $present && array_key_exists($column, $row) ? $row[$column] : null;
			$isNull = $present && $value === null;
			$isNumeric = $present && $value !== null && is_numeric($value);
			$fieldStatus[$column] = array(
				'present' => $present,
				'missing' => !$present,
				'null' => $isNull,
				'numeric' => $isNumeric,
				'non_numeric' => $present && $value !== null && !$isNumeric,
				'value' => $present ? $value : null,
				'usable_as_current_height' => $isNumeric && in_array($column, $this->maturitySourceCurrentHeightColumns(), true),
				'usable_as_maturity_threshold' => $isNumeric && in_array($column, $this->maturitySourceThresholdColumns(), true),
			);
		}

		$currentHeight = $this->maturitySourceFirstUsableField($fieldStatus, 'usable_as_current_height');
		$threshold = $this->maturitySourceFirstUsableField($fieldStatus, 'usable_as_maturity_threshold');
		return array(
			'fields' => $fieldStatus,
			'selected_current_height' => $currentHeight,
			'selected_maturity_threshold' => $threshold,
			'available_maturity_like_columns' => $available,
			'note' => 'Coin table fields are DB-only hints. They do not prove daemon chain height.',
		);
	}

	private function maturitySourceBlocksEvidence()
	{
		if (!$this->guard->tableExists('blocks')) {
			return $this->guard->missingTable('blocks');
		}
		$schema = $this->maturitySourceBlocksSchema();
		if (!$schema['has_category']) {
			return array('error' => 'blocks.category column is missing');
		}

		$where = $this->blockCategoryMaturityBlocksWhere('B');
		$parts = array(
			'B.'.$this->guard->qcol('category').' AS category',
			'COUNT(*) AS block_count',
		);
		if ($schema['has_height']) {
			$parts[] = 'MAX(B.'.$this->guard->qcol('height').') AS max_block_height';
		}
		if ($schema['has_time']) {
			$parts[] = 'MAX(B.'.$this->guard->qcol('time').') AS max_block_time';
		}
		$byCategorySql = 'SELECT '.implode(', ', $parts).' FROM blocks B WHERE '.$where['sql'].
			' GROUP BY B.'.$this->guard->qcol('category').' ORDER BY B.'.$this->guard->qcol('category');

		return array(
			'filter' => $where['filter'],
			'by_category' => $this->guard->selectAll($byCategorySql, $where['params']),
			'latest_block_row_by_time' => $this->maturitySourceLatestBlockRow('time', $where, $schema),
			'latest_block_row_by_height' => $this->maturitySourceLatestBlockRow('height', $where, $schema),
			'note' => 'Blocks table evidence is DB-only and may be stale while backend updaters remain frozen.',
		);
	}

	private function maturitySourceLinkedImmatureRange()
	{
		if (!$this->guard->tableExists('earnings')) {
			return $this->guard->missingTable('earnings');
		}
		if (!$this->guard->tableExists('blocks')) {
			return $this->guard->missingTable('blocks');
		}
		$schema = $this->maturitySourceEarningsBlocksSchema();
		if (!$schema['can_join'] || !$schema['blocks_has_category']) {
			return array('status' => 'indeterminate', 'error' => 'earnings/block category linkage is unavailable', 'schema' => $schema);
		}
		if (!$schema['earnings_has_status']) {
			return array('error' => 'earnings.status column is missing');
		}

		$where = $this->earningsCreditReadinessWhere('E');
		$params = $where['params'];
		$amount = $schema['earnings_has_amount'] ? 'SUM(E.'.$this->guard->qcol('amount').')' : 'NULL';
		$parts = array(
			'COUNT(DISTINCT B.'.$this->guard->qcol('id').') AS linked_block_count',
			'COUNT(*) AS linked_earnings_row_count',
			"$amount AS amount_sum",
		);
		if ($schema['blocks_has_height']) {
			$height = 'B.'.$this->guard->qcol('height');
			$parts[] = "MIN($height) AS min_linked_block_height";
			$parts[] = "MAX($height) AS max_linked_block_height";
		}
		if ($schema['blocks_has_time']) {
			$time = 'B.'.$this->guard->qcol('time');
			$parts[] = "MIN($time) AS min_linked_block_time";
			$parts[] = "MAX($time) AS max_linked_block_time";
		}

		$sql = 'SELECT '.implode(', ', $parts).
			' FROM earnings E INNER JOIN blocks B ON B.'.$this->guard->qcol('id').'=E.'.$this->guard->qcol('blockid').
			' WHERE '.$where['sql'].' AND E.'.$this->guard->qcol('status').' IN (0, 1)'.
			" AND B.".$this->guard->qcol('category')."='immature'";
		return array(
			'filter' => $where['filter'],
			'summary' => $this->guard->selectRow($sql, $params),
		);
	}

	private function maturitySourceDelta($coinFields)
	{
		$current = arraySafeVal($coinFields, 'selected_current_height', array());
		$threshold = arraySafeVal($coinFields, 'selected_maturity_threshold', array());
		if (!arraySafeVal($current, 'numeric', false) || !arraySafeVal($threshold, 'numeric', false)) {
			return array(
				'maturity_calculation_available' => false,
				'reason' => 'current height or maturity threshold is missing, null, or non-numeric in coin DB fields',
				'current_height' => arraySafeVal($current, 'value'),
				'maturity_threshold' => arraySafeVal($threshold, 'value'),
			);
		}
		if (!$this->guard->tableExists('earnings') || !$this->guard->tableExists('blocks')) {
			return array('maturity_calculation_available' => false, 'reason' => 'earnings or blocks table is unavailable');
		}
		$schema = $this->maturitySourceEarningsBlocksSchema();
		if (!$schema['can_join'] || !$schema['blocks_has_category'] || !$schema['blocks_has_height'] || !$schema['earnings_has_status']) {
			return array('maturity_calculation_available' => false, 'reason' => 'linked block height/category/status fields are unavailable', 'schema' => $schema);
		}

		$where = $this->earningsCreditReadinessWhere('E');
		$params = $where['params'];
		$params[':current_height'] = floatval(arraySafeVal($current, 'value'));
		$params[':mature_blocks'] = floatval(arraySafeVal($threshold, 'value'));
		$sql = 'SELECT '.
			'COUNT(*) AS linked_immature_block_count, '.
			'MIN(:current_height - D.block_height) AS min_height_delta, '.
			'MAX(:current_height - D.block_height) AS max_height_delta, '.
			'SUM(CASE WHEN (:current_height - D.block_height) >= :mature_blocks THEN 1 ELSE 0 END) AS mature_by_height_delta_count, '.
			'SUM(CASE WHEN (:current_height - D.block_height) < :mature_blocks THEN 1 ELSE 0 END) AS not_mature_by_height_delta_count '.
			'FROM ('.
				'SELECT DISTINCT B.'.$this->guard->qcol('id').' AS block_id, B.'.$this->guard->qcol('height').' AS block_height '.
				'FROM earnings E INNER JOIN blocks B ON B.'.$this->guard->qcol('id').'=E.'.$this->guard->qcol('blockid').' '.
				'WHERE '.$where['sql'].' AND E.'.$this->guard->qcol('status').' IN (0, 1) '.
				"AND B.".$this->guard->qcol('category')."='immature'".
			') D';
		$row = $this->guard->selectRow($sql, $params);

		return array(
			'maturity_calculation_available' => true,
			'current_height' => floatval(arraySafeVal($current, 'value')),
			'current_height_source' => arraySafeVal($current, 'column'),
			'maturity_threshold' => floatval(arraySafeVal($threshold, 'value')),
			'maturity_threshold_source' => arraySafeVal($threshold, 'column'),
			'summary' => $row,
			'calculation' => 'current_height_minus_block_height_compared_to_maturity_threshold',
			'note' => 'This is DB-only arithmetic over linked immature blocks; it is not daemon height verification.',
		);
	}

	private function maturitySourceConfidence($coinFields, $blockEvidence)
	{
		$current = arraySafeVal($coinFields, 'selected_current_height', array());
		$threshold = arraySafeVal($coinFields, 'selected_maturity_threshold', array());
		$maxBlocksHeight = $this->maturitySourceMaxBlocksHeight($blockEvidence);
		$currentNumeric = arraySafeVal($current, 'numeric', false);
		$thresholdNumeric = arraySafeVal($threshold, 'numeric', false);
		$currentValue = arraySafeVal($current, 'value');
		$heightStatus = 'missing_or_non_numeric';
		if ($currentNumeric) {
			$heightStatus = $maxBlocksHeight !== null && floatval($currentValue) < $maxBlocksHeight ? 'possibly_stale' : 'db_numeric';
		}

		return array(
			'db_coin_height_confidence' => array(
				'status' => $heightStatus,
				'source' => arraySafeVal($current, 'column'),
				'value' => $currentValue,
				'max_blocks_table_height' => $maxBlocksHeight,
				'message' => 'DB coin height is a preview input only and may be stale while backend updaters remain frozen.',
			),
			'db_mature_blocks_confidence' => array(
				'status' => $thresholdNumeric ? 'db_numeric' : 'missing_or_non_numeric',
				'source' => arraySafeVal($threshold, 'column'),
				'value' => arraySafeVal($threshold, 'value'),
			),
			'blocks_table_height_confidence' => array(
				'status' => $maxBlocksHeight === null ? 'missing_or_empty' : 'db_numeric',
				'max_height' => $maxBlocksHeight,
				'message' => 'Blocks table height is DB evidence only and is not chain-tip proof.',
			),
			'rpc_height_needed_for_final_decision' => true,
		);
	}

	private function maturitySourceDecision($coinFields, $delta, $confidence)
	{
		$calculationAvailable = arraySafeVal($delta, 'maturity_calculation_available', false);
		$heightStatus = arraySafeVal(arraySafeVal($confidence, 'db_coin_height_confidence', array()), 'status');
		$thresholdStatus = arraySafeVal(arraySafeVal($confidence, 'db_mature_blocks_confidence', array()), 'status');
		return array(
			'can_determine_maturity_from_db_only' => $calculationAvailable,
			'can_prepare_transition_package_from_db_only' => false,
			'needs_rpc_height_or_backend_logic_review' => true,
			'decision_basis' => array(
				'maturity_calculation_available' => $calculationAvailable,
				'db_coin_height_confidence' => $heightStatus,
				'db_mature_blocks_confidence' => $thresholdStatus,
				'daemon_rpc_used_by_preview' => false,
			),
			'message' => 'DB-only maturity math can inform review, but final transition work still needs separate chain-height and backend logic review.',
		);
	}

	private function maturitySourceBlockers($coinFields, $blockEvidence, $confidence)
	{
		$current = arraySafeVal($coinFields, 'selected_current_height', array());
		$threshold = arraySafeVal($coinFields, 'selected_maturity_threshold', array());
		$heightStatus = arraySafeVal(arraySafeVal($confidence, 'db_coin_height_confidence', array()), 'status');
		return array(
			'mature_blocks_null_or_non_numeric' => array(
				'present' => !arraySafeVal($threshold, 'numeric', false),
				'message' => 'A numeric maturity threshold is required before height-delta review can be trusted.',
			),
			'coin_current_height_null_or_non_numeric' => array(
				'present' => !arraySafeVal($current, 'numeric', false),
				'message' => 'A numeric DB current-height candidate is required for DB-only maturity math.',
			),
			'block_height_stale_or_missing' => array(
				'present' => $heightStatus !== 'db_numeric',
				'message' => 'DB coin height is missing, non-numeric, or below blocks table evidence.',
			),
			'daemon_rpc_not_used_by_preview' => array(
				'present' => true,
				'message' => 'This preview intentionally does not query daemon height.',
			),
			'backend_updater_frozen' => array(
				'present' => true,
				'message' => 'Backend updaters remain outside this preview and may leave DB height/category state stale.',
			),
			'transition_still_blocked' => array(
				'present' => true,
				'message' => 'No block category, earnings status, account-credit, or payout transition is authorized here.',
			),
		);
	}

	private function maturitySourceStages()
	{
		return array(
			array('stage' => 'verify_daemon_current_height_separately', 'status' => 'blocked_not_run', 'message' => 'Final height source review must happen outside this DB-only preview.'),
			array('stage' => 'inspect_backend_category_transition_logic', 'status' => 'blocked', 'message' => 'Backend category transition logic requires separate source review.'),
			array('stage' => 'compare_db_height_with_daemon_height', 'status' => 'blocked_not_run', 'message' => 'DB height must be compared with a separately approved live height source.'),
			array('stage' => 'prepare_block_category_transition_approval_package', 'status' => 'blocked_not_run', 'message' => 'Transition package preparation remains future work.'),
			array('stage' => 'rerun_earnings_credit_readiness_preview', 'status' => 'blocked_not_run', 'message' => 'Readiness must be rechecked after any separately approved transition.'),
			array('stage' => 'prepare_account_credit_approval_package', 'status' => 'blocked_not_run', 'message' => 'Account-credit approval remains future work after readiness is resolved.'),
		);
	}

	private function maturitySourceBlockedMetadata()
	{
		return array(
			'status' => 'blocked',
			'blocked_actions' => array(
				'daemon_rpc_read',
				'wallet_rpc_read',
				'backend_accounting_processing',
				'block_category_mutation',
				'earnings_status_mutation',
				'account_credit_mutation',
				'account_balance_mutation',
				'block_mutation',
				'earnings_mutation',
				'coin_mutation',
				'payout_row_creation',
				'account_debit',
				'wallet_send',
				'share_deletion',
				'payout_retry_delete',
				'service_or_cron_changes',
			),
			'daemon_rpc_used' => false,
			'wallet_rpc_used' => false,
			'message' => 'Read-only maturity source verification only. It does not query daemons or wallets, run backend accounting, change categories, change earnings, credit accounts, create payout rows, delete shares, or change services.',
		);
	}

	private function maturitySourceAuditSummary($report)
	{
		$scope = $this->guard->getScope();
		$coin = arraySafeVal($scope, 'coin', array());
		$summary = arraySafeVal($report, 'summary', array());
		$decision = arraySafeVal($summary, 'conservative_decision', array());
		$delta = arraySafeVal($summary, 'maturity_delta', array());
		$executionBlocked = arraySafeVal($summary, 'execution_blocked', array());
		return array(
			'command' => arraySafeVal($report, 'command'),
			'coin_id' => arraySafeVal($scope, 'coin_id'),
			'coin_symbol' => arraySafeVal($coin, 'symbol'),
			'coin_algo' => arraySafeVal($coin, 'algo'),
			'maturity_calculation_available' => arraySafeVal($delta, 'maturity_calculation_available', false),
			'can_determine_maturity_from_db_only' => arraySafeVal($decision, 'can_determine_maturity_from_db_only', false),
			'can_prepare_transition_package_from_db_only' => arraySafeVal($decision, 'can_prepare_transition_package_from_db_only', false),
			'needs_rpc_height_or_backend_logic_review' => arraySafeVal($decision, 'needs_rpc_height_or_backend_logic_review', true),
			'blocked_actions' => arraySafeVal($executionBlocked, 'blocked_actions', array()),
			'checksum_note' => 'See top-level report_checksum; generated_at is excluded from checksum input.',
			'checksum_purpose' => 'preview audit comparison only; not payout authorization',
		);
	}

	private function maturitySourceCoinFieldCandidates()
	{
		return array(
			'id', 'symbol', 'symbol2', 'name', 'algo', 'block_height', 'target_height',
			'mature_blocks', 'block_time', 'cleared', 'immature', 'available', 'balance',
			'minted', 'reward', 'lastblock', 'last_block', 'confirmations', 'height',
			'best_height', 'chain_height', 'current_height',
		);
	}

	private function maturitySourceCurrentHeightColumns()
	{
		return array('block_height', 'target_height', 'height', 'best_height', 'chain_height', 'current_height');
	}

	private function maturitySourceThresholdColumns()
	{
		return array('mature_blocks');
	}

	private function maturitySourceFirstUsableField($fields, $flag)
	{
		foreach ($fields as $column => $status) {
			if (arraySafeVal($status, $flag, false)) {
				return array(
					'column' => $column,
					'value' => arraySafeVal($status, 'value'),
					'numeric' => true,
				);
			}
		}
		return array('column' => null, 'value' => null, 'numeric' => false);
	}

	private function maturitySourceBlocksSchema()
	{
		return array(
			'has_id' => $this->guard->columnExists('blocks', 'id'),
			'has_category' => $this->guard->columnExists('blocks', 'category'),
			'has_height' => $this->guard->columnExists('blocks', 'height'),
			'has_time' => $this->guard->columnExists('blocks', 'time'),
			'has_amount' => $this->guard->columnExists('blocks', 'amount'),
		);
	}

	private function maturitySourceEarningsBlocksSchema()
	{
		return array(
			'earnings_has_status' => $this->guard->columnExists('earnings', 'status'),
			'earnings_has_amount' => $this->guard->columnExists('earnings', 'amount'),
			'earnings_has_blockid' => $this->guard->columnExists('earnings', 'blockid'),
			'blocks_has_id' => $this->guard->columnExists('blocks', 'id'),
			'blocks_has_category' => $this->guard->columnExists('blocks', 'category'),
			'blocks_has_height' => $this->guard->columnExists('blocks', 'height'),
			'blocks_has_time' => $this->guard->columnExists('blocks', 'time'),
			'can_join' => $this->guard->tableExists('earnings') && $this->guard->tableExists('blocks') &&
				$this->guard->columnExists('earnings', 'blockid') && $this->guard->columnExists('blocks', 'id'),
		);
	}

	private function maturitySourceLatestBlockRow($orderColumn, $where, $schema)
	{
		if (!$this->guard->columnExists('blocks', 'id')) {
			return array('status' => 'not_available', 'reason' => 'blocks.id column is missing');
		}
		if (!$this->guard->columnExists('blocks', $orderColumn)) {
			return array('status' => 'not_available', 'reason' => "blocks.$orderColumn column is missing");
		}
		$select = array('B.'.$this->guard->qcol('id').' AS id');
		foreach (array('category', 'height', 'time', 'amount') as $column) {
			if ($this->guard->columnExists('blocks', $column)) {
				$select[] = 'B.'.$this->guard->qcol($column).' AS '.$this->guard->qcol($column);
			}
		}
		$sql = 'SELECT '.implode(', ', $select).' FROM blocks B WHERE '.$where['sql'].
			' ORDER BY B.'.$this->guard->qcol($orderColumn).' DESC, B.'.$this->guard->qcol('id').' DESC LIMIT 1';
		$row = $this->guard->selectRow($sql, $where['params']);
		return $row ? $row : array('status' => 'empty');
	}

	private function maturitySourceMaxBlocksHeight($blockEvidence)
	{
		$rows = arraySafeVal($blockEvidence, 'by_category', array());
		$max = null;
		foreach ($rows as $row) {
			$value = arraySafeVal($row, 'max_block_height');
			if (is_numeric($value)) {
				$value = floatval($value);
				$max = $max === null ? $value : max($max, $value);
			}
		}
		return $max;
	}

	private function forwardCatchupModel()
	{
		$scope = $this->guard->getScope();
		$coin = arraySafeVal($scope, 'coin', array());
		$checkpoint = $this->forwardCatchupCheckpoint();
		$lastPayoutTime = arraySafeVal($checkpoint, 'last_payout_time');
		$blockWindow = $this->forwardCatchupBlockWindow($lastPayoutTime);
		$importCandidates = $this->forwardCatchupImportCandidates($lastPayoutTime);
		$daemonSample = $this->forwardCatchupDaemonSample(arraySafeVal($importCandidates, 'sample_candidates', array()));
		$safety = $this->forwardCatchupSafetyClassification($checkpoint, $blockWindow, $importCandidates, $daemonSample);

		return array(
			'coin' => array(
				'coin_id' => arraySafeVal($scope, 'coin_id'),
				'symbol' => arraySafeVal($coin, 'symbol'),
				'algo' => arraySafeVal($coin, 'algo'),
				'enable' => arraySafeVal($coin, 'enable'),
				'installed' => arraySafeVal($coin, 'installed'),
				'visible' => arraySafeVal($coin, 'visible'),
				'auto_ready' => arraySafeVal($coin, 'auto_ready'),
			),
			'checkpoint' => $checkpoint,
			'block_window' => $blockWindow,
			'import_candidates' => $importCandidates,
			'daemon_sample' => $daemonSample,
			'safety' => $safety,
		);
	}

	private function forwardCatchupPreviewEquivalentReport($model)
	{
		$report = $this->guard->baseReport();
		$report['command'] = 'forward-catchup-preview';
		$report['daemon_rpc_used'] = arraySafeVal(arraySafeVal($model, 'daemon_sample', array()), 'daemon_rpc_used', false);
		$report['backend_loops_run'] = false;
		$report['summary']['coin'] = arraySafeVal($model, 'coin', array());
		$report['summary']['forward_checkpoint'] = arraySafeVal($model, 'checkpoint', array());
		$report['summary']['forward_block_window'] = arraySafeVal($model, 'block_window', array());
		$report['summary']['import_candidates'] = arraySafeVal($model, 'import_candidates', array());
		$report['summary']['daemon_read_only_sample'] = arraySafeVal($model, 'daemon_sample', array());
		$report['summary']['projected_stages'] = $this->forwardCatchupProjectedStages();
		$report['summary']['safety_classification'] = arraySafeVal($model, 'safety', array());
		$report['summary']['execution_blocked'] = $this->forwardCatchupBlockedMetadata(arraySafeVal($model, 'daemon_sample', array()));
		$report['summary']['audit'] = $this->forwardCatchupAuditSummary($report);
		$report = BadpoolGuardReport::finalize($report);
		$report['report_checksum'] = BadpoolGuardReport::checksum($report);
		return $report;
	}

	private function forwardCatchupStage1DryrunLimit()
	{
		$limit = $this->guard->getOption('limit', self::FORWARD_CATCHUP_STAGE1_DRYRUN_DEFAULT_LIMIT);
		if (!preg_match('/^[0-9]+$/', (string)$limit) || intval($limit) <= 0) {
			$this->guard->addError('Invalid --limit. Expected a positive integer.');
			return false;
		}

		$limit = intval($limit);
		if ($limit > self::FORWARD_CATCHUP_STAGE1_DRYRUN_MAX_LIMIT) {
			$this->guard->addError('Invalid --limit. Maximum is '.self::FORWARD_CATCHUP_STAGE1_DRYRUN_MAX_LIMIT.'.');
			return false;
		}

		return $limit;
	}

	private function forwardCatchupStage1DryrunCandidates($lastPayoutTime, $limit)
	{
		if (!$this->guard->tableExists('blocks') || $lastPayoutTime === null || !is_numeric($lastPayoutTime)) {
			return array();
		}
		foreach (array('id', 'coin_id', 'category', 'time', 'blockhash') as $column) {
			if (!$this->guard->columnExists('blocks', $column)) {
				return array();
			}
		}

		$scope = $this->guard->getScope();
		$select = array(
			'B.'.$this->guard->qcol('id').' AS id',
			'B.'.$this->guard->qcol('time').' AS time',
			'B.'.$this->guard->qcol('blockhash').' AS blockhash',
		);
		foreach (array('height', 'userid', 'workerid') as $column) {
			if ($this->guard->columnExists('blocks', $column)) {
				$select[] = 'B.'.$this->guard->qcol($column).' AS '.$this->guard->qcol($column);
			}
		}
		$order = $this->guard->columnExists('blocks', 'height') ?
			'B.'.$this->guard->qcol('height').' ASC, B.'.$this->guard->qcol('time').' ASC, B.'.$this->guard->qcol('id').' ASC' :
			'B.'.$this->guard->qcol('time').' ASC, B.'.$this->guard->qcol('id').' ASC';

		return $this->guard->selectAll(
			'SELECT '.implode(', ', $select).' FROM blocks B '.
			'WHERE B.'.$this->guard->qcol('coin_id').'=:coin_id '.
			"AND B.".$this->guard->qcol('category')."='new' ".
			'AND B.'.$this->guard->qcol('time').'>:last_time '.
			'AND B.'.$this->guard->qcol('blockhash').' IS NOT NULL '.
			"AND B.".$this->guard->qcol('blockhash')."!='' ".
			'ORDER BY '.$order.' LIMIT '.intval($limit),
			array(':coin_id' => arraySafeVal($scope, 'coin_id'), ':last_time' => $lastPayoutTime)
		);
	}

	private function forwardCatchupStage1DryrunClassify($candidates)
	{
		$result = array();
		if (empty($candidates)) {
			return $result;
		}

		$coin = db_coins::model()->findByPk(arraySafeVal($this->guard->getScope(), 'coin_id'));
		$remote = $coin ? new WalletRPC($coin) : null;
		foreach ($candidates as $candidate) {
			$item = $candidate;
			$item['daemon'] = array(
				'txid' => null,
				'category' => null,
				'amount' => null,
				'confirmations' => null,
				'getblock_found' => false,
				'gettransaction_found' => false,
			);
			$item['classification'] = 'stage1_blocked_review';
			if (!$remote) {
				$item['daemon']['error'] = 'coin rpc record unavailable';
				$result[] = $item;
				continue;
			}

			try {
				$block = $this->forwardCatchupNormalizeRpcValue($remote->getblock(arraySafeVal($candidate, 'blockhash')));
				$txids = is_array(arraySafeVal($block, 'tx')) ? arraySafeVal($block, 'tx') : array();
				$txid = arraySafeVal($txids, 0);
				$tx = $txid ? $this->forwardCatchupNormalizeRpcValue($remote->gettransaction($txid)) : array();
			} catch (Exception $e) {
				$item['daemon']['error'] = $e->getMessage();
				$result[] = $item;
				continue;
			}

			$details = is_array(arraySafeVal($tx, 'details')) ? arraySafeVal($tx, 'details') : array();
			$detail = is_array(arraySafeVal($details, 0)) ? arraySafeVal($details, 0) : array();
			$category = arraySafeVal($detail, 'category', arraySafeVal($tx, 'category'));
			$amount = arraySafeVal($detail, 'amount', arraySafeVal($tx, 'amount'));
			$confirmations = arraySafeVal($tx, 'confirmations', arraySafeVal($block, 'confirmations'));
			$item['daemon'] = array(
				'txid' => $txid,
				'category' => $category,
				'amount' => $amount,
				'confirmations' => $confirmations,
				'getblock_found' => !empty($block),
				'gettransaction_found' => !empty($tx),
			);

			$positiveAmount = is_numeric($amount) && floatval($amount) > 0;
			$knownConfirmations = is_numeric($confirmations);
			if ($category === 'generate' && $positiveAmount && $knownConfirmations && intval($confirmations) > 0) {
				$item['classification'] = 'stage1_import_generate';
			} elseif ($category === 'immature' && $positiveAmount && $knownConfirmations && intval($confirmations) >= 0) {
				$item['classification'] = 'stage1_import_immature';
			} elseif ($category === 'orphan') {
				$item['classification'] = 'stage1_mark_orphan_no_earnings';
			}
			$result[] = $item;
		}

		return $result;
	}

	private function forwardCatchupStage1DryrunPlan($classified)
	{
		$scope = $this->guard->getScope();
		$mutations = array();
		$earnings = array();
		foreach ($classified as $item) {
			$daemon = arraySafeVal($item, 'daemon', array());
			$class = arraySafeVal($item, 'classification');
			$mutation = array(
				'blockid' => arraySafeVal($item, 'id'),
				'height' => arraySafeVal($item, 'height'),
				'classification' => $class,
				'would_set_txhash' => arraySafeVal($daemon, 'txid'),
			);
			if ($class === 'stage1_import_generate' || $class === 'stage1_import_immature') {
				$mutation['would_set_amount'] = arraySafeVal($daemon, 'amount');
				$mutation['would_set_confirmations'] = arraySafeVal($daemon, 'confirmations');
				$mutation['would_set_category'] = 'immature';
				$earnings[] = array(
					'userid' => arraySafeVal($item, 'userid'),
					'coinid' => arraySafeVal($scope, 'coin_id'),
					'blockid' => arraySafeVal($item, 'id'),
					'create_time' => arraySafeVal($item, 'time'),
					'amount' => arraySafeVal($daemon, 'amount'),
					'status' => 0,
					'mature_time' => null,
					'attribution_model' => 'block_userid_single_recipient',
					'attribution_model_requires_operator_confirmation' => true,
					'historical_evidence_mixed' => true,
					'backendblocknew_not_used' => true,
					'fee_policy' => 'not_applied_in_dryrun',
				);
			} elseif ($class === 'stage1_mark_orphan_no_earnings') {
				$mutation['would_set_category'] = 'orphan';
				$mutation['daemon_amount_excluded_from_earnings'] = arraySafeVal($daemon, 'amount');
			} else {
				$mutation['would_skip_or_block'] = true;
			}
			$mutations[] = $mutation;
		}

		return array(
			'projected_block_mutations' => $mutations,
			'projected_pending_earnings' => $earnings,
		);
	}

	private function forwardCatchupStage1DryrunTotals($classified, $plan)
	{
		$totals = array(
			'selected_count' => count($classified),
			'stage1_import_generate_count' => 0,
			'stage1_import_immature_count' => 0,
			'stage1_mark_orphan_no_earnings_count' => 0,
			'stage1_blocked_review_count' => 0,
			'projected_pending_earnings_rows' => count(arraySafeVal($plan, 'projected_pending_earnings', array())),
			'projected_pending_earnings_amount_gross' => 0.0,
			'projected_orphan_excluded_amount' => 0.0,
			'first_height' => null,
			'last_height' => null,
		);
		foreach (arraySafeVal($plan, 'projected_pending_earnings', array()) as $earning) {
			$totals['projected_pending_earnings_amount_gross'] += floatval(arraySafeVal($earning, 'amount', 0));
		}
		foreach ($classified as $item) {
			$height = arraySafeVal($item, 'height');
			if (is_numeric($height)) {
				$height = intval($height);
				$totals['first_height'] = $totals['first_height'] === null ? $height : min($totals['first_height'], $height);
				$totals['last_height'] = $totals['last_height'] === null ? $height : max($totals['last_height'], $height);
			}
			$class = arraySafeVal($item, 'classification');
			if ($class === 'stage1_import_generate') {
				$totals['stage1_import_generate_count']++;
			} elseif ($class === 'stage1_import_immature') {
				$totals['stage1_import_immature_count']++;
			} elseif ($class === 'stage1_mark_orphan_no_earnings') {
				$totals['stage1_mark_orphan_no_earnings_count']++;
				$totals['projected_orphan_excluded_amount'] += floatval(arraySafeVal(arraySafeVal($item, 'daemon', array()), 'amount', 0));
			} else {
				$totals['stage1_blocked_review_count']++;
			}
		}

		return $totals;
	}

	private function forwardCatchupStage1DryrunSafetyGates($checkpoint, $classified, $plan, $limit)
	{
		$blockedCount = 0;
		$orphanBlockids = array();
		foreach ($classified as $item) {
			$class = arraySafeVal($item, 'classification');
			if ($class === 'stage1_blocked_review') {
				$blockedCount++;
			} elseif ($class === 'stage1_mark_orphan_no_earnings') {
				$orphanBlockids[arraySafeVal($item, 'id')] = true;
			}
		}

		$orphanEarnings = false;
		foreach (arraySafeVal($plan, 'projected_pending_earnings', array()) as $earning) {
			if (isset($orphanBlockids[arraySafeVal($earning, 'blockid')])) {
				$orphanEarnings = true;
			}
		}

		$gates = array(
			'gate_coin_scope' => !$this->guard->isAllCoinsPreview(),
			'gate_checkpoint_present' => arraySafeVal($checkpoint, 'last_payout_time') !== null,
			'gate_batch_limited' => $limit <= self::FORWARD_CATCHUP_STAGE1_DRYRUN_MAX_LIMIT,
			'gate_daemon_classification_complete' => $blockedCount === 0,
			'gate_orphans_excluded_from_earnings' => !$orphanEarnings,
			'gate_no_account_credit' => true,
			'gate_no_payout_rows' => true,
			'gate_no_wallet_sends' => true,
			'gate_no_backend_loop_calls' => true,
			'gate_operator_attribution_confirmation_required' => true,
		);
		$gates['overall_dryrun_status'] = !in_array(false, $gates, true) ? 'pass' : 'blocked';
		return $gates;
	}

	private function forwardCatchupCheckpoint()
	{
		if (!$this->guard->tableExists('payouts')) {
			return $this->guard->missingTable('payouts');
		}
		if (!$this->guard->columnExists('payouts', 'idcoin')) {
			return array('error' => 'payouts.idcoin column is missing');
		}
		if (!$this->guard->columnExists('payouts', 'time')) {
			return array('error' => 'payouts.time column is missing');
		}

		$scope = $this->guard->getScope();
		$params = array(':coin_id' => arraySafeVal($scope, 'coin_id'));
		$completedFilter = $this->guard->columnExists('payouts', 'completed') ? ' AND P.'.$this->guard->qcol('completed').'=1' : '';
		$amount = $this->guard->columnExists('payouts', 'amount') ? 'SUM(P.'.$this->guard->qcol('amount').')' : 'NULL';
		$sql = 'SELECT MAX(P.'.$this->guard->qcol('time').') AS last_payout_time, '.
			'COUNT(*) AS payout_row_count, '.$amount.' AS payout_amount '.
			'FROM payouts P WHERE P.'.$this->guard->qcol('idcoin').'=:coin_id'.$completedFilter;
		$row = $this->guard->selectRow($sql, $params);

		$checkpointAmount = null;
		if (arraySafeVal($row, 'last_payout_time') !== null && $this->guard->columnExists('payouts', 'amount')) {
			$checkpointAmountRow = $this->guard->selectRow(
				'SELECT SUM(P.'.$this->guard->qcol('amount').') AS checkpoint_payout_amount '.
				'FROM payouts P WHERE P.'.$this->guard->qcol('idcoin').'=:coin_id AND P.'.$this->guard->qcol('time').'=:last_time'.$completedFilter,
				array(':coin_id' => arraySafeVal($scope, 'coin_id'), ':last_time' => arraySafeVal($row, 'last_payout_time'))
			);
			$checkpointAmount = arraySafeVal($checkpointAmountRow, 'checkpoint_payout_amount');
		}

		return array(
			'last_payout_time' => arraySafeVal($row, 'last_payout_time'),
			'payout_row_count' => intval(arraySafeVal($row, 'payout_row_count', 0)),
			'payout_amount' => arraySafeVal($row, 'payout_amount'),
			'checkpoint_payout_amount_at_last_time' => $checkpointAmount,
			'checkpoint_source' => 'payouts.MAX(time)',
			'completed_filter_applied' => $this->guard->columnExists('payouts', 'completed'),
		);
	}

	private function forwardCatchupBlockWindow($lastPayoutTime)
	{
		if (!$this->guard->tableExists('blocks')) {
			return $this->guard->missingTable('blocks');
		}
		$required = array('coin_id', 'time', 'category');
		foreach ($required as $column) {
			if (!$this->guard->columnExists('blocks', $column)) {
				return array('error' => "blocks.$column column is missing");
			}
		}
		if ($lastPayoutTime === null || !is_numeric($lastPayoutTime)) {
			return array(
				'status' => 'blocked_no_checkpoint',
				'message' => 'Forward window requires a numeric payout checkpoint.',
				'by_category' => array(),
			);
		}

		$scope = $this->guard->getScope();
		$params = array(':coin_id' => arraySafeVal($scope, 'coin_id'), ':last_time' => $lastPayoutTime);
		$where = 'B.'.$this->guard->qcol('coin_id').'=:coin_id AND B.'.$this->guard->qcol('time').'>:last_time';
		$select = array(
			'B.'.$this->guard->qcol('category').' AS category',
			'COUNT(*) AS block_count',
		);
		foreach (array('height', 'time') as $column) {
			if ($this->guard->columnExists('blocks', $column)) {
				$select[] = 'MIN(B.'.$this->guard->qcol($column).') AS min_'.$column;
				$select[] = 'MAX(B.'.$this->guard->qcol($column).') AS max_'.$column;
			}
		}
		if ($this->guard->columnExists('blocks', 'amount')) {
			$select[] = 'SUM(CASE WHEN B.'.$this->guard->qcol('amount').' IS NULL THEN 1 ELSE 0 END) AS null_amount_count';
			$select[] = 'SUM(B.'.$this->guard->qcol('amount').') AS amount_sum';
		} else {
			$select[] = 'NULL AS null_amount_count';
			$select[] = 'NULL AS amount_sum';
		}
		if ($this->guard->columnExists('blocks', 'txhash')) {
			$select[] = "SUM(CASE WHEN B.".$this->guard->qcol('txhash')." IS NULL OR B.".$this->guard->qcol('txhash')."='' THEN 1 ELSE 0 END) AS missing_txhash_count";
		} else {
			$select[] = 'NULL AS missing_txhash_count';
		}

		$join = '';
		if ($this->forwardCatchupCanJoinEarningsBlocks()) {
			$join = ' LEFT JOIN earnings E ON E.'.$this->guard->qcol('blockid').'=B.'.$this->guard->qcol('id');
			$select[] = 'COUNT(E.'.$this->guard->qcol('id').') AS linked_earnings_rows';
			$select[] = 'COUNT(DISTINCT E.'.$this->guard->qcol('blockid').') AS linked_earning_blocks';
		} else {
			$select[] = 'NULL AS linked_earnings_rows';
			$select[] = 'NULL AS linked_earning_blocks';
		}

		$sql = 'SELECT '.implode(', ', $select).' FROM blocks B'.$join.' WHERE '.$where.
			' GROUP BY B.'.$this->guard->qcol('category').' ORDER BY B.'.$this->guard->qcol('category');
		return array(
			'filter' => 'blocks.coin_id selected coin and blocks.time greater than checkpoint',
			'last_payout_time' => $lastPayoutTime,
			'by_category' => $this->guard->selectAll($sql, $params),
		);
	}

	private function forwardCatchupImportCandidates($lastPayoutTime)
	{
		if (!$this->guard->tableExists('blocks')) {
			return $this->guard->missingTable('blocks');
		}
		foreach (array('coin_id', 'time', 'category', 'blockhash', 'userid', 'workerid') as $column) {
			if (!$this->guard->columnExists('blocks', $column)) {
				return array('error' => "blocks.$column column is missing");
			}
		}
		if (!$this->guard->columnExists('blocks', 'amount') && !$this->guard->columnExists('blocks', 'txhash')) {
			return array('error' => 'blocks.amount or blocks.txhash column is required');
		}
		if ($lastPayoutTime === null || !is_numeric($lastPayoutTime)) {
			return array(
				'status' => 'blocked_no_checkpoint',
				'candidate_count' => 0,
				'sample_candidates' => array(),
			);
		}

		$scope = $this->guard->getScope();
		$params = array(':coin_id' => arraySafeVal($scope, 'coin_id'), ':last_time' => $lastPayoutTime);
		$where = $this->forwardCatchupImportCandidateWhere();
		$whereSql = 'B.'.$this->guard->qcol('coin_id').'=:coin_id AND B.'.$this->guard->qcol('time').'>:last_time AND '.$where;
		$heightParts = $this->guard->columnExists('blocks', 'height') ?
			'MIN(B.'.$this->guard->qcol('height').') AS min_height, MAX(B.'.$this->guard->qcol('height').') AS max_height, ' :
			'NULL AS min_height, NULL AS max_height, ';
		$timeParts = 'MIN(B.'.$this->guard->qcol('time').') AS min_time, MAX(B.'.$this->guard->qcol('time').') AS max_time';
		$summary = $this->guard->selectRow(
			'SELECT COUNT(*) AS candidate_count, '.$heightParts.$timeParts.' FROM blocks B WHERE '.$whereSql,
			$params
		);

		$select = array(
			'B.'.$this->guard->qcol('id').' AS id',
			'B.'.$this->guard->qcol('time').' AS time',
			'B.'.$this->guard->qcol('blockhash').' AS blockhash',
			'B.'.$this->guard->qcol('userid').' AS userid',
			'B.'.$this->guard->qcol('workerid').' AS workerid',
		);
		foreach (array('height', 'amount', 'txhash', 'confirmations') as $column) {
			if ($this->guard->columnExists('blocks', $column)) {
				$select[] = 'B.'.$this->guard->qcol($column).' AS '.$this->guard->qcol($column);
			}
		}
		$order = $this->guard->columnExists('blocks', 'height') ?
			'B.'.$this->guard->qcol('height').' ASC, B.'.$this->guard->qcol('time').' ASC' :
			'B.'.$this->guard->qcol('time').' ASC';
		$rows = $this->guard->selectAll(
			'SELECT '.implode(', ', $select).' FROM blocks B WHERE '.$whereSql.' ORDER BY '.$order.' LIMIT '.intval(self::FORWARD_CATCHUP_DAEMON_SAMPLE_LIMIT),
			$params
		);

		return array(
			'status' => 'present_if_candidate_count_positive',
			'criteria' => array(
				"category equals new",
				"amount is null or txhash is missing",
				"blockhash present",
				"userid present",
				"workerid present",
				"block time greater than last payout checkpoint",
			),
			'candidate_count' => intval(arraySafeVal($summary, 'candidate_count', 0)),
			'min_height' => arraySafeVal($summary, 'min_height'),
			'max_height' => arraySafeVal($summary, 'max_height'),
			'min_time' => arraySafeVal($summary, 'min_time'),
			'max_time' => arraySafeVal($summary, 'max_time'),
			'ordering' => $this->guard->columnExists('blocks', 'height') ? 'height asc, time asc' : 'time asc',
			'sample_limit' => self::FORWARD_CATCHUP_DAEMON_SAMPLE_LIMIT,
			'sample_candidates' => $rows,
		);
	}

	private function forwardCatchupDaemonSample($candidates)
	{
		$result = array(
			'daemon_rpc_used' => false,
			'wallet_sends' => false,
			'sample_limit' => self::FORWARD_CATCHUP_DAEMON_SAMPLE_LIMIT,
			'samples' => array(),
			'errors' => array(),
			'note' => 'Read-only daemon sample uses getblock and gettransaction only.',
		);
		if (empty($candidates)) {
			$result['status'] = 'not_run_no_import_candidates';
			return $result;
		}

		$scope = $this->guard->getScope();
		$coin = db_coins::model()->findByPk(arraySafeVal($scope, 'coin_id'));
		if (!$coin) {
			$result['status'] = 'not_run_coin_record_unavailable';
			return $result;
		}

		try {
			$remote = new WalletRPC($coin);
			foreach ($candidates as $candidate) {
				$blockhash = arraySafeVal($candidate, 'blockhash');
				$sample = array(
					'block_id' => arraySafeVal($candidate, 'id'),
					'height' => arraySafeVal($candidate, 'height'),
					'time' => arraySafeVal($candidate, 'time'),
					'blockhash' => $blockhash,
					'daemon_rpc_used' => true,
				);
				$result['daemon_rpc_used'] = true;
				if (!$blockhash) {
					$sample['status'] = 'skipped_missing_blockhash';
					$result['samples'][] = $sample;
					continue;
				}

				$block = $this->forwardCatchupNormalizeRpcValue($remote->getblock($blockhash));
				$sample['getblock_found'] = !empty($block);
				$sample['getblock_error'] = $remote->error;
				$txids = is_array(arraySafeVal($block, 'tx')) ? arraySafeVal($block, 'tx') : array();
				$coinbaseTxid = arraySafeVal($txids, 0);
				$sample['coinbase_txid'] = $coinbaseTxid;
				if (!$coinbaseTxid) {
					$sample['status'] = 'coinbase_txid_unavailable';
					$result['samples'][] = $sample;
					continue;
				}

				$tx = $this->forwardCatchupNormalizeRpcValue($remote->gettransaction($coinbaseTxid));
				$details = is_array(arraySafeVal($tx, 'details')) ? arraySafeVal($tx, 'details') : array();
				$detail = is_array(arraySafeVal($details, 0)) ? arraySafeVal($details, 0) : array();
				$sample['gettransaction_found'] = !empty($tx);
				$sample['gettransaction_error'] = $remote->error;
				$sample['confirmations'] = arraySafeVal($tx, 'confirmations', arraySafeVal($block, 'confirmations'));
				$sample['tx_category'] = arraySafeVal($detail, 'category', arraySafeVal($tx, 'category'));
				$sample['amount'] = arraySafeVal($detail, 'amount', arraySafeVal($tx, 'amount'));
				$sample['status'] = 'sampled_read_only';
				$result['samples'][] = $sample;
			}
		} catch (Exception $e) {
			$result['errors'][] = $e->getMessage();
		}

		$result['status'] = $result['daemon_rpc_used'] ? 'sampled_read_only' : 'not_run';
		return $result;
	}

	private function forwardCatchupProjectedStages()
	{
		return array(
			array(
				'stage' => 'stage_1_import_new_blocks',
				'name' => 'import-new-blocks',
				'future_transition' => 'new to immature',
				'future_fields' => array('txhash', 'amount', 'confirmations'),
				'future_earnings_effect' => 'call-equivalent would later create earnings rows',
				'status' => 'blocked_not_run',
			),
			array(
				'stage' => 'stage_2_update_maturity',
				'name' => 'update-maturity',
				'future_transition' => 'immature to generate or generated when daemon says generate',
				'future_earnings_effect' => 'earnings.status 0 to 1',
				'status' => 'blocked_not_run',
			),
			array(
				'stage' => 'stage_3_clear_forward_earnings',
				'name' => 'clear-forward-earnings',
				'future_transition' => 'earnings.status 1 to 2',
				'future_account_effect' => 'accounts.balance plus converted amount',
				'status' => 'blocked_not_run',
			),
			array(
				'stage' => 'stage_4_payout_row_create',
				'name' => 'payout-row-create',
				'future_transition' => 'positive account balances to payout rows',
				'status' => 'blocked_not_run',
			),
			array(
				'stage' => 'stage_5_wallet_send',
				'name' => 'wallet-send',
				'future_transition' => 'approved payout rows to wallet tx',
				'status' => 'blocked_not_run',
			),
		);
	}

	private function forwardCatchupSafetyClassification($checkpoint, $blockWindow, $importCandidates, $daemonSample)
	{
		$windowRows = arraySafeVal($blockWindow, 'by_category', array());
		$newCount = $this->sumRowsByValue($windowRows, 'category', 'new', 'block_count');
		$candidateCount = intval(arraySafeVal($importCandidates, 'candidate_count', 0));
		$sampleGenerateCount = 0;
		foreach (arraySafeVal($daemonSample, 'samples', array()) as $sample) {
			if (arraySafeVal($sample, 'tx_category') == 'generate') {
				$sampleGenerateCount++;
			}
		}

		return array(
			'forward_catchup_needed' => $newCount > 0 || $candidateCount > 0,
			'can_import_forward_new_blocks' => $candidateCount > 0 && $sampleGenerateCount > 0,
			'legacy_backlog_isolated' => true,
			'broad_backend_loop_required' => false,
			'recommended_next_stage' => 'forward-catchup-approval-package',
			'decision_basis' => array(
				'last_payout_time' => arraySafeVal($checkpoint, 'last_payout_time'),
				'forward_new_block_count' => $newCount,
				'import_candidate_count' => $candidateCount,
				'daemon_sample_generate_count' => $sampleGenerateCount,
			),
		);
	}

	private function forwardCatchupBlockedMetadata($daemonSample)
	{
		return array(
			'status' => 'blocked',
			'blocked_actions' => array(
				'apply_or_execute_mode',
				'database_writes',
				'backend_loop_execution',
				'payout_row_creation',
				'account_balance_change',
				'wallet_send',
				'payout_retry_delete',
				'share_deletion',
				'process_or_schedule_changes',
			),
			'read_only' => true,
			'db_mutations' => false,
			'wallet_sends' => false,
			'backend_loops_run' => false,
			'daemon_rpc_used' => arraySafeVal($daemonSample, 'daemon_rpc_used', false),
			'message' => 'Forward catch-up preview only. It maps a checkpoint and future stage candidates without writes or backend loop execution.',
		);
	}

	private function forwardCatchupAuditSummary($report)
	{
		$scope = $this->guard->getScope();
		$coin = arraySafeVal($scope, 'coin', array());
		$summary = arraySafeVal($report, 'summary', array());
		$safety = arraySafeVal($summary, 'safety_classification', array());
		$executionBlocked = arraySafeVal($summary, 'execution_blocked', array());
		return array(
			'command' => arraySafeVal($report, 'command'),
			'coin_id' => arraySafeVal($scope, 'coin_id'),
			'coin_symbol' => arraySafeVal($coin, 'symbol'),
			'coin_algo' => arraySafeVal($coin, 'algo'),
			'read_only' => true,
			'db_mutations' => false,
			'wallet_sends' => false,
			'backend_loops_run' => false,
			'daemon_rpc_used' => arraySafeVal($report, 'daemon_rpc_used', false),
			'forward_catchup_needed' => arraySafeVal($safety, 'forward_catchup_needed', false),
			'recommended_next_stage' => arraySafeVal($safety, 'recommended_next_stage'),
			'blocked_actions' => arraySafeVal($executionBlocked, 'blocked_actions', array()),
			'checksum_note' => 'See top-level report_checksum; generated_at is excluded from checksum input.',
			'checksum_purpose' => 'preview audit comparison only; not payout authorization',
		);
	}

	private function forwardCatchupCanJoinEarningsBlocks()
	{
		return $this->guard->tableExists('earnings') &&
			$this->guard->columnExists('earnings', 'id') &&
			$this->guard->columnExists('earnings', 'blockid') &&
			$this->guard->columnExists('blocks', 'id');
	}

	private function forwardCatchupImportCandidateWhere()
	{
		$missingAmount = $this->guard->columnExists('blocks', 'amount') ? 'B.'.$this->guard->qcol('amount').' IS NULL' : '0=1';
		$missingTxhash = $this->guard->columnExists('blocks', 'txhash') ? "(B.".$this->guard->qcol('txhash')." IS NULL OR B.".$this->guard->qcol('txhash')."='')" : '0=1';
		return 'B.'.$this->guard->qcol('category')."='new' ".
			"AND ($missingAmount OR $missingTxhash) ".
			"AND B.".$this->guard->qcol('blockhash')." IS NOT NULL AND B.".$this->guard->qcol('blockhash')."!='' ".
			'AND B.'.$this->guard->qcol('userid').' IS NOT NULL AND B.'.$this->guard->qcol('userid').'>0 '.
			'AND B.'.$this->guard->qcol('workerid').' IS NOT NULL AND B.'.$this->guard->qcol('workerid').'>0';
	}

	private function forwardCatchupNormalizeRpcValue($value)
	{
		if ($value === false || $value === null) {
			return array();
		}
		if (is_array($value)) {
			return $value;
		}
		$decoded = json_decode(json_encode($value), true);
		return is_array($decoded) ? $decoded : array();
	}

	private function forwardCatchupApprovalIdentity()
	{
		$scope = $this->guard->getScope();
		return array(
			'approval_package_type' => 'forward-catchup-stage-1-import',
			'approval_package_version' => self::FORWARD_CATCHUP_APPROVAL_VERSION,
			'coin_id' => arraySafeVal($scope, 'coin_id'),
			'generated_at' => gmdate('c'),
			'approval_required' => true,
			'execution_enabled' => false,
		);
	}

	private function forwardCatchupApprovalCandidateSummary($lastPayoutTime)
	{
		if (!$this->guard->tableExists('blocks')) {
			return $this->guard->missingTable('blocks');
		}
		foreach (array('coin_id', 'time', 'category', 'blockhash', 'userid', 'workerid') as $column) {
			if (!$this->guard->columnExists('blocks', $column)) {
				return array('error' => "blocks.$column column is missing");
			}
		}
		if ($lastPayoutTime === null || !is_numeric($lastPayoutTime)) {
			return array('status' => 'blocked_no_checkpoint', 'candidate_count' => 0);
		}

		$scope = $this->guard->getScope();
		$params = array(':coin_id' => arraySafeVal($scope, 'coin_id'), ':last_time' => $lastPayoutTime);
		$whereSql = 'B.'.$this->guard->qcol('coin_id').'=:coin_id AND B.'.$this->guard->qcol('time').'>:last_time AND '.$this->forwardCatchupImportCandidateWhere();
		$join = '';
		$linked = 'NULL AS linked_earnings_rows';
		if ($this->forwardCatchupCanJoinEarningsBlocks()) {
			$join = ' LEFT JOIN earnings E ON E.'.$this->guard->qcol('blockid').'=B.'.$this->guard->qcol('id');
			$linked = 'COUNT(E.'.$this->guard->qcol('id').') AS linked_earnings_rows';
		}

		$select = array(
			'COUNT(DISTINCT B.'.$this->guard->qcol('id').') AS candidate_count',
			$linked,
		);
		if ($this->guard->columnExists('blocks', 'height')) {
			$select[] = 'MIN(B.'.$this->guard->qcol('height').') AS min_height';
			$select[] = 'MAX(B.'.$this->guard->qcol('height').') AS max_height';
		} else {
			$select[] = 'NULL AS min_height';
			$select[] = 'NULL AS max_height';
		}
		$select[] = 'MIN(B.'.$this->guard->qcol('time').') AS min_time';
		$select[] = 'MAX(B.'.$this->guard->qcol('time').') AS max_time';
		if ($this->guard->columnExists('blocks', 'amount')) {
			$select[] = 'SUM(CASE WHEN B.'.$this->guard->qcol('amount').' IS NULL THEN 1 ELSE 0 END) AS null_amount_count';
		} else {
			$select[] = 'NULL AS null_amount_count';
		}
		if ($this->guard->columnExists('blocks', 'txhash')) {
			$select[] = "SUM(CASE WHEN B.".$this->guard->qcol('txhash')." IS NULL OR B.".$this->guard->qcol('txhash')."='' THEN 1 ELSE 0 END) AS missing_txhash_count";
		} else {
			$select[] = 'NULL AS missing_txhash_count';
		}

		$row = $this->guard->selectRow(
			'SELECT '.implode(', ', $select).' FROM blocks B'.$join.' WHERE '.$whereSql,
			$params
		);
		$row['category'] = 'new';
		$row['candidate_criteria'] = array(
			'coin_id selected coin only',
			'blocks.time greater than last payout time',
			'category equals new',
			'amount is null or txhash is missing',
			'blockhash present',
			'userid present',
			'workerid present',
		);
		return $row;
	}

	private function forwardCatchupApprovalBatchPlan($lastPayoutTime)
	{
		$rows = $this->forwardCatchupApprovalBatchRows($lastPayoutTime, self::FORWARD_CATCHUP_APPROVAL_BATCH_SIZE);
		$minHeight = null;
		$maxHeight = null;
		foreach ($rows as $row) {
			$height = arraySafeVal($row, 'height');
			if (is_numeric($height)) {
				$height = intval($height);
				$minHeight = $minHeight === null ? $height : min($minHeight, $height);
				$maxHeight = $maxHeight === null ? $height : max($maxHeight, $height);
			}
		}

		return array(
			'ordering' => $this->guard->columnExists('blocks', 'height') ? 'height asc, time asc' : 'time asc',
			'proposed_default_batch_size' => self::FORWARD_CATCHUP_APPROVAL_BATCH_SIZE,
			'first_batch_min_height' => $minHeight,
			'first_batch_max_height' => $maxHeight,
			'first_batch_count' => count($rows),
			'reason' => 'batch-limited to keep future Stage 1 review and rollback boundaries small',
			'first_batch_block_ids' => $this->forwardCatchupApprovalBatchIds($rows),
			'status' => 'blocked_not_run',
		);
	}

	private function forwardCatchupApprovalBatchRows($lastPayoutTime, $limit)
	{
		if (!$this->guard->tableExists('blocks') || $lastPayoutTime === null || !is_numeric($lastPayoutTime)) {
			return array();
		}
		foreach (array('coin_id', 'time', 'category', 'blockhash', 'userid', 'workerid') as $column) {
			if (!$this->guard->columnExists('blocks', $column)) {
				return array();
			}
		}

		$scope = $this->guard->getScope();
		$params = array(':coin_id' => arraySafeVal($scope, 'coin_id'), ':last_time' => $lastPayoutTime);
		$whereSql = 'B.'.$this->guard->qcol('coin_id').'=:coin_id AND B.'.$this->guard->qcol('time').'>:last_time AND '.$this->forwardCatchupImportCandidateWhere();
		$select = array(
			'B.'.$this->guard->qcol('id').' AS id',
			'B.'.$this->guard->qcol('time').' AS time',
		);
		if ($this->guard->columnExists('blocks', 'height')) {
			$select[] = 'B.'.$this->guard->qcol('height').' AS height';
		}
		$order = $this->guard->columnExists('blocks', 'height') ?
			'B.'.$this->guard->qcol('height').' ASC, B.'.$this->guard->qcol('time').' ASC' :
			'B.'.$this->guard->qcol('time').' ASC';
		return $this->guard->selectAll(
			'SELECT '.implode(', ', $select).' FROM blocks B WHERE '.$whereSql.' ORDER BY '.$order.' LIMIT '.intval($limit),
			$params
		);
	}

	private function forwardCatchupApprovalBatchIds($rows)
	{
		$ids = array();
		foreach ($rows as $row) {
			$ids[] = arraySafeVal($row, 'id');
		}
		return $ids;
	}

	private function forwardCatchupApprovalMutationScope($checkpoint, $candidateSummary)
	{
		$scope = $this->guard->getScope();
		return array(
			'scope_type' => 'single_coin_forward_stage_1_import',
			'coin_id' => arraySafeVal($scope, 'coin_id'),
			'blocks_time_gt_last_payout_time' => arraySafeVal($checkpoint, 'last_payout_time'),
			'category' => 'new',
			'blockhash_present' => true,
			'userid_present' => true,
			'workerid_present' => true,
			'linked_earnings_rows' => intval(arraySafeVal($candidateSummary, 'linked_earnings_rows', 0)),
			'linked_earnings_rows_required' => 0,
			'linked_earnings_rows_requirement_met' => intval(arraySafeVal($candidateSummary, 'linked_earnings_rows', 0)) === 0,
			'candidate_count' => intval(arraySafeVal($candidateSummary, 'candidate_count', 0)),
			'stage' => 'stage_1_import_new_blocks',
			'status' => 'blocked_not_run',
		);
	}

	private function forwardCatchupApprovalDaemonSampleSummary($daemonSample)
	{
		$samples = arraySafeVal($daemonSample, 'samples', array());
		$positiveAmountCount = 0;
		$categoryCounts = array();
		$confirmationsKnown = 0;
		foreach ($samples as $sample) {
			$category = arraySafeVal($sample, 'tx_category', 'unknown');
			if (!isset($categoryCounts[$category])) {
				$categoryCounts[$category] = 0;
			}
			$categoryCounts[$category]++;
			if (is_numeric(arraySafeVal($sample, 'amount')) && floatval(arraySafeVal($sample, 'amount')) > 0) {
				$positiveAmountCount++;
			}
			if (arraySafeVal($sample, 'confirmations') !== null) {
				$confirmationsKnown++;
			}
		}

		return array(
			'daemon_rpc_used' => arraySafeVal($daemonSample, 'daemon_rpc_used', false),
			'wallet_rpc_wrapper_used_for_read_only_daemon_calls' => arraySafeVal($daemonSample, 'daemon_rpc_used', false),
			'wallet_sends' => false,
			'allowed_methods' => array('getblock', 'gettransaction'),
			'forbidden_method_classes' => array('wallet_send_methods', 'wallet_unlock_methods'),
			'sample_count' => count($samples),
			'oldest_sample_confirms_category_amount_confirmations_where_available' => count($samples) > 0,
			'tx_category_counts' => $categoryCounts,
			'positive_amount_count' => $positiveAmountCount,
			'confirmations_known_count' => $confirmationsKnown,
			'samples' => $samples,
			'status' => arraySafeVal($daemonSample, 'status'),
		);
	}

	private function forwardCatchupApprovalApplyIntent()
	{
		return array(
			'status' => 'blocked_not_run',
			'intent' => array(
				'populate_txhash',
				'populate_amount',
				'populate_confirmations',
				'set_category_to_daemon_derived_category_or_safe_intermediate_category_as_designed',
				'create_projected_earnings_rows_according_to_existing_backend_block_new_equivalent_logic',
			),
			'not_executed' => true,
			'notes' => array(
				'Projected earnings rows are approval-plan data only and are not created by this command.',
				'Existing backend mutation functions are not called by this command.',
			),
		);
	}

	private function forwardCatchupApprovalBlockedStages()
	{
		return array(
			array('stage' => 'stage_1_apply', 'status' => 'blocked'),
			array('stage' => 'stage_2_maturity_update', 'status' => 'blocked'),
			array('stage' => 'stage_3_account_credit', 'status' => 'blocked'),
			array('stage' => 'stage_4_payout_row_creation', 'status' => 'blocked'),
			array('stage' => 'stage_5_wallet_send', 'status' => 'blocked'),
		);
	}

	private function forwardCatchupApprovalSafetyMetadata($model)
	{
		return array(
			'read_only' => true,
			'db_mutations' => false,
			'wallet_sends' => false,
			'service_actions' => false,
			'backend_loops_run' => false,
			'broad_backend_loop_required' => false,
			'daemon_rpc_used' => arraySafeVal(arraySafeVal($model, 'daemon_sample', array()), 'daemon_rpc_used', false),
			'approval_required' => true,
		);
	}

	private function forwardCatchupApprovalBlockedMetadata($daemonSample)
	{
		$blocked = $this->forwardCatchupBlockedMetadata($daemonSample);
		$blocked['approval_package_only'] = true;
		$blocked['message'] = 'Forward catch-up approval package only. Stage 1 apply remains blocked and requires a separate approved task.';
		return $blocked;
	}

	private function forwardCatchupApprovalAuditSummary($report)
	{
		$scope = $this->guard->getScope();
		$coin = arraySafeVal($scope, 'coin', array());
		$summary = arraySafeVal($report, 'summary', array());
		$identity = arraySafeVal($summary, 'approval_package_identity', array());
		$candidateSummary = arraySafeVal($summary, 'candidate_summary', array());
		$executionBlocked = arraySafeVal($summary, 'execution_blocked', array());
		return array(
			'command' => arraySafeVal($report, 'command'),
			'approval_package_type' => arraySafeVal($identity, 'approval_package_type'),
			'approval_package_version' => arraySafeVal($identity, 'approval_package_version'),
			'coin_id' => arraySafeVal($scope, 'coin_id'),
			'coin_symbol' => arraySafeVal($coin, 'symbol'),
			'coin_algo' => arraySafeVal($coin, 'algo'),
			'candidate_count' => intval(arraySafeVal($candidateSummary, 'candidate_count', 0)),
			'linked_earnings_rows' => intval(arraySafeVal($candidateSummary, 'linked_earnings_rows', 0)),
			'approval_required' => true,
			'read_only' => true,
			'db_mutations' => false,
			'wallet_sends' => false,
			'backend_loops_run' => false,
			'blocked_actions' => arraySafeVal($executionBlocked, 'blocked_actions', array()),
			'checksum_note' => 'See top-level report_checksum and approval checksum fields; generated_at is excluded from checksum input.',
			'checksum_purpose' => 'approval comparison only; not payout authorization and not execution authorization',
		);
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
		return in_array($command, array('payout-candidates-preview', 'payout-row-preflight-preview', 'payout-row-dryrun-plan', 'payable-source-reconciliation-preview', 'account-credit-transition-preview', 'earnings-credit-readiness-preview', 'block-category-maturity-preview', 'earnings-block-reconciliation-preview', 'maturity-source-verification-preview', 'forward-catchup-preview', 'forward-catchup-approval-package'), true);
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
