/**
 * Owner settings : interrupteur Auto/Manuel pour les variantes sombres des couleurs
 * du thème (voir ThemeColorPalette) — affiche/masque les 7 sélecteurs correspondants.
 */
(function () {
    var switcher = document.querySelector('[data-theme-dark-mode-switcher]');
    var modeInput = document.getElementById('app_theme_dark_mode');
    if (!switcher || !modeInput) return;

    var buttons = switcher.querySelectorAll('[data-dark-mode-option]');
    var thumb = switcher.querySelector('.btn-group__thumb');
    var darkFields = document.querySelectorAll('[data-theme-dark-color-for]');

    function applyMode(mode) {
        modeInput.value = mode;
        buttons.forEach(function (btn) {
            btn.classList.toggle('btn--active', btn.dataset.darkModeOption === mode);
        });
        if (thumb) thumb.style.setProperty('--pos', mode === 'manual' ? 1 : 0);
        darkFields.forEach(function (field) {
            field.hidden = mode !== 'manual';
        });
    }

    buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            applyMode(btn.dataset.darkModeOption);
        });
    });
})();
