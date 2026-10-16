# POOL-L3.199G — durable schema startup guard

**Base:** `809ed8f47283962a52e69fce39d1a4b01fb0643d`
**Scope:** source change and disposable MariaDB 10.11 validation only. No production deployment, migration, service, wallet, or payment action is authorized by this document.

## Startup behavior

With `STRATUM:durable_rounds=true`, `round_startup()` calls `round_schema_assert()` before `round_recover()`, Stratum registration, coin loading, job creation, or listener creation. Any SQL error, missing/hidden metadata, mismatched signature, missing guard, or unexpected guard result refuses startup. The guard does not create or repair objects. Legacy mode retains its prior registration-before-`round_startup()` order and its commissioned-lane refusal rule.

The new `sql/2026-10-16-durable-schema-guard.sql` is an **additional forward migration** after `2026-09-06-live-block-accounting.sql` and `2026-10-04-durable-share-rounds.sql`. Its `SQL SECURITY DEFINER` procedure reads metadata and returns its own definition hash only after every check passes. The C++ caller pins that self-definition hash. The existing marker remains exactly one row with `version=2`; a v2 database without the new guard refuses startup. Applying the migration requires a separately authorized future database task. Stratum never applies it automatically.

## Derived contract

| Scope | Enforced definition |
| --- | --- |
| Tables/engines | Exact names, `BASE TABLE` kind, and InnoDB engine for `round_lanes`, `share_rounds`, `accepted_work`, `round_intents`, `round_resolution_audit`, `round_operator_events`, `round_schema_version`, `live_block_share_cursors`, `live_block_candidates`, `live_block_attributions`, `shares`, `blocks`, `accounts`, `coins`, `earnings`. |
| Migration-owned columns and indexes | Full ordered metadata signatures for the seven `round_*`/`accepted_work` tables and the three `live_block_*` tables, including type, nullability, default, extra, charset/collation, index name, uniqueness, column order, prefix and type. The source migrations create or alter these definitions. |
| Round foreign keys | Referencing/referenced names and columns, order, and update/delete/match rules for the six round-owned tables with FKs. |
| Legacy downstream columns/indexes | Named, compatible columns used by round journal, stored procedures, legacy share writing, and block persistence in `accounts`, `coins`, `blocks`, `earnings`, and `shares`; base primary keys and the migrated `shares.operational_round` index. Legacy widths may vary, so this is a named/type/size compatibility check rather than a full hash of every unrelated legacy column. |
| Procedures | Exact names, type, security mode, SQL access/mode, full body hash, and parameter metadata for `round_lock`, `round_hold`, `round_resolve`. `round_schema_assert` must exist and its body hash must match the C++ pinned hash. |
| Triggers | All 22 pinned trigger names and their table, timing, event, order, SQL mode, and full body hashes; an extra trigger on any protected table also changes the signature. This includes `round_work_guard`, the confirmed POOL-L3.199F gap. |
| Version | `round_schema_version` has its exact table/column/index definition and exactly one data row, `version=2`. Missing, wrong, or additional rows refuse startup. |

These signatures were derived by applying the pinned 2026-09 and 2026-10 migrations to the existing minimal fixture on disposable MariaDB **10.11.18**, then reading `information_schema` under the migration owner. The signature probe was development-only; the checked-in SQL contains literal expected digests. The new SQL file is forced to LF by `.gitattributes` so its own body hash is stable across Windows checkouts. Different MariaDB metadata formatting, SQL mode, inherited charset/collation, or a deliberate future schema change may require a reviewed new contract and binary hash; the current guard intentionally refuses rather than guessing compatibility.

## SQL identity and privilege boundary

MariaDB 10.11 exposes tables, columns and indexes to a `SELECT`/DML Stratum identity, but returns `NULL` for its trigger and routine bodies; `SHOW CREATE TRIGGER` is denied without `TRIGGER`, which would allow schema-changing operations. A disposable privilege probe confirmed this. The guard procedure therefore runs as the migration-owned definer with full metadata visibility. The test Stratum identity had only `SELECT, INSERT, UPDATE, DELETE` on the disposable database and **routine-specific `EXECUTE`** on the three existing round procedures and new guard. It had no `TRIGGER`, `ALTER`, `CREATE`, or `DROP` grant. It passed the complete-schema check and journaled one synthetic share. Revoking its guard `EXECUTE` grant refused startup.

For future deployment, the migration owner/definer must be an approved stable identity with metadata visibility; a missing or insufficient definer causes a startup HOLD. Keep that definer separate from the runtime Stratum credential, restrict its login and DDL use by operational policy, and grant the runtime credential only the needed DML and named procedure executions. Verify the effective grants and exact definer before any service action. Do not grant `TRIGGER` or broad metadata/admin privileges to Stratum to make the check pass.

## Focused validation

Run from an independent clone, with extracted MariaDB 10.11 binaries and development libraries available:

```sh
python3 tests/round_schema_guard_mariadb_harness.py \
  --mariadb-bin /absolute/path/to/mariadb-10.11-bin \
  --json-out tests/round_schema_guard_results.json
make -C stratum/iniparser
make -C stratum -j2
```

The runner verifies the server version, random `/tmp` datadir, Unix socket and `@@skip_networking=1` **before** any fixture SQL. It recompiles the production `durable_round.cpp` into the existing driver, creates a complete disposable schema, tests under the restricted SQL identity, then recreates that same disposable database for each mutation. It ran **56** refusal cases: every one of 15 required tables, all four procedures, all 22 triggers, wrong engine, missing/wrong column, missing indexes, missing/wrong FK actions, changed/extra trigger, changed procedure and guard, three bad marker states, and revoked guard execution. Each refusal must exit the driver with status `1`, so an unrelated connection failure cannot count as success. A complete schema passes before and after the cases. The legacy uncommissioned path passes without the new procedure. The runner returns nonzero for both `FAIL` and `PARTIAL` (including unavailable 10.11 binaries).

Final [result JSON](../tests/round_schema_guard_results.json): `PASS` on MariaDB `10.11.18-MariaDB-0+deb12u1`, with 56/56 incomplete-schema refusals, complete-schema journal proof, least-privilege proof, and startup-order check. The full pinned-source Stratum build also completed in the independent clone; compiler warnings from unchanged source remain. The broader 34-fixture harness set was not rerun because this change is covered by the focused database and build checks.

## Limits and rollout boundary

This checks the durable-round schema and its required downstream contract at **startup**. It is not a full validation of every legacy Yiimp table/column or application workflow. A database administrator could alter schema after startup; protection against concurrent DDL requires operational permissions/change control. A malicious database administrator with power to replace both schema and the definer guard could forge a response; this check addresses incomplete and accidental mismatched migrations under an authoritative database administration boundary. The fixture and its account have no production RPC, listener, miner, wallet, or payout path. No production host, service, database, wallet, or payment coordinator was contacted or changed.
