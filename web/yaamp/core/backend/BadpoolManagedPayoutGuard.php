<?php

/**
 * Fail-closed boundary for payout rows owned by the BadPool guarded workflow.
 *
 * These coin IDs may only cross the wallet boundary through
 * BadpoolGuardCommand::wallet-send-apply. Legacy payout code must return before
 * it constructs a WalletRPC object or mutates payout/account state.
 */
class BadpoolManagedPayoutGuard
{
	public static function coinIds()
	{
		return array(1266, 1267, 1268, 1269, 1270);
	}

	public static function isManagedCoinId($coinId)
	{
		return in_array(intval($coinId), self::coinIds(), true);
	}

	public static function refuseLegacyOperation($coinId, $operation)
	{
		if (!self::isManagedCoinId($coinId)) return false;

		$message = 'BADPOOL GUARDED PAYOUT BOUNDARY: refusing legacy '.$operation.
			' for managed coin ID '.intval($coinId).'. Use badpoolguard wallet-send-apply with its approval package.';
		if (function_exists('debuglog')) debuglog($message);
		else error_log($message);
		return $message;
	}
}
