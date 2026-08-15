# Exact block binding for maturity authorization

`earnings-maturity-transition-dryrun` and `earnings-maturity-transition-approval-package` accept an optional `--selected-block-ids=<comma-separated IDs>`. The value is strict: it must be a non-empty list of unique, canonical positive decimal integers. The command sorts the IDs numerically, verifies every block exists and belongs to `--coin-id`, and selects eligible earnings only through those blocks. Missing, wrong-coin, malformed, empty, or duplicate scope is refused; it never falls back to coin-wide selection.

Bounded reports distinguish `requested_block_ids`, `selected_linked_block_ids`, and `requested_blocks_without_selected_earnings`. They bind the coin, `exact-blocks` selection mode, canonical requested scope, selected earnings, linked blocks, mutation previews, and total through semantic checksums. Reordering an equivalent request does not change those checksums.

The backward Scrypt clean-tail review uses a stricter, read-only-only contract documented in [badpool-backward-scrypt-maturity-dryrun.md](badpool-backward-scrypt-maturity-dryrun.md). That inventory has intentional earning and block ID gaps, so it binds both explicit ID lists and does not reuse this command's approval or apply paths.

Bounded approval packages use `badpool.approval_package.v2`; existing unbounded packages remain `badpool.approval_package.v1` and retain their existing behavior. Version 1 is never reinterpreted as bounded. A v2 apply command carries both `--selection-mode=exact-blocks` and the canonical `--selected-block-ids`. Apply freshly rebuilds that bounded cohort and requires the supplied earning IDs and every checksum to match exactly before opening a transaction. Newly eligible coin-wide rows are not added.

## Apply closeout classification

Wrappers validating an `earnings-maturity-transition-apply` result must use `BadpoolGuardReport::classifyMaturityApplyResult()`. The classifier requires command success, the canonical guarded-apply report contract, an exact committed transaction, exact authorized row counts, an empty error list, and explicit safe values for every mutation-boundary flag. Missing flags and any account credit, payout-row creation, wallet send, backend-loop execution, or share deletion classify the closeout as `hold`.

Amount comparisons are the only normalized portion of this closeout check. Projected, applied, and authorized totals are rounded as decimal strings to the chain/accounting precision of eight decimal places before comparison. Higher-precision display residue such as `107970.307768920000` versus `107970.307768919971` therefore compares equal as `107970.30776892`; an actual one-satoshi mismatch remains a hold. Wrappers must not compare these values as raw strings or binary floats.
