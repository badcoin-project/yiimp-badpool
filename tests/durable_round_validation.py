#!/usr/bin/env python3
"""Run existing PHP guardrail/attribution harnesses without relying on shell line endings."""
import pathlib,subprocess
root=pathlib.Path(__file__).resolve().parent.parent
files=sorted(set((root/'tests').glob('badpool_*_harness.php'))|set((root/'tests').glob('live_*_harness.php'))|
             {root/'tests/yaamp_fee_console_harness.php',root/'tests/durable_round_downstream_harness.php'})
failed=[]
for file in files:
    result=subprocess.run(['php',str(file)],cwd=root,text=True,capture_output=True)
    print(('PASS' if result.returncode==0 else 'FAIL')+' '+file.name,flush=True)
    if result.returncode:
        failed.append(file.name);print(result.stdout+result.stderr,flush=True)
for file in list((root/'web/yaamp/core/backend').glob('BadpoolRound*.php'))+[
    root/'web/yaamp/core/backend/BadpoolLiveBlockAccounting.php',root/'web/yaamp/core/backend/BadpoolLiveBlockMaturity.php',
    root/'web/yaamp/core/backend/BadpoolLiveCaptureEarningsBridge.php',root/'web/yaamp/core/backend/BadpoolPaymentBatchPhaseAdapter.php',
    root/'web/yaamp/core/backend/blocks.php',root/'tools/badpool-round-status.php',root/'tools/badpool-round-adjudicate.php']:
    result=subprocess.run(['php','-l',str(file)],text=True,capture_output=True)
    if result.returncode:failed.append(file.name);print(result.stdout+result.stderr)
print('HARNESS_TOTAL='+str(len(files))+' FAILURES='+str(len(failed)))
raise SystemExit(bool(failed))
