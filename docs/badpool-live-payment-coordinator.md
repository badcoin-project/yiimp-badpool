# Configured live-payment lanes

## Implemented now

`badpoolguard live-payment-coordinator --format=json` remains the compatibility invocation for coin 1267, Scrypt, and `block_id > 29242`. Explicit `--lane-id` forms select the independent Groestl, Yescrypt, or Skein lane. Each commissioned payout-preparation lane creates auto/all-active-payout-coins batches of at most 25 through `BadpoolPaymentBatchRunner`; the coordinator never implements financial SQL or wallet operations itself.

Yescrypt lane `live-yescrypt-v1` is commissioned for live accounting, maturity, and payout preparation: coin `1266`, database and operational algorithm `yescrypt`, and the permanent exclusive activation boundary `block_id > 31284`. Accounting and maturity commands must select this lane and match that coin, algorithm, and boundary exactly. Maturity is independently bounded to at most 10 blocks per invocation, and payout preparation is bounded to at most 25 exact earning IDs. The future manual production invocation is `php yaamp/yiic.php badpoolguard live-payment-coordinator --lane-id=live-yescrypt-v1 --format=json`. This source change does not run that command or install a Yescrypt payment service or timer. Yescrypt wallet send remains disabled.

Skein lane `live-skein-v1` is commissioned in source for live accounting, maturity, and payout preparation: coin `1268`, database and operational algorithm `skein`, and the immutable exclusive activation boundary `block_id > 31812` (first included block `31813`). The deployed production timers `badpool-live-accounting-skein.timer` and `badpool-live-maturity-skein.timer` already exist. Accounting and maturity remain explicitly lane-bound, and maturity is independently bounded to at most 10 blocks per invocation. Payout preparation is bounded to at most 25 exact status-1 earning IDs and must be invoked manually as `php yaamp/yiic.php badpoolguard live-payment-coordinator --lane-id=live-skein-v1 --format=json`. The ordinary selected-ID payment delay remains mandatory. Skein wallet send remains disabled, and no recurring Skein payment-coordinator timer exists. This SHA256d source change does not execute or alter Skein payout preparation or its owned batch.

SHA256d lane `live-sha256d-v1` is commissioned in source for live accounting only: coin `1270`, database algorithm `sha256`, operational/wallet identity `sha256d`, and the locked permanent exclusive boundary `block_id > 32014` (first eligible block ID `32015`). The exact future production command contract is `php yaamp/yiic.php liveblockaccounting --coin=1270 --algo=sha256 --after=32014 --limit=2 --lane=live-sha256d-v1`; the explicit lane and the database algorithm spelling are mandatory. Maturity, payout preparation, and wallet send remain disabled, with null maturity and payout limits. No post-boundary SHA256d block has yet proven production accounting, so the first future real SHA256d block will serve as the production canary. This source change does not create a timer or service and does not execute accounting.

`BadpoolLivePaymentLaneConfiguration` is the strict identity and policy boundary used by the coordinator and its live phase adapter. Commissioning is an ordered sequence: accounting, maturity, payout preparation, then wallet send. Each enabled stage requires every earlier stage; accounting requires a positive block boundary, maturity requires a positive block limit, and payout preparation requires a positive earning-batch limit. The explicit predicates are `isAccountingCommissioned()`, `isMaturityCommissioned()`, `isPayoutPreparationCommissioned()`, and `isWalletSendCommissioned()`. The legacy `isCommissioned()` method remains only as a compatibility alias for `isPayoutPreparationCommissioned()`; it is therefore true for Yescrypt while wallet-send commissioning remains false.

The compatibility configuration keeps lane `live-scrypt-v1`, ownership schema `badpool.live_payment_coordinator.v1`, coin `1267`, database and operational identity `scrypt`, boundary `29242`, the 25-earning ceiling, wallet binding/account `scrypt` / `pool-scrypt`, and the existing state and lock filenames. Lane `live-groestl-v1` commissions accounting, maturity, and payout preparation after block `31212` for coin `1269` and DB algorithm `badcoin-groestl`, with a 10-block maturity ceiling and 25-earning batch ceiling. Groestl wallet send remains disabled. Yescrypt commissions accounting, maturity, and payout preparation after block `31284`, with a 10-block maturity ceiling and 25-earning batch ceiling, independent `live-yescrypt-coordinator.json` / `.lock` identities, and wallet binding/account `yescrypt` / `pool-yescrypt`. Skein commissions accounting, maturity, and payout preparation after block `31812`, with a 10-block maturity ceiling and 25-earning batch ceiling, independent `live-skein-coordinator.json` / `.lock` identities, and wallet binding/account `skein` / `pool-skein`. SHA256d commissions accounting only after block `32014`, with independent `live-sha256d-coordinator.json` / `.lock` identities and wallet binding/account `sha256d` / `pool-sha256d`. Yescrypt, Skein, and SHA256d retain no operational RPC or wallet-send identity; Skein and SHA256d also retain no payment-coordinator service/timer identity. The registry rejects duplicate lane IDs, coin IDs, state paths, lock paths, and wallet source accounts. Invalid identities, unsafe filenames, incompatible ownership schemas, out-of-order activation, or missing stage-owned activation values fail closed.

