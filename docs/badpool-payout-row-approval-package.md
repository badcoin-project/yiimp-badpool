# BadPool Payout Row Approval Package

This document defines the approval package required before any future payout-row creation implementation or mutation-capable task is proposed.

This package is documentation and review scaffolding only. It does not authorize production changes, payout-row creation, account debits, wallet sends, service activation, deployments, or any database mutation.

## Required Package

An approval package for future payout-row creation must contain all of the following items for one coin only:

- Latest `payable-source-reconciliation-preview` JSON path.
- Latest `payable-source-reconciliation-preview` top-level `report_checksum.value`.
- Latest `account-credit-transition-preview` JSON path when payable source data is not yet credited to account balances.
- Latest `account-credit-transition-preview` top-level `report_checksum.value` when that preview is required.
- Latest `earnings-credit-readiness-preview` JSON path when status 0 or indeterminate earnings remain in scope.
- Latest `earnings-credit-readiness-preview` top-level `report_checksum.value` when that preview is required.
- Latest `block-category-maturity-preview` JSON path when immature/new block categories remain in scope.
- Latest `block-category-maturity-preview` top-level `report_checksum.value` when that preview is required.
- Latest `earnings-block-reconciliation-preview` JSON path when earnings row counts differ from linked block counts.
- Latest `earnings-block-reconciliation-preview` top-level `report_checksum.value` when that preview is required.
- Latest `maturity-source-verification-preview` JSON path before category/status or account-credit transition review.
- Latest `maturity-source-verification-preview` top-level `report_checksum.value` when that preview is required.
- Latest `payout-candidates-preview` JSON path.
- Latest `payout-candidates-preview` top-level `report_checksum.value`.
- Latest `payout-row-preflight-preview` JSON path.
- Latest `payout-row-preflight-preview` top-level `report_checksum.value`.
- Latest `payout-row-dryrun-plan` JSON path.
- Latest `payout-row-dryrun-plan` top-level `report_checksum.value`.
- Coin ID.
- Coin algorithm.
- Candidate count.
- Projected total payout amount.
- Threshold used.
- Explicit operator approval text.
- Database backup path.
- Mutation log path.
- Expected payout-row count.
- Expected account debit total.
- STOP conditions reviewed and accepted.
- Rerun/idempotency rule reviewed and accepted.
- Confirmation that payable source reconciliation supports payout-row review rather than account-credit/backlog review.
- Confirmation that account-credit transition preview does not require unresolved account-credit/backlog work.
- Confirmation that earnings credit-readiness preview has no unresolved not-ready or indeterminate blockers.
- Confirmation that block category maturity preview has no unresolved stale, indeterminate, orphan-risk, missing maturity-source, or missing current-height blockers.
- Confirmation that earnings-block reconciliation preview has no unresolved missing linkage, schema limitation, or unexplained row-count difference blockers.
- Confirmation that maturity source verification preview has no unresolved current-height, maturity-threshold, DB staleness, or backend-logic blockers.
- Confirmation that the wallet-send guard remains active.
- Confirmation that the share-delete guard remains active.
- Confirmation that `badpool-blocks.service` remains inactive/disabled.
- Confirmation that `badpool-loop2.service` remains inactive/disabled.
- Confirmation that payout-row creation, account debit, wallet send, and post-send verification remain separate stages.
- Explicit statement that this package does not authorize wallet sends.

## Required Approval Text

The operator approval text must be explicit and stage-scoped. It must include the selected coin ID, coin algorithm, expected payout-row count, expected account debit total, and the three report checksum values.

Suggested form:

```text
I approve preparation of a future payout-row creation implementation proposal for coin <coin_id> / <algo> using the attached preview reports and checksums. I understand this package does not authorize payout-row creation, account debits, wallet sends, service activation, deployments, or any database mutation.
```

## STOP Conditions

The approval package must name STOP conditions before any future implementation task begins. At minimum, stop if:

