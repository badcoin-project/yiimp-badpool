-- L3.194: source migration only. No historical backfill and no lane activation.
-- Requires all participating tables to use InnoDB and coordinated Stratum cutover.
CREATE TABLE round_lanes (
 coin_id INT UNSIGNED NOT NULL, algo VARCHAR(32) NOT NULL,
 current_round BIGINT UNSIGNED NULL, pending_intent BIGINT UNSIGNED NULL,
 next_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY (coin_id,algo)
) ENGINE=InnoDB;
CREATE TABLE share_rounds (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, coin_id INT UNSIGNED NOT NULL,
 algo VARCHAR(32) NOT NULL, state ENUM('OPEN','INTENT_PENDING','AMBIGUOUS_HOLD','SEALED','MERGED') NOT NULL,
 created_at DATETIME(6) NOT NULL, cutoff BIGINT UNSIGNED NULL,
 merged_into BIGINT UNSIGNED NULL, block_id BIGINT UNSIGNED NULL,
 PRIMARY KEY(id), UNIQUE KEY one_block(block_id), KEY lane_state(coin_id,algo,state),
 FOREIGN KEY(coin_id,algo) REFERENCES round_lanes(coin_id,algo),
 FOREIGN KEY(merged_into) REFERENCES share_rounds(id)
) ENGINE=InnoDB;
CREATE TABLE accepted_work (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, coin_id INT UNSIGNED NOT NULL, algo VARCHAR(32) NOT NULL,
 work_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 header_hex CHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 round_id BIGINT UNSIGNED NOT NULL, original_round_id BIGINT UNSIGNED NOT NULL,
 sequence BIGINT UNSIGNED NOT NULL, userid INT UNSIGNED NOT NULL, workerid INT UNSIGNED NOT NULL,
 assigned_difficulty DOUBLE NOT NULL, no_fees TINYINT NOT NULL, donation DOUBLE NOT NULL,
 state ENUM('OWNED_BY_ROUND','ATTRIBUTED') NOT NULL, accepted_at DATETIME(6) NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY replay_identity(coin_id,algo,work_hash),
 UNIQUE KEY lane_order(coin_id,algo,sequence), KEY round_order(round_id,sequence),
 FOREIGN KEY(round_id) REFERENCES share_rounds(id), FOREIGN KEY(original_round_id) REFERENCES share_rounds(id)
) ENGINE=InnoDB;
CREATE TABLE round_intents (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, coin_id INT UNSIGNED NOT NULL, algo VARCHAR(32) NOT NULL,
 round_id BIGINT UNSIGNED NOT NULL, continuation_round BIGINT UNSIGNED NOT NULL,
 cutoff BIGINT UNSIGNED NOT NULL, work_id BIGINT UNSIGNED NOT NULL,
 blockhash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, height INT UNSIGNED NOT NULL,
 block_hex LONGTEXT NOT NULL, block_difficulty DOUBLE NOT NULL, winning_difficulty DOUBLE NOT NULL,
 segwit TINYINT NOT NULL, created_at DATETIME(6) NOT NULL,
 dispatch_state ENUM('NEVER_DISPATCHED','MAY_HAVE_DISPATCHED') NOT NULL DEFAULT 'NEVER_DISPATCHED',
 dispatched_at DATETIME(6) NULL, state ENUM('PREPARED','INTENT_PENDING','AMBIGUOUS_HOLD','RESOLVED') NOT NULL,
 daemon_identity VARCHAR(255) NULL,
 daemon_outcome ENUM('ACCEPTED','REJECTED') NULL,
 accounting_outcome ENUM('ACCEPTED','REJECTED') NULL,
 original_response MEDIUMTEXT NULL, response_captured_at DATETIME(6) NULL,
 hold_since DATETIME(6) NULL, ambiguity_reason VARCHAR(128) NULL,
 resolution_source VARCHAR(64) NULL, resolution_evidence MEDIUMTEXT NULL,
 resolved_at DATETIME(6) NULL, block_id BIGINT UNSIGNED NULL,
 PRIMARY KEY(id), UNIQUE KEY one_round(round_id), UNIQUE KEY one_candidate(coin_id,algo,blockhash),
 UNIQUE KEY one_binding(block_id), KEY held(state,hold_since,id), KEY dispatch_age(state,dispatched_at,id),
 FOREIGN KEY(round_id) REFERENCES share_rounds(id), FOREIGN KEY(continuation_round) REFERENCES share_rounds(id),
 FOREIGN KEY(work_id) REFERENCES accepted_work(id)
) ENGINE=InnoDB;
CREATE TABLE round_resolution_audit (
 intent_id BIGINT UNSIGNED NOT NULL, actor VARCHAR(128) NOT NULL, treatment ENUM('ACCEPT','REJECT') NOT NULL,
 reason TEXT NOT NULL, evidence TEXT NOT NULL, prior_state VARCHAR(32) NOT NULL,
 created_at DATETIME(6) NOT NULL, PRIMARY KEY(intent_id), FOREIGN KEY(intent_id) REFERENCES round_intents(id)
) ENGINE=InnoDB;
CREATE TABLE round_operator_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, intent_id BIGINT UNSIGNED NOT NULL,
 event_type ENUM('HOLD_ENTERED','HOLD_RESOLVED') NOT NULL,
 occurred_at DATETIME(6) NOT NULL, payload TEXT NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY once_per_transition(intent_id,event_type),
 FOREIGN KEY(intent_id) REFERENCES round_intents(id)
) ENGINE=InnoDB;
ALTER TABLE round_lanes ADD FOREIGN KEY(current_round) REFERENCES share_rounds(id),
 ADD FOREIGN KEY(pending_intent) REFERENCES round_intents(id);
