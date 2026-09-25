<?php
$failures = array();
function durable_ok($condition, $message) { global $failures; if (!$condition) $failures[] = $message; }

class AcceptedBlockStore
{
	public $blocks = array();
	public $candidates = array();
	public $attributions = array();
	public $observations = array();
	public $nextId = 1;
	public $failPersistence = false;

	public function accept($coin, $dbAlgo, $operationalAlgo, $height, $canonicalHash, $powHash, $userid, $workerid)
	{
		if ($this->failPersistence) return array('durable'=>false, 'retained'=>true);
		$key = $coin.'|'.$dbAlgo.'|'.$canonicalHash;
		if (isset($this->blocks[$key])) return array('durable'=>true, 'duplicate'=>true, 'id'=>$this->blocks[$key]['id']);
		$id = $this->nextId++;
		$this->blocks[$key] = array('id'=>$id, 'coin_id'=>$coin, 'db_algo'=>$dbAlgo,
			'operational_algo'=>$operationalAlgo, 'height'=>$height, 'blockhash'=>$canonicalHash,
			'userid'=>$userid, 'workerid'=>$workerid, 'category'=>'new');
		$this->candidates[$id] = array('block_id'=>$id, 'coin_id'=>$coin, 'algo'=>$dbAlgo, 'blockhash'=>$canonicalHash);
		$this->attributions[$id] = array('userid'=>$userid, 'workerid'=>$workerid);
		$this->observations[$id] = array('canonical_blockhash'=>$canonicalHash, 'pow_hash'=>$powHash);
		return array('durable'=>true, 'duplicate'=>false, 'id'=>$id);
	}
}

$lanes = array(
	array(1267, 'scrypt', 'scrypt'),
	array(1266, 'yescrypt', 'yescrypt'),
	array(1268, 'skein', 'skein'),
	array(1269, 'badcoin-groestl', 'groestl'),
	array(1270, 'sha256', 'sha256d'),
);
$store = new AcceptedBlockStore;
foreach ($lanes as $n=>$lane) {
	list($coin, $dbAlgo, $operationalAlgo) = $lane;
	$chain = str_pad(dechex(1000+$n), 64, '0', STR_PAD_LEFT);
	$pow = str_pad(dechex(2000+$n), 64, '0', STR_PAD_LEFT);
	$result = $store->accept($coin, $dbAlgo, $operationalAlgo, 9000, $chain, $pow, 70+$n, 170+$n);
	durable_ok($result['durable'] === true, $operationalAlgo.' accepted block was not durable');
	$row = $store->blocks[$coin.'|'.$dbAlgo.'|'.$chain];
	durable_ok($row['db_algo'] === $dbAlgo && $row['operational_algo'] === $operationalAlgo, $operationalAlgo.' algorithm identities collapsed');
	durable_ok($row['blockhash'] === $chain && $store->observations[$result['id']]['pow_hash'] === $pow, $operationalAlgo.' hash identities collapsed');
	durable_ok($row['userid'] === 70+$n && $row['workerid'] === 170+$n, $operationalAlgo.' finder attribution lost');
}

$chain = str_repeat('a', 64); $pow = str_repeat('b', 64);
$first = $store->accept(1267, 'scrypt', 'scrypt', 9100, $chain, $pow, 79, 179);
$again = $store->accept(1267, 'scrypt', 'scrypt', 9100, $chain, $pow, 99, 199);
durable_ok($again['duplicate'] === true && $again['id'] === $first['id'], 're-observed accepted block duplicated');
durable_ok(count($store->candidates) === 6, 'repeated blocknotify/observation duplicated candidate state');
durable_ok($store->attributions[$first['id']]['userid'] === 79 && $store->attributions[$first['id']]['workerid'] === 179, 'duplicate changed finder attribution');

$otherAlgo = $store->accept(1267, 'yescrypt', 'yescrypt', 9100, $chain, $pow, 80, 180);
durable_ok(!$otherAlgo['duplicate'], 'same height/hash on another algorithm collided');
$otherHash = $store->accept(1267, 'scrypt', 'scrypt', 9100, str_repeat('c', 64), str_repeat('d', 64), 81, 181);
durable_ok(!$otherHash['duplicate'], 'different canonical hash silently collided');

$before = count($store->blocks);
$rejectedRpcWasAccepted = false;
if ($rejectedRpcWasAccepted) $store->accept(1267, 'scrypt', 'scrypt', 9200, str_repeat('e',64), str_repeat('f',64), 82, 182);
durable_ok(count($store->blocks) === $before, 'rejected RPC submission created durable state');
$store->failPersistence = true;
$failed = $store->accept(1267, 'scrypt', 'scrypt', 9300, str_repeat('1',64), str_repeat('2',64), 83, 183);
durable_ok($failed['durable'] === false && $failed['retained'] === true, 'persistence failure did not fail closed');

$share = file_get_contents(__DIR__.'/../stratum/share.cpp');
$submit = file_get_contents(__DIR__.'/../stratum/client_submit.cpp');
durable_ok(strpos($share, 'if(!block->durable && !block_persist_accepted(db, block))') !== false, 'durability still depends on confirmation');
durable_ok(strpos($share, 'CommonLock(&g_db_mutex)') !== false && strpos($share, 'block_persist_accepted(g_db, block)') !== false, 'accepted submit does not attempt synchronous durable capture');
durable_ok(strpos($share, 'strcmp(g_stratum_algo, "decred")') !== false, 'later-identity Decred path lost its confirmation gate');
durable_ok(strpos($share, 'timeout_discard_allowed=false') !== false, 'failed persistence can be timeout-discarded');
durable_ok(strpos($share, 'durable=true discard_state=in_memory_only') !== false, 'timeout does not distinguish durable state');
durable_ok(strpos($share, "B.coin_id=%d AND B.algo='%s' AND B.blockhash='%s'") !== false, 'durable identity is not coin/algo/canonical-hash scoped');
durable_ok(strpos($share, 'ORDER BY B.id LIMIT 2 FOR UPDATE') !== false, 'duplicate lookup is not locked or ambiguity-bounded');
durable_ok(strpos($share, 'strcpy(block->hash, h1)') !== false, 'canonical chain hash is not initialized from header identity');
durable_ok(strpos($share, 'block->hash2') !== false && strpos($share, 'pow_hash=%s') !== false, 'PoW identity telemetry missing');
durable_ok(strpos($share, 'block->userid') !== false && strpos($share, 'block->workerid') !== false, 'finder identity missing from persistence');
durable_ok(strpos($share, 'BLOCK_DURABILITY_DUPLICATE') !== false, 'duplicate telemetry missing');
durable_ok(strpos($share, 'BLOCK_CHAIN_CONFIRMED') !== false, 'later confirmation telemetry missing');
durable_ok(strpos($submit, 'if(b)') !== false && strpos($submit, 'bool durable = block_add(', strpos($submit, 'if(b)')) !== false, 'accepted submission no longer performs durable capture');
durable_ok(strpos($submit, 'durable? "accepted_submit": "block_prune"') !== false, 'accepted-path versus retry ownership is not observable');
durable_ok(strpos($share, 'INSERT INTO payouts') === false && strpos($share, 'sendmany') === false, 'durability path reaches payout or wallet send');

if ($failures) {
	echo "Accepted block durability harness FAILED\n";
	foreach ($failures as $failure) echo " - $failure\n";
	exit(1);
}
echo "Accepted block durability harness PASSED\n";
