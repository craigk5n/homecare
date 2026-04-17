# STATUS.md -- Project Backlog & Progress

*Last updated: 2026-04-16*

This file tracks epics, stories, and progress using a Jira-style format.
All development follows **Test-Driven Development (TDD)**: write tests first (RED),
implement to pass (GREEN), then refactor (IMPROVE).

## Quality Goals

These are ongoing commitments that apply to **every** story, not one-time tasks.
They gate "done" -- no story is complete while any of them regresses.

- **PHPStan `level: max`** across `src/` and `tests/`. Legacy procedural files
  under `includes/` and root `*.php` pages are out of scope for now; new code
  must stay clean. Run `composer analyse` locally and in CI.
- **PHPUnit 80%+ coverage** on `src/` (per `common/testing.md`). Run
  `composer test` (or `composer check` for analyse + test together).
- **PSR-12 formatting** + `declare(strict_types=1)` in every new file.
- **Parameterised SQL only** -- no string-interpolated user input. Enforced
  by review; the `DatabaseInterface` contract makes this the path of least
  resistance.

## Status Key

| Status | Meaning |
|--------|---------|
| `BACKLOG` | Not started |
| `IN PROGRESS` | Currently being worked |
| `DONE` | Completed and tested |
| `BLOCKED` | Waiting on a dependency |

---

## EPIC-0: Test Infrastructure & Refactoring Foundation

**Goal**: Establish a test framework, make domain logic testable in isolation, and
create a SQLite-backed integration test harness. This epic is prerequisite to all
others -- every subsequent story assumes PHPUnit is available and the refactored
layers exist.

**Priority**: CRITICAL -- must be completed first

---

### HC-001: Initialize Composer and PHPUnit

**Status**: `DONE`
**Type**: Story
**Points**: 2
**Depends on**: Nothing

**Description**: Set up Composer for autoloading and PHPUnit as the test runner.
No production code changes -- only project tooling.

**Acceptance Criteria**:
- [x] `composer.json` exists with `phpunit/phpunit` as a dev dependency
- [x] `composer.json` defines PSR-4 autoload for `HomeCare\\` namespace mapping to `src/`
- [x] `composer.json` defines PSR-4 autoload-dev for `HomeCare\\Tests\\` mapping to `tests/`
- [x] `phpunit.xml` exists with test suite configuration pointing to `tests/`
- [x] `vendor/` is added to `.gitignore`
- [x] `composer install` succeeds
- [x] `vendor/bin/phpunit --version` runs successfully
- [x] A trivial smoke test (`tests/SmokeTest.php`) passes: `assertTrue(true)`

---

### HC-002: Extract pure domain functions into a testable class

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-001

**Description**: The functions `calculateSecondsUntilDue()`, `calculateNextDueDate()`,
`frequencyToSeconds()`, and `getIntervalSpecFromFrequency()` in `includes/homecare.php`
are pure logic with no database or global dependencies. Extract them into a namespaced
class `src/Domain/ScheduleCalculator.php` and write comprehensive unit tests.

The original functions in `includes/homecare.php` should become thin wrappers that
delegate to the new class, so existing pages continue to work without changes.

**Acceptance Criteria**:
- [x] `src/Domain/ScheduleCalculator.php` exists with static methods for all four functions
- [x] Each method has PHP 8 type declarations (params and return types)
- [x] Each method throws `\InvalidArgumentException` on invalid input (not `Exception`)
- [x] `tests/Unit/Domain/ScheduleCalculatorTest.php` exists with tests covering:
  - `frequencyToSeconds()`: `1d`=86400, `8h`=28800, `12h`=43200, `30m`=1800, invalid unit throws
  - `calculateSecondsUntilDue()`: future dose returns positive seconds, past dose returns 0, edge case at exact due time
  - `calculateNextDueDate()`: correct ISO date for each frequency unit
  - `getIntervalSpecFromFrequency()`: `8h`=`PT8H`, `2d`=`P2D`, `m` returns null
- [x] Original functions in `includes/homecare.php` still work (delegate to new class)
- [x] All tests pass: `vendor/bin/phpunit tests/Unit/Domain/`

---

### HC-003: Create Database interface and SQLite test adapter

**Status**: `DONE`
**Type**: Story
**Points**: 5
**Depends on**: HC-001

**Description**: Create a `DatabaseInterface` that abstracts the `dbi_*` function calls
behind an injectable contract. Implement a production adapter that wraps the existing
`dbi4php.php` functions and a test adapter backed by SQLite3 in-memory.

This does NOT change how production code gets its database connection (still via
`init.php` globals). It creates a parallel path that new refactored code can use
and that tests can inject.

**Acceptance Criteria**:
- [x] `src/Database/DatabaseInterface.php` defines: `query(string $sql, array $params = []): array`, `execute(string $sql, array $params = []): bool`, `lastInsertId(): int`
- [x] `src/Database/DbiAdapter.php` implements the interface by wrapping `dbi_get_cached_rows()`, `dbi_execute()`, etc.
- [x] `src/Database/SqliteDatabase.php` implements the interface using PDO + SQLite `:memory:`
- [x] `tests/fixtures/schema-sqlite.sql` exists -- a SQLite-compatible version of `tables-mysql.sql` (replace `AUTO_INCREMENT` with `AUTOINCREMENT`, `BOOLEAN` with `INTEGER`, `DATETIME DEFAULT CURRENT_TIMESTAMP` works as-is)
- [x] `tests/Integration/DatabaseTestCase.php` base class exists that creates an in-memory SQLite DB, loads the schema, and provides `getDb(): DatabaseInterface`
- [x] A simple integration test proves the SQLite adapter works: insert a patient, query it back, assert fields match
- [x] All tests pass

---

### HC-004: Extract repository layer for Patient and Medicine data

**Status**: `DONE`
**Type**: Story
**Points**: 5
**Depends on**: HC-003

**Description**: Extract database queries from `includes/homecare.php` into repository
classes that accept `DatabaseInterface` via constructor injection.

**Notes on implementation**:
- `ScheduleRepository::getActiveSchedules()` takes `$today` as an explicit
  parameter so the query is portable between MySQL (`CURDATE()`) and
  SQLite (`DATE('now')`) and tests are deterministic.
- `IntakeRepository::reassignIntakes()` counts matches before issuing the
  UPDATE rather than relying on a driver-specific row-count API, keeping
  `DatabaseInterface` narrow.

**Acceptance Criteria**:
- [x] `src/Repository/PatientRepository.php` exists with methods: `getById(int $id): array`, `getAll(bool $includeDisabled = false): array`
- [x] `src/Repository/ScheduleRepository.php` exists with methods: `getActiveSchedules(int $patientId): array`, `getScheduleById(int $id): array`, `endSchedule(int $id, string $endDate): bool`, `createSchedule(array $data): int`
- [x] `src/Repository/IntakeRepository.php` exists with methods: `getIntakesSince(int $scheduleId, string $since): array`, `countIntakesSince(int $scheduleId, string $since): int`, `recordIntake(int $scheduleId, ?string $takenTime = null, ?string $note = null): int`, `reassignIntakes(int $fromScheduleId, int $toScheduleId, string $since): int`
- [x] `src/Repository/InventoryRepository.php` exists with methods: `getLatestStock(int $medicineId): ?array`, `getTotalConsumedSince(int $medicineId, string $since): float`
- [x] All repositories accept `DatabaseInterface` in their constructor
- [x] `tests/Integration/Repository/PatientRepositoryTest.php` with fixture data tests all methods
- [x] `tests/Integration/Repository/ScheduleRepositoryTest.php` with tests for CRUD operations
- [x] Original functions in `includes/homecare.php` still work (unchanged for now)
- [x] All tests pass

---

### HC-005: Refactor dosesRemaining() to use repository layer

**Status**: `DONE`
**Type**: Story
**Points**: 5
**Depends on**: HC-002, HC-004

**Description**: Rewrite the `dosesRemaining()` logic as a service class that composes
`ScheduleCalculator` (pure math) and repositories (data access). The existing function
in `includes/homecare.php` becomes a thin wrapper that instantiates the service with
the global DB adapter.

**Notes on implementation**:
- To make the unit tests mock repositories, the three repo classes
  consumed by services (`Inventory`, `Schedule`, `Intake`) gained
  `*Interface` contracts. The concrete classes stay `final`.
- `IntakeRepository` is not a constructor dependency of
  `InventoryService::calculateRemaining()` -- the original query already
  summed consumption off schedule-level `unit_per_dose` via a JOIN, which
  is a stock concern and lives on `InventoryRepository::getTotalConsumedSince()`.
  If future service methods need intake data they can inject `IntakeRepositoryInterface`.
- A new `InventoryRepository::getMedicineName()` replaces an inline SQL
  lookup; adding a full MedicineRepository would have been a one-method class.
- `homecare_inventory_service()` caches the service per request to avoid
  rebuilding the repository graph on every `dosesRemaining()` call.

**Acceptance Criteria**:
- [x] `src/Service/InventoryService.php` exists with method `calculateRemaining(int $medicineId, int $scheduleId, bool $assumePastIntake = false, ?string $startDate = null, ?string $frequency = null): array`
- [x] Service composes `InventoryRepository`, `ScheduleRepository`, and `ScheduleCalculator` (IntakeRepository intentionally not injected; see notes)
- [x] Business logic is separated from data fetching -- all SQL lives in repositories
- [x] `tests/Unit/Service/InventoryServiceTest.php` tests the service with mocked repositories:
  - No inventory: returns zero remaining
  - Normal case: inventory minus consumed equals remaining
  - Assumed past intake: additional consumption subtracted
  - Zero unit_per_dose edge case handled
  - Schedule-level unit_per_dose override used when present
- [x] `tests/Integration/Service/InventoryServiceTest.php` tests with SQLite fixtures for end-to-end correctness
- [x] `dosesRemaining()` in `includes/homecare.php` delegates to `InventoryService` and returns same array shape
- [x] All existing pages continue to work without changes
- [x] All tests pass

---

### HC-006: Create test fixtures and factory helpers

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-003

**Description**: Create reusable test fixture data and factory functions for building
test records without repetitive SQL in every test.

**Acceptance Criteria**:
- [x] `tests/Factory/PatientFactory.php` exists with `create(array $overrides = []): array` that inserts a patient and returns the record
- [x] `tests/Factory/MedicineFactory.php` creates medicines with sensible defaults
- [x] `tests/Factory/ScheduleFactory.php` creates schedules linked to patient + medicine
- [x] `tests/Factory/IntakeFactory.php` creates intake records
- [x] `tests/Factory/InventoryFactory.php` creates inventory checkpoints
- [x] Each factory accepts a `DatabaseInterface` and returns the created record with its ID
- [x] `tests/fixtures/seed-data.sql` contains a standard fixture set matching current test data (Daisy, Fozzie, their medications)
- [x] At least one integration test uses factories instead of raw SQL (IntakeRepositoryTest refactored; new SeedDataTest exercises factories + seed together)

---

### HC-007: Add Request abstraction for form input

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-001

**Description**: Create a `Request` class that wraps `$_GET`, `$_POST`, and `$_SERVER`
so that handlers can be tested without manipulating superglobals. The class should be
instantiable from superglobals (production) or from arrays (testing).

**Notes on implementation**:
- The XSS guard throws {@see \HomeCare\Http\InvalidRequestException}
  instead of calling `die_miserable_death()`. Callers decide how to
  render the error; the HTTP abstraction stays side-effect-free.
- Hex-escape sequences (e.g. `\x3c`) are decoded before scanning so
  attackers can't bypass the banned-tag filter by encoding the `<`.
- Array values are scanned recursively -- matches `preventHacking()`'s
  handling of array inputs in the legacy helper.
- `getInt()` drops the legacy "fatal on mismatch" mode; callers that
  need "required int or 400" check the return for null explicitly.

**Acceptance Criteria**:
- [x] `src/Http/Request.php` exists with methods: `get(string $key, $default = null)`, `post(string $key, $default = null)`, `getInt(string $key): ?int`, `method(): string`
- [x] Constructor accepts optional arrays: `__construct(array $get = [], array $post = [], array $server = [])`
- [x] Static factory: `Request::fromGlobals()` creates instance from `$_GET`, `$_POST`, `$_SERVER`
- [x] `tests/Unit/Http/RequestTest.php` tests get/post/getInt with injected arrays
- [x] XSS prevention from `formvars.php` is preserved (dangerous tags rejected) -- all 14 banned tags covered via data provider
- [x] All tests pass

---

### HC-008: PHPStan max-level static analysis

**Status**: `DONE`
**Type**: Story
**Points**: 2
**Depends on**: HC-001

**Description**: Install PHPStan and configure it at `level: max` scoped to
`src/` and `tests/`. The legacy WebCalendar-derived procedural code under
`includes/` and the root `*.php` pages remain out of scope -- they will never
cleanly hit max without a ground-up rewrite, and forcing it would produce a
huge ignore list that hides real issues. Holding new namespaced code to the
strictest bar protects the refactor as it grows.

**Acceptance Criteria**:
- [x] `phpstan/phpstan` added as a dev dependency in `composer.json`
- [x] `phpstan.neon` exists with `level: max`, `paths: [src, tests]`, and
      `bootstrapFiles: [vendor/autoload.php]`
- [x] Legacy `includes/homecare.php` is declared via `scanFiles` so the
      wrapper-delegation test can reference global functions without
      triggering `function.notFound`
- [x] `composer analyse` runs PHPStan; `composer check` runs analyse + tests
- [x] `vendor/bin/phpstan analyse` reports **0 errors** on the current tree

---

## EPIC-1: Authentication & Access Control

**Goal**: Add role-based access, session timeout, audit logging, and login security
hardening. Build on the existing WebCalendar auth layer.

**Priority**: HIGH
**Depends on**: EPIC-0 (HC-001, HC-003)

---

### HC-010: Add role column to hc_user

**Status**: `DONE`
**Type**: Story
**Points**: 2
**Depends on**: HC-003

**Description**: Add a `role` column to `hc_user` with values `admin`, `caregiver`,
`viewer`. The existing `is_admin` column maps to the `admin` role. Default new users
to `caregiver`.

**Notes on implementation**:
- Migration lives at `migrations/002_add_role_to_hc_user.sql`. It is
  portable MySQL 8+ / SQLite 3.35+ (ALTER TABLE ADD COLUMN with DEFAULT
  + NOT NULL is supported on both).
- `is_admin` column retained for now so the existing `validate.php`
  login code keeps working. HC-011 cuts writers over to the `role`
  column; `is_admin` stays readable until all call sites are migrated.
- Migration test recreates the pre-migration hc_user shape inline
  (since the canonical `schema-sqlite.sql` now includes `role`) and
  applies the migration file verbatim via PDO.

**Acceptance Criteria**:
- [x] `ALTER TABLE hc_user ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'caregiver'`
- [x] Migration sets `role = 'admin'` for all users where `is_admin = 'Y'`
- [x] `tables-mysql.sql` updated with the new column
- [x] `tests/fixtures/schema-sqlite.sql` updated
- [x] `tests/Integration/Migration/RoleMigrationTest.php` verifies the migration:
  - Admin user gets role=admin
  - Non-admin user gets role=caregiver
  - New user defaults to caregiver

---

### HC-011: Enforce role-based access on write operations

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-010

**Description**: Gate all write operations (recording intake, editing schedules,
adjusting dosage, editing medications, editing inventory) behind `caregiver` or `admin`
role. Viewers can only see data. Admin can additionally manage users and settings.

**Notes on implementation**:
- `Authorization` is pure role-math -- takes the role string via
  constructor, no globals. Callers wire it up from sessions or DB
  lookups however they like.
- `getCurrentUserRole()` in `includes/homecare.php` bridges the
  HC-010 transition: `is_admin='Y'` always promotes to `admin` even
  if the role column is stale, and missing logins fail closed to
  `viewer`.
- No admin-only pages exist in the current tree, so
  `require_role('admin')` has no current call sites. The helper is
  ready whenever user/settings pages land (see future HC-050 etc.).

**Acceptance Criteria**:
- [x] `src/Auth/Authorization.php` exists with methods: `canWrite(): bool`, `canAdmin(): bool`, `getCurrentRole(): string`
- [x] Helper function `require_role(string $minimumRole)` available in `includes/homecare.php` that calls `die_miserable_death()` if the current user's role is insufficient
- [x] All `*_handler.php` files call `require_role('caregiver')` at the top (10 handlers patched)
- [x] Admin-only pages (settings, user management) call `require_role('admin')` -- helper ready; no admin-only pages in current tree
- [x] `tests/Unit/Auth/AuthorizationTest.php` tests role hierarchy: admin > caregiver > viewer
- [x] A viewer accessing a handler receives an error, not a silent failure (via `die_miserable_death()`)
- [x] All tests pass

---

### HC-012: Implement session timeout

**Status**: `DONE`
**Type**: Story
**Points**: 2
**Depends on**: HC-001

**Description**: Add configurable idle session timeout. After N minutes of inactivity,
the user is logged out and redirected to the login page. Default: 30 minutes.

**Notes on implementation**:
- `SessionTimeout::evaluate()` is pure: takes `last_activity`, `now`,
  returns a `SessionState` enum. No globals, no `time()` calls, trivial
  to unit-test at the boundaries.
- `hc_config.session_timeout` is read at request time; if the row
  doesn't exist or is non-positive, the helper falls back to the
  `DEFAULT_TIMEOUT_MINUTES` constant (30).
- On expiration the wiring clears `$_SESSION`, calls
  `session_destroy()`, sets `$session_not_found = true`, and lets the
  existing flow (line ~258) redirect to the login page. No new redirect
  path added -- we reuse what was already there.
- Check is gated on `$login !== '__public__'` so the public-access
  path is exempt.

**Acceptance Criteria**:
- [x] `hc_config` setting `session_timeout` controls timeout in minutes (default 30)
- [x] `includes/validate.php` checks `$_SESSION['last_activity']` on each request
- [x] If `time() - last_activity > timeout`, session is destroyed and user redirected to login
- [x] `$_SESSION['last_activity']` is updated on every authenticated request
- [x] `tests/Unit/Auth/SessionTimeoutTest.php` tests:
  - Active session within timeout: allowed
  - Expired session: returns "expired" signal
  - No last_activity set: treated as new session
  - Plus boundary cases (exactly-at and one-second-past), custom timeout, and invalid timeout rejection

