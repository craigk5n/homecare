<?php
require_once 'includes/init.php';

print_header();

$patient_id = getGetValue('patient_id');
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
$sql = "SELECT ms.id, m.name, ms.frequency, ms.start_date, ms.end_date, ms.medicine_id,
        (SELECT MAX(mi.taken_time) FROM hc_medicine_intake mi WHERE mi.schedule_id = ms.id) AS last_taken
        FROM hc_medicine_schedules ms
        JOIN hc_medicines m ON ms.medicine_id = m.id
        WHERE ms.patient_id = ? $includeCompletedSql
        ORDER BY last_taken ASC";

$rows = dbi_get_cached_rows($sql, [$patient_id]);

// ── Build medication data and group by urgency ──
$groups = [
    'overdue'  => [],
    'due_soon' => [],
    'ok'       => [],
    'done'     => [],
];

foreach ($rows as $row) {
    $schedule_id  = $row[0];
    $medName      = $row[1];
    $frequency    = $row[2];
    $start_date   = $row[3];
    $end_date     = $row[4];
    $medicine_id  = $row[5];
    $lastTaken    = $row[6] ? $row[6] : null;
    $lastTakenNicely = formatDateNicely($lastTaken);

    $secondsUntilDue = null;
    $nextDueLabel    = 'Not Yet Taken';
    $nextDueDetail   = '';
    $sortKey         = '0000-' . $medName;
    $statusGroup     = 'ok';
    $isCompleted     = false;

    $remainingDoses = dosesRemaining($medicine_id, $schedule_id, $assumePastIntake, $start_date, $frequency);

    if ($lastTaken) {
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

    // Build remaining display
    $days = $remainingDoses['remainingDays'];
    $futureDate = date('M j, Y', strtotime("+$days days"));
    if (empty($remainingDoses['remainingDoses'])) {
        $remainShort  = translate('None');
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

    $entry = [
        'sortKey'         => $sortKey,
        'medName'         => $medName,
        'frequency'       => $frequency,
        'lastTakenNicely' => $lastTakenNicely,
        'nextDueLabel'    => $nextDueLabel,
        'nextDueDetail'   => $nextDueDetail,
        'secondsUntilDue' => $secondsUntilDue,
        'remainShort'     => $remainShort,
        'remainDetail'    => $remainDetail,
        'recordUrl'       => $recordUrl,
        'editUrl'         => $editUrl,
        'adjustUrl'       => $adjustUrl,
        'statusGroup'     => $statusGroup,
        'isCompleted'     => $isCompleted,
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

echo '<div class="schedule-sticky-header noprint" role="region" aria-label="Patient schedule header" id="scheduleStickyHeader">';
echo '<div class="d-flex justify-content-between align-items-center flex-wrap" style="gap:.5rem;">';

// Left: patient name + status badges
echo '<div class="d-flex align-items-center flex-wrap" style="gap:.6rem;">';
echo '<h1 class="hc-patient-name">' . $patientNameEsc . '</h1>';
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
echo ' onchange="toggleParam(this, \'assume_past_intake\')">';
echo '<label class="custom-control-label" for="assumePastIntake">Assume past doses taken</label>';
echo '</div>';
$completedChecked = $showCompleted ? ' checked' : '';
echo '<div class="custom-control custom-switch">';
echo '<input type="checkbox" class="custom-control-input" id="showCompleted"' . $completedChecked;
echo ' onchange="toggleParam(this, \'show_completed\')">';
echo '<label class="custom-control-label" for="showCompleted">Show completed</label>';
echo '</div>';
echo '<button class="btn btn-outline-secondary btn-sm ml-auto" onclick="window.print()">Print</button>';
echo '</div>';

// ── Section config ──
$sectionConfig = [
    'overdue'  => ['label' => 'Overdue',   'sectionClass' => 'section-overdue',  'statusClass' => 'status-overdue',  'icon' => 'exclamation-triangle-fill'],
    'due_soon' => ['label' => 'Due Soon',  'sectionClass' => 'section-due-soon', 'statusClass' => 'status-due-soon', 'icon' => 'clock'],
    'ok'       => ['label' => 'Upcoming',  'sectionClass' => 'section-ok',       'statusClass' => 'status-ok',       'icon' => ''],
    'done'     => ['label' => 'Completed', 'sectionClass' => 'section-done',     'statusClass' => 'status-done',     'icon' => 'check-circle'],
];

// Count completed for collapse label
$completedCount = count($groups['done']);

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
    if ($entry['nextDueDetail']) {
        $html .= '<br><small class="text-muted">' . htmlspecialchars($entry['nextDueDetail']) . '</small>';
    }
    $html .= '</td>';
    $html .= '<td>' . htmlspecialchars($entry['remainShort']);
    if ($entry['remainDetail']) {
        $html .= '<br><small class="text-muted">' . htmlspecialchars($entry['remainDetail']) . '</small>';
    }
    $html .= '</td>';
    $html .= '<td class="actions-cell">';
    if (!$entry['isCompleted']) {
        $html .= '<a href="' . htmlspecialchars($entry['recordUrl']) . '" class="btn btn-sm btn-success mr-1" title="Record Intake">';
        $html .= '<img src="images/bootstrap-icons/journal-medical.svg" alt="" class="button-icon-inverse"> Record</a>';
        $html .= '<a href="' . htmlspecialchars($entry['adjustUrl']) . '" class="btn btn-sm btn-outline-warning mr-1" title="Adjust Dosage">Adjust</a>';
    }
    $html .= '<a href="' . htmlspecialchars($entry['editUrl']) . '" class="btn btn-sm btn-outline-secondary" title="Edit">';
    $html .= '<img src="images/bootstrap-icons/pencil.svg" alt="" class="button-icon-inverse"> Edit</a>';
    $html .= '</td>';
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
    if (!$entry['isCompleted']) {
        $html .= '<a href="' . htmlspecialchars($entry['recordUrl']) . '" class="btn btn-sm btn-success" title="Record Intake">';
        $html .= '<img src="images/bootstrap-icons/journal-medical.svg" alt="" class="button-icon-inverse"> Record</a>';
    }
    $html .= '</div>';
    $html .= '<div class="card-meta">Every ' . htmlspecialchars($entry['frequency']);
    if ($entry['lastTakenNicely']) {
        $html .= ' &middot; Last: ' . htmlspecialchars($entry['lastTakenNicely']);
    }
    $html .= '</div>';
    $html .= '<div class="card-due js-countdown"' . $dueAttr . '>' . $warningIcon;
    if ($entry['isCompleted']) {
        $html .= 'Completed';
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
    $html .= '<div class="card-actions">';
    if (!$entry['isCompleted']) {
        $html .= '<a href="' . htmlspecialchars($entry['adjustUrl']) . '" class="btn btn-sm btn-outline-warning mr-1" title="Adjust Dosage">Adjust</a>';
    }
    $html .= '<a href="' . htmlspecialchars($entry['editUrl']) . '" class="btn btn-sm btn-outline-secondary" title="Edit">';
    $html .= '<img src="images/bootstrap-icons/pencil.svg" alt="" class="button-icon-inverse"> Edit</a>';
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

// ══════════════════════════════════════════
// ── DESKTOP TABLE VIEW (hidden on mobile) ──
// ══════════════════════════════════════════
echo '<div class="d-none d-md-block">';

foreach (['overdue', 'due_soon', 'ok', 'done'] as $groupKey) {
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
$totalEntries = count($groups['overdue']) + count($groups['due_soon']) + count($groups['ok']) + count($groups['done']);
if ($totalEntries === 0) {
    echo '<div class="alert alert-info mt-3">No medications scheduled for this patient.</div>';
}

echo '</div>'; // close d-none d-md-block


// ══════════════════════════════════════════
// ── MOBILE CARD VIEW (hidden on desktop) ──
// ══════════════════════════════════════════
echo '<div class="d-md-none">';

foreach (['overdue', 'due_soon', 'ok', 'done'] as $groupKey) {
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
<script>
// Toggle URL parameter and reload
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
