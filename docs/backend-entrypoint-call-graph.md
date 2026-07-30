# Backend service entrypoint call graph and safety boundary

This is the verified graph for the frozen `cronjob/runBlocks` and `cronjob/runLoop2` routes. “Removed legacy” identifies code that remains in the repository but is no longer reachable from either guarded action. No activity counter is inferred from callback completion: reports declare possible effect classes and explicitly state that database/RPC boundary instrumentation is unavailable.

## Current guarded graph

Both actions have the same deliberately empty cycle shape:

```text
shell --once --mode=... → runconsole.php → CronjobController action
  → BackendCycleGuard::run(route, mode, [])
    → report-only: structured report (no readiness, lock, or callback)
    → execute: exact route readiness → fixed /run/badpool route lock
      → zero callbacks → structured report → lock release
```

Consequently the current routes perform no database read/write/delete, accounting operation, payout/fund movement, wallet/network RPC, service/process action, shell command, share-delete attempt, or application filesystem write. Execute mode only opens and locks an existing pre-provisioned lock file after validating the full parent chain, directory, pathname, and opened descriptor. It never creates or writes a lock asset. The route identities are `/run/badpool/badpool-backend-blocks.lock` and `/run/badpool/badpool-backend-loop2.lock`.

## Independently traced callbacks removed from the guarded graph

### `BackendProcessList`

* Reads MySQL `SHOW PROCESSLIST` through `dbolist`.
* Reads `connections` rows using `getdbo('db_connections', Id)`.
* Inserts or updates one `db_connections` ActiveRecord per returned process (`id`, `user`, `host`, `db`, `created`, `idle`, `last`) via `save()`.
* Deletes stale rows with `DELETE FROM connections WHERE last < …` through `dborun`.
* Performs no accounting, wallet/network RPC, payment, share operation, service/process control, shell command, or filesystem write. Despite its name, it only asks MySQL for its process list.

### `BackendStatsUpdate`

* Reads stale `stratums`, configured algorithms, shares, blocks, coins, accounts, balances, orders, earnings, renters, jobs, and prior `hashstats`/`hashrate`/`stats`/`algos` aggregates through ActiveRecord helpers and scalar/list SQL.
* Deletes stale `stratums`, workers whose PID is absent from `stratums`, and zero-valued `hashstats` using three direct `dborun(DELETE …)` calls.
* Inserts or updates `db_hashstats`, `db_hashrate`, `db_stats`, and `db_algos` rows via `save()`.
* Calls internal calculation helpers such as `yaamp_pool_rate`, `yaamp_pool_rate_bad`, `yaamp_profitability`, algorithm normalization/hashrate helpers, and numeric formatting. These consume database-derived data; they do not provide a guarded mutation boundary.
* Reads balances and earnings to calculate statistics but does not credit/debit accounts or create payouts.
* Performs no wallet/network RPC directly, payment, share deletion, service/process control, shell command, or filesystem write.

### `BackendUsersUpdate`

* Reads accounts needing coin resolution and candidate coins.
* Constructs `WalletRPC` clients and calls `validateaddress` while searching for a user's coin: **external wallet RPC**.
* May convert an existing account balance using coin prices, reset a balance to zero on a coin change, change `coinid`, and clear `coinsymbol`: **accounting mutation** and database mutation through account `save()`.
* Writes monitoring data through `controller()->memcache->add_monitoring_function`.
* The `resolveip`, worker save, and direct coin-update sections are commented out and therefore unreachable; no active service action, shell command, filesystem write, payout, or share deletion occurs.

### `BackendStatsUpdate2`

* Reads recent shares and job submits; reads accounts, earnings, and prior `hashuser`, `hashrenter`, and `balanceuser` aggregates.
* Inserts or updates `db_hashuser`, `db_hashrenter`, and `db_balanceuser` rows via `save()` after internal rate and pending-earnings calculations.
* Deletes old cleared earnings per user with `DELETE FROM earnings WHERE status=2 …`: database deletion of accounting history.
* Reads account balances/pending earnings for snapshots but does not credit/debit accounts, create payouts, call wallets/network services, delete shares, control services/processes, run shell commands, or write files.

