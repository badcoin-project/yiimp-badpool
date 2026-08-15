<?php

require_once dirname(__DIR__).'/web/yaamp/core/backend/BadpoolBackwardMaturityDryrun.php';

class CConsoleCommand {}
function arraySafeVal($array, $key, $default=null) { return is_array($array) && array_key_exists($key, $array) ? $array[$key] : $default; }
if (!defined('YAAMP_ALLOW_EXCHANGE')) define('YAAMP_ALLOW_EXCHANGE', false);
if (!defined('YAAMP_PAYMENTS_FREQ')) define('YAAMP_PAYMENTS_FREQ', 3600);

$failures = array();
function backwardExpect($condition, $message) { global $failures; if (!$condition) $failures[] = $message; }
function backwardArgs($earningIds=null, $blockIds=null) {
	if ($earningIds === null) $earningIds = BadpoolBackwardMaturityDryrun::expectedEarningIds();
	if ($blockIds === null) $blockIds = BadpoolBackwardMaturityDryrun::expectedBlockIds();
	return array(
		'--coin-id=1267',
		'--selected-earning-ids='.implode(',', $earningIds),
		'--selected-block-ids='.implode(',', $blockIds),
		'--expected-inventory-checksum='.BadpoolBackwardMaturityDryrun::INVENTORY_CHECKSUM,
		'--format=json',
	);
}
function backwardFixture() {
	$earningIds = BadpoolBackwardMaturityDryrun::expectedEarningIds();
	$blockIds = BadpoolBackwardMaturityDryrun::expectedBlockIds();
	$earnings = array(); $blocks = array();
	foreach ($earningIds as $index=>$earningId) {
		$blockId = $blockIds[$index];
		$height = $index === 0 ? 1858981 : ($index === 70 ? 1859322 : 1858981 + $index * 4);
		$time = $index === 0 ? 1783549939 : ($index === 70 ? 1783590153 : 1783549939 + $index * 500);
		$amount = $index === 70 ? '153554.877229050000' : '1.000000000000';
		$earnings[] = array('earning_id'=>$earningId,'userid'=>79,'coinid'=>1267,'blockid'=>$blockId,'amount'=>$amount,'status'=>0,'mature_time'=>0,'create_time'=>$time,'block_id'=>$blockId,'block_height'=>$height,'block_time'=>$time,'block_coin_id'=>1267,'block_category'=>'immature','account_id'=>79,'account_coin_id'=>1267,'account_last_earning'=>0);
		$blocks[] = array('block_id'=>$blockId,'block_height'=>$height,'block_time'=>$time,'block_coin_id'=>1267,'block_category'=>'immature');
	}
	return array($earnings, $blocks, $earnings, array(array('status'=>2,'row_count'=>50)));
}
function backwardValidation($mutate=null) {
	$fixture = backwardFixture();
	if ($mutate) $mutate($fixture);
	return BadpoolBackwardMaturityDryrun::validate($fixture[0], $fixture[1], $fixture[2], $fixture[3]);
}
function backwardHolds($report, $assertion) {
	return $report['status'] === 'hold' && $report['validation_assertions'][$assertion]['status'] === 'hold';
}

require_once dirname(__DIR__).'/web/yaamp/commands/BadpoolGuardCommand.php';
class BackwardDryrunFakeGuard {
	public $errors=array(); public $queries=array();
	public function isAllCoinsPreview(){return false;}
	public function isValid(){return empty($this->errors);}
	public function addError($message){$this->errors[]=$message;}
	public function getFormat(){return 'json';}
	public function getScope(){return array('all_coins_preview'=>false,'coin_id'=>1267,'coin'=>array('id'=>1267,'algo'=>'scrypt','symbol'=>'BAD'));}
	public function baseReport($status='ok'){return array('schema'=>BadpoolGuardReport::PREVIEW_SCHEMA,'generated_at'=>'fixture','command'=>BadpoolBackwardMaturityDryrun::COMMAND,'mode'=>BadpoolGuardReport::PREVIEW_MODE,'status'=>$status,'read_only'=>true,'wallet_reads'=>false,'wallet_sends'=>false,'db_mutations'=>false,'service_actions'=>false,'scope'=>$this->getScope(),'summary'=>array(),'items'=>array(),'warnings'=>array(),'errors'=>$this->errors,'blocked_actions'=>array());}
	public function refusalReport(){return $this->baseReport('refused');}
	public function finalizeReport($report){$report['errors']=$this->errors;return BadpoolGuardReport::finalize($report);}
	public function selectAll($sql,$params=array()){$this->queries[]=$sql;$f=backwardFixture();if(strpos($sql,'LEFT JOIN blocks')!==false)return $f[0];if(strpos($sql,'FROM blocks B')!==false)return $f[1];if(strpos($sql,'E.blockid IN')!==false)return $f[2];if(strpos($sql,'BETWEEN 12801 AND 12850')!==false)return $f[3];return array();}
}
function backwardCommand(&$guard){$guard=new BackwardDryrunFakeGuard;$command=new BadpoolGuardCommand;$property=new ReflectionProperty('BadpoolGuardCommand','guard');$property->setAccessible(true);$property->setValue($command,$guard);return $command;}
function backwardMethod($name){$method=new ReflectionMethod('BadpoolGuardCommand',$name);$method->setAccessible(true);return $method;}

