# POOL-L3.199I-R3 — Real Stratum reconnect

**Authority:** BadPool L2. **Classification:** PARTIAL. The real reconnect and accounting assertions passed on the final isolated binary. The single R2 exploratory allocator abort was not reproduced in three final runs, so its cause remains unknown. Canary, deployment, and miner cutover remain HOLD.

## Scope and identities

Review base: `04a25a32b00a1344c7cbd37a97ef7ddae92e0e10`. Final tested source and runner: `ca3b39848b6dfdb72df4e4b6b5f9d5b125bbd4e6`, branch `codex/pool-l3-199i-r3-reconnect`. The final runtime source was already present at `8cdfdb3245103e16f5ebdddbf0b2aa2f20dba0ef`; the later commits only strengthened evidence capture in the Python runner. Evidence commits: `64f59bbde02d7f3c41f236a338229f945259578e` plus the child commit containing the final raw snapshots and this report; see `git log -1` in the standalone checkout. The checkout is `C:\Users\Michael\AppData\Local\Temp\pool-l3-199i-r3-independent`, with its own Git directory and no remote or linked worktrees. The final Stratum binary SHA-256 is `8d7a2cfe8f7134776df9e84cad9c00c875be6cfa2ac66ad1be94aadeaa52456d`. Nothing was pushed or deployed.

The Badcoin AI Development Standards v1 and Badcoin L3 SOP v1.0 supplied operating guidance; this R3 directive supplied the test and mutation authority. Earlier L3.199I, R1, and R2 results were not rerun or counted as new evidence. The R2 `error=6` still lacks its original SQLSTATE, error text, and same-attempt server log; it did not recur here and remains unexplained.

## Findings and source repair

The R2 fake upstream used a remote job price of `1000`, while this fixture's local synthetic BAD job had computed profit about `1,005,828`. Job sorting therefore filled the one miner with local work first and never called remote `job_assign_client()`. R3 raised only the disposable fake job's price and speed to make remote work eligible. With diagnostic tracing, the remote job reached `job_assign_client()` and the process exited `-11` (SIGSEGV). The preserved core backtrace places the failure in `job_assign_client()`. The source checks `client->coinid != job->coind->id` for all jobs when autoexchange is off, but a remote job has `coind == NULL`. The final patch applies that coin restriction only to local jobs.

After that repair, Stratum emitted a real `client.reconnect`, and a second connection subscribed using the original notify ID. Its true-branch debug line was `reconnecting client locked to 0`; the subscription returned the upstream extranonce, and the same worker authorized and submitted. A conflicting reauthorization exposed another error: `client_authorize()` sent error 21, returned false, closed the already-authorized session, and cleared its worker row. The final patch returns the successful error-write result so the rejected authorization leaves that session and row intact; a failed socket write still closes it. A second valid submit after the rejection verifies the inherited identity remains usable.

The remote submit is not a local BAD durable round. Its `shares` row has `coinid=0`, `jobid=199`, and `round_id=NULL`; `accepted_work` remains the original local BAD owner. The two accepted remote submits aggregate into one worker/job share row. On final snapshot run 02, the local row was `id=1, userid=1, workerid=1, coinid=1, jobid=0, valid=1, difficulty=0.000015, round_id=1`; the remote row was `id=2, userid=1, workerid=1, coinid=0, jobid=199, valid=1, difficulty=0.000031, round_id=NULL`. The aggregate weight is approximately twice the local single-share weight. Its JSON contains raw accepted-work, worker, share, earning, and candidate rows before and after each rejected request; those rows were identical for each pair in this run. The runner also allows a previously accepted share to flush asynchronously between snapshots, but requires the owner, worker, earnings, candidates, and claimed-worker share count to remain safe and verifies the final remote share weight against exactly two accepted submits. No claimed-worker accounting row appeared. The three initial final reconnect repetitions and the final snapshot run each exited Stratum with status 0 after requested shutdown.

## Isolated topology and reproduction

Each attempt used a new `unshare -n` network namespace with only loopback and an empty route table; all listeners and connections were `127.0.0.1`. The runner started fresh MariaDB `10.11.18-MariaDB-0+deb12u1` in a unique `/tmp/pool-l3-199i-*` datadir/socket, applied 21 fresh-schema migrations, started a fake BAD HTTP RPC, fake upstream Stratum, and the actual built Scrypt Stratum binary. The JSON records the namespace, database/socket/datadir, fixture config, restricted grants, listener ownership, raw miner and upstream frames, result rows, source/binary identity, and process exits. Every attempt has a paired Stratum stdout/stderr log and MariaDB error log in [`results`](results/). The two abrupt crashes left their Stratum stdout files empty; their JSONs preserve the last debug-log content, and the confirmed `-11` JSON includes the core backtrace. Successful attempts have populated Stratum logs. No public or production route was present.

Build from this checkout:

