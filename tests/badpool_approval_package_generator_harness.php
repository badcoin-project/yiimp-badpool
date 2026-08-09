<?php

class CConsoleCommand {}

function arraySafeVal($array, $key, $default=null)
{
	return is_array($array) && array_key_exists($key, $array) ? $array[$key] : $default;
}

function getdbo($class, $id)
{
	return (object)array('id'=>intval($id), 'coinid'=>1267, 'price'=>1.0);
}

function yaamp_convert_amount_user($coin, $amount, $user)
{
	return floatval($amount);
}

if (!defined('YAAMP_ALLOW_EXCHANGE')) define('YAAMP_ALLOW_EXCHANGE', false);
if (!defined('YAAMP_PAYMENTS_FREQ')) define('YAAMP_PAYMENTS_FREQ', 3600);

$root = dirname(__DIR__);
require_once($root.'/web/yaamp/commands/BadpoolGuardCommand.php');

class ApprovalPackageFixtureGuard
{
	public $command;
	public $errors = array();
	public $warnings = array();
	public $readQueries = array();
	public $mutationCalls = 0;

	public function __construct($command) { $this->command = $command; }
	public function isAllCoinsPreview() { return false; }
	public function isValid() { return empty($this->errors); }
	public function getFormat() { return 'json'; }
	public function getScope() { return array('all_coins_preview'=>false, 'coin_id'=>1267, 'coin'=>array('id'=>1267, 'symbol'=>'BAD', 'algo'=>'scrypt')); }
	public function getCoin() { return array('id'=>1267, 'account'=>'', 'rpcencoding'=>'POW'); }
	public function getOption($name, $default=null) { return $name === 'selected-payout-ids' ? '517' : $default; }
	public function addError($message) { $this->errors[] = $message; }
	public function addWarning($message) { $this->warnings[] = $message; }
	public function tableExists($table) { return true; }
	public function columnExists($table, $column) { return true; }
	public function coinWhere($alias, $column) { return array('sql'=>$alias.'.'.$column.'=:coin_id', 'params'=>array(':coin_id'=>1267)); }

	public function baseReport($status='ok')
	{
		return array(
			'schema'=>BadpoolGuardReport::PREVIEW_SCHEMA,
			'generated_at'=>'2026-08-05T00:00:00Z',
			'command'=>$this->command,
			'mode'=>BadpoolGuardReport::PREVIEW_MODE,
			'status'=>$status,
			'read_only'=>true,
			'wallet_reads'=>false,
			'wallet_sends'=>false,
			'wallet_rpc_send_performed'=>false,
			'db_mutations'=>false,
			'scope'=>$this->getScope(),
			'summary'=>array(),
			'items'=>array(),
			'warnings'=>$this->warnings,
			'errors'=>$this->errors,
			'blocked_actions'=>array('database_mutations','wallet_sends','payout_row_creation','account_balance_mutation'),
		);
	}

	public function refusalReport() { return $this->baseReport('refused'); }
	public function finalizeReport($report)
	{
		$report['warnings'] = $this->warnings;
		$report['errors'] = $this->errors;
		return BadpoolGuardReport::finalize($report);
	}

