# BADPOOL L3.196 — payout backlog path repair

Classification: **PASS — READY FOR REVIEW** (local code and offline regressions).

## Baseline and Git state

Pinned before editing on 2026-10-05:

- Checkout HEAD: `04c6bcb5ac53832d71e0f7ca65df026938563e86`.
- Local `main`: `492b8503c67c77354394bdf1be380333d082055f`.
- Cached `origin/main`: `1be711a19203bba8188c9de12b49e0f74d52bd00`.
- Branch: `generalize/multi-batch-payment-waiting-queue`.

These are distinct refs. Remote main freshness was not verified or fetched.
The repair is based on the checkout HEAD, not an assertion that these refs match.
HEAD and branch remain unchanged; changes are uncommitted. The working tree is
dirty with this repair and the pre-existing untracked `.worktrees/` directory.
No pre-existing tracked modifications were present.

## Precision loss and exact repair

Payout approval previously passed the account balance through
`decimalString()`, implemented as `sprintf('%.12F', floatval($v))`.
It then bound the rounded value as the old balance in the exact debit predicate.
This lost authoritative digits before checksumming and applying.

Payout candidate reads and before/after balance evidence now use
`CAST(balance AS CHAR)`. Approval construction accepts decimal text (or exact
integers), rejects binary floats, and retains every supplied fractional digit.
Payout selection totals, projected amounts, debit values and committed totals
use exact decimal string arithmetic. Selected-only approval totals are also
recomputed from the selected accounts.

The original account/coin/numeric-balance debit predicate remains; an additional
`CAST(balance AS CHAR)=:old_balance_text` predicate binds the same authoritative
text. This strengthens comparison rather than allowing an approximate match.
`4541.6627178399995` survives approval serialization, checksums, fresh apply
validation, payout creation and both old-balance bindings. The adjacent value
`4541.6627178399994` refuses without mutation.

This repairs payout preparation. It does not rewrite earlier accounting history
or migrate the database's numeric storage types.

## Held phase-6 recovery

A phase-6 resume requires durable successful phase results for phases 0–5.
Those phases are skipped, so recovery only packages and applies payout rows.
Missing credit evidence refuses rather than attempting credit again. A per-batch
resume lock prevents concurrent recovery attempts.

The production-shaped offline ledger uses batch
`20261004T072007Z-d73ffc159420`, earnings `20144,20145` at status 2, committed
credit and no payout IDs. Recovery invokes only payout approval/apply commands,
creates one payout once, and leaves earning status and history unchanged.
Retry with a new runner object invokes no additional financial commands.
No literal production batch ID is special-cased in implementation.

Before payout apply dispatch, an atomic `APPLY_ATTEMPT_STARTED` artifact records
the batch, phase, package checksum, time and unknown mutation outcome. Definitive
non-mutating refusal clears it only after retaining the refusal report. Success
clears it only after retaining the committed report. Interrupted or uncertain
apply retains the barrier and subsequent preparation holds for investigation.
Existing committed payout artifacts remain subject to the existing strict
recovery checks; missing historical evidence is never synthesized.

## Durable refusal evidence

Guarded apply refusals now atomically retain the reports, including the refused
diagnostic, before returning HOLD. Phase results retain batch ID, phase,
classification, readable reason, report path and SHA256, timestamp and mutation
status. Checksum and missing-contract refusals also produce evidence; exceptions
retain their sanitized reason and classify mutation outcome as unknown.
Earlier package commits are retained and identified if a later package refuses.

Diagnostics omit arbitrary transport output and redact credential fields,
authorization/cookie values and URL credentials. If artifact storage fails,
the result remains HOLD and explicitly reports inability to retain the artifact.

## Wallet counts and destinations

Retained funding evidence must match the retained wallet-operation set exactly,
including operation, lane, coin, wallet, source account and projected total.
Missing, extra and duplicate observations refuse. Fresh authoritative inventory
and operations are rebuilt and must match the retained approval before execution.
Valid one-, two-, three- and four-wallet scopes pass. SHA256d eligibility is
unchanged; no wallet or payout is added to satisfy a count.

Identical destination strings aggregate only inside the same wallet/source
operation. Each payout is projected to eight decimal places using the existing
deterministic rounding rule, then those projected decimal amounts are summed
exactly. Aggregating raw amounts before rounding is not substituted for this rule.
An invariant checks destination totals against the wallet's projected total.

The plan retains every selected payout ID, raw amount and individual projection;
new durable journals also retain payout and wallet-operation evidence. Each
contributing row reconciles to its own wallet operation's txid. An unselected
row sharing a destination is excluded. Cross-wallet destinations remain separate.

`SEND_ATTEMPT_STARTED`, durable txids, uncertain-outcome manual HOLD, human
approval, exact ownership and transactional reconciliation remain enforced.
Recorded txid operations skip funding checks and sending on restart, proceeding
through the existing exact reconciliation checks. Recurring send commissioning
and service definitions were not changed.

## HOLD and coordinator indexes

Lane-blocking ledgers rebuild the cached index before FAIL_CLOSED returns,
including when a resumed or fresh batch enters a blocking state. The held batch
does not appear in waiting/ready queues. The index records its blocking batch ID
and disables wallet-approval-required output. FAIL_CLOSED reports require human
action with `next_safe_action=investigate_hold`, even if a different batch is READY.
Index rebuilding does not modify authoritative ledgers.

## Files changed

