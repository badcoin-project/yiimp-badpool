#!/usr/bin/env python3
"""Stop only the exact disposable socket-only database created by the test setup."""
import os,pathlib,signal,time
directory=pathlib.Path('/tmp/badpool-l3194-isolated-db')
pid=int((directory/'test.pid').read_text())
command=(pathlib.Path('/proc')/str(pid)/'cmdline').read_bytes().split(b'\0')
assert command[0]==b'/tmp/badpool-l3194-test-tools/extracted/usr/sbin/mysqld'
assert b'--datadir=/tmp/badpool-l3194-isolated-db/data' in command and b'--skip-networking' in command
os.kill(pid,signal.SIGTERM)
for _ in range(100):
 if not (directory/'mysql.sock').exists():print('ISOLATED_TEST_DB_STOPPED');break
 time.sleep(.1)
else:raise RuntimeError('disposable test database did not stop within 10 seconds')
