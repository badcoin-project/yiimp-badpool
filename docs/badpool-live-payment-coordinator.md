# Configured live-payment lanes

## Implemented now

`badpoolguard live-payment-coordinator --format=json` is the single-flight controller for coin 1267, Scrypt, and `block_id > 29242`. It creates auto/all-active-payout-coins batches of at most 25 through `BadpoolPaymentBatchRunner`; it never implements financial SQL or wallet operations itself.

`BadpoolLivePaymentLaneConfiguration` is the strict identity and policy boundary used by the coordinator and its live phase adapter. Commissioning is an ordered sequence: accounting, maturity, payout preparation, then wallet send. Each enabled stage requires every earlier stage; accounting requires a positive block boundary, maturity requires a positive block limit, and payout preparation requires a positive earning-batch limit. The explicit predicates are `isAccountingCommissioned()`, `isMaturityCommissioned()`, `isPayoutPreparationCommissioned()`, and `isWalletSendCommissioned()`. The legacy `isCommissioned()` method remains only as a compatibility alias for `isPayoutPreparationCommissioned()`; payment call sites use the explicit predicate.

The compatibility configuration keeps lane `live-scrypt-v1`, ownership schema `badpool.live_payment_coordinator.v1`, coin `1267`, database and operational identity `scrypt`, boundary `29242`, the 25-earning ceiling, wallet binding/account `scrypt` / `pool-scrypt`, and the existing state and lock filenames. Lane `live-groestl-v1` commissions accounting and maturity after block `31212` for coin `1269` and DB algorithm `badcoin-groestl`, with a 10-block maturity ceiling; payout preparation and wallet send remain disabled. The registry rejects duplicate lane IDs, coin IDs, state paths, lock paths, and wallet source accounts. Invalid identities, unsafe filenames, incompatible ownership schemas, out-of-order activation, or missing stage-owned activation values fail closed.

The existing Scrypt state and ledger formats are unchanged. In particular, active batch `20260920T120829Z-414141f5f7d0`, phase 6, state `READY_FOR_WALLET_APPROVAL`, and payout `526` remain owned by `live-scrypt-v1`; no migration, adoption, reconciliation, or payout rewrite is required. Human wallet approval remains mandatory, and the coordinator still cannot invoke wallet RPC or wallet send.

The atomic `runtime/badpool-payment-batches/live-scrypt-coordinator.json` record uses schema `badpool.live_payment_coordinator.v1`. It records lane, coin, algorithm, boundary, active batch, observed state/phase, transition time, human-approval flag, and last reconciled batch. Every owned ledger carries the same lane ownership envelope. On startup the coordinator scans owned ledgers, so a crash after ledger creation cannot be mistaken for no active work. A nonblocking `flock` on `live-scrypt-coordinator.lock` prevents overlap; PID text is not authority.

An owned delay-held batch is resumed by exact ID and its earning scope must remain unchanged. Wallet-ready and completed-payout states stop at human approval or read-only wallet-proof closeout respectively. Unknown HOLD, FAIL, REFUSED, malformed/missing/mismatched ledgers, and state disagreements fail closed. The sole terminal state is `RECONCILED`. An active terminal ledger is loaded directly by its durable ID, clears the active ID, records `last_terminal_reconciled_batch_id`, and returns `READY_FOR_NEW_BATCH`; the following invocation may create the next bounded batch.

The read-only `completed-payout-batch-closeout` proof cannot change a ledger. An operator must retain its successful JSON report, calculate its SHA-256, and explicitly run `completed-payout-batch-closeout-apply` with the exact batch ID, report path, checksum, and confirmation `reconcile_completed_payout_ledger_only`. The apply revalidates the owned ledger and exact payout IDs, performs no DB or wallet operation, and atomically records `RECONCILED`, timestamp, proof checksum, and payout IDs. Repeating the identical apply is idempotent; any changed proof or evidence fails closed.

Empty selection is IDLE. Cleanup first proves coordinator ownership and empty earning, block, account, payment-delay, and payout scopes, confines the target to one direct child of the runtime root, rejects symlinks, and then removes the complete artifact tree. Historical, recovery, manual, normal, and unowned batches—including canary `20260918T005930Z-8089f25db0fe`—are never adopted or deleted.

Closeout apply now resolves the exact ownership envelope through the lane registry instead of comparing against a global Scrypt owner constant. A ledger can only be reconciled by the commissioned lane whose schema, lane ID, coin, database algorithm, and boundary match the durable owner envelope. Existing Scrypt v1 ledgers remain valid.

## Future generalization / commissioning

The registry records the other known identities, but all four are disabled and uncommissioned. They have no block boundary, no batch ceiling, and all accounting, payout-preparation, and wallet-send enablement flags are false. Supplying one to the coordinator fails before creating runtime state.

| Lane status | Coin | Operational/wallet identity | Database/accounting algorithm | Wallet account |
| --- | ---: | --- | --- | --- |
| uncommissioned | 1266 | `yescrypt` | `yescrypt` | `pool-yescrypt` |
| active compatibility lane | 1267 | `scrypt` | `scrypt` | `pool-scrypt` |
| uncommissioned | 1268 | `skein` | `skein` | `pool-skein` |
| uncommissioned | 1269 | `groestl` | `badcoin-groestl` | `pool-groestl` |
| uncommissioned | 1270 | `sha256d` | `sha256` | `pool-sha256d` |

Groestl and SHA256d use explicit operational-to-database mappings; code must not infer one identity from the other. Activation boundaries and production ownership history are deliberately absent until commissioning evidence exists. The Scrypt-only live maturity command and the Scrypt-only wallet preparation/proof restrictions are intentionally retained in this first compatibility refactor.

Later maturity work must also support more than one earning per block. Production Scrypt history includes such blocks, so future lane generalization cannot assume a one-to-one block/earning relationship. This change does not alter maturity selection, monetary calculations, payment-delay semantics, phase ordering, payout cadence, or wallet authorization.

## Future systemd contract (do not install in this change)

Use a `Type=oneshot` `badpool-live-payment.service`, `User=zako`, `WorkingDirectory=/srv/badpool/yiimp-badpool/web`, and `ExecStart=/usr/bin/php yaamp/yiic.php badpoolguard live-payment-coordinator --format=json`. A persistent timer should run every five minutes. The application lock supplies no-overlap and reboot-safe reconciliation.

Activation requires a separate handoff: prove the current canary reached the wallet boundary or an accepted terminal state; stop and disable `badpool-live-payment-canary.timer`; reconcile the canary; explicitly initialize permanent coordinator state; only then enable this timer. The coordinator never invokes `wallet-send-dryrun`, `wallet-send-approval-package`, `wallet-send-apply`, `sendmany`, or `sendtoaddress`.
