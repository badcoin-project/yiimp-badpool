# BadPool Read-Only Preview Commands

Patch group A adds a guarded Yii console command for DB-only preview reports:

```text
cd web
php yaamp/yiic.php badpoolguard overview --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard blocks-preview --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard earnings-preview --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard account-credit-preview --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard payout-candidates-preview --coin-id=<coin-id>
php yaamp/yiic.php badpoolguard safety-scan --coin-id=<coin-id>
```

On the VPS layout, run these from `/srv/badpool/yiimp-badpool/web`. `yaamp/yiic.php` loads `serverconfig.php` through a relative path, so repository-root invocations with a `web/` prefix are not valid for that layout.

JSON is the default output format. Add `--format=text` for a human-readable report.

All-coin preview is refused by default. To intentionally preview across all coins, pass `--all-coins-preview` instead of `--coin-id=<coin-id>`.

```text
cd web
php yaamp/yiic.php badpoolguard overview --all-coins-preview
```

These commands are read-only. They use SELECT-only database queries and are for review before any L3/live apply work.

Safety properties:

- No wallet reads are used by default.
- No wallet sends are available.
- No DB mutations are performed.
- No shares are deleted.
- No payout rows are created.
- No withdraw rows are created.
- No account balances, earnings, blocks, coins, or payouts are changed.
- No payout retry/delete behavior is available.
- No backend service, cron, or deployment behavior is changed.

Backend/payment restoration remains **NO-GO / KEEP FROZEN** until separate guardrails, validation, and explicit approval exist. The `safety-scan` command summarizes risky database conditions, but it does not prove production service/process state unless run on the server with separate L3 runtime inspection.
