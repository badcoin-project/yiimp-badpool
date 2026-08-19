<?php
require_once __DIR__.'/badpool_backward_maturity_approval_package_harness.php';
require_once dirname(__DIR__).'/web/yaamp/core/backend/BadpoolBackwardMaturityApply.php';

$applyFailures=array();
function applyExpect($ok,$message){global $applyFailures;if(!$ok)$applyFailures[]=$message;}
class BackwardApplyFixtureStore {
	public $freshReport,$earningUpdates=71,$blockUpdates=71,$postReport,$began=false,$committed=false,$rolledBack=false;
	function __construct($fresh){$this->freshReport=$fresh;$this->postReport=array('selected_earnings_status_1'=>71,'selected_blocks_generate'=>71,'other_earnings_changed'=>0,'other_blocks_changed'=>0,'forbidden_tables_changed'=>0);}
	function begin(){$this->began=true;} function lock($e,$b){if(count($e)!==71||count($b)!==71)throw new Exception('bad lock scope');} function fresh(){return $this->freshReport;}
	function updateEarnings($e,$b){return $this->earningUpdates;} function updateBlocks($e,$b){return $this->blockUpdates;} function post($e,$b){return $this->postReport;}
	function commit(){$this->committed=true;} function rollback(){$this->rolledBack=true;}
}
function applyFixture($mutatePackage=null,$mutateDry=null,$mutateFresh=null,$mutateStore=null,$confirmation=true,$fileChecksumOverride=null,$pr98Shape=false){
	$dry=approvalRetained();if($mutateDry)$mutateDry($dry);$dryPath=tempnam(sys_get_temp_dir(),'back-dry-');$dryRaw=json_encode($dry,JSON_UNESCAPED_SLASHES);file_put_contents($dryPath,$dryRaw);$drySha=hash('sha256',$dryRaw);
	$fresh=approvalRetained();if($mutateFresh)$mutateFresh($fresh);
	$package=BadpoolBackwardMaturityApprovalPackage::generate($dryPath,$dryRaw,$drySha,BadpoolBackwardMaturityDryrun::expectedEarningIds(),BadpoolBackwardMaturityDryrun::expectedBlockIds(),BadpoolBackwardMaturityDryrun::INVENTORY_CHECKSUM,$dry,$fresh,'2026-08-15T00:00:00Z');$reviewedInternal=$package['approval_package_checksum']['value'];if($pr98Shape)$package['report_checksum']=BadpoolGuardReport::checksum($package);if($mutatePackage)$mutatePackage($package);
	$packagePath=tempnam(sys_get_temp_dir(),'back-package-');$packageRaw=json_encode($package,JSON_UNESCAPED_SLASHES);file_put_contents($packagePath,$packageRaw);$packageSha=hash('sha256',$packageRaw);
	$suppliedSha=$fileChecksumOverride===null?$packageSha:$fileChecksumOverride;$confirmationText=is_callable($confirmation)?call_user_func($confirmation,$suppliedSha,$reviewedInternal):($confirmation?BadpoolBackwardMaturityApply::confirmationShape($suppliedSha):'wrong');
	$args=array('--coin-id=1267','--approval-package='.$packagePath,'--approval-package-checksum='.$suppliedSha,'--dryrun-report='.$dryPath,'--dryrun-report-checksum='.$drySha,'--selected-earning-ids='.implode(',',BadpoolBackwardMaturityDryrun::expectedEarningIds()),'--selected-block-ids='.implode(',',BadpoolBackwardMaturityDryrun::expectedBlockIds()),'--expected-inventory-checksum='.BadpoolBackwardMaturityDryrun::INVENTORY_CHECKSUM,'--operator-confirms-backward-maturity-transition='.$confirmationText,'--format=json');
	$store=new BackwardApplyFixtureStore($fresh);if($mutateStore)$mutateStore($store);$report=BadpoolBackwardMaturityApply::run($args,$store,array('approval_package_internal_checksum'=>$reviewedInternal));unlink($dryPath);unlink($packagePath);return array($report,$store,$args,$packageSha,$reviewedInternal,$confirmationText);
}
function applyStatus($status,$mp=null,$md=null,$mf=null,$ms=null,$confirmation=true){$x=applyFixture($mp,$md,$mf,$ms,$confirmation);applyExpect($x[0]['status']===$status,'expected '.$status.', got '.$x[0]['status'].': '.implode(';',$x[0]['errors']));return $x;}

