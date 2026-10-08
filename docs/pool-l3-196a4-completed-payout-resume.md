# POOL-L3.196A4 — completed-payout batch resume

Classification: **PASS (local code and deterministic tests)**

Recommendation: **READY FOR REVIEW**

Parent: POOL-L3.196A3. No production action is part of this result.

## Source and contract

Base: `7e30f2fb3f3e1156d80657f42262bc1340886f11` (locally available
`origin/main`). Isolated branch: `repair/l3-196a4-completed-payout-resume`.
The desktop working checkout and its unrelated changes were not modified.
Shell network fetch was unavailable; the GitHub connector independently
confirmed the upstream `main` runner content matched the inspected base.

Before modifying code, read the desktop copies of
`badpool-guarded-payout-operator-runbook.md` (11,106 bytes) and
`badpool-live-payment-coordinator.md` (30,061 bytes). Their lifecycle contract
is READY -> completed-payout HOLD -> read-only proof -> separately confirmed
ledger-only apply -> RECONCILED. A send is never repeated to repair closeout.

### Root-cause qualification

The inspected source does **not** substantiate a hard Scrypt-only algorithm
refusal inside the completed-payout detector. `paymentBatchRunReport()` emits
the unconditional non-Scrypt warning "not implemented and cannot be mutated".
It constructs a generic adapter without a lane configuration. The adapter's
`lane()` falls back to `scryptCompatibility()`. These are the Scrypt-specific
command/default restrictions found; the warning is not itself an enforcement
gate. The exact deployed artifact was not inspected in this code-only task.

`BadpoolPaymentBatchRunner::enforceCompletedPayoutBoundary()` already recognizes
completed rows for arbitrary algorithms. Previously it required positive unique
created IDs, an available exact-row reader, matching row count and ID set,
`completed=1`, and a nonempty tx. It did not validate ownership, coin, database
algorithm, activation boundary, phase state, or artifact checksums at that
boundary. The ordinary loop skipped phases with historical passing entries.
Coordinator ledger scans validate owner/coin/algorithm/boundary, but direct CLI
resume did not inherit those guarantees. CLI defaults were overwritten by the
ledger, including `only`, without first checking the requested lane.

Scrypt's configured coin, boundary, wallet account, and historical compatibility
defaults are legitimate Scrypt identities. They remain unchanged. A blanket
non-Scrypt warning and the absence of an ownership-bound direct resume are not
appropriate authority for completed batches owned by other commissioned lanes.
No legacy financial confirmation string or financial algorithm is changed.

## Implementation and file scope

| File | Change |
| --- | --- |
| `web/yaamp/core/backend/BadpoolPaymentBatchRunner.php` | Registry-owned boundary resume before the financial loop; exact evidence validation, nonblocking batch lock, shared completed-row detector with coin binding, checked boundary persistence. |
| `web/yaamp/commands/BadpoolGuardCommand.php` | Require ownership at CLI wallet boundaries; non-Scrypt CLI resume cannot fall into legacy financial execution; replace stale warning. |
| `tests/badpool_completed_payout_resume_harness.php` | Deterministic runner, real CLI dispatch, refusal, preservation, and existing proof/apply integration tests. |
| `tests/fixtures/completed-payout-531.json` | Authoritative supplied payout-531 identifiers/state/tx, without credentials. |
| `docs/badpool-live-payment-coordinator.md` | Document the bounded resume contract and operational separation. |
| `docs/pool-l3-196a4-completed-payout-resume.md` | This review evidence. |

`BadpoolLivePaymentLaneRegistry::fromOwnershipEnvelope()` supplies the lane and
strict schema/lane/coin/database-algorithm/boundary validation, including payout
commissioning. Explicit CLI `only`, supplied coordinator ownership, and supplied
lane configuration must agree with this durable authority. It never constructs
a lane from the payout row or guesses a database algorithm from an operational
name. SHA256d fails the registry's payout-commissioning gate.

The new owned path requires phase 6 and READY (or an existing completed-payout
HOLD for a recheck), auto mode, the established scope, wallet stop, valid bounded
batch size, exact coin/database algorithm, valid nonempty scopes above the
activation boundary, successful latest phase entries 0–6, and unchanged retained
phase-file checksums. The phase-6 creation report must bind exactly the same
created payout IDs and count. Reconciled batches cannot regress to HOLD.

The existing detector then reads exactly those payout IDs, validates their coin,
completed status and nonempty transaction IDs, and sets the existing HOLD. It
does not introduce a second closeout implementation. Historical unowned runner
fixtures/legacy financial recovery are unchanged; the operator CLI requires
ownership at the wallet boundary. This does not generalize non-Scrypt CLI
financial-phase recovery.

## Lane and payout results

| Lane | Coin | Database algorithm | Resume / existing proof / existing apply |
| --- | ---: | --- | --- |
| `live-scrypt-v1` | 1267 | `scrypt` | PASS / PASS / RECONCILED |
| `live-skein-v1` | 1268 | `skein` | PASS / PASS / RECONCILED |
| `live-yescrypt-v1` | 1266 | `yescrypt` | PASS / PASS / RECONCILED |
| `live-groestl-v1` | 1269 | `badcoin-groestl` | PASS / PASS / RECONCILED |
| `live-sha256d-v1` | 1270 | `sha256` | REFUSED before database access |

