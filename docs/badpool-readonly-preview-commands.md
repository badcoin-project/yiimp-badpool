# BadPool Read-Only Preview Commands

Patch group A adds a guarded Yii console command for DB-only preview reports. Patch group B adds shared guard context/report plumbing used by the same commands. Patch group C adds a source-level wallet-send hard guard. Patch group D adds a source-level share-delete hard guard. Patch group E improves read-only payout candidate preview reporting. Patch group F adds deterministic preview checksum metadata. These guards and previews do not add execute/apply behavior or any activation path.

```text
cd web
php yaamp/yiic.php badpoolguard overview --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard blocks-preview --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard earnings-preview --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard account-credit-preview --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard payout-candidates-preview --coin-id=<coin-id>
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

Each rendered preview report includes a top-level `report_checksum` object. The checksum excludes volatile `generated_at` metadata and is intended only for comparing repeated preview reports during review. It is not payout authorization and does not enable execution.

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

Future payout execution must be a separate approved change. It should keep payout candidate reports, payout-row creation, account debits, payout retry/delete, and wallet sends as separate stages with report checksum validation and explicit production approval.
