<?php
require_once 'includes/init.php';

print_header();

// TODO: Move this to a shared file
$frequencies = [
    '1d' => '1d - once daily',
    '12h' => '12h - twice daily (every 12 hours)',
    '8h' => '8h - three times daily (every 8 hours)',
    '6h' => '6h - four times daily (every 6 hours)',
    '4h' => '4h - every 4 hours'
];


$patient_id = getIntValue('patient_id');
$medicine_id = getIntValue('medicine_id');
$schedule_id = getIntValue('schedule_id');

// Fetch patients
$patientsSql = "SELECT id, name FROM hc_patients WHERE is_active = TRUE ORDER BY name ASC";
$patientsResult = dbi_query($patientsSql);

// Fetch medications
$medicationsSql = "SELECT id, name FROM hc_medicines ORDER BY name ASC";
$medicationsResult = dbi_query($medicationsSql);

// If medicine id specified, load that
if (!empty($medicine_id) && !empty($schedule_id)) {
  echo "<h2>Edit Medication to Patient Schedule</h2>\n";
  $sql = 'SELECT id, start_date, end_date, frequency, unit_per_dose, is_prn, dose_basis, cycle_on_days, cycle_off_days, wall_clock_times FROM hc_medicine_schedules ' .
    'WHERE patient_id = ? and medicine_id = ? AND id = ?';
  $rows = dbi_get_cached_rows($sql, [$patient_id, $medicine_id, $schedule_id]);
  $start_date = $rows[0][1];
  $end_date = $rows[0][2];
  $frequency = $rows[0][3];
  $unit_per_dose = $rows[0][4];
  $is_prn = ($rows[0][5] ?? 'N') === 'Y';
  $dose_basis = $rows[0][6] ?? 'fixed';
  $cycle_on_days = $rows[0][7] ?? '';
  $cycle_off_days = $rows[0][8] ?? '';
  $wall_clock_times = $rows[0][9] ?? '';
} else {
  echo "<h2>Add Medication to Patient Schedule</h2>\n";
  $start_date = $end_date = '';
  $frequency = '1d'; // default
  $unit_per_dose = '1.00';
  $is_prn = false;
  $dose_basis = 'fixed';
  $cycle_on_days = '';
  $cycle_off_days = '';
  $wall_clock_times = '';
}

echo "<div class='container mt-3'>\n";
echo "<form action='add_to_schedule_handler.php' method='POST'>\n";
print_form_key();

// Patient selection
echo "<div class='form-group'>\n";
echo "<label for='patient_id'>Select Patient:</label>\n";
echo "<select name='patient_id' id='patient_id' class='form-control'>\n";
while ($patient = dbi_fetch_row($patientsResult)) {
    echo "<option " . ($patient[0] == $patient_id ? " selected ": "") .
      " value='" . htmlspecialchars($patient[0]) . "'>" . htmlspecialchars($patient[1]) . "</option>\n";
}
echo "</select>\n";
echo "</div>\n";

if (!empty($medicine_id) && !empty($schedule_id)) {
  echo "<input type=\"hidden\" name=\"schedule_id\" value=\"$schedule_id\">\n";
}

// Medication selection — autocomplete from drug catalog + existing medicines
$allMeds = [];
while ($medicine = dbi_fetch_row($medicationsResult)) {
    $allMeds[] = ['id' => $medicine[0], 'name' => $medicine[1]];
}
$selectedMedName = '';
foreach ($allMeds as $m) {
    if ($m['id'] == $medicine_id) {
        $selectedMedName = $m['name'];
    }
}
echo "<div class='form-group'>\n";
echo "<label for='medicine_search'>Medication:</label>\n";
echo "<input type='text' id='medicine_search' class='form-control' autocomplete='off' placeholder='Type to search...'";
if ($selectedMedName !== '') {
    echo " value='" . htmlspecialchars($selectedMedName, ENT_QUOTES, 'UTF-8') . "'";
}
echo " required>\n";
echo "<input type='hidden' name='medicine_id' id='medicine_id' value='" . htmlspecialchars((string) $medicine_id) . "'>\n";
echo "<div id='med-suggestions' class='list-group' style='position:absolute;z-index:1000;max-height:300px;overflow-y:auto;display:none'></div>\n";
echo "<small class='form-text text-muted'><a href='#' id='cant-find-med'>I don't see my medication</a> &mdash; <a href='edit_medication.php'>add a new one</a></small>\n";
echo "</div>\n";
// Pass existing medications to JS for local filtering
echo "<script>var HC_MEDICINES = " . json_encode($allMeds, JSON_HEX_TAG | JSON_HEX_AMP) . ";</script>\n";

