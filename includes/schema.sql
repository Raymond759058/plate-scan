-- PlateScan - database schema
-- Engine: InnoDB, charset: utf8mb4, collation: utf8mb4_unicode_ci
--
-- Import into an EXISTING database (on shared hosting create it first in the control panel):
--   mysql -u USER -p DATABASE_NAME < includes/schema.sql
-- Local development only (optional):
--   CREATE DATABASE plate_scan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE DATABASE IF NOT EXISTS `plate_scan` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `plate_scan`;

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `vehicles` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `plate_number`  VARCHAR(20)   NOT NULL,
  `owner_name`    VARCHAR(100)  NOT NULL,
  `phone`         VARCHAR(20)   NOT NULL,
  `make`          VARCHAR(50)   NOT NULL,
  `model`         VARCHAR(50)   NOT NULL,
  `color`         VARCHAR(30)   NOT NULL,
  `body_type`     VARCHAR(30)   NOT NULL,
  `base_fee`      DECIMAL(10,2) NOT NULL DEFAULT 50.00,   -- always stored in USD
  `currency`      VARCHAR(3)    NOT NULL DEFAULT 'USD',   -- currency chosen by the user
  `fee_converted` DECIMAL(10,2) NOT NULL,                 -- base_fee converted into `currency`
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plate_number` (`plate_number`),
  KEY `idx_owner_name` (`owner_name`),
  KEY `idx_make_model` (`make`, `model`),
  KEY `idx_body_type` (`body_type`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rate_cache` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `base_currency` VARCHAR(8)   NOT NULL,                  -- 'USD' = real rates, 'FAIL' = "both APIs were down" marker
  `rates_json`    TEXT         NOT NULL,
  `fetched_at`    INT UNSIGNED NOT NULL,                  -- unix timestamp
  PRIMARY KEY (`id`),
  KEY `idx_base_fetched` (`base_currency`, `fetched_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rate_limits` (
  `ip_address`    VARCHAR(45)  NOT NULL,
  `endpoint`      VARCHAR(50)  NOT NULL,
  `request_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_request`  INT UNSIGNED NOT NULL,                  -- start (unix time) of the current window
  PRIMARY KEY (`ip_address`, `endpoint`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_last_request` (`last_request`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
