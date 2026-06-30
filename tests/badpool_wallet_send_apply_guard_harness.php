<?php
$root = dirname(__DIR__);
$command = file_get_contents($root.'/web/yaamp/commands/BadpoolGuardCommand.php');
$rpc = file_get_contents($root.'/web/yaamp/core/rpc/wallet-rpc.php');
$failures = array();
function expect_contains($label,$haystack,$needle,&$failures){ if(strpos($haystack,$needle)===false) $failures[]="$label: missing $needle"; }
function expect_not_contains($label,$haystack,$needle,&$failures){ if(strpos($haystack,$needle)!==false) $failures[]="$label: forbidden $needle"; }
function section_between($s,$a,$b){ $start=strpos($s,$a); if($start===false) return ''; $end=strpos($s,$b,$start+1); if($end===false) $end=strlen($s); return substr($s,$start,$end-$start); }
$apply = section_between($command, 'private function walletSendApplyReport', 'private function walletSendDryrunReport');
$context = section_between($command, 'private function walletSendApplyContextArgs', 'private function guardedApplyContextArgs');
$approvalForIds = section_between($command, 'private function walletSendApprovalPackageForIds', 'private function walletSendApplyReport');
$rpcCoin = section_between($command, 'private function walletSendApplyRpcCoin', 'private function walletSendDryrunReport');
expect_contains('action registered', $command, "'wallet-send-apply'", $failures);
expect_contains('help documents apply', $command, 'badpoolguard wallet-send-apply --coin-id=<id> --selected-payout-ids=<csv>', $failures);
expect_contains('dedicated context helper used', $command, '$actionArgs = $this->walletSendApplyContextArgs($args);', $failures);
expect_not_contains('wallet-send-apply must not use generic guarded context', section_between($command, 'if ($action === \'wallet-send-apply\')', 'elseif ($action ==='), '$this->guardedApplyContextArgs($args)', $failures);
foreach(array('coin-id','format','selected-payout-ids') as $opt) expect_contains('context preserves '.$opt, $context, $opt, $failures);
expect_not_contains('context excludes operator confirmation', $context, 'operator-confirms-wallet-send', $failures);
foreach(array('selected-payout-ids','approval-package-checksum','row-inventory-checksum','destination-plan-checksum','projected-total','projected-total-checksum','wallet-send-total','wallet-send-total-checksum','wallet-send-destination-plan-checksum','operator-confirms-wallet-send') as $opt) expect_contains('requires '.$opt, $apply, $opt, $failures);
expect_contains('json only', $apply, 'wallet-send-apply supports --format=json only.', $failures);
expect_contains('broad scope refused', $apply, 'refuses broad/all-coin scope', $failures);
expect_contains('empty payout list refused', $apply, 'refuses empty or missing --selected-payout-ids', $failures);
expect_contains('sorted explicit csv', $apply, 'Selected payout IDs must be explicit sorted CSV', $failures);
expect_contains('operator confirmation exact', $apply, 'selected_payout_rows_', $failures);
expect_contains('full approval package recompute helper', $apply, 'walletSendApprovalPackageForIds($ids)', $failures);
expect_contains('full approval package includes checksum', $approvalForIds, 'walletSendApprovalPackageReport()', $failures);
expect_contains('approval checksum verified', $apply, "'approval-package-checksum'=>'approval_package_checksum", $failures);
expect_contains('wallet send total checksum verified', $apply, "'wallet-send-total-checksum'=>'wallet_send_total_checksum", $failures);
expect_contains('wallet send destination plan checksum verified', $apply, "'wallet-send-destination-plan-checksum'=>'wallet_send_destination_plan_checksum", $failures);
expect_contains('wallet send total value verified', $apply, "wallet_send_total_mismatch", $failures);
expect_not_contains('approval checksum must not use read-only builder only', $apply, 'walletSendBuildReadOnlyPackage(true)', $failures);
expect_contains('rpc helper used', $apply, 'walletSendApplyRpcCoin', $failures);
expect_not_contains('no direct guard coin for WalletRPC', $apply, 'new WalletRPC((object)$this->guard->getCoin())', $failures);
foreach(array('rpcencoding','rpcuser','rpcpasswd','rpchost','rpcport','account','hasgetinfo','master_wallet') as $field) expect_contains('rpc helper selects '.$field, $rpcCoin, $field, $failures);
expect_contains('duplicate recipient helper present', $apply, 'walletSendApplyDuplicateRecipient', $failures);
expect_contains('duplicate recipient refusal reason', $apply, 'duplicate_recipient_destination_refused', $failures);
$dupPos = strpos($apply, 'walletSendApplyDuplicateRecipient'); $mapPos = strpos($apply, 'walletSendApplyDestinationMap'); $sendGuardPos = strpos($apply, 'badpoolGuardedSendmanyApply');
if(!($dupPos !== false && $mapPos !== false && $sendGuardPos !== false && $dupPos < $mapPos && $dupPos < $sendGuardPos)) $failures[]='duplicate recipient guard must run before destination map and wallet send';
expect_contains('apply uses wallet_send_destination_plan', $apply, "arraySafeVal(\$approval, 'wallet_send_destination_plan', array())", $failures);
expect_contains('destination map uses plan amount', $command, "\$d[(string)\$row['recipient']] = (string)\$row['amount']", $failures);
expect_contains('send after guards', $apply, 'badpoolGuardedSendmanyApply', $failures);
expect_contains('completion after send', $apply, 'walletSendApplyMarkCompleted($ids, $txid', $failures);
expect_contains('post-send checksum recheck', $apply, 'post-send row_inventory_checksum mismatch before completion update', $failures);
expect_contains('update guards idcoin', $apply, 'idcoin=:idcoin', $failures);
expect_contains('update guards account id', $apply, 'account_id=:account_id', $failures);
expect_contains('update guards amount', $apply, 'amount=:amount', $failures);
expect_contains('failure no send flag', $command, "'wallet_rpc_send_performed']=false", $failures);
expect_contains('db transaction', $apply, 'beginTransaction()', $failures);
expect_contains('critical reconciliation', $apply, 'manual_reconciliation_required', $failures);
foreach(array('BackendPayments','BackendCoinPayments','PayoutCommand','startService(','stopService(','restartService(','DELETE FROM shares','DELETE FROM payouts','walletpassphrase','unlock') as $bad) expect_not_contains('unsafe broad path in apply', $apply, $bad, $failures);
$sendPos = strpos($apply, 'badpoolGuardedSendmanyApply'); $confirmPos = strpos($apply, 'operator-confirms-wallet-send'); $checksumPos = strpos($apply, "'approval-package-checksum'=>'approval_package_checksum'");
if(!($confirmPos !== false && $checksumPos !== false && $sendPos !== false && $confirmPos < $sendPos && $checksumPos < $sendPos)) $failures[]='sendmany reachable before confirmation/checksum guards';
function wallet_send_apply_decimal_add_harness($a,$b){ $ap=explode('.',$a,2); $bp=explode('.',$b,2); $af=isset($ap[1])?$ap[1]:''; $bf=isset($bp[1])?$bp[1]:''; $scale=max(strlen($af),strlen($bf)); $ad=ltrim($ap[0].str_pad($af,$scale,'0'),'0'); $bd=ltrim($bp[0].str_pad($bf,$scale,'0'),'0'); if($ad==='')$ad='0'; if($bd==='')$bd='0'; $carry=0; $out=''; for($i=strlen($ad)-1,$j=strlen($bd)-1;$i>=0||$j>=0||$carry;$i--,$j--){ $sum=$carry+($i>=0?ord($ad[$i])-48:0)+($j>=0?ord($bd[$j])-48:0); $out=chr(48+$sum%10).$out; $carry=intdiv($sum,10); } if($scale){ if(strlen($out)<=$scale)$out=str_pad($out,$scale+1,'0',STR_PAD_LEFT); $out=rtrim(rtrim(substr($out,0,-$scale).'.'.substr($out,-$scale),'0'),'.'); } $out=ltrim($out,'0'); return $out===''?'0':($out[0]==='.'?'0'.$out:$out); }
if(wallet_send_apply_decimal_add_harness('209676.33670359','0.29446342') !== '209676.63116701') $failures[]='projected exact decimal addition fixture failed';
expect_contains('wallet rpc primitive documented', $command, 'WalletRPC::badpoolGuardedSendmanyApply(sendmany)', $failures);
expect_contains('wallet rpc precise atomic conversion', $rpc, 'badpoolDecimalToAtomicString', $failures);
expect_not_contains('no float in guarded primitive', section_between($rpc, 'function badpoolGuardedSendmanyApply', 'function __call'), '(double)', $failures);
if($failures){ echo "Badpool wallet-send apply guard harness FAILED\n"; foreach($failures as $f) echo " - $f\n"; exit(1); }
echo "Badpool wallet-send apply guard harness passed\n";
