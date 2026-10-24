/**
 * Liste des shifts : sélection en masse (bulk) + initialisation couleurs type-badge.
 * Les messages i18n sont lus depuis #shifts-meta (data-msg-max, data-msg-selected).
 * bulkSelectAll et bulkUpdateBar sont exposés en global (appelés via onclick dans la vue).
 */
(function () {
    var meta    = document.getElementById('shifts-meta');
    var msgMax  = meta ? meta.dataset.msgMax      : '';
    var msgSel  = meta ? meta.dataset.msgSelected : '';
    var BULK_MAX = 100;

    /* ── Couleurs dynamiques des badges de type ── */
    document.querySelectorAll('.type-badge[data-color]').forEach(function (el) {
        var c = el.dataset.color;
        if (!c) return;
        el.style.setProperty('--type-bg',     c + '20');
        el.style.setProperty('--type-fg',     c);
        el.style.setProperty('--type-border', c + '40');
    });

    /* ── Tout sélectionner / désélectionner ────── */
    function bulkSelectAll(checked) {
        var all      = document.querySelectorAll('.bulk-cb');
        var selected = 0;
        all.forEach(function (cb) {
            if (checked && selected >= BULK_MAX) {
                cb.checked = false;
            } else {
                cb.checked = checked;
                if (checked) selected++;
            }
        });
        if (checked && selected >= BULK_MAX) alert(msgMax);
        bulkUpdateBar();
    }

    /* ── Mise à jour de la barre d'action ──────── */
    function bulkUpdateBar() {
        var checked = document.querySelectorAll('.bulk-cb:checked');
        var bar     = document.getElementById('bulk-bar');
        var count   = document.getElementById('bulk-count');
        var all     = document.getElementById('bulk-select-all');
        var total   = document.querySelectorAll('.bulk-cb').length;
        if (checked.length > 0) {
            bar.classList.remove('is-hidden');
            count.textContent = checked.length + ' ' + msgSel +
                (checked.length >= BULK_MAX ? ' (max ' + BULK_MAX + ')' : '');
        } else {
            bar.classList.add('is-hidden');
        }
        all.indeterminate = checked.length > 0 && checked.length < total;
        all.checked       = checked.length > 0 && checked.length === total;
    }

    /* ── Délégation : clic sur case à cocher ───── */
    document.addEventListener('change', function (e) {
        if (!e.target.classList.contains('bulk-cb')) return;
        if (e.target.checked && document.querySelectorAll('.bulk-cb:checked').length > BULK_MAX) {
            e.target.checked = false;
            alert(msgMax);
        }
        bulkUpdateBar();
    });

    window.bulkSelectAll  = bulkSelectAll;
    window.bulkUpdateBar  = bulkUpdateBar;
})();
