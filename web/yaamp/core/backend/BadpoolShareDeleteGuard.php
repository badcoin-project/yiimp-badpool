<?php
/**
 * BadPool share-delete hard guard.
 *
 * This helper intentionally has no activation switch. Historical share rows
 * are preserved until a separate approved cleanup design exists.
 */

function badpool_share_delete_guard_refusal_message($source, $sqlCond, $candidateCount=null)
{
	$count = $candidateCount === null ? 'unknown' : (string) intval($candidateCount);
	return "BADPOOL SHARE DELETE HARD GUARD: {$source} skipped share deletion. candidates={$count}; predicate={$sqlCond}. Share deletion is disabled by default; this build has no source-level activation path.";
}

function badpool_share_delete_guard_candidate_count($sqlCond, $params=array())
{
	try {
		return intval(dboscalar("SELECT COUNT(*) FROM shares WHERE $sqlCond", $params));
	}
	catch (Exception $e) {
		if (function_exists('debuglog')) {
			debuglog('BADPOOL SHARE DELETE HARD GUARD: unable to count share deletion candidates: '.$e->getMessage());
		}
		else {
			error_log('BADPOOL SHARE DELETE HARD GUARD: unable to count share deletion candidates: '.$e->getMessage());
		}
		return null;
	}
}

function badpool_share_delete_guard_skip($source, $sqlCond, $params=array())
{
	$candidateCount = badpool_share_delete_guard_candidate_count($sqlCond, $params);
	$message = badpool_share_delete_guard_refusal_message($source, $sqlCond, $candidateCount);

	if (function_exists('debuglog')) {
		debuglog($message);
	}
	else {
		error_log($message);
	}

	return false;
}
