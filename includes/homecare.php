<?php
/*
 * PHP functions that are specific to the HomeCare database or its use.
 * General purpose functions that were borrowed/copied from other projects
 * are in functions.php, formvars.php, etc. The functions in this file would
 * not be useful to other projects.
 */

// Load Composer autoload for namespaced domain classes when available.
// Pages include this file before any DB call, so we pull the autoloader in
// here once. When Composer has not been bootstrapped (e.g. initial install
// before `composer install`) the wrappers below still work via a local fallback.
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

use HomeCare\Audit\AuditLogger;
use HomeCare\Auth\Authorization;
use HomeCare\Database\DbiAdapter;
use HomeCare\Domain\ScheduleCalculator;
use HomeCare\Repository\InventoryRepository;
use HomeCare\Repository\PatientRepository;
use HomeCare\Repository\ScheduleRepository;
use HomeCare\Repository\StepRepository;
use HomeCare\Service\InventoryService;

/**
 * Look up the current user's role from hc_user.
 *
 * Returns 'admin' when `is_admin = 'Y'` even if the role column has not
 * been migrated yet, so the helper keeps working during the HC-010
 * transition. Unknown logins fall back to 'viewer' -- the most
 * restrictive role -- so fail-closed is the default.
 */
function getCurrentUserRole(): string
{
    $login = $GLOBALS['login'] ?? null;
    if (!is_string($login) || $login === '') {
        return Authorization::ROLE_VIEWER;
    }

    $rows = dbi_get_cached_rows(
        'SELECT role, is_admin FROM hc_user WHERE login = ?',
        [$login]
    );
    if (empty($rows) || empty($rows[0])) {
        return Authorization::ROLE_VIEWER;
    }

    $role = (string) ($rows[0][0] ?? '');
    $isAdmin = (string) ($rows[0][1] ?? '');
    // Legacy is_admin='Y' always promotes, regardless of stored role. Once
    // HC-013/HC-014 land and all writes go through the role column we can
    // drop this bridge and trust the column.
    if ($isAdmin === 'Y') {
        return Authorization::ROLE_ADMIN;
    }

    return $role === '' ? Authorization::ROLE_CAREGIVER : $role;
}

/**
 * Enforce role-based access on a handler or admin page.
 *
 * Calls `die_miserable_death()` when the current user's role does not
 * satisfy the minimum. Typical usage at the top of a handler:
 *
 *     require_once 'includes/init.php';
 *     require_role('caregiver');
 */
function require_role(string $minimumRole): void
{
    $auth = new Authorization(getCurrentUserRole());
    if (!$auth->satisfies($minimumRole)) {
        die_miserable_death(
            translate('Access denied') . ': '
            . translate('this action requires the') . ' ' . htmlspecialchars($minimumRole) . ' '
            . translate('role')
        );
    }
}

/**
 * Record one audit event against the live dbi4php connection.
 *
 * Convenience wrapper over {@see AuditLogger}: reads `$login` and
 * `$_SERVER['REMOTE_ADDR']` for context so handlers can simply call
 * `audit_log('intake.recorded', 'schedule', $scheduleId, [...])`
 * without re-wiring the graph each time.
 *
 * @param array<string,mixed> $details
 */
function audit_log(string $action, string $entityType = '', ?int $entityId = null, array $details = []): void
{
    static $logger = null;
    if ($logger === null) {
        $logger = new AuditLogger(
            new DbiAdapter(),
            static function (): ?string {
                $login = $GLOBALS['login'] ?? null;
                return is_string($login) && $login !== '' ? $login : null;
            },
            static function (): ?string {
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                return is_string($ip) && $ip !== '' ? $ip : null;
            }
        );
    }

    $logger->log($action, $entityType, $entityId, $details);
}

/**
 * Build the InventoryService against the live dbi4php connection.
 *
 * Kept inline rather than in a container because HomeCare has no DI
 * framework; every request already bootstraps a single mysqli connection
 * via init.php, and DbiAdapter is a thin wrapper over it.
 */
function homecare_inventory_service(): InventoryService
{
    static $service = null;
    if ($service === null) {
        $db = new DbiAdapter();
        $service = new InventoryService(
            new InventoryRepository($db),
            new ScheduleRepository($db),
            new PatientRepository($db),
            new StepRepository($db),
        );
    }

    return $service;
}

/**
 * Get the configured weight display unit ('kg' or 'lb').
 */
function getWeightUnit(): string
{
    return ($GLOBALS['weight_unit'] ?? 'kg') === 'lb' ? 'lb' : 'kg';
}

