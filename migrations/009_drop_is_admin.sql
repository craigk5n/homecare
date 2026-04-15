-- Migration 009: Drop legacy is_admin column now that role-based auth is fully in place.

ALTER TABLE hc_user DROP COLUMN is_admin;