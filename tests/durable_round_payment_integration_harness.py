#!/usr/bin/env python3
"""Offline integration: execute the actual payment adapter SELECT in SQLite.

This uses an in-memory synthetic database, not pool configuration or wallet RPC.
PHP emits the production query and consumes the resulting exact row inventory.
The existing durable-round MariaDB harness separately covers schema triggers.
"""
import json
import pathlib
import sqlite3
import subprocess
import tempfile

ROOT = pathlib.Path(__file__).resolve().parent.parent
PHP = r'''
function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}
require $argv[1].'/web/yaamp/core/backend/BadpoolPaymentBatchPhaseAdapter.php';
class RoundPaymentQueryProbe {
 public $sql,$params,$rows;
 function selectAll($sql,$params){$this->sql=$sql;$this->params=$params;return $this->rows;}
}
$input=json_decode(stream_get_contents(STDIN),true);
$lane=(new BadpoolLivePaymentLaneRegistry())->get($input['lane']);
$guard=new RoundPaymentQueryProbe();$guard->rows=$input['rows'];
$adapter=new BadpoolPaymentBatchPhaseAdapter($guard,function(){throw new Exception('No financial command is allowed.');});
$result=$adapter->selectEligibleWork(array('mode'=>'auto','run_directory'=>$argv[2],
 'selected_coin_scope'=>array(array('id'=>$lane->coinId()))),array('mode'=>'auto',
 'batch_size'=>25,'lane_configuration'=>$lane,'excluded_earning_ids'=>$input['excluded']));
echo json_encode(array('sql'=>$guard->sql,'params'=>$guard->params,'result'=>$result));
'''
checks = 0


def check(condition, label):
    global checks
    checks += 1
    if not condition:
        raise AssertionError(label)


def probe(directory, lane, rows=(), excluded=()):
    result = subprocess.run(
        ['php', '-r', PHP, str(ROOT), directory],
        input=json.dumps({'lane': lane, 'rows': list(rows), 'excluded': list(excluded)}),
        capture_output=True, text=True, check=True,
    )
    return json.loads(result.stdout)


def database():
    db = sqlite3.connect(':memory:')
    db.row_factory = sqlite3.Row
    db.executescript('''
    CREATE TABLE earnings(id INTEGER,blockid INTEGER,userid INTEGER,coinid INTEGER,status INTEGER,mature_time INTEGER);
    CREATE TABLE blocks(id INTEGER,coin_id INTEGER,blockhash TEXT,category TEXT);
    CREATE TABLE accounts(id INTEGER,coinid INTEGER);
    CREATE TABLE live_block_candidates(block_id INTEGER,coin_id INTEGER,algo TEXT,blockhash TEXT,
        attribution_version INTEGER,seal_state TEXT,round_id INTEGER);
    CREATE TABLE share_rounds(id INTEGER,state TEXT,block_id INTEGER);
    CREATE TABLE round_intents(round_id INTEGER,state TEXT,block_id INTEGER);
    ''')
    return db


def insert(db, coin, algo, boundary, earning, version=2, candidate='SEALED',
           round_state='SEALED', intent='RESOLVED', round_exists=True,
           intent_exists=True, round_matches=True, intent_matches=True,
           status=1, mature=100):
    block = boundary + earning
    round_id = earning
    db.execute('INSERT INTO blocks VALUES(?,?,?,?)', (block, coin, 'hash-'+str(block), 'generate'))
    db.execute('INSERT INTO earnings VALUES(?,?,?,?,?,?)', (earning, block, 79, coin, status, mature))
    db.execute('INSERT INTO live_block_candidates VALUES(?,?,?,?,?,?,?)',
               (block, coin, algo, 'hash-'+str(block), version, candidate, round_id))
    if round_exists:
        db.execute('INSERT INTO share_rounds VALUES(?,?,?)',
                   (round_id, round_state, block if round_matches else block + 1))
    if intent_exists:
        db.execute('INSERT INTO round_intents VALUES(?,?,?)',
                   (round_id, intent, block if intent_matches else block + 1))


cases = (
    ('legacy', {'version': 1, 'candidate': 'LEGACY', 'round_exists': False, 'intent_exists': False}, True),
    ('sealed-v2', {}, True),
    ('unsealed-candidate', {'candidate': 'OPEN'}, False),
    ('held-candidate', {'candidate': 'AMBIGUOUS_HOLD'}, False),
    ('unsealed-round', {'round_state': 'OPEN'}, False),
    ('held-round', {'round_state': 'AMBIGUOUS_HOLD'}, False),
    ('held-intent', {'intent': 'AMBIGUOUS_HOLD'}, False),
    ('pending-intent', {'intent': 'INTENT_PENDING'}, False),
    ('wrong-round-block', {'round_matches': False}, False),
    ('wrong-intent-block', {'intent_matches': False}, False),
    ('missing-round', {'round_exists': False}, False),
    ('missing-intent', {'intent_exists': False}, False),
    ('unknown-version', {'version': 3}, False),
    ('already-credited', {'status': 2}, False),
    ('not-mature', {'mature': None}, False),
)

with tempfile.TemporaryDirectory(prefix='badpool-round-payment-integration-') as directory:
    for lane in ('live-scrypt-v1', 'live-groestl-v1', 'live-yescrypt-v1', 'live-skein-v1'):
        query = probe(directory, lane)
        params = {key.lstrip(':'): value for key, value in query['params'].items()}
        sql = query['sql']
        db = database()
        db.execute('INSERT INTO accounts VALUES(79,?)', (params['coin'],))
        for earning, (label, changes, eligible) in enumerate(cases, 1):
            insert(db, params['coin'], params['algo'], params['boundary'], earning, **changes)
        selected = [dict(row) for row in db.execute(sql, params)]
        check([row['earning_id'] for row in selected] == [1, 2], lane + ': only legacy and fully sealed v2 selected')
        # Test each blocked row independently using the same production query.
        for earning, (label, changes, eligible) in enumerate(cases, 1):
            single = sql.replace('WHERE ', 'WHERE E.id='+str(earning)+' AND ', 1)
            check(bool(db.execute(single, params).fetchall()) == eligible, lane + ': ' + label)
        applied = probe(directory, lane, selected)['result']
        check(applied['status'] == 'pass' and applied['selected_earning_ids'] == [1, 2],
              lane + ': adapter consumes exact gated inventory')
        check(applied['selected_accounts_by_coin'][str(params['coin'])]['account_ids'] == [79],
              lane + ': gated inventory retains exact account ownership')
        excluded = probe(directory, lane, excluded=[1, 2])
        excluded_params = {key.lstrip(':'): value for key, value in excluded['params'].items()}
        check(not db.execute(excluded['sql'], excluded_params).fetchall(),
              lane + ': durable batch exclusions remain effective with round gate')
        db.close()

print('PASS durable round/payment SQL integration ('+str(checks)+' checks; 4 lanes; no production access)')