-- Existing downstream rows keep their original values and default legacy version.
ALTER TABLE live_block_candidates ADD attribution_version INT NOT NULL DEFAULT 1,
 ADD round_id BIGINT UNSIGNED NULL, ADD seal_state VARCHAR(16) NOT NULL DEFAULT 'LEGACY',
 ADD UNIQUE KEY one_round_attribution(round_id);
ALTER TABLE shares ADD round_id BIGINT UNSIGNED NULL, ADD KEY operational_round(round_id);

DELIMITER $$
-- Caller-owned transaction: lane lock is the only cross-process linearization authority.
CREATE PROCEDURE round_lock(IN c INT UNSIGNED, IN a VARCHAR(32))
BEGIN
 DECLARE r BIGINT UNSIGNED;
 IF (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND engine='InnoDB'
   AND table_name IN ('blocks','accounts','coins','earnings','live_block_candidates','live_block_attributions'))<>6 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='transactional downstream tables required before activation';
 END IF;
 -- ON DUPLICATE KEY takes an exclusive lane lock directly. INSERT IGNORE
 -- would take a shared duplicate-key lock and risk an upgrade deadlock.
 INSERT INTO round_lanes(coin_id,algo) VALUES(c,a) ON DUPLICATE KEY UPDATE coin_id=VALUES(coin_id);
 SELECT current_round INTO r FROM round_lanes WHERE coin_id=c AND algo=a FOR UPDATE;
 IF r IS NULL THEN
  INSERT INTO share_rounds(coin_id,algo,state,created_at) VALUES(c,a,'OPEN',UTC_TIMESTAMP(6));
  UPDATE round_lanes SET current_round=LAST_INSERT_ID() WHERE coin_id=c AND algo=a;
 END IF;
