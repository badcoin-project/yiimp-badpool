#!/usr/bin/env python3
"""Compile and run the exact reconnect-identity helpers in a route-free fixture."""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

SOURCE=Path(__file__).resolve().parents[2]
OUT=Path(__file__).resolve().parent/'results'
RUN_ID=os.environ.get('R2_RUN_ID','helper')
report={'task':'POOL-L3.199I-R2','classification':'PARTIAL','run_id':RUN_ID,
        'source_commit':os.environ.get('I_SOURCE_COMMIT','')}

def run(command):
    return subprocess.run([str(x) for x in command],text=True,capture_output=True,timeout=30)

def main():
    if not re.fullmatch(r'[A-Za-z0-9_-]{1,64}',RUN_ID):
        raise RuntimeError('invalid run ID')
    routes=run(['ip','-j','route'])
    if routes.returncode or json.loads(routes.stdout)!=[]:
        raise RuntimeError('route-free namespace required')
    report['route_free']=True
    head=run(['git','-C',SOURCE,'rev-parse','HEAD'])
    if head.returncode or not report['source_commit'] or head.stdout.strip()!=report['source_commit']:
        raise RuntimeError('wrong source commit')
    report['binary_sha256']=hashlib.sha256((SOURCE/'stratum/stratum').read_bytes()).hexdigest()
    with tempfile.TemporaryDirectory(prefix='l3-199i-r2-helper-',dir='/tmp') as tmp:
        binary=Path(tmp)/'reconnect-identity-test'
        command=['g++','-std=c++17','-O2','-I'+str(SOURCE/'stratum'),
                 '-I/usr/include/mariadb','-I/usr/include/x86_64-linux-gnu',
                 SOURCE/'review/l3-199i-r2/test_reconnect_identity.cpp','-o',binary]
        compiled=run(command)
        report['compile']={'command':[str(x) for x in command],'exit':compiled.returncode,
                           'stderr':compiled.stderr[-3000:]}
        if compiled.returncode:
            report['classification']='FAIL'
            return 1
        tested=run([binary])
        report['test']={'exit':tested.returncode,'stdout':tested.stdout.strip(),
                        'stderr':tested.stderr[-3000:]}
        if tested.returncode:
            report['classification']='FAIL'
            return 1
        parsed=json.loads(tested.stdout)
        report['focused_result']=parsed
        if parsed.get('classification')!='PASS' or not all(parsed.get('checks',{}).values()):
            report['classification']='FAIL'
            return 1
    report['classification']='PASS'
    return 0

if __name__=='__main__':
    try:
        code=main()
    except Exception as exc:
        report['error']=str(exc)
        code=2
    finally:
        OUT.mkdir(parents=True,exist_ok=True)
        with (OUT/f'result-{RUN_ID}.json').open('x') as output:
            output.write(json.dumps(report,indent=2)+'\n')
        print(json.dumps({'classification':report['classification'],'error':report.get('error')}))
    sys.exit(code)
