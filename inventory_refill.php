<?php
require_once 'includes/init.php';

print_header();

$medicine_id = getIntValue('medicine_id');
$scan_mode = !empty(getGetValue('scan'));

if (empty($medicine_id) && !$scan_mode) {
    die_miserable_death('Missing medicine_id');
}

$medicineName = '';
$dosage = '';
$currentStock = 0;
$lastRecorded = null;

if (!empty($medicine_id)) {
    // Get medicine name
    $sql = "SELECT name, dosage FROM hc_medicines WHERE id = ?";
    $rows = dbi_get_cached_rows($sql, [$medicine_id]);
    if (empty($rows)) {
        die_miserable_death('Medicine not found');
    }
    $medicineName = $rows[0][0];
    $dosage = $rows[0][1];

    // Get current stock from most recent inventory
    $sql = "SELECT current_stock, recorded_at FROM hc_medicine_inventory
            WHERE medicine_id = ? ORDER BY recorded_at DESC LIMIT 1";
    $invRows = dbi_get_cached_rows($sql, [$medicine_id]);
    if (!empty($invRows) && !empty($invRows[0])) {
        $currentStock = floatval($invRows[0][0]);
        $lastRecorded = $invRows[0][1];
    }
}

echo "<h2>Record Refill</h2>\n";
echo "<div class='container mt-3'>\n";

// Barcode scanner section
echo "<div class='card mb-4' id='scanner-card'>\n";
echo "<div class='card-header d-flex justify-content-between align-items-center'>\n";
echo "<strong>Scan Barcode</strong>\n";
// Live-scanner button (HTTPS only — hidden on HTTP by JS)
echo "<button type='button' class='btn btn-sm btn-outline-primary' id='scan-btn' style='display:none'>\n";
echo "<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='currentColor' viewBox='0 0 16 16' class='mr-1'><path d='M1.5 1a.5.5 0 0 0-.5.5v3a.5.5 0 0 1-1 0v-3A1.5 1.5 0 0 1 1.5 0h3a.5.5 0 0 1 0 1h-3zM11 .5a.5.5 0 0 1 .5-.5h3A1.5 1.5 0 0 1 16 1.5v3a.5.5 0 0 1-1 0v-3a.5.5 0 0 0-.5-.5h-3a.5.5 0 0 1-.5-.5zM.5 11a.5.5 0 0 1 .5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 1 0 1h-3A1.5 1.5 0 0 1 0 14.5v-3a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v3a1.5 1.5 0 0 1-1.5 1.5h-3a.5.5 0 0 1 0-1h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 1 .5-.5z'/><path d='M3 4.5a.5.5 0 0 1 1 0v7a.5.5 0 0 1-1 0v-7zm2 0a.5.5 0 0 1 1 0v7a.5.5 0 0 1-1 0v-7zm2 0a.5.5 0 0 1 1 0v7a.5.5 0 0 1-1 0v-7zm2 0a.5.5 0 0 1 1 0v7a.5.5 0 0 1-1 0v-7z'/></svg>";
echo " Scan barcode</button>\n";
echo "</div>\n";
echo "<div class='card-body'>\n";
// Photo capture input (HTTP fallback — hidden on HTTPS by JS)
echo "<div id='capture-group' style='display:none'>\n";
echo "<label for='barcode-photo' class='btn btn-outline-primary mb-2'>\n";
echo "<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='currentColor' viewBox='0 0 16 16' class='mr-1'><path d='M10.5 8.5a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0z'/><path d='M2 4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-1.172a2 2 0 0 1-1.414-.586l-.828-.828A2 2 0 0 0 9.172 2H6.828a2 2 0 0 0-1.414.586l-.828.828A2 2 0 0 1 3.172 4H2zm.5 2a.5.5 0 1 1 0-1 .5.5 0 0 1 0 1zm9 2.5a3.5 3.5 0 1 1-7 0 3.5 3.5 0 0 1 7 0z'/></svg>";
echo " Take photo of barcode</label>\n";
echo "<input type='file' id='barcode-photo' accept='image/*' capture='environment' class='d-none'>\n";
echo "<div id='capture-processing' style='display:none' class='mb-2'><small class='text-muted'>Decoding barcode from photo...</small></div>\n";
echo "</div>\n";
echo "<div id='scanner-region' style='display:none;width:100%;max-width:400px'></div>\n";
echo "<div id='scan-result' style='display:none'>\n";
echo "<div class='alert alert-info' id='scan-status'></div>\n";
echo "</div>\n";
echo "<div id='scan-preview' style='display:none' class='mt-2'>\n";
echo "<div class='alert alert-success'>\n";
echo "<strong>Match found:</strong>\n";
echo "<div id='preview-name'></div>\n";
echo "<div id='preview-strength'></div>\n";
echo "<div id='preview-form'></div>\n";
echo "</div>\n";
echo "<button type='button' class='btn btn-primary btn-sm' id='use-scanned'>Use this medication</button>\n";
echo "<button type='button' class='btn btn-secondary btn-sm ml-2' id='scan-again'>Scan again</button>\n";
echo "</div>\n";
echo "<div id='scan-not-found' style='display:none' class='mt-2'>\n";
echo "<div class='alert alert-warning'>No matching drug found for this barcode. You can enter the refill manually below.</div>\n";
echo "</div>\n";
// Manual NDC entry — shown when photo decode fails or as a direct fallback
echo "<div id='manual-ndc-group' style='display:none' class='mt-2'>\n";
echo "<div class='input-group'>\n";
echo "<input type='text' id='manual-ndc' class='form-control' placeholder='Type NDC digits (e.g. 00071015523)' maxlength='13' pattern='[0-9\\-]{10,13}'>\n";
echo "<div class='input-group-append'>\n";
echo "<button type='button' class='btn btn-outline-primary' id='manual-ndc-btn'>Look up</button>\n";
echo "</div>\n";
echo "</div>\n";
echo "<small class='form-text text-muted'>Find the NDC number on the prescription label (usually near the barcode).</small>\n";
echo "</div>\n";
echo "<small class='form-text text-muted'>Scan the NDC barcode on a US prescription label, or a UPC/EAN on veterinary products.</small>\n";
echo "</div>\n";
echo "</div>\n";

