<?php

require_once(dirname(__FILE__).'/BadpoolBackwardMaturityDryrun.php');
require_once(dirname(__FILE__).'/BadpoolGuardReport.php');

/** Read-only review binding for the bounded backward Scrypt maturity cohort. */
class BadpoolBackwardMaturityApprovalPackage
{
	const SCHEMA = 'badpool.backward_maturity_approval_package.v1';
	const COMMAND = 'backward-maturity-transition-approval-package';
	const PACKAGE_TYPE = 'backward-scrypt-maturity-transition';
	const MODE = 'approval-package-preview';
	const CHECKSUM_PURPOSE = 'approval-package review binding only; not apply authorization; not payout authorization; not wallet authorization';
	const PRIOR_CREDIT_BASIS = 'earnings.create_time <= accounts.last_earning';

	public static function parseOptions($args)
	{
		$required=array('coin-id','dryrun-report','dryrun-report-checksum','selected-earning-ids','selected-block-ids','expected-inventory-checksum','format');
		$options=array();
		foreach($args as $arg){
			if(!preg_match('/^--([^=]+)=(.*)$/',$arg,$m))return self::failure('Every option requires an explicit value.');
			$name=strtolower($m[1]);
			if(!in_array($name,$required,true))return self::failure('Unknown option refused: --'.$m[1].'.');
			if(isset($options[$name]))return self::failure('Duplicate option refused: --'.$m[1].'.');
			$options[$name]=$m[2];
		}
		foreach($required as $name)if(!isset($options[$name])||$options[$name]==='')return self::failure('Missing required --'.$name.'.');
		if(!preg_match('/^[a-f0-9]{64}$/',$options['dryrun-report-checksum']))return self::failure('--dryrun-report-checksum must be a lowercase SHA-256 value.');
		$dryArgs=array('--coin-id='.$options['coin-id'],'--selected-earning-ids='.$options['selected-earning-ids'],'--selected-block-ids='.$options['selected-block-ids'],'--expected-inventory-checksum='.$options['expected-inventory-checksum'],'--format='.$options['format']);
		$parsed=BadpoolBackwardMaturityDryrun::parseOptions($dryArgs);
		if($parsed['status']!=='pass')return self::failure($parsed['message']);
		return array('status'=>'pass','options'=>$options,'dryrun_args'=>$dryArgs,'selected_earning_ids'=>$parsed['selected_earning_ids'],'selected_block_ids'=>$parsed['selected_block_ids'],'message'=>null);
	}

