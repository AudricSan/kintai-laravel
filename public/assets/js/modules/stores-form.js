/**
 * Store form : toggle déductions + cartes fonctionnalités interactives.
 */
(function () {
    /* ── Toggle déductions ─────────────────────── */
    var dedToggle = document.querySelector('input[type="checkbox"][name="ded_enabled"]');
    if (dedToggle) {
        dedToggle.addEventListener('change', function () {
            var fields = document.getElementById('ded-fields');
            if (fields) fields.hidden = !this.checked;
        });
    }

    /* ── Style du symbole yen (visible seulement si devise = JPY) ─── */
    var currencySelect = document.querySelector('[name="currency"]');
    var jpyStyleGroup   = document.getElementById('jpy-symbol-style-group');
    if (currencySelect && jpyStyleGroup) {
        var toggleJpyStyle = function () {
            jpyStyleGroup.hidden = currencySelect.value !== 'JPY';
        };
        currencySelect.addEventListener('change', toggleJpyStyle);
        toggleJpyStyle();
    }

    /* ── Cartes de fonctionnalités ──────────────── */
    var i18nEl = document.getElementById('store-form-i18n');
    var i18n   = i18nEl ? JSON.parse(i18nEl.textContent) : {};
    var onTxt  = i18n.active   || 'Active';
    var offTxt = i18n.inactive || 'Inactive';

    function applyFeatureState(card, checked) {
        card.classList.toggle('feature-card--on', checked);
        var lbl = card.querySelector('.toggle-switch__label');
        if (lbl) lbl.textContent = checked ? onTxt : offTxt;
    }

    document.querySelectorAll('.feature-card__input').forEach(function (cb) {
        applyFeatureState(cb.closest('.feature-card'), cb.checked);

        cb.addEventListener('change', function () {
            applyFeatureState(this.closest('.feature-card'), this.checked);
        });
    });
})();
