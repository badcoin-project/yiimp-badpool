# Badpool Forward-Catchup Stage 1 Apply Command

## Purpose

This document describes the implemented guarded Stage 1 forward-catchup apply command. The command applies an operator-reviewed Stage 1 forward-catchup import plan only after the live runtime plan is recomputed and stable authorization checksums match the approved package.

Stage 1 is limited to importing selected already-classified forward-catchup block outcomes into block state and pending earnings state. It is not payout execution and does not authorize any later maturity, account-credit, payout-row, wallet-send, service, backend-loop, or share-deletion behavior.

Stage 1 creates pending earnings only; it does not credit accounts, create payout rows, send wallet transactions, delete shares, run backend loops, or start the blocks service.

## Non-Payout Boundary

The Stage 1 apply command is not a payout execution command.

The command may only mutate selected block rows and pending earnings rows as described below. It must not perform account crediting, payout creation, wallet sends, wallet unlocks, payout retries, service orchestration, backend loop execution, or share deletion.

## Command Shape

`--limit` is accepted by Stage 1 dry-run, approval-package generation, and apply. Apply requires the reviewed `--limit` and `--selected-count`; apply scope is bound by the approved checksum set and must not silently fall back to the default limit.

The operator command shape is:

```bash
cd /srv/badpool/yiimp-badpool/web
php yaamp/yiic.php badpoolguard forward-catchup-stage1-apply \
  --coin-id=1267 \
  --approval-package-checksum=<approval_package_checksum> \
  --batch-scope-checksum=<batch_scope_checksum> \
  --projected-mutation-checksum=<projected_mutation_checksum> \
  --projected-earnings-checksum=<projected_earnings_checksum> \
  --operator-confirms-attribution-model=block_userid_single_recipient \
  --format=json
```

The checksum arguments bind execution to the approved stable authorization package, batch scope, projected stable block mutation intent, and projected pending earnings that were reviewed before apply. Live daemon confirmation counts are volatile audit/runtime data and are excluded from the stable approval and projected mutation authorization checksums. Current confirmations may be written at execution time only after all stable checksum and pre-mutation gates pass.

## Operator Preconditions

Before running the command, the operator is expected to confirm outside the command that services and backend activity are frozen sufficiently to prevent concurrent mutation of the selected blocks or related earnings.

The explicit attribution confirmation must match exactly:

```text
block_userid_single_recipient
```

This attribution model means each selected generated/immature block creates one pending earning for the recipient identified by the selected block row `userid`.

## Required Apply Gates

The apply command verifies all required gates before performing any mutation:

1. All required checksum flags are present.
2. `--format=json` is supplied.
3. `--operator-confirms-attribution-model=block_userid_single_recipient` is supplied exactly.
4. The current approval package/dry-run state is recomputed internally.
5. The supplied stable approval package checksum matches the freshly generated stable authorization payload.
6. The supplied batch scope checksum matches the freshly generated coin scope, selected block IDs, and selected block heights.
7. The supplied projected mutation checksum matches the freshly generated stable mutation intent, excluding volatile live confirmation counts.
8. The supplied projected earnings checksum matches the freshly generated pending earnings recipient, amount, coin, block, and status projections.
9. The current block category for every selected block is still `new`.
10. No linked earnings already exist for selected blocks.
11. Daemon classification and stable mutation intent still match the approved package.
12. Orphan rows create no earnings.
13. No account credit is performed.
14. No payout rows are created.
15. No wallet sends or wallet unlocks are performed.
16. No backend loops are run.
17. No shares are deleted.
18. No `BackendBlockNew`, `BackendBlocksUpdate`, `BackendPayments`, or `BackendCoinPayments` helper is used.

If any gate fails, the command aborts before mutation.

## Stable Authorization Checksums

The apply command separates stable authorization fields from volatile preview/audit fields:

- `batch_scope_checksum` binds coin scope plus selected block IDs and heights.
- `projected_earnings_checksum` binds projected pending earnings rows, including recipient, coin, block, amount, and `status=0`.
- `projected_mutation_checksum` binds stable mutation intent, including block ID, height, classification, target category, txhash, and amount where applicable.
- `approval_package_checksum` is generated from a stable authorization payload rather than the whole volatile report.
- `report_checksum` remains an audit checksum and must not be used as live apply authorization when it includes volatile fields.

Live daemon confirmation counts can drift naturally between approval-package generation and apply. They are excluded from stable authorization checksums; the command may write the current execution-time confirmation count only after all stable gates have passed.

## Stage 1 Mutations Only

The command may perform only the following Stage 1 mutations.

### Generated/Immature Rows

For selected rows classified by the approved package as generated/immature, the command may:

1. Update the block row with:
   - `txhash` from daemon classification
   - `amount` from daemon classification
   - current execution-time `confirmations`
   - `category='immature'`
2. Insert exactly one pending earning row linked to the selected block with:
   - `status=0`
   - `userid` equal to the selected block row `userid`
   - `amount` equal to the projected earning amount from the validated plan

### Orphan Rows

For selected rows classified by the approved package as orphan, the command may:

1. Update the block row with:
   - `category='orphan'`
2. Create no earnings.

## Explicit Non-Goals for Stage 1

Stage 1 does not:

- credit accounts
- create payout rows
- send wallet transactions
- unlock wallets
- run backend loops
- start services
- delete shares
- mark final maturity beyond setting selected generated/immature rows to `category='immature'`
- retry payouts

Any behavior in this list belongs outside Stage 1 and requires separate design, review, approval, and implementation.

## Rollback and Abort Behavior

The command is designed to avoid partial application. It performs all validation gates before mutation and aborts without modifying data when any precondition fails.

The command aborts before mutation if:

1. Required checksum inputs are missing.
2. Stable checksum inputs do not match the freshly recomputed authorization state.
3. Selected block IDs or heights differ from the approved batch scope.
4. Any selected block is no longer `category='new'`.
5. Any selected block already has linked earnings.
6. Stable daemon classification or mutation intent changed from the approval package.
7. The operator attribution confirmation is absent or not exact.

The allowed Stage 1 mutations run inside a database transaction. Any failure during mutation rolls back the transaction and returns JSON failure. The command does not attempt compensating payout, wallet, backend, service, or share actions because those actions are outside Stage 1 scope.

## JSON Output Contract

When `--format=json` is supplied, the command emits JSON identifying:

- command
- coin ID
- pass/fail status
- `read_only=false`
- stage name
- selected block count
- generated/immature block update count
- orphan block update count
- pending earnings inserted count
- projected pending earnings row count
- projected pending earnings gross amount
- approval package checksum
- batch scope checksum
- projected mutation checksum
- projected earnings checksum
- attribution model
- safety flags showing no account credit, payout rows, wallet sends, backend loops, or share deletion
- abort reason on failure

The JSON output must not include wallet secrets, service credentials, private keys, or payout transaction signing material.

## Expected Next Stages After Stage 1

Stage 1 intentionally stops before maturity, crediting, payout, or wallet send behavior. Expected later design stages are:

1. Stage 2: maturity/status transition design
2. Stage 3: account-credit design
3. Stage 4: payout-row design
4. Stage 5: wallet-send approval design

Each later stage needs its own explicit approval boundary, validation gates, rollback behavior, and operator command design before implementation.
