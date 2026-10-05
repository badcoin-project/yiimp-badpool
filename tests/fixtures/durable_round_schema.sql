-- Minimal downstream contract fixture. Only the new migration supplies round tables/routines.
CREATE DATABASE round_test;
USE round_test;
CREATE TABLE accounts(id INT UNSIGNED PRIMARY KEY,no_fees TINYINT DEFAULT 0,donation DOUBLE DEFAULT 0) ENGINE=InnoDB;
CREATE TABLE coins(id INT UNSIGNED PRIMARY KEY,price DOUBLE DEFAULT 1) ENGINE=InnoDB;
CREATE TABLE shares(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,algo VARCHAR(32),userid INT,workerid INT,coinid INT,jobid INT,pid INT,
 valid TINYINT,extranonce1 TINYINT,difficulty DOUBLE,share_diff DOUBLE,time INT,error INT) ENGINE=InnoDB;
CREATE TABLE blocks(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,height INT,blockhash VARCHAR(255),coin_id INT,
 userid INT,workerid INT,category VARCHAR(32),difficulty DOUBLE,difficulty_user DOUBLE,time INT,algo VARCHAR(32),segwit TINYINT) ENGINE=InnoDB;
CREATE TABLE earnings(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,blockid BIGINT UNSIGNED,userid INT,amount DOUBLE) ENGINE=InnoDB;
CREATE TABLE payout_batches(id INT PRIMARY KEY,state VARCHAR(64),checksum VARCHAR(64)) ENGINE=InnoDB;
INSERT INTO accounts VALUES(1,0,0),(2,1,2.5),(3,0,1);
INSERT INTO coins VALUES(1,1);
