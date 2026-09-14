<?php

$root = dirname(__DIR__);
$commandPath = $root.'/web/yaamp/commands/BadpoolGuardCommand.php';
$failures = array();

function badpool_expect_contains($label, $haystack, $needle, &$failures)
{
	if (strpos($haystack, $needle) === false) {
		$failures[] = "$label: missing expected text: $needle";
	}
}

function badpool_expect_not_contains($label, $haystack, $needle, &$failures)
{
	if (strpos($haystack, $needle) !== false) {
		$failures[] = "$label: found forbidden text: $needle";
	}
}

$command = is_file($commandPath) ? file_get_contents($commandPath) : '';
if ($command === '') {
	$failures[] = "Unable to read required file: $commandPath";
}

foreach (array(
	'earnings-maturity-transition-dryrun',
	'earnings-maturity-transition-approval-package',
	'earnings-maturity-transition-apply',
	'account-credit-clear-dryrun',
	'account-credit-clear-approval-package',
	'account-credit-clear-apply',
) as $action) {
	badpool_expect_contains("action registered: $action", $command, "'$action'", $failures);
	badpool_expect_contains("help documents: $action", $command, "badpoolguard $action", $failures);
}

badpool_expect_contains('maturity proof status emitted', $command, 'maturity_proof_status', $failures);
badpool_expect_contains('maturity proof reason emitted', $command, 'maturity_proof_reason', $failures);
badpool_expect_contains('maturity proof unavailable blocker emitted', $command, 'conservative_maturity_proof_unavailable', $failures);
badpool_expect_contains('maturity missing mature blocks excluded', $command, 'missing_mature_blocks', $failures);
badpool_expect_contains('maturity below mature blocks excluded', $command, 'confirmations_below_mature_blocks', $failures);
badpool_expect_contains('bounded maturity selector explicitly admits generate blocks', $command, "B.category IN ('immature','generate')", $failures);
badpool_expect_contains('coin-wide maturity selector remains immature-only', $command, "B.category='immature'", $failures);
badpool_expect_contains('maturity apply mutates only immature blocks', $command, "WHERE id=:id AND coin_id=:coin_id AND height=:height AND category='immature'", $failures);
badpool_expect_contains('maturity apply mutates only selected earnings by identity scope', $command, "WHERE id=:id AND userid=:uid AND coinid=:cid AND blockid=:bid AND status=0", $failures);
badpool_expect_not_contains('maturity apply must not require exact amount equality', $command, "WHERE id=:id AND userid=:uid AND coinid=:cid AND blockid=:bid AND amount".'=:amt'." AND status=0", $failures);
badpool_expect_contains('maturity confirmation required', $command, 'operator-confirms-maturity-transition', $failures);
badpool_expect_contains('maturity checksum required', $command, 'projected-block-mutation-checksum', $failures);
badpool_expect_not_contains('maturity block aggregate must not reset per selected earning', $command, '$items[] = $item; $blocks[$blockId] = array(', $failures);
badpool_expect_not_contains('maturity block aggregate must not stage linked earning IDs separately', $command, '$blockEarningIds', $failures);
badpool_expect_contains('maturity block aggregate initializes each block once', $command, 'if (!isset($blocks[$blockId])) $blocks[$blockId] = array(', $failures);
badpool_expect_contains('maturity block aggregate appends every selected earning ID', $command, '$blocks[$blockId][\'linked_earning_ids\'][] = intval($r[\'earning_id\']);', $failures);
badpool_expect_contains('maturity block aggregate uses canonical decimal accumulation', $command, '$blocks[$blockId][\'total_amount\'] = BadpoolStage1Manifest::addAmounts($blocks[$blockId][\'total_amount\'], $r[\'amount\']);', $failures);
badpool_expect_contains('maturity user aggregate uses canonical decimal accumulation', $command, '$totalsByUser[$u][\'amount_total\']=BadpoolStage1Manifest::addAmounts($totalsByUser[$u][\'amount_total\'],$r[\'amount\']);', $failures);

