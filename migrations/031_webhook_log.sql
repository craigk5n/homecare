-- Migration 031: Webhook delivery log (HC-102 follow-up).
--
-- Logs every webhook dispatch attempt so admins can see what was
-- sent, when, whether it succeeded, and how long it took.

CREATE TABLE IF NOT EXISTS hc_webhook_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  message_id VARCHAR(64) NOT NULL,
  url VARCHAR(512) NOT NULL,
  request_body TEXT NOT NULL,
  http_status INT NULL,
  response_body TEXT NULL,
  error_message VARCHAR(512) NULL,
  attempt INT NOT NULL DEFAULT 1,
  max_attempts INT NOT NULL DEFAULT 4,
  elapsed_ms INT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_hc_webhook_log_message ON hc_webhook_log (message_id);
CREATE INDEX idx_hc_webhook_log_created ON hc_webhook_log (created_at DESC);
CREATE INDEX idx_hc_webhook_log_success ON hc_webhook_log (success, created_at DESC);
