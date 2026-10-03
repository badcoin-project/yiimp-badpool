<?php
require_once(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolLivePaymentCoordinator.php');

$failures=array();$checks=0;
function queue_ok($value,$message){global$failures,$checks;$checks++;if(!$value)$failures[]=$message;}
function queue_root($label){$root=sys_get_temp_dir().'/badpool-multi-batch-'.$label.'-'.bin2hex(random_bytes(5));mkdir($root,0770,true);return$root;}
function queue_remove($path){if(!file_exists($path))return;if(is_dir($path)&&!is_link($path)){foreach(scandir($path)as$item)if($item!=='.'&&$item!=='..')queue_remove($path.'/'.$item);rmdir($path);}else unlink($path);}
function queue_ledger($root,$id,$lane,$state,$earnings,$blocks,$payouts=array(),$changes=array()){
	$dir=$root.'/'.$id;if(!is_dir($dir))mkdir($dir,0770,true);
	$ledger=array('batch_id'=>$id,'mode'=>'auto','scope'=>'all-active-payout-coins','only'=>$lane->operationalAlgo(),'batch_size'=>$lane->batchLimit(),'stop_before_wallet_send'=>true,'coordinator_owner'=>$lane->ownershipEnvelope(),'selected_coin_scope'=>array(array('id'=>$lane->coinId(),'algo'=>$lane->dbAlgo())),'selected_earning_ids'=>$earnings,'selected_block_ids'=>$blocks,'selected_account_ids'=>$earnings?array(79):array(),'created_payout_ids'=>$payouts,'payment_delay_qualified_earning_ids'=>array(),'current_phase'=>$state==='WAITING_PAYMENT_DELAY'?4:6,'batch_state'=>$state);
	$ledger=array_merge($ledger,$changes);file_put_contents($dir.'/ledger.json',json_encode($ledger));return$ledger;
}
function queue_v1_state($root,$lane,$id,$terminal=null){file_put_contents($lane->statePath($root),json_encode(array('schema'=>$lane->get('ownership_schema'),'version'=>1,'lane'=>$lane->laneId(),'coin_id'=>$lane->coinId(),'algo'=>$lane->dbAlgo(),'block_id_gt'=>$lane->blockBoundary(),'active_batch_id'=>$id,'last_terminal_reconciled_batch_id'=>$terminal)));}

class MultiBatchRunnerFixture
{
	public$root,$lane,$calls=array(),$candidates=array(),$advance=array(),$next=0;
	private$ids=array('20261003T100000Z-100000000000','20261003T100500Z-200000000000','20261003T101000Z-300000000000','20261003T101500Z-400000000000');
	function __construct($root,$lane,$candidates=array()){$this->root=$root;$this->lane=$lane;$this->candidates=$candidates;}
	function run($options){
		$this->calls[]=$options;
		if(isset($options['resume_batch_id'])){$id=$options['resume_batch_id'];$path=$this->root.'/'.$id.'/ledger.json';$ledger=json_decode(file_get_contents($path),true);if(isset($this->advance[$id])){$ledger['batch_state']='READY_FOR_WALLET_APPROVAL';$ledger['current_phase']=6;$ledger['payment_delay_qualified_earning_ids']=$ledger['selected_earning_ids'];$ledger['created_payout_ids']=array($this->advance[$id]);file_put_contents($path,json_encode($ledger));}return array('batch_id'=>$id);}
		$excludedE=array_flip(isset($options['excluded_earning_ids'])?$options['excluded_earning_ids']:array());$excludedB=array_flip(isset($options['excluded_block_ids'])?$options['excluded_block_ids']:array());$chosen=null;
		foreach($this->candidates as$row)if(!isset($excludedE[$row[0]])&&!isset($excludedB[$row[1]])){$chosen=$row;break;}
		$id=$this->ids[$this->next++];$earnings=$chosen?array($chosen[0]):array();$blocks=$chosen?array($chosen[1]):array();queue_ledger($this->root,$id,$this->lane,'WAITING_PAYMENT_DELAY',$earnings,$blocks);return array('batch_id'=>$id);
	}
}

$registry=new BadpoolLivePaymentLaneRegistry();$lane=$registry->get('live-scrypt-v1');$groestl=$registry->get('live-groestl-v1');$roots=array();

$root=$roots[]=queue_root('build');$runner=new MultiBatchRunnerFixture($root,$lane,array(array(1001,29243),array(1002,29244)));$first=(new BadpoolLivePaymentCoordinator($runner,$root,$lane))->run();
queue_ok($first['active_batch_id']===null&&$first['fresh_batch_created']===true&&$first['existing_batch_resumed']===false&&$first['waiting_payment_delay_count']===1,'first invocation did not park one fresh waiting batch and release the active slot');
$firstId=$first['waiting_payment_delay_batch_ids'][0];$second=(new BadpoolLivePaymentCoordinator($runner,$root,$lane))->run();
queue_ok($second['waiting_payment_delay_count']===2&&$second['existing_batch_resumed']===true&&$second['fresh_batch_created']===true,'a waiting batch prevented a second bounded batch from being created');
queue_ok($runner->calls[1]['resume_batch_id']===$firstId&&$runner->calls[2]['excluded_earning_ids']===array(1001)&&$runner->calls[2]['excluded_block_ids']===array(29243),'oldest resume or durable fresh-batch exclusions were not deterministic');
$state=json_decode(file_get_contents($lane->statePath($root)),true);queue_ok($state['version']===2&&$state['active_batch_id']===null&&$state['waiting_payment_delay_batch_ids']===$second['waiting_payment_delay_batch_ids'],'reconstructable queue state was not persisted with a free active slot');

$root=$roots[]=queue_root('oldest');$old='20261003T010000Z-111111111111';$new='20261003T020000Z-222222222222';queue_ledger($root,$new,$lane,'WAITING_PAYMENT_DELAY',array(2002),array(29246));queue_ledger($root,$old,$lane,'WAITING_PAYMENT_DELAY',array(2001),array(29245));$runner=new MultiBatchRunnerFixture($root,$lane,array());$report=(new BadpoolLivePaymentCoordinator($runner,$root,$lane))->run();
queue_ok($runner->calls[0]['resume_batch_id']===$old&&$report['waiting_payment_delay_batch_ids']===array($old,$new),'multiple waiting batches were not ordered and resumed oldest-first');
queue_ok(json_decode(file_get_contents($root.'/'.$new.'/ledger.json'),true)['selected_earning_ids']===array(2002),'still-ineligible waiting batch lost its durable scope');

$root=$roots[]=queue_root('advance');$old='20261003T030000Z-333333333333';queue_ledger($root,$old,$lane,'WAITING_PAYMENT_DELAY',array(3001),array(29247));$runner=new MultiBatchRunnerFixture($root,$lane,array(array(3002,29248)));$runner->advance[$old]=9301;$report=(new BadpoolLivePaymentCoordinator($runner,$root,$lane))->run();
queue_ok($report['ready_for_wallet_approval_batch_ids']===array($old)&&$report['ready_for_wallet_approval_payout_ids_by_batch'][$old]===array(9301)&&$report['waiting_payment_delay_count']===1,'expired payment delay did not advance independently while fresh work formed');

$root=$roots[]=queue_root('ready');$a='20261003T040000Z-444444444444';$b='20261003T050000Z-555555555555';queue_ledger($root,$a,$lane,'READY_FOR_WALLET_APPROVAL',array(4001),array(29249),array(9401));queue_ledger($root,$b,$lane,'READY_FOR_WALLET_APPROVAL',array(4002),array(29250),array(9402));$runner=new MultiBatchRunnerFixture($root,$lane,array());$report=(new BadpoolLivePaymentCoordinator($runner,$root,$lane))->run();
queue_ok($report['ready_for_wallet_approval_count']===2&&$report['ready_for_wallet_approval_batch_ids']===array($a,$b),'two READY batches were not retained as independent queue members');
queue_ok($report['ready_for_wallet_approval_payout_ids_by_batch']===array($a=>array(9401),$b=>array(9402))&&$report['human_action_required']===true,'READY batches lost distinct payout IDs or human approval reporting');

$root=$roots[]=queue_root('compat');$ready='20261003T060000Z-666666666666';queue_ledger($root,$ready,$lane,'READY_FOR_WALLET_APPROVAL',array(5001),array(29251),array(9501));queue_v1_state($root,$lane,$ready,'20261002T000000Z-aaaaaaaaaaaa');$hash=hash_file('sha256',$root.'/'.$ready.'/ledger.json');$runner=new MultiBatchRunnerFixture($root,$lane,array());$report=(new BadpoolLivePaymentCoordinator($runner,$root,$lane))->run();$state=json_decode(file_get_contents($lane->statePath($root)),true);
queue_ok($report['ready_for_wallet_approval_batch_ids']===array($ready)&&$state['version']===2&&$state['active_batch_id']===null&&$state['last_terminal_reconciled_batch_id']==='20261002T000000Z-aaaaaaaaaaaa','single-active v1 state did not reconstruct safely into the queue index');
queue_ok(hash_file('sha256',$root.'/'.$ready.'/ledger.json')===$hash,'compatibility reconstruction mutated the immutable READY ledger');

$root=$roots[]=queue_root('reconcile');$hold='20261003T070000Z-777777777777';queue_ledger($root,$hold,$lane,'HOLD_COMPLETED_PAYOUT_RECONCILIATION',array(6001),array(29252),array(9601));$runner=new MultiBatchRunnerFixture($root,$lane,array(array(6002,29253)));$report=(new BadpoolLivePaymentCoordinator($runner,$root,$lane))->run();
queue_ok($report['reconciliation_required_batch_ids']===array($hold)&&$report['waiting_payment_delay_count']===1&&$report['next_safe_action']==='read_only_wallet_proof_closeout','completed-payout reconciliation did not remain independently addressable while new work continued');

$root=$roots[]=queue_root('terminal');$terminal='20261003T080000Z-888888888888';queue_ledger($root,$terminal,$lane,'RECONCILED',array(7001),array(29254),array(9701));$runner=new MultiBatchRunnerFixture($root,$lane,array(array(7001,29254)));$report=(new BadpoolLivePaymentCoordinator($runner,$root,$lane))->run();
queue_ok($report['last_terminal_reconciled_batch_id']===$terminal&&$report['waiting_payment_delay_count']===1&&!in_array($terminal,$report['waiting_payment_delay_batch_ids'],true),'RECONCILED batch remained unresolved or was not recorded as the last terminal batch');

$root=$roots[]=queue_root('lane');$foreign='20261003T090000Z-999999999999';queue_ledger($root,$foreign,$groestl,'READY_FOR_WALLET_APPROVAL',array(8001),array(31213),array(9801));$runner=new MultiBatchRunnerFixture($root,$lane,array(array(8001,29255)));$report=(new BadpoolLivePaymentCoordinator($runner,$root,$lane))->run();
queue_ok($report['ready_for_wallet_approval_count']===0&&$report['waiting_payment_delay_count']===1,'cross-lane ledger contaminated Scrypt queue or exclusions');

foreach(array('HOLD','FAIL','REFUSED','MYSTERY')as$stateName){$root=$roots[]=queue_root(strtolower($stateName));$id='20261003T110000Z-abababababab';queue_ledger($root,$id,$lane,$stateName,array(9001),array(29256));$runner=new MultiBatchRunnerFixture($root,$lane,array(array(9002,29257)));$report=(new BadpoolLivePaymentCoordinator($runner,$root,$lane))->run();queue_ok($report['classification']==='FAIL_CLOSED'&&count($runner->calls)===0,$stateName.' batch was silently bypassed');}

$root=$roots[]=queue_root('malformed');$id='20261003T120000Z-bcbcbcbcbcbc';queue_ledger($root,$id,$lane,'WAITING_PAYMENT_DELAY',array(9101),array(29258),array(),array('batch_size'=>26));$runner=new MultiBatchRunnerFixture($root,$lane,array(array(9102,29259)));$report=(new BadpoolLivePaymentCoordinator($runner,$root,$lane))->run();queue_ok($report['classification']==='FAIL_CLOSED'&&count($runner->calls)===0,'malformed owned ledger did not fail closed');

foreach(array('earning'=>array(array(9201),array(29260),array(9201),array(29261)),'block'=>array(array(9202),array(29262),array(9203),array(29262)))as$kind=>$scope){$root=$roots[]=queue_root('duplicate-'.$kind);queue_ledger($root,'20261003T130000Z-cdcdcdcdcdcd',$lane,'WAITING_PAYMENT_DELAY',$scope[0],$scope[1]);queue_ledger($root,'20261003T140000Z-dededededede',$lane,'READY_FOR_WALLET_APPROVAL',$scope[2],$scope[3],array(9901));$runner=new MultiBatchRunnerFixture($root,$lane,array());$report=(new BadpoolLivePaymentCoordinator($runner,$root,$lane))->run();queue_ok($report['classification']==='FAIL_CLOSED'&&count($runner->calls)===0,'duplicate '.$kind.' ownership across unresolved ledgers did not fail closed');}

$source=file_get_contents(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolLivePaymentCoordinator.php');queue_ok(strpos($source,'sendmany')===false&&strpos($source,'sendtoaddress')===false&&strpos($source,'wallet-send-apply')===false,'coordinator source gained a wallet-send path');
queue_ok($report['wallet_boundary']==='blocked_human_required'&&$report['wallet_rpc_used']===false&&$report['wallet_send_performed']===false&&$report['db_mutations_by_coordinator']===false,'wallet boundary or direct-mutation attribution changed');

foreach($roots as$root)queue_remove($root);
if($failures){echo"FAIL live payment multi-batch queue harness ($checks checks)\n - ".implode("\n - ",$failures)."\n";exit(1);}echo"PASS live payment multi-batch queue harness ($checks checks)\n";
