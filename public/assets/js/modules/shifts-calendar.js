/**
 * Calendrier des shifts : sélection/désélection de tous les employés.
 * Fonction globale appelée via onclick dans admin/shifts-calendar.php.
 * Sentinelle u[]=0 quand tout est décoché (évite "aucun filtre = tous").
 */
function calSelectAll(checked) {
    var form = document.getElementById('calFilterForm');
    if (!form) return;
    document.querySelectorAll('#calFilterForm input[type=checkbox]').forEach(function (cb) {
        cb.checked = checked;
    });
    if (!checked) {
        var inp   = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = 'u[]';
        inp.value = '0';
        form.appendChild(inp);
    }
    form.submit();
}

/* ── Filtre employés : menu déroulant ─────────────────────── */
(function () {
    var wrap   = document.getElementById('calEmployeeFilter');
    var toggle = document.getElementById('calEmployeeToggle');
    if (!wrap || !toggle) return;

    toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        var open = wrap.classList.toggle('cal-employee-filter--open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    document.addEventListener('click', function (e) {
        if (!wrap.contains(e.target)) {
            wrap.classList.remove('cal-employee-filter--open');
            toggle.setAttribute('aria-expanded', 'false');
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && wrap.classList.contains('cal-employee-filter--open')) {
            wrap.classList.remove('cal-employee-filter--open');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.focus();
        }
    });
}());
