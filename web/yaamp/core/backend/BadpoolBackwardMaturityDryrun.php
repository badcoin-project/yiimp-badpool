<?php

require_once(dirname(__FILE__).'/BadpoolStage1Manifest.php');

class BadpoolBackwardMaturityDryrun
{
	const SCHEMA = 'badpool.backward_maturity_dryrun.v1';
	const COMMAND = 'backward-maturity-transition-dryrun';
	const COIN_ID = 1267;
	const ALGO = 'scrypt';
	const SYMBOL = 'BAD';
	const ROW_COUNT = 71;
	const AMOUNT = '153624.877229050000';
	const ACCOUNT_ID = 79;
	const USER_ID = 79;
	const MIN_HEIGHT = 1858981;
	const MAX_HEIGHT = 1859322;
	const MIN_TIME = 1783549939;
	const MAX_TIME = 1783590153;
	const INVENTORY_CHECKSUM = '145db645b8ce0b04b55a13ef788b00c0f87b10674bbc9ab2d7e00c250bcd2d2c';
	const INVENTORY_CHECKSUM_PURPOSE = 'read-only inventory comparison only; not payout authorization; not maturity authorization; not account-credit authorization';
	const REPORT_CHECKSUM_PURPOSE = 'preview audit comparison only; not payout authorization; not maturity authorization; not account-credit authorization';

	public static function expectedEarningIds()
	{
		return array_merge(range(12623, 12662), range(12696, 12726));
	}

	public static function expectedBlockIds()
	{
		return array_merge(range(14263, 14302), range(14336, 14359), range(14361, 14367));
	}

	public static function excludedEarningIds()
	{
		return range(12663, 12695);
	}

	public static function excludedBlockIds()
	{
		return array_merge(range(14303, 14335), array(14360));
	}

	public static function formerExact50EarningIds()
	{
		return range(12801, 12850);
	}

	public static function parseOptions($args)
	{
		$allowed = array('coin-id','selected-earning-ids','selected-block-ids','expected-inventory-checksum','format');
		$options = array();
		foreach ($args as $arg) {
			if (!preg_match('/^--([^=]+)=(.*)$/', $arg, $matches)) return self::parseFailure('Every option requires an explicit value.');
			$name = strtolower($matches[1]);
			if (!in_array($name, $allowed, true)) return self::parseFailure('Unknown option refused: --'.$matches[1].'.');
			if (array_key_exists($name, $options)) return self::parseFailure('Duplicate option refused: --'.$matches[1].'.');
			$options[$name] = $matches[2];
		}
		foreach ($allowed as $name) if (!array_key_exists($name, $options) || $options[$name] === '') return self::parseFailure('Missing required --'.$name.'.');
		if ($options['coin-id'] !== (string)self::COIN_ID) return self::parseFailure('--coin-id must exactly equal 1267.');
		if (strtolower($options['format']) !== 'json') return self::parseFailure('This contract requires --format=json.');
		if (!preg_match('/^[a-f0-9]{64}$/', $options['expected-inventory-checksum'])) return self::parseFailure('--expected-inventory-checksum must be a lowercase SHA-256 value.');
		if ($options['expected-inventory-checksum'] !== self::INVENTORY_CHECKSUM) return self::parseFailure('The supplied inventory checksum does not match the validated read-only inventory.');
		$earnings = self::parseIds($options['selected-earning-ids']);
		$blocks = self::parseIds($options['selected-block-ids']);
		if ($earnings === false) return self::parseFailure('--selected-earning-ids must be a non-empty CSV of unique canonical positive integers.');
		if ($blocks === false) return self::parseFailure('--selected-block-ids must be a non-empty CSV of unique canonical positive integers.');
		if ($earnings !== self::expectedEarningIds()) return self::parseFailure('Selected earning IDs must exactly equal the explicit validated inventory; range expansion, missing IDs, extra IDs, and excluded gaps are refused.');
		if ($blocks !== self::expectedBlockIds()) return self::parseFailure('Selected block IDs must exactly equal the explicit validated inventory; range expansion, missing IDs, extra IDs, and excluded gaps are refused.');
		return array('status'=>'pass', 'options'=>$options, 'selected_earning_ids'=>$earnings, 'selected_block_ids'=>$blocks, 'message'=>null);
	}

