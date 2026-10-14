<?php
class CConsoleCommand {}
function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}
require_once(dirname(__DIR__).'/web/yaamp/commands/BadpoolGuardCommand.php');

$fail=array();
function phase6_ok($value,$message){global $fail;if(!$value)$fail[]=$message;}
function phase6_call($object,$name,$args=array()){$m=new ReflectionMethod($object,$name);return $m->invokeArgs($object,$args);}
class Phase6Guard {
	public $balance='4541.6627178399995';
	public function isAllCoinsPreview(){return false;}
	public function getScope(){return array('coin_id'=>1267,'coin'=>array('id'=>1267,'symbol'=>'BAD','algo'=>'scrypt'));}
	public function getFormat(){return 'json';}
	public function tableExists($table){return true;}
	public function columnExists($table,$column){return true;}
	public function coinWhere($alias,$field){return array('sql'=>'A.coinid=:coin_id','params'=>array(':coin_id'=>1267));}
	public function selectAll($sql,$params){return array(array('account_id'=>79,'username'=>null,'coin_id'=>1267,'coin_symbol'=>'BAD','coin_algo'=>'scrypt','current_balance'=>$this->balance,'payout_min'=>'0','txfee'=>'0','threshold'=>'0.00010000','projected_payout_amount'=>$this->balance,'projected_remaining_balance'=>'0','above_threshold'=>1));}
	public function selectRow($sql,$params){return array('account_id'=>79,'coinid'=>1267,'balance'=>$this->balance);}
	public function baseReport($status='ok'){return array('status'=>$status,'scope'=>$this->getScope(),'summary'=>array(),'items'=>array(),'errors'=>array(),'warnings'=>array());}
	public function finalizeReport($report){return $report;}
	public function addWarning($message){}
}
class Phase6Tx {public $active=true;public $rolledBack=false;public function rollback(){$this->active=false;$this->rolledBack=true;}public function commit(){$this->active=false;}}
class Phase6DbCommand {
	private $db;private $sql;
	public function __construct($db,$sql){$this->db=$db;$this->sql=$sql;}
	public function execute($params){$this->db->statements[]=array($this->sql,$params);if(strpos($this->sql,'INSERT INTO payouts')===0)return 1;if(strpos($this->sql,'UPDATE accounts')===0)return $this->db->balanceChanged?0:1;return 0;}
	public function queryRow($fetch,$params){return array('id'=>9001);}
}
class Phase6Db {public $balanceChanged=false;public $statements=array();public $tx;public function createCommand($sql){return new Phase6DbCommand($this,$sql);}public function getLastInsertID(){return 9001;}public function beginTransaction(){$this->tx=new Phase6Tx();return $this->tx;}}
class Phase6App {public $db;public function __construct(){$this->db=new Phase6Db();}}
$phase6App=new Phase6App();
function app(){global $phase6App;return $phase6App;}

$guard=new Phase6Guard();$command=new BadpoolGuardCommand();$property=new ReflectionProperty($command,'guard');$property->setValue($command,$guard);
$old=$guard->balance='4541.662717839999';$retained=phase6_call($command,'payoutRowApprovalPackageReport');
$guard->balance='4541.6627178399995';$fresh=phase6_call($command,'payoutRowApprovalPackageReport');
$item=$fresh['items']['selected_accounts'][0];
phase6_ok(phase6_call($command,'payoutCoinAmount',array('0.999999995'))==='1.00000000','eight-decimal carry rounding failed');
phase6_ok(phase6_call($command,'payoutCoinAmount',array('1.234567894'))==='1.23456789','eight-decimal truncation boundary failed');
phase6_ok($item['current_balance']==='4541.6627178399995','raw account snapshot was rounded');
phase6_ok($item['projected_payout_row_amount']==='4541.66271784'&&$item['projected_account_debit_amount']==='4541.66271784','coin amount did not round to eight decimals');
phase6_ok($retained['approval_package_checksum']['value']!==$fresh['approval_package_checksum']['value'],'raw balance drift did not change approval checksum');
$args=array('--coin-id=1267','--selected-account-ids=79','--approval-package-checksum='.$retained['approval_package_checksum']['value'],'--selected-scope-checksum='.$retained['selected_scope_checksum']['value'],'--projected-payout-row-checksum='.$retained['projected_payout_row_checksum']['value'],'--projected-account-debit-checksum='.$retained['projected_account_debit_checksum']['value'],'--operator-confirms-payout-row-creation=scrypt_balance_to_payout_rows_no_wallet_send','--format=json');
$stale=phase6_call($command,'payoutRowApplyReport',array($args));
phase6_ok($stale['status']==='refused'&&$stale['abort_reason']==='checksum_mismatch','stale approval did not fail closed');
phase6_ok($phase6App->db->tx===null&&$phase6App->db->statements===array(),'stale approval reached a mutation');
$args[2]='--approval-package-checksum='.$fresh['approval_package_checksum']['value'];$args[3]='--selected-scope-checksum='.$fresh['selected_scope_checksum']['value'];$args[4]='--projected-payout-row-checksum='.$fresh['projected_payout_row_checksum']['value'];$args[5]='--projected-account-debit-checksum='.$fresh['projected_account_debit_checksum']['value'];
$phase6App->db->balanceChanged=true;$changed=phase6_call($command,'payoutRowApplyReport',array($args));
phase6_ok($changed['status']==='refused'&&$changed['abort_reason']==='mutation_failed_rolled_back','concurrent balance change was not refused');
phase6_ok($phase6App->db->tx->rolledBack&&$changed['payout_rows_created']===false,'concurrent change did not roll back inserted row');
$updates=array_values(array_filter($phase6App->db->statements,function($entry){return strpos($entry[0],'UPDATE accounts')===0;}));
phase6_ok(count($updates)===1&&$updates[0][1][':old_balance']==='4541.6627178399995'&&$updates[0][1][':new_balance']==='0','exact raw balance guard or zero debit changed');
phase6_ok(strpos($updates[0][0],'CAST(balance AS CHAR)=:old_balance_text')!==false,'text-exact concurrency guard missing');

