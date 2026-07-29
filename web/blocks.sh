#!/bin/bash

PHP_CLI=${PHP_CLI:-php}

DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd ${DIR}

if [[ "${1:-}" != "--once" || "${2:-}" != --mode=* ]]; then
	echo '{"schema":"badpool.backend-cycle.v1","route":"blocks","result":"refused","errors":["usage: blocks.sh --once --mode=report-only|execute"]}'
	exit 64
fi
export BADPOOL_BACKEND_MODE="${2#--mode=}"
exec ${PHP_CLI} -d max_execution_time=60 runconsole.php cronjob/runBlocks