The existing Scrypt state and ledger formats are unchanged. In particular, payout `526` remains parked with `completed=0` and no transaction, and Groestl payout `527` also remains parked with `completed=0` and no transaction. Yescrypt batch `20260927T135815Z-f30c33bc6543` and Skein batch `20260928T002836Z-dadc9cef85e9` retain their existing lane ownership. The accounting-only SHA256d lane cannot adopt, inspect as its own, mutate, complete, or send any of these payment artifacts. Human wallet approval remains mandatory, and no migration, adoption, reconciliation, payout rewrite, wallet RPC, or wallet send is introduced.

Commissioned lane identities reserve independent state and lock paths: `live-scrypt-coordinator.json` / `.lock`, `live-yescrypt-coordinator.json` / `.lock`, `live-skein-coordinator.json` / `.lock`, `live-groestl-coordinator.json` / `.lock`, and `live-sha256d-coordinator.json` / `.lock`. Accounting and maturity do not create or use payment-coordinator state, and SHA256d payout preparation is not commissioned. Payment state records contain lane, coin, algorithm, boundary, active batch, observed state/phase, transition time, human-approval flag, and last reconciled batch. Every owned ledger carries the same lane ownership envelope. On startup the coordinator scans only ledgers owned by its exact payout-commissioned lane, so a crash after ledger creation cannot be mistaken for no active work and one lane cannot adopt another lane's batch. A nonblocking lane-specific `flock` prevents overlap; PID text is not authority.

The normal payment delay remains active without a bypass. An owned delay-held batch is `WAITING_PAYMENT_DELAY`, retains its exact selected earning IDs, block IDs, and lane ownership, and is resumed by exact batch ID; no replacement batch, account credit, payout row, or wallet activity is allowed while it is held. Wallet-ready and completed-payout states stop at human approval or read-only wallet-proof closeout respectively. For Yescrypt and Skein, `READY_FOR_WALLET_APPROVAL` is the terminal automated phase. Unknown HOLD, FAIL, REFUSED, malformed/missing/mismatched ledgers, and state disagreements fail closed. The sole reconciled state is `RECONCILED`. An active reconciled ledger is loaded directly by its durable ID, clears the active ID, records `last_terminal_reconciled_batch_id`, and returns `READY_FOR_NEW_BATCH`; the following invocation may create the next bounded batch.

## Generalized exact-ID wallet approval framework

`BadpoolGuardedMultiLaneWalletSend` is the operator-only framework for a future human-approved send spanning one or more payment-ready lanes. It does not expose a recurring coordinator action and does not enable wallet sending globally. Lane configuration keeps `wallet_send_enabled` (recurring commissioning) separate from `human_approved_wallet_send_enabled` (eligibility for an explicit operator scope). Scrypt, Groestl, and Yescrypt are eligible for the latter; Skein is not eligible while its owned batch is `WAITING_PAYMENT_DELAY`, and accounting-only SHA256d is not eligible.

The approval document uses schema `badpool.wallet_send.multi_lane_approval.v1`, requires `human_approved=true`, and contains a non-empty, ascending `entries` array. Every entry binds exactly `payout_id`, `lane_id`, `coin_id`, `account_id`, the unrounded decimal `amount`, `recipient`, `wallet_binding_identity`, `source_account_identity`, `expected_completed=0`, `expected_tx=null`, and `expected_batch_state=READY_FOR_WALLET_APPROVAL`. Missing, additional, duplicate, unordered, unknown, completed, transaction-bearing, mismatched, or non-ready entries fail closed before the wallet gateway is called. Repository lookup authority is only the explicit payout-ID array; incomplete or otherwise discoverable payouts are never added.

```json
{
  "schema": "badpool.wallet_send.multi_lane_approval.v1",
  "human_approved": true,
  "entries": [
    {
      "payout_id": 526,
      "lane_id": "live-scrypt-v1",
      "coin_id": 1267,
      "account_id": 79,
      "amount": "54111.530811649995",
      "recipient": "<exact account destination>",
      "wallet_binding_identity": "scrypt",
      "source_account_identity": "pool-scrypt",
      "expected_completed": 0,
      "expected_tx": null,
      "expected_batch_state": "READY_FOR_WALLET_APPROVAL"
    }
  ]
}
```

After every entry and lane validates, the framework constructs deterministic per-recipient amounts using the existing eight-decimal wallet projection while retaining the exact raw aggregate. Duplicate destinations are refused rather than implicitly combined. It then makes one call to the injected `sendmanyApprovedScope(wallet_bindings, recipients, payout_ids)` gateway. This abstraction is fixture-backed in tests and performs no live RPC. A failed gateway result leaves every payout unchanged.