$parsed = BadpoolBackwardMaturityDryrun::parseOptions(backwardArgs());
backwardExpect($parsed['status'] === 'pass', 'exact explicit inventory options did not pass');
$pass = backwardValidation();
backwardExpect($pass['status'] === 'pass', 'exact 71-row fixture did not pass');
backwardExpect($pass['summary']['row_count'] === 71 && $pass['summary']['distinct_block_count'] === 71, 'validated counts changed');
backwardExpect($pass['summary']['amount'] === '153624.877229050000', 'decimal-safe amount changed');
backwardExpect($pass['summary']['earning_id_groups'] === '12623-12662,12696-12726', 'earning ID groups changed');
backwardExpect($pass['summary']['block_id_groups'] === '14263-14302,14336-14359,14361-14367', 'block ID groups changed');
backwardExpect($pass['summary']['height_range'] === array('min'=>1858981,'max'=>1859322), 'height range changed');
backwardExpect($pass['summary']['time_range'] === array('min'=>1783549939,'max'=>1783590153), 'time range changed');
backwardExpect($pass['forward_exact50_exclusion']['former_exact50_current_status_distribution'] === array('2'=>50), 'exact-50 status distribution missing');
backwardExpect(BadpoolBackwardMaturityDryrun::INVENTORY_CHECKSUM_PURPOSE === 'read-only inventory comparison only; not payout authorization; not maturity authorization; not account-credit authorization', 'inventory checksum was mislabeled as authorization');
backwardExpect(BadpoolBackwardMaturityDryrun::REPORT_CHECKSUM_PURPOSE === 'preview audit comparison only; not payout authorization; not maturity authorization; not account-credit authorization', 'report checksum purpose changed');
$command = backwardCommand($commandGuard);
$commandReport = backwardMethod('backwardMaturityTransitionDryrunReport')->invoke($command, backwardArgs());
$commandReport = backwardMethod('finalizeCommandReport')->invoke($command, $commandReport);
backwardExpect($commandReport['schema'] === BadpoolBackwardMaturityDryrun::SCHEMA, 'command report schema changed');
backwardExpect($commandReport['status'] === 'ok' && $commandReport['summary']['validation_status'] === 'pass', 'command report did not pass');
backwardExpect($commandReport['scope']['expected_inventory_checksum'] === BadpoolBackwardMaturityDryrun::INVENTORY_CHECKSUM, 'inventory checksum missing from report scope');
backwardExpect($commandReport['scope']['expected_inventory_checksum_purpose'] === BadpoolBackwardMaturityDryrun::INVENTORY_CHECKSUM_PURPOSE, 'inventory checksum report purpose implies authorization');
backwardExpect($commandReport['report_checksum']['purpose'] === BadpoolBackwardMaturityDryrun::REPORT_CHECKSUM_PURPOSE, 'report checksum purpose implies authorization');
backwardExpect($commandReport['report_checksum']['excludes'] === array('generated_at','report_checksum'), 'report checksum exclusions changed');
backwardExpect(count($commandGuard->queries) === 4, 'command did not perform exactly four bounded reads');
foreach($commandGuard->queries as $sql) backwardExpect(strpos(ltrim($sql),'SELECT ') === 0, 'command attempted a non-SELECT query');
foreach(array('approval_package_generation','maturity_apply','account_credit_apply','payout_row_creation','wallet_send','database_mutation','backend_loop_execution','service_changes','share_deletion') as $blocked) backwardExpect($commandReport['blocked_actions'][$blocked] === true, 'blocked action missing: '.$blocked);

