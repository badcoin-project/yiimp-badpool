#include "stratum.h"
#include <atomic>

extern bool g_debuglog_block_path;
extern bool g_debuglog_block_path_verbose;

#define BLOCK_PATH_SCHEMA "badpool.stratum.blockpath.v1"

struct BLOCK_PATH_CONTEXT
{
	char trace_id[64];
	char algo[64];
	int coin_id;
	int height;
	int jobid;
	int userid;
	int workerid;
	char nbits[17];
	char target_hex[65];
	char hash_be[65];
	uint64_t hash_int_legacy;
	uint64_t coin_target_legacy;
	bool full_block_target;
	char submit_method[32];
};

static std::atomic<unsigned long long> block_path_trace_sequence(0);
static std::atomic<unsigned long long> block_path_shares_accepted(0);
static std::atomic<unsigned long long> block_path_shares_rejected(0);
static std::atomic<unsigned long long> block_path_target_checks(0);
static std::atomic<unsigned long long> block_path_target_false(0);
static std::atomic<unsigned long long> block_path_candidates(0);
static std::atomic<unsigned long long> block_path_submit_begins(0);
static std::atomic<unsigned long long> block_path_submit_returns(0);
static std::atomic<unsigned long long> block_path_submit_accepts(0);
static std::atomic<unsigned long long> block_path_submit_rejects(0);
static std::atomic<unsigned long long> block_path_submit_no_answer(0);
static std::atomic<unsigned long long> block_path_block_add_calls(0);
static std::atomic<long long> block_path_last_summary(0);
static std::atomic<int> block_path_last_coin_id(0);
static std::atomic<int> block_path_last_height(0);

static const char *block_path_nonempty(const char *value)
{
	return value && value[0]? value: "-";
}

static const char *block_path_submit_method(YAAMP_COIND *coind)
{
	if(!coind) return "unknown";
	if(coind->usegetwork) return "getwork";
	if(coind->hassubmitblock) return "submitblock";
	return "submitblocktemplate";
}

static void block_path_context_init(BLOCK_PATH_CONTEXT *ctx, YAAMP_CLIENT *client, YAAMP_JOB *job,
	YAAMP_JOB_VALUES *submitvalues, uint64_t hash_int, uint64_t coin_target,
	bool full_block_target, const char *target_hex)
{
	memset(ctx, 0, sizeof(*ctx));
	unsigned long long sequence = block_path_trace_sequence.fetch_add(1) + 1;
	snprintf(ctx->trace_id, sizeof(ctx->trace_id), "%d-%llu", (int)getpid(), sequence);
	snprintf(ctx->algo, sizeof(ctx->algo), "%s", g_current_algo? g_current_algo->name: g_stratum_algo);
	ctx->coin_id = job && job->coind? job->coind->id: 0;
	ctx->height = job && job->templ? job->templ->height: 0;
	ctx->jobid = job? job->id: 0;
	ctx->userid = client? client->userid: 0;
	ctx->workerid = client? client->workerid: 0;
	snprintf(ctx->nbits, sizeof(ctx->nbits), "%s",
		(job && job->templ && job->templ->nbits[0])? job->templ->nbits: "-");
	snprintf(ctx->target_hex, sizeof(ctx->target_hex), "%s", target_hex && target_hex[0]? target_hex: "-");
	snprintf(ctx->hash_be, sizeof(ctx->hash_be), "%s",
		submitvalues && submitvalues->hash_be[0]? submitvalues->hash_be: "-");
	ctx->hash_int_legacy = hash_int;
	ctx->coin_target_legacy = coin_target;
	ctx->full_block_target = full_block_target;
	snprintf(ctx->submit_method, sizeof(ctx->submit_method), "%s",
		block_path_submit_method(job? job->coind: NULL));
}

static void block_path_record_share(bool accepted)
{
	if(accepted) block_path_shares_accepted.fetch_add(1);
	else block_path_shares_rejected.fetch_add(1);
}

static void block_path_record_target(const BLOCK_PATH_CONTEXT *ctx)
{
	block_path_target_checks.fetch_add(1);
	if(ctx->full_block_target) block_path_candidates.fetch_add(1);
	else block_path_target_false.fetch_add(1);
	block_path_last_coin_id.store(ctx->coin_id);
	block_path_last_height.store(ctx->height);
}

static void block_path_log_target(const BLOCK_PATH_CONTEXT *ctx)
{
	if(!ctx->full_block_target && !g_debuglog_block_path) return;
	const char *format =
		"STRATUM_BLOCK_TARGET_DIAG schema=%s trace_id=%s algo=%s coin_id=%d height=%d jobid=%x "
		"userid=%d workerid=%d nbits=%s target_compare=nbits_256 target_hex=%s hash_be=%s "
		"hash_int_legacy=%016llx coin_target_legacy=%016llx full_block_target=%d\n";
	if(ctx->full_block_target)
		stratumlog(format, BLOCK_PATH_SCHEMA, ctx->trace_id, ctx->algo, ctx->coin_id, ctx->height, ctx->jobid,
			ctx->userid, ctx->workerid, ctx->nbits, ctx->target_hex, ctx->hash_be,
			(unsigned long long)ctx->hash_int_legacy,
			(unsigned long long)ctx->coin_target_legacy, 1);
	else
		debuglog(format, BLOCK_PATH_SCHEMA, ctx->trace_id, ctx->algo, ctx->coin_id, ctx->height, ctx->jobid,
			ctx->userid, ctx->workerid, ctx->nbits, ctx->target_hex, ctx->hash_be,
			(unsigned long long)ctx->hash_int_legacy,
			(unsigned long long)ctx->coin_target_legacy, 0);
}

static void block_path_log_candidate(const BLOCK_PATH_CONTEXT *ctx)
{
	stratumlog(
		"STRATUM_BLOCK_CANDIDATE_DETECTED schema=%s trace_id=%s algo=%s coin_id=%d height=%d jobid=%x "
		"userid=%d workerid=%d nbits=%s target_compare=nbits_256 target_hex=%s hash_be=%s "
		"hash_int_legacy=%016llx coin_target_legacy=%016llx full_block_target=1 block_hex_len=0 "
		"block_hex_state=not_built submit_method=%s\n",
		BLOCK_PATH_SCHEMA, ctx->trace_id, ctx->algo, ctx->coin_id, ctx->height, ctx->jobid,
		ctx->userid, ctx->workerid, ctx->nbits, ctx->target_hex, ctx->hash_be,
		(unsigned long long)ctx->hash_int_legacy,
		(unsigned long long)ctx->coin_target_legacy, ctx->submit_method);
}

