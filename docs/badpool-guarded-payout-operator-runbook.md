# Guarded payout operator runbook (legacy production pool)

Use this sequence for **existing** `READY_FOR_WALLET_APPROVAL` payouts. One explicit human approval can bind a tightly bounded, ascending exact-ID scope across distinct READY owning batches and commissioned Scrypt, Groestl, Yescrypt, and Skein wallets. The guarded planner partitions that scope by wallet binding and source account; batches do not partition the wallet send. Each payout still needs exactly one independently validated READY owner, and every owning batch needs its own completed-payout closeout after the send. The send step is a real wallet operation. Obtain the exact scope and payment authority for each run; a previous preflight, checksum, or payout does not authorize another one. Keep the four recurring payment timers inactive unless separately authorized.

Run commands on the production host in `/srv/badpool/yiimp-badpool/web` as an operator with `sudo` access to the `zako` payment user. Commands below use Bash, Python 3, `/usr/bin/php`, and `sha256sum`. Record command output and timestamps in the task evidence. Never use payout 530's old retained report, checksums, wrapper, or txid for a later payout.

## 1. Fix the exact scope and capture read-only state

Set these values from current coordinator ledgers and database rows, not from an earlier task. Use strictly ascending, unique payout IDs. For a single payout, `PAYOUT_IDS` contains one ID. Build and retain a payout-ID-to-owning-batch map, with each batch's `LANE` (`scrypt`, `groestl`, `yescrypt`, or `skein`); the batch-run `--only` value may differ from the database algorithm (`badcoin-groestl`). Set `BATCH_ID` and `LANE` for **one owning batch at a time** when performing steps 5–6. Verify the installed runner supports that lane's completed-payout resume before using it.

```bash
cd /srv/badpool/yiimp-badpool/web
export PAYOUT_IDS='<ascending-comma-separated-existing-ids>'
export BATCH_ID='<one-owning-batch-id-for-closeout-steps>'
export LANE='<that-batch-only-algorithm>'
git rev-parse HEAD
git branch --show-current
git status --short
for lane in scrypt groestl yescrypt skein; do
  systemctl show "badpool-live-payment-${lane}.timer" -p ActiveState -p SubState -p UnitFileState
  systemctl show "badpool-live-payment-${lane}.service" -p ActiveState -p SubState
done
```

Read each selected payout's owning `/srv/badpool/yiimp-badpool/runtime/badpool-payment-batches/<batch-id>/ledger.json` and the current payout rows. Establish exactly one READY owning ledger **per payout**, `created_payout_ids` membership, phase-6 `READY_FOR_WALLET_APPROVAL`, matching lane/coin/algorithm, earning and block ownership, and phase-file checksums. The selected payouts need not share a batch. Reconcile each row's database amount, destination, `completed=0`, and `tx=NULL` against its ledger's credit/debit and payout-row reports. Inventory *all* unpaid payout rows by algorithm, coin, status, count, and sum before sending. Scan every send journal for the selected IDs, unresolved sends, conflicting txids, or a prior send. An unrelated old fixture ledger or untracked backup is not proof of a conflict; resolve its ownership against live database rows and coordinator state.

The planner creates one operation per wallet binding and source account, ordered by the lowest payout ID in each operation. Payouts from different batches can share an operation when their wallet and source match. Repeated recipient addresses are aggregated within that operation only when every payout's eight-decimal projection reconciles with both the recipient total and the operation total; otherwise preflight refuses the scope. The journal retains each contributing payout ID and amount. Different commissioned wallets have distinct operations and may return different txids. An approved group can therefore produce more than one send. A partial send, uncertain result, or definite failure leaves a durable manual-recovery hold; preserve every returned txid and pending operation and do not replay the scope as a fresh send.

The retained 526–529 journal is historical evidence of four reconciled wallet operations. Treat it as support for multi-wallet execution; its summary alone does not establish that distinct READY batch owners were verified together at send time. The offline cross-batch repository harness proves the grouped ownership and execution contract. A fresh production scope still requires the live evidence checks in this runbook.

