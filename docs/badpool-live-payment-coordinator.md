# Configured live-payment lanes

## Exact completed-payout batch resume

`batch-run --resume-batch-id=<exact-id> --only=<operational-algorithm>` supports
the completed-payout boundary for registry-owned Scrypt, Groestl, Yescrypt, and
Skein batches. This does not generalize manual financial phase execution. The
registry's `fromOwnershipEnvelope()` validates schema, lane, coin, database
algorithm, activation boundary, and payout-preparation commissioning. Groestl
uses operational `groestl` and database `badcoin-groestl`; SHA256d is refused.

Under the exact batch's nonblocking resume lock, the runner requires phase 6,
`READY_FOR_WALLET_APPROVAL` (or an idempotent
`HOLD_COMPLETED_PAYOUT_RECONCILIATION` recheck), successful phases 0–6 with
matching retained artifact checksums, and exact created payout IDs matching the
phase-6 creation report. Every selected payout row must belong to the registered
coin and already have `completed=1` plus a nonempty transaction ID. Invalid
ownership, scope, state, or evidence refuses before financial phase dispatch.
An explicit `--only` may not override durable ownership. A terminal `RECONCILED`
batch is never moved back to HOLD.

This path calls only the existing exact payout-row SELECT and the existing
completed-payout boundary setter. It does not execute phases 0–6, construct a
wallet gateway, invoke a guard command, or change payout/account/earning rows.
Only boundary metadata in this batch's ledger changes; phase artifacts and
wallet-send journals remain untouched. Journal reconciliation and wallet
transaction verification remain operator prerequisites, not a new send/retry
permission. The boundary detector itself does not read the send journal.

Continue with the existing read-only `completed-payout-batch-closeout` proof and
the separately confirmed, ledger-only `completed-payout-batch-closeout-apply`.
Neither implementation is duplicated by resume. The four recurring payment
timers remain under incident HOLD; deployment and production recovery are
separate tasks.

## Implemented now

`badpoolguard live-payment-coordinator --format=json` remains a CLI compatibility invocation for coin 1267, Scrypt, and `block_id > 29242`, but no source-managed recurring service relies on that default. Every recurring systemd invocation supplies one exact `--lane-id`. Each commissioned payout-preparation lane creates auto/all-active-payout-coins batches of at most 25 through `BadpoolPaymentBatchRunner`; the coordinator never implements financial SQL or wallet operations itself.

Yescrypt lane `live-yescrypt-v1` is commissioned for live accounting, maturity, and payout preparation: coin `1266`, database and operational algorithm `yescrypt`, and the permanent exclusive activation boundary `block_id > 31284`. Accounting and maturity commands must select this lane and match that coin, algorithm, and boundary exactly. Maturity is independently bounded to at most 10 blocks per invocation, and payout preparation is bounded to at most 25 exact earning IDs. Its source-managed recurring payment units are `badpool-live-payment-yescrypt.service` and `.timer`, with explicit `--lane-id=live-yescrypt-v1`. Yescrypt recurring wallet send remains disabled.

Skein lane `live-skein-v1` is commissioned in source for live accounting, maturity, and payout preparation: coin `1268`, database and operational algorithm `skein`, and the immutable exclusive activation boundary `block_id > 31812` (first included block `31813`). Accounting and maturity remain explicitly lane-bound, and maturity is independently bounded to at most 10 blocks per invocation. Payout preparation is bounded to at most 25 exact status-1 earning IDs. Its source-managed recurring payment units are `badpool-live-payment-skein.service` and `.timer`, with explicit `--lane-id=live-skein-v1`. The ordinary selected-ID payment delay remains mandatory and Skein recurring wallet send remains disabled.

