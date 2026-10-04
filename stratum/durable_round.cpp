#include "stratum.h"
#include "durable_round.h"
#include <curl/curl.h>
#include <cmath>
#include <cctype>
#include <string>

bool g_durable_rounds = false;
namespace {
// No reconnect inside transactions. g_db_mutex protects this connection only;
// round_lanes FOR UPDATE provides the cross-process ownership serialization.
struct Transaction {
 YAAMP_DB *db; bool committed;
 Transaction(YAAMP_DB *d): db(d), committed(false) { }
 ~Transaction() { if(!committed) mysql_query(&db->mysql, "ROLLBACK"); }
 bool commit() { committed = mysql_query(&db->mysql, "COMMIT") == 0; return committed; }
};
std::string quote(YAAMP_DB *db, const char *s) {
 std::string out(strlen(s)*2+1, '\0');
 out.resize(mysql_real_escape_string(&db->mysql, &out[0], s, strlen(s)));
 return "'" + out + "'";
}
bool query(YAAMP_DB *db, const std::string &s) {
 if(mysql_query(&db->mysql, s.c_str())) { stratumlog("BADPOOL_ROUND_SQL_FAILED error=%u\n",mysql_errno(&db->mysql)); return false; }
 // Stored procedures return a trailing status result, which must be consumed.
 MYSQL_RES *r=mysql_store_result(&db->mysql); if(r) mysql_free_result(r);
 while(mysql_more_results(&db->mysql)) {
  if(mysql_next_result(&db->mysql)>0) return false;
  r=mysql_store_result(&db->mysql); if(r) mysql_free_result(r);
 }
 return mysql_errno(&db->mysql)==0;
}
bool row(YAAMP_DB *db, const std::string &s, std::vector<std::string> &out) {
 if(mysql_query(&db->mysql,s.c_str())) { stratumlog("BADPOOL_ROUND_SQL_FAILED error=%u\n",mysql_errno(&db->mysql)); return false; }
 MYSQL_RES *r=mysql_store_result(&db->mysql); if(!r) return false;
 MYSQL_ROW value=mysql_fetch_row(r);
 if(value) for(unsigned n=0;n<mysql_num_fields(r);++n) out.push_back(value[n]?value[n]:"");
 mysql_free_result(r); return value != NULL;
}
std::string num(unsigned long long n) { return std::to_string(n); }
std::string scope(YAAMP_JOB *job) {
 return "coin_id="+num(job->coind->id)+" AND algo='"+std::string(g_stratum_algo)+"'";
}
bool lock_lane(YAAMP_DB *db, YAAMP_JOB *job) {
 return query(db,"CALL round_lock("+num(job->coind->id)+","+quote(db,g_stratum_algo)+")");
}
size_t response_write(char *data,size_t size,size_t count,void *context) {
 std::string *out=static_cast<std::string *>(context);
 if(size && count>65536/size) return 0;
 size_t n=size*count; if(out->size()+n>65536) return 0;
 out->append(data,n); return n;
}
// A fresh connection, a unique intent request ID, no redirects, no retry loop.
// Transport failures and malformed/mismatched envelopes are always ambiguous.
struct Endpoint { std::string url,authorization,certificate; };
std::string original_submit(const Endpoint &endpoint,unsigned long long intent,const char *block,std::string &response) {
 CURL *curl=curl_easy_init(); if(!curl) return "";
 std::string request_id="badpool-round-"+num(intent)+"-attempt-1";
 std::string request="{\"method\":\"submitblock\",\"params\":[\""+std::string(block)+"\"],\"id\":\""+request_id+"\"}";
 struct curl_slist *headers=NULL;
 headers=curl_slist_append(headers,"Content-Type: application/json");
 std::string auth="Authorization: Basic "+endpoint.authorization;
 headers=curl_slist_append(headers,auth.c_str()); headers=curl_slist_append(headers,"Expect:");
 curl_easy_setopt(curl,CURLOPT_URL,endpoint.url.c_str());
 curl_easy_setopt(curl,CURLOPT_HTTPHEADER,headers);
 curl_easy_setopt(curl,CURLOPT_POSTFIELDS,request.c_str());
 curl_easy_setopt(curl,CURLOPT_POSTFIELDSIZE,(long)request.size());
 curl_easy_setopt(curl,CURLOPT_FOLLOWLOCATION,0L);
 curl_easy_setopt(curl,CURLOPT_FRESH_CONNECT,1L);
 curl_easy_setopt(curl,CURLOPT_FORBID_REUSE,1L);
 curl_easy_setopt(curl,CURLOPT_NOSIGNAL,1L);
 curl_easy_setopt(curl,CURLOPT_CONNECTTIMEOUT,5L);
 curl_easy_setopt(curl,CURLOPT_TIMEOUT,30L);
 curl_easy_setopt(curl,CURLOPT_WRITEFUNCTION,response_write);
 curl_easy_setopt(curl,CURLOPT_WRITEDATA,&response);
 if(!endpoint.certificate.empty()) curl_easy_setopt(curl,CURLOPT_CAINFO,endpoint.certificate.c_str());
 CURLcode rc=curl_easy_perform(curl); long status=0;
 curl_easy_getinfo(curl,CURLINFO_RESPONSE_CODE,&status);
 curl_slist_free_all(headers); curl_easy_cleanup(curl);
 if(rc!=CURLE_OK || status!=200) { response.clear(); return ""; }
 json_value *j=json_parse(response.c_str(),response.size());
 if(!j || j->type!=json_object) { if(j) json_value_free(j); response.clear(); return ""; }
 json_value *id=json_get_val(j,"id"), *result=json_get_val(j,"result"), *error=json_get_val(j,"error");
 std::string outcome;
 if(id && id->type==json_string && request_id==id->u.string.ptr && error && error->type==json_null && result) {
  if(result->type==json_null) outcome="ACCEPTED";
  else if(result->type==json_string) {
   std::string value=result->u.string.ptr;
   // Badcoin BIP22ValidationResult returns a reject reason for invalid state.
   // All duplicate/inconclusive variants describe insufficient invocation history.
   if(!value.empty() && value.find("duplicate")==std::string::npos && value.find("inconclusive")==std::string::npos)
    outcome="REJECTED";
  }
 }
 json_value_free(j);
 // Retain only conclusive envelopes; never persist arbitrary daemon error text.
 if(outcome.empty()) response.clear();
 return outcome;
}
}