static void block_path_log_submit_begin(const BLOCK_PATH_CONTEXT *ctx, size_t block_hex_len)
{
	block_path_submit_begins.fetch_add(1);
	stratumlog(
		"STRATUM_COIND_SUBMIT_BEGIN schema=%s trace_id=%s algo=%s coin_id=%d height=%d jobid=%x "
		"userid=%d workerid=%d nbits=%s target_compare=nbits_256 target_hex=%s hash_be=%s "
		"full_block_target=1 block_hex_len=%zu submit_method=%s rpc_returned=pending accepted=pending\n",
		BLOCK_PATH_SCHEMA, ctx->trace_id, ctx->algo, ctx->coin_id, ctx->height, ctx->jobid,
		ctx->userid, ctx->workerid, ctx->nbits, ctx->target_hex, ctx->hash_be,
		block_hex_len, ctx->submit_method);
}

static void block_path_log_submit_returned(const BLOCK_PATH_CONTEXT *ctx, size_t block_hex_len,
	const STRATUM_COIND_SUBMIT_OBSERVATION *observation)
{
	block_path_submit_returns.fetch_add(1);
	if(observation && observation->accepted) block_path_submit_accepts.fetch_add(1);
	else block_path_submit_rejects.fetch_add(1);
	if(!observation || !observation->rpc_returned) block_path_submit_no_answer.fetch_add(1);

	stratumlog(
		"STRATUM_COIND_SUBMIT_RETURNED schema=%s trace_id=%s algo=%s coin_id=%d height=%d jobid=%x "
		"userid=%d workerid=%d nbits=%s target_compare=nbits_256 target_hex=%s hash_be=%s "
		"full_block_target=1 block_hex_len=%zu submit_method=%s rpc_returned=%d accepted=%d "
		"result_type=%s result_value=%s error_type=%s error_code=%s error_message=%s elapsed_us=%llu\n",
		BLOCK_PATH_SCHEMA, ctx->trace_id, ctx->algo, ctx->coin_id, ctx->height, ctx->jobid,
		ctx->userid, ctx->workerid, ctx->nbits, ctx->target_hex, ctx->hash_be,
		block_hex_len, observation? block_path_nonempty(observation->method): ctx->submit_method,
		(observation && observation->rpc_returned)? 1: 0,
		(observation && observation->accepted)? 1: 0,
		observation? block_path_nonempty(observation->result_type): "missing",
		observation? block_path_nonempty(observation->result_value): "-",
		observation? block_path_nonempty(observation->error_type): "missing",
		observation? block_path_nonempty(observation->error_code): "-",
		observation? block_path_nonempty(observation->error_message): "-",
		(unsigned long long)(observation? observation->elapsed_us: 0));
}

static void block_path_log_block_add_begin(const BLOCK_PATH_CONTEXT *ctx)
{
	block_path_block_add_calls.fetch_add(1);
	stratumlog(
		"STRATUM_BLOCK_ADD_BEGIN schema=%s trace_id=%s algo=%s coin_id=%d height=%d jobid=%x "
		"userid=%d workerid=%d queue_state=append_pending db_write_performed=false\n",
		BLOCK_PATH_SCHEMA, ctx->trace_id, ctx->algo, ctx->coin_id, ctx->height, ctx->jobid,
		ctx->userid, ctx->workerid);
}

static void block_path_log_block_add_returned(const BLOCK_PATH_CONTEXT *ctx)
{
	stratumlog(
		"STRATUM_BLOCK_ADD_RETURNED schema=%s trace_id=%s algo=%s coin_id=%d height=%d jobid=%x "
		"userid=%d workerid=%d queue_state=appended_in_memory db_write_performed=false\n",
		BLOCK_PATH_SCHEMA, ctx->trace_id, ctx->algo, ctx->coin_id, ctx->height, ctx->jobid,
		ctx->userid, ctx->workerid);
}

void block_path_maybe_log_summary()
{
	long long now = (long long)time(NULL);
	long long previous = block_path_last_summary.load();
	if(previous == 0)
	{
		block_path_last_summary.compare_exchange_strong(previous, now);
		return;
	}
	if(now - previous < 60) return;
	if(!block_path_last_summary.compare_exchange_strong(previous, now)) return;

	unsigned long long shares_accepted = block_path_shares_accepted.exchange(0);
	unsigned long long shares_rejected = block_path_shares_rejected.exchange(0);
	unsigned long long target_checks = block_path_target_checks.exchange(0);
	unsigned long long target_false = block_path_target_false.exchange(0);
	unsigned long long candidates = block_path_candidates.exchange(0);
	unsigned long long submit_begins = block_path_submit_begins.exchange(0);
	unsigned long long submit_returns = block_path_submit_returns.exchange(0);
	unsigned long long submit_accepts = block_path_submit_accepts.exchange(0);
	unsigned long long submit_rejects = block_path_submit_rejects.exchange(0);
	unsigned long long submit_no_answer = block_path_submit_no_answer.exchange(0);
	unsigned long long block_add_calls = block_path_block_add_calls.exchange(0);

	if(!(shares_accepted || shares_rejected || target_checks || candidates ||
		submit_begins || submit_returns || block_add_calls)) return;
	stratumlog(
		"STRATUM_BLOCK_PATH_SUMMARY schema=%s algo=%s coin_id=%d height=%d interval_seconds=%lld "
		"shares_accepted=%llu shares_rejected=%llu block_target_checks=%llu block_target_false=%llu "
		"candidates_detected=%llu submit_begins=%llu submit_returns=%llu submit_accepts=%llu "
		"submit_rejects=%llu submit_no_answer=%llu block_add_calls=%llu\n",
		BLOCK_PATH_SCHEMA, g_current_algo? g_current_algo->name: g_stratum_algo,
		block_path_last_coin_id.load(), block_path_last_height.load(), now - previous,
		shares_accepted, shares_rejected, target_checks, target_false, candidates,
		submit_begins, submit_returns, submit_accepts, submit_rejects,
		submit_no_answer, block_add_calls);
}

// --- YESCRYPT DEBUG HELPER ---
static void debug_hex(const char* label, const unsigned char* data, int len)
{
    char buf[512];
    for (int i = 0; i < len; i++)
        sprintf(buf + i*2, "%02x", data[i]);
    buf[len*2] = 0;
    debuglog("%s: %s", label, buf);
}


uint64_t lyra2z_height = 0;

//#define MERKLE_DEBUGLOG
//#define DONTSUBMIT

