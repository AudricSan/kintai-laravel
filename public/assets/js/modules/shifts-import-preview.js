/**
 * Import Excel — prévisualisation et création rapide d'employé (formulaire complet).
 */

/* ── Création rapide d'employé (modal) ────────── */
(function () {
    var cfgEl  = document.getElementById('kintai-import-data');
    var cfg    = cfgEl ? JSON.parse(cfgEl.textContent || '{}') : (window.KintaiImport || {});
    var BASE   = cfg.baseUrl   || '';
    var STORE  = cfg.storeId   || 0;
    var i18n   = cfg.i18n      || {};
    var introTpl   = i18n.introTpl   || ':name';
    var msgSuccess = i18n.msgSuccess || '';
    var msgError   = i18n.msgError   || '';
    var filterTpl  = cfg.filterTpl   || ':n';

    var _currentRow = null;

    // La vérification en temps réel (email/code employé) est gérée par le
    // module générique user-form-live-check.js, branché sur les mêmes champs
    // `.live-check-input` du partiel _form-user.php inclus dans #qc-form :
    // il pose/retire la classe `is-invalid` et affiche [data-check-error].
    function resetLiveCheckState() {
        document.querySelectorAll('#qc-form .live-check-input').forEach(function (el) {
            el.classList.remove('is-invalid');
            var group = el.closest('.form-group');
            var err = group ? group.querySelector('[data-check-error]') : null;
            if (err) err.hidden = true;
        });
    }

    /* ── Ouverture / fermeture de la modal ─────── */
    window.qcOpen = function (btn) {
        _currentRow = btn.closest('tr');
        var staffName = btn.dataset.staff || '';
        var parts = staffName.split(/\s+/);
        var fn = document.querySelector('#qc-form input[name="first_name"]');
        var ln = document.querySelector('#qc-form input[name="last_name"]');
        if (fn) fn.value = parts[0] || staffName;
        if (ln) ln.value = parts.slice(1).join(' ');

        var dn = document.querySelector('#qc-form input[name="display_name"]');
        if (dn) dn.value = staffName;

        var ec = document.querySelector('#qc-form input[name="employee_code"]');
        if (ec) ec.value = staffName.substring(0, 10).toUpperCase().replace(/\s+/g, '_');

        /* Générer un email par défaut basé sur le nom */
        var em = document.querySelector('#qc-form input[name="email"]');
        if (em) {
            var emailLocal = (fn ? fn.value : '') + '.' + (ln ? ln.value : '');
            em.value = emailLocal.toLowerCase().replace(/[^a-z0-9.]/g, '') + '@import.local';
        }

        var intro = document.getElementById('qc-intro');
        if (intro) intro.innerHTML = introTpl.replace(':name', escHtml(staffName));
        resetLiveCheckState();
        var alertEl = document.getElementById('qc-alert');
        if (alertEl) { alertEl.style.display = 'none'; alertEl.className = 'alert mb-sm'; }
        var overlay = document.getElementById('qc-overlay');
        if (overlay) overlay.classList.add('open');
        if (fn) fn.focus();
    };

    window.qcClose = function () {
        var overlay = document.getElementById('qc-overlay');
        if (overlay) overlay.classList.remove('open');
        resetLiveCheckState();
        _currentRow = null;
    };

    /* ── Soumission AJAX du formulaire de création ── */
    window.qcSubmit = function () {
        var qcForm = document.getElementById('qc-form');
        if (!qcForm) return;
        var fn = document.querySelector('#qc-form input[name="first_name"]');
        if (!fn || !fn.value.trim()) { if (fn) fn.focus(); return; }
        var ln = document.querySelector('#qc-form input[name="last_name"]');
        if (!ln || !ln.value.trim()) { if (ln) ln.focus(); return; }

        // qc-form n'est pas soumis nativement (appel JS direct) : les attributs
        // required de last_name/first_name/furigana_last_name/furigana_first_name
        // ne sont donc pas vérifiés par le navigateur, il faut le faire ici.
        var furiganaLast  = document.querySelector('#qc-form input[name="furigana_last_name"]');
        var furiganaFirst = document.querySelector('#qc-form input[name="furigana_first_name"]');
        if (furiganaLast && !furiganaLast.value.trim()) { furiganaLast.focus(); return; }
        if (furiganaFirst && !furiganaFirst.value.trim()) { furiganaFirst.focus(); return; }

        // Un champ signalé pris par la vérification en temps réel bloque l'envoi.
        var invalidField = qcForm.querySelector('.live-check-input.is-invalid');
        if (invalidField) {
            invalidField.focus();
            return;
        }

        var submitBtn = document.getElementById('qc-submit');
        if (submitBtn) submitBtn.disabled = true;

        var formData = new URLSearchParams();
        var formEls = qcForm.querySelectorAll('input, select, textarea');
        formEls.forEach(function (el) {
            if (el.name && el.type !== 'checkbox' && el.type !== 'radio') {
                formData.append(el.name, el.value);
            } else if (el.name && el.type === 'checkbox' && el.checked) {
                formData.append(el.name, el.value);
            }
        });

        fetch(BASE + '/admin/users/quick-create', {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body:    formData.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (submitBtn) submitBtn.disabled = false;
            if (!data.success) {
                var fieldName = data.error === 'employee_code_taken' ? 'employee_code'
                    : data.error === 'email_taken' ? 'email' : null;
                var field = fieldName ? qcForm.querySelector('.live-check-input[name="' + fieldName + '"]') : null;
                if (field) {
                    field.classList.add('is-invalid');
                    var group = field.closest('.form-group');
                    var err = group ? group.querySelector('[data-check-error]') : null;
                    if (err) err.hidden = false;
                    field.focus();
                } else {
                    showAlert('alert--error', msgError + (data.error ? ' — ' + data.error : ''));
                }
                return;
            }
            var newUser = data.user;
            /* Ajoute le nouvel utilisateur à tous les selects de la table */
            document.querySelectorAll('.import-select').forEach(function (sel) {
                var opt = document.createElement('option');
                opt.value       = newUser.id;
                opt.textContent = newUser.name;
                sel.appendChild(opt);
            });
            var staffName = _currentRow ? _currentRow.dataset.staff : '';
            document.querySelectorAll('tbody tr[data-idx]').forEach(function (row) {
                if (row.dataset.staff === staffName) {
                    var sel = row.querySelector('[data-field="user_id"]');
                    if (sel) sel.value = newUser.id;
                    var createBtn = row.querySelector('.qc-open-btn');
                    if (createBtn) createBtn.style.display = 'none';
                    row.classList.remove('tr-warn');
                }
            });
            showAlert('alert--success', msgSuccess);
            setTimeout(window.qcClose, 1200);
        })
        .catch(function () {
            if (submitBtn) submitBtn.disabled = false;
            showAlert('alert--error', msgError);
        });
    };

    function showAlert(cls, msg) {
        var el = document.getElementById('qc-alert');
        if (!el) return;
        el.className   = 'alert ' + cls + ' mb-sm';
        el.textContent = msg;
        el.style.display = '';
    }

    function escHtml(s) {
        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /* ── Délégation d'événements ──────────────── */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.qc-open-btn');
        if (btn) window.qcOpen(btn);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') window.qcClose();
    });

    /* Interception du submit du formulaire de création */
    document.addEventListener('submit', function (e) {
        var form = e.target.closest('#qc-form');
        if (!form) return;
        e.preventDefault();
        window.qcSubmit();
    });
})();

