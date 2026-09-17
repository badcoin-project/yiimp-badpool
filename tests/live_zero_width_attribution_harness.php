<?php
// Focused executable model plus source assertions for stratum's atomic capture.
$fail=array();
function ok($condition,$message){global $fail;if(!$condition)$fail[]=$message;}
function capture(&$db,$block,$floor,$ceiling,$shares,$existing=array()) {
	$before=$db;
	$work=$db;
	$id=$block['id'];
	$work['blocks'][$id]=$block;
	$work['candidates'][$id]=array('floor'=>$floor,'ceiling'=>$ceiling);
	$work['attributions'][$id]=$existing;
	$normal=array();
	foreach($shares as $share) if($share['id']>$floor&&$share['id']<=$ceiling&&$share['valid']) {
		$uid=$share['userid'];
		if(isset($work['accounts'][$uid])) $normal[$uid]=isset($normal[$uid])?$normal[$uid]+$share['difficulty']:$share['difficulty'];
	}
	if($normal) foreach($normal as $uid=>$difficulty) $work['attributions'][$id][$uid]=array('difficulty'=>$difficulty,'no_fees'=>$work['accounts'][$uid]['no_fees'],'donation'=>$work['accounts'][$uid]['donation'],'fallback'=>false);
	else {
		$uid=isset($block['userid'])?$block['userid']:0;
		$difficulty=isset($block['difficulty_user'])?$block['difficulty_user']:null;
		$valid=is_int($uid)&&$uid>0&&isset($work['accounts'][$uid])&&is_numeric($difficulty)&&is_finite((float)$difficulty)&&(float)$difficulty>0;
		if($floor!==$ceiling||!$valid){$db=$before;return false;}
		if($work['attributions'][$id]){$db=$before;return false;}
		$account=$work['accounts'][$uid];
		$work['attributions'][$id][$uid]=array('difficulty'=>$difficulty,'no_fees'=>$account['no_fees']===null?0:$account['no_fees'],'donation'=>$account['donation']===null?0:$account['donation'],'fallback'=>true);
	}
	$work['cursor']=$ceiling;
	$db=$work;
	return true;
}
function fixture(){return array('blocks'=>array(),'candidates'=>array(),'attributions'=>array(),'accounts'=>array(7=>array('no_fees'=>1,'donation'=>2.5),8=>array('no_fees'=>0,'donation'=>1),9=>array('no_fees'=>null,'donation'=>0),10=>array('no_fees'=>null,'donation'=>null)),'cursor'=>10);}
$db=fixture();ok(capture($db,array('id'=>1,'userid'=>7,'difficulty_user'=>9),10,12,array(array('id'=>11,'userid'=>7,'difficulty'=>3,'valid'=>1))), 'normal capture commits');ok(count($db['attributions'][1])===1&&$db['attributions'][1][7]===array('difficulty'=>3,'no_fees'=>1,'donation'=>2.5,'fallback'=>false),'normal attribution remains unchanged');
$db=fixture();ok(capture($db,array('id'=>2,'userid'=>7,'difficulty_user'=>50.360301),10,10,array()),'zero-width fallback commits');ok(count($db['attributions'][2])===1&&$db['attributions'][2][7]===array('difficulty'=>50.360301,'no_fees'=>1,'donation'=>2.5,'fallback'=>true),'fallback preserves discovery difficulty and account terms');
$db=fixture();ok(capture($db,array('id'=>8,'userid'=>9,'difficulty_user'=>4),10,10,array()),'zero-width fallback with nullable no_fees commits');ok($db['attributions'][8][9]['no_fees']===0&&$db['attributions'][8][9]['donation']===0,'fallback normalizes null no_fees and preserves zero donation');
$db=fixture();ok(capture($db,array('id'=>9,'userid'=>10,'difficulty_user'=>4),10,10,array()),'zero-width fallback with nullable account terms commits');ok($db['attributions'][9][10]['no_fees']===0&&$db['attributions'][9][10]['donation']===0,'fallback normalizes both nullable account terms');
foreach(array(0,-1,null) as $uid){$db=fixture();$before=$db;ok(!capture($db,array('id'=>3,'userid'=>$uid,'difficulty_user'=>1),10,10,array())&&$db===$before,'invalid user rolls back atomically');}
$db=fixture();$before=$db;ok(!capture($db,array('id'=>4,'userid'=>99,'difficulty_user'=>1),10,10,array())&&$db===$before,'missing account rolls back atomically');
foreach(array(null,0,-1,INF,NAN,'invalid') as $difficulty){$db=fixture();$before=$db;ok(!capture($db,array('id'=>5,'userid'=>7,'difficulty_user'=>$difficulty),10,10,array())&&$db===$before,'invalid difficulty rolls back atomically');}
$db=fixture();$before=$db;$existing=array(7=>array('difficulty'=>4,'no_fees'=>1,'donation'=>2.5,'fallback'=>false));ok(!capture($db,array('id'=>6,'userid'=>7,'difficulty_user'=>2),10,10,array(),$existing)&&$db===$before,'existing attribution is not duplicated and capture fails closed');
$db=fixture();$shares=array(array('id'=>11,'userid'=>7,'difficulty'=>3,'valid'=>1),array('id'=>12,'userid'=>8,'difficulty'=>5,'valid'=>1));ok(capture($db,array('id'=>7,'userid'=>7,'difficulty_user'=>99),10,12,$shares)&&count($db['attributions'][7])===2&&!$db['attributions'][7][7]['fallback']&&!$db['attributions'][7][8]['fallback'],'multi-user normal attribution remains authoritative');
$source=file_get_contents(dirname(__FILE__).'/../stratum/share.cpp');
foreach(array('SELECT %llu,S.userid,SUM(S.difficulty),IFNULL(A.no_fees,0),IFNULL(A.donation,0)','std::isfinite(block->difficulty_user)','SELECT B.id,B.userid,B.difficulty_user,IFNULL(A.no_fees,0),IFNULL(A.donation,0)','C.share_floor_id=C.share_ceiling_id','B.userid>0','B.difficulty_user IS NOT NULL','B.difficulty_user>0','NOT EXISTS (SELECT 1 FROM live_block_attributions','db_query_transaction(db, "ROLLBACK")') as $needle) ok(strpos($source,$needle)!==false,'source guard '.$needle);
$accounting=file_get_contents(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolLiveBlockAccounting.php');ok(strpos($accounting,"throw new RuntimeException('empty discovery attribution')")!==false,'downstream empty attribution refusal unchanged');
if($fail)throw new RuntimeException('FAIL: '.implode('; ',$fail));
echo "PASS live zero-width attribution harness\n";
