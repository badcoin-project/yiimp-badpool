# Badpool Stage1-only bounded drain runner

The Stage1 drain runner repeats the existing guarded forward-catchup Stage1 approval/apply flow in bounded coin-scoped batches. It is only for Stage1 block/earning catchup and does not authorize later accounting or payout stages.

## Command shapes

```bash
php yaamp/yiic.php badpoolguard forward-catchup-stage1-drain-plan --coin-id=1267 --max-batches=3 --batch-limit=50 --format=json
php yaamp/yiic.php badpoolguard forward-catchup-stage1-drain-apply --coin-id=1267 --max-batches=3 --batch-limit=50 --operator-confirms-stage1-drain=stage1_only_no_later_accounting_no_wallet --format=json
```

## Safety boundaries

* Coin-scoped only: `--coin-id` is required and all-coin mutation is refused.
* `--batch-limit` is capped at 50.
* `--max-batches` is required and capped by `FORWARD_CATCHUP_STAGE1_DRAIN_SAFE_MAX_BATCHES` unless code and tests are intentionally changed.
* Plan is read-only and does not execute apply logic.
* Apply uses `schema=badpool.guardrail.apply.v1` and `mode=guarded-apply`.
* Hard boundary: no maturity transition, no account-credit, no payout-row creation, no wallet-send, no backend loop start, no service/cron changes, and no share deletion.

## Summary JSON shape

```json
{
  "schema": "badpool.guardrail.apply.v1",
  "status": "pass",
  "command": "forward-catchup-stage1-drain-apply",
  "coin_id": 1267,
  "batch_limit": 50,
  "max_batches": 3,
  "batches_attempted": 3,
  "batches_applied": 3,
  "total_selected": 150,
  "total_generated": 147,
  "total_orphan": 3,
  "total_inserted_earnings": 147,
  "total_projected_pending_amount": 318114.1474558799,
  "final_preview_selected_count": 6,
  "stop_reason": "max_batches_reached",
  "errors": [],
  "warnings": [],
  "per_batch": [
    {
      "batch_number": 1,
      "selected_count": 50,
      "projected_generated_rows": 49,
      "projected_orphan_rows": 1,
      "projected_pending_amount": 106038.04915195997,
      "approval_package_checksum": {"value": "..."},
      "batch_scope_checksum": {"value": "..."},
      "projected_mutation_checksum": {"value": "..."},
      "projected_earnings_checksum": {"value": "..."},
      "inserted_earnings_count": 49,
      "reconciliation_status": "pass"
    }
  ]
}
```
