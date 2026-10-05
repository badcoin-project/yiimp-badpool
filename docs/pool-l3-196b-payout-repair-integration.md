# POOL-L3.196B — payout repair integration

Classification: **PASS — READY FOR PR REVIEW**. All validation below was rerun
after integration on 2026-10-05; it does not reuse the parent task's test output.

## Preserved work and refreshed main

- Original branch: `generalize/multi-batch-payment-waiting-queue`.
- Original HEAD: `59f0ab94bef5b61b3476ce90672d40cb017416d3`.
- Original status: no tracked modifications; pre-existing untracked `.worktrees/`.
- Original repair: nine files committed at that HEAD and already pushed to origin.
- Refreshed `origin/main`: `ccc3d7fb42fe953dc6921e920f662453e603e4d5`.
- PR #176: [merged durable share-round ownership](https://github.com/badcoin-project/yiimp-badpool/pull/176).
- GitHub reports MERGED at `2026-10-05T21:14:00Z`, with that exact merge commit.
- `git merge-base --is-ancestor` returned exit 0 for both the expected merge and
  implementation `617de912b3411887bf53c8672a521defd0dfd6cd` against refreshed main.
- Remote main was checked again before committing and still matched this SHA.

## Integration method and conflicts

Created `repair/l3-196-payout-backlog-path` directly from refreshed origin/main
without tracking main. Applied only the reviewed `59f0ab94` commit using
`git cherry-pick --no-commit`, so the completed repair can be published as one
bounded commit after validation. No reset, force push, or rewrite of the original
branch was used. The original branch and its pushed commit remain preserved.

There were **no conflicts**. Git automatically merged the phase adapter, the only
implementation file shared by PR #176 and this repair. The payment selector keeps
PR #176's `BadpoolRoundGate::sql()` condition; the payout preparation/apply hunks
retain the reviewed L3.196 behavior. No production-code integration correction
or redesign was needed.

Compared with the reviewed repair, the six modified implementation files are
unchanged except for the phase adapter's imported RoundGate require and selection
condition from main. Compared with current main, durable-round implementation,
schema, candidate outcome/replay handling, bridge, accounting and maturity gates
have no changes. A focused offline integration test and this report are the only
new integration additions.

## Preserved contracts

The actual payment SELECT requires either legacy attribution version 1 or a
version-2 candidate marked SEALED whose bound round is SEALED and whose matching
intent is RESOLVED, with both round and intent block IDs matching the candidate.
Unsealed/held candidates, held/unsealed rounds, pending/held intents, missing
round/intent evidence, ownership mismatches and unknown versions are excluded.
Already-credited or unmatured earnings remain excluded. Durable batch exclusions
and exact lane/account ownership remain effective. Legacy inventory behavior
remains unchanged where intended.

L3.196 preserves authoritative decimal text and exact debit checks; skips committed
credit during phase-6 recovery; retains refusal evidence and apply-attempt barriers;
binds funding observations to the exact variable wallet set; aggregates repeated
destinations only within one wallet while retaining individual payout audit data;
and rebuilds HOLD indexes with investigation next actions. Wallet approval,
funding/reserve checks, exact payout ownership, SEND_ATTEMPT_STARTED, txid journals,
manual recovery for uncertain outcomes and transactional reconciliation remain.

## Fresh validation

Local PHP CLI 7.4.3, Python 3 and synthetic fixtures only. Each suite used a fresh
temporary directory. All changed PHP files passed syntax validation.

| Validation | Result |
|---|---|
| `python3 tests/durable_round_validation.py` | **69 harnesses, 0 failures**, exit 0; includes all 22 parent payout/wallet/coordinator harnesses and share-round downstream/evidence, accounting, maturity, bridge and legacy PHP regressions |
| `python3 tests/durable_round_payment_integration_harness.py` | **76 checks PASS**, exit 0; actual integrated payment SELECT executed in an in-memory SQLite fixture across Scrypt, Groestl, Yescrypt and Skein |
| `php tests/badpool_payout_backlog_repair_harness.php` | **54 checks PASS**, exit 0 |
| `php tests/badpool_multi_wallet_apply_harness.php` | **51 checks PASS**, exit 0 |
| `php tests/badpool_multi_wallet_send_state_machine_harness.php` | **95 checks PASS**, exit 0 |
| `php tests/badpool_multi_wallet_production_preflight_harness.php` | **75 checks PASS**, exit 0 |
| PHP syntax: six changed implementation files and two changed PHP harnesses | **8/8 PASS** |
| Share-round runner's additional gate/accounting/maturity/tool PHP syntax checks | PASS |
| `git diff --check` and staged diff check | PASS |

