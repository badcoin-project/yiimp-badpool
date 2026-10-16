-- POOL-L3.199G. Apply only after 2026-09-06-live-block-accounting.sql and
-- 2026-10-04-durable-share-rounds.sql. This is a source migration, not a
-- startup repair. The migration owner/definer must retain metadata visibility;
-- the Stratum user needs EXECUTE on this procedure, never TRIGGER or DDL.
-- Hashes below are derived from the pinned migrations on MariaDB 10.11.18.
DELIMITER $$
CREATE PROCEDURE round_schema_assert() SQL SECURITY DEFINER
BEGIN
 DECLARE observed CHAR(64);
 DECLARE bad_columns INT DEFAULT 0;
 DECLARE marker_count INT DEFAULT 0;
 DECLARE marker_version INT DEFAULT 0;
 SET SESSION group_concat_max_len=1048576;

 -- Engine, name, and table kind for every durable and downstream table.
 SELECT SHA2(GROUP_CONCAT(CONCAT_WS(CHAR(31),table_name,table_type,COALESCE(engine,'<NULL>'))
  ORDER BY table_name SEPARATOR 0x1e),256) INTO observed
 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN
 ('round_lanes','share_rounds','accepted_work','round_intents','round_resolution_audit',
  'round_operator_events','round_schema_version','live_block_share_cursors','live_block_candidates',
  'live_block_attributions','shares','blocks','accounts','coins','earnings');
 IF observed IS NULL OR observed<>'51f6ed2530da129110aa408ea7c0350c074b8477ad458fb4e5a2066165c5ce44' THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='durable schema table/engine mismatch';
 END IF;

 -- Exact columns and index definitions for the migration-owned tables.
 SELECT SHA2(GROUP_CONCAT(CONCAT_WS(CHAR(31),table_name,ordinal_position,column_name,
  column_type,is_nullable,COALESCE(column_default,'<NULL>'),extra,
  COALESCE(character_set_name,'<NULL>'),COALESCE(collation_name,'<NULL>'))
  ORDER BY table_name,ordinal_position SEPARATOR 0x1e),256) INTO observed
 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name IN
 ('round_lanes','share_rounds','accepted_work','round_intents','round_resolution_audit',
  'round_operator_events','round_schema_version','live_block_share_cursors','live_block_candidates','live_block_attributions');
 IF observed IS NULL OR observed<>'b94b0a2b27c111d28d43a5cfcba62c0682e8a02c169f37792d833a342419d930' THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='durable schema owned-column mismatch';
 END IF;

 SELECT SHA2(GROUP_CONCAT(CONCAT_WS(CHAR(31),table_name,index_name,non_unique,seq_in_index,
  column_name,COALESCE(sub_part,'<NULL>'),COALESCE(collation,'<NULL>'),index_type)
  ORDER BY table_name,index_name,seq_in_index SEPARATOR 0x1e),256) INTO observed
 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name IN
 ('round_lanes','share_rounds','accepted_work','round_intents','round_resolution_audit',
  'round_operator_events','round_schema_version','live_block_share_cursors','live_block_candidates','live_block_attributions');
 IF observed IS NULL OR observed<>'fe2f1afe9c9992d5269e2a4501fb513b3e737392e8eeb5a242c88e6037db0234' THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='durable schema owned-index mismatch';
 END IF;

 SELECT SHA2(GROUP_CONCAT(CONCAT_WS(CHAR(31),table_name,constraint_name,column_name,
  ordinal_position,referenced_table_name,referenced_column_name)
  ORDER BY table_name,constraint_name,ordinal_position SEPARATOR 0x1e),256) INTO observed
 FROM information_schema.key_column_usage WHERE table_schema=DATABASE()
  AND referenced_table_name IS NOT NULL AND table_name IN
 ('round_lanes','share_rounds','accepted_work','round_intents','round_resolution_audit','round_operator_events');
 IF observed IS NULL OR observed<>'a7921dc9a6fb07956077e8603a985b803d07bff913105910f3ef3876f5b8f5bf' THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='durable schema foreign-key mismatch';
 END IF;

 SELECT SHA2(GROUP_CONCAT(CONCAT_WS(CHAR(31),table_name,constraint_name,
  referenced_table_name,match_option,update_rule,delete_rule)
  ORDER BY table_name,constraint_name SEPARATOR 0x1e),256) INTO observed
 FROM information_schema.referential_constraints WHERE constraint_schema=DATABASE() AND table_name IN
 ('round_lanes','share_rounds','accepted_work','round_intents','round_resolution_audit','round_operator_events');
 IF observed IS NULL OR observed<>'a3efe57ad038cd06f53228f2c7803142a2f81b94c98975bae551bc680272ed47' THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='durable schema foreign-key action mismatch';
 END IF;

 -- Only a compatible subset of legacy downstream columns is required; the
 -- old baseline and the small disposable fixture have different widths.
 SELECT COUNT(*) INTO bad_columns FROM (
  SELECT 'accounts' t,'id' c,'integer' k,0 minimum UNION ALL
  SELECT 'accounts','no_fees','number',0 UNION ALL
  SELECT 'accounts','donation','number',0 UNION ALL
  SELECT 'coins','id','integer',0 UNION ALL
  SELECT 'coins','price','number',0 UNION ALL
  SELECT 'blocks','id','integer',0 UNION ALL
  SELECT 'blocks','height','integer',0 UNION ALL
  SELECT 'blocks','blockhash','text',64 UNION ALL
  SELECT 'blocks','coin_id','integer',0 UNION ALL
  SELECT 'blocks','userid','integer',0 UNION ALL
  SELECT 'blocks','workerid','integer',0 UNION ALL
  SELECT 'blocks','category','text',3 UNION ALL
  SELECT 'blocks','difficulty','number',0 UNION ALL
  SELECT 'blocks','difficulty_user','number',0 UNION ALL
  SELECT 'blocks','time','integer',0 UNION ALL
  SELECT 'blocks','algo','text',6 UNION ALL
  SELECT 'blocks','segwit','integer',0 UNION ALL
  SELECT 'earnings','id','integer',0 UNION ALL
  SELECT 'earnings','blockid','integer',0 UNION ALL
  SELECT 'earnings','userid','integer',0 UNION ALL
  SELECT 'shares','id','integer',0 UNION ALL
  SELECT 'shares','userid','integer',0 UNION ALL
  SELECT 'shares','workerid','integer',0 UNION ALL
  SELECT 'shares','coinid','integer',0 UNION ALL
  SELECT 'shares','jobid','integer',0 UNION ALL
  SELECT 'shares','pid','integer',0 UNION ALL
  SELECT 'shares','valid','integer',0 UNION ALL
  SELECT 'shares','extranonce1','integer',0 UNION ALL
  SELECT 'shares','difficulty','number',0 UNION ALL
  SELECT 'shares','share_diff','number',0 UNION ALL
  SELECT 'shares','time','integer',0 UNION ALL
  SELECT 'shares','algo','text',6 UNION ALL
  SELECT 'shares','error','integer',0 UNION ALL
  SELECT 'shares','round_id','integer',0
 ) required_columns LEFT JOIN information_schema.columns actual
  ON actual.table_schema=DATABASE() AND actual.table_name=required_columns.t
  AND actual.column_name=required_columns.c
 WHERE actual.column_name IS NULL OR
  (required_columns.k='integer' AND actual.data_type NOT IN ('tinyint','smallint','mediumint','int','bigint')) OR
  (required_columns.k='number' AND actual.data_type NOT IN ('tinyint','smallint','mediumint','int','bigint','float','double','decimal')) OR
  (required_columns.k='text' AND (actual.data_type NOT IN ('char','varchar','text','mediumtext','longtext') OR
   COALESCE(actual.character_maximum_length,0)<required_columns.minimum));
 IF bad_columns<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='durable schema downstream-column mismatch'; END IF;

 -- Migrated downstream indexes and the base primary keys used for ownership.
 IF (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE()
      AND ((table_name='shares' AND index_name='operational_round' AND seq_in_index=1 AND column_name='round_id')
       OR (table_name='accounts' AND index_name='PRIMARY' AND seq_in_index=1 AND column_name='id')
       OR (table_name='coins' AND index_name='PRIMARY' AND seq_in_index=1 AND column_name='id')
       OR (table_name='blocks' AND index_name='PRIMARY' AND seq_in_index=1 AND column_name='id')
       OR (table_name='earnings' AND index_name='PRIMARY' AND seq_in_index=1 AND column_name='id')
       OR (table_name='shares' AND index_name='PRIMARY' AND seq_in_index=1 AND column_name='id')))<>6 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='durable schema downstream-index mismatch';
 END IF;

 -- Compare names, target/timing/event, SQL mode and complete bodies, including
 -- any extra trigger on a protected table. A restricted caller sees NULL bodies;
 -- the migration-owned definer must see the full definitions or this fails.
 SELECT SHA2(GROUP_CONCAT(CONCAT_WS(CHAR(31),trigger_name,event_object_table,
  action_timing,event_manipulation,action_order,COALESCE(sql_mode,'<NULL>'),
  COALESCE(SHA2(action_statement,256),'<NULL>'))
  ORDER BY trigger_name SEPARATOR 0x1e),256) INTO observed
 FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND event_object_table IN
 ('round_lanes','share_rounds','accepted_work','round_intents','round_resolution_audit',
  'round_operator_events','round_schema_version','live_block_share_cursors','live_block_candidates',
  'live_block_attributions','shares','blocks','accounts','coins','earnings');
 IF observed IS NULL OR observed<>'6baf43f3a051203a3d800d70084af8e2013e4e77e5cf80b6e874b16eab5720ed' THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='durable schema trigger mismatch or hidden definition';
 END IF;

 SELECT SHA2(GROUP_CONCAT(CONCAT_WS(CHAR(31),routine_name,routine_type,
  security_type,sql_data_access,COALESCE(sql_mode,'<NULL>'),
  COALESCE(SHA2(routine_definition,256),'<NULL>'))
  ORDER BY routine_name SEPARATOR 0x1e),256) INTO observed
 FROM information_schema.routines WHERE routine_schema=DATABASE() AND routine_name IN
 ('round_lock','round_hold','round_resolve');
 IF observed IS NULL OR observed<>'5fff7db9d667e9fde5e5b8e6c13ae2c09dfe4a2a190d961bf2aa38e7e3d25848' THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='durable schema procedure mismatch or hidden definition';
 END IF;

 SELECT SHA2(GROUP_CONCAT(CONCAT_WS(CHAR(31),specific_name,ordinal_position,
  COALESCE(parameter_mode,'<NULL>'),COALESCE(parameter_name,'<NULL>'),dtd_identifier)
  ORDER BY specific_name,ordinal_position SEPARATOR 0x1e),256) INTO observed
 FROM information_schema.parameters WHERE specific_schema=DATABASE() AND specific_name IN
 ('round_lock','round_hold','round_resolve');
 IF observed IS NULL OR observed<>'dca7e985902f227e5fe87711bb97a7ba3db09ab75d7dcfd3580c23872038da8a' THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='durable schema procedure-parameter mismatch';
 END IF;

 SELECT COUNT(*),COALESCE(MIN(version),0) INTO marker_count,marker_version FROM round_schema_version;
 IF marker_count<>1 OR marker_version<>2 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='durable schema version marker mismatch';
 END IF;

 -- The caller checks this exact self-definition digest. A missing, unreadable,
 -- or accidentally changed guard is therefore a startup refusal too.
 SELECT SHA2(routine_definition,256) FROM information_schema.routines
  WHERE routine_schema=DATABASE() AND routine_name='round_schema_assert';
END$$
DELIMITER ;