// Medicine info card (shown when medicine_id is set)
if (!empty($medicine_id)) {
    echo "<div class='card mb-4' id='medicine-info'>\n";
    echo "<div class='card-header'><strong>" . htmlspecialchars($medicineName) . "</strong></div>\n";
    echo "<div class='card-body'>\n";
    echo "<p class='mb-1'><strong>Dosage:</strong> " . htmlspecialchars($dosage) . "</p>\n";
    echo "<p class='mb-1'><strong>Current stock:</strong> " . number_format($currentStock, 2) . "</p>\n";
    if ($lastRecorded) {
        echo "<p class='mb-0'><strong>Last updated:</strong> " . htmlspecialchars(date('M j, Y g:i A', strtotime($lastRecorded))) . "</p>\n";
    }
    echo "</div>\n";
    echo "</div>\n";
}

echo "<form action='inventory_refill_handler.php' method='POST' id='refill-form'>\n";
print_form_key();
echo "<input type='hidden' name='medicine_id' id='form_medicine_id' value='" . intval($medicine_id) . "'>\n";
echo "<input type='hidden' name='refill_source' id='refill_source' value='manual'>\n";

echo "<div class='form-group'>\n";
echo "<label for='refill_quantity'>Refill Quantity:</label>\n";
echo "<input type='number' step='0.25' min='0.25' id='refill_quantity' name='refill_quantity' class='form-control' required>\n";
echo "<small class='form-text text-muted'>How many units are you adding?</small>\n";
echo "</div>\n";

echo "<div class='form-group'>\n";
echo "<label for='new_stock'>New Stock Total:</label>\n";
echo "<input type='number' step='0.25' id='new_stock' name='new_stock' class='form-control' value='" . number_format($currentStock, 2, '.', '') . "' readonly>\n";
echo "<small class='form-text text-muted'>Automatically calculated. Edit if the total should differ.</small>\n";
echo "</div>\n";

echo "<div class='form-group'>\n";
echo "<label for='note'>Note:</label>\n";
echo "<input type='text' id='note' name='note' class='form-control' placeholder='e.g., Ordered from Chewy'>\n";
echo "</div>\n";

echo "<div class='mt-4'>\n";
echo "<a href='inventory_dashboard.php' class='btn btn-secondary mr-2'>Cancel</a>\n";
echo "<button type='submit' class='btn btn-success' id='submit-btn'" . (empty($medicine_id) ? " disabled" : "") . ">Record Refill</button>\n";
echo "</div>\n";

