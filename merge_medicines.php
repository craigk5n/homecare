<?php
require_once 'includes/init.php';

print_header();

$merged = getGetValue('merged');

echo "<h2>Merge Medicines</h2>\n";
echo "<div class='container mt-3'>\n";

if (!empty($merged)) {
    echo "<div class='alert alert-success'>Successfully merged " . intval($merged) . " duplicate medicine(s).</div>\n";
}

echo "<p>Select a <strong>primary</strong> medicine (the one to keep) and check one or more <strong>duplicates</strong> to merge into it. ";
echo "All schedules, inventory, and intake records from duplicates will be reassigned to the primary.</p>\n";

// Fetch all medicines with counts
$sql = "SELECT m.id, m.name, m.dosage,
        (SELECT COUNT(*) FROM hc_medicine_schedules WHERE medicine_id = m.id) AS schedule_count,
        (SELECT COUNT(*) FROM hc_medicine_inventory WHERE medicine_id = m.id) AS inventory_count
        FROM hc_medicines m ORDER BY m.name ASC";
$rows = dbi_get_cached_rows($sql);

if (empty($rows)) {
    echo "<div class='alert alert-info'>No medicines found.</div>\n";
    echo "</div>\n";
    echo print_trailer();
    exit;
}

echo "<form id='mergeForm'>\n";
// CSRF token for the AJAX preview call. The confirm step below renders
// its own token via print_form_key() for the real submit.
print_form_key();
echo "<div class='table-responsive'>\n";
echo "<table class='table table-bordered table-hover'>\n";
echo "<thead class='thead-light'><tr>";
echo "<th style='width:60px'>Primary</th>";
echo "<th style='width:60px'>Merge</th>";
echo "<th>Name</th>";
echo "<th>Dosage</th>";
echo "<th>Schedules</th>";
echo "<th>Inventory</th>";
echo "</tr></thead>\n";
echo "<tbody>\n";

foreach ($rows as $row) {
    $id = intval($row[0]);
    $name = htmlspecialchars($row[1]);
    $dosage = htmlspecialchars($row[2]);
    $schedCount = intval($row[3]);
    $invCount = intval($row[4]);
    echo "<tr data-id='$id'>";
    echo "<td class='text-center'><input type='radio' name='primary_id' value='$id' class='merge-primary'></td>";
    echo "<td class='text-center'><input type='checkbox' name='duplicate_ids[]' value='$id' class='merge-duplicate'></td>";
    echo "<td>$name</td>";
    echo "<td>$dosage</td>";
    echo "<td>$schedCount</td>";
    echo "<td>$invCount</td>";
    echo "</tr>\n";
}

echo "</tbody></table>\n";
echo "</div>\n";

// Button starts enabled. Validation happens in the click handler so a
// missing jQuery or earlier-script error can't leave the button frozen
// in a disabled state -- you'll get a visible error instead of silence.
echo "<button type='button' id='previewBtn' class='btn btn-warning mr-2'>Preview Merge</button>\n";
echo "</form>\n";

// Preview area
echo "<div id='previewArea' class='mt-4' style='display:none'>\n";
echo "<div class='card border-warning'>\n";
echo "<div class='card-header bg-warning text-dark'><strong>Merge Preview</strong></div>\n";
echo "<div class='card-body' id='previewBody'></div>\n";
echo "<div class='card-footer'>\n";
echo "<form action='merge_medicines_handler.php' method='POST' id='confirmForm'>\n";
print_form_key();
echo "<input type='hidden' name='primary_id' id='confirmPrimary'>\n";
echo "<div id='confirmDuplicates'></div>\n";
echo "<button type='button' class='btn btn-secondary mr-2' data-hide='previewArea'>Cancel</button>\n";
echo "<button type='submit' class='btn btn-danger'>Confirm Merge</button>\n";
echo "</form>\n";
echo "</div>\n";
echo "</div>\n";
echo "</div>\n";

echo "</div>\n";
?>
<!-- Vanilla JS: no jQuery dependency, no event-listener ordering assumptions.
     Previous versions depended on a code.jquery.com CDN load + a delicate
     enable/disable chain on radio + checkbox change events, and a single
     script-loading hiccup left the Preview button permanently disabled. -->
