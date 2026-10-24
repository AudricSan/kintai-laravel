/**
 * Dashboard employé : navigation mois, toggle détail shifts, toggle panneau personnalisation.
 * Fonctions globales appelées via attributs onclick dans employee/dashboard.php.
 * Le label du bouton "Détails" est lu depuis data-label sur le bouton lui-même.
 */
function dashMonthNav(month) {
    var url = new URL(window.location.href);
    url.searchParams.set('month', month);
    window.location.href = url.toString();
}

var _dashDetailOpen = false;
function dashDetailToggle() {
    _dashDetailOpen = !_dashDetailOpen;
    var detail = document.getElementById('dash-detail');
    var toggle = document.getElementById('dash-detail-toggle');
    if (detail) detail.classList.toggle('open', _dashDetailOpen);
    if (toggle) {
        var label = toggle.dataset.label || '';
        toggle.textContent = label + (_dashDetailOpen ? ' ▲' : ' ▼');
    }
}

// Panneau de personnalisation ; passe aussi la grille en mode édition (widgets déplaçables, dashboard-layout.js).
function dashCustomizeToggle() {
    var p = document.getElementById('dash-customize');
    if (!p) return;
    var open = !p.classList.toggle('hidden');
    if (window.kintaiDashLayout) window.kintaiDashLayout.setEditing(open);
}