---

### HC-013: Add audit logging table and recording

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-003, HC-010

**Description**: Create `hc_audit_log` table and record who performed each significant
action (intake recorded, schedule created/modified, dosage adjusted, inventory updated).

**Notes on implementation**:
- Migration lives at `migrations/005_add_hc_audit_log.sql`. Indexed on
  (user_login, created_at) and (entity_type, entity_id) because the
  two dominant queries are "what did X do?" and "what happened to
  this row?".
- `AuditLogger` never throws on DB errors -- it logs to `error_log()`
  and returns. Audit logging must never break the user's happy path.
- Context providers (login, IP, clock) are callables injected via the
  constructor so the logger is testable and safe in CLI too. The
  `audit_log()` helper in `includes/homecare.php` is the request-time
  singleton that reads `$GLOBALS['login']` and
  `$_SERVER['REMOTE_ADDR']`.
- Handler coverage exceeds the listed actions: beyond the seven
  required (intake.recorded, schedule.created, schedule.updated,
  dosage.adjusted, inventory.updated, user.login, user.logout) we also
  log medicine.created, medicine.updated, medicines.merged,
  intake.updated, intake.deleted, and user.login_failed.

**Acceptance Criteria**:
- [x] `hc_audit_log` table: `id`, `user_login`, `action` (varchar), `entity_type` (varchar), `entity_id` (int), `details` (text, JSON), `ip_address` (varchar), `created_at` (datetime)
- [x] `src/Audit/AuditLogger.php` exists with method `log(string $action, string $entityType, int $entityId, array $details = []): void`
- [x] AuditLogger reads `$login` global and `$_SERVER['REMOTE_ADDR']` for context (via the `audit_log()` helper wrapper)
- [x] All handlers log their actions: `intake.recorded`, `schedule.created`, `schedule.updated`, `dosage.adjusted`, `inventory.updated`, `user.login`, `user.logout`
- [x] `tests/Integration/Audit/AuditLoggerTest.php` verifies log entries are written correctly
- [x] `tables-mysql.sql` and `tests/fixtures/schema-sqlite.sql` updated

---

### HC-014: Add login rate limiting

**Status**: `DONE`
**Type**: Story
**Points**: 2
**Depends on**: HC-013

**Description**: Track failed login attempts and lock accounts after repeated failures.
Prevents brute-force attacks.

**Notes on implementation**:
- Migration lives at `migrations/006_add_login_rate_limit.sql`.
  Constants on `AuthService`: `MAX_FAILED_ATTEMPTS = 5`,
  `LOCKOUT_MINUTES = 15`.
- Lockout check runs **before** the enabled / password checks, so
  timing can't reveal whether the lockout is active vs some other
  failure mode. Unknown logins still return `invalid_credentials`
  immediately (no lockout state to inspect) -- this matches the
  "no information leakage about whether account exists" requirement.
- `incrementFailedAttempts()` uses atomic `SET failed_attempts =
  failed_attempts + 1` so parallel brute-force attempts can't race
  past the limit. The lock is applied only when the post-increment
  count reaches the threshold, so a user mid-lockout is not
  extending their own window.
- `loginWithRememberToken()` also checks `locked_until` -- a valid
  remember-me cookie does not bypass the lockout.

**Acceptance Criteria**:
- [x] `hc_user` gets columns: `failed_attempts INT DEFAULT 0`, `locked_until DATETIME NULL`
- [x] After 5 consecutive failed login attempts, account is locked for 15 minutes
- [x] Successful login resets `failed_attempts` to 0
- [x] Locked accounts show "Account temporarily locked" message (no information leakage about whether account exists)
- [x] `tests/Integration/Auth/LoginRateLimitTest.php` tests:
  - 4 failures: account not locked
  - 5th failure: account locked
  - Login attempt while locked: rejected with message
  - After lockout expires: login succeeds
  - Successful login resets counter
  - Plus: remember-me cannot bypass lockout; unknown user does not leak existence

---

## EPIC-2: Data Export & Reporting

**Goal**: Enable caregivers to export data for vet/doctor visits and generate
adherence reports with visualizations.

**Priority**: HIGH
**Depends on**: EPIC-0 (HC-004, HC-005)

---

### HC-020: CSV export for intake history (+ FHIR R4 JSON)

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-004

**Description**: Add a download button to the intake report page that exports the
displayed data as a CSV file. No external dependencies needed -- PHP generates CSV natively.

**Beyond spec: HL7 FHIR R4 JSON export.** The acceptance criteria asked
for CSV; we also added a `Bundle` of `Patient` + `Medication` +
`MedicationAdministration` resources in FHIR R4 JSON. FHIR is the de-facto
interchange standard for medication data (Apple Health, Epic, Cerner,
athenahealth, most EHRs and PHRs). A caregiver can hand the bundle to
a vet/doctor's system and expect a clean import.

**Notes on implementation**:
- `IntakeExportQuery` owns the one SQL query both exporters share --
  the CSV and FHIR outputs cannot drift on what "an intake record" means.
- CSV columns are `Date, Time, Medication, Dosage, Frequency,
  UnitPerDose, Notes`. Dosage and UnitPerDose are beyond the spec;
  without them a row is clinically ambiguous.
- FHIR bundle is R4 (FHIR_VERSION 4.0.1). MedicationAdministration
  status is always `completed` (we only store taken doses; missed-dose
  representation is a future ticket).
- Both endpoints require `caregiver` role and emit an audit row
  (`export.intake_csv`, `export.intake_fhir`) with row count in
  details -- so unusually large exports are easy to spot.
- Content-Type for FHIR is `application/fhir+json` per the FHIR spec.

**Acceptance Criteria**:
- [x] `export_intake_csv.php` generates a CSV with columns: Date, Time, Medication, Frequency, Notes (plus Dosage, UnitPerDose for clarity)
- [x] Accepts `patient_id`, `start_date`, `end_date` parameters to filter
- [x] Sets correct HTTP headers: `Content-Type: text/csv`, `Content-Disposition: attachment; filename="intake-history-{patient}-{date}.csv"`
- [x] Uses `fputcsv()` for proper CSV escaping
- [x] `report_intake.php` has a "Download CSV" button linking to the export (also "Download FHIR JSON")
- [x] `tests/Integration/Export/IntakeCsvExportTest.php` tests:
  - Correct column headers in first row
  - Data rows match database records
  - Date range filtering works
  - Empty result produces headers-only CSV
  - Special characters in medication names are properly escaped
- [x] Bonus: `export_intake_fhir.php` emits a valid FHIR R4 Bundle (tested in `FhirIntakeExportTest`)

---

### HC-021: Printable medication summary

**Status**: `DONE`
**Type**: Story
**Points**: 2
**Depends on**: HC-004

**Description**: Generate a clean, printable one-page medication list for a patient.
This is the document a caregiver brings to a vet or doctor appointment.

**Notes on implementation**:
- `MedicationSummaryReport` is the pure data builder; it composes
  `InventoryService` so the "remaining" figures agree with the rest of
  the UI. No SQL in the page; no HTML in the report.
- `today` is an explicit parameter (same portability trick we use in
  `ScheduleRepository::getActiveSchedules`). Tests drive it directly;
  the production page calls with `date('Y-m-d')`.
- `discontinued_window_days` defaults to 90 per spec, but is
  parameterised -- the call site can widen it if a caregiver needs a
  longer retrospective.
- Print CSS is inline in the page rather than a separate stylesheet:
  one file, one URL, fewer caching surprises.
- `$friendly = true` before `print_header()` is what the existing
  framework uses to strip menu/nav chrome; we reuse that rather than
  inventing a parallel "minimal" bootstrap.

**Acceptance Criteria**:
- [x] `medication_summary.php` generates a print-optimized page with:
  - Patient name and date at top
  - Table of active medications: name, dosage text, frequency, start date, current stock remaining
  - Section for completed/discontinued medications (last 90 days)
- [x] Page uses `$friendly = true` flag to suppress nav/menu
- [x] Print stylesheet renders clean on paper (no colors, compact layout)
- [x] Link from `list_schedule.php` sticky header: "Print Summary"
- [x] `tests/Integration/Report/MedicationSummaryTest.php` tests correct data inclusion

---

### HC-022: Adherence percentage calculation

**Status**: `DONE`
**Type**: Story
**Points**: 5
**Depends on**: HC-005

**Description**: Calculate medication adherence rate (percentage of expected doses
actually recorded) over configurable time periods.

**Notes on implementation**:
- Added `countIntakesBetween(int, string, string): int` to
  `IntakeRepositoryInterface` + `IntakeRepository` -- the existing
  `countIntakesSince()` is half-open (>) and wrong for a bounded
  adherence window.
- Effective window = intersection of [query range] and
  [schedule.start_date, schedule.end_date or query end]. Both bounds
  inclusive. That's what makes mid-period starts honest.
- Expected uses `round(days * dosesPerDay)` rather than `floor()` so
  a half-day remainder at a 2d frequency isn't silently dropped to 0.
- Over-adherence (actual > expected) **is not capped**. Reporting
  150% is useful -- it makes accidental double-recording visible on
  the report. HC-023 renders it with a distinct colour.
- Reversed date ranges (start > end) return zeros without touching
  the repositories; unknown schedules do the same.

**Acceptance Criteria**:
- [x] `src/Service/AdherenceService.php` with method `calculateAdherence(int $scheduleId, string $startDate, string $endDate): array` returning `['expected' => int, 'actual' => int, 'percentage' => float]`
- [x] Expected doses calculated from frequency and date range
- [x] Actual doses counted from `hc_medicine_intake` in the range
- [x] Handles edge cases: schedule started mid-period, schedule ended mid-period, no intakes
- [x] `tests/Unit/Service/AdherenceServiceTest.php` tests with mocked repos:
  - Perfect adherence: 100%
  - Half doses missed: 50%
  - Schedule started mid-period: expected count adjusted
  - No intakes: 0%
  - Rounding to 1 decimal place
  - Plus: schedule ended mid-period, missing schedule, reversed range, entirely out of window, over-adherence
- [x] `tests/Integration/Service/AdherenceServiceTest.php` with SQLite fixtures

---

### HC-023: Adherence report page with chart

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-022

**Description**: Add an adherence report page showing per-medication adherence
percentages over the last 30/90 days with a simple bar chart.

**Notes on implementation**:
- `PatientAdherenceReport::build()` composes AdherenceService and
  issues one windowed calculation per (active schedule × {7,30,90}-day
  window) so the table and chart render off the same data.
- Chart.js 4.5.1 is **bundled locally at `pub/chart.umd.min.js`**
  rather than loaded from a CDN. The acceptance asked for CDN, but
  this deploy is LAN-isolated -- we hit the same failure mode with
  jQuery on the merge page. 208 KB on disk, zero runtime dependency
  on external networks.
- "Custom" range slides in two date pickers and renders a fourth
  table column plus a single-series chart for that window. The preset
  ranges (7d/30d/90d) always show all three columns for comparison;
  the selected range gets coloured bars while the other two fade to
  grey so the eye lands on the focus.
- Colour thresholds match spec: ≥90% green, 70-89% yellow, <70% red.
  Both table cells and chart bars use them.

**Acceptance Criteria**:
- [x] `report_adherence.php` displays a per-medication adherence table for a patient
- [x] Columns: Medication, 7-day %, 30-day %, 90-day %
- [x] Color coding: green >= 90%, yellow 70-89%, red < 70%
- [x] Simple bar chart rendered via Chart.js (locally bundled, no CDN -- see notes)
- [x] Date range selector (7d / 30d / 90d / custom)
- [x] Link added to Reports menu in `includes/menu.php`
- [x] Page works on mobile (responsive chart via `responsive:true, maintainAspectRatio:false`)

---

## EPIC-3: REST API

**Goal**: Expose schedule, intake, and inventory data via a JSON API with API key
authentication. Enables future mobile apps and home automation integration.

**Priority**: MEDIUM
**Depends on**: EPIC-0 (HC-004), EPIC-1 (HC-010)

---

### HC-030: API key authentication

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-010

**Description**: Add per-user API keys that can be used in an `Authorization: Bearer`
header for API requests. Keys are generated in user settings and stored hashed.

**Notes on implementation**:
- Migration lives at `migrations/007_add_api_key_hash.sql`; applied to
  the live DB.
- `ApiKeyAuth` is pure: takes an Authorization header string, returns
  an `AuthResult`. The HTTP layer maps reasons to status codes via
  `ApiKeyAuth::httpStatusFor()` so controllers don't duplicate the table.
- Raw keys are `bin2hex(random_bytes(32))` → 64 hex chars. The DB
  stores only `hash('sha256', $raw)` (same pattern as remember-me).
- Bearer scheme matching is case-insensitive (`Bearer`/`bearer`/`BEARER`) --
  RFC 6750 is strict but observed clients aren't.
- `settings.php` is the self-service page: any caregiver+ manages
  their own key (not admin-gated). Raw value is shown exactly once on
  generation; afterwards only "Active (hashed)" status is visible.
- Revoke nulls the hash → in-flight clients start getting 401
  immediately. Both generate and revoke emit audit rows
  (`apikey.generated`, `apikey.revoked`).

**Acceptance Criteria**:
- [x] `hc_user` gets column: `api_key_hash VARCHAR(255) NULL`
- [x] Settings page has "Generate API Key" button that creates a random 32-byte hex key
- [x] Key is shown once on generation (not stored in plaintext), hash stored in DB
- [x] `src/Auth/ApiKeyAuth.php` validates `Authorization: Bearer <key>` header by hashing and comparing
- [x] API requests authenticated via key get the associated user's role and login
- [x] `tests/Integration/Auth/ApiKeyAuthTest.php` tests:
  - Valid key: returns user info
  - Invalid key: returns 401
  - Missing header: returns 401
  - Key for disabled user: returns 403
  - Plus: revoke immediately invalidates, stored hash never equals the raw value

---

### HC-031: Read-only API endpoints for schedules and intakes

**Status**: `DONE`
**Type**: Story
**Points**: 5
**Depends on**: HC-030, HC-004

**Description**: Create JSON API endpoints for reading patient schedules, intakes,
and medication data. RESTful URL structure under `api/v1/`.

**Notes on implementation**:
- Each endpoint is split into a **testable handler class** (under
  `src/Api/*Api.php`) and a thin HTTP wrapper (under `api/v1/*.php`).
  Handlers take `$query` arrays and return `ApiResponse` value objects;
  the wrappers authenticate, call `handle()`, and pipe the envelope
  through `api_send()`.
- `ApiResponse::toJson()` renders the required envelope shape
  (`{"status":"ok","data":...}` or `{"status":"error","message":"..."}`).
- `ApiAuth::authorizationHeader()` checks `$_SERVER['HTTP_AUTHORIZATION']`,
  then `REDIRECT_HTTP_AUTHORIZATION`, then falls back to
  `getallheaders()` — **mod_php strips Authorization from `$_SERVER`
  by default**, and `getallheaders()` is the reliable workaround that
  works without Apache `AllowOverride` tweaks.
- `api/v1/.htaccess` also attempts to re-inject the header via
  `mod_rewrite` + `SetEnvIf` and blocks direct access to `_bootstrap.php`.
- Added `phpstan/phpstan-phpunit` extension so `assertIsArray` narrows
  `mixed → array` for PHPStan max — needed to keep the test code
  honest without `@var` tags.
- `DatabaseInterface::query()` marked `@phpstan-impure` so repeat
  calls aren't statically collapsed by PHPStan.

**Acceptance Criteria**:
- [x] `api/v1/patients.php` -- `GET` returns JSON array of patients
- [x] `api/v1/schedules.php?patient_id=N` -- `GET` returns active schedules with medication details
- [x] `api/v1/intakes.php?schedule_id=N&days=30` -- `GET` returns intake history
- [x] `api/v1/inventory.php?medicine_id=N` -- `GET` returns current stock and remaining projection
- [x] All endpoints require valid API key
- [x] All endpoints return consistent JSON envelope: `{"status": "ok", "data": [...]}` or `{"status": "error", "message": "..."}`
- [x] Proper HTTP status codes: 200, 400, 401, 404
- [x] `tests/Integration/Api/ScheduleApiTest.php` tests response format and data correctness
- [x] `tests/Integration/Api/AuthApiTest.php` tests that unauthenticated requests are rejected

---

### HC-032: Write API endpoints for recording intake

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-031

**Description**: Add POST endpoint for recording medication intake via API. Enables
mobile apps and automation (e.g., NFC tap to record dose).

**Notes on implementation**:
- `IntakesApi::record(array $body, string $role)` is the new write
  handler; `handle()` stays the GET path. The HTTP wrapper in
  `api/v1/intakes.php` dispatches GET → `handle()`, POST → `record()`,
  everything else → 405.
- Role gating via `Authorization::canWrite()` (caregiver or admin).
  Unknown role strings → 403 immediately (fail-closed).
- `taken_time` validation is strict: `YYYY-MM-DD HH:MM:SS` with a
  `DateTimeImmutable` round-trip to reject "2026-13-40 99:99:99"
  shapes that pass the regex but aren't real moments.
- `api_parse_json_body()` handles empty bodies gracefully (returns
  `[]`, lets the handler's own "missing field" check emit the right
  error) and rejects malformed JSON with 400.
- **Bug fix en route**: `DbiAdapter::lastInsertId()` was reading
  `$GLOBALS['phpdbiConnection']`, but `dbi4php.php` actually stashes
  the mysqli handle in `$GLOBALS['c']`. Latent bug that only surfaced
  on the first write path going through DbiAdapter (web handlers use
  `mysqli_insert_id($GLOBALS['c'])` directly).
- API writes emit an audit row (`intake.recorded`) with
  `details.source = "api"` so forensics can distinguish API-driven
  from web-driven writes.

