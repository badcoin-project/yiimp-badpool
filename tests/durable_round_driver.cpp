// Uses production journal/intent/dispatch/recovery code and a disposable MySQL DB.
#include "stratum.h"
#include "durable_round.h"
#include <openssl/sha.h>
#include <cctype>
YAAMP_DB *g_db;
pthread_mutex_t g_db_mutex=PTHREAD_MUTEX_INITIALIZER;
char g_stratum_algo[256];
uint64_t g_shares_counter=0; bool g_stratum_reconnect=false;
CommonList g_list_share,g_list_worker,g_list_coind;
CommonList::CommonList():count(0),first(NULL),last(NULL) {
 pthread_mutexattr_t attr;pthread_mutexattr_init(&attr);pthread_mutexattr_settype(&attr,PTHREAD_MUTEX_RECURSIVE);
 pthread_mutex_init(&mutex,&attr);pthread_mutexattr_destroy(&attr);
}
CommonList::~CommonList() { }
void CommonList::Enter() { pthread_mutex_lock(&mutex); }
void CommonList::Leave() { pthread_mutex_unlock(&mutex); }
CLI CommonList::AddTail(void *data) { Enter();CLI n=new COMMONLISTITEM{data,NULL,last};if(last)last->next=n;else first=n;last=n;count++;Leave();return n; }
void object_delete(YAAMP_OBJECT *object) { object->deleted=true; }
void debuglog(const char *,...) { }
void db_query(YAAMP_DB *db,const char *format,...) { char q[1024*1024];va_list a;va_start(a,format);vsnprintf(q,sizeof(q),format,a);va_end(a);if(mysql_query(&db->mysql,q)) {fprintf(stderr,"aggregate SQL error %s\n",mysql_error(&db->mysql));exit(1);} }
YAAMP_ALGO algorithm={}; YAAMP_ALGO *g_current_algo=&algorithm;
void CommonLock(pthread_mutex_t *m) { pthread_mutex_lock(m); }
void CommonUnlock(pthread_mutex_t *m) { pthread_mutex_unlock(m); }
void stratumlog(const char *format,...) { va_list a;va_start(a,format);vfprintf(stderr,format,a);va_end(a); }
void sha256_double_hash_hex(const char *in,char *out,unsigned len) {
 unsigned char a[32],b[32];SHA256((const unsigned char *)in,len,a);SHA256(a,32,b);
 for(int n=0;n<32;++n) sprintf(out+n*2,"%02x",b[n]);
}
void string_be(const char *in,char *out) { for(int n=0;n<32;++n) {out[n*2]=in[(31-n)*2];out[n*2+1]=in[(31-n)*2+1];}out[64]=0; }
bool ishexa(char *s,int n) { for(int i=0;i<n;++i) if(!isxdigit((unsigned char)s[i])) return false;return true; }
int main(int argc,char **argv) {
 if(argc<3) return 64;
 YAAMP_DB database;g_db=&database;mysql_init(&g_db->mysql);
 if(!mysql_real_connect(&g_db->mysql,"localhost","root","","round_test",0,argv[1],0)) { fprintf(stderr,"%s\n",mysql_error(&g_db->mysql));return 65; }
 g_durable_rounds=true;
 if(!strcmp(argv[2],"startup")) {
  strcpy(g_stratum_algo,argv[3]);g_durable_rounds=getenv("ROUND_TEST_DISABLED")==NULL;
  bool ok=round_startup(g_db);mysql_close(&g_db->mysql);return ok?0:1;
 }
 if(!strcmp(argv[2],"recover")) { bool ok=round_recover(g_db);mysql_close(&g_db->mysql);return ok?0:1; }
 // journal/prepare: algo user worker difficulty unique_header_number; dispatch: algo intent port block_hex
 strcpy(g_stratum_algo,argv[3]);algorithm.diff_multiplier=1;
 YAAMP_COIND coin={};coin.id=1;strcpy(coin.symbol,"BAD");coin.hassubmitblock=true;
 YAAMP_JOB_TEMPLATE templ={};templ.height=100;
 YAAMP_JOB job={};job.coind=&coin;job.templ=&templ;
 ROUND_RECEIPT receipt={};
 if(!strcmp(argv[2],"aggregate")) {
  YAAMP_CLIENT client={};client.userid=1;client.workerid=1;client.difficulty_actual=2;
  char nonce[]="00000000",ntime[32];sprintf(ntime,"%08x",(unsigned)time(NULL));
  share_add(&client,&job,true,nonce,ntime,nonce,999,0,1,2);
  share_add(&client,&job,true,nonce,ntime,nonce,888,0,2,3);
  share_write(g_db);mysql_close(&g_db->mysql);return 0;
 }
 bool ok;
 if(!strcmp(argv[2],"dispatch") || !strcmp(argv[2],"resume")) {
  receipt.intent_id=strtoull(argv[4],NULL,10);coin.rpc.port=atoi(argv[5]);strcpy(coin.rpc.host,"127.0.0.1");
  ok=!strcmp(argv[2],"resume")?round_resume_never_dispatched(g_db,&coin):round_dispatch(&coin,&receipt,argv[6]);
 } else {
  YAAMP_CLIENT client={};client.userid=atoi(argv[4]);client.workerid=atoi(argv[5]);client.difficulty_actual=atof(argv[6]);
  YAAMP_JOB_VALUES values={}; memset(values.header_bin,0,80);
  unsigned long long unique=strtoull(argv[7],NULL,10);memcpy(values.header_bin,&unique,sizeof(unique));
  for(int n=0;n<80;++n) sprintf(values.header_be+n*2,"%02x",values.header_bin[n]);
  if(getenv("ROUND_TEST_UPPERCASE")) for(int n=0;n<160;++n) values.header_be[n]=toupper(values.header_be[n]);
  ok=round_journal(&client,&job,&values,&receipt);
  if(ok && !strcmp(argv[2],"prepare")) ok=round_prepare(&client,&job,&values,&receipt,values.header_be,100,999);
  printf("{\"ok\":%s,\"work\":%llu,\"round\":%llu,\"sequence\":%llu,\"intent\":%llu,\"duplicate\":%s,\"block\":\"%s\"}\n",
    ok?"true":"false",receipt.work_id,receipt.round_id,receipt.sequence,receipt.intent_id,receipt.duplicate?"true":"false",values.header_be);
 }
 mysql_close(&g_db->mysql);return ok?0:1;
}
