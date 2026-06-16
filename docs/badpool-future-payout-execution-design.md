# BadPool Future Payout Execution Design

This document is a design specification only. It adds no payout execution capability, no database mutation path, no wallet-send path, no backend loop restoration, no service action, and no deployment behavior.

Backend and payment restoration remains **NO-GO / KEEP FROZEN** until a later implementation is separately reviewed, approved, deployed, and operated under an explicit production approval workflow.

## Scope

Patch group G defines the future payout execution process before any mutation-capable code is introduced. The current implementation remains limited to read-only preview reports, guard/report plumbing, wallet-send blocking, share-delete blocking, payout candidate preview separation, and preview checksum metadata.

The design assumes future execution will be split into small, auditable stages. Each stage must be one coin at a time and one stage at a time. A successful preview must never authorize a mutation by itself.

## Required Preflight Checks

Before any future payout mutation is considered, an operator must complete and archive a preflight packet for the selected coin only:

- Confirm the repository branch, commit, and PR that introduced the specific stage implementation.
- Confirm backend/payment services remain frozen unless a separate approval explicitly changes that state.
- Confirm no unreviewed production-specific patch exists outside the reviewed repository code.
- Confirm a fresh read-only payout candidate preview was run from the approved production working directory.
- Confirm the preview report is for exactly one coin and the intended coin matches the operator approval.
- Confirm the preview report contains the required audit inputs listed below.
- Confirm no wallet-send path is being used during payout row creation or account debit stages.
- Confirm the wallet-send hard guard remains active until a later approved send-stage design exists.
- Confirm the share-delete hard guard remains active and historical share evidence is preserved.
- Confirm database backups or snapshots exist and are restorable before any mutation.
- Confirm a rollback or repair plan exists for partial payout state.
- Confirm the operator has a STOP procedure and authority to stop after any anomaly.

## Required Preview Report Inputs

A future execution request must reference a read-only preview report that includes:

- `report_checksum`
- `generated_at`
- `coin_id`
- coin symbol and algorithm
- `candidate_count`
- `projected_total_payout_amount`
- blocked actions metadata
- payout threshold and fee inputs when available without wallet access
- projected remaining balances
- report warnings and errors

The preview checksum is for audit comparison only. It is not automatic authorization, does not prove that execution is safe, and must not unlock mutation by itself.

## Human Approval Workflow

Future payout execution must require a recorded human approval packet before each stage:

- The exact repository commit and PR for the implementation.
- The selected coin ID and symbol.
- The preview report checksum and generation timestamp.
- The projected candidate count and total payout amount.
- The requested stage and the precise mutation it may perform.
- The backup or snapshot identifier.
- The operator name, reviewer name, approval timestamp, and communication channel.
- A statement that the approval covers one coin and one stage only.
- A statement that a new approval is required for the next stage.

Approval must be reviewed against the emitted report, not inferred from command names, scripts, cron state, or prior successful runs.

The payout-row creation approval package checklist is defined in `docs/badpool-payout-row-approval-package.md`. That package is required before any future payout-row creation implementation or mutation-capable task is proposed, and it does not authorize wallet sends.

## Stage Separation

Future payout execution must be separated into the following stages. A later stage must not be able to run as a side effect of an earlier stage.

### 1. Payout Candidate Preview

The preview stage remains read-only. It reports candidate accounts, thresholds, fees, projected payout amounts, projected remaining balances, blocked actions, audit metadata, and checksum metadata.

It must not create payout rows, debit accounts, create withdraw rows, call wallet code, send coins, retry failed payouts, delete payout rows, or modify backend accounting tables.

### 2. Payout Row Creation

Current scaffolding may report payout-row preflight readiness in read-only mode. That preflight report can list the selected coin, required preview checksum input, candidate count, projected payout total, payout threshold, backup status, mutation-log status, stage status, and blocked actions.

The preflight report does not create payout rows, debit accounts, call wallet RPC, authorize execution, or perform any database mutation. Future payout-row creation still requires a separate approved PR and operator action.

Current scaffolding may also report a read-only payout-row dry-run plan. That plan can list the selected coin, proposed row-creation stage name, required source preview checksum input, proposed mutation-log and backup/snapshot status, idempotency/rerun status, blocked row creation, blocked account debit, blocked wallet send, and a blocked post-execution verification checklist.

The dry-run plan does not create rows, debit accounts, call wallet RPC, write logs, write backups, authorize execution, or perform any database mutation.

This future stage may only create payout-intent rows after approval for a specific preview report and coin. It must not debit accounts and must not call wallet code.

The mutation log must record every row that would be or was created, including account ID, coin ID, amount, fee fields, status fields, and an idempotency key derived from deterministic non-secret inputs.

### 3. Account Debit

This future stage may only debit account balances for previously approved payout-intent rows. It must not create new payout candidates and must not call wallet code.

The mutation log must record old balances, debit amounts, new balances, payout row references, and the idempotency key. Any mismatch between the approved preview and current account state is a STOP condition.

### 4. Wallet Send

