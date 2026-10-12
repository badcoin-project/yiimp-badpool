# Guarded payout throughput: review design

This document describes offline source behavior only. It grants no wallet-send, batch-closeout, service, merge, or deployment authority.

## Same-recipient wallet operations

The approval document retains a separate, ascending entry for every payout ID. `BadpoolYiiExactMultiWalletPayoutRepository` requires one READY owning ledger for each ID and reconciles only the approved IDs in one database transaction. The planner partitions by wallet binding and source account. Within a partition it projects each payout to eight decimal places once, then adds the projected values as decimal strings for each destination. One destination appears once in the wallet RPC payload. Existing journal `payout_evidence` retains each ID, raw amount, projected amount, destination, and owning batch; `wallet_operation_evidence` retains the operation's payout IDs and recipient totals. Together they give the exact payout-to-recipient mapping. The approval checksum binds the individual entries; the operation ID and one-use capability bind the wallet operation and its recipient totals.

Rounding is half up at the ninth decimal digit, matching the existing planner. The exact raw total for each destination and wallet operation is retained. The planner refuses a scope unless the sum of per-payout projections equals the eight-place projection of each destination's raw sum and the whole wallet operation's raw sum. Thus a one-satoshi drift between per-payout and aggregate rounding cannot silently change value. A projected zero wallet operation is refused. This policy can reject some legitimate scopes; the operator must choose a smaller scope, never adjust an approved amount in place.

The journal and plan schemas remain v1, and the planner emits the same plan for every previously accepted scope. Existing journals are read without conversion or changed checksums. `SEND_ATTEMPT_STARTED` remains durable before gateway invocation. A returned txid is retained before any next wallet; uncertain outcomes and database failures remain manual-recovery HOLDs. No automatic resend is introduced.

**Deployment hold:** the deployed guard file has a separate stricter duplicate-recipient refusal. The source planner change must not be copied into production by itself. Existing completed-payout wallet proof compares a batch subtotal to the entire wallet transaction amount. A wallet transaction covering payouts in different batches therefore fails per-batch proof. An operation-wide wallet proof and exact journal-to-batch allocation are prerequisites to using same-wallet cross-batch aggregation in production.

The fetched main source includes the A13 recovery logic: it retains payout and wallet-operation evidence in the journal, compares an existing plan checksum, and skips fresh funding checks for previously attempted or txid-returned operations. This is a source-level compatibility observation, not byte equivalence with the deployed A13 handlers. Deployment requires fresh deployed hashes and a focused diff against those exact bytes; do not replace production files from this branch by broad Git synchronization.

## Grouped closeout contract and current blocker

A future closeout coordinator should use one exact `RECONCILED` journal as its discovery root. Read-only discovery must reject missing or ambiguous payout-to-batch ownership, noncommissioned lanes, unpaid siblings, and any mismatch between completed rows, their transaction IDs, and journal operation IDs. Read-only preview must prove the full wallet transaction amount once per wallet operation, then bind each owned batch's subset and phase artifacts to that operation proof. The current `completed-payout-batch-closeout` proof cannot do this: it expects its selected batch payout sum to equal the entire wallet debit.

One human closeout approval can safely cover a bounded, sorted batch set only when it binds every batch ID, complete proof-file SHA-256, journal checksum, and operation-proof checksum. Execution would first resume each exact batch through the existing completed-payout boundary, then call the existing ledger-only apply with the exact proof and checksum, and finally verify the terminal ledger. It must record a durable per-batch state (`PENDING`, `APPLYING`, `VERIFIED`, or `HOLD`) under a group checksum before each transition. On restart, a `VERIFIED` batch is checked again without applying; an `APPLYING` batch is reconciled from its actual ledger and proof before retry. A failure stops the group and leaves completed batches untouched. There must be no wallet or accounting gateway in the coordinator.

The present proof and apply contracts do not supply operation-wide proof allocation or a group progress journal. Grouped closeout is **BLOCKED** until those contracts and their interruption, replay, and no-mutation tests are implemented and reviewed. Continue individual batch closeout under the current operator runbook for wallet operations whose proofs satisfy its full-transaction amount check. Human wallet-send approval remains mandatory for every exact send scope.

## Full-pipeline inventory contract

