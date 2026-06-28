# PR #56 Wallet-Send Path Source Review

This is a source-review and design/audit document only. It adds no executable wallet-send code, no production command, no wallet RPC call, no database write, and no service change.

Reviewed source files:

- `web/yaamp/core/backend/payment.php`
- `web/yaamp/commands/PayoutCommand.php`
- `web/yaamp/core/rpc/wallet-rpc.php`
- `web/yaamp/core/rpc/wallet-send-guard.php`
- `web/yaamp/models/db_payoutsModel.php`
- `web/yaamp/models/db_accountsModel.php`
- `web/yaamp/models/db_coinsModel.php`
- `web/yaamp/modules/thread/CronjobController.php`

## Executive findings

The production backend payout path does **not** select an existing inventory of `completed=0` payout rows to send. Instead, `BackendCoinPayments()` selects eligible `accounts` rows by coin and balance, creates new `payouts` rows, debits account balances, calls wallet RPC, and then updates the payout rows it just created.

The normal multi-recipient production path uses `accounts.username` as the wallet destination address and calls `sendmany`. A coin-specific branch uses individual `sendtoaddress` calls, also using `accounts.username` as the destination. Existing payout rows with empty/null `tx` can be deleted and recreated by retry repair logic after a successful normal send. The current wallet-send hard guard blocks `sendmany`, `sendtoaddress`, and other send-like methods before the wallet RPC implementation can dispatch them.

## Question-by-question audit

### 1. Which function actually selects `completed=0` payout rows?

No production wallet-send function in the reviewed backend payment path selects `completed=0` payout rows as the send inventory.

The reviewed `completed=0` selection is in the console audit/check command, not in the production send path:

```php
$payouts = $dbPayouts->findAll(array(
    'condition'=>"completed=0 AND account_id=$uid AND time > ".intval($since),
    'order'=>'time DESC',
));
```

That code is inside `PayoutCommand::checkPayouts()` and is used to print extra database transaction rows after comparing wallet history. It is not the send path.

Production `BackendCoinPayments()` selects eligible users from `accounts` with `balance>$min_payout AND coinid={$coin->id}`, then creates payout rows for those users before calling the wallet.

### 2. Does the production send path use `payouts.account_id` + `idcoin`, account username/address, or another recipient source?

The production send path uses `accounts.username` as the recipient destination address.

For normal multi-recipient sends, `BackendCoinPayments()` builds `$addresses[$user->username] = round($user->balance, 8)` from users selected out of `accounts`. It creates payout rows with `account_id=$user->id` and `idcoin=$coin->id`, but those payout columns are recordkeeping for the newly created rows; they are not the recipient source for the wallet call.

For the coin-specific individual send branch, the wallet call is also made to `$user->username` with `$remote->sendtoaddress($user->username, round($amount, 8))`.

The `PayoutCommand::redoTransaction()` maintenance path is different: it starts from existing payout rows selected by old `tx`, resolves each row's `account_id` to an account, and uses that account's `username` as the `sendmany` destination. That command is gated by `YIIMP_CLI_ALLOW_TXS` and is not the normal cron production payout path.

### 3. Does it group rows into `sendmany`, individual `sendtoaddress`, or both?

Both patterns exist.

- The normal backend production path groups selected accounts into one `$addresses` map and calls `sendmany($account, $addresses)`.
- A special branch for `BOD`, `DIME`, `BTCRY`, or coins with non-empty `payout_max` loops one account at a time and calls `sendtoaddress($user->username, round($amount, 8))`.
- The retry repair block for failed empty-`tx` payout rows also groups destinations into `$addresses` and calls `sendmany`.
- `PayoutCommand::redoTransaction()` groups existing payout rows into `$dests` and calls `sendmany`, but that is a manual maintenance command rather than the normal cron path.

### 4. What columns are updated after successful send?

In the normal `sendmany` path, payout rows are first inserted with `account_id`, `time`, `amount`, `fee=0`, and `idcoin`. If row creation succeeds, the user's `accounts.balance` is reduced before the wallet send is attempted.

After a successful normal `sendmany`, each just-created payout row is updated with:

- `errmsg = NULL`
- `tx = $tx`
- `completed = 1`

In the individual `sendtoaddress` branch, each payout row is inserted after the wallet returns a transaction id. The inserted row receives:

- `account_id`
- `time`
- `amount`
- `fee = 0`
- `tx = $tx`
- `idcoin = $coin->id`

That branch then reduces and saves `accounts.balance`.

In the retry repair `sendmany` path, successful retry rows are updated with `tx = $tx` only. The retry success block does **not** set `completed = 1`.

### 5. Is `completed` set to `1`, and is `tx` set to the wallet txid?