This future stage must remain unavailable until a later approved task designs a narrowly scoped send path. The wallet-send hard guard must remain active until that later task explicitly replaces or narrows it under review.

The send stage must require its own approval packet, a validated payout-row inventory, explicit destination review, and a post-send verification plan. It must not be implicitly enabled by payout row creation or account debit.

### 5. Post-Send Verification

This future stage reconciles emitted payout state after a wallet transaction is available. It records wallet transaction identifiers when used, updates only the approved verification fields, and checks miner-visible state.

Verification must not retry sends, delete failed rows, or repair balances automatically. Repair work requires a separate incident-specific approval.

## Backups And Snapshots

Before any future mutation, operators must confirm backups or snapshots for at least:

- `accounts`
- `balances`
- `blocks`
- `earnings`
- `payouts`
- `withdraws` if they are in scope
- `shares`
- `coins`

The backup packet must include snapshot time, storage location reference, restore owner, and a restore test or documented restore confidence. Sensitive values must not be copied into repository docs or PRs.

## Mutation Log Requirements

Every future mutation-capable stage must emit an append-only structured mutation log. The log must include:

- schema name and version
- generated timestamp
- repository commit
- command and stage name
- operator-provided approval reference
- coin ID and symbol
- preview report checksum
- idempotency key
- rows selected
- rows changed
- old and new values for financial fields
- blocked actions that remained blocked
- warnings, errors, and STOP decisions

Mutation logs must avoid sensitive values, RPC credentials, cookies, signing material, and wallet daemon configuration. Addresses and usernames should follow the same redaction or fingerprinting rules used by preview reports unless full values are explicitly required for operator review.

## One-Coin And One-Stage Rules

Future execution must run one coin at a time. All-coin payout mutation is out of scope for restoration.

Future execution must run one stage at a time. Payout row creation, account debit, wallet send, and post-send verification each require their own approval, logs, and stop point.

The output of one stage may become an input to the next stage only after human review confirms that row counts, totals, warnings, and blocked actions match expectations.

## Idempotency And Rerun Rules

Every mutation-capable future stage must be idempotent by design:

- A rerun with the same idempotency key must not duplicate payout rows.
- A rerun must detect already-applied account debits before changing balances.
- A rerun must not initiate an additional wallet send for the same approved payout inventory.
- A rerun must fail closed if the current database state no longer matches the approved preflight report.
- A rerun must report existing rows and prior mutation-log references instead of silently proceeding.

When idempotency cannot be proven, the stage must stop and require manual review.

## Failure Handling And STOP Conditions

Any of the following must stop future payout execution immediately:

- The selected coin does not match the approved preview.
- The preview report checksum, candidate count, or projected total does not match the approval packet.
- Backend/payment service state is unknown when the stage requires a frozen state assertion.
- Required backup or snapshot evidence is missing.
- Candidate rows, payout rows, balances, or totals differ from the approved preflight expectations.
- Any wallet-send action appears before the dedicated send stage.
- The wallet-send guard is not active before the dedicated send-stage implementation exists.
- The share-delete guard is not active.
- A mutation log cannot be written.
- A database error, partial write, duplicate idempotency key, or unexpected row count occurs.
- Any sensitive credential or signing material appears in report output.

STOP means no automatic retry, no cleanup mutation, no wallet send, and no service restart. The operator must preserve reports and logs, capture the exact failure, and open a follow-up repair or incident task.

## Partial Payout Repair Expectations

If payout row creation succeeds but wallet send does not happen, the default repair posture is to preserve the payout rows and stop. The system must not delete and recreate payout rows automatically, must not debit accounts unless the account-debit stage was separately approved and completed, and must not retry wallet sends.

Repair planning must identify the exact state:

- payout rows created and account balances unchanged
- payout rows created and account balances debited
- wallet send attempted but no transaction identifier recorded
- wallet transaction identifier recorded but payout verification incomplete
- miner-visible balance or payment state inconsistent with database state

Each repair path requires a separate approval, a current report, and a mutation log. Failed or empty-transaction payout rows must not be retried or deleted by default.

## Required Post-Execution Verification

After any future payout stage, operators must verify:

- payout rows created, skipped, completed, failed, or left pending
- account balances before and after the approved stage
- balance history rows if used by the implementation
- withdraw rows if they are in scope
- wallet transaction identifier if a later approved send stage uses one
- payout totals by coin and account
- miner-visible balance and payment state
- blocked actions that remained blocked
- mutation-log row counts and checksums

Verification results should be captured in the same incident or restoration packet as the approval and mutation logs.

## Non-Authorization Rules

The preview checksum is only an audit comparison tool. It must not become an automatic authorization token.

Future payout execution still requires a separate PR, separate code review, separate validation, and separate operator approval.

Future payout-row creation also requires the approval package defined in `docs/badpool-payout-row-approval-package.md` before any mutation-capable task is proposed.

The wallet-send hard guard remains active until a later approved task designs a narrowly scoped send path.

The share-delete hard guard remains active. Historical share evidence must be preserved during payout restoration.

This specification does not authorize production execution, service activation, payout creation, account debits, wallet sends, retry/delete behavior, share deletion, or deployment.
