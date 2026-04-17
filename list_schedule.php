<?php
require_once 'includes/init.php';

$patient_id = getGetValue('patient_id');

// Missing or non-numeric patient_id → bounce to the home page. This
// catches stale bookmarks, menu links that lost their query string,
// and anyone who reached here via an empty return_path after login.
// Must run BEFORE print_header() so the redirect can emit headers.
if (!is_numeric($patient_id) || (int) $patient_id <= 0) {
    header('Location: index.php');
    exit;
}

print_header();

$patient = getPatient($patient_id);
$patientName = $patient['name'];
$showCompletedParam = getIntValue('show_completed');
$showCompleted = !empty($showCompletedParam);
$assumePastIntake = getIntValue('assume_past_intake');

$dueInNextHour = 1800; // 30 minutes in seconds

// NOTE: the sticky patient header and the toggle controls used to be echoed
// here, at the top of the page. They now live further down — after the
// grouping loop — so the header can show live "N overdue / N due soon"
// badges computed from the same data the sections render.

// ── Fetch schedule data ──
if (!$showCompleted) {
    $includeCompletedSql = ' AND (ms.end_date IS NULL OR ms.end_date >= CURDATE())';
} else {
    $includeCompletedSql = '';
}
$sql = "SELECT ms.id, m.name, ms.frequency, ms.start_date, ms.end_date, ms.medicine_id, ms.is_prn,
        (SELECT MAX(mi.taken_time) FROM hc_medicine_intake mi WHERE mi.schedule_id = ms.id) AS last_taken
        FROM hc_medicine_schedules ms
        JOIN hc_medicines m ON ms.medicine_id = m.id
        WHERE ms.patient_id = ? $includeCompletedSql
        ORDER BY last_taken ASC";

$rows = dbi_get_cached_rows($sql, [$patient_id]);

// HC-124: pause-aware schedule rendering. Load the repository once so
// we can check each row for an active pause.
$pauseDb = new \HomeCare\Database\DbiAdapter();
$pauseRepo = new \HomeCare\Repository\PauseRepository($pauseDb);
$todayDate = date('Y-m-d');

// ── Build medication data and group by urgency ──
// HC-120: PRN ("as-needed") schedules land in their own bucket — they
// never compute a "next due" and never surface an Overdue badge. They
// still accept Record-intake actions, so the row UI is otherwise the
// same as a normal schedule.
$groups = [
    'overdue'  => [],
    'due_soon' => [],
    'ok'       => [],
    'paused'   => [],
    'prn'      => [],
    'done'     => [],
];