END$$
CREATE PROCEDURE round_hold(IN i BIGINT UNSIGNED, IN why VARCHAR(128))
BEGIN
 DECLARE changed INT;
 DECLARE c INT UNSIGNED; DECLARE a VARCHAR(32); DECLARE r BIGINT UNSIGNED;
 SELECT coin_id,algo INTO c,a FROM round_intents WHERE id=i;
 SELECT current_round INTO r FROM round_lanes WHERE coin_id=c AND algo=a FOR UPDATE;
 UPDATE round_intents SET state='AMBIGUOUS_HOLD',hold_since=UTC_TIMESTAMP(6),ambiguity_reason=why
 WHERE id=i AND state='INTENT_PENDING' AND dispatch_state='MAY_HAVE_DISPATCHED';
 SET changed=ROW_COUNT();
 IF changed=1 THEN
  UPDATE share_rounds R JOIN round_intents I ON I.round_id=R.id SET R.state='AMBIGUOUS_HOLD' WHERE I.id=i;
  INSERT INTO round_operator_events(intent_id,event_type,occurred_at,payload)
  SELECT id,'HOLD_ENTERED',UTC_TIMESTAMP(6),JSON_OBJECT('schema','badpool.round-hold.v2',
   'priority','HIGH',
   'coin_id',coin_id,'db_algo',algo,'round_id',round_id,'intent_id',id,'height',height,
   'blockhash',blockhash,'cutoff',cutoff,'hold_since',hold_since,'reason',ambiguity_reason,
   'downstream','BLOCKED','inspection','badpool-round-status --json') FROM round_intents WHERE id=i;
 END IF;
