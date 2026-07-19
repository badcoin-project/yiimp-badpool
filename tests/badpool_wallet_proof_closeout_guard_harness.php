<?php
if (!class_exists('CConsoleCommand')) { abstract class CConsoleCommand {} }
if (!function_exists('arraySafeVal')) { function arraySafeVal($arr, $key, $default=null) { return is_array($arr) && array_key_exists($key, $arr) ? $arr[$key] : $default; } }
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolGuardAutomationContracts.php');
require_once(dirname(__FILE__).'/../web/yaamp/commands/BadpoolGuardCommand.php');
$root = dirname(__DIR__);
$command = file_get_contents($root.'/web/yaamp/commands/BadpoolGuardCommand.php');
$parser = file_get_contents($root.'/tests/badpool_guardrail_report_parser_hardening_harness.php');
$failures = array();
function expect_contains($label, $haystack, $needle, &$failures) { if (strpos($haystack, $needle) === false) $failures[] = $label; }
function expect_not_contains($label, $haystack, $needle, &$failures) { if (strpos($haystack, $needle) !== false) $failures[] = $label; }
function section_between($haystack, $start, $end) { $s = strpos($haystack, $start); if ($s === false) return ''; $e = strpos($haystack, $end, $s + strlen($start)); return $e === false ? substr($haystack, $s) : substr($haystack, $s, $e - $s); }
function invoke_private($object, $method, $args=array()) { $r = new ReflectionMethod(get_class($object), $method); $r->setAccessible(true); return $r->invokeArgs($object, $args); }
$proof = section_between($command, 'private function walletProofCloseoutReport', 'private function walletSendDryrunReport');
expect_contains('action registered', $command, "'wallet-proof-closeout'", $failures);
expect_contains('help documents command shape', $command, 'badpoolguard wallet-proof-closeout --coin-id=<id> --selected-payout-ids=<csv> --format=json', $failures);
foreach (array('schema','command_shape','read_only','db_mutations','wallet_sends','selected_payout_ids','payout_inventory','wallet_lookup_success','wallet_txid_expected','wallet_amount_matches_expected','wallet_confirmations_present','closeout_valid','missing_closeout_fields','invalid_closeout_fields','classification','final_classification','do_not_rerun','next_safe_lane_or_STOP','fix_items') as $field) expect_contains('machine field '.$field, $proof, "'$field'", $failures);
$rc = new ReflectionClass('BadpoolGuardCommand');
$cmd = $rc->newInstanceWithoutConstructor();
$supported = invoke_private($cmd, 'walletProofDaemonContext', array(1267));
$unsupported = invoke_private($cmd, 'walletProofDaemonContext', array(1));
if (!is_array($supported) || !$supported['supported'] || $supported['conf'] !== '/etc/badcoin/pool-scrypt.conf' || $supported['datadir'] !== '/var/lib/badcoin-pool-scrypt') $failures[] = 'payout 516 daemon-equivalent context behavior';
if (!is_array($unsupported) || $unsupported['supported'] !== false) $failures[] = 'unsupported coins fail closed by daemon context behavior';
$redacted = invoke_private($cmd, 'walletProofRedactedDaemonContext', array($supported));
if (strpos(json_encode($redacted), '/etc/badcoin/pool-scrypt.conf') !== false || !$redacted['credentials_redacted'] || !$redacted['cookie_redacted']) $failures[] = 'daemon context redacts conf/cookie credential material behavior';
$secret = invoke_private($cmd, 'walletProofRedact', array('rpcuser=alice rpcpassword=secret rpccookie=token rpcauth=hash cookie=sensitive'));
if (strpos($secret, 'secret') !== false || strpos($secret, 'token') !== false || strpos($secret, '[REDACTED]') === false) $failures[] = 'secret redaction behavior';
if (invoke_private($cmd, 'walletProofDecimalIsZero', array('0.00000000')) !== true || invoke_private($cmd, 'walletProofDecimalIsZero', array('0.1')) !== false) $failures[] = 'account balance zero decimal behavior';
if (invoke_private($cmd, 'walletProofAmountMatches', array('-1.25000000', '1.25000000')) !== true || invoke_private($cmd, 'walletProofAmountMatches', array('-1.25000001', '1.25000000')) !== false) $failures[] = 'negative wallet send amount match behavior';
$contractReport = array('classification'=>'wallet_proof_hold','run_dir'=>'/srv/badpool/yiimp-badpool/web','mutation_boundary'=>'read_only_wallet_gettransaction_no_db_mutation_no_wallet_send','next_lane'=>'STOP','do_not_rerun'=>array('wallet-send-apply'=>true),'fix_items'=>array());
if (BadpoolGuardAutomationContracts::validateCloseout($contractReport)['closeout_valid'] !== true) $failures[] = 'closeout minimum fields validated through BadpoolGuardAutomationContracts';
expect_contains('runtime pass classification', $proof, 'PASS / WALLET PROOF CLOSEOUT COMPLETE', $failures);
expect_contains('runtime hold classification', $proof, 'HOLD / WALLET PROOF INCOMPLETE', $failures);
expect_not_contains('must not use PR approval as runtime classification', $proof, 'READY FOR PR APPROVAL', $failures);
expect_contains('unsupported classification', $proof, 'unsupported_wallet_proof_context', $failures);
foreach (array('payout_tx','completed_not_1','coin_id_mismatch','account_balance_nonzero','withdraw_rows_present') as $guard) expect_contains('closeout guard '.$guard, $proof, $guard, $failures);
foreach (array('sendmany','sendtoaddress','transfer','wallet passphrase RPCs') as $blocked) expect_contains('blocked method reported '.$blocked, $proof, $blocked, $failures);
foreach (array('badpoolGuardedSendmanyApply(', '->sendmany(', '->sendtoaddress(', '->transfer(', 'UPDATE payouts', 'createCommand()->update', 'beginTransaction') as $forbidden) expect_not_contains('no mutation/send reachable: '.$forbidden, $proof, $forbidden, $failures);
expect_contains('required PR75 parser harness remains intact', $parser, 'BadpoolGuardAutomationContracts::validateCloseout', $failures);
if ($failures) { echo "Badpool wallet proof closeout guard harness FAILED\n"; foreach ($failures as $f) echo " - $f\n"; exit(1); }
echo "Badpool wallet proof closeout guard harness passed\n";
