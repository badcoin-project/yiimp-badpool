# Backend service entrypoint call graph and safety boundary

This inventory describes the legacy graph as found before the guarded entrypoint boundary. “DB” includes ActiveRecord saves; “accounting” means earnings, renter balances, or account balances. The guarded routes now execute only the explicitly listed safe subset and report-only executes none of it.

## `cronjob/runBlocks`

| Reachable call | Classification |
|---|---|
| `actionRunBlocks` → `monitorApache` → `exec(pgrep/uptime)`, `system(service start)` | read-only process inspection; **service/process mutation** on recovery |
| stale-cycle `dborun(update jobs)` | **DB mutation** |
| `BackendBlockFind1` → `WalletRPC::{getblock,gettransaction}` | **external RPC**, **DB mutation**, deletion of invalid/duplicate blocks |
| `BackendBlockFind1` → `BackendBlockNew` → earnings/account updates | **accounting mutation**, **DB mutation**; share deletion is routed to `badpool_share_delete_guard_skip` and refused |
| `BackendClearEarnings` → earnings/account saves, invalid-record deletes, `yaamp_convert_amount_user` | **accounting mutation**, **DB mutation**, deletion; no wallet send |
| `BackendRentingUpdate` → job/jobsubmit/renter updates | **accounting mutation**, **DB mutation**, deletion of invalid jobs/submits |
| `BackendProcessList` → process-list query and connection-row saves | read-only process inspection; **DB mutation** |
| `BackendBlocksUpdate` → wallet block/transaction queries and block/earning transitions | **external RPC**, **accounting mutation**, **DB mutation**, deletion of invalid blocks; reaches `BackendBlockNew`; guarded share-delete attempt |
| memcache timestamp writes | monitoring mutation (not an execution lock) |

The guarded execute subset is only `BackendProcessList`. Legacy block discovery, maturity, account credit, renter debit, and process/service recovery have no activation path through this route. `BackendPayments` and wallet-send methods are not reachable. Future accounting activation must reuse the guarded BadPool accounting contract rather than adding a legacy switch.

## `cronjob/runLoop2`

| Reachable call | Classification |
|---|---|
| `actionRunLoop2` → `monitorApache` | read-only process inspection; possible **service/process mutation** |
| `BackendCoinsUpdate` | wallet/market **external RPC**; **DB mutation** of coin state |
| `BackendStatsUpdate`, `BackendStatsUpdate2`, `BackendUsersUpdate` | aggregate reads; **DB mutation** of statistics/users |
| `BackendUpdateServices` → start/stop/restart helpers | **service/process mutation** and DB state mutation |
| `BackendUpdateDeposit` → `WalletRPC::{getinfo,getblockhash,getblock,listaccounts,listtransactions,move}` | **external RPC**, **fund/accounting movement**, **DB mutation** |
| `MonitorBTC` → wallet queries and block updates | **external RPC**, **DB mutation** |
| `BackendRentingPayout` → jobsubmit/block/earning/account updates | **accounting mutation**, **DB mutation**, deletion of old jobsubmits; share deletion is hard-guarded |
| memcache timestamps | monitoring mutation (not an execution lock) |

The guarded execute subset is limited to `BackendStatsUpdate`, `BackendUsersUpdate`, and `BackendStatsUpdate2`. Coin wallet RPC, service mutation, deposit wallet RPC/move, `MonitorBTC`, and `BackendRentingPayout` are not reachable. Neither `BackendPayments` nor `BackendCoinPayments` is reachable, so no payment or wallet-send capability is implied by readiness.

## Configuration contract

`BADPOOL_BACKEND_BLOCKS_READY=1` and `BADPOOL_BACKEND_LOOP2_READY=1` are distinct exact-value gates; absent and every other value refuse before lock-independent work. `BADPOOL_BACKEND_MODE` must be `report-only` or `execute`. Stable, separate non-blocking `flock` files are `badpool-backend-blocks.lock` and `badpool-backend-loop2.lock`. The shell wrappers accept only `--once --mode=…`; they contain no retry loop.