END$$
-- Resolve only under the lane lock. A saved original result or immutable operator audit is mandatory.
CREATE PROCEDURE round_resolve(IN i BIGINT UNSIGNED)
BEGIN
 DECLARE c INT UNSIGNED; DECLARE a VARCHAR(32); DECLARE r BIGINT UNSIGNED;
 DECLARE following BIGINT UNSIGNED; DECLARE b BIGINT UNSIGNED; DECLARE pending BIGINT UNSIGNED;
 DECLARE outcome VARCHAR(16); DECLARE source VARCHAR(64); DECLARE prior VARCHAR(32); DECLARE captured DATETIME(6);
 SELECT coin_id,algo,round_id,continuation_round INTO c,a,r,following FROM round_intents WHERE id=i;
 SELECT pending_intent INTO pending FROM round_lanes WHERE coin_id=c AND algo=a FOR UPDATE;
 SELECT COALESCE(accounting_outcome,daemon_outcome),resolution_source,state,response_captured_at INTO outcome,source,prior,captured FROM round_intents WHERE id=i FOR UPDATE;
 IF prior IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='missing intent'; END IF;
 IF prior<>'RESOLVED' THEN
  IF pending IS NULL OR pending<>i OR outcome IS NULL OR source IS NULL THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='unresolved outcome or ordering dependency';
  END IF;
  IF source='DAEMON_ORIGINAL_RESPONSE' THEN
   IF captured IS NULL OR NOT EXISTS(SELECT 1 FROM round_intents WHERE id=i AND daemon_identity IS NOT NULL
    AND original_response IS NOT NULL AND JSON_VALID(original_response) AND dispatch_state='MAY_HAVE_DISPATCHED'
    AND JSON_UNQUOTE(JSON_EXTRACT(original_response,'$.id'))=CONCAT('badpool-round-',id,'-attempt-1')
    AND JSON_TYPE(JSON_EXTRACT(original_response,'$.error'))='NULL'
    AND ((outcome='ACCEPTED' AND JSON_TYPE(JSON_EXTRACT(original_response,'$.result'))='NULL') OR
     (outcome='REJECTED' AND JSON_TYPE(JSON_EXTRACT(original_response,'$.result'))='STRING'
      AND JSON_UNQUOTE(JSON_EXTRACT(original_response,'$.result'))<>''
      AND JSON_UNQUOTE(JSON_EXTRACT(original_response,'$.result')) NOT LIKE '%duplicate%'
      AND JSON_UNQUOTE(JSON_EXTRACT(original_response,'$.result')) NOT LIKE '%inconclusive%'))) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='missing original response';
   END IF;
  ELSEIF source IN ('OPERATOR_ADJUDICATED_ACCEPT','OPERATOR_ADJUDICATED_REJECT') THEN
   IF prior<>'AMBIGUOUS_HOLD' OR captured IS NOT NULL OR NOT EXISTS(SELECT 1 FROM round_resolution_audit WHERE intent_id=i AND prior_state='AMBIGUOUS_HOLD'
      AND ((treatment='ACCEPT' AND outcome='ACCEPTED') OR (treatment='REJECT' AND outcome='REJECTED'))) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='missing adjudication audit';
   END IF;
  ELSE
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='unsupported outcome provenance';
  END IF;
  IF outcome='REJECTED' THEN
   -- Only unsealed work moves, atomically, after a conclusive rejection. Original identity remains.
   UPDATE accepted_work SET round_id=following WHERE round_id=r AND state='OWNED_BY_ROUND';
   UPDATE share_rounds SET state='MERGED',merged_into=following WHERE id=r;
  ELSE
   IF NOT EXISTS(SELECT 1 FROM accepted_work WHERE round_id=r AND state='OWNED_BY_ROUND') OR
      EXISTS(SELECT 1 FROM accepted_work W JOIN round_intents I ON I.id=i WHERE W.round_id=r AND W.sequence>I.cutoff) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='incomplete round ownership';
   END IF;
   IF EXISTS(SELECT 1 FROM blocks B JOIN round_intents I ON I.id=i WHERE B.coin_id=I.coin_id AND B.algo=I.algo AND B.blockhash=I.blockhash) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='candidate conflicts with existing block';
   END IF;
   INSERT INTO blocks(height,blockhash,coin_id,userid,workerid,category,difficulty,difficulty_user,time,algo,segwit)
   SELECT I.height,I.blockhash,I.coin_id,W.userid,W.workerid,'new',I.block_difficulty,I.winning_difficulty,
    UNIX_TIMESTAMP(I.created_at),I.algo,I.segwit FROM round_intents I JOIN accepted_work W ON W.id=I.work_id WHERE I.id=i;
   SET b=LAST_INSERT_ID();
   INSERT INTO live_block_candidates(block_id,coin_id,blockhash,algo,found_time,price,share_floor_id,share_ceiling_id,attribution_version,round_id,seal_state)
   SELECT b,I.coin_id,I.blockhash,I.algo,UNIX_TIMESTAMP(I.created_at),CO.price,0,I.cutoff,2,r,'SEALED'
    FROM round_intents I JOIN coins CO ON CO.id=I.coin_id WHERE I.id=i;
   IF ROW_COUNT()<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='missing coin'; END IF;
   -- Fee/donation snapshot at journal acceptance. Never use winning hash difficulty as weight.
   INSERT INTO live_block_attributions(block_id,userid,difficulty,no_fees,donation)
   SELECT b,W.userid,SUM(W.assigned_difficulty),IFNULL(A.no_fees,0),IFNULL(A.donation,0)
    FROM accepted_work W JOIN accounts A ON A.id=W.userid WHERE W.round_id=r
    GROUP BY W.userid,IFNULL(A.no_fees,0),IFNULL(A.donation,0);
   IF (SELECT COUNT(DISTINCT userid) FROM accepted_work WHERE round_id=r) <> ROW_COUNT() THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='missing attribution account';
   END IF;
   UPDATE share_rounds SET state='SEALED',block_id=b WHERE id=r;
   UPDATE accepted_work SET state='ATTRIBUTED' WHERE round_id=r;
   UPDATE round_intents SET block_id=b WHERE id=i;
  END IF;
  UPDATE round_intents SET state='RESOLVED',resolved_at=UTC_TIMESTAMP(6) WHERE id=i;
  UPDATE round_lanes SET pending_intent=NULL WHERE coin_id=c AND algo=a;
  IF prior='AMBIGUOUS_HOLD' THEN
   INSERT INTO round_operator_events(intent_id,event_type,occurred_at,payload)
   SELECT id,'HOLD_RESOLVED',UTC_TIMESTAMP(6),JSON_OBJECT('schema','badpool.round-resolution.v2',
    'intent_id',id,'coin_id',coin_id,'db_algo',algo,'round_id',round_id,'blockhash',blockhash,
    'source',resolution_source,'evidence',resolution_evidence,'held_seconds',TIMESTAMPDIFF(SECOND,hold_since,resolved_at))
    FROM round_intents WHERE id=i;
  END IF;
 END IF;