SHA256d lane `live-sha256d-v1` is commissioned in source for live accounting only: coin `1270`, database algorithm `sha256`, operational/wallet identity `sha256d`, and the locked permanent exclusive boundary `block_id > 32014` (first eligible block ID `32015`). The exact production accounting contract is `php yaamp/yiic.php liveblockaccounting --coin=1270 --algo=sha256 --after=32014 --limit=2 --lane=live-sha256d-v1`; the explicit lane and database algorithm spelling are mandatory. Maturity and payment-coordinator service/timer definitions now exist in source, but maturity, payout preparation, wallet send, and human-approved wallet-send eligibility remain uncommissioned. The SHA256d maturity and payment timers must remain disabled until the first real post-boundary block passes live accounting and a separate production commissioning change enables each downstream stage.

`BadpoolLivePaymentLaneConfiguration` is the strict identity and policy boundary used by the coordinator and its live phase adapter. Source capability is represented separately by `isMaturityPipelinePrepared()`, `isPayoutPreparationPipelinePrepared()`, and `isRecurringPaymentCoordinatorPrepared()`. Production commissioning remains the ordered sequence exposed by `isAccountingCommissioned()`, `isMaturityCommissioned()`, `isPayoutPreparationCommissioned()`, and `isWalletSendCommissioned()`. An installed unit or prepared pipeline does not make a production stage commissioned, and an enabled payment coordinator does not authorize recurring wallet sends. The legacy `isCommissioned()` method remains only as a compatibility alias for `isPayoutPreparationCommissioned()`.

The compatibility configuration keeps lane `live-scrypt-v1`, ownership schema `badpool.live_payment_coordinator.v1`, coin `1267`, database and operational identity `scrypt`, boundary `29242`, the 25-earning ceiling, wallet binding/account `scrypt` / `pool-scrypt`, and the existing state and lock filenames. Groestl, Yescrypt, and Skein retain their existing commissioned accounting, maturity, and payout-preparation stages and independent wallet/state identities. SHA256d commissions accounting only after block `32014`, with independent `live-sha256d-coordinator.json` / `.lock` identities and no operational RPC or wallet-send identity. The registry records distinct maturity and payment service/timer names for every lane and rejects duplicate lane IDs, coin IDs, state paths, lock paths, wallet source accounts, or service/timer identities. Invalid identities, unsafe filenames, incompatible ownership schemas, out-of-order activation, or missing stage-owned activation values fail closed.

Individual batch ledgers remain the accounting authority. Coordinator state version 2 is only a reconstructable lane index: it records the currently executing batch (normally `null` between invocations), ordered waiting/ready/reconciliation batch IDs, the last terminal reconciled ID, and transition metadata. Version 1 state with `active_batch_id` naming an existing waiting, ready, reconciliation, or reconciled ledger is accepted and rebuilt from the ledger inventory without changing that ledger, payout scope, or payout rows. Production payouts `526` through `529` remain canonical `RECONCILED` evidence, while later wallet-ready batches remain independently addressable. The accounting-only SHA256d lane cannot adopt, inspect as its own, mutate, complete, or send any commissioned-lane payment artifact. Human wallet approval remains mandatory.

Commissioned lane identities reserve independent state and lock paths: `live-scrypt-coordinator.json` / `.lock`, `live-yescrypt-coordinator.json` / `.lock`, `live-skein-coordinator.json` / `.lock`, `live-groestl-coordinator.json` / `.lock`, and `live-sha256d-coordinator.json` / `.lock`. Accounting and maturity do not create or use payment-coordinator state, and SHA256d payout preparation is not commissioned. Every owned ledger carries the exact lane ownership envelope. On startup the coordinator scans and validates every ledger owned by its lane, rebuilds the queue in ascending canonical batch-ID order, and rejects duplicate earning, block, or payout ownership across unresolved ledgers. Valid foreign-lane ledgers are ignored. A malformed same-lane ownership envelope, malformed owned ledger, or cached active ID that cannot be proven against a durable owned ledger fails closed. A nonblocking lane-specific `flock` preserves one mutation-active batch at a time; parked ledgers do not occupy that execution slot.

