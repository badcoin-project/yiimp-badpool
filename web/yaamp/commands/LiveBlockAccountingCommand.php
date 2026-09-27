<?php

require_once(dirname(__FILE__).'/../core/backend/BadpoolLiveBlockAccounting.php');

class LiveBlockAccountingCommand extends CConsoleCommand
{
	public function getHelp()
	{
		return "Usage: php yaamp/yiic.php liveblockaccounting --coin=<id> --algo=<algo> --after=<block_id> [--limit=2] [--lane=live-scrypt-v1]\n".
			"The coin, database algorithm, and exclusive block boundary must exactly match an accounting-commissioned lane; only block IDs strictly greater than the boundary are eligible. The lane defaults to live-scrypt-v1, so non-Scrypt lanes such as live-skein-v1 must be explicit.";
	}
	public static function configurationForRequest($coin,$algo,$after,$lane)
	{
		if(!BadpoolLiveBlockAccounting::isPositiveInteger($after))
			throw new InvalidArgumentException('--after must be an explicit positive integer block ID');
		$config=(new BadpoolLivePaymentLaneRegistry())->get((string)$lane);
		if(!$config->isAccountingCommissioned())throw new InvalidArgumentException('selected live accounting lane is disabled or uncommissioned');
		if(intval($coin)!==$config->coinId() || (string)$algo!==$config->dbAlgo() || intval($after)!==$config->blockBoundary())
			throw new InvalidArgumentException('coin, DB algo, and boundary do not match the selected lane');
		return $config;
	}
	public function actionIndex($coin, $algo, $after, $limit=2, $lane='live-scrypt-v1')
	{
		$config=self::configurationForRequest($coin,$algo,$after,$lane);
		$coinId=intval($coin);
		$dbCoin=getdbo('db_coins',$coinId);
		if(!$dbCoin || (string)$dbCoin->algo!==(string)$algo)
			throw new InvalidArgumentException('explicit coin and algo scope do not match');
		$processor=new BadpoolLiveBlockAccounting(
			new BadpoolYiiLiveBlockStore(Yii::app()->db),
			new BadpoolWalletLiveBlockDaemon($dbCoin),
			$config
		);
		$result=$processor->run($coinId,$algo,$after,$limit);
		echo json_encode($result,JSON_UNESCAPED_SLASHES)."\n";
		return self::resultExitCode($result);
	}
	public static function resultExitCode($result)
	{
		return (intval(arraySafeVal($result,'daemon_failed',0))>0 || intval(arraySafeVal($result,'apply_failed',0))>0) ? 1 : 0;
	}
}
