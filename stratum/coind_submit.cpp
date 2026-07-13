#include "stratum.h"

static const char *submitblock_json_type_name(json_value *json)
{
	if(!json) return "missing";
	switch(json->type)
	{
		case json_null: return "null";
		case json_string: return "string";
		case json_boolean: return "boolean";
		case json_integer: return "number";
		case json_double: return "number";
		case json_object: return "object";
		case json_array: return "array";
		default: return "unknown";
	}
}

static void submitblock_copy_sanitized(const char *input, char *out, size_t outlen)
{
	if(!out || !outlen) return;
	out[0] = '\0';
	if(!input) return;

	size_t o = 0;
	for(size_t i = 0; input[i] && o + 1 < outlen && o < 160; i++)
	{
		unsigned char c = (unsigned char)input[i];
		out[o++] = (c <= 0x20 || c >= 0x7f)? '_': input[i];
	}
	out[o] = '\0';
}

static void submitblock_json_scalar_value(json_value *json, char *out, size_t outlen)
{
	if(!out || !outlen) return;
	out[0] = '\0';
	if(!json) return;

	switch(json->type)
	{
		case json_string:
			submitblock_copy_sanitized(json->u.string.ptr, out, outlen);
			break;
		case json_boolean:
			snprintf(out, outlen, "%s", json->u.boolean? "true": "false");
			break;
		case json_integer:
			snprintf(out, outlen, "%lld", (long long)json->u.integer);
			break;
		case json_double:
			snprintf(out, outlen, "%.8f", json->u.dbl);
			break;
		default:
			break;
	}
}

static uint64_t submitblock_monotonic_us()
{
	struct timespec ts;
	clock_gettime(CLOCK_MONOTONIC, &ts);
	return (uint64_t)ts.tv_sec * 1000000ULL + (uint64_t)ts.tv_nsec / 1000ULL;
}

static void submit_observation_init(STRATUM_COIND_SUBMIT_OBSERVATION *observation, const char *method)
{
	if(!observation) return;
	memset(observation, 0, sizeof(*observation));
	snprintf(observation->method, sizeof(observation->method), "%s", method? method: "unknown");
	snprintf(observation->result_type, sizeof(observation->result_type), "missing");
	snprintf(observation->error_type, sizeof(observation->error_type), "missing");
}

static void submit_observation_failure(STRATUM_COIND_SUBMIT_OBSERVATION *observation,
	const char *error_type, const char *error_code, const char *error_message, uint64_t started_us)
{
	if(!observation) return;
	observation->rpc_returned = false;
	observation->accepted = false;
	snprintf(observation->error_type, sizeof(observation->error_type), "%s", error_type? error_type: "unknown");
	snprintf(observation->error_code, sizeof(observation->error_code), "%s", error_code? error_code: "-");
	submitblock_copy_sanitized(error_message, observation->error_message, sizeof(observation->error_message));
	observation->elapsed_us = submitblock_monotonic_us() - started_us;
}

static void submit_observation_json(STRATUM_COIND_SUBMIT_OBSERVATION *observation,
	json_value *json_result, json_value *json_error, bool accepted, uint64_t started_us)
{
	if(!observation) return;
	observation->rpc_returned = true;
	observation->accepted = accepted;
	snprintf(observation->result_type, sizeof(observation->result_type), "%s", submitblock_json_type_name(json_result));
	snprintf(observation->error_type, sizeof(observation->error_type), "%s", submitblock_json_type_name(json_error));
	submitblock_json_scalar_value(json_result, observation->result_value, sizeof(observation->result_value));
	submitblock_json_scalar_value(
		(json_error && json_error->type == json_object)? json_get_val(json_error, "code"): NULL,
		observation->error_code, sizeof(observation->error_code));
	submitblock_json_scalar_value(
		(json_error && json_error->type == json_object)? json_get_val(json_error, "message"): NULL,
		observation->error_message, sizeof(observation->error_message));
	observation->elapsed_us = submitblock_monotonic_us() - started_us;
}

static void log_submitblock_response(YAAMP_COIND *coind, const char *method,
	json_value *json_result, json_value *json_error, bool returned_success)
{
	char result_value[192];
	char error_code[64];
	char error_message[192];
	submitblock_json_scalar_value(json_result, result_value, sizeof(result_value));
	submitblock_json_scalar_value(
		(json_error && json_error->type == json_object)? json_get_val(json_error, "code"): NULL,
		error_code, sizeof(error_code));
	submitblock_json_scalar_value(
		(json_error && json_error->type == json_object)? json_get_val(json_error, "message"): NULL,
		error_message, sizeof(error_message));

	stratumlog("submitblock_observe method=%s algo=%s coinid=%d coin=%s symbol=%s height=%d "
		"result_type=%s result_value=%s error_type=%s error_code=%s error_message=%s return_success=%d\n",
		method? method: "unknown",
		(coind && coind->algo[0])? coind->algo: g_stratum_algo,
		coind? coind->id: 0,
		(coind && coind->name[0])? coind->name: "-",
		(coind && coind->symbol[0])? coind->symbol: "-",
		coind? coind->height: 0,
		submitblock_json_type_name(json_result),
		result_value[0]? result_value: "-",
		submitblock_json_type_name(json_error),
		error_code[0]? error_code: "-",
		error_message[0]? error_message: "-",
		returned_success? 1: 0);
}

