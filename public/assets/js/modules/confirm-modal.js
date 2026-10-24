/**
 * Modale de confirmation générique — remplace le confirm() natif du navigateur
 * pour tout formulaire marqué data-confirm="message". Un seul dialogue partagé
 * (layout/partials/_confirm-modal.php), inclus une fois pour toute l'app.
 *
 * Interception au clic sur le bouton submit (pas sur l'événement submit du
 * formulaire) : plus robuste, évite toute subtilité liée à la phase de
 * capture/bulle de l'événement submit natif selon la structure DOM de la page.
 */
(function () {
    'use strict';

    var MODAL_ID = 'global-confirm-modal';
    var pendingForm = null;
    var pendingSubmitter = null;

    document.addEventListener('click', function (e) {
        var submitter = e.target.closest('button[type="submit"], input[type="submit"]');
        if (!submitter) return;

        // data-confirm sur le formulaire, ou sur le bouton lui-même quand il est rattaché à un formulaire
        // situé ailleurs dans le DOM (attribut form="…", cas où un <form> ne peut pas l'englober).
        var form = submitter.closest('form[data-confirm]');
        var message = form ? form.getAttribute('data-confirm') : submitter.getAttribute('data-confirm');
        if (message === null) return;
        if (!form) form = submitter.form;
        if (!form) return;

        e.preventDefault();
        e.stopPropagation();
        pendingForm = form;
        pendingSubmitter = submitter.hasAttribute('data-confirm') ? submitter : null;

        var msgEl = document.getElementById('global-confirm-message');
        if (msgEl) msgEl.textContent = message || '';

        if (window.openModal) window.openModal(MODAL_ID);
    }, true);

    document.addEventListener('click', function (e) {
        if (e.target.id !== 'global-confirm-submit-btn') return;

        if (window.closeModal) window.closeModal(MODAL_ID);
        if (pendingForm) {
            var form = pendingForm;
            var submitter = pendingSubmitter;
            pendingForm = null;
            pendingSubmitter = null;
            // requestSubmit(submitter) conserve le formaction et le nom/valeur du bouton cliqué.
            if (form.requestSubmit) form.requestSubmit(submitter || undefined);
            else form.submit();
        }
    });
})();