	public function selectAll($sql, $params=array())
	{
		$this->readQueries[] = array('sql'=>$sql, 'params'=>$params);
		if (strpos($sql, 'FROM earnings E INNER JOIN blocks B') !== false) {
			return array(array(
				'earning_id'=>20, 'userid'=>7, 'coinid'=>1267, 'blockid'=>10, 'amount'=>'2.500000000000',
				'status'=>0, 'mature_time'=>0, 'block_id'=>10, 'block_height'=>100, 'block_coin_id'=>1267,
				'block_category'=>'immature', 'confirmations'=>120, 'mature_blocks'=>100,
			));
		}
		if (strpos($sql, 'FROM earnings E INNER JOIN accounts A') !== false) {
			return array(array(
				'earning_id'=>30, 'userid'=>7, 'coinid'=>1267, 'blockid'=>10, 'amount'=>'3.000000000000',
				'status'=>1, 'mature_time'=>1700000000, 'coin_price'=>'1.000000000000',
				'account_id'=>7, 'account_coinid'=>1267, 'account_balance'=>'10.000000000000',
			));
		}
		if (strpos($sql, 'FROM accounts A INNER JOIN coins C') !== false) {
			return array(array(
				'account_id'=>79, 'username'=>'Bfixture', 'coin_id'=>1267, 'coin_symbol'=>'BAD', 'coin_algo'=>'scrypt',
				'current_balance'=>'12.500000000000', 'payout_min'=>'1.000000000000', 'txfee'=>'0.010000000000',
				'threshold'=>'1.000000000000', 'projected_payout_amount'=>'12.500000000000',
				'projected_remaining_balance'=>'0.000000000000', 'above_threshold'=>1,
			));
		}
		if (strpos($sql, 'FROM payouts P INNER JOIN accounts A') !== false) {
			return array(array(
				'payout_id'=>517, 'account_id'=>79, 'payout_idcoin'=>1267, 'amount'=>'12.500000000000',
				'completed'=>0, 'tx'=>null, 'username'=>'Bfixture', 'account_coinid'=>1267,
				'coin_id'=>1267, 'symbol'=>'BAD', 'rpcencoding'=>'POW',
			));
		}
		return array();
	}
}

function approval_expect($condition, $message, &$failures)
{
	if (!$condition) $failures[] = $message;
}

function approval_private_method($name)
{
	$method = new ReflectionMethod('BadpoolGuardCommand', $name);
	$method->setAccessible(true);
	return $method;
}

function approval_generate($methodName, $commandName, &$guards)
{
	$guard = new ApprovalPackageFixtureGuard($commandName);
	$command = new BadpoolGuardCommand();
	$property = new ReflectionProperty('BadpoolGuardCommand', 'guard');
	$property->setAccessible(true);
	$property->setValue($command, $guard);
	$report = approval_private_method($methodName)->invoke($command);
	$guards[] = $guard;
	return BadpoolGuardReport::finalize($report);
}

function approval_recomputed_checksum($report, $keys)
{
	$command = new BadpoolGuardCommand();
	return approval_private_method('stableApprovalChecksum')->invoke($command, $report, $keys);
}

