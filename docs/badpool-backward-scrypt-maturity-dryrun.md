# Bounded backward Scrypt maturity dry-run

`backward-maturity-transition-dryrun` is a read-only contract for reviewing the validated 71-row Scrypt clean tail. It is intentionally separate from the existing forward maturity transition commands.

The command performs bounded database reads only. It does not read a wallet, generate an approval package, expose an apply command, accept an operator confirmation, change database state, create account credit or payout rows, run a backend loop, change a service, delete shares, or send funds. A passing report is design evidence, not permission to perform a later stage.

## Exact inventory boundary

The clean-tail inventory contains discontinuities, so minimum and maximum IDs are unsafe selectors. Expanding earning range `12623-12726` would incorrectly include `12663-12695`. Expanding block range `14263-14367` would incorrectly include `14303-14335` and `14360`.

The command therefore requires both complete explicit inventories:

- earning IDs: `12623-12662,12696-12726` (71 individual IDs)
- block IDs: `14263-14302,14336-14359,14361-14367` (71 individual IDs)

Arguments are canonicalized for ordering, then compared with these exact inventories before any database query. Missing IDs, extra IDs, duplicates, malformed CSV, range-expanded gap IDs, a different coin, a different checksum, and non-JSON output are refused.

The expected inventory checksum is `145db645b8ce0b04b55a13ef788b00c0f87b10674bbc9ab2d7e00c250bcd2d2c`. Its purpose is **read-only inventory comparison only; not payout authorization; not maturity authorization; not account-credit authorization**. The report creates a separate SHA-256 checksum that excludes `generated_at` and `report_checksum`; that checksum is preview audit comparison only and is also not authorization.

## Validation contract

A PASS requires exact reconciliation to all of the following:

- coin `1267`, algorithm `scrypt`, symbol `BAD`
- 71 earnings and 71 distinct linked blocks
- exact amount `153624.87722905 BAD`, evaluated with the existing 12-place decimal-safe helpers
- earning status `0` only
- complete user, account, and block linkage
- linked block coin `1267` and category `immature` only
- no `generate` or `orphan` category
- no selected row at or before its account's `last_earning`
- no exact duplicate block/user/amount/status group
- no multirow selected block
- no Scrypt earning outside the explicit earning inventory on a selected block
- no former exact-50 earning overlap
- no excluded earning or block gap selected

Any failed assertion produces HOLD. The report records the exact failed assertions, inventory summaries, explicit scope, safety flags, former exact-50 status distribution, and blocked later actions.

## Historical exclusions

The older 181-row historical portion remains outside this contract because its possible prior-credit history has not been reconciled. It must not be appended to this clean tail or inferred through an earlier range boundary.

The former exact-50 forward lane (`earnings 12801-12850`) was intentionally processed separately before this inventory was exported. The post-payout read-only evidence found all 50 rows at status `2` and zero overlap with this backward inventory. This command reports their current status distribution as comparison evidence but never selects them.

`status1_count=0` is not a boundary failure for this inventory. It reflects the separately completed forward-lane payout/accounting workflow.

## Invocation

Run from the repository `web` directory with the complete explicit CSV values:

```text
php yaamp/yiic.php badpoolguard backward-maturity-transition-dryrun \
  --coin-id=1267 \
  --selected-earning-ids=<complete-explicit-71-id-csv> \
  --selected-block-ids=<complete-explicit-71-id-csv> \
  --expected-inventory-checksum=145db645b8ce0b04b55a13ef788b00c0f87b10674bbc9ab2d7e00c250bcd2d2c \
  --format=json
```

No approval or apply path should be derived from this preview. Future approval-package or mutation work requires a separate reviewed task after this dry-run contract and its production read-only output have been reviewed.