// HC-112: Interaction warning area
echo "<div id='interaction-warnings' style='display:none' class='mb-3'></div>\n";
echo "<input type='hidden' name='interaction_acknowledged' id='interaction_acknowledged' value='0'>\n";

// Schedule dates and frequency
echo "<div class='form-group'>\n";
echo "<label for='start_date'>Start Date:</label>\n";
echo "Start date: $start_date <br>";
echo "<input type='date' name='start_date' id='start_date' class='form-control' required" .
  (!empty($start_date) ? " value=\"$start_date\" " : '') .
  ">\n";
echo "</div>\n";

echo "<div class='form-group'>\n";
echo "<label for='end_date'>End Date:</label>\n";
echo "<input type='date' name='end_date' id='end_date' class='form-control'" .
  (!empty($end_date) ? " value=\"$end_date\" " : '') .
  ">\n";
echo "</div>\n";

// HC-120: PRN (as-needed) schedules have no fixed cadence. When the
// checkbox is on, the frequency dropdown is hidden (the browser won't
// submit its value, so the handler sees it as empty and stores NULL).
echo "<div class='form-group form-check'>\n";
echo "<input type='checkbox' class='form-check-input' name='is_prn' id='is_prn' value='1'"
  . ($is_prn ? ' checked' : '') . ">\n";
echo "<label class='form-check-label' for='is_prn'>Take as needed (PRN) "
  . "&mdash; no schedule, no reminders</label>\n";
echo "</div>\n";

echo "<div class='form-group' id='frequency-group'" . ($is_prn ? " style='display:none'" : '') . ">\n";
echo "<label for='frequency'>Frequency:</label>\n";
echo "<select name='frequency' id='frequency' class='form-control'>\n";
foreach ($frequencies as $value => $description) {
  $selected = ($value == $frequency) ? ' selected' : '';
  echo "<option value='$value'$selected>$description</option>\n";
}
echo "</select>\n";
echo "</div>\n";

// Show/hide the frequency row in response to the PRN checkbox. No
// network call; pure DOM toggle. When PRN is on, we also clear the
// `name` attribute on the <select> so the browser omits it from the
// submitted form and the handler treats frequency as absent.
// HC-123: wall-clock fixed times ("8am + 2pm + 8pm"). When filled,
// these override the frequency dropdown for next-due calculations.
$wcTimes = !empty($wall_clock_times) ? explode(',', $wall_clock_times) : [];
echo "<div class='form-group' id='wall-clock-group'" . ($is_prn ? " style='display:none'" : '') . ">\n";
echo "<label>Fixed times per day (optional):</label>\n";
echo "<div id='wall-clock-inputs'>\n";
foreach ($wcTimes as $i => $t) {
    echo "<input type='time' name='wall_clock_times[]' class='form-control d-inline-block mb-1' style='width:auto' value='" . htmlspecialchars(trim($t), ENT_QUOTES, 'UTF-8') . "'>\n";
}
echo "</div>\n";
echo "<button type='button' class='btn btn-sm btn-outline-secondary mt-1' id='add-time-btn'>+ Add time</button>\n";
echo "<small class='form-text text-muted'>When set, doses are expected at these exact times instead of every N hours. Leave blank for interval-based scheduling.</small>\n";
echo "</div>\n";

echo <<<HTML
<script>
(function () {
  var box = document.getElementById('is_prn');
  var freqWrap = document.getElementById('frequency-group');
  var wcWrap = document.getElementById('wall-clock-group');
  var sel = document.getElementById('frequency');
  if (!box || !freqWrap || !sel) return;
  function sync() {
    if (box.checked) {
      freqWrap.style.display = 'none';
      if (wcWrap) wcWrap.style.display = 'none';
      sel.removeAttribute('name');
    } else {
      freqWrap.style.display = '';
      if (wcWrap) wcWrap.style.display = '';
      sel.setAttribute('name', 'frequency');
    }
  }
  box.addEventListener('change', sync);
  sync();

  // Add-time button
  var addBtn = document.getElementById('add-time-btn');
  var container = document.getElementById('wall-clock-inputs');
  if (addBtn && container) {
    addBtn.addEventListener('click', function() {
      var inp = document.createElement('input');
      inp.type = 'time';
      inp.name = 'wall_clock_times[]';
      inp.className = 'form-control d-inline-block mb-1';
      inp.style.width = 'auto';
      container.appendChild(inp);
    });
  }
})();
</script>
HTML;


