<?php

require_once(dirname(__FILE__).'/BadpoolLivePaymentLaneConfiguration.php');

interface BadpoolLiveBlockMaturityDaemon { public function inspect($candidate); }
interface BadpoolLiveBlockMaturityStore {
	public function candidates($coinId,$algo,$after,$limit);
	public function apply($candidate,$result);
}

/** A deliberately small owner for the post-accounting, pre-credit transition. */
class BadpoolLiveBlockMaturity
{
	private $store,$daemon,$lane;
	public function __construct(BadpoolLiveBlockMaturityStore $store,BadpoolLiveBlockMaturityDaemon $daemon,$lane=null){$this->store=$store;$this->daemon=$daemon;$this->lane=$lane?:BadpoolLivePaymentLaneRegistry::scryptCompatibility();}
	public function run($coinId,$algo,$after,$limit)
	{
		if(!$this->lane instanceof BadpoolLivePaymentLaneConfiguration || !$this->lane->isMaturityCommissioned())throw new InvalidArgumentException('live maturity lane is disabled or uncommissioned');
		if(intval($coinId)!==$this->lane->coinId() || (string)$algo!==$this->lane->dbAlgo() || !self::positiveInteger($after) || intval($after)!==$this->lane->blockBoundary() || !self::positiveInteger($limit) || intval($limit)>$this->lane->maturityBlockLimit())
			throw new InvalidArgumentException('coin, DB algo, boundary, and limit must exactly match the commissioned live maturity lane');
		$out=array('selected'=>0,'refreshed_immature'=>0,'matured'=>0,'orphaned'=>0,'skipped'=>0,'daemon_failed'=>0,'apply_failed'=>0,'failures'=>array());
		$rows=$this->store->candidates($this->lane->coinId(),$this->lane->dbAlgo(),intval($after),intval($limit));
		if(!is_array($rows)) throw new RuntimeException('invalid maturity candidate inventory');
		foreach($rows as $row){
			$out['selected']++;
			$reason=$this->integrityFailure($row,intval($after));
			if($reason!==null){$out['apply_failed']++;$this->failure($out,$row,$reason);continue;}
			try{$result=$this->daemon->inspect($row);}catch(Exception $e){$out['daemon_failed']++;$this->failure($out,$row,'rpc_failure');continue;}
			if(!is_array($result)||!in_array(arraySafeVal($result,'state'),array('immature','generate','orphan'),true)||!isset($result['confirmations'])||!is_numeric($result['confirmations'])){
				$out['daemon_failed']++;$this->failure($out,$row,'invalid_daemon_response');continue;
			}
			try{$applied=$this->store->apply($row,$result);}catch(Exception $e){$applied=array('status'=>'failed','reason'=>'transaction_exception');}
			if(is_array($applied)&&arraySafeVal($applied,'status')==='applied')$out[$result['state']==='generate'?'matured':($result['state']==='orphan'?'orphaned':'refreshed_immature')]++;
			elseif(is_array($applied)&&arraySafeVal($applied,'status')==='skipped')$out['skipped']++;
			else{$out['apply_failed']++;$this->failure($out,$row,is_array($applied)?arraySafeVal($applied,'reason','apply_failure'):'invalid_apply_result');}
		}
		return $out;
	}
	private function integrityFailure($r,$after)
	{
		if(intval(arraySafeVal($r,'block_id',0))<=$after)return 'outside_activation_boundary';
		if(intval(arraySafeVal($r,'candidate_coin_id',0))!==$this->lane->coinId()||arraySafeVal($r,'candidate_algo')!==$this->lane->dbAlgo())return 'candidate_identity_mismatch';
		if(intval(arraySafeVal($r,'block_coin_id',0))!==$this->lane->coinId()||arraySafeVal($r,'block_algo')!==$this->lane->dbAlgo())return 'block_identity_mismatch';
		if(!$this->lane->ownershipMatches(arraySafeVal($r,'lane_ownership')))return 'lane_ownership_mismatch';
		if((string)arraySafeVal($r,'candidate_blockhash')===''||(string)arraySafeVal($r,'candidate_blockhash')!==(string)arraySafeVal($r,'block_blockhash'))return 'blockhash_mismatch';
		if(arraySafeVal($r,'block_category')!=='immature')return 'block_not_immature';
		try{$inventory=self::canonicalEarningInventory(arraySafeVal($r,'earnings'),$this->lane->coinId(),intval(arraySafeVal($r,'block_id',0)),true);}catch(Exception $e){return 'ambiguous_earning_state';}
		if(arraySafeVal($r,'earning_inventory_checksum')!==self::earningInventoryChecksum($inventory))return 'earning_inventory_checksum_mismatch';
		return null;
	}
	private function failure(&$out,$row,$reason){if(count($out['failures'])<10)$out['failures'][]=array('block_id'=>intval(arraySafeVal($row,'block_id',0)),'reason'=>$reason);}
	public static function positiveInteger($v){return (is_int($v)||is_string($v))&&preg_match('/^[1-9][0-9]*$/D',(string)$v)===1;}
	public static function canonicalEarningInventory($rows,$coinId,$blockId,$requireStatus0)
	{
		if(!is_array($rows)||count($rows)<1)throw new RuntimeException('empty earning inventory');
		$out=array();$seen=array();
		foreach($rows as $r){
			if(!is_array($r)||!self::positiveInteger(arraySafeVal($r,'id'))||!self::positiveInteger(arraySafeVal($r,'userid'))||intval(arraySafeVal($r,'coinid',0))!==intval($coinId)||intval(arraySafeVal($r,'blockid',0))!==intval($blockId)||!array_key_exists('amount',$r)||!array_key_exists('price',$r)||!array_key_exists('status',$r)||!array_key_exists('mature_time',$r))throw new RuntimeException('invalid earning inventory');
			$id=intval($r['id']);if(isset($seen[$id]))throw new RuntimeException('duplicate earning inventory');$seen[$id]=true;
			$status=intval($r['status']);if($requireStatus0&&$status!==0)throw new RuntimeException('invalid earning status');
			$out[]=array('id'=>$id,'userid'=>intval($r['userid']),'coinid'=>intval($r['coinid']),'blockid'=>intval($r['blockid']),'amount'=>(string)$r['amount'],'price'=>(string)$r['price'],'status'=>$status,'mature_time'=>$r['mature_time']===null?null:(string)$r['mature_time']);
		}
		usort($out,function($a,$b){return $a['id']-$b['id'];});return $out;
	}
	public static function earningInventoryChecksum($inventory){return hash('sha256',json_encode(array_values($inventory),JSON_UNESCAPED_SLASHES));}
	public static function immutableEarningInventory($inventory){$out=array();foreach($inventory as $r)$out[]=array('id'=>$r['id'],'userid'=>$r['userid'],'coinid'=>$r['coinid'],'blockid'=>$r['blockid'],'amount'=>$r['amount'],'price'=>$r['price']);return $out;}
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
	private $db,$lane;public function __construct($db,$lane=null){$this->db=$db;$this->lane=$lane?:BadpoolLivePaymentLaneRegistry::scryptCompatibility();}
	public function candidates($coin,$algo,$after,$limit)
	{
		$sql="SELECT C.block_id,C.coin_id candidate_coin_id,C.algo candidate_algo,C.blockhash candidate_blockhash,B.coin_id block_coin_id,CO.algo block_algo,B.blockhash block_blockhash,B.txhash block_txhash,B.category block_category FROM live_block_candidates C INNER JOIN blocks B ON B.id=C.block_id INNER JOIN coins CO ON CO.id=B.coin_id WHERE C.coin_id=:coin AND C.algo=:algo AND C.block_id>:after AND B.category='immature' ORDER BY C.block_id LIMIT ".intval($limit);
		$rows=$this->db->createCommand($sql)->queryAll(true,array(':coin'=>$coin,':algo'=>$algo,':after'=>$after));
		foreach($rows as &$row){$row['lane_ownership']=$this->lane->ownershipEnvelope();$inventory=$this->db->createCommand('SELECT id,userid,coinid,blockid,amount,price,status,mature_time FROM earnings WHERE blockid=:id ORDER BY id')->queryAll(true,array(':id'=>$row['block_id']));try{$inventory=BadpoolLiveBlockMaturity::canonicalEarningInventory($inventory,$coin,$row['block_id'],true);$row['earnings']=$inventory;$row['earning_count']=count($inventory);$row['status0_count']=count($inventory);$row['earning_inventory_checksum']=BadpoolLiveBlockMaturity::earningInventoryChecksum($inventory);}catch(Exception $e){$row['earnings']=$inventory;$row['earning_count']=is_array($inventory)?count($inventory):0;$row['status0_count']=0;$row['earning_inventory_checksum']='';}}unset($row);
		return $rows;
	}
	public function apply($c,$r)
	{
		$tx=$this->db->beginTransaction();
		try{
			if(!$this->lane->isMaturityCommissioned()||!$this->lane->ownershipMatches(arraySafeVal($c,'lane_ownership')))throw new RuntimeException('lane ownership changed');
			$p=array(':id'=>$c['block_id'],':coin'=>$this->lane->coinId(),':algo'=>$this->lane->dbAlgo(),':hash'=>$c['block_blockhash']);
			$b=$this->db->createCommand("SELECT B.category,B.confirmations FROM blocks B INNER JOIN coins CO ON CO.id=B.coin_id INNER JOIN live_block_candidates C ON C.block_id=B.id AND C.coin_id=B.coin_id AND C.blockhash=B.blockhash AND C.algo=:algo WHERE B.id=:id AND B.coin_id=:coin AND B.blockhash=:hash AND CO.algo=:algo FOR UPDATE")->queryRow(true,$p);
			if(!$b){$tx->rollback();return array('status'=>'skipped','reason'=>'no_longer_eligible');}
			$earnings=$this->db->createCommand('SELECT id,userid,coinid,blockid,amount,price,status,mature_time FROM earnings WHERE blockid=:id ORDER BY id FOR UPDATE')->queryAll(true,array(':id'=>$c['block_id']));
			$locked=BadpoolLiveBlockMaturity::canonicalEarningInventory($earnings,$this->lane->coinId(),$c['block_id'],false);$expected=BadpoolLiveBlockMaturity::canonicalEarningInventory(arraySafeVal($c,'earnings'),$this->lane->coinId(),$c['block_id'],true);
			if(BadpoolLiveBlockMaturity::immutableEarningInventory($locked)!==BadpoolLiveBlockMaturity::immutableEarningInventory($expected))throw new RuntimeException('earning inventory changed');
			$statuses=array();foreach($locked as $earning)$statuses[$earning['status']]=true;
			if($b['category']==='generate'&&count($statuses)===1&&isset($statuses[1])){$tx->rollback();return array('status'=>'skipped','reason'=>'already_mature');}
			if($b['category']==='orphan'&&count($statuses)===1&&isset($statuses[-1])){$tx->rollback();return array('status'=>'skipped','reason'=>'already_orphaned');}
			if($b['category']!=='immature')throw new RuntimeException('ambiguous block/earning terminal state');
			if(BadpoolLiveBlockMaturity::earningInventoryChecksum($locked)!==arraySafeVal($c,'earning_inventory_checksum')||$locked!==$expected)throw new RuntimeException('earning inventory changed');
			$conf=intval($r['confirmations']);
			if($r['state']==='immature'){
				$n=$this->db->createCommand("UPDATE blocks SET confirmations=:conf WHERE id=:id AND coin_id=:coin AND blockhash=:hash AND category='immature'")->execute(array(':conf'=>$conf,':id'=>$c['block_id'],':coin'=>$this->lane->coinId(),':hash'=>$c['block_blockhash']));
				if($n!==0&&$n!==1)throw new RuntimeException('block refresh failed');
			} elseif($r['state']==='generate') {
				$n=$this->db->createCommand("UPDATE blocks SET category='generate',confirmations=:conf WHERE id=:id AND coin_id=:coin AND blockhash=:hash AND category='immature'")->execute(array(':conf'=>$conf,':id'=>$c['block_id'],':coin'=>$this->lane->coinId(),':hash'=>$c['block_blockhash']));if($n!==1)throw new RuntimeException('block transition failed');
				foreach($locked as $earning){$n=$this->db->createCommand('UPDATE earnings SET status=1,mature_time=UNIX_TIMESTAMP() WHERE id=:earning AND blockid=:block AND coinid=:coin AND status=0')->execute(array(':earning'=>$earning['id'],':block'=>$c['block_id'],':coin'=>$this->lane->coinId()));if($n!==1)throw new RuntimeException('earning transition failed');}
			} elseif($r['state']==='orphan') {
				$n=$this->db->createCommand("UPDATE blocks SET category='orphan',confirmations=:conf WHERE id=:id AND coin_id=:coin AND blockhash=:hash AND category='immature'")->execute(array(':conf'=>$conf,':id'=>$c['block_id'],':coin'=>$this->lane->coinId(),':hash'=>$c['block_blockhash']));if($n!==1)throw new RuntimeException('block orphan transition failed');
				foreach($locked as $earning){$n=$this->db->createCommand('UPDATE earnings SET status=-1,mature_time=NULL WHERE id=:earning AND blockid=:block AND coinid=:coin AND status=0')->execute(array(':earning'=>$earning['id'],':block'=>$c['block_id'],':coin'=>$this->lane->coinId()));if($n!==1)throw new RuntimeException('earning orphan transition failed');}
			} else {
				throw new RuntimeException('unsupported daemon state');
			}
			$tx->commit();return array('status'=>'applied');
		}catch(Exception $e){if($tx->active)$tx->rollback();error_log('live maturity apply failed for block '.intval($c['block_id']).': '.$e->getMessage());return array('status'=>'failed','reason'=>'transaction_exception');}
	}
}