Required regression mapping:

| Follow-up requirements | Fresh evidence |
|---|---|
| 1–2 | Exact `4541.6627178399995` approval/apply and adjacent-value refusal in backlog harness |
| 3–5 | Committed-credit phase-6-only recovery, concurrent/uncertain attempt blocking, one insert and fresh-runner idempotence |
| 6 | Atomic refusal reports, ledger SHA256, reason/classification/time/mutation status and credential redaction |
| 7–12 | End-to-end one/two/three/four wallet scopes and missing/extra funding refusals |
| 13–17 | Same-wallet destination aggregation, retained per-payout IDs/raw amounts/projections, cross-wallet separation, unselected-row exclusion and individual txids/reconciliation |
| 18 | **526–529 PASS**, four wallet operations, RECONCILED retries do not resend |
| 19 | **Synthetic 530–536 PASS**, three wallets, repeated recipients; invalid ownership/funding still fail closed |
| 20 | **Synthetic 213 rows PASS**: 201 Scrypt, 4 Skein, 8 Yescrypt, 0 Groestl, three fake wallet operations |
| 21–22 | HOLD overrides READY approval next actions, rebuilds blocking index and preserves authoritative ledger hash |
| 23–24 | Wallet exceptions/UNCERTAIN block blind resend; returned-txid restart reconciles without send or fresh funding calls |
| 25–27 | Actual RoundGate payment query: sealed v2 only; unsealed and AMBIGUOUS_HOLD dependent work excluded across all four payout lanes |
| 28 | Legacy version-1 payment row remains eligible in SQL integration; existing legacy bridge and payment regressions PASS in 69-harness suite |

## Files in the bounded repair

1. `web/yaamp/commands/BadpoolGuardCommand.php`
2. `web/yaamp/core/backend/BadpoolGuardedMultiWalletSend.php`
3. `web/yaamp/core/backend/BadpoolMultiWalletSendApply.php`
4. `web/yaamp/core/backend/BadpoolPaymentBatchRunner.php`
5. `web/yaamp/core/backend/BadpoolPaymentBatchPhaseAdapter.php`
6. `web/yaamp/core/backend/BadpoolLivePaymentCoordinator.php`
7. `tests/badpool_multi_wallet_send_state_machine_harness.php`
8. `tests/badpool_payout_backlog_repair_harness.php`
9. `tests/durable_round_payment_integration_harness.py`
10. `docs/badpool-l3-196-payout-backlog-repair.md` — historical parent report
11. `docs/pool-l3-196b-payout-repair-integration.md` — this integration report

No `.worktrees/`, runtime state, credentials, production artifacts or unrelated
local files are included. The parent report's baseline/status describe its
original validation snapshot, not this integration branch's final publication.

## Limits and recommendation

The new SQL integration executes the production query against SQLite; it does not
claim a new MySQL/MariaDB trigger or C++ daemon/network integration run. Those
implementations were not changed. Their merged PR #176 baseline remains the
authority, supplemented by freshly passing downstream PHP and actual payment
query tests. Live row eligibility and wallet funding remain unverified.

The payout HOLD and frozen recurring payment timers remain in force. No production
database, ledger, daemon, Stratum, service, coordinator, wallet preflight/send,
deployment or runtime reconstruction was touched.

Recommendation to BadPool L2: review the bounded PR against the verified merged
main. Do not interpret PR preparation as deployment or payout-recovery approval.
Commit SHA, push verification, PR identity and final status are returned in the
task's publication report. Do not merge as part of this task.
