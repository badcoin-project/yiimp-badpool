# BadPool stats-only refresh

## Scope and data flow

The dedicated `cronjob/runBadpoolStats` route restores mining-history sampling without re-enabling either legacy backend loop. It is deliberately limited to the internal algorithm keys `sha256`, `scrypt`, `groestl`, `skein`, and `yescrypt`; `SHA256d` remains only the public label for `sha256`.

```text
live stratums + shares
        |
        v
BadPool stats-only refresh
        |
        +--> hashrate   (5-minute pool history)
        +--> hashstats  (hourly pool history)
        +--> hashuser   (5-minute user history)
        |
        v
BadPool frontend graphs/status
```

The component's exact write boundary is `hashrate`, `hashstats`, and `hashuser`. It reads current share-derived rates, current-bucket shares, existing rows in those three tables, and the read-only `SUM(blocks.amount * blocks.price)` for non-orphan blocks in the current hour. It never backfills gaps or deletes rows. Current status pages may continue to calculate `yaamp_pool_rate($algo)` directly from recent shares rather than waiting for a persisted bucket; raw rates from different algorithms must not be summed.

Pool and user samples store raw rate-calculator results, including legitimate rates below 1,000 H/s. User samples do not retain the legacy 20% smoothing: at a five-minute cadence smoothing would make changes lag across several graph points, while the existing rate function already supplies the intended recent-share estimate.

## Why the legacy functions remain frozen

`BackendStatsUpdate()` cannot safely be reused because it deletes stale stratums and workers, removes empty history, calculates rental/profitability state, and writes generic financial and algorithm state. `BackendStatsUpdate2()` mixes user mining history with renter history, account balance history, earnings conversion, and paid-earnings deletion. The new route calls neither function. `actionRunLoop2()` and `actionRunBlocks()` retain their deliberately empty callback lists.

## Miner-location discovery and privacy note

The `workers` table stores an `ip` value. Stratum inserts it when a client authenticates, using the connected socket address. A worker row is deleted when that connection is cleared, so it represents a connection record rather than durable location history; `workers.time` is insertion time and is not a share heartbeat.

The socket layer can accept HAProxy PROXY v2 IPv4 metadata when `g_handle_haproxy_ips` is enabled. Without that mode it stores the direct peer address. Consequently, a TCP or WSS gateway preserves the miner address only if the gateway is configured to send supported PROXY metadata; otherwise the worker records the gateway address. The repository contains no yescrypt-specific WSS forwarding implementation or configuration proving that its gateway supplies PROXY v2, so production gateway behavior must be verified before location data is trusted.

A future privacy-safe design should perform country lookup locally, cache only coarse country codes, and publish aggregate counts (for example, `Germany: 2 miners`). It should not expose exact IPs, city/street locations, wallet-to-location mappings, or individual map pins. Remote per-share GeoIP calls should not be used.

## Frontend follow-ups

A future five-algorithm status table could show algorithm, port, active miners/workers, live pool and network rates, reject percentage, difficulty, pool/network share, last pool block and age, and stratum state. The ports are Yescrypt 3032, Scrypt 4032, Groestl 5032, Skein 6032, and SHA256d 7032.

A separate chain panel could show height, latest Badcoin block, active pool miners, pool blocks over one and 24 hours, recent algorithm distribution, and payout-system status. The current Wallet Lookup is effectively a miner/account dashboard and could later be named Miner Stats, My Mining, or Miner Dashboard while retaining wallet address lookup. Legacy MH/s-oriented wallet/miner graph titles also need a separate mixed-algorithm unit review.

## Deferred production entrypoint

For a later guarded canary review, the proposed command shape is:

```sh
cd /srv/badpool/yiimp-badpool/web
/usr/bin/php -d max_execution_time=120 runconsole.php cronjob/runBadpoolStats
```

This change does not add a timer or service. Production canary execution, table-advancement checks, frontend validation, cadence selection, and enablement remain deployment follow-ups.
