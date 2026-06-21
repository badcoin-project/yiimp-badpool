# BadPool

BadPool is a Badcoin-focused mining pool codebase derived from Yiimp/Yaamp-era pool software. This repository is maintained for Badcoin pool development and documentation while preserving appropriate upstream attribution and history.

This repository contains pool web, stratum, database, and operational-adjacent code. Treat changes here as potentially production-sensitive even when they are documentation-only.

## Upstream attribution

BadPool is based on Yiimp, which itself traces back to Yaamp-derived pool software and the Yii PHP framework. Preserve upstream copyright notices, license headers, file history, and attribution when modifying existing files.

Historical upstream notes credited globalzon for releasing the initial Yaamp source code and tpruvot for Yiimp maintenance. This README does not replace that attribution; it removes stale install/run instructions that could be mistaken for current Badcoin operational truth.

## Project governance and organization defaults

BadPool follows Badcoin organization-wide repository defaults unless this repository provides more specific guidance:

- [Contributing](https://github.com/badcoin-project/.github/blob/main/CONTRIBUTING.md)
- [Security policy](https://github.com/badcoin-project/.github/blob/main/SECURITY.md)
- [Support policy](https://github.com/badcoin-project/.github/blob/main/SUPPORT.md)
- [Branch policy](https://github.com/badcoin-project/.github/blob/main/docs/branch-policy.md)
- [Repository hygiene](https://github.com/badcoin-project/.github/blob/main/docs/repo-hygiene.md)

## Branch flow

- `main` is stable and release-oriented.
- `development` is the integration branch for reviewed work before it is promoted toward `main`.
- Scoped branches such as `docs/readme-hygiene`, `bugfix/stratum-guard`, or `refactor/config-loader` should target `development` unless maintainers document a different target for a specific change.

A Git merge is not production deployment approval. Merging into `development` does not approve service restarts, database mutations, wallet actions, payout actions, fund movement, or production deployment.

## Production-safety expectations

Pool software can affect miners, accounting, payouts, wallets, coin daemons, and infrastructure. Keep changes conservative and reviewable.

Do not treat repository documentation, merge status, or branch state as proof that any live service is running, healthy, funded, payout-ready, or approved for deployment. If current runtime status is not documented by maintainers in an appropriate operational channel, make no claims about live service state.

Before proposing operational changes, clearly separate:

- code review,
- configuration review,
- database review,
- wallet/fund-movement review,
- deployment approval,
- restart approval,
- payout approval.

These are separate decisions. Approval of one does not imply approval of the others.

## Secrets and local artifacts

Never commit secrets or local runtime material. This includes, but is not limited to:

- wallet files or wallet backups,
- RPC usernames, RPC passwords, credentials, tokens, cookies, and API keys,
- SQL dumps, database exports, and private migration scratch files,
- logs, debug traces, crash dumps, backups, and runtime files,
- local configs such as `web/serverconfig.php`, daemon configs, pool configs, or environment files,
- production hostnames, private contacts, private infrastructure notes, or private incident details.

Use `.gitignore` and local-only files for machine-specific state, but do not rely on ignore rules as the only protection. Review diffs before committing.

## Documentation status

The old README contained generic Yiimp installation and runtime instructions, including sample web-server snippets, shell startup notes, exchange key paths, admin login notes, and donation text. Those instructions were stale for this Badcoin repository and could be misread as current deployment guidance.

For now, this README intentionally provides project identity, attribution, governance links, branch flow, and safety boundaries only. Add repository-specific setup or operation documentation in focused follow-up changes after maintainer review.
