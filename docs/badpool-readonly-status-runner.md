# BadPool read-only status runner

`badpoolguard status-runner` is an accounting-readiness status command only. It reports the five BadPool accounting rows (coin IDs `1266` through `1270`) and chooses a conservative `next_safe_action` from read-only database state.

## Command shapes

```sh
php yaamp/yiic.php badpoolguard status-runner --format=json
php yaamp/yiic.php badpoolguard status-runner --coin-id=1267 --format=json
php yaamp/yiic.php badpoolguard status-runner --algo=scrypt --format=json
```

## Safety contract

The runner sets `read_only=true` and never executes apply commands, wallet-send paths, database mutation helpers, backend loops, share deletion, payout-row creation, or account-credit creation.

## JSON shape

Each entry in `items.algos[]` includes `coin_id`, `symbol`, `algo`, block totals, stage readiness counts and amounts, payout/account/withdraw totals, `blocked_reason`, and `next_safe_action`.
