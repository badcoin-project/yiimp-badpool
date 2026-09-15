<?php
require_once dirname(__FILE__).'/../web/framework/yii.php';
Yii::import('system.console.CConsoleCommand');
function arraySafeVal($a,$k,$d=null){return isset($a[$k])?$a[$k]:$d;}
require_once dirname(__FILE__).'/../web/yaamp/commands/LiveBlockAccountingCommand.php';

$fail=array();
function ok($value,$message){global $fail;if(!$value)$fail[]=$message;}

class LiveAccountingOptionProbe extends CConsoleCommand
{
	public $called=false;
	public $values=array();
	public function actionIndex($coin,$algo,$after,$limit=2){$this->called=true;$this->values=func_get_args();return 0;}
	public function usageError($message){throw new InvalidArgumentException($message);}
}

$probe=new LiveAccountingOptionProbe('liveblockaccounting',null);
$exit=$probe->run(array('--coin=1267','--algo=scrypt','--after=30000','--limit=2'));
ok($exit===0&&$probe->called,'Yii invokes the default action with named options');
ok($probe->values===array('1267','scrypt','30000','2'),'Yii supports the documented --name=value option shape');
$missing=new LiveAccountingOptionProbe('liveblockaccounting',null);
try{$missing->run(array('--coin=1267','--algo=scrypt','--limit=2'));ok(false,'missing boundary refused');}
catch(InvalidArgumentException $e){ok(!$missing->called&&strpos($e->getMessage(),'--after')!==false,'missing boundary refused before action');}

$command=new LiveBlockAccountingCommand('liveblockaccounting',null);
foreach(array('',0,'0',-1,'-1','30000x',1.5,true) as $bad) {
	try{$command->actionIndex(1267,'scrypt',$bad,2);ok(false,'malformed boundary refused');}
	catch(InvalidArgumentException $e){ok(strpos($e->getMessage(),'--after')!==false,'malformed boundary reports after');}
}
$help=$command->getHelp();
ok(strpos($help,'--after=<block_id>')!==false&&strpos($help,'strictly greater')!==false,'help documents exclusive required boundary');
ok(LiveBlockAccountingCommand::resultExitCode(array('daemon_failed'=>0,'apply_failed'=>0))===0,'clean result returns success');
ok(LiveBlockAccountingCommand::resultExitCode(array('daemon_failed'=>1,'apply_failed'=>0))===1,'daemon failure returns nonzero');
ok(LiveBlockAccountingCommand::resultExitCode(array('daemon_failed'=>0,'apply_failed'=>1))===1,'apply failure returns nonzero');

if($fail)throw new RuntimeException('FAIL: '.implode('; ',$fail));
echo "PASS live block accounting command harness\n";