- Any required report path or checksum is missing.
- Payable source reconciliation indicates account-credit or backlog processing is the next required stage.
- Account-credit transition preview indicates unresolved earnings credit readiness or block accounting inspection is still required.
- Earnings credit-readiness preview reports not-ready rows, indeterminate rows, immature/new block backlog, orphan risk, missing linkage, duplicate uncertainty, or schema limitations that have not been separately resolved.
- Block category maturity preview reports stale or potentially mature but untransitioned categories, indeterminate maturity, orphan risk, missing maturity-source fields, missing current height, or unvalidated category transition logic that has not been separately resolved.
- Earnings-block reconciliation preview reports missing block linkage, schema limitations, unexplained row-count differences, or multirow block grouping that has not been separately reviewed.
- Maturity source verification preview reports missing, null, non-numeric, stale, or DB-only current-height inputs; missing or non-numeric maturity threshold; or unresolved need for separate live height/backend logic review.
- Coin ID or algorithm differs across the candidate, preflight, and dry-run plan reports.
- Candidate count differs across reports.
- Projected total payout amount differs across reports.
- Threshold used differs across reports.
- Expected payout-row count does not match the approved candidate count.
- Expected account debit total does not match the approved projected total payout amount.
- Database backup path is missing or not operator-confirmed.
- Mutation log path is missing or not operator-confirmed.
- Wallet-send guard status is unknown or inactive.
- Share-delete guard status is unknown or inactive.
- `badpool-blocks.service` or `badpool-loop2.service` is not confirmed inactive/disabled.
- Any stage coupling is proposed between payout-row creation, account debit, wallet send, or post-send verification.
- Any wallet-send authorization is requested by the payout-row approval package.

STOP means preserve the reports, do not proceed with implementation or production action, and open a separate review task.

## Rerun And Idempotency Rule

The package must state the rerun rule for future design review:

- A future payout-row creation stage must be one coin only.
- A future payout-row creation stage must be one stage only.
- A rerun must not duplicate payout rows.
- A rerun must not debit accounts.
- A rerun must not send wallets.
- A rerun must fail closed if current report checksums, counts, totals, or threshold inputs differ from the approved package.
- Idempotency design must be reviewed in a separate implementation PR before any mutation-capable task exists.

## Operator Template

Copy and fill this block for later review. Do not include credentials, signing material, cookies, or sensitive environment values.

```text
Approval package date:
Repository commit:
Operator:
Reviewer:

payable-source-reconciliation-preview JSON path:
payable-source-reconciliation-preview report_checksum.value:

account-credit-transition-preview JSON path:
account-credit-transition-preview report_checksum.value:
account-credit/backlog work resolved before payout-row review: yes/no

earnings-credit-readiness-preview JSON path:
earnings-credit-readiness-preview report_checksum.value:
earnings readiness blockers resolved before payout-row review: yes/no

block-category-maturity-preview JSON path:
block-category-maturity-preview report_checksum.value:
block category/maturity blockers resolved before payout-row review: yes/no

earnings-block-reconciliation-preview JSON path:
earnings-block-reconciliation-preview report_checksum.value:
earnings/block reconciliation blockers resolved before payout-row review: yes/no

maturity-source-verification-preview JSON path:
maturity-source-verification-preview report_checksum.value:
maturity source blockers resolved before payout-row review: yes/no

payout-candidates-preview JSON path:
payout-candidates-preview report_checksum.value:

payout-row-preflight-preview JSON path:
payout-row-preflight-preview report_checksum.value:

payout-row-dryrun-plan JSON path:
payout-row-dryrun-plan report_checksum.value:

Coin ID:
Coin algo:
Candidate count:
Projected total payout amount:
Threshold used:

Database backup path:
Mutation log path:

Expected payout-row count:
Expected account debit total:

Wallet-send guard remains active: yes/no
Share-delete guard remains active: yes/no
badpool-blocks.service inactive/disabled: yes/no
badpool-loop2.service inactive/disabled: yes/no

Separate stages confirmed:
- payout-row creation: yes/no
- account debit: yes/no
- wallet send: yes/no
- post-send verification: yes/no

This package does not authorize wallet sends: yes/no

STOP conditions reviewed:
Rerun/idempotency rule reviewed:
Payable source reconciliation supports payout-row review:

Explicit operator approval text:
```

## Non-Authorization

This approval package is an input to future design review only. Future payout-row creation still requires a separate approved PR, separate validation, and separate operator action.

This package does not authorize wallet sends. Wallet send must remain a separate stage with its own later approval path.