void build_submit_values(YAAMP_JOB_VALUES *submitvalues, YAAMP_JOB_TEMPLATE *templ,
	const char *nonce1, const char *nonce2, const char *ntime, const char *nonce, const char *version_override = NULL)
{
	const char *version = version_override? version_override: templ->version;

	sprintf(submitvalues->coinbase, "%s%s%s%s", templ->coinb1, nonce1, nonce2, templ->coinb2);
	int coinbase_len = strlen(submitvalues->coinbase);

	unsigned char coinbase_bin[1024];
	memset(coinbase_bin, 0, 1024);
	binlify(coinbase_bin, submitvalues->coinbase);

	char doublehash[128];
	memset(doublehash, 0, 128);

	// some (old) wallet/algos need a simple SHA256 (blakecoin, whirlcoin, groestlcoin...)
	YAAMP_HASH_FUNCTION merkle_hash = sha256_double_hash_hex;
	if (g_current_algo->merkle_func)
		merkle_hash = g_current_algo->merkle_func;
	merkle_hash((char *)coinbase_bin, doublehash, coinbase_len/2);

	string merkleroot = merkle_with_first(templ->txsteps, doublehash);
	ser_string_be(merkleroot.c_str(), submitvalues->merkleroot_be, 8);

#ifdef MERKLE_DEBUGLOG
	printf("merkle root %s\n", merkleroot.c_str());
#endif
	if (!strcmp(g_stratum_algo, "lbry")) {
		sprintf(submitvalues->header, "%s%s%s%s%s%s%s", version, templ->prevhash_be, submitvalues->merkleroot_be,
			templ->claim_be, ntime, templ->nbits, nonce);
		ser_string_be(submitvalues->header, submitvalues->header_be, 112/4);
	} else if (strlen(templ->extradata_be) == 128) { // LUX SC
		sprintf(submitvalues->header, "%s%s%s%s%s%s%s", version, templ->prevhash_be, submitvalues->merkleroot_be,
			ntime, templ->nbits, nonce, templ->extradata_be);
		ser_string_be(submitvalues->header, submitvalues->header_be, 36); // 80+64 / sizeof(u32)
	} else {
		sprintf(submitvalues->header, "%s%s%s%s%s%s", version, templ->prevhash_be, submitvalues->merkleroot_be,
			ntime, templ->nbits, nonce);
		ser_string_be(submitvalues->header, submitvalues->header_be, 20);
	}

	binlify(submitvalues->header_bin, submitvalues->header_be);

//	printf("%s\n", submitvalues->header_be);
	int header_len = strlen(submitvalues->header)/2;
	g_current_algo->hash_function((char *)submitvalues->header_bin, (char *)submitvalues->hash_bin, header_len);

    if ((g_debuglog_hash || g_debuglog_block_path_verbose) && !strcmp(g_stratum_algo, "yescrypt")) {
        debuglog("=== YESCRYPT HASH DEBUG ===");
        debug_hex("header_bin", submitvalues->header_bin, header_len);
        debug_hex("hash_bin", submitvalues->hash_bin, 32);
    }


	hexlify(submitvalues->hash_hex, submitvalues->hash_bin, 32);
	string_be(submitvalues->hash_hex, submitvalues->hash_be);
}

/////////////////////////////////////////////

static void create_decred_header(YAAMP_JOB_TEMPLATE *templ, YAAMP_JOB_VALUES *out,
	const char *ntime, const char *nonce, const char *nonce2, const char *vote, bool usegetwork)
{
	struct __attribute__((__packed__)) {
		uint32_t version;
		char prevblock[32];
		char merkleroot[32];
		char stakeroot[32];
		uint16_t votebits;
		char finalstate[6];
		uint16_t voters;
		uint8_t freshstake;
		uint8_t revoc;
		uint32_t poolsize;
		uint32_t nbits;
		uint64_t sbits;
		uint32_t height;
		uint32_t size;
		uint32_t ntime;
		uint32_t nonce;
		unsigned char extra[32];
		uint32_t stakever;
		uint32_t hashtag[3];
	} header;

	memcpy(&header, templ->header, sizeof(header));

	memset(header.extra, 0, 32);
	sscanf(nonce, "%08x", &header.nonce);

	if (strcmp(vote, "")) {
		uint16_t votebits = 0;
		sscanf(vote, "%04hx", &votebits);
		header.votebits = (header.votebits & 1) | (votebits & 0xfffe);
	}

	binlify(header.extra, nonce2);

	hexlify(out->header, (const unsigned char*) &header, 180);
	memcpy(out->header_bin, &header, sizeof(header));
}

static void build_submit_values_decred(YAAMP_JOB_VALUES *submitvalues, YAAMP_JOB_TEMPLATE *templ,
	const char *nonce1, const char *nonce2, const char *ntime, const char *nonce, const char *vote, bool usegetwork)
{
	if (!usegetwork) {
		// not used yet
		char doublehash[128] = { 0 };

		sprintf(submitvalues->coinbase, "%s%s%s%s", templ->coinb1, nonce1, nonce2, templ->coinb2);
		int coinbase_len = strlen(submitvalues->coinbase);

		unsigned char coinbase_bin[1024];
		memset(coinbase_bin, 0, 1024);
		binlify(coinbase_bin, submitvalues->coinbase);

		YAAMP_HASH_FUNCTION merkle_hash = sha256_double_hash_hex;
		if (g_current_algo->merkle_func)
			merkle_hash = g_current_algo->merkle_func;
		merkle_hash((char *)coinbase_bin, doublehash, coinbase_len/2);

		string merkleroot = merkle_with_first(templ->txsteps, doublehash);
		ser_string_be(merkleroot.c_str(), submitvalues->merkleroot_be, 8);

#ifdef MERKLE_DEBUGLOG
		printf("merkle root %s\n", merkleroot.c_str());
#endif
	}
	create_decred_header(templ, submitvalues, ntime, nonce, nonce2, vote, usegetwork);

	int header_len = strlen(submitvalues->header)/2;
	g_current_algo->hash_function((char *)submitvalues->header_bin, (char *)submitvalues->hash_bin, header_len);

    if ((g_debuglog_hash || g_debuglog_block_path_verbose) && !strcmp(g_stratum_algo, "yescrypt")) {
        debuglog("=== YESCRYPT HASH DEBUG ===");
        debug_hex("header_bin", submitvalues->header_bin, header_len);
        debug_hex("hash_bin", submitvalues->hash_bin, 32);
    }


	hexlify(submitvalues->hash_hex, submitvalues->hash_bin, 32);
	string_be(submitvalues->hash_hex, submitvalues->hash_be);
}

/////////////////////////////////////////////////////////////////////////////////