badpool_expect_contains('account credit dryrun selects clearable status1 rows', $command, 'E.status=1 AND E.mature_time<:delay AND E.coinid=:coin_id', $failures);
badpool_expect_contains('account credit exact scope narrows delayed eligibility', $command, '$where.=\' AND E.id IN (', $failures);
badpool_expect_contains('account credit uses BackendClearEarnings conversion helper', $command, 'yaamp_convert_amount_user($coin,$r[\'amount\'],$user)', $failures);
badpool_expect_contains('account credit apply guards selected earnings by identity scope', $command, 'WHERE id=:id AND userid=:uid AND coinid=:cid AND blockid=:bid AND status=1 AND mature_time=:mt', $failures);
badpool_expect_not_contains('account credit apply must not require exact amount equality', $command, 'WHERE id=:id AND userid=:uid AND coinid=:cid AND blockid=:bid AND amount'.'=:amt'.' AND status=1 AND mature_time=:mt', $failures);
badpool_expect_contains('account credit apply guards selected accounts', $command, 'WHERE id=:id AND coinid=:coinid', $failures);
badpool_expect_contains('account credit confirmation required', $command, 'operator-confirms-account-credit', $failures);
badpool_expect_contains('account credit checksum required', $command, 'projected-account-credit-checksum', $failures);

badpool_expect_contains('apply scope uses selected earning ids', $command, 'selected-earning-ids', $failures);
badpool_expect_contains('apply scope exact row IDs required', $command, 'selected_row_ids_required', $failures);
badpool_expect_contains('apply scope exact row IDs message', $command, 'Apply scope must be exact selected row IDs.', $failures);
badpool_expect_contains('approval filtered to selected row IDs', $command, 'filterRowsByIds', $failures);
badpool_expect_contains('unselected drift excluded from authorization', $command, 'unselected drift is informational only and not part of authorization', $failures);
badpool_expect_contains('unselected candidate delta reported', $command, 'unselected_candidate_count_delta', $failures);
badpool_expect_contains('apply parser refuses unknown options', $command, 'Unknown option refused: --', $failures);
badpool_expect_contains('apply requires json format', $command, 'json_format_required', $failures);
badpool_expect_contains('apply requires checksums', $command, 'missing_required_checksum', $failures);
badpool_expect_contains('apply detects checksum mismatch', $command, 'checksum_mismatch', $failures);
badpool_expect_contains('apply transaction wrapped', $command, 'app()->db->beginTransaction()', $failures);
badpool_expect_contains('apply rolls back on mutation failure', $command, '$tx->rollback()', $failures);
badpool_expect_contains('apply refuses all-coin scope', $command, 'refuses all-coin scope', $failures);
badpool_expect_contains('maturity apply no account credit', $command, "'no_account_credit'=>true", $failures);
badpool_expect_contains('apply reports no wallet sends', $command, "'wallet_sends'=>false", $failures);
badpool_expect_contains('apply reports no backend loops', $command, "'backend_loops_run'=>false", $failures);
badpool_expect_contains('apply reports no share deletion', $command, "'shares_deleted'=>false", $failures);
badpool_expect_contains('apply reports informational unselected drift', $command, 'unselected drift is informational only and not part of authorization', $failures);

$applyParserStart = strpos($command, 'private function parseGuardedApplyOptions');
$applyParserEnd = strpos($command, 'private function guardedApplyBaseReport', $applyParserStart);
$applyParser = ($applyParserStart === false || $applyParserEnd === false) ? '' : substr($command, $applyParserStart, $applyParserEnd - $applyParserStart);
badpool_expect_not_contains('new apply parser must not accept limit', $applyParser, "'limit'", $failures);

if (!class_exists('CConsoleCommand')) { class CConsoleCommand {} }
if (!function_exists('arraySafeVal')) { function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;} }
if (!defined('YAAMP_ALLOW_EXCHANGE')) define('YAAMP_ALLOW_EXCHANGE', false);
if (!defined('YAAMP_PAYMENTS_FREQ')) define('YAAMP_PAYMENTS_FREQ', 3600);
if (!function_exists('getdbo')) { function getdbo($class,$id){return (object)array('id'=>$id);} }
if (!function_exists('yaamp_convert_amount_user')) { function yaamp_convert_amount_user($coin,$amount,$user){return floatval($amount);} }
require_once $commandPath;

