# BadPool Backend/Payment Production Guardrail Implementation Plan

## Executive summary

BadPool should remain in **NO-GO / KEEP FROZEN** state for regular backend and miner payout execution until source-level guardrails exist and are validated in preview mode. The reviewed YIIMP backend path combines block discovery, earnings creation, share deletion, earnings maturity, account credits, payout-row creation, account debits, payout retry cleanup, and wallet RPC sends across long-running cron loops. That makes the current production backlog unsafe to process by simply re-enabling backend services.

The immediate source-level goal is to add default-safe guardrails that let operators preview each stage, scope execution to an explicit coin allowlist, and keep wallet sends, share deletion, payout retry/delete, and account mutation disabled unless separate explicit execute flags are provided.

Known live concern to account for during design, without accessing live systems from this repository task:

- `badpool-blocks.service` remains inactive/disabled.
- `badpool-loop2.service` remains inactive/disabled.
- A live production backlog exists in block and earnings accounting tables. Normal backend execution could process that backlog through unsafe mutation paths.

This plan is documentation-only. It does not start services, connect to wallets, connect to production databases, read secrets, or change runtime behavior.

## Current unsafe production path summary

### Long-running service entry points

- `web/blocks.sh` repeatedly calls `runconsole.php cronjob/runBlocks`, which dispatches `CronjobController::actionRunBlocks()`.
- `web/main.sh` repeatedly calls `runconsole.php cronjob/run`, which dispatches `CronjobController::actionRun()`.
- `web/loop2.sh` repeatedly calls `runconsole.php cronjob/runLoop2`, which dispatches `CronjobController::actionRunLoop2()`.
- `web/runconsole.php` is the generic Yii console dispatcher.

### Backend block/accounting flow

Current block/accounting flow should be treated as unsafe on production data because it mutates multiple tables and can delete shares:

```text
blocks.sh -> cronjob/runBlocks
  -> BackendBlockFind1()
  -> BackendBlockNew()
  -> earnings rows created
  -> shares deleted
  -> BackendClearEarnings()
  -> earnings marked cleared
  -> accounts.balance credited
  -> BackendBlocksUpdate()
  -> blocks/earnings/coins mutated
```

Secondary block discovery runs from the main loop:

```text
main.sh -> cronjob/run state 6
  -> BackendBlockFind2()
  -> blocks rows created
  -> coins.lastblock/hasmasternodes possibly updated
  -> BackendBlockNew()
  -> earnings rows created
  -> shares deleted
  -> BackendUpdatePoolBalances()
  -> coins balance summary fields updated
```

### Payment flow

Current payment flow should be treated as unsafe because it couples candidate selection, payout rows, account debits, retry cleanup, and wallet sends:

```text
main.sh -> cronjob/run scheduled payment block
  -> balances_locked=true
  -> BackendPayments()
  -> BackendCoinPayments()
  -> payout candidates selected
  -> payout rows created
  -> accounts.balance debited
  -> sendtoaddress() or sendmany()
  -> payouts updated with tx/completed/errmsg
  -> failed payout rows may be deleted/recreated
  -> retry sendmany() may execute
```

`PayoutCommand` has additional risk areas:

- `check <symbol>` is mostly reconciliation-oriented, but `check <symbol> fixit` creates payout rows and debits account/balance history.
- `redotx <txid>` can call `sendmany()` and create replacement payout rows when transaction execution is enabled.

### Exchange/withdraw paths

Related exchange/trading code can create `withdraws` rows and can call wallet or exchange transfer/withdraw APIs. These are not normal miner payout rows, but restoration guardrails must treat them as financial mutation paths and keep them outside any miner payout preview/execute task unless explicitly scoped.

## Proposed flags, constants, and options

Use default-safe names that can be implemented as config constants, environment-backed config, or CLI options. Defaults must be conservative even when `YAAMP_PRODUCTION` is true.

| Name | Default | Scope | Purpose |
| --- | --- | --- | --- |
| `BADPOOL_BACKEND_DRY_RUN` | `true` | all guardrail commands | Global no-mutation mode. Any planned write/send/delete is reported, not executed. |
| `BADPOOL_COIN_ALLOWLIST` | empty | all guardrail commands | Comma-separated coin IDs/symbols. Empty means no execute-mode mutations are allowed. Preview may allow explicit `--coin-id`. |
| `BADPOOL_REQUIRE_COIN_ALLOWLIST` | `true` | all execute modes | Blocks accidental all-coin execution. |
| `BADPOOL_WALLET_SENDS_ENABLED` | `false` | wallet RPC/send paths | Hard gate for `sendmany`, `sendtoaddress`, CryptoNote `transfer`, and CLI redotx. |
| `BADPOOL_SHARE_DELETE_ENABLED` | `false` | `BackendBlockNew`, cleanup paths | Hard gate for `DELETE FROM shares` in guarded backend flows. |
| `BADPOOL_PAYOUT_RETRY_DELETE_ENABLED` | `false` | payment retry paths | Prevents failed payout deletion/recreation and retry sends unless explicitly enabled. |
| `BADPOOL_ACCOUNT_CREDIT_ENABLED` | `false` | `BackendClearEarnings` | Blocks `accounts.balance` credits unless execute mode explicitly permits. |
| `BADPOOL_PAYOUT_ROWS_ENABLED` | `false` | payment execute mode | Blocks `db_payouts` row insertion unless explicitly enabled. |
| `BADPOOL_BLOCK_UPDATES_ENABLED` | `false` | block update execute mode | Blocks `blocks` category/amount/tx/confirmations updates unless explicitly enabled. |
| `BADPOOL_EARNINGS_CREATE_ENABLED` | `false` | earnings creation execute mode | Blocks `db_earnings` inserts unless explicitly enabled. |
| `BADPOOL_EARNINGS_MATURE_ENABLED` | `false` | maturity execute mode | Blocks earnings status transitions and deletes unless explicitly enabled. |
| `BADPOOL_COIN_UPDATES_ENABLED` | `false` | coin summary/update code | Blocks `coins.lastblock`, `mature_blocks`, `available`, `cleared`, `immature`, and wallet balance writes unless enabled. |
| `BADPOOL_STRUCTURED_REPORT` | `json` | preview commands | Output format: `json`, `jsonl`, `csv`, or `table`. |
| `BADPOOL_REPORT_DIR` | unset | preview commands | Optional local report destination. If unset, output to stdout. |
| `BADPOOL_REQUIRE_SERVICE_INACTIVE` | `true` | restoration preview/execute | Require confirmation that dangerous backend services are inactive before execute-mode operations. |
| `BADPOOL_EXECUTE_CONFIRMATION` | empty | execute modes | Must match a generated confirmation token or exact operator-provided phrase before any mutation. |

