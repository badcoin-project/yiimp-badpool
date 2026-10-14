# POOL-L3.198A: bounded Scrypt phase-6 recovery

## Refusal and fix

Batch `20261004T072007Z-d73ffc159420` has a committed phase-5 credit for earnings `20144` and `20145`, account `79`, coin `1267`. Their coin-precision total is `4541.66271784 BAD`. The account's authoritative database text is `4541.6627178399995`; the earlier phase-6 approval used `4541.662717839999`. Since the selected scope and debit checksums include `current_balance`, the old approval cannot authorize the new account snapshot. The guarded apply returns `checksum_mismatch` before starting a payout transaction. This is the correct refusal for a stale approval.

Payout preparation now converts the authoritative balance **text** to an eight-decimal BAD payout amount by decimal-string rounding. It retains the unrounded `current_balance` in the approval and requires both numeric balance equality and `CAST(balance AS CHAR)` equality in the guarded account update. A concurrent balance change therefore rolls back the payout insert. The amount sent to the payout row is `4541.66271784`; the account is debited to zero only after the exact old balance guard succeeds. No older approval checksum is accepted under the new code.

## Recovery boundary

The recovery commands below are limited to an owned Scrypt batch already at a phase-6 `HOLD` with no created payout IDs. The runner verifies durable, SHA-256-bound phase 0–5 artifacts before either recovery step. It never replays those phases. A phase-6 approval regeneration reads current candidates and writes a new, uniquely named package. It leaves the original package, prior refusal, and earlier ledger entries intact. It does **not** apply payout rows. A later explicit resume requires the exact SHA-256 of that new package, checks its batch scope and file hash, and then runs the existing guarded payout-row apply. The apply recomputes its approval and all selected checksums from live account data. Each apply attempt is durably retained, including definite refusal and committed outcomes. An unresolved attempt barrier blocks another apply.

These steps create no wallet send or completed payout marker. `READY_FOR_WALLET_APPROVAL` is the terminal boundary for this procedure.

## Operator procedure

Run only after deploying and reviewing this change. Use the actual batch directory under `runtime/badpool-payment-batches`; keep the existing ledger, `payout-row-packages.json`, phase-6 refusal report, and their SHA-256 values as immutable evidence. Do not edit these files. Read-only database checks must show coin `1267`, account `79`, exact balance text `4541.6627178399995`, earnings `20144` and `20145` at status `2`, and **zero** phase-6 payout rows. Confirm the ledger remains `HOLD` at `current_phase: 6`, with successful phases 0–5 and no created payout IDs. A changed balance, extra selected account, payout row, missing artifact, or unresolved `payout-row-apply-attempt.json` is a stop condition.

From `/srv/badpool/yiimp-badpool/web`, generate the new approval:

```sh
php yaamp/yiic.php badpoolguard batch-run \
  --resume-batch-id=20261004T072007Z-d73ffc159420 \
  --only=scrypt --phase6-approval-only=1 --format=json
```

Retain the JSON output and the returned `package_path`. Independently calculate the file's SHA-256 and compare it with `package_sha256` in the output and the ledger's `phase6_recovery_approvals` entry. Review the selected account list, raw current balance, eight-decimal amount, projected zero balance, and every approval checksum. The expected selected scope is **only account 79**, coin `1267`, payout/debit `4541.66271784 BAD`, raw balance `4541.6627178399995`. This step changes only batch evidence files; it does not mutate the database.

**Stop here for explicit human approval of that exact package SHA-256 and selected amount.** Do not use the old phase-6 approval, an unreviewed regenerated approval, or a checksum copied from another batch. After approval, an operator may run the single guarded phase-6 resume with the reviewed SHA-256:

```sh
php yaamp/yiic.php badpoolguard batch-run \
  --resume-batch-id=20261004T072007Z-d73ffc159420 \
  --only=scrypt --phase6-approval-sha256=<reviewed-package-file-sha256> \
  --format=json
```

Require a passing result, one created payout ID, one payout row for account `79` and coin `1267` at `4541.66271784`, account balance zero, unchanged status-2 earnings, and `READY_FOR_WALLET_APPROVAL`. Retain the new payout apply report and archived attempt file with their SHA-256 values. If the result is `HOLD` or `refused`, inspect its retained failure evidence; do not repeat an apply while an unresolved attempt barrier exists. Do not invoke wallet send, restart a service, adjust balances directly, or replay account credit as part of this recovery.

## Local regression

`tests/badpool_phase6_precision_recovery_harness.php` reproduces the stale checksum using the two supplied balance texts, checks eight-decimal rounding and the exact account update predicate, proves rollback after a concurrent balance change, and exercises approval-only regeneration and explicit phase-6 resume without earlier phase calls. The existing payout-row and batch harnesses remain part of the verification set.
