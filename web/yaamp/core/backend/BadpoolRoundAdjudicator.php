<?php
/** Explicit accounting adjudication. Never calls a daemon or a wallet. */
class BadpoolRoundAdjudicator
{
    private $db;
    public function __construct(PDO $db) { $this->db=$db; }
    public function resolve($identity,$actor,$treatment,$reason,$evidence)
    {
        foreach(array('id','coin_id','round_id') as $field) if(empty($identity[$field]) || !preg_match('/^[1-9][0-9]{0,18}$/',(string)$identity[$field]))
            throw new InvalidArgumentException('exact intent, coin and round identity required');
        if(empty($identity['algo']) || !in_array($identity['algo'],array('scrypt','yescrypt','skein','sha256','badcoin-groestl'),true) ||
            empty($identity['blockhash']) || !preg_match('/^[0-9a-f]{64}$/',$identity['blockhash'])) throw new InvalidArgumentException('exact lane/hash required');
        if(!is_string($actor) || trim($actor)==='' || strlen($actor)>128 || !is_string($reason) || trim($reason)==='' ||
            !is_string($evidence) || trim($evidence)==='' || strlen($reason)>8192 || strlen($evidence)>8192 ||
            !in_array($treatment,array('ACCEPT','REJECT'),true)) throw new InvalidArgumentException('actor, treatment, reason and evidence required');
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare('SELECT pending_intent FROM round_lanes WHERE coin_id=? AND algo=? FOR UPDATE');
            $q->execute(array($identity['coin_id'],$identity['algo']));
            if((string)$q->fetchColumn()!==(string)$identity['id']) throw new RuntimeException('ordering dependency mismatch');
            $q=$this->db->prepare("SELECT id FROM round_intents WHERE id=? AND coin_id=? AND algo=? AND round_id=? AND blockhash=? AND state='AMBIGUOUS_HOLD' AND response_captured_at IS NULL AND daemon_outcome IS NULL FOR UPDATE");
            $q->execute(array($identity['id'],$identity['coin_id'],$identity['algo'],$identity['round_id'],$identity['blockhash']));
            if(!$q->fetchColumn()) throw new RuntimeException('exact held intent required');
            $q=$this->db->prepare("INSERT INTO round_resolution_audit(intent_id,actor,treatment,reason,evidence,prior_state,created_at) VALUES(?,?,?,?,?,'AMBIGUOUS_HOLD',UTC_TIMESTAMP(6))");
            $q->execute(array($identity['id'],$actor,$treatment,$reason,$evidence));
            $q=$this->db->prepare('UPDATE round_intents SET accounting_outcome=?,resolution_source=?,resolution_evidence=? WHERE id=?');
            $q->execute(array($treatment==='ACCEPT'?'ACCEPTED':'REJECTED','OPERATOR_ADJUDICATED_'.$treatment,$evidence,$identity['id']));
            $q=$this->db->prepare('CALL round_resolve(?)'); $q->execute(array($identity['id'])); $q->closeCursor();
            $this->db->commit();
        } catch(Exception $e) { if($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
}
