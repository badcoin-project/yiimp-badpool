#!/usr/bin/env python3
"""Offline integration: production C++ + actual migration + PHP against a socket-only disposable DB.

Prerequisite: tests/durable_round_test_setup.py. No production configuration is read.
"""
import concurrent.futures, http.server, json, os, pathlib, subprocess, tempfile, threading, time
ROOT=pathlib.Path(__file__).resolve().parent.parent
BASE=pathlib.Path('/tmp/badpool-l3194-test-tools/extracted')
SOCKET='/tmp/badpool-l3194-isolated-db/mysql.sock'
ENV=dict(os.environ,LD_LIBRARY_PATH=str(BASE/'usr/lib/x86_64-linux-gnu'))
MYSQL=[str(BASE/'usr/bin/mysql'),'--no-defaults','--socket='+SOCKET,'-uroot','--batch','--skip-column-names']
DRIVER='/tmp/badpool-round-driver'
EXT=BASE/'usr/lib/php/20190902'
PHP=['php','-d','extension='+str(EXT/'mysqlnd.so'),'-d','extension='+str(EXT/'pdo_mysql.so')]
RESULTS={}
FAILURES=[]
def sql(command,check=True):
    result=subprocess.run(MYSQL,input=command,text=True,capture_output=True,env=ENV,timeout=15)
    if check and result.returncode: raise AssertionError(result.stderr)
    return result.stdout.strip() if check else result
assert sql('SELECT @@datadir').startswith('/tmp/badpool-l3194-isolated-db/data/'), 'refuse non-test database'
def reset():
    sql('DROP DATABASE IF EXISTS round_test;'+(ROOT/'tests/fixtures/durable_round_schema.sql').read_text())
    sql('USE round_test;'+(ROOT/'sql/2026-09-06-live-block-accounting.sql').read_text())
    sql('USE round_test;'+(ROOT/'sql/2026-10-04-durable-share-rounds.sql').read_text())
def q(command): return sql('USE round_test;'+command)
def driver(action='journal',user=1,worker=1,difficulty=1,unique=1,algo='scrypt',check=True):
    r=subprocess.run([DRIVER,SOCKET,action,algo,str(user),str(worker),str(difficulty),str(unique)],text=True,capture_output=True,timeout=15)
    if check: assert r.returncode==0,r.stderr+r.stdout
    return json.loads(r.stdout)