echo "<div class='form-group'>\n";
echo "<label for='unit_per_dose'>Unit Per Dose:</label>\n";
echo "<input type='number' step='0.01' min='0.01' name='unit_per_dose' id='unit_per_dose' class='form-control' required";
echo " value='" . htmlspecialchars($unit_per_dose) . "'>\n";
echo "<small class='form-text text-muted' id='unit_per_dose_help'>Number of tablets/units consumed per dose</small>\n";
echo "</div>\n";

// HC-113: dose basis (fixed amount vs weight-based)
echo "<div class='form-group'>\n";
echo "<label for='dose_basis'>Dose Basis:</label>\n";
echo "<select name='dose_basis' id='dose_basis' class='form-control'>\n";
$fixedSelected = $dose_basis === 'fixed' ? ' selected' : '';
$perKgSelected = $dose_basis === 'per_kg' ? ' selected' : '';
echo "<option value='fixed'{$fixedSelected}>Fixed amount</option>\n";
echo "<option value='per_kg'{$perKgSelected}>Per kg body weight (mg/kg)</option>\n";
echo "</select>\n";
echo "<small class='form-text text-muted'>Per-kg multiplies unit_per_dose by the patient's weight.</small>\n";
echo "</div>\n";

// HC-121: optional cycle dosing ("3 weeks on, 1 week off")
echo "<fieldset class='border p-2 mb-3'>\n";
echo "<legend class='w-auto px-2' style='font-size:1rem'>Cycle (optional)</legend>\n";
echo "<div class='form-row'>\n";
echo "<div class='form-group col-md-6'>\n";
echo "<label for='cycle_on_days'>Days on:</label>\n";
echo "<input type='number' min='1' name='cycle_on_days' id='cycle_on_days' class='form-control' placeholder='e.g. 21'"
    . (!empty($cycle_on_days) ? " value='" . htmlspecialchars((string) $cycle_on_days, ENT_QUOTES, 'UTF-8') . "'" : '')
    . ">\n";
echo "</div>\n";
echo "<div class='form-group col-md-6'>\n";
echo "<label for='cycle_off_days'>Days off:</label>\n";
echo "<input type='number' min='1' name='cycle_off_days' id='cycle_off_days' class='form-control' placeholder='e.g. 7'"
    . (!empty($cycle_off_days) ? " value='" . htmlspecialchars((string) $cycle_off_days, ENT_QUOTES, 'UTF-8') . "'" : '')
    . ">\n";
echo "</div>\n";
echo "</div>\n";
echo "<small class='form-text text-muted'>Leave blank for continuous dosing. When set, the schedule alternates between on-days (doses expected) and off-days (no doses).</small>\n";
echo "</fieldset>\n";

echo <<<'HTML'
<script>
(function () {
  var sel = document.getElementById('dose_basis');
  var help = document.getElementById('unit_per_dose_help');
  if (!sel || !help) return;
  function sync() {
    help.textContent = sel.value === 'per_kg'
      ? 'Dose in mg/kg — will be multiplied by patient weight'
      : 'Number of tablets/units consumed per dose';
  }
  sel.addEventListener('change', sync);
  sync();
})();
</script>
HTML;

// Submit button
if (!empty($medicine_id) && !empty($schedule_id)) {
  echo "<button type='submit' class='btn btn-primary'>Update Schedule</button>\n";
} else {
  echo "<button type='submit' class='btn btn-primary'>Add Schedule</button>\n";
}
echo "</form>\n";
echo "</div>\n";

