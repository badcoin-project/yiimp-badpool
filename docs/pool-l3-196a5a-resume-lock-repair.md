# POOL-L3.196A5A — completed-payout resume lock repair

Classification: **PASS — ready for PR review**. SOP: BADCOIN L3-SOP v1.0. Authority: BadPool L2, PR_READY. Production and financial mutation: **NONE**.

## Baseline and repair

Authoritative `origin/main` was fetched and verified at `ea4e940836ddf71f879332fef8143d8192b24f05`. The isolated local branch is `repair/l3-196a5a-completed-resume-lock`. The primary checkout's unrelated changes were preserved. No independent upstream repair was present at this base.

The confirmed root cause was nested nonblocking acquisition of one batch's `resume.lock`: public `run()` acquired it, then private `runUnlocked()` called `resumeCompletedPayout()`, which attempted to acquire it again. The repair retains public `run()` as the sole lock owner for ordinary financial recovery and completed-payout resume. `runUnlocked()` and `resumeCompletedPayout()` remain private; neither is an unlocked alternate entrypoint. The inner routine retains path, symlink, ownership, lane, phase, artifact, row, and terminal-state validation. No payout arithmetic, wallet authorization, or commissioning logic changed.

The resume harness previously printed each of four lifecycle PASS lines after failed assertions. It now records the failure count before each lifecycle and prints PASS only if that lifecycle added no failures. A synthetic reporting check verifies recorded failure produces FAIL without PASS.

Files changed: `web/yaamp/core/backend/BadpoolPaymentBatchRunner.php`, `tests/badpool_completed_payout_resume_harness.php`, and this report.

## Validation performed

Windows PHP CLI 8.5.11 was downloaded from the official PHP distribution and matched its published SHA-256 `0ea96e0d2b9b737a6036f05cf4e95c49313faa6d0f27bd97edb2742503f0c043`. Both changed PHP files passed syntax checking. The completed-payout resume harness returned exit 0, **349 PASS / 0 FAIL**. All four commissioned lanes completed READY -> HOLD -> existing proof -> ledger-only RECONCILED. Real CLI dispatch and existing evidence-validation refusals passed. Financial phase, database-write, and wallet-send guards remained at zero calls.

A separate PHP process held the batch lock for the contention test. The competing resume was refused without reading payout rows or changing retained JSON evidence. After that owner released the lock, a legitimate resume succeeded. Lock availability was checked after successful operation, every evidence refusal, contention refusal, and a reader exception; a legitimate operation succeeded after the exception. The harness also verified the conditional lifecycle output. The public lock `finally` releases on both normal and exceptional paths.

The complete locally available `tests/badpool_*_harness.php` suite returned **59 / 59 exit 0**. The enumeration contained 59 scripts, and the retained result listed 59 distinct harness names with exit 0; none failed or was silently skipped. Specifically, the completed-payout resume, batch closeout, closeout apply, and wallet-proof transport harnesses passed. The payment batch preview/run, live payment coordinator, and live payment lane configuration harnesses also passed. BadPool L2 explicitly accepted this complete suite as satisfying A5A's regression gate in place of reproducing the unavailable VPS-only A5 nine-harness subset. `git diff --check` passed. The reviewed source change removes only the nested lock acquisition and release; the test changes add contention, release, exception, and reporting checks.

## Remaining gate and state

The preserved A5 evidence directory `/tmp/badpool-l3-196a5-validation.yRz8mo` and its exact nine-harness manifest were not available in this local checkout. The exact historical nine names remain **NOT VERIFIED**; L2 clarified that the verified broader 59-harness suite meets the intended A5A gate. The evidence directory was not modified, and no VPS access was used.

The bounded branch is committed, pushed, and submitted for PR review; the commit and PR identities are recorded in the task handoff response. Merge and deployment: **NONE**. Production source, payout 531, wallet, database, services, and timers: **UNCHANGED**. Payout recovery remains on HOLD.

Next authorized gate: PR review. Do not merge or deploy under this L3.

RESULT=POOL_L3_196A5A_RESUME_LOCK_REPAIR_PASS
