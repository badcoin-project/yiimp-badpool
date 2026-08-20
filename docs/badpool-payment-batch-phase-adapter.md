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
