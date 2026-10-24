/**
 * Validation visible des champs obligatoires sur les formulaires marqués
 * `novalidate` : au clic sur Enregistrer, si un champ `[required]` est vide
 * ou invalide, la soumission est bloquée, le champ passe en `is-invalid`
 * (bordure rouge) et le message `[data-required-error]` du formulaire
 * (juste au-dessus des boutons d'action) devient visible — remplace la
 * validation native du navigateur, silencieuse et facilement manquée sur
 * un long formulaire à sections.
 */
(function () {
    'use strict';

    document.querySelectorAll('form[novalidate]').forEach(function (form) {
        // Repli document-large : sur la page d'édition employé, le message d'erreur
        // et les boutons sont rendus après </form> (regroupés en bas de page avec
        // Enregistrer/Annuler), donc pas un descendant du form — retrouvé via
        // data-required-error-form="<id du form>" plutôt que form.querySelector().
        var errorEl = form.querySelector('[data-required-error]')
            || (form.id && document.querySelector('[data-required-error][data-required-error-form="' + form.id + '"]'));
        if (!errorEl) return;

        var requiredFields = form.querySelectorAll('[required]');
        if (!requiredFields.length) return;

        function clearInvalid(field) {
            if (field.checkValidity()) field.classList.remove('is-invalid');
        }

        requiredFields.forEach(function (field) {
            field.addEventListener('input', function () { clearInvalid(field); });
            field.addEventListener('change', function () { clearInvalid(field); });
        });

        form.addEventListener('submit', function (e) {
            var firstInvalid = null;
            requiredFields.forEach(function (field) {
                var invalid = !field.checkValidity();
                field.classList.toggle('is-invalid', invalid);
                if (invalid && !firstInvalid) firstInvalid = field;
            });

            if (!firstInvalid) {
                errorEl.hidden = true;
                return;
            }

            e.preventDefault();
            errorEl.hidden = false;
            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstInvalid.focus();
        });
    });
})();