// Medication autocomplete: searches drug catalog via API and falls back
// to filtering the local HC_MEDICINES array for existing medicines.
echo <<<'HTML'
<script>
(function() {
    var search = document.getElementById('medicine_search');
    var hiddenId = document.getElementById('medicine_id');
    var suggestions = document.getElementById('med-suggestions');
    var cantFind = document.getElementById('cant-find-med');
    if (!search || !suggestions || !hiddenId) return;

    var debounce = null;
    var localMeds = window.HC_MEDICINES || [];

    function filterLocal(q) {
        q = q.toLowerCase();
        return localMeds.filter(function(m) {
            return m.name.toLowerCase().indexOf(q) !== -1;
        }).slice(0, 10);
    }

    function render(items, type) {
        suggestions.innerHTML = '';
        items.forEach(function(item) {
            var a = document.createElement('a');
            a.href = '#';
            a.className = 'list-group-item list-group-item-action';
            if (type === 'catalog') {
                var label = item.name;
                if (item.strength) label += ' [' + item.strength + ']';
                if (item.dosage_form) label += ' — ' + item.dosage_form;
                a.textContent = label;
                a.dataset.catalogId = item.id;
            } else {
                a.textContent = item.name;
                a.dataset.medicineId = item.id;
            }
            a.addEventListener('click', function(e) {
                e.preventDefault();
                if (type === 'local') {
                    search.value = item.name;
                    hiddenId.value = item.id;
                } else {
                    search.value = item.name;
                    // For catalog items, check if we already have this medicine locally
                    var match = localMeds.find(function(m) {
                        return m.name.toLowerCase() === item.name.toLowerCase();
                    });
                    if (match) {
                        hiddenId.value = match.id;
                    } else {
                        hiddenId.value = '';
                        search.value = item.name;
                    }
                }
                suggestions.style.display = 'none';
            });
            suggestions.appendChild(a);
        });
        if (items.length > 0) suggestions.style.display = 'block';
    }

    search.addEventListener('input', function() {
        hiddenId.value = '';
        clearTimeout(debounce);
        var q = search.value.trim();
        if (q.length < 2) { suggestions.style.display = 'none'; return; }

        // Show local matches immediately
        var local = filterLocal(q);
        if (local.length > 0) render(local, 'local');

        // Also search the drug catalog (debounced)
        debounce = setTimeout(function() {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'api/v1/drugs.php?q=' + encodeURIComponent(q));
            xhr.setRequestHeader('Authorization', 'Bearer ' + (window.HC_API_KEY || ''));
            xhr.onload = function() {
                if (xhr.status !== 200) return;
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.status === 'ok' && resp.data && resp.data.length > 0) {
                        // Merge: local matches first, then catalog
                        var seen = {};
                        var merged = [];
                        local.forEach(function(m) { seen[m.name.toLowerCase()] = true; merged.push({item:m, type:'local'}); });
                        resp.data.forEach(function(c) {
                            if (!seen[c.name.toLowerCase()]) {
                                merged.push({item:c, type:'catalog'});
                            }
                        });
                        suggestions.innerHTML = '';
                        merged.slice(0, 15).forEach(function(entry) {
                            var a = document.createElement('a');
                            a.href = '#';
                            a.className = 'list-group-item list-group-item-action';
                            if (entry.type === 'catalog') {
                                var label = entry.item.name;
                                if (entry.item.strength) label += ' [' + entry.item.strength + ']';
                                if (entry.item.dosage_form) label += ' — ' + entry.item.dosage_form;
                                a.textContent = label;
                                a.innerHTML += ' <span class="badge badge-secondary">catalog</span>';
                            } else {
                                a.textContent = entry.item.name;
                            }
                            a.addEventListener('click', function(e) {
                                e.preventDefault();
                                if (entry.type === 'local') {
                                    search.value = entry.item.name;
                                    hiddenId.value = entry.item.id;
                                } else {
                                    search.value = entry.item.name;
                                    var match = localMeds.find(function(m) {
                                        return m.name.toLowerCase() === entry.item.name.toLowerCase();
                                    });
                                    hiddenId.value = match ? match.id : '';
                                }
                                suggestions.style.display = 'none';
                            });
                            suggestions.appendChild(a);
                        });
                        if (merged.length > 0) suggestions.style.display = 'block';
                    }
                } catch(e) {}
            };
            xhr.send();
        }, 250);
    });

    // Form validation: ensure a medicine is selected
    search.closest('form').addEventListener('submit', function(e) {
        if (!hiddenId.value) {
            // Try exact match against local medicines
            var val = search.value.trim().toLowerCase();
            var match = localMeds.find(function(m) { return m.name.toLowerCase() === val; });
            if (match) {
                hiddenId.value = match.id;
            } else {
                e.preventDefault();
                alert('Please select a medication from the list, or add a new one first.');
                search.focus();
            }
        }
    });

    document.addEventListener('click', function(e) {
        if (!suggestions.contains(e.target) && e.target !== search) {
            suggestions.style.display = 'none';
        }
    });

    if (cantFind) {
        cantFind.addEventListener('click', function(e) {
            e.preventDefault();
            window.location.href = 'edit_medication.php';
        });
    }
})();