$rangeEarnings = range(12623, 12726);
$rangeBlocks = range(14263, 14367);
backwardExpect(BadpoolBackwardMaturityDryrun::parseOptions(backwardArgs($rangeEarnings, BadpoolBackwardMaturityDryrun::expectedBlockIds()))['status'] === 'fail', 'min/max earning range including excluded gap passed');
backwardExpect(BadpoolBackwardMaturityDryrun::parseOptions(backwardArgs(BadpoolBackwardMaturityDryrun::expectedEarningIds(), $rangeBlocks))['status'] === 'fail', 'min/max block range including excluded gaps passed');
$ids = BadpoolBackwardMaturityDryrun::expectedEarningIds(); array_pop($ids);
backwardExpect(BadpoolBackwardMaturityDryrun::parseOptions(backwardArgs($ids, BadpoolBackwardMaturityDryrun::expectedBlockIds()))['status'] === 'fail', 'missing earning ID passed');
$ids = BadpoolBackwardMaturityDryrun::expectedEarningIds(); $ids[] = 12663;
backwardExpect(BadpoolBackwardMaturityDryrun::parseOptions(backwardArgs($ids, BadpoolBackwardMaturityDryrun::expectedBlockIds()))['status'] === 'fail', 'extra earning ID passed');
$ids = BadpoolBackwardMaturityDryrun::expectedBlockIds(); array_pop($ids);
backwardExpect(BadpoolBackwardMaturityDryrun::parseOptions(backwardArgs(BadpoolBackwardMaturityDryrun::expectedEarningIds(), $ids))['status'] === 'fail', 'missing block ID passed');
$ids = BadpoolBackwardMaturityDryrun::expectedBlockIds(); $ids[] = 14360;
backwardExpect(BadpoolBackwardMaturityDryrun::parseOptions(backwardArgs(BadpoolBackwardMaturityDryrun::expectedEarningIds(), $ids))['status'] === 'fail', 'extra block ID passed');

foreach (array(1,2) as $status) {
	$r = backwardValidation(function(&$f) use ($status) { $f[0][0]['status']=$status; });
	backwardExpect(backwardHolds($r, 'all_earnings_status_0'), 'earning status '.$status.' did not HOLD');
}
foreach (array('generate'=>'no_generate_blocks','orphan'=>'no_orphan_blocks') as $category=>$assertion) {
	$r = backwardValidation(function(&$f) use ($category) { $f[0][0]['block_category']=$category; $f[1][0]['block_category']=$category; });
	backwardExpect(backwardHolds($r, $assertion), $category.' block did not HOLD');
}
$r = backwardValidation(function(&$f) { $f[0][0]['block_id']=null; $f[0][0]['block_coin_id']=null; $f[0][0]['block_category']=null; });
backwardExpect(backwardHolds($r, 'all_rows_have_block_linkage'), 'missing block linkage did not HOLD');
$r = backwardValidation(function(&$f) { $f[0][0]['account_id']=null; });
backwardExpect(backwardHolds($r, 'no_missing_account_linkage'), 'missing account linkage did not HOLD');
$r = backwardValidation(function(&$f) { $f[0][0]['userid']=null; });
backwardExpect(backwardHolds($r, 'no_missing_user_linkage'), 'missing user linkage did not HOLD');
$r = backwardValidation(function(&$f) { $f[0][0]['account_last_earning']=$f[0][0]['block_time']; });
backwardExpect(backwardHolds($r, 'no_prior_credit_risk_via_account_last_earning'), 'prior-credit risk did not HOLD');
$r = backwardValidation(function(&$f) { $f[0][1]['block_id']=$f[0][0]['block_id']; $f[0][1]['userid']=$f[0][0]['userid']; $f[0][1]['amount']=$f[0][0]['amount']; });
backwardExpect(backwardHolds($r, 'no_exact_duplicate_groups'), 'exact duplicate group did not HOLD');
$r = backwardValidation(function(&$f) { $f[0][1]['block_id']=$f[0][0]['block_id']; });
backwardExpect(backwardHolds($r, 'no_multirow_block_groups'), 'multirow block group did not HOLD');
$r = backwardValidation(function(&$f) { $f[2][]=array('earning_id'=>99999,'blockid'=>14263,'coinid'=>1267,'status'=>0); });
backwardExpect(backwardHolds($r, 'no_selected_block_earnings_outside_scope'), 'outside-scope earning on selected block did not HOLD');
$r = backwardValidation(function(&$f) { $f[0][0]['earning_id']=12801; });
backwardExpect(backwardHolds($r, 'no_former_exact50_overlap'), 'former exact-50 overlap did not HOLD');

$commandSource = file_get_contents(dirname(__DIR__).'/web/yaamp/commands/BadpoolGuardCommand.php');
backwardExpect(strpos($commandSource, "'backward-maturity-transition-apply'") === false, 'backward apply action exists');
backwardExpect(strpos($commandSource, "'backward-maturity-transition-approval-package'") !== false, 'backward approval-package action missing');
backwardExpect(strpos($commandSource, 'operator-confirms-backward') === false, 'backward command accepts operator confirmation');

if ($failures) {
	echo "Badpool backward maturity dry-run harness FAILED\n";
	foreach ($failures as $failure) echo " - $failure\n";
	exit(1);
}
echo "Badpool backward maturity dry-run harness passed\n";