The normal payment delay remains active without a bypass. Multiple batches may be safely parked in exactly three unresolved states: `WAITING_PAYMENT_DELAY`, `READY_FOR_WALLET_APPROVAL`, and `HOLD_COMPLETED_PAYOUT_RECONCILIATION`. A timer invocation resumes at most the oldest `WAITING_PAYMENT_DELAY` batch once by exact batch ID, verifies that its earning/block/account scope did not change, and then may create at most one fresh bounded batch. Thus a still-ineligible delay remains parked while later unclaimed work can form another batch; an expired delay can advance independently. READY batches keep their immutable scope and distinct payout IDs, release the active slot, and remain at the human boundary. Reconciliation-required batches remain addressable by exact batch ID and never enter a wallet-send retry path.

Fresh live status-1 selection receives the union of earning and block IDs claimed by every unresolved same-lane ledger. The SQL applies both `NOT IN` exclusions before `ORDER BY E.id` and the existing limit. The coordinator then independently rejects any overlap before accepting the new ledger. This deliberately supplements the database status predicate: `WAITING_PAYMENT_DELAY` rows are still status 1, so database state alone cannot prevent duplicate selection. Account IDs may repeat across batches, but earning, block, and created-payout IDs may not. `RECONCILED` ledgers no longer reserve scope and record only terminal history.

Generic `HOLD`, `FAIL`, `REFUSED`, unknown/in-progress states, invalid ownership, malformed scope, duplicate unresolved ownership, and missing cached-active evidence are lane-blocking errors; they are never skipped to manufacture new work. Valid cross-lane ledgers do not contaminate a lane. The JSON report exposes ordered IDs and counts for every parked class, READY payout IDs by batch, last terminal batch, action, resume/create booleans, human action, and explicit false wallet-send/RPC fields. The coordinator itself performs no direct database mutation; mutation-capable work remains delegated to the single sequential batch runner under the lane lock.

## Generalized exact-ID wallet approval framework

`BadpoolGuardedMultiLaneWalletSend` is the operator-only framework for a human-approved send spanning one or more payment-ready lanes. It does not expose a recurring coordinator action and does not enable wallet sending globally. Lane configuration keeps the wallet-send production stage in `wallet_send_enabled` separate from `human_approved_wallet_send_enabled`, which only allows an explicit operator scope. Neither flag permits a recurring coordinator to send. Scrypt, Groestl, Yescrypt, and Skein are eligible for explicit human-approved scopes; accounting-only SHA256d is not eligible. The completed 526-529 payout set is retained as closeout evidence rather than an active send scope.

The approval document uses schema `badpool.wallet_send.multi_lane_approval.v1`, requires `human_approved=true`, and contains a non-empty, ascending `entries` array. Every entry binds exactly `payout_id`, `lane_id`, `coin_id`, `account_id`, the unrounded decimal `amount`, `recipient`, `wallet_binding_identity`, `source_account_identity`, `expected_completed=0`, `expected_tx=null`, and `expected_batch_state=READY_FOR_WALLET_APPROVAL`. Missing, additional, duplicate, unordered, unknown, completed, transaction-bearing, mismatched, or non-ready entries fail closed before the wallet gateway is called. Repository lookup authority is only the explicit payout-ID array; incomplete or otherwise discoverable payouts are never added. This operator-only eligibility is not recurring wallet-send eligibility.

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

The human boundary remains unchanged: `READY_FOR_WALLET_APPROVAL` is classified as `HUMAN_WALLET_APPROVAL_REQUIRED`, and `BadpoolLivePaymentCoordinator` has no wallet gateway or RPC call. Its reports continue to state `wallet_boundary=blocked_human_required`, `wallet_rpc_used=false`, and `wallet_send_performed=false`. Recurring wallet sends are not part of this architecture. SHA256d remains accounting-only and has no payout.

## Guarded multi-wallet execution state machine