	private static function parseFailure($message)
	{
		return array('status'=>'fail', 'options'=>array(), 'selected_earning_ids'=>array(), 'selected_block_ids'=>array(), 'message'=>$message);
	}

	private static function parseIds($csv)
	{
		if (!preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/', (string)$csv)) return false;
		$ids = array_map('intval', explode(',', $csv));
		if (count($ids) !== count(array_unique($ids))) return false;
		sort($ids, SORT_NUMERIC);
		return $ids;
	}

	public static function validate($earningRows, $blockRows, $outsideRows, $exact50StatusRows)
	{
		$expectedEarnings = self::expectedEarningIds();
		$expectedBlocks = self::expectedBlockIds();
		$earningIds = array(); $linkedBlockIds = array(); $amount = BadpoolStage1Manifest::normalizeAmount('0');
		$statusRisk = 0; $missingBlock = 0; $wrongCoin = 0; $generate = 0; $orphan = 0; $nonImmature = 0;
		$missingAccount = 0; $missingUser = 0; $wrongAccount = 0; $wrongUser = 0; $priorCredit = 0; $duplicateGroups = 0; $multirowBlocks = 0;
		$heights = array(); $blockTimes = array(); $createTimes = array(); $duplicateKeys = array(); $blockCounts = array();
		foreach ((array)$earningRows as $row) {
			$id = intval(self::value($row, 'earning_id')); $blockId = intval(self::value($row, 'block_id'));
			$earningIds[] = $id;
			if ($blockId > 0) $linkedBlockIds[] = $blockId;
			try { $normalized = BadpoolStage1Manifest::normalizeAmount(self::value($row, 'amount', '0')); $amount = BadpoolStage1Manifest::addAmounts($amount, $normalized); }
			catch (Exception $e) { $normalized = 'invalid'; }
			if (intval(self::value($row, 'status', -1)) !== 0) $statusRisk++;
			if ($blockId <= 0) $missingBlock++;
			if (intval(self::value($row, 'coinid')) !== self::COIN_ID || intval(self::value($row, 'block_coin_id')) !== self::COIN_ID) $wrongCoin++;
			$category = (string)self::value($row, 'block_category', '');
			if ($category === 'generate') $generate++;
			if ($category === 'orphan') $orphan++;
			if ($category !== 'immature') $nonImmature++;
			if (intval(self::value($row, 'userid')) <= 0) $missingUser++;
			if (intval(self::value($row, 'account_id')) <= 0) $missingAccount++;
			if (intval(self::value($row, 'userid')) !== self::USER_ID) $wrongUser++;
			if (intval(self::value($row, 'account_id')) !== self::ACCOUNT_ID) $wrongAccount++;
			$createTime = intval(self::value($row, 'create_time'));
			$lastEarning = self::value($row, 'account_last_earning');
			if ($lastEarning !== null && $lastEarning !== '' && $createTime > 0 && $createTime <= intval($lastEarning)) $priorCredit++;
			$key = $blockId.'|'.intval(self::value($row, 'userid')).'|'.$normalized.'|'.intval(self::value($row, 'status', -1));
			$duplicateKeys[$key] = isset($duplicateKeys[$key]) ? $duplicateKeys[$key] + 1 : 1;
			$blockCounts[$blockId] = isset($blockCounts[$blockId]) ? $blockCounts[$blockId] + 1 : 1;
			if (self::value($row, 'block_height') !== null) $heights[] = intval(self::value($row, 'block_height'));
			if (self::value($row, 'block_time') !== null) $blockTimes[] = intval(self::value($row, 'block_time'));
			if (self::value($row, 'create_time') !== null) $createTimes[] = intval(self::value($row, 'create_time'));
		}
		foreach ($duplicateKeys as $count) if ($count > 1) $duplicateGroups++;
		foreach ($blockCounts as $count) if ($count > 1) $multirowBlocks++;
		sort($earningIds, SORT_NUMERIC); $linkedBlockIds = array_values(array_unique($linkedBlockIds)); sort($linkedBlockIds, SORT_NUMERIC);
		$actualBlocks = array(); $blockWrongCoin = 0; $blockGenerate = 0; $blockOrphan = 0; $blockNonImmature = 0;
		foreach ((array)$blockRows as $row) {
			$actualBlocks[] = intval(self::value($row, 'block_id'));
			if (intval(self::value($row, 'block_coin_id')) !== self::COIN_ID) $blockWrongCoin++;
			$category = (string)self::value($row, 'block_category', '');
			if ($category === 'generate') $blockGenerate++;
			if ($category === 'orphan') $blockOrphan++;
			if ($category !== 'immature') $blockNonImmature++;
		}
		sort($actualBlocks, SORT_NUMERIC);
		$outside = array(); foreach ((array)$outsideRows as $row) { $id=intval(self::value($row,'earning_id')); if(!in_array($id,$expectedEarnings,true))$outside[]=$id; }
		$overlap = array_values(array_intersect($earningIds, self::formerExact50EarningIds()));
		$excludedEarningOverlap = array_values(array_intersect($earningIds, self::excludedEarningIds()));
		$excludedBlockOverlap = array_values(array_intersect(array_unique(array_merge($actualBlocks,$linkedBlockIds)), self::excludedBlockIds()));
		$exact50Distribution = array(); foreach ((array)$exact50StatusRows as $row) $exact50Distribution[(string)intval(self::value($row,'status'))] = intval(self::value($row,'row_count'));
		ksort($exact50Distribution, SORT_NUMERIC);
		$assertions = array();
		self::assertion($assertions, 'selected_earning_ids_exact', $earningIds === $expectedEarnings, count($expectedEarnings), count($earningIds));
		self::assertion($assertions, 'selected_block_ids_exact', $actualBlocks === $expectedBlocks && $linkedBlockIds === $expectedBlocks, count($expectedBlocks), count($actualBlocks));
		self::assertion($assertions, 'row_count', count($earningRows) === self::ROW_COUNT, self::ROW_COUNT, count($earningRows));
		self::assertion($assertions, 'distinct_block_count', count($linkedBlockIds) === self::ROW_COUNT, self::ROW_COUNT, count($linkedBlockIds));
		self::assertion($assertions, 'amount_decimal_safe', $amount === self::AMOUNT, self::AMOUNT, $amount);
		self::assertion($assertions, 'coin_id_1267', $wrongCoin + $blockWrongCoin === 0, 0, $wrongCoin + $blockWrongCoin);
		self::assertion($assertions, 'all_earnings_status_0', $statusRisk === 0, 0, $statusRisk);
		self::assertion($assertions, 'all_rows_have_block_linkage', $missingBlock === 0, 0, $missingBlock);
		self::assertion($assertions, 'all_blocks_immature', $nonImmature + $blockNonImmature === 0, 0, $nonImmature + $blockNonImmature);
		self::assertion($assertions, 'no_generate_blocks', $generate + $blockGenerate === 0, 0, $generate + $blockGenerate);
		self::assertion($assertions, 'no_orphan_blocks', $orphan + $blockOrphan === 0, 0, $orphan + $blockOrphan);
		self::assertion($assertions, 'no_missing_user_linkage', $missingUser === 0, 0, $missingUser);
		self::assertion($assertions, 'no_missing_account_linkage', $missingAccount === 0, 0, $missingAccount);
		self::assertion($assertions, 'all_user_ids_79', $wrongUser === 0, 0, $wrongUser);
		self::assertion($assertions, 'all_account_ids_79', $wrongAccount === 0, 0, $wrongAccount);
		self::assertion($assertions, 'no_prior_credit_risk_via_account_last_earning', $priorCredit === 0, 0, $priorCredit);
		self::assertion($assertions, 'no_exact_duplicate_groups', $duplicateGroups === 0, 0, $duplicateGroups);
		self::assertion($assertions, 'no_multirow_block_groups', $multirowBlocks === 0, 0, $multirowBlocks);
		self::assertion($assertions, 'no_selected_block_earnings_outside_scope', count($outside) === 0, 0, count($outside));
		self::assertion($assertions, 'no_former_exact50_overlap', count($overlap) === 0, 0, count($overlap));
		self::assertion($assertions, 'excluded_earning_gaps_not_selected', count($excludedEarningOverlap) === 0, 0, count($excludedEarningOverlap));
		self::assertion($assertions, 'excluded_block_gaps_not_selected', count($excludedBlockOverlap) === 0, 0, count($excludedBlockOverlap));
		self::assertion($assertions, 'height_range_exact', self::rangeSummary($heights) === array('min'=>self::MIN_HEIGHT,'max'=>self::MAX_HEIGHT), array('min'=>self::MIN_HEIGHT,'max'=>self::MAX_HEIGHT), self::rangeSummary($heights));
		self::assertion($assertions, 'time_range_exact', self::rangeSummary($blockTimes) === array('min'=>self::MIN_TIME,'max'=>self::MAX_TIME), array('min'=>self::MIN_TIME,'max'=>self::MAX_TIME), self::rangeSummary($blockTimes));
		$failed = array(); foreach ($assertions as $name=>$assertion) if ($assertion['status'] !== 'pass') $failed[]=$name;
		return array(
			'status'=>empty($failed)?'pass':'hold', 'failed_assertions'=>$failed, 'validation_assertions'=>$assertions,
			'summary'=>array('row_count'=>count($earningRows),'distinct_block_count'=>count($linkedBlockIds),'amount'=>$amount,'earning_id_groups'=>self::idGroups($earningIds),'block_id_groups'=>self::idGroups($linkedBlockIds),'earning_range'=>self::rangeSummary($earningIds),'block_range'=>self::rangeSummary($linkedBlockIds),'height_range'=>self::rangeSummary($heights),'time_range'=>self::rangeSummary($blockTimes),'block_time_range'=>self::rangeSummary($blockTimes),'create_time_range'=>self::rangeSummary($createTimes),'validation_status'=>empty($failed)?'pass':'hold'),
			'forward_exact50_exclusion'=>array('former_exact50_earning_range'=>array('min'=>12801,'max'=>12850),'former_exact50_current_status_distribution'=>$exact50Distribution,'overlap_count'=>count($overlap)),
		);
	}

	private static function assertion(&$assertions, $name, $pass, $expected, $actual)
	{
		$assertions[$name] = array('status'=>$pass?'pass':'hold', 'expected'=>$expected, 'actual'=>$actual);
	}

	private static function rangeSummary($values)
	{
		if (empty($values)) return null;
		return array('min'=>min($values), 'max'=>max($values));
	}

	public static function idGroups($ids)
	{
		if (empty($ids)) return '';
		$ids=array_values(array_unique($ids)); sort($ids,SORT_NUMERIC); $groups=array(); $start=$ids[0]; $last=$ids[0];
		for($i=1;$i<count($ids);$i++){if($ids[$i]===$last+1){$last=$ids[$i];continue;}$groups[]=$start===$last?(string)$start:$start.'-'.$last;$start=$last=$ids[$i];}
		$groups[]=$start===$last?(string)$start:$start.'-'.$last;
		return implode(',', $groups);
	}

	private static function value($array, $key, $default=null)
	{
		return is_array($array) && array_key_exists($key, $array) ? $array[$key] : $default;
	}
}