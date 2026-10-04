#!/usr/bin/env python3
"""Offline L3.194 stop-condition probe; never connects to a daemon or database.

Usage: python3 tests/durable_round_daemon_recovery_preflight.py --badcoin-source
       /path/to/Badcoin --compiler g++

This compiles the response-selection tail extracted from the supplied daemon
source. It tests that code, not a proposed accounting implementation. Returning
2 means the reproductions passed but the implementation preflight is BLOCKED.
Returning 1 means the probe failed or its source assumptions need review.

An unresolved durable intent can safely remain HOLD. These probes establish why
RPC replay alone is insufficient evidence to *resolve* every such intent: the
current response does not necessarily describe the original submission outcome.
Do not merge on duplicate-invalid or seal on duplicate without stronger evidence.
"""

import argparse
import pathlib
import re
import subprocess
import tempfile


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def probe(root, compiler):
    mining = (root / 'src/rpc/mining.cpp').read_text()
    validation = (root / 'src/validation.cpp').read_text()
    blockchain = (root / 'src/rpc/blockchain.cpp').read_text()
    submit = mining.split('UniValue submitblock(const JSONRPCRequest& request)', 1)[1]
    submit = submit.split('UniValue estimatefee', 1)[0]
    match = re.search(
        r'UnregisterValidationInterface\(&sc\);\s*(if \(fBlockPresent\).*?'
        r'return BIP22ValidationResult\(sc.state\);)', submit, re.S)
    require(match is not None, 'submitblock response-selection tail changed; inspect it')
    # Stub only external types. The branching logic below comes verbatim from
    # mining.cpp; the invalid/valid states are injected at its ProcessNewBlock
    # boundary. This is a control-flow probe, not a full daemon integration test.
    cpp = r'''
#include <cassert>
#include <iostream>
#include <string>
struct Catcher { bool found; bool state; };
std::string BIP22ValidationResult(bool valid) {
    return valid ? "null" : "rejected";
}
std::string response(bool fBlockPresent, bool fAccepted, Catcher sc) {
''' + match.group(1) + r'''
}
int main() {
    assert(response(false, true, {true, true}) == "null");
    std::cout << "PASS initial accepted response\n";
    assert(response(false, false, {true, false}) == "rejected");
    std::cout << "PASS initial rejected response\n";
    assert(response(true, true, {true, true}) == "duplicate");
    assert(response(true, false, {true, false}) == "duplicate");
    std::cout << "PASS duplicate aliases accepted and failed processing\n";
    assert(response(true, true, {false, false}) == "duplicate-inconclusive");
    assert(response(false, true, {false, false}) == "inconclusive");
    std::cout << "PASS inconclusive responses remain distinguishable\n";
}
'''
    with tempfile.TemporaryDirectory(prefix='badpool-round-preflight-') as directory:
        source = pathlib.Path(directory) / 'probe.cpp'
        binary = pathlib.Path(directory) / 'probe'
        source.write_text(cpp)
        subprocess.run([compiler, '-std=c++17', '-Wall', '-Wextra', '-Werror',
                        str(source), '-o', str(binary)], check=True)
        subprocess.run([str(binary)], check=True)

    require(re.search(r'if \(pindex->nStatus & BLOCK_FAILED_MASK\)\s*\{?\s*'
                      r'return "duplicate-invalid";', submit),
            'failed-index replay handling changed; inspect it')
    invalidate = validation.split('bool CChainState::InvalidateBlock(', 1)[1]
    invalidate = invalidate.split('bool InvalidateBlock(', 1)[0]
    require('while (chainActive.Contains(pindex))' in invalidate
            and 'pindex->nStatus |= BLOCK_FAILED_VALID;' in invalidate,
            'active-chain invalidation semantics changed; inspect it')
    require('InvalidateBlock(state, Params(), pblockindex);' in blockchain,
            'invalidateblock RPC handling changed; inspect it')
    print('PASS previously active block can acquire failed flags through invalidateblock')
    print('PASS failed-index replay returns duplicate-invalid without original-outcome history')

    # Current confirmations describe current membership, not prior acceptance.
    require(re.search(r'int confirmations = -1;.*?if \(chainActive.Contains\(blockindex\)\)'
                      r'\s*confirmations = chainActive.Height\(\) - blockindex->nHeight \+ 1;',
                      blockchain, re.S), 'confirmation semantics changed; inspect it')
    print('PASS negative confirmations do not encode historical acceptance')
    print('PRECHECK=BLOCKED: terminal replay resolution needs original-outcome evidence; '
          'HOLD is safe but is not terminal recovery')
    return 2


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--badcoin-source', type=pathlib.Path, required=True)
    parser.add_argument('--compiler', default='g++')
    args = parser.parse_args()
    try:
        raise SystemExit(probe(args.badcoin_source.resolve(), args.compiler))
    except (OSError, RuntimeError, subprocess.CalledProcessError, IndexError) as error:
        print('PROBE=FAIL:', error)
        raise SystemExit(1)