static void client_do_submit(YAAMP_CLIENT *client, YAAMP_JOB *job, YAAMP_JOB_VALUES *submitvalues,
	char *extranonce2, char *ntime, char *nonce, char *vote)
{
	YAAMP_COIND *coind = job->coind;
	YAAMP_JOB_TEMPLATE *templ = job->templ;

	if(job->block_found) return;
	if(job->deleted) return;

	uint64_t hash_int = get_hash_difficulty(submitvalues->hash_bin);
	uint64_t coin_target = decode_compact(templ->nbits);
	if (templ->nbits && !coin_target) coin_target = 0xFFFF000000000000ULL;

	char block_target_hex[65] = { 0 };
	bool full_block_target = hash_meets_target_from_nbits(submitvalues->hash_be, templ->nbits, block_target_hex);
	BLOCK_PATH_CONTEXT block_path;
	block_path_context_init(&block_path, client, job, submitvalues, hash_int, coin_target, full_block_target, block_target_hex);
	block_path_record_target(&block_path);
	block_path_log_target(&block_path);
	if(full_block_target) block_path_log_candidate(&block_path);

	int block_size = YAAMP_SMALLBUFSIZE;
	vector<string>::const_iterator i;

	for(i = templ->txdata.begin(); i != templ->txdata.end(); ++i)
		block_size += strlen((*i).c_str());

	char *block_hex = (char *)malloc(block_size);
	if(!block_hex) return;

	// do aux first
	for(int i=0; i<templ->auxs_size; i++)
	{
		if(!templ->auxs[i]) continue;
		YAAMP_COIND *coind_aux = templ->auxs[i]->coind;

		if(!coind_aux || !strcmp(coind->symbol, coind_aux->symbol2))
			continue;

		unsigned char target_aux[1024];
		binlify(target_aux, coind_aux->aux.target);

		uint64_t coin_target_aux = get_hash_difficulty(target_aux);
		if(hash_int <= coin_target_aux)
		{
			memset(block_hex, 0, block_size);

			strcat(block_hex, submitvalues->coinbase);		// parent coinbase
			strcat(block_hex, submitvalues->hash_be);		// parent hash

			////////////////////////////////////////////////// parent merkle steps

			sprintf(block_hex+strlen(block_hex), "%02x", (unsigned char)templ->txsteps.size());

			vector<string>::const_iterator i;
			for(i = templ->txsteps.begin(); i != templ->txsteps.end(); ++i)
				sprintf(block_hex + strlen(block_hex), "%s", (*i).c_str());

			strcat(block_hex, "00000000");

			////////////////////////////////////////////////// auxs merkle steps

			vector<string> lresult = coind_aux_merkle_branch(templ->auxs, templ->auxs_size, coind_aux->aux.index);
			sprintf(block_hex+strlen(block_hex), "%02x", (unsigned char)lresult.size());

			for(i = lresult.begin(); i != lresult.end(); ++i)
				sprintf(block_hex+strlen(block_hex), "%s", (*i).c_str());

			sprintf(block_hex+strlen(block_hex), "%02x000000", (unsigned char)coind_aux->aux.index);

			////////////////////////////////////////////////// parent header

			strcat(block_hex, submitvalues->header_be);

			bool b = coind_submitgetauxblock(coind_aux, coind_aux->aux.hash, block_hex);
			if(b)
			{
				debuglog("*** ACCEPTED %s %d (+1)\n", coind_aux->name, coind_aux->height);

				block_add(client->userid, client->workerid, coind_aux->id, coind_aux->height, target_to_diff(coin_target_aux),
					target_to_diff(hash_int), coind_aux->aux.hash, "", 0);
			}

			else
				debuglog("%s %d REJECTED\n", coind_aux->name, coind_aux->height);
		}
	}

	if(full_block_target)
	{
		char count_hex[8] = { 0 };
		if (templ->txcount <= 252)
			sprintf(count_hex, "%02x", templ->txcount & 0xFF);
		else
			sprintf(count_hex, "fd%02x%02x", templ->txcount & 0xFF, templ->txcount >> 8);

		memset(block_hex, 0, block_size);
		sprintf(block_hex, "%s%s%s", submitvalues->header_be, count_hex, submitvalues->coinbase);

		if (g_current_algo->name && !strcmp("jha", g_current_algo->name)) {
			// block header of 88 bytes
			sprintf(block_hex, "%s8400000008000000%s%s", submitvalues->header_be, count_hex, submitvalues->coinbase);
		}

		vector<string>::const_iterator i;
		for(i = templ->txdata.begin(); i != templ->txdata.end(); ++i)
			sprintf(block_hex+strlen(block_hex), "%s", (*i).c_str());

		// POS coins need a zero byte appended to block, the daemon replaces it with the signature
		if(coind->pos)
			strcat(block_hex, "00");

		if(!strcmp("DCR", coind->rpcencoding)) {
			// submit the regenerated block header
			char hex[384];
			hexlify(hex, submitvalues->header_bin, 180);
			if (coind->usegetwork)
				snprintf(block_hex, block_size, "%s8000000100000000000005a0", hex);
			else
				snprintf(block_hex, block_size, "%s", hex);
		}

		size_t block_hex_len = strlen(block_hex);
		block_path_log_submit_begin(&block_path, block_hex_len);
		STRATUM_COIND_SUBMIT_OBSERVATION submit_observation;
		bool b = coind_submit(coind, block_hex, &submit_observation);
		block_path_log_submit_returned(&block_path, block_hex_len, &submit_observation);
		if(b)
		{
			debuglog("*** ACCEPTED %s %d (diff %g) by %s (id: %d)\n", coind->name, templ->height,
				target_to_diff(hash_int), client->sock->ip, client->userid);

			job->block_found = true;

			char doublehash2[128];
			memset(doublehash2, 0, 128);

			YAAMP_HASH_FUNCTION merkle_hash = sha256_double_hash_hex;
			//if (g_current_algo->merkle_func)
			//	merkle_hash = g_current_algo->merkle_func;

			merkle_hash((char *)submitvalues->header_bin, doublehash2, strlen(submitvalues->header_be)/2);

			char hash1[1024];
			memset(hash1, 0, 1024);

			string_be(doublehash2, hash1);

			if(coind->usegetwork && !strcmp("DCR", coind->rpcencoding)) {
				// no merkle stuff
				strcpy(hash1, submitvalues->hash_hex);
			}

			block_path_log_block_add_begin(&block_path);
			block_add(client->userid, client->workerid, coind->id, templ->height,
				target_to_diff(coin_target), target_to_diff(hash_int),
				hash1, submitvalues->hash_be, templ->has_segwit_txs);
			block_path_log_block_add_returned(&block_path);

			if(!strcmp("DCR", coind->rpcencoding)) {
				// delay between dcrd and dcrwallet
				sleep(1);
			}

			if(!strcmp(coind->lastnotifyhash,submitvalues->hash_be)) {
				block_confirm(coind->id, submitvalues->hash_be);
			}

			if (g_debuglog_hash) {
				debuglog("--------------------------------------------------------------\n");
				debuglog("hash1 %s\n", hash1);
				debuglog("hash2 %s\n", submitvalues->hash_be);
			}
		}

		else {
			debuglog("*** REJECTED :( %s block %d %d txs\n", coind->name, templ->height, templ->txcount);
			rejectlog("REJECTED %s block %d\n", coind->symbol, templ->height);
			if (g_debuglog_hash) {
				//debuglog("block %s\n", block_hex);
				debuglog("--------------------------------------------------------------\n");
			}
		}
	}

	free(block_hex);
}


