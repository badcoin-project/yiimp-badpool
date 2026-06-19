#!/usr/bin/env python3
"""Replay a captured SHA256D stratum submit through the pool reconstruction path.

This is an offline diagnostic harness. It performs no RPC, DB, wallet, or stratum
network activity. The transformations intentionally mirror the SHA256 path in
stratum/client_submit.cpp and the target/difficulty helpers in stratum/util.cpp.
"""

import argparse
import hashlib
import json
import sys
from typing import Any, Dict, Iterable, List

UINT64_MASK = (1 << 64) - 1
BASE_TARGET64 = 0x0000FFFF00000000


def require_hex(name: str, value: str, even: bool = True, length: int | None = None) -> str:
    if not isinstance(value, str):
        raise ValueError(f"{name} must be a hex string")
    value = value.strip().lower()
    if even and len(value) % 2:
        raise ValueError(f"{name} must have even hex length")
    if length is not None and len(value) != length:
        raise ValueError(f"{name} must be {length} hex chars, got {len(value)}")
    try:
        bytes.fromhex(value)
    except ValueError as exc:
        raise ValueError(f"{name} is not valid hex: {exc}") from exc
    return value


def sha256d(data: bytes) -> bytes:
    return hashlib.sha256(hashlib.sha256(data).digest()).digest()


def ser_string_be_words(hex_string: str, words: int) -> str:
    """Mirror ser_string_be(): reverse byte order inside each 4-byte word."""
    if len(hex_string) < words * 8:
        raise ValueError(f"need at least {words * 8} hex chars for {words} words")
    out: List[str] = []
    for i in range(words):
        word = hex_string[i * 8:(i + 1) * 8]
        out.append("".join(word[j:j + 2] for j in range(6, -1, -2)))
    return "".join(out)


def reverse_hex_bytes(hex_string: str) -> str:
    return bytes.fromhex(hex_string)[::-1].hex()


def merkle_with_first(first_hash_hex: str, branches: Iterable[str]) -> str:
    current = require_hex("coinbase_hash/merkle_first", first_hash_hex, length=64)
    for idx, branch in enumerate(branches):
        branch_hex = require_hex(f"merkle_branches[{idx}]", branch, length=64)
        current = sha256d(bytes.fromhex(current + branch_hex)).hex()
    return current


def get_hash_difficulty(hash_bytes: bytes) -> int:
    """Mirror get_hash_difficulty(): use bytes 22..29 in pool order."""
    if len(hash_bytes) != 32:
        raise ValueError("hash must be exactly 32 bytes")
    p = hash_bytes
    return ((p[29] << 56) | (p[28] << 48) | (p[27] << 40) | (p[26] << 32) |
            (p[25] << 24) | (p[24] << 16) | (p[23] << 8) | p[22])


def diff_to_target(difficulty: float, diff_multiplier: float = 1.0) -> int:
    if difficulty == 0:
        return 0
    return int(BASE_TARGET64 * diff_multiplier / difficulty) & UINT64_MASK


def target_to_diff(target: int) -> float:
    if target == 0:
        return 0.0
    return float(BASE_TARGET64) / float(target)


def decode_compact(nbits: str) -> int:
    c = int(require_hex("nbits", nbits, length=8), 16)
    nshift = (c >> 24) & 0xff
    mantissa = c & 0x00ffffff
    if mantissa == 0:
        return 0
    d = float(0x0000ffff) / float(mantissa)
    while nshift < 29:
        d *= 256.0
        nshift += 1
    while nshift > 29:
        d /= 256.0
        nshift -= 1
    return int(BASE_TARGET64 / d) & UINT64_MASK


def load_fixture(path: str) -> Dict[str, Any]:
    with open(path, "r", encoding="utf-8") as handle:
        data = json.load(handle)
    if not isinstance(data, dict):
        raise ValueError("fixture root must be an object")
    return data


def fixture_value(data: Dict[str, Any], key: str, default: Any = None) -> Any:
    if key in data:
        return data[key]
    if default is not None:
        return default
    raise ValueError(f"missing required fixture key: {key}")


def print_kv(key: str, value: Any) -> None:
    print(f"{key}={value}")