static bool coind_submitwork_observed(YAAMP_COIND *coind, const char *block,
	STRATUM_COIND_SUBMIT_OBSERVATION *observation)
{
	const uint64_t started_us = submitblock_monotonic_us();
	submit_observation_init(observation, "getwork");
	int paramlen = strlen(block);

	char *params = (char *)malloc(paramlen+1024);
	if(!params)
	{
		debuglog("%s: OOM!\n", __func__);
		submit_observation_failure(observation, "local", "ENOMEM", "submit_params_allocation_failed", started_us);
		return false;
	}

	sprintf(params, "[\"%s\"]", block);
	json_value *json = rpc_call(&coind->rpc, "getwork", params);
	if(!json)
	{
		debuglog("%s: retry\n", __func__);
		usleep(500*YAAMP_MS);
		json = rpc_call(&coind->rpc, "getwork", params);
	}
	free(params);

	if(!json)
	{
		debuglog("%s: error, no answer\n", __func__);
		submit_observation_failure(observation, "transport", "NO_RESPONSE", "rpc_call_returned_no_response", started_us);
		return false;
	}

	json_value *json_error = json_get_object(json, "error");
	json_value *json_result = json_get_object(json, "result");
	bool b = json_result && json_result->type == json_boolean && json_result->u.boolean;
	submit_observation_json(observation, json_result, json_error, b, started_us);
	log_submitblock_response(coind, "getwork", json_result, json_error, b);
	json_value_free(json);
	return b;
}

static bool coind_submitblock_observed(YAAMP_COIND *coind, const char *block,
	STRATUM_COIND_SUBMIT_OBSERVATION *observation)
{
	const uint64_t started_us = submitblock_monotonic_us();
	submit_observation_init(observation, "submitblock");
	int paramlen = strlen(block);

	char *params = (char *)malloc(paramlen+1024);
	if(!params)
	{
		submit_observation_failure(observation, "local", "ENOMEM", "submit_params_allocation_failed", started_us);
		return false;
	}

	sprintf(params, "[\"%s\"]", block);
	json_value *json = rpc_call(&coind->rpc, "submitblock", params);
	free(params);

	if(!json)
	{
		submit_observation_failure(observation, "transport", "NO_RESPONSE", "rpc_call_returned_no_response", started_us);
		return false;
	}

	json_value *json_error = json_get_object(json, "error");
	json_value *json_result = json_get_object(json, "result");
	bool has_error = json_error && json_error->type != json_null;
	bool b = !has_error && json_result && json_result->type == json_null;

	submit_observation_json(observation, json_result, json_error, b, started_us);
	log_submitblock_response(coind, "submitblock", json_result, json_error, b);
	json_value_free(json);
	return b;
}

static bool coind_submitblocktemplate_observed(YAAMP_COIND *coind, const char *block,
	STRATUM_COIND_SUBMIT_OBSERVATION *observation)
{
	const uint64_t started_us = submitblock_monotonic_us();
	submit_observation_init(observation, "submitblocktemplate");
	int paramlen = strlen(block);

	char *params = (char *)malloc(paramlen+1024);
	if(!params)
	{
		submit_observation_failure(observation, "local", "ENOMEM", "submit_params_allocation_failed", started_us);
		return false;
	}

	sprintf(params, "[{\"mode\": \"submit\", \"data\": \"%s\"}]", block);
	json_value *json = rpc_call(&coind->rpc, "getblocktemplate", params);
	free(params);

	if(!json)
	{
		submit_observation_failure(observation, "transport", "NO_RESPONSE", "rpc_call_returned_no_response", started_us);
		return false;
	}

	json_value *json_error = json_get_object(json, "error");
	json_value *json_result = json_get_object(json, "result");
	bool has_error = json_error && json_error->type != json_null;
	bool b = !has_error && json_result && json_result->type == json_null;

	submit_observation_json(observation, json_result, json_error, b, started_us);
	log_submitblock_response(coind, "submitblocktemplate", json_result, json_error, b);
	json_value_free(json);
	return b;
}

bool coind_submit(YAAMP_COIND *coind, const char *block)
{
	return coind_submit(coind, block, NULL);
}

bool coind_submit(YAAMP_COIND *coind, const char *block,
	STRATUM_COIND_SUBMIT_OBSERVATION *observation)
{
	if(coind->usegetwork) // DCR
		return coind_submitwork_observed(coind, block, observation);
	else if(coind->hassubmitblock)
		return coind_submitblock_observed(coind, block, observation);
	else
		return coind_submitblocktemplate_observed(coind, block, observation);
}

bool coind_submitgetauxblock(YAAMP_COIND *coind, const char *hash, const char *block)
{
	int paramlen = strlen(block);

	char *params = (char *)malloc(paramlen+1024);
	if(!params) return false;

	sprintf(params, "[\"%s\",\"%s\"]", hash, block);
	json_value *json = rpc_call(&coind->rpc, "getauxblock", params);

	free(params);
	if(!json) return false;

	json_value *json_error = json_get_object(json, "error");
	if(json_error && json_error->type != json_null)
	{
		const char *p = json_get_string(json_error, "message");
		if(p) stratumlog("ERROR %s %s\n", coind->name, p);

	//	job_reset();
		json_value_free(json);
		return false;
	}

	json_value *json_result = json_get_object(json, "result");
	bool b = json_result && json_result->type == json_boolean && json_result->u.boolean;
	// some auxpow coins return error:null, result: null on success
	if(!b)
		b = json_result && json_result->type == json_null;

	json_value_free(json);
	return b;
}