bool round_enabled(YAAMP_JOB *job) {
 return g_durable_rounds && job && job->coind && !strcmp(job->coind->symbol,"BAD");
}

bool round_startup(YAAMP_DB *db) {
 std::vector<std::string> tables,commissioned,revision,engines;
 if(!row(db,"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='round_lanes'",tables)) return false;
 if(tables[0]=="0") return !g_durable_rounds;
 if(!row(db,"SELECT COUNT(*) FROM round_lanes WHERE algo="+quote(db,g_stratum_algo)+" AND current_round IS NOT NULL",commissioned)) return false;
 if(!g_durable_rounds && commissioned[0]!="0") {
  stratumlog("BADPOOL_ROUND_STARTUP_REFUSED db_algo=%s reason=commissioned_lane_cannot_revert_to_legacy\n",g_stratum_algo);
  return false;
 }
 if(!g_durable_rounds) return true;
 if(!row(db,"SELECT COUNT(*) FROM round_schema_version WHERE version=2",revision) || revision[0]!="1" ||
    !row(db,"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND engine='InnoDB' AND table_name IN ('blocks','accounts','coins','earnings','live_block_candidates','live_block_attributions')",engines) || engines[0]!="6") return false;
 return round_recover(db);
}

bool round_journal(YAAMP_CLIENT *client,YAAMP_JOB *job,YAAMP_JOB_VALUES *values,ROUND_RECEIPT *receipt) {
 memset(receipt,0,sizeof(*receipt));
 if(!round_enabled(job)) return true;
 if(job->templ->auxs_size || strlen(values->header_be)!=160 || !ishexa(values->header_be,160) || client->userid<=0 || client->workerid<=0 ||
    !std::isfinite(client->difficulty_actual) || client->difficulty_actual<=0 || g_current_algo->diff_multiplier<=0 ||
    (strcmp(g_stratum_algo,"scrypt") && strcmp(g_stratum_algo,"yescrypt") && strcmp(g_stratum_algo,"skein") &&
     strcmp(g_stratum_algo,"sha256") && strcmp(g_stratum_algo,"badcoin-groestl"))) return false;
 char hash[128]={0},identity[65]={0};
 char header[161]={0};
 for(int n=0;n<80;++n) sprintf(header+n*2,"%02x",values->header_bin[n]);
 for(int n=0;n<160;++n) if(header[n]!=std::tolower(static_cast<unsigned char>(values->header_be[n]))) return false;
 sha256_double_hash_hex((char *)values->header_bin,hash,80); string_be(hash,identity);
 double assigned=client->difficulty_actual/g_current_algo->diff_multiplier;
 receipt->assigned_weight=assigned;
 CommonLock(&g_db_mutex);
 bool success=false;
 {
  YAAMP_DB *db=g_db; Transaction tx(db);
  std::vector<std::string> lane,existing,account;
  if(query(db,"START TRANSACTION") && lock_lane(db,job) &&
     row(db,"SELECT current_round,next_sequence FROM round_lanes WHERE "+scope(job)+" FOR UPDATE",lane)) {
   if(row(db,"SELECT id,round_id,sequence,header_hex,userid,workerid FROM accepted_work WHERE "+scope(job)+" AND work_hash="+quote(db,identity)+" FOR UPDATE",existing)) {
    // Replay never changes the original account, worker, difficulty, round or sequence.
    if(existing[3]==header && existing[4]==num(client->userid) && existing[5]==num(client->workerid)) {
     receipt->work_id=std::stoull(existing[0]); receipt->round_id=std::stoull(existing[1]); receipt->sequence=std::stoull(existing[2]);
     receipt->duplicate=true; success=tx.commit();
    }
   } else if(mysql_errno(&db->mysql)==0 && row(db,"SELECT IFNULL(no_fees,0),IFNULL(donation,0) FROM accounts WHERE id="+num(client->userid)+" FOR UPDATE",account)) {
    receipt->round_id=std::stoull(lane[0]); receipt->sequence=std::stoull(lane[1])+1;
    char difficulty[64]; snprintf(difficulty,sizeof(difficulty),"%.17g",assigned);
    success=query(db,"INSERT INTO accepted_work(coin_id,algo,work_hash,header_hex,round_id,original_round_id,sequence,userid,workerid,assigned_difficulty,no_fees,donation,state,accepted_at) VALUES("+
     num(job->coind->id)+","+quote(db,g_stratum_algo)+","+quote(db,identity)+","+quote(db,header)+","+num(receipt->round_id)+","+num(receipt->round_id)+","+num(receipt->sequence)+","+
     num(client->userid)+","+num(client->workerid)+","+difficulty+","+account[0]+","+account[1]+",'OWNED_BY_ROUND',UTC_TIMESTAMP(6))");
    receipt->work_id=mysql_insert_id(&db->mysql);
    success=success && query(db,"UPDATE round_lanes SET next_sequence="+num(receipt->sequence)+" WHERE "+scope(job)) && tx.commit();
   }
  }
 }
 CommonUnlock(&g_db_mutex);
 return success;
}

