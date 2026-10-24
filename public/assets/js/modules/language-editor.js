'use strict';
/**
 * Éditeur de traductions : ajout/modification/suppression d'une clé en AJAX
 * depuis la page system/language-edit.php.
 */
(function () {
    var _cfgEl = document.getElementById('lang-edit-data');
    var cfg    = _cfgEl ? JSON.parse(_cfgEl.textContent) : {};
    var BASE   = cfg.baseUrl   || '';
    var CSRF   = cfg.csrfToken || '';
    var CODE   = cfg.code      || '';

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify(body),
        }).then(function (r) { return r.json(); });
    }

    document.querySelectorAll('.translation-value-input').forEach(function (textarea) {
        var row = textarea.closest('tr');
        var key = row.dataset.key;
        var initial = textarea.value;

        textarea.addEventListener('blur', function () {
            if (textarea.value === initial) return;
            post(BASE + '/admin/languages/' + encodeURIComponent(CODE) + '/edit/save', {
                key: key,
                value: textarea.value,
            }).then(function (res) {
                if (res && !res.error) initial = textarea.value;
            });
        });
    });

    document.querySelectorAll('.translation-delete-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm(cfg.confirmDelete)) return;
            var row = btn.closest('tr');
            var key = row.dataset.key;
            post(BASE + '/admin/languages/' + encodeURIComponent(CODE) + '/edit/delete', { key: key })
                .then(function (res) {
                    if (res && !res.error) row.remove();
                });
        });
    });

    var addBtn = document.getElementById('add-key-btn');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            var keyInput = document.getElementById('new-key-input');
            var valueInput = document.getElementById('new-value-input');
            var key = keyInput.value.trim();
            if (!key) return;

            post(BASE + '/admin/languages/' + encodeURIComponent(CODE) + '/edit/save', {
                key: key,
                value: valueInput.value,
            }).then(function (res) {
                if (res && !res.error) {
                    location.reload();
                }
            });
        });
    }
}());
