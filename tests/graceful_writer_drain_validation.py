#!/usr/bin/env python3
"""Build and run the process-level graceful writer-drain signal test."""
import pathlib
import subprocess

root = pathlib.Path(__file__).resolve().parent.parent
build = root / 'tests' / '.graceful_writer_drain_driver'
command = [
    'g++', '-std=c++17', '-Wall', '-Wextra', '-Werror', '-pthread',
    '-I' + str(root / 'stratum'),
    str(root / 'tests' / 'graceful_writer_drain_driver.cpp'),
    str(root / 'stratum' / 'shutdown_drain.cpp'), '-o', str(build),
]
result = subprocess.run(command, cwd=root, text=True, capture_output=True)
if result.returncode:
    print(result.stdout + result.stderr)
    raise SystemExit(result.returncode)
result = subprocess.run([str(build)], cwd=root, text=True, capture_output=True)
print(result.stdout + result.stderr, end='')
if result.returncode:
    raise SystemExit(result.returncode)

checks = {
    root / 'stratum' / 'shutdown_drain.cpp': [
        'sigaction(SIGTERM', 'sigaction(SIGINT', 'g_shutdown_requested = 1',
        'pthread_cond_wait', 'g_active_submissions',
    ],
    root / 'stratum' / 'client_submit.cpp': [
        'StratumSubmissionGate submission_gate', 'Stratum is draining',
    ],
    root / 'stratum' / 'stratum.cpp': [
        'stratum_begin_drain();', 'STRATUM_DRAIN_FAILED',
        'STRATUM_DRAIN_COMPLETE', 'share_pending_count()',
    ],
    root / 'stratum' / 'share.cpp': [
        'bool share_write', 'if(!db_query_write(db, buffer))',
        'unsigned int share_pending_count()',
    ],
}
for path, needles in checks.items():
    source = path.read_text()
    for needle in needles:
        if needle not in source:
            print(f'MISSING {path.name}: {needle}')
            raise SystemExit(1)
build.unlink(missing_ok=True)
print('GRACEFUL_WRITER_DRAIN_SOURCE_CHECK=PASS')
