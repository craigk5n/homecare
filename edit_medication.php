<?php
require_once 'includes/init.php';

// Initialize variables
$medicationId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$medication = ['name' => '', 'dosage' => '', 'drug_catalog_id' => null];

if ($medicationId > 0) {
    // Fetch medication details for editing
    $sql = "SELECT id, name, dosage, drug_catalog_id FROM hc_medicines WHERE id = ?";
    $medication = dbi_fetch_row(dbi_query($sql, [$medicationId]));
}

print_header();

$actionUrl = $medicationId > 0 ? "update_medication_handler.php" : "add_medication_handler.php";
?>
<h2><?= $medicationId > 0 ? 'Edit Medication' : 'Add Medication' ?></h2>
<div class='container mt-3'>
<form action='<?= htmlspecialchars($actionUrl) ?>' method='POST'>
<?php print_form_key(); ?>
    <input type='hidden' name='id' value='<?= $medicationId ?>'>
    <input type='hidden' name='drug_catalog_id' id='drug_catalog_id' value='<?= htmlspecialchars((string) ($medication['drug_catalog_id'] ?? '')) ?>'>
    <div class='form-group'>
        <label for='name'>Medication Name:</label>
        <input type='text' name='name' id='name' class='form-control' required value='<?= htmlspecialchars($medication['name']) ?>' autocomplete='off'>
        <div id='drug-suggestions' class='list-group' style='position:absolute;z-index:1000;max-height:300px;overflow-y:auto;display:none'></div>
        <small class='form-text text-muted' id='catalog-link-info'><?php
            if (!empty($medication['drug_catalog_id'])) {
                echo 'Linked to drug catalog entry #' . htmlspecialchars((string) $medication['drug_catalog_id']);
            }
        ?></small>
    </div>
    <div class='form-group'>
        <label for='dosage'>Dosage:</label>
        <input type='text' name='dosage' id='dosage' class='form-control' required value='<?= htmlspecialchars($medication['dosage']) ?>'>
    </div>
    <p class='text-muted small'><a href='#' id='free-text-toggle'>I don't see my medication &mdash; enter manually</a></p>
    <button type='submit' class='btn btn-primary'><?= $medicationId > 0 ? 'Update' : 'Add' ?> Medication</button>
</form>
</div>
<script>
(function() {
    var nameInput = document.getElementById('name');
    var dosageInput = document.getElementById('dosage');
    var catalogIdInput = document.getElementById('drug_catalog_id');
    var suggestions = document.getElementById('drug-suggestions');
    var catalogInfo = document.getElementById('catalog-link-info');
    var freeTextLink = document.getElementById('free-text-toggle');
    var debounceTimer = null;

    if (!nameInput || !suggestions) return;

    function fetchDrugs(query) {
        if (query.length < 2) {
            suggestions.style.display = 'none';
            return;
        }
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'api/v1/drugs.php?q=' + encodeURIComponent(query));
        xhr.setRequestHeader('Authorization', 'Bearer ' + (window.HC_API_KEY || ''));
        xhr.onload = function() {
            if (xhr.status !== 200) { suggestions.style.display = 'none'; return; }
            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp.status !== 'ok' || !resp.data || resp.data.length === 0) {
                    suggestions.style.display = 'none';
                    return;
                }
                renderSuggestions(resp.data);
            } catch(e) { suggestions.style.display = 'none'; }
        };
        xhr.onerror = function() { suggestions.style.display = 'none'; };
        xhr.send();
    }

    function renderSuggestions(items) {
        suggestions.innerHTML = '';
        items.forEach(function(item) {
            var a = document.createElement('a');
            a.href = '#';
            a.className = 'list-group-item list-group-item-action';
            var label = item.name;
            if (item.strength) label += ' [' + item.strength + ']';
            if (item.dosage_form) label += ' - ' + item.dosage_form;
            if (item.generic) label += ' (generic)';
            a.textContent = label;
            a.addEventListener('click', function(e) {
                e.preventDefault();
                selectDrug(item);
            });
            suggestions.appendChild(a);
        });
        suggestions.style.display = 'block';
    }

    function selectDrug(item) {
        nameInput.value = item.name;
        var dosage = '';
        if (item.strength) dosage = item.strength;
        if (item.dosage_form) dosage += (dosage ? ' ' : '') + item.dosage_form;
        if (dosage) dosageInput.value = dosage;
        catalogIdInput.value = item.id;
        suggestions.style.display = 'none';
        catalogInfo.textContent = 'Linked to drug catalog entry #' + item.id;
    }

    nameInput.addEventListener('input', function() {
        catalogIdInput.value = '';
        catalogInfo.textContent = '';
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function() {
            fetchDrugs(nameInput.value.trim());
        }, 250);
    });

    document.addEventListener('click', function(e) {
        if (!suggestions.contains(e.target) && e.target !== nameInput) {
            suggestions.style.display = 'none';
        }
    });

    if (freeTextLink) {
        freeTextLink.addEventListener('click', function(e) {
            e.preventDefault();
            catalogIdInput.value = '';
            catalogInfo.textContent = 'Manual entry — not linked to drug catalog';
            suggestions.style.display = 'none';
            nameInput.focus();
        });
    }
})();
</script>
<?php
echo print_trailer();
?>
