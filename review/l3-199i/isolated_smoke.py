#!/usr/bin/env python3
"""Disposable, route-free POOL-L3.199I Stratum regression test. Run under unshare -n.

This runner deliberately exits nonzero for PARTIAL and leaves a JSON report.
It never reads a production configuration or connects to a non-loopback host.
"""
import gzip
import hashlib
import http.server
import json
import os
from pathlib import Path
import re
import resource
import select
import signal
import socket
import socketserver
import subprocess
import sys
import tempfile
import threading
import time
from datetime import datetime, timezone

SOURCE = Path(__file__).resolve().parents[2]
OUT = SOURCE/'review/l3-199i-r2/results'
RUN_ID = os.environ.get('R2_RUN_ID',f'run-{os.getpid()}-{time.time_ns()}')
if not re.fullmatch(r'[A-Za-z0-9_-]{1,64}',RUN_ID):
    raise SystemExit('invalid R2_RUN_ID')
BIN = Path('/mnt/d/BAD/BadConeGit/yiimp-badpool/.worktrees/production-authoritative-reconciliation-evidence/l3-199c-mariadb-bin')
PORT_DB, PORT_RPC, PORT_STRATUM = 43317, 43318, 43319
report = {'task': 'POOL-L3.199I-R2', 'classification': 'PARTIAL',
          'source_commit': os.environ.get('I_SOURCE_COMMIT', ''), 'checks': {}, 'migrations': [],
          'attempt': {'id':RUN_ID,'concurrency_level':int(os.environ.get('R2_CONCURRENCY','1')),
                      'started_utc':datetime.now(timezone.utc).isoformat()}}
children = []

def run(cmd, data=None, check=True, timeout=120):
    p = subprocess.run([str(x) for x in cmd], input=data, text=True, stdout=subprocess.PIPE,
                       stderr=subprocess.PIPE, timeout=timeout)
    if check and p.returncode:
        raise RuntimeError(f'{cmd[0]} exit {p.returncode}: {(p.stderr+p.stdout)[-1600:]}')
    return p

def wait_file(path, child, seconds=15):
    for _ in range(int(seconds*10)):
        if path.exists(): return
        if child.poll() is not None: raise RuntimeError(f'child exited {child.returncode} before {path}')
        time.sleep(.1)
    raise RuntimeError(f'timed out waiting for {path}')

def wait_listener(child, port, seconds=7, probe=None):
    """Require a live child and an observed listener before the deadline."""
    if probe is None:
        def probe():
            with socket.socket() as sock:
                sock.settimeout(.1)
                return sock.connect_ex(('127.0.0.1',port)) == 0
    deadline=time.monotonic()+seconds
    while time.monotonic()<deadline:
        if child.poll() is not None:
            raise RuntimeError(f'listener process exited before opening: {child.returncode}')
        if probe():
            if child.poll() is not None:
                raise RuntimeError(f'listener process exited after probe: {child.returncode}')
            return True
        time.sleep(.1)
    raise RuntimeError(f'listener on {port} missing after {seconds}s')

def require_exit(child, expected, label):
    if child.returncode != expected:
        raise RuntimeError(f'{label} exit {child.returncode}, expected {expected}')