bool round_prepare(YAAMP_CLIENT *,YAAMP_JOB *job,YAAMP_JOB_VALUES *values,ROUND_RECEIPT *receipt,
 const char *block,double difficulty,double winning) {
 if(!round_enabled(job)) return true;
 if(receipt->duplicate || !receipt->work_id || strlen(block)>8*1024*1024 || strlen(block)<160 ||
    strncmp(block,values->header_be,160) || !ishexa((char *)block,strlen(block))) return false;
 char hash[128]={0},canonical[65]={0}; sha256_double_hash_hex((char *)values->header_bin,hash,80); string_be(hash,canonical);
 CommonLock(&g_db_mutex); bool success=false;
 {
  YAAMP_DB *db=g_db; Transaction tx(db); std::vector<std::string> lane;
  if(query(db,"START TRANSACTION") && lock_lane(db,job) && row(db,"SELECT current_round,next_sequence,pending_intent FROM round_lanes WHERE "+scope(job)+" FOR UPDATE",lane) &&
    lane[2].empty() && lane[0]==num(receipt->round_id)) {
   // Acceptance already has an authoritative sequence. Cutoff includes every
   // acceptance committed before this second lane-lock acquisition; no gaps.
   receipt->sequence=std::stoull(lane[1]);
   success=query(db,"INSERT INTO share_rounds(coin_id,algo,state,created_at) VALUES("+num(job->coind->id)+","+quote(db,g_stratum_algo)+",'OPEN',UTC_TIMESTAMP(6))");
   unsigned long long following=mysql_insert_id(&db->mysql);
   char weights[128]; snprintf(weights,sizeof(weights),"%.17g,%.17g",difficulty,winning);
   success=success && query(db,"INSERT INTO round_intents(coin_id,algo,round_id,continuation_round,cutoff,work_id,blockhash,height,block_hex,block_difficulty,winning_difficulty,segwit,created_at,state) VALUES("+
    num(job->coind->id)+","+quote(db,g_stratum_algo)+","+num(receipt->round_id)+","+num(following)+","+num(receipt->sequence)+","+num(receipt->work_id)+","+quote(db,canonical)+","+
    num(job->templ->height)+","+quote(db,block)+","+weights+","+num(job->templ->has_segwit_txs?1:0)+",UTC_TIMESTAMP(6),'PREPARED')");
   receipt->intent_id=mysql_insert_id(&db->mysql);
   success=success && query(db,"UPDATE share_rounds SET state='INTENT_PENDING',cutoff="+num(receipt->sequence)+" WHERE id="+num(receipt->round_id)) &&
    query(db,"UPDATE round_lanes SET current_round="+num(following)+",pending_intent="+num(receipt->intent_id)+" WHERE "+scope(job)) && tx.commit();
  }
 }
 CommonUnlock(&g_db_mutex); return success;
}