A valid returned transaction ID is first written to the required durable possible-send evidence store and is then reconciled transactionally to only the approved payout IDs, with the validated row preconditions supplied to the repository. All approved rows receive the identical transaction ID and completed state; a count mismatch or any changed row fails the reconciliation as a unit. If evidence retention or database reconciliation fails after wallet success, the result is a manual-recovery HOLD containing the transaction ID and validated scope. Possible-send evidence blocks another automatic call for that exact scope. Operators must reconcile it manually; the framework never automatically retries after a transaction ID may have been returned.

The human boundary remains unchanged: `READY_FOR_WALLET_APPROVAL` is classified as `HUMAN_WALLET_APPROVAL_REQUIRED`, and `BadpoolLivePaymentCoordinator` has no wallet gateway or RPC call. This source framework does not claim that any production multi-algorithm send occurred and does not imply that a Skein or SHA256d payout exists.

The read-only `completed-payout-batch-closeout` proof cannot change a ledger. An operator must retain its successful JSON report, calculate its SHA-256, and explicitly run `completed-payout-batch-closeout-apply` with the exact batch ID, report path, checksum, and confirmation `reconcile_completed_payout_ledger_only`. The apply revalidates the owned ledger and exact payout IDs, performs no DB or wallet operation, and atomically records `RECONCILED`, timestamp, proof checksum, and payout IDs. Repeating the identical apply is idempotent; any changed proof or evidence fails closed.

Empty selection is IDLE. Cleanup first proves coordinator ownership and empty earning, block, account, payment-delay, and payout scopes, confines the target to one direct child of the runtime root, rejects symlinks, and then removes the complete artifact tree. Historical, recovery, manual, normal, and unowned batches—including canary `20260918T005930Z-8089f25db0fe`—are never adopted or deleted.

Closeout apply now resolves the exact ownership envelope through the lane registry instead of comparing against a global Scrypt owner constant. A ledger can only be reconciled by the commissioned lane whose schema, lane ID, coin, database algorithm, and boundary match the durable owner envelope. Existing Scrypt v1 ledgers remain valid.

## Lane commissioning status

The registry records all known identities. Yescrypt is commissioned for forward live accounting, maturity, and payout preparation after block `31284`; maturity is capped at 10 blocks and each payout-preparation batch is capped at 25 earnings. Skein is commissioned for forward live accounting, maturity, and payout preparation after block `31812`; maturity is capped at 10 blocks and each payout-preparation batch is capped at 25 earnings, while wallet send remains disabled. SHA256d is commissioned only for forward live accounting after block `32014`; maturity, payout preparation, and wallet send remain disabled. Groestl is commissioned only through payout preparation; wallet-send approval and apply remain unavailable.

| Lane status | Coin | Operational/wallet identity | Database/accounting algorithm | Wallet account |
| --- | ---: | --- | --- | --- |
| accounting, maturity, and payout preparation commissioned after block 31284; wallet send disabled | 1266 | `yescrypt` | `yescrypt` | `pool-yescrypt` |
| active compatibility lane | 1267 | `scrypt` | `scrypt` | `pool-scrypt` |
| accounting, maturity, and payout preparation commissioned after block 31812; wallet send disabled | 1268 | `skein` | `skein` | `pool-skein` |
| payout preparation commissioned; wallet send disabled | 1269 | `groestl` | `badcoin-groestl` | `pool-groestl` |
| accounting commissioned after block 32014; maturity, payout preparation, and wallet send disabled | 1270 | `sha256d` | `sha256` | `pool-sha256d` |

Groestl and SHA256d use explicit operational-to-database mappings; code must not infer one identity from the other. The Scrypt-only wallet-send approval/proof restrictions are retained. In addition, `wallet-send-apply` now consults the lane registry and refuses any coin whose wallet-send stage is not commissioned before wallet RPC construction.

Live maturity continues to support more than one earning per block; production Scrypt history includes such blocks, so no lane may assume a one-to-one block/earning relationship. Yescrypt and Skein payout preparation select only mature status-1 earnings with exact live-candidate lineage after their permanent boundaries. Skein accounting, maturity, and payout preparation exclude every `block_id <= 31812`, including historical recovery scope such as block `4265`. SHA256d accounting excludes every `block_id <= 32014`, including all known historical SHA256d rows, and does not commission any later financial stage. Commissioning does not alter monetary calculations, payment-delay semantics, phase ordering, payout cadence, or wallet authorization.

## Future systemd contract (do not install in this change)

Use a `Type=oneshot` `badpool-live-payment.service`, `User=zako`, `WorkingDirectory=/srv/badpool/yiimp-badpool/web`, and `ExecStart=/usr/bin/php yaamp/yiic.php badpoolguard live-payment-coordinator --format=json`. A persistent timer should run every five minutes. The application lock supplies no-overlap and reboot-safe reconciliation.

Activation requires a separate handoff: prove the current canary reached the wallet boundary or an accepted terminal state; stop and disable `badpool-live-payment-canary.timer`; reconcile the canary; explicitly initialize permanent coordinator state; only then enable this timer. The coordinator never invokes `wallet-send-dryrun`, `wallet-send-approval-package`, `wallet-send-apply`, `sendmany`, or `sendtoaddress`.
