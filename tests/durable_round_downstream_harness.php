<?php
// Reuse the legacy bridge fixtures, then exercise the production v2 eligibility logic.
require dirname(__FILE__).'/live_capture_earnings_bridge_harness.php';
require_once dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolRoundReadOnlyEvidence.php';
$store=new BridgeStore;
$item=row(123);$item['attribution_version']=2;$item['round_id']=10;$item['seal_state']='AMBIGUOUS_HOLD';
$store->rows=array($item);$bridge=new BadpoolLiveCaptureEarningsBridge($store,function($n){return $n;});
$report=$bridge->dryrun(1267);
if($report['eligible_count']!==0 || $report['projected_earnings_rows']) throw new RuntimeException('nonsealed bridge accepted');
$store->rows[0]['seal_state']='SEALED';$report=$bridge->dryrun(1267);
if($report['eligible_count']!==1 || count($report['projected_earnings_rows'])!==2) throw new RuntimeException('sealed bridge refused');
$store->rows[0]['attributions']=array();$store->rows[0]['share_ceiling_id']=10;
$report=$bridge->dryrun(1267);
if($report['eligible_count']!==0 || $report['per_block_inventory'][0]['attribution_model']==='block_userid_single_recipient')
    throw new RuntimeException('v2 finder fallback retained');
class EvidenceProbe implements BadpoolRoundEvidenceReader {
 public $calls=array();
 public function read($identity,$method,$params,$seconds){$this->calls[]=array($method,$params,$seconds);return array('confirmations'=>-1);}
}
$reader=new EvidenceProbe;$inspect=new BadpoolRoundReadOnlyEvidence($reader);
$evidence=$inspect->inspect(array('id'=>1,'daemon_identity'=>'fixture-daemon','blockhash'=>str_repeat('a',64),'original_response'=>null));
if(count($reader->calls)!==3 || $evidence['original']!=='MISSING') throw new RuntimeException('read-only evidence bounds failed');
foreach($evidence['checks'] as $c)if($c['classification']!=='CURRENT_STATE_ONLY')throw new RuntimeException('current state became proof');
echo "PASS durable round downstream/evidence harness scenarios 27,28,31,32,57,58\n";
