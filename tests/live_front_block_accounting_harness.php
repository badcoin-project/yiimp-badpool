<?php
function arraySafeVal($a,$k,$d=null){return isset($a[$k])?$a[$k]:$d;}
require_once dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolLiveBlockAccounting.php';
$fail=array(); function ok($v,$m){global $fail;if(!$v)$fail[]=$m;}
class FixtureDaemon implements BadpoolLiveBlockDaemon { public $answers=array(); public function inspect($c){$a=$this->answers[$c['block_id']];if($a==='fail')throw new RuntimeException('rpc');return $a;} }
class FixtureStore implements BadpoolLiveBlockStore {
	public $blocks=array(),$candidates=array(),$earnings=array(),$mutations=array(),$raceOnApply=false;
	public function candidates($coin,$algo,$limit){$out=array();foreach($this->candidates as $c){$b=$this->blocks[$c['block_id']];if($c['coin_id']===$coin&&$c['algo']===$algo&&$b['category']==='new'&&!isset($this->earnings[$c['block_id']]))$out[]=$c;}usort($out,function($a,$b){return $a['block_id']-$b['block_id'];});return array_slice($out,0,$limit);}
	public function apply($c,$x){$id=$c['block_id'];if($this->raceOnApply)$this->blocks[$id]['category']='generate';$b=&$this->blocks[$id];if($b['id']!==$id||$b['coin_id']!==$c['coin_id']||$b['blockhash']!==$c['blockhash']||$b['category']!=='new'||isset($this->earnings[$id]))return false;$before=$this->blocks;if($x['category']==='orphan'){$b['category']='orphan';$b['amount']=0;}else{if(empty($c['attribution'])){$this->blocks=$before;return false;}$b=array_merge($b,$x);$this->earnings[$id]=array('status'=>0,'attribution'=>$c['attribution']);}$this->mutations[]=$id;return true;}
}
function candidate($id,$coin=1267,$algo='scrypt'){return array('block_id'=>$id,'coin_id'=>$coin,'blockhash'=>'h'.$id,'algo'=>$algo,'found_time'=>1000+$id,'price'=>0.01,'share_floor_id'=>100,'share_ceiling_id'=>110,'attribution'=>array(7=>3,8=>1));}
$s=new FixtureStore;$d=new FixtureDaemon;
$s->blocks[20431]=array('id'=>20431,'coin_id'=>1267,'blockhash'=>'h20431','category'=>'new'); // historical: deliberately no candidate
$s->blocks[30001]=array('id'=>30001,'coin_id'=>1267,'blockhash'=>'h30001','category'=>'new');$s->candidates[]=candidate(30001);$d->answers[30001]=array('category'=>'immature','txhash'=>'tx1','amount'=>8,'confirmations'=>2);
$r=(new BadpoolLiveBlockAccounting($s,$d))->run(1267,'scrypt',2);
ok($r['immature']===1,'live candidate');ok($s->blocks[20431]['category']==='new','historical excluded');ok($s->blocks[30001]['txhash']==='tx1'&&$s->blocks[30001]['amount']===8&&$s->blocks[30001]['confirmations']===2,'enrichment');ok($s->earnings[30001]['status']===0,'status zero');
try{(new BadpoolLiveBlockAccounting($s,$d))->run(0,'',11);ok(false,'scope required');}catch(InvalidArgumentException $e){ok(true,'scope required');}
$s->blocks[30002]=array('id'=>30002,'coin_id'=>1267,'blockhash'=>'h30002','category'=>'new');$s->candidates[]=candidate(30002);$d->answers[30002]=array('category'=>'orphan','amount'=>0,'txhash'=>null,'confirmations'=>0);(new BadpoolLiveBlockAccounting($s,$d))->run(1267,'scrypt',1);ok($s->blocks[30002]['category']==='orphan'&&!isset($s->earnings[30002]),'orphan no earning');
$s->blocks[30003]=array('id'=>30003,'coin_id'=>1267,'blockhash'=>'h30003','category'=>'new');$s->candidates[]=candidate(30003);$s->earnings[30003]=array('status'=>0);$d->answers[30003]=array('category'=>'immature');(new BadpoolLiveBlockAccounting($s,$d))->run(1267,'scrypt',10);ok($s->blocks[30003]['category']==='new','existing earning excluded');
$s->blocks[30004]=array('id'=>30004,'coin_id'=>1267,'blockhash'=>'h30004','category'=>'new');$s->candidates[]=candidate(30004);$d->answers[30004]='fail';$before=$s->blocks[30004];$r=(new BadpoolLiveBlockAccounting($s,$d))->run(1267,'scrypt',10);ok($s->blocks[30004]===$before&&$r['daemon_failed']===1,'daemon failure atomic');
$race=new FixtureStore;$race->blocks[4]=array('id'=>4,'coin_id'=>1267,'blockhash'=>'h4','category'=>'new');$race->candidates[]=candidate(4);$race->raceOnApply=true;$d2=new FixtureDaemon;$d2->answers[4]=array('category'=>'immature','amount'=>1,'txhash'=>'t','confirmations'=>1);(new BadpoolLiveBlockAccounting($race,$d2))->run(1267,'scrypt',1);ok(empty($race->mutations)&&!isset($race->earnings[4]),'category race');
$empty=new FixtureStore;$empty->blocks[5]=array('id'=>5,'coin_id'=>1267,'blockhash'=>'h5','category'=>'new');$c=candidate(5);$c['attribution']=array();$empty->candidates[]=$c;$d2->answers[5]=array('category'=>'immature','amount'=>1,'txhash'=>'t','confirmations'=>1);$before=$empty->blocks[5];(new BadpoolLiveBlockAccounting($empty,$d2))->run(1267,'scrypt',1);ok($empty->blocks[5]===$before&&!isset($empty->earnings[5]),'atomic rollback');
$src=file_get_contents(dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolLiveBlockAccounting.php');$insert=file_get_contents(dirname(__FILE__).'/../stratum/share.cpp');$schema=file_get_contents(dirname(__FILE__).'/../sql/2026-09-06-live-block-accounting.sql');
ok(strpos($insert,'SELECT %llu,%d,\'%s\',\'%s\',%d,CO.price,')===false,'raw nullable price excluded');
ok(strpos($insert,'IFNULL(CO.price,0)')!==false,'null price normalized');
ok(strpos($insert,'IFNULL(A.no_fees,0)')!==false,'null no_fees normalized');
ok(strpos($insert,'IFNULL(A.donation,0)')!==false,'null donation normalized');
ok(strpos($insert,'GROUP BY S.userid,IFNULL(A.no_fees,0),IFNULL(A.donation,0)')!==false,'attribution grouping uses normalized account fields');
ok(preg_match('/`price`\s+double\s+NOT NULL\s+DEFAULT 0/i',$schema)===1,'schema price default safe');
$p0=strpos($insert,'insert into blocks');$p1=strpos($insert,'INSERT INTO live_block_candidates');$p2=strpos($insert,'INSERT INTO live_block_attributions');$p3=strpos($insert,'UPDATE live_block_share_cursors C INNER JOIN live_block_candidates');
ok($p0!==false&&$p1!==false&&$p2!==false&&$p3!==false&&$p0<$p1&&$p1<$p2&&$p2<$p3,'live insert sequence preserved');
ok(strpos($insert,'INSERT IGNORE INTO live_block_share_cursors')!==false&&strpos($insert,'last_share_id=B.share_ceiling_id')!==false,'share cursor behavior preserved');
ok(strpos($insert,'INNER JOIN live_block_candidates C ON C.block_id=%llu')!==false,'live candidate eligibility boundary preserved');
ok(stripos($insert,"WHERE category='new'")===false,'no historical new-block backlog selection');
foreach(array('DELETE FROM shares','status=1','status=2','INSERT INTO payouts','sendmany','service ') as $forbidden)ok(stripos($src.$insert,$forbidden)===false,'forbidden '.$forbidden);
ok(strpos($src,'live_block_candidates')!==false&&strpos($insert,'live_block_candidates')!==false,'durable live ledger');
if($fail)throw new RuntimeException('FAIL: '.implode('; ',$fail));echo "PASS live front block accounting harness\n";
