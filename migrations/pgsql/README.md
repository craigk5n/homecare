# PostgreSQL migrations

`bin/migrate.php` reads this directory instead of `migrations/` when
`includes/settings.php` has `db_type: postgresql` (see `docs/postgresql.md`).

- On an empty database it first loads `tables-postgres.sql`, the full
  current schema (equivalent to `tables-mysql.sql` after migrations
  001-035).
- It then applies every `*.sql` file here that is not yet recorded in
  `hc_migrations`, in natural sort order, each in its own transaction.

When adding a MySQL migration `migrations/NNN_name.sql`, add the
PostgreSQL equivalent here under the same file name, fold the change into
`tables-postgres.sql`, and append the file name to the
`INSERT INTO hc_migrations` list at the bottom of `tables-postgres.sql`
so fresh installs do not re-apply it.