// HC-112: Drug interaction check when medicine is selected.
(function() {
    var hiddenId = document.getElementById('medicine_id');
    var patientSel = document.getElementById('patient_id');
    var warnArea = document.getElementById('interaction-warnings');
    var ackInput = document.getElementById('interaction_acknowledged');
    if (!hiddenId || !patientSel || !warnArea) return;

    var lastChecked = '';

    function checkInteractions() {
        var medId = hiddenId.value;
        var patId = patientSel.value;
        var key = patId + ':' + medId;
        if (!medId || !patId || key === lastChecked) return;
        lastChecked = key;

        warnArea.style.display = 'none';
        warnArea.innerHTML = '';
        if (ackInput) ackInput.value = '0';

        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'api/v1/interactions.php?patient_id=' + encodeURIComponent(patId)
            + '&medicine_id=' + encodeURIComponent(medId));
        xhr.setRequestHeader('Authorization', 'Bearer ' + (window.HC_API_KEY || ''));
        xhr.onload = function() {
            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp.status !== 'ok' || !resp.data || resp.data.length === 0) return;
                renderWarnings(resp.data);
            } catch(e) {}
        };
        xhr.send();
    }

    function renderWarnings(items) {
        warnArea.innerHTML = '';
        var hasMajor = false;
        var hasModerate = false;

        items.forEach(function(item) {
            var cls = 'alert-info';
            if (item.severity === 'major') { cls = 'alert-danger'; hasMajor = true; }
            else if (item.severity === 'moderate') { cls = 'alert-warning'; hasModerate = true; }

            var div = document.createElement('div');
            div.className = 'alert ' + cls + ' py-2 mb-2';
            div.innerHTML = '<strong>' + escHtml(item.severity.toUpperCase()) + ' interaction:</strong> '
                + escHtml(item.ingredient_a) + ' + ' + escHtml(item.ingredient_b)
                + ' (with ' + escHtml(item.existing_medicine) + ')'
                + (item.description ? '<br><small>' + escHtml(item.description) + '</small>' : '');
            warnArea.appendChild(div);
        });

        if (hasMajor || hasModerate) {
            var gate = document.createElement('div');
            gate.className = 'form-check mb-2';
            gate.innerHTML = '<input type="checkbox" class="form-check-input" id="ack-interactions">'
                + '<label class="form-check-label" for="ack-interactions">'
                + "Doctor has OK'd this combination</label>";
            warnArea.appendChild(gate);
            var cb = gate.querySelector('#ack-interactions');
            cb.addEventListener('change', function() {
                if (ackInput) ackInput.value = cb.checked ? '1' : '0';
            });
        }

        warnArea.style.display = 'block';
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    // Observe changes to the hidden medicine_id
    var observer = new MutationObserver(function() { checkInteractions(); });
    observer.observe(hiddenId, { attributes: true, attributeFilter: ['value'] });

    // Also poll on input events since MutationObserver may miss programmatic .value changes
    hiddenId.addEventListener('change', checkInteractions);
    patientSel.addEventListener('change', function() { lastChecked = ''; checkInteractions(); });

    // Check periodically in case value was set programmatically
    setInterval(function() {
        var key = patientSel.value + ':' + hiddenId.value;
        if (key !== lastChecked && hiddenId.value) checkInteractions();
    }, 500);

    // Block form submit if moderate/major interaction not acknowledged
    var form = hiddenId.closest('form');
    if (form) {
        form.addEventListener('submit', function(e) {
            var ackCb = document.getElementById('ack-interactions');
            if (ackCb && !ackCb.checked) {
                e.preventDefault();
                alert('Please acknowledge the drug interaction warning before submitting.');
                ackCb.focus();
            }
        });
    }
})();
</script>
HTML;

echo print_trailer();
?>