def replay(data: Dict[str, Any]) -> int:
    algo = str(fixture_value(data, "algo", "sha256")).lower()
    if algo not in ("sha256", "sha256d"):
        raise ValueError("this harness is for sha256/sha256d fixtures only")

    job_id = str(fixture_value(data, "job_id", "-"))
    coinb1 = require_hex("coinb1", fixture_value(data, "coinb1"))
    coinb2 = require_hex("coinb2", fixture_value(data, "coinb2"))
    extranonce1 = require_hex("extranonce1", fixture_value(data, "extranonce1"))
    extranonce2 = require_hex("extranonce2", fixture_value(data, "extranonce2"))
    version = require_hex("version", fixture_value(data, "version"), length=8)
    prevhash = require_hex("prevhash", fixture_value(data, "prevhash"), length=64)
    nbits = require_hex("nbits", fixture_value(data, "nbits"), length=8)
    ntime = require_hex("ntime", fixture_value(data, "ntime"), length=8)
    nonce = require_hex("nonce", fixture_value(data, "nonce"), length=8)
    branches = fixture_value(data, "merkle_branches", [])
    if not isinstance(branches, list):
        raise ValueError("merkle_branches must be an array")
    difficulty_actual = float(fixture_value(data, "difficulty_actual"))
    diff_multiplier = float(fixture_value(data, "diff_multiplier", 1.0))

    expected_submit = data.get("expected_submit_tuple") or {}
    if expected_submit and not isinstance(expected_submit, dict):
        raise ValueError("expected_submit_tuple must be an object when present")

    coinbase_hex = coinb1 + extranonce1 + extranonce2 + coinb2
    coinbase_hash = sha256d(bytes.fromhex(coinbase_hex)).hex()
    merkle_internal = merkle_with_first(coinbase_hash, branches)
    merkle_be = ser_string_be_words(merkle_internal, 8)
    prevhash_be = ser_string_be_words(prevhash, 8)
    header_pre_ser = version + prevhash_be + merkle_be + ntime + nbits + nonce
    header_hex = ser_string_be_words(header_pre_ser, 20)
    header_bytes = bytes.fromhex(header_hex)
    if len(header_bytes) != 80:
        raise ValueError(f"serialized header must be 80 bytes, got {len(header_bytes)}")

    sha256d_hash = sha256d(header_bytes)
    sha256d_hash_hex = sha256d_hash.hex()
    sha256d_hash_display = reverse_hex_bytes(sha256d_hash_hex)
    hash_int = get_hash_difficulty(sha256d_hash)
    user_target = diff_to_target(difficulty_actual, diff_multiplier)
    coin_target = decode_compact(nbits)
    if nbits and coin_target == 0:
        coin_target = 0xFFFF000000000000
    share_diff = target_to_diff(hash_int)
    hash_le_user = hash_int <= user_target
    hash_le_coin = hash_int <= coin_target
    low_difficulty = hash_int > user_target and hash_int > coin_target
    final_decision = "LOW_DIFFICULTY_REJECT" if low_difficulty else "ACCEPT_OR_SUBMIT_CANDIDATE"

    print_kv("replay.algo", algo)
    print_kv("replay.job_id", job_id)
    print_kv("replay.expected_submit_tuple_match", {
        "job_id": str(expected_submit.get("job_id", job_id)) == job_id,
        "extranonce2": str(expected_submit.get("extranonce2", extranonce2)).lower() == extranonce2,
        "ntime": str(expected_submit.get("ntime", ntime)).lower() == ntime,
        "nonce": str(expected_submit.get("nonce", nonce)).lower() == nonce,
    } if expected_submit else "not_provided")
    print_kv("pool.coinbase_hex", coinbase_hex)
    print_kv("pool.coinbase_hash_sha256d", coinbase_hash)
    print_kv("pool.merkle_root_internal", merkle_internal)
    print_kv("pool.merkle_root_be_words", merkle_be)
    print_kv("pool.merkle_root_display_le", reverse_hex_bytes(merkle_internal))
    print_kv("pool.prevhash_input", prevhash)
    print_kv("pool.prevhash_be_words", prevhash_be)
    print_kv("pool.version", version)
    print_kv("pool.nbits", nbits)
    print_kv("pool.ntime", ntime)
    print_kv("pool.nonce", nonce)
    print_kv("pool.header_pre_serialization", header_pre_ser)
    print_kv("pool.header_hex_80_bytes", header_hex)
    print_kv("reference.header_sha256d_hex", sha256d_hash_hex)
    print_kv("reference.header_sha256d_display", sha256d_hash_display)
    print_kv("pool.hash_int", f"{hash_int:016x}")
    print_kv("pool.user_target", f"{user_target:016x}")
    print_kv("pool.coin_target", f"{coin_target:016x}")
    print_kv("pool.share_diff_from_hash_int", f"{share_diff:.12g}")
    print_kv("pool.hash_lte_user_target", str(hash_le_user).lower())
    print_kv("pool.hash_lte_coin_target", str(hash_le_coin).lower())
    print_kv("pool.final_low_difficulty", str(low_difficulty).lower())
    print_kv("pool.final_decision", final_decision)
    print_kv("diagnostic.next_step", "compare pool.header_hex_80_bytes and reference.header_sha256d_display with miner-side captured header/hash")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="Replay SHA256D stratum submit reconstruction and target checks")
    parser.add_argument("fixture", help="JSON fixture with redacted stratum job and submit fields")
    args = parser.parse_args()
    try:
        return replay(load_fixture(args.fixture))
    except Exception as exc:  # intentionally top-level CLI error only
        print(f"error={exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
