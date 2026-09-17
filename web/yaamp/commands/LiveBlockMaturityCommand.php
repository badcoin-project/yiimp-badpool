<?php
require_once(dirname(__FILE__).'/../core/backend/BadpoolLiveBlockMaturity.php');
class LiveBlockMaturityCommand extends CConsoleCommand
{
	public function getHelp(){return "Usage: php yaamp/yiic.php liveblockmaturity --coin=1267 --algo=scrypt --after=<positive_block_id> --limit=<1..10>\nThe activation boundary is exclusive; no account, payout, or wallet-send operation is performed.";}
	public function actionIndex($coin,$algo,$after,$limit)
	{
		if(!BadpoolLiveBlockMaturity::positiveInteger($after))throw new InvalidArgumentException('--after must be an explicit positive integer block ID');
		if(!BadpoolLiveBlockMaturity::positiveInteger($limit))throw new InvalidArgumentException('--limit must be explicit');
		$dbCoin=getdbo('db_coins',intval($coin));if(!$dbCoin||intval($coin)!==1267||(string)$algo!=='scrypt'||(string)$dbCoin->algo!=='scrypt')throw new InvalidArgumentException('only coin 1267 / scrypt is permitted');
		$p=new BadpoolLiveBlockMaturity(new BadpoolYiiLiveBlockMaturityStore(Yii::app()->db),new BadpoolWalletLiveBlockMaturityDaemon($dbCoin));$r=$p->run($coin,$algo,$after,$limit);echo json_encode($r,JSON_UNESCAPED_SLASHES)."\n";return self::resultExitCode($r);
	}
	public static function resultExitCode($r){return intval(arraySafeVal($r,'daemon_failed',0))||intval(arraySafeVal($r,'apply_failed',0))?1:0;}
}