foreach ($rows as $row) {
    $schedule_id  = $row[0];
    $medName      = $row[1];
    $frequency    = $row[2];
    $start_date   = $row[3];
    $end_date     = $row[4];
    $medicine_id  = $row[5];
    $isPrn        = isset($row[6]) && $row[6] === 'Y';
    $lastTaken    = !empty($row[7]) ? $row[7] : null;
    $lastTakenNicely = formatDateNicely($lastTaken);

    $secondsUntilDue = null;
    $nextDueLabel    = 'Not Yet Taken';
    $nextDueDetail   = '';
    $sortKey         = '0000-' . $medName;
    $statusGroup     = 'ok';
    $isCompleted     = false;

    $remainingDoses = dosesRemaining($medicine_id, $schedule_id, $assumePastIntake, $start_date, $frequency);

    // HC-124: check if schedule is currently paused. Paused rows land
    // in their own bucket with a "Paused" badge and a "Resume" action
    // instead of the usual due-time math.
    $isPaused = !$isPrn && $pauseRepo->isPausedOn($schedule_id, $todayDate);

    if ($isPaused) {
        $nextDueLabel = 'Paused';
        $nextDueDetail = '';
        $sortKey = 'PAUSED-' . strtolower($medName) . '-' . sprintf('%06d', $schedule_id);
        $statusGroup = 'paused';
        if (!empty($end_date) && $end_date < $todayDate) {
            $isCompleted = true;
            $statusGroup = 'done';
            $nextDueLabel = 'Completed';
            $sortKey = sprintf("999999-%06d", $schedule_id);
        }
    } elseif ($isPrn) {
        // PRN rows have no next-due, sort alphabetically within the PRN
        // bucket so the list is stable regardless of last-intake time.
        $nextDueLabel = 'PRN — no schedule';
        $nextDueDetail = 'Take as needed';
        $sortKey = 'PRN-' . strtolower($medName) . '-' . sprintf('%06d', $schedule_id);
        $statusGroup = 'prn';
        // Still honour end_date for PRN so an ended PRN schedule rolls
        // into the Completed bucket like any other.
        if (!empty($end_date) && $end_date < date('Y-m-d')) {
            $isCompleted   = true;
            $statusGroup   = 'done';
            $nextDueLabel  = 'Completed';
            $nextDueDetail = '';
            $sortKey       = sprintf("999999-%06d", $schedule_id);
        }
    } elseif ($lastTaken) {
        $secondsUntilDue = calculateSecondsUntilDue($lastTaken, $frequency);
        $hours   = floor($secondsUntilDue / 3600);
        $minutes = floor(($secondsUntilDue % 3600) / 60);
        $sortKey = sprintf("%06d-%06d", (60 * $hours) + $minutes, $schedule_id);

        $nextDueDateTime = calculateNextDueDate($lastTaken, $frequency);
        $nextDueDetail   = formatDateNicely($nextDueDateTime);

        if ($secondsUntilDue <= 0) {
            $nextDueLabel = 'Overdue';
            $statusGroup  = 'overdue';
        } elseif ($secondsUntilDue <= $dueInNextHour) {
            $nextDueLabel = $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
            $statusGroup  = 'due_soon';
        } else {
            $nextDueLabel = $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
            $statusGroup  = 'ok';
        }

        // Check if completed
        if (!empty($end_date) && $end_date < date('Y-m-d')) {
            $isCompleted   = true;
            $statusGroup   = 'done';
            $nextDueLabel  = 'Completed';
            $nextDueDetail = '';
            $sortKey       = sprintf("999999-%06d", $schedule_id);
        } elseif (!empty($end_date) && $end_date == date('Y-m-d')) {
            if ($secondsUntilDue > secondsUntilMidnight()) {
                if (!$showCompleted) {
                    continue;
                }
                $isCompleted   = true;
                $statusGroup   = 'done';
                $nextDueLabel  = 'Completed';
                $nextDueDetail = '';
                $sortKey       = sprintf("999999-%06d", $schedule_id);
            }
        }
    }

    // Cadence mismatch warning
    require_once 'src/Database/DbiAdapter.php';
    require_once 'src/Repository/IntakeRepository.php';
    require_once 'src/Repository/ScheduleRepository.php';
    require_once 'src/Domain/ScheduleCalculator.php';
    require_once 'src/Service/CadenceCheck.php';

    $db = new \HomeCare\Database\DbiAdapter();
    $intakesRepo = new \HomeCare\Repository\IntakeRepository($db);
    $schedulesRepo = new \HomeCare\Repository\ScheduleRepository($db);
    $calc = new \HomeCare\Domain\ScheduleCalculator();
    $cadenceCheck = new \HomeCare\Service\CadenceCheck($intakesRepo, $schedulesRepo, $calc);
    $warningText = $cadenceCheck->getWarningText($schedule_id);

    // Build remaining display. PRN schedules have no cadence, so the
    // "(N days)" and "Until <date>" framings don't apply — show doses
    // only, or "None" when stock is depleted.
    $days = $remainingDoses['remainingDays'];
    $futureDate = date('M j, Y', strtotime("+$days days"));
    if (empty($remainingDoses['remainingDoses'])) {
        $remainShort  = translate('None');
        $remainDetail = '';
    } elseif ($isPrn) {
        $remainShort  = sprintf("%s doses", $remainingDoses['remainingDoses']);
        $remainDetail = '';
    } else {
        $doseCount    = $remainingDoses['remainingDoses'];
        $remainShort  = sprintf("%s doses (%d days)", $doseCount, $days);
        $remainDetail = 'Until ' . $futureDate;
        if ($assumePastIntake) {
            $remainDetail .= ' (est.)';
        }
    }

    // Action URLs
    $recordUrl = 'record_intake.php?schedule_id=' . urlencode($schedule_id) . '&patient_id=' . urlencode($patient_id);
    $editUrl   = 'add_to_schedule.php?patient_id=' . urlencode($patient_id) . '&schedule_id=' . urlencode($schedule_id) . '&medicine_id=' . urlencode($medicine_id);
    $adjustUrl = 'adjust_dosage.php?patient_id=' . urlencode($patient_id) . '&schedule_id=' . urlencode($schedule_id);

    // Action URLs for pause/skip/resume (HC-124)
    $pauseUrl = 'pause_schedule.php?patient_id=' . urlencode($patient_id) . '&schedule_id=' . urlencode($schedule_id);

    $entry = [
        'sortKey'         => $sortKey,
        'medName'         => $medName,
        'frequency'       => $isPrn ? 'PRN' : $frequency,
        'lastTakenNicely' => $lastTakenNicely,
        'nextDueLabel'    => $nextDueLabel,
        'nextDueDetail'   => $nextDueDetail,
        'secondsUntilDue' => ($isPrn || $isPaused) ? null : $secondsUntilDue,
        'remainShort'     => $remainShort,
        'remainDetail'    => $remainDetail,
        'recordUrl'       => $recordUrl,
        'editUrl'         => $editUrl,
        'adjustUrl'       => $adjustUrl,
        'pauseUrl'        => $pauseUrl,
        'statusGroup'     => $statusGroup,
        'isCompleted'     => $isCompleted,
        'isPrn'           => $isPrn,
        'isPaused'        => $isPaused,
        'scheduleId'      => $schedule_id,
        'patientId'       => $patient_id,
    ];

    $groups[$statusGroup][] = $entry;
}

