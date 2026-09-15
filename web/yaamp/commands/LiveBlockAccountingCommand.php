<?php

require_once(dirname(__FILE__).'/../core/backend/BadpoolLiveBlockAccounting.php');

class LiveBlockAccountingCommand extends CConsoleCommand
{
	public function getHelp()
	{
		return "Usage: php yaamp/yiic.php liveblockaccounting --coin=<id> --algo=<algo> --after=<block_id> [--limit=2]\n".
			"Only live candidates whose block_id is strictly greater than the required positive --after boundary are eligible.";
	}
	public function actionIndex($coin, $algo, $after, $limit=2)
	{
		if(!BadpoolLiveBlockAccounting::isPositiveInteger($after))
			throw new InvalidArgumentException('--after must be an explicit positive integer block ID');
		$coinId=intval($coin);
		$dbCoin=getdbo('db_coins',$coinId);
		if(!$dbCoin || (string)$dbCoin->algo!==(string)$algo)
			throw new InvalidArgumentException('explicit coin and algo scope do not match');
		$processor=new BadpoolLiveBlockAccounting(
			new BadpoolYiiLiveBlockStore(Yii::app()->db),
			new BadpoolWalletLiveBlockDaemon($dbCoin)
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
