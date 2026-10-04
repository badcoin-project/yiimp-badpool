<?php
class BadpoolRoundGate
{
    public static function eligible($candidate)
    {
        if (!isset($candidate['attribution_version']) || (int)$candidate['attribution_version']===1) return true;
        return (int)$candidate['attribution_version']===2 && isset($candidate['seal_state']) && $candidate['seal_state']==='SEALED' && !empty($candidate['round_id']);
    }
    public static function sql($alias='C')
    {
        if(!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/',$alias)) throw new InvalidArgumentException('invalid candidate alias');
        return "($alias.attribution_version=1 OR ($alias.attribution_version=2 AND $alias.seal_state='SEALED' AND EXISTS (SELECT 1 FROM share_rounds RG JOIN round_intents RI ON RI.round_id=RG.id WHERE RG.id=$alias.round_id AND RG.state='SEALED' AND RG.block_id=$alias.block_id AND RI.state='RESOLVED' AND RI.block_id=$alias.block_id)))";
    }
}