// Sort each group by sortKey
foreach ($groups as &$g) {
    usort($g, function ($a, $b) {
        return strcmp($a['sortKey'], $b['sortKey']);
    });
}
unset($g);

// ── Sticky patient header (enriched) ──
// Rendered here (not at the top) so we can show counts from $groups.
$overdueCount  = count($groups['overdue']);
$dueSoonCount  = count($groups['due_soon']);
$okCount       = count($groups['ok']);

$patientNameEsc = htmlspecialchars($patientName, ENT_QUOTES, 'UTF-8');
$patientIdEsc   = htmlspecialchars((string) $patient_id, ENT_QUOTES, 'UTF-8');
// HC-113: show species + weight alongside the patient name.
$patientSubline = '';
$patientSpecies = $patient['species'] ?? null;
$patientWeightKg = $patient['weight_kg'] ?? null;
if (!empty($patientSpecies)) {
    $patientSubline .= ucfirst(htmlspecialchars($patientSpecies, ENT_QUOTES, 'UTF-8'));
}
if ($patientWeightKg !== null) {
    $patientSubline .= ($patientSubline !== '' ? ' · ' : '') . htmlspecialchars((string) $patientWeightKg, ENT_QUOTES, 'UTF-8') . ' kg';
}

echo '<div class="schedule-sticky-header noprint" role="region" aria-label="Patient schedule header" id="scheduleStickyHeader">';
echo '<div class="d-flex justify-content-between align-items-center flex-wrap" style="gap:.5rem;">';

