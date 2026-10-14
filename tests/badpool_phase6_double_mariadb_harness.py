#!/usr/bin/env python3
"""Exercise the production payout debit predicate against an isolated MariaDB DOUBLE column.

Run on Linux with mariadbd, mariadb-install-db, and mariadb installed. The server
uses a fresh temporary datadir and Unix socket with networking disabled.
"""

import os
import pathlib
import pwd
import re
import shutil
import subprocess
import tempfile
import time


ROOT = pathlib.Path(__file__).resolve().parent.parent
COMMAND = ROOT / "web/yaamp/commands/BadpoolGuardCommand.php"
SOURCE = COMMAND.read_text()
match = re.search(
    r"createCommand\('(?P<sql>UPDATE accounts SET balance=:new_balance WHERE id=:id "
    r"AND coinid=:coinid AND balance=:old_balance AND CAST\(balance AS CHAR\)=:old_balance_text)'\)",
    SOURCE,
)
assert match, "production guarded payout UPDATE was changed or removed"
guarded_update = match.group("sql")
bindings = re.findall(r":([a-z_]+)", guarded_update)
assert bindings == ["new_balance", "id", "coinid", "old_balance", "old_balance_text"]
prepared_update = re.sub(r":[a-z_]+", "?", guarded_update)

for executable in ("mariadbd", "mariadb-install-db", "mariadb"):
    assert shutil.which(executable), f"required local MariaDB executable missing: {executable}"


def checked(command, **kwargs):
    result = subprocess.run(command, text=True, capture_output=True, timeout=60, **kwargs)
    assert result.returncode == 0, f"{' '.join(command)} failed:\n{result.stdout}\n{result.stderr}"
    return result.stdout.strip()


with tempfile.TemporaryDirectory(prefix="badpool-phase6-mariadb-") as temporary:
    sandbox = pathlib.Path(temporary).resolve()
    assert sandbox.parent == pathlib.Path(tempfile.gettempdir()).resolve()
    datadir = sandbox / "data"
    socket = sandbox / "mysql.sock"
    pidfile = sandbox / "mariadb.pid"
    logfile = sandbox / "mariadb.log"
    checked(["mariadb-install-db", "--no-defaults", f"--datadir={datadir}", "--auth-root-authentication-method=normal"])
    server = subprocess.Popen(
        [
            "mariadbd", "--no-defaults", f"--datadir={datadir}", f"--socket={socket}",
            f"--pid-file={pidfile}", f"--log-error={logfile}", "--skip-networking",
            f"--user={pwd.getpwuid(os.getuid()).pw_name}",
        ],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    client = ["mariadb", "--no-defaults", "--protocol=socket", f"--socket={socket}", "-uroot", "--batch", "--skip-column-names"]

    def sql(statement, database=None):
        args = client + ([f"--database={database}"] if database else [])
        return checked(args, input=statement)

    try:
        for _ in range(100):
            if server.poll() is not None:
                raise AssertionError(f"isolated MariaDB exited early: {logfile.read_text()}")
            if socket.exists():
                try:
                    sql("SELECT 1")
                    break
                except AssertionError:
                    pass
            time.sleep(0.1)
        else:
            raise AssertionError(f"isolated MariaDB did not start: {logfile.read_text()}")

        datadir_text, networking, version = sql("SELECT @@datadir, @@skip_networking, @@version").split("\t")
        assert pathlib.Path(datadir_text).resolve() == datadir.resolve(), "refuse non-test datadir"
        assert networking == "1" and "MariaDB" in version, "refuse non-isolated or non-MariaDB server"

        sql("CREATE DATABASE phase6_precision_test")
        database = "phase6_precision_test"
        sql(
            "CREATE TABLE accounts (id INT PRIMARY KEY, coinid INT NOT NULL, balance DOUBLE NOT NULL) ENGINE=InnoDB;"
            "CREATE TABLE payouts (id INT AUTO_INCREMENT PRIMARY KEY, account_id INT NOT NULL, idcoin INT NOT NULL,"
            " amount DOUBLE NOT NULL, completed TINYINT NOT NULL, tx VARCHAR(128) NULL) ENGINE=InnoDB;"
            "INSERT INTO accounts VALUES (79,1267,4541.6627178399995),(80,1267,4541.6627178399995);",
            database,
        )
        original = sql("SELECT CAST(balance AS CHAR) FROM accounts WHERE id=79", database)
        assert original == "4541.6627178399995", f"unexpected MariaDB DOUBLE text: {original}"

        def debit(account_id, approved_balance, commit):
            # The statement text comes from BadpoolGuardCommand.php; only named
            # placeholders are converted to MariaDB PREPARE positional bindings.
            decision = "COMMIT" if commit else "ROLLBACK"
            output = sql(
                "START TRANSACTION;"
                f"INSERT INTO payouts (account_id,idcoin,amount,completed,tx) VALUES ({account_id},1267,4541.66271784,0,NULL);"
                f"SET @guarded_sql='{prepared_update}';PREPARE guarded FROM @guarded_sql;"
                f"SET @new_balance='0',@id={account_id},@coinid=1267,"
                f"@old_balance='{approved_balance}',@old_balance_text='{approved_balance}';"
                "EXECUTE guarded USING @new_balance,@id,@coinid,@old_balance,@old_balance_text;"
                "SELECT CONCAT('affected=',ROW_COUNT());"
                f"SELECT CONCAT('inside_payouts=',COUNT(*)) FROM payouts WHERE account_id={account_id};"
                f"{decision};DEALLOCATE PREPARE guarded;",
                database,
            ).splitlines()
            return dict(line.split("=", 1) for line in output if "=" in line)

        unchanged = debit(79, original, True)
        assert unchanged == {"affected": "1", "inside_payouts": "1"}, unchanged
        assert sql("SELECT CAST(balance AS CHAR),COUNT(*) FROM accounts A JOIN payouts P ON P.account_id=A.id WHERE A.id=79", database) == "0\t1"

        # A separate connection changes account 80 after approval, before the
        # guarded debit. The later INSERT and failed UPDATE share one transaction.
        sql("UPDATE accounts SET balance=balance+0.00000001 WHERE id=80", database)
        changed_text = sql("SELECT CAST(balance AS CHAR) FROM accounts WHERE id=80", database)
        assert changed_text != original, "concurrent fixture balance did not change"
        changed = debit(80, original, False)
        assert changed == {"affected": "0", "inside_payouts": "1"}, changed
        assert sql("SELECT COUNT(*) FROM payouts WHERE account_id=80", database) == "0", "failed debit did not roll back payout insert"
        assert sql("SELECT CAST(balance AS CHAR) FROM accounts WHERE id=80", database) == changed_text
        print(f"PASS MariaDB {version}: unchanged DOUBLE affected=1; changed DOUBLE affected=0; payout INSERT rolled back")
    finally:
        server.terminate()
        try:
            server.wait(timeout=15)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait(timeout=15)
