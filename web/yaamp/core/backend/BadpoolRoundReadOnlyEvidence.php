<?php
require_once dirname(__FILE__).'/BadpoolRoundStatus.php';
/** Adapter must bind to the intent's recorded daemon identity; no mutating method is offered. */
interface BadpoolRoundEvidenceReader { public function read($daemonIdentity,$method,$params,$timeoutSeconds); }
class BadpoolRoundHttpEvidenceReader implements BadpoolRoundEvidenceReader
{
    private $authorization;
    public function __construct($user,$password) { $this->authorization=base64_encode($user.':'.$password); }
    public function read($daemonIdentity,$method,$params,$timeoutSeconds)
    {
        if(!in_array($method,array('getblockheader','getblockchaininfo','getchaintips'),true) ||
            !preg_match('~^https?://[A-Za-z0-9_.-]+:[0-9]{1,5}$~',$daemonIdentity)) throw new InvalidArgumentException('read-only endpoint/method required');
        $id='badpool-readonly-evidence';
        $context=stream_context_create(array('http'=>array('method'=>'POST','timeout'=>min(2,max(1,$timeoutSeconds)),
            'follow_location'=>0,'header'=>"Content-Type: application/json\r\nAuthorization: Basic ".$this->authorization."\r\n",
            'content'=>json_encode(array('id'=>$id,'method'=>$method,'params'=>$params)))));
        $body=@file_get_contents($daemonIdentity,false,$context,0,65537);
        if($body===false || strlen($body)>65536) throw new RuntimeException('read-only evidence unavailable');
        $reply=json_decode($body,true);
        if(!is_array($reply) || !isset($reply['id']) || $reply['id']!==$id || !array_key_exists('error',$reply) || !array_key_exists('result',$reply))
            throw new RuntimeException('inconclusive read-only envelope');
        return $reply['error']===null?$reply['result']:null;
    }
}
class BadpoolRoundReadOnlyEvidence
{
    private $reader;
    public function __construct(BadpoolRoundEvidenceReader $reader) { $this->reader=$reader; }
    public function inspect($intent)
    {
        if(empty($intent['daemon_identity']) || empty($intent['blockhash']) || !preg_match('/^[0-9a-f]{64}$/',$intent['blockhash']))
            return array('read_only'=>true,'original'=>BadpoolRoundStatus::evidence($intent),'checks'=>array(array('classification'=>'MISSING')));
        $checks=array();
        foreach(array('getblockheader','getblockchaininfo','getchaintips') as $method) {
            try {
                $value=$this->reader->read($intent['daemon_identity'],$method,$method==='getblockheader'?array($intent['blockhash']):array(),2);
                $checks[]=array('method'=>$method,'classification'=>$value===null?'MISSING':'CURRENT_STATE_ONLY','evidence'=>$value);
            } catch(Exception $e) { $checks[]=array('method'=>$method,'classification'=>'INCONCLUSIVE'); }
        }
        return array('read_only'=>true,'original'=>BadpoolRoundStatus::evidence($intent),'checks'=>$checks,
            'automatic_resolution_rule'=>'only durably matched original single-attempt response; current state never resolves');
    }
}