class AccountCreditSelectionGuardFixture
{
	public $rows;
	public function __construct($rows){$this->rows=$rows;}
	public function isAllCoinsPreview(){return false;}
	public function isValid(){return true;}
	public function getScope(){return array('coin_id'=>1267);}
	public function baseReport(){return array('scope'=>$this->getScope(),'summary'=>array(),'items'=>array(),'warnings'=>array(),'errors'=>array());}
	public function finalizeReport($report){return $report;}
	public function selectAll($sql,$params=array())
	{
		$out=array();$selected=array();foreach($params as $key=>$value)if(strpos($key,':selected_id_')===0)$selected[]=intval($value);
		foreach($this->rows as $row){
			if(strpos($sql,'E.status=1')!==false&&intval($row['status'])!==1)continue;
			if(strpos($sql,'E.mature_time<:delay')!==false&&intval($row['mature_time'])>=intval($params[':delay']))continue;
			if(strpos($sql,'E.coinid=:coin_id')!==false&&intval($row['coinid'])!==intval($params[':coin_id']))continue;
			if(strpos($sql,'E.id IN (')!==false&&!in_array(intval($row['earning_id']),$selected,true))continue;
			$out[]=$row;
		}
		return $out;
	}
}

$threshold=time()-(YAAMP_PAYMENTS_FREQ/2);
$selectionRows=array(
	array('earning_id'=>1,'userid'=>9,'coinid'=>1267,'blockid'=>41,'amount'=>'1.0','status'=>1,'mature_time'=>$threshold-1,'coin_price'=>'1.0','account_id'=>9,'account_coinid'=>1267,'account_balance'=>'0'),
	array('earning_id'=>2,'userid'=>9,'coinid'=>1267,'blockid'=>42,'amount'=>'2.0','status'=>1,'mature_time'=>$threshold+1,'coin_price'=>'1.0','account_id'=>9,'account_coinid'=>1267,'account_balance'=>'0'),
	array('earning_id'=>3,'userid'=>9,'coinid'=>1268,'blockid'=>43,'amount'=>'3.0','status'=>1,'mature_time'=>$threshold-1,'coin_price'=>'1.0','account_id'=>9,'account_coinid'=>1268,'account_balance'=>'0'),
	array('earning_id'=>4,'userid'=>9,'coinid'=>1267,'blockid'=>44,'amount'=>'4.0','status'=>0,'mature_time'=>$threshold-1,'coin_price'=>'1.0','account_id'=>9,'account_coinid'=>1267,'account_balance'=>'0'),
	array('earning_id'=>5,'userid'=>9,'coinid'=>1267,'blockid'=>45,'amount'=>'5.0','status'=>1,'mature_time'=>$threshold-2,'coin_price'=>'1.0','account_id'=>9,'account_coinid'=>1267,'account_balance'=>'0'),
);
$selectionGuard=new AccountCreditSelectionGuardFixture($selectionRows);$selectionCommand=new BadpoolGuardCommand;
$guardProperty=new ReflectionProperty('BadpoolGuardCommand','guard');$guardProperty->setAccessible(true);$guardProperty->setValue($selectionCommand,$selectionGuard);
$dryrunMethod=new ReflectionMethod('BadpoolGuardCommand','accountCreditClearDryrunReport');$dryrunMethod->setAccessible(true);
$wide=$dryrunMethod->invoke($selectionCommand);$wideIds=array_column($wide['items']['selected_earnings'],'earning_id');
if($wideIds!==array(1,5))$failures[]='coin-wide selection did not enforce status, delay, and coin eligibility';
$exact=$dryrunMethod->invoke($selectionCommand,array(1,2,3,4,5));$exactIds=array_column($exact['items']['selected_earnings'],'earning_id');
if($exactIds!==array(1,5))$failures[]='selected IDs replaced rather than narrowed normal delay eligibility';
$eligible=$dryrunMethod->invoke($selectionCommand,array(1));if(array_column($eligible['items']['selected_earnings'],'earning_id')!==array(1))$failures[]='delay-eligible exact selected ID was not selected';
$immature=$dryrunMethod->invoke($selectionCommand,array(2));if(!empty($immature['items']['selected_earnings']))$failures[]='immature exact selected ID bypassed payment delay';

if (!empty($failures)) {
	echo "Badpool account-credit guard harness FAILED\n";
	foreach ($failures as $failure) {
		echo " - $failure\n";
	}
	exit(1);
}

echo "Badpool account-credit guard harness passed\n";
