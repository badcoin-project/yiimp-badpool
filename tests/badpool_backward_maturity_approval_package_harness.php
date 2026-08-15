<?php
require_once __DIR__.'/badpool_backward_maturity_dryrun_harness.php';
require_once dirname(__DIR__).'/web/yaamp/core/backend/BadpoolBackwardMaturityApprovalPackage.php';

$approvalFailures=array();
function approvalExpect($ok,$message){global $approvalFailures;if(!$ok)$approvalFailures[]=$message;}
function approvalRetained(){global $command;$r=backwardMethod('backwardMaturityTransitionDryrunReport')->invoke($command,backwardArgs());$r=backwardMethod('finalizeCommandReport')->invoke($command,$r);return $r;}
function approvalBuild($mutateRetained=null,$mutateFresh=null,$checksumOverride=null){
	$retained=approvalRetained();if($mutateRetained)$mutateRetained($retained);$fresh=approvalRetained();if($mutateFresh)$mutateFresh($fresh);
	$raw=json_encode($retained,JSON_UNESCAPED_SLASHES);$checksum=$checksumOverride===null?hash('sha256',$raw):$checksumOverride;
	return BadpoolBackwardMaturityApprovalPackage::generate('/tmp/fixture.json',$raw,$checksum,BadpoolBackwardMaturityDryrun::expectedEarningIds(),BadpoolBackwardMaturityDryrun::expectedBlockIds(),BadpoolBackwardMaturityDryrun::INVENTORY_CHECKSUM,$retained,$fresh,'2026-08-15T00:00:00Z');
}
function approvalHolds($mutateRetained=null,$mutateFresh=null,$checksumOverride=null){return approvalBuild($mutateRetained,$mutateFresh,$checksumOverride)['status']==='hold';}

$pass=approvalBuild();
approvalExpect($pass['status']==='pass','valid retained report did not generate PASS package');
approvalExpect($pass['schema']==='badpool.backward_maturity_approval_package.v1','approval schema differs');
approvalExpect($pass['prior_credit_basis']==='earnings.create_time <= accounts.last_earning','create_time prior-credit basis missing');
approvalExpect($pass['equality_checks']['prior_credit_basis_is_create_time_documented']===true,'create_time basis equality check failed');
approvalExpect($pass['read_only']&& !$pass['db_mutations']&&!$pass['wallet_reads']&&!$pass['wallet_sends'],'package safety flags differ');
approvalExpect(!isset($pass['apply_command'])&&!isset($pass['apply_command_args']),'package exposes apply behavior');
approvalExpect($pass['approval_package_checksum']['purpose']===BadpoolBackwardMaturityApprovalPackage::CHECKSUM_PURPOSE,'package checksum purpose is authorizing');
approvalExpect(approvalHolds(null,null,str_repeat('0',64)),'retained content checksum mismatch did not HOLD');
approvalExpect(approvalHolds(function(&$r){$r['schema']='wrong';}),'wrong retained schema did not HOLD');
approvalExpect(approvalHolds(function(&$r){$r['status']='hold';}),'non-ok retained status did not HOLD');
approvalExpect(approvalHolds(function(&$r){$r['summary']['validation_status']='hold';}),'non-pass retained validation did not HOLD');
approvalExpect(approvalHolds(function(&$r){$r['failed_assertions']=array('risk');}),'retained failed assertion did not HOLD');
approvalExpect(approvalHolds(function(&$r){$r['wallet_reads']=true;}),'unsafe retained flags did not HOLD');
approvalExpect(approvalHolds(function(&$r){array_pop($r['scope']['selected_earning_ids']);}),'retained earning scope drift did not HOLD');
approvalExpect(approvalHolds(function(&$r){array_pop($r['scope']['selected_block_ids']);}),'retained block scope drift did not HOLD');
approvalExpect(approvalHolds(function(&$r){$r['scope']['expected_inventory_checksum']=str_repeat('0',64);}),'inventory checksum drift did not HOLD');
approvalExpect(approvalHolds(function(&$r){$r['scope']['expected_inventory_checksum_purpose']='maturity authorization';}),'authorizing inventory purpose did not HOLD');
approvalExpect(approvalHolds(null,function(&$r){$r['summary']['amount']='0.000000000000';}),'fresh database drift did not HOLD');
approvalExpect(approvalHolds(null,function(&$r){$r['status']='hold';$r['summary']['validation_status']='hold';$r['failed_assertions']=array('no_prior_credit_risk_via_account_last_earning');}),'fresh prior-credit risk did not HOLD');
approvalExpect(approvalHolds(function(&$r){$r['validation_assertions']['no_prior_credit_risk_via_account_last_earning']['status']='hold';$r['prior_credit_basis']='blocks.time <= accounts.last_earning';}),'block-time prior-credit basis did not HOLD');

$parsed=BadpoolBackwardMaturityApprovalPackage::parseOptions(array_merge(array('--dryrun-report=/tmp/report.json','--dryrun-report-checksum='.str_repeat('a',64)),backwardArgs()));
approvalExpect($parsed['status']==='pass','required approval arguments did not parse');

$retained=approvalRetained();$raw=json_encode($retained,JSON_UNESCAPED_SLASHES);$tmp=tempnam(sys_get_temp_dir(),'badpool-approval-');file_put_contents($tmp,$raw);
$commandArgs=array('--coin-id=1267','--dryrun-report='.$tmp,'--dryrun-report-checksum='.hash('sha256',$raw),'--selected-earning-ids='.implode(',',BadpoolBackwardMaturityDryrun::expectedEarningIds()),'--selected-block-ids='.implode(',',BadpoolBackwardMaturityDryrun::expectedBlockIds()),'--expected-inventory-checksum='.BadpoolBackwardMaturityDryrun::INVENTORY_CHECKSUM,'--format=json');
$commandPackage=backwardMethod('backwardMaturityTransitionApprovalPackageReport')->invoke($command,$commandArgs);$commandPackage=backwardMethod('finalizeCommandReport')->invoke($command,$commandPackage);unlink($tmp);
approvalExpect($commandPackage['schema']===BadpoolBackwardMaturityApprovalPackage::SCHEMA&&$commandPackage['mode']===BadpoolBackwardMaturityApprovalPackage::MODE,'command finalization changed approval schema or mode');
approvalExpect($commandPackage['status']==='pass','command integration did not generate PASS package');

if($approvalFailures){echo "Badpool backward maturity approval package harness FAILED\n";foreach($approvalFailures as $f)echo " - $f\n";exit(1);}echo "Badpool backward maturity approval package harness passed\n";