Because all four callbacks have database mutation and/or deletion effects—and `BackendUsersUpdate` additionally reaches wallet RPC and account-balance mutation—none is registered in the production guarded actions.

## Removed `actionRunBlocks` legacy graph

| Legacy reachable call | Effect classification |
|---|---|
| `monitorApache` → `exec(uptime/pgrep)`, `system(service start)` | process inspection; **service/process mutation** |
| stale-cycle `UPDATE jobs` | **database mutation** |
| `BackendBlockFind1` → `WalletRPC::{getblock,gettransaction}` | **external RPC**, block writes/deletes |
| `BackendBlockFind1` → `BackendBlockNew` | **accounting mutation**, earning/account writes; share deletion is refused by `badpool_share_delete_guard_skip` |
| `BackendClearEarnings` | earning status and account-balance **accounting mutation**; invalid earning deletion |
| `BackendRentingUpdate` | job/jobsubmit/renter **accounting/database mutation** and invalid-record deletion |
| `BackendProcessList` | effects traced above |
| `BackendBlocksUpdate` → wallet queries and `BackendBlockNew` | **external RPC**, block/earning/accounting writes/deletes, guarded share-delete attempt |
| memcache timestamp | monitoring marker, not a lock |

`BackendPayments`, `BackendCoinPayments`, and wallet-send methods were not direct calls in this legacy action and remain unreachable. Earnings creation, maturity, and account credit were direct/indirect legacy effects and are now unreachable.

## Removed `actionRunLoop2` legacy graph

| Legacy reachable call | Effect classification |
|---|---|
| `monitorApache` | process inspection; possible **service/process mutation** |
| `BackendCoinsUpdate` | extensive wallet **external RPC** and coin-state database writes |
| `BackendStatsUpdate`, `BackendUsersUpdate`, `BackendStatsUpdate2` | effects independently traced above |
| `BackendUpdateServices` | **service/process mutation** and database state writes |
| `BackendUpdateDeposit` → wallet info/block/account/transaction calls and `move` | **external wallet RPC**, **fund/accounting movement**, database writes |
| `MonitorBTC` | wallet queries and block database writes |
| `BackendRentingPayout` | renter/jobsubmit/block/earning/account **accounting mutation**, jobsubmit deletion, guarded share-delete attempt |
| memcache timestamps | monitoring markers, not locks |

`BackendPayments`, `BackendCoinPayments`, `sendmany`, and `sendtoaddress` are not reachable from the current route. Rental payout, deposit movement, wallet calls, and account credit are also unreachable.

## Configuration, lock, and reporting contracts

* `report-only` accepts no readiness switch, acquires no lock, and invokes zero callbacks.
* `execute` requires exactly `BADPOOL_BACKEND_BLOCKS_READY=1` or `BADPOOL_BACKEND_LOOP2_READY=1`. Missing and every other value refuse before filesystem or callback work.
* Production lock identity is the constant absolute directory `/run/badpool`; environment and working directory cannot override it. `runForTest()` is the only alternate-directory seam.
* `/run/badpool` and both lock files must be provisioned before the service starts; this change intentionally does not alter systemd. Every directory from `/` through `/run/badpool` must be a real non-symlink directory, must not be owned by the service UID, and must have no group/world write bits.
* Both required lock files must already exist, be regular non-symlink files owned by the same trusted UID as `/run/badpool`, have a link count of one, and have no write bits. They must be readable by the service (for example through a read-only service group). A typical privileged provisioning shape is a root-owned `0750` directory and root-owned, service-group-readable `0440` files named `badpool-backend-blocks.lock` and `badpool-backend-loop2.lock`.
* The guard opens with read-only `fopen(..., 'r')`, never creation mode. Because the service cannot modify any parent or the lock file, it cannot substitute the pathname between validation and open. The pathname and opened descriptor are then required to retain the same device/inode, trusted owner, regular-file type, permissions, and link count before `flock` is attempted.
* Reports contain callback start/completion/failure traces, declared possible effects, and `instrumentation_available=false`. They do not claim unmeasured database, accounting, wallet, payment, deletion, process, or filesystem counts.
* Wrappers accept exactly `--once --mode=report-only` or `--once --mode=execute`, in that order, and invoke exactly one quoted absolute `PHP_CLI` executable pathname at most once.