static int share_decision_diag_index(const char *decision)
{
	if(!decision) return 0;
	if(!strcmp(decision, "LOW_DIFFICULTY_REJECT")) return 1;
	if(!strcmp(decision, "PROCEED_TO_SUBMIT")) return 2;
	if(!strcmp(decision, "PROCEED_TO_CLIENT_DO_SUBMIT")) return 3;
	if(!strcmp(decision, "PROCEED_TO_REMOTE_SUBMIT")) return 4;
	if(!strcmp(decision, "SUBMIT_RETURNED")) return 5;
	if(!strcmp(decision, "SHARE_ADD_BEGIN")) return 6;
	if(!strcmp(decision, "SHARE_ADD_DONE")) return 7;
	return 0;
}

static bool share_decision_diag_rate_limited(const char *decision)
{
	static time_t last_log[16];
	static unsigned int suppressed[16];
	int idx = share_decision_diag_index(decision);
	time_t now = time(NULL);

	if(last_log[idx] != now)
	{
		if(suppressed[idx])
		{
			debuglog("share_decision_diag_suppressed decision=%s count=%u\n", decision? decision: "-", suppressed[idx]);
			suppressed[idx] = 0;
		}
		last_log[idx] = now;
		return false;
	}

	suppressed[idx]++;
	return true;
}

static void log_share_decision_diag(YAAMP_CLIENT *client, YAAMP_JOB *job, const char *decision, const char *result,
	uint64_t hash_int, uint64_t user_target, uint64_t coin_target, double share_diff)
{
	if(!(g_debuglog_hash || g_debuglog_block_path_verbose)) return;
	if(share_decision_diag_rate_limited(decision)) return;

	YAAMP_JOB_TEMPLATE *templ = job? job->templ: NULL;
	YAAMP_COIND *coind = job? job->coind: NULL;

	debuglog("share_decision_diag algo=%s decision=%s result=%s jobid=%x coinid=%d coin=%s worker_uid=%d client_ip=%s "
		"difficulty=%.8f nbits=%s hash_int=%016llx user_target=%016llx coin_target=%016llx "
		"hash_gt_user_target=%d hash_gt_coin_target=%d share_diff=%.8f\n",
		g_current_algo? g_current_algo->name: g_stratum_algo,
		decision? decision: "-",
		result? result: "-",
		job? job->id: 0,
		coind? coind->id: 0,
		coind? coind->symbol: "-",
		client? client->userid: 0,
		(client && client->sock)? client->sock->ip: "-",
		client? client->difficulty_actual: 0,
		(templ && templ->nbits[0])? templ->nbits: "-",
		(unsigned long long)hash_int,
		(unsigned long long)user_target,
		(unsigned long long)coin_target,
		hash_int > user_target,
		hash_int > coin_target,
		share_diff);
}

static bool submit_reject_diag_enabled()
{
	return g_debuglog_hash || g_debuglog_block_path_verbose;
}

static bool submit_reject_diag_code_selected(int id)
{
	return id == 21 || id == 22 || id == 25 || id == 26;
}

static bool submit_reject_diag_rate_limited(int id)
{
	static time_t last_log[32];
	static unsigned int suppressed[32];
	int idx = id >= 0? id % 32: 0;
	time_t now = time(NULL);

	if(last_log[idx] != now)
	{
		if(suppressed[idx])
		{
			debuglog("share_reject_diag_suppressed code=%d count=%u\n", id, suppressed[idx]);
			suppressed[idx] = 0;
		}
		last_log[idx] = now;
		return false;
	}

	suppressed[idx]++;
	return true;
}

static void log_share_reject_diag(YAAMP_CLIENT *client, YAAMP_JOB *job, int id, const char *message,
	char *extranonce2, char *ntime, char *nonce, bool build_complete, bool hash_computed,
	uint64_t hash_int, uint64_t user_target, uint64_t coin_target)
{
	if(!submit_reject_diag_enabled() || !submit_reject_diag_code_selected(id)) return;
	if(submit_reject_diag_rate_limited(id)) return;

	YAAMP_JOB_TEMPLATE *templ = job? job->templ: NULL;
	YAAMP_COIND *coind = job? job->coind: NULL;
	debuglog("share_reject_diag algo=%s code=%d reason=\"%s\" jobid=%x job_lookup=%s coinid=%d coin=%s worker_uid=%d "
		"client_ip=%s authorized=%d subscribed=%d difficulty=%.8f nbits=%s ntime=%s nonce=%s extranonce2_len=%u "
		"build_submit_values=%d hash_computed=%d hash_int=%016llx user_target=%016llx coin_target=%016llx\n",
		g_current_algo? g_current_algo->name: g_stratum_algo, id, message? message: "-", job? job->id: 0,
		job? "found": "missing", coind? coind->id: 0, coind? coind->symbol: "-", client? client->userid: 0,
		(client && client->sock)? client->sock->ip: "-", (client && client->username[0])? 1: 0, client? client->extranonce_subscribe: 0,
		client? client->difficulty_actual: 0, (templ && templ->nbits[0])? templ->nbits: "-", ntime? ntime: "-", nonce? nonce: "-",
		extranonce2? (unsigned int)strlen(extranonce2): 0, build_complete? 1: 0, hash_computed? 1: 0,
		(unsigned long long)hash_int, (unsigned long long)user_target, (unsigned long long)coin_target);
}

bool dump_submit_debug(const char *title, YAAMP_CLIENT *client, YAAMP_JOB *job, char *extranonce2, char *ntime, char *nonce)
{
	debuglog("ERROR %s, %s subs %d, job %x, %s, id %x, %d, %s, %s %s\n",
		title, client->sock->ip, client->extranonce_subscribe, job? job->id: 0, client->extranonce1,
		client->extranonce1_id, client->extranonce2size, extranonce2, ntime, nonce);
}

