# STATUS.md -- Project Backlog & Progress

*Last updated: 2026-04-13*

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
- [ ] `tests.yml` adds a coverage step (`pcov` or `xdebug` via
      shivammathur/setup-php's `coverage:` flag)
- [ ] `vendor/bin/phpunit --coverage-text --coverage-clover=coverage.xml`
      runs alongside the gate
- [ ] Coverage threshold enforced at 80% for `src/` (lines); CI fails
      below it
- [ ] Coverage badge added to README (Coveralls or Codecov, whichever
      is simplest)
- [ ] Per-class report uploaded as a CI artifact for inspection

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
- [ ] CI workflow `e2e.yml` stands up the Docker stack
      (`docker compose up -d`), seeds the default admin user, runs
      Playwright against `http://localhost:8080`
- [x] Smoke flow: login → list_schedule → record an intake → log out
- [x] Merge-page flow: login → merge_medicines → preview → confirm
- [ ] Adherence-report flow: login → report_adherence → toggle range
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
- [ ] `tests.yml` adds a matrix on `php-version: ['8.2', '8.3', '8.4']`
- [ ] Each version cell runs `composer check`
- [ ] Any new deprecations under 8.3/8.4 are addressed in code
- [ ] `composer.json` `php` requirement bumped if any 8.3+ feature is
      adopted; otherwise stays `>=8.1`
- [ ] Dockerfile bumps to `php:8.3-apache` once green on 8.3

---

### HC-081: Dependabot for Composer + bundled JS

**Status**: `DONE`
**Type**: Story
**Points**: 1
**Depends on**: HC-051

**Description**: PHPUnit, PHPStan, Chart.js, Bootstrap, jQuery all
need periodic refresh. Automate it.

**Acceptance Criteria**:
- [ ] `.github/dependabot.yml` watches `composer` weekly
- [ ] `.github/dependabot.yml` watches `github-actions` monthly
- [ ] `pub/chart.umd.min.js` re-fetch documented in
      README ("upgrading PWA assets") since it's not a Composer dep

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

EPIC-7 (follow-ups, all currently BACKLOG):
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

### Sprint 7: Hardening & Operability (EPIC-7) — *new follow-ups*
The 12 stories in EPIC-7 don't form a single sprint -- they're a
prioritised backlog for the team to pull from. Suggested ordering by
risk-reduction-per-point:

1. **HC-077** (Coverage in CI) — single small change, tells us where
   the test gaps actually live before we touch anything else.
2. **HC-078** (Playwright E2E) — three real bugs (CSRF, jQuery CDN,
   double session_start) shipped past PHPStan + PHPUnit. Browser
   coverage closes that gap.
3. **HC-072** (Migrations runner) — unblocks HC-073 and HC-074 and
   removes a class of "did anyone run that ALTER?" deploy mistakes.
4. **HC-073** + **HC-074** — strip the WebCalendar / dual-auth
   scaffolding now that the native flow has bedded in.
5. **HC-079** (CSP + headers) — easy security wins.
6. **HC-070** (Audit log viewer) — turns existing data into
   operability.
7. **HC-076** (API rate limiting), **HC-071** (Signed URLs) — security
   hardening as the API surface area grows.
8. **HC-075** (Cadence-mismatch warning) — UX safety net.
9. **HC-080** (PHP 8.3/8.4) + **HC-081** (Dependabot) — automation
   chores; pick up whenever the matrix can afford the CI minutes.