The Skein fixture uses batch `20261003T041722Z-60ae157ef88b`, phase 6,
READY, `created_payout_ids=[531]`, completed payout row, reconciled send journal
and operation, and tx
`1f0d56712d4008da808796f236c887fbaa2d2246c67866a28f3f7d5486149ad7`.
It reaches exactly `HOLD_COMPLETED_PAYOUT_RECONCILIATION` without replaying a
financial phase. Remaining account/earning/phase-artifact fixture values are
synthetic; this is not a claim to have verified current production bytes.

## Mutation and wallet evidence

All seven financial adapter methods throw if called by these resume tests.
The guard command executor also throws, including any route to a wallet-send
command. The real adapter is used for its exact payout SELECT; the fixture
reader rejects any different SQL. There is no wallet gateway construction or
RPC call in the runner's completed-resume call path.

For every lane, before/after assertions show:

- Zero financial-phase calls, guard-command calls, and database-write calls.
- Byte-identical serialized payout rows, account balances, and earnings.
- Unchanged phase results, checksums, batch ID, ownership and financial scopes.
- Byte-identical phase files and reconciled send-journal fixture.
- Only ledger boundary metadata changes on resume.
- Existing read-only proof consumes the HOLD using an injected valid wallet
  proof, followed by the unchanged apply implementation producing RECONCILED.
- Apply reports `db_mutations=false`, `wallet_rpc_used=false`, and
  `wallet_sends=false`; identical apply replay is idempotent.

The boundary does not independently read the wallet-send journal, just as the
existing detector did not. Operator journal/transaction verification remains a
prerequisite, and the existing wallet proof remains a separate read-only step.
No recurring wallet sends or new wallet authorization are introduced.

## Validation

PHP CLI 8.5.11 on Windows. Commands use `-d error_reporting=22527` for harnesses
to omit unrelated existing PHP deprecation/strict notices; other diagnostics
remain enabled. Every harness returned exit 0.

**26 harnesses PASS / 0 FAIL:** 25 existing harnesses plus the new harness.
**New harness: 295 assertions PASS / 0 FAIL.** Existing harnesses do not all
print assertion totals, so their aggregate assertion count is not invented.

All below are `tests/<name>.php`:

| Harness | Result |
| --- | --- |
| badpool_completed_payout_batch_closeout_harness | PASS |
| badpool_completed_payout_closeout_apply_harness | PASS |
| badpool_completed_payout_resume_harness | PASS, 295 checks |
| badpool_completed_payout_wallet_proof_transport_harness | PASS |
| badpool_confirmed_block_payment_delay_override_harness | PASS |
| badpool_explicit_payment_coordinator_units_harness | PASS |
| badpool_legacy_payout_send_guard_harness | PASS |
| badpool_live_payment_coordinator_harness | PASS |
| badpool_live_payment_lane_configuration_harness | PASS |
| badpool_live_status1_payment_selection_harness | PASS |
| badpool_multi_lane_wallet_send_harness | PASS |
| badpool_multi_wallet_apply_harness | PASS |
| badpool_multi_wallet_production_preflight_harness | PASS |
| badpool_multi_wallet_production_repository_harness | PASS |
| badpool_multi_wallet_send_state_machine_harness | PASS |
| badpool_payment_batch_preview_harness | PASS |
| badpool_payment_batch_run_harness | PASS |
| badpool_payout_row_apply_guard_harness | PASS |
| badpool_readonly_wallet_cli_transport_harness | PASS |
| badpool_readonly_wallet_cookie_auth_harness | PASS |
| badpool_skein_payout_preparation_harness | PASS |
| badpool_wallet_funding_guard_harness | PASS |
| badpool_wallet_proof_closeout_guard_harness | PASS |
| badpool_wallet_send_apply_guard_harness | PASS |
| badpool_wallet_send_dryrun_guard_harness | PASS |
| badpool_yescrypt_payout_preparation_harness | PASS |

Refusal coverage includes foreign lanes/configurations, missing or malformed
owners, wrong schema/coin/algorithm/boundary, Groestl's operational spelling
misused as database identity, incomplete/missing/malformed payout evidence,
changed creation scope, outside/duplicate payout IDs, inappropriate phase/state,
failed or missing phases, changed artifacts/checksums, foreign directory,
concurrent resume and already-reconciled batches. Multi-row exact scope is
also exercised. Real CLI tests cover all five lanes and missing-owner and
foreign-lane refusal for all four commissioned lanes.

`php -l`: **3 PASS / 0 FAIL**, covering both modified production PHP files and
the new harness. JSON fixture parses successfully. `git diff --check` passes.
No native/build-system files changed; no native rebuild applies to this patch.

The final review diff is limited to the six listed files. Commit identity and
final clean working-tree status are reported in the task response, rather than
embedding a self-referential commit hash in this file. No push, PR, merge,
deployment, live resume, live closeout apply, wallet transaction, coordinator
restart, or timer enablement was performed. Production payout 531 remains for
separate authorized recovery; all incident HOLD instructions remain in force.
