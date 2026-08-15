# Backward Scrypt maturity approval package

`backward-maturity-transition-approval-package` is a read-only review package for the exact PR #97 backward Scrypt maturity dry-run. It adds **no apply path**. Any future maturity apply implementation requires a separate reviewed pull request.

## Retained evidence and exact scope

The production evidence is the retained report `/tmp/badpool-pr97-readonly-dryrun-20260815-023051/backward-maturity-dryrun.json`, produced from deployed head `10fe42d5671cc63e1e65dde81111ae7b752e3faf`. It reported `status: ok`, `validation_status: pass`, no failed assertions, 71 distinct blocks/rows, and `153624.877229050000 BAD`.

The fixed scope is coin 1267 (`scrypt`, `BAD`), earning groups `12623-12662,12696-12726`, block groups `14263-14302,14336-14359,14361-14367`, and inventory checksum `145db645b8ce0b04b55a13ef788b00c0f87b10674bbc9ab2d7e00c250bcd2d2c`. The block and earning time ranges are both `1783549939` through `1783590153`.

The report must remain on disk. `--dryrun-report-checksum` is the SHA-256 digest of its exact bytes, so any retained-content change causes HOLD. The command then freshly rebuilds the same bounded 71-row cohort from the current database with SELECT-only queries. Scope, amount, counts, time ranges, and every validation assertion must still match before a PASS package can be emitted.

```sh
php yaamp/yiic.php badpoolguard backward-maturity-transition-approval-package \
  --coin-id=1267 \
  --dryrun-report=/path/to/backward-maturity-dryrun.json \
  --dryrun-report-checksum=<sha256-of-exact-file-bytes> \
  --selected-earning-ids=<explicit-71-id-csv> \
  --selected-block-ids=<explicit-71-id-csv> \
  --expected-inventory-checksum=145db645b8ce0b04b55a13ef788b00c0f87b10674bbc9ab2d7e00c250bcd2d2c \
  --format=json
```

## Safety and checksum meaning

The inventory checksum is read-only comparison evidence only; it is not payout, maturity, or account-credit authorization. The approval-package checksum excludes `generated_at` and itself and binds review of the remaining package content only. It is not apply, payout, or wallet authorization. No checksum produced or consumed by this command authorizes mutation.

The command performs no database mutation, wallet read/send, payout-row creation, service action, backend loop, or share deletion. Its JSON deliberately contains no apply command or operator-confirmation string, and all mutation-related actions remain blocked.

Prior-credit risk is evaluated using **`earnings.create_time <= accounts.last_earning`**. `blocks.time` is descriptive evidence only and must never be used or documented as the prior-credit basis.
