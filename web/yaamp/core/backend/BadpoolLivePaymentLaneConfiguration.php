<?php

/** Strict, immutable identity and enablement contract for one live-payment lane. */
class BadpoolLivePaymentLaneConfiguration
{
	const SCHEMA = 'badpool.live_payment_lane_configuration.v1';
	const VERSION = 1;
	const OWNERSHIP_SCHEMA = 'badpool.live_payment_coordinator.v1';

	private $values;

	public function __construct($values)
	{
		if (!is_array($values)) throw new InvalidArgumentException('Lane configuration must be an array.');
		$this->values = $values;
		$this->validate();
	}

	public function get($key, $default=null) { return array_key_exists($key,$this->values)?$this->values[$key]:$default; }
	public function toArray() { return $this->values; }
	public function laneId() { return $this->get('lane_id'); }
	public function coinId() { return $this->get('coin_id'); }
	public function dbAlgo() { return $this->get('db_algo'); }
	public function operationalAlgo() { return $this->get('operational_algo'); }
	public function blockBoundary() { return $this->get('block_id_gt'); }
	public function batchLimit() { return $this->get('batch_max_earnings'); }
	public function runtimeRoot() { return $this->get('runtime_root'); }
	public function statePath($root=null) { return ($root?:$this->runtimeRoot()).'/'.$this->get('state_filename'); }
	public function lockPath($root=null) { return ($root?:$this->runtimeRoot()).'/'.$this->get('lock_filename'); }
	public function isCommissioned() { return $this->get('accounting_enabled')===true && $this->get('payout_preparation_enabled')===true; }

	public function ownershipEnvelope()
	{
		return array('schema'=>$this->get('ownership_schema'),'lane'=>$this->laneId(),'coin_id'=>$this->coinId(),'algo'=>$this->dbAlgo(),'block_id_gt'=>$this->blockBoundary());
	}

	public function ownershipMatches($owner)
	{
		if (!is_array($owner)) return false;
		foreach ($this->ownershipEnvelope() as $key=>$value) if (!array_key_exists($key,$owner) || $owner[$key] !== $value) return false;
		return true;
	}

	private function validate()
	{
		$v=$this->values;
		if (isset($v['schema'])?$v['schema']!==self::SCHEMA:true) throw new InvalidArgumentException('Unsupported lane configuration schema.');
		if (isset($v['version'])?intval($v['version'])!==self::VERSION:true) throw new InvalidArgumentException('Unsupported lane configuration version.');
		if (!isset($v['lane_id']) || !is_string($v['lane_id']) || !preg_match('/^[a-z0-9][a-z0-9._-]*$/',$v['lane_id'])) throw new InvalidArgumentException('A safe lane ID is required.');
		if (!isset($v['ownership_schema']) || $v['ownership_schema']!==self::OWNERSHIP_SCHEMA) throw new InvalidArgumentException('The coordinator ownership schema is incompatible.');
		if (!isset($v['coin_id']) || !is_int($v['coin_id']) || $v['coin_id']<1) throw new InvalidArgumentException('A positive integer coin ID is required.');
		foreach (array('db_algo','operational_algo','wallet_binding_identity','wallet_source_account') as $key)
			if (!isset($v[$key]) || !is_string($v[$key]) || !preg_match('/^[a-z0-9][a-z0-9._-]*$/',$v[$key])) throw new InvalidArgumentException('Invalid or missing '.$key.'.');
		foreach (array('accounting_enabled','payout_preparation_enabled','wallet_send_enabled') as $key)
			if (!array_key_exists($key,$v) || !is_bool($v[$key])) throw new InvalidArgumentException('Explicit boolean '.$key.' is required.');
		if ($v['wallet_binding_identity']!==$v['operational_algo']) throw new InvalidArgumentException('Wallet binding must match the explicit operational identity.');
		if ($v['wallet_source_account']!=='pool-'.$v['operational_algo']) throw new InvalidArgumentException('Wallet source account is inconsistent with the operational identity.');
		if (!isset($v['runtime_root']) || !is_string($v['runtime_root']) || trim($v['runtime_root'])==='') throw new InvalidArgumentException('A runtime root is required.');
		foreach (array('state_filename'=>'.json','lock_filename'=>'.lock') as $key=>$suffix) {
			if (!isset($v[$key]) || !is_string($v[$key]) || basename($v[$key])!==$v[$key] || substr($v[$key],-strlen($suffix))!==$suffix) throw new InvalidArgumentException('Unsafe '.$key.'.');
		}
		if ($v['state_filename']===$v['lock_filename']) throw new InvalidArgumentException('State and lock paths collide.');
		$commissioningValues = isset($v['block_id_gt']) && is_int($v['block_id_gt']) && $v['block_id_gt']>0 && isset($v['batch_max_earnings']) && is_int($v['batch_max_earnings']) && $v['batch_max_earnings']>0;
		if ($this->isCommissioned() && !$commissioningValues) throw new InvalidArgumentException('Enabled lanes require a boundary and positive batch limit.');
		if (!$this->isCommissioned() && ($v['accounting_enabled'] || $v['payout_preparation_enabled'] || $v['wallet_send_enabled'])) throw new InvalidArgumentException('Partial lane activation is refused.');
		if (!$this->isCommissioned() && (array_key_exists('block_id_gt',$v) && $v['block_id_gt']!==null || array_key_exists('batch_max_earnings',$v) && $v['batch_max_earnings']!==null)) throw new InvalidArgumentException('Uncommissioned lanes must not imply activation values.');
		foreach (array('minimum_reserve_policy','payment_delay_policy') as $key) if (!isset($v[$key]) || !is_string($v[$key]) || trim($v[$key])==='') throw new InvalidArgumentException('Missing '.$key.'.');
	}
}

