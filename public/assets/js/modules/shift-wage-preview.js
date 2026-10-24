/**
 * Formulaire de shift (création/édition, y compris la modale rapide du
 * planning) : aperçu en direct de la décomposition par tranche horaire,
 * recalculé via AdminShiftController::wagePreview() à chaque changement de
 * store/utilisateur/horaires/pause. Remplace l'ancien select manuel de
 * shift_type_id — un shift peut désormais traverser plusieurs types, chacun
 * facturé à son propre taux.
 */
(function () {
    'use strict';

    function init(container) {
        if (!container || container.dataset.wagePreviewBound === '1') return;
        container.dataset.wagePreviewBound = '1';

        var form = container.closest('form');
        if (!form) return;

        var url = container.dataset.previewUrl;
        var emptyText = container.dataset.emptyText || '';
        var body = container.querySelector('[data-role="body"]');

        var storeField = form.querySelector('[name="store_id"]');
        var userField = form.querySelector('[name="user_id"]');
        var startField = form.querySelector('[name="start_time"]');
        var endField = form.querySelector('[name="end_time"]');
        var pauseField = form.querySelector('[name="pause_minutes"]');

        var debounceTimer = null;
        var requestId = 0;

        function fieldValue(field) {
            return field ? field.value : '';
        }

        function render(result) {
            var breakdown = (result && result.breakdown) || [];
            if (!breakdown.length) {
                body.innerHTML = '<span class="form-hint">' + escapeHtml(emptyText) + '</span>';
                return;
            }
            var parts = breakdown.map(function (b) {
                var h = Math.floor(b.minutes / 60);
                var m = b.minutes % 60;
                var dur = h + 'h' + (m > 0 ? String(m).padStart(2, '0') : '');
                return escapeHtml(b.name || '—') + ' : ' + dur + ' × ' + Number(b.rate).toLocaleString();
            });
            var total = Number(result.estimated_salary || 0).toLocaleString();
            body.innerHTML = '<ul class="shift-wage-preview__list">'
                + parts.map(function (p) { return '<li>' + p + '</li>'; }).join('')
                + '</ul><div class="shift-wage-preview__total">' + total + '</div>';
        }

        function escapeHtml(s) {
            var d = document.createElement('div');
            d.textContent = String(s);
            return d.innerHTML;
        }

        function fetchPreview() {
            var storeId = fieldValue(storeField);
            var startTime = fieldValue(startField);
            var endTime = fieldValue(endField);
            if (!url || !storeId || !startTime || !endTime) return;

            var params = new URLSearchParams({
                store_id: storeId,
                start_time: startTime,
                end_time: endTime,
                pause_minutes: fieldValue(pauseField) || '0',
                user_id: fieldValue(userField) || '0',
            });

            var thisRequestId = ++requestId;
            fetch(url + '?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (thisRequestId !== requestId) return;
                    render(data);
                })
                .catch(function () {
                    if (thisRequestId !== requestId) return;
                    body.innerHTML = '';
                });
        }

        function scheduleFetch() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(fetchPreview, 300);
        }

        [storeField, userField, startField, endField, pauseField].forEach(function (field) {
            if (!field) return;
            field.addEventListener('change', scheduleFetch);
            field.addEventListener('input', scheduleFetch);
        });

        fetchPreview();
    }

    document.querySelectorAll('#shift-wage-preview').forEach(init);
})();
