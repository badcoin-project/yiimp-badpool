<?php
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolGuardAutomationContracts.php');
$root = dirname(__DIR__);
$command = file_get_contents($root.'/web/yaamp/commands/BadpoolGuardCommand.php');
$parser = file_get_contents($root.'/tests/badpool_guardrail_report_parser_hardening_harness.php');
$failures = array();
function expect_contains($label, $haystack, $needle, &$failures) { if (strpos($haystack, $needle) === false) $failures[] = $label; }
function expect_not_contains($label, $haystack, $needle, &$failures) { if (strpos($haystack, $needle) !== false) $failures[] = $label; }
function section_between($haystack, $start, $end) { $s = strpos($haystack, $start); if ($s === false) return ''; $e = strpos($haystack, $end, $s + strlen($start)); return $e === false ? substr($haystack, $s) : substr($haystack, $s, $e - $s); }
$proof = section_between($command, 'private function walletProofCloseoutReport', 'private function walletSendDryrunReport');
expect_contains('action registered', $command, "'wallet-proof-closeout'", $failures);
expect_contains('help documents command shape', $command, 'badpoolguard wallet-proof-closeout --coin-id=<id> --selected-payout-ids=<csv> --format=json', $failures);
foreach (array('schema','command_shape','read_only','db_mutations','wallet_sends','selected_payout_ids','payout_inventory','wallet_lookup_success','wallet_txid_expected','wallet_amount_matches_expected','wallet_confirmations_present','closeout_valid','missing_closeout_fields','invalid_closeout_fields','classification','final_classification','do_not_rerun','next_safe_lane_or_STOP','fix_items') as $field) expect_contains('machine field '.$field, $proof, "'$field'", $failures);
$contractReport = array('classification'=>'wallet_proof_hold','run_dir'=>'/srv/badpool/yiimp-badpool/web','mutation_boundary'=>'read_only_wallet_gettransaction_no_db_mutation_no_wallet_send','next_lane'=>'STOP','do_not_rerun'=>array('wallet-send-apply'=>true),'fix_items'=>array());
if (BadpoolGuardAutomationContracts::validateCloseout($contractReport)['closeout_valid'] !== true) $failures[] = 'closeout minimum fields validated through BadpoolGuardAutomationContracts';
expect_contains('payout 516 daemon conf fixture', $proof, '/etc/badcoin/pool-scrypt.conf', $failures);
expect_contains('payout 516 daemon datadir fixture', $proof, '/var/lib/badcoin-pool-scrypt', $failures);
expect_contains('uses gettransaction only', $proof, "'gettransaction'", $failures);
expect_contains('wrong credential context classified as wallet_proof_hold', $proof, 'wallet_proof_hold, not payment failure', $failures);
foreach (array('payout_tx','completed_not_1','coin_id_mismatch','account_balance_nonzero','withdraw_rows_present') as $guard) expect_contains('closeout guard '.$guard, $proof, $guard, $failures);
foreach (array('sendmany','sendtoaddress','transfer','wallet passphrase RPCs') as $blocked) expect_contains('blocked method reported '.$blocked, $proof, $blocked, $failures);
foreach (array('badpoolGuardedSendmanyApply(', '->sendmany(', '->sendtoaddress(', '->transfer(', 'UPDATE payouts', 'createCommand()->update', 'beginTransaction') as $forbidden) expect_not_contains('no mutation/send reachable: '.$forbidden, $proof, $forbidden, $failures);
foreach (array('rpcuser','rpcpassword','rpccookie','rpcauth','cookie_redacted','credentials_redacted','[REDACTED]') as $redaction) expect_contains('secrets redaction '.$redaction, $proof, $redaction, $failures);
expect_contains('required PR75 parser harness remains intact', $parser, 'BadpoolGuardAutomationContracts::validateCloseout', $failures);
if ($failures) { echo "Badpool wallet proof closeout guard harness FAILED\n"; foreach ($failures as $f) echo " - $f\n"; exit(1); }
echo "Badpool wallet proof closeout guard harness passed\n";