Production Scrypt, Groestl, and Yescrypt funds are held by three independent daemon identities. One human approval may bind the global exact payout scope, but one real `sendmany` cannot span those wallets. `BadpoolMultiWalletApprovalPlanner` therefore partitions an approved scope by wallet binding and source account, orders operations by their lowest payout ID, and derives each operation ID from the global approval checksum plus the exact wallet, source, payout, recipient, and projected-amount binding. Payouts 526, 527, and 528 consequently plan as Scrypt/`pool-scrypt`, Groestl/`pool-groestl`, and Yescrypt/`pool-yescrypt` operations, respectively.

`BadpoolDurableMultiWalletSendJournal` records global states `PREPARED`, `IN_PROGRESS`, `HOLD_MANUAL_RECOVERY`, `READY_FOR_RECONCILIATION`, and `RECONCILED`. Each operation records `PENDING`, `SEND_ATTEMPT_STARTED`, `TXID_RETURNED`, `UNCERTAIN`, `DEFINITE_FAILURE`, or `RECONCILED`. The journal is keyed by the global approval checksum, written by atomic same-directory rename under `runtime/badpool-wallet-send-journals`, locked during transitions, and refuses journal, lock, temporary-file, or root symlinks. A conflicting plan for an existing approval checksum is rejected.

Every wallet performs its read-only funding/readiness check before the first send. Immediately before one gateway call, the executor durably records `SEND_ATTEMPT_STARTED`; this is the replay barrier. The gateway receives a one-use capability bound to the approval checksum, operation ID, wallet identity, source account, payout IDs, recipients, projected amounts, and that single operation. This abstraction does not modify `wallet-send-guard.php` or expose a production RPC bypass. A later reviewed lane must connect the capability to exactly one `WalletRPC` instance without enabling generic or recurring sends.

A returned transaction ID is journaled immediately before another wallet can be attempted. An exception, ambiguous result, or restart with `SEND_ATTEMPT_STARTED` and no txid becomes `UNCERTAIN` and `HOLD_MANUAL_RECOVERY`; an explicitly definite failure is also a terminal operator HOLD. Previously returned txids remain durable, later wallets remain pending, and no operation is automatically retried. A restart skips only operations with durable `TXID_RETURNED` evidence. It never treats an unjournaled outcome as safe to retry.

Only after every operation has a definite txid does the journal enter `READY_FOR_RECONCILIATION`. `BadpoolYiiExactMultiWalletPayoutRepository` reloads only the explicitly approved payout IDs, joins `payouts.account_id` to the exact `accounts.id` row for `accounts.username`, and joins the authoritative payout coin `payouts.idcoin` to `coins.id`. The adapter proves lane ownership from the matching queued `READY_FOR_WALLET_APPROVAL` ledger's `coordinator_owner` and `created_payout_ids`; READY ownership no longer depends on occupying the active execution slot. It also requires the joined account and coin, a non-empty recipient, `completed=0`, and `tx=NULL`. One database transaction then changes every exact row to `completed=1` and that payout's wallet-specific txid. Any changed precondition or update-count mismatch rolls back the complete reconciliation. If reconciliation fails after sends, all txids remain in the journal, the global state becomes `HOLD_MANUAL_RECOVERY`, and wallet execution cannot resume.

`accounts.coinid` is not historical payout-row ownership. Source and schema history use it as the account's current/default payout denomination and balance-conversion target: address discovery can change it, convert or reset the account balance, and legacy payout candidate selection groups the account's current balance by that field. By contrast, the `payouts.idcoin` migration explicitly added the coin used for each payment and a foreign key from that value to `coins.id`; payout creation snapshots the selected payment coin there. Consequently, an account ID identifies the recipient record but does not identify the durable lane or wallet for an existing payout. The exact-payout repository neither selects nor compares `accounts.coinid` when validating a payout row.

The production read-only operator contract is:

`php yaamp/yiic.php badpoolguard multi-wallet-send-preflight --selected-payout-ids=526,527,528,529 --format=json`

