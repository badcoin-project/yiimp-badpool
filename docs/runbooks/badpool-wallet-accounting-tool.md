# BadPool Wallet Accounting Tool Runbook

## Purpose

`badpool-wallet-accounting-tool` is a reusable, auditable helper for BadPool per-algo wallet/accounting enablement.

It is intended to reduce repeated manual command blocks while preserving one-algo-at-a-time safety. The normal workflow is read-only preflight first, review second, and one explicitly authorized algo apply only after review.

This tool must not be used to start payout or block accounting loops.

## Supported Modes

- `--preflight all`
- `--dry-run all`
- `--dry-run yescrypt|scrypt|skein|groestl|sha256d`
- `--verify yescrypt|scrypt|skein|groestl|sha256d`
- `--apply yescrypt|skein|groestl|sha256d`

`--apply all` must refuse.

`scrypt` is verify-only unless separately authorized. It is included because the Scrypt wallet/accounting repair is already proven and should remain auditable without being casually re-applied.

## Safety Gates

Apply mode requires all of the following:

- Explicit `--apply <single-algo>`.
- A supported apply target in the internal allowlist.
- `BADPOOL_APPLY_CONFIRM='I understand this mutates one BadPool algo'`.
- Accounting freeze confirmed.
- Global `payouts` and `withdraws` table counts confirmed as zero.
- Target row and algo mapping confirmed.
- Target row backup created.
- Target config backup created.
- Only one target row selected.
- Cookie exists.
- Daemon RPC reachable.
- `getblocktemplate` works.
- Wallet RPC works after wallet-enable attempt.
- `gettransaction` is available.
- Generated reward address validates as `isvalid=true` and `ismine=true`.
- DB/live cookie match verified after refresh.
- Target stratum service returns healthy after restart.
- Payout and withdraw counts remain zero after apply.
- Accounting loops remain frozen after apply.

The tool does not include an override for multi-algo mutation.

## Do-Not-Run List

Do not run these as part of this wallet/accounting preflight or apply:

- Do not start payout crons.
- Do not start `badpool-blocks.service`.
- Do not start `badpool-loop2.service`.
- Do not run `main.sh`.
- Do not run `BackendPayments`.
- Do not run `BackendCoinPayments`.
- Do not edit historical block categories.
- Do not edit earnings, accounts, payouts, or withdraws.
- Do not send funds.
- Do not unlock wallets.
- Do not print secrets.

Payment crons must remain off while using this tool.


## Backup Handling

Apply mode creates root-only backups. The target `coin-row-$ROW_ID.tsv` backup may contain RPC credentials from the `coins` row. Keep these files root-owned/root-readable only and do not paste backup contents into chat, public issues, logs, or tickets.


## Read-Only Preflight

Install the reviewed script on the BadPool host, then run:

```bash
sudo /usr/local/sbin/badpool-wallet-accounting-tool --preflight all
```

Review the global freeze state, per-algo readiness, cookie match status, wallet RPC status, `getblocktemplate` status, and the final summary table.

## One-Algo Apply

Only after reviewing read-only output and choosing one target algo, run:

```bash
sudo BADPOOL_APPLY_CONFIRM='I understand this mutates one BadPool algo' /usr/local/sbin/badpool-wallet-accounting-tool --apply groestl
```

The tool should mutate only the selected algo. It should stop only the target stratum service, edit only the target daemon config, restart only the target daemon service, update only the target BAD row, refresh only the target row cookie, and restart only the target stratum service.

This example uses `groestl`; replace it only with another separately reviewed single target.

## Verification

After apply, verify the same algo:

```bash
sudo /usr/local/sbin/badpool-wallet-accounting-tool --verify groestl
```

Verification should report:

- Daemon wallet exists.
- Wallet RPC works.
- `gettransaction` exists.
- `getblocktemplate` works.
- Current DB `master_wallet` is owned by the daemon.
- Target row account is correct.
- Target row cookie matches the live cookie.
- Target stratum listener is live.
- No payout or withdraw mutation occurred.
- Accounting loops remain frozen.

## Production Install After Merge

After the PR is reviewed and merged, pull the merged branch on the BadPool host and install:

```bash
sudo install -o root -g root -m 0750 scripts/badpool-wallet-accounting-tool /usr/local/sbin/badpool-wallet-accounting-tool
```

Do not install from an unreviewed scratch artifact.
