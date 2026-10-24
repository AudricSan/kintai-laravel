/**
 * Dashboard admin : panneau de personnalisation des widgets, qui passe aussi la grille en mode édition
 * (widgets déplaçables, dashboard-layout.js). Appelée via data-on-click dans dashboard/index.php.
 */
function adminDashCustomize() {
    var p = document.getElementById('admin-dash-customize');
    if (!p) return;
    var open = !p.classList.toggle('hidden');
    if (window.kintaiDashLayout) window.kintaiDashLayout.setEditing(open);
}