The exact fixture is payout `526` / Scrypt / account `79`, payout `527` / Groestl / account `76`, payout `528` / Yescrypt / account `75`, and payout `529` / Skein / account `76`. The production regression is the account `76` overlap: its single current `accounts.coinid` value cannot be authoritative for both payout `527` (`payouts.idcoin=1269`, Groestl) and payout `529` (`payouts.idcoin=1268`, Skein). Account identity supplies each payout's recipient only. Durable lane and batch ownership plus the lane registry's wallet binding and source account keep Groestl/`pool-groestl` and Skein/`pool-skein` in independent operations. The command performs SELECT-only exact-payout discovery, fixed-allowlist read-only readiness/funding RPC, per-wallet reserve evaluation from `YAAMP_BADPOOL_MINIMUM_WALLET_RESERVES[coin_id]`, and generates a proposed `badpool.wallet_send.multi_lane_approval.v1` document with `human_approved=false`.

The JSON report is atomically retained at `runtime/badpool-multi-wallet-preflights/multi-wallet-preflight-<selected-payout-id-checksum>.json`. The directory, final target, and temporary target refuse symlinks. The retained report contains no RPC credentials and is separate from the execution-journal schema and directory. Its selected-ID, inventory, lane/batch ownership, recipient, wallet partition, per-wallet amount, funding/readiness, approval, and complete-report SHA-256 values are comparison evidence for later review. They are not authority to send.

Production readiness uses one fixed native CLI path: the `zako` PHP process invokes `/usr/bin/sudo -n -u badcoin /opt/badcoin/mainnet/bin/badcoin-cli`, followed by the exact lane-registry `-conf=<rpc_config_identity>` and `-datadir=<wallet_datadir_identity>` arguments. The process is launched with an argv array and shell bypass. Only `getnetworkinfo`, `getblockchaininfo`, `getwalletinfo`, and `getbalance <source_account_identity> 1` are reachable; there is no generic RPC, arbitrary CLI-argument, or wallet-send surface. Non-zero status, timeout, stderr, empty output, malformed JSON, or a malformed decimal balance fails closed with a fixed credential-free reason.

The PHP process does not open, parse, copy, log, return, hash, or otherwise receive wallet cookie material. Native `.cookie` discovery and authentication occur inside `badcoin-cli` as user `badcoin`; cookie ownership and mode `600` remain unchanged. The existing sudo policy already authorizes this exact CLI binary, so no sudoers or group-membership change is required. Lane ID, coin ID, wallet binding, source account, config, and datadir must still match the configured registry exactly before the CLI process is invoked.

The repository default reserve policy is an independent post-send wallet floor, not a percentage of payout size: Yescrypt coin `1266` = `1000` BAD, Scrypt coin `1267` = `1000` BAD, Skein coin `1268` = `1000` BAD, and Groestl coin `1269` = `1000` BAD. SHA256d coin `1270` remains unconfigured because wallet and payout commissioning have not occurred. `serverconfig.php` is loaded before `defaultconfig.php`, and the default is guarded by `defined()`, so a deployed `YAAMP_BADPOOL_MINIMUM_WALLET_RESERVES` value remains authoritative. Production currently overrides the repository map with only `1267 => '100'`; deployment must intentionally update that untracked file separately. This transport change neither defeats nor mutates the override.

Funding is evaluated independently for Scrypt `pool-scrypt`, Groestl `pool-groestl`, Yescrypt `pool-yescrypt`, and Skein `pool-skein`. An unsafe or mismatched identity, CLI failure, unreachable daemon, unavailable or ambiguous source-account balance, missing reserve, insufficient funds, or reserve violation produces `HOLD`. The repository-default 1000-BAD policy makes the four projected requirements `55111.53081165`, `56875.65007077`, `34063.28546626`, and `58981.11592853` BAD respectively; deployed results continue to reflect the authoritative production override until deployment updates it. Preflight creates no execution journal and no `PREPARED` or `SEND_ATTEMPT_STARTED` state. A preflight `PASS` is review evidence, not wallet-send authorization.

