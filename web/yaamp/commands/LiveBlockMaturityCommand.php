<?php
require_once(dirname(__FILE__).'/../core/backend/BadpoolLiveBlockMaturity.php');
class LiveBlockMaturityCommand extends CConsoleCommand
{
	public function getHelp(){return "Usage: php yaamp/yiic.php liveblockmaturity --coin=1267 --algo=scrypt --after=29242 --limit=<1..10> [--lane=live-scrypt-v1]\nThe lane must be explicitly commissioned for maturity; no account, payout, or wallet-send operation is performed.";}
	public static function configurationForRequest($coin,$algo,$after,$limit,$lane)
	{
		if(!BadpoolLiveBlockMaturity::positiveInteger($after))throw new InvalidArgumentException('--after must be an explicit positive integer block ID');
		if(!BadpoolLiveBlockMaturity::positiveInteger($limit))throw new InvalidArgumentException('--limit must be explicit');
		$config=(new BadpoolLivePaymentLaneRegistry())->get((string)$lane);if(!$config->isMaturityCommissioned())throw new InvalidArgumentException('selected live maturity lane is disabled or uncommissioned');
		if(intval($coin)!==$config->coinId()||(string)$algo!==$config->dbAlgo()||intval($after)!==$config->blockBoundary()||intval($limit)>$config->maturityBlockLimit())throw new InvalidArgumentException('coin, DB algo, boundary, and limit do not match the selected lane');
		return $config;
	}
	public function actionIndex($coin,$algo,$after,$limit,$lane='live-scrypt-v1')
	{
		$config=self::configurationForRequest($coin,$algo,$after,$limit,$lane);
		$dbCoin=getdbo('db_coins',$config->coinId());if(!$dbCoin||(string)$dbCoin->algo!==$config->dbAlgo())throw new InvalidArgumentException('configured coin and DB algo scope do not match');
		$p=new BadpoolLiveBlockMaturity(new BadpoolYiiLiveBlockMaturityStore(Yii::app()->db,$config),new BadpoolWalletLiveBlockMaturityDaemon($dbCoin),$config);$r=$p->run($coin,$algo,$after,$limit);echo json_encode($r,JSON_UNESCAPED_SLASHES)."\n";return self::resultExitCode($r);
	}
	public static function resultExitCode($r){return intval(arraySafeVal($r,'daemon_failed',0))||intval(arraySafeVal($r,'apply_failed',0))?1:0;}
}