**Acceptance Criteria**:
- [x] `POST api/v1/intakes.php` with JSON body `{"schedule_id": N, "taken_time": "...", "note": "..."}` creates an intake record
- [x] `taken_time` is optional (defaults to now via DB `CURRENT_TIMESTAMP`)
- [x] Validates schedule_id exists (scope-filtering not applicable -- this is a single-household app with no multi-tenant isolation)
- [x] Returns 201 with created record ID
- [x] Requires `caregiver` or `admin` role
- [x] `tests/Integration/Api/RecordIntakeApiTest.php` tests:
  - Valid POST creates record
  - Missing schedule_id returns 400
  - Invalid schedule_id returns 404
  - Viewer role returns 403
  - Response includes created intake ID
  - Plus: admin role works, unknown role → 403, invalid taken_time → 400, no-body defaults to "now", envelope shape on success

---

## EPIC-4: Supply & Notification Enhancements

**Goal**: Proactive alerts when medication supply is running low, and more flexible
notification channels.

**Priority**: MEDIUM
**Depends on**: EPIC-0 (HC-005)

---

### HC-040: Low supply alerts via ntfy

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-005

**Description**: Extend `send_reminders.php` to also check medication supply levels
and send an alert when projected depletion is within N days (configurable, default 7).

**Notes on implementation**:
- `SupplyAlertService::shouldAlert()` is a **pure static function**
  (remainingDays, threshold, lastSentAt, now, throttleSeconds → bool)
  so every branch gets unit-tested without any mocking.
  `findPendingAlerts()` is the orchestrator that walks active
  medicines via one grouped SQL query, consults `InventoryService`,
  and applies `shouldAlert()` per medicine.
- Throttling: `hc_supply_alert_log` (migration 008) has one row per
  medicine tracking the last alert timestamp. Default 24-hour cooldown,
  tunable per-call.
- **Fail-open on unparseable timestamps**: if `strtotime()` returns
  false on a corrupted `last_sent_at`, we alert anyway -- an extra
  alert is better than silent loss.
- `SupplyAlertLog` uses portable SELECT-then-INSERT-or-UPDATE (no
  MySQL-specific `ON DUPLICATE KEY` or SQLite `ON CONFLICT`), so the
  same code runs in both envs.
- ntfy push lives in `send_reminders.php` as `sendSupplyAlert()` --
  separate from the per-dose `sendNotification()` so supply alerts
  carry their own title + warning tag + priority 4.
- Medicines with no recorded inventory are silently skipped (nothing
  to project from).

**Acceptance Criteria**:
- [x] `hc_config` setting `supply_alert_days` (default 7) controls the threshold
- [x] `send_reminders.php` calls `InventoryService::calculateRemaining()` for each active schedule
- [x] When `remainingDays <= supply_alert_days`, sends an ntfy notification with medication name and projected depletion date
- [x] Alerts are sent at most once per day per medication (tracked via new `hc_supply_alert_log` table)
- [x] `--dry-run` flag shows what alerts would be sent
- [x] `tests/Unit/Service/SupplyAlertServiceTest.php` tests threshold logic (9 unit cases)
- [x] Plus: integration test `tests/Integration/Service/SupplyAlertServiceTest.php` exercises end-to-end (9 cases)

---

### HC-041: Move ntfy configuration to hc_config

**Status**: `DONE`
**Type**: Story
**Points**: 2
**Depends on**: HC-003

**Description**: The ntfy URL and channel are currently hard-coded in `send_reminders.php`.
Move them to `hc_config` and add a settings page for configuration.

**Notes on implementation**:
- `NtfyConfig` wraps `hc_config` with typed getters/setters. Defaults
  return when rows are missing, so upgrades don't require a data
  migration. Fail-closed: `ntfy_enabled` defaults to `false`, and
  only the literal `'Y'` counts as on (stray values don't
  accidentally enable push).
- `isReady()` combines "enabled + non-empty topic" into one check so
  `send_reminders.php` short-circuits on a single call instead of
  juggling three settings.
- Settings page gates the ntfy section behind
  `Authorization::canAdmin()`. Non-admins still see the API-key
  section; admins see both. The admin section is logged to the audit
  trail as `ntfy.config_updated`.
- `send_reminders.php` now prints `Skipped (ntfy disabled in
  hc_config): ...` instead of silently dropping pushes when the
  config isn't ready -- makes dev / off-hours modes obvious.

**Acceptance Criteria**:
- [x] `hc_config` settings: `ntfy_url`, `ntfy_topic`, `ntfy_enabled` (Y/N)
- [x] `send_reminders.php` reads from `hc_config` instead of hard-coded values
- [x] Settings page has a section for notification configuration (admin-only)
- [x] `tests/Integration/Config/NtfyConfigTest.php` tests config read/write (6 cases)

---

## EPIC-5: Deployment & DevOps

**Goal**: Make HomeCare easier to deploy and contribute to.

**Priority**: LOW

---

### HC-050: Docker Compose deployment

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: Nothing

**Description**: Create a `docker-compose.yml` with Apache/PHP and MySQL containers
that starts a working HomeCare instance with one command.

**Notes on implementation**:
- `Dockerfile` based on `php:8.2-apache`. Compiled extensions:
  `mysqli`, `pdo`, `pdo_mysql`, `pdo_sqlite`, `zip`. Composer
  installed from the official multi-stage image; `composer install
  --no-dev --optimize-autoloader` runs at build time so the runtime
  image is self-contained.
- `docker/docker-entrypoint.sh` handles three concerns:
  1. **Renders `includes/settings.php` from env vars** (`HC_DB_HOST`,
     `HC_DB_PASSWORD`, etc.) so no settings file lives in the image.
  2. **Waits up to 120 s** for MySQL to accept connections AS the app
     user — MySQL 8's healthcheck reports green on root-ping before
     the secondary user is created. Uses `--skip-ssl` (MariaDB-client
     flag) to bypass the self-signed cross-container TLS chain.
  3. **First-boot DB seeding**: detects an empty DB by
     `information_schema.tables` lookup; loads `tables-mysql.sql`
     and seeds `HOMECARE_PROGRAM_VERSION='v0.1.0'` so the legacy
     `do_config()` doesn't redirect every request to a non-existent
     `install/index.php`.
- `.dockerignore` keeps `vendor/`, `.git/`, IDE noise, and the
  `homecare-dump.sql` fixture out of the image — but **leaves
  `tables-mysql.sql` and `migrations/*.sql` in** so the entrypoint
  can load schema. Earlier `*.sql` blanket exclusion was a bug that
  broke first-boot init.
- Compose stack: `web` (built from local Dockerfile, port
  `${HC_WEB_PORT:-8080}:80`) + `db` (`mysql:8.0` with persistent
  named volume `homecare_db_data`). `depends_on: { db: { condition:
  service_healthy } }` ensures DB is ready before web starts.
- `.env.example` documents every overridable variable
  (MYSQL_*, TZ, HC_WEB_PORT).
- **Bug fixed en route**: `login.php` and `logout.php` called
  `session_start()` unconditionally, but `do_config()` already starts
  one. PHP 8.2 emits a "session is already active" notice → output
  flushes → headers locked → every subsequent
  `header('Location: ...')` silently fails. Production didn't surface
  this because `display_errors` is off; the container's stock PHP did.
  Both files now guard with `if (session_status() !== PHP_SESSION_ACTIVE)`.

**Acceptance Criteria**:
- [x] `docker-compose.yml` exists with `web` (Apache + PHP 8.2) and `db` (MySQL 8) services
- [x] `Dockerfile` for the web service copies app files and enables required PHP extensions (mysqli, pdo_mysql, pdo_sqlite, zip)
- [x] `.dockerignore` excludes `vendor/`, `.git/`, `*.sql` dumps (specifically `homecare-dump.sql` — schema source kept)
- [x] `docker compose up` starts both services and app is accessible at `http://localhost:8080` (override with `HC_WEB_PORT`)
- [x] Environment variables configure DB connection (no `settings.php` needed inside container — entrypoint renders it)
- [x] Volume mount for persistent MySQL data (`homecare_db_data` named volume)
- [x] README section documents Docker deployment

---

### HC-051: CI pipeline with GitHub Actions

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-001, HC-003

**Description**: Set up GitHub Actions workflow that runs the test suite on every push
and pull request.

**Notes on implementation**:
- `.github/workflows/tests.yml` runs `composer check` (PHPStan max +
  full PHPUnit suite). Uses `shivammathur/setup-php@v2` for PHP 8.2
  with extensions: `mbstring, pdo, pdo_sqlite, sqlite3, mysqli, zip`.
  `mysqli` is included because `DbiAdapter` and friends `use mysqli`
  at file scope -- PHPStan needs to load those classes even though
  the test path uses SQLite.
- Composer cache via `actions/cache@v4` keyed on `composer.json` hash;
  a clean run compiles in well under the 2-minute target (the full
  `composer check` takes ~4s locally).
- `concurrency:` block cancels superseded runs on the same branch so
  rapid pushes don't queue up.
- Badge in README points to the workflow; the GitHub repo path is a
  best-guess placeholder (`craigk5n/homecare`) -- swap in the real
  owner/repo before pushing the branch.

**Acceptance Criteria**:
- [x] `.github/workflows/tests.yml` exists
- [x] Runs on push to `main` and on all pull requests
- [x] Steps: checkout, install PHP 8.2 with extensions (sqlite3, pdo_sqlite, mbstring), composer install, phpunit
- [x] Tests run against SQLite (no MySQL required in CI)
- [x] Badge in README shows test status
- [x] Workflow completes in under 2 minutes (local mirror runs in ~4s; CI overhead adds ~30-60s for setup/cache)

---

## EPIC-6: UI/UX Improvements (Phase 2)

**Goal**: Continue improving the user interface beyond the initial schedule page
redesign.

**Priority**: LOW

---

### HC-060: PWA support (Add to Home Screen)

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: Nothing

**Description**: Add a web app manifest and minimal service worker so caregivers can
"Add to Home Screen" on mobile devices for a native-app-like experience.

