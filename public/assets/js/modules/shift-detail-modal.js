'use strict';
/**
 * Shift detail modal : affichage des infos d'un shift dans la timeline.
 * Expose sdModalOpen / sdModalClose globalement.
 */
(function () {
    var _cfgEl     = document.getElementById('kintai-timeline-data');
    var cfg        = _cfgEl ? JSON.parse(_cfgEl.textContent) : {};
    var BASE       = cfg.baseUrl    || '';
    var CAN_MANAGE = cfg.canManage  || false;

    /* ── Ouverture : remplit la modale ─────────── */
    window.sdModalOpen = function (el) {
        var id         = el.dataset.id;
        var name       = el.dataset.name;
        var date       = el.dataset.date;
        var start      = el.dataset.start;
        var end        = el.dataset.end;
        var type       = el.dataset.type;
        var notes      = el.dataset.notes;
        var color      = el.dataset.color;
        var hours      = el.dataset.hours;
        var pay        = el.dataset.pay;
        var rateDetail = [];
        try { rateDetail = JSON.parse(el.dataset.rateDetail || '[]'); } catch (e) {}

        /* Infos de base */
        document.getElementById('sd-dot').style.background = color;
        document.getElementById('sd-name').textContent     = name;
        document.getElementById('sd-date').textContent     = date;
        document.getElementById('sd-time').textContent     = start + ' – ' + end;
        document.getElementById('sd-hours').textContent    = hours || '—';
        document.getElementById('sd-type').textContent     = type  || '—';

        /* Détail des taux (admin/manager) */
        if (CAN_MANAGE) {
            var block      = document.getElementById('sd-rate-block');
            var rows       = document.getElementById('sd-rate-rows');
            var payRow     = document.getElementById('sd-pay-row');
            var noRate     = document.getElementById('sd-no-rate');
            var hasAnyRate = rateDetail.some(function (item) { return item.has_rate; });

            rows.innerHTML = '';
            if (rateDetail.length > 0) {
                rateDetail.forEach(function (item) {
                    var row = document.createElement('div');
                    row.className = 'pay-row';
                    var lbl  = item.rate_fmt ? item.type_name + ' · ' + item.rate_fmt : item.type_name;
                    var mins = Math.round(item.minutes);
                    var h    = Math.floor(mins / 60), m = mins % 60;
                    // Construit par nœuds DOM : le nom du type de shift est saisi par un administrateur, jamais
                    // interprété comme du HTML.
                    var label = document.createElement('span');
                    label.className = 'pay-label';
                    label.textContent = lbl + ' × ' + h + 'h' + String(m).padStart(2, '0');
                    var amount = document.createElement('span');
                    amount.className = 'pay-amount';
                    amount.textContent = item.pay_fmt || '—';
                    row.appendChild(label);
                    row.appendChild(amount);
                    rows.appendChild(row);
                });
            }

            if (hasAnyRate) {
                noRate.style.display = 'none';
                payRow.style.display = 'flex';
                document.getElementById('sd-pay').textContent = pay || '—';
            } else {
                payRow.style.display = 'none';
                noRate.style.display = 'block';
            }
            block.style.display       = 'flex';
            block.style.flexDirection = 'column';

            /* Liens d'action (edit / delete) */
            var currentLocation = window.location.pathname + window.location.search;
            document.getElementById('sd-edit-link').href =
                BASE + '/admin/shifts/' + id + '/edit?redirect_to=' + encodeURIComponent(currentLocation);
            document.getElementById('sd-delete-form').action =
                BASE + '/admin/shifts/' + id + '/delete';
            var delForm = document.getElementById('sd-delete-form');
            var existingRedirect = delForm.querySelector('input[name="redirect_to"]');
            if (!existingRedirect) {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'redirect_to';
                inp.value = currentLocation;
                delForm.appendChild(inp);
            }
        }

        /* Notes */
        var notesRow = document.getElementById('sd-notes-row');
        if (notes) {
            document.getElementById('sd-notes').textContent = notes;
            notesRow.style.display = 'flex';
        } else {
            notesRow.style.display = 'none';
        }

        document.getElementById('sd-overlay').classList.add('open');
    };

    /* ── Fermeture ─────────────────────────────── */
    window.sdModalClose = function () {
        document.getElementById('sd-overlay').classList.remove('open');
    };

    /* ── Escape ────────────────────────────────── */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            sdModalClose();
            if (typeof window.sdQcClose === 'function') sdQcClose();
        }
    });
}());