$pass=applyStatus('committed');applyExpect($pass[0]['mutation_result']===array('earnings_status_updates'=>71,'block_category_updates'=>71),'committed update counts differ');applyExpect($pass[1]->committed&&!$pass[1]->rolledBack,'fixture transaction did not commit');
applyExpect($pass[3]!==$pass[4],'fixture file-byte and internal checksums unexpectedly match');
applyExpect($pass[0]['package_bindings']['approval_package_file_sha256']===$pass[3]&&$pass[0]['package_bindings']['approval_package_internal_checksum']===$pass[4],'report does not expose distinct checksum bindings');
$pr98=applyFixture(null,null,null,null,true,null,true);applyExpect($pr98[0]['status']==='committed','PR98-shaped finalized package did not pass strict recomputation');applyExpect($pr98[0]['package_bindings']['approval_package_internal_checksum']===$pr98[4]&&$pr98[0]['package_bindings']['approval_package_internal_checksum']!==null,'PR98-shaped package internal checksum was not exposed');applyExpect(!isset($pr98[0]['pre_apply_validation']['retained_package_checks']['internal_checksum_valid'])&&$pr98[0]['pre_apply_validation']['retained_package_checks']['internal_checksum_recompute_required']===true&&$pr98[0]['pre_apply_validation']['retained_package_checks']['internal_checksum_recompute_matches']===true,'non-retained package checksum semantics were misleading');
$extractor=new ReflectionMethod('BadpoolBackwardMaturityApply','approvalPackageInternalChecksum');$extractor->setAccessible(true);$reviewedObject=array('approval_package_checksum'=>array('algorithm'=>'sha256','value'=>BadpoolBackwardMaturityApply::APPROVAL_PACKAGE_INTERNAL_CHECKSUM,'excludes'=>array('generated_at','approval_package_checksum'),'purpose'=>BadpoolBackwardMaturityApprovalPackage::CHECKSUM_PURPOSE));applyExpect($extractor->invoke(null,$reviewedObject)===BadpoolBackwardMaturityApply::APPROVAL_PACKAGE_INTERNAL_CHECKSUM,'reviewed PR98 internal checksum was not extracted exactly');
$checksMethod=new ReflectionMethod('BadpoolBackwardMaturityApply','packageChecks');$checksMethod->setAccessible(true);
function retainedPr98Checks($mutate=null,$fileSha=null){global $checksMethod;$dry=approvalRetained();$raw=json_encode($dry,JSON_UNESCAPED_SLASHES);$path='/retained/pr98/backward-maturity-approval-package.json';$package=BadpoolBackwardMaturityApprovalPackage::generate($path,$raw,hash('sha256',$raw),BadpoolBackwardMaturityDryrun::expectedEarningIds(),BadpoolBackwardMaturityDryrun::expectedBlockIds(),BadpoolBackwardMaturityDryrun::INVENTORY_CHECKSUM,$dry,$dry,'2026-08-15T00:00:00Z');$package['report_checksum']=BadpoolGuardReport::checksum($package);$package['approval_package_checksum']['value']=BadpoolBackwardMaturityApply::APPROVAL_PACKAGE_INTERNAL_CHECKSUM;if($mutate)$mutate($package);$o=array('approval-package-checksum'=>$fileSha===null?BadpoolBackwardMaturityApply::APPROVAL_PACKAGE_FILE_SHA256:$fileSha,'expected-inventory-checksum'=>BadpoolBackwardMaturityDryrun::INVENTORY_CHECKSUM,'dryrun-report'=>$path,'dryrun-report-checksum'=>hash('sha256',$raw));$extractor=new ReflectionMethod('BadpoolBackwardMaturityApply','approvalPackageInternalChecksum');$extractor->setAccessible(true);$internal=$extractor->invoke(null,$package);return $checksMethod->invoke(null,$package,$dry,$o,BadpoolBackwardMaturityDryrun::expectedEarningIds(),BadpoolBackwardMaturityDryrun::expectedBlockIds(),BadpoolBackwardMaturityApply::APPROVAL_PACKAGE_INTERNAL_CHECKSUM,$internal);}
$historical=retainedPr98Checks();applyExpect($historical['internal_checksum_present']&&$historical['internal_checksum_shape_valid']&&$historical['internal_checksum_matches_reviewed']&&$historical['internal_checksum_recompute_required']===false&&$historical['internal_checksum_recompute_matches']===true&&$historical['internal_checksum_historical_review_binding']===true,'exact retained PR98 historical checksum binding did not pass');applyExpect(!isset($historical['internal_checksum_valid']),'historical package emitted misleading internal_checksum_valid');
applyExpect(retainedPr98Checks(null,str_repeat('0',64))['internal_checksum_historical_review_binding']===false,'wrong approval package file SHA accepted historical binding');
foreach(array('wrong_value'=>function(&$p){$p['approval_package_checksum']['value']=str_repeat('0',64);},'malformed'=>function(&$p){$p['approval_package_checksum']='malformed';},'wrong_key'=>function(&$p){$p['wrong_checksum_key']=$p['approval_package_checksum'];unset($p['approval_package_checksum']);},'wrong_purpose'=>function(&$p){$p['approval_package_checksum']['purpose']='apply authorization';},'wrong_excludes'=>function(&$p){$p['approval_package_checksum']['excludes']=array('generated_at');}) as $name=>$mutator){$checks=retainedPr98Checks($mutator);applyExpect($checks['internal_checksum_historical_review_binding']===false,'retained historical binding accepted '.$name);}
applyStatus('hold',function(&$p){$p['schema']='wrong';});
applyStatus('hold',function(&$p){$p['package_type']='wrong';}); applyStatus('hold',function(&$p){$p['status']='hold';}); applyStatus('hold',function(&$p){$p['mode']='wrong';});
applyStatus('hold',function(&$p){$p['failed_equality_checks']=array('drift');});
applyStatus('hold',function(&$p){array_pop($p['scope']['selected_earning_ids']);}); applyStatus('hold',function(&$p){array_pop($p['scope']['selected_block_ids']);});
applyStatus('hold',function(&$p){$p['scope']['expected_inventory_checksum']=str_repeat('0',64);});
applyStatus('hold',function(&$p){$p['retained_dryrun']['supplied_checksum']=str_repeat('0',64);});
applyStatus('hold',function(&$p){$p['approval_package_checksum']['value']=str_repeat('0',64);});
applyStatus('hold',function(&$p){$p['approval_package_checksum']='malformed';});
applyStatus('hold',function(&$p){$p['wrong_checksum_key']=$p['approval_package_checksum'];unset($p['approval_package_checksum']);});
applyStatus('hold',function(&$p){$p['scope']['symbol']='ALTERED';});
applyStatus('hold',function(&$p){$p['review_note']='different valid package';$copy=$p;unset($copy['generated_at'],$copy['approval_package_checksum']);$p['approval_package_checksum']['value']=BadpoolGuardReport::checksum($copy)['value'];});
applyStatus('hold',function(&$p){$p['approval_package_checksum']['purpose']='mutation authorization';});
applyStatus('hold',function(&$p){$p['apply_command']='unsafe';}); applyStatus('hold',function(&$p){$p['blocked_actions']['wallet_send']=false;});
applyStatus('hold',null,null,function(&$f){$f['summary']['amount']='0.000000000000';});
applyStatus('hold',null,null,function(&$f){$f['status']='hold';$f['failed_assertions']=array('all_earnings_status_0');});
applyStatus('hold',null,null,function(&$f){$f['status']='hold';$f['failed_assertions']=array('all_blocks_immature');});
applyStatus('hold',null,null,function(&$f){$f['status']='hold';$f['failed_assertions']=array('no_prior_credit_risk_via_account_last_earning');});
applyStatus('hold',null,null,function(&$f){$f['prior_credit_basis']='blocks.time <= accounts.last_earning';});
applyStatus('hold',null,null,null,null,false);
applyStatus('hold',null,null,null,null,function($fileSha,$internal){return str_replace($fileSha,$internal,BadpoolBackwardMaturityApply::confirmationShape($fileSha));});
applyStatus('hold',null,null,null,null,function($fileSha,$internal){return str_replace(' approval_package_internal_checksum '.BadpoolBackwardMaturityApply::APPROVAL_PACKAGE_INTERNAL_CHECKSUM,'',BadpoolBackwardMaturityApply::confirmationShape($fileSha));});
applyStatus('rollback',null,null,null,function($s){$s->earningUpdates=70;}); applyStatus('rollback',null,null,null,function($s){$s->blockUpdates=70;});
applyStatus('rollback',null,null,null,function($s){$s->postReport['forbidden_tables_changed']=1;});
$safe=$pass[0];applyExpect(strpos(json_encode($safe),$pass[5])===false,'report leaked raw operator confirmation');
foreach(array('account_credit_apply','payout_row_creation','wallet_send','service_actions','backend_loops_run','shares_deleted') as $k)applyExpect($safe['safety'][$k]===false,'unsafe report flag: '.$k);

// Exact-byte package checksum mismatch is exercised without reaching a store transaction.
$badFile=applyFixture(null,null,null,null,true,str_repeat('0',64));applyExpect($badFile[0]['status']==='hold'&&!$badFile[1]->began,'approval file checksum mismatch did not HOLD before transaction');

if($applyFailures){echo "Badpool backward maturity apply harness FAILED\n";foreach($applyFailures as $f)echo " - $f\n";exit(1);}echo "Badpool backward maturity apply harness passed\n";
