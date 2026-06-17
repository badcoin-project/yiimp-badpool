# BadPool Read-Only Preview Commands

Patch group A adds a guarded Yii console command for DB-only preview reports. Patch group B adds shared guard context/report plumbing used by the same commands. Patch group C adds a source-level wallet-send hard guard. Patch group D adds a source-level share-delete hard guard. Patch group E improves read-only payout candidate preview reporting. Patch group F adds deterministic preview checksum metadata. Patch group G defines the future payout execution design. Patch group H adds read-only payout-row preflight scaffolding. Patch group I adds a read-only payout-row dry-run plan preview. Patch group J defines the payout-row approval package checklist. Patch group K adds a read-only payable source reconciliation preview. Patch group L adds a read-only account-credit transition preview for earnings/block backlog review. Patch group M adds a read-only earnings credit-readiness inspection preview. Patch group N adds a read-only block category maturity preview. Patch group O adds a read-only earnings-to-block reconciliation preview. Patch group P adds a read-only maturity source verification preview. These guards, previews, and docs do not add execute/apply behavior or any activation path.

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
php yaamp/yiic.php badpoolguard safety-scan --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard guard-context --coin-id=<coin-id>
```

On the VPS layout, run these from `/srv/badpool/yiimp-badpool/web`. `yaamp/yiic.php` loads `serverconfig.php` through a relative path, so repository-root invocations with a `web/` prefix are not valid for that layout.

JSON is the default output format. Add `--format=text` for a human-readable report.

All-coin preview is refused by default. To intentionally preview across all coins, pass `--all-coins-preview` instead of `--coin-id=<coin-id>`.

```text
cd web
php yaamp/yiic.php badpoolguard overview --all-coins-preview
```

These commands are read-only. They use SELECT-only database queries and are for review before any L3/live apply work.

`payout-candidates-preview` reports account/coin payout candidates, threshold inputs, projected payout amounts, projected remaining balances, and blocked execution metadata. It does not create payout rows, does not debit accounts, does not call wallet RPC, and does not send coins.

Payout candidates require already credited positive account balances. A zero candidate count means there are no already-credited balances currently above the payout threshold; it does not prove that unpaid miner source data is absent from blocks or earnings.

`payout-row-preflight-preview` reports the read-only preflight fields that a later payout-row creation task would require: selected coin, required preview checksum input, candidate count, projected payout total, payout threshold used, backup status, mutation-log status, stage status, and blocked action metadata. It does not create payout rows, does not debit accounts, does not call wallet RPC, does not authorize execution, and does not perform any database mutation.

`payout-row-dryrun-plan` reports a read-only plan for future payout-row creation: selected coin, coin symbol/algo, candidate count, projected payout total, threshold used, required source preview checksum input, proposed stage name, proposed mutation-log and backup/snapshot status, idempotency/rerun status, blocked row creation, blocked account debit, blocked wallet send, and a blocked post-execution verification checklist. It plans future row creation but does not create rows, debit accounts, call wallet RPC, write logs, write backups, authorize execution, or perform any database mutation.

`payable-source-reconciliation-preview` bridges account balances, payout candidate readiness, earnings statuses, and block categories for one coin. It helps decide whether the next review should be account-credit/backlog processing or payout-row creation. It is read-only, does not mutate database state, and does not enable payout-row creation.

`account-credit-transition-preview` explains the account-credit/backlog transition needed before payout-row creation can be considered. It reports current account balances, earnings grouped by status, uncredited and credit-ready earnings totals when distinguishable, block categories that need accounting inspection, proposed future transition stages, and why payout rows remain blocked until positive credited balances exist. It is read-only; it does not credit accounts, modify earnings or blocks, create payout rows, authorize execution, or perform any database mutation.

`earnings-credit-readiness-preview` explains why status 0 earnings are payable source backlog, not automatically credit-ready. It reports earnings grouped by status, status 0 and status 1 readiness signals, account/user and block linkage, block categories, amount polarity, duplicate-risk hints, readiness classification totals, blockers, proposed future stages, audit metadata, and checksum metadata. It is read-only; it does not change earnings status, credit accounts, modify blocks, create payout rows, authorize execution, or perform any database mutation.

`block-category-maturity-preview` inspects whether immature or new block categories may be stale while backend updaters are frozen. It reports service/update freeze assumptions, coin maturity reference fields, blocks grouped by category, status 0 and status 1 earnings linked to blocks, height/time maturity signals where derivable, stale/frozen indicators, conservative classification totals, blockers, proposed future stages, audit metadata, and checksum metadata. It is read-only; it does not mature blocks, change block categories, change earnings status, credit accounts, create payout rows, authorize execution, or perform any database mutation.

`earnings-block-reconciliation-preview` reconciles earnings rows to block rows when earnings counts and block counts differ. Earnings rows and block rows are different units, and one block can have multiple earnings rows. The preview reports status 0/status 1 earnings totals, block linkage, row-per-block distribution, linked block category distribution, row-count difference explanations, reconciliation classification, blockers, proposed future stages, audit metadata, and checksum metadata. It is read-only; it does not change blocks, earnings, accounts, payouts, wallets, shares, services, or cron.

`maturity-source-verification-preview` verifies which DB fields can be used as maturity threshold and current-height inputs before any later category/status or account-credit transition review. It reports coin maturity/current-height fields, field usability, block table height evidence, linked immature block ranges, DB-only maturity delta counts when possible, source confidence, conservative decisions, blockers, proposed future stages, audit metadata, and checksum metadata. It is read-only; it does not call daemon RPC, call wallet RPC, mature blocks, change block categories, change earnings status, credit accounts, create payout rows, modify shares, change services, or change cron.

Each rendered preview report includes a top-level `report_checksum` object. The checksum excludes volatile `generated_at` metadata and is intended only for comparing repeated preview reports during review. It is not payout authorization and does not enable execution.

`BadpoolGuardCommand` finalizes reports immediately before output, and JSON output is emitted only after checksum and payout audit metadata are attached.

Runtime validation should check checksum fields with explicit matches, for example `grep -q '"report_checksum"' preview.json` and `grep -q '"audit"' preview.json`, or with explicit match counts. Do not use `grep` piped to `sed` as the condition for these checks.

The shared guard context centralizes option parsing, coin scope validation, dangerous-option refusal, safety metadata, JSON/text rendering, and SELECT-only query helpers. No execute/apply mode exists yet.

Safety properties:

- No wallet reads are used by default.
- No wallet sends are available; send-style wallet RPC methods are refused by the hard guard.
- No DB mutations are performed.
- No shares are deleted; backend share cleanup is refused by the hard guard.
- No payout rows are created.
- No withdraw rows are created.
- No account balances, earnings, blocks, coins, or payouts are changed.
- No payout retry/delete behavior is available.
- No backend service, cron, or deployment behavior is changed.

Backend/payment restoration remains **NO-GO / KEEP FROZEN** until separate guardrails, validation, and explicit approval exist. The `safety-scan` command summarizes risky database conditions, but it does not prove production service/process state unless run on the server with separate L3 runtime inspection.

Future wallet-send enablement must be a separate approved change. It should require explicit command context, coin scope, report checksum validation, production approval, and service-state review before any live send path is considered.

Future share deletion enablement must also be a separate approved change. Historical share evidence is preserved during restoration planning, and backend/payment services must remain frozen unless separately approved.

Future payout execution must be a separate approved change. The future design is documented in `docs/badpool-future-payout-execution-design.md`; it keeps payout candidate reports, payout-row creation, account debits, payout retry/delete, and wallet sends as separate stages with report checksum review and explicit production approval.

Future payout-row creation requires a separate approved PR and separate operator action. The reconciliation, preflight, and dry-run plan previews added after the design spec are review scaffolding only.

Future block category or maturity-state transition work requires a separate approved PR and separate operator action. Immature/new categories may be stale while backend updaters remain frozen, but the preview only reports database evidence and required checks; it does not approve category changes, earnings status changes, account-credit work, or payout-row creation.

Future category/account-credit transition work must reconcile earnings rows to linked block rows first when row counts differ. The reconciliation preview is informational only and does not approve category changes, earnings status changes, account-credit work, or payout-row creation.

Future category/account-credit transition work also requires a reliable current-height source and maturity threshold. DB-only previews must not assume chain height when `coins.block_height` is stale, missing, null, or non-numeric; a separate approved operator review must verify live height before any transition.

The payout-row approval package checklist is documented in `docs/badpool-payout-row-approval-package.md`. It requires the latest preview report paths and checksums, coin scope, expected counts/totals, guard confirmations, service-state confirmations, STOP conditions, rerun/idempotency rule, and explicit operator approval text before any future payout-row creation implementation is proposed. It does not authorize wallet sends.
