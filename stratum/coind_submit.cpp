
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

static void submitblock_json_scalar_value(json_value *json, char *out, size_t outlen)
{
	if(!out || !outlen) return;
	out[0] = '\0';
	if(!json) return;

	switch(json->type)
	{
		case json_string:
			snprintf(out, outlen, "%.160s", json->u.string.ptr? json->u.string.ptr: "");
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

static void log_submitblock_response(YAAMP_COIND *coind, const char *method, json_value *json_result, json_value *json_error, bool returned_success)
{
	char result_value[192];
	char error_code[64];
	char error_message[192];
	submitblock_json_scalar_value(json_result, result_value, sizeof(result_value));
	submitblock_json_scalar_value((json_error && json_error->type == json_object)? json_get_val(json_error, "code"): NULL, error_code, sizeof(error_code));
	submitblock_json_scalar_value((json_error && json_error->type == json_object)? json_get_val(json_error, "message"): NULL, error_message, sizeof(error_message));

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

bool coind_submitwork(YAAMP_COIND *coind, const char *block)
{
	int paramlen = strlen(block);

	char *params = (char *)malloc(paramlen+1024);
	if(!params) {
		debuglog("%s: OOM!\n", __func__);
		return false;
	}

	sprintf(params, "[\"%s\"]", block);
	json_value *json = rpc_call(&coind->rpc, "getwork", params);
	if(!json) {
		debuglog("%s: retry\n", __func__);
		usleep(500*YAAMP_MS);
		json = rpc_call(&coind->rpc, "getwork", params);
	}
	free(params);

	if(!json) {
		debuglog("%s: error, no answer\n", __func__);
		return false;
	}

	json_value *json_res = json_get_object(json, "result");

	bool b = json_res && json_res->type == json_boolean && json_res->u.boolean;
	json_value_free(json_res);

	return b;
}

bool coind_submitblock(YAAMP_COIND *coind, const char *block)
{
	int paramlen = strlen(block);

	char *params = (char *)malloc(paramlen+1024);
	if(!params) return false;

	sprintf(params, "[\"%s\"]", block);
	json_value *json = rpc_call(&coind->rpc, "submitblock", params);

	free(params);
	if(!json) return false;

	json_value *json_error = json_get_object(json, "error");
	json_value *json_result = json_get_object(json, "result");
	bool has_error = json_error && json_error->type != json_null;
	bool b = !has_error && json_result && json_result->type == json_null;

	log_submitblock_response(coind, "submitblock", json_result, json_error, b);

	json_value_free(json);
	return b;
}

bool coind_submitblocktemplate(YAAMP_COIND *coind, const char *block)
{
	int paramlen = strlen(block);

	char *params = (char *)malloc(paramlen+1024);
	if(!params) return false;

	sprintf(params, "[{\"mode\": \"submit\", \"data\": \"%s\"}]", block);
	json_value *json = rpc_call(&coind->rpc, "getblocktemplate", params);

	free(params);
	if(!json) return false;

	json_value *json_error = json_get_object(json, "error");
	json_value *json_result = json_get_object(json, "result");
	bool has_error = json_error && json_error->type != json_null;
	bool b = !has_error && json_result && json_result->type == json_null;

	log_submitblock_response(coind, "submitblocktemplate", json_result, json_error, b);

	json_value_free(json);
	return b;
}

bool coind_submit(YAAMP_COIND *coind, const char *block)
{
	bool b;

	if(coind->usegetwork) // DCR
		b = coind_submitwork(coind, block);
	else if(coind->hassubmitblock)
		b = coind_submitblock(coind, block);
	else
		b = coind_submitblocktemplate(coind, block);

	return b;
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
		b=json_result && json_result->type == json_null;

	json_value_free(json);
	return b;
}

