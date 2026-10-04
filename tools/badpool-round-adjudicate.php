#!/usr/bin/env php
<?php
require_once dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolRoundAdjudicator.php';
$o=getopt('',array('intent:','coin:','algo:','round:','hash:','actor:','treatment:','reason:','evidence:'));
try {
    foreach(array('intent','coin','algo','round','hash','actor','treatment','reason','evidence') as $key)
        if(!isset($o[$key])) throw new InvalidArgumentException('all identity and audit arguments are required');
    $db=new PDO(getenv('BADPOOL_ROUND_DSN'),getenv('BADPOOL_ROUND_DB_USER'),getenv('BADPOOL_ROUND_DB_PASSWORD'),array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
    $service=new BadpoolRoundAdjudicator($db);
    $service->resolve(array('id'=>$o['intent'],'coin_id'=>$o['coin'],'algo'=>$o['algo'],'round_id'=>$o['round'],'blockhash'=>$o['hash']),
        $o['actor'],$o['treatment'],$o['reason'],$o['evidence']);
    echo "Audited round treatment committed. No daemon or wallet RPC was performed.\n";
} catch(Exception $e) { fwrite(STDERR,"Adjudication refused; exact held identity and complete audit are required.\n"); exit(1); }
