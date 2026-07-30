#!/bin/bash

PHP_CLI=${PHP_CLI:-/usr/bin/php}

DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
refuse() {
	local code="$1" message="$2" now
	now="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
	printf '{"schema":"badpool.backend-cycle.v2","route":"blocks","mode":"invalid","started_at_utc":"%s","completed_at_utc":"%s","lock_acquired":false,"readiness_gate":"refused","callbacks_started":[],"callbacks_completed":[],"callbacks_failed":[],"declared_effect_classes":[],"instrumentation_available":false,"result":"refused","errors":["%s"]}\n' "$now" "$now" "$message"
	exit "$code"
}
if ! cd "${DIR}"; then
	refuse 70 'script directory is unavailable'
fi

if [[ $# -ne 2 || "$1" != "--once" || ( "$2" != "--mode=report-only" && "$2" != "--mode=execute" ) ]]; then
	refuse 64 'usage: blocks.sh --once --mode=report-only|execute'
fi
if [[ "${PHP_CLI}" != /* || ! -f "${PHP_CLI}" || ! -x "${PHP_CLI}" ]]; then
	refuse 69 'PHP_CLI must be one absolute executable file pathname'
fi
export BADPOOL_BACKEND_MODE="${2#--mode=}"
exec "${PHP_CLI}" -d max_execution_time=60 runconsole.php cronjob/runBlocks