// Left: patient name + species/weight + status badges
echo '<div class="d-flex align-items-center flex-wrap" style="gap:.6rem;">';
echo '<div>';
echo '<h1 class="hc-patient-name" style="margin-bottom:0">' . $patientNameEsc . '</h1>';
if ($patientSubline !== '') {
    echo '<small class="text-muted">' . $patientSubline . '</small>';
}
echo '</div>';
echo '<div class="hc-status-badges" aria-label="Schedule status summary">';
if ($overdueCount > 0) {
    echo '<span class="hc-badge hc-badge-overdue" title="Overdue doses">'
       . '<span class="hc-badge-dot" aria-hidden="true"></span>'
       . $overdueCount . ' overdue</span>';
}
if ($dueSoonCount > 0) {
    echo '<span class="hc-badge hc-badge-due-soon" title="Due within 30 minutes">'
       . '<span class="hc-badge-dot" aria-hidden="true"></span>'
       . $dueSoonCount . ' due soon</span>';
}
if ($overdueCount === 0 && $dueSoonCount === 0 && $okCount > 0) {
    echo '<span class="hc-badge hc-badge-ok" title="No overdue or due-soon doses">'
       . '<span class="hc-badge-dot" aria-hidden="true"></span>'
       . 'all caught up</span>';
}
echo '</div>';
echo '</div>';

// Right: header actions (labels collapse when .is-compact)
echo '<div class="hc-header-actions">';
echo '<a href="medication_summary.php?patient_id=' . $patientIdEsc
   . '" class="btn btn-outline-secondary btn-sm" title="One-page printable summary">'
   . '<span class="hc-label-full">Print Summary</span>'
   . '<span class="hc-label-compact" aria-hidden="true">Print</span>'
   . '<span class="sr-only hc-label-compact">Print Summary</span>'
   . '</a>';
echo '<a href="list_caregiver_notes.php?patient_id=' . $patientIdEsc
   . '" class="btn btn-outline-secondary btn-sm" title="Caregiver notes">'
   . '<span class="hc-label-full">Notes</span>'
   . '<span class="hc-label-compact" aria-hidden="true">Notes</span>'
   . '<span class="sr-only hc-label-compact">Caregiver Notes</span>'
   . '</a>';
echo '<a href="edit_patient.php?id=' . $patientIdEsc
   . '" class="btn btn-outline-secondary btn-sm" title="Edit patient details">'
   . '<span class="hc-label-full">Edit Patient</span>'
   . '<span class="hc-label-compact" aria-hidden="true">Edit</span>'
   . '<span class="sr-only hc-label-compact">Edit Patient</span>'
   . '</a>';
echo '<a href="add_to_schedule.php?patient_id=' . $patientIdEsc
   . '" class="btn btn-primary btn-sm" title="Add medication to this schedule">'
   . '<span class="hc-label-full">+ Add Medication</span>'
   . '<span class="hc-label-compact" aria-hidden="true">+ Add</span>'
   . '<span class="sr-only hc-label-compact">Add Medication</span>'
   . '</a>';
echo '</div>';

echo '</div>'; // flex row
echo '</div>'; // sticky header

// ── Toggle controls (auto-submit, no button needed) ──
$baseUrl = 'list_schedule.php?patient_id=' . urlencode($patient_id);
echo '<div class="schedule-controls noprint mt-3">';
$assumeChecked = $assumePastIntake ? ' checked' : '';
echo '<div class="custom-control custom-switch">';
echo '<input type="checkbox" class="custom-control-input" id="assumePastIntake"' . $assumeChecked;
echo ' data-toggle-param="assume_past_intake">';
echo '<label class="custom-control-label" for="assumePastIntake">Assume past doses taken</label>';
echo '</div>';
$completedChecked = $showCompleted ? ' checked' : '';
echo '<div class="custom-control custom-switch">';
echo '<input type="checkbox" class="custom-control-input" id="showCompleted"' . $completedChecked;
echo ' data-toggle-param="show_completed">';
echo '<label class="custom-control-label" for="showCompleted">Show completed</label>';
echo '</div>';
echo '</div>';

