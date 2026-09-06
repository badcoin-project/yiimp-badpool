<?php

interface BadpoolLiveBlockDaemon { public function inspect($candidate); }
interface BadpoolLiveBlockStore {
	public function candidates($coinId, $algo, $limit);
	public function apply($candidate, $classification);
}

class BadpoolLiveBlockAccounting
{
	private $store;
	private $daemon;
	public function __construct(BadpoolLiveBlockStore $store, BadpoolLiveBlockDaemon $daemon)
	{
		$this->store=$store; $this->daemon=$daemon;
	}
	public function run($coinId, $algo, $limit)
	{
		$coinId=intval($coinId); $algo=trim((string)$algo); $limit=intval($limit);
		if($coinId<=0 || $algo==='' || $limit<1 || $limit>10)
			throw new InvalidArgumentException('coin, algo, and a limit from 1 through 10 are required');
		$out=array('selected'=>0,'immature'=>0,'orphan'=>0,'skipped'=>0,'daemon_failed'=>0);
		foreach($this->store->candidates($coinId,$algo,$limit) as $candidate) {
			$out['selected']++;
			try { $result=$this->daemon->inspect($candidate); }
			catch(Exception $e) { $out['daemon_failed']++; continue; }
			if(!is_array($result) || !in_array(arraySafeVal($result,'category'),array('immature','orphan'),true)) {
				$out['daemon_failed']++; continue;
			}
			$applied=$this->store->apply($candidate,$result);
			if($applied) $out[$result['category']]++; else $out['skipped']++;
		}
		return $out;
	}
}

class BadpoolWalletLiveBlockDaemon implements BadpoolLiveBlockDaemon
{
	private $coin;
	public function __construct($coin) { $this->coin=$coin; }
	public function inspect($candidate)
	{
		$rpc=new WalletRPC($this->coin);
		$block=$rpc->getblock($candidate['blockhash']);
		if(!$block) {
			if(!empty($rpc->error)) throw new RuntimeException('getblock failed: '.$rpc->error);
			return array('category'=>'orphan','amount'=>0,'txhash'=>null,'confirmations'=>0);
		}
		if(empty($block['tx'][0])) return array('category'=>'orphan','amount'=>0,'txhash'=>null,'confirmations'=>0);
		$tx=$rpc->gettransaction($block['tx'][0]);
		if(!$tx && !empty($rpc->error)) throw new RuntimeException('gettransaction failed: '.$rpc->error);
		if(!$tx || empty($tx['details'][0])) return array('category'=>'orphan','amount'=>0,'txhash'=>$block['tx'][0],'confirmations'=>0);
		if(arraySafeVal($tx['details'][0],'category')==='orphan')
			return array('category'=>'orphan','amount'=>0,'txhash'=>$block['tx'][0],'confirmations'=>intval(arraySafeVal($tx,'confirmations',0)));
		if(!isset($tx['details'][0]['amount']) || !isset($tx['confirmations']))
			throw new RuntimeException('incomplete daemon response');
		return array('category'=>'immature','txhash'=>$block['tx'][0],
			'amount'=>$tx['details'][0]['amount'],'confirmations'=>$tx['confirmations']);
	}
}

class BadpoolYiiLiveBlockStore implements BadpoolLiveBlockStore
{
	private $db;
	public function __construct($db) { $this->db=$db; }
	public function candidates($coinId,$algo,$limit)
	{
		return $this->db->createCommand("SELECT C.*,B.height,B.category FROM live_block_candidates C INNER JOIN blocks B ON B.id=C.block_id AND B.coin_id=C.coin_id AND B.blockhash=C.blockhash WHERE C.coin_id=:coin AND C.algo=:algo AND B.category='new' AND NOT EXISTS (SELECT 1 FROM earnings E WHERE E.blockid=B.id) ORDER BY C.block_id LIMIT ".intval($limit))->queryAll(true,array(':coin'=>$coinId,':algo'=>$algo));
	}
	public function apply($candidate,$class)
	{
		$tx=$this->db->beginTransaction();
		try {
			$p=array(':id'=>$candidate['block_id'],':coin'=>$candidate['coin_id'],':hash'=>$candidate['blockhash'],':algo'=>$candidate['algo']);
			$live=$this->db->createCommand("SELECT B.id FROM blocks B INNER JOIN live_block_candidates C ON C.block_id=B.id AND C.coin_id=B.coin_id AND C.blockhash=B.blockhash WHERE B.id=:id AND B.coin_id=:coin AND B.blockhash=:hash AND B.category='new' AND C.algo=:algo AND NOT EXISTS (SELECT 1 FROM earnings E WHERE E.blockid=B.id) FOR UPDATE")->queryRow(true,$p);
			if(!$live) { $tx->rollback(); return false; }
			if($class['category']==='orphan') {
				$n=$this->db->createCommand("UPDATE blocks SET category='orphan',amount=0,confirmations=0 WHERE id=:id AND coin_id=:coin AND blockhash=:hash AND category='new'")->execute(array(':id'=>$candidate['block_id'],':coin'=>$candidate['coin_id'],':hash'=>$candidate['blockhash']));
				if($n!==1) throw new RuntimeException('block changed');
			} else {
				$attrs=$this->db->createCommand('SELECT userid,difficulty,no_fees,donation FROM live_block_attributions WHERE block_id=:id ORDER BY userid')->queryAll(true,array(':id'=>$candidate['block_id']));
				$total=0.0; foreach($attrs as $a) $total+=floatval($a['difficulty']);
				if($total<=0) throw new RuntimeException('empty discovery attribution');
				$n=$this->db->createCommand("UPDATE blocks SET category='immature',txhash=:txhash,amount=:amount,confirmations=:confirmations WHERE id=:id AND coin_id=:coin AND blockhash=:hash AND category='new'")->execute(array(':txhash'=>$class['txhash'],':amount'=>$class['amount'],':confirmations'=>$class['confirmations'],':id'=>$candidate['block_id'],':coin'=>$candidate['coin_id'],':hash'=>$candidate['blockhash']));
				if($n!==1) throw new RuntimeException('block changed');
				foreach($attrs as $a) {
					$amount=floatval($class['amount'])*floatval($a['difficulty'])/$total;
					if(empty($a['no_fees'])) $amount=take_yaamp_fee($amount,$candidate['algo']);
					if(!empty($a['donation'])) $amount=take_yaamp_fee($amount,$candidate['algo'],$a['donation']);
					if($amount>0) $this->db->createCommand()->insert('earnings',array('userid'=>$a['userid'],'coinid'=>$candidate['coin_id'],'blockid'=>$candidate['block_id'],'create_time'=>$candidate['found_time'],'amount'=>$amount,'price'=>$candidate['price'],'status'=>0));
				}
			}
			$tx->commit(); return true;
		} catch(Exception $e) { if($tx->active) $tx->rollback(); return false; }
	}
}