The read-only `wallet-proof-closeout` path supports every human-approved wallet-send-eligible registry lane: Yescrypt `1266`, Scrypt `1267`, Skein `1268`, and Groestl `1269`. For each explicit coin and payout scope it resolves the wallet binding, source account, RPC config, and datadir from `BadpoolLivePaymentLaneRegistry`, then invokes only `/usr/bin/sudo -n -u badcoin /opt/badcoin/mainnet/bin/badcoin-cli -conf=<lane config> -datadir=<lane datadir> gettransaction <exact txid>` through an argv array with shell bypass and a timeout. It cannot select another RPC method, add CLI arguments, send funds, or mutate the database. SHA256d `1270` remains unsupported because it is accounting-only and is not human-approved wallet-send eligible.

The read-only `completed-payout-batch-closeout` proof cannot change a ledger. It groups the batch's completed payout rows by exact payout coin and runs one wallet proof per coin scope without cross-wallet aggregation. An operator must retain its successful JSON report, calculate its SHA-256, and explicitly run `completed-payout-batch-closeout-apply` with the exact batch ID, report path, checksum, and confirmation `reconcile_completed_payout_ledger_only`. The apply revalidates the owned ledger and exact payout IDs, performs no DB or wallet operation, and atomically changes only that owned batch ledger to `RECONCILED`, recording the timestamp, proof checksum, and payout IDs. Repeating the identical apply is idempotent; any changed proof or evidence fails closed.

Empty selection is IDLE. Cleanup first proves coordinator ownership and empty earning, block, account, payment-delay, and payout scopes, confines the target to one direct child of the runtime root, rejects symlinks, and then removes the complete artifact tree. Historical, recovery, manual, normal, and unowned batches—including canary `20260918T005930Z-8089f25db0fe`—are never adopted or deleted.

Closeout apply now resolves the exact ownership envelope through the lane registry instead of comparing against a global Scrypt owner constant. A ledger can only be reconciled by the commissioned lane whose schema, lane ID, coin, database algorithm, and boundary match the durable owner envelope. Existing Scrypt v1 ledgers remain valid.

## Lane commissioning status

The registry records all known identities. Scrypt, Groestl, Yescrypt, and Skein are commissioned through payout preparation and have independent recurring payment-coordinator units. Every such coordinator stops before wallet send. SHA256d has equivalent source plumbing but is commissioned only for forward live accounting after block `32014`; maturity, payout preparation, human-approved wallet send, completed-payout wallet proof, and both downstream timers remain disabled pending the production accounting gate.

| Lane status | Coin | Operational/wallet identity | Database/accounting algorithm | Wallet account |
| --- | ---: | --- | --- | --- |
| accounting, maturity, and payout preparation commissioned after block 31284; wallet send disabled | 1266 | `yescrypt` | `yescrypt` | `pool-yescrypt` |
| accounting, maturity, and payout preparation commissioned; explicit recurring payment coordinator | 1267 | `scrypt` | `scrypt` | `pool-scrypt` |
| accounting, maturity, and payout preparation commissioned after block 31812; wallet send disabled | 1268 | `skein` | `skein` | `pool-skein` |
| payout preparation commissioned; wallet send disabled | 1269 | `groestl` | `badcoin-groestl` | `pool-groestl` |
| accounting commissioned after block 32014; maturity, payout preparation, and wallet send disabled | 1270 | `sha256d` | `sha256` | `pool-sha256d` |

Groestl and SHA256d use explicit operational-to-database mappings; code must not infer one identity from the other. Scrypt, Groestl, Yescrypt, and Skein each have an independent recurring payment-preparation coordinator; none is a recurring wallet-send lane. Completed-payout proof is separately available to all four human-approved wallet-send-eligible lanes through the fixed read-only transport described above. `wallet-send-apply` continues to consult the lane registry and refuses any coin whose applicable wallet-send eligibility is absent before wallet RPC construction.