END$$
-- Audit records and emitted events are append-only, even to application SQL callers.
CREATE TRIGGER round_audit_validate BEFORE INSERT ON round_resolution_audit FOR EACH ROW
BEGIN
 IF TRIM(NEW.actor)='' OR TRIM(NEW.reason)='' OR TRIM(NEW.evidence)='' OR NEW.prior_state<>'AMBIGUOUS_HOLD' OR
  NOT EXISTS(SELECT 1 FROM round_intents WHERE id=NEW.intent_id AND state='AMBIGUOUS_HOLD' AND response_captured_at IS NULL AND daemon_outcome IS NULL) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='complete held identity and audit required';
 END IF;
END$$
CREATE TRIGGER round_audit_no_update BEFORE UPDATE ON round_resolution_audit FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable audit'; END$$
CREATE TRIGGER round_audit_no_delete BEFORE DELETE ON round_resolution_audit FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable audit'; END$$
CREATE TRIGGER round_event_no_update BEFORE UPDATE ON round_operator_events FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable event'; END$$
CREATE TRIGGER round_event_no_delete BEFORE DELETE ON round_operator_events FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable event'; END$$
CREATE TRIGGER round_work_guard BEFORE UPDATE ON accepted_work FOR EACH ROW
BEGIN
 IF OLD.state='ATTRIBUTED' OR NEW.coin_id<>OLD.coin_id OR NEW.algo<>OLD.algo OR NEW.work_hash<>OLD.work_hash OR
  NEW.header_hex<>OLD.header_hex OR NEW.original_round_id<>OLD.original_round_id OR NEW.sequence<>OLD.sequence OR
  NEW.userid<>OLD.userid OR NEW.workerid<>OLD.workerid OR NEW.assigned_difficulty<>OLD.assigned_difficulty THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable accepted work identity or sealed ownership';
 END IF;
 IF NEW.round_id<>OLD.round_id AND NOT EXISTS(SELECT 1 FROM round_intents I WHERE I.round_id=OLD.round_id
  AND I.continuation_round=NEW.round_id AND COALESCE(I.accounting_outcome,I.daemon_outcome)='REJECTED' AND I.state IN ('INTENT_PENDING','AMBIGUOUS_HOLD')
  AND I.resolution_source IN ('DAEMON_ORIGINAL_RESPONSE','OPERATOR_ADJUDICATED_REJECT')) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='unresolved ownership cannot move';
 END IF;
 IF NEW.state='ATTRIBUTED' AND NOT EXISTS(SELECT 1 FROM share_rounds WHERE id=NEW.round_id AND state='SEALED' AND block_id IS NOT NULL) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='only sealed round can attribute work';
 END IF;
END$$
CREATE TRIGGER round_work_no_delete BEFORE DELETE ON accepted_work FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='durable journal cannot be deleted'; END$$
CREATE TRIGGER round_work_insert_guard BEFORE INSERT ON accepted_work FOR EACH ROW
BEGIN
 IF NEW.assigned_difficulty<=0 OR NEW.userid=0 OR NEW.workerid=0 OR NEW.state<>'OWNED_BY_ROUND' OR NEW.original_round_id<>NEW.round_id OR
  NOT EXISTS(SELECT 1 FROM share_rounds R JOIN round_lanes L ON L.current_round=R.id AND L.coin_id=R.coin_id AND L.algo=R.algo
   WHERE R.id=NEW.round_id AND R.coin_id=NEW.coin_id AND R.algo=NEW.algo AND R.state='OPEN' AND NEW.sequence=L.next_sequence+1) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='invalid acceptance ownership or sequence';
 END IF;
