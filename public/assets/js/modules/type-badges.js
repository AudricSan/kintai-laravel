/**
 * Initialise les couleurs dynamiques des badges de type via CSS custom properties.
 * Utilisé dans shifts.php, shifts-conflicts.php, et toute vue avec .type-badge[data-color].
 */
(function () {
    document.querySelectorAll('.type-badge[data-color]').forEach(function (el) {
        var c = el.dataset.color;
        if (!c) return;
        el.style.setProperty('--type-bg',     c + '20');
        el.style.setProperty('--type-fg',     c);
        el.style.setProperty('--type-border', c + '40');
    });
})();