Stop before send for duplicate or ambiguous ownership, an unexplained partial row, mismatched amounts, an existing txid, ambiguous journal, invalid amount/address, selected-wallet fault, an active payment timer, or a changed tested production artifact. Do not edit payout rows to make a candidate pass.

## 2. Preflight the selected wallets and retain the exact report

Verify each selected daemon and main chain, its configured source wallet/account, RPC connectivity and usability, destination validity, spendable source-account balance, configured reserve plus fee headroom, and pending/conflicting sends. The lane registry is authoritative for coin, RPC config, wallet datadir, and source account. Do not require unrelated wallets to be healthy for an unselected lane. SHA256d has no commissioned guarded wallet-send lane.

```bash
sudo -u zako /usr/bin/php yaamp/yiic.php badpoolguard multi-wallet-send-preflight \
  "--selected-payout-ids=$PAYOUT_IDS" --format=json
```

The command writes a retained JSON file under `runtime/badpool-multi-wallet-preflights/` and prints its path in `retained_report.path`. Confirm `status=pass`, `classification=PASS`, exact selected IDs, recipient/amount plan, one funding result per **selected** wallet operation, `funding_status=sufficient`, expected reserve, no hold reasons, and `wallet_sends=false`. The reported `approval_object.human_approved=false` and its checksum are comparison evidence only. Copy the exact retained path and checksum from this *fresh* report into the next step. Calculate the file's SHA-256 independently:

```bash
export PREFLIGHT_REPORT='<exact-retained-report-path-from-output>'
sudo -u zako sha256sum "$PREFLIGHT_REPORT"
export PREFLIGHT_SHA='<sha256-of-exact-retained-file>'
export APPROVAL_SHA='<checksums.approval_object.value-from-that-report>'
```

Do not proceed if any value differs from the current authoritative row, wallet, ledger, or preflight evidence. Immediately before send, reread row completion/txid, each ledger's ownership, journal state, each selected wallet's balance and reserve, amount, destination count, timers, and payment HOLD/approval scope. The guarded apply also rebuilds current state and checks the selected wallets before creating its send journal.

## 3. Execute one guarded send, once

Record a durable local attempt marker and a before snapshot first. Issue the following command **one time** for the reviewed scope. Avoid a shell retry loop, a generic process timeout, or a second send when output is lost. Save stdout/stderr even if the process fails.

```bash
sudo -u zako /usr/bin/php yaamp/yiic.php badpoolguard multi-wallet-send-apply \
  "--preflight-report=$PREFLIGHT_REPORT" \
  "--preflight-report-checksum=$PREFLIGHT_SHA" \
  "--approval-checksum=$APPROVAL_SHA" \
  "--selected-payout-ids=$PAYOUT_IDS" \
  --operator-confirms-multi-wallet-send=execute_exact_approved_multi_wallet_scope_no_retry \
  --format=json
```

Capture the apply result, `journal_path`, each wallet operation ID and state, txid by payout ID, send count, database reconciliation status, and completed payout IDs. The guarded path durably writes `SEND_ATTEMPT_STARTED` before the wallet RPC, journals a returned txid, then reconciles only approved database rows. If there is no definite txid, the journal is uncertain, or database closeout fails after a send, **stop and preserve all state; never send again to recover closeout**. Investigate the wallet's transaction history and journal through a separate reconciliation procedure.

## 4. Reconcile the wallet, database, and replay barrier

Read each selected wallet's `gettransaction <txid>` through its registered `-conf` and `-datadir`. Match txid, outgoing recipient(s), exact eight-decimal amount, fee, confirmations, and conflicts. Count sends in complete wallet history over the candidate interval; the expected count is exactly the approved operation count. Compare before/after database snapshots: selected rows alone should move to `completed=1` with the txid of their wallet operation; unrelated payouts, accounts, and earnings should have only the designed changes. Check that the durable journal and every operation reached `RECONCILED` and retained its txid. Verify the actual apply replay path through a controlled test that disables repository and wallet gateway calls; it must return `already_reconciled` with zero new sends. Do not use a second live CLI apply invocation as a replay test.