END$$
CREATE TRIGGER round_intent_guard BEFORE UPDATE ON round_intents FOR EACH ROW
BEGIN
 IF NEW.coin_id<>OLD.coin_id OR NEW.algo<>OLD.algo OR NEW.round_id<>OLD.round_id OR NEW.continuation_round<>OLD.continuation_round OR
  NEW.cutoff<>OLD.cutoff OR NEW.work_id<>OLD.work_id OR NEW.blockhash<>OLD.blockhash OR NEW.height<>OLD.height OR NEW.block_hex<>OLD.block_hex OR
  OLD.state='RESOLVED' OR (OLD.dispatch_state='MAY_HAVE_DISPATCHED' AND NEW.dispatch_state<>'MAY_HAVE_DISPATCHED') OR
  (OLD.dispatch_state='MAY_HAVE_DISPATCHED' AND NOT(NEW.daemon_identity <=> OLD.daemon_identity)) OR
  (OLD.original_response IS NOT NULL AND (NOT(NEW.original_response <=> OLD.original_response) OR
    NOT(NEW.daemon_outcome <=> OLD.daemon_outcome) OR NOT(NEW.response_captured_at <=> OLD.response_captured_at) OR
    NOT(NEW.resolution_source <=> OLD.resolution_source))) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable candidate identity or resolved outcome';
 END IF;
END$$
CREATE TRIGGER round_intent_insert_guard BEFORE INSERT ON round_intents FOR EACH ROW
BEGIN
 IF NOT EXISTS(SELECT 1 FROM share_rounds R JOIN round_lanes L ON L.current_round=R.id
  JOIN accepted_work W ON W.id=NEW.work_id AND W.round_id=R.id JOIN share_rounds N ON N.id=NEW.continuation_round
  WHERE R.id=NEW.round_id AND R.coin_id=NEW.coin_id AND R.algo=NEW.algo AND L.coin_id=NEW.coin_id AND L.algo=NEW.algo
   AND R.state='OPEN' AND L.pending_intent IS NULL AND NEW.cutoff=L.next_sequence AND W.work_hash=NEW.blockhash
   AND LOWER(LEFT(NEW.block_hex,160))=W.header_hex AND N.state='OPEN' AND N.coin_id=NEW.coin_id AND N.algo=NEW.algo) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='invalid candidate identity or cutoff ownership';
 END IF;
END$$
CREATE TRIGGER round_attribution_no_update BEFORE UPDATE ON live_block_attributions FOR EACH ROW
BEGIN
 IF EXISTS(SELECT 1 FROM live_block_candidates WHERE block_id=OLD.block_id AND attribution_version=2) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable round attribution';
 END IF;
END$$
CREATE TRIGGER round_attribution_no_append BEFORE INSERT ON live_block_attributions FOR EACH ROW
BEGIN
 IF EXISTS(SELECT 1 FROM live_block_candidates C JOIN share_rounds R ON R.id=C.round_id
  WHERE C.block_id=NEW.block_id AND C.attribution_version=2 AND R.state='SEALED') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='cannot append sealed attribution';
 END IF;
END$$
CREATE TRIGGER round_candidate_guard BEFORE UPDATE ON live_block_candidates FOR EACH ROW
BEGIN
 IF OLD.attribution_version=2 AND (NEW.attribution_version<>2 OR NEW.block_id<>OLD.block_id OR NEW.coin_id<>OLD.coin_id OR
  NEW.algo<>OLD.algo OR NEW.blockhash<>OLD.blockhash OR NOT(NEW.round_id <=> OLD.round_id) OR NEW.seal_state<>OLD.seal_state OR
  NEW.share_floor_id<>OLD.share_floor_id OR NEW.share_ceiling_id<>OLD.share_ceiling_id OR NEW.found_time<>OLD.found_time) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable sealed candidate provenance';
 END IF;
END$$
CREATE TRIGGER round_candidate_no_delete BEFORE DELETE ON live_block_candidates FOR EACH ROW
BEGIN
 IF OLD.attribution_version=2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='durable candidate cannot be deleted'; END IF;
