#!/usr/bin/env php
<?php
require_once dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolRoundStatus.php';
require_once dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolRoundReadOnlyEvidence.php';
$options=getopt('',array('login','json','intent:','events-after:','limit:','daemon-evidence'));
$login=isset($options['login']);
try {
    $limit=isset($options['limit'])?(int)$options['limit']:25;
    if ($limit<1 || $limit>100) throw new InvalidArgumentException('limit must be 1..100');
    foreach(array('intent','events-after') as $name) if(isset($options[$name]) && !preg_match('/^[0-9]{1,18}$/',$options[$name]))
        throw new InvalidArgumentException('invalid identity');
    if(isset($options['daemon-evidence']) && (!isset($options['intent']) || $login)) throw new InvalidArgumentException('daemon evidence requires one intent and cannot run during login');
    $db=new PDO(getenv('BADPOOL_ROUND_DSN'),getenv('BADPOOL_ROUND_DB_USER'),getenv('BADPOOL_ROUND_DB_PASSWORD'),
        array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>2));
    // A dedicated SELECT-only account is required. This transaction adds a
    // database-enforced read-only boundary; no external mutation interfaces exist.
    $maria=strpos($db->getAttribute(PDO::ATTR_SERVER_VERSION),'MariaDB')!==false;
    $db->exec($maria?'SET SESSION max_statement_time=2':'SET SESSION max_execution_time=2000');
    $db->exec('START TRANSACTION READ ONLY');
    if(isset($options['events-after'])) {
        $q=$db->prepare('SELECT id,intent_id,event_type,occurred_at,payload FROM round_operator_events WHERE id>:after ORDER BY id LIMIT '.$limit);
        $q->execute(array(':after'=>$options['events-after']));
        $events=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach($events as &$event) $event['payload']=json_decode($event['payload'],true);
        echo json_encode(array('schema'=>'badpool.round-events.v2','read_only'=>true,'events'=>$events),JSON_PRETTY_PRINT)."\n";
    } else {
        $where="(I.state='AMBIGUOUS_HOLD' OR (I.state='INTENT_PENDING' AND I.dispatched_at<UTC_TIMESTAMP(6)-INTERVAL 60 SECOND))";
        $params=array();
        if(isset($options['intent'])) { $where.=' AND I.id=:intent'; $params[':intent']=$options['intent']; }
        $q=$db->prepare("SELECT COUNT(*) count,MIN(COALESCE(I.hold_since,I.dispatched_at)) oldest,GROUP_CONCAT(DISTINCT CONCAT(I.coin_id,':',I.algo) ORDER BY I.coin_id,I.algo) lanes FROM round_intents I WHERE ".$where);
        $q->execute($params); $summary=$q->fetch(PDO::FETCH_ASSOC);
        $summary['lanes']=empty($summary['lanes'])?array():explode(',',$summary['lanes']);
        $q=$db->prepare("SELECT I.id,I.coin_id,I.algo,I.round_id,I.height,I.blockhash,I.cutoff,I.dispatch_state,I.dispatched_at,I.state,
            COALESCE(I.hold_since,I.dispatched_at) hold_since,COALESCE(I.ambiguity_reason,'expired_possible_dispatch') ambiguity_reason,
            I.daemon_outcome,I.accounting_outcome,I.original_response,I.response_captured_at,I.daemon_identity,I.continuation_round,R.state dependent_round_status,
            (SELECT E.id FROM round_operator_events E WHERE E.intent_id=I.id AND E.event_type='HOLD_ENTERED') hold_event_id
            FROM round_intents I JOIN share_rounds R ON R.id=I.continuation_round WHERE ".$where.' ORDER BY hold_since,I.id LIMIT '.$limit);
        $q->execute($params); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $db->exec('ROLLBACK'); // Release the read-only snapshot before bounded RPC investigation.
        if(isset($options['daemon-evidence'])) {
            $checker=new BadpoolRoundReadOnlyEvidence(new BadpoolRoundHttpEvidenceReader(getenv('BADPOOL_ROUND_RPC_USER'),getenv('BADPOOL_ROUND_RPC_PASSWORD')));
            foreach($rows as &$item) $item['daemon_evidence']=$checker->inspect($item);
            unset($item);
        }
        $report=BadpoolRoundStatus::report($rows,$summary);
        echo isset($options['json'])?json_encode($report,JSON_PRETTY_PRINT)."\n":BadpoolRoundStatus::human($report,$login);
    }
    $db->exec('ROLLBACK');
} catch(Exception $e) {
    if ($login) echo "BADPOOL ROUND STATUS UNAVAILABLE\nUnresolved HOLD status could not be checked.\nRun: badpool-round-status --json\n";
    else fwrite(STDERR,"BadPool round status unavailable (read-only connection or query failed).\n");
    exit($login?0:1);
}