/* ── Filtres par type de conflit (table import) ─ */
(function () {
    var cfgEl     = document.getElementById('kintai-import-data');
    var cfg       = cfgEl ? JSON.parse(cfgEl.textContent || '{}') : (window.KintaiImport || {});
    var filterTpl = cfg.filterTpl || ':n';

    function updateCount() {
        var visible = document.querySelectorAll('tbody tr[data-idx]:not([style*="display: none"])').length;
        var el = document.getElementById('import-filter-count');
        if (el) el.textContent = filterTpl.replace(':n', visible);
    }

    function applyFilters() {
        var active = {};
        document.querySelectorAll('.import-filter-btn').forEach(function (btn) {
            active[btn.dataset.filter] = btn.classList.contains('active');
        });
        document.querySelectorAll('tbody tr[data-idx]').forEach(function (row) {
            var type = row.dataset.conflict || 'ok';
            row.style.display = active[type] === false ? 'none' : '';
        });
        updateCount();
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.import-filter-btn');
        if (!btn) return;
        btn.classList.toggle('active');
        applyFilters();
    });

    updateCount();
})();

/* ── Propagation des assignations manuelles ──── */
(function () {
    var cfgEl = document.getElementById('kintai-import-data');
    var cfg   = cfgEl ? JSON.parse(cfgEl.textContent || '{}') : (window.KintaiImport || {});
    var i18n  = cfg.i18n || {};

    document.addEventListener('change', function (e) {
        var sel = e.target.closest('[data-field="user_id"]');
        if (!sel) return;
        var row = sel.closest('tr[data-staff]');
        if (!row) return;
        var staffName = row.dataset.staff;
        var newVal    = sel.value;
        if (!staffName || newVal === '0') return;

        var count = 0;
        document.querySelectorAll('tbody tr[data-staff]').forEach(function (r) {
            if (r === row || r.dataset.staff !== staffName) return;
            var otherSel = r.querySelector('[data-field="user_id"]');
            if (otherSel && otherSel.value !== newVal) {
                otherSel.value = newVal;
                r.classList.remove('tr-warn');
                var btn = r.querySelector('.qc-open-btn');
                if (btn) btn.style.display = 'none';
                count++;
            }
        });
        if (count > 0) {
            var pill = row.querySelector('.propagate-pill');
            if (!pill) {
                pill = document.createElement('span');
                pill.className = 'propagate-pill';
                sel.parentNode.appendChild(pill);
            }
            pill.textContent = '→ ' + count + ' ' + (count > 1 ? (i18n.propagatedRows || 'lignes') : (i18n.propagatedRow || 'ligne'));
            clearTimeout(sel._propagateTimer);
            sel._propagateTimer = setTimeout(function () { if (pill) pill.remove(); }, 3000);
        }
    });
})();

/* ── Soumission du formulaire d'import ────────── */
(function () {
    var form = document.getElementById('import-form');
    if (!form) return;
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var shifts = [];
        document.querySelectorAll('tbody tr[data-idx]').forEach(function (row) {
            shifts.push({
                date:       row.dataset.date,
                staff_name: row.dataset.staff,
                start_time: row.querySelector('[data-field="start_time"]').value,
                end_time:   row.querySelector('[data-field="end_time"]').value,
                user_id:    row.querySelector('[data-field="user_id"]').value,
                skip:       row.querySelector('[data-field="skip"]').checked ? '1' : '0'
            });
        });
        document.getElementById('shifts_json').value = JSON.stringify(shifts);
        this.submit();
    });
})();