// ── Section config ──
$sectionConfig = [
    'overdue'  => ['label' => 'Overdue',   'sectionClass' => 'section-overdue',  'statusClass' => 'status-overdue',  'icon' => 'exclamation-triangle-fill'],
    'due_soon' => ['label' => 'Due Soon',  'sectionClass' => 'section-due-soon', 'statusClass' => 'status-due-soon', 'icon' => 'clock'],
    'ok'       => ['label' => 'Upcoming',  'sectionClass' => 'section-ok',       'statusClass' => 'status-ok',       'icon' => ''],
    'paused'   => ['label' => 'Paused',    'sectionClass' => 'section-done',     'statusClass' => 'status-done',     'icon' => 'pause-circle'],
    'prn'      => ['label' => 'As-Needed (PRN)', 'sectionClass' => 'section-ok', 'statusClass' => 'status-ok',       'icon' => ''],
    'done'     => ['label' => 'Completed', 'sectionClass' => 'section-done',     'statusClass' => 'status-done',     'icon' => 'check-circle'],
];

// Count completed for collapse label
$completedCount = count($groups['done']);

// ── Action renderers ──
// Record is the primary 1-click action (used ~90% of the time).
// Adjust dosage / Edit schedule live behind a kebab (⋮) dropdown so
// the row stays compact and a future "View history" / "End schedule"
// action can land there without another redesign.
function renderOverflowMenu($entry) {
    global $GLOBALS;
    $nonce = htmlspecialchars($GLOBALS['NONCE'] ?? '', ENT_QUOTES, 'UTF-8');
    $items = '';
    if (!$entry['isCompleted']) {
        $items .= '<a class="dropdown-item" href="' . htmlspecialchars($entry['adjustUrl']) . '">Adjust dosage</a>';
    }
    $items .= '<a class="dropdown-item" href="' . htmlspecialchars($entry['editUrl']) . '">Edit schedule</a>';

    // HC-124: Pause / Skip today / Resume actions
    if (!$entry['isCompleted'] && empty($entry['isPrn'])) {
        if (!empty($entry['isPaused'])) {
            $items .= '<div class="dropdown-divider"></div>';
            $items .= '<form method="POST" action="resume_schedule_handler.php" class="d-inline">'
                     . '<input type="hidden" name="schedule_id" value="' . (int) $entry['scheduleId'] . '">'
                     . '<input type="hidden" name="patient_id" value="' . htmlspecialchars((string) $entry['patientId']) . '">'
                     . '<button type="submit" class="dropdown-item text-success">Resume schedule</button>'
                     . '</form>';
        } else {
            $items .= '<div class="dropdown-divider"></div>';
            $items .= '<form method="POST" action="skip_today_handler.php" class="d-inline">'
                     . '<input type="hidden" name="schedule_id" value="' . (int) $entry['scheduleId'] . '">'
                     . '<input type="hidden" name="patient_id" value="' . htmlspecialchars((string) $entry['patientId']) . '">'
                     . '<button type="submit" class="dropdown-item">Skip today</button>'
                     . '</form>';
            $items .= '<a class="dropdown-item" href="' . htmlspecialchars($entry['pauseUrl']) . '">Pause schedule&hellip;</a>';
        }
    }

    $html  = '<div class="dropdown d-inline-block">';
    $html .= '<button type="button" class="btn btn-sm btn-outline-secondary" '
           . 'data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" '
           . 'aria-label="More actions" title="More actions">';
    $html .= '<img src="images/bootstrap-icons/three-dots-vertical.svg" alt="">';
    $html .= '</button>';
    $html .= '<div class="dropdown-menu dropdown-menu-right">' . $items . '</div>';
    $html .= '</div>';
    return $html;
}

function renderActionGroup($entry) {
    $html = '<div class="action-btn-group">';
    if (!$entry['isCompleted']) {
        $html .= '<a href="' . htmlspecialchars($entry['recordUrl']) . '" class="btn btn-sm btn-success" title="Record Intake">';
        $html .= '<img src="images/bootstrap-icons/journal-medical.svg" alt="" class="button-icon-inverse"> Record</a>';
    }
    $html .= renderOverflowMenu($entry);
    $html .= '</div>';
    return $html;
}