END$$
CREATE TRIGGER round_candidate_insert_gate BEFORE INSERT ON live_block_candidates FOR EACH ROW
BEGIN
 IF NEW.attribution_version=1 AND EXISTS(SELECT 1 FROM round_lanes WHERE coin_id=NEW.coin_id AND algo=NEW.algo AND current_round IS NOT NULL) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='commissioned lane cannot create legacy attribution';
 END IF;
 IF NEW.attribution_version=2 AND NOT EXISTS(SELECT 1 FROM round_intents I WHERE I.round_id=NEW.round_id
  AND I.coin_id=NEW.coin_id AND I.algo=NEW.algo AND I.blockhash=NEW.blockhash AND I.cutoff=NEW.share_ceiling_id
  AND NEW.seal_state='SEALED' AND NEW.share_floor_id=0 AND I.state IN ('INTENT_PENDING','AMBIGUOUS_HOLD')) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='version two candidate requires exact durable intent';
 END IF;
END$$
CREATE TRIGGER round_intent_no_delete BEFORE DELETE ON round_intents FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='durable intent cannot be deleted'; END$$
CREATE TRIGGER round_block_no_delete BEFORE DELETE ON blocks FOR EACH ROW
BEGIN
 IF EXISTS(SELECT 1 FROM share_rounds WHERE block_id=OLD.id) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='sealed block binding cannot be deleted';
 END IF;
END$$
CREATE TRIGGER round_block_identity_guard BEFORE UPDATE ON blocks FOR EACH ROW
BEGIN
 IF EXISTS(SELECT 1 FROM share_rounds WHERE block_id=OLD.id) AND
  (NEW.id<>OLD.id OR NEW.coin_id<>OLD.coin_id OR NEW.algo<>OLD.algo OR NEW.blockhash<>OLD.blockhash) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='sealed block identity cannot change';
 END IF;
END$$
CREATE TRIGGER round_seal_guard BEFORE UPDATE ON share_rounds FOR EACH ROW
BEGIN
 IF OLD.state='SEALED' OR NEW.coin_id<>OLD.coin_id OR NEW.algo<>OLD.algo OR
  (OLD.cutoff IS NOT NULL AND NOT(NEW.cutoff <=> OLD.cutoff)) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable round identity or sealed ownership';
 END IF;
END$$
CREATE TRIGGER round_attribution_no_delete BEFORE DELETE ON live_block_attributions FOR EACH ROW
BEGIN
 IF EXISTS(SELECT 1 FROM live_block_candidates WHERE block_id=OLD.block_id AND attribution_version=2) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable round attribution';
 END IF;
END$$
CREATE TRIGGER round_earnings_insert_gate BEFORE INSERT ON earnings FOR EACH ROW
BEGIN
 IF EXISTS(SELECT 1 FROM live_block_candidates WHERE block_id=NEW.blockid AND attribution_version=2) AND
  NOT EXISTS(SELECT 1 FROM live_block_candidates C JOIN share_rounds R ON R.id=C.round_id JOIN round_intents I ON I.round_id=R.id
   WHERE C.block_id=NEW.blockid AND C.attribution_version=2 AND C.seal_state='SEALED' AND R.state='SEALED' AND R.block_id=C.block_id
   AND I.state='RESOLVED' AND I.block_id=C.block_id) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='non-sealed round cannot enter earnings';
 END IF;
END$$
CREATE TRIGGER round_earnings_update_gate BEFORE UPDATE ON earnings FOR EACH ROW
BEGIN
 IF EXISTS(SELECT 1 FROM live_block_candidates WHERE block_id=NEW.blockid AND attribution_version=2) AND
  NOT EXISTS(SELECT 1 FROM live_block_candidates C JOIN share_rounds R ON R.id=C.round_id JOIN round_intents I ON I.round_id=R.id
   WHERE C.block_id=NEW.blockid AND C.attribution_version=2 AND C.seal_state='SEALED' AND R.state='SEALED' AND R.block_id=C.block_id
   AND I.state='RESOLVED' AND I.block_id=C.block_id) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='non-sealed round cannot mature or credit earnings';
 END IF;
END$$
DELIMITER ;
-- Written last: a partial DDL application must never enable accepted-work capture.
CREATE TABLE round_schema_version(version INT NOT NULL PRIMARY KEY,installed_at DATETIME(6) NOT NULL) ENGINE=InnoDB;
INSERT INTO round_schema_version VALUES(2,UTC_TIMESTAMP(6));