- `web/yaamp/commands/BadpoolGuardCommand.php`
- `web/yaamp/core/backend/BadpoolGuardedMultiWalletSend.php`
- `web/yaamp/core/backend/BadpoolMultiWalletSendApply.php`
- `web/yaamp/core/backend/BadpoolPaymentBatchRunner.php`
- `web/yaamp/core/backend/BadpoolPaymentBatchPhaseAdapter.php`
- `web/yaamp/core/backend/BadpoolLivePaymentCoordinator.php`
- `tests/badpool_multi_wallet_send_state_machine_harness.php`
- `tests/badpool_payout_backlog_repair_harness.php` (new)
- `docs/badpool-l3-196-payout-backlog-repair.md` (this report)

## Exact validation results

PHP CLI 7.4.3 in local WSL; all database and wallet boundaries in these tests
are offline doubles. Final suite ran with a fresh isolated temporary directory:
**22/22 harnesses passed**, exit 0, with no warnings in that final run.
Syntax checks passed for all six modified implementation files and both tests.
`git diff --check` passed.

| Harness in `tests/` | Final result |
|---|---|
| badpool_completed_payout_batch_closeout_harness.php | PASS |
| badpool_completed_payout_closeout_apply_harness.php | PASS |
| badpool_completed_payout_wallet_proof_transport_harness.php | PASS, 43 checks |
| badpool_explicit_payment_coordinator_units_harness.php | PASS, 59 checks |
| badpool_legacy_payout_send_guard_harness.php | PASS |
| badpool_live_payment_coordinator_harness.php | PASS, 23 checks |
| badpool_multi_lane_wallet_send_harness.php | PASS, 74 checks |
| badpool_multi_wallet_apply_harness.php | PASS, 51 checks |
| badpool_multi_wallet_production_preflight_harness.php | PASS, 75 checks |
| badpool_multi_wallet_production_repository_harness.php | PASS, 39 checks |
| badpool_multi_wallet_send_state_machine_harness.php | PASS, 95 checks |
| badpool_payment_batch_run_harness.php | PASS |
| badpool_payout_backlog_repair_harness.php | PASS, 54 checks |
| badpool_payout_row_apply_guard_harness.php | PASS |
| badpool_readonly_wallet_cli_transport_harness.php | PASS, 88 checks |
| badpool_readonly_wallet_cookie_auth_harness.php | PASS, 8 checks |
| badpool_skein_payout_preparation_harness.php | PASS, 44 checks |
| badpool_wallet_funding_guard_harness.php | PASS, 36 checks |
| badpool_wallet_proof_closeout_guard_harness.php | PASS |
| badpool_wallet_send_apply_guard_harness.php | PASS |
| badpool_wallet_send_dryrun_guard_harness.php | PASS |
| badpool_yescrypt_payout_preparation_harness.php | PASS |

Required regression coverage:

| Requirement numbers | Evidence |
|---|---|
| 1–3 | Exact JSON approval/apply round trip, adjacent checksum and textual CAS refusal, float authority rejection |
| 4–6 | Actual runner/phase adapter resumes committed-credit fixture only at phase 6; one insertion; fresh runner retry performs no mutation |
| 7 | Durable refusal report/ledger SHA256, classification/reason/time/status; sanitized exception and pre-dispatch checksum refusal |
| 8–11 | End-to-end one-, two-, three- and four-wallet apply/reconciliation |
| 12–13 | Missing/extra funding observations refuse before send; duplicate, identity and total mismatches also refuse |
| 14–17 | Same-wallet duplicate aggregation, all contributing IDs, cross-wallet separation, exact projected sums |
| 18–19 | Seven-row reconciliation and individual wallet txids; unselected row sharing address stays unpaid |
| 20 | Existing 526–529 apply/preflight/repository fixtures remain PASS, including RECONCILED retry |
| 21–22 | Synthetic 530–536 three-wallet scope passes repeated recipients; changed ownership and insufficient funding still refuse |
| 23–24 | HOLD plus another READY ledger produces investigation action, rebuilt blocking index and unchanged ledger hash |
| 25 | Wallet exception/uncertain result yields manual HOLD with exactly one send attempt across retries |
| 26 | All txids durably recorded before restart: zero sends and zero funding calls, exact reconciliation succeeds with funding unavailable |

Additional coverage proves concurrent recovery refuses, unknown payout apply
outcomes cannot replay, and a synthetic 213-row scope with 201 Scrypt / 4 Skein /
8 Yescrypt rows reconciles through exactly three wallet operations.

## Backlog support, risks and recommendation

**526–529 regression: PASS.** Existing four-wallet RECONCILED behavior remains.

**Production-shaped 530–536 regression: PASS.** This is synthetic ownership and
amount data with the reported duplicate-recipient/three-wallet shape, not a
replay of production records or a production preflight.

**213-row backlog: structurally supportable**, demonstrated offline with the
reported lane counts. This is not a claim that every live row currently satisfies
ownership, balance, funding, reserve or human-approval requirements.

Remaining risks/limits:

- No live MySQL/MariaDB integration or production wallet inspection was run.
  Database casting and exact predicates were exercised through strict test doubles.
- Approval packages containing previously rounded values must be regenerated
  through a separately authorized operator workflow; this task did not regenerate
  or alter production artifacts.
- Interrupted account-credit or payout commits without sufficient durable
  completion evidence remain HOLD for investigation; no history is reconstructed.
- Legacy float helpers outside payout preparation were not broadly rewritten.
- Storage failure cannot guarantee a durable report; the attempt barrier and HOLD
  preserve conservative recovery behavior when retained evidence is incomplete.

No deployment, production coordinator, production preflight, production balance
or earning mutation, wallet send, ledger edit, service restart, legacy backend
loop or production cleanup was performed.

Recommendation: **READY FOR REVIEW**. Deployment and production recovery remain
outside this code-and-tests task.

RESULT=BADPOOL_L3_196_PAYOUT_BACKLOG_PATH_REPAIR_READY