class Handler(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        request=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        self.server.calls.append(request)
        mode=self.server.mode
        if mode=='timeout':
            time.sleep(31)
            self.close_connection=True; return
        if mode in ('lost','crash'):
            self.close_connection=True; return
        reply={'id':request['id'],'result':None,'error':None}
        if request['method']=='getblockheader': reply['result']={'hash':request['params'][0],'confirmations':2}
        if request['method']=='getblockchaininfo': reply['result']={'blocks':100}
        if request['method']=='getchaintips': reply['result']=[{'height':100,'status':'active'}]
        if mode=='reject': reply['result']='bad-txnmrklroot'
        if mode in ('duplicate','duplicate-invalid','inconclusive','duplicate-inconclusive'): reply['result']=mode
        if mode=='wrong-id': reply['id']='another-candidate'
        if mode=='missing-error': del reply['error']
        self.send_response(200);self.end_headers();self.wfile.write(json.dumps(reply).encode())
    def log_message(self,*args): pass
class FakeDaemon:
    def __init__(self,mode='accept'):
        self.server=http.server.HTTPServer(('127.0.0.1',0),Handler);self.server.mode=mode;self.server.calls=[]
        self.thread=threading.Thread(target=self.server.serve_forever,daemon=True);self.thread.start()
    def dispatch(self,r,algo='scrypt'):
        return subprocess.run([DRIVER,SOCKET,'dispatch',algo,str(r['intent']),str(self.server.server_port),r['block']],capture_output=True,text=True,timeout=40)
    def close(self): self.server.shutdown();self.server.server_close();self.thread.join()
def send(r,mode='accept',algo='scrypt'):
    daemon=FakeDaemon(mode)
    try: result=daemon.dispatch(r,algo);return result,len(daemon.server.calls)
    finally: daemon.close()
def state(): return q('SELECT state FROM round_intents ORDER BY id LIMIT 1')
def amounts():
    return q('SELECT GROUP_CONCAT(CONCAT(userid,":",difficulty) ORDER BY userid) FROM live_block_attributions')
def mark(r):
    q("UPDATE round_intents SET dispatch_state='MAY_HAVE_DISPATCHED',state='INTENT_PENDING',dispatched_at=UTC_TIMESTAMP(6)-INTERVAL 61 SECOND,daemon_identity='http://127.0.0.1:test' WHERE id="+str(r['intent']))
def recover():
    result=subprocess.run([DRIVER,SOCKET,'recover'],capture_output=True,text=True)
    assert result.returncode==0,result.stderr
def status(*arguments):
    env=dict(ENV,BADPOOL_ROUND_DSN='mysql:unix_socket='+SOCKET+';dbname=round_test',BADPOOL_ROUND_DB_USER='root',BADPOOL_ROUND_DB_PASSWORD='')
    result=subprocess.run(PHP+[str(ROOT/'tools/badpool-round-status.php')]+list(arguments),env=env,text=True,capture_output=True)
    assert result.returncode==0,result.stderr
    return result.stdout
def hold():
    r=driver('prepare');result,calls=send(r,'lost');assert result.returncode==1 and calls==1 and state()=='AMBIGUOUS_HOLD';return r
def interactive_login():
    with tempfile.TemporaryDirectory(prefix='badpool-round-php-') as directory:
        config=pathlib.Path(directory)/'90-round-test.ini'
        config.write_text('extension='+str(EXT/'mysqlnd.so')+'\nextension='+str(EXT/'pdo_mysql.so')+'\n')
        env=dict(ENV,BADPOOL_ROUND_DSN='mysql:unix_socket='+SOCKET+';dbname=round_test',BADPOOL_ROUND_DB_USER='root',BADPOOL_ROUND_DB_PASSWORD='',
            PATH=str(ROOT/'ops')+os.pathsep+os.environ['PATH'],PHP_INI_SCAN_DIR='/etc/php/7.4/cli/conf.d:'+directory)
        result=subprocess.run(['bash','--noprofile','--norc','-ic','. "'+str(ROOT/'ops/badpool-round-login-notice.sh')+'"'],
            env=env,text=True,capture_output=True,stdin=subprocess.DEVNULL,timeout=5)
        assert result.returncode==0,result.stderr
        return result.stdout
def recorded(r,outcome='ACCEPTED'):
    response=json.dumps({'id':'badpool-round-'+str(r['intent'])+'-attempt-1','result':None if outcome=='ACCEPTED' else 'bad-txnmrklroot','error':None})
    q("UPDATE round_intents SET daemon_outcome='"+outcome+"',original_response='"+response+"',response_captured_at=UTC_TIMESTAMP(6),resolution_source='DAEMON_ORIGINAL_RESPONSE',resolution_evidence='contemporaneous matched original envelope' WHERE id="+str(r['intent']))
def adjudicate(r,treatment='ACCEPT',reason='documented policy',evidence='offline fixture record',actor='test-operator'):
    identity=q('SELECT coin_id,algo,round_id,blockhash FROM round_intents WHERE id='+str(r['intent'])).split('\t')
    env=dict(ENV,BADPOOL_ROUND_DSN='mysql:unix_socket='+SOCKET+';dbname=round_test',BADPOOL_ROUND_DB_USER='root',BADPOOL_ROUND_DB_PASSWORD='')
    return subprocess.run(PHP+[str(ROOT/'tools/badpool-round-adjudicate.php'),'--intent='+str(r['intent']),'--coin='+identity[0],
        '--algo='+identity[1],'--round='+identity[2],'--hash='+identity[3],'--actor='+actor,'--treatment='+treatment,'--reason='+reason,'--evidence='+evidence],env=env,text=True,capture_output=True)
def case(numbers,function):
    reset()
    try:
        function()
        for n in numbers: RESULTS[n]='PASS'
        print('PASS scenarios '+(','.join(map(str,numbers)) if numbers else function.__name__),flush=True)
    except Exception as error:
        FAILURES.append(numbers or ['extra_concurrency'])
        for n in numbers: RESULTS[n]='FAIL'
        print('FAIL scenarios '+','.join(map(str,numbers))+': '+repr(error),flush=True)
def normal():
    r=driver('prepare',difficulty=2);result,calls=send(r);assert result.returncode==0 and calls==1
    assert amounts()=='1:2' and q('SELECT COUNT(*) FROM accepted_work')=='1'
    assert q('SELECT difficulty_user FROM blocks')=='999'
case([1,7,10,19,58],normal)
def multiple():
    driver(user=1,worker=1,difficulty=2,unique=1);driver(user=1,worker=2,difficulty=3,unique=2)
    driver(user=2,worker=3,difficulty=4,unique=3)
    r=driver('prepare',user=3,worker=4,difficulty=5,unique=4);assert send(r)[0].returncode==0
    assert amounts()=='1:5,2:4,3:5' and q('SELECT COUNT(*) FROM shares')=='0'
    assert q('SELECT no_fees,donation FROM live_block_attributions WHERE userid=2')=='1\t2.5'
case([2,3,4,6,9,59],multiple)
def rapid():
    for n in range(1,4):
        r=driver('prepare',unique=n,difficulty=n);assert send(r)[0].returncode==0
    assert q('SELECT COUNT(DISTINCT round_id),COUNT(*) FROM accepted_work')=='3\t3'
    assert q('SELECT COUNT(*) FROM shares')=='0'
    assert q('SELECT COUNT(*) FROM live_block_candidates WHERE attribution_version=2 AND seal_state="SEALED"')=='3'
case([5,8,9],rapid)
def duplicate():
    r=driver('prepare');assert send(r)[0].returncode==0
    replay=driver(unique=1);assert replay['duplicate']
    assert q('SELECT COUNT(*) FROM accepted_work')=='1'
    result,calls=send(r);assert result.returncode==1 and calls==0
case([11,13,14,50],duplicate)
def failures():
    q("CREATE TRIGGER fail_journal BEFORE INSERT ON accepted_work FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected failure'")
    r=driver(check=False);assert not r['ok'] and q('SELECT COUNT(*) FROM accepted_work')=='0'
    q('DROP TRIGGER fail_journal')
    q("CREATE TRIGGER fail_intent BEFORE INSERT ON round_intents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected failure'")
    r=driver('prepare',check=False);assert not r['ok'] and q('SELECT COUNT(*) FROM round_intents')=='0'
    assert q('SELECT COUNT(*) FROM accepted_work')=='1'
case([15,16],failures)
def predispatch():
    r=driver('prepare');assert q('SELECT dispatch_state FROM round_intents')=='NEVER_DISPATCHED'
    recover();assert state()=='PREPARED'
    daemon=FakeDaemon()
    try:
        result=subprocess.run([DRIVER,SOCKET,'resume','scrypt',str(r['intent']),str(daemon.server.server_port),r['block']],capture_output=True,text=True,timeout=40)
        assert result.returncode==0 and len(daemon.server.calls)==1
        again=subprocess.run([DRIVER,SOCKET,'resume','scrypt',str(r['intent']),str(daemon.server.server_port),r['block']],capture_output=True,text=True,timeout=5)
        assert again.returncode==1 and len(daemon.server.calls)==1
    finally:daemon.close()
case([17],predispatch)
def afterdispatch():
    r=driver('prepare');daemon=FakeDaemon('timeout')
    process=subprocess.Popen([DRIVER,SOCKET,'dispatch','scrypt',str(r['intent']),str(daemon.server.server_port),r['block']],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    try:
        deadline=time.monotonic()+5
        while not daemon.server.calls and time.monotonic()<deadline: time.sleep(.01)
        assert daemon.server.calls
        process.kill();process.communicate()
        assert q('SELECT dispatch_state FROM round_intents')=='MAY_HAVE_DISPATCHED' and state()=='INTENT_PENDING'
    finally:
        if process.poll() is None: process.kill();process.communicate()
        daemon.close()
    q('UPDATE round_intents SET dispatched_at=UTC_TIMESTAMP(6)-INTERVAL 61 SECOND');recover();assert state()=='AMBIGUOUS_HOLD'
    result,calls=send(r);assert result.returncode==1 and calls==0
    recover();assert state()=='AMBIGUOUS_HOLD' and 'ATTENTION' in status('--login')
case([18,47,48],afterdispatch)
def rejection():
    r=driver('prepare',difficulty=2);assert send(r,'reject')[0].returncode==1
    assert state()=='RESOLVED' and q('SELECT state FROM share_rounds WHERE id='+str(r['round']))=='MERGED'
    next_r=driver('prepare',unique=2,difficulty=3);assert send(next_r)[0].returncode==0;assert amounts()=='1:5'
case([20],rejection)
for n,mode in [(21,'timeout'),(22,'lost'),(23,'crash'),(24,'inconclusive'),(25,'duplicate'),(26,'duplicate-invalid')]:
    def ambiguous(mode=mode):
        r=driver('prepare');result,calls=send(r,mode);assert result.returncode==1 and calls==1
        assert state()=='AMBIGUOUS_HOLD' and q('SELECT COUNT(*) FROM blocks')=='0'
        again,count=send(r);assert again.returncode==1 and count==0
    case([n],ambiguous)
def current_only():
    r=hold()
    source=ROOT/'web/yaamp/core/backend/BadpoolRoundStatus.php'
    code="require '"+str(source)+"';echo BadpoolRoundStatus::evidence(array('id'=>1,'original_response'=>null),array('confirmations'=>-1));"
    result=subprocess.run(['php','-r',code],capture_output=True,text=True)
    assert result.stdout=='CURRENT_STATE_ONLY' and state()=='AMBIGUOUS_HOLD'
case([27,28,31],current_only)
def original():
    r=hold();recorded(r);recover();assert state()=='RESOLVED' and amounts()=='1:1'
    assert q('SELECT resolution_source FROM round_intents')=='DAEMON_ORIGINAL_RESPONSE'
case([29,30],original)
def held_gate():
    r=hold();driver(unique=2,user=2,difficulty=4)
    assert q('SELECT COUNT(*) FROM blocks')=='0' and q('SELECT COUNT(*) FROM live_block_attributions')=='0'
    assert q('SELECT COUNT(DISTINCT round_id) FROM accepted_work')=='2'
    next_r=driver('prepare',unique=3,check=False);assert not next_r['ok']
    assert q('SELECT COUNT(*) FROM round_intents')=='1'
case([32,33,34,35,36],held_gate)
def audit():
    r=hold();assert adjudicate(r,reason='').returncode!=0;assert adjudicate(r,evidence='').returncode!=0
    assert adjudicate(r).returncode==0 and state()=='RESOLVED'
    assert q('SELECT resolution_source FROM round_intents')=='OPERATOR_ADJUDICATED_ACCEPT'
    assert q('SELECT daemon_outcome IS NULL,accounting_outcome FROM round_intents')=='1\tACCEPTED'
    assert q('SELECT COUNT(*) FROM round_resolution_audit')=='1'
    assert sql("USE round_test;UPDATE round_resolution_audit SET reason='changed';",False).returncode!=0
case([37,38],audit)
def notices():
    assert status('--login')==''
    assert interactive_login()==''
    r=hold();assert q('SELECT COUNT(*) FROM round_operator_events WHERE event_type="HOLD_ENTERED"')=='1'
    one=status('--login');assert '1 unresolved' in one and status('--login')==one
    assert '1 unresolved' in interactive_login() and '1 unresolved' in interactive_login()
    assert json.loads(status('--json'))['holds'][0]['downstream']=='BLOCKED'
    # Consumer outage: no delivery acknowledgement is needed to retain the HOLD.
    assert state()=='AMBIGUOUS_HOLD'
    before=q('SELECT COUNT(*) FROM round_operator_events');status('--json');status('--events-after=0')
    assert q('SELECT COUNT(*) FROM round_operator_events')==before and state()=='AMBIGUOUS_HOLD'
    assert adjudicate(r).returncode==0
    assert status('--login')=='' and q('SELECT COUNT(*) FROM round_operator_events WHERE event_type="HOLD_RESOLVED"')=='1'
    assert interactive_login()==''
case([39,40,41,42,43,45,46],notices)
def several():
    r=hold();second=driver('prepare',unique=2,algo='yescrypt');assert send(second,'lost','yescrypt')[0].returncode==1
    assert '2 unresolved' in status('--login') and len(json.loads(status('--json'))['holds'])==2
case([44],several)
def seal_retry():
    q("CREATE TRIGGER fail_seal BEFORE INSERT ON live_block_attributions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected seal failure'")
    r=driver('prepare');result,calls=send(r);assert result.returncode==1 and calls==1 and state()=='AMBIGUOUS_HOLD'
    assert q('SELECT COUNT(*) FROM blocks')=='0'
    q('DROP TRIGGER fail_seal');recover();assert state()=='RESOLVED' and amounts()=='1:1'
    recover();assert q('SELECT COUNT(*) FROM blocks')=='1'
case([49],seal_retry)
def orphan():
    r=driver('prepare');assert send(r)[0].returncode==0
    for category in ('orphan','stale','invalidated'):
        q("UPDATE blocks SET category='"+category+"'")
        recover();assert q('SELECT daemon_outcome FROM round_intents')=='ACCEPTED' and amounts()=='1:1'
        assert sql('USE round_test;UPDATE accepted_work SET round_id=2;',False).returncode!=0
        assert sql('USE round_test;DELETE FROM live_block_candidates;',False).returncode!=0
        assert sql('USE round_test;DELETE FROM round_intents;',False).returncode!=0
        assert sql('USE round_test;DELETE FROM blocks;',False).returncode!=0
case([51,52,53],orphan)
def algorithms():
    for n,algo in enumerate(('scrypt','yescrypt','skein','sha256','badcoin-groestl')):
        r=driver('prepare',unique=n+1,algo=algo);assert send(r,algo=algo)[0].returncode==0
    assert q('SELECT COUNT(*) FROM round_lanes')=='5'
case([54],algorithms)
def aggregate():
    r=driver('prepare');assert send(r)[0].returncode==0
    result=subprocess.run([DRIVER,SOCKET,'aggregate','scrypt'],capture_output=True,text=True,timeout=10)
    assert result.returncode==0,result.stderr
    assert q('SELECT GROUP_CONCAT(CONCAT(round_id,":",difficulty) ORDER BY round_id) FROM shares')=='1:2,2:3'
    assert amounts()=='1:1' and q('SELECT COUNT(*) FROM accepted_work')=='1'
case([12],aggregate)
def legacy():
    q("INSERT INTO blocks(id,coin_id,algo,blockhash,category) VALUES(900,1,'scrypt','legacy','immature');INSERT INTO live_block_candidates(block_id,coin_id,algo,blockhash,found_time,price,share_floor_id,share_ceiling_id) VALUES(900,1,'scrypt','legacy',1,1,1,1);INSERT INTO live_block_attributions VALUES(900,2,215.228201,1,2.5);INSERT INTO earnings(blockid,userid,amount) VALUES(900,2,4);INSERT INTO payout_batches VALUES(1,'WAITING_FOR_WALLET_APPROVAL','legacy-checksum');")
    before=q('SELECT * FROM live_block_attributions WHERE block_id=900')+q('SELECT * FROM earnings WHERE blockid=900')+q('SELECT * FROM payout_batches')
    r=driver('prepare');assert send(r)[0].returncode==0
    after=q('SELECT * FROM live_block_attributions WHERE block_id=900')+q('SELECT * FROM earnings WHERE blockid=900')+q('SELECT * FROM payout_batches')
    assert before==after and q('SELECT attribution_version,seal_state FROM live_block_candidates WHERE block_id=900')=='1\tLEGACY'
case([55,56],legacy)
def database_gate():
    r=hold()
    identity=q('SELECT blockhash FROM round_intents WHERE id='+str(r['intent']))
    q("INSERT INTO blocks(id,coin_id,algo,blockhash,category) VALUES(500,1,'scrypt','"+identity+"','immature');INSERT INTO live_block_candidates(block_id,coin_id,algo,blockhash,found_time,price,share_floor_id,share_ceiling_id,attribution_version,round_id,seal_state) VALUES(500,1,'scrypt','"+identity+"',1,1,0,1,2,"+str(r['round'])+",'SEALED');")
    assert sql('USE round_test;INSERT INTO earnings(blockid,userid,amount) VALUES(500,1,1);',False).returncode!=0
    q('INSERT INTO earnings(blockid,userid,amount) VALUES(901,1,1)')
    assert sql('USE round_test;UPDATE earnings SET blockid=500 WHERE blockid=901;',False).returncode!=0
    gate=subprocess.run(['php','-r',"require '"+str(ROOT/'web/yaamp/core/backend/BadpoolRoundGate.php')+"';echo BadpoolRoundGate::sql();"],text=True,capture_output=True).stdout
    assert q('SELECT COUNT(*) FROM live_block_candidates C WHERE '+gate)=='0'
    result=subprocess.run(['php',str(ROOT/'tests/durable_round_downstream_harness.php')],text=True,capture_output=True)
    assert result.returncode==0,result.stderr
case([57],database_gate)
def nontransactional():
    q('ALTER TABLE blocks ENGINE=MyISAM')
    result=driver(check=False);assert not result['ok'] and q('SELECT COUNT(*) FROM accepted_work')=='0'
case([],nontransactional)
def daemon_inspection():
    r=driver('prepare');daemon=FakeDaemon('lost')
    try:
        assert daemon.dispatch(r).returncode==1 and state()=='AMBIGUOUS_HOLD'
        daemon.server.mode='accept'
        report=json.loads(status('--intent='+str(r['intent']),'--json','--daemon-evidence'))
        assert state()=='AMBIGUOUS_HOLD'
        assert [c['method'] for c in report['holds'][0]['daemon_evidence']['checks']]==['getblockheader','getblockchaininfo','getchaintips']
        assert all(c['classification']=='CURRENT_STATE_ONLY' for c in report['holds'][0]['daemon_evidence']['checks'])
        assert [c['method'] for c in daemon.server.calls]==['submitblock','getblockheader','getblockchaininfo','getchaintips']
    finally:daemon.close()
case([],daemon_inspection)
def operator_reject():
    r=hold();assert adjudicate(r,'REJECT').returncode==0
    assert q('SELECT daemon_outcome IS NULL,accounting_outcome FROM round_intents')=='1\tREJECTED'
    following=driver('prepare',unique=2,difficulty=3);assert send(following)[0].returncode==0 and amounts()=='1:4'
case([],operator_reject)
def nullable_fees_and_canonical_replay():
    q('UPDATE accounts SET no_fees=NULL,donation=NULL WHERE id=1')
    r=driver('prepare',unique=171);assert send(r)[0].returncode==0
    result=subprocess.run([DRIVER,SOCKET,'journal','scrypt','1','1','10','171'],env=dict(os.environ,ROUND_TEST_UPPERCASE='1'),capture_output=True,text=True)
    assert result.returncode==0 and json.loads(result.stdout)['duplicate']
    assert q('SELECT COUNT(*),SUM(assigned_difficulty) FROM accepted_work')=='1\t1'
    assert q('SELECT no_fees,donation FROM live_block_attributions')=='0\t0'
case([],nullable_fees_and_canonical_replay)
def forward_startup():
    args=[DRIVER,SOCKET,'startup','scrypt']
    assert subprocess.run(args,capture_output=True).returncode==0
    driver(unique=1)
    assert subprocess.run(args,env=dict(os.environ,ROUND_TEST_DISABLED='1'),capture_output=True).returncode==1
    assert sql("USE round_test;INSERT INTO live_block_candidates(block_id,coin_id,algo,blockhash,found_time,price,share_floor_id,share_ceiling_id) VALUES(999,1,'scrypt','legacy',1,1,0,0);",False).returncode!=0
    q('DROP TABLE round_schema_version')
    assert subprocess.run(args,capture_output=True).returncode==1
case([],forward_startup)
def concurrency():
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        rows=list(pool.map(lambda n:driver(unique=n+1),range(32)))
    assert len({r['sequence'] for r in rows})==32
    r=driver('prepare',unique=100);assert send(r)[0].returncode==0;assert amounts()=='1:33'
    assert q('SELECT COUNT(DISTINCT round_id) FROM accepted_work')=='1'
case([],concurrency)
print('SCENARIO_RESULTS='+json.dumps(RESULTS,sort_keys=True))
print('TESTS_FAILED='+str(len(FAILURES)))
raise SystemExit(bool(FAILURES))
