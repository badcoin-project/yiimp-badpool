# SHA256D Modern Stratum Compatibility

## Scope

This patch is repository-only and targets SHA256D miner negotiation compatibility. It does not change payout/accounting code, database schema, wallet behavior, systemd units, or production data.

## Findings

- SHA256D is represented internally as `sha256` in stratum configuration.
- `stratum.cpp` maps `sha256` to `sha256_double_hash`, so the internal name is expected for SHA256D.
- Valid shares already reach the SHA256D submit path, which supports leaving the hash mapping untouched.
- `mining.configure` is a modern Stratum extension negotiation method. Previously, if unhandled, it was treated as an unknown method, returned error 20, incremented `submit_bad`, and logged noisy unknown-method entries.
- Invalid submit rows with `difficulty=0` and `share_diff=0` occur when a submit fails before hash difficulty is computed. In that path, `client_submit_error(...)` records the invalid share with `share_diff=0`, while the invalid worker accumulator does not add difficulty for invalid shares.

## Patch Behavior

- Accept `mining.configure` in `stratum/client.cpp`.
- Return a valid JSON-RPC success result object.
- Mark requested extensions as unsupported with `false`.
- Do not advertise version rolling or ASICBoost support.
- Do not increment `submit_bad` for harmless negotiation.
- Leave `mining.multi_version` behavior unchanged.
- Leave all `mining.submit` validation and invalid share recording unchanged.

## Pre-Production Test Plan

1. Build the stratum binary from the repository branch.
2. Confirm `mining.configure` returns JSON similar to:

   ```json
   {"id":1,"result":{"version-rolling":false},"error":null}
   ```

3. Confirm `mining.subscribe`, `mining.authorize`, and `mining.submit` still follow the existing flow.
4. Confirm malformed submits still return the existing reject errors and still record invalid rows.
5. Confirm valid SHA256D test shares still use `sha256_double_hash`.
6. Confirm logs no longer show `unknown method mining.configure` for modern miners.
7. Confirm no behavior changed for scrypt, groestl, skein, yescrypt, or other algos except this generic negotiation response.

## Rollback

Revert the commit that adds `client_configure(...)` and removes the `mining.configure` dispatch entry. No database rollback is required because the patch does not modify schema or production data.
