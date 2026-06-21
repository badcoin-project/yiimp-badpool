# BadPool Forward Catch-Up Stage 1 Apply Design

## Purpose

This document defines the design for a future **Stage 1 forward catch-up apply** workflow for BadPool. It is a design document only: it does not add an apply command, an execute mode, backend-loop execution, database writes, payout creation, account crediting, or wallet sends.

Stage 1 exists to import forward block metadata and create only pending earnings rows for explicitly approved, batch-limited blocks. Later stages remain separate and must handle maturity, crediting, payouts, and wallet sends.

## Production Evidence Driving the Design

Current production evidence shows that the forward scrypt candidate window after the payout checkpoint is mixed and cannot be safely handled by a broad backend loop or a blanket import path:

| Daemon classification bucket | Count | Required Stage 1 behavior |
| --- | ---: | --- |
| `generate_import_candidate` | 1,640 | Import as pending metadata and pending earnings only. |
| `immature` / `daemon_other_review` | 32 | Import only when explicitly classified and approved as pending; otherwise review. |
| `daemon_orphan_no_earnings` | 42 | Mark or exclude as orphan; create no earnings. |

Additional attribution evidence is mixed:

- Recent historical generated blocks show multi-row earnings for users `79` and `86`.
- Older stuck `status=0` backlog rows show one row per block for user `79`.
- Therefore, single-recipient `block.userid` attribution is not universally proven and must not be silently assumed.

The future apply workflow must require explicit operator confirmation before using any deterministic single-recipient attribution model.

## Why Existing Backend Import Cannot Be Reused Blindly

`BackendBlockNew()` must not be reused blindly for this catch-up path. Its normal earning calculation depends on the current global valid shares table and share cleanup behavior. BadPool guardrails now skip broad share cleanup and backend loop execution, so running the legacy block import path against historical forward blocks could attribute earnings using the wrong share window or stale/current global shares.

For Stage 1, attribution must therefore be explicit, deterministic, reviewable, and operator-approved before any future write operation is allowed.

## Stage Boundaries

The catch-up process is intentionally split into isolated stages:

1. **Stage 1: metadata import + pending earnings only**
   - Import daemon-derived block metadata.
   - Create only pending earnings rows with `status=0`.
   - Do not credit accounts.
   - Do not create payout rows.
   - Do not send wallet transactions.
   - Do not run backend loops.
2. **Stage 2: maturity/status transition**
   - Later process pending/imported blocks into matured status when daemon state supports it.
3. **Stage 3: account credit**
   - Later credit account balances from approved matured earnings.
4. **Stage 4: payout row creation**
   - Later create payout rows from eligible credited balances.
5. **Stage 5: wallet send**
   - Later perform wallet sends from approved payout rows.

Stage 1 must never collapse or shortcut Stages 2 through 5.

## Stage 1 Classification Rules

A future Stage 1 apply must classify each block using daemon RPC before any mutation is planned or written:

1. Read the block by `blockhash`.
2. Read the coinbase transaction from the block transaction list.
3. Extract confirmations, transaction detail category, and amount.
4. Classify the block into one of the approved Stage 1 buckets.

| Daemon result | Stage 1 classification | Mutation plan | Earnings plan |
| --- | --- | --- | --- |
| `generate` with positive amount and confirmations greater than zero | Import generate as pending | Set `txhash`, `amount`, `confirmations`, and Stage 1 category/status as pending/immature. | Create pending earnings only with `status=0`. |
| `immature` with positive amount and non-negative confirmations | Import immature as pending | Set `txhash`, `amount`, `confirmations`, and Stage 1 category/status as pending/immature. | Create pending earnings only with `status=0`. |
| `orphan` | Orphan exclusion | Mark or preserve as orphan according to approved write policy. | Create no earnings. |
| Unknown, inconsistent, missing, or daemon error | Review/block | Skip or abort according to explicit approval policy. | Create no earnings unless a later approval explicitly reclassifies the block. |

Even when the daemon says `generate`, Stage 1 must treat the import as pending for accounting purposes. Maturity and account-credit coupling belongs to later stages only.

## Attribution Model Approval

Because historical evidence is mixed, a future Stage 1 apply must require explicit operator approval for the attribution model.

The currently proposed deterministic dry-run attribution model is:

- Primary recipient: `blocks.userid`
- Projected earning `userid`: `blocks.userid`
- Projected earning `coinid`: selected coin ID
- Projected earning `blockid`: selected block ID
- Projected earning `create_time`: block time
- Projected earning `status`: `0`
- Projected earning `mature_time`: `NULL`

This model must be identified as `block_userid_single_recipient` and must require an explicit operator confirmation before any future apply may use it.

## Required Future Apply Safety Gates

A future Stage 1 apply must be batch-limited and checksum-gated. It must require all of the following before writing anything:

- Explicit selected coin scope; all-coin scope is refused.
- Explicit batch limit.
- Exact batch boundary match against the reviewed dry-run plan.
- Approval input checksum matching the reviewed dry-run report.
- Batch scope checksum matching the reviewed block set.
- Projected mutation checksum matching the reviewed mutation plan.
- Projected earnings checksum matching the reviewed pending-earnings plan.
- Explicit operator confirmation of attribution model `block_userid_single_recipient`.
- Operator acknowledgement that attribution evidence is mixed.

## Required Future Apply Idempotency Rules

A future Stage 1 apply must be idempotent and must abort or skip safely when reality differs from the reviewed plan:

- Abort or skip if a block is no longer in the approved pre-Stage 1 category/state.
- Abort if linked earnings already exist for a block in the batch.
- Abort on daemon classification changes from the approved dry-run.
- Abort on missing `userid` for any import candidate that would create earnings.
- Ensure orphan blocks create no earnings.
- Ensure generated and immature imports create only pending earnings with `status=0`.
- Ensure no account balance changes occur.
- Ensure no payout rows are created.
- Ensure no wallet sends occur.
- Ensure no broad backend loops are run.

## Explicit Non-Goals for Stage 1

Stage 1 must not do any of the following:

- Credit accounts.
- Create payout rows.
- Send wallet transactions.
- Run backend block or payment loops.
- Recalculate earnings from the current global valid shares table.
- Reuse legacy block-import behavior without isolating attribution and share-window assumptions.
- Perform maturity transitions.
- Perform payout eligibility transitions.

## Operator Review Checklist

Before any future Stage 1 apply exists or is run, operators should review:

- The payout checkpoint used to define the forward window.
- The exact block IDs, heights, hashes, and ordering in the batch.
- Per-block daemon classification results.
- Orphan exclusions and proof that no earnings are planned for orphans.
- Any unknown/error blocks and the explicit skip-or-abort policy.
- Projected pending earnings rows and their `status=0` state.
- Attribution model and mixed historical attribution evidence.
- All approval and batch checksums.
- Confirmation that later stages remain separate.

## Recommended Next Step

The recommended next stage is an approval package for a future batch-limited Stage 1 apply design, not an executable apply command. Any implementation must preserve the stage boundaries and safety gates in this document.