// ── Helper to render a table row ──
function renderTableRow($entry, $statusClass) {
    $warningIcon = '';
    if ($entry['nextDueLabel'] === 'Overdue') {
        $warningIcon = '<img src="images/bootstrap-icons/exclamation-triangle-fill.svg" alt="Warning" class="mr-1"> ';
    }
    $dueAttr = $entry['secondsUntilDue'] !== null && !$entry['isCompleted']
        ? ' data-due-seconds="' . intval($entry['secondsUntilDue']) . '"'
        : '';

    $html  = '<tr class="' . $statusClass . '">';
    $html .= '<td>' . htmlspecialchars($entry['medName']) . '</td>';
    $html .= '<td>' . htmlspecialchars($entry['frequency']) . '</td>';
    $html .= '<td>' . htmlspecialchars($entry['lastTakenNicely']) . '</td>';
    $html .= '<td class="js-countdown"' . $dueAttr . '>' . $warningIcon . htmlspecialchars($entry['nextDueLabel']);
    if ($warningText) {
        $html .= '<br><div class="alert alert-warning mt-1 mb-0 p-1 small"><strong>Cadence Mismatch:</strong> ' . htmlspecialchars($warningText) . '</div>';
    }
    if ($entry['nextDueDetail']) {
        $html .= '<br><small class="text-muted">' . htmlspecialchars($entry['nextDueDetail']) . '</small>';
    }
    $html .= '</td>';
    $html .= '<td>' . htmlspecialchars($entry['remainShort']);
    if ($entry['remainDetail']) {
        $html .= '<br><small class="text-muted">' . htmlspecialchars($entry['remainDetail']) . '</small>';
    }
    $html .= '</td>';
    $html .= '<td class="actions-cell">' . renderActionGroup($entry) . '</td>';
    $html .= '</tr>';
    return $html;
}

