# Payment batch phase adapter

`badpoolguard batch-run` wires `BadpoolPaymentBatchPhaseAdapter` into the durable
phase coordinator. The adapter processes the audited Scrypt payout-coin scope
through phases 0–6 and persists each phase's JSON artifact and SHA-256 checksum
inside the batch run directory.

The adapter composes existing guard commands rather than duplicating mutation
SQL. Maturity, account credit, and payout-row changes therefore retain their
existing exact selection, approval-package, checksum, and transaction checks.
Each generated approval package is consumed only by its matching guarded apply
command.

The coordinator remains the hard wallet boundary. Phase 6 may create guarded
payout rows, but neither the adapter nor the runner exposes or invokes a wallet
RPC or `wallet-send-apply`. A successful run ends in
`READY_FOR_WALLET_APPROVAL`; wallet approval remains a separate human action.

Resume loads the existing ledger and skips phases already recorded as `pass` or
`ok`. The ledger's mode, scope, algorithm filter, batch size, and exact per-coin
Phase 1 selection remain authoritative rather than being replaced by new command
defaults. A held phase can be retried without replaying earlier successful
mutations.

`batch-run-preview` is not connected to the adapter. It remains a database-free,
wallet-free, read-only description and its default all-active-payout-coins scope
does not require `--coin-id`.

Phase 1 first inventories eligible maturity work read-only, then binds an exact
block selection that fits the durable `batch_size`. Later approval packages are
refused if they contain earnings or accounts outside that ledger selection.
This prevents a fresh approval-package query from widening a resumed batch.
Both the legacy `apply_command_shape` and standardized `apply_command_args`
contracts are accepted, while the adapter substitutes only the package's own
checksum placeholder before dispatching the guarded apply.


## Hardened binding checks

Before any persisted approval-package artifact is applied, the adapter recomputes
the artifact SHA-256 and compares it with the durable ledger checksum for that
phase. A resumed run therefore refuses missing, malformed, or tampered package
artifacts before dispatching guarded apply commands.

Package contents are compared against the exact expected selection for the
specific coin being processed. Empty packages, subsets, duplicates, omitted IDs,
extra IDs, widened IDs, and cross-coin rows fail closed instead of advancing the
batch.

The all-active-payout-coins scope is resolved only from configured payout coin
IDs that are enabled, installed, visible, auto-ready, and have a positive payout
minimum. If no coin satisfies those predicates, the batch holds before mutation.

Bounded maturity dry-run responses are validated against the requested block
scope before becoming durable Phase 1 evidence: returned block IDs must exactly
match the requested IDs, selected earning IDs must be positive and unique, and
returned coin IDs must match the active coin when present.

Active payout coin resolution follows the pool's operational coin flags (`enable`, `installed`, `visible`, and `auto_ready`). A blank or null `payout_min` does not by itself make a coin inactive; payout threshold logic remains owned by the payout-candidate guard command.

## Confirmed-block payment-delay override

Phase 4 continues to require every selected earning to appear in the ordinary
`account-credit-clear-dryrun` result, which preserves the configured 12-hour
hold by default. An operator may override only this delay (not any maturity,
credit, payout-row, or wallet guard) while resuming a held batch:

```text
bin/badpool-batch-run --resume-batch-id=<batch-id> \
  --payment-delay-override-package=/absolute/path/override.json \
  --payment-delay-override-package-checksum=<sha256sum-of-exact-file> \
  --operator-confirms-payment-delay-override=override_12h_payment_delay_for_exact_confirmed_block_scope \
  --format=json
```

The JSON package has this exact shape (ID arrays contain canonical JSON positive
integers, are unique, sorted, and must exactly equal the durable batch ledger):

```json
{
  "schema": "badpool.confirmed_block_payment_delay_override.v1",
  "generated_at": "2026-08-24T19:30:00Z",
  "selected_coin_ids": [1267],
  "selected_block_ids": [123],
  "selected_account_ids": [456],
  "selected_earning_ids": [789],
  "scope_checksum": "<BadpoolGuardReport checksum of every preceding field>"
}
```

After adding `scope_checksum`, compute the separate file checksum with
`sha256sum override.json`. Packages expire after 15 minutes. Phase 4 re-queries
durable `earnings INNER JOIN blocks INNER JOIN coins` evidence and requires each
selected earning to remain status 1, each linked block to remain `generate`, and
`blocks.confirmations >= coins.mature_blocks`. Missing rows, unconfirmed blocks,
scope drift, stale packages, malformed or duplicate options, either checksum
mismatch, and missing or inexact confirmation all fail closed. The override is
read-only and does not itself credit accounts, create payout rows, or call a
wallet.