/** Canonical lane set plus cross-lane collision checks. */
class BadpoolLivePaymentLaneRegistry
{
	private $lanes;

	public function __construct($lanes=null)
	{
		$this->lanes=$lanes===null?self::configuredLanes():$lanes;
		$this->validateCollisions();
	}

	public static function scryptCompatibility() { $r=new self(); return $r->get('live-scrypt-v1'); }
	public function all() { return $this->lanes; }
	public function get($laneId) { foreach($this->lanes as $lane)if($lane instanceof BadpoolLivePaymentLaneConfiguration&&$lane->laneId()===$laneId)return $lane; throw new InvalidArgumentException('Unknown live-payment lane.'); }
	public function fromOwnershipEnvelope($owner) { if(!is_array($owner)||!isset($owner['lane']))return null; try{$lane=$this->get($owner['lane']);}catch(Exception $e){return null;} return $lane->isCommissioned()&&$lane->ownershipMatches($owner)?$lane:null; }

	private static function configuredLanes()
	{
		$root=dirname(__FILE__).'/../../../../runtime/badpool-payment-batches';
		$base=array('schema'=>BadpoolLivePaymentLaneConfiguration::SCHEMA,'version'=>1,'ownership_schema'=>BadpoolLivePaymentLaneConfiguration::OWNERSHIP_SCHEMA,'runtime_root'=>$root,'minimum_reserve_policy'=>'YAAMP_BADPOOL_MINIMUM_WALLET_RESERVES[coin_id]','payment_delay_policy'=>'existing_guarded_account_credit_delay','rpc_config_identity'=>null,'wallet_datadir_identity'=>null,'service_timer_identity'=>null);
		$enabled=array_merge($base,array('lane_id'=>'live-scrypt-v1','coin_id'=>1267,'db_algo'=>'scrypt','operational_algo'=>'scrypt','block_id_gt'=>29242,'batch_max_earnings'=>25,'state_filename'=>'live-scrypt-coordinator.json','lock_filename'=>'live-scrypt-coordinator.lock','wallet_binding_identity'=>'scrypt','wallet_source_account'=>'pool-scrypt','rpc_config_identity'=>'/etc/badcoin/pool-scrypt.conf','wallet_datadir_identity'=>'/var/lib/badcoin-pool-scrypt','service_timer_identity'=>'badpool-live-payment.timer','accounting_enabled'=>true,'payout_preparation_enabled'=>true,'wallet_send_enabled'=>true));
		$disabled=array(
			array('lane_id'=>'uncommissioned-yescrypt','coin_id'=>1266,'db_algo'=>'yescrypt','operational_algo'=>'yescrypt'),
			array('lane_id'=>'uncommissioned-skein','coin_id'=>1268,'db_algo'=>'skein','operational_algo'=>'skein'),
			array('lane_id'=>'uncommissioned-groestl','coin_id'=>1269,'db_algo'=>'badcoin-groestl','operational_algo'=>'groestl'),
			array('lane_id'=>'uncommissioned-sha256d','coin_id'=>1270,'db_algo'=>'sha256','operational_algo'=>'sha256d'),
		);
		$out=array(new BadpoolLivePaymentLaneConfiguration($enabled));
		foreach($disabled as $lane){$op=$lane['operational_algo'];$out[]=new BadpoolLivePaymentLaneConfiguration($base+$lane+array('block_id_gt'=>null,'batch_max_earnings'=>null,'state_filename'=>$lane['lane_id'].'-coordinator.json','lock_filename'=>$lane['lane_id'].'-coordinator.lock','wallet_binding_identity'=>$op,'wallet_source_account'=>'pool-'.$op,'accounting_enabled'=>false,'payout_preparation_enabled'=>false,'wallet_send_enabled'=>false));}
		return $out;
	}

	private function validateCollisions()
	{
		$seen=array('lane'=>array(),'coin'=>array(),'state'=>array(),'lock'=>array(),'wallet'=>array());
		foreach($this->lanes as $lane){
			if(!$lane instanceof BadpoolLivePaymentLaneConfiguration)throw new InvalidArgumentException('Registry entries must be lane configurations.');
			$values=array('lane'=>$lane->laneId(),'coin'=>(string)$lane->coinId(),'state'=>$lane->statePath(),'lock'=>$lane->lockPath(),'wallet'=>$lane->get('wallet_source_account'));
			foreach($values as $kind=>$value){if(isset($seen[$kind][$value]))throw new InvalidArgumentException('Live-payment lane '.$kind.' collision.');$seen[$kind][$value]=true;}
			if(isset($seen['state'][$lane->lockPath()])||isset($seen['lock'][$lane->statePath()]))throw new InvalidArgumentException('Live-payment state/lock path collision.');
		}
	}
}
