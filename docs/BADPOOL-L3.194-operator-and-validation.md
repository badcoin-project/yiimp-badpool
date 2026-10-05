# BADPOOL L3.194 operator and validation notes

These are source instructions, not a deployment authorization. This change was tested only with synthetic local data and a simulated RPC endpoint. It does not install login hooks, send notifications, or authorize wallet actions.

The ownership authority is the InnoDB accepted-work journal, not `shares`. `STRATUM:durable_rounds` defaults to false. When enabled it applies to the five BAD lanes: `scrypt`, `yescrypt`, `skein`, `sha256`, and `badcoin-groestl`. Other coins retain legacy handling, excluding journal-owned operational share rows.

Each work identity is `(coin_id, db_algo, double-SHA256(serialized 80-byte header))`. The full header is retained and checked on replay. It commits to the previous block, effective version bits, coinbase/merkle root including extranonces, ntime, nbits, and nonce. Job IDs, process IDs, user IDs, and worker IDs cannot manufacture another unit with the same header. A replay cannot change the original account, worker, difficulty, round, or sequence. A conflicting identity fails closed.

`round_lock` acquires an exclusive lane row lock across processes. Current reads use `FOR UPDATE`; ordinary snapshot reads are insufficient after waiting for a competing transaction. Accepted work commits its sequence and round before a successful miner acknowledgement or candidate RPC. A candidate takes the lane lock again to fix the exact cutoff, including all work committed before that lock acquisition. It creates its continuation round in the same transaction. There is one outstanding candidate intent per lane.

Ordinary work continues into that separate provisional round while an intent is pending or held. Further candidate dispatch is paused until the earlier intent resolves. This deliberately sacrifices candidate availability to preserve simple, provable ordering. A rejected closed round is merged into its continuation atomically; original round identity and intent history remain available. A held round is never moved or sealed without authoritative proof or audited adjudication.

Before a candidate RPC, a compare-and-swap commits `MAY_HAVE_DISPATCHED`. The exact persisted payload, lane, and intent must match. A single-attempt HTTP client snapshots the recorded endpoint, disables redirects and connection reuse, imposes a 30-second timeout, and requires the exact unique request ID in the response. No shared legacy RPC observation is used as proof. An uncertain marker commit acknowledgement prevents sending. Once the marker exists, no recovery submission is allowed.

`round_resume_never_dispatched` reloads a stored PREPARED payload during Stratum coin refresh. Its dispatch CAS permits only the first attempt and excludes every possible prior send. Accounting recovery never calls a daemon. It resolves retained conclusive original responses or enters HOLD for possible sends older than 60 seconds; each maintenance pass examines at most 100 actionable intents. Existing unresolved HOLDs without evidence do not starve later actionable recovery work.

An original matched `submitblock` response with explicit null error and null result proves acceptance. A matched nonempty BIP22 reject string, excluding all duplicate and inconclusive variants, proves rejection. Errors, missing fields, wrong IDs, transport failures, and current chain observations do not prove an original outcome. The SQL resolution routine independently checks the retained envelope and provenance before sealing. Core source assumptions are checked by the retained L3.195 probe.

The original response commits separately before sealing. If sealing fails, ownership stays held and the saved response can finish sealing on restart. Attribution is immutable, uses the journal's assigned difficulty, and snapshots account fee/donation state at seal as the existing capture path did. Winning hash difficulty is block metadata only. New records use attribution version 2; existing records default to version 1 / LEGACY and are not recalculated.

## Operator interfaces

Put the repository `ops` directory on the authorized operator's PATH, or provide an equivalent wrapper for `tools/badpool-round-status.php`. The operator environment supplies `BADPOOL_ROUND_DSN`, `BADPOOL_ROUND_DB_USER`, and `BADPOOL_ROUND_DB_PASSWORD`. The status identity must have SELECT-only access to the relevant round tables. PHP PDO MySQL and the standard `timeout` utility are required. Do not place credentials in script source or command arguments.

| Command | Behavior |
|---|---|
| `badpool-round-status` | Concise read-only HOLD summary |
| `badpool-round-status --json` | Counts, lanes, oldest age, bounded per-intent ownership/dispatch/evidence/dependency/notification details |
| `badpool-round-status --intent=123 --json` | Exact held intent investigation |
| `badpool-round-status --intent=123 --json --daemon-evidence` | At most three read-only RPCs, each with a two-second deadline and bounded response size |
| `badpool-round-status --events-after=0 --limit=25` | Durable notification interface; external consumer owns its own cursor/delivery state |
| `badpool-round-status --login` | Nothing when no HOLD exists; concise warning while held |

Read-only daemon evidence uses the recorded endpoint and externally supplied `BADPOOL_ROUND_RPC_USER` / `BADPOOL_ROUND_RPC_PASSWORD`. The whitelist is `getblockheader`, `getblockchaininfo`, and `getchaintips`. Every such observation is CURRENT_STATE_ONLY, MISSING, or INCONCLUSIVE. It never resolves or resubmits a candidate. The login path cannot request daemon evidence. Credentials and arbitrary original RPC response bodies are excluded from status output.

The login template is `ops/badpool-round-login-notice.sh`. It runs only in an interactive shell, waits at most three seconds, has no prompts, and performs no wallet/daemon/accounting mutation. A future approved installation may source this template from the operator-login mechanism. Nothing in this task installs it or changes `/etc/profile.d/`. Test logins use `bash --noprofile --norc -ic`, so host login files are never read or changed. If the database cannot be inspected, login shows a concise status-unavailable message instead of asserting that no HOLD exists.