The payout row and send journal can be reconciled while the **batch ledger remains `READY_FOR_WALLET_APPROVAL`**. That is an incomplete operational closeout. Continue below for every completed payout's owning batch. If the installed runner cannot safely move that algorithm's batch to the completed-payout boundary, stop with the wallet, database, and journal state preserved; do not repeat the send.

## 5. Move each completed owning batch to its closeout boundary

For **each distinct owning batch**, set its exact `BATCH_ID` and `LANE`, then read that ledger again. Require all financial phases 0–6 to have passed and **every** `created_payout_ids` row in that batch to be completed with wallet proof; a grouped send does not authorize closeout of unpaid siblings. Require the database, journal, and wallet proof above to agree for that batch's IDs. If the ledger is still `READY_FOR_WALLET_APPROVAL`, resume that exact batch **only when the installed batch runner supports that lane's completed-payout boundary**. The runner must recognize the completed payouts and return `HOLD_COMPLETED_PAYOUT_RECONCILIATION` without rerunning a financial phase. Compare phase results before and after. If it is already at that HOLD, proceed. Any other state is a stop for investigation.

```bash
sudo -u zako /usr/bin/php yaamp/yiic.php badpoolguard batch-run \
  "--resume-batch-id=$BATCH_ID" "--only=$LANE" --format=json
```

`HOLD_COMPLETED_PAYOUT_RECONCILIATION` is the expected safe boundary, not final closeout. Do **not** rerun wallet send, account credit, or payout-row creation.

## 6. Produce proof, apply ledger closeout, verify terminal state

Repeat this step **independently for each owning batch**, using that batch's `BATCH_ID` and a separate proof file and approval. The first command is read-only and performs wallet proof for the exact batch payout IDs. It must return `status=pass`, the expected `created_payout_ids`, `wallet_sends=false`, and `db_mutations=false`. Save its exact JSON bytes to a regular file accessible to `zako`, calculate SHA-256 of that file, then run the second command. The apply mutates only this batch ledger to `RECONCILED`; it sends no wallet transaction and changes no database row.

```bash
export CLOSEOUT_PROOF="$(sudo -u zako mktemp "/srv/badpool/yiimp-badpool/runtime/badpool-payment-batches/$BATCH_ID/closeout-proof.XXXXXX.json")"
sudo -u zako /usr/bin/php yaamp/yiic.php badpoolguard completed-payout-batch-closeout \
  "--batch-id=$BATCH_ID" --format=json | sudo -u zako tee "$CLOSEOUT_PROOF"
sudo -u zako sha256sum "$CLOSEOUT_PROOF"
export CLOSEOUT_SHA='<sha256-of-exact-closeout-proof-file>'
```

Inspect the saved proof and wallet transaction evidence **before** this ledger mutation:

```bash
sudo -u zako /usr/bin/php yaamp/yiic.php badpoolguard completed-payout-batch-closeout-apply \
  "--batch-id=$BATCH_ID" \
  "--closeout-proof=$CLOSEOUT_PROOF" \
  "--closeout-proof-checksum=$CLOSEOUT_SHA" \
  --operator-confirms-closeout=reconcile_completed_payout_ledger_only \
  --format=json
```

Require apply `status=pass`, `canonical_terminal_state=RECONCILED`, exact payout IDs, and `wallet_sends=false`/`db_mutations=false`. Reread `ledger.json` and prove `batch_state=RECONCILED`, `reconciled_payout_ids` exact, and `reconciliation_proof_checksum` equals the saved proof's SHA-256. Run a read-only coordinator ledger scan: the batch must be absent from READY and unresolved sets. Preserve the receipt, preflight, send journal, tx proof, DB before/after, replay result, closeout proof and apply result.

Finally inventory the remaining unpaid rows and all four timer states again. Report production HEAD and deployed file hash, selected IDs/lane/coin/amount, exact wallet send count and txid, wallet proof, DB/journal/replay result, terminal batch state, unrelated-row comparison, remaining backlog, and unresolved risks. A second payout requires its own authorization and a fresh run from step 1.

For a fully worked production example and evidence paths, see [POOL-L3.196A](pool-l3-196a-guarded-payout-recovery.md). For the command contracts and lane registry, see [live payment coordinator](badpool-live-payment-coordinator.md).
