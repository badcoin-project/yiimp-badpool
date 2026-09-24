
#include "stratum.h"
#include <cmath>
#include <cctype>
#include <cerrno>

#define BLOCK_DURABILITY_SCHEMA "badpool.stratum.blockdurability.v1"

static const char *block_operational_algo()
{
	if(!strcmp(g_stratum_algo, "badcoin-groestl")) return "groestl";
	if(!strcmp(g_stratum_algo, "sha256")) return "sha256d";
	return g_stratum_algo;
}

static bool block_hash_is_canonical(const char *hash)
{
	if(!hash || strlen(hash) != 64) return false;
	for(const unsigned char *p = (const unsigned char *)hash; *p; ++p)
		if(!isxdigit(*p)) return false;
	return true;
}

static bool block_hash_is_optional_pow(const char *hash)
{
	return !hash || !hash[0] || block_hash_is_canonical(hash);
}

static bool block_log_attempt(unsigned int attempt)
{
	return attempt <= 3 || attempt == 5 || attempt == 10 || attempt == 30 || !(attempt % 60);
}

// The live share cursor row is locked before this lookup.  It serializes
// capture for one DB algorithm across stratum processes without requiring a
// second accounting authority or a schema migration.
static bool block_find_durable(YAAMP_DB *db, YAAMP_BLOCK *block,
	unsigned long long *blockid, bool *candidate_exists, bool *ambiguous)
{
	char query[4096];
	snprintf(query, sizeof(query),
		"SELECT B.id,B.height,C.block_id FROM blocks B "
		"LEFT JOIN live_block_candidates C ON C.block_id=B.id AND C.coin_id=B.coin_id "
		"AND C.algo=B.algo AND C.blockhash=B.blockhash "
		"WHERE B.coin_id=%d AND B.algo='%s' AND B.blockhash='%s' "
		"ORDER BY B.id LIMIT 2 FOR UPDATE",
		block->coinid, g_stratum_algo, block->hash);
	if(mysql_query(&db->mysql, query)) {
		stratumlog("BLOCK_DURABILITY_LOOKUP_FAILED schema=%s coin_id=%d db_algo=%s operational_algo=%s "
			"height=%d canonical_blockhash=%s pow_hash=%s mysql_error=%d\n",
			BLOCK_DURABILITY_SCHEMA, block->coinid, g_stratum_algo, block_operational_algo(),
			block->height, block->hash, block->hash2, mysql_errno(&db->mysql));
		return false;
	}

	MYSQL_RES *result = mysql_store_result(&db->mysql);
	if(!result) return false;
	my_ulonglong rows = mysql_num_rows(result);
	*ambiguous = rows > 1;
	*blockid = 0;
	*candidate_exists = false;
	MYSQL_ROW row = mysql_fetch_row(result);
	if(row) {
		errno = 0;
		*blockid = strtoull(row[0], NULL, 10);
		if(errno || !*blockid || !row[1] || atoi(row[1]) != block->height)
			*ambiguous = true;
		*candidate_exists = row[2] != NULL;
	}
	mysql_free_result(result);
	return true;
}