/**
 * Convert a weight from internal kg storage to the display unit.
 */
function displayWeight(float $kg, int $decimals = 2): string
{
    if (getWeightUnit() === 'lb') {
        return number_format($kg * 2.20462, $decimals);
    }
    return number_format($kg, $decimals);
}

/**
 * Return the weight unit label string.
 */
function weightUnitLabel(): string
{
    return getWeightUnit();
}

/**
 * Convert a user-entered weight in the display unit back to kg for storage.
 */
function inputWeightToKg(float $value): float
{
    if (getWeightUnit() === 'lb') {
        return $value / 2.20462;
    }
    return $value;
}

// $lastTaken is the DateTime object of the last time the medication was taken
// $frequency is how often to take it (1d, 8h, 12h, etc.)
function calculateSecondsUntilDue($lastTaken, $frequency, $showNegative = false)
{
    return ScheduleCalculator::calculateSecondsUntilDue(
        (string) $lastTaken,
        (string) $frequency,
        (bool) $showNegative
    );
}

function getDueDateTimeInSeconds($patient_id, $schedule_id, $medicine_id, $show_negative = false)
{
    // Fetch patient's medication schedule
    $sql = "SELECT ms.id, m.name, ms.frequency, ms.start_date, ms.end_date, 
            (SELECT MAX(taken_time) FROM hc_medicine_intake WHERE schedule_id = ms.id) AS last_taken
            FROM hc_medicine_schedules ms
            JOIN hc_medicines m ON ms.medicine_id = m.id
            WHERE ms.patient_id = ?
            AND ms.id = ?
            AND ms.medicine_id = ?";

    $rows = dbi_get_cached_rows($sql, [$patient_id, $schedule_id, $medicine_id]);
    if (!empty($rows) && !empty($rows[0])) {
        $freq = $rows[0][2];
        $lastTaken = $rows[0][5];
        if (empty($lastTaken)) {
            return null;
        }
        return calculateSecondsUntilDue($lastTaken, $freq, $show_negative);
    } else {
        return null;
    }
}

function getPatient($patientId)
{
    $sql = 'SELECT id, name, species, weight_kg, weight_as_of, created_at, updated_at, is_active FROM hc_patients WHERE id = ?';
    $rows = dbi_get_cached_rows($sql, [$patientId]);
    if ($rows) {
        $patient = [
            'id' => $rows[0][0],
            'name' => $rows[0][1],
            'species' => $rows[0][2],
            'weight_kg' => $rows[0][3] !== null ? (float) $rows[0][3] : null,
            'weight_as_of' => $rows[0][4],
            'created_at' => $rows[0][5],
            'updated_at' => $rows[0][6],
            'is_active' => $rows[0][7],
        ];
        return $patient;
    } else {
        die_miserable_death("No such patient id " . htmlspecialchars($patientId));
    }
}

/**
 * Pick the "active" patient whose schedule/notes/reports the menu
 * should point at.
 *
 * Resolution order:
 *   1. `?patient_id=N` in the current request — treated as a context
 *      switch. Persisted to the session so navigation downstream
 *      keeps the same patient.
 *   2. `$_SESSION['active_patient_id']` — remembered from an earlier
 *      context switch.
 *   3. First active patient from `hc_patients`.
 *   4. `0` when no patients exist (fresh install).
 *
 * Only verifies that the patient row exists; `is_active=0` is still
 * a valid target so historical views keep working.
 */
function getActivePatientId(): int
{
    $fromUrl = getIntValue('patient_id');
    if (!empty($fromUrl) && patientExistsById((int) $fromUrl)) {
        $_SESSION['active_patient_id'] = (int) $fromUrl;
        return (int) $fromUrl;
    }

    $fromSession = $_SESSION['active_patient_id'] ?? null;
    if (is_int($fromSession) && $fromSession > 0 && patientExistsById($fromSession)) {
        return $fromSession;
    }

    $rows = dbi_get_cached_rows(
        'SELECT id FROM hc_patients WHERE is_active = 1 ORDER BY name ASC LIMIT 1'
    );
    if (!empty($rows) && isset($rows[0][0])) {
        $id = (int) $rows[0][0];
        $_SESSION['active_patient_id'] = $id;
        return $id;
    }

    return 0;
}

/**
 * True when a patient row with this id exists (active or disabled).
 */
function patientExistsById(int $id): bool
{
    $rows = dbi_get_cached_rows(
        'SELECT id FROM hc_patients WHERE id = ?',
        [$id]
    );
    return !empty($rows);
}