CLI aliases should mirror config flags:

- `--dry-run` / `--execute`
- `--coin-id=<coin-id>`
- `--coin-symbol=BAD`
- `--allow-wallet-send`
- `--allow-share-delete`
- `--allow-payout-retry-delete`
- `--allow-account-credit`
- `--allow-payout-rows`
- `--allow-block-updates`
- `--allow-earnings-create`
- `--allow-earnings-mature`
- `--allow-coin-updates`
- `--format=json|jsonl|csv|table`
- `--report-file=/safe/local/path/report.json`
- `--require-services-inactive`
- `--confirm=<token>`

## Proposed command entry points

Add new command entry points rather than extending the always-on cron loops first. Proposed command names are illustrative and should be implemented in a new command class such as `BackendGuardCommand` or `BadpoolGuardCommand`.

### Patch group A implementation status

Patch group A is implemented by `web/yaamp/commands/BadpoolGuardCommand.php` as a read-only Yii console command. This repository's command convention routes command classes through `yaamp/yiic.php` from the `web` directory, not through `runconsole.php`, which is reserved for thread controller routes such as `cronjob/run`.

Implemented read-only syntax:

```text
cd web
php yaamp/yiic.php badpoolguard overview --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard blocks-preview --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard earnings-preview --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard account-credit-preview --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard payout-candidates-preview --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard payout-row-preflight-preview --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard payout-row-dryrun-plan --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard payable-source-reconciliation-preview --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard account-credit-transition-preview --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard earnings-credit-readiness-preview --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard block-category-maturity-preview --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard earnings-block-reconciliation-preview --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard maturity-source-verification-preview --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard forward-catchup-preview --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard forward-catchup-approval-package --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard safety-scan --coin-id=<coin-id>
```

On the VPS layout, run these from `/srv/badpool/yiimp-badpool/web`. `yaamp/yiic.php` loads `serverconfig.php` through a relative path, so repository-root invocations with a `web/` prefix are not valid for that layout.

JSON is the default output format. `--format=text` is available for human review. All-coin preview is refused unless `--all-coins-preview` is explicitly supplied. Patch group A does not add execute/apply behavior, wallet reads, wallet sends, DB mutations, share deletion, payout row creation, payout retry/delete, service inspection, or cron/service start behavior.

### Patch group B implementation status

Patch group B adds shared dry-run plumbing in:

- `web/yaamp/core/backend/BadpoolGuardContext.php`
- `web/yaamp/core/backend/BadpoolGuardReport.php`

`BadpoolGuardContext` centralizes read-only option parsing, dangerous-option refusal, coin scope validation, safety metadata, warnings/errors, and SELECT-only query helpers. `BadpoolGuardReport` centralizes JSON and text rendering. Existing preview commands remain compatible, and the metadata-only context report is available as:

```text
cd web
php yaamp/yiic.php badpoolguard guard-context --coin-id=<coin-id>
```

No execute/apply mode exists yet. Commands remain read-only previews with no wallet reads by default, no wallet sends, no DB mutations, no share deletion, no payout retry/delete, and no service or cron actions.

### Patch group C implementation status

Patch group C adds a source-level wallet-send hard guard in:

- `web/yaamp/core/rpc/wallet-send-guard.php`
- `web/yaamp/core/rpc/wallet-rpc.php`

`WalletRPC::__call()` now refuses wallet-send methods before they reach Bitcoin, Ethereum, or CryptoNote RPC clients. The raw JSON and parsed console paths in `WalletRPC::execute()` use the same refusal helper so console-style wallet sends cannot bypass the guard. Guarded methods include `sendmany`, `sendtoaddress`, `walletpassphrase`, CryptoNote `transfer` wrappers, and other known send-style RPC verbs.

This implementation has no source-level activation path and does not add execute/apply behavior. Future wallet-send enablement must be designed and approved in a separate task with explicit command context, coin scope, report checksum validation, and production approval. Read-only preview commands continue to report `wallet_reads=false` and `wallet_sends=false`.

### Patch group D implementation status

Patch group D adds a source-level share-delete hard guard in:

- `web/yaamp/core/backend/BadpoolShareDeleteGuard.php`
- `web/yaamp/core/backend/blocks.php`
- `web/yaamp/core/backend/system.php`

`BackendBlockNew()` now routes its historical share cleanup point through `badpool_share_delete_guard_skip()` instead of issuing share deletion SQL. The guard counts matching share rows with a SELECT-only query when possible, logs an explicit skipped-deletion message, and returns without deleting shares. Historical share evidence is therefore preserved during backend/accounting restoration planning.

Backend database cleanup share-deletion paths in `system.php` also route through the same guard. Old-share consolidation is skipped while share deletion is disabled, so the cleanup path does not create aggregate share rows without removing originals.

Other command/model cleanup paths that previously deleted from `shares` also call the same guard. They log skipped share deletion while leaving their non-share cleanup behavior unchanged.

This implementation has no source-level activation path and does not add execute/apply behavior. Future share deletion enablement must be designed and approved in a separate task with explicit command context, coin/algo scope, preflight candidate counts, report checksum validation, production approval, and service-state review. Backend/payment services remain frozen unless separately approved.

Read-only preview commands continue to report `share_deletion=false`. `overview`, `blocks-preview`, and `safety-scan` now include `share_delete_guard` metadata with a guarded status and SELECT-only share candidate summary.

### Patch group E implementation status

Patch group E improves the existing read-only payout candidate preview in `web/yaamp/commands/BadpoolGuardCommand.php`. The `payout-candidates-preview` command now builds payout candidates separately from any execution path and reports account, coin, threshold, fee, projected payout, projected remaining balance, and blocked execution metadata.

Implemented read-only syntax:

```text
cd /srv/badpool/yiimp-badpool/web
php yaamp/yiic.php badpoolguard payout-candidates-preview --coin-id=<coin-id>
```

The preview uses SELECT-only account and coin queries. It does not call `BackendPayments()`, does not call `BackendCoinPayments()`, does not instantiate `WalletRPC`, does not call wallet RPC, does not create payout rows, does not debit accounts, does not retry/delete payouts, and does not send coins. Wallet-send remains blocked by the hard guard. Future payout execution requires a separate explicit approval task with report checksum validation, coin scope, account-debit separation, payout-row approval, and wallet-send approval.

### Patch group F implementation status

Patch group F adds deterministic checksum metadata for read-only preview reports in:

