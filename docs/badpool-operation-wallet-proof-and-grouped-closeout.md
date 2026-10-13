# Operation wallet proof and grouped batch closeout

This contract applies only to an exact, fully `RECONCILED` guarded multi-wallet send journal whose A13 retained `payout_evidence` and `wallet_operation_evidence` are present. It does not authorize a send, deployment, service action, or database update. The historical `wallet-proof-closeout` and single-batch v1 proof remain separate and retain their original full-transaction amount rule.

## Read-only proof v2

`operation-wallet-proof --journal-path=<exact path> --journal-sha256=<file SHA-256> --format=json` accepts one regular journal file in the authoritative journal directory. The filename is the execution approval checksum. The complete file SHA-256, journal schema, approval checksum, plan checksum, sorted payout IDs, and global and per-operation `RECONCILED` states are checked. The proof currently bounds one journal to at most 25 payout IDs and 25 owning batches. A v1 journal without retained payout and operation evidence is refused by this v2 path; it is neither rewritten nor inferred from payout rows.

For each ID, the proof requires exactly one durable owner and an exact completed database row. It compares account, coin, raw decimal amount, recipient, and batch against retained send evidence. Its eight-place amount must equal the half-up projection of the raw payout amount. Every ID must occur in exactly one journal wallet operation, and the operation ID is recomputed from approval checksum, wallet binding, source account, payout IDs, and recipient totals. Payout row txids must equal that operation's recorded txid. The summed individual projections must exactly equal each recipient's retained amount and the operation total. Raw amounts are summed as decimal strings and checked against the retained raw operation total.

The fixed `gettransaction` reader selects only the registered wallet config and datadir for the operation coin. It preserves JSON numeric tokens as strings for the operation proof. Each operation is read once. The proof requires the returned txid, a negative debit equal to all operation destinations, an explicit nonpositive fee, at least one confirmation, no wallet conflicts, no abandoned transaction, and `send` details whose address and eight-place debits exactly reproduce the retained recipients. Changing confirmation count does not change the stable operation proof hash, but every fresh check still enforces the confirmation policy.

The runner's existing completed-payout contract checks durable ownership, all phase 0–6 artifact checksums, phase-6 created payout IDs, selected coin and lane, and activation boundary without writing a ledger. A batch is eligible for this one-journal workflow only if all of its `created_payout_ids` are present in the journal and completed. The v2 batch allocation records every payout's recipient, raw amount, eight-place amount and txid; recipient and batch subtotals; referenced operation proof hashes; phase checksum hash; and journal identity. A batch subtotal need not equal the wallet operation debit when another batch shares that operation. Batches with siblings outside the journal require a separately reviewed proof design and are refused here.

## One grouped approval and durable progress

`grouped-batch-closeout-preview` produces a read-only, sorted batch set and embeds the complete operation and v2 batch proofs. Save its exact JSON bytes and calculate the file SHA-256. One later human approval covers only that saved sorted set, its per-batch allocation hashes, operation proof hashes, and journal SHA-256. The apply command requires the preview path, file checksum, and exact confirmation `reconcile_exact_grouped_batches_ledger_only`.

Apply reruns the full read-only proof before creating progress. The saved approval must equal the fresh preview in all fields except the current confirmation count. It writes one progress journal named by the approved preview file hash and records each batch as `PENDING`, `APPLYING`, `VERIFIED`, or `HOLD`. One lock in the grouped progress directory serializes all grouped approvals, including different previews that overlap in batch ownership. It reuses the existing exact `batch-run --resume-batch-id=<id> --only=<lane>` boundary. A private grouped transition checks the runner's phase contract and exact v2 proof before atomically writing the owned ledger; it has no wallet-send or accounting gateway. The standalone completed-payout apply retains its historical v1-only proof contract and refuses v2 allocations, including direct calls that supply extra arguments.

On interruption, a durable `APPLYING` batch is checked against the actual ledger. A terminal batch must have exact reconciled IDs and the deterministic retained v2 proof file hash. Such a batch becomes `VERIFIED` without another apply. A batch still at READY or HOLD can continue through the existing boundary and grouped ledger transition. An observed validation or apply error marks that batch `HOLD` for investigation; it never triggers a send or financial phase. A `VERIFIED` batch is rechecked on every replay. The process stops at the first unverified result. It does not roll back earlier verified ledger closeouts. Progress and ledger writes flush and sync the file before atomic rename; the operator must retain backups because the sequence spans multiple ledgers rather than one transaction.

## Operator sequence and gates

For a new payout scope, the existing guarded preflight and one explicit wallet-send approval remain mandatory. After the send journal, database rows, and wallet transactions are reconciled, grouped closeout has **one additional human approval** for the exact saved preview. If the send is already reconciled, only that grouped-closeout approval remains. A different journal or changed preview requires a new review and approval. The group apply performs no wallet send, database payout update, or accounting phase.

On the production host, from `/srv/badpool/yiimp-badpool/web`, use the exact current paths and checksums, after validating the deployed handlers and freeze state:

```bash
sudo -v
sudo -u zako /usr/bin/php yaamp/yiic.php badpoolguard operation-wallet-proof --journal-path="$JOURNAL_PATH" --journal-sha256="$JOURNAL_SHA" --format=json
sudo -u zako /usr/bin/php yaamp/yiic.php badpoolguard grouped-batch-closeout-preview --journal-path="$JOURNAL_PATH" --journal-sha256="$JOURNAL_SHA" --format=json
```

Save the complete second JSON result to a regular file accessible to `zako`, inspect all batch IDs and proof hashes, and independently calculate its SHA-256. After the exact group has been approved:

```bash
sudo -v
sudo -u zako /usr/bin/php yaamp/yiic.php badpoolguard grouped-batch-closeout-apply --group-preview="$GROUP_PREVIEW" --group-preview-checksum="$GROUP_SHA" --operator-confirms-grouped-closeout=reconcile_exact_grouped_batches_ledger_only --format=json
```

Inspect the returned progress path and independently reread every terminal ledger. If any result is `HOLD`, retain the preview, proof files, progress journal, runner output, and current ledgers. Resolve the exact cause before considering a replay; never rerun wallet send to recover a closeout.

## Deployment prerequisites

Before copying or deploying code, obtain fresh production HEAD, status, timer states, and deployed hashes. Diff the deployed `BadpoolGuardedMultiWalletSend.php` and `BadpoolMultiWalletSendApply.php` byte for byte against the reviewed candidate and the verified A13 reference artifacts retained in private operations evidence. Historical hashes do not establish today's deployed state. Preserve A13 replay, returned-txid recovery, and journal evidence. Review the exact PHP version and wallet `gettransaction` output shape on each selected lane, then execute the repository, preflight, state-machine, apply, closeout, and grouped harnesses on the candidate artifact. No merge, deployment, timer change, wallet send, or production database mutation follows automatically from this document.