function getPatients($includeDisabled = false)
{
    $sql = $includeDisabled
        ? 'SELECT name, id FROM hc_patients ORDER BY name ASC'
        : 'SELECT name, id FROM hc_patients WHERE is_active = 1 ORDER BY name ASC';
    $rows = dbi_get_cached_rows($sql);
    $ret = [];
    foreach ($rows as $row) {
        $patient = [
            'name' => $row[0],
            'id' => $row[1]
        ];
        $ret[] = $patient;
    }
    return $ret;
}

// Calculate the next due date and time and return in iso8601 format.
function calculateNextDueDate($lastTaken, $frequency) {
    return ScheduleCalculator::calculateNextDueDate((string) $lastTaken, (string) $frequency);
}

function getIntervalSpecFromFrequency($frequency) {
    return ScheduleCalculator::getIntervalSpecFromFrequency((string) $frequency);
}

function frequencyToSeconds($frequency) {
    return ScheduleCalculator::frequencyToSeconds((string) $frequency);
}

// Return an array of what remains of a medication
// [ 'remainingDays' => 100, 'remainingDoses' => 50, 'lastInventory' => 70,
//   'quantityTakenSince' => 30, 'unitPerDose' => 0, 'medicineName' => '' ]
function dosesRemaining($medicine_id, $schedule_id, $assumePastIntake = false, $start_date = null, $frequency = null) {
    return homecare_inventory_service()->calculateRemaining(
        (int) $medicine_id,
        (int) $schedule_id,
        (bool) $assumePastIntake,
        $start_date === null ? null : (string) $start_date,
        $frequency === null ? null : (string) $frequency
    );
}

// Returns an array of medicines with current stock, last updated, and days-of-supply.
function getInventoryDashboardData() {
    $sql = "SELECT m.id, m.name, m.dosage,
            inv.current_stock, inv.recorded_at
            FROM hc_medicines m
            LEFT JOIN hc_medicine_inventory inv ON inv.medicine_id = m.id
              AND inv.recorded_at = (
                SELECT MAX(inv2.recorded_at) FROM hc_medicine_inventory inv2
                WHERE inv2.medicine_id = m.id
              )
            ORDER BY m.name ASC";
    $rows = dbi_get_cached_rows($sql);

    $results = [];
    foreach ($rows as $row) {
        $medicine_id = $row[0];
        $currentStock = $row[3] !== null ? floatval($row[3]) : null;

        // Calculate daily consumption from all active schedules for this medicine
        $dailyConsumption = 0;
        $schedSql = "SELECT unit_per_dose, frequency FROM hc_medicine_schedules
                     WHERE medicine_id = ? AND (end_date IS NULL OR end_date >= CURDATE())";
        $schedRows = dbi_get_cached_rows($schedSql, [$medicine_id]);
        foreach ($schedRows as $sched) {
            $upd = floatval($sched[0]);
            $freq = $sched[1];
            try {
                $secondsPerDose = frequencyToSeconds($freq);
                $dosesPerDay = 86400 / $secondsPerDose;
                $dailyConsumption += $upd * $dosesPerDay;
            } catch (Exception $e) {
                // skip invalid frequencies
            }
        }

        $daysSupply = null;
        if ($currentStock !== null && $dailyConsumption > 0) {
            $daysSupply = floor($currentStock / $dailyConsumption);
        }

        $results[] = [
            'medicine_id' => $medicine_id,
            'name' => $row[1],
            'dosage' => $row[2],
            'current_stock' => $currentStock,
            'last_updated' => $row[4],
            'daily_consumption' => $dailyConsumption,
            'days_supply' => $daysSupply,
        ];
    }

    return $results;
}

/**
 * Parse a date parameter for the intake exporters.
 *
 * Accepts both YYYY-MM-DD (the on-page date picker's native format) and
 * YYYYMMDD (shell-friendly, emitted by `date +%Y%m%d` without needing
 * extra quoting). Everything else falls back to $default so a typo in
 * the URL never produces a silent empty export — you get 30-days-ago or
 * today, which is at least obviously-wrong.
 *
 * Normalised to YYYY-MM-DD either way so IntakeExportQuery's SQL
 * parameter handling stays simple.
 */
function parse_export_date($raw, string $default): string
{
    if (!is_string($raw)) {
        return $default;
    }
    $raw = trim($raw);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
        $y = (int) $m[1]; $mo = (int) $m[2]; $d = (int) $m[3];
    } elseif (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $m)) {
        $y = (int) $m[1]; $mo = (int) $m[2]; $d = (int) $m[3];
    } else {
        return $default;
    }
    if (!checkdate($mo, $d, $y)) {
        return $default;
    }

    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}
?>