-- Migration 030: Add reverse-proxy authentication mode (HC-143).
--
-- Two new hc_config rows control the feature:
--   auth_mode            'native' (default) or 'reverse_proxy'
--   reverse_proxy_header  The HTTP header the proxy sets (default X-Forwarded-User)
--
-- Idempotent: INSERT IGNORE so re-running is safe.

INSERT IGNORE INTO hc_config (setting, value) VALUES ('auth_mode', 'native');
INSERT IGNORE INTO hc_config (setting, value) VALUES ('reverse_proxy_header', 'X-Forwarded-User');
