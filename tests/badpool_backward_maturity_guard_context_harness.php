<?php
require_once __DIR__.'/badpool_backward_maturity_apply_harness.php';

$guardFailures=array();
function guardExpect($ok,$message){global $guardFailures;if(!$ok)$guardFailures[]=$message;}

class BackwardGuardFakeSchema {
	public function getTable($table,$refresh=true){
		if($table!=='coins')return null;
		$result=new stdClass;$result->columns=array('id'=>true,'symbol'=>true,'symbol2'=>true,'name'=>true,'algo'=>true,'enable'=>true,'installed'=>true,'visible'=>true,'auto_ready'=>true,'rpcencoding'=>true,'txfee'=>true,'payout_min'=>true,'balance'=>true,'available'=>true,'cleared'=>true,'immature'=>true,'mature_blocks'=>true,'block_height'=>true,'target_height'=>true,'price'=>true,'price2'=>true);return $result;
	}
}
class BackwardGuardFakeCommand {
	public function bindValue($name,$value){}
	public function queryRow(){return array('id'=>1267,'symbol'=>'BAD','algo'=>'scrypt');}
}
class BackwardGuardFakeDb {
	public $schema;
	public function __construct(){$this->schema=new BackwardGuardFakeSchema;}
	public function createCommand($sql){return new BackwardGuardFakeCommand;}
}
class BackwardGuardFakeApp {
	public $db;
	public function __construct(){$this->db=new BackwardGuardFakeDb;}
}
$backwardGuardApp=new BackwardGuardFakeApp;
function app(){global $backwardGuardApp;return $backwardGuardApp;}

function guardFixtureArgs(){
	$sha=str_repeat('a',64);
	return array('--coin-id=1267','--approval-package=/tmp/package.json','--approval-package-checksum='.$sha,'--dryrun-report=/tmp/dryrun.json','--dryrun-report-checksum='.$sha,'--selected-earning-ids='.implode(',',BadpoolBackwardMaturityDryrun::expectedEarningIds()),'--selected-block-ids='.implode(',',BadpoolBackwardMaturityDryrun::expectedBlockIds()),'--expected-inventory-checksum='.BadpoolBackwardMaturityDryrun::INVENTORY_CHECKSUM,'--operator-confirms-backward-maturity-transition='.BadpoolBackwardMaturityApply::confirmationShape($sha),'--format=json');
}
function guardContextFor($args){return BadpoolGuardContext::fromBackwardMaturityApplyArgs(BadpoolBackwardMaturityApply::COMMAND,$args);}
function guardWithout($args,$prefix){$out=array();foreach($args as $arg)if(strpos($arg,$prefix)!==0)$out[]=$arg;return $out;}

$args=guardFixtureArgs();$context=guardContextFor($args);
guardExpect($context->isValid(),'valid backward apply arguments failed the outer guard context');
guardExpect($context->getScope()['coin_id']===1267&&$context->getFormat()==='json','valid outer guard scope or format changed');
guardExpect(!guardContextFor(guardWithout($args,'--operator-confirms-backward-maturity-transition='))->isValid(),'missing operator confirmation passed');
$malformed=$args;$malformed[1]='--approval-package';guardExpect(!guardContextFor($malformed)->isValid(),'malformed approval package argument passed');
$wrongCoin=$args;$wrongCoin[0]='--coin-id=1268';guardExpect(!guardContextFor($wrongCoin)->isValid(),'wrong coin_id passed');

$legacy=BadpoolGuardContext::fromArgs('earnings-maturity-transition-apply',array('--coin-id=1267','--format=json'));
guardExpect($legacy->isValid(),'existing apply command guard behavior changed');
$legacyUnknown=BadpoolGuardContext::fromArgs('earnings-maturity-transition-apply',array('--coin-id=1267','--approval-package=/tmp/package.json','--format=json'));
guardExpect(!$legacyUnknown->isValid(),'existing apply command option allowlist was weakened');
$legacyReport=BadpoolGuardReport::finalize(array('schema'=>'custom','command'=>'earnings-maturity-transition-apply','mode'=>'custom','db_mutations'=>false));
guardExpect($legacyReport['schema']===BadpoolGuardReport::APPLY_SCHEMA,'existing apply report normalization changed');

$command=new BadpoolGuardCommand;$runnerCalled=false;
$runner=new ReflectionProperty('BadpoolGuardCommand','backwardMaturityApplyRunner');$runner->setAccessible(true);
$runner->setValue($command,function($received)use(&$runnerCalled,$args){$runnerCalled=$received===$args;return BadpoolBackwardMaturityApply::run($received,new BackwardApplyFixtureStore(array()));});
ob_start();$rc=$command->run(array_merge(array(BadpoolBackwardMaturityApply::COMMAND),$args));$raw=ob_get_clean();$report=json_decode($raw,true);
guardExpect($rc===0&&$runnerCalled,'dispatcher did not reach the backward apply runner with complete arguments');
guardExpect(is_array($report)&&$report['schema']===BadpoolBackwardMaturityApply::SCHEMA,'fixture dispatcher route did not return backward apply schema');
guardExpect($report['schema']!==BadpoolGuardCommand::APPLY_SCHEMA,'valid fixture route returned the former generic guardrail apply schema');
guardExpect(strpos($raw,BadpoolBackwardMaturityApply::confirmationShape(str_repeat('a',64)))===false,'dispatcher report leaked raw operator confirmation');

if($guardFailures){echo "Badpool backward maturity guard context harness FAILED\n";foreach($guardFailures as $f)echo " - $f\n";exit(1);}echo "Badpool backward maturity guard context harness passed\n";