def main():
    if os.geteuid() != 0: raise RuntimeError('run as root only inside disposable netns')
    run(['ip','link','set','lo','up'])
    addr = run(['ip','-j','addr']).stdout
    routes = run(['ip','-j','route']).stdout.strip()
    links = json.loads(addr)
    if len(links) != 1 or links[0]['ifname'] != 'lo' or json.loads(routes) != []:
        raise RuntimeError('STOP: namespace has a non-loopback interface or route')
    report['network'] = {'addresses': links, 'routes': [], 'namespace': os.readlink('/proc/self/ns/net')}
    report['checks']['private_no_route'] = 'PASS'
    source_head = run(['git','-C',SOURCE,'rev-parse','HEAD']).stdout.strip()
    if not report['source_commit'] or source_head != report['source_commit']:
        raise RuntimeError('wrong or missing I_SOURCE_COMMIT')
    OUT.mkdir(parents=True,exist_ok=True)
    binary = SOURCE/'stratum/stratum'
    report['binary_sha256'] = hashlib.sha256(binary.read_bytes()).hexdigest()
    with tempfile.TemporaryDirectory(prefix='pool-l3-199i-', dir='/tmp') as temporary:
        root = Path(temporary).resolve()
        if root.parent != Path('/tmp'): raise RuntimeError('wrong disposable directory')
        stratum_env=os.environ.copy()
        stratum_env.pop('LD_PRELOAD',None)
        if os.environ.get('H_VARIANT')=='commit_ack':
            # Only the exact-header restart retry needs a stable coinbase time.
            clock_source=root/'fixed_time.c';clock_shim=root/'fixed_time.so'
            clock_source.write_text('#include <time.h>\ntime_t time(time_t *out) { time_t fixed=1700000000; if(out) *out=fixed; return fixed; }\n')
            run(['cc','-shared','-fPIC','-o',clock_shim,clock_source])
            stratum_env['LD_PRELOAD']=str(clock_shim)
            report['test_clock']={'source':str(clock_source),'sha256':hashlib.sha256(clock_shim.read_bytes()).hexdigest(),
                                  'fixed_unix_time':1700000000,'scope':'initial and commit-ack restart Stratum only'}
        else:
            report['test_clock']={'mode':'normal','scope':'all Stratum processes'}
        data = root/'db-data'; dbsock = root/'db.sock'; db_log = OUT/f'mariadb-{RUN_ID}.log'
        report['attempt']['fixture_root']=str(root)
        report['attempt']['mariadb_error_log_path']=str(db_log)
        run([BIN/'mariadb-install-db','--no-defaults',f'--datadir={data}',
             '--auth-root-authentication-method=normal','--skip-test-db','--user=root'])
        with db_log.open('w') as log:
            server = subprocess.Popen([str(BIN/'mariadbd'),'--no-defaults',f'--datadir={data}',
                f'--socket={dbsock}',f'--pid-file={root/"db.pid"}','--bind-address=127.0.0.1',
                f'--port={PORT_DB}','--user=root','--innodb-flush-log-at-trx-commit=1'],
                stdout=log,stderr=log)
        children.append(server)
        report['attempt']['mariadb_pid']=server.pid
        wait_file(dbsock,server)
        client = [BIN/'mariadb','--no-defaults',f'--socket={dbsock}','-uroot','--batch','--skip-column-names']
        def sql(s): return run(client,data=s,timeout=120).stdout.strip()
        identity = sql('SELECT VERSION(),@@hostname,@@datadir,@@socket,@@port,@@skip_networking;').split('\t')
        report['database'] = {'version':identity[0], 'server':identity[1], 'datadir':identity[2],
                              'socket':identity[3], 'port':identity[4], 'skip_networking':identity[5],
                              'database':'pool_l3_199h'}
        if not identity[0].startswith('10.11.') or Path(identity[2]).resolve()!=data or identity[3]!=str(dbsock) or identity[4]!=str(PORT_DB):
            raise RuntimeError('STOP: wrong MariaDB instance')
        sql('CREATE DATABASE pool_l3_199h;')
        legacy = gzip.open(SOURCE/'sql/2016-04-03-yaamp.sql.gz','rt').read()
        ddl = re.findall(r'(?ms)^(?:CREATE TABLE|ALTER TABLE)\s+.*?;',legacy)
        if len(ddl)<80: raise RuntimeError(f'legacy schema extraction too small: {len(ddl)}')
        sql('USE pool_l3_199h;\n'+'\n'.join(ddl))
        report['migrations'].append({'identity':'2016-04-03-yaamp.sql.gz schema-only', 'ddl_statements':len(ddl),
                                     'sha256':hashlib.sha256(legacy.encode()).hexdigest()})
        for name in ['2016-04-24-market_history.sql','2016-04-27-settings.sql','2016-05-11-coins.sql',
                     '2016-05-15-benchmarks.sql','2016-05-23-bookmarks.sql','2016-06-01-notifications.sql',
                     '2016-06-04-bench_chips.sql','2016-11-23-coins.sql','2017-02-05-benchmarks.sql',
                     '2017-03-31-earnings_index.sql','2017-05-accounts_case_swaptime.sql',
                     '2017-06-payouts_coinid_memo.sql','2017-09-notifications.sql','2017-10-bookmarks.sql',
                     '2017-11-segwit.sql','2018-01-stratums_ports.sql','2018-02-coins_getinfo.sql',
                     '2026-09-06-live-block-accounting.sql','2026-10-04-durable-share-rounds.sql',
                     '2026-10-16-durable-schema-guard.sql']:
            content=(SOURCE/'sql'/name).read_text()
            sql('USE pool_l3_199h;\n'+content)
            report['migrations'].append({'identity':name,'sha256':hashlib.sha256(content.encode()).hexdigest(),'result':'APPLIED'})
        sql("CREATE USER 'stratum_h'@'127.0.0.1' IDENTIFIED BY 'disposable-only';"
            "GRANT SELECT,INSERT,UPDATE,DELETE ON pool_l3_199h.* TO 'stratum_h'@'127.0.0.1';"
            "GRANT EXECUTE ON PROCEDURE pool_l3_199h.round_lock TO 'stratum_h'@'127.0.0.1';"
            "GRANT EXECUTE ON PROCEDURE pool_l3_199h.round_hold TO 'stratum_h'@'127.0.0.1';"
            "GRANT EXECUTE ON PROCEDURE pool_l3_199h.round_resolve TO 'stratum_h'@'127.0.0.1';"
            "GRANT EXECUTE ON PROCEDURE pool_l3_199h.round_schema_assert TO 'stratum_h'@'127.0.0.1';")
        report['grants']=sql("SHOW GRANTS FOR 'stratum_h'@'127.0.0.1';").splitlines()
        report['migration_definer']=sql("SELECT ROUTINE_NAME,DEFINER,SECURITY_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='pool_l3_199h' AND ROUTINE_NAME IN ('round_lock','round_hold','round_resolve','round_schema_assert') ORDER BY ROUTINE_NAME;").splitlines()
        report['migration_session_identity']=sql('SELECT CURRENT_USER();')
        if any(x in '\n'.join(report['grants']) for x in ['TRIGGER','ALTER','CREATE','DROP']):
            raise RuntimeError('runtime user unexpectedly has schema privileges')
        # Every address and credential below belongs to this namespace and synthetic fixture.
        sql("USE pool_l3_199h; INSERT INTO algos(name,profit,rent,factor,overflow) VALUES('scrypt',1,0,1,0);"
            "INSERT INTO coins(name,symbol,symbol2,algo,rpchost,rpcport,rpcuser,rpcpasswd,master_wallet,reward,price,"
            "hassubmitblock,enable,auto_ready,usememorypool,installed) VALUES"
            "('Synthetic BAD','BAD','BAD','scrypt','127.0.0.1',43318,'fake','fake',"
            "'1BadTest11111111111111111111111111',50,1,1,1,1,0,1);")
        proxy_server=None
        if os.environ.get('H_VARIANT')=='commit_ack':
            report['commit_proxy']={'listen':'127.0.0.1:43321','upstream':f'127.0.0.1:{PORT_DB}',
                'commit_query_seen':False,'server_ack_dropped':False}
            class Proxy(socketserver.BaseRequestHandler):
                def handle(self):
                    upstream=socket.create_connection(('127.0.0.1',PORT_DB),timeout=5)
                    upstream.settimeout(None);self.request.settimeout(None)
                    buffers={self.request:b'',upstream:b''};drop_response=False
                    try:
                        while True:
                            readable,_,_=select.select([self.request,upstream],[],[],60)
                            if not readable:return
                            for current in readable:
                                chunk=current.recv(65536)
                                if not chunk:return
                                buffers[current]+=chunk
                                while len(buffers[current])>=4:
                                    size=int.from_bytes(buffers[current][:3],'little')
                                    if len(buffers[current])<4+size:break
                                    packet=buffers[current][:4+size]
                                    buffers[current]=buffers[current][4+size:]
                                    payload=packet[4:]
                                    if current is self.request:
                                        if payload[:1]==b'\x03' and payload[1:].strip().upper()==b'COMMIT' and \
                                           not report['commit_proxy']['commit_query_seen']:
                                            report['commit_proxy']['commit_query_seen']=True
                                            upstream.sendall(packet);drop_response=True
                                        else:upstream.sendall(packet)
                                    elif drop_response:
                                        report['commit_proxy']['server_ack_type']=payload[:1].hex()
                                        report['commit_proxy']['server_ack_dropped']=True
                                        return
                                    else:self.request.sendall(packet)
                    finally:upstream.close()
            class ProxyServer(socketserver.ThreadingTCPServer):
                allow_reuse_address=True
                daemon_threads=True
            proxy_server=ProxyServer(('127.0.0.1',43321),Proxy)
            threading.Thread(target=proxy_server.serve_forever,daemon=True).start()
        class Rpc(http.server.BaseHTTPRequestHandler):
            protocol_version='HTTP/1.1'
            def log_message(self,*args): pass
            def do_POST(self):
                n=int(self.headers.get('Content-Length','0'))
                request=json.loads(self.rfile.read(n))
                method=request.get('method')
                report.setdefault('rpc_calls',[]).append(method)
                if method=='validateaddress': result={'isvalid':True,'ismine':True,'pubkey':'',
                    'scriptPubKey':'76a914000000000000000000000000000000000000000088ac'}
                elif method=='getblocktemplate': result={'version':2,'height':102,'curtime':1700000000,
                    'bits':'207fffff' if os.environ.get('H_VARIANT') in ('rpc_reject','rpc_timeout') else '1d00ffff',
                    'previousblockhash':'11'*32,'coinbasevalue':5000000000,
                    'transactions':[],'coinbaseaux':{'flags':'00'},'rules':[]}
                elif method=='submitblock':
                    variant=os.environ.get('H_VARIANT')
                    report.setdefault('rpc_evidence',[]).append({'request_id':request.get('id'),
                        'method':'submitblock','policy':'NO_BLOCK_ACCEPTANCE',
                        'reply':'synthetic-reject' if variant=='rpc_reject' else 'delayed-until-after-client-timeout'})
                    if variant=='rpc_timeout': time.sleep(31)
                    result='synthetic-reject' if variant=='rpc_reject' else 'synthetic-timeout'
                else: result=None
                response=json.dumps({'result':result,'error':None,'id':request.get('id')}).encode()
                try:
                    self.send_response(200);self.send_header('Content-Type','application/json')
                    self.send_header('Content-Length',str(len(response)));self.end_headers();self.wfile.write(response)
                except (BrokenPipeError,ConnectionResetError):pass
        httpd=http.server.ThreadingHTTPServer(('127.0.0.1',PORT_RPC),Rpc)
        thread=threading.Thread(target=httpd.serve_forever,daemon=True);thread.start()
        upstream_server=None
        upstream_fixture={'job':None,'ready':threading.Event()}
        if os.environ.get('H_VARIANT')=='reconnect_true':
            class Upstream(socketserver.BaseRequestHandler):
                def handle(self):
                    wire=self.request.makefile('rwb',buffering=0)
                    def send(value):
                        wire.write((json.dumps(value)+'\n').encode())
                        report.setdefault('upstream_responses',[]).append(value)
                    try:
                        while True:
                            raw=wire.readline()
                            if not raw:break
                            request=json.loads(raw)
                            report.setdefault('upstream_requests',[]).append(request)
                            method=request.get('method')
                            if method=='mining.subscribe':
                                send({'id':request['id'],'result':[[['mining.notify','upstream-session']],
                                     'aabbccdd',6],'error':None})
                            elif method=='mining.authorize':
                                send({'id':request['id'],'result':True,'error':None})
                            elif method=='mining.extranonce.subscribe':
                                send({'id':request['id'],'result':True,'error':None})
                                if not upstream_fixture['ready'].wait(25):break
                                send({'id':None,'method':'mining.set_difficulty','params':[1]})
                                send({'id':None,'method':'mining.notify','params':upstream_fixture['job']})
                            elif method=='mining.submit':
                                send({'id':request['id'],'result':True,'error':None})
                    except (BrokenPipeError,ConnectionResetError,OSError):
                        pass
                    finally:
                        wire.close()
            class UpstreamServer(socketserver.ThreadingTCPServer):
                allow_reuse_address=True
                daemon_threads=True
            upstream_server=UpstreamServer(('127.0.0.1',43322),Upstream)
            threading.Thread(target=upstream_server.serve_forever,daemon=True).start()
        cfg=root/'scrypt.conf'
        sql_port=43321 if os.environ.get('H_VARIANT')=='commit_ack' else PORT_DB
        renting=1 if os.environ.get('H_VARIANT')=='reconnect_true' else 0
        cfg.write_text(f'[TCP]\nserver = 127.0.0.1\nport = {PORT_STRATUM}\npassword = isolated-only\n'
            f'[SQL]\nhost = 127.0.0.1\nport = {sql_port}\ndatabase = pool_l3_199h\n'
            'username = stratum_h\npassword = disposable-only\n'
            '[STRATUM]\nalgo = scrypt\ndifficulty = 1\ndiff_min = 1\n'
            f'diff_max = 1\ndurable_rounds = 1\nrenting = {renting}\n[DEBUGLOG]\nhash = 1\n'
            f'client = {renting}\nremote = {renting}\n')
        logpath=OUT/f'stratum-{RUN_ID}.log'
        report['attempt']['stratum_log_path']=str(logpath)
        def enable_core():
            resource.setrlimit(resource.RLIMIT_CORE,(512*1024*1024,512*1024*1024))
        def core_trace():
            files=list(root.glob('core*'))
            if not files:return 'core file unavailable'
            core=max(files,key=lambda p:p.stat().st_mtime)
            return run(['gdb','-batch','-ex','set pagination off','-ex','thread apply all bt 12',
                        binary,core],check=False,timeout=20).stdout[-12000:]
        with logpath.open('w') as log:
            stratum=subprocess.Popen([str(binary),'scrypt'],cwd=root,stdout=log,stderr=log,preexec_fn=enable_core,env=stratum_env)
        children.append(stratum)
        report['attempt']['stratum_pid']=stratum.pid
        sock=None
        for _ in range(100):
            if stratum.poll() is not None: break
            try:
                sock=socket.create_connection(('127.0.0.1',PORT_STRATUM),timeout=.2);break
            except OSError: time.sleep(.2)
        report['stratum_exit']=stratum.poll()
        if not sock:
            report['startup_log']=logpath.read_text(errors='replace')
            report['startup_debug_log']=(root/'scrypt.log').read_text(errors='replace')[-5000:] if (root/'scrypt.log').exists() else ''
            report['mariadb_error_log']=db_log.read_text(errors='replace') if db_log.exists() else 'missing'
            guard_lines=[line for line in report['startup_log'].splitlines() if 'BADPOOL_ROUND_SCHEMA_REFUSED' in line]
            report['guard_failure_log_lines']=guard_lines
            if guard_lines:
                match=re.search(r'phase=(\S+) error=(\d+) sqlstate=(\S+) detail=(.*)',guard_lines[-1])
                if match:
                    report['guard_call_failure']={'phase':match[1],'mysql_errno':int(match[2]),
                                                  'sqlstate':match[3],'mysql_error':match[4]}
            report['attempt']['stratum_exit_before_cleanup']=stratum.poll()
            raise RuntimeError('isolated Stratum did not open its expected listener')
        if sock:
            report['checks']['listener_reached_inside_namespace']='PASS'
            report['listener_owners']=run(['ss','-ltnp']).stdout
            if os.environ.get('H_VARIANT') == 'guard_restore':
                outside=run(['nsenter','-t','1','-n','--','python3','-c',
                    f'import socket; s=socket.socket(); s.settimeout(1); print(s.connect_ex(("127.0.0.1",{PORT_STRATUM}))); s.close()'],
                    check=False)
                report['outside_namespace_probe']={'exit':outside.returncode,
                    'connect_ex':outside.stdout.strip(),'stderr':outside.stderr.strip(),
                    'host_netns':run(['readlink','/proc/1/ns/net']).stdout.strip()}
                if outside.returncode == 0 and outside.stdout.strip() != '0':
                    report['checks']['listener_unreachable_outside_netns']='PASS'
                else: raise RuntimeError('STOP: listener reachable outside private namespace or probe unavailable')
            sock.settimeout(4)
            stream=sock.makefile('rwb',buffering=0)
            for obj in [{'id':1,'method':'mining.subscribe','params':['l3-199h/1']},
                        {'id':2,'method':'mining.authorize','params':['test.rig1','x']}]:
                stream.write((json.dumps(obj)+'\n').encode())
                report.setdefault('miner_requests',[]).append(obj)
                time.sleep(.2)
            received=[]
            for _ in range(15):
                try: received.append(json.loads(stream.readline()))
                except (TimeoutError,OSError,json.JSONDecodeError): break
                if any(x.get('method')=='mining.notify' for x in received):break
            report['miner_responses']=received
            subscribed=next((x for x in received if x.get('id')==1),None)
            authorized=next((x for x in received if x.get('id')==2),None)
            notify=next((x for x in received if x.get('method')=='mining.notify'),None)
            if not subscribed or authorized.get('result') is not True or not notify:
                raise RuntimeError('miner authorization/job unavailable')
            report['checks']['synthetic_bad_scrypt_job']='PASS'
            if os.environ.get('H_VARIANT')=='rpc_timeout':sock.settimeout(45)
            report['pre_submit_identity']=sql('USE pool_l3_199h; SELECT id,username,coinid FROM accounts; SELECT id,userid,name,worker FROM workers; SELECT coin_id,algo,current_round,next_sequence FROM round_lanes;')
            nonce1=subscribed['result'][1]; nonce2='00000000'; job=notify['params']
            def swap_words(value):
                raw=bytes.fromhex(value)
                return b''.join(raw[i:i+4][::-1] for i in range(0,len(raw),4))
            coinbase=bytes.fromhex(job[2]+nonce1+nonce2+job[3])
            merkle=hashlib.sha256(hashlib.sha256(coinbase).digest()).digest()
            header_prefix=swap_words(job[5]+job[1]+swap_words(merkle.hex()).hex()+job[7]+job[6])
            # Source exchanges bytes within every 32-bit word; the suffix is the nonce.
            # A deterministic loop finds a non-candidate share below the assigned target.
            for candidate in range(10000):
                nonce=f'{candidate:08x}'
                header=header_prefix+swap_words(nonce)
                digest=hashlib.scrypt(header,salt=header,n=1024,r=1,p=1,dklen=32)
                target=int.from_bytes(digest[22:30],'little')
                if os.environ.get('H_VARIANT') in ('rpc_reject','rpc_timeout'):
                    suitable=int.from_bytes(digest,'little') <= (0x7fffff << (8*(32-3)))
                else:
                    suitable=0x0000ffff00000000 < target <= 0xffff000000000000
                if suitable:
                    break
            else: raise RuntimeError('no deterministic non-candidate Scrypt share found')
            report['mined_share']={'jobid':job[0],'extranonce1':nonce1,'extranonce2':nonce2,
                                   'ntime':job[7],'nonce':nonce,'hash_int':f'{target:016x}',
                                   'header_hex':header.hex(),'scrypt_digest':digest.hex()}
            def submit(case,params):
                request_id=len(report['miner_requests'])+1
                if os.environ.get('I_ID_STYLE')=='string':request_id=str(request_id)
                req={'id':request_id,'method':'mining.submit','params':params}
                report['miner_requests'].append(req)
                try:
                    stream.write((json.dumps(req)+'\n').encode())
                    for _ in range(10):
                        raw=stream.readline()
                        if not raw: raise RuntimeError('EOF')
                        response=json.loads(raw)
                        report['miner_responses'].append(response)
                        if response.get('id')==req['id']:
                            report.setdefault('share_cases',{})[case]=response
                            return response
                    raise RuntimeError(f'no miner response for {case}')
                except Exception as exc:
                    report['submit_failure']={'case':case,'error':str(exc),'stratum_poll':stratum.poll(),
                        'debug_log':(root/'scrypt.log').read_text(errors='replace')[-10000:],
                        'stratum_log':logpath.read_text(errors='replace')[-5000:],
                        'core_backtrace':core_trace() if stratum.poll()==-11 else None}
                    raise
            accepted=submit('valid',['test.rig1',job[0],nonce2,job[7],nonce])
            durable=sql('USE pool_l3_199h; SELECT id,coin_id,algo,round_id,sequence,userid,workerid,state,header_hex FROM accepted_work;')
            report['durable_after_valid']=durable
            if accepted.get('result') is True and not durable:
                raise RuntimeError('STOP: accepted work has no durable owner')
            if os.environ.get('H_VARIANT')=='commit_ack':
                report['commit_ack_resolution']={'miner_response':accepted,'durable_rows':durable.splitlines(),
                    'proxy':dict(report['commit_proxy'])}
                if not report['commit_proxy']['server_ack_dropped'] or report['commit_proxy'].get('server_ack_type')!='00' or \
                   accepted.get('result') is not False or len(durable.splitlines())!=1:
                    report['classification']='HOLD'
                    raise RuntimeError('STOP: uncertain COMMIT could not be resolved')
                report['checks']['dropped_commit_ack_resolved_by_server_and_row']='PASS'
                if stream.readline()!=b'' or stratum.poll() is not None:
                    report['classification']='HOLD'
                    raise RuntimeError('STOP: uncertain COMMIT did not close only the miner session')
                report['checks']['uncertain_commit_session_closed']='PASS'
            elif accepted.get('result') is not True or len(durable.splitlines())!=1:
                report['journal_failure_state']=sql('USE pool_l3_199h; SELECT id,username,coinid FROM accounts; SELECT id,userid,name FROM workers; SELECT * FROM round_lanes;')
                report['journal_failure_log']=(root/'scrypt.log').read_text(errors='replace')[-12000:]
                report['journal_failure_stratum_log']=logpath.read_text(errors='replace')[-12000:]
                report['journal_failure_client_log']=(root/'client.log').read_text(errors='replace')[-6000:]
                raise RuntimeError(f'valid Scrypt share not journaled: {accepted}')
            else:report['checks']['valid_share_durable_owner']='PASS'
            if os.environ.get('H_VARIANT')=='rpc_reject':
                intent=sql('USE pool_l3_199h; SELECT id,state,dispatch_state,daemon_outcome,accounting_outcome,work_id,round_id FROM round_intents;')
                report['rpc_reject_intent']=intent
                if 'submitblock' not in report.get('rpc_calls',[]) or not intent or 'REJECTED' not in intent or 'RESOLVED' not in intent:
                    report['classification']='HOLD'
                    raise RuntimeError('STOP: synthetic candidate rejection outcome unresolved')
                report['checks']['synthetic_candidate_rejection_resolved']='PASS'
            if os.environ.get('H_VARIANT')=='rpc_timeout':
                hold=sql('USE pool_l3_199h; SELECT id,coin_id,algo,round_id,blockhash,state,dispatch_state,daemon_outcome,accounting_outcome FROM round_intents;')
                report['rpc_timeout_hold']=hold
                if not hold or 'AMBIGUOUS_HOLD' not in hold or 'MAY_HAVE_DISPATCHED' not in hold:
                    report['classification']='HOLD';raise RuntimeError('STOP: timeout intent not durably held')
                parts=hold.split('\t')
                if len(parts)!=9 or parts[7]!='NULL' or parts[8]!='NULL':
                    report['classification']='HOLD';raise RuntimeError('STOP: timeout outcome not safely unresolved')
                backup=OUT/'timeout-held-backup.sql'
                dump=run(['mariadb-dump','--no-defaults','--protocol=socket','--skip-ssl',
                    f'--socket={dbsock}','-uroot','--single-transaction','--routines','--triggers',
                    '--databases','pool_l3_199h'],timeout=120)
                backup.write_text(dump.stdout)
                report['held_backup']={'path':str(backup),'sha256':hashlib.sha256(backup.read_bytes()).hexdigest()}
                evidence=f'fake RPC request {report["rpc_evidence"][-1]["request_id"]} policy NO_BLOCK_ACCEPTANCE; no chain or wallet exists in namespace'
                audit=("USE pool_l3_199h; START TRANSACTION; "
                    f"SELECT pending_intent FROM round_lanes WHERE coin_id={parts[1]} AND algo='scrypt' FOR UPDATE; "
                    f"SELECT id FROM round_intents WHERE id={parts[0]} AND coin_id={parts[1]} AND algo='scrypt' AND round_id={parts[3]} "
                    f"AND blockhash='{parts[4]}' AND state='AMBIGUOUS_HOLD' AND response_captured_at IS NULL AND daemon_outcome IS NULL FOR UPDATE; "
                    f"INSERT INTO round_resolution_audit(intent_id,actor,treatment,reason,evidence,prior_state,created_at) "
                    f"VALUES({parts[0]},'isolated-test-operator','REJECT','fake RPC intentionally withheld response','{evidence}','AMBIGUOUS_HOLD',UTC_TIMESTAMP(6)); "
                    f"UPDATE round_intents SET accounting_outcome='REJECTED',resolution_source='OPERATOR_ADJUDICATED_REJECT',resolution_evidence='{evidence}' WHERE id={parts[0]}; "
                    f"CALL round_resolve({parts[0]}); COMMIT;")
                try:sql(audit)
                except Exception:
                    report['classification']='HOLD';raise
                report['rpc_timeout_resolved']=sql('USE pool_l3_199h; SELECT id,state,accounting_outcome,resolution_source FROM round_intents; SELECT id,round_id,original_round_id,state FROM accepted_work; SELECT COUNT(*) FROM round_resolution_audit;')
                if 'RESOLVED' not in report['rpc_timeout_resolved'] or 'OPERATOR_ADJUDICATED_REJECT' not in report['rpc_timeout_resolved']:
                    report['classification']='HOLD';raise RuntimeError('STOP: timeout adjudication did not resolve')
                report['checks']['timeout_held_then_audited_reject']='PASS'
            variant=os.environ.get('H_VARIANT')
            if variant in ('rpc_reject','rpc_timeout','commit_ack'):
                stream.close();sock.close()
            elif variant == 'db_down':
                for next_candidate in range(candidate+1,candidate+10000):
                    next_nonce=f'{next_candidate:08x}'
                    next_header=header_prefix+swap_words(next_nonce)
                    next_digest=hashlib.scrypt(next_header,salt=next_header,n=1024,r=1,p=1,dklen=32)
                    next_target=int.from_bytes(next_digest[22:30],'little')
                    if 0x0000ffff00000000 < next_target <= 0xffff000000000000:break
                else:raise RuntimeError('no second non-candidate share')
                server.terminate();server.wait(timeout=10)
                report['db_outage']={'server_exit':server.returncode,'nonce':next_nonce,
                    'socket_gone':not dbsock.exists()}
                if dbsock.exists():raise RuntimeError('database did not stop for outage injection')
                submit('db_unavailable',['test.rig1',job[0],nonce2,job[7],next_nonce])
                report['db_outage']['miner_response']=report['share_cases']['db_unavailable']
                with db_log.open('a') as log:
                    recovered_db=subprocess.Popen([str(BIN/'mariadbd'),'--no-defaults',f'--datadir={data}',
                        f'--socket={dbsock}',f'--pid-file={root/"db-recovered.pid"}',
                        '--bind-address=127.0.0.1',f'--port={PORT_DB}','--user=root',
                        '--innodb-flush-log-at-trx-commit=1'],stdout=log,stderr=log)
                children.append(recovered_db);wait_file(dbsock,recovered_db)
                report['db_outage']['durable_after_restart']=sql('USE pool_l3_199h; SELECT COUNT(*) FROM accepted_work;')
                if report['db_outage']['miner_response'].get('result') is True and report['db_outage']['durable_after_restart']=='1':
                    raise RuntimeError('STOP: database outage share accepted without durable row')
                if report['db_outage']['miner_response'].get('result') is not False or report['db_outage']['durable_after_restart']!='1':
                    raise RuntimeError('database outage outcome not resolved')
                report['checks']['database_outage_rejected_and_owner_preserved']='PASS'
                if stream.readline()!=b'' or stratum.poll() is not None:
                    report['classification']='FAIL'
                    raise RuntimeError('STOP: database outage did not close only the miner session')
                report['checks']['database_outage_session_closed']='PASS'
                stream.close();sock.close()
            elif variant == 'guard_startup':
                stream.close();sock.close()
            elif variant == 'reconnect_true':
                trace=report['reconnect_true']={'old_notify_id':subscribed['result'][0][1][1],
                    'old_worker_id':int(durable.split('\t')[6]),'original_owner':durable}
                before_workers=sql('USE pool_l3_199h; SELECT id,userid,name,worker FROM workers ORDER BY id;')
                upstream_job=list(job);upstream_job[0]='upstream1'
                upstream_job[7]=f'{int(time.time()):08x}'
                upstream_fixture['job']=upstream_job
                sql("USE pool_l3_199h; INSERT INTO jobs(id,ready,active,time,price,speed,difficulty,algo,host,port,username,password) "
                    "VALUES(199,1,1,UNIX_TIMESTAMP(),1000000000000,1000000000000000000,1,'scrypt','127.0.0.1',43322,'synthetic-upstream','fake');")
                upstream_fixture['ready'].set()
                trace['remote_job_fixture']=upstream_job
                trace['remote_row']=sql('USE pool_l3_199h; SELECT id,ready,active,algo,host,port FROM jobs WHERE id=199;')
                sock.settimeout(None)
                deadline=time.monotonic()+38
                while time.monotonic()<deadline:
                    if not select.select([sock],[],[],max(0,deadline-time.monotonic()))[0]:break
                    raw=stream.readline()
                    if not raw:break
                    event=json.loads(raw);report['miner_responses'].append(event)
                    if event.get('method')=='client.reconnect':
                        trace['reconnect_message']=event
                        break
                if 'reconnect_message' not in trace:
                    trace['debug_log']=(root/'scrypt.log').read_text(errors='replace')[-8000:]
                    try: trace['stratum_exit_before_cleanup']=stratum.wait(timeout=5)
                    except subprocess.TimeoutExpired: trace['stratum_exit_before_cleanup']=None
                    if trace['stratum_exit_before_cleanup'] is not None:
                        report['classification']='FAIL'
                        trace['core_backtrace']=core_trace()
                    raise RuntimeError('fixture did not reach actual client.reconnect path')
                report['checks']['actual_reconnecting_true_triggered']='PASS'
                resume_sock=socket.create_connection(('127.0.0.1',PORT_STRATUM),timeout=4)
                resume_sock.settimeout(5);resume=resume_sock.makefile('rwb',buffering=0)
                def send_resume(method,params):
                    request={'id':len(report['miner_requests'])+1,'method':method,'params':params}
                    report['miner_requests'].append(request)
                    resume.write((json.dumps(request)+'\n').encode())
                    for _ in range(16):
                        raw=resume.readline()
                        if not raw:raise RuntimeError('resumed connection closed before reply')
                        value=json.loads(raw);report['miner_responses'].append(value)
                        if value.get('id')==request['id']:return value
                    raise RuntimeError(f'no reply to {method} on resumed session')
                resumed=send_resume('mining.subscribe',['l3-r2-actual-reconnect',trace['old_notify_id']])
                trace['subscribe_response']=resumed
                if resumed.get('result',[None,None])[1]!='aabbccdd00':
                    raise RuntimeError('reconnect did not inherit upstream extranonce')
                inherited_log=(root/'scrypt.log').read_text(errors='replace')
                trace['true_branch_log']=[line for line in inherited_log.splitlines() if 'reconnecting client locked' in line]
                if not trace['true_branch_log']:
                    raise RuntimeError('true reconnect identity-inheritance branch not observed')
                before_auth_wrong=send_resume('mining.submit',['test.other',job[0],nonce2,job[7],nonce])
                trace['wrong_claim_before_reauthorization']=before_auth_wrong
                if before_auth_wrong.get('result') is not False or before_auth_wrong.get('error',[None])[0]!=24:
                    raise RuntimeError('inherited identity did not reject conflicting submit before authorize')
                if sql('USE pool_l3_199h; SELECT id,userid,name,worker FROM workers ORDER BY id;')!=before_workers or \
                   sql('USE pool_l3_199h; SELECT id,coin_id,algo,round_id,sequence,userid,workerid,state,header_hex FROM accepted_work;')!=durable:
                    raise RuntimeError('conflicting inherited identity changed worker or owner')
                report['checks']['inherited_worker_rejects_claim_before_authorize']='PASS'
                same_auth=send_resume('mining.authorize',['test.rig1','x'])
                trace['same_worker_authorization']=same_auth
                if same_auth.get('result') is not True or \
                   sql('USE pool_l3_199h; SELECT id,userid,name,worker FROM workers ORDER BY id;')!=before_workers:
                    raise RuntimeError('same worker did not authorize with inherited worker row')
                report['checks']['inherited_same_worker_authorizes']='PASS'
                remote_notice=None
                for _ in range(12):
                    raw=resume.readline()
                    if not raw:break
                    event=json.loads(raw);report['miner_responses'].append(event)
                    if event.get('method')=='mining.notify':
                        remote_notice=event['params'];break
                if not remote_notice:raise RuntimeError('resumed session received no remote job')
                trace['remote_job_received']=remote_notice
                remote_nonce1=resumed['result'][1]
                remote_nonce2='00'*resumed['result'][2]
                remote_coinbase=bytes.fromhex(remote_notice[2]+remote_nonce1+remote_nonce2+remote_notice[3])
                remote_merkle=hashlib.sha256(hashlib.sha256(remote_coinbase).digest()).digest()
                remote_prefix=swap_words(remote_notice[5]+remote_notice[1]+swap_words(remote_merkle.hex()).hex()+remote_notice[7]+remote_notice[6])
                for remote_candidate in range(10000):
                    remote_nonce=f'{remote_candidate:08x}'
                    remote_header=remote_prefix+swap_words(remote_nonce)
                    remote_digest=hashlib.scrypt(remote_header,salt=remote_header,n=1024,r=1,p=1,dklen=32)
                    remote_target=int.from_bytes(remote_digest[22:30],'little')
                    if 0x0000ffff00000000 < remote_target <= 0xffff000000000000:break
                else:raise RuntimeError('no valid non-candidate remote Scrypt share')
                normal_submit=send_resume('mining.submit',['test.rig1',remote_notice[0],remote_nonce2,remote_notice[7],remote_nonce])
                trace['same_worker_submit']=normal_submit
                if normal_submit.get('result') is not True:
                    raise RuntimeError('inherited same worker could not submit normally')
                report['checks']['inherited_same_worker_submits']='PASS'
                time.sleep(2)
                def accounting_rows():
                    return {'owner':sql('USE pool_l3_199h; SELECT id,coin_id,algo,round_id,sequence,userid,workerid,state,header_hex FROM accepted_work;'),
                        'workers':sql('USE pool_l3_199h; SELECT id,userid,name,worker FROM workers ORDER BY id;'),
                        'counts':sql(f'USE pool_l3_199h; SELECT (SELECT COUNT(*) FROM shares),(SELECT COUNT(*) FROM earnings),(SELECT COUNT(*) FROM round_intents),'
                            f'(SELECT COUNT(*) FROM shares WHERE workerid<>{trace["old_worker_id"]});')}
                accepted_baseline=accounting_rows()
                trace['after_valid_accounting']=accepted_baseline
                if accepted_baseline['owner']!=durable or accepted_baseline['workers']!=before_workers or \
                   accepted_baseline['counts'].split('\t')[-1]!='0':
                    raise RuntimeError('remote valid share changed BAD durable owner or worker identity')
                wrong=send_resume('mining.submit',['test.other',remote_notice[0],remote_nonce2,remote_notice[7],remote_nonce])
                trace['wrong_claim_response']=wrong
                trace['after_wrong_claim_accounting']=accounting_rows()
                if wrong.get('result') is not False or wrong.get('error',[None])[0]!=24 or \
                   trace['after_wrong_claim_accounting']!=accepted_baseline:
                    raise RuntimeError('conflicting submit changed inherited identity or accounting')
                report['checks']['reconnected_wrong_worker_no_accounting']='PASS'
                conflict=send_resume('mining.authorize',['test.other','x'])
                trace['conflicting_reauthorization']=conflict
                trace['after_conflicting_authorization_accounting']=accounting_rows()
                if conflict.get('result') is not False or conflict.get('error',[None])[0]!=21 or \
                   trace['after_conflicting_authorization_accounting']!=accepted_baseline:
                    raise RuntimeError('conflicting reauthorization changed inherited identity or accounting')
                report['checks']['reconnected_reauthorization_no_accounting']='PASS'
                resume.close();resume_sock.close();stream.close();sock.close()
            elif variant == 'auth_contract':
                report['authorization_boundary']={}
                baseline={
                    'owner':durable,
                    'counts':sql('USE pool_l3_199h; SELECT (SELECT COUNT(*) FROM accepted_work),(SELECT COUNT(*) FROM shares),(SELECT COUNT(*) FROM earnings),(SELECT COUNT(*) FROM round_intents);')}
                def receive_id(connection, request_id):
                    for _ in range(12):
                        raw=connection.readline()
                        if not raw:raise RuntimeError(f'authorization session closed before response {request_id}')
                        value=json.loads(raw)
                        report['miner_responses'].append(value)
                        if value.get('id')==request_id:return value
                    raise RuntimeError(f'authorization response {request_id} missing')
                def send_on(connection, method, params):
                    request_id=len(report['miner_requests'])+1
                    request={'id':request_id,'method':method,'params':params}
                    report['miner_requests'].append(request)
                    connection.write((json.dumps(request)+'\n').encode())
                    return receive_id(connection,request_id)
                def unchanged(label):
                    actual={
                        'owner':sql('USE pool_l3_199h; SELECT id,coin_id,algo,round_id,sequence,userid,workerid,state,header_hex FROM accepted_work;'),
                        'counts':sql('USE pool_l3_199h; SELECT (SELECT COUNT(*) FROM accepted_work),(SELECT COUNT(*) FROM shares),(SELECT COUNT(*) FROM earnings),(SELECT COUNT(*) FROM round_intents);'),
                        'claimed_worker_rows':sql("USE pool_l3_199h; SELECT COUNT(*) FROM workers WHERE worker='other';")}
                    report.setdefault('authorization_boundary',{})[label]=actual
                    if actual['owner']!=baseline['owner'] or actual['counts']!=baseline['counts'] or actual['claimed_worker_rows']!='0':
                        raise RuntimeError(f'STOP: {label} changed authenticated accounting identity')
                pre_sock=socket.create_connection(('127.0.0.1',PORT_STRATUM),timeout=4)
                pre_sock.settimeout(4);pre=pre_sock.makefile('rwb',buffering=0)
                send_on(pre,'mining.subscribe',['l3-r1-preauth'])
                pre_submit=send_on(pre,'mining.submit',['test.other',job[0],nonce2,job[7],nonce])
                report['authorization_boundary']['pre_authorization_response']=pre_submit
                if pre_submit.get('result') is not False or pre_submit.get('error',[None])[0]!=24:
                    raise RuntimeError('submit before authorization was not rejected')
                unchanged('before_authorization')
                report['checks']['submit_before_authorization_no_accounting']='PASS'
                pre.close();pre_sock.close()
                notify_id=subscribed['result'][0][1][1]
                resume_sock=socket.create_connection(('127.0.0.1',PORT_STRATUM),timeout=4)
                resume_sock.settimeout(4);resume=resume_sock.makefile('rwb',buffering=0)
                resumed=send_on(resume,'mining.subscribe',['l3-r1-resume',notify_id])
                reauth=send_on(resume,'mining.authorize',['test.rig1','x'])
                report['authorization_boundary']['resumed_subscription']={'notify_id':notify_id,'response':resumed,'authorization':reauth}
                if reauth.get('result') is not True:
                    raise RuntimeError('restored subscription did not authorize the original worker')
                mismatch=send_on(resume,'mining.submit',['test.other',job[0],nonce2,job[7],nonce])
                if mismatch.get('result') is not False or mismatch.get('error',[None])[0]!=24:
                    raise RuntimeError('restored subscription accepted conflicting submit identity')
                unchanged('resumed_session_conflicting_submit')
                report['checks']['resumed_subscription_submit_worker_binding']='PASS'
                conflict=send_on(resume,'mining.authorize',['test.other','x'])
                report['authorization_boundary']['resumed_conflicting_authorization']=conflict
                if conflict.get('result') is not False or conflict.get('error',[None])[0]!=21:
                    raise RuntimeError('conflicting authorization on resumed connection was not rejected')
                unchanged('resumed_session_conflicting_authorization')
                report['checks']['conflicting_authorization_no_accounting']='PASS'
                resume.close();resume_sock.close()
                original_conflict=send_on(stream,'mining.authorize',['test.other','x'])
                report['authorization_boundary']['original_conflicting_authorization']=original_conflict
                if original_conflict.get('result') is not False or original_conflict.get('error',[None])[0]!=21:
                    raise RuntimeError('conflicting authorization on original connection was not rejected')
                unchanged('original_session_conflicting_authorization')
                stream.close();sock.close()
            elif variant == 'one_case':
                case=os.environ.get('H_CASE')
                if case=='malformed_nonce': params=['test.rig1',job[0],nonce2,job[7],'xx']
                elif case=='stale_job': params=['test.rig1','ffffffff',nonce2,job[7],nonce]
                elif case=='wrong_worker':
                    for wrong_candidate in range(candidate+1,candidate+10000):
                        wrong_nonce=f'{wrong_candidate:08x}'
                        wrong_header=header_prefix+swap_words(wrong_nonce)
                        wrong_digest=hashlib.scrypt(wrong_header,salt=wrong_header,n=1024,r=1,p=1,dklen=32)
                        wrong_target=int.from_bytes(wrong_digest[22:30],'little')
                        if 0x0000ffff00000000 < wrong_target <= 0xffff000000000000:break
                    else:raise RuntimeError('no wrong-worker Scrypt nonce found')
                    report['wrong_worker_hash_int']=f'{wrong_target:016x}'
                    params=['test.other',job[0],nonce2,job[7],wrong_nonce]
                else:raise RuntimeError(f'unknown H_CASE={case}')
                submit(case,params)
                report['durable_after_cases']=sql('USE pool_l3_199h; SELECT id,userid,workerid,state,sequence FROM accepted_work ORDER BY id;')
                if report['share_cases'][case].get('result') is not False or len(report['durable_after_cases'].splitlines())!=1:
                    report['classification']='FAIL'
                    raise RuntimeError(f'STOP: {case} accepted or changed durable ownership')
                report['checks'][f'{case}_rejected_without_durable_owner']='PASS'
                if case=='wrong_worker':
                    authorized_worker_id=int(durable.split('\t')[6])
                    report['authorized_worker_id']=authorized_worker_id
                    report['wrong_worker_boundary']=sql(f"USE pool_l3_199h; "
                        f"SELECT COUNT(*) FROM workers WHERE id<>{authorized_worker_id}; "
                        f"SELECT COUNT(*) FROM accepted_work WHERE workerid<>{authorized_worker_id}; "
                        f"SELECT COUNT(*) FROM shares WHERE workerid<>{authorized_worker_id}; "
                        "SELECT COUNT(*) FROM earnings; SELECT COUNT(*) FROM round_intents;")
                    if report['wrong_worker_boundary'].splitlines()!=['0']*5:
                        report['classification']='FAIL'
                        raise RuntimeError('STOP: wrong worker created an accounting record')
                    report['checks']['wrong_worker_no_claimed_accounting']='PASS'
                for follow_candidate in range(candidate+10000,candidate+20000):
                    follow_nonce=f'{follow_candidate:08x}'
                    follow_header=header_prefix+swap_words(follow_nonce)
                    follow_digest=hashlib.scrypt(follow_header,salt=follow_header,n=1024,r=1,p=1,dklen=32)
                    follow_target=int.from_bytes(follow_digest[22:30],'little')
                    if 0x0000ffff00000000 < follow_target <= 0xffff000000000000:break
                else:raise RuntimeError('no follow-up valid Scrypt share')
                follow=submit('valid_after_reject',['test.rig1',job[0],nonce2,job[7],follow_nonce])
                report['durable_after_followup']=sql('USE pool_l3_199h; SELECT id,userid,workerid,state,sequence FROM accepted_work ORDER BY id;')
                if follow.get('result') is not True or len(report['durable_after_followup'].splitlines())!=2:
                    report['classification']='FAIL'
                    raise RuntimeError(f'STOP: {case} left the process unable to journal a valid share')
                report['checks'][f'{case}_then_valid_share']='PASS'
                stream.close();sock.close()
            elif variant != 'guard_restore':
                duplicate=submit('duplicate',['test.rig1',job[0],nonce2,job[7],nonce])
                report['duplicate_owner']=sql('USE pool_l3_199h; SELECT id,userid,workerid,round_id,sequence FROM accepted_work;')
                if duplicate.get('result') is not False or duplicate.get('error',[None])[0]!=22 or \
                   len(report['duplicate_owner'].splitlines())!=1:
                    report['classification']='FAIL'
                    raise RuntimeError('STOP: duplicate share accepted or created a second owner')
                report['checks']['duplicate_rejected_single_owner']='PASS'
                for follow_candidate in range(candidate+10000,candidate+20000):
                    follow_nonce=f'{follow_candidate:08x}'
                    follow_header=header_prefix+swap_words(follow_nonce)
                    follow_digest=hashlib.scrypt(follow_header,salt=follow_header,n=1024,r=1,p=1,dklen=32)
                    follow_target=int.from_bytes(follow_digest[22:30],'little')
                    if 0x0000ffff00000000 < follow_target <= 0xffff000000000000:break
                else:raise RuntimeError('no follow-up valid Scrypt share')
                follow=submit('valid_after_duplicate',['test.rig1',job[0],nonce2,job[7],follow_nonce])
                report['duplicate_followup_owner']=sql('USE pool_l3_199h; SELECT id,userid,workerid,round_id,sequence FROM accepted_work ORDER BY id;')
                if follow.get('result') is not True or len(report['duplicate_followup_owner'].splitlines())!=2:
                    report['classification']='FAIL'
                    raise RuntimeError('STOP: duplicate rejection left process unable to journal a valid share')
                report['checks']['duplicate_then_valid_share']='PASS'
                stream.close()
                sock.close()
            def new_miner(tag):
                new_sock=socket.create_connection(('127.0.0.1',PORT_STRATUM),timeout=4)
                new_sock.settimeout(4)
                new_stream=new_sock.makefile('rwb',buffering=0)
                for method,params in [('mining.subscribe',[f'l3-199h/{tag}']),
                                      ('mining.authorize',[f'test.{tag}','x'])]:
                    req={'id':len(report['miner_requests'])+1,'method':method,'params':params}
                    report['miner_requests'].append(req)
                    new_stream.write((json.dumps(req)+'\n').encode())
                    time.sleep(.2)
                replies=[]
                for _ in range(10):
                    raw=new_stream.readline()
                    if not raw:
                        report['new_miner_failure']={'tag':tag,'stratum_poll':stratum.poll(),
                            'debug_log':(root/'scrypt.log').read_text(errors='replace')[-7000:],
                            'stratum_log':logpath.read_text(errors='replace')[-4000:],
                            'core_backtrace':core_trace() if stratum.poll()==-11 else None}
                        raise RuntimeError(f'new miner {tag} disconnected')
                    reply=json.loads(raw)
                    report['miner_responses'].append(reply);replies.append(reply)
                    if reply.get('method')=='mining.notify':break
                if not any(x.get('method')=='mining.notify' for x in replies):
                    raise RuntimeError(f'no job for {tag}')
                return new_sock,new_stream,next(x['params'] for x in replies if x.get('method')=='mining.notify')
            if variant not in ('guard_restore','guard_startup','reconnect_true','one_case','db_down','rpc_reject','rpc_timeout','commit_ack','auth_contract'):
                for case,tag in [('malformed_nonce','rig2'),('stale_job','rig3'),('wrong_worker','rig4')]:
                    new_sock,stream,new_job=new_miner(tag)
                    if case=='malformed_nonce': params=[f'test.{tag}',new_job[0],nonce2,new_job[7],'xx']
                    elif case=='stale_job': params=[f'test.{tag}','ffffffff',nonce2,new_job[7],nonce]
                    else: params=['test.other',new_job[0],nonce2,new_job[7],'00000001']
                    try: submit(case,params)
                    finally: stream.close(); new_sock.close()
                report['durable_after_cases']=sql('USE pool_l3_199h; SELECT id,userid,workerid,state,sequence FROM accepted_work ORDER BY id;')
            elif variant == 'guard_restore':
                stream.close();sock.close()
            elif variant == 'auth_contract':
                healthy_sock,healthy_stream,_=new_miner('rig1')
                healthy_stream.close();healthy_sock.close()
                report['checks']['authorization_rejections_process_stays_available']='PASS'
        else: report['checks']['listener_reached_inside_namespace']='FAIL'
        if os.environ.get('H_VARIANT') != 'db_down':
            report['tables']=sql("SELECT table_name,engine FROM information_schema.tables WHERE table_schema='pool_l3_199h' AND table_name IN ('accepted_work','share_rounds','round_schema_version','shares','coins') ORDER BY table_name;").splitlines()
            report['counts']=sql('USE pool_l3_199h; SELECT (SELECT COUNT(*) FROM accepted_work),(SELECT COUNT(*) FROM share_rounds),(SELECT COUNT(*) FROM shares);')
        report['stratum_log_tail']=logpath.read_text(errors='replace')[-8000:]
        report['debug_log_tail']=(root/'scrypt.log').read_text(errors='replace')[-8000:]
        report['client_log_tail']=(root/'client.log').read_text(errors='replace')[-4000:]
        report['mariadb_log_tail']=db_log.read_text(errors='replace')[-1000:]
        report['stratum_pre_shutdown_exit']=stratum.poll()
        if stratum.poll() is None:
            stratum.send_signal(signal.SIGTERM)
            try: stratum.wait(timeout=10)
            except subprocess.TimeoutExpired: stratum.kill();stratum.wait()
        report['stratum_final_exit']=stratum.returncode
        if os.environ.get('H_VARIANT')=='one_case' and stratum.returncode==0:
            report['post_shutdown_counts']=sql('USE pool_l3_199h; SELECT (SELECT COUNT(*) FROM accepted_work),(SELECT COUNT(*) FROM share_rounds),(SELECT COUNT(*) FROM shares);')
            report['post_shutdown_shares']=sql('USE pool_l3_199h; SELECT userid,workerid,valid,difficulty,error,round_id FROM shares;')
        if stratum.returncode == -11:
            report['classification']='FAIL'
            report['core_backtrace']=core_trace()
        if os.environ.get('H_VARIANT')=='commit_ack':
            cfg.write_text(cfg.read_text().replace('port = 43321','port = 43317'))
            restart_log=root/'commit-restart.log'
            with restart_log.open('w') as log:
                restart=subprocess.Popen([str(binary),'scrypt'],cwd=root,stdout=log,stderr=log,env=stratum_env)
            children.append(restart)
            restart_listener=False
            for _ in range(70):
                if restart.poll() is not None:break
                probe=socket.socket();probe.settimeout(.1)
                restart_listener=probe.connect_ex(('127.0.0.1',PORT_STRATUM))==0
                probe.close()
                if restart_listener:break
                time.sleep(.1)
            report['commit_restart']={'listener':restart_listener,'exit_before_shutdown':restart.poll(),
                'durable_count':sql('USE pool_l3_199h; SELECT COUNT(*) FROM accepted_work;'),
                'submitblock_calls':report.get('rpc_calls',[]).count('submitblock')}
            if not restart_listener or report['commit_restart']['durable_count']!='1' or report['commit_restart']['submitblock_calls']!=0:
                raise RuntimeError('STOP: commit-ack restart replay or owner mismatch')
            report['checks']['commit_ack_restart_no_silent_replay']='PASS'
            retry_subscribe_id=len(report['miner_requests'])+1
            retry_sock,retry_stream,retry_job=new_miner('rig1')
            retry_subscribed=next(x for x in report['miner_responses'] if x.get('id')==retry_subscribe_id)
            retry_nonce1=retry_subscribed['result'][1]
            retry_coinbase=bytes.fromhex(retry_job[2]+retry_nonce1+nonce2+retry_job[3])
            retry_merkle=hashlib.sha256(hashlib.sha256(retry_coinbase).digest()).digest()
            retry_header_prefix=swap_words(retry_job[5]+retry_job[1]+swap_words(retry_merkle.hex()).hex()+retry_job[7]+retry_job[6])
            retry_header=retry_header_prefix+swap_words(nonce)
            report['commit_restart']['retry_identity']={'old_header':header.hex(),'retry_header':retry_header.hex(),
                'old_extranonce1':nonce1,'retry_extranonce1':retry_nonce1}
            if retry_header!=header:
                report['classification']='HOLD'
                raise RuntimeError('STOP: restart retry did not reproduce the same work identity')
            retry_req={'id':len(report['miner_requests'])+1,'method':'mining.submit',
                'params':['test.rig1',retry_job[0],nonce2,retry_job[7],nonce]}
            report['miner_requests'].append(retry_req)
            retry_stream.write((json.dumps(retry_req)+'\n').encode())
            for _ in range(10):
                retry_reply=json.loads(retry_stream.readline())
                report['miner_responses'].append(retry_reply)
                if retry_reply.get('id')==retry_req['id']:break
            else:raise RuntimeError('no restart retry response')
            report['commit_restart']['retry_response']=retry_reply
            report['commit_restart']['owner_after_retry']=sql('USE pool_l3_199h; SELECT id,coin_id,algo,round_id,sequence,userid,workerid,state,header_hex FROM accepted_work;')
            report['commit_restart']['candidate_count_after_retry']=sql('USE pool_l3_199h; SELECT COUNT(*) FROM round_intents;')
            if retry_reply.get('result') is not False or retry_reply.get('error',[None])[0] not in (22,28) or \
               report['commit_restart']['owner_after_retry']!=durable or \
               report['commit_restart']['candidate_count_after_retry']!='0' or \
               report.get('rpc_calls',[]).count('submitblock')!=0:
                report['classification']='HOLD'
                raise RuntimeError('STOP: restart retry created a second owner or candidate')
            report['checks']['uncertain_commit_restart_retry_single_owner']='PASS'
            retry_stream.close();retry_sock.close()
            restart.send_signal(signal.SIGTERM);restart.wait(timeout=10)
            report['commit_restart']['exit_after_shutdown']=restart.returncode
            report['commit_restart']['log']=restart_log.read_text(errors='replace')[-3000:]
            require_exit(restart,0,'commit-ack restart')
            report['checks']['commit_ack_restart_expected_exit']='PASS'
        if os.environ.get('H_VARIANT') == 'guard_restore':
            if stratum.returncode != 0:
                raise RuntimeError(f'isolated Stratum failed graceful exit {stratum.returncode}')
            report['post_shutdown_counts']=sql('USE pool_l3_199h; SELECT (SELECT COUNT(*) FROM accepted_work),(SELECT COUNT(*) FROM share_rounds),(SELECT COUNT(*) FROM shares);')
            expected_records={
                'owner':durable,
                'rounds':sql('USE pool_l3_199h; SELECT * FROM share_rounds ORDER BY id;'),
                'shares':sql('USE pool_l3_199h; SELECT * FROM shares ORDER BY id;')}
            report['pre_backup_records']=expected_records
            if not all(expected_records.values()):
                raise RuntimeError('missing durable owner, round, or persisted share before backup')
            app_client=[BIN/'mariadb','--no-defaults','--protocol=tcp','--host=127.0.0.1',
                        f'--port={PORT_DB}','-ustratum_h','--password=disposable-only',
                        '--batch','--skip-column-names','pool_l3_199h']
            report['restricted_guard_result']=run(app_client,data='CALL round_schema_assert();').stdout.strip()
            if report['restricted_guard_result'] != '12e0d439aa5a29f1256e2de1c894f77459ca7b3ba675e776c5578cf4ef659034':
                raise RuntimeError('complete schema guard refused restricted user')
            report['checks']['complete_guard_restricted_stratum']='PASS'
            mutation=os.environ.get('H_GUARD_MUTATION','missing_trigger')
            report['guard_mutation']=mutation
            backup=OUT/f'backup-{RUN_ID}-{mutation}.sql'
            dump=run(['mariadb-dump','--no-defaults','--protocol=socket','--skip-ssl',f'--socket={dbsock}','-uroot',
                '--single-transaction','--routines','--triggers','--events','--databases','pool_l3_199h'],timeout=120)
            backup.write_text(dump.stdout)
            report['backup']={'path':str(backup),'sha256':hashlib.sha256(backup.read_bytes()).hexdigest(),
                              'size':backup.stat().st_size}
            if mutation=='missing_trigger':sql('USE pool_l3_199h; DROP TRIGGER round_work_guard;')
            elif mutation=='wrong_engine':sql('USE pool_l3_199h; ALTER TABLE shares ENGINE=MyISAM;')
            elif mutation=='wrong_version':sql('USE pool_l3_199h; UPDATE round_schema_version SET version=3;')
            else:raise RuntimeError(f'unknown H_GUARD_MUTATION={mutation}')
            bad_log=root/'guard-refusal.log'
            with bad_log.open('w') as log:
                bad_start=subprocess.Popen([str(binary),'scrypt'],cwd=root,stdout=log,stderr=log)
            children.append(bad_start)
            try: bad_start.wait(timeout=8)
            except subprocess.TimeoutExpired:
                bad_start.terminate();bad_start.wait(timeout=4)
            report['missing_trigger_startup']={'exit':bad_start.returncode,
                'log':bad_log.read_text(errors='replace')[-4000:],
                'stratum_log':logpath.read_text(errors='replace')[-4000:]}
            probe=socket.socket();probe.settimeout(.2)
            connected=probe.connect_ex(('127.0.0.1',PORT_STRATUM))==0;probe.close()
            report['missing_trigger_startup']['listener_open']=connected
            if bad_start.returncode != 1 or connected:
                raise RuntimeError(f'STOP: {mutation} did not fail closed')
            report['checks'][f'{mutation}_real_startup_refusal']='PASS'
            data2=root/'restore-data';socket2=root/'restore.sock';log2=root/'restore-server.log'
            run([BIN/'mariadb-install-db','--no-defaults',f'--datadir={data2}',
                 '--auth-root-authentication-method=normal','--skip-test-db','--user=root'])
            with log2.open('w') as log:
                server2=subprocess.Popen([str(BIN/'mariadbd'),'--no-defaults',f'--datadir={data2}',
                    f'--socket={socket2}',f'--pid-file={root/"restore.pid"}',
                    '--bind-address=127.0.0.1','--port=43320','--user=root',
                    '--innodb-flush-log-at-trx-commit=1'],stdout=log,stderr=log)
            children.append(server2);wait_file(socket2,server2)
            client2=[BIN/'mariadb','--no-defaults',f'--socket={socket2}','-uroot',
                     '--batch','--skip-column-names']
            run(client2,data=backup.read_text(),timeout=120)
            def sql2(s): return run(client2,data=s,timeout=120).stdout.strip()
            sql2("CREATE USER 'stratum_h'@'127.0.0.1' IDENTIFIED BY 'disposable-only';"
                 "GRANT SELECT,INSERT,UPDATE,DELETE ON pool_l3_199h.* TO 'stratum_h'@'127.0.0.1';"
                 "GRANT EXECUTE ON PROCEDURE pool_l3_199h.round_lock TO 'stratum_h'@'127.0.0.1';"
                 "GRANT EXECUTE ON PROCEDURE pool_l3_199h.round_hold TO 'stratum_h'@'127.0.0.1';"
                 "GRANT EXECUTE ON PROCEDURE pool_l3_199h.round_resolve TO 'stratum_h'@'127.0.0.1';"
                 "GRANT EXECUTE ON PROCEDURE pool_l3_199h.round_schema_assert TO 'stratum_h'@'127.0.0.1';")
            restored_client=[str(x).replace(f'--port={PORT_DB}','--port=43320') for x in app_client]
            report['restored_guard_result']=run(restored_client,data='CALL round_schema_assert();').stdout.strip()
            report['restore']={'server_version':sql2('SELECT VERSION();'),'datadir':sql2('SELECT @@datadir;'),
                'socket':sql2('SELECT @@socket;'),'port':sql2('SELECT @@port;'),
                'counts':sql2('USE pool_l3_199h; SELECT (SELECT COUNT(*) FROM accepted_work),(SELECT COUNT(*) FROM share_rounds),(SELECT COUNT(*) FROM shares);'),
                'owner':sql2('USE pool_l3_199h; SELECT id,coin_id,algo,round_id,sequence,userid,workerid,state,header_hex FROM accepted_work;'),
                'rounds':sql2('USE pool_l3_199h; SELECT * FROM share_rounds ORDER BY id;'),
                'shares':sql2('USE pool_l3_199h; SELECT * FROM shares ORDER BY id;')}
            if report['restored_guard_result'] != report['restricted_guard_result'] or \
               any(report['restore'][key]!=value for key,value in expected_records.items()):
                raise RuntimeError('restored guard, owner, round, or share mismatched backup')
            report['checks']['backup_restore_owner_round_share_guard']='PASS'
            cfg.write_text(cfg.read_text().replace(f'port = {PORT_DB}', 'port = 43320'))
            restored_log=root/'restored-stratum.log'
            submitblock_before=report.get('rpc_calls',[]).count('submitblock')
            with restored_log.open('w') as log:
                restored_stratum=subprocess.Popen([str(binary),'scrypt'],cwd=root,stdout=log,stderr=log,env=stratum_env)
            children.append(restored_stratum)
            report['restore']['listener_open']=wait_listener(restored_stratum,PORT_STRATUM,seconds=7)
            report['restore']['stratum_exit_before_shutdown']=restored_stratum.poll()
            report['restore']['post_start_records']={
                'owner':sql2('USE pool_l3_199h; SELECT id,coin_id,algo,round_id,sequence,userid,workerid,state,header_hex FROM accepted_work;'),
                'rounds':sql2('USE pool_l3_199h; SELECT * FROM share_rounds ORDER BY id;'),
                'shares':sql2('USE pool_l3_199h; SELECT * FROM shares ORDER BY id;')}
            report['restore']['submitblock_calls_before_start']=submitblock_before
            report['restore']['submitblock_calls_after_start']=report.get('rpc_calls',[]).count('submitblock')
            if restored_stratum.poll() is not None or \
               report['restore']['post_start_records']!=expected_records or \
               report['restore']['submitblock_calls_after_start']!=submitblock_before:
                raise RuntimeError('restored startup changed durable records or called submitblock')
            report['checks']['restore_listener_records_no_replay']='PASS'
            restored_stratum.send_signal(signal.SIGTERM);restored_stratum.wait(timeout=10)
            report['restore']['stratum_final_exit']=restored_stratum.returncode
            report['restore']['log']=restored_log.read_text(errors='replace')[-3000:]
            require_exit(restored_stratum,0,'restored Stratum')
            report['checks']['restored_process_expected_exit']='PASS'
        httpd.shutdown();httpd.server_close()
        if upstream_server:
            upstream_server.shutdown();upstream_server.server_close()
        if proxy_server:
            proxy_server.shutdown();proxy_server.server_close()
    acceptable_exit = (1,) if os.environ.get('H_VARIANT')=='db_down' else (0,)
    if stratum.returncode not in acceptable_exit or any(v!='PASS' for v in report['checks'].values()):
        report['classification']='FAIL'
        return 1
    report['classification']='PASS'
    return 0

if __name__=='__main__':
    try: code=main()
    except Exception as exc:
        report['error']=str(exc);code=2
        if report.get('new_miner_failure',{}).get('stratum_poll') == -11 or \
           report.get('submit_failure',{}).get('stratum_poll') == -11:
            report['classification']='FAIL'
    finally:
        for child in reversed(children):
            if child.poll() is None:
                child.terminate()
                try: child.wait(timeout=8)
                except subprocess.TimeoutExpired: child.kill();child.wait()
        OUT.mkdir(parents=True,exist_ok=True)
        report['attempt']['finished_utc']=datetime.now(timezone.utc).isoformat()
        with (OUT/f'result-{RUN_ID}.json').open('x') as result_file:
            result_file.write(json.dumps(report,indent=2,default=str)+'\n')
        print(json.dumps({'classification':report['classification'],'error':report.get('error'),
                          'checks':report['checks'],'migrations':len(report['migrations'])}))
    sys.exit(code)
