#!/usr/bin/env python3
"""Start ONLY a socket-only disposable test DB, never a system service."""
import os, pathlib, subprocess, tempfile, time
root=pathlib.Path('/tmp/badpool-l3194-test-tools/extracted')
env=dict(os.environ,LD_LIBRARY_PATH=str(root/'usr/lib/x86_64-linux-gnu'))
directory=pathlib.Path('/tmp/badpool-l3194-isolated-db')
directory.mkdir(exist_ok=True)
subprocess.run([str(root/'usr/bin/mysql_install_db'),'--no-defaults','--basedir='+str(root/'usr'),
 '--datadir='+str(directory/'data'),'--auth-root-authentication-method=normal','--skip-test-db'],env=env,check=True)
log=open(directory/'server.log','w')
server=subprocess.Popen([str(root/'usr/sbin/mysqld'),'--no-defaults','--basedir='+str(root/'usr'),
 '--datadir='+str(directory/'data'),'--socket='+str(directory/'mysql.sock'),'--pid-file='+str(directory/'test.pid'),
 '--skip-networking','--user='+__import__('getpass').getuser(),'--innodb-flush-log-at-trx-commit=1'],env=env,stdout=log,stderr=log)
for _ in range(100):
 if (directory/'mysql.sock').exists():
  print('ISOLATED_TEST_DB_READY pid='+str(server.pid));break
 if server.poll() is not None: raise RuntimeError((directory/'server.log').read_text())
 time.sleep(.1)
else: server.terminate(); raise RuntimeError('isolated test DB startup timeout')
