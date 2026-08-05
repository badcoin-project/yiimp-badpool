# Badpool immutable Stage1 drain manifest

The forward Stage1 drain uses one finite approval manifest for one exact bounded cohort. The required selection limit authorizes only the first deterministic eligible candidates in `height,time,id` order. The operator approves the manifest once. The default 25-block batch is an internal transaction, verification, progress, and resume boundary; it is not another human approval boundary.

The internal batch size must remain between 1 and 50. Both manifest generation and apply-time manifest validation enforce this transaction ceiling.

## Generate and preserve the approval manifest

The approval-package command performs chain and database reads, emits every selected block ID and every projected row, and performs no database write:

```bash
cd /srv/badpool/yiimp-badpool/web
php yaamp/yiic.php badpoolguard forward-catchup-stage1-drain-approval-package \
  --coin-id=1267 \
  --selection-limit=50 \
  --internal-batch-size=25 \
  --format=json > /var/lib/badpool/stage1-scrypt-manifest.json
```

`forward-catchup-stage1-drain-plan` is a compatibility alias for the same bounded manifest. The selection limit controls the authorized cohort; the internal batch size controls only transaction boundaries.

The operator reviews the full JSON and preserves it unchanged. Important authority-bearing fields include:

* `selection_limit`, `eligible_candidate_count`, `selected_count`, and complete `selected_block_ids`;
* `excluded_by_selection_limit_count`, `excluded_newer_candidate_count`, and `snapshot_boundary`;
* complete `selected_records`, `projected_block_mutations`, and `projected_earning_rows`;
* `projected_recipient_totals` and `projected_total_amount`;
* `canonical_checksum_inputs`, `selection_checksum`, `intended_mutation_checksum`, and `package_checksum`;
* `internal_batch_size` and `projected_batch_count`; and
* `exact_operator_confirmation`.

The complete eligibility snapshot is fixed before daemon classification begins. Only its first `selection_limit` candidates are classified and projected. The snapshot records both the maximum selected order key and maximum eligible order key, so intentionally unselected snapshot candidates are not mislabeled as newer. A block arriving after the eligibility query is separately reported as newer/excluded and cannot join the manifest.

## Apply the approved manifest

Use the exact package checksum and confirmation printed by the manifest:

```bash
cd /srv/badpool/yiimp-badpool/web
php yaamp/yiic.php badpoolguard forward-catchup-stage1-drain-apply \
  --coin-id=1267 \
  --manifest-file=/var/lib/badpool/stage1-scrypt-manifest.json \
  --progress-file=/var/lib/badpool/stage1-scrypt-progress.json \
  --package-checksum=<manifest-package-checksum> \
  --operator-confirms-stage1-drain=<exact-operator-confirmation-from-manifest> \
  --format=json
```

The consumer independently recomputes the selection, intended-mutation, and package checksums. It refuses a different coin, altered file, wrong checksum, wrong confirmation, invalid ordering, duplicate ID, changed attribution, changed amount, changed projection, or incompatible progress file before mutation.

The process then handles the manifest IDs consecutively in deterministic batches. Each pending batch is re-read from the chain without a wallet read, compared with the approved projection, preflighted, committed in its own database transaction, verified, and recorded atomically in the progress file. No additional operator confirmation occurs between batches.

## Interruption and resume

The progress file binds to the package checksum and full selected cohort. It records:

* completed batch count and completed block IDs;
* remaining block IDs;
* rows and amounts for each completed batch;
* cumulative rows and amount;
* failure point and reason;
* whether retry is safe; and
* that the identical manifest confirmation remains required; and
* a `progress_checksum` covering the complete persisted progress record.

On every invocation, the progress checksum, complete-batch prefix, per-batch rows and amounts, and cumulative totals are independently validated before database state is checked. A manifest entry is then either fully pending, fully completed exactly as projected, or a mismatch. Fully completed batches are verified and skipped. Altered progress, a mixed or partially linked state, different attribution or value, or any other incompatible state fails closed. This makes resume safe even if the process stopped after a commit but before progress-file publication.

Unselected candidates inside the eligibility snapshot and newer production candidates are never appended. Newer candidates are counted separately for visibility. Resume always begins with the first uncompleted manifest entry and accepts only the same package checksum and bounded cohort.

## Final reconciliation and hard boundary

Completion requires every selected ID to reconcile, the actual earning rows to equal the manifest projection, recipient totals to match, and the actual total amount to equal `projected_total_amount`.

This command performs Stage1 only: no maturity transition, no account credit, no payout-row creation, no wallet read, no wallet send, no backend loop, no service action, and no share deletion. Later pipeline stages require separate aggregate approvals and their own internal batching.
