<?php
class CConsoleCommand {}
function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}
if(!defined('YAAMP_ALLOW_EXCHANGE'))define('YAAMP_ALLOW_EXCHANGE',false);
if(!defined('YAAMP_PAYMENTS_FREQ'))define('YAAMP_PAYMENTS_FREQ',3600);
require_once dirname(__DIR__).'/web/yaamp/commands/BadpoolGuardCommand.php';
class ExactScopeGuard {
 public $errors=array(); public function isAllCoinsPreview(){return false;} public function isValid(){return !$this->errors;} public function addError($m){$this->errors[]=$m;} public function getScope(){return array('coin_id'=>1267,'all_coins_preview'=>false,'coin'=>array('id'=>1267,'symbol'=>'BAD','algo'=>'scrypt'));}
 public function baseReport($s='ok'){return array('schema'=>BadpoolGuardReport::PREVIEW_SCHEMA,'command'=>'earnings-maturity-transition-dryrun','mode'=>BadpoolGuardReport::PREVIEW_MODE,'status'=>$s,'scope'=>$this->getScope(),'summary'=>array(),'items'=>array(),'warnings'=>array(),'errors'=>$this->errors,'read_only'=>true,'db_mutations'=>false);}
 public function refusalReport(){return $this->baseReport('refused');} public function finalizeReport($r){$r['errors']=$this->errors;return BadpoolGuardReport::finalize($r);}
 public function selectAll($sql,$params=array()){
  if(strpos($sql,'SELECT id,coin_id FROM blocks')!==false){$out=array();foreach($params as $k=>$id)if(strpos($k,':requested_block_')===0)$out[]=array('id'=>$id,'coin_id'=>1267);return $out;}
  if(strpos($sql,'FROM earnings E INNER JOIN blocks B')!==false){$out=array();for($id=20381,$n=1;$id<=20430;$id++,$n++){if($id===20393||$id===20399)continue;$out[]=array('earning_id'=>30000+$n,'userid'=>7,'coinid'=>1267,'blockid'=>$id,'amount'=>$id===20430?'103848.497537160000':'1.000000000000','status'=>0,'mature_time'=>0,'block_id'=>$id,'block_height'=>500000+$n,'block_coin_id'=>1267,'block_category'=>'immature','confirmations'=>120,'mature_blocks'=>100);}return $out;} return array();
 }
}
function pm($n){$m=new ReflectionMethod('BadpoolGuardCommand',$n);$m->setAccessible(true);return $m;}
function commandWith(&$guard){$guard=new ExactScopeGuard;$c=new BadpoolGuardCommand;$p=new ReflectionProperty('BadpoolGuardCommand','guard');$p->setAccessible(true);$p->setValue($c,$guard);return $c;}
$f=array();function ok($v,$m){global $f;if(!$v)$f[]=$m;}
$c=commandWith($g);$csv=implode(',',range(20430,20381));$r=pm('earningsMaturityTransitionDryrunReport')->invoke($c,array('--selected-block-ids='.$csv));
ok($r['selection_mode']==='exact-blocks'&&$r['requested_block_count']===50,'50-block bounded selection missing');ok($r['selected_earning_count']===48&&$r['selected_linked_block_count']===48,'48 earning/link count mismatch');ok($r['requested_blocks_without_selected_earnings']===array(20393,20399),'empty blocks not explicit');ok($r['summary']['total_amount']==='103895.497537160000','exact total mismatch');ok($r['requested_block_ids']===range(20381,20430),'input was not canonicalized');
$c2=commandWith($g2);$r2=pm('earningsMaturityTransitionDryrunReport')->invoke($c2,array('--selected-block-ids='.implode(',',range(20381,20430))));ok($r['scope_checksum']['value']===$r2['scope_checksum']['value']&&$r['selected_scope_checksum']['value']===$r2['selected_scope_checksum']['value'],'checksums unstable under reorder');
foreach(array('', '0','-1','1.2','1,1','01','1,,2') as $bad){$x=pm('parseMaturitySelection')->invoke($c,array('--selected-block-ids='.$bad));ok($x['status']==='fail','invalid scope accepted: '.$bad);}
$x=pm('parseMaturitySelection')->invoke($c,array());ok($x['mode']==='coin-wide','legacy unbounded mode unavailable');
$t=$r['scope_checksum']['value'];$x=pm('parseMaturitySelection')->invoke($c,array('--selected-block-ids=20381,20382'));$y=BadpoolGuardReport::checksum(array('coin_id'=>1267,'selection_mode'=>$x['mode'],'requested_block_ids'=>$x['requested_block_ids']));ok($t!==$y['value'],'changed requested block scope retained checksum');
$v2=BadpoolGuardReport::finalize(array('schema'=>BadpoolGuardReport::BOUNDED_MATURITY_APPROVAL_PACKAGE_SCHEMA,'command'=>'earnings-maturity-transition-approval-package','package_type'=>'earnings-maturity-transition','approval_package_type'=>'earnings-maturity-transition','approval_required'=>true));ok($v2['schema']===BadpoolGuardReport::BOUNDED_MATURITY_APPROVAL_PACKAGE_SCHEMA,'bounded v2 schema was downgraded');
if($f){echo "Badpool exact maturity scope harness FAILED\n";foreach($f as $m)echo " - $m\n";exit(1);}echo "Badpool exact maturity scope harness passed\n";
