<?php
/** Pure read-only formatting/evidence policy; no wallet or submission interface. */
class BadpoolRoundStatus
{
    public static function evidence($intent, $current=null)
    {
        $original=isset($intent['original_response']) ? json_decode($intent['original_response'],true) : null;
        $expected='badpool-round-'.$intent['id'].'-attempt-1';
        if (is_array($original) && isset($original['id']) && $original['id']===$expected &&
            array_key_exists('error',$original) && $original['error']===null && array_key_exists('result',$original) &&
            !empty($intent['response_captured_at']) && $intent['dispatch_state']==='MAY_HAVE_DISPATCHED') {
            if ($original['result']===null && $intent['daemon_outcome']==='ACCEPTED') return 'PROVES_ORIGINAL_ACCEPTED';
            if (is_string($original['result']) && $original['result']!=='' &&
                strpos($original['result'],'duplicate')===false && strpos($original['result'],'inconclusive')===false &&
                $intent['daemon_outcome']==='REJECTED') return 'PROVES_ORIGINAL_REJECTED';
        }
        // Current presence, absence, confirmations and duplicate responses cannot
        // reconstruct the result of the original invocation.
        if ($current!==null) return 'CURRENT_STATE_ONLY';
        return empty($intent['original_response']) ? 'MISSING' : 'INCONCLUSIVE';
    }
    public static function report($rows,$summary,$now=null)
    {
        $now=$now===null?time():$now; $holds=array();
        foreach ($rows as $r) {
            $r['evidence_classification']=self::evidence($r);
            $r['downstream']='BLOCKED';
            $r['notification_state']=empty($r['hold_event_id'])?'AWAITING_HOLD_EVENT':'DURABLE_EVENT_AVAILABLE';
            // Prepared intents are definitely unsent; expired possible sends are
            // visible even if the Stratum process is down before writing HOLD.
            $r['effective_state']=$r['state']==='INTENT_PENDING'?'AMBIGUOUS_HOLD_PENDING_RECOVERY':$r['state'];
            unset($r['original_response']); // Never dump arbitrary RPC content in login/status output.
            $holds[]=$r;
        }
        return array('schema'=>'badpool.round-status.v2','read_only'=>true,'unresolved_hold_count'=>(int)$summary['count'],
            'affected_lanes'=>$summary['lanes'],'oldest_hold_age_seconds'=>empty($summary['oldest'])?null:max(0,$now-strtotime($summary['oldest'].' UTC')),
            'truncated'=>(int)$summary['count']>count($holds),'holds'=>$holds);
    }
    public static function human($report,$login=false)
    {
        $count=$report['unresolved_hold_count'];
        if (!$count) return $login?'':"BadPool: no unresolved AMBIGUOUS_HOLD candidates.\n";
        $out="BADPOOL ATTENTION REQUIRED\n".$count." unresolved AMBIGUOUS_HOLD candidate".($count===1?'':'s')."\n";
        foreach (array_slice($report['holds'],0,$login?3:100) as $r)
            $out.='Coin: '.$r['coin_id'].' Lane: '.$r['algo'].' Height: '.$r['height'].' Round: '.$r['round_id'].' Intent: '.$r['id'].' Since: '.$r['hold_since']."\n";
        if ($count>min(count($report['holds']),$login?3:100)) $out.="Additional HOLDs omitted; inspect detailed status.\n";
        return $out."Accounting for this dependency is held. This helper performed no wallet send.\nRun: badpool-round-status --json\n";
    }
}
