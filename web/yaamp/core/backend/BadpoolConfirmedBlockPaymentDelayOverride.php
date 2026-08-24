<?php

/**
 * Strict, read-only authorization for bypassing the normal payment-delay gate.
 * The package is intentionally operator-authored and is rechecked against the
 * durable database state immediately before phase 4 is allowed to pass.
 */
class BadpoolConfirmedBlockPaymentDelayOverride
{
	const SCHEMA = 'badpool.confirmed_block_payment_delay_override.v1';
	const CONFIRMATION = 'override_12h_payment_delay_for_exact_confirmed_block_scope';
	const MAX_AGE_SECONDS = 900;

	public static function validateOptions($options)
	{
		$keys=array('payment_delay_override_package','payment_delay_override_package_checksum','operator_confirms_payment_delay_override');
		$present=0;foreach($keys as $key)if(array_key_exists($key,$options))$present++;
		if($present===0)return array('status'=>'absent');
		if($present!==count($keys))return self::fail('missing_option','All three payment-delay override options are required together.');
		if(!is_string($options[$keys[0]])||$options[$keys[0]]==='')return self::fail('malformed_option','Override package path must be non-empty.');
		if(!is_string($options[$keys[1]])||!preg_match('/^[0-9a-f]{64}$/',$options[$keys[1]]))return self::fail('malformed_checksum','Override package checksum must be lowercase canonical SHA-256.');
		if($options[$keys[2]]!==self::CONFIRMATION)return self::fail('operator_confirmation_required','Exact payment-delay override operator confirmation is required.');
		return array('status'=>'pass');
	}

	public static function validate($options,$ledger,$guard,$now=null)
	{
		$valid=self::validateOptions($options);if($valid['status']!=='pass')return $valid;
		$path=$options['payment_delay_override_package'];
		if(!is_file($path)||!is_readable($path))return self::fail('missing_evidence','Override package is missing or unreadable.');
		if(!hash_equals($options['payment_delay_override_package_checksum'],hash_file('sha256',$path)))return self::fail('file_checksum_mismatch','Override package file checksum mismatch.');
		$package=json_decode(file_get_contents($path),true);if(!is_array($package))return self::fail('malformed_package','Override package must be valid JSON.');
		if(arraySafeVal($package,'schema')!==self::SCHEMA)return self::fail('malformed_package','Override package schema is invalid.');
		$sealed=arraySafeVal($package,'scope_checksum');if(!is_string($sealed)||!preg_match('/^[0-9a-f]{64}$/',$sealed))return self::fail('malformed_package','Package scope checksum is missing or malformed.');
		$input=$package;unset($input['scope_checksum']);$actual=BadpoolGuardReport::checksum($input);
		if(!hash_equals($sealed,(string)arraySafeVal($actual,'value')))return self::fail('scope_checksum_mismatch','Override package scope checksum mismatch.');
		$generated=strtotime((string)arraySafeVal($package,'generated_at',''));$now=$now===null?time():intval($now);
		if($generated===false||$generated>$now+60||$now-$generated>self::MAX_AGE_SECONDS)return self::fail('stale_package','Override package is stale or has an invalid generation time.');

		$coinIds=self::ids(arraySafeVal($package,'selected_coin_ids'));$blockIds=self::ids(arraySafeVal($package,'selected_block_ids'));
		$accountIds=self::ids(arraySafeVal($package,'selected_account_ids'));$earningIds=self::ids(arraySafeVal($package,'selected_earning_ids'));
		if($coinIds===null||$blockIds===null||$accountIds===null||$earningIds===null||!$coinIds||!$blockIds||!$accountIds||!$earningIds)return self::fail('malformed_scope','Package scope lists must contain sorted, unique canonical positive integer IDs.');
		$expectedCoins=array();foreach((array)arraySafeVal($ledger,'selected_coin_scope',array()) as $coin)$expectedCoins[]=intval(arraySafeVal($coin,'id',arraySafeVal($coin,'coin_id')));
		$expectedBlocks=self::normalized((array)arraySafeVal($ledger,'selected_block_ids',array()));$expectedAccounts=self::normalized((array)arraySafeVal($ledger,'selected_account_ids',array()));$expectedEarnings=self::normalized((array)arraySafeVal($ledger,'selected_earning_ids',array()));
		if($coinIds!==self::normalized($expectedCoins)||$blockIds!==$expectedBlocks||$accountIds!==$expectedAccounts||$earningIds!==$expectedEarnings)return self::fail('ledger_scope_mismatch','Override package does not exactly match the durable batch coin/block/account/earning scope.');

		$rows=$guard->selectAll("SELECT E.id AS earning_id,E.userid AS account_id,E.coinid,E.blockid,E.status,B.category,B.confirmations,C.mature_blocks FROM earnings E INNER JOIN blocks B ON B.id=E.blockid AND B.coin_id=E.coinid INNER JOIN coins C ON C.id=E.coinid WHERE E.coinid IN (".implode(',',array_map('intval',$coinIds)).") AND E.id IN (".implode(',',array_map('intval',$earningIds)).") ORDER BY E.id",array());
		if(count($rows)!==count($earningIds))return self::fail('missing_evidence','Durable confirmed-block evidence is missing for one or more selected earnings.');
		$seenBlocks=array();$seenAccounts=array();foreach($rows as $row){
			if(intval($row['status'])!==1||strtolower((string)$row['category'])!=='generate'||!is_numeric($row['confirmations'])||!is_numeric($row['mature_blocks'])||intval($row['confirmations'])<intval($row['mature_blocks']))return self::fail('unconfirmed_block','Every selected earning must remain status 1 and link to a generate block with confirmations at or above mature_blocks.');
			$seenBlocks[]=intval($row['blockid']);$seenAccounts[]=intval($row['account_id']);
		}
		if(self::normalized($seenBlocks)!==$blockIds||self::normalized($seenAccounts)!==$accountIds)return self::fail('evidence_scope_mismatch','Durable evidence does not exactly reproduce the selected block/account scope.');
		return array('status'=>'pass','reason'=>'confirmed_block_payment_delay_override','package'=>$package,'evidence'=>array('source'=>'earnings INNER JOIN blocks INNER JOIN coins','required_block_category'=>'generate','confirmation_rule'=>'blocks.confirmations >= coins.mature_blocks'));
	}

	private static function ids($value){if(!is_array($value))return null;$out=array();foreach($value as $id){if(!is_int($id)||$id<1||in_array($id,$out,true))return null;$out[]=$id;}$canonical=$out;sort($canonical,SORT_NUMERIC);return $canonical===$out?$out:null;}
	private static function normalized($ids){$out=array();foreach($ids as $id){$id=intval($id);if($id>0&&!in_array($id,$out,true))$out[]=$id;}sort($out,SORT_NUMERIC);return $out;}
	private static function fail($reason,$message){return array('status'=>'refused','reason'=>$reason,'errors'=>array($message));}
}
