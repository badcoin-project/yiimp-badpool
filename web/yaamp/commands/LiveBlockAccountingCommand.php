<?php

require_once(dirname(__FILE__).'/../core/backend/BadpoolLiveBlockAccounting.php');

class LiveBlockAccountingCommand extends CConsoleCommand
{
	public function actionIndex($coin, $algo, $limit=2)
	{
		$coinId=intval($coin);
		$dbCoin=getdbo('db_coins',$coinId);
		if(!$dbCoin || (string)$dbCoin->algo!==(string)$algo)
			throw new InvalidArgumentException('explicit coin and algo scope do not match');
		$processor=new BadpoolLiveBlockAccounting(
			new BadpoolYiiLiveBlockStore(Yii::app()->db),
			new BadpoolWalletLiveBlockDaemon($dbCoin)
		);
		echo json_encode($processor->run($coinId,$algo,$limit),JSON_UNESCAPED_SLASHES)."\n";
	}
}