static bool block_persist_accepted(YAAMP_DB *db, YAAMP_BLOCK *block)
{
	block->persistence_attempts++;
	if(!block_hash_is_canonical(block->hash) || !block_hash_is_optional_pow(block->hash2)) {
		if(block_log_attempt(block->persistence_attempts))
			stratumlog("BLOCK_DURABILITY_REFUSED schema=%s coin_id=%d db_algo=%s operational_algo=%s "
				"height=%d canonical_blockhash=%s pow_hash=%s attempt=%u reason=invalid_hash_identity\n",
				BLOCK_DURABILITY_SCHEMA, block->coinid, g_stratum_algo, block_operational_algo(),
				block->height, block->hash, block->hash2, block->persistence_attempts);
		return false;
	}

	if(block_log_attempt(block->persistence_attempts))
		stratumlog("BLOCK_DURABILITY_ATTEMPT schema=%s coin_id=%d db_algo=%s operational_algo=%s "
			"height=%d canonical_blockhash=%s pow_hash=%s userid=%d workerid=%d found_time=%d attempt=%u\n",
			BLOCK_DURABILITY_SCHEMA, block->coinid, g_stratum_algo, block_operational_algo(),
			block->height, block->hash, block->hash2, block->userid, block->workerid,
			(int)block->created, block->persistence_attempts);

	bool capture_ok = db_query_transaction(db, "START TRANSACTION");
	if(capture_ok) capture_ok = db_query_transaction(db,
		"INSERT IGNORE INTO live_block_share_cursors (algo,last_share_id) VALUES ('%s',0)", g_stratum_algo);
	if(capture_ok) capture_ok = db_query_transaction(db,
		"UPDATE live_block_share_cursors SET last_share_id=last_share_id WHERE algo='%s'", g_stratum_algo);

	unsigned long long blockid = 0;
	bool candidate_exists = false;
	bool ambiguous = false;
	if(capture_ok) capture_ok = block_find_durable(db, block, &blockid, &candidate_exists, &ambiguous);
	if(capture_ok && ambiguous) capture_ok = false;
	if(capture_ok && blockid) {
		capture_ok = db_query_transaction(db, "COMMIT");
		if(capture_ok) {
			block->blockid = blockid;
			block->durable = true;
			stratumlog("BLOCK_DURABILITY_DUPLICATE schema=%s coin_id=%d db_algo=%s operational_algo=%s "
				"height=%d canonical_blockhash=%s pow_hash=%s block_id=%llu candidate_exists=%d action=reused\n",
				BLOCK_DURABILITY_SCHEMA, block->coinid, g_stratum_algo, block_operational_algo(),
				block->height, block->hash, block->hash2, blockid, candidate_exists?1:0);
			return true;
		}
	}

	if(capture_ok) capture_ok = db_query_transaction(db, "insert into blocks (height, blockhash, coin_id, userid, workerid, category, difficulty, difficulty_user, time, algo, segwit) values "
		"(%d, '%s', %d, %d, %d, 'new', %f, %f, %d, '%s', %d)",
		block->height, block->hash, block->coinid, block->userid, block->workerid,
		block->difficulty, block->difficulty_user, (int)block->created, g_stratum_algo, block->segwit?1:0);
	if(capture_ok) blockid = mysql_insert_id(&db->mysql);
	if(capture_ok) capture_ok = blockid > 0 && db_query_transaction(db,
		"INSERT INTO live_block_candidates (block_id,coin_id,blockhash,algo,found_time,price,share_floor_id,share_ceiling_id) "
		"SELECT %llu,%d,'%s','%s',%d,IFNULL(CO.price,0),C.last_share_id,IFNULL(MAX(S.id),C.last_share_id) "
		"FROM live_block_share_cursors C INNER JOIN coins CO ON CO.id=%d LEFT JOIN shares S ON S.algo=C.algo AND S.id>C.last_share_id "
		"WHERE C.algo='%s' GROUP BY IFNULL(CO.price,0),C.last_share_id",
		blockid, block->coinid, block->hash, g_stratum_algo, (int)block->created, block->coinid, g_stratum_algo);
	if(capture_ok) capture_ok = mysql_affected_rows(&db->mysql) == 1;
	if(capture_ok) capture_ok = db_query_transaction(db,
		"INSERT INTO live_block_attributions (block_id,userid,difficulty,no_fees,donation) "
		"SELECT %llu,S.userid,SUM(S.difficulty),IFNULL(A.no_fees,0),IFNULL(A.donation,0) FROM shares S "
		"INNER JOIN live_block_candidates C ON C.block_id=%llu INNER JOIN accounts A ON A.id=S.userid "
		"WHERE S.id>C.share_floor_id AND S.id<=C.share_ceiling_id AND S.valid=1 AND S.algo='%s' "
		"GROUP BY S.userid,IFNULL(A.no_fees,0),IFNULL(A.donation,0)",
		blockid, blockid, g_stratum_algo);
	my_ulonglong normal_attributions = capture_ok ? mysql_affected_rows(&db->mysql) : 0;
	if(capture_ok && normal_attributions == 0) {
		capture_ok = std::isfinite(block->difficulty_user) && block->difficulty_user > 0;
		if(capture_ok) capture_ok = db_query_transaction(db,
			"INSERT INTO live_block_attributions (block_id,userid,difficulty,no_fees,donation) "
			"SELECT B.id,B.userid,B.difficulty_user,IFNULL(A.no_fees,0),IFNULL(A.donation,0) FROM blocks B "
			"INNER JOIN live_block_candidates C ON C.block_id=B.id INNER JOIN accounts A ON A.id=B.userid "
			"WHERE B.id=%llu AND C.share_floor_id=C.share_ceiling_id AND B.userid>0 "
			"AND B.difficulty_user IS NOT NULL AND B.difficulty_user>0 "
			"AND NOT EXISTS (SELECT 1 FROM live_block_attributions X WHERE X.block_id=B.id)", blockid);
		capture_ok = capture_ok && mysql_affected_rows(&db->mysql) == 1;
	}
	if(capture_ok) capture_ok = db_query_transaction(db,
		"UPDATE live_block_share_cursors C INNER JOIN live_block_candidates B ON B.block_id=%llu "
		"SET C.last_share_id=B.share_ceiling_id WHERE C.algo='%s'", blockid, g_stratum_algo);
	if(capture_ok) capture_ok = db_query_transaction(db, "COMMIT");
	if(!capture_ok) {
		db_query_transaction(db, "ROLLBACK");
		if(block_log_attempt(block->persistence_attempts))
			stratumlog("BLOCK_DURABILITY_FAILED schema=%s coin_id=%d db_algo=%s operational_algo=%s "
				"height=%d canonical_blockhash=%s pow_hash=%s userid=%d workerid=%d attempt=%u "
				"state=retained_in_memory timeout_discard_allowed=false\n",
				BLOCK_DURABILITY_SCHEMA, block->coinid, g_stratum_algo, block_operational_algo(),
				block->height, block->hash, block->hash2, block->userid, block->workerid,
				block->persistence_attempts);
		return false;
	}

	block->blockid = blockid;
	block->durable = true;
	stratumlog("BLOCK_DURABILITY_SUCCEEDED schema=%s coin_id=%d db_algo=%s operational_algo=%s "
		"height=%d canonical_blockhash=%s pow_hash=%s userid=%d workerid=%d found_time=%d "
		"block_id=%llu category=new candidate_state=created\n",
		BLOCK_DURABILITY_SCHEMA, block->coinid, g_stratum_algo, block_operational_algo(),
		block->height, block->hash, block->hash2, block->userid, block->workerid,
		(int)block->created, blockid);
	return true;
}

