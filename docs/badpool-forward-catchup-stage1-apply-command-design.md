# Badpool Forward-Catchup Stage 1 Apply Command Design

## Purpose

This document defines the intended future design for a Stage 1 forward-catchup apply command. The command will apply an already-approved Stage 1 forward-catchup import plan after an operator has reviewed and approved the corresponding approval package.

This is design documentation only. It does not implement the command and does not change runtime behavior.

## Non-Payout Boundary

The future Stage 1 apply command is not a payout execution command.

Stage 1 is limited to importing selected already-classified forward-catchup block outcomes into block state and pending earnings state. It must not perform account crediting, payout creation, wallet sends, payout retries, service orchestration, or backend loop execution.

## Future Command Shape

The intended operator command shape is:

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

The checksum arguments are intended to bind execution to the exact approval package, batch scope, projected block mutations, and projected earnings that were reviewed before apply.

## Operator Preconditions

Before running the future command, the operator is expected to confirm outside the command that services and backend activity are frozen sufficiently to prevent concurrent mutation of the selected blocks or related earnings. The command must abort before mutation if this service freeze is not confirmed by the operator outside the command.

The explicit attribution confirmation must match:

```text
block_userid_single_recipient
```

This attribution model means each selected generated block is expected to create one pending earning for the block recipient identified by the block `userid` attribution model used by the approved Stage 1 plan.

## Required Apply Gates

The future apply command must verify all required gates before performing any mutation:

1. The current block category for every selected block must still be `new`.
2. No linked earnings may already exist for selected blocks.
3. Daemon classification must still match the approval package.
4. Orphan rows create no earnings.
5. No account credit.
6. No payout rows.
7. No wallet sends.
8. No backend loops.
9. No share deletion.
10. No `BackendBlockNew` usage.
11. No `BackendBlocksUpdate` usage.
12. No `BackendPayments` usage.
13. No `BackendCoinPayments` usage.

These gates are both safety requirements and scope boundaries. If any gate fails, the command must abort before mutation.

## Intended Stage 1 Mutations Only

The future command may perform only the following Stage 1 mutations.

### Generate/Immature Rows

For selected rows classified by the approved package as generated/immature, the command may:

1. Update the block row with the approved daemon-observed values:
   - `txhash`
   - `amount`
   - `confirmations`
   - `category='immature'`
2. Insert exactly one pending earning row linked to the selected block with:
   - `status=0`
   - the approved amount and recipient attribution from the approval package

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
- mark final maturity
- retry payouts

Any behavior in this list belongs outside Stage 1 and must be designed, reviewed, and approved separately.

## Rollback and Abort Behavior

The future command should be designed to avoid partial application. It must perform all validation gates before mutation and abort without modifying data when any precondition fails.

The command must abort before mutation if:

1. Checksum inputs do not match the approved package artifacts.
2. Selected block IDs or heights differ from the approved batch scope.
3. Any selected block is no longer `category='new'`.
4. Any selected block already has linked earnings.
5. Daemon classification changed from the approval package.
6. Service freeze is not confirmed by the operator outside the command.

If implementation later uses a database transaction for the allowed Stage 1 mutations, any failure during mutation should roll back the transaction and report the failed gate or mutation step in JSON output. The command should not attempt compensating payout, wallet, backend, or service actions because those actions are outside Stage 1 scope.

## Expected Output Contract

When implemented, the command should emit JSON when `--format=json` is supplied. The output should identify:

- the coin ID
- the approval package checksum
- the batch scope checksum
- the projected mutation checksum
- the projected earnings checksum
- the number of selected blocks
- the number of generated/immature block updates
- the number of orphan block updates
- the number of pending earnings inserted
- whether mutation was applied or aborted
- any failed gate name and reason

The JSON output should not include wallet secrets, service credentials, private keys, or payout transaction signing material.

## Expected Next Stages After Stage 1

Stage 1 intentionally stops before maturity, crediting, payout, or wallet send behavior. Expected later design stages are:

1. Stage 2: maturity/status transition design
2. Stage 3: account-credit design
3. Stage 4: payout-row design
4. Stage 5: wallet-send approval design

Each later stage should have its own explicit approval boundary, validation gates, rollback behavior, and operator command design before implementation.