- `web/yaamp/core/backend/BadpoolGuardReport.php`
- `web/yaamp/core/backend/BadpoolGuardContext.php`
- `web/yaamp/commands/BadpoolGuardCommand.php`

`BadpoolGuardContext::finalizeReport()` adds a top-level `report_checksum` object to rendered JSON/text reports. The checksum uses SHA-256 over canonicalized report content with stable object key ordering and excludes volatile `generated_at` and `report_checksum` fields. This checksum is for preview audit comparison only and is not payout authorization.

`payout-candidates-preview` also includes `summary.audit` fields for command, coin id, coin symbol/algo, candidate count, projected total payout amount, and blocked actions. It still does not create payout rows, debit accounts, call wallet RPC, send coins, add execute/apply flags, or change production payment behavior.

### Patch group G implementation status

Patch group G is documented in `docs/badpool-future-payout-execution-design.md`. It is a specification-only step for future payout execution review and adds no execution capability.

The design requires future payout work to remain one coin at a time and one stage at a time. Candidate preview, payout row creation, account debit, wallet send, and post-send verification must be separately approved, logged, and verified. The preview checksum remains an audit comparison input only, not automatic authorization.

Wallet-send and share-delete hard guards remain active. Any future payout execution implementation still requires a separate PR, separate code review, separate validation, and separate operator approval.

### Patch group H implementation status

Patch group H adds read-only payout-row creation preflight scaffolding to `web/yaamp/commands/BadpoolGuardCommand.php`:

```text
cd /srv/badpool/yiimp-badpool/web
php yaamp/yiic.php badpoolguard payout-row-preflight-preview --coin-id=<coin-id>
```

The preflight preview reports selected coin, required preview checksum input, candidate count, projected payout total, payout threshold used, backup status, mutation-log status, stage status, and blocked action metadata. It does not create payout rows, debit accounts, call wallet RPC, authorize execution, or change production payment behavior.

Future payout-row creation still requires a separate approved PR, separate validation, and separate operator action.

### Patch group I implementation status

Patch group I adds a read-only payout-row dry-run plan preview to `web/yaamp/commands/BadpoolGuardCommand.php`:

```text
cd /srv/badpool/yiimp-badpool/web
php yaamp/yiic.php badpoolguard payout-row-dryrun-plan --coin-id=<coin-id>
```

The dry-run plan reports selected coin, coin symbol/algo, candidate count, projected payout total, threshold used, required source preview checksum input, proposed payout-row stage name, proposed mutation-log and backup/snapshot status, idempotency/rerun status, blocked row creation, blocked account debit, blocked wallet send, and a blocked post-execution verification checklist.

This plan is read-only. It does not create payout rows, debit accounts, call wallet RPC, write logs, write backups, authorize execution, or change production payment behavior. Future payout-row creation still requires a separate approved PR, separate validation, and separate operator action.

### Patch group J implementation status

Patch group J is documented in `docs/badpool-payout-row-approval-package.md`. It defines the approval package required before any future payout-row creation implementation or mutation-capable task is proposed.

The package requires the latest candidate, preflight, and dry-run plan report paths and checksums; coin ID and algorithm; candidate count; projected payout total; threshold used; operator approval text; backup and mutation-log paths; expected payout-row count; expected account debit total; STOP conditions; rerun/idempotency rule; active wallet-send and share-delete guards; inactive/disabled backend services; separate stage confirmation; and an explicit statement that the package does not authorize wallet sends.

This is documentation-only and adds no payout-row creation capability.

### Patch group K implementation status

Patch group K adds a read-only payable source reconciliation preview to `web/yaamp/commands/BadpoolGuardCommand.php`:

```text
cd /srv/badpool/yiimp-badpool/web
php yaamp/yiic.php badpoolguard payable-source-reconciliation-preview --coin-id=<coin-id> --format=json
```

Payout candidates require credited account balances. A zero candidate count does not prove there is no unpaid miner source data; it only means no already-credited balances currently meet payout candidate rules.

The reconciliation preview reports coin identity, positive account balances, payout candidate readiness, earnings grouped by status, blocks grouped by category, a payable-source state assessment, next required stage text, blocked action metadata, audit metadata, and a report checksum. It is SELECT-only and does not create payout rows, debit accounts, call wallet RPC, mutate earnings/blocks/coins, delete shares, or restore backend services.

Use this preview to decide whether the next safe review is account-credit/backlog processing or payout-row approval work. Payout-row creation still requires a separate approved PR and operator action.

### Patch group L implementation status

Patch group L adds a read-only account-credit transition preview to `web/yaamp/commands/BadpoolGuardCommand.php`:

```text
cd /srv/badpool/yiimp-badpool/web
php yaamp/yiic.php badpoolguard account-credit-transition-preview --coin-id=<coin-id> --format=json
```

Payout rows require positive credited account balances. Payable source data may exist in earnings or block backlog before account balances are credited, so a zero payout candidate count is not enough to approve payout-row creation.

The transition preview reports coin identity, current account balance state, earnings grouped by status, uncredited and credit-ready earnings totals when distinguishable, block categories needing accounting inspection, proposed future transition stages, blocked action metadata, audit metadata, and a report checksum. It is SELECT-only and does not credit accounts, change earnings or blocks, create payout rows, call wallet RPC, delete shares, or restore backend services.

Any future account-credit mutation remains a separate approved PR, separate validation step, and separate operator action.

### Patch group M implementation status

Patch group M adds a read-only earnings credit-readiness inspection preview to `web/yaamp/commands/BadpoolGuardCommand.php`:

```text
cd /srv/badpool/yiimp-badpool/web
php yaamp/yiic.php badpoolguard earnings-credit-readiness-preview --coin-id=<coin-id> --format=json
```

Status 0 earnings are payable source backlog, not automatically credit-ready. Credit-readiness requires block/category/linkage inspection before any future account-credit mutation can be considered.

The readiness preview reports coin identity, earnings grouped by status, status 0 and status 1 readiness signals, account/user and block linkage, block categories, amount polarity, duplicate-risk hints, readiness classification totals, blockers, proposed future stages, audit metadata, and a report checksum. It is SELECT-only and does not change earnings status, credit accounts, change blocks or coins, create payout rows, call wallet RPC, delete shares, or restore backend services.

Any future account-credit mutation remains a separate approved PR, separate validation step, and separate operator action.

### Patch group N implementation status

Patch group N adds a read-only frozen block category and maturity-state inspection preview to `web/yaamp/commands/BadpoolGuardCommand.php`:

```text
cd /srv/badpool/yiimp-badpool/web
php yaamp/yiic.php badpoolguard block-category-maturity-preview --coin-id=<coin-id> --format=json
```