<script nonce="<?= htmlspecialchars($GLOBALS['NONCE'] ?? '') ?>">
(function () {
    'use strict';

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str == null ? '' : String(str)));
        return div.innerHTML;
    }

    function selectedPrimaryId() {
        var el = document.querySelector('input[name="primary_id"]:checked');
        return el ? el.value : null;
    }

    function selectedDuplicateIds() {
        var nodes = document.querySelectorAll('input[name="duplicate_ids[]"]:checked');
        var ids = [];
        for (var i = 0; i < nodes.length; i++) ids.push(nodes[i].value);
        return ids;
    }

    function csrfKey() {
        var el = document.querySelector('#mergeForm input[name="csrf_form_key"]');
        return el ? el.value : '';
    }

    function renderPreview(primaryId, duplicateIds, data) {
        var html = '<p>Keeping: <strong>' + escHtml(data.primary.name)
            + '</strong> (' + escHtml(data.primary.dosage) + ')</p>'
            + '<p>The following will be merged into it:</p><ul>';
        var totals = { sched: 0, inv: 0, intake: 0 };
        for (var i = 0; i < data.duplicates.length; i++) {
            var d = data.duplicates[i];
            html += '<li><strong>' + escHtml(d.name) + '</strong> ('
                + escHtml(d.dosage) + '): '
                + d.schedule_count + ' schedule(s), '
                + d.inventory_count + ' inventory row(s), '
                + d.intake_count + ' intake(s)</li>';
            totals.sched  += Number(d.schedule_count)  || 0;
            totals.inv    += Number(d.inventory_count) || 0;
            totals.intake += Number(d.intake_count)    || 0;
        }
        html += '</ul><p><strong>Total:</strong> '
            + totals.sched + ' schedules, ' + totals.inv + ' inventory rows, '
            + 'and ' + totals.intake + ' intakes will be reassigned. '
            + data.duplicates.length + ' medicine record(s) will be deleted.</p>'
            + '<p class="text-danger mb-0"><strong>This action cannot be undone.</strong></p>';

        document.getElementById('previewBody').innerHTML = html;
        document.getElementById('confirmPrimary').value = primaryId;

        var dupsHtml = '';
        for (var j = 0; j < duplicateIds.length; j++) {
            dupsHtml += '<input type="hidden" name="duplicate_ids[]" value="'
                + escHtml(duplicateIds[j]) + '">';
        }
        document.getElementById('confirmDuplicates').innerHTML = dupsHtml;
        document.getElementById('previewArea').style.display = '';
    }

    function onPrimaryChange(ev) {
        // Uncheck the duplicate on the primary's own row and disable it so
        // a user can't pick the same row as both primary and duplicate.
        var all = document.querySelectorAll('input[name="duplicate_ids[]"]');
        for (var i = 0; i < all.length; i++) all[i].disabled = false;
        var row = ev.target.closest('tr');
        if (row) {
            var same = row.querySelector('input[name="duplicate_ids[]"]');
            if (same) { same.checked = false; same.disabled = true; }
        }
    }

    function onPreviewClick() {
        var primaryId = selectedPrimaryId();
        var duplicateIds = selectedDuplicateIds();

        if (!primaryId) { alert('Please pick a primary medicine (the one to keep).'); return; }
        if (duplicateIds.length === 0) { alert('Please check at least one duplicate to merge.'); return; }

        var body = new URLSearchParams();
        body.append('primary_id', primaryId);
        body.append('csrf_form_key', csrfKey());
        duplicateIds.forEach(function (id) { body.append('duplicate_ids[]', id); });

        fetch('merge_medicines_preview.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (resp) {
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            return resp.text().then(function (text) {
                try { return JSON.parse(text); }
                catch (e) { throw new Error('Non-JSON response: ' + text.substring(0, 200)); }
            });
        }).then(function (data) {
            if (data.error) { alert(data.error); return; }
            if (!data.primary) { alert('Preview returned no primary row.'); return; }
            renderPreview(primaryId, duplicateIds, data);
        }).catch(function (err) {
            alert('Preview failed: ' + err.message);
        });
    }

    function init() {
        var primaries = document.querySelectorAll('.merge-primary');
        for (var i = 0; i < primaries.length; i++) {
            primaries[i].addEventListener('change', onPrimaryChange);
        }
        var btn = document.getElementById('previewBtn');
        if (btn) btn.addEventListener('click', onPreviewClick);
        document.addEventListener('click', function(e) {
            var el = e.target.closest('[data-hide]');
            if (el) {
                var target = document.getElementById(el.getAttribute('data-hide'));
                if (target) target.style.display = 'none';
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
<?php
echo print_trailer();
?>