```text
wsl -d Debian -u root -- make -C /mnt/c/Users/Michael/AppData/Local/Temp/pool-l3-199i-r3-independent/stratum/iniparser
wsl -d Debian -u root -- make -C /mnt/c/Users/Michael/AppData/Local/Temp/pool-l3-199i-r3-independent/stratum -j2
```

Both final build commands exited 0. `git diff --check 04a25a32b00a1344c7cbd37a97ef7ddae92e0e10 ca3b39848b6dfdb72df4e4b6b5f9d5b125bbd4e6 -- stratum/job.cpp stratum/client.cpp review/l3-199i/isolated_smoke.py` exited 0. The complete source/test patch is [`source-test.diff`](source-test.diff).

Run the bounded test with a new `R2_RUN_ID` for each attempt; the historical runner name and environment variable are retained from R2. The output is copied without alteration into this R3 evidence directory.

```text
wsl -d Debian -u root -- env I_SOURCE_COMMIT=ca3b39848b6dfdb72df4e4b6b5f9d5b125bbd4e6 R2_RUN_ID=<unique-id> R2_CONCURRENCY=1 H_VARIANT=reconnect_true unshare -n -- python3 /mnt/c/Users/Michael/AppData/Local/Temp/pool-l3-199i-r3-independent/review/l3-199i/isolated_smoke.py
```

For focused regressions, substitute `H_VARIANT=auth_contract` or `H_VARIANT=guard_startup`. All attempts were sequential; no public route, mainnet RPC, wallet, or production credential was used. The runner's R2 guard diagnostics remain present and would preserve SQLSTATE, MariaDB error and same-attempt logs if a guard startup failure recurred.

| Attempt (raw JSON) | Source | Result and decisive evidence |
| --- | --- | --- |
| [`r3-baseline-01`](results/result-r3-baseline-01.json) | `04a25a32` | PARTIAL, exit 1; upstream frames arrived, no reconnect with low job price |
| [`r3-diag-01`](results/result-r3-diag-01.json) | `13ea91f4` | PARTIAL, exit 1; remote job created, never assigned |
| [`r3-priority-01`](results/result-r3-priority-01.json) | `6a244660` | PARTIAL, exit 1; remote assignment reached, process crash raced exit collection |
| [`r3-crash-capture-01`](results/result-r3-crash-capture-01.json) | `c53aad6f` | FAIL, exit 1; Stratum `-11`, core backtrace in `job_assign_client()` |
| [`r3-reconnect-fixed-01`](results/result-r3-reconnect-fixed-01.json) | `a1e62840` | PARTIAL, exit 1; real reconnect and submits; conflicting reauthorization removed worker row |
| [`r3-reconnect-repaired-01`](results/result-r3-reconnect-repaired-01.json) | `0aa864c0` | PARTIAL, exit 1; all protocol checks passed; test incorrectly expected one row per remote submit |
| [`r3-reconnect-aggregate-01`](results/result-r3-reconnect-aggregate-01.json) | `ba8651c6` | PASS, exit 0; true reconnect, aggregated share row, process 0; diagnostic build |
| [`r3-final-reconnect-01`](results/result-r3-final-reconnect-01.json), [`02`](results/result-r3-final-reconnect-02.json), [`03`](results/result-r3-final-reconnect-03.json) | `8cdfdb32` | PASS, exit 0 each; all reconnect, identity, database, and shutdown checks; no allocator abort |
| [`r3-final-auth`](results/result-r3-final-auth.json) | `8cdfdb32` | PASS, exit 0; ordinary authorization regression |
| [`r3-final-guard`](results/result-r3-final-guard.json) | `8cdfdb32` | PASS, exit 0; complete guarded startup |
| [`r3-final-snapshots-01`](results/result-r3-final-snapshots-01.json) | `c12187d2` | PARTIAL, exit 1; the first snapshot comparison crossed an asynchronous flush of the previously accepted local share |
| [`r3-final-snapshots-02`](results/result-r3-final-snapshots-02.json) | `ca3b3984` | PASS, exit 0; complete raw before/after row evidence and final aggregated share ownership; same final binary |

Every row above has `mariadb-<attempt>.log` and `stratum-<attempt>.log` under [`results`](results/), including failures. The earlier R2 exploratory `free(): invalid pointer` did not recur in the three initial final reconnect runs or the final snapshot run. Those runs exercised actual remote-job creation, assignment, reconnect, submits, and shutdown, but do not identify the old allocator abort's cause or prove it impossible. The R3 reproduced crash was SIGSEGV from the null coind dereference, not the earlier allocator message; the passing runs are meaningful regression coverage for that path.

## Handoff

The reviewed diff and full raw evidence are local for BadPool L2 source review. The bounded isolated real-reconnect path passed; the classification stays PARTIAL because the older allocator abort's cause is unresolved. Remote shares remain legacy aggregated records rather than BAD durable-round accepted-work, and this test covers only synthetic Scrypt in one isolated namespace. No production host, service, database, RPC, wallet, payment coordinator, miner route, old checkout, or linked worktree was accessed or changed. The next gate is BadPool L2 source review; canary, deployment, and miner cutover remain HOLD.
