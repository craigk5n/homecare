<?php
/**
 * HC-140: SSE auto-refresh snippet.
 *
 * Include on pages that should auto-update when another caregiver
 * records an action. Set $sse_patient_id before including.
 *
 * On receiving a relevant event, debounces 500 ms then reloads the page.
 * Graceful fallback: if EventSource is unsupported, does nothing.
 */

if (!defined('_ISVALID') || empty($sse_patient_id)) {
    return;
}
$ssePatientId = (int) $sse_patient_id;
?>
<script>
(function() {
    if (typeof EventSource === 'undefined') return;

    var patientId = <?= $ssePatientId ?>;
    var reloadTimer = null;
    var source = null;

    function connect() {
        var url = 'events.php?patient_id=' + patientId;
        source = new EventSource(url);

        source.onmessage = function(e) {
            try {
                var data = JSON.parse(e.data);
                if (shouldRefresh(data)) {
                    clearTimeout(reloadTimer);
                    reloadTimer = setTimeout(function() {
                        window.location.reload();
                    }, 500);
                }
            } catch(err) {}
        };

        source.addEventListener('timeout', function() {
            source.close();
            setTimeout(connect, 1000);
        });

        source.onerror = function() {
            source.close();
            setTimeout(connect, 5000);
        };
    }

    function shouldRefresh(data) {
        var actions = [
            'intake.recorded', 'intake.deleted',
            'schedule.created', 'schedule.updated', 'schedule.ended',
            'inventory.refilled', 'inventory.updated',
            'note.created', 'note.updated', 'note.deleted',
            'medicine.created', 'medicine.updated',
            'schedule.paused', 'schedule.resumed', 'schedule.skipped'
        ];
        return actions.indexOf(data.action) !== -1;
    }

    connect();

    window.addEventListener('beforeunload', function() {
        if (source) source.close();
    });
})();
</script>