class Phase6RecoveryAdapter {
	public $calls=array();public $approvedSha=null;
	private function call($phase,$ledger,$extra=array()){$this->calls[]=$phase;$path=$ledger['run_directory'].'/phase-'.$phase.'.json';file_put_contents($path,json_encode(array('phase'=>$phase,'status'=>'pass'))."\n");return array_merge(array('status'=>'pass','report_path'=>$path,'checksums'=>array('phase_'.$phase.'_sha256'=>hash_file('sha256',$path))),$extra);}
	public function safetyCheck($l,$o){return $this->call(0,$l);}
	public function selectEligibleWork($l,$o){return $this->call(1,$l,array('selected_coin_scope'=>array(array('id'=>1267,'algo'=>'scrypt')),'selected_earning_ids'=>array(20144,20145),'selected_block_ids'=>array(33792,33793)));}
	public function packageMaturity($l,$o){return $this->call(2,$l);}
	public function applyMaturity($l,$o){return $this->call(3,$l);}
	public function paymentDelayCheck($l,$o){return $this->call(4,$l);}
	public function creditAccounts($l,$o){return $this->call(5,$l,array('selected_account_ids'=>array(79),'selected_accounts_by_coin'=>array('1267'=>array('account_ids'=>array(79)))));}
	public function preparePayoutRows($l,$o){$this->calls[]=6;if(!isset($o['phase6_approval_sha256'])||$o['phase6_approval_sha256']!==$this->approvedSha)return array('status'=>'hold');return array('status'=>'pass','created_payout_ids'=>array(9001),'payout_rows_inserted'=>1);}
	public function regeneratePayoutApproval($l,$o){$this->calls[]='approval';$path=$l['run_directory'].'/phase6-reviewed.json';file_put_contents($path,"{\"account_id\":79,\"amount\":\"4541.66271784\"}\n");$this->approvedSha=hash_file('sha256',$path);return array('status'=>'pass','package_path'=>$path,'package_sha256'=>$this->approvedSha,'selected_account_ids'=>array(79),'selected_accounts_by_coin'=>array('1267'=>array('account_ids'=>array(79))));}
	public function inspectCreatedPayoutRows($ids){return array();}
}
$root=sys_get_temp_dir().'/badpool-phase6-precision-'.getmypid();$adapter=new Phase6RecoveryAdapter();$runner=new BadpoolPaymentBatchRunner($adapter,$root);
$options=array('mode'=>'auto','scope'=>'all-active-payout-coins','only'=>'scrypt','batch_size'=>2,'coordinator_owner'=>BadpoolLivePaymentLaneRegistry::scryptCompatibility()->ownershipEnvelope());
$held=$runner->run($options);$before=$adapter->calls;
phase6_ok($held['batch_state']==='HOLD'&&$before===array(0,1,2,3,4,5,6),'fixture did not reach owned phase-6 HOLD');
$options['resume_batch_id']=$held['batch_id'];$unapproved=$runner->run($options);
phase6_ok($unapproved['status']==='refused'&&$adapter->calls===$before,'unapproved phase-6 resume invoked a financial phase');
$phase5Path=$held['run_directory'].'/phase-5.json';$phase5Original=file_get_contents($phase5Path);file_put_contents($phase5Path,"tampered\n");$options['phase6_approval_only']='1';$tampered=$runner->run($options);unset($options['phase6_approval_only']);file_put_contents($phase5Path,$phase5Original);
phase6_ok($tampered['status']==='refused'&&$adapter->calls===$before,'tampered prior credit evidence allowed approval regeneration');
$options['phase6_approval_only']='1';$review=$runner->run($options);$afterReview=$adapter->calls;
phase6_ok($review['approval_only']===true&&$review['batch_state']==='HOLD'&&$afterReview===array(0,1,2,3,4,5,6,'approval'),'approval regeneration applied payout or repeated credit');
phase6_ok(is_file($review['package_path'])&&hash_file('sha256',$review['package_path'])===$review['package_sha256'],'review artifact was not retained');
unset($options['phase6_approval_only']);$options['phase6_approval_sha256']=$review['package_sha256'];$ready=$runner->run($options);
phase6_ok($ready['batch_state']==='READY_FOR_WALLET_APPROVAL'&&$adapter->calls===array(0,1,2,3,4,5,6,'approval',6),'phase-6 resume replayed earlier phases or failed to stop at wallet approval');
$ledger=json_decode(file_get_contents($ready['ledger_path']),true);phase6_ok(count($ledger['phase6_recovery_approvals'])===1&&$ledger['phase6_recovery_approvals'][0]['package_sha256']===$review['package_sha256'],'durable approval registration was lost');

