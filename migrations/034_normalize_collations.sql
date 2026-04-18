-- Migration 034: Normalize collations to utf8mb4_unicode_ci.
--
-- Migrations 031 (hc_webhook_log) and 032 (hc_weight_history) used
-- utf8mb4_0900_ai_ci while all older tables use utf8mb4_unicode_ci.
-- This mismatch causes "Illegal mix of collations" errors in UNION
-- queries. Align everything to the existing convention.

ALTER TABLE hc_webhook_log CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE hc_weight_history CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
