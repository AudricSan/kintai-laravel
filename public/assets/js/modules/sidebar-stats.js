/**
 * Sidebar stats : navigation mois + modal détail journalier.
 * Fonctions globales appelées via attributs onclick dans layout/app.php.
 */
function sbMonthNav(month) {
    var url = new URL(window.location.href);
    url.searchParams.set('month', month);
    window.location.href = url.toString();
}

function sbDetailOpen() {
    var overlay = document.getElementById('sb-detail-overlay');
    if (overlay) overlay.classList.add('open');
}

function sbDetailClose() {
    var overlay = document.getElementById('sb-detail-overlay');
    if (overlay) overlay.classList.remove('open');
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') sbDetailClose();
});