echo "</form>\n";
echo "</div>\n";
?>
<script src="pub/html5-qrcode.min.js"></script>
<script nonce="<?= htmlspecialchars($GLOBALS['NONCE'] ?? '') ?>">
(function() {
    var refillInput = document.getElementById('refill_quantity');
    var currentStock = <?php echo json_encode($currentStock); ?>;
    var newStockInput = document.getElementById('new_stock');

    refillInput.addEventListener('input', function() {
        var refill = parseFloat(this.value) || 0;
        newStockInput.value = (currentStock + refill).toFixed(2);
        newStockInput.removeAttribute('readonly');
    });

    // Shared elements
    var scanBtn = document.getElementById('scan-btn');
    var captureGroup = document.getElementById('capture-group');
    var barcodePhoto = document.getElementById('barcode-photo');
    var captureProcessing = document.getElementById('capture-processing');
    var scannerRegion = document.getElementById('scanner-region');
    var scanResult = document.getElementById('scan-result');
    var scanStatus = document.getElementById('scan-status');
    var scanPreview = document.getElementById('scan-preview');
    var scanNotFound = document.getElementById('scan-not-found');
    var scanAgainBtn = document.getElementById('scan-again');
    var useScannedBtn = document.getElementById('use-scanned');
    var refillSource = document.getElementById('refill_source');
    var formMedicineId = document.getElementById('form_medicine_id');
    var submitBtn = document.getElementById('submit-btn');
    var scanner = null;
    var scannedDrug = null;
    var isSecure = window.isSecureContext;

    // Show the appropriate scanner UI based on protocol
    if (isSecure) {
        if (scanBtn) scanBtn.style.display = '';
    } else {
        if (captureGroup) captureGroup.style.display = '';
    }

    function stopScanner() {
        if (scanner) {
            scanner.stop().catch(function() {});
            scanner.clear();
            scanner = null;
        }
        scannerRegion.style.display = 'none';
    }

    function resetScanUI() {
        scanResult.style.display = 'none';
        scanPreview.style.display = 'none';
        scanNotFound.style.display = 'none';
        if (captureProcessing) captureProcessing.style.display = 'none';
        scannedDrug = null;
    }

    function lookupNdc(code) {
        scanStatus.textContent = 'Looking up ' + code + '...';
        scanResult.style.display = 'block';

        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'api/v1/drug_lookup.php?ndc=' + encodeURIComponent(code));
        xhr.setRequestHeader('Authorization', 'Bearer ' + (window.HC_API_KEY || ''));
        xhr.onload = function() {
            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp.status === 'ok' && resp.data && resp.data.length > 0) {
                    scannedDrug = resp.data[0];
                    scanStatus.textContent = 'Barcode matched!';
                    document.getElementById('preview-name').textContent = scannedDrug.name;
                    document.getElementById('preview-strength').textContent = scannedDrug.strength ? 'Strength: ' + scannedDrug.strength : '';
                    document.getElementById('preview-form').textContent = scannedDrug.dosage_form ? 'Form: ' + scannedDrug.dosage_form : '';
                    scanPreview.style.display = 'block';
                    scanNotFound.style.display = 'none';
                } else {
                    scanStatus.textContent = 'No match for NDC: ' + code;
                    scanPreview.style.display = 'none';
                    scanNotFound.style.display = 'block';
                }
            } catch(e) {
                scanStatus.textContent = 'Error looking up barcode.';
                scanNotFound.style.display = 'block';
            }
        };
        xhr.onerror = function() {
            scanStatus.textContent = 'Network error during lookup.';
            scanNotFound.style.display = 'block';
        };
        xhr.send();
    }

    // ── HTTPS: live camera scanner ──
    if (isSecure && scanBtn && typeof Html5Qrcode !== 'undefined') {
        scanBtn.addEventListener('click', function() {
            resetScanUI();

            if (scanner) {
                stopScanner();
                return;
            }

            scannerRegion.style.display = 'block';
            scanner = new Html5Qrcode('scanner-region');

            scanner.start(
                { facingMode: 'environment' },
                {
                    fps: 10,
                    qrbox: { width: 250, height: 100 },
                    formatsToSupport: [
                        Html5QrcodeSupportedFormats.UPC_A,
                        Html5QrcodeSupportedFormats.UPC_E,
                        Html5QrcodeSupportedFormats.EAN_13,
                        Html5QrcodeSupportedFormats.EAN_8,
                        Html5QrcodeSupportedFormats.CODE_128,
                        Html5QrcodeSupportedFormats.CODE_39,
                        Html5QrcodeSupportedFormats.ITF,
                        Html5QrcodeSupportedFormats.CODABAR
                    ]
                },
                function onScanSuccess(decodedText) {
                    stopScanner();
                    lookupNdc(decodedText);
                },
                function onScanFailure() {}
            ).catch(function(err) {
                scannerRegion.style.display = 'none';
                scanStatus.textContent = 'Camera access denied or unavailable: ' + err;
                scanResult.style.display = 'block';
            });
        });
    }

    // ── HTTP: photo capture + decode from image ──
    if (!isSecure && barcodePhoto && typeof Html5Qrcode !== 'undefined') {
        barcodePhoto.addEventListener('change', function() {
            var file = barcodePhoto.files[0];
            if (!file) return;

            resetScanUI();
            captureProcessing.style.display = 'block';

            var html5Qr = new Html5Qrcode('scanner-region', {
                formatsToSupport: [
                    Html5QrcodeSupportedFormats.UPC_A,
                    Html5QrcodeSupportedFormats.UPC_E,
                    Html5QrcodeSupportedFormats.EAN_13,
                    Html5QrcodeSupportedFormats.EAN_8,
                    Html5QrcodeSupportedFormats.CODE_128,
                    Html5QrcodeSupportedFormats.CODE_39,
                    Html5QrcodeSupportedFormats.ITF,
                    Html5QrcodeSupportedFormats.CODABAR
                ]
            });
            // showImage=true makes the library render the image into
            // scanner-region so the underlying ZXing decoder can work
            // on the full-resolution image — critical when the barcode
            // is only part of a larger photo.
            html5Qr.scanFileV2(file, true).then(function(result) {
                captureProcessing.style.display = 'none';
                scannerRegion.style.display = 'none';
                lookupNdc(result.decodedText);
            }).catch(function(err) {
                captureProcessing.style.display = 'none';
                scannerRegion.style.display = 'none';
                scanStatus.innerHTML = 'Could not read a barcode from that photo.<br>'
                    + '<small>Tip: get closer so the barcode fills most of the frame, or type the NDC digits manually below.</small>';
                scanResult.style.display = 'block';
                manualNdcGroup.style.display = 'block';
            });

            // Reset so the same file can be re-selected
            barcodePhoto.value = '';
        });
    }

    // ── Manual NDC entry fallback ──
    var manualNdcGroup = document.getElementById('manual-ndc-group');
    var manualNdcInput = document.getElementById('manual-ndc');
    var manualNdcBtn = document.getElementById('manual-ndc-btn');
    if (manualNdcBtn && manualNdcInput) {
        manualNdcBtn.addEventListener('click', function() {
            var ndc = manualNdcInput.value.trim();
            if (ndc.length < 10) {
                alert('NDC codes are typically 10-11 digits. Please check the number.');
                return;
            }
            resetScanUI();
            manualNdcGroup.style.display = 'none';
            lookupNdc(ndc);
        });
    }

    // ── Shared: "Use this medication" button ──
    if (useScannedBtn) {
        useScannedBtn.addEventListener('click', function() {
            if (!scannedDrug) return;

            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'api/v1/drugs.php?q=' + encodeURIComponent(scannedDrug.name) + '&limit=1');
            xhr.setRequestHeader('Authorization', 'Bearer ' + (window.HC_API_KEY || ''));
            xhr.onload = function() {
                if (formMedicineId.value && formMedicineId.value !== '0') {
                    refillSource.value = 'barcode';
                    submitBtn.disabled = false;
                    scanPreview.querySelector('.alert').className = 'alert alert-success';
                    scanPreview.querySelector('.alert').innerHTML += '<br><em>Refill source set to barcode scan.</em>';
                    useScannedBtn.disabled = true;
                } else {
                    alert('Scanned: ' + scannedDrug.name + '\n\nPlease select this medication from the inventory dashboard to record the refill.');
                    window.location.href = 'inventory_dashboard.php';
                }
            };
            xhr.onerror = function() {
                refillSource.value = 'barcode';
                submitBtn.disabled = false;
            };
            xhr.send();
        });
    }

    // ── Shared: "Scan again" button ──
    if (scanAgainBtn) {
        scanAgainBtn.addEventListener('click', function() {
            resetScanUI();
            if (isSecure && scanBtn) {
                scanBtn.click();
            } else if (barcodePhoto) {
                barcodePhoto.click();
            }
        });
    }
})();
</script>
<?php
echo print_trailer();
?>