void client_submit_error(YAAMP_CLIENT *client, YAAMP_JOB *job, int id, const char *message, char *extranonce2, char *ntime, char *nonce)
{
//	if(job->templ->created+2 > time(NULL))
	if(job && job->deleted)
		client_send_result(client, "true");

	else
	{
		block_path_record_share(false);
		client_send_error(client, id, message);
		share_add(client, job, false, extranonce2, ntime, nonce, 0, id);

		client->submit_bad++;
		if (g_debuglog_hash) {
			dump_submit_debug(message, client, job, extranonce2, ntime, nonce);
		}
	}

	object_unlock(job);
}

static void client_submit_error_diag(YAAMP_CLIENT *client, YAAMP_JOB *job, int id, const char *message, char *extranonce2, char *ntime, char *nonce,
	bool build_complete, bool hash_computed, uint64_t hash_int, uint64_t user_target, uint64_t coin_target)
{
	log_share_reject_diag(client, job, id, message, extranonce2, ntime, nonce, build_complete, hash_computed, hash_int, user_target, coin_target);
	client_submit_error(client, job, id, message, extranonce2, ntime, nonce);
}

static bool ntime_valid_range(const char ntimehex[])
{
	time_t rawtime = 0;
	uint32_t ntime = 0;
	if (strlen(ntimehex) != 8) return false;
	sscanf(ntimehex, "%8x", &ntime);
	time(&rawtime);
	return (abs(rawtime - ntime) < (30 * 60));
}

static bool valid_string_params(json_value *json_params)
{
	for(int p=0; p < json_params->u.array.length; p++) {
		if (!json_is_string(json_params->u.array.values[p]))
			return false;
	}
	return true;
}

static bool is_sha256_algo()
{
	return g_current_algo && !strcmp(g_current_algo->name, "sha256");
}

static const char *log_safe_string(const char *input, char *output, size_t output_size)
{
	if(!output || !output_size) return "-";
	if(!input)
	{
		snprintf(output, output_size, "-");
		return output;
	}

	size_t o = 0;
	for(size_t i = 0; input[i] && o + 1 < output_size; i++)
	{
		unsigned char c = (unsigned char)input[i];
		output[o++] = (c <= 0x20 || c >= 0x7f)? '_': input[i];
	}
	output[o] = 0;
	return output;
}

static void log_version_rolling_submit(const char *label, uint32_t template_version, uint32_t submitted_bits,
	uint32_t mask, const char *effective_version, bool applied, const char *reason)
{
	debuglog("%s template_version=%08x submitted_bits=%08x mask=%08x effective_version=%s applied=%s reason=%s\n",
		label? label: "version_rolling_submit",
		template_version, submitted_bits, mask, effective_version? effective_version: "-",
		applied? "true": "false", reason? reason: "-");
}

static bool client_submit_version_rolling_allowed(YAAMP_CLIENT *client, uint32_t submitted_bits, uint32_t *mask,
	bool *negotiated)
{
	if(mask) *mask = STRATUM_VERSION_ROLLING_MASK;
	if(negotiated) *negotiated = false;
	if(!client || !client->version_rolling_enabled) return false;

	uint32_t selected_mask = client->version_rolling_mask;
	if(mask) *mask = selected_mask;
	if(negotiated) *negotiated = true;
	return (submitted_bits & ~selected_mask) == 0;
}

static bool apply_sha256_version_rolling(YAAMP_CLIENT *client, YAAMP_JOB *job, const char *submitted_bits,
	char *effective_version, size_t effective_version_size, uint32_t *submitted_version_bits_out = NULL,
	uint32_t *version_mask_out = NULL)
{
	if(!job || !job->templ || !submitted_bits || !effective_version || effective_version_size < 9) return false;

	uint32_t template_version = htoi(job->templ->version);
	if(version_mask_out) *version_mask_out = STRATUM_VERSION_ROLLING_MASK;

	if(strlen(submitted_bits) != 8 || !ishexa((char *)submitted_bits, 8))
	{
		debuglog("version_rolling_submit_fallback template_version=%s submitted_bits=%s mask=%08x "
			"effective_version=- applied=false reason=invalid_hex\n",
			job->templ->version, submitted_bits? submitted_bits: "-", STRATUM_VERSION_ROLLING_MASK);
		return false;
	}

	uint32_t version_bits = htoi(submitted_bits);
	uint32_t mask = STRATUM_VERSION_ROLLING_MASK;
	bool negotiated = false;
	bool allowed = client_submit_version_rolling_allowed(client, version_bits, &mask, &negotiated);
	if(submitted_version_bits_out) *submitted_version_bits_out = version_bits;
	if(version_mask_out) *version_mask_out = mask;

	if(!negotiated)
		allowed = (version_bits & ~mask) == 0;

	if(!allowed)
	{
		uint32_t outside_mask = version_bits & ~mask;
		debuglog("%s template_version=%08x submitted_bits=%08x mask=%08x outside_mask=%08x "
			"effective_version=- applied=false reason=bits_outside_mask\n",
			negotiated? "version_rolling_submit": "version_rolling_submit_fallback",
			template_version, version_bits, mask, outside_mask);
		return false;
	}

	uint32_t effective = template_version | (version_bits & mask);
	snprintf(effective_version, effective_version_size, "%08x", effective);
	log_version_rolling_submit(negotiated? "version_rolling_submit": "version_rolling_submit_fallback",
		template_version, version_bits, mask, effective_version, true, "-");
	return true;
}

