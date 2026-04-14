# HomeCare

<!-- HC-051 CI badge. The owner/repo placeholder below is a guess based on
     CLAUDE.md's WebCalendar reference; replace `craigk5n/homecare` with
     the real GitHub path before pushing the workflow. -->
[![tests](https://github.com/craigk5n/homecare/actions/workflows/tests.yml/badge.svg)](https://github.com/craigk5n/homecare/actions/workflows/tests.yml)

A small PHP + MySQL web app for tracking a patient's medication schedule,
intake, inventory, and caregiver notes.

This README documents how to run HomeCare; for the broader project
backlog, design notes, and progress tracking see [`STATUS.md`](STATUS.md)
and [`CLAUDE.md`](CLAUDE.md).

## Run with Docker (HC-050)

The fastest way to get a working install on a fresh machine is the
bundled Docker Compose stack.

### Prerequisites

- Docker 20.10+
- Docker Compose v2 (built into recent Docker Desktop / `docker-compose-plugin`)

### One-shot bring-up

```bash
# (Optional) adjust passwords / timezone — defaults work for local dev.
cp .env.example .env

docker compose up --build
```

When you see Apache log lines, open <http://localhost:8080> in a
browser. On a fresh database the container's entrypoint loads the
schema from `tables-mysql.sql` automatically; subsequent restarts
detect the existing schema and skip init.

### Services

| Service | Image | Purpose |
|---------|-------|---------|
| `web`   | Built from `Dockerfile` (Apache + PHP 8.2) | Application server, port `8080`→`80` |
| `db`    | `mysql:8.0` | Persistent database, volume `homecare_db_data` |

### Configuration

The `web` container reads connection settings from environment
variables (no committed `settings.php`):

| Variable | Default | Purpose |
|----------|---------|---------|
| `HC_DB_HOST` | `db` | MySQL host (set automatically by Compose) |
| `HC_DB_PORT` | `3306` | MySQL port |
| `HC_DB_NAME` | `homecare` | Database name |
| `HC_DB_USER` | `homecare` | DB user |
| `HC_DB_PASSWORD` | required | DB password |
| `HC_DB_TYPE` | `mysqli` | dbi4php driver name |
| `HC_TIMEZONE` | `America/New_York` | PHP timezone |

The entrypoint script writes a fresh `includes/settings.php` from
these on every container start.

### Seeding a user

The shipped DB starts empty. To create the default admin used in
development (`cknudsen` / `cknudsen`):

```bash
docker compose exec db mysql -u homecare -phomecare homecare \
    < migrations/004_seed_cknudsen_user.sql
```

Then visit <http://localhost:8080/login.php>.

### Persistent data

The `homecare_db_data` named volume keeps your medication history
between `docker compose down` / `up` cycles. To wipe it:

```bash
docker compose down -v
```

### Building only the image

```bash
docker build -t homecare:local .
```

## Run on a host (no Docker)

If you already have Apache + PHP 8.2 + MySQL 8 set up, deploy this
repository under your docroot, populate `includes/settings.php` with
your DB credentials, and load the schema:

```bash
mysql <your-db> < tables-mysql.sql

# Run any migrations newer than your last upgrade:
mysql <your-db> < migrations/00X_<name>.sql
```

Composer dependencies are required for the namespaced `src/` code:

```bash
composer install --no-dev --optimize-autoloader
```

## Development

```bash
composer install      # includes phpunit + phpstan
composer check        # runs PHPStan max + the full test suite
```

See [`STATUS.md`](STATUS.md) for the project backlog (Jira-style
epics + stories), the per-story implementation notes, and the
quality-gate requirements (PHPStan max, 80%+ coverage, parameterized
SQL, etc.).
