/**
 * Auto-enregistrement de la page d'édition employé : chaque changement de
 * champ du formulaire principal (id=userEditForm, marqué data-autosave)
 * déclenche une sauvegarde en arrière-plan (AJAX), sans recharger la page.
 * form.elements est utilisé plutôt qu'un simple listener sur le form : les
 * champs de la section "Rôles et apparence" sont rattachés via l'attribut
 * form= mais physiquement hors du <form> (fusionnés avec Stores/Cotisations/
 * Taux horaires, voir _form-user.php et users-form.php) — leurs événements
 * ne remonteraient pas par bubbling jusqu'à un listener posé sur le <form>.
 * Actif uniquement en édition (data-autosave="1") ; absent en création, où
 * l'enregistrement reste manuel via le bouton "Créer".
 */
(function () {
    'use strict';

    function init() {
        var form = document.getElementById('userEditForm');
        if (!form || form.dataset.autosave !== '1') return;

        var status = document.querySelector('[data-autosave-status]');
        var pending = false;
        var queued = false;

        function setStatus(label, state) {
            if (!status) return;
            status.textContent = label;
            status.dataset.state = state;
        }

        function save() {
            // Les champs requis (nom, furigana...) restent validés même hors soumission
            // manuelle : un envoi en arrière-plan avec un champ vide serait accepté par
            // le serveur (seuls last_name/first_name/furigana bloquent réellement), donc
            // on évite ici de sauvegarder un état visiblement incomplet.
            if (!form.checkValidity()) {
                setStatus(status ? status.dataset.errorLabel : '', 'error');
                return;
            }
            if (pending) { queued = true; return; }
            pending = true;
            setStatus(status ? status.dataset.savingLabel : '', 'saving');

            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (res) {
                    return res.json().then(function (data) { return { ok: res.ok, data: data }; });
                })
                .then(function (result) {
                    pending = false;
                    if (result.ok && result.data && result.data.success) {
                        setStatus(status ? status.dataset.savedLabel : '', 'saved');
                    } else {
                        setStatus(status ? status.dataset.errorLabel : '', 'error');
                    }
                    if (queued) { queued = false; save(); }
                })
                .catch(function () {
                    pending = false;
                    setStatus(status ? status.dataset.errorLabel : '', 'error');
                    if (queued) { queued = false; save(); }
                });
        }

        Array.prototype.forEach.call(form.elements, function (field) {
            if (field.type === 'submit' || field.type === 'button' || !field.name) return;
            field.addEventListener('change', save);
        });
    }

    // Différé à DOMContentLoaded : les champs "Rôles et apparence" sont rattachés
    // via l'attribut form= mais apparaissent plus bas dans le HTML (fusionnés avec
    // Stores/Cotisations/Taux horaires, cf. users-form.php), *après* la balise
    // <script> de ce module. form.elements ne les contiendrait pas encore si
    // l'initialisation lisait le formulaire pendant le parsing synchrone du script.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