$failures = array();
$guards = array();
$cases = array(
	'maturity'=>array(
		'method'=>'earningsMaturityTransitionApprovalPackageReport',
		'command'=>'earnings-maturity-transition-approval-package',
		'type'=>'earnings-maturity-transition',
		'apply'=>'earnings-maturity-transition-apply',
		'id_field'=>'earning_id', 'id'=>20,
		'checksum_fields'=>array('approval_package_checksum','selected_scope_checksum','projected_block_mutation_checksum','projected_earnings_mutation_checksum'),
		'stable_keys'=>array('schema','approval_package_type','approval_package_version','scope','selection_mode','requested_block_ids','requested_block_count','selected_earning_ids','selected_earning_count','selected_linked_block_ids','selected_linked_block_count','requested_blocks_without_selected_earnings','scope_checksum','selected_scope_checksum','projected_block_mutation_checksum','projected_earnings_mutation_checksum','items','summary','apply_command_shape','apply_scope_binding','selected_records','checksums','apply_command_args'),
	),
	'account'=>array(
		'method'=>'accountCreditClearApprovalPackageReport',
		'command'=>'account-credit-clear-approval-package',
		'type'=>'account-credit-clear',
		'apply'=>'account-credit-clear-apply',
		'id_field'=>'earning_id', 'id'=>30,
		'checksum_fields'=>array('approval_package_checksum','selected_earnings_scope_checksum','projected_earnings_mutation_checksum','projected_account_credit_checksum'),
		'stable_keys'=>array('approval_package_type','scope','selected_earnings_scope_checksum','projected_earnings_mutation_checksum','projected_account_credit_checksum','items','apply_command_shape','apply_scope_binding','selected_records','checksums','apply_command_args'),
	),
	'payout'=>array(
		'method'=>'payoutRowApprovalPackageReport',
		'command'=>'payout-row-approval-package',
		'type'=>'payout-row-creation',
		'apply'=>'payout-row-apply',
		'id_field'=>'account_id', 'id'=>79,
		'checksum_fields'=>array('approval_package_checksum','selected_scope_checksum','projected_payout_row_checksum','projected_account_debit_checksum'),
		'stable_keys'=>array('approval_package_type','scope_binding','safety_binding','selected_scope_checksum','projected_payout_row_checksum','projected_account_debit_checksum','items','apply_command_shape','selected_records','checksums','apply_command_args'),
	),
	'wallet'=>array(
		'method'=>'walletSendApprovalPackageReport',
		'command'=>'wallet-send-approval-package',
		'type'=>'wallet-send',
		'apply'=>'wallet-send-apply',
		'id_field'=>'payout_id', 'id'=>517,
		'checksum_fields'=>array('approval_package_checksum','row_inventory_checksum','destination_plan_checksum','projected_total_checksum','wallet_send_total_checksum','wallet_send_destination_plan_checksum'),
		'stable_keys'=>array('approval_package_type','scope_binding','selected_payout_ids','row_inventory_checksum','destination_plan_checksum','projected_total','projected_total_checksum','wallet_send_destination_plan_checksum','wallet_send_total','wallet_send_total_checksum','dry_run_safety_flags','apply_command_shape','operator_confirmation','selected_records','checksums','apply_command_args'),
	),
);

foreach ($cases as $label => $case) {
	$first = approval_generate($case['method'], $case['command'], $guards);
	$second = approval_generate($case['method'], $case['command'], $guards);
	ob_start();
	BadpoolGuardReport::render($first, 'json');
	$rendered = json_decode(ob_get_clean(), true);
	approval_expect(is_array($rendered), $label.' generator did not render valid JSON', $failures);
	approval_expect(arraySafeVal($rendered, 'schema') === BadpoolGuardReport::APPROVAL_PACKAGE_SCHEMA, $label.' rendered JSON lost the canonical schema', $failures);
	approval_expect($first['schema'] === BadpoolGuardReport::APPROVAL_PACKAGE_SCHEMA, $label.' generator schema was not canonical after finalization', $failures);
	approval_expect($first['mode'] === BadpoolGuardReport::APPROVAL_PACKAGE_MODE, $label.' generator mode was not approval-package mode', $failures);
	approval_expect($first['package_type'] === $case['type'] && $first['approval_package_type'] === $case['type'], $label.' package types do not match', $failures);
	approval_expect($first['selected_count'] === 1 && count($first['selected_records']) === 1, $label.' selected scope count changed', $failures);
	approval_expect(intval($first['selected_records'][0][$case['id_field']]) === $case['id'], $label.' selected scope identity changed', $failures);
	approval_expect(in_array($case['apply'], $first['apply_command_args'], true), $label.' apply command shape is missing the expected action', $failures);
	approval_expect($first['approval_package_checksum']['value'] === $second['approval_package_checksum']['value'], $label.' approval checksum is not stable across identical generation', $failures);
	approval_expect($first['selected_records'] === $second['selected_records'], $label.' immutable selected scope changed across identical generation', $failures);
	approval_expect($first['read_only'] === true && $first['db_mutations'] === false && $first['wallet_rpc_send_performed'] === false, $label.' generation crossed a mutation boundary', $failures);
	foreach ($case['checksum_fields'] as $field) {
		approval_expect(isset($first[$field]['value']), $label.' missing checksum binding '.$field, $failures);
		approval_expect(arraySafeVal($first[$field], 'purpose') === BadpoolGuardReport::APPROVAL_CHECKSUM_PURPOSE, $label.' checksum metadata is not an approval binding for '.$field, $failures);
	}
	$tampered = $first;
	$tampered['selected_records'][0][$case['id_field']] = $case['id'] + 1;
	$recomputed = approval_recomputed_checksum($tampered, $case['stable_keys']);
	approval_expect($recomputed['value'] !== $first['approval_package_checksum']['value'], $label.' selected-scope tampering did not invalidate the approval checksum', $failures);
}

