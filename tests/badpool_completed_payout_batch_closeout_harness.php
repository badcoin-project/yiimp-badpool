<?php
class CConsoleCommand {}
function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}
require_once(dirname(__DIR__).'/web/yaamp/commands/BadpoolGuardCommand.php');

class CompletedPayoutFixtureAdapter {
	public $rows=array(); public $calls=0;
	public function inspectCreatedPayoutRows($ids){$this->calls++;return $this->rows;}
}
function closeout_expect($condition,$message,&$failures){if(!$condition)$failures[]=$message;}
function closeout_ledger($root,$id,$overrides=array()){
	$dir=$root.'/'.$id;mkdir($dir,0770,true);
	$ledger=array_merge(array('batch_id'=>$id,'batch_state'=>'READY_FOR_WALLET_APPROVAL','current_phase'=>6,'created_payout_ids'=>array(520)),$overrides);
	file_put_contents($dir.'/ledger.json',json_encode($ledger));return $dir;
}
function closeout_valid_proof($overrides=array()){return array_merge(array('status'=>'ok','final_classification'=>'PASS / WALLET PROOF CLOSEOUT COMPLETE','selected_payout_ids'=>array(520),'scope'=>array('coin_id'=>1267),'payout_inventory'=>array(array('payout_id'=>520,'coin_id'=>1267)),'closeout_valid'=>true,'wallet_lookup_success'=>true,'wallet_txid_expected'=>true,'wallet_amount_matches_expected'=>true,'wallet_confirmations_present'=>true,'wallet_sends'=>false,'db_mutations'=>false),$overrides);}

$failures=array();$root=sys_get_temp_dir().'/badpool-completed-closeout-'.getmypid();
$dir=closeout_ledger($root,'20260821T040144Z-092a351fa22a');
$adapter=new CompletedPayoutFixtureAdapter();
$adapter->rows=array(array('id'=>520,'idcoin'=>1267,'completed'=>1,'tx'=>'458bf4f254e09a4de4572febd15171f4700e3a6d9c68fc64a856f25cfa95229e'));
$proofCalls=array();$proof=function($coin,$ids)use(&$proofCalls){$proofCalls[]=array($coin,$ids);return closeout_valid_proof();};
$ledgerChecksum=hash_file('sha256',$dir.'/ledger.json');
$report=(new BadpoolCompletedPayoutBatchCloseout($adapter,$proof,$root))->preview('20260821T040144Z-092a351fa22a');
closeout_expect($report['status']==='pass'&&$report['completed_payout_reconciliation']===true,'520 fixture did not pass completed-payout reconciliation',$failures);
closeout_expect($report['wallet_reads']==='proof_only'&&$report['wallet_sends']===false&&$report['db_mutations']===false,'closeout was not proof-only',$failures);
closeout_expect($report['next_safe_lane_or_STOP']==='STOP','valid proof did not stop',$failures);
closeout_expect($report['do_not_rerun']===array('wallet-send-apply','payout-row-apply','account-credit-apply'),'apply rerun prohibitions changed',$failures);
closeout_expect($report['ledger_only_apply_mode_available']===false&&hash_file('sha256',$dir.'/ledger.json')===$ledgerChecksum,'preview exposed an apply mode or mutated the ledger',$failures);
closeout_expect($proofCalls===array(array(1267,array(520))),'completed payout did not route exclusively to wallet proof',$failures);
closeout_expect(strpos(json_encode($proofCalls),'wallet-send')===false,'proof-valid payout emitted wallet-send command',$failures);

// A normal fresh payout stays at the existing wallet approval boundary.  This
// lane refuses it before any wallet RPC, rather than converting it to closeout.
$pending='fresh-unpaid';closeout_ledger($root,$pending);$adapter->rows=array(array('id'=>520,'idcoin'=>1267,'completed'=>0,'tx'=>''));$before=count($proofCalls);
$pendingReport=(new BadpoolCompletedPayoutBatchCloseout($adapter,$proof,$root))->preview($pending);
closeout_expect($pendingReport['status']==='hold'&&in_array('created_payout_not_completed',$pendingReport['errors'],true),'pending payout did not remain wallet-approval/send preparation work',$failures);
closeout_expect(count($proofCalls)===$before,'pending payout invoked wallet proof/send work',$failures);

$missingTx='missing-tx';closeout_ledger($root,$missingTx);$adapter->rows=array(array('id'=>520,'idcoin'=>1267,'completed'=>1,'tx'=>''));
closeout_expect(in_array('created_payout_tx_missing',(new BadpoolCompletedPayoutBatchCloseout($adapter,$proof,$root))->preview($missingTx)['errors'],true),'empty payout tx was accepted',$failures);
$badProof='bad-proof';closeout_ledger($root,$badProof);$adapter->rows=array(array('id'=>520,'idcoin'=>1267,'completed'=>1,'tx'=>'dbtx'));
$invalidProof=function(){return closeout_valid_proof(array('wallet_amount_matches_expected'=>false));};
closeout_expect(in_array('wallet_proof_missing_or_invalid',(new BadpoolCompletedPayoutBatchCloseout($adapter,$invalidProof,$root))->preview($badProof)['errors'],true),'amount-invalid wallet proof was accepted',$failures);
$sendReport='unexpected-send-report';$sendDir=closeout_ledger($root,$sendReport);file_put_contents($sendDir.'/wallet-send-apply-report.json','{}');
$unexpected=(new BadpoolCompletedPayoutBatchCloseout($adapter,$proof,$root))->preview($sendReport);
closeout_expect($unexpected['wallet_send_apply_report_exists']===true&&in_array('wallet_send_apply_report_exists_unexpectedly',$unexpected['errors'],true),'wallet send apply report was not refused',$failures);
$sendLedgerFlag='unexpected-send-ledger-flag';closeout_ledger($root,$sendLedgerFlag,array('wallet_send_apply_report_exists'=>true));
$unexpectedFlag=(new BadpoolCompletedPayoutBatchCloseout($adapter,$proof,$root))->preview($sendLedgerFlag);
closeout_expect($unexpectedFlag['wallet_send_apply_report_exists']===true&&in_array('wallet_send_apply_report_exists_unexpectedly',$unexpectedFlag['errors'],true),'wallet send apply ledger flag was not refused',$failures);
$ambiguous='ambiguous';closeout_ledger($root,$ambiguous,array('created_payout_ids'=>array(520,520)));
closeout_expect(in_array('created_payout_ids_missing_or_ambiguous',(new BadpoolCompletedPayoutBatchCloseout($adapter,$proof,$root))->preview($ambiguous)['errors'],true),'ambiguous payout IDs were accepted',$failures);

