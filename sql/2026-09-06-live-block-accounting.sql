-- Forward-only block accounting. Existing shares establish the deployment
-- boundary; existing blocks are deliberately not backfilled into candidates.
CREATE TABLE `live_block_share_cursors` (
  `algo` varchar(32) NOT NULL,
  `last_share_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`algo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO `live_block_share_cursors` (`algo`,`last_share_id`)
SELECT `algo`,MAX(`id`) FROM `shares` GROUP BY `algo`;

CREATE TABLE `live_block_candidates` (
  `block_id` bigint unsigned NOT NULL,
  `coin_id` int unsigned NOT NULL,
  `blockhash` varchar(255) NOT NULL,
  `algo` varchar(32) NOT NULL,
  `found_time` int unsigned NOT NULL,
  `price` double NOT NULL DEFAULT 0,
  `share_floor_id` bigint unsigned NOT NULL,
  `share_ceiling_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`block_id`),
  KEY `live_scope` (`coin_id`,`algo`,`block_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `live_block_attributions` (
  `block_id` bigint unsigned NOT NULL,
  `userid` int unsigned NOT NULL,
  `difficulty` double NOT NULL,
  `no_fees` tinyint(1) NOT NULL DEFAULT 0,
  `donation` double NOT NULL DEFAULT 0,
  PRIMARY KEY (`block_id`,`userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
