<?php
$root = dirname(__DIR__);
$commandPath = $root.'/web/yaamp/commands/BadpoolGuardCommand.php';
$failures = array();
function expect_contains($label, $haystack, $needle, &$failures) { if (strpos($haystack, $needle) === false) $failures[] = "$label: missing expected text: $needle"; }
function expect_not_contains($label, $haystack, $needle, &$failures) { if (strpos($haystack, $needle) !== false) $failures[] = "$label: found forbidden text: $needle"; }
$command = is_file($commandPath) ? file_get_contents($commandPath) : '';
foreach (array('payout-row-approval-package','payout-row-apply') as $action) { expect_contains("action registered: $action", $command, "'$action'", $failures); expect_contains("help documents: $action", $command, "badpoolguard $action", $failures); }
foreach (array('approval-package-checksum','selected-scope-checksum','projected-payout-row-checksum','projected-account-debit-checksum') as $opt) expect_contains("exact checksum required: $opt", $command, $opt, $failures);
expect_contains('operator confirmation required', $command, 'operator-confirms-payout-row-creation', $failures);
expect_contains('operator confirmation exact string', $command, 'scrypt_balance_to_payout_rows_no_wallet_send', $failures);
expect_contains('apply report schema constant', $command, "const APPLY_SCHEMA = 'badpool.guardrail.apply.v1';", $failures);
expect_contains('apply report mode constant', $command, "const APPLY_MODE = 'guarded-apply';", $failures);
expect_contains('apply base report marks mutating schema', $command, "\$r['schema']=self::APPLY_SCHEMA", $failures);
expect_contains('apply base report marks guarded mode', $command, "\$r['mode']=self::APPLY_MODE", $failures);
expect_contains('apply base report is not read only', $command, "\$r['read_only']=false", $failures);
expect_contains('read-only base schema preserved', file_get_contents($root.'/web/yaamp/core/backend/BadpoolGuardContext.php'), "const SCHEMA = 'badpool.guardrail.preview.v1';", $failures);
expect_contains('read-only base mode preserved', file_get_contents($root.'/web/yaamp/core/backend/BadpoolGuardContext.php'), "const MODE = 'read-only-preview';", $failures);
expect_contains('operator web cwd constant', $command, "const OPERATOR_WEB_CWD = '/srv/badpool/yiimp-badpool/web';", $failures);
expect_contains('payout apply command cd prefix', $command, "array('cd',self::OPERATOR_WEB_CWD,'&&','php','yaamp/yiic.php','badpoolguard','payout-row-apply'", $failures);
expect_contains('payout apply committed mutation label', $command, "'db_mutations'=>'guarded_transaction_committed'", $failures);
expect_contains('same source as preview', $command, 'same buildReadOnlyPayoutCandidates source as payout-candidates-preview', $failures);
expect_contains('apply refuses empty selected account IDs', $command, 'selected_account_ids_required', $failures);
expect_contains('apply rejects duplicate selected account IDs', $command, 'Duplicate selected account IDs are refused.', $failures);
expect_contains('apply rejects missing noncandidate selected account IDs', $command, 'Every requested account ID must be present in current payout candidates.', $failures);
expect_contains('apply uses scope mismatch abort reason', $command, 'selected_account_scope_mismatch', $failures);
expect_contains('apply requires json format abort reason', $command, 'json_format_required', $failures);
expect_contains('apply transaction wrapped', $command, 'app()->db->beginTransaction()', $failures);
expect_contains('payout row insert exists', $command, 'INSERT INTO payouts (account_id, idcoin, time, amount, completed, tx) VALUES', $failures);
expect_contains('payout schema preflight abort exists', $command, 'payout_schema_missing', $failures);
expect_contains('accounts schema preflight abort exists', $command, 'accounts_schema_missing', $failures);
expect_contains('payout schema preflight checks required columns', $command, "array('account_id', 'idcoin', 'time', 'amount', 'completed', 'tx')", $failures);
expect_contains('payout schema uses columnExists', $command, "columnExists('payouts', \$column)", $failures);
expect_contains('accounts schema preflight checks required columns', $command, "array('id', 'coinid', 'balance')", $failures);
expect_contains('accounts schema uses columnExists', $command, "columnExists('accounts', \$column)", $failures);
expect_contains('account debit guarded by account id coinid exact balance', $command, 'UPDATE accounts SET balance=:new_balance WHERE id=:id AND coinid=:coinid AND balance=:old_balance', $failures);
expect_contains('balance changed refusal', $command, 'selected account balance changed before apply', $failures);
expect_contains('before balances reported', $command, 'before_account_balances', $failures);
expect_contains('after balances reported', $command, 'after_account_balances', $failures);
expect_contains('payout count reported', $command, 'payout_count', $failures);
expect_contains('withdraw rows not created', $command, 'withdraw_rows_created', $failures);
expect_contains('payouts not marked completed', $command, 'payouts_marked_completed', $failures);
expect_contains('old payouts not retried or deleted', $command, 'old_payouts_retried_or_deleted', $failures);
$start = strpos($command, 'private function payoutRowApplyReport');
$end = strpos($command, 'private function parsePayoutRowApplyOptions', $start);
$apply = ($start === false || $end === false) ? '' : substr($command, $start, $end - $start);
foreach (array('sendtoaddress','sendmany','wallet-rpc','WalletRPC','BackendPayments','delete()','DELETE FROM payouts','completed=1') as $forbidden) expect_not_contains('wallet send/retry/delete not used in apply path', $apply, $forbidden, $failures);
$schemaPreflight = strpos($command, 'payoutRowApplySchemaError');
$transactionStart = strpos($command, 'app()->db->beginTransaction()', strpos($command, 'private function payoutRowApplyReport'));
if ($schemaPreflight === false || $transactionStart === false || $schemaPreflight > $transactionStart) $failures[] = 'schema preflight must exist before payout-row apply transaction begins';
if (!empty($failures)) { echo "Badpool payout-row apply guard harness FAILED\n"; foreach ($failures as $failure) echo " - $failure\n"; exit(1); }
echo "Badpool payout-row apply guard harness passed\n";
