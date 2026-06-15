<?php
/**
 * BadPool wallet-send hard guard.
 *
 * This file intentionally contains no runtime activation switch. Wallet sends
 * remain disabled by default until a separate approved patch adds an explicit
 * execution design.
 */

function badpool_wallet_send_guard_methods()
{
	return array(
		'move' => true,
		'send' => true,
		'sendfrom' => true,
		'sendmany' => true,
		'sendrawtransaction' => true,
		'sendtoaddress' => true,
		'sendtransaction' => true,
		'eth_sendrawtransaction' => true,
		'eth_sendtransaction' => true,
		'personal_sendtransaction' => true,
		'transfer' => true,
		'transfer_original' => true,
		'transfer_split' => true,
		'sweep_all' => true,
		'sweep_dust' => true,
		'walletpassphrase' => true,
		'walletpassphrasechange' => true,
	);
}

function badpool_wallet_send_guard_normalize_method($method)
{
	return strtolower(trim((string) $method));
}

function badpool_wallet_send_guard_is_send_method($method)
{
	$method = badpool_wallet_send_guard_normalize_method($method);
	if ($method === '') {
		return false;
	}

	$guarded_methods = badpool_wallet_send_guard_methods();
	if (isset($guarded_methods[$method])) {
		return true;
	}

	if (strpos($method, 'send') === 0) {
		return true;
	}

	if (strpos($method, '_send') !== false) {
		return true;
	}

	return false;
}

function badpool_wallet_send_guard_refusal_message($method)
{
	$method = badpool_wallet_send_guard_normalize_method($method);
	return "BADPOOL WALLET SEND HARD GUARD: refusing wallet RPC method '{$method}'. Wallet sends are disabled by default; this build has no source-level activation path.";
}

function badpool_wallet_send_guard_refuse($method)
{
	$message = badpool_wallet_send_guard_refusal_message($method);

	if (function_exists('debuglog')) {
		debuglog($message);
	}
	else {
		error_log($message);
	}

	return $message;
}
