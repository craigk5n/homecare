-- Migration 010: Add API rate limiting table (HC-076)
-- and default config values

CREATE TABLE IF NOT EXISTS `hc_api_rate_limit` (
  `ip` VARCHAR(45) NOT NULL,
  `bucket` VARCHAR(20) NOT NULL,
  `window_start` BIGINT NOT NULL,
  `count` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`ip`, `bucket`, `window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `hc_config` (`setting`, `value`) VALUES 
  ('api_rate_limit_rpm', '60') 
ON DUPLICATE KEY UPDATE `value` = '60';

INSERT INTO `hc_config` (`setting`, `value`) VALUES 
  ('api_rate_limit_authenticated_rpm', '600') 
ON DUPLICATE KEY UPDATE `value` = '600';