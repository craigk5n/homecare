-- Migration 036: Opt-in auto-provisioning for reverse-proxy auth (HC-243).
--
-- Seeds the hc_config rows with their (off) defaults so the settings are
-- visible in the table. ReverseProxyConfig applies the same defaults
-- when a row is missing, so this migration changes no behaviour:
--   reverse_proxy_auto_provision    'N' -- create unknown proxy users?
--   reverse_proxy_groups_header     header carrying the user's groups
--   reverse_proxy_admin_groups      comma-separated group names -> admin
--   reverse_proxy_caregiver_groups  comma-separated group names -> caregiver
--   reverse_proxy_viewer_groups     comma-separated group names -> viewer
--   reverse_proxy_default_role      role when no group matches ('none' = refuse)
--   reverse_proxy_sync_role         'N' -- re-derive role from groups each request?
--   reverse_proxy_name_header       optional, '' = unused
--   reverse_proxy_email_header      optional, '' = unused
--
-- Idempotent: INSERT IGNORE so re-running is safe.

INSERT IGNORE INTO hc_config (setting, value) VALUES ('reverse_proxy_auto_provision', 'N');
INSERT IGNORE INTO hc_config (setting, value) VALUES ('reverse_proxy_groups_header', 'Remote-Groups');
INSERT IGNORE INTO hc_config (setting, value) VALUES ('reverse_proxy_admin_groups', '');
INSERT IGNORE INTO hc_config (setting, value) VALUES ('reverse_proxy_caregiver_groups', '');
INSERT IGNORE INTO hc_config (setting, value) VALUES ('reverse_proxy_viewer_groups', '');
INSERT IGNORE INTO hc_config (setting, value) VALUES ('reverse_proxy_default_role', 'viewer');
INSERT IGNORE INTO hc_config (setting, value) VALUES ('reverse_proxy_sync_role', 'N');
INSERT IGNORE INTO hc_config (setting, value) VALUES ('reverse_proxy_name_header', '');
INSERT IGNORE INTO hc_config (setting, value) VALUES ('reverse_proxy_email_header', '');