bool round_dispatch(YAAMP_COIND *coin,const ROUND_RECEIPT *receipt,const char *block) {
 if(!receipt->intent_id) return false;
 // Coin configuration is updated under this list lock. Snapshot once so the
 // exact endpoint recorded before dispatch is also the endpoint actually used.
 g_list_coind.Enter();
 bool protocol_ok=!coin->usegetwork && coin->hassubmitblock;
 Endpoint endpoint{std::string(coin->rpc.ssl?"https://":"http://")+coin->rpc.host+":"+num(coin->rpc.port),coin->rpc.credential,coin->rpc.cert};
 g_list_coind.Leave();
 if(!protocol_ok) return false;
 CommonLock(&g_db_mutex);
 // This CAS COMMIT is the dispatch boundary. A lost COMMIT acknowledgement
 // prevents sending; durable recovery nevertheless conservatively holds it.
 bool marked=query(g_db,"UPDATE round_intents SET dispatch_state='MAY_HAVE_DISPATCHED',state='INTENT_PENDING',dispatched_at=UTC_TIMESTAMP(6),daemon_identity="+
  quote(g_db,endpoint.url.c_str())+" WHERE id="+num(receipt->intent_id)+" AND coin_id="+num(coin->id)+" AND algo="+quote(g_db,g_stratum_algo)+
  " AND block_hex="+quote(g_db,block)+" AND state='PREPARED' AND dispatch_state='NEVER_DISPATCHED'") && mysql_affected_rows(&g_db->mysql)==1;
 CommonUnlock(&g_db_mutex); if(!marked) return false;
 std::string response,outcome=original_submit(endpoint,receipt->intent_id,block,response);
 CommonLock(&g_db_mutex); bool resolved=false;
 if(!outcome.empty()) {
  // Capture separately from sealing: a crash during sealing leaves the original
  // authoritative envelope available for bounded accounting-only recovery.
  bool saved=query(g_db,"UPDATE round_intents SET daemon_outcome="+quote(g_db,outcome.c_str())+",original_response="+quote(g_db,response.c_str())+
   ",response_captured_at=UTC_TIMESTAMP(6),resolution_source='DAEMON_ORIGINAL_RESPONSE',resolution_evidence='matched original single attempt' WHERE id="+
   num(receipt->intent_id)+" AND state IN ('INTENT_PENDING','AMBIGUOUS_HOLD') AND original_response IS NULL") && mysql_affected_rows(&g_db->mysql)==1;
  if(saved) { Transaction tx(g_db); resolved=query(g_db,"START TRANSACTION") && query(g_db,"CALL round_resolve("+num(receipt->intent_id)+")") && tx.commit(); }
 }
 if(!resolved) {
  Transaction tx(g_db);
  if(query(g_db,"START TRANSACTION") && query(g_db,"CALL round_hold("+num(receipt->intent_id)+",'original_outcome_or_seal_not_durable')")) tx.commit();
  stratumlog("BADPOOL_ROUND_HOLD intent_id=%llu coin_id=%d db_algo=%s downstream=BLOCKED inspection=badpool-round-status\n",receipt->intent_id,coin->id,g_stratum_algo);
 }
 CommonUnlock(&g_db_mutex);
 return resolved && outcome=="ACCEPTED";
}