Immature or new block categories may be stale while backend block/accounting updaters remain frozen. This preview reports service/update freeze assumptions, coin maturity reference fields, blocks grouped by category, status 0 and status 1 earnings linked to blocks, height/time maturity signals where derivable, stale/frozen indicators, conservative classification totals, blockers, proposed future stages, audit metadata, and a report checksum.

The preview is SELECT-only. It does not run backend accounting, mature blocks, change block categories, change earnings status, credit accounts, change coins, create payout rows, call wallet RPC, delete shares, or restore backend services.

Any future block category/status transition remains a separate approved PR, separate validation step, and separate operator action. Account-credit and payout-row work remain blocked until category/maturity questions are separately resolved and rechecked.

### Patch group O implementation status

Patch group O adds a read-only earnings-to-block maturity reconciliation preview to `web/yaamp/commands/BadpoolGuardCommand.php`:

```text
cd /srv/badpool/yiimp-badpool/web
php yaamp/yiic.php badpoolguard earnings-block-reconciliation-preview --coin-id=<coin-id> --format=json
```

Earnings rows and block rows are different accounting units. One block can have multiple earnings rows, so a larger earnings row count can reconcile to a smaller distinct linked block count.

The reconciliation preview reports coin identity, status 0 and status 1 earnings totals, block linkage, row-per-block distribution, linked block category distribution, row-count difference explanations, reconciliation classification, blockers, proposed future stages, blocked action metadata, audit metadata, and a report checksum.

The preview is SELECT-only. It does not run backend accounting, change block categories, change earnings status, credit accounts, change coins, create payout rows, call wallet RPC, delete shares, or restore backend services.

Any future category/status or account-credit transition remains blocked until earnings-row and block-row differences are reconciled and separately approved.

### Patch group P implementation status

Patch group P adds a read-only maturity threshold and current-height source verification preview to `web/yaamp/commands/BadpoolGuardCommand.php`:

```text
cd /srv/badpool/yiimp-badpool/web
php yaamp/yiic.php badpoolguard maturity-source-verification-preview --coin-id=<coin-id> --format=json
```

Maturity/category transition requires a reliable current-height source and a numeric maturity threshold. DB-only previews must not assume chain height when `coins.block_height` is stale, missing, null, or non-numeric.

The maturity source preview reports coin maturity/current-height fields, per-field usability, block table height evidence, linked immature block ranges for status 0/status 1 earnings, DB-only maturity delta counts when possible, source confidence, conservative decisions, blockers, proposed future stages, blocked action metadata, audit metadata, and a report checksum.

The preview is SELECT-only. It does not call daemon RPC, call wallet RPC, run backend accounting, mature blocks, change block categories, change earnings status, credit accounts, change coins, create payout rows, delete shares, or restore backend services.

Any future category/status or account-credit transition remains blocked until current height, maturity threshold, and backend transition logic are separately verified and approved.

### Patch group Q implementation status

Patch group Q adds a read-only forward catch-up window preview to `web/yaamp/commands/BadpoolGuardCommand.php`:

```text
cd /srv/badpool/yiimp-badpool/web
php yaamp/yiic.php badpoolguard forward-catchup-preview --coin-id=<coin-id> --format=json
```

The forward catch-up preview maps the post-payout checkpoint window for one coin. It reports coin identity, the latest `payouts.MAX(time)` checkpoint, post-checkpoint blocks grouped by category, import candidates for incomplete `new` blocks, a fixed-size read-only daemon sample for oldest import candidates, projected future stages, conservative safety classification, blocked action metadata, audit metadata, and a report checksum.

The daemon sample only reads `getblock` and `gettransaction` through the guarded wallet RPC wrapper. It does not add an apply path, does not create payout rows, does not change account balances, does not change block or earnings rows, does not send coins, does not run backend loops, and does not create an approval package command.

Any future forward catch-up execution remains blocked until a separate approval package and separate implementation task are reviewed and approved.

### Patch group R implementation status

Patch group R adds a read-only forward catch-up Stage 1 approval package command to `web/yaamp/commands/BadpoolGuardCommand.php`:

```text
cd /srv/badpool/yiimp-badpool/web
php yaamp/yiic.php badpoolguard forward-catchup-approval-package --coin-id=<coin-id> --format=json
```

The approval package is for Stage 1 review only: importing post-checkpoint `category='new'` blocks, populating `txhash`, `amount`, and `confirmations`, choosing a daemon-derived or safe intermediate category, and planning projected earnings rows according to existing backend block-new-equivalent logic. The command reports these as blocked future intent only and does not execute them.

The package reuses the forward-catch-up preview data model and reports coin identity, latest payout checkpoint, preview dependency checksums, approval package identity, proposed single-coin mutation scope, candidate summary, first batch plan, daemon sample summary, exact blocked future apply intent, blocked later stages, safety metadata, `approval_input_checksum`, `intended_mutation_scope_checksum`, and a report checksum.

The command is read-only. It does not add an apply command, does not accept execute flags, does not create payout rows, does not change account balances, does not change block or earnings rows, does not send coins, does not run backend loops, and does not change services or cron. Future Stage 1 apply design or preflight remains a separate approved PR and operator action.

### Read-only preview commands

```text
yiimp badpoolguard preview-block-new --coin-id=<coin-id> --format=json
yiimp badpoolguard preview-block-update --coin-id=<coin-id> --format=json
yiimp badpoolguard preview-earnings-create --coin-id=<coin-id> --format=json
yiimp badpoolguard preview-account-credit --coin-id=<coin-id> --format=json
yiimp badpoolguard preview-payout-candidates --coin-id=<coin-id> --format=json
yiimp badpoolguard preview-payout-retry --coin-id=<coin-id> --format=json
yiimp badpoolguard preview-pool-balances --coin-id=<coin-id> --format=json
yiimp badpoolguard service-safety-check --format=json
```

### Explicit execute commands for later phases

Execution commands must be unavailable by default and require all relevant explicit flags:

```text
yiimp badpoolguard apply-block-update --coin-id=<coin-id> --execute --allow-block-updates --confirm=<token>
yiimp badpoolguard apply-earnings-create --coin-id=<coin-id> --execute --allow-earnings-create --confirm=<token>
yiimp badpoolguard apply-account-credit --coin-id=<coin-id> --execute --allow-account-credit --confirm=<token>
yiimp badpoolguard apply-payout-rows --coin-id=<coin-id> --execute --allow-payout-rows --confirm=<token>
yiimp badpoolguard apply-wallet-send --coin-id=<coin-id> --execute --allow-wallet-send --confirm=<token>
```

Do not add cron/service auto-start behavior. These commands are operator-invoked only.