class Phase6AdapterGuard {public function selectAll($sql,$params){return array();}}
class Phase6AdapterExecutor {
	public $calls=array();
	public function run($command,$args){$this->calls[]=$command;if($command==='payout-row-approval-package')return array('status'=>'pass','items'=>array('selected_accounts'=>array(array('account_id'=>79,'account_coinid'=>1267,'current_balance'=>'4541.6627178399995','projected_payout_row_amount'=>'4541.66271784'))),'approval_package_checksum'=>array('value'=>str_repeat('a',64)),'apply_command_shape'=>array('badpoolguard','payout-row-apply','--selected-account-ids=79','--approval-package-checksum=<approval_package_checksum>','--format=json'));if($command==='payout-row-apply')return array('status'=>'pass','created_payout_ids'=>array(9001),'payout_rows_inserted'=>1,'db_mutations'=>true);return array('status'=>'hold');}
}
$adapterDir=$root.'/adapter-only';mkdir($adapterDir,0770,true);file_put_contents($adapterDir.'/payout-row-packages.json',"original retained approval\n");
$adapterLedger=array('batch_id'=>'20261004T072007Z-d73ffc159420','run_directory'=>$adapterDir,'selected_coin_scope'=>array(array('id'=>1267,'algo'=>'scrypt')),'selected_account_ids'=>array(79),'selected_accounts_by_coin'=>array('1267'=>array('account_ids'=>array(79))),'phase_results'=>array(),'checksums'=>array());
$executor=new Phase6AdapterExecutor();$realAdapter=new BadpoolPaymentBatchPhaseAdapter(new Phase6AdapterGuard(),array($executor,'run'));
$generated=$realAdapter->regeneratePayoutApproval($adapterLedger,array());$sha=$generated['package_sha256'];
phase6_ok($generated['status']==='pass'&&file_get_contents($adapterDir.'/payout-row-packages.json')==="original retained approval\n"&&$executor->calls===array('payout-row-approval-package'),'regeneration overwrote original approval or applied payout');
$adapterLedger['phase6_recovery_approvals']=array(array('package_path'=>$generated['package_path'],'package_sha256'=>$sha));
$bad=$realAdapter->preparePayoutRows($adapterLedger,array('phase6_approval_sha256'=>str_repeat('b',64)));
phase6_ok($bad['status']==='hold'&&$executor->calls===array('payout-row-approval-package'),'unregistered approval SHA reached guarded apply');
$applied=$realAdapter->preparePayoutRows($adapterLedger,array('phase6_approval_sha256'=>$sha));
phase6_ok($applied['status']==='pass'&&$applied['created_payout_ids']===array(9001)&&$executor->calls===array('payout-row-approval-package','payout-row-apply'),'registered recovery approval did not apply exactly once');
phase6_ok(count(glob($adapterDir.'/payout-row-apply-attempt-committed-*.json'))===1&&!file_exists($adapterDir.'/payout-row-apply-attempt.json'),'committed attempt evidence was not retained');
phase6_ok(count($applied['mutation_scope']['payout_apply_attempt_paths'])===1&&is_file($applied['mutation_scope']['payout_apply_attempt_paths'][0]),'committed attempt path was not returned as durable phase evidence');
if($fail){echo "Phase-6 precision recovery harness FAILED\n";foreach($fail as $f)echo " - $f\n";exit(1);}echo "Phase-6 precision recovery harness passed\n";