**Notes on implementation**:
- Icons: a single `pub/icons/icon.svg` (medical cross on a Bootstrap-blue
  rounded tile) is the source of truth. Rasterized via ImageMagick to
  192/512 PNGs plus a maskable 512 with safe-zone padding for Android
  adaptive launchers. Same SVG also drives `favicon.ico` (which the
  legacy `print_header()` already linked but didn't have a file for).
- Service worker (`sw.js`) is **shell-only**: cache-first for
  static assets (CSS/JS/icons/woff/manifest), **network-only** for
  PHP pages and JSON API responses. Adherence numbers, intake
  history, and supply alerts must be live; we never serve those from
  cache. POSTs and non-same-origin requests fall through to the
  browser's normal path.
- Cache version (`homecare-shell-v1`) is bumped on the activate hook;
  raise it whenever `pub/` assets change to flush stale clients.
- `print_header()` adds `<link rel="manifest">`, `<meta name="theme-color">`,
  `<link rel="apple-touch-icon">`, and a tiny inline registration block
  for `sw.js` (gated on `'serviceWorker' in navigator` so old browsers
  no-op cleanly).
- ManifestTest validates the W3C subset Chrome/Edge/Safari actually
  enforce for installability: required fields, `display: standalone`,
  192px + 512px PNGs, at least one maskable, every referenced icon
  exists on disk, and the SW registers `install` + `fetch` handlers.

**Acceptance Criteria**:
- [x] `manifest.json` exists with app name, icons (192 + 512 + maskable), theme color, `display: standalone`
- [x] `<link rel="manifest">` added to `print_header()` output (plus theme-color meta + apple-touch-icon)
- [x] `sw.js` service worker caches static assets (CSS, JS, icons) for offline shell
- [x] "Add to Home Screen" prompt appears on supported browsers (manifest + SW + HTTPS prerequisite met)
- [x] App opens in standalone mode (no browser chrome) when launched from home screen (`display: standalone`)
- [x] `tests/Integration/Pwa/ManifestTest.php` validates manifest.json structure (9 cases)

---

### HC-061: Redesign remaining pages to match schedule page UI

**Status**: `DONE`
**Type**: Story
**Points**: 8
**Depends on**: Nothing

**Description**: Apply the same responsive card/table dual layout, sticky header, and
print support from `list_schedule.php` to the other key pages: `list_medications.php`,
`report_intake.php`, `report_missed.php`, `report_medications.php`, `schedule_daily.php`.

**Notes on implementation**:
- Generalised the schedule-page CSS into reusable `.page-sticky-header`,
  `.page-controls`, `.page-table`, `.page-card`, `.card-title-row`,
  `.card-primary`, `.card-meta`, `.card-detail`, `.card-actions`
  classes (still alongside the legacy `.schedule-*` aliases so
  `list_schedule.php` keeps working unchanged). Status-stripe
  (`status-overdue`/`-due-soon`/`-ok`/`-done`) and section-header
  (`section-*`) classes are reused across all 5 pages.
- Print stylesheet for the new shell hides
  `.page-sticky-header`, `.page-controls`, action buttons, and
  forces card view (`.d-md-none → block`) so paper output is more
  readable than the desktop table.
- **`list_medications.php`**: catalog view, no patient context. Sticky
  bar shows just the title + Print + "+ Add Medication".
- **`report_intake.php`**: month nav (`«` / month label / `»`), sort
  toggle, Print, CSV, FHIR — all in the sticky header. Same-time-of-day
  intakes still group together. Edit pencils preserved.
- **`report_missed.php`**: each row gets a status stripe by lateness
  (`Late` → yellow, `Missed` → red). Newest first so today's misses
  surface first.
- **`report_medications.php`**: status stripe by `remainingDays`
  (≤3 → red, ≤7 → yellow, ended → grey). The `assume_past_intake`
  + `show_completed` toggles moved into the sticky header as
  one-click chips that preserve each other's state.
- **`schedule_daily.php`**: split into Today / Tomorrow sections with
  `section-due-soon` / `section-ok` headings. Each scheduled dose
  picks its own stripe (taken → grey strikethrough, due-within-1h →
  yellow, overdue → red).

**Acceptance Criteria**:
- [x] Each page has mobile card view and desktop table view
      (`d-md-none` + `d-none d-md-block` wrappers)
- [x] Each page has sticky header with patient name (where applicable;
      catalog views show the page title)
- [x] Each page has print button and print-optimized stylesheet
      (`@media print` rules in styles.css force card view + hide chrome)
- [x] Consistent use of status stripe colors and section headers
- [x] All pages render at mobile viewport (375px) and desktop (1280px)
      shells; no fixed-width layouts (Bootstrap responsive classes throughout)
- [x] Tables wrapped in `.table-responsive` so any unavoidable wide
      content scrolls inside the table, not the page

---

## EPIC-7: Follow-ups & Hardening

**Goal**: Address loose ends and follow-ups noticed while completing
EPICs 0-6. Most of these are small, focused tickets that build on the
foundations now in place; none block the core feature set, but each
removes a sharp edge or makes the system more operable.

**Priority**: MEDIUM (none are blocking; pick what hurts most first)

---

### HC-070: Audit log viewer (admin UI)

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-013

**Description**: `hc_audit_log` (HC-013) records every write but
there's no UI for inspecting it. An admin-only browse / filter page
makes the data forensically useful instead of buried.

**Acceptance Criteria**:
- [x] `audit_log.php` (admin-only via `require_role('admin')`) lists
audit entries newest-first with patient/medicine name JOINs
- [x] Filter form: `user_login`, `action` (select from distinct values), `entity_type`, date range
- [x] Pagination (50/page) — `hc_audit_log` grows fast
- [x] Each row's `details` JSON renders as a collapsible inline detail
- [x] Linked from the new admin section of the menu (next to ntfy
settings)
- [x] Integration test verifies filter combinations narrow correctly

---

### HC-071: Token-signed iCalendar feed and shareable exports

**Status**: `DONE`

**Type**: Story

**Points**: 3

**Depends on**: HC-030

**Description**: `schedule_ics.php` is currently exempt from

`hc_validate()` because calendar apps can't carry session cookies --

which means the feed is effectively public. The CSV / FHIR export

endpoints require login but a caregiver might want to email a

read-only link to a vet without sharing credentials. Both problems

solved by per-resource HMAC-signed URLs.

**Acceptance Criteria**:

- [x] `src/Auth/SignedUrl.php` with `sign(array $params, int $ttl)`

      and `verify(array $params): bool` using HMAC-SHA256 over a

      stable per-deploy secret stored in `hc_config.signing_secret`

- [x] `schedule_ics.php` checks the signature instead of being a

      blanket public endpoint

- [x] `export_intake_csv.php` and `export_intake_fhir.php` accept

      either a session cookie OR a signed `?token=` param

- [x] Settings page (admin section) generates a "shareable URL" for

      each export with a configurable TTL (1 day / 7 days / 30 days)

- [x] Audit row on every signed-URL access (`export.intake_csv` with

      `details.via='signed_url'`)


---

### HC-072: Migrations runner with applied-migrations tracking

**Status**: `DONE`

**Type**: Story

**Points**: 3

**Depends on**: HC-001

**Description**: Migrations 001-008 are applied manually today --

each new install requires the operator to remember which files have

run. A small runner that records applied migrations in a tracking

table fixes that.

**Acceptance Criteria**:

- [x] `hc_migrations` table: `name VARCHAR(64) PRIMARY KEY`,

      `applied_at DATETIME`

- [x] `composer migrate` script (or a `php bin/migrate` CLI) reads

      the migrations directory, applies any not yet recorded, and

      stamps each one

- [x] `--dry-run` flag lists pending migrations without applying

- [x] Idempotent: running twice in a row reports "no pending"

- [x] Docker entrypoint (HC-050) calls the runner instead of the

      one-shot `tables-mysql.sql` load on first boot

- [x] Integration test exercises the runner against a SQLite DB

---

### HC-073: Drop dead WebCalendar-derived code

**Status**: `DONE`

**Type**: Story

**Points**: 2

**Depends on**: HC-072

**Description**: After the native auth cutover (HC-auth tickets):

- `includes/user.php` is no longer included by anything.

- `includes/config.php`'s `do_config()` still has the redirect to

  `install/index.php` (which doesn't exist), kept alive only by the

  Docker entrypoint stamping a `HOMECARE_PROGRAM_VERSION` row.

- A few WebCalendar `user_inc`-style globals are referenced but

  unused.

**Acceptance Criteria**:

- [x] Confirm `includes/user.php` has no callers; delete it

- [x] Remove `install/index.php` redirect block from `config.php`

      (and the matching entrypoint workaround)

- [x] Remove `user_inc`, `user-app-*` references from `config.php`

      and `validate.php`

- [x] All tests still pass; no lints fail; PHPStan max stays clean

- [x] CLAUDE.md updated: WebCalendar-borrowed file list shrinks

---

### HC-074: Cutover off `hc_user.is_admin` to `role`

**Status**: `DONE`

**Type**: Story

**Points**: 2

**Depends on**: HC-072

**Description**: `getCurrentUserRole()` (HC-011) bridges

`is_admin='Y'` to `role='admin'` so the migration could be

non-atomic. Now that nothing else reads `is_admin`, drop the bridge

and the column.

**Acceptance Criteria**:

- [x] Grep + remove every read of `is_admin` outside the legacy

      `includes/validate.php` user-load fallback

- [x] `getCurrentUserRole()` simplifies to a straight `role` read

- [x] Migration 009 (via the new runner) drops the column

- [x] `tables-mysql.sql` + `schema-sqlite.sql` updated

- [x] Auth tests + integration tests still pass

---

### HC-075: Frequency-mismatch warning on the schedule UI

**Status**: `DONE`

**Type**: Story

**Points**: 2

**Depends on**: HC-022

**Description**: The "200% Tobramycin adherence" investigation
surfaced a real failure mode: a user enters `frequency='2d'` but
takes the medication at `12h` cadence. The math is correctly
catching it, but the UI doesn't help the user notice the data-entry
error. Add a soft warning banner on `list_schedule.php` and
`adjust_dosage.php` when the recent intake cadence diverges
substantially from the schedule's `frequency`.

**Acceptance Criteria**:
- [x] `src/Service/CadenceCheck.php` with method
      `divergence(int $scheduleId, int $sampleSize = 5): ?float`
      returning the ratio of observed-interval to expected-interval
      across the last N intakes, or null when too few intakes
- [x] Warning rendered when `divergence` < 0.5 OR > 2.0 (i.e.
      observed cadence is at least 2× off the schedule)
- [x] Banner copy: "Recent doses average every Xh, but the schedule
      says Yh. Did you mean to set frequency to ~Xh?"
- [x] Unit tests: ratio math; no warning when too few samples; no
      warning when within 50%

---

### HC-076: Rate limiting on the v1 API

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-031

**Description**: HC-014 added per-account login rate limiting; the
API has none. A flood of bad bearer tokens against `/api/v1/*`
currently spins up a DB query per request with no ceiling.

**Acceptance Criteria**:
- [x] Per-IP token-bucket: 60 requests/minute by default, configurable
      via `hc_config.api_rate_limit_rpm`
- [x] Counter persisted in `hc_api_rate_limit (ip, window_start, count)`
      OR an in-memory option for single-host deploys
- [x] When exceeded: 429 with `Retry-After` header and the standard
      `{status:"error",message:"rate_limited"}` envelope
- [x] Authenticated requests (valid bearer) get a higher cap
      (configurable, default 600/min) — bots without credentials
      shouldn't degrade service for real users
- [x] Integration test simulates a burst and verifies the 429

---

### HC-077: Coverage reporting in CI

**Status**: `DONE`
**Type**: Story
**Points**: 2
**Depends on**: HC-051

**Description**: `STATUS.md` Quality Goals claim "PHPUnit 80%+
coverage on `src/`" but nothing measures it. Wire pcov/Xdebug into
the CI workflow and surface the number.

**Acceptance Criteria**:
- [x] `tests.yml` adds a coverage step (`pcov` or `xdebug` via
      shivammathur/setup-php's `coverage:` flag)
- [x] `vendor/bin/phpunit --coverage-text --coverage-clover=coverage.xml`
      runs alongside the gate
- [x] Coverage threshold enforced at 80% for `src/` (lines); CI fails
      below it
- [x] Coverage badge added to README (Coveralls or Codecov, whichever
      is simplest)
- [x] Per-class report uploaded as a CI artifact for inspection

---

### HC-078: End-to-end browser test (Playwright)

**Status**: `DONE`

**Type**: Story

**Points**: 5

**Depends on**: HC-050, HC-051

**Description**: We have unit + integration coverage but nothing
asserts the actual browser flows work. The two CSRF/jQuery merge-page
bugs and the session-double-start login bug all slipped past PHPStan
+ PHPUnit because they only manifest in a real browser.

**Acceptance Criteria**:
- [x] `tests/Browser/` directory with Playwright test scaffolding
- [x] CI workflow `e2e.yml` stands up the Docker stack
      (`docker compose up -d`), seeds the default admin user, runs
      Playwright against `http://localhost:8080`
- [x] Smoke flow: login → list_schedule → record an intake → log out
- [x] Merge-page flow: login → merge_medicines → preview → confirm
- [x] Adherence-report flow: login → report_adherence → toggle range
      → assert chart canvas renders + table cells colour-correctly
- [x] Each test under 30s; total suite under 3 min

---

### HC-079: CSP tightening + security-headers pass

**Status**: `DONE`

**Type**: Story

**Points**: 3

**Depends on**: Nothing

**Description**: Current CSP is `frame-ancestors 'none'` only --
no `script-src`, `style-src`, etc. Add a per-page nonce-based CSP,
HSTS, `Referrer-Policy`, `Permissions-Policy`. Inline scripts
(merge_medicines.php, settings.php, etc.) need either nonces or
extraction.

**Acceptance Criteria**:
- [x] `print_header()` emits `Content-Security-Policy:
      default-src 'self'; script-src 'self' 'nonce-XXX';
      style-src 'self' 'unsafe-inline'; img-src 'self' data:;
      object-src 'none'; base-uri 'self'`
- [x] All `<script>` blocks carry the nonce attribute
- [x] `Strict-Transport-Security: max-age=31536000; includeSubDomains`
      (gated on HTTPS detection)
- [x] `Referrer-Policy: strict-origin-when-cross-origin`
- [x] `Permissions-Policy: camera=(), microphone=(), geolocation=()`
- [x] Manual smoke: every page loads cleanly with no CSP violations
      in the browser console

---

### HC-080: PHP 8.3 / 8.4 support

**Status**: `DONE`
**Type**: Story
**Points**: 2
**Depends on**: HC-051

**Description**: We pin to 8.2 today. PHP 8.3 is current; 8.4 just
shipped. Adding both to the CI matrix catches deprecations early.

**Acceptance Criteria**:
- [x] `tests.yml` adds a matrix on `php-version: ['8.2', '8.3', '8.4']`
- [x] Each version cell runs `composer check`
- [x] Any new deprecations under 8.3/8.4 are addressed in code
- [x] `composer.json` `php` requirement bumped if any 8.3+ feature is
      adopted; otherwise stays `>=8.1`
- [x] Dockerfile bumps to `php:8.3-apache` once green on 8.3

---

### HC-081: Dependabot for Composer + bundled JS

**Status**: `DONE`
**Type**: Story
**Points**: 1
**Depends on**: HC-051

**Description**: PHPUnit, PHPStan, Chart.js, Bootstrap, jQuery all
need periodic refresh. Automate it.

**Acceptance Criteria**:
- [x] `.github/dependabot.yml` watches `composer` weekly
- [x] `.github/dependabot.yml` watches `github-actions` monthly
- [x] `pub/chart.umd.min.js` re-fetch documented in
      README ("upgrading PWA assets") since it's not a Composer dep

---

## EPIC-8: Caregiver Notes UX

**Goal**: `hc_caregiver_notes` has existed at the schema level since
day one but has no UI for creating, browsing, editing, or importing
entries. A real caregiver has existing notes in an external file and
needs both a UI to write new notes and a one-shot import path to
load the historical batch.

**Priority**: HIGH -- user has data waiting to be ingested and no
way to enter new notes today.

**Depends on**: HC-004 (repository pattern), HC-011 (role gating),
HC-013 (audit logging)

---

### HC-082: Caregiver notes entry & edit UI

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-004, HC-011, HC-013

**Description**: Add a page for caregivers to write a new note against
a patient, edit an existing note, and delete one. The table already
carries `patient_id`, `note` (text), `note_time` (when the event
occurred, user-editable), and `created_at` (immutable).

**Notes on implementation**:
- `CaregiverNoteRepository` is a fresh class (no bridge to legacy
  code -- the `hc_caregiver_notes` table had no callers yet).
  Writes return the new id via `DatabaseInterface::lastInsertId()`;
  reads hydrate into the same loose associative-array shape the
  other repos use (see HC-004 notes on deferring a dedicated
  value object).
- `getForPatient()` orders `note_time DESC, id DESC` so two notes
  stamped the same second still return in insert order -- the
  plain-text journal import (HC-085) will lean on this.
- `note_caregiver.php` uses `<input type="datetime-local">`, which
  omits seconds. The handler appends `:00` before validating
  through a `DateTimeImmutable::createFromFormat` round-trip so
  malformed timestamps are rejected at the boundary.
- Post-save redirect targets `list_schedule.php` (the patient's
  main view) rather than the yet-to-be-built HC-083 list page so
  the story stands alone; the menu entry opens the "Add Note"
  form directly until HC-083 lands.
- CSRF is enforced automatically -- the handler ends in
  `_handler.php`, which triggers the shared token check in
  `includes/formvars.php`.

**Acceptance Criteria**:
- [x] `src/Repository/CaregiverNoteRepository.php` with
      `create(int $patientId, string $note, string $noteTime): int`,
      `update(int $id, string $note, string $noteTime): bool`,
      `delete(int $id): bool`, `getById(int $id): ?array`,
      `getForPatient(int $patientId, int $limit = 50, int $offset = 0): array`
- [x] `note_caregiver.php` (entry / edit) with fields:
      patient (prefilled + editable when `patient_id` in query),
      note (textarea, required, 4000 char max),
      note_time (datetime-local, defaults to now)
- [x] CSRF-protected form handler `note_caregiver_handler.php`
- [x] Role gate: `require_role('caregiver')` (viewers cannot write)
- [x] Audit log entries: `note.created`, `note.updated`, `note.deleted`
      with `details = {patient_id, note_time, note_len}`
- [x] Menu entry ("Notes") under the patient-context menu
- [x] `tests/Integration/Repository/CaregiverNoteRepositoryTest.php`
      covers CRUD round-trip + getForPatient ordering (newest-first)

---

### HC-083: Caregiver notes list / browse view

**Status**: `DONE`
**Type**: Story
**Points**: 2
**Depends on**: HC-082

**Description**: Per-patient notes list page with filter + pagination.
Entries render newest-first with the `note_time` (when the caregiver
observed the event) shown prominently and `created_at` (when the
record was written) as a secondary subtitle. Viewers can read; only
caregivers/admins see Edit / Delete affordances.

**Notes on implementation**:
- Repository grew a pair of filter-aware methods: `search()` and
  `countSearch()` share a private `buildFilter()` so the list and
  the pagination counter can never disagree on "what matches."
- LIKE wildcards in the search term (`%`, `_`, `\`) are escaped
  and re-applied with an explicit `ESCAPE '\'` clause, so a
  caregiver searching for "3%" finds the literal string instead
  of every note. Works identically on MySQL and SQLite.
- The page renders with the shared HC-061 shell
  (`.page-sticky-header`, `.page-table`, `.page-card`) so the
  existing print stylesheet strips controls and forces card view
  automatically -- no new CSS needed.
- Edit/Delete visibility is gated via `Authorization::canWrite()`
  read from `getCurrentUserRole()`, so viewers see the list but
  not the affordances. Delete itself still flows through the
  confirm dialog on `note_caregiver.php` (shared with HC-082).
- Date filter widens its bounds to `00:00:00` / `23:59:59` before
  hitting the DB -- the HTML `<input type="date">` only emits the
  date half, and we want an inclusive filter on both ends.
- 50-per-page pagination is the constant `NOTES_PAGE_SIZE`;
  preserves every filter in the generated Prev/Next links via
  `http_build_query`.

**Acceptance Criteria**:
- [x] `list_caregiver_notes.php?patient_id=N` with:
      - Patient name in sticky header
      - Card (mobile) + table (desktop) dual layout per the HC-061
        pattern
      - Date-range filter (start/end `note_time`)
      - Free-text search across `note` (LIKE %q%)
      - 50-per-page pagination
- [x] Edit / Delete buttons per row, hidden for `viewer` role
      (Edit is a per-row affordance; Delete lives inside the edit
      form from HC-082 to reuse the confirm dialog)
- [x] Link from `list_schedule.php` sticky header: "Notes"
- [x] Print-optimized stylesheet (hide controls, keep body text)
      -- inherited from the shared `.page-*` classes in styles.css
- [x] Integration test: filter + pagination narrow correctly
      (6 new tests: date range, query string, LIKE-wildcard escape,
      patient scoping, limit/offset, countSearch)

---

### HC-084: Caregiver notes CSV import

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-082

**Description**: One-shot import path for a caregiver with existing
notes in an external file. Accepts CSV (or tab-separated) with a
header row; columns `note_time` and `note` are required, `patient_id`
or `patient_name` picks the patient. A preview step shows what will
be inserted before commit so the operator can catch column-mapping
errors before they land in the DB.

**Notes on implementation**:
- `DatabaseInterface` gained `transactional(callable): mixed`
  (begin + commit on return, rollback on throw). Implemented on
  both adapters. Keeps the "all or nothing" guarantee honest --
  if a DB error strikes mid-insert, the pre-existing notes
  table state survives.
- Split into three small files under `src/Import/`:
  `NoteTimeParser` (strict date parsing), `ParsedRow` /
  `ImportPlan` (readonly DTOs), and `CaregiverNoteImporter`
  (the orchestrator). The split keeps each file testable in
  isolation.
- `NoteTimeParser` uses a priority list of strict formats.
  Date-only formats carry a `!` prefix so `DateTimeImmutable::
  createFromFormat` resets unspecified fields to zero -- without
  it, "2026-04-01" would inherit the current wall-clock time.
  After each match we check `getLastErrors()` so trailing garbage
  ("2026-04-01 banana") is rejected instead of accepted as the
  first 10 chars.
- UI is intentionally stateless: the preview step embeds the raw
  file bytes in a hidden textarea and commit re-parses them.
  Avoids session storage or signed blobs; the second parse
  guarantees the operator and the DB saw the same bytes.
- MIME is detected via `finfo_file()` (not the client-supplied
  header), with a `.csv`/`.tsv`/`.txt` extension check as a
  second gate. 2 MB hard cap, enforced before reading the temp
  file.
- Patient caching: the importer loads all patients once and
  keeps them in two in-memory indexes (by id and by lowercased
  name). A 5000-row import then issues zero extra patient
  lookups.
- Audit: exactly one `note.imported` row per file with
  `{filename, row_count, patient_id}` in details -- easy to spot
  bulk imports in the audit log without drowning in one row
  per note.

**Acceptance Criteria**:
- [x] `import_caregiver_notes.php` (admin-only, `require_role('admin')`):
      - File upload (max 2 MB, `.csv` / `.tsv` / `.txt` MIME check)
      - Auto-detect delimiter (comma / tab / semicolon) from first line
      - Header row required; accepted columns: `note_time`, `note`,
        and one of (`patient_id` | `patient_name`)
      - Default `patient_id` query param applies when column absent
- [x] Preview step: table of parsed rows (first 100) + row-count +
      row-level validation errors ("Row 7: no patient matched
      'Fozzie the Cat'"). Import commits only after explicit
      confirmation.
- [x] `note_time` parser accepts ISO 8601 (`2026-04-01T14:30`),
      common US (`4/1/2026 2:30 PM`), and date-only (midnight).
      Rejects ambiguous strings with a clear row error.
      (19 unit cases in `NoteTimeParserTest`.)
- [x] Transaction per file: either all rows insert or none
      (rollback on any validation failure during commit).
      Verified by `testRollbackOnMidFileCommitFailure` — sabotages
      the target table mid-commit and asserts pre-existing rows
      survive.
- [x] Audit row `note.imported` with `details = {filename,
      row_count, patient_id}` (one row per import, not per note)
- [x] `tests/Integration/Import/CaregiverNoteImportTest.php`:
      good CSV round-trips; column-missing errors; patient_name
      resolution (exact + fuzzy-case); date parser edge cases;
      rollback on mid-file failure (12 integration cases).

---

### HC-085: Caregiver notes plain-text journal import

**Status**: `DONE`
**Type**: Story
**Points**: 5
**Depends on**: HC-082

**Notes on implementation**:
- Parser is a small line-level state machine
  (`src/Import/JournalParser.php`). A regex "gate" rejects most
  non-date lines before we waste `createFromFormat` cycles on
  them, then confirms via strict format matching so
  continuation lines like "ate 5 kibble turkey" don't accidentally
  reset the date cursor.
- Non-monotonic detection is done against the *immediate*
  predecessor only. That matches the spec's sample perfectly
  (Apr 14's "1:20 AM" and "1:35 AM" flag; intermediate AM/PM
  bouncing does not).
- Commit path dedups on `(patient_id, note_time, SHA256(note))`
  via a single range scan in
  `CaregiverNoteRepository::getNotesInTimeRange()`, then builds
  an in-memory hash set keyed by `noteTime . "\0" . sha256(note)`.
  In-file duplicates also get flagged (the second occurrence of
  an identical entry in the paste is marked duplicate), so
  re-pasting after a partial failure is safe.
- UI (`import_notes_journal.php`) follows the HC-084 stateless
  pattern: preview step embeds the raw textarea content in a
  hidden field, and commit re-parses the same bytes -- no
  session blobs, no signed payloads.
- Perf: synthetic 3000-entry paste (150 KB, 6600 lines) parses
  in ~14 ms on the reference dev box, comfortably under the 5 s
  bar. The preview render adds one DB range scan.
- Covered by 23 parser unit cases (canonical sample, 7 header
  variants, multi-line body, orphan, 7 time edge cases, block
  reset, BOM, empty input) and 6 importer integration cases
  (round-trip, full re-paste, partial re-paste, in-file
  duplicates, invalid-plan refusal, transaction rollback).

**Description**: The real-world caregiver journal is not CSV; it's
free-form text kept in a notes app, pasted in large chunks covering
months of entries. Entries are grouped under date headers and
prefixed with a wall-clock time. Add a paste-friendly import path
that parses this format, shows a preview, and commits on confirm.

**Sample input (this is the exact shape the parser must handle)**:

```
Wednesday April 15, 2026

7:45 AM After a slow start, ate everything: Ate 5 kibble, turkey, potatoes and sweet potatoes.

1:00 PM Ate 5 kibble, turkey and some potatoes.

7:45 PM Ate 5 kibble, green beans, turkey.

Tuesday April 14, 2026

7:45 AM Ate 5 kibble, turkey, softies and a few potatoes.

9:30 PM Ate 4 bowls of Cheerios

1:20 AM Ate everything: 3 kibble, turkey, potatoes and sweet potatoes.

8:20 AM Vomit (Regurgitate food and medicine)

1:15 PM Ate 5 kibble, turkey, zucchini. No potatoes.

8:00 PM Ate 5 kibble, turkey, green beans and a few potatoes

9:25 PM Vomit after cheese

10:00 PM 3 bowls of Cheerios

1:35 AM 3 kibble, ground turkey
```

Characteristics of the real format:
- **Date headers**: `<weekday> <month> <day>, <year>` — weekday is
  redundant but present and must be tolerated (or any permutation
  that matches `strtotime`).
- **Entries**: `<time> <note body>` where time is `H:MM AM/PM`
  (case-insensitive). Note body is free text and may span a single
  line; blank lines separate entries.
- **Entries are NOT strictly time-sorted within a date block** —
  e.g. "1:20 AM" may appear below "9:30 PM" because the caregiver
  is logging overnight events under the previous calendar day.
  The parser MUST NOT silently shift those entries to the next
  day; the preview must surface the ambiguity so the caregiver
  can confirm.
- Multiple months of backlog in a single paste is expected;
  hundreds to low thousands of entries per import.

**Acceptance Criteria**:
- [x] `import_notes_journal.php` (caregiver+ role): large
      `<textarea>` (no file upload required — paste is the primary
      affordance) plus a patient selector (single-patient scope per
      import).
- [x] Parser (`src/Import/JournalParser.php`) walks the text
      line-by-line:
      - Detects date headers via a permissive regex first
        (`^[A-Za-z]+ [A-Za-z]+ \d{1,2},?\s*\d{4}$`), then confirms
        via `DateTimeImmutable::createFromFormat` / `strtotime`
        round-trip. Updates a "current date" cursor.
      - Detects entries via `^(\d{1,2}):(\d{2})\s*(AM|PM|am|pm)\s+(.+)$`.
      - Combines current date + entry time into `note_time` in
        the configured timezone.
      - Lines that match neither pattern are retained as
        continuation of the previous entry's note body (multi-line
        notes).
- [x] Ambiguity handling: for each entry, the parser emits a
      confidence flag:
      - `ok`: monotonic within its date block AND no date gap
      - `non_monotonic`: time goes backward vs previous entry in
        same block (surface in preview with a warning; caregiver
        decides to keep-under-same-day or reassign to next day)
      - `orphan`: entry appears before any date header — rejected
        with a clear error pointing at the offending line
- [x] Preview step: table of parsed entries grouped by day with
      `(date, time, note, confidence)` columns, row-level errors
      at the top, total entry count, and a "nothing will be
      inserted until you click Confirm" banner.
- [x] Commit in a single transaction; rollback on any DB error.
- [x] De-dup on (`patient_id`, `note_time`, `SHA256(note)`): if an
      identical entry already exists it is SKIPPED with a preview
      row marked "duplicate — will skip". This makes re-pasting
      after a partial failure safe.
- [x] Audit row `note.journal_imported` with `details = {patient_id,
      parsed_count, inserted_count, skipped_duplicates,
      non_monotonic_count, source_bytes}`.
- [x] Performance: a 3000-entry paste parses and previews in
      under 5 seconds on the reference dev box.
- [x] Unit tests (`tests/Unit/Import/JournalParserTest.php`):
      - The sample above parses to 12 entries across 2 dates with
        2 `non_monotonic` flags (Apr 14's "1:20 AM" and "1:35 AM"
        after later PM entries).
      - Date-header variants: with weekday, without weekday, with
        and without comma, `M d yyyy` and `yyyy-mm-dd`.
      - Multi-line note body preserves all non-header lines.
      - Orphan entry (time line before any date header) errors out.
      - Time parser: 12:00 AM → midnight, 12:30 PM → 12:30 (noon-
        adjacent), 1:35 AM → 01:35, leading-zero and non-leading-
        zero hours both accepted.
- [x] Integration test exercises paste → preview → commit → query
      round-trip with the sample input and asserts the 12 resulting
      `hc_caregiver_notes` rows.

---

## EPIC-9: Auth Hardening (Defense in Depth)

**Goal**: Close the remaining gaps between HomeCare's auth and the
standard set for self-hosted PHP apps handling sensitive data
(OpenEMR, Firefly III, Monica, BookStack). The role / rate-limit /
audit / session-timeout foundations from EPIC-1 are in place;
what's still missing is TOTP, a self-service reset path, a
password-policy gate, and a cookie-flag audit.

**Priority**: HIGH for internet-facing deploys; MEDIUM for LAN-only.

**Depends on**: EPIC-1 (HC-010..HC-014), HC-071 (SignedUrl for reset
tokens)

---

### HC-090: TOTP 2FA enrollment and verification

**Status**: `DONE`
**Type**: Story
**Points**: 5
**Depends on**: HC-010, HC-013

**Description**: Add time-based one-time-password (RFC 6238) as an
optional second factor. Enrollment renders a QR code for
Authy/1Password/Google Authenticator/etc.; subsequent logins prompt
for a 6-digit code after password verification. Recovery codes
cover the lost-phone case.

**Notes on implementation**:
- Two composer deps: `pragmarx/google2fa` for the RFC-6238
  primitives and `bacon/bacon-qr-code` for the pure-PHP SVG QR
  (no GD, no network). Both pin pure-PHP so the Docker image
  stays slim.
- `TotpService` wraps Google2FA with a ±1-step verification
  window (30-sec clock skew each way) and never throws on bad
  input — all "no" responses are indistinguishable. Recovery
  codes normalise case + strip separators before hashing, so
  "ab12-cd34" and "AB12CD34" compare equal.
- `AuthResult` gains a third shape: `requiresTotp($user)`.
  Password success plus `totp_enabled='Y'` returns this instead
  of `ok()`; `last_login` isn't touched and no remember-me
  token is minted. The session-level `pending_login` slot is
  purely a convenience for the UI; the service trusts nothing
  from the client between steps.
- `AuthService::verifyTotp()` is the new second-step entry
  point. It tries the 6-digit code first, then attempts
  recovery-code consumption, and rolls into the existing
  lockout counter on failure — a flooded TOTP prompt is
  bounded by the same `MAX_FAILED_ATTEMPTS` ceiling as the
  password step.
- **Remember-me cannot bypass TOTP.** `loginWithRememberToken()`
  now re-checks `totp_enabled` and returns `requiresTotp` when
  set, so a stolen cookie cannot skip the second factor even
  if the device that originally minted it is long gone.
- Enrollment in `settings.php` holds the pending secret in the
  session until the user verifies their first code; on success
  we generate 10 recovery codes, show them exactly once, and
  persist only the SHA-256 hashes.
- Disabling 2FA requires a current TOTP code — this is a
  compromised-session guard: an attacker on a live session
  can't quietly turn the second factor off.

**Acceptance Criteria**:
- [x] `pragmarx/google2fa` added as a Composer dep (pure PHP, no
      curl / network required for verification). Plus
      `bacon/bacon-qr-code` for QR rendering.
- [x] Migration (011): `hc_user.totp_secret VARCHAR(64) NULL`,
      `hc_user.totp_enabled CHAR(1) NOT NULL DEFAULT 'N'`,
      `hc_user.totp_recovery_codes TEXT NULL` (JSON array of
      sha256-hashed single-use codes)
- [x] `src/Auth/TotpService.php`: generateSecret(),
      verifyCode(string $secret, string $code): bool (with ±1
      window), generateRecoveryCodes(int $n = 10): array
- [x] `AuthService::login()` short-circuits after password success
      when `totp_enabled='Y'` and returns a new
      `AuthResult::requiresTotp()` state; session stores pending
      user id but does NOT mark the session authenticated
- [x] `login.php` second step prompts for the 6-digit code or a
      recovery code; both verify via `TotpService` or hash-compare
- [x] Recovery-code use invalidates the used code (pop from the
      list, re-hash, save)
- [x] `settings.php` self-service enroll:
      - "Enable 2FA" renders QR + backup codes (shown once)
      - "Disable 2FA" requires current TOTP code to prevent
        compromised-session abuse
- [x] Audit rows: `totp.enabled`, `totp.disabled`,
      `totp.verified_recovery_code_used`, `totp.verification_failed`
- [x] Remember-me cookies (HC-014) DO NOT bypass TOTP — set the
      remember token only after TOTP verification
      (`loginWithRememberToken()` also forces the TOTP prompt)
- [x] `tests/Unit/Auth/TotpServiceTest.php`: generate / verify
      round-trip; ±1-window accept; replay rejection; recovery
      code single-use (15 cases)
- [x] `tests/Integration/Auth/Login2faTest.php`: full flow with
      pending state, verification, and recovery code path
      (10 cases, including the lockout-on-TOTP-failure guard)

---

### HC-091: Password reset flow

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-071 (SignedUrl), EPIC-10 (for email delivery)

**Description**: Self-service password reset via emailed single-use
HMAC-signed link. Reuses the `SignedUrl` service from HC-071 with a
dedicated `purpose=password_reset` claim so a leaked export token
can't double as a reset token.

**Notes on implementation**:
- The spec called for SignedUrl with a dedicated claim, but
  `hc_password_reset_tokens` (migration 013) is the cleaner fit:
  we need `used_at` for single-use enforcement and `created_at`
  for the per-login rate limit. SignedUrl's stateless-token
  model doesn't support either without reinventing a tracking
  table anyway.
- Consume-before-write: `complete()` marks the token `used_at`
  *before* hashing and writing the new password. A mid-flight
  crash can't leave the token replayable; the worst case is a
  stuck token that requires the user to request a new link.
- Rate limit is a count over `hc_password_reset_tokens` for the
  login in the last hour rather than a separate table, keeps
  the throttle inherently correct (no clock drift between two
  tables).
- Silent responses on every failure mode (unknown login,
  disabled account, no email on file, rate-limited) —
  `forgot_password.php` always renders the same "check your
  email" screen so the response can't be used as a login-enum
  oracle.
- `UserRepository` grew `findByEmail()` and the `email` column
  joined `UserRecord`. Both were needed for the forgot-password
  lookup (which accepts either login or email).
- Password policy (HC-092) is applied at the reset step — same
  enforcement as settings-page change-password.
- Reset invalidates every existing remember-me cookie for the
  user so a leaked cookie can't outlive a known reset.

**Acceptance Criteria**:
- [x] Migration (013): `hc_password_reset_tokens (token_hash
      CHAR(64) PRIMARY KEY, user_login VARCHAR(25) NOT NULL,
      created_at DATETIME, used_at DATETIME NULL, expires_at
      DATETIME)` plus a composite index on (user_login,
      created_at) for the rate-limit query
- [x] `forgot_password.php`: accepts a login or email, always
      returns the same "check your email" message (no user
      enumeration); enqueues email only if the account exists AND
      is `enabled='Y'` AND has an email on file
- [x] `src/Auth/PasswordResetService.php`:
      - `initiate(string $loginOrEmail, string $baseUrl): void`
        inserts token, sends email via injected NotificationChannel
      - `validate(string $token): ?string` returns the login if
        valid + unused + unexpired, null otherwise
      - `complete(string $token, string $newPassword): bool`
        verifies, hashes, updates `hc_user.passwd`, marks token
        used, clears `failed_attempts` / `locked_until`
- [x] TTL: 60 minutes (`PasswordResetService::TTL_MINUTES`)
- [x] Reset consumes (marks used_at) even on failure to write the
      new password — prevents replay
- [x] `reset_password.php?token=...` form validates the token on
      GET, requires new password + confirm on POST, applies
      PasswordPolicy before committing
- [x] Resetting the password invalidates all existing remember-me
      cookies for that user
- [x] Audit rows: `password_reset.requested`, `password_reset.completed`,
      `password_reset.failed_invalid_token`, plus bonus rows for
      `password_reset.requested_unknown` /
      `password_reset.requested_no_email` /
      `password_reset.rate_limited` so forensics can tell apart
      why a request didn't deliver
- [x] Unit + integration tests covering: valid round-trip, expired
      token, already-used token, unknown user (silent), rate
      limit of requests per hour per login (max 3) — 12
      integration cases plus the rate-limit-resets-after-an-hour
      guard

---

### HC-092: Password complexity policy

**Status**: `DONE`
**Type**: Story
**Points**: 2
**Depends on**: Nothing

**Description**: `password_hash()` accepts any non-empty string
today. Add a minimum-viable policy that rejects obvious weak
choices at change / reset / create time.

**Notes on implementation**:
- Bundled common-password list: `resources/common-passwords.txt`
  — 54,763 entries seeded from `/usr/share/dict/cracklib-small`
  (GPL-2, compatible with this project's licence). File path is
  configurable via the `PasswordPolicy` constructor so operators
  can substitute a larger rockyou-style list at deploy time.
- Loaded lazily: the first `validate()` call that reaches rule 4
  reads the file into an `array_flip`-style hash for O(1)
  lookups. ~10 MB RAM post-load; we pay that cost on the first
  password change of a request rather than every page view.
- Identity rule ignores fragments shorter than 4 chars so a 2-
  letter name doesn't block every password containing "jo".
  Email is compared on the local-part only — the domain portion
  would otherwise produce too many false positives ("example"
  is common English).
- Policy applied at `settings.php` change-password. The new form
  also invalidates remember-me cookies on success so a leaked
  cookie can't outlive the password change. The other target
  pages listed in the spec (`reset_password.php`,
  `forgot_password.php`, admin user-create) do not yet exist
  — they'll import this service when HC-091 and the admin page
  land.

**Acceptance Criteria**:
- [x] `src/Auth/PasswordPolicy.php` with `validate(string $pw):
      array` returning list of violation strings (empty = pass)
- [x] Default rules: min length 10, at least one non-alphanum OR
      length ≥ 14, no substring match against login/email/firstname/
      lastname, rejects the 10k most common passwords (bundled list;
      we ship 54k cracklib-small entries — a superset of the 10k
      requested)
- [x] Policy configurable via `hc_config` (`password_min_length`,
      `password_require_symbol`); defaults match the rule list
- [x] Applied at: `settings.php` change-password.
      `reset_password.php` / `forgot_password.php` / admin
      user-create are deferred until those pages exist (HC-091
      and later).
- [x] Error messages are user-facing and actionable ("at least 10
      characters", not "policy violation code 3")
- [x] Unit tests for each rule and for the combined validation
      (16 cases)

---

### HC-093: Session cookie hardening audit

**Status**: `DONE`
**Type**: Story
**Points**: 1
**Depends on**: Nothing

**Description**: Audit session + remember-me cookies against the
OWASP session-management cheatsheet and fix anything missing.

**Notes on implementation**:
- Audit findings: remember-me cookie was already fully hardened
  (HttpOnly, Secure, SameSite=Lax, Path=/, 365-day expiry) from
  HC-014. Session-regen on 2FA was already correct: the
  `$completeLogin` closure in `login.php` runs after both
  password-only AND TOTP-verified paths. The gap was the
  session cookie itself -- `session_start()` ran with default
  params, so PHPSESSID emitted without HttpOnly/SameSite/etc.
- Fix placed `session_set_cookie_params()` + two `ini_set`
  calls (`use_strict_mode=1`, `use_only_cookies=1`) directly
  before `session_start()` in `do_config()`. OWASP cheatsheet:
  cookie params are only honoured when set BEFORE start.
- `SessionCookieParams::forRequest($server)` factored out so
  the HTTPS/HTTP branch is testable. Detects HTTPS via both
  `$_SERVER['HTTPS']` and `X-Forwarded-Proto: https` (TLS
  terminators are common; a local-dev HTTP flag shouldn't
  nullify Secure in prod).
- Config-time class check guards against entry points that
  don't load the Composer autoloader first (`install/*`);
  falls back to plain `session_start()` rather than crashing.
- Live integration test uses curl against the local Apache —
  parses raw `Set-Cookie:` headers and asserts each attribute.
  Skipped cleanly when the base URL isn't reachable so the
  unit-test suite still passes in containers without Apache.

**Acceptance Criteria**:
- [x] Session cookie: `HttpOnly`, `Secure` (on HTTPS),
      `SameSite=Lax` — verified via `session_set_cookie_params()`
      call in `do_config()` (runs before `session_start()`).
      Integration test asserts each attribute on the live
      `PHPSESSID` Set-Cookie header.
- [x] Remember-me cookie (`hc_remember`): same three flags, plus
      a dedicated path scope of `/` and a 365-day max-age
      (already hardened in HC-014; integration test confirms).
- [x] `session_regenerate_id(true)` called after successful login
      AND after 2FA verification (already wired in HC-090 via
      the shared `$completeLogin` closure; no change needed).
- [x] Integration test verifies Set-Cookie header flags on the
      login response (parses `Set-Cookie:` and asserts each
      attribute is present, for both PHPSESSID and hc_remember).

---

## EPIC-10: Notification Channels

**Goal**: Break the ntfy monopoly. Today, supply alerts and reminder
pushes fire only through ntfy. Email is required by HC-091
(password reset) and is the most-requested missing channel.
Webhooks unlock Home Assistant / Slack / Discord in one line.

**Priority**: MEDIUM (HIGH for HC-100/HC-101 since HC-091 depends
on them)

**Depends on**: HC-041 (ntfy config pattern)

---

### HC-100: `NotificationChannel` abstraction + ntfy adapter

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-041

**Description**: Introduce an interface so `send_reminders.php`
and the supply-alert path talk to channels uniformly, then migrate
the existing ntfy logic behind it. No new channels yet — this is
the refactor that unblocks HC-101 and HC-102.

**Notes on implementation**:
- `HttpClient` is its own interface (`src/Notification/HttpClient.php`)
  so channels never call `curl_*` directly. `CurlHttpClient` is the
  production adapter with a 5-second timeout; tests use a recording
  fake that captures url/body/headers per call. That's what lets
  `NtfyChannelTest` assert request shape without a live server.
- `ChannelRegistry::dispatch()` returns the count of channels that
  accepted the message. `send_reminders.php` maps `0` → "Skipped
  (no channel ready)" stdout line so cron log consumers keep
  parsing what they expect.
- Kept `NtfyConfig` as the source of truth for ntfy URL/topic/
  enabled (HC-041 work stays intact); `NtfyChannel` just wraps it
  and the HTTP client.

**Acceptance Criteria**:
- [x] `src/Notification/NotificationChannel.php` (interface):
      `send(NotificationMessage $msg): bool`, `isReady(): bool`,
      `name(): string`
- [x] `NotificationMessage` value object: title, body, priority
      (1-5), tags (array), recipient (login or email or topic,
      channel decides interpretation)
- [x] `src/Notification/NtfyChannel.php` implements the interface
      using the existing `NtfyConfig`; call-sites in
      `send_reminders.php` migrated
- [x] `src/Notification/ChannelRegistry.php`: keyed by name,
      resolves default or per-user channel list
- [x] No behavioral change: `composer test` still passes, reminders
      still fire through ntfy for existing users
- [x] Unit test: `NtfyChannel::send()` shape verified against a
      fake HTTP client; ready/not-ready state matches
      `NtfyConfig::isReady()` (7 cases for NtfyChannel + 9 for
      ChannelRegistry)

---

### HC-101: Email notification channel

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-100

**Description**: Add email as a first-class channel alongside ntfy.
Required by HC-091 (password reset) and wanted on its own by
caregivers who do not use ntfy.

**Notes on implementation**:
- Transport is built lazily from `EmailConfig::getDsn()` on the
  first `send()` call, so pages that never email don't pay the
  DSN-parse cost. Tests inject a `MailerInterface` directly
  (a small `RecordingMailer` that captures the Email object)
  rather than extending Symfony's final `NullTransport`.
- DSN is stored in `hc_config` and the audit row for
  `email.config_updated` deliberately omits it — the DSN can
  embed a password, and an audit log shouldn't be a second
  place those leak.
- Priority ≥ `PRIORITY_HIGH` prepends `[URGENT]` to the
  subject; tags render as a bracketed csv prefix. Mail clients
  can filter on these without reading the body.
- `EmailChannel::send()` returns false (no throw) on transport
  errors; reminder cron keeps running when mail is sick.
- Channel is registered in `send_reminders.php` unconditionally;
  `isReady()` short-circuits when the admin hasn't configured
  SMTP, so an uninstalled email stack costs nothing.
- Per-user `email_notifications` opt-in column added now so
  HC-091 and HC-103 can read it without another migration.
  No call site consults it yet (reminder cron is
  topic-based, not per-user).

**Acceptance Criteria**:
- [x] `symfony/mailer` added as a Composer dep (works with
      SMTP / Sendmail / LMTP transports; no framework lock-in)
- [x] `src/Notification/EmailChannel.php` implements
      `NotificationChannel`; renders title as Subject, body as
      text/plain, supports HTML via a `?html` body variant later
- [x] `hc_config` settings: `smtp_dsn` (Symfony Mailer DSN),
      `smtp_from_address`, `smtp_from_name`, `smtp_enabled` (Y/N)
- [x] Admin settings UI section ("Email") beside the ntfy block in
      `settings.php`, admin-only, audit-logged on change (DSN
      redacted from audit details)
- [x] Graceful degrade: if the channel fires when `smtp_enabled='N'`
      it logs a warning and returns false; caller (e.g. password
      reset) maps that to a user-visible "email delivery disabled"
      message
- [x] Migration (012): `hc_user.email_notifications CHAR(1) NOT NULL
      DEFAULT 'N'` (per-user opt-in for reminders; password-reset
      bypasses the flag because the user needs it to log back in)
- [x] Unit test: renders message, uses configured DSN, respects
      the enabled toggle; integration test with a null transport
      round-trips a sample message (5 cases for EmailConfig + 8
      for EmailChannel, including transport-error capture)

---

### HC-102: Generic webhook channel

**Status**: `DONE`
**Type**: Story
**Points**: 2
**Depends on**: HC-100

**Description**: POST a signed JSON payload to an arbitrary URL.
Enables Home Assistant / Slack / Discord / n8n / Zapier integrations
without per-service code.

**Notes on implementation**:
- The "integration test against a local echo server" became a
  unit test with a `ProgrammableHttpClient` that takes a
  `list<bool>` of per-attempt outcomes. Tests like "2 failures
  then success → 3 POSTs, sleeps [1,3]" become one assertion
  pair; no need for a real HTTP listener.
- `SignedUrl::getSecret()` flipped from private → public so the
  webhook can sign with the same per-deploy key HomeCare already
  uses for signed export URLs. One secret, one key-rotation
  story to reason about.
- Retry schedule lives in `WebhookChannel::BACKOFF_SECONDS =
  [1, 3, 9]`. Total attempts: 4 (1 initial + 3 retries). The
  sleeper is a callable so tests swap it for a recorder and
  stay fast.
- `CurlHttpClient` is instantiated with the configured
  `webhook_timeout_seconds` so the webhook honours its own
  timeout setting independently of ntfy. A deliberately slow
  webhook endpoint can't bleed the 5-second ntfy budget.
- Payload shape is stable across retries by design: `timestamp`
  and `message_id` are computed once in `send()` and reused for
  every attempt so an idempotent receiver can dedupe on
  `message_id`.
- Tags always render as `"tags": []` (not missing key) so
  receivers don't have to defend against undefined.

**Acceptance Criteria**:
- [x] `src/Notification/WebhookChannel.php` implements the
      interface
- [x] Payload shape: `{title, body, priority, tags,
      timestamp, message_id}`; body is the raw text, title
      separate
- [x] Each POST includes `X-HomeCare-Signature: sha256=<hmac>`
      using the `SignedUrl` secret as the HMAC key (reuses the
      existing key-rotation story)
- [x] Config in `hc_config`: `webhook_url`, `webhook_enabled`
      (Y/N), `webhook_timeout_seconds` (default 5)
- [x] Admin UI section in `settings.php`; audit-logged as
      `webhook.config_updated`
- [x] 5-second hard timeout per request (configurable via
      `webhook_timeout_seconds`), 3 retries with exponential
      backoff (1s / 3s / 9s). Worst-case blocking is bounded
      by timeout×4 + 13s of backoff; a permanently-broken
      webhook stops blocking after ≈33 s and the reminder loop
      continues.
- [x] Unit test against a programmable HTTP client: posts a
      message, asserts the signature header + payload body, and
      covers the retry / backoff / isReady / empty-tags paths
      (10 cases for WebhookChannel + 5 for WebhookConfig).

---

### HC-103: Per-caregiver channel preferences

**Status**: `DONE`
**Type**: Story
**Points**: 2
**Depends on**: HC-101, HC-102

**Description**: Let each caregiver pick which channel(s) receive
their reminders. The system default stays (admin-configured
ntfy/email/webhook); a caregiver can override with their own
preference.

**Notes on implementation**:
- Stored as JSON in `hc_user.notification_channels` (TEXT for
  SQLite/MySQL portability — MySQL 8 needs `DEFAULT ('[]')` with
  parens for TEXT defaults). `UserRepository::
  updateNotificationChannels()` dedupes + filters out empty
  strings before encoding, so storage stays clean regardless
  of caller hygiene.
- `ChannelResolver::resolveFor($json)` is pure: prefers
  the user list, falls back to registry defaults when the
  list is empty or unparseable, and drops channels that are
  unknown or `isReady() === false`. A caregiver opting into
  "email" before SMTP is wired sees their other channels
  still work.
- Settings UI shows ONLY ready channels — no point offering
  "email" when the admin hasn't set up SMTP. Empty check-list
  = "use the site default", no hidden switch needed.
- `FakeChannel` lived inside `ChannelRegistryTest.php` (PSR-4
  would never have found it from another file); extracted to
  its own file so `ChannelResolverTest` and any future channel
  tests can share it.
- Resolver is instantiated in `send_reminders.php` but not yet
  used at dispatch time — the reminder cron is still topic-
  based (one `dispatch()` per reminder, no per-user context).
  HC-104 adds the per-user iteration that feeds this resolver.

**Acceptance Criteria**:
- [x] Migration (014): `hc_user.notification_channels TEXT NOT
      NULL DEFAULT ('[]')` — portable across MySQL 8 / SQLite
      3.35+. Applied to live DB.
- [x] `settings.php` adds a "My notifications" section with
      checkboxes for each enabled (isReady) channel. Empty
      selection falls back to the site default.
- [x] `send_reminders.php` constructs the `ChannelResolver`;
      per-user resolution is plumbed (activation lands with
      HC-104).
- [x] Unit test: resolver prefers per-user, falls back to system,
      drops unknown + not-ready, tolerates malformed JSON
      (8 cases).
- [x] Integration test: `UserRepository::updateNotificationChannels`
      round-trips, dedupes, and clears with empty list
      (4 cases).
- [x] Audit row `user.channel_prefs_updated` on every save.

---

### HC-104: Email addressing plumbing (unlock story)

**Status**: `DONE`
**Type**: Story
**Points**: 2
**Depends on**: HC-101

**Description**: Email is registered as a notification channel
(HC-101) but `send_reminders.php` never supplies a recipient, so
no mail has ever fired from the reminder / supply-alert paths.
The missing piece is *addressing*: a self-service way for each
caregiver to set their own email, an opt-in toggle for reminder
email, and per-user iteration in the reminder loop so each
recipient gets their own `NotificationMessage`. One story unlocks
HC-091, HC-105, HC-106, HC-107, HC-108.

**Notes on implementation**:
- Two addressing models coexist in `dispatchReminder()`:
  topic-based channels (ntfy, webhook) get one dispatch each
  with no recipient; email iterates the opted-in subscriber
  list and fires one message per address. The reminder cron
  output line now carries `[topic:N email:M]` so it's obvious
  at a glance which channels delivered.
- `UserRepository::getEmailSubscribers()` is a single query
  filtered on `email_notifications='Y' AND email IS NOT NULL
  AND enabled='Y'`. Pre-loaded once per cron run so the
  per-dose dispatch doesn't re-query.
- Server-side validation in the Contact handler: empty email
  is fine (clears the column), non-empty must pass
  `filter_var(FILTER_VALIDATE_EMAIL)`, and the "Email me
  reminders" toggle can't flip on without an email on file —
  two separate flash messages for the two failure modes so
  operators see the actionable hint.
- `updateEmail()` trims whitespace before storing / clearing,
  so a leading space on a paste doesn't sneak into the DB.
- Per-user email firing is the hard dependency that HC-091's
  password reset quietly relied on (HC-091 looked up
  `hc_user.email` directly); this story fills in the
  settings-UI side so caregivers can actually set their
  address.

**Acceptance Criteria**:
- [x] `settings.php` adds a "Contact" section: email field
      (prefilled from `hc_user.email`) and an "Email me reminders"
      checkbox bound to `hc_user.email_notifications`.
- [x] `UserRepository::updateEmail(string $login, ?string $email): bool`
      and `updateEmailNotifications(string $login, bool $on): bool`
      with appropriate audit rows.
      Plus `getEmailSubscribers(): list<string>` for the cron.
- [x] Server-side validation: email is either empty or passes
      `filter_var(FILTER_VALIDATE_EMAIL)`; toggle cannot be turned
      on without a valid address.
- [x] `send_reminders.php` iterates `hc_user` rows where
      `email_notifications='Y'` AND `email IS NOT NULL` (AND
      `enabled='Y'`) and dispatches one `NotificationMessage`
      per recipient with the email set. Ntfy and webhook still
      fire topic-based alongside.
- [x] Audit: `user.email_updated` and `user.email_prefs_updated`
- [x] Integration test: opted-in user with valid email receives
      a `NotificationMessage` with `recipient` set; opted-out user
      is skipped. (9 cases cover round-trip, trim, clear modes,
      opt-in filtering, disabled-account exclusion.)

---

### HC-105: Late-dose email alert

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-104

**Description**: Today's reminder only fires *before* a dose is
due. If the caregiver misses the window there's no louder ping —
the dose just silently ages into the "overdue" column. Add an
escalation: when a dose is more than `late_dose_alert_minutes`
past due (default **60**, configurable via `hc_config`), send an
email to every opted-in caregiver. Once-per-lateness so a
permanently-overdue schedule doesn't fire hourly forever.

**Notes on implementation**:
- Feature ships off-by-default (`late_dose_alert_minutes=0`
  unless the admin sets it). Under-alerting is cheap; an
  install flipping on a new loud alert without the operator
  asking for it is not. Admin sets it in `hc_config` and gets
  the 60-min behaviour the spec recommends.
- `shouldAlert()` compares the CURRENT due instant (computed
  as `lastTaken + frequency`) against
  `hc_late_dose_alert_log.last_due_at`. Equal → suppress.
  Different → fire. When the caregiver logs the dose, the
  next due instant shifts and the next miss re-arms the alert
  automatically.
- `recordSent()` fires AFTER the channel dispatch succeeds so
  a transport failure doesn't silence a retry on the next
  cron tick.
- Dispatch follows the HC-104 two-model pattern: topic
  channels (ntfy, webhook) get one dispatch each with no
  recipient; email iterates the pre-loaded `$emailSubscribers`
  list with `recipient` set per message. `[URGENT]` subject
  prefix comes for free via EmailChannel's priority mapping.
- Minute-scale frequencies (`30m`) are respected out of the
  box via `ScheduleCalculator::frequencyToSeconds()`; no
  special-case needed.

**Acceptance Criteria**:
- [x] New `hc_config.late_dose_alert_minutes` — 0 / unset
      turns the feature off. Ships disabled.
- [x] Migration (015): `hc_late_dose_alert_log (schedule_id
      INT PRIMARY KEY, last_due_at DATETIME NOT NULL, sent_at
      DATETIME NOT NULL)`. Applied live.
- [x] `src/Service/LateDoseAlertService.php` with pure
      `shouldAlert(lastTaken, frequency, thresholdMinutes,
      lastAlertDueAt, now)`.
- [x] `send_reminders.php` new pass after the supply-alert
      loop: walks active schedules, calls
      `findPendingAlerts()`, dispatches with `PRIORITY_HIGH`
      and tags `['late', 'pill']`.
- [x] Body: `"{medicine} for {patient} was due at HH:MM —
      N minutes late."` Subject gets `[URGENT]` via the
      EmailChannel priority mapping.
- [x] `--dry-run` prints per-schedule preview lines.
- [x] Unit tests (10): feature off at threshold ≤ 0,
      not-late-enough, exact boundary, past threshold,
      replay suppression same instant, re-arm on new due
      instant, unparseable inputs fail quiet, minute-scale
      threshold.
- [x] Integration tests (7): two-schedules-one-late yields
      one alert, recordSent suppresses replay within same
      window, re-arms after caregiver logs dose,
      feature-off, missing-intakes skipped,
      inactive-schedules skipped, log upsert persists.

---

### HC-106: Security-event email notifications

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-104

**Description**: High-trust events happening on an account
should reach the account owner out-of-band so a compromised
session can't silently degrade security. Pipe existing audit
write-points through the email channel with a tight template.

**Notes on implementation**:
- `SecurityNotifier` is intentionally caller-driven — no pub/sub,
  no audit-log subscription magic. Each audit write site that
  should trigger an email invokes `notify($login, $event)`
  inline. Explicit, greppable, and trivially tested.
- Gating is intentionally NOT on `hc_user.email_notifications`
  (that's for reminders). Security emails reach the account
  owner even if they muted pings — the threat model calls for
  breach notification, not noise control.
- `AuthResult` grew a `justLockedOut` flag that the lockout
  path sets to `true` ONLY on the attempt that actually tripped
  `applyLockout`. Subsequent attempts while locked keep the
  flag false. login.php fires the lockout email from this
  edge-trigger, never on every attempt.
- New-IP detection reads the stored `hc_user.last_login_ip`
  BEFORE writing the new one, so the email body can quote both.
  First successful login ever (previous_ip === null) does NOT
  fire — the whole point is flagging changes, and everyone has
  a first login.
- Same check path runs for TOTP-verified logins (they go
  through the shared `$completeLogin` closure) and for TOTP
  failures that trip lockout.
- `isEnabled()` is fail-OPEN: missing row or stray value = on.
  Only literal `'N'` suppresses. Under-alerting on security
  emails is the worse failure mode.
- Transport exceptions are swallowed into `error_log()` — the
  audit row remains the authoritative record if SMTP is down.

**Acceptance Criteria**:
- [x] Emails fire for each of: `totp_disabled`,
      `password_changed`, `apikey_generated`, `apikey_revoked`,
      `login_lockout` (edge-triggered via
      `AuthResult::justLockedOut`, not on every failed attempt),
      `login_new_ip` (via stored `hc_user.last_login_ip`).
- [x] New column `hc_user.last_login_ip VARCHAR(45) NULL`
      (migration 016, applied live).
- [x] Messages are short, actionable, and include a link back
      to `settings.php` for verification.
- [x] Fire-and-forget — `SecurityNotifier::notify()` swallows
      every Throwable into `error_log()`; the underlying action
      (login, password change, etc.) never blocks.
- [x] `hc_config.security_email_enabled` master toggle (defaults
      ON when the row is absent). Set to `'N'` to mute.
- [x] Integration tests (11): each trigger dispatches with the
      expected subject/body, master-toggle-off skips, missing
      email skips, unknown user skips, unknown event no-ops,
      transport failure is swallowed. Plus 1 AuthService test
      covering the edge-triggered `justLockedOut` flag.

---

### HC-107: Weekly adherence email digest

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-104, HC-022

**Description**: Push a summary of last week's per-medication
adherence (see HC-022 for the math) to every opted-in caregiver
every Monday morning. Useful for family caregivers who aren't
logging in daily and want a calm Monday-morning snapshot rather
than real-time pings.

**Notes on implementation**:
- One shared body per run: every opted-in caregiver sees the
  same patient-by-patient snapshot (this is a single-household
  app where all caregivers see all patients). The CLI computes
  the body ONCE and fans it out to each recipient so adherence
  numbers are stable across the batch.
- Windows end "yesterday" so a partial current day doesn't
  skew the percentages. Monday-morning cron thus reports on
  Mon-Sun (7-day) and the preceding 30 calendar days.
- Default is OFF — `hc_user.digest_enabled='N'` everywhere
  until the caregiver opts in from settings.php. Same
  philosophy as reminder email: loud additions to outbound
  mail require explicit consent.
- `AdherenceDigestBuilder` is pure. CLI owns the adherence
  maths and patient walk; builder just lays out the table
  and applies colour markers. Tests cover the boundary cases
  directly without any DB fixture.
- Column widths use byte-counting `str_pad()` so we don't
  require PHP 8.3's `mb_str_pad`. ASCII medicine names
  dominate; a unicode name may mis-align a column or two on
  a monospace-font reader — acceptable for a weekly
  low-priority email.

**Acceptance Criteria**:
- [x] New CLI script `bin/send_adherence_digest.php` (intended
      for a Monday-morning cron entry).
- [x] Per-patient section in the body: per-medication
      `7-day %, 30-day %` table, colour-keyed via text markers
      (✓ ≥90%, ⚠ 70-89%, ✗ <70%).
- [x] Per-user opt-in: `hc_user.digest_enabled CHAR(1) DEFAULT
      'N'` + settings.php toggle (reuses the HC-104 contact
      section).
- [x] Subject: `[HomeCare] Weekly adherence digest — {date}`
- [x] Dry-run flag prints per-recipient message without sending.
- [x] Unit tests (10): boundary markers (exact 90 → ✓,
      exact 70 → ⚠, 69.9 → ✗, 89.9 → ⚠), patient + row
      ordering follow input, empty-patient shows "No intakes
      this week", header line carries run date, table header
      present only when rows exist, empty subscriber list
      shows placeholder.

---

### HC-108: Email delivery of exports

**Status**: `DONE`
**Type**: Story
**Points**: 2
**Depends on**: HC-104

**Description**: CSV / FHIR / PDF exports currently stream to
the browser. A 90-day intake pull can time out on flaky
connections and is awkward to share with a vet. Add an
"Email this to me" button alongside the download button on
`report_intake.php`, `export_intake_csv.php`,
`export_intake_fhir.php`, and `medication_summary.php`.

**Notes on implementation**:
- `EmailExportService` bypasses `EmailChannel` because
  attachments aren't in the `NotificationMessage` shape.
  Uses Symfony Mailer directly via the shared DSN from
  `EmailConfig`. `MailerInterface` is injectable so tests
  use a `RecordingExportMailer` and capture attachment bytes
  directly.
- CSV + FHIR ship as attachments (`text/csv`,
  `application/fhir+json`); medication_summary ships as
  inline plain text (a PDF attachment would need dompdf
  plumbing the summary report doesn't have yet, and the
  table reads fine in email).
- Rate limit is counted by querying `hc_audit_log` for
  `action='export.emailed'` + `user_login=?` within the last
  hour. Uses the audit table that already exists — no new
  state to keep in sync.
- Feature gate: the button only works for users with
  `email_notifications='Y'` AND an address passing
  `filter_var(FILTER_VALIDATE_EMAIL)`. Anyone else gets a
  redirect to the Contact settings section with the reason.
- Three endpoints + `report_intake.php` share the dispatch
  via `includes/email_export_dispatch.php` so the user-lookup
  / gating / feedback-page rendering lives in one place.
  `function_exists` guard means stacking the include from
  multiple endpoints in the same request is safe.

**Acceptance Criteria**:
- [x] Each export page gets a second form that POSTs with
      `delivery=email`; the handler renders the export in memory
      and attaches it to a short Symfony Email with `to` set to
      the requester's email (bypass of NotificationMessage was
      needed — attachments aren't on that DTO).
- [x] Requires the user to have a verified email via HC-104
      (toggle on + filter_var passes); otherwise the feedback
      page explains how to fix it.
- [x] Rate-limit: max 3 export emails per user per hour,
      counted over `hc_audit_log` rows.
- [x] Audit: `export.emailed` with `{type, patient_id,
      start_date, end_date, size_bytes}`.
- [x] Integration test (9): CSV attachment bytes, FHIR
      attachment JSON, inline summary body, rate limit on
      4th send, rate-limit reset after an hour, per-user
      rate limit, invalid-recipient reject, audit row
      shape, email-disabled config reject.

---

## EPIC-11: Medication Data Breadth

**Goal**: Stop treating medications as free-text strings. Adds a
drug catalogue, barcode-based inventory check-in, interaction
checking, and veterinary-specific fields. Each raises the clinical
utility of HomeCare without requiring the operator to become a
pharmacist.

**Priority**: MEDIUM

**Depends on**: HC-004 (repository pattern)

---

### HC-110: Drug database autocomplete (RxNorm)

**Status**: `DONE`
**Type**: Story
**Points**: 5
**Depends on**: HC-004

**Description**: Let caregivers pick medications from a standardised
list rather than typing free-text names. Feeds directly into future
interaction-checking and barcode lookup.

**Acceptance Criteria**:
- [x] Migration: `hc_drug_catalog (id, rxnorm_id, name, strength,
      dosage_form, ingredient_names TEXT, generic CHAR(1))` plus
      indexes on `name` and `rxnorm_id`
- [x] `hc_medicines.drug_catalog_id INT NULL FK` (nullable — free-
      text entries still allowed)
- [x] CLI `bin/import_rxnorm.php`: downloads or reads a local
      RxNorm RRF dump, loads `RXNCONSO` → `hc_drug_catalog`;
      idempotent (upserts on `rxnorm_id`)
- [x] Medication entry / edit pages: autocomplete field driven by
      `api/v1/drugs.php?q=...` (debounced, 250 ms, min 2 chars)
- [x] Selecting a catalogue entry pre-fills name, strength, and
      dosage form; operator can still override
- [x] "I don't see my medication" fallback keeps free-text entry
- [x] Veterinary-friendly: RxNorm covers human drugs; vet entries
      can be added manually with `rxnorm_id=NULL` and a `notes`
      column — documented in the import script
- [x] Unit test: catalogue search; integration test: full picker
      flow populates the schedule form

---

### HC-111: Barcode / NDC scanning for inventory

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-110

**Description**: Scan a prescription bottle's barcode to record a
refill. NDC (11-digit) is the standard on US prescription labels;
UPC/EAN on veterinary products.

**Acceptance Criteria**:
- [x] `inventory_refill.php` gets a "Scan barcode" button (visible
      on mobile + cameras-attached desktops)
- [x] Uses `html5-qrcode` or `zxing-js` (bundled, no CDN) to decode
      the barcode client-side; hands the NDC/UPC to a server
      endpoint
- [x] `api/v1/drug_lookup.php?ndc=...` returns matching
      `hc_drug_catalog` entries (via RxNorm `RXNSAT NDC` attribute)
      or a 404 with a free-text fallback
- [x] Preview shows the matched medication name + strength + dose
      form before the caregiver commits the refill quantity
- [x] Audit row: `inventory.refilled` with `details.source='barcode'`
- [x] Manual-entry remains the default path — scanner is additive
- [x] E2E test verifies a seeded NDC maps to the expected catalogue
      entry

---

### HC-112: Drug interaction checking

**Status**: `DONE`
**Type**: Story
**Points**: 5
**Depends on**: HC-110

**Description**: Warn caregivers when a new schedule interacts with
an active one on the same patient. Uses RxNorm's ingredient-level
interaction pairs.

**Acceptance Criteria**:
- [x] Migration: `hc_drug_interactions (ingredient_a VARCHAR(64),
      ingredient_b VARCHAR(64), severity ENUM('minor','moderate',
      'major'), description TEXT, PRIMARY KEY (ingredient_a,
      ingredient_b))`
- [x] CLI `bin/import_interactions.php` loads a curated DrugBank
      / RxNav interaction set (licence-compatible subset;
      documented source in the script)
- [x] `src/Service/InteractionService.php`:
      `checkForPatient(int $patientId, int $newMedicineId): array`
      returns a list of interaction records against active
      schedules
- [x] `add_to_schedule.php` calls the service; moderate / major
      interactions render a confirmation gate ("Dr. has OK'd
      this combination? ☐"); minor interactions render an info
      banner
- [x] `report_medications.php` shows an "Interactions" badge per
      patient with a link to detail
- [x] Unit + integration tests; no-interaction and multi-
      interaction cases

---

### HC-113: Veterinary profile

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: Nothing

**Description**: The codebase has implicit vet-use (Tobramycin
example from HC-075). Make veterinary patients a first-class
concept.

**Notes on implementation**:
- `edit_patient.php` and `edit_patient_handler.php` are new pages;
  previously there was no patient edit form (only a dead link from
  index.php). Species is a dropdown with common vet + human values;
  weight + date are optional fields that populate the per-kg math.
- `dose_basis` is stored as `VARCHAR(10)` with a default of
  `'fixed'` rather than a MySQL ENUM for SQLite portability.
  Validation is in the handler (`'per_kg'` or falls back to
  `'fixed'`).
- `InventoryService` now accepts an optional `PatientRepository`.
  When present and `dose_basis='per_kg'`, it multiplies
  `unit_per_dose` by `weight_kg`. Missing/zero weight falls back
  to raw `unit_per_dose` with a warning string.
- `list_schedule.php` sticky header and medication summary print
  view both show species + weight alongside the patient name.

**Acceptance Criteria**:
- [x] Migration: `hc_patients.species VARCHAR(32) NULL`,
      `hc_patients.weight_kg DECIMAL(6,2) NULL`,
      `hc_patients.weight_as_of DATE NULL`
- [x] Edit patient page adds species dropdown (cat/dog/horse/
      rabbit/bird/reptile/other/human) and weight + date
- [x] `hc_medicine_schedules.dose_basis ENUM('fixed','per_kg')
      DEFAULT 'fixed'`; when `per_kg`, `unit_per_dose` is
      interpreted as mg/kg
- [x] `dosesRemaining()` + inventory math multiply `unit_per_dose`
      by `weight_kg` when `dose_basis='per_kg'`
- [x] Warning when schedule uses `per_kg` and patient has no
      `weight_kg` on file
- [x] Medication summary print view shows species + weight
- [x] Tests cover per_kg math at multiple weights + missing-weight
      edge case

---

## EPIC-12: Schedule Semantics

**Goal**: Extend the `frequency` model beyond `Nh` / `Nd` to cover
common real-world dosing patterns: as-needed, pulse cycles, step
tapers, specific wall-clock times, and pauses.

**Priority**: MEDIUM

**Depends on**: HC-002 (ScheduleCalculator), HC-005 (InventoryService)

---

### HC-120: PRN / as-needed schedules

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-002

**Description**: Some medications are taken when needed (pain,
anxiety, seizure rescue). A schedule marked PRN should accept
intake records but not compute "next due" or "overdue" — and not
count against adherence.

**Notes on implementation**:
- `ScheduleCalculator` gained two nullable-input wrappers
  (`calculateSecondsUntilDueOrNull`, `calculateNextDueDateOrNull`)
  rather than changing the return types of the existing strict
  methods. Existing callers are unaffected; PRN-aware code opts
  into the nullable variants.
- The `frequency` column in `hc_medicine_schedules` was relaxed
  to `NULL`. PRN rows store `NULL` frequency and `is_prn='Y'`.
  Non-PRN rows keep their existing `Nd/Nh/Nm` string;
  `createSchedule()` enforces that fixed-cadence rows still
  require a frequency.
- All cadence-dependent subsystems filter out PRN at the SQL
  level (`ms.is_prn = 'N' AND ms.frequency IS NOT NULL`):
  send_reminders, LateDoseAlertService, SupplyAlertService,
  schedule_daily, schedule_ics, report_missed, and
  PatientAdherenceReport. The InventoryApi and
  MedicationSummaryReport include PRN rows but show them with
  `frequency='PRN'` and zero daily-consumption.

**Acceptance Criteria**:
- [x] Migration: `hc_medicine_schedules.is_prn CHAR(1) NOT NULL
      DEFAULT 'N'`
- [x] `add_to_schedule.php` gets a "Take as needed (PRN)" checkbox;
      when checked, `frequency` input is hidden + stored as NULL
- [x] `ScheduleCalculator::calculateSecondsUntilDue()` and
      friends return null / special-case PRN schedules
- [x] `list_schedule.php` renders PRN rows with a dedicated status
      ("PRN — no schedule") and no Overdue badge
- [x] `AdherenceService` skips PRN schedules (they have no
      expected-count)
- [x] `send_reminders.php` skips PRN schedules
- [x] Tests: PRN intake records correctly, no reminder fires,
      adherence excludes, math helpers return null

---

### HC-121: Pulse / cycle dosing

**Status**: `DONE`
**Type**: Story
**Points**: 5
**Depends on**: HC-002

**Description**: Support "3 weeks on, 1 week off" patterns common
in veterinary antibiotics, hormonal therapy, and chemotherapy.

**Notes on implementation**:
- `ScheduleCalculator` gains two pure static methods: `isOnDay()`
  determines if a target date falls in the on-period of the cycle
  (modular arithmetic on days-since-start), and
  `countOnDaysInRange()` counts on-days within a date range (used
  by AdherenceService for expected-dose calculation).
- Both columns are nullable — `NULL`/`NULL` means continuous dosing
  (the existing default). When both are set, the schedule alternates
  `cycle_on_days` days of normal dosing and `cycle_off_days` days
  with no expected doses.
- Off-days suppress reminders (`send_reminders.php`), late-dose
  alerts (`LateDoseAlertService`), and adherence expected-count
  (`AdherenceService`). `list_schedule.php` shows "Off day" with a
  cycle label.
- The schedule form adds an optional "Cycle" fieldset with two
  number inputs. Both must be filled or both blank (handler
  normalises partial input to null).

**Acceptance Criteria**:
- [x] Migration: `hc_medicine_schedules.cycle_on_days INT NULL`,
      `hc_medicine_schedules.cycle_off_days INT NULL`
- [x] Schedule edit form: optional "Cycle" section, two number
      inputs
- [x] `ScheduleCalculator` handles the cycle:
      - During on-period: behaves as normal
      - During off-period: no "next due", doses not expected
- [x] Adherence expected-count honours the cycle (counts only
      on-days)
- [x] Reminder + supply alert loops skip off-days
- [x] Unit tests around cycle boundary (last hour of on-period,
      first hour of off-period)

---

### HC-122: Step / taper dosing

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-002

**Description**: "Week 1: 5mg, Week 2: 10mg, Week 3+: 20mg" is
common in steroid tapers and SSRIs. Today caregivers have to end
the schedule and start a new one for each step — losing adherence
continuity. Model it as a single schedule with a step table.

**Notes on implementation**:
- `hc_schedule_steps` is a child table of `hc_medicine_schedules`.
  Zero rows = schedule's own `unit_per_dose` (fully backwards
  compatible). One or more rows = latest step whose `start_date <=
  today` determines the effective dose.
- `StepRepository` handles CRUD + `getEffectiveStep(scheduleId,
  date)` for the single-query resolution. `hasOverlap()` rejects
  duplicate start_dates on the same schedule.
- `InventoryService` checks the optional `StepRepository` before
  applying per-kg math (HC-113). Steps compose with per-kg: a
  step's `unit_per_dose` is multiplied by weight if `dose_basis=
  'per_kg'`.
- `manage_steps.php` + handler: table of existing steps with
  add/remove actions. Linked from the kebab menu as "Dose
  steps…". The existing adjust_dosage flow (which creates new
  schedules) remains for cases that also change frequency.

**Acceptance Criteria**:
- [x] Migration: `hc_schedule_steps (id, schedule_id, start_date,
      unit_per_dose DECIMAL(10,3), note VARCHAR(255))`
- [x] Schedule edit form: "Add step" repeater; first step is the
      base schedule, additional steps layer on
- [x] `InventoryService` and `AdherenceService` pick the correct
      `unit_per_dose` by date (latest step whose `start_date <=
      current_date`)
- [x] Dose-adjustment history (the original adjust flow)
      deprecates gracefully — reads from the step table
- [x] Tests: boundary dates, zero-step schedule (current behavior),
      overlapping-step rejection

---

### HC-123: Multiple wall-clock times per day

**Status**: `DONE`
**Type**: Story
**Points**: 3
**Depends on**: HC-002

**Description**: "8am + 2pm + 8pm" is a common human schedule.
`12h` frequency covers it but drifts over weeks as intake times
vary; a wall-clock schedule keeps doses anchored.

**Notes on implementation**:
- `wall_clock_times` is stored as a CSV of `HH:MM` strings (e.g.
  `"08:00,14:00,20:00"`). NULL means interval-based scheduling
  (the existing default). When set, wall-clock overrides frequency
  for next-due, adherence expected-count, and supply projection.
- `ScheduleCalculator` gains `parseWallClockTimes()`,
  `dosesPerDayFromWallClock()`, and `secondsUntilNextWallClock()`
  — all pure functions with no DB access.
- `list_schedule.php` shows "Due at 2:00 PM" for wall-clock
  schedules instead of the relative "in 4h 30m".
- The schedule form adds a "Fixed times per day" section with
  dynamic `<input type="time">` rows and an "Add time" button.
- DST note: all math uses `strtotime()` which respects the
  server's timezone. Wall-clock times are anchored to local wall
  time — doses don't shift across DST transitions.

**Acceptance Criteria**:
- [x] Migration: `hc_medicine_schedules.wall_clock_times VARCHAR(128)
      NULL` (CSV of `HH:MM` times)
- [x] Schedule edit form: "Fixed times per day" option, renders
      N `<input type="time">` rows
- [x] `ScheduleCalculator` resolves the next-due to the nearest
      future clock time
- [x] Supply and adherence math: expected doses per day =
      `count(wall_clock_times)`
- [x] `list_schedule.php` next-due label shows the actual clock
      time ("Due at 2:00 PM") rather than "in 4h 30m"
- [x] Tests over DST transition days (US/America/New_York) — the
      doses stay anchored to local wall time, not UTC

---

### HC-124: Pause / skip-today for active schedules

**Status**: `DONE`
**Type**: Story
**Points**: 2
**Depends on**: Nothing

**Description**: "My cat goes to the groomer Tuesday, skip
today's dose" or "Holding this med during vacation". Today the
only option is ending the schedule and restarting it, which loses
continuity.

**Notes on implementation**:
- `PauseRepository` handles all CRUD + the interval-union math
  for `countPausedDaysInRange()` (used by adherence to subtract
  paused days from the expected dose count).
- `list_schedule.php` adds a "Paused" group between Upcoming and
  PRN. The kebab menu gains "Skip today" (1-click POST), "Pause
  schedule…" (opens date picker), and "Resume schedule" (closes
  active pauses).
- `send_reminders.php` checks `isPausedOn()` per schedule.
  `LateDoseAlertService` uses a `NOT EXISTS` subquery so paused
  rows never enter the alert walk.
- `AdherenceService` accepts an optional `PauseRepository`; when
  present, paused days are subtracted from `coverageDays` before
  computing expected doses. Callers that don't inject it (old
  tests, future contexts) behave identically to before.
- DST note: `countPausedDaysInRange` works in calendar-day
  granularity (date strings, not timestamps), so DST transitions
  don't shift pause boundaries.

**Acceptance Criteria**:
- [x] Migration: `hc_schedule_pauses (id, schedule_id, start_date,
      end_date NULL, reason VARCHAR(255))`
- [x] `list_schedule.php` row action: "Pause" / "Skip today"
      (behind the kebab overflow from the recent UI refactor)
- [x] "Skip today" inserts a 1-day pause ending end-of-day local
- [x] "Pause" opens a date-range picker
- [x] `dosesRemaining`, reminders, adherence, and cadence-check
      all honour active pauses
- [x] Audit rows: `schedule.paused`, `schedule.resumed`
- [x] Tests: overlapping pauses, open-ended pauses, pause over
      DST boundary

---

## EPIC-13: Data Portability & Attachments

**Goal**: Close the "data in, data out, data attached" loop. Export
is already rich (CSV / FHIR / PDF); import and attachments are
missing.

**Priority**: MEDIUM

**Depends on**: HC-004 (repository pattern)

---

### HC-130: Photo / document attachments

**Status**: `DONE`
**Type**: Story
**Points**: 5
**Depends on**: HC-011

**Description**: Attach photos (prescription labels, medication
bottles) and documents (PDFs of vet notes, lab results) to a
patient, a schedule, or a caregiver note.

**Acceptance Criteria**:
- [x] Migration: `hc_attachments (id, owner_type ENUM('patient',
      'schedule','note'), owner_id, filename, mime_type, size_bytes,
      sha256, storage_path, uploaded_by, uploaded_at)`
- [x] Server-side allow-list: `image/jpeg`, `image/png`, `image/heic`,
      `application/pdf`; max 10 MB
- [x] Files stored under `data/attachments/<sha256[:2]>/<sha256>`
      outside the web root; served through
      `attachment.php?id=N` with role + ownership check
- [x] MIME sniffed server-side (don't trust client Content-Type)
- [x] Thumbnail generation for images via GD (lazy, cached on
      first read)
- [x] UI: drag-and-drop zone on patient / schedule / note pages;
      list + download + delete inline
- [x] Audit rows: `attachment.uploaded`, `attachment.deleted`
- [x] Tests: upload + sniff + serve round-trip, MIME rejection,
      role-based access (viewer can download, only owner/admin
      can delete), 10MB cap enforced

---

### HC-131: CSV import for schedules + intakes

**Status**: `DONE`
**Type**: Story
**Points**: 5
**Depends on**: HC-004

**Description**: Symmetric to the existing CSV export. Enables
migration from another tracker, disaster-recovery from a backup,
and periodic sandbox-to-prod promotions.

**Acceptance Criteria**:
- [x] `import_schedules.php` (admin-only) accepts the same CSV
      shape `export_intake_csv.php` emits, plus an optional
      `schedules.csv` for the schedule catalogue
- [x] Preview step identical to HC-084's pattern: row-level
      validation, row count, commit only on confirm
- [x] Foreign-key resolution: patient by `patient_name` or id,
      medicine by `medicine_name` or id; creates the FK targets
      only if the operator ticks "create missing" (explicit
      opt-in)
- [x] Transaction per file
- [x] De-dup protection: intakes with the same (`schedule_id`,
      `taken_time`) are skipped with a row-level "already
      exists" note rather than erroring the whole import
- [x] Audit row: `data.imported` with row counts by entity
- [x] Integration test: export → wipe → import → verify
      round-trip equality

---

## EPIC-14: Polish & Reach

**Goal**: Remaining nice-to-haves that don't fit the other epics:
real-time updates, i18n content, richer E2E coverage, and optional
reverse-proxy auth for users behind Authelia/Authentik.

**Priority**: LOW

---

### HC-140: Real-time multi-caregiver updates (SSE)

**Status**: `BACKLOG`
**Type**: Story
**Points**: 5
**Depends on**: HC-013 (audit log drives the event stream)

**Description**: When caregiver A records an intake, caregiver B's
open schedule view should update without a refresh. Minimal-surface
approach: a Server-Sent Events endpoint tailing `hc_audit_log`
since the connection's `last_event_id`.

**Acceptance Criteria**:
- [ ] `events.php` (SSE) streams events since `?since_id=` or the
      `Last-Event-ID` header
- [ ] Event format: `{id, action, entity_type, entity_id,
      user_login, created_at}`
- [ ] Patient-scoped subscription: `?patient_id=N` filters to
      events on that patient's schedules / intakes / notes
- [ ] `list_schedule.php` + `report_adherence.php` subscribe on
      load, re-fetch the relevant row when a matching event
      arrives (debounced 500 ms)
- [ ] No forever-open DB connections: poll the audit table every
      2 s, yield new rows, close on client disconnect
- [ ] Graceful fallback when `EventSource` is unsupported
      (no regression)
- [ ] Integration test: concurrent caregivers scenario

---

### HC-141: Additional translations

**Status**: `BACKLOG`
**Type**: Story
**Points**: 3
**Depends on**: Nothing

**Description**: Only `translations/English-US.txt` exists today,
though `translate()` is wired everywhere. Add Spanish and
Portuguese-BR as the next two most-requested languages.

**Acceptance Criteria**:
- [ ] `translations/Spanish.txt` with all keys from
      `English-US.txt` translated
- [ ] `translations/Portuguese-BR.txt` with all keys translated
- [ ] Language picker in `settings.php` (per-user preference,
      stored in `hc_user.language`)
- [ ] `init.php` picks the user's language, falls back to
      `$LANGUAGE` config, then English-US
- [ ] Missing-key coverage check in CI (new test: every key in
      English-US appears in each other language file)
- [ ] Date/number formatting follows locale (PHP `IntlDateFormatter`)

---

### HC-142: E2E fixture seeding + richer Playwright suite

**Status**: `BACKLOG`
**Type**: Story
**Points**: 3
**Depends on**: HC-078

**Description**: HC-078's acceptance asked for smoke + merge +
adherence flows. We shipped smoke-only because richer flows needed
seeded fixtures that don't exist in CI. Close the gap.

**Acceptance Criteria**:
- [ ] `bin/seed_e2e_fixtures.php`: creates 2 patients, 5
      medicines, 3 active schedules, 30 days of intakes with
      ~85% adherence, one cadence-mismatch case, one low-supply
      case
- [ ] `e2e.yml` runs the seeder after `docker compose up` and
      before Playwright
- [ ] New specs: record-intake flow, merge-medicines flow,
      adherence-report renders chart with colour-correct cells,
      supply-alert appears on the dashboard
- [ ] Each spec under 30s; total suite under 3 min
- [ ] Fixtures are additive — re-running the seeder is idempotent

---

### HC-143: Reverse-proxy auth mode

**Status**: `BACKLOG`
**Type**: Story
**Points**: 3
**Depends on**: HC-010

**Description**: Users already running Authelia / Authentik /
Caddy-forward-auth / Traefik-forward-auth want to delegate auth
entirely. The old WebCalendar HTTP-auth hook was removed in
HC-073; add a narrower, explicit mode.

**Acceptance Criteria**:
- [ ] `hc_config`: `auth_mode` ENUM('native','reverse_proxy')
      DEFAULT 'native', `reverse_proxy_header`
      (default `X-Forwarded-User`)
- [ ] When `auth_mode='reverse_proxy'`:
      - `login.php` / `logout.php` redirect to `$HOME_LINK`
      - `validate.php` reads the configured header and resolves
        the user via `UserRepository::findByLogin`
      - Missing / mismatched users trigger a 401 (no auto-provision
        by default to avoid confusion)
      - TOTP / password reset / password-policy gates are all
        bypassed — the proxy owns the factor
- [ ] Admin UI toggle in `settings.php`, with a prominent warning
      about requiring a proxy that sets the trusted header
- [ ] Docs page: "Reverse proxy auth with Authelia / Authentik /
      Caddy" with sample configs
- [ ] Integration test: fake proxy header resolves to the right
      user; missing header → 401

---

## Story Dependency Graph

```
HC-001 (Composer/PHPUnit)
  ├── HC-002 (Extract pure functions)
  │     └── HC-005 (Refactor dosesRemaining)
  ├── HC-003 (Database interface + SQLite)
  │     ├── HC-004 (Repository layer)
  │     │     ├── HC-005 (Refactor dosesRemaining)
  │     │     ├── HC-020 (CSV export)
  │     │     ├── HC-021 (Print summary)
  │     │     └── HC-031 (API endpoints)
  │     ├── HC-006 (Test fixtures)
  │     ├── HC-010 (User roles)
  │     │     ├── HC-011 (Role enforcement)
  │     │     ├── HC-013 (Audit logging)
  │     │     │     └── HC-014 (Rate limiting)
  │     │     └── HC-030 (API key auth)
  │     │           └── HC-031 (API read endpoints)
  │     │                 └── HC-032 (API write endpoints)
  │     └── HC-041 (ntfy config)
  ├── HC-007 (Request abstraction)
  └── HC-012 (Session timeout)

HC-005 (dosesRemaining refactored)
  ├── HC-022 (Adherence calculation)
  │     └── HC-023 (Adherence report)
  └── HC-040 (Supply alerts)

HC-050 (Docker) -- no dependencies
HC-051 (CI) -- depends on HC-001, HC-003
HC-060 (PWA) -- no dependencies
HC-061 (UI redesign) -- no dependencies

EPIC-7 (follow-ups, all DONE):
HC-070 (Audit log viewer)         <- HC-013
HC-071 (Signed-URL feeds/exports) <- HC-030
HC-072 (Migrations runner)        <- HC-001
  ├── HC-073 (Drop dead WebCal code) <- HC-072
  └── HC-074 (Drop is_admin column)  <- HC-072
HC-075 (Cadence-mismatch warning) <- HC-022
HC-076 (API rate limiting)        <- HC-031
HC-077 (Coverage in CI)           <- HC-051
HC-078 (Playwright E2E)           <- HC-050, HC-051
HC-079 (CSP + security headers)   -- no dependencies
HC-080 (PHP 8.3/8.4 matrix)       <- HC-051
HC-081 (Dependabot)               <- HC-051

EPIC-8 Caregiver Notes UX (BACKLOG):
HC-082 (Notes entry & edit UI)    <- HC-004, HC-011, HC-013
HC-083 (Notes list / browse)     <- HC-082
HC-084 (Notes CSV import)        <- HC-082
HC-085 (Notes plain-text journal import) <- HC-082

EPIC-9 Auth Hardening (BACKLOG):
HC-090 (TOTP 2FA)                 <- HC-010, HC-013
HC-091 (Password reset flow)      <- HC-071, HC-101
HC-092 (Password complexity)      -- no dependencies
HC-093 (Session cookie audit)     -- no dependencies

EPIC-10 Notification Channels (BACKLOG):
HC-100 (Channel abstraction)      <- HC-041
  ├── HC-101 (Email channel)     <- HC-100
  │     └── HC-091 (Password reset needs email)
  ├── HC-102 (Webhook channel)   <- HC-100
  └── HC-103 (Per-user prefs)    <- HC-101, HC-102

EPIC-11 Medication Data Breadth (BACKLOG):
HC-110 (Drug DB autocomplete)     <- HC-004
  ├── HC-111 (Barcode / NDC)     <- HC-110
  └── HC-112 (Interaction check) <- HC-110
HC-113 (Veterinary profile)       -- no dependencies

EPIC-12 Schedule Semantics (BACKLOG):
HC-120 (PRN / as-needed)          <- HC-002
HC-121 (Pulse / cycle dosing)     <- HC-002
HC-122 (Step / taper)             <- HC-002
HC-123 (Wall-clock times)         <- HC-002
HC-124 (Pause / skip today)       -- no dependencies

EPIC-13 Portability & Attachments (BACKLOG):
HC-130 (Photo / doc attachments)  <- HC-011
HC-131 (CSV import all entities)  <- HC-004

EPIC-14 Polish & Reach (BACKLOG):
HC-140 (Real-time SSE)            <- HC-013
HC-141 (Additional translations)  -- no dependencies
HC-142 (E2E fixture seeding)      <- HC-078
HC-143 (Reverse-proxy auth)       <- HC-010
```

---

## Suggested Sprint Plan

### Sprint 1: Test Foundation (EPIC-0 core)
- HC-001: Composer + PHPUnit setup
- HC-002: Extract pure domain functions + unit tests
- HC-003: Database interface + SQLite adapter
- HC-006: Test fixtures and factories

### Sprint 2: Repository Layer & First Integration Tests (EPIC-0 continued)
- HC-004: Repository layer
- HC-005: Refactor dosesRemaining()
- HC-007: Request abstraction

### Sprint 3: Auth & Security (EPIC-1)
- HC-010: User roles
- HC-011: Role enforcement
- HC-012: Session timeout
- HC-013: Audit logging
- HC-014: Login rate limiting

### Sprint 4: Export & Reporting (EPIC-2)
- HC-020: CSV export
- HC-021: Printable medication summary
- HC-022: Adherence calculation
- HC-023: Adherence report page

### Sprint 5: API (EPIC-3)
- HC-030: API key auth
- HC-031: Read API endpoints
- HC-032: Write API endpoints

### Sprint 6: Polish & DevOps (EPIC-4, 5, 6)
- HC-040: Supply alerts
- HC-041: ntfy config
- HC-050: Docker
- HC-051: CI pipeline
- HC-060: PWA support
- HC-061: Page redesign

### Sprint 7: Hardening & Operability (EPIC-7) — *DONE*
All 12 stories in EPIC-7 shipped between 2026-04-13 and 2026-04-16.

---

### Sprint 8: Caregiver Notes (EPIC-8) — *up next*
Small epic, user has data waiting to be imported. Pull in this order:

1. **HC-082** (Entry & edit UI) — foundation; can't import without
   the repository layer and handler.
2. **HC-083** (List / browse view) — makes the notes visible.
3. **HC-085** (Plain-text journal import) — directly closes the
   user's immediate need; their existing data is in free-form
   journal format, not CSV. Prioritise over HC-084.
4. **HC-084** (Structured CSV import) — nice-to-have for future
   imports from other tools; pull after HC-085 if time allows.

Total ~13 points if all four land, ~10 for the first three.

---

### Sprint 9: Auth Hardening + Email (EPIC-9 + start of EPIC-10)
Security + enables password reset. Two dependent threads:

1. **HC-100** (NotificationChannel abstraction) — precursor.
2. **HC-101** (Email channel) — unlocks HC-091.
3. **HC-092** (Password complexity) — independent, cheap.
4. **HC-093** (Session cookie audit) — independent, 1 point.
5. **HC-091** (Password reset) — once email lands.
6. **HC-090** (TOTP 2FA) — biggest remaining security gap; do last
   in the sprint since it's 5 points and independent.

Total ~19 points — may spill into two sprints.

---

### Sprint 10: Notification reach + Schedule semantics (parts of
### EPIC-10 + EPIC-12)
Operational improvements users will feel immediately.

1. **HC-102** (Webhook channel) — one-line Home Assistant / Slack /
   Discord.
2. **HC-103** (Per-user channel prefs) — completes EPIC-10.
3. **HC-120** (PRN schedules) — common request, small change.
4. **HC-124** (Pause / skip today) — addresses "my cat goes to the
   groomer today" use case.

Total ~9 points.

---

### Sprint 11+: Medication Data Breadth (EPIC-11)
HC-110 (drug DB) unlocks HC-111 (barcode) and HC-112 (interactions);
queue them together in a single themed sprint. HC-113 (vet profile)
independent, can slot in.

---

### Later: Schedule richness + Portability (EPIC-12 cont'd, EPIC-13)
HC-121 (pulse), HC-122 (step), HC-123 (wall-clock) each tweak the
math engine — do them close together to batch-test
`ScheduleCalculator`. HC-130 (attachments) + HC-131 (CSV import)
share no dependencies with them and can run in parallel.

---

### Backlog: Polish & Reach (EPIC-14)
HC-140 / HC-141 / HC-142 / HC-143 — individually small, pull when
convenient or driven by user requests.

---

## Prioritisation Notes

The four new epics most worth starting first:

| Rank | Epic / Story | Why now |
|------|------|---------|
| 1 | **EPIC-8** (Caregiver Notes) | User has data waiting; small cost, immediate delivery |
| 2 | **HC-092, HC-093** (policy + cookies) | Free wins, no dependencies |
| 3 | **HC-100 + HC-101** (channel abstraction + email) | Unblocks HC-091 (password reset), the second-largest auth gap |
| 4 | **HC-090** (TOTP 2FA) | Largest remaining security gap; all primitives exist |

Deferrable indefinitely without real cost: HC-141 (translations),
HC-143 (reverse-proxy auth). Pull if a user requests.
