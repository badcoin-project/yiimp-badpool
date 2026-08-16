<?php
class CConsoleCommand {}
function arraySafeVal($array, $key, $default=null) { return is_array($array) && array_key_exists($key, $array) ? $array[$key] : $default; }

require_once(dirname(__DIR__).'/web/yaamp/commands/BadpoolGuardCommand.php');

function batch_run($args, &$rc)
{
	$command = new BadpoolGuardCommand();
	ob_start();
	$rc = $command->run(array_merge(array('batch-run-preview'), $args));
	return ob_get_clean();
}
function batch_expect($condition, $message, &$failures) { if (!$condition) $failures[] = $message; }

$failures = array();
$json = json_decode(batch_run(array('--format=json'), $rc), true);
batch_expect($rc === 0 && is_array($json), 'default JSON preview failed', $failures);
batch_expect($json['schema'] === 'badpool.payment_batch.preview.v1', 'schema mismatch', $failures);
batch_expect($json['scope'] === 'all-active-payout-coins', 'default scope mismatch', $failures);
batch_expect($json['batch_size'] === 250, 'default batch size mismatch', $failures);
batch_expect($json['stop_before_wallet_send'] === true, 'wallet boundary is not boolean true', $failures);
$names = array('Safety Check','Select Eligible Work','Package Intent','Mature Earnings','Payment Delay Check','Credit Accounts','Prepare Payout Rows','Send Wallet Payment','Closeout Proof','Batch Complete');
batch_expect(count($json['phases']) === 10 && array_column($json['phases'], 'phase_name') === $names, 'phase contract mismatch', $failures);
foreach (array('maturity_apply','account_credit_apply','payout_row_creation','wallet_send','wallet_rpc_read','wallet_rpc_send','service_changes','backend_loops','share_deletion') as $blocked) batch_expect(in_array($blocked, $json['blocked_actions'], true), "missing blocked action $blocked", $failures);
batch_expect(is_array($json['inferred_coin_scope']) && is_array($json['eligible_work_summary']), 'object contracts are not objects', $failures);

$scrypt = json_decode(batch_run(array('--only=scrypt', '--format=json'), $rc), true);
batch_expect($rc === 0 && $scrypt['inferred_coin_scope']['only'] === 'scrypt', 'scrypt narrowing failed', $failures);
$invalidOnly = json_decode(batch_run(array('--only=randomx', '--format=json'), $rc), true);
batch_expect($rc === 2 && $invalidOnly['status'] === 'refused', 'invalid only was not refused', $failures);
$invalidSize = json_decode(batch_run(array('--batch-size=0', '--format=json'), $rc), true);
batch_expect($rc === 2 && $invalidSize['status'] === 'refused', 'invalid batch size was not refused', $failures);
$text = batch_run(array('--format=text'), $rc);
batch_expect(strpos($text, 'BADPOOL PAYMENT BATCH PREVIEW') !== false && strpos($text, 'CLASSIFICATION=PASS') !== false && strpos($text, 'NEXT_ACTION=implement_phase_0_to_6_automation') !== false, 'text contract mismatch', $failures);

$method = new ReflectionMethod('BadpoolGuardCommand', 'paymentBatchPreviewReport');
$source = file_get_contents(dirname(__DIR__).'/web/yaamp/commands/BadpoolGuardCommand.php');
$lines = explode("\n", $source);
$body = implode("\n", array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
foreach (array('->select', 'app()', 'WalletRPC', 'sendmany(', 'sendtoaddress(', 'UPDATE ', 'INSERT ', 'DELETE ', 'BackendPayments', 'BackendCoinPayments') as $forbidden) batch_expect(strpos($body, $forbidden) === false, "preview invokes forbidden surface $forbidden", $failures);

if ($failures) { echo "Badpool payment batch preview harness FAILED\n"; foreach ($failures as $failure) echo " - $failure\n"; exit(1); }
echo "Badpool payment batch preview harness passed\n";