	private static function failure($message){return array('status'=>'fail','options'=>array(),'message'=>$message);}
	private static function value($a,$k,$default=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$default;}
	private static function summary($report,$key,$default=null){return self::value(self::value($report,'summary',array()),$key,$default);}
	public static function generate($path,$raw,$suppliedChecksum,$earningIds,$blockIds,$inventoryChecksum,$retained,$fresh,$generatedAt=null)
	{
		$scope=self::value($retained,'scope',array());
		$retainedSummary=self::value($retained,'summary',array()); $freshSummary=self::value($fresh,'summary',array());
		$purpose=(string)self::value($scope,'expected_inventory_checksum_purpose','');
		$purposeSafe=$purpose===BadpoolBackwardMaturityDryrun::INVENTORY_CHECKSUM_PURPOSE && strpos(strtolower($purpose),'not maturity authorization')!==false;
		$retainedAssertions=self::value($retained,'validation_assertions',array());
		$priorAssertion=self::value($retainedAssertions,'no_prior_credit_risk_via_account_last_earning',array());
		$allAssertionsPass=!empty($retainedAssertions);foreach($retainedAssertions as $assertion)if(self::value($assertion,'status')!=='pass')$allAssertionsPass=false;
		$checks=array(
			'retained_report_checksum_matches_supplied'=>hash_equals($suppliedChecksum,hash('sha256',$raw)),
			'retained_schema_is_backward_maturity_dryrun_v1'=>self::value($retained,'schema')===BadpoolBackwardMaturityDryrun::SCHEMA,
			'retained_status_ok'=>self::value($retained,'status')==='ok',
			'retained_validation_status_pass'=>self::value($retainedSummary,'validation_status')==='pass',
			'retained_failed_assertions_empty'=>self::value($retained,'failed_assertions')===array(),
			'retained_safety_contract_pass'=>self::value($retained,'mode')==='read-only-preview' && self::value($retained,'read_only')===true && self::value($retained,'db_mutations')===false && self::value($retained,'wallet_reads')===false && self::value($retained,'wallet_sends')===false && self::value($retained,'service_actions')===false && self::value($retained,'backend_loops_run')===false && self::value($retained,'shares_deleted')===false,
			'retained_expected_evidence_matches'=>self::value($retainedSummary,'row_count')===BadpoolBackwardMaturityDryrun::ROW_COUNT && self::value($retainedSummary,'distinct_block_count')===BadpoolBackwardMaturityDryrun::ROW_COUNT && self::value($retainedSummary,'amount')===BadpoolBackwardMaturityDryrun::AMOUNT,
			'retained_validation_assertions_pass'=>$allAssertionsPass,
			'retained_scope_matches_explicit_ids'=>self::value($scope,'coin_id')===BadpoolBackwardMaturityDryrun::COIN_ID && self::value($scope,'algo')===BadpoolBackwardMaturityDryrun::ALGO && self::value($scope,'symbol')===BadpoolBackwardMaturityDryrun::SYMBOL && self::value($scope,'selected_earning_ids')===$earningIds && self::value($scope,'selected_block_ids')===$blockIds,
			'retained_inventory_checksum_matches'=>self::value($scope,'expected_inventory_checksum')===$inventoryChecksum,
			'retained_checksum_purpose_non_authorizing'=>$purposeSafe,
			'fresh_validation_pass'=>self::value($fresh,'status')==='ok' && self::value($freshSummary,'validation_status')==='pass' && self::value($fresh,'failed_assertions')===array(),
			'fresh_matches_retained_scope'=>self::value($fresh,'scope')===$scope,
			'fresh_matches_retained_amount'=>self::value($freshSummary,'amount')===self::value($retainedSummary,'amount'),
			'fresh_matches_retained_counts'=>self::value($freshSummary,'row_count')===self::value($retainedSummary,'row_count') && self::value($freshSummary,'distinct_block_count')===self::value($retainedSummary,'distinct_block_count'),
			'fresh_matches_retained_time_ranges'=>self::value($freshSummary,'block_time_range')===self::value($retainedSummary,'block_time_range') && self::value($freshSummary,'create_time_range')===self::value($retainedSummary,'create_time_range'),
			'prior_credit_basis_is_create_time_documented'=>self::value($priorAssertion,'status')==='pass' && self::value($fresh,'prior_credit_basis')===self::PRIOR_CREDIT_BASIS,
		);
		$failed=array();foreach($checks as $name=>$pass)if(!$pass)$failed[]=$name;
		$project=function($r,$checksum=null) use($path){$s=self::value($r,'summary',array());$out=array('schema'=>self::value($r,'schema'),'status'=>self::value($r,'status'),'validation_status'=>self::value($s,'validation_status'),'row_count'=>self::value($s,'row_count'),'distinct_block_count'=>self::value($s,'distinct_block_count'),'amount'=>self::value($s,'amount'),'block_time_range'=>self::value($s,'block_time_range'),'create_time_range'=>self::value($s,'create_time_range'),'failed_assertions'=>self::value($r,'failed_assertions',array()));if($checksum!==null){$out=array_merge(array('path'=>$path,'supplied_checksum'=>$checksum),$out);}return $out;};
		$package=array('package_type'=>self::PACKAGE_TYPE,'schema'=>self::SCHEMA,'generated_at'=>$generatedAt?:gmdate('c'),'command'=>self::COMMAND,'mode'=>self::MODE,'status'=>empty($failed)?'pass':'hold','read_only'=>true,'db_mutations'=>false,'wallet_reads'=>false,'wallet_sends'=>false,'service_actions'=>false,'backend_loops_run'=>false,'shares_deleted'=>false,
			'scope'=>array('coin_id'=>BadpoolBackwardMaturityDryrun::COIN_ID,'algo'=>BadpoolBackwardMaturityDryrun::ALGO,'symbol'=>BadpoolBackwardMaturityDryrun::SYMBOL,'selected_earning_ids'=>$earningIds,'selected_block_ids'=>$blockIds,'expected_inventory_checksum'=>$inventoryChecksum,'expected_inventory_checksum_purpose'=>BadpoolBackwardMaturityDryrun::INVENTORY_CHECKSUM_PURPOSE),
			'retained_dryrun'=>$project($retained,$suppliedChecksum),'fresh_validation'=>$project($fresh),'prior_credit_basis'=>self::PRIOR_CREDIT_BASIS,'equality_checks'=>$checks,'failed_equality_checks'=>$failed,
			'blocked_actions'=>array('maturity_apply'=>true,'account_credit_apply'=>true,'payout_row_creation'=>true,'wallet_send'=>true,'database_mutation'=>true,'backend_loop_execution'=>true,'service_changes'=>true,'share_deletion'=>true));
		$checksumInput=$package;unset($checksumInput['generated_at']);
		$package['approval_package_checksum']=array('algorithm'=>'sha256','value'=>BadpoolGuardReport::checksum($checksumInput)['value'],'excludes'=>array('generated_at','approval_package_checksum'),'purpose'=>self::CHECKSUM_PURPOSE);
		return $package;
	}
}