For the normal production `sendmany` path: yes. If the wallet call returns a string txid and no error message is set, the just-created payout rows are saved with `tx=$tx` and `completed=1`.

For the individual production `sendtoaddress` branch: `tx` is set to the wallet txid on insert, but `completed` is not explicitly set in the reviewed source.

For the retry repair `sendmany` path after empty-`tx` rows are deleted and recreated: `tx` is set to the retry wallet txid, but `completed` is not explicitly set to `1` in that success block.

For `PayoutCommand::redoTransaction()`: replacement payout rows are inserted with `completed=1` and `tx=$new_txid`; original rows for the old tx can then be updated to `completed=0`, `tx='orphaned'`, and `memoid='redo'`.

### 6. Can it accidentally process payout rows beyond ids 512 and 513?

Yes, if a future send implementation reused the existing production/retry code without a new id allowlist, it could process more than payout IDs 512 and 513.

Reasons:

- The normal production path does not select existing payout IDs at all. It selects all accounts for a coin whose balance exceeds the payout threshold, then creates a new payout row per selected account.
- The retry repair path scans each selected user's existing payout rows with empty/null `tx`, deletes all such rows for those users, recreates aggregate failed payout rows, and may resend them if the aggregate exceeds the minimum payout.
- The manual `redoTransaction()` path selects all payout rows with a matching `tx`, not a fixed row-id list.

Therefore a future wallet-send command for rows 512 and 513 must have a hard database predicate of `payouts.id IN (512, 513)`, must require an exact returned row count of two, and must fail closed if any additional row appears in the send inventory.

### 7. Can it retry or delete existing payout rows?

Yes.

- `BackendUserCancelFailedPayment($userid)` selects payout rows for one account where `IFNULL(tx,'') = ''`, sums their amount, deletes those rows, and credits the account balance.
- The production post-send repair block searches for previous payouts not executed by selecting rows where `account_id=:uid AND IFNULL(tx,'') = '' ORDER BY time`. For most coins it deletes those failed rows, creates a new aggregate payout row, and may retry the wallet send via `sendmany`.
- `db_accounts::deleteWithDeps()` deletes all payout rows for an account when deleting that account.
- `db_coins::deleteDeps()` deletes all payout rows for accounts belonging to the deleted coin.
- `PayoutCommand::redoTransaction()` can create replacement payout rows and mark old rows for a tx as `completed=0`, `tx='orphaned'`, and `memoid='redo'`.

### 8. Does `wallet-send-guard` currently block `sendmany`/`sendtoaddress` unless explicitly allowed?

Yes. The guard has no runtime activation switch and no allow path in the reviewed code. `WalletRPC::__call()` invokes `badpool_wallet_send_guard_is_send_method($method)` before wallet-specific dispatch. The guarded method list includes `sendmany` and `sendtoaddress`, and the guard also catches methods beginning with `send` or containing `_send`. On a match, it records a refusal message in `$this->error` and returns `false`.

### 9. What exact guard command should be added next for wallet send?

Add `wallet-send-dryrun` next.

Do **not** add `wallet-send-apply` yet. The next step should remain non-mutating and should only read/validate an explicitly approved two-row inventory. The dry run should prove that the send inventory, source rows, destination derivation, amount formatting, idempotency constraints, and guard/blocked-actions state are correct before any approval package or apply path exists.

Recommended staged command order:

1. `wallet-send-dryrun` — read-only inventory and checksum generation only.
2. `wallet-send-approval-package` — read-only operator packet builder bound to a dry-run checksum and explicit confirmations.
3. `wallet-send-apply` — later, in a separate approved PR, after the dry-run and approval-package outputs are reviewed.

### 10. What exact checksums and operator confirmations should bind rows 512/513?

Because this task is source-review only and does not query production data, this document cannot truthfully provide the row-content checksum values for payout rows 512 and 513. The next read-only command must compute them from a deterministic, canonical representation of exactly those two database rows and the derived send plan.

The checksum contract should be exact and narrow:

#### Required row inventory checksum

Name: `wallet_send_row_inventory_sha256`

Rows: exactly payout IDs `512` and `513`, ordered by numeric `payouts.id` ascending.

Canonical row fields per row, with no secret material:

- `payouts.id`
- `payouts.account_id`
- `payouts.idcoin`
- `payouts.amount`
- `payouts.fee`
- `payouts.tx`
- `payouts.completed`
- `payouts.time`
- `payouts.errmsg`
- `payouts.memoid`
- joined `accounts.id`
- joined `accounts.coinid`
- joined `accounts.username`
- joined `accounts.balance`
- joined `accounts.is_locked`
- joined `coins.id`
- joined `coins.symbol`
- joined `coins.symbol2`
- joined `coins.account`
- joined `coins.txfee`
- joined `coins.payout_min`
- joined `coins.rpcencoding`

