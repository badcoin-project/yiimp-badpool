#!/usr/bin/env python3
"""Focused POOL-L3.199G startup regression on disposable MariaDB 10.11.

Requires --mariadb-bin to point to 10.11 client/server/install-db wrappers.
Every database action is restricted to the random /tmp socket-only server.
PARTIAL and FAIL deliberately return nonzero to CI.
"""

import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import time


SOURCE = Path(__file__).resolve().parents[1]
APP_USER = 'stratum_guard_test'
APP_PASSWORD = 'disposable-only'


def run(argv, *, data=None, env=None, check=True, timeout=120):
    result = subprocess.run([str(x) for x in argv], input=data, text=True,
                            capture_output=True, env=env, timeout=timeout)
    if check and result.returncode:
        raise RuntimeError(f'{argv[0]} exit={result.returncode}: {(result.stderr + result.stdout)[-1000:]}')
    return result


def main(binary_dir):
    report = {'task': 'POOL-L3.199G', 'source_base': '809ed8f47283962a52e69fce39d1a4b01fb0643d',
              'checks': {}, 'cases': []}
    with tempfile.TemporaryDirectory(prefix='pool-l3-199g-', dir='/tmp') as temporary:
        root = Path(temporary).resolve()
        if root.parent != Path('/tmp') or not root.name.startswith('pool-l3-199g-'):
            raise RuntimeError('refusing non-disposable server root')
        datadir = root / 'data'
        socket = root / 'db.sock'
        run([binary_dir/'mariadb-install-db', '--no-defaults', f'--datadir={datadir}',
             '--auth-root-authentication-method=normal', '--skip-test-db', '--user=root'])
        with (root/'server.log').open('w') as log:
            server = subprocess.Popen([str(binary_dir/'mariadbd'), '--no-defaults',
                f'--datadir={datadir}', f'--socket={socket}', f'--pid-file={root/"db.pid"}',
                '--skip-networking', '--user=root', '--innodb-flush-log-at-trx-commit=1'],
                stdout=log, stderr=log)
            try:
                for _ in range(100):
                    if socket.exists():
                        break
                    if server.poll() is not None:
                        raise RuntimeError(f'disposable MariaDB exited: {(root/"server.log").read_text()[-1000:]}')
                    time.sleep(.1)
                else:
                    raise RuntimeError('disposable MariaDB socket unavailable')
                client = [binary_dir/'mariadb', '--no-defaults', f'--socket={socket}',
                          '-uroot', '--batch', '--skip-column-names']

                def sql(statement):
                    return run(client, data=statement, timeout=60).stdout.strip()

                identity = sql('SELECT VERSION(),@@datadir,@@skip_networking;').split('\t')
                if len(identity) != 3 or not identity[0].startswith('10.11.') or \
                   Path(identity[1]).resolve() != datadir or identity[2] != '1':
                    raise RuntimeError(f'wrong or networked database: {identity}')
                report['server_version'] = identity[0]
                report['checks']['isolated_mariadb_10_11'] = 'PASS'

                driver = root/'durable-round-driver'
                run(['g++', '-std=c++17', '-ffunction-sections', '-fdata-sections',
                     '-Wl,--gc-sections', '-I'+str(SOURCE/'stratum'), '-I/usr/include/mariadb',
                     SOURCE/'tests/durable_round_driver.cpp', SOURCE/'stratum/durable_round.cpp',
                     SOURCE/'stratum/share.cpp', SOURCE/'stratum/json.cpp', '-lmariadb', '-lcurl',
                     '-lcrypto', '-lpthread', '-o', driver], timeout=180)
                app_env = dict(os.environ, ROUND_TEST_DB_USER=APP_USER,
                               ROUND_TEST_DB_PASSWORD=APP_PASSWORD)

                fixture = (SOURCE/'tests/fixtures/durable_round_schema.sql').read_text()
                forward = (SOURCE/'sql/2026-09-06-live-block-accounting.sql').read_text()
                durable = (SOURCE/'sql/2026-10-04-durable-share-rounds.sql').read_text()
                guard = (SOURCE/'sql/2026-10-16-durable-schema-guard.sql').read_text()
                trigger_names = re.findall(r'^CREATE TRIGGER (\w+) ', durable, re.M)
                if len(trigger_names) != 22 or len(set(trigger_names)) != 22:
                    raise RuntimeError('pinned trigger inventory changed')
                owned_tables = ['round_lanes', 'share_rounds', 'accepted_work', 'round_intents',
                                'round_resolution_audit', 'round_operator_events',
                                'round_schema_version', 'live_block_candidates',
                                'live_block_attributions', 'live_block_share_cursors', 'shares', 'blocks', 'accounts',
                                'coins', 'earnings']
                procedures = ['round_lock', 'round_hold', 'round_resolve', 'round_schema_assert']

                def startup(*, disabled=False):
                    env = dict(app_env)
                    if disabled:
                        env['ROUND_TEST_DISABLED'] = '1'
                    return run([driver, socket, 'startup', 'scrypt'], env=env, check=False).returncode

                def create_complete():
                    sql('DROP DATABASE IF EXISTS round_test;\n'+fixture)
                    sql('USE round_test;\n'+forward)
                    sql('USE round_test;\n'+durable)
                    sql('USE round_test;\n'+guard)
                    sql("CREATE USER IF NOT EXISTS 'stratum_guard_test'@'localhost' IDENTIFIED BY 'disposable-only';"
                        "GRANT SELECT,INSERT,UPDATE,DELETE ON round_test.* TO 'stratum_guard_test'@'localhost';"
                        "GRANT EXECUTE ON PROCEDURE round_test.round_lock TO 'stratum_guard_test'@'localhost';"
                        "GRANT EXECUTE ON PROCEDURE round_test.round_hold TO 'stratum_guard_test'@'localhost';"
                        "GRANT EXECUTE ON PROCEDURE round_test.round_resolve TO 'stratum_guard_test'@'localhost';"
                        "GRANT EXECUTE ON PROCEDURE round_test.round_schema_assert TO 'stratum_guard_test'@'localhost';")

                create_complete()
                if startup() != 0:
                    raise RuntimeError('complete schema refused under restricted identity')
                report['checks']['complete_schema_restricted_identity'] = 'PASS'
                grants = sql("SHOW GRANTS FOR 'stratum_guard_test'@'localhost';")
                if 'TRIGGER' in grants or 'ALTER' in grants or 'CREATE' in grants or 'DROP' in grants:
                    raise RuntimeError('test Stratum identity has DDL/trigger privilege')
                report['checks']['no_runtime_schema_privilege'] = 'PASS'
                accepted = run([driver, socket, 'journal', 'scrypt', '1', '1', '2', '1'],
                               env=app_env).stdout
                if '"ok":true' not in accepted or sql('USE round_test; SELECT COUNT(*) FROM accepted_work;') != '1':
                    raise RuntimeError('complete schema did not journal a synthetic share')
                report['checks']['complete_schema_journal'] = 'PASS'

                def refuses(name, mutation):
                    create_complete()
                    sql('USE round_test;\n'+mutation)
                    code = startup()
                    if code != 1:
                        raise RuntimeError(f'incomplete schema was not refused by startup: {name}, exit={code}')
                    report['cases'].append(name)

                for table in owned_tables:
                    refuses('missing_table:'+table, 'SET FOREIGN_KEY_CHECKS=0; DROP TABLE `'+table+'`;')
                for procedure in procedures:
                    refuses('missing_procedure:'+procedure, 'DROP PROCEDURE `'+procedure+'`;')
                for trigger in trigger_names:
                    refuses('missing_trigger:'+trigger, 'DROP TRIGGER `'+trigger+'`;')
                refuses('wrong_engine:shares', 'ALTER TABLE shares ENGINE=MyISAM;')
                refuses('missing_column:accepted_work.donation', 'ALTER TABLE accepted_work DROP COLUMN donation;')
                refuses('wrong_column_type:accepted_work.donation',
                        'ALTER TABLE accepted_work MODIFY donation DECIMAL(20,8) NOT NULL;')
                refuses('missing_index:accepted_work.replay_identity', 'ALTER TABLE accepted_work DROP INDEX replay_identity;')
                refuses('missing_index:shares.operational_round', 'ALTER TABLE shares DROP INDEX operational_round;')
                refuses('missing_foreign_key:accepted_work.round_id',
                        'ALTER TABLE accepted_work DROP FOREIGN KEY accepted_work_ibfk_1;')
                refuses('wrong_foreign_key_action:accepted_work.round_id',
                        'ALTER TABLE accepted_work DROP FOREIGN KEY accepted_work_ibfk_1, '
                        'ADD CONSTRAINT accepted_work_round_id_cascade FOREIGN KEY(round_id) REFERENCES share_rounds(id) ON DELETE CASCADE;')
                refuses('altered_trigger:round_work_guard',
                        'DROP TRIGGER round_work_guard; '
                        'CREATE TRIGGER round_work_guard BEFORE UPDATE ON accepted_work FOR EACH ROW SET @guard_tamper=1;')
                refuses('extra_trigger:accounts',
                        'CREATE TRIGGER unexpected_account_trigger BEFORE UPDATE ON accounts FOR EACH ROW SET @guard_tamper=1;')
                refuses('altered_procedure:round_hold',
                        'DROP PROCEDURE round_hold;\nDELIMITER $$\n'
                        'CREATE PROCEDURE round_hold(IN i BIGINT UNSIGNED,IN why VARCHAR(128)) BEGIN SELECT 1; END$$\nDELIMITER ;')
                refuses('altered_guard_procedure',
                        "DROP PROCEDURE round_schema_assert; CREATE PROCEDURE round_schema_assert() SELECT 'tampered';")
                refuses('missing_version_row', 'DELETE FROM round_schema_version;')
                refuses('wrong_version_row', 'UPDATE round_schema_version SET version=3;')
                refuses('partial_version_marker', 'INSERT INTO round_schema_version VALUES(3,UTC_TIMESTAMP(6));')
                refuses('missing_runtime_execute_grant',
                        "REVOKE EXECUTE ON PROCEDURE round_test.round_schema_assert FROM 'stratum_guard_test'@'localhost';")

                create_complete()
                if startup() != 0:
                    raise RuntimeError('complete schema did not pass after all mutations')
                # The legacy path must retain its original uncommissioned behavior.
                sql('USE round_test; DROP PROCEDURE round_schema_assert;')
                if startup(disabled=True) != 0:
                    raise RuntimeError('legacy startup behavior changed')
                report['checks']['legacy_uncommissioned_unchanged'] = 'PASS'
                source = (SOURCE/'stratum/stratum.cpp').read_text()
                if source.index('if(!round_startup(db))') > source.index('if(g_durable_rounds) db_register_stratum(db);'):
                    raise RuntimeError('durable schema check occurs after Stratum registration')
                if source.index('if(!g_durable_rounds) db_register_stratum(db);') > source.index('if(!round_startup(db))'):
                    raise RuntimeError('legacy Stratum registration order changed')
                report['checks']['guard_precedes_registration'] = 'PASS'
                report['checks']['missing_and_altered_cases'] = len(report['cases'])
            finally:
                if server.poll() is None:
                    server.terminate()
                    try:
                        server.wait(timeout=15)
                    except subprocess.TimeoutExpired:
                        server.kill()
                        server.wait(timeout=10)
    return report


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--mariadb-bin', type=Path, required=True)
    parser.add_argument('--json-out', type=Path)
    args = parser.parse_args()
    if not all((args.mariadb_bin/name).is_file() for name in
               ('mariadbd', 'mariadb', 'mariadb-install-db')):
        output = {'task': 'POOL-L3.199G', 'status': 'PARTIAL',
                  'reason': 'MariaDB 10.11 binaries unavailable'}
    else:
        try:
            output = main(args.mariadb_bin)
            output['status'] = 'PASS'
        except Exception as error:
            output = {'task': 'POOL-L3.199G', 'status': 'FAIL', 'reason': str(error)}
    rendered = json.dumps(output, indent=2, sort_keys=True)
    print(rendered)
    if args.json_out:
        args.json_out.write_text(rendered+'\n')
    sys.exit(0 if output['status'] == 'PASS' else 2)
