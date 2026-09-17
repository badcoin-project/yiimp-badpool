<?php

interface BadpoolLiveBlockMaturityDaemon { public function inspect($candidate); }
interface BadpoolLiveBlockMaturityStore {
	public function candidates($coinId,$algo,$after,$limit);
	public function apply($candidate,$result);
}

/** A deliberately small owner for the post-accounting, pre-credit transition. */
class BadpoolLiveBlockMaturity
{
	const COIN_ID=1267;
	const ALGO='scrypt';
	private $store,$daemon;
	public function __construct(BadpoolLiveBlockMaturityStore $store,BadpoolLiveBlockMaturityDaemon $daemon){$this->store=$store;$this->daemon=$daemon;}
	public function run($coinId,$algo,$after,$limit)
	{
		if(intval($coinId)!==self::COIN_ID || (string)$algo!==self::ALGO || !self::positiveInteger($after) || !self::positiveInteger($limit) || intval($limit)>10)
			throw new InvalidArgumentException('coin=1267, algo=scrypt, a positive exclusive --after boundary, and --limit from 1 through 10 are required');
		$out=array('selected'=>0,'refreshed_immature'=>0,'matured'=>0,'orphan'=>0,'skipped'=>0,'daemon_failed'=>0,'apply_failed'=>0,'failures'=>array());
		$rows=$this->store->candidates(self::COIN_ID,self::ALGO,intval($after),intval($limit));
		if(!is_array($rows)) throw new RuntimeException('invalid maturity candidate inventory');
		foreach($rows as $row){
			$out['selected']++;
			$reason=$this->integrityFailure($row,intval($after));
			if($reason!==null){$out['apply_failed']++;$this->failure($out,$row,$reason);continue;}
			try{$result=$this->daemon->inspect($row);}catch(Exception $e){$out['daemon_failed']++;$this->failure($out,$row,'rpc_failure');continue;}
			if(!is_array($result)||!in_array(arraySafeVal($result,'state'),array('immature','generate','orphan'),true)||!isset($result['confirmations'])||!is_numeric($result['confirmations'])){
				$out['daemon_failed']++;$this->failure($out,$row,'invalid_daemon_response');continue;
			}
			if($result['state']==='orphan'){$out['orphan']++;continue;} // Report only: destructive cleanup is a separate lane.
			try{$applied=$this->store->apply($row,$result);}catch(Exception $e){$applied=array('status'=>'failed','reason'=>'transaction_exception');}
			if(is_array($applied)&&arraySafeVal($applied,'status')==='applied')$out[$result['state']==='generate'?'matured':'refreshed_immature']++;
			elseif(is_array($applied)&&arraySafeVal($applied,'status')==='skipped')$out['skipped']++;
			else{$out['apply_failed']++;$this->failure($out,$row,is_array($applied)?arraySafeVal($applied,'reason','apply_failure'):'invalid_apply_result');}
		}
		return $out;
	}
	private function integrityFailure($r,$after)
	{
		if(intval(arraySafeVal($r,'block_id',0))<=$after)return 'outside_activation_boundary';
		if(intval(arraySafeVal($r,'candidate_coin_id',0))!==self::COIN_ID||arraySafeVal($r,'candidate_algo')!==self::ALGO)return 'candidate_identity_mismatch';
		if(intval(arraySafeVal($r,'block_coin_id',0))!==self::COIN_ID||arraySafeVal($r,'block_algo')!==self::ALGO)return 'block_identity_mismatch';
		if((string)arraySafeVal($r,'candidate_blockhash')===''||(string)arraySafeVal($r,'candidate_blockhash')!==(string)arraySafeVal($r,'block_blockhash'))return 'blockhash_mismatch';
		if(arraySafeVal($r,'block_category')!=='immature')return 'block_not_immature';
		if(intval(arraySafeVal($r,'earning_count',0))!==1||intval(arraySafeVal($r,'status0_count',0))!==1)return 'ambiguous_earning_state';
		return null;
	}
	private function failure(&$out,$row,$reason){if(count($out['failures'])<10)$out['failures'][]=array('block_id'=>intval(arraySafeVal($row,'block_id',0)),'reason'=>$reason);}
	public static function positiveInteger($v){return (is_int($v)||is_string($v))&&preg_match('/^[1-9][0-9]*$/D',(string)$v)===1;}
}

class BadpoolWalletLiveBlockMaturityDaemon implements BadpoolLiveBlockMaturityDaemon
{
	private $coin; public function __construct($coin){$this->coin=$coin;}
	public function inspect($c)
	{
		$rpc=new WalletRPC($this->coin);$block=$rpc->getblock($c['block_blockhash']);
		if(!$block){if(!empty($rpc->error))throw new RuntimeException('getblock failed');return array('state'=>'orphan','confirmations'=>-1);}
		if(!is_array($block)||!isset($block['hash'])||(string)$block['hash']!==(string)$c['block_blockhash'])throw new RuntimeException('block identity mismatch');
		$conf=isset($block['confirmations'])&&is_numeric($block['confirmations'])?intval($block['confirmations']):null;
		if($conf!==null&&$conf<0)return array('state'=>'orphan','confirmations'=>$conf);
		$txhash=(string)arraySafeVal($c,'block_txhash','');
		if($txhash===''||!isset($block['tx'])||!is_array($block['tx'])||!in_array($txhash,$block['tx'],true))throw new RuntimeException('transaction identity mismatch');
		$tx=$rpc->gettransaction($txhash);if(!$tx){if(!empty($rpc->error))throw new RuntimeException('gettransaction failed');return array('state'=>'orphan','confirmations'=>-1);}
		if(!is_array($tx))throw new RuntimeException('invalid transaction response');
		$txconf=isset($tx['confirmations'])&&is_numeric($tx['confirmations'])?intval($tx['confirmations']):$conf;
		$category=isset($tx['details'][0]['category'])?(string)$tx['details'][0]['category']:(string)arraySafeVal($tx,'category','');
		if($txconf===null)throw new RuntimeException('missing confirmations');
		if($txconf<0||in_array($category,array('orphan','conflicted'),true))return array('state'=>'orphan','confirmations'=>$txconf);
		if(!in_array($category,array('immature','generate'),true))throw new RuntimeException('unknown transaction category');
		return array('state'=>$category,'confirmations'=>$txconf);
	}
}