Canonicalization requirements:

- Emit UTF-8 JSON.
- Sort object keys lexicographically at every level.
- Preserve decimal values as database strings, not floats.
- Represent SQL `NULL` as JSON `null`.
- Include an outer object with `schema="badpool.wallet_send.row_inventory.v1"`, `payout_ids=[512,513]`, `row_count=2`, and `rows=[...]`.
- Compute lowercase hex SHA-256 over the exact JSON bytes.

#### Required destination plan checksum

Name: `wallet_send_destination_plan_sha256`

Plan: exactly two destinations derived from `accounts.username`, with amounts derived from `payouts.amount`, ordered by payout id ascending.

Canonical plan fields per destination:

- `payout_id`
- `account_id`
- `idcoin`
- `coin_symbol`
- `recipient_source="accounts.username"`
- `recipient`
- `amount`
- `send_method="sendmany"`
- `wallet_account` from `coins.account`

Canonicalization requirements:

- Emit UTF-8 JSON.
- Sort object keys lexicographically at every level.
- Preserve amount as the exact database decimal string intended for wallet submission.
- Include an outer object with `schema="badpool.wallet_send.destination_plan.v1"`, `payout_ids=[512,513]`, `row_count=2`, and `destinations=[...]`.
- Compute lowercase hex SHA-256 over the exact JSON bytes.

#### Required approval checksum

Name: `wallet_send_operator_approval_sha256`

Canonical fields:

- `schema="badpool.wallet_send.operator_approval.v1"`
- `repository_commit`
- `command_name="wallet-send-approval-package"`
- `requested_next_command="wallet-send-apply"`
- `payout_ids=[512,513]`
- `wallet_send_row_inventory_sha256`
- `wallet_send_destination_plan_sha256`
- `operator_name`
- `reviewer_name`
- `approval_timestamp_utc`
- `backup_or_snapshot_reference`
- `confirmed_row_count=2`
- `confirmed_no_extra_rows=true`
- `confirmed_completed_zero_for_all=true`
- `confirmed_tx_empty_for_all=true`
- `confirmed_coin_single_id=true`
- `confirmed_accounts_match_coin=true`
- `confirmed_recipients_match_expected_addresses=true`
- `confirmed_amounts_match_expected=true`
- `confirmed_send_method="sendmany"`
- `confirmed_wallet_guard_change_reviewed=true`
- `confirmed_no_retry_delete_or_repair_path=true`
- `confirmed_apply_is_one_shot=true`
- `confirmed_stop_on_any_mismatch=true`

Canonicalization requirements:

- Emit UTF-8 JSON.
- Sort object keys lexicographically at every level.
- Preserve checksum values as lowercase hex strings.
- Compute lowercase hex SHA-256 over the exact JSON bytes.

#### Operator confirmations required before any future apply

The approval package for rows 512 and 513 must require the operator and reviewer to confirm, in writing, all of the following exact statements:

1. I approve wallet-send work for payout IDs `512` and `513` only.
2. I confirm the row inventory contains exactly two rows and no row outside IDs `512` and `513`.
3. I confirm both payout rows have `completed=0` before send.
4. I confirm both payout rows have empty or null `tx` before send.
5. I confirm both payout rows share the same `idcoin`.
6. I confirm each joined account exists and `accounts.coinid` equals the payout `idcoin`.
7. I confirm each recipient is derived from `accounts.username` and matches the expected wallet address for that account.
8. I confirm each wallet amount matches the approved payout row amount exactly.
9. I confirm the intended wallet method is `sendmany`, not per-row `sendtoaddress`, unless a later approval explicitly changes this.
10. I confirm the command must stop if the live row inventory checksum differs from `wallet_send_row_inventory_sha256`.
11. I confirm the command must stop if the live destination plan checksum differs from `wallet_send_destination_plan_sha256`.
12. I confirm the command must stop if either row is already completed, has a non-empty tx, has a changed amount, has a changed account, or has a changed recipient.
13. I confirm no automatic retry, deletion, recreation, account debit, backend payment run, service restart, or repair mutation is authorized by this approval.
14. I confirm a backup or snapshot reference has been captured before apply.
15. I confirm post-send verification must update only the approved rows and must record the wallet txid only after a successful wallet result.

## Implications for the next PR

The next PR should introduce only `wallet-send-dryrun` behavior. It should not call wallet RPC send methods. It should not unblock the current wallet-send hard guard for production sends. It should only prove that a future send apply can be bound to payout IDs 512 and 513 with deterministic checksums, exact row-count checks, and explicit human confirmations.
