/**
 * Formulaire de création d'utilisateur — sélecteur "Rôle" unifié
 * (Owner + rôles par store). Le rôle Owner s'applique à toute
 * l'organisation : masque le sélecteur de store quand il est choisi,
 * plutôt que de laisser un champ store sans effet visible.
 */
(function () {
    var select = document.getElementById('userRoleSelect');
    var storeGroup = document.getElementById('userStoreGroup');
    if (!select || !storeGroup) return;

    function sync() {
        var opt = select.options[select.selectedIndex];
        var isOwner = !!opt && opt.dataset.system === '1';
        // .form-group fixe déjà `display`, ce qui l'emporterait sur l'attribut
        // hidden (styles auteur > styles UA à spécificité égale) : on bascule
        // `display` directement plutôt que `hidden`.
        storeGroup.style.display = isOwner ? 'none' : '';
    }

    select.addEventListener('change', sync);
    sync();
}());
