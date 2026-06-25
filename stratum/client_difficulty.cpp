#include "stratum.h"

static bool sha256d_outbound_trace_enabled()
{
	return (g_current_algo && !strcmp(g_current_algo->name, "sha256")) || !strcmp(g_stratum_algo, "sha256");
}

static const char *sha256d_trace_safe_string(const char *input, char *output, size_t output_size)
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

static void sha256d_log_set_difficulty_trace(YAAMP_CLIENT *client, double difficulty)
{
	if(!sha256d_outbound_trace_enabled()) return;

	char safe_version[256];
	char params[64];
	char set_difficulty_json[256];
	uint64_t user_target = diff_to_target(difficulty);
	double difficulty_actual_before = client? client->difficulty_actual: 0;
	double difficulty_actual_after = client? client->difficulty_actual: 0;

	if(difficulty >= 1)
		snprintf(params, sizeof(params), "[%.0f]", difficulty);
	else
		snprintf(params, sizeof(params), "[%.3f]", difficulty);

	snprintf(set_difficulty_json, sizeof(set_difficulty_json),
		"{\"id\":null,\"method\":\"mining.set_difficulty\",\"params\":%s}", params);

	debuglog("SHA256D_SET_DIFFICULTY_TRACE client_ip=%s userid=%d workerid=%d worker_version=%s "
		"difficulty_sent=%.8f difficulty_actual_before=%.8f difficulty_actual_after=%.8f "
		"user_target=%016llx g_stratum_min_diff=%.8f g_stratum_max_diff=%.8f set_difficulty_json=%s\n",
		(client && client->sock)? client->sock->ip: "-",
		client? client->userid: 0,
		client? client->workerid: 0,
		sha256d_trace_safe_string(client? client->version: NULL, safe_version, sizeof(safe_version)),
		difficulty,
		difficulty_actual_before,
		difficulty_actual_after,
		(unsigned long long)user_target,
		g_stratum_min_diff,
		g_stratum_max_diff,
		set_difficulty_json);
}

double client_normalize_difficulty(double difficulty)
{
	if(difficulty < g_stratum_min_diff) difficulty = g_stratum_min_diff;
	else if(difficulty < 1) difficulty = floor(difficulty*1000/2)/1000*2;
	else if(difficulty > 1) difficulty = floor(difficulty/2)*2;
	if(difficulty > g_stratum_max_diff) difficulty = g_stratum_max_diff;
	return difficulty;
}

void client_record_difficulty(YAAMP_CLIENT *client)
{
	if(client->difficulty_remote)
	{
		client->last_submit_time = current_timestamp();
		return;
	}

	int e = current_timestamp() - client->last_submit_time;
	if(e < 500) e = 500;
	int p = 5;

	client->shares_per_minute = (client->shares_per_minute * (100 - p) + 60*1000*p/e) / 100;
	client->last_submit_time = current_timestamp();

//	debuglog("client->shares_per_minute %f\n", client->shares_per_minute);
}

void client_change_difficulty(YAAMP_CLIENT *client, double difficulty)
{
	if(difficulty <= 0) return;

	difficulty = client_normalize_difficulty(difficulty);
	if(difficulty <= 0) return;

//	debuglog("change diff to %f %f\n", difficulty, client->difficulty_actual);
	if(difficulty == client->difficulty_actual) return;

	uint64_t user_target = diff_to_target(difficulty);
	if(user_target >= YAAMP_MINDIFF && user_target <= YAAMP_MAXDIFF)
	{
		client->difficulty_actual = difficulty;
		client_send_difficulty(client, difficulty);
	}
}

void client_adjust_difficulty(YAAMP_CLIENT *client)
{
	if(client->difficulty_remote) {
		client_change_difficulty(client, client->difficulty_remote);
		return;
	}

	if(client->shares_per_minute > 100)
		client_change_difficulty(client, client->difficulty_actual*4);

	else if(client->difficulty_fixed)
		return;

	else if(client->shares_per_minute > 25)
		client_change_difficulty(client, client->difficulty_actual*2);

	else if(client->shares_per_minute > 20)
		client_change_difficulty(client, client->difficulty_actual*1.5);

	else if(client->shares_per_minute <  5)
		client_change_difficulty(client, client->difficulty_actual/2);
}

int client_send_difficulty(YAAMP_CLIENT *client, double difficulty)
{
//	debuglog("%s diff %f\n", client->sock->ip, difficulty);
	client->shares_per_minute = YAAMP_SHAREPERSEC;
	sha256d_log_set_difficulty_trace(client, difficulty);

	if(difficulty >= 1)
		client_call(client, "mining.set_difficulty", "[%.0f]", difficulty);
	else
		client_call(client, "mining.set_difficulty", "[%.3f]", difficulty);
	return 0;
}

void client_initialize_difficulty(YAAMP_CLIENT *client)
{
	char *p = strstr(client->password, "d=");
	char *p2 = strstr(client->password, "decred=");
	if(!p || p2) return;

	double diff = client_normalize_difficulty(atof(p+2));
	uint64_t user_target = diff_to_target(diff);

//	debuglog("%016llx target\n", user_target);
	if(user_target >= YAAMP_MINDIFF && user_target <= YAAMP_MAXDIFF)
	{
		client->difficulty_actual = diff;
		client->difficulty_fixed = true;
	}

}



