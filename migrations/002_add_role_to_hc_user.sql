-- Migration 002: Add role column to hc_user (HC-010)
-- BACKUP FIRST: ./dump.sh
--
-- Introduces role-based access control (admin/caregiver/viewer). Existing
-- admins (is_admin='Y') are promoted to role='admin'; everyone else defaults
-- to 'caregiver'. The is_admin column is retained for now so existing login
-- code keeps working -- HC-011 cuts over to the new column.
--
-- Portable across MySQL 8+ and SQLite 3.35+: ALTER TABLE ADD COLUMN with a
-- DEFAULT and NOT NULL works on both.

ALTER TABLE hc_user ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'caregiver';

UPDATE hc_user SET role = 'admin' WHERE is_admin = 'Y';
