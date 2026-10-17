#include "stratum.h"
#include <iostream>

int main()
{
	YAAMP_CLIENT original = {};
	YAAMP_CLIENT resumed = {};
	original.userid = 41;
	original.workerid = 73;
	strcpy(original.authorized_worker_name, "test.rig1");
	resumed.userid = 999;
	resumed.workerid = 999;
	strcpy(resumed.authorized_worker_name, "test.other");

	client_inherit_reconnect_identity(&resumed, &original);
	const bool copied = resumed.userid == 41 && resumed.workerid == 73 &&
		!strcmp(resumed.authorized_worker_name, "test.rig1");
	const bool same_authorization = client_claim_matches_authorized_worker(&resumed, "test.rig1");
	const bool wrong_submit_rejected = !client_claim_matches_authorized_worker(&resumed, "test.other");
	const bool wrong_reauthorization_rejected = !client_claim_matches_authorized_worker(&resumed, "test.other");
	const bool unchanged = resumed.userid == 41 && resumed.workerid == 73 &&
		!strcmp(resumed.authorized_worker_name, "test.rig1");
	resumed.workerid = 0;
	const bool unauthorized_rejected = !client_claim_matches_authorized_worker(&resumed, "test.rig1");

	const bool pass = copied && same_authorization && wrong_submit_rejected &&
		wrong_reauthorization_rejected && unchanged && unauthorized_rejected;
	std::cout << "{\"classification\":\"" << (pass ? "PASS" : "FAIL")
		<< "\",\"checks\":{\"identity_copied\":" << (copied ? "true" : "false")
		<< ",\"same_worker_matches\":" << (same_authorization ? "true" : "false")
		<< ",\"wrong_submit_rejected\":" << (wrong_submit_rejected ? "true" : "false")
		<< ",\"wrong_reauthorization_rejected\":" << (wrong_reauthorization_rejected ? "true" : "false")
		<< ",\"identity_unchanged\":" << (unchanged ? "true" : "false")
		<< ",\"unauthorized_rejected\":" << (unauthorized_rejected ? "true" : "false")
		<< "},\"inherited_userid\":41,\"inherited_workerid\":73,\"inherited_worker_name\":\"test.rig1\"}" << std::endl;
	return pass ? 0 : 1;
}