static void log_sha256d_error26_submit_trace(YAAMP_CLIENT *client, YAAMP_JOB *job, YAAMP_JOB_VALUES *submitvalues,
	const char *extranonce2, const char *ntime, const char *nonce, bool submit_param6_present,
	uint32_t submitted_version_bits, uint32_t version_mask, const char *effective_version,
	uint64_t hash_int, uint64_t user_target, uint64_t coin_target, double share_diff)
{
	if(!is_sha256_algo()) return;

	YAAMP_JOB_TEMPLATE *templ = job? job->templ: NULL;
	char safe_version[256];
	char submitted_bits_text[16];

	if(submit_param6_present)
		snprintf(submitted_bits_text, sizeof(submitted_bits_text), "%08x", submitted_version_bits);
	else
		snprintf(submitted_bits_text, sizeof(submitted_bits_text), "-");

	debuglog("SHA256D_ERROR26_SUBMIT_TRACE client_ip=%s userid=%d workerid=%d worker_version=%s "
		"jobid=%x template_height=%d template_version=%s submitted_version_bits=%s version_mask=%08x "
		"effective_version=%s extranonce1=%s extranonce2=%s extranonce2_len=%u ntime=%s nonce=%s "
		"prevhash_be=%s merkleroot_be=%s nbits=%s header=%s header_be=%s hash_hex=%s hash_be=%s "
		"hash_int=%016llx user_target=%016llx coin_target=%016llx difficulty_actual=%.8f share_diff=%.8f "
		"reject_code=26\n",
		(client && client->sock)? client->sock->ip: "-",
		client? client->userid: 0,
		client? client->workerid: 0,
		log_safe_string(client? client->version: NULL, safe_version, sizeof(safe_version)),
		job? job->id: 0,
		templ? templ->height: 0,
		(templ && templ->version[0])? templ->version: "-",
		submitted_bits_text,
		version_mask,
		effective_version && effective_version[0]? effective_version: ((templ && templ->version[0])? templ->version: "-"),
		client? client->extranonce1: "-",
		extranonce2? extranonce2: "-",
		extranonce2? (unsigned int)strlen(extranonce2): 0,
		ntime? ntime: "-",
		nonce? nonce: "-",
		(templ && templ->prevhash_be[0])? templ->prevhash_be: "-",
		(submitvalues && submitvalues->merkleroot_be[0])? submitvalues->merkleroot_be: "-",
		(templ && templ->nbits[0])? templ->nbits: "-",
		(submitvalues && submitvalues->header[0])? submitvalues->header: "-",
		(submitvalues && submitvalues->header_be[0])? submitvalues->header_be: "-",
		(submitvalues && submitvalues->hash_hex[0])? submitvalues->hash_hex: "-",
		(submitvalues && submitvalues->hash_be[0])? submitvalues->hash_be: "-",
		(unsigned long long)hash_int,
		(unsigned long long)user_target,
		(unsigned long long)coin_target,
		client? client->difficulty_actual: 0,
		share_diff);
}