// ── Helper to render a card (mobile) ──
function renderCard($entry, $statusClass) {
    $warningIcon = '';
    if ($entry['nextDueLabel'] === 'Overdue') {
        $warningIcon = '<img src="images/bootstrap-icons/exclamation-triangle-fill.svg" alt="Warning" class="mr-1"> ';
    }
    $dueAttr = $entry['secondsUntilDue'] !== null && !$entry['isCompleted']
        ? ' data-due-seconds="' . intval($entry['secondsUntilDue']) . '"'
        : '';

    $html  = '<div class="schedule-card ' . $statusClass . '">';
    $html .= '<div class="d-flex justify-content-between align-items-start">';
    $html .= '<span class="card-med-name">' . htmlspecialchars($entry['medName']) . '</span>';
    $html .= '<div class="action-btn-group">';
    if (!$entry['isCompleted']) {
        $html .= '<a href="' . htmlspecialchars($entry['recordUrl']) . '" class="btn btn-sm btn-success" title="Record Intake">';
        $html .= '<img src="images/bootstrap-icons/journal-medical.svg" alt="" class="button-icon-inverse"> Record</a>';
    }
    $html .= renderOverflowMenu($entry);
    $html .= '</div>';
    $html .= '</div>';
    $html .= '<div class="card-meta">Every ' . htmlspecialchars($entry['frequency']);
    if ($entry['lastTakenNicely']) {
        $html .= ' &middot; Last: ' . htmlspecialchars($entry['lastTakenNicely']);
    }
    $html .= '</div>';
    $html .= '<div class="card-due js-countdown"' . $dueAttr . '>' . $warningIcon;
    if ($entry['isCompleted']) {
        $html .= 'Completed';
    } elseif (!empty($entry['isPaused'])) {
        $html .= '<span class="text-muted">Paused</span>';
    } elseif (!empty($entry['isPrn'])) {
        $html .= htmlspecialchars($entry['nextDueLabel']);
    } else {
        $html .= htmlspecialchars($entry['nextDueLabel'] === 'Overdue' ? 'Overdue' : 'Due in ' . $entry['nextDueLabel']);
        if ($entry['nextDueDetail']) {
            $html .= ' <small class="text-muted">(' . htmlspecialchars($entry['nextDueDetail']) . ')</small>';
        }
    }
    $html .= '</div>';
    if ($entry['remainShort'] && $entry['remainShort'] !== translate('None')) {
        $html .= '<div class="card-remaining">' . htmlspecialchars($entry['remainShort']);
        if ($entry['remainDetail']) {
            $html .= ' &middot; ' . htmlspecialchars($entry['remainDetail']);
        }
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

// ══════════════════════════════════════════
// ── DESKTOP TABLE VIEW (hidden on mobile) ──
// ══════════════════════════════════════════
echo '<div class="d-none d-md-block">';

foreach (['overdue', 'due_soon', 'ok', 'paused', 'prn', 'done'] as $groupKey) {
    $entries = $groups[$groupKey];
    if (empty($entries)) {
        continue;
    }
    $cfg = $sectionConfig[$groupKey];

    // Completed section: collapsible
    if ($groupKey === 'done') {
        echo '<div class="schedule-section-header ' . $cfg['sectionClass'] . '">';
        echo '<a data-toggle="collapse" href="#completedTableSection" role="button" aria-expanded="false" class="text-decoration-none" style="color:inherit;">';
        if ($cfg['icon']) {
            echo '<img src="images/bootstrap-icons/' . $cfg['icon'] . '.svg" alt="" class="mr-1" style="width:16px;height:16px;"> ';
        }
        echo $cfg['label'] . ' (' . $completedCount . ')';
        echo ' <small>&#9660;</small></a></div>';
        echo '<div class="collapse" id="completedTableSection">';
    } else {
        echo '<div class="schedule-section-header ' . $cfg['sectionClass'] . '">';
        if ($cfg['icon']) {
            echo '<img src="images/bootstrap-icons/' . $cfg['icon'] . '.svg" alt="" class="mr-1" style="width:16px;height:16px;"> ';
        }
        echo $cfg['label'] . ' (' . count($entries) . ')</div>';
    }

    echo '<div class="table-responsive">';
    echo '<table class="table table-hover schedule-table mb-2">';
    echo '<thead class="thead-light"><tr>';
    echo '<th>Medication</th><th>Frequency</th><th>Last Taken</th><th>Next Due</th><th>Remaining</th><th class="actions-cell">Actions</th>';
    echo '</tr></thead><tbody>';

    foreach ($entries as $entry) {
        echo renderTableRow($entry, $cfg['statusClass']);
    }

    echo '</tbody></table></div>';

    if ($groupKey === 'done') {
        echo '</div>'; // close collapse
    }
}

// If all groups empty
$totalEntries = count($groups['overdue']) + count($groups['due_soon']) + count($groups['ok']) + count($groups['paused']) + count($groups['prn']) + count($groups['done']);
if ($totalEntries === 0) {
    echo '<div class="alert alert-info mt-3">No medications scheduled for this patient.</div>';
}

echo '</div>'; // close d-none d-md-block


// ══════════════════════════════════════════
// ── MOBILE CARD VIEW (hidden on desktop) ──
// ══════════════════════════════════════════
echo '<div class="d-md-none">';

foreach (['overdue', 'due_soon', 'ok', 'paused', 'prn', 'done'] as $groupKey) {
    $entries = $groups[$groupKey];
    if (empty($entries)) {
        continue;
    }
    $cfg = $sectionConfig[$groupKey];

    if ($groupKey === 'done') {
        echo '<div class="schedule-section-header ' . $cfg['sectionClass'] . '">';
        echo '<a data-toggle="collapse" href="#completedCardSection" role="button" aria-expanded="false" class="text-decoration-none" style="color:inherit;">';
        if ($cfg['icon']) {
            echo '<img src="images/bootstrap-icons/' . $cfg['icon'] . '.svg" alt="" class="mr-1" style="width:16px;height:16px;"> ';
        }
        echo $cfg['label'] . ' (' . $completedCount . ')';
        echo ' <small>&#9660;</small></a></div>';
        echo '<div class="collapse" id="completedCardSection">';
    } else {
        echo '<div class="schedule-section-header ' . $cfg['sectionClass'] . '">';
        if ($cfg['icon']) {
            echo '<img src="images/bootstrap-icons/' . $cfg['icon'] . '.svg" alt="" class="mr-1" style="width:16px;height:16px;"> ';
        }
        echo $cfg['label'] . ' (' . count($entries) . ')</div>';
    }

    foreach ($entries as $entry) {
        echo renderCard($entry, $cfg['statusClass']);
    }

    if ($groupKey === 'done') {
        echo '</div>';
    }
}

if ($totalEntries === 0) {
    echo '<div class="alert alert-info mt-3">No medications scheduled for this patient.</div>';
}

echo '</div>'; // close d-md-none

// ── Bottom actions ──
echo '<div class="mt-3 noprint">';
echo '<a href="add_to_schedule.php?patient_id=' . htmlspecialchars($patient_id) . '" class="btn btn-primary">+ Add Medication to Schedule</a>';
echo '</div>';

?>
<script nonce="<?= htmlspecialchars($GLOBALS['NONCE'] ?? '') ?>">
// Toggle URL parameter and reload (wired via data-toggle-param)
document.querySelectorAll('[data-toggle-param]').forEach(function(el) {
  el.addEventListener('change', function() { toggleParam(this, this.getAttribute('data-toggle-param')); });
});
function toggleParam(el, param) {
    var url = new URL(window.location.href);
    if (el.checked) {
        url.searchParams.set(param, '1');
    } else {
        url.searchParams.delete(param);
    }
    window.location.href = url.toString();
}

// Shrink-on-scroll for the sticky patient header.
// Collapses the header to .is-compact once the user has scrolled past the
// threshold; a 16px hysteresis band prevents flapping when the scroll
// position sits right on the boundary. rAF-throttled so we don't thrash
// on scroll events.
(function() {
    var header = document.getElementById('scheduleStickyHeader');
    if (!header) return;
    var COLLAPSE_AT = 80;
    var EXPAND_AT   = 64;
    var ticking = false;
    var compact = false;
    function update() {
        var y = window.scrollY || window.pageYOffset || 0;
        if (!compact && y > COLLAPSE_AT) {
            header.classList.add('is-compact');
            compact = true;
        } else if (compact && y < EXPAND_AT) {
            header.classList.remove('is-compact');
            compact = false;
        }
        ticking = false;
    }
    window.addEventListener('scroll', function() {
        if (!ticking) {
            window.requestAnimationFrame(update);
            ticking = true;
        }
    }, { passive: true });
    update();
})();

// Live countdown: update every 60 seconds
(function() {
    function updateCountdowns() {
        var els = document.querySelectorAll('.js-countdown[data-due-seconds]');
        els.forEach(function(el) {
            var secs = parseInt(el.getAttribute('data-due-seconds'), 10) - 60;
            el.setAttribute('data-due-seconds', secs);

            if (secs <= 0) {
                // Switch to overdue styling
                el.innerHTML = '<img src="images/bootstrap-icons/exclamation-triangle-fill.svg" alt="Warning" class="mr-1"> Overdue';
                var row = el.closest('tr');
                var card = el.closest('.schedule-card');
                if (row) {
                    row.className = row.className.replace(/status-\w+/g, '') + ' status-overdue';
                }
                if (card) {
                    card.className = card.className.replace(/status-\w+/g, '') + ' status-overdue';
                }
            } else {
                var h = Math.floor(secs / 3600);
                var m = Math.floor((secs % 3600) / 60);
                var label = h > 0 ? h + 'h ' + m + 'm' : m + 'm';
                // Preserve any existing <small> detail
                var small = el.querySelector('small');
                var detailHtml = small ? '<br>' + small.outerHTML : '';
                // Check if this is a card (uses "Due in" prefix)
                if (el.closest('.schedule-card')) {
                    el.innerHTML = 'Due in ' + label + (small ? ' <small class="text-muted">' + small.textContent + '</small>' : '');
                } else {
                    el.innerHTML = label + detailHtml;
                }
            }
        });
    }
    setInterval(updateCountdowns, 60000);
})();
</script>
<?php
echo print_trailer();
?>