//void check_job(YAAMP_JOB *job)
//{
//	if(job->coind && job->remote)
//	{
//		debuglog("error memory\n");
//	}
//}

static YAAMP_WORKER *share_find_worker(YAAMP_CLIENT *client, YAAMP_JOB *job, bool valid)
{
	for(CLI li = g_list_worker.first; li; li = li->next)
	{
		YAAMP_WORKER *worker = (YAAMP_WORKER *)li->data;
		if(worker->deleted) continue;

		if(	worker->userid == client->userid &&
			worker->workerid == client->workerid &&
			worker->valid == valid)
		{
			if(!job && !worker->coinid && !worker->remoteid)
				return worker;

			else if(!job)
				continue;

			else if((job->coind && worker->coinid == job->coind->id) ||
				(job->remote && worker->remoteid == job->remote->id))
				return worker;
		}
	}

	return NULL;
}

static void share_add_worker(YAAMP_CLIENT *client, YAAMP_JOB *job, bool valid, char *ntime, double share_diff, int error_number)
{
//	check_job(job);
	g_list_worker.Enter();

	YAAMP_WORKER *worker = share_find_worker(client, job, valid);
	if(!worker)
	{
		worker = new YAAMP_WORKER;
		memset(worker, 0, sizeof(YAAMP_WORKER));

		worker->userid = client->userid;
		worker->workerid = client->workerid;
		worker->coinid = job? (job->coind? job->coind->id: 0): 0;
		worker->remoteid = job? (job->remote? job->remote->id: 0): 0;
		worker->valid = valid;
		worker->error_number = error_number;
		sscanf(ntime, "%x", &worker->ntime);
		worker->share_diff = share_diff;

		if(g_stratum_reconnect)
			worker->extranonce1 = !client->reconnecting && (client->reconnectable || client->extranonce_subscribe);
		else
			worker->extranonce1 = client->extranonce_subscribe;

		g_list_worker.AddTail(worker);
	}

	if(valid)
	{
		worker->difficulty += client->difficulty_actual / g_current_algo->diff_multiplier;
		client->speed += client->difficulty_actual / g_current_algo->diff_multiplier * 42;
	//	client->source->speed += client->difficulty_actual / g_current_algo->diff_multiplier * 42;
	}

	g_list_worker.Leave();
}

