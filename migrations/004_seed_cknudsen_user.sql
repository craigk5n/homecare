-- Migration 004: Seed the `cknudsen` admin user.
--
-- Password: "cknudsen" (bcrypt-hashed below, cost 10). Rotate this in
-- Admin Settings after first login.
--
-- Idempotent: safe to re-run; INSERT IGNORE (MySQL) / INSERT OR IGNORE
-- (SQLite) both no-op when the login already exists. Use whichever
-- dialect matches your DB; MySQL's is the default for production.

INSERT IGNORE INTO hc_user
    (login, passwd, firstname, lastname, email, is_admin, role, enabled)
VALUES
    ('cknudsen',
     '$2y$10$xKRqUT47pj2e7aViySpyROIwvAkbIwLIHXjsGJKyk9K7rdbB5vUj2',
     'Craig', 'Knudsen', 'cknudsen@cknudsen.com',
     'Y', 'admin', 'Y');