## Affected functions

### Cron/service dispatch

- `CronjobController::actionRunBlocks()`
  - Current calls: `BackendBlockFind1()`, `BackendClearEarnings()`, `BackendBlocksUpdate()`.
  - Guardrail need: do not run this as a restoration tool until guarded versions exist.
- `CronjobController::actionRun()`
  - Current calls: `BackendBlocksUpdate()`, `BackendBlockFind2()`, `BackendUpdatePoolBalances()`, `BackendPayments()`.
  - Guardrail need: keep payment segment disabled/frozen until wallet-send and payout separation exist.
- `CronjobController::actionRunLoop2()`
  - Current calls include broad service/deposit/renting paths.
  - Guardrail need: keep outside miner-payout restoration unless separately reviewed.

### Block and earnings path

- `BackendBlockFind1($coinid = NULL)`
  - Preview: list `category='new'` block rows and planned category/amount/tx/confirmation changes.
  - Execute requirement: coin allowlist, block updates enabled, earnings creation mode decision, share-delete gate.
- `BackendBlockFind2($coinid = NULL)`
  - Preview: list wallet-discovered block candidates if wallet read RPC is allowed; otherwise DB-only preview should not call wallets.
  - Execute requirement: coin allowlist, wallet read approval, block inserts enabled, coin updates enabled, share-delete gate.
- `BackendBlockNew($coin, $db_block)`
  - Preview: report reward allocation by account, would-create earnings rows, would-update last earning, would-delete share counts and predicate.
  - Execute requirement: earnings create enabled. Share deletion remains independently disabled unless `BADPOOL_SHARE_DELETE_ENABLED=true` and `--allow-share-delete` are both present.
- `BackendBlocksUpdate($coinid = NULL)`
  - Preview: report planned block category transitions, confirmations, orphan/generate decisions, earnings maturity/deletion effects, and mature-block changes.
  - Execute requirement: block updates enabled, earnings mature enabled, coin updates enabled for mature-block writes.
- `BackendUpdatePoolBalances($coinid = NULL)`
  - Preview: report computed `immature`, `cleared`, `pending`, `available`, and wallet balance source.
  - Execute requirement: coin updates enabled; wallet reads should be optional and separately declared.

### Account credit path

- `BackendClearEarnings($coinid = NULL)`
  - Preview: report mature earnings eligible for clearing, conversion amount, target account, old/new balance, and skipped/invalid rows.
  - Execute requirement: account credit enabled and earnings mature/clear enabled. Must be coin-scoped.

### Payment path

- `BackendPayments()`
  - Preview: should delegate to guarded payout candidate builder only; should not iterate all coins in execute mode without allowlist.
  - Execute requirement: payout rows enabled for row creation; wallet-send remains separately disabled.
- `BackendCoinPayments($coin)`
  - Refactor target: split into candidate builder, payout-row creation/account debit, wallet-send, and retry handling.
  - Execute requirement: each phase has independent flags. Wallet-send cannot be implied by payout-row creation.
- `BackendUserCancelFailedPayment($userid)`
  - Preview: report failed payout rows and account refund effect.
  - Execute requirement: failed-payout mutation flag, account credit/debit flag, explicit user scope.

### Wallet send path

- `WalletRPC::__call()`
  - Hard guard send methods centrally: `sendmany`, `sendtoaddress`, CryptoNote `transfer`, and any method name matching known wallet-send verbs.
  - When disabled, return a structured guard error and do not forward to underlying RPC.
- Direct call sites in `BackendCoinPayments()`, `PayoutCommand::redoTransaction()`, market/renting/sell/trading paths
  - Add call-site checks too, so wallet sends are blocked before they reach RPC.

### Payout command path

- `PayoutCommand::checkPayouts($symbol, $fixit)`
  - Default preview-only. `fixit` should be rejected unless explicit mutation flags and confirmation are present.
- `PayoutCommand::redoTransaction($args)`
  - Must require wallet-send enabled, payout row enabled, coin allowlist, and confirmation token.
- `PayoutCommand::checkPayoutsConfirmations($args)`
  - Can remain read-oriented, but should label wallet RPC read calls and support structured output.

## Default-safe behavior

Default behavior for new guardrail commands:

1. No DB mutations.
2. No wallet sends.
3. No share deletion.
4. No payout retry/delete.
5. No payout rows created.
6. No account balance credit/debit.
7. No cron/service auto-start.
8. No all-coin execution unless an explicit allowlist is configured.
9. No secrets in output. Redact RPC usernames, passwords, tokens, deposit secrets, and environment values.
10. Structured reports go to stdout by default and only write to an operator-provided local path when requested.

## What each preview mode reports

### Block/update preview

Report fields:

- `coin_id`, `symbol`, `algo`
- block `id`, `height`, `blockhash` fingerprint, `txhash` fingerprint
- current `category`, proposed `category`
- current/proposed `amount`, `confirmations`, `price`
- whether wallet read RPC was used
- duplicate/orphan/stake/generate decision
- proposed `blocks` writes
- proposed `coins.lastblock`, `coins.mature_blocks`, `coins.hasmasternodes`, or pool-balance writes

### Earnings creation preview

Report fields:

- block ID and reward amount
- share selection predicate
- total valid share difficulty
- per-user hash share and calculated earning
- fee/donation deductions
- target `earnings` row fields
- target `accounts.last_earning` change
- would-delete share predicate and estimated row count
- share deletion disabled/enabled state

### Earnings maturity preview

Report fields:

- block ID and old/new block category
- affected earnings count and sum by status
- rows that would become `status=1`
- rows that would be deleted for orphan/non-immature outcomes
- proposed mature time

### Account-credit preview

Report fields:

- earning ID, account ID, coin ID
- earning amount and price
- user payout coin and conversion rate/result
- current account balance and proposed account balance
- earning old/new status and price
- skipped rows with reason

### Pool-balance preview

Report fields:

- coin ID and symbol
- wallet balance source: DB-only or wallet-read
- `blocks` immature sum
- `accounts` cleared sum
- `earnings` pending sum
- proposed `coins.immature`, `coins.cleared`, `coins.available`, `coins.balance`

### Payout candidate preview

Report fields:

- coin ID/symbol
- payment thresholds: `YAAMP_PAYMENTS_MINI`, `coin.payout_min`, `coin.txfee`, Sunday reduction result
- wallet balance if wallet read allowed, otherwise DB-only candidate warning
- eligible accounts count
- per-account current balance and proposed payout amount
- proposed address fingerprint, not full sensitive payload when unnecessary
- total candidate amount
- whether payout rows would be created
- whether account balances would be debited
- whether wallet-send is disabled/enabled