bool round_resume_never_dispatched(YAAMP_DB *db,YAAMP_COIND *coin) {
 if(!g_durable_rounds || strcmp(coin->symbol,"BAD")) return false;
 std::vector<std::string> intent;
 if(!row(db,"SELECT I.id,I.block_hex FROM round_intents I JOIN round_lanes L ON L.pending_intent=I.id WHERE I.coin_id="+
  num(coin->id)+" AND I.algo="+quote(db,g_stratum_algo)+" AND I.state='PREPARED' AND I.dispatch_state='NEVER_DISPATCHED' LIMIT 1",intent)) return false;
 ROUND_RECEIPT receipt={};receipt.intent_id=std::stoull(intent[0]);
 // The marker CAS rechecks the exact persisted payload and identity. Concurrent
 // recovery processes can observe PREPARED, but only one can dispatch it.
 return round_dispatch(coin,&receipt,intent[1].c_str());
}

bool round_recover(YAAMP_DB *db) {
 // Bounded startup/maintenance recovery. Never blind replay submitblock.
 std::vector<std::string> ids;
 if(mysql_query(&db->mysql,"SELECT id FROM round_intents WHERE (state IN ('INTENT_PENDING','AMBIGUOUS_HOLD') AND response_captured_at IS NOT NULL) OR (state='INTENT_PENDING' AND dispatched_at<UTC_TIMESTAMP(6)-INTERVAL 60 SECOND) ORDER BY id LIMIT 100")) return false;
 MYSQL_RES *r=mysql_store_result(&db->mysql); if(!r) return false; MYSQL_ROW v;
 while((v=mysql_fetch_row(r))) ids.push_back(v[0]);
 mysql_free_result(r);
 bool success=true;
 for(const std::string &id:ids) {
  Transaction tx(db); std::vector<std::string> proof;
  bool ok=query(db,"START TRANSACTION") && row(db,"SELECT response_captured_at FROM round_intents WHERE id="+id,proof);
  if(ok) ok=query(db,proof[0].empty()?"CALL round_hold("+id+",'dispatch_deadline_elapsed_without_durable_response')":"CALL round_resolve("+id+")");
  success=(ok && tx.commit()) && success;
 }
 return success;
}
