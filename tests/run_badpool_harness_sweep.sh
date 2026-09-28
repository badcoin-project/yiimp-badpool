#!/usr/bin/env bash
set -u

total=0
passed=0
failed=0

for harness in tests/badpool_*_harness.php; do
	total=$((total + 1))
	output="$(php "$harness" 2>&1)"
	status=$?
	printf '%s :: %s\n' "$harness" "$(printf '%s\n' "$output" | tail -n 1)"
	if [ "$status" -eq 0 ]; then
		passed=$((passed + 1))
	else
		failed=$((failed + 1))
		printf '%s\n' "$output"
	fi
done

printf 'SWEEP_TOTAL=%s SWEEP_PASSED=%s SWEEP_FAILED=%s\n' "$total" "$passed" "$failed"
test "$failed" -eq 0