### Payout retry/delete preview

Report fields:

- failed payout rows with empty tx
- candidate delete/recreate rows
- proposed refund or debit effect
- retry send candidates
- disabled/enabled state for retry delete and wallet-send

### Wallet-send preview

Report fields:

- coin ID/symbol
- method that would be used: `sendmany`, `sendtoaddress`, CryptoNote `transfer`
- destination count
- total amount
- fee/message/account parameters, redacted where needed
- wallet-send disabled/enabled state
- confirmation token required for execution

### Service/process safety preview

Report fields:

- configured service names to inspect, for example `badpool-blocks.service`, `badpool-loop2.service`, and any pool-specific main loop unit
- expected safe state: inactive/disabled for mutation-restoration execute mode
- command examples for L3 operators to run manually on the VPS
- result placeholders; repository commands must not inspect live services

## What each execute mode would require

Every execute mode must require:

1. `--execute`.
2. Explicit `--coin-id` or a non-empty configured allowlist.
3. A stage-specific `--allow-*` flag.
4. A confirmation token or exact phrase.
5. Structured preflight report generated in the same invocation or referenced by checksum.
6. Service/process safety assertion when applicable.
7. Wallet-send still disabled unless the stage is specifically wallet send and `--allow-wallet-send` is present.

Stage-specific examples:

- Block update execution requires `--allow-block-updates` and must not imply earnings creation or share deletion.
- Earnings creation execution requires `--allow-earnings-create` and must not imply share deletion.
- Share deletion execution requires `--allow-share-delete` and must be a separate final cleanup phase.
- Account credit execution requires `--allow-account-credit` and must not imply payout row creation.
- Payout row execution requires `--allow-payout-rows` and must not imply wallet-send.
- Wallet-send execution requires `--allow-wallet-send`, payout candidate checksum, coin allowlist, and confirmation.
- Payout retry/delete requires `--allow-payout-retry-delete` and must not imply wallet-send.

## Wallet-send disabled unless explicitly enabled

Wallet-send safety must be enforced in two layers:

1. **Central guard in `WalletRPC`:** block `sendmany`, `sendtoaddress`, CryptoNote `transfer`, and compatible send verbs unless `BADPOOL_WALLET_SENDS_ENABLED=true` and the current command context permits wallet-send.
2. **Call-site guard:** payment, payout command, market, renting, sell, and trading code should check wallet-send permission before preparing or invoking send calls.

Failure mode should be safe and loud:

```json
{
  "stage": "wallet_send",
  "status": "blocked",
  "reason": "BADPOOL_WALLET_SENDS_ENABLED is false",
  "method": "sendmany",
  "coin_id": "<coin-id>"
}
```

No send method should be enabled by `YAAMP_PRODUCTION` alone.

## Share deletion disabled unless explicitly enabled

`BackendBlockNew()` currently couples earnings creation with `DELETE FROM shares`. The guarded design must split this into separate phases:

1. Calculate earnings and report share-delete predicate/count.
2. Optionally create earnings rows.
3. Leave shares untouched by default.
4. Run share deletion only under a separate cleanup command with `--allow-share-delete`, coin scope, time predicate, preflight count, and confirmation token.

Default preview output should make it obvious when shares would have been deleted by legacy behavior.

## Payout retry/delete disabled unless explicitly enabled

Payout retry handling currently deletes failed payout rows, recreates replacement payout rows, and may retry `sendmany()`. The guarded design must split it into:

1. Failed payout inventory preview.
2. Optional payout-row cleanup/recreate execution with `--allow-payout-retry-delete`.
3. Optional wallet retry execution with `--allow-wallet-send` in a separate stage.

A retry/delete flag must not enable wallet-send. A wallet-send flag must not enable retry/delete.

## Structured report output

Reports should be deterministic enough to review and compare across runs.

Recommended common envelope:

```json
{
  "schema": "badpool.guardrail.report.v1",
  "generated_at": "UTC timestamp",
  "repository_commit": "git sha",
  "command": "preview-payout-candidates",
  "mode": "dry-run",
  "coin_scope": ["<coin-id>"],
  "wallet_reads": false,
  "wallet_sends_enabled": false,
  "share_delete_enabled": false,
  "payout_retry_delete_enabled": false,
  "summary": {},
  "items": [],
  "warnings": [],
  "blocked_actions": []
}
```

Do not include secrets. Hash or fingerprint addresses and transaction hashes where full values are not required for operator review.

## Safety checks for service/process state

Repository code cannot inspect the VPS in this task, but later implementation should provide a read-only `service-safety-check` command or operator checklist that reports expected systemd/process states.

For execute modes that mutate accounting or payment state, require an L3-provided assertion that the relevant services remain inactive/disabled, including at minimum:

- `badpool-blocks.service`
- `badpool-loop2.service`
- any `main.sh` / `cronjob/run` service unit used on the live server
- any supervisor/process-manager entry that can invoke the same loops

The source guard should not start, stop, enable, or disable services. It should only consume an operator-provided assertion or print manual read-only commands for L3.

## Staged restoration order

1. **Patch group A: read-only preview commands**
   - Add new guard command with DB-only previews first.
   - Produce structured reports for the approved coin scope.
   - No wallet reads, sends, DB writes, or share deletion.

2. **Patch group B: dry-run plumbing**
   - Add a shared guard context object for dry-run, coin allowlist, structured report collector, and explicit stage permissions.
   - Wire preview logic into guarded helper methods without changing cron defaults.

3. **Patch group C: wallet-send hard guard**
   - Add central `WalletRPC` send-method guard.
   - Add call-site checks in `BackendCoinPayments()`, `PayoutCommand::redoTransaction()`, market/renting/sell/trading send paths.
   - Default remains blocked.

4. **Patch group D: share-delete hard guard**
   - Split or guard `BackendBlockNew()` share deletion.
   - Report would-delete shares by predicate/count.
   - Default remains blocked.

5. **Patch group E: payout preview and execute separation**
   - Refactor `BackendCoinPayments()` into candidate calculation, payout-row/account-debit execution, wallet-send execution, and reconciliation.
   - Add payout retry/delete preview and separate execute gate.

6. **Patch group F: structured reports**
   - Stabilize report schemas, add JSON/JSONL/CSV output, and add diff-friendly summary sections.
   - Add report checksum support for execute confirmations.

7. **DB-only preview on live server by L3**
   - L3 runs preview commands with services frozen and no wallet reads.
   - Compare report totals against known block and earnings backlog expectations.

8. **Wallet-read-only preview**
   - If approved, enable wallet read-only preview for block/update checks.
   - Still no wallet sends.