class BadpoolYiiLiveBlockMaturityStore implements BadpoolLiveBlockMaturityStore
{
	private $db;public function __construct($db){$this->db=$db;}
	public function candidates($coin,$algo,$after,$limit)
	{
		$sql="SELECT C.block_id,C.coin_id candidate_coin_id,C.algo candidate_algo,C.blockhash candidate_blockhash,B.coin_id block_coin_id,CO.algo block_algo,B.blockhash block_blockhash,B.txhash block_txhash,B.category block_category,COUNT(E.id) earning_count,SUM(CASE WHEN E.status=0 THEN 1 ELSE 0 END) status0_count FROM live_block_candidates C INNER JOIN blocks B ON B.id=C.block_id INNER JOIN coins CO ON CO.id=B.coin_id LEFT JOIN earnings E ON E.blockid=B.id WHERE C.coin_id=:coin AND C.algo=:algo AND C.block_id>:after AND B.category='immature' GROUP BY C.block_id,C.coin_id,C.algo,C.blockhash,B.coin_id,CO.algo,B.blockhash,B.txhash,B.category ORDER BY C.block_id LIMIT ".intval($limit);
		return $this->db->createCommand($sql)->queryAll(true,array(':coin'=>$coin,':algo'=>$algo,':after'=>$after));
	}
	public function apply($c,$r)
	{
		$tx=$this->db->beginTransaction();
		try{
			$p=array(':id'=>$c['block_id'],':coin'=>BadpoolLiveBlockMaturity::COIN_ID,':algo'=>BadpoolLiveBlockMaturity::ALGO,':hash'=>$c['block_blockhash']);
			$b=$this->db->createCommand("SELECT B.category,B.confirmations FROM blocks B INNER JOIN coins CO ON CO.id=B.coin_id INNER JOIN live_block_candidates C ON C.block_id=B.id AND C.coin_id=B.coin_id AND C.blockhash=B.blockhash AND C.algo=:algo WHERE B.id=:id AND B.coin_id=:coin AND B.blockhash=:hash AND CO.algo=:algo FOR UPDATE")->queryRow(true,$p);
			if(!$b){$tx->rollback();return array('status'=>'skipped','reason'=>'no_longer_eligible');}
			$earnings=$this->db->createCommand('SELECT id,status,mature_time FROM earnings WHERE blockid=:id FOR UPDATE')->queryAll(true,array(':id'=>$c['block_id']));
			if($b['category']==='generate'&&count($earnings)===1&&intval($earnings[0]['status'])===1){$tx->rollback();return array('status'=>'skipped','reason'=>'already_mature');}
			if($b['category']!=='immature'){ $tx->rollback();return array('status'=>'skipped','reason'=>'block_changed'); }
			if(count($earnings)!==1||intval($earnings[0]['status'])!==0)throw new RuntimeException('earning state changed');
			$conf=intval($r['confirmations']);
			if($r['state']==='immature'){
				$n=$this->db->createCommand("UPDATE blocks SET confirmations=:conf WHERE id=:id AND coin_id=:coin AND blockhash=:hash AND category='immature'")->execute(array(':conf'=>$conf,':id'=>$c['block_id'],':coin'=>BadpoolLiveBlockMaturity::COIN_ID,':hash'=>$c['block_blockhash']));
				if($n!==0&&$n!==1)throw new RuntimeException('block refresh failed');
			} else {
				$n=$this->db->createCommand("UPDATE blocks SET category='generate',confirmations=:conf WHERE id=:id AND coin_id=:coin AND blockhash=:hash AND category='immature'")->execute(array(':conf'=>$conf,':id'=>$c['block_id'],':coin'=>BadpoolLiveBlockMaturity::COIN_ID,':hash'=>$c['block_blockhash']));if($n!==1)throw new RuntimeException('block transition failed');
				$n=$this->db->createCommand('UPDATE earnings SET status=1,mature_time=UNIX_TIMESTAMP() WHERE id=:earning AND blockid=:block AND coinid=:coin AND status=0')->execute(array(':earning'=>$earnings[0]['id'],':block'=>$c['block_id'],':coin'=>BadpoolLiveBlockMaturity::COIN_ID));if($n!==1)throw new RuntimeException('earning transition failed');
			}
			$tx->commit();return array('status'=>'applied');
		}catch(Exception $e){if($tx->active)$tx->rollback();error_log('live maturity apply failed for block '.intval($c['block_id']).': '.$e->getMessage());return array('status'=>'failed','reason'=>'transaction_exception');}
	}
}
