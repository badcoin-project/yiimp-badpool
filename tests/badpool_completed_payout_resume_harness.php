<?php
// Offline fixtures only. No Yii bootstrap, database connection, or wallet transport.
class CConsoleCommand {}
function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}
require_once(dirname(__DIR__).'/web/yaamp/commands/BadpoolGuardCommand.php');

$checks=0;$failures=array();
function resume_expect($ok,$message){global $checks,$failures;$checks++;if(!$ok)$failures[]=$message;}
function resume_lifecycle_report($name,$startingFailures){
    global $failures;
    $added=count($failures)-$startingFailures;
    if($added===0)echo 'PASS '.$name.($name==='skein'?' payout-531':'').' resume -> proof -> ledger-only apply -> RECONCILED'."\n";
    else echo 'FAIL '.$name.' resume lifecycle: '.$added.' assertion(s)'."\n";
}
function resume_lock_released($path,$label){
    $lock=fopen($path,'c');$owned=$lock&&flock($lock,LOCK_EX|LOCK_NB);
    resume_expect($owned,$label.': batch lock released');
    if($owned)flock($lock,LOCK_UN);
    if($lock)fclose($lock);
}
class ResumeFixtureGuard {
    public $payouts,$accounts=array(array('id'=>76,'balance'=>'123.45678900')),$earnings=array(array('id'=>901,'status'=>2,'amount'=>'12.50000000'));
    public $reads=0,$writes=0;
    public function selectAll($sql,$params){
        $this->reads++;
        if(!preg_match('/^SELECT id, idcoin, IFNULL\(completed,0\) AS completed, tx FROM payouts WHERE id IN \(:payout_boundary_0(?:,:payout_boundary_[0-9]+)*\) ORDER BY id$/',$sql))throw new RuntimeException('Unexpected SQL');
        resume_expect(array_values($params)===array_column($this->payouts,'id'),'SELECT binds the exact requested fixture payout scope');
        return $this->payouts;
    }
    public function __call($method,$args){$this->writes++;throw new RuntimeException('Database mutation reached: '.$method);}
}
class ResumeFixtureAdapter extends BadpoolPaymentBatchPhaseAdapter {
    public $phases=0,$commands=0;
    public function __construct($guard){parent::__construct($guard,function(){$this->commands++;throw new RuntimeException('Guard command reached');});}
    private function forbidden(){$this->phases++;throw new RuntimeException('Financial phase reached');}
    public function safetyCheck($l,$o){return $this->forbidden();}
    public function selectEligibleWork($l,$o){return $this->forbidden();}
    public function packageMaturity($l,$o){return $this->forbidden();}
    public function applyMaturity($l,$o){return $this->forbidden();}
    public function paymentDelayCheck($l,$o){return $this->forbidden();}
    public function creditAccounts($l,$o){return $this->forbidden();}
    public function preparePayoutRows($l,$o){return $this->forbidden();}
}
class ResumeFixtureCommand extends BadpoolGuardCommand {
    public static $fixtureAdapter;
    protected function paymentBatchPhaseAdapter(){return self::$fixtureAdapter;}
    public function executePaymentBatchPhaseCommand($command,$args){throw new RuntimeException('Nested phase command reached');}
}
function resume_write($path,$value){file_put_contents($path,json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");}
function resume_fixture($root,$laneId,$shape=array()){
    $lane=(new BadpoolLivePaymentLaneRegistry())->get($laneId);
    $id=arraySafeVal($shape,'batch_id','20261003T041722Z-'.substr(hash('sha256',$root.$laneId),0,12));
    $dir=$root.'/'.$id;if(file_exists($dir))throw new RuntimeException('Fixture path already exists');mkdir($dir,0770,true);
    $ids=arraySafeVal($shape,'created_payout_ids',array(531));
    $ledger=array('batch_id'=>$id,'created_at'=>'2026-10-03T04:17:22Z','updated_at'=>'2026-10-03T04:17:22Z','command'=>'batch-run',
        'mode'=>'auto','scope'=>'all-active-payout-coins','only'=>$lane->operationalAlgo(),'batch_size'=>25,'stop_before_wallet_send'=>true,
        'current_phase'=>arraySafeVal($shape,'current_phase',6),'batch_state'=>arraySafeVal($shape,'batch_state','READY_FOR_WALLET_APPROVAL'),
        'run_directory'=>$dir,'coordinator_owner'=>$lane->ownershipEnvelope(),
        'selected_coin_scope'=>array(array('id'=>$lane->coinId(),'coin_id'=>$lane->coinId(),'symbol'=>'BAD','algo'=>$lane->dbAlgo())),
        'selected_earning_ids'=>array(901),'selected_block_ids'=>array($lane->blockBoundary()+1),'selected_account_ids'=>array(76),
        'selected_accounts_by_coin'=>array($lane->coinId()=>array('account_ids'=>array(76))),
        'selected_work_by_coin'=>array($lane->coinId()=>array('selection_mode'=>'live_status1','earning_ids'=>array(901),'block_ids'=>array($lane->blockBoundary()+1),'account_ids'=>array(76))),
        'payment_delay_qualified_earning_ids'=>array(901),'created_payout_ids'=>$ids,'phase_results'=>array(),'checksums'=>array(),
        'approval_package_paths'=>array(),'dryrun_report_paths'=>array(),'warnings'=>array(),'errors'=>array());
    $files=array('safety-check-report.json','eligible-work-report.json','maturity-packages.json','maturity-apply-report.json','payment-delay-report.json','account-credit-apply-report.json','payout-row-apply-report.json');
    foreach($files as $phase=>$name){
        $report=array('status'=>'pass','scope'=>array('coin_id'=>$lane->coinId()));
        if($phase===6)$report+=array('created_payout_ids'=>$ids,'payout_rows_inserted'=>count($ids),'db_mutations'=>true);
        $path=$dir.'/'.$name;resume_write($path,array($report));$sum=hash_file('sha256',$path);$key='phase_'.$phase.'_sha256';
        $ledger['phase_results'][]=array('phase_number'=>$phase,'phase_name'=>$name,'status'=>'pass','mutation_scope'=>array(),
            'report_path'=>$phase===2?null:$path,'package_path'=>$phase===2?$path:null,'checksum_summary'=>array($key=>$sum),
            'started_at'=>'2026-10-03T04:17:22Z','finished_at'=>'2026-10-03T04:17:23Z');
        $ledger['checksums'][$key]=$sum;
    }
    resume_write($dir.'/ledger.json',$ledger);
    // Send evidence is preserved, not interpreted as permission for another send.
    resume_write($dir.'/fixture-reconciled-send-journal.json',array('state'=>arraySafeVal($shape,'journal_state','RECONCILED'),
        'operations'=>array(array('state'=>arraySafeVal($shape,'operation_state','RECONCILED'),'payout_ids'=>$ids,'txid'=>arraySafeVal($shape,'tx',str_repeat('a',64))))));
    $guard=new ResumeFixtureGuard();$guard->payouts=array();
    foreach($ids as $pid)$guard->payouts[]=array('id'=>$pid,'idcoin'=>$lane->coinId(),'completed'=>arraySafeVal($shape,'completed',1),'tx'=>arraySafeVal($shape,'tx',str_repeat('a',64)));
    return array($ledger,$guard,new ResumeFixtureAdapter($guard),$lane);
}
function resume_options($l){return array('mode'=>'auto','scope'=>'all-active-payout-coins','only'=>$l['only'],'batch_size'=>250,'resume_batch_id'=>$l['batch_id'],'completed_payout_resume'=>true);}
function resume_snapshot($dir){$out=array();foreach(glob($dir.'/*.json') as $p)$out[basename($p)]=hash_file('sha256',$p);return $out;}
function resume_safety($guard,$adapter,$before,$label){
    resume_expect(serialize(array($guard->payouts,$guard->accounts,$guard->earnings))===$before,$label.': payouts/accounts/earnings unchanged');
    resume_expect($guard->writes===0&&$adapter->phases===0&&$adapter->commands===0,$label.': no financial phase, DB write, or guard dispatch (including wallet send)');
}

$root=sys_get_temp_dir().'/badpool-completed-resume-'.bin2hex(random_bytes(6));mkdir($root);
foreach(array('scrypt','skein','yescrypt','groestl') as $name){
    $lifecycleFailures=count($failures);
    $shape=$name==='skein'?json_decode(file_get_contents(__DIR__.'/fixtures/completed-payout-531.json'),true):array();
    list($l,$g,$a,$lane)=resume_fixture($root.'/'.$name,'live-'.$name.'-v1',$shape);
    $before=serialize(array($g->payouts,$g->accounts,$g->earnings));$files=resume_snapshot($l['run_directory']);
    $runner=new BadpoolPaymentBatchRunner($a,dirname($l['run_directory']));
    $options=resume_options($l);unset($options['completed_payout_resume']); // Ownership alone selects the safe path.
    $r=$runner->run($options);$after=json_decode(file_get_contents($l['run_directory'].'/ledger.json'),true);
    resume_lock_released($l['run_directory'].'/resume.lock',$name.' successful resume');
    resume_expect($r['status']==='hold'&&$r['batch_state']==='HOLD_COMPLETED_PAYOUT_RECONCILIATION',$name.': exact completed boundary');
    resume_expect($r['created_payout_ids']===$l['created_payout_ids']&&$r['batch_id']===$l['batch_id']&&$after['coordinator_owner']===$lane->ownershipEnvelope(),$name.': exact batch/payout/owner preserved');
    resume_expect($r['next_action']==='read_only_wallet_proof_closeout'&&$r['suggested_command']===null,$name.': no wallet-send recommendation');
    $expected=$l;foreach(array('updated_at','batch_state','reconciliation_classification','completed_payout_ids','warnings') as $key){unset($expected[$key]);unset($after[$key]);}
    resume_expect($after===$expected,$name.': only boundary metadata changed; all scopes/results/checksums preserved');
    $newFiles=resume_snapshot($l['run_directory']);unset($files['ledger.json'],$newFiles['ledger.json']);resume_expect($files===$newFiles,$name.': phase files and send journal byte-identical');
    $again=$runner->run(resume_options($l));resume_expect($again['batch_state']==='HOLD_COMPLETED_PAYOUT_RECONCILIATION',$name.': boundary replay does not run phases');
    $proofCalls=0;
    $proof=function($coin,$ids)use($lane,$l,&$proofCalls){$proofCalls++;resume_expect($coin===$lane->coinId()&&$ids===$l['created_payout_ids'],'proof exact lane and payout scope');
        return array('status'=>'pass','selected_payout_ids'=>$ids,'scope'=>array('coin_id'=>$coin),'closeout_valid'=>true,'wallet_lookup_success'=>true,'wallet_txid_expected'=>true,'wallet_amount_matches_expected'=>true,'wallet_confirmations_present'=>true,'wallet_sends'=>false,'db_mutations'=>false);};
    $proofReport=(new BadpoolCompletedPayoutBatchCloseout($a,$proof,dirname($l['run_directory'])))->preview($l['batch_id']);
    resume_expect($proofReport['status']==='pass'&&$proofCalls===1,$name.': existing closeout proof consumes HOLD');
    $proofPath=$l['run_directory'].'/closeout-proof.json';resume_write($proofPath,$proofReport);$sum=hash_file('sha256',$proofPath);
    $apply=new BadpoolCompletedPayoutBatchCloseoutApply(dirname($l['run_directory']));$applied=$apply->apply($l['batch_id'],$proofPath,$sum,BadpoolCompletedPayoutBatchCloseoutApply::CONFIRMATION);
    $terminal=json_decode(file_get_contents($l['run_directory'].'/ledger.json'),true);
    resume_expect($applied['status']==='pass'&&$terminal['batch_state']==='RECONCILED'&&$terminal['reconciled_payout_ids']===$l['created_payout_ids'],$name.': existing ledger-only apply completes');
    resume_expect($applied['wallet_sends']===false&&$applied['wallet_rpc_used']===false&&$applied['db_mutations']===false,$name.': closeout apply has no DB/wallet mutations');
    resume_expect($apply->apply($l['batch_id'],$proofPath,$sum,BadpoolCompletedPayoutBatchCloseoutApply::CONFIRMATION)['already_reconciled']===true,$name.': closeout replay unchanged');
    $terminalHash=hash_file('sha256',$l['run_directory'].'/ledger.json');$replay=$runner->run(resume_options($l));
    resume_expect($replay['status']==='refused'&&hash_file('sha256',$l['run_directory'].'/ledger.json')===$terminalHash,$name.': terminal batch never regresses to HOLD');
    resume_safety($g,$a,$before,$name);
    resume_lifecycle_report($name,$lifecycleFailures);
}

$cases=array(
    'sha256d','foreign-only','foreign-config','foreign-owner','missing-owner','malformed-owner','wrong-schema','wrong-owner-coin','wrong-owner-algo','wrong-boundary',
    'wrong-coin','wrong-coin-alias','wrong-db-algo','groestl-operational-as-db','before-activation','wrong-ledger-only','wrong-mode','wrong-scope','wallet-stop-disabled','batch-over-limit',
    'not-completed','missing-tx','missing-row','foreign-row-coin','malformed-row-coin','malformed-completed','malformed-tx','outside-created-ids','duplicate-row','changed-payout-scope','duplicate-payout-scope',
    'non-phase-6','invalid-state','missing-phase','failed-last-phase','changed-phase-file','changed-checksum','changed-phase6-scope','wrong-run-directory'
);
foreach($cases as $case){
    $name=$case==='sha256d'?'sha256d':($case==='groestl-operational-as-db'?'groestl':'skein');
    list($l,$g,$a,$lane)=resume_fixture($root.'/'.$case,'live-'.$name.'-v1');$o=resume_options($l);
    switch($case){
        case 'foreign-only':$o['only']='scrypt';break;
        case 'foreign-config':$o['lane_configuration']=BadpoolLivePaymentLaneRegistry::scryptCompatibility();break;
        case 'foreign-owner':$o['coordinator_owner']=BadpoolLivePaymentLaneRegistry::scryptCompatibility()->ownershipEnvelope();break;
        case 'missing-owner':unset($l['coordinator_owner']);break;
        case 'malformed-owner':$l['coordinator_owner']='skein';break;
        case 'wrong-schema':$l['coordinator_owner']['schema']='wrong';break;
        case 'wrong-owner-coin':$l['coordinator_owner']['coin_id']=1267;break;
        case 'wrong-owner-algo':$l['coordinator_owner']['algo']='scrypt';break;
        case 'wrong-boundary':$l['coordinator_owner']['block_id_gt']++;break;
        case 'wrong-coin':$l['selected_coin_scope'][0]['id']=1267;break;
        case 'wrong-coin-alias':$l['selected_coin_scope'][0]['coin_id']=1267;break;
        case 'wrong-db-algo':$l['selected_coin_scope'][0]['algo']='scrypt';break;
        case 'groestl-operational-as-db':$l['selected_coin_scope'][0]['algo']='groestl';break;
        case 'before-activation':$l['selected_block_ids']=array($lane->blockBoundary());break;
        case 'wrong-ledger-only':$l['only']='scrypt';break;
        case 'wrong-mode':$l['mode']='normal';break;
        case 'wrong-scope':$l['scope']='all';break;
        case 'wallet-stop-disabled':$l['stop_before_wallet_send']=false;break;
        case 'batch-over-limit':$l['batch_size']=26;break;
        case 'not-completed':$g->payouts[0]['completed']=0;break;
        case 'missing-tx':$g->payouts[0]['tx']='';break;
        case 'missing-row':$g->payouts=array();break;
        case 'foreign-row-coin':$g->payouts[0]['idcoin']=1267;break;
        case 'malformed-row-coin':$g->payouts[0]['idcoin']='1268foreign';break;
        case 'malformed-completed':$g->payouts[0]['completed']='1foreign';break;
        case 'malformed-tx':$g->payouts[0]['tx']=array('invalid');break;
        case 'outside-created-ids':$g->payouts[0]['id']=532;break;
        case 'duplicate-row':$g->payouts[]=$g->payouts[0];break;
        case 'changed-payout-scope':$l['created_payout_ids']=array(532);break;
        case 'duplicate-payout-scope':$l['created_payout_ids']=array(531,531);break;
        case 'non-phase-6':$l['current_phase']=5;break;
        case 'invalid-state':$l['batch_state']='HOLD';break;
        case 'missing-phase':unset($l['phase_results'][3]);break;
        case 'failed-last-phase':$e=$l['phase_results'][5];$e['status']='hold';$l['phase_results'][]=$e;break;
        case 'changed-phase-file':file_put_contents($l['phase_results'][5]['report_path'],'[]');break;
        case 'changed-checksum':$l['checksums']['phase_6_sha256']=str_repeat('0',64);break;
        case 'changed-phase6-scope':$p=$l['phase_results'][6]['report_path'];resume_write($p,array(array('status'=>'pass','created_payout_ids'=>array(532),'payout_rows_inserted'=>1)));$sum=hash_file('sha256',$p);$l['checksums']['phase_6_sha256']=$sum;$l['phase_results'][6]['checksum_summary']['phase_6_sha256']=$sum;break;
        case 'wrong-run-directory':$l['run_directory']=$root;break;
    }
    $dir=$root.'/'.$case.'/'.$l['batch_id'];resume_write($dir.'/ledger.json',$l);$files=resume_snapshot($dir);$before=serialize(array($g->payouts,$g->accounts,$g->earnings));
    // A reader returning malformed rows is tested separately from its parameter binding.
    if(in_array($case,array('outside-created-ids','missing-row','duplicate-row'),true)){
        $g=new class($g->payouts) extends ResumeFixtureGuard {public function __construct($rows){$this->payouts=$rows;}public function selectAll($sql,$params){$this->reads++;return $this->payouts;}};
        $a=new ResumeFixtureAdapter($g);$before=serialize(array($g->payouts,$g->accounts,$g->earnings));
    }
    $r=(new BadpoolPaymentBatchRunner($a,$root.'/'.$case))->run($o);
    resume_lock_released($dir.'/resume.lock',$case.' refusal');
    resume_expect($r['status']==='refused',$case.': fails closed');resume_expect($files===resume_snapshot($dir),$case.': all retained evidence unchanged');resume_safety($g,$a,$before,$case);
    if(!in_array($case,array('not-completed','missing-tx','missing-row','foreign-row-coin','malformed-row-coin','malformed-completed','malformed-tx','outside-created-ids','duplicate-row'),true))resume_expect($g->reads===0,$case.': refused before any DB access');
}

// A separate PHP process owns the exact batch lock during contention.
list($l,$g,$a)=resume_fixture($root.'/locked','live-skein-v1');
$childCode='$lock=fopen($argv[1],"c");if(!$lock||!flock($lock,LOCK_EX)){exit(2);}echo "LOCKED\n";flush();fgets(STDIN);flock($lock,LOCK_UN);fclose($lock);';
$child=proc_open(array(PHP_BINARY,'-r',$childCode,$l['run_directory'].'/resume.lock'),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
resume_expect(is_resource($child)&&trim(fgets($pipes[1]))==='LOCKED','separate process owns batch lock before contention test');
$files=resume_snapshot($l['run_directory']);$r=(new BadpoolPaymentBatchRunner($a,$root.'/locked'))->run(resume_options($l));
resume_expect($r['status']==='refused'&&$files===resume_snapshot($l['run_directory'])&&$g->reads===0&&$a->phases===0,'concurrent resume refuses without reading rows or changing evidence');
fwrite($pipes[0],"\n");fclose($pipes[0]);fclose($pipes[1]);fclose($pipes[2]);resume_expect(proc_close($child)===0,'separate lock owner exits cleanly');
resume_lock_released($l['run_directory'].'/resume.lock','contention refusal');
$r=(new BadpoolPaymentBatchRunner($a,$root.'/locked'))->run(resume_options($l));
resume_expect($r['batch_state']==='HOLD_COMPLETED_PAYOUT_RECONCILIATION','legitimate resume succeeds after competing owner releases lock');
resume_lock_released($l['run_directory'].'/resume.lock','post-contention success');

// An evidence-reader exception is refused, and the outer owner still releases.
list($l,$g,$a)=resume_fixture($root.'/exception','live-skein-v1');
$throwing=new class extends ResumeFixtureGuard {public function selectAll($sql,$params){$this->reads++;throw new RuntimeException('Fixture read failed');}};
$throwing->payouts=$g->payouts;
$files=resume_snapshot($l['run_directory']);
$r=(new BadpoolPaymentBatchRunner(new ResumeFixtureAdapter($throwing),$root.'/exception'))->run(resume_options($l));
resume_expect($r['status']==='refused'&&$files===resume_snapshot($l['run_directory'])&&$throwing->reads===1,'reader exception refuses without evidence mutation');
resume_lock_released($l['run_directory'].'/resume.lock','reader exception');
$r=(new BadpoolPaymentBatchRunner($a,$root.'/exception'))->run(resume_options($l));
resume_expect($r['batch_state']==='HOLD_COMPLETED_PAYOUT_RECONCILIATION','legitimate resume succeeds after reader exception');

// Multi-row batches must match the entire durable creation report.
list($l,$g,$a)=resume_fixture($root.'/multi-row','live-groestl-v1',array('created_payout_ids'=>array(601,602)));
$r=(new BadpoolPaymentBatchRunner($a,$root.'/multi-row'))->run(resume_options($l));
resume_expect($r['batch_state']==='HOLD_COMPLETED_PAYOUT_RECONCILIATION'&&$r['created_payout_ids']===array(601,602),'exact multi-row batch reaches the same boundary');

// Real command parser/dispatcher with the real read-only phase adapter. Only
// the database reader is replaced. The command's default runtime is local.
// Production defaults resolve backend/../../../../runtime (repository runtime).
$commandRoot=dirname(__DIR__).'/runtime/badpool-payment-batches';
$commandDirs=array();
foreach(array('scrypt','skein','yescrypt','groestl','sha256d') as $name){
    list($l,$g,$a,$lane)=resume_fixture($commandRoot,'live-'.$name.'-v1',array('batch_id'=>'20991008T010000Z-'.substr(hash('sha256',$root.$name),0,12)));$commandDirs[]=$l['run_directory'];
    $before=serialize(array($g->payouts,$g->accounts,$g->earnings));ResumeFixtureCommand::$fixtureAdapter=$a;
    ob_start();$rc=(new ResumeFixtureCommand())->run(array('batch-run','--resume-batch-id='.$l['batch_id'],'--only='.$name,'--format=json'));$r=json_decode(ob_get_clean(),true);
    resume_expect(is_array($r)&&($name==='sha256d'?$r['status']==='refused':$r['batch_state']==='HOLD_COMPLETED_PAYOUT_RECONCILIATION'),$name.': real CLI dispatch result');
    resume_expect(strpos(json_encode($r),'not implemented and cannot be mutated')===false,$name.': stale warning removed');
    resume_safety($g,$a,$before,$name.' CLI');
}
foreach(array('scrypt','skein','yescrypt','groestl') as $name){
    foreach(array('missing-owner','foreign-only') as $case){
        list($l,$g,$a)=resume_fixture($commandRoot,'live-'.$name.'-v1',array('batch_id'=>'20991008T010001Z-'.substr(hash('sha256',$root.$name.$case),0,12)));$commandDirs[]=$l['run_directory'];
        if($case==='missing-owner')unset($l['coordinator_owner']);resume_write($l['run_directory'].'/ledger.json',$l);$files=resume_snapshot($l['run_directory']);
        ResumeFixtureCommand::$fixtureAdapter=$a;$only=$case==='foreign-only'?($name==='scrypt'?'skein':'scrypt'):$name;
        ob_start();(new ResumeFixtureCommand())->run(array('batch-run','--resume-batch-id='.$l['batch_id'],'--only='.$only,'--format=json'));$r=json_decode(ob_get_clean(),true);
        resume_expect($r['status']==='refused'&&$files===resume_snapshot($l['run_directory'])&&$g->reads===0&&$a->phases===0,$name.' CLI '.$case.': refuses before phases/DB');
    }
}
// Test-only cleanup is confined to the exact directories this fixture created.
// A recorded lifecycle failure must never produce a visible PASS message.
$beforeReportingCheck=count($failures);$failures[]='synthetic lifecycle assertion failure';
ob_start();resume_lifecycle_report('reporting-self-check',$beforeReportingCheck);$reporting=ob_get_clean();array_pop($failures);
resume_expect(strpos($reporting,'PASS')===false&&strpos($reporting,'FAIL reporting-self-check')===0,'lifecycle reporting cannot claim PASS after an assertion failure');
function resume_cleanup($dir){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $p){if($p->isLink())throw new RuntimeException('Unexpected fixture symlink');if($p->isDir())rmdir($p->getPathname());else unlink($p->getPathname());}rmdir($dir);}
resume_cleanup($root);foreach($commandDirs as $dir)resume_cleanup($dir);
echo 'Completed-payout resume checks: '.($checks-count($failures)).' PASS / '.count($failures)." FAIL\n";
foreach($failures as $failure)echo 'FAIL: '.$failure."\n";
exit($failures?1:0);