9. **Apply block/earnings accounting in small scoped phases**
   - Start with a small coin-ID scoped batch.
   - Keep share deletion disabled.
   - Validate reports before and after each batch.

10. **Apply account-credit phase**
    - Credit accounts only after earnings reports reconcile.
    - Keep payout row creation disabled.

11. **Payout candidate preview**
    - Produce payout candidates after account credits reconcile.
    - Do not create payout rows or send wallets.

12. **Payout row creation phase**
    - Create payout rows only after candidate report approval.
    - Wallet sends remain disabled.

13. **Wallet-send phase**
    - Last phase only.
    - Requires explicit production approval, coin allowlist, report checksum, wallet-send flag, and confirmation token.

14. **Share cleanup phase**
    - Only after all accounting has been validated and backups/snapshots are confirmed.
    - Requires separate share-delete approval.

## Open questions requiring VPS/runtime inspection

These must be answered by L3 using read-only live-server checks, not by repository-only Codex tasks:

1. What exact systemd/supervisor units invoke `web/blocks.sh`, `web/main.sh`, and `web/loop2.sh`?
2. Is there a separate live service for `main.sh` / `cronjob/run`, and is it inactive/disabled?
3. Are there any manually running PHP backend loops outside systemd?
4. What is the current live value of `YAAMP_PRODUCTION`, `YAAMP_ALLOW_EXCHANGE`, `YAAMP_PAYMENTS_FREQ`, and payment thresholds, without revealing secrets?
5. Which production coin IDs and symbols are approved for scoped preview and staged restoration?
6. Are wallet RPCs available for read-only calls, and can send methods be blocked at wallet daemon level during previews?
7. What backup/snapshot exists for `blocks`, `earnings`, `accounts`, `payouts`, `withdraws`, `shares`, and `coins` before any execute mode?
8. Are there database triggers on affected tables that could mutate additional state?
9. Are exchange/trading services disabled, and can they create `withdraws` during restoration?
10. Which rows in `blocks.category='new'` for approved coin scope have matching share windows available?
11. Do uncleared earnings rows overlap with pending block rows or represent a different backlog segment?
12. Are there existing failed payouts with empty `tx` that would be touched by retry/delete logic?
13. Are there any production-specific patches not present in this repository branch?

## Small patch proposal for later implementation

### Patch group A: read-only preview commands

- Add `web/yaamp/commands/BadpoolGuardCommand.php`.
- Implement DB-only preview subcommands:
  - `preview-block-new`
  - `preview-block-update`
  - `preview-earnings-create`
  - `preview-account-credit`
  - `preview-payout-candidates`
  - `preview-payout-retry`
  - `preview-pool-balances`
  - `service-safety-check`
- Add unit-like smoke tests or syntax checks.
- No mutations and no wallet sends.

### Patch group B: dry-run plumbing

- Add a guard context/helper, for example `web/yaamp/core/backend/badpool_guard.php`.
- Provide helpers:
  - `badpool_guard_is_dry_run()`
  - `badpool_guard_coin_allowed($coin)`
  - `badpool_guard_can_mutate($stage)`
  - `badpool_guard_report($event)`
  - `badpool_guard_blocked($stage, $reason, $metadata)`
- Keep existing cron behavior unchanged initially, except where hard send/delete guards are intentionally added in later groups.

### Patch group C: wallet-send hard guard

- Add central guard in `WalletRPC::__call()` for send methods.
- Guard raw JSON and parsed console execution paths that could otherwise call send methods directly.
- Default behavior: blocked with an explicit logged refusal.
- Do not add an activation path in this patch; any future wallet-send activation must be separately approved.
- Leave call-site separation for later payout execution work, after read-only previews and report checksums are established.

### Patch group D: share-delete hard guard

- Guard the `DELETE FROM shares` statements in `BackendBlockNew()`.
- Add a preview count before skipped deletion.
- Default behavior: report-only; no deletion.
- Do not add an activation path in this patch; any future share deletion cleanup command must be separately approved.

### Patch group E: payout preview and execute separation

- Keep payout candidate calculation separate from `BackendCoinPayments()` execution.
- Report account, coin, threshold, fee, projected payout, projected remaining balance, and blocked action metadata.
- Do not add payout-row execution, account debit, wallet RPC, retry/delete, execute/apply flags, or wallet-send behavior in this patch.
- Leave payout-row execution, wallet-send execution, and retry/delete separation for separately approved future work.

### Patch group F: structured reports

- Define `badpool.guardrail.report.v1` schema.
- Add output adapters for table, JSON, JSONL, and CSV.
- Add report checksum generation for execute gating.
- Add redaction helpers for secrets and optional address/tx fingerprints.
- Current Patch F implementation adds checksum metadata for preview audit comparison only; execution gating remains future work.

### Patch group H: payout-row preflight scaffolding

- Add a read-only payout-row preflight preview section or command.
- Report selected coin, required preview checksum input, candidate count, projected payout total, payout threshold used, backup status, mutation-log status, stage status, and blocked action metadata.
- Do not add payout-row creation, account debit, wallet RPC calls, retry/delete behavior, activation flags, service changes, or backend loop restoration.
- Future payout-row creation remains a separate approved PR and operator action.

### Patch group I: payout-row dry-run plan preview

- Add a read-only payout-row dry-run plan command.
- Require explicit coin scope and refuse all-coin scope.
- Report selected coin, coin symbol/algo, candidate count, projected payout total, threshold used, required source preview checksum input, proposed stage name, proposed mutation-log and backup/snapshot status, idempotency/rerun status, blocked row creation, blocked account debit, blocked wallet send, and blocked post-execution verification checklist.
- Do not create payout rows, debit accounts, call wallet RPC, write logs, write backups, retry/delete payouts, add activation flags, change services, or restore backend loops.
- Future payout-row creation remains a separate approved PR and operator action.

### Patch group J: payout-row approval package

- Add a documentation-only approval package checklist for future payout-row creation review.
- Require latest candidate, preflight, and dry-run plan report paths and checksums.
- Require coin scope, candidate count, projected total, threshold, explicit operator approval text, backup path, mutation log path, expected payout-row count, expected account debit total, STOP conditions, and rerun/idempotency rule.
- Require confirmation that wallet-send and share-delete guards remain active, `badpool-blocks.service` and `badpool-loop2.service` remain inactive/disabled, and payout-row creation, account debit, wallet send, and post-send verification remain separate stages.
- State explicitly that the package does not authorize wallet sends.
- Do not add code, runtime behavior, service behavior, or deployment behavior.

### Patch group K: payable source reconciliation preview

