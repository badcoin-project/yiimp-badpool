#pragma once

// Version 2 is opt-in and applies only to explicitly configured Badcoin lanes.
extern bool g_durable_rounds;
bool round_startup(YAAMP_DB *);
struct ROUND_RECEIPT {
 unsigned long long work_id, round_id, sequence, intent_id;
 bool duplicate; double assigned_weight;
};
bool round_journal(YAAMP_CLIENT *, YAAMP_JOB *, YAAMP_JOB_VALUES *, ROUND_RECEIPT *);
bool round_prepare(YAAMP_CLIENT *, YAAMP_JOB *, YAAMP_JOB_VALUES *, ROUND_RECEIPT *,
 const char *block_hex, double block_difficulty, double winning_difficulty);
bool round_dispatch(YAAMP_COIND *, const ROUND_RECEIPT *, const char *block_hex);
// Only a durable NEVER_DISPATCHED intent is eligible for this first-attempt path.
bool round_resume_never_dispatched(YAAMP_DB *, YAAMP_COIND *);
bool round_enabled(YAAMP_JOB *);
// Accounting recovery only: never sends a candidate or invokes any daemon RPC.
bool round_recover(YAAMP_DB *);