// Proof reports must bind back to the exact requested payout inventory and
// must independently attest that neither wallet nor database writes occurred.
foreach(array(
	'different-payout'=>array('selected_payout_ids'=>array(519)),
	'missing-payout'=>array('selected_payout_ids'=>array()),
	'extra-payout'=>array('selected_payout_ids'=>array(520,521)),
) as $case=>$overrides){
	$id='proof-'.$case;closeout_ledger($root,$id);$adapter->rows=array(array('id'=>520,'idcoin'=>1267,'completed'=>1,'tx'=>'dbtx'));
	$caseProof=function()use($overrides){return closeout_valid_proof($overrides);};
	$caseReport=(new BadpoolCompletedPayoutBatchCloseout($adapter,$caseProof,$root))->preview($id);
	closeout_expect($caseReport['status']==='hold'&&in_array('wallet_proof_payout_ids_mismatch',$caseReport['errors'],true),$case.' proof inventory was accepted',$failures);
}
$wrongCoin=function(){return closeout_valid_proof(array('scope'=>array('coin_id'=>1268),'payout_inventory'=>array(array('payout_id'=>520,'coin_id'=>1268))));};
$wrongCoinReport=(new BadpoolCompletedPayoutBatchCloseout($adapter,$wrongCoin,$root))->preview('proof-extra-payout');
closeout_expect(in_array('wallet_proof_coin_id_mismatch',$wrongCoinReport['errors'],true),'wrong-coin proof was accepted',$failures);
foreach(array('wallet_sends','db_mutations') as $unsafeFlag){
	$unsafeProof=function()use($unsafeFlag){return closeout_valid_proof(array($unsafeFlag=>true));};
	$unsafe=(new BadpoolCompletedPayoutBatchCloseout($adapter,$unsafeProof,$root))->preview('proof-extra-payout');
	closeout_expect($unsafe['status']==='hold'&&in_array('wallet_proof_report_not_read_only',$unsafe['errors'],true),$unsafeFlag.'=true proof was accepted',$failures);
}
foreach(array('closeout_valid','wallet_lookup_success','wallet_txid_expected','wallet_amount_matches_expected','wallet_confirmations_present') as $requiredFlag){
	$falseProof=function()use($requiredFlag){return closeout_valid_proof(array($requiredFlag=>false));};
	$falseReport=(new BadpoolCompletedPayoutBatchCloseout($adapter,$falseProof,$root))->preview('proof-extra-payout');
	closeout_expect(in_array('wallet_proof_missing_or_invalid',$falseReport['errors'],true),$requiredFlag.'=false proof was accepted',$failures);
}

// Exercise the real command/context parser. A syntactically valid, nonexistent
// batch reaches the closeout report and produces its ledger HOLD, not a context
// refusal. Invalid option shapes remain parser refusals.
function closeout_command($args,&$rc){$command=new BadpoolGuardCommand();ob_start();$rc=$command->run(array_merge(array('completed-payout-batch-closeout'),$args));return json_decode(ob_get_clean(),true);}
$reached=closeout_command(array('--batch-id=parser-fixture-not-present','--format=json'),$parserRc);
closeout_expect($parserRc===0&&$reached['command']==='completed-payout-batch-closeout'&&$reached['errors']===array('batch_ledger_missing_or_invalid'),'--batch-id did not reach closeout report path',$failures);
foreach(array(
	'missing'=>array('--format=json'),
	'malformed'=>array('--batch-id=bad/id','--format=json'),
	'duplicate'=>array('--batch-id=one','--batch-id=two','--format=json'),
	'unknown'=>array('--batch-id=one','--unknown-option=x','--format=json'),
) as $case=>$args){$refused=closeout_command($args,$refusedRc);closeout_expect($refusedRc===2&&$refused['status']==='refused',$case.' command option was not refused',$failures);}

$command=file_get_contents(dirname(__DIR__).'/web/yaamp/commands/BadpoolGuardCommand.php');
foreach(array("'completed-payout-batch-closeout'",'case \'completed-payout-batch-closeout\':','completed-payout-batch-closeout --batch-id=<id> --format=json') as $needle)closeout_expect(strpos($command,$needle)!==false,'command wiring missing '.$needle,$failures);
if($failures){echo "Badpool completed-payout batch closeout harness FAILED\n";foreach($failures as $failure)echo " - $failure\n";exit(1);}echo "Badpool completed-payout batch closeout harness passed\n";