bool client_submit(YAAMP_CLIENT *client, json_value *json_params)
{
	// submit(worker_name, jobid, extranonce2, ntime, nonce):
	if(json_params->u.array.length<5 || !valid_string_params(json_params)) {
		debuglog("%s - %s bad message\n", client->username, client->sock->ip);
		client->submit_bad++;
		return false;
	}

	char extranonce2[32] = { 0 };
	char extra[160] = { 0 };
	char nonce[80] = { 0 };
	char ntime[32] = { 0 };
	char vote[8] = { 0 };
	char submit_param6[160] = { 0 };

	if (strlen(json_params->u.array.values[1]->u.string.ptr) > 32) {
		clientlog(client, "bad json, wrong jobid len");
		client->submit_bad++;
		return false;
	}
	int jobid = htoi(json_params->u.array.values[1]->u.string.ptr);

	strncpy(extranonce2, json_params->u.array.values[2]->u.string.ptr, 31);
	strncpy(ntime, json_params->u.array.values[3]->u.string.ptr, 31);
	strncpy(nonce, json_params->u.array.values[4]->u.string.ptr, 31);

	string_lower(extranonce2);
	string_lower(ntime);
	string_lower(nonce);

	if (json_params->u.array.length == 6) {
		strncpy(submit_param6, json_params->u.array.values[5]->u.string.ptr, sizeof(submit_param6)-1);
		string_lower(submit_param6);
		if (strstr(g_stratum_algo, "phi")) {
			// lux optional field, smart contral root hashes (not mandatory on shares submit)
			strncpy(extra, submit_param6, 128);
		} else if(!is_sha256_algo()) {
			// heavycoin vote
			strncpy(vote, submit_param6, 7);
		}
	}

	if (g_debuglog_hash) {
		debuglog("submit %s (uid %d) %d, %s, t=%s, n=%s, extra=%s\n", client->sock->ip, client->userid,
			jobid, extranonce2, ntime, nonce, extra);
	}

	YAAMP_JOB *job = (YAAMP_JOB *)object_find(&g_list_job, jobid, true);
	if(!job)
	{
		client_submit_error_diag(client, NULL, 21, "Invalid job id", extranonce2, ntime, nonce, false, false, 0, 0, 0);
		return true;
	}

	if(job->deleted)
	{
		client_send_result(client, "true");
		object_unlock(job);

		return true;
	}

	bool is_decred = job->coind && !strcmp("DCR", job->coind->rpcencoding);

	YAAMP_JOB_TEMPLATE *templ = job->templ;
	char effective_version[16] = { 0 };
	const char *version_override = NULL;
	bool submit_param6_present = submit_param6[0] != 0;
	uint32_t submitted_version_bits = 0;
	uint32_t version_mask = STRATUM_VERSION_ROLLING_MASK;

	if(submit_param6[0] && is_sha256_algo() && !is_decred && !strstr(g_stratum_algo, "phi"))
	{
		if(!apply_sha256_version_rolling(client, job, submit_param6, effective_version, sizeof(effective_version),
			&submitted_version_bits, &version_mask))
		{
			client_submit_error(client, job, 20, "Invalid version rolling bits", extranonce2, ntime, nonce);
			return true;
		}
		version_override = effective_version;
	}

	if(strlen(nonce) != YAAMP_NONCE_SIZE*2 || !ishexa(nonce, YAAMP_NONCE_SIZE*2)) {
		client_submit_error(client, job, 20, "Invalid nonce size", extranonce2, ntime, nonce);
		return true;
	}

	if(strcmp(ntime, templ->ntime))
	{
		if (!ishexa(ntime, 8) || !ntime_valid_range(ntime)) {
			client_submit_error(client, job, 23, "Invalid time rolling", extranonce2, ntime, nonce);
			return true;
		}
		// dont allow algos permutations change over time (can lead to different speeds)
		if (!g_allow_rolltime) {
			client_submit_error(client, job, 23, "Invalid ntime (rolling not allowed)", extranonce2, ntime, nonce);
			return true;
		}
	}

	YAAMP_SHARE *share = share_find(job->id, extranonce2, ntime, nonce, client->extranonce1);
	if(share)
	{
		client_submit_error_diag(client, job, 22, "Duplicate share", extranonce2, ntime, nonce, false, false, 0, 0, 0);
		return true;
	}

	if(strlen(extranonce2) != client->extranonce2size*2)
	{
		client_submit_error(client, job, 24, "Invalid extranonce2 size", extranonce2, ntime, nonce);
		return true;
	}

	// check if the submitted extranonce is valid
	if(is_decred && client->extranonce2size > 4) {
		char extra1_id[16], extra2_id[16];
		int cmpoft = client->extranonce2size*2 - 8;
		strcpy(extra1_id, &client->extranonce1[cmpoft]);
		strcpy(extra2_id, &extranonce2[cmpoft]);
		int extradiff = (int) strcmp(extra2_id, extra1_id);
		int extranull = (int) !strcmp(extra2_id, "00000000");
		if (extranull && client->extranonce2size > 8)
			extranull = (int) !strcmp(&extranonce2[8], "00000000" "00000000");
		if (extranull) {
			debuglog("extranonce %s is empty!, should be %s - %s\n", extranonce2, extra1_id, client->sock->ip);
			client_submit_error(client, job, 27, "Invalid extranonce2 suffix", extranonce2, ntime, nonce);
			return true;
		}
		if (extradiff) {
			// some ccminer pre-release doesn't fill correctly the extranonce
			client_submit_error(client, job, 27, "Invalid extranonce2 suffix", extranonce2, ntime, nonce);
			socket_send(client->sock, "{\"id\":null,\"method\":\"mining.set_extranonce\",\"params\":[\"%s\",%d]}\n",
				client->extranonce1, client->extranonce2size);
			return true;
		}
	}
	else if(!ishexa(extranonce2, client->extranonce2size*2)) {
		client_submit_error(client, job, 27, "Invalid nonce2", extranonce2, ntime, nonce);
		return true;
	}

	///////////////////////////////////////////////////////////////////////////////////////////

	YAAMP_JOB_VALUES submitvalues;
	memset(&submitvalues, 0, sizeof(submitvalues));

	if(is_decred)
		build_submit_values_decred(&submitvalues, templ, client->extranonce1, extranonce2, ntime, nonce, vote, true);
	else
		build_submit_values(&submitvalues, templ, client->extranonce1, extranonce2, ntime, nonce, version_override);

	if (templ->height && !strcmp(g_current_algo->name,"lyra2z")) {
		lyra2z_height = templ->height;
	}

	// minimum hash diff begins with 0000, for all...
	uint8_t pfx = submitvalues.hash_bin[30] | submitvalues.hash_bin[31];
	if(pfx && strcmp(g_current_algo->name, "scrypt") && strcmp(g_current_algo->name, "yescrypt") && strcmp(g_current_algo->name, "sha256")) {
		if (g_debuglog_hash) {
			debuglog("Possible %s error, hash starts with %02x%02x%02x%02x\n", g_current_algo->name,
				(int) submitvalues.hash_bin[31], (int) submitvalues.hash_bin[30],
				(int) submitvalues.hash_bin[29], (int) submitvalues.hash_bin[28]);
		}
		client_submit_error_diag(client, job, 25, "Invalid share", extranonce2, ntime, nonce, true, true, 0, 0, 0);
		return true;
	}

	uint64_t hash_int = get_hash_difficulty(submitvalues.hash_bin);
	uint64_t user_target = diff_to_target(client->difficulty_actual);
	uint64_t coin_target = decode_compact(templ->nbits);
	if (templ->nbits && !coin_target) coin_target = 0xFFFF000000000000ULL;

	if (g_debuglog_hash || g_debuglog_block_path_verbose) {
		debuglog("POOL hash_hex=%s\n", submitvalues.hash_hex);
		debuglog("POOL hash_be=%s\n", submitvalues.hash_be);
		debuglog("POOL user_target=%016llx\n", (unsigned long long)user_target);
		debuglog("POOL coin_target=%016llx\n", (unsigned long long)coin_target);
		debuglog("%016llx actual\n", hash_int);
		debuglog("%016llx target\n", user_target);
		debuglog("%016llx coin\n", coin_target);
	}
	bool hash_gt_user_target = hash_int > user_target;
	bool hash_gt_coin_target = hash_int > coin_target;
	if(hash_gt_user_target && hash_gt_coin_target)
	{
		double share_diff = target_to_diff(hash_int);
		log_sha256d_error26_submit_trace(client, job, &submitvalues, extranonce2, ntime, nonce,
			submit_param6_present, submitted_version_bits, version_mask,
			version_override? version_override: templ->version, hash_int, user_target, coin_target, share_diff);
		log_share_decision_diag(client, job, "LOW_DIFFICULTY_REJECT", "code=26", hash_int, user_target, coin_target, 0);
		client_submit_error_diag(client, job, 26, "Low difficulty share", extranonce2, ntime, nonce, true, true, hash_int, user_target, coin_target);
		return true;
	}

	log_share_decision_diag(client, job, "PROCEED_TO_SUBMIT", "hash_target_gate_passed", hash_int, user_target, coin_target, 0);
	if(job->coind)
	{
		log_share_decision_diag(client, job, "PROCEED_TO_CLIENT_DO_SUBMIT", "begin", hash_int, user_target, coin_target, 0);
		client_do_submit(client, job, &submitvalues, extranonce2, ntime, nonce, vote);
		log_share_decision_diag(client, job, "SUBMIT_RETURNED", "client_do_submit_returned", hash_int, user_target, coin_target, 0);
	}
	else
	{
		log_share_decision_diag(client, job, "PROCEED_TO_REMOTE_SUBMIT", "begin", hash_int, user_target, coin_target, 0);
		remote_submit(client, job, &submitvalues, extranonce2, ntime, nonce);
		log_share_decision_diag(client, job, "SUBMIT_RETURNED", "remote_submit_returned", hash_int, user_target, coin_target, 0);
	}

	client_send_result(client, "true");
	client_record_difficulty(client);
	client->submit_bad = 0;
	client->shares++;
	if (client->shares <= 200 && (client->shares % 50) == 0) {
		// 4 records are enough per miner
		if (!client_ask_stats(client)) client->stats = false;
	}

	double share_diff = diff_to_target(hash_int);
//	if (g_current_algo->diff_multiplier != 0) {
//		share_diff = share_diff / g_current_algo->diff_multiplier;
//	}

	if (g_debuglog_hash) {
		// only log a few...
		if (share_diff > (client->difficulty_actual * 16))
			debuglog("submit %s (uid %d) %d, %s, %s, %s, %.3f/%.3f\n", client->sock->ip, client->userid,
				jobid, extranonce2, ntime, nonce, share_diff, client->difficulty_actual);
	}

	log_share_decision_diag(client, job, "SHARE_ADD_BEGIN", "valid_true", hash_int, user_target, coin_target, share_diff);
	share_add(client, job, true, extranonce2, ntime, nonce, share_diff, 0);
	block_path_record_share(true);
	log_share_decision_diag(client, job, "SHARE_ADD_DONE", "reached_after_share_add", hash_int, user_target, coin_target, share_diff);
	object_unlock(job);

	return true;
}