HOLD_ENTERED events contain the lane, coin ID, round, intent, height, canonical hash, cutoff, entry time, reason, HIGH priority, downstream BLOCKED status, and inspection command. HOLD_RESOLVED events contain provenance, evidence reference, and held duration. Events are append-only and unique per transition. Delivery failure cannot change accounting. The poll interface sends no real notifications and introduces no reminder schedule. If the database itself is unavailable after a possible send, the pre-dispatch marker remains authoritative; the durable HOLD event is written when recovery can access the database again.

`tools/badpool-round-adjudicate.php` is a separate accounting-only interface requiring intent, coin, DB algorithm, round, canonical hash, actor, ACCEPT/REJECT treatment, reason, and evidence references. A privileged operator database identity is required. It refuses missing or mismatched identity, empty reason/evidence, non-HOLD state, and a retained authoritative original daemon response. Audit insertion and treatment resolution are one transaction. `OPERATOR_ADJUDICATED_ACCEPT` / `OPERATOR_ADJUDICATED_REJECT` set a separate accounting outcome; the unknown daemon outcome remains null. The audit references an immutable intent identity and cannot be updated or deleted. There is no force-continue switch.

## Migration and forward boundary

Apply `sql/2026-10-04-durable-share-rounds.sql` only as part of a separately approved cutover. The existing live attribution migration must already exist. Revised PHP readers require the new columns, so migration ordering matters even while Stratum's new mode is disabled.

The migration writes its version-2 completion marker last. Durable startup refuses an incomplete migration or nontransactional participating tables. Once a lane has a durable current round, startup refuses legacy mode for that algorithm, and the database rejects new legacy candidates for that commissioned lane. These guards supplement the coordinated cutover; they cannot replace stopping an older executable that lacks the startup guard.

This implementation accepts standard 80-byte BAD headers on the five supported DB algorithms. Auxiliary/merged-mining and extended headers are refused in durable mode. Operational share rows preserve the original round label; after a proven rejection, the journal's effective round owns the merged work. Version-2 candidates use sequence cutoffs rather than operational share IDs. The existing proportional display label `share_window` remains for reader compatibility; `accounting_version=2` and the durable round binding identify the authority.

Before commissioning, verify `blocks`, `accounts`, `coins`, `earnings`, `live_block_candidates`, and `live_block_attributions` are InnoDB. The journal refuses acceptance if this prerequisite is not met. This task does not assume or alter production storage engines. Any required engine conversion needs a separately reviewed migration.

All old writers for a commissioned lane must be stopped/drained under an approved deployment procedure, and pre-boundary buffered work and unfinished legacy captures must remain accounted through the legacy path. The migration does not import historical work or unfinished payment batches. Start all participating BAD lane writers consistently in durable mode after that boundary; mixed legacy/durable writers are unsupported. Do not commission by toggling one writer in a mixed fleet.

There is no payout redesign. Only sealed v2 candidates reach the existing per-block/per-user earnings contract. SQL and application guards enforce this at earnings creation and update, live maturity, and payment inventory selection. Account credit, delay, payout preparation, coordinator ownership, and human wallet approval remain in their existing implementations. Legacy aggregate block processing excludes v2 candidates and round-labelled shares. Reorg/orphan handling can change downstream block/earnings state, but cannot recycle sealed work or reinterpret the original outcome.

## Reproducing the local tests

The integration harness is deliberately isolated: it verifies the server datadir before dropping the synthetic `round_test` database, uses only a Unix socket, starts no system services, and reads no production configuration. The local run used extracted Ubuntu MariaDB 10.3.39 binaries and PHP 7.4.3 database extensions under `/tmp/badpool-l3194-test-tools/extracted`. They were extracted, not installed. Equivalent tooling may be substituted in the harness constants for another local Linux environment.

1. Provide extracted server/client binaries and their runtime dependencies in that temporary tools directory.
2. Run `python3 tests/durable_round_test_setup.py` to initialize the socket-only disposable database.
3. Compile the driver with production `durable_round.cpp`, `share.cpp`, and `json.cpp`:

```sh
g++ -std=c++17 -ffunction-sections -fdata-sections -Wl,--gc-sections \
  -Istratum -I/usr/include/mariadb tests/durable_round_driver.cpp \
  stratum/durable_round.cpp stratum/share.cpp stratum/json.cpp \
  -lmariadb -lcurl -lcrypto -lpthread -o /tmp/badpool-round-driver
python3 tests/durable_round_mysql_harness.py
python3 tests/durable_round_validation.py
python3 tests/durable_round_daemon_recovery_preflight.py --badcoin-source /path/to/Badcoin
python3 tests/durable_round_test_teardown.py
```

The driver stubs only process globals, list plumbing, hash utility wrappers, and the operational SQL adapter. Its list mutexes reproduce Stratum's recursive-list behavior. Journal, intent, dispatch, aggregation, recovery, schema routines, PHP status, and PHP adjudication code are the actual implementation. HTTP failures and chain responses are simulated; process death after dispatch is an actual driver kill and transport timeout is an actual 30-second timeout. This is not a production miner/network/load test.

The full Stratum build also passed after building its bundled iniparser library. On this host the MariaDB headers required a temporary `mysql` include alias because the repository includes `mysql/mysqld_error.h`; build overrides were `SQLFLAGS="-I/usr/include/mariadb -I/tmp/badpool-l3194-includes" LDFLAGS=-lmariadb`. No build-system install target or Stratum executable was run.

Production throughput, coordinated cutover, notification-consumer wiring, operator authentication/credential provisioning, and login-template installation remain deployment concerns. The opt-in flag is not an authorization to deploy.