An unfinished payout count covers only the final unpaid-row stage. A reliable inventory needs one read-only production snapshot for each of the four commissioned lanes, with separate units for blocks, earnings, batches, accounts, and payout rows. The snapshot must include:

1. Lane registry and schema identity, source HEAD plus deployed handler hashes, timer and service state, and observation time.
2. Block counts by lane, live-boundary eligibility, and category (`new`, `immature`, `generate`, exceptional), with oldest/newest IDs and timestamps. Count distinct block IDs when joined to earnings.
3. Earning counts by coin, status, maturity marker, block category, and age bucket. Distinguish immature status-0 earnings from mature status-1 earnings and record distinct block and account counts. Do not sum both a block amount and its earnings.
4. Coordinator ledger counts by lane, batch state, phase, and age; exact selected earning, block, account, and created payout ID sets for unresolved batches; waiting payment-delay, READY, HOLD, and reconciliation queues. Detect overlaps before counting unclaimed eligible earnings.
5. Current account-credit and payout-preparation phase counts, account counts, and exact phase artifact checksum validity for unresolved batches.
6. Unfinished payout counts and exact ID ranges by `payouts.idcoin`, completion/tx state, age, and READY owner; identify rows with zero or multiple owners.
7. Two identical snapshots at least 24 hours apart, plus newly accepted eligible blocks, created earnings, matured earnings, created payout rows, and completed payouts between them. These are growth indicators, not a single liability total.

Use the lane-specific block boundaries and database algorithm mappings in `BadpoolLivePaymentLaneRegistry`. The source eligibility query in `BadpoolPaymentBatchPhaseAdapter` requires status-1 mature earnings, a `generate` block, the live candidate lineage, an account with matching coin, and an unclaimed earning. A raw status-1 count alone is not an eligible-work count. Payment-delay state is represented in durable ledgers and must not be added again as unclaimed earnings. The Scrypt precision HOLD is a separate phase-6 batch with no created payout IDs; it must not be counted as an unfinished payout row or repaired by this work.

Without current production read-only database and ledger evidence, stage counts, growth rate, and total payment liability remain **PENDING**. Do not add balances or amounts across these overlapping stages.

### Bounded operator evidence request

On the production host, first confirm the installed command help lists these read-only previews. Run the following block once for each coin ID `1266`, `1267`, `1268`, and `1269`, substituting one ID per block. Retain the complete JSON outputs and UTC observation time. These commands are previews only; no apply, batch-run, or wallet-send command is part of the request.

```bash
sudo -v
cd /srv/badpool/yiimp-badpool/web
COIN_ID=1267
date -u +%FT%TZ
sudo -u zako /usr/bin/php yaamp/yiic.php badpoolguard overview --coin-id="$COIN_ID" --format=json
sudo -u zako /usr/bin/php yaamp/yiic.php badpoolguard block-category-maturity-preview --coin-id="$COIN_ID" --format=json
sudo -u zako /usr/bin/php yaamp/yiic.php badpoolguard earnings-credit-readiness-preview --coin-id="$COIN_ID" --format=json
sudo -u zako /usr/bin/php yaamp/yiic.php badpoolguard payable-source-reconciliation-preview --coin-id="$COIN_ID" --format=json
sudo -u zako /usr/bin/php yaamp/yiic.php badpoolguard payout-candidates-preview --coin-id="$COIN_ID" --format=json
sudo -u zako /usr/bin/php yaamp/yiic.php badpoolguard payout-row-preflight-preview --coin-id="$COIN_ID" --format=json
```

Also return read-only metadata for every commissioned lane's coordinator state and unresolved `ledger.json` files: batch ID, lane, state, phase, age, selected earning/block/account IDs, created payout IDs, and phase artifact checksums. Return a grouped database inventory of `blocks` by `coin_id/category`, `earnings` by `coinid/status/mature_time` and distinct `blockid`, `payouts` by `idcoin/completed/tx presence`, and relevant positive-balance account counts by `coinid`. Include the oldest and newest row IDs/timestamps per group. The operator should use the existing read-only database connection procedure and return query text with the result so its bounds and schema can be verified; do not guess a credential path. Repeat this same snapshot after at least 24 hours to measure arrivals and completions. The inventory remains PENDING until both snapshots and ledger ownership evidence are reviewed.