$maturityPreview = approval_generate('earningsMaturityTransitionDryrunReport', 'earnings-maturity-transition-dryrun', $guards);
$walletPreview = approval_generate('walletSendDryrunReport', 'wallet-send-dryrun', $guards);
foreach (array('maturity'=>$maturityPreview, 'wallet'=>$walletPreview) as $label => $preview) {
	approval_expect($preview['schema'] === BadpoolGuardReport::PREVIEW_SCHEMA, $label.' real dryrun did not retain the generic preview schema', $failures);
	approval_expect($preview['mode'] === BadpoolGuardReport::PREVIEW_MODE, $label.' real dryrun did not retain preview mode', $failures);
	approval_expect(!isset($preview['approval_package_checksum']), $label.' generic preview exposed an apply authorization checksum', $failures);
}

$canonicalStage1 = array(
	'schema'=>BadpoolGuardReport::APPROVAL_PACKAGE_SCHEMA,
	'command'=>'forward-catchup-stage1-apply-approval-package',
	'approval_package_type'=>'forward-catchup-stage1-apply',
	'package_type'=>'forward-catchup-stage1-apply',
	'approval_required'=>true,
	'read_only'=>true,
	'db_mutations'=>false,
);
$canonicalStage1 = BadpoolGuardReport::finalize($canonicalStage1);
approval_expect($canonicalStage1['schema'] === BadpoolGuardReport::APPROVAL_PACKAGE_SCHEMA, 'Stage1 apply approval package caller was not preserved', $failures);

$genericPreview = array(
	'schema'=>BadpoolGuardReport::APPROVAL_PACKAGE_SCHEMA,
	'command'=>'earnings-maturity-transition-dryrun',
	'approval_package_type'=>'earnings-maturity-transition',
	'package_type'=>'earnings-maturity-transition',
	'approval_required'=>true,
);
$genericPreview = BadpoolGuardReport::finalize($genericPreview);
approval_expect($genericPreview['schema'] === BadpoolGuardReport::PREVIEW_SCHEMA, 'generic dryrun retained an approval-package schema', $failures);
approval_expect($genericPreview['mode'] === BadpoolGuardReport::PREVIEW_MODE, 'generic dryrun retained an approval-package mode', $failures);

$tamperedIdentity = array(
	'schema'=>BadpoolGuardReport::APPROVAL_PACKAGE_SCHEMA,
	'command'=>'wallet-send-approval-package',
	'approval_package_type'=>'payout-row-creation',
	'package_type'=>'wallet-send',
	'approval_required'=>true,
);
$tamperedIdentity = BadpoolGuardReport::finalize($tamperedIdentity);
approval_expect($tamperedIdentity['schema'] === BadpoolGuardReport::PREVIEW_SCHEMA, 'tampered package identity retained approval-package authorization', $failures);

foreach ($guards as $guard) {
	approval_expect($guard->mutationCalls === 0, $guard->command.' fixture recorded a mutation call', $failures);
	approval_expect(!empty($guard->readQueries), $guard->command.' did not execute its real read-only selection path', $failures);
}

if ($failures) {
	echo "Badpool approval package generator harness FAILED\n";
	foreach ($failures as $failure) echo " - $failure\n";
	exit(1);
}

echo "Badpool approval package generator harness passed\n";