/////////////////////////////////////////////////////////////////////////

void share_add(YAAMP_CLIENT *client, YAAMP_JOB *job, bool valid, char *extranonce2, char *ntime, char *nonce, double share_diff, int error_number)
{
//	check_job(job);
	g_shares_counter++;
	share_add_worker(client, job, valid, ntime, share_diff, error_number);

	YAAMP_SHARE *share = new YAAMP_SHARE;
	memset(share, 0, sizeof(YAAMP_SHARE));

	share->jobid = job? job->id: 0;
	strcpy(share->extranonce2, extranonce2);
	strcpy(share->ntime, ntime);
	strcpy(share->nonce, nonce);
	strcpy(share->nonce1, client->extranonce1);

	g_list_share.AddTail(share);
}

YAAMP_SHARE *share_find(int jobid, char *extranonce2, char *ntime, char *nonce, char *nonce1)
{
	g_list_share.Enter();
	for(CLI li = g_list_share.first; li; li = li->next)
	{
		YAAMP_SHARE *share = (YAAMP_SHARE *)li->data;
		if(share->deleted) continue;

		if(	share->jobid == jobid &&
			!strcmp(share->extranonce2, extranonce2) && !strcmp(share->ntime, ntime) &&
			!strcmp(share->nonce, nonce) && !strcmp(share->nonce1, nonce1))
		{
			g_list_share.Leave();
			return share;
		}
	}

	g_list_share.Leave();
	return NULL;
}

void share_write(YAAMP_DB *db)
{
	int pid = getpid();
	int count = 0;
	int now = time(NULL);

	char buffer[1024*1024] = "insert into shares (userid, workerid, coinid, jobid, pid, valid, extranonce1, difficulty, share_diff, time, algo, error) values ";
	g_list_worker.Enter();

	for(CLI li = g_list_worker.first; li; li = li->next)
	{
		YAAMP_WORKER *worker = (YAAMP_WORKER *)li->data;
		if(worker->deleted) continue;

		if(!worker->workerid) {
			object_delete(worker);
			continue;
		}

		if(count) strcat(buffer, ",");
		sprintf(buffer+strlen(buffer), "(%d, %d, %d, %d, %d, %d, %d, %f, %f, %d, '%s', %d)",
			worker->userid, worker->workerid, worker->coinid, worker->remoteid, pid,
			worker->valid, worker->extranonce1, worker->difficulty, worker->share_diff, now, g_stratum_algo, worker->error_number);

		// todo: link max_ttf ?
		if((now - worker->ntime) > 15*60 || worker->ntime > now) {
			debuglog("ntime warning: value %d (%08x) offset %d secs from uid %d\n", worker->ntime, worker->ntime, (now - worker->ntime), worker->userid);
		}

		if(++count >= 1000)
		{
			db_query(db, buffer);

			strcpy(buffer, "insert into shares (userid, workerid, coinid, jobid, pid, valid, extranonce1, difficulty, share_diff, time, algo, error) values ");
			count = 0;
		}

		object_delete(worker);
	}

	g_list_worker.Leave();
	if(count) db_query(db, buffer);
}