- Add a read-only command that bridges blocks, earnings, account credit readiness, account balances, and payout candidate readiness.
- Require explicit coin scope and refuse all-coin scope.
- Report whether payable data appears already credited to accounts, present in earnings but not credited, present in block data needing accounting, absent, or indeterminate.
- Report next required stage text only, such as account-credit preview needed, backend block accounting still frozen, or no payout rows should be created until account balances exist.
- Do not add payout-row creation, account debit, wallet RPC calls, earnings/block/coin mutation, share deletion, retry/delete behavior, service changes, or backend loop restoration.

### Patch group L: account-credit transition preview

- Add a read-only command that explains the account-credit/backlog transition needed before payout-row creation can be considered.
- Require explicit coin scope and refuse all-coin scope.
- Report account count, positive account count, total positive balance, earnings grouped by status, uncredited and credit-ready earnings totals, block categories needing accounting inspection, future transition stages, blocked action metadata, audit metadata, and checksum metadata.
- State why payout rows remain blocked until positive credited account balances exist.
- Do not add account credit mutation, earnings/block/coin mutation, payout-row creation, account debit, wallet RPC calls, share deletion, retry/delete behavior, service changes, or backend loop restoration.
- Future account-credit mutation remains a separate approved PR and operator action.

### Patch group M: earnings credit-readiness inspection preview

- Add a read-only command that explains why status 0 earnings and related block backlog are not automatically credit-ready.
- Require explicit coin scope and refuse all-coin scope.
- Report earnings grouped by status, status 0 and status 1 readiness signals, account/user presence, coin/algo scope match, block linkage, matching block categories, amount positive/non-positive counts, duplicate-risk hints, readiness classification totals, blockers, future stages, audit metadata, and checksum metadata.
- Report blockers such as immature/new block backlog, orphan risk, status not credit-ready, missing linkage, duplicate or previous-credit uncertainty, and schema limitations.
- Do not add account credit mutation, earnings/block/coin mutation, payout-row creation, account debit, wallet RPC calls, share deletion, retry/delete behavior, service changes, or backend loop restoration.
- Future account-credit mutation remains a separate approved PR and operator action.

### Patch group N: frozen block category and maturity-state preview

- Add a read-only command that inspects whether block categories and maturity state appear frozen or stale while backend updaters remain frozen.
- Require explicit coin scope and refuse all-coin scope.
- Report service/update freeze assumptions, coin height and maturity reference fields, blocks grouped by category, status 0/status 1 earnings linked to blocks, height/time maturity signals where derivable, stale category indicators, conservative classification totals, blockers, future stages, audit metadata, and checksum metadata.
- State that immature/new categories can be stale DB state, but that the preview does not prove chain state or service state.
- Do not run backend accounting, mature blocks, change block categories, change earnings status, credit accounts, mutate coins, create payout rows, call wallet RPC, delete shares, retry/delete payouts, change services, or restore backend loops.
- Future block category/status transition remains a separate approved PR and operator action.

### Patch group O: earnings-to-block maturity reconciliation preview

- Add a read-only command that reconciles status 0/status 1 earnings rows to linked block rows.
- Require explicit coin scope and refuse all-coin scope.
- Report earnings row totals, amount sums, distinct blockid counts, block linkage, row-per-block distribution, linked block category distribution, row-count difference explanation, reconciliation classification, blockers, future stages, audit metadata, and checksum metadata.
- Explain that earnings rows and block rows are different units, and one block can have multiple earnings rows.
- State that row-count differences must be reconciled before any category/status or account-credit transition.
- Do not run backend accounting, change block categories, change earnings status, credit accounts, mutate blocks/earnings/accounts/coins, create payout rows, call wallet RPC, delete shares, retry/delete payouts, change services, or restore backend loops.
- Future category/status and account-credit transition remains a separate approved PR and operator action.

### Patch group P: maturity threshold and current-height source verification preview

- Add a read-only command that verifies which DB fields can safely inform maturity threshold and current-height review before any future category/status or account-credit transition.
- Require explicit coin scope and refuse all-coin scope.
- Report coin maturity/current-height fields, field presence/null/numeric/usability classification, block table height evidence, linked immature block ranges, DB-only height-delta counts, source confidence, conservative decisions, blockers, future stages, audit metadata, and checksum metadata.
- State that DB-only previews must not assume chain height when `coins.block_height` is stale, missing, null, or non-numeric.
- Do not call daemon RPC, call wallet RPC, run backend accounting, mature blocks, change block categories, change earnings status, credit accounts, mutate blocks/earnings/accounts/coins, create payout rows, delete shares, retry/delete payouts, change services, or restore backend loops.
- Future category/status and account-credit transition remains a separate approved PR and operator action.

### Patch group Q: forward catch-up window preview

- Add a read-only command that maps the forward catch-up window from the latest payout checkpoint for one coin.
- Require explicit coin scope and refuse all-coin scope.
- Report coin identity, `payouts.MAX(time)` checkpoint data, post-checkpoint blocks grouped by category, incomplete `new` block import candidates, fixed-size daemon read sample, projected future stages, safety classification, audit metadata, and checksum metadata.
- Use daemon reads only for oldest import candidates, limited to `getblock` and `gettransaction` through the guarded wallet RPC wrapper.
- Do not add an apply command, approval package command, payout-row creation, account-balance change, block/earnings mutation, coin mutation, backend loop execution, wallet send, share deletion, retry/delete payout path, process change, or schedule change.
- Future forward catch-up execution remains a separate approved PR and operator action.

### Patch group R: forward catch-up Stage 1 approval package

- Add a read-only command that generates an auditable approval package for future Stage 1 forward catch-up import review.
- Require explicit coin scope and refuse all-coin scope.
- Reuse the forward-catch-up preview data model and report dependency checksums, approval identity, mutation scope, candidate summary, first batch plan, daemon sample summary, exact blocked future apply intent, blocked later stages, safety metadata, and approval checksums.
- Scope Stage 1 to post-checkpoint `category='new'` blocks with blockhash, userid, workerid, and no linked earnings rows.
- Keep future intent blocked: populate `txhash`, `amount`, and `confirmations`; transition category according to daemon-derived or safe intermediate rules; and plan projected earnings rows without creating them.
- Do not add an apply command, execute flag, DB write, payout-row creation, account-balance change, block/earnings mutation, backend loop execution, wallet send, service change, cron change, or approval of later payout stages.
- Future Stage 1 apply design or preflight remains a separate approved PR and operator action.

## Non-goals for this plan

- No production deployment.
- No service start/enable behavior.
- No wallet send code.
- No default DB mutations.
- No removal of existing production code.
- No secret handling beyond redaction guidance.
