# Permanent live Scrypt payment coordinator

`badpoolguard live-payment-coordinator --format=json` is the single-flight controller for coin 1267, Scrypt, and `block_id > 29242`. It creates auto/all-active-payout-coins batches of at most 25 through `BadpoolPaymentBatchRunner`; it never implements financial SQL or wallet operations itself.

The atomic `runtime/badpool-payment-batches/live-scrypt-coordinator.json` record uses schema `badpool.live_payment_coordinator.v1`. It records lane, coin, algorithm, boundary, active batch, observed state/phase, transition time, human-approval flag, and last reconciled batch. Every owned ledger carries the same lane ownership envelope. On startup the coordinator scans owned ledgers, so a crash after ledger creation cannot be mistaken for no active work. A nonblocking `flock` on `live-scrypt-coordinator.lock` prevents overlap; PID text is not authority.

An owned delay-held batch is resumed by exact ID and its earning scope must remain unchanged. Wallet-ready and completed-payout states stop at human approval or read-only wallet-proof closeout respectively. Unknown HOLD, FAIL, REFUSED, malformed/missing/mismatched ledgers, and state disagreements fail closed. The sole terminal state is `RECONCILED`. An active terminal ledger is loaded directly by its durable ID, clears the active ID, records `last_terminal_reconciled_batch_id`, and returns `READY_FOR_NEW_BATCH`; the following invocation may create the next bounded batch.

The read-only `completed-payout-batch-closeout` proof cannot change a ledger. An operator must retain its successful JSON report, calculate its SHA-256, and explicitly run `completed-payout-batch-closeout-apply` with the exact batch ID, report path, checksum, and confirmation `reconcile_completed_payout_ledger_only`. The apply revalidates the owned ledger and exact payout IDs, performs no DB or wallet operation, and atomically records `RECONCILED`, timestamp, proof checksum, and payout IDs. Repeating the identical apply is idempotent; any changed proof or evidence fails closed.

Empty selection is IDLE. Cleanup first proves coordinator ownership and empty earning, block, account, payment-delay, and payout scopes, confines the target to one direct child of the runtime root, rejects symlinks, and then removes the complete artifact tree. Historical, recovery, manual, normal, and unowned batches—including canary `20260918T005930Z-8089f25db0fe`—are never adopted or deleted.

## Future systemd contract (do not install in this change)

Use a `Type=oneshot` `badpool-live-payment.service`, `User=zako`, `WorkingDirectory=/srv/badpool/yiimp-badpool/web`, and `ExecStart=/usr/bin/php yaamp/yiic.php badpoolguard live-payment-coordinator --format=json`. A persistent timer should run every five minutes. The application lock supplies no-overlap and reboot-safe reconciliation.

Activation requires a separate handoff: prove the current canary reached the wallet boundary or an accepted terminal state; stop and disable `badpool-live-payment-canary.timer`; reconcile the canary; explicitly initialize permanent coordinator state; only then enable this timer. The coordinator never invokes `wallet-send-dryrun`, `wallet-send-approval-package`, `wallet-send-apply`, `sendmany`, or `sendtoaddress`.