void share_prune(YAAMP_DB *db)
{
	g_list_share.Enter();
	for(CLI li = g_list_share.first; li; li = li->next)
	{
		YAAMP_SHARE *share = (YAAMP_SHARE *)li->data;
		if(share->deleted) continue;

		YAAMP_JOB *job = (YAAMP_JOB *)object_find(&g_list_job, share->jobid);
		if(job) continue;

		object_delete(share);
	}

	g_list_share.Leave();
}

/////////////////////////////////////////////////////////////////////////////////////////////////////////////

void block_prune(YAAMP_DB *db)
{
	g_list_block.Enter();
	for(CLI li = g_list_block.first; li; li = li->next)
	{
		YAAMP_BLOCK *block = (YAAMP_BLOCK *)li->data;
		// Decred's regenerated header path does not establish the canonical chain
		// identity until notification; retain its existing confirmation gate.
		if(!block->durable && !block->confirmed && !strcmp(g_stratum_algo, "decred")) {
			if((block->created + 60 * 15) < time(NULL)) object_delete(block);
			continue;
		}
		if(!block->durable && !block_persist_accepted(db, block))
			continue;

		if(!block->confirmed)
		{
			int elapsed = 30;
			// slow block time...
			if(g_stratum_algo && !strcmp(g_stratum_algo, "decred")) elapsed = 60 * 15; // 15mn

			if((block->created + elapsed) < time(NULL)) {
				stratumlog("BLOCK_TRACKING_TIMEOUT schema=%s coin_id=%d db_algo=%s operational_algo=%s "
					"height=%d canonical_blockhash=%s pow_hash=%s block_id=%llu "
					"durable=true discard_state=in_memory_only\n",
					BLOCK_DURABILITY_SCHEMA, block->coinid, g_stratum_algo, block_operational_algo(),
					block->height, block->hash, block->hash2, block->blockid);
				object_delete(block);
			}

			continue;
		}
		stratumlog("BLOCK_CHAIN_CONFIRMED schema=%s coin_id=%d db_algo=%s operational_algo=%s "
			"height=%d canonical_blockhash=%s pow_hash=%s block_id=%llu durable=true\n",
			BLOCK_DURABILITY_SCHEMA, block->coinid, g_stratum_algo, block_operational_algo(),
			block->height, block->hash, block->hash2, block->blockid);
		object_delete(block);
	}

	g_list_block.Leave();
}

void block_add(int userid, int workerid, int coinid, int height, double diff, double diff_user, const char *h1, const char *h2, int segwit)
{
	YAAMP_BLOCK *block = new YAAMP_BLOCK;
	memset(block, 0, sizeof(YAAMP_BLOCK));

	block->created = time(NULL);
	block->userid = userid;
	block->workerid = workerid;
	block->coinid = coinid;
	block->height = height;
	block->difficulty = diff;
	block->difficulty_user = diff_user;
	block->segwit = segwit;

	strcpy(block->hash, h1);
	strcpy(block->hash1, h1);
	strcpy(block->hash2, h2);

	g_list_block.AddTail(block);
}

