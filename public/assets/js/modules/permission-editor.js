/**
 * Formulaire de rôle — grille de permissions (slider Ok/NG par action,
 * compteur et bouton "Tout sélectionner/désélectionner" par catégorie).
 * L'état visuel du slider (rouge/vert) suit :checked en CSS pur, rien à
 * faire ici pour ça — seuls le compteur et le libellé sont recalculés.
 */
'use strict';

(function () {
    var grid = document.querySelector('[data-perm-grid]');
    var SELECT_ALL_LABEL   = (grid && grid.dataset.selectAllLabel) || 'Select all';
    var DESELECT_ALL_LABEL = (grid && grid.dataset.deselectAllLabel) || 'Deselect all';

    function refreshCard(card) {
        var checkboxes = card.querySelectorAll('[data-perm-checkbox]');
        var checkedCount = 0;
        checkboxes.forEach(function (cb) {
            if (cb.checked) checkedCount++;
        });

        var counter = card.querySelector('[data-perm-count]');
        if (counter) counter.textContent = checkedCount + '/' + checkboxes.length;

        var toggleAll = card.querySelector('[data-perm-toggle-all]');
        if (toggleAll) {
            toggleAll.textContent = checkedCount === checkboxes.length ? DESELECT_ALL_LABEL : SELECT_ALL_LABEL;
        }
    }

    // Grise/dégrise la case de portée "Toutes les boutiques" en fonction de
    // l'état de la case de permission sœur (voir .perm-row/.perm-scope dans
    // roles-form.php) — le contrôleur ignore de toute façon un scope posté
    // pour une permission non accordée, ceci n'est qu'un confort visuel.
    function syncScopeState(mainCheckbox) {
        var row = mainCheckbox.closest('[data-perm-row]');
        if (!row) return;
        var scopeCheckbox = row.querySelector('[data-perm-scope-checkbox]');
        if (scopeCheckbox) scopeCheckbox.disabled = !mainCheckbox.checked;
    }

    document.addEventListener('change', function (e) {
        if (!e.target.matches('[data-perm-checkbox]')) return;
        syncScopeState(e.target);
        var card = e.target.closest('[data-perm-card]');
        if (card) refreshCard(card);
    });

    document.addEventListener('click', function (e) {
        var toggleAll = e.target.closest('[data-perm-toggle-all]');
        if (!toggleAll) return;
        var card = toggleAll.closest('[data-perm-card]');
        if (!card) return;

        var checkboxes = card.querySelectorAll('[data-perm-checkbox]');
        var target = !Array.prototype.every.call(checkboxes, function (cb) { return cb.checked; });
        checkboxes.forEach(function (cb) {
            cb.checked = target;
            syncScopeState(cb);
        });
        refreshCard(card);
    });
}());
