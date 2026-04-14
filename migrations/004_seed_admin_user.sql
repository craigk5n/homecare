-- Migration 004: Seed a starter admin user for a fresh install.
--
-- Login:    admin
-- Password: admin  (bcrypt-hashed below, cost 10)
--
-- **Rotate this immediately after first login** via Settings → Your
-- Settings, or by re-running password_hash() and UPDATE-ing hc_user.
-- Leaving the default password in place on anything reachable from a
-- network is obviously a bad idea.
--
-- Idempotent: safe to re-run. INSERT IGNORE (MySQL) / INSERT OR IGNORE
-- (SQLite) both no-op when the login already exists. Use whichever
-- dialect matches your DB; MySQL's is the default for production.

INSERT IGNORE INTO hc_user
    (login, passwd, firstname, lastname, email, is_admin, role, enabled)
VALUES
    ('admin',
     '$2y$10$sYf/R7ev6nWCEip0bEBK3.Nl.qC4sb9okTA/2qhspHHdRVYfwVIL2',
     'Home', 'Admin', 'admin@example.com',
     'Y', 'admin', 'Y');