// called from blocknotify tool
bool block_confirm(int coinid, const char *blockhash)
{
	char hash[192];
	if(strlen(blockhash) < 64) return false;

	snprintf(hash, 161, "%s", blockhash);

	// required for multi algos wallets where pow hash is not the blockhash
	g_list_coind.Enter();
	for(CLI li = g_list_coind.first; li ; li = li->next)
	{
		YAAMP_COIND *coind = (YAAMP_COIND *)li->data;
		if(coind->id != coinid || coind->deleted) continue;

		if(coind->multialgos) {
			char params[192];
			sprintf(params, "[\"%s\"]", blockhash);
			json_value *json = rpc_call(&coind->rpc, "getblock", params);
			if(!json) {
				debuglog("%s: error getblock, no answer\n", __func__);
				break;
			}
			json_value *json_res = json_get_object(json, "result");
			if(!json_res) {
				debuglog("%s: error getblock, no result\n", __func__);
				break;
			}
			const char *h1 = json_get_string(json_res, "pow_hash"); // DGB, MYR, J
			const char *h2 = json_get_string(json_res, "mined_hash"); // XVG
			const char *h3 = json_get_string(json_res, "phash"); // XSH
			if (h1) snprintf(hash, 161, "%s", h1);
			else if (h2) snprintf(hash, 161, "%s", h2);
			else if (h3) snprintf(hash, 161, "%s", h3);
			//debuglog("%s: getblock %s -> pow %s\n", __func__, blockhash, hash);
			json_value_free(json);
			break;
		} else if (strcmp(coind->symbol,"ORB") == 0) {
			char params[192];
			sprintf(params, "[\"%s\"]", blockhash);
			json_value *json = rpc_call(&coind->rpc, "getblock", params);
			if(!json) {
				debuglog("%s: error getblock, no answer\n", __func__);
				break;
			}
			json_value *json_res = json_get_object(json, "result");
			if(!json_res) {
				debuglog("%s: error getblock, no result\n", __func__);
				break;
			}
			const char *h = json_get_string(json_res, "proofhash");
			if (h) snprintf(hash, 161, "%s", h);
			json_value_free(json);
			break;
		}
	}
	g_list_coind.Leave();

	for(CLI li = g_list_block.first; li; li = li->next)
	{
		YAAMP_BLOCK *block = (YAAMP_BLOCK *)li->data;
		if(block->coinid == coinid && !block->deleted)
		{
			if(strcmp(block->hash1, hash) && strcmp(block->hash2, hash)) continue;
			if (!block->confirmed) {
				debuglog("*** CONFIRMED %d : %s\n", block->height, block->hash2);
				if(!block->durable) strncpy(block->hash, blockhash, 65);
				else if(strcmp(block->hash, blockhash))
					stratumlog("BLOCK_CHAIN_HASH_MISMATCH schema=%s coin_id=%d db_algo=%s operational_algo=%s "
						"height=%d durable_blockhash=%s notified_blockhash=%s pow_hash=%s block_id=%llu\n",
						BLOCK_DURABILITY_SCHEMA, block->coinid, g_stratum_algo, block_operational_algo(),
						block->height, block->hash, blockhash, block->hash2, block->blockid);
				block->confirmed = true;
			}
			return true;
		}
	}
	return false;
}

//////////////////////////////////////////////////////////////////////////////////////////

YAAMP_SUBMIT *submit_add(int remoteid, double difficulty)
{
	YAAMP_SUBMIT *submit = new YAAMP_SUBMIT;
	memset(submit, 0, sizeof(YAAMP_SUBMIT));

	submit->created = time(NULL);
	submit->valid = true;
	submit->remoteid = remoteid;
	submit->difficulty = difficulty / g_current_algo->diff_multiplier;

	g_list_submit.AddTail(submit);
	return submit;
}

void submit_prune(YAAMP_DB *db)
{
	int count = 0;
	char buffer[128*1024] = "insert into jobsubmits (jobid, valid, difficulty, time, algo, status) values ";

	g_list_submit.Enter();
	for(CLI li = g_list_submit.first; li; li = li->next)
	{
		YAAMP_SUBMIT *submit = (YAAMP_SUBMIT *)li->data;

		if(count) strcat(buffer, ",");
		sprintf(buffer+strlen(buffer), "(%d, %d, %f, %d, '%s', 0)", submit->remoteid, submit->valid,
			submit->difficulty, (int)submit->created, g_stratum_algo);

		if(++count >= 1000)
		{
			db_query(db, buffer);

			strcpy(buffer, "insert into jobsubmits (jobid, valid, difficulty, time, algo, status) values ");
			count = 0;
		}

		object_delete(submit);
	}

	g_list_submit.Leave();
	if(count) db_query(db, buffer);
}