Live maturity continues to support more than one earning per block; production Scrypt history includes such blocks, so no lane may assume a one-to-one block/earning relationship. Yescrypt and Skein payout preparation select only mature status-1 earnings with exact live-candidate lineage after their permanent boundaries. Skein accounting, maturity, and payout preparation exclude every `block_id <= 31812`, including historical recovery scope such as block `4265`. SHA256d accounting excludes every `block_id <= 32014`, including all known historical SHA256d rows, and does not commission any later financial stage. Commissioning does not alter monetary calculations, payment-delay semantics, phase ordering, payout cadence, or wallet authorization.

## Source-managed systemd contract

`ops/systemd` contains explicit `badpool-live-payment-{scrypt,groestl,yescrypt,skein}.service` and `.timer` pairs. Each service uses the established `Type=oneshot`, `User=zako`, `/srv/badpool/yiimp-badpool/web` working directory, journal logging, and basic process hardening, and invokes exactly one lane with `--lane-id=<lane> --format=json`. Each persistent timer runs approximately every five minutes. The lane-specific application lock supplies no-overlap and reboot-safe reconciliation. The historical generic `badpool-live-payment.service` / `.timer` is not source-managed and must be stopped and disabled before `badpool-live-payment-scrypt.timer` is enabled so two drivers can never target Scrypt.

SHA256d source also contains `badpool-live-maturity-sha256d.service` / `.timer` and `badpool-live-payment-sha256d.service` / `.timer`. These definitions are preparation only. They must be installed disabled, and must not be enabled until a real block with `block_id > 32014` passes live accounting, the result is reviewed, and a separate source/deployment change explicitly commissions maturity and then payout preparation with positive stage limits. Until then, both command paths fail closed against the registry.

The coordinator never invokes `wallet-send-dryrun`, `wallet-send-approval-package`, `wallet-send-apply`, `multi-wallet-send-apply`, `sendmany`, or `sendtoaddress`. Deployment of these files, daemon reload, timer enablement, and stage commissioning are separate operator actions outside this source change.


## Guarded multi-wallet apply

The exact command contract is:

`php yaamp/yiic.php badpoolguard multi-wallet-send-apply --preflight-report=<exact-retained-json> --preflight-report-checksum=<file-sha256> --approval-checksum=<non-authorizing-approval-sha256> --selected-payout-ids=<strictly-sorted-explicit-csv> --operator-confirms-multi-wallet-send=execute_exact_approved_multi_wallet_scope_no_retry --format=json`

Every option is mandatory. Duplicate, unknown, reordered, and broad-scope options are refused; there is no dry-execution flag. The retained file must be a regular non-symlink file below the authoritative preflight directory, match its supplied SHA256, carry the exact preflight schema and non-authorizing `PASS` declarations, bind the exact payout IDs and approval checksum, and contain four passing funding observations. Apply then rebuilds exact payout rows, recipients, coin and account ownership, batch readiness, lane wallet/source partition, and eight-decimal operation plan from authoritative state and performs fresh read-only wallet readiness, source-account balance, and 1000-BAD reserve checks before creating the execution journal.

Execution changes only `human_approved` from `false` to `true` in the freshly verified approval document and keys the journal with that execution approval checksum. The sequence is retained PASS preflight -> explicit human confirmation -> fresh live revalidation -> human-approved exact scope -> durable `PREPARED` -> per-wallet `SEND_ATTEMPT_STARTED` -> one capability-bound fixed `sendmany` -> immediate `TXID_RETURNED` persistence -> repeat for the next independent wallet -> all txids definite -> `READY_FOR_RECONCILIATION` -> one exact DB reconciliation transaction -> `RECONCILED`. Destination JSON is assembled from validated recipient strings and raw eight-decimal numeric tokens without PHP floating-point conversion or aggregation.

**Never rerun after an uncertain send.** A durable attempt without a txid becomes `UNCERTAIN`/`HOLD_MANUAL_RECOVERY`; definite failure also stops later wallets. Returned txids are never resent. If all sends return definite txids but DB reconciliation fails, all txids remain durable, the journal enters `HOLD_MANUAL_RECOVERY`, and operators must use a separate manual reconciliation lane rather than retrying wallet operations.
