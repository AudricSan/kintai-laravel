/**
 * Vérification en temps réel de la disponibilité d'un champ unique (email,
 * code employé) sur les formulaires utilisateur (création/édition, et modale
 * de création rapide depuis l'import Excel — même partiel `_form-user.php`).
 * Chaque champ concerné porte `data-check-url`/`data-check-param` ; l'élément
 * `[data-check-error]` du même `.form-group` affiche le message et le champ
 * reçoit `is-invalid` (bordure rouge) tant que la valeur saisie est prise.
 */
(function () {
    var DEBOUNCE_MS = 350;

    document.querySelectorAll('.live-check-input').forEach(function (input) {
        var checkUrl   = input.dataset.checkUrl;
        var checkParam = input.dataset.checkParam;
        if (!checkUrl || !checkParam) return;

        var group    = input.closest('.form-group');
        var errorEl  = group ? group.querySelector('[data-check-error]') : null;
        var original = input.dataset.originalValue || '';
        var timer    = null;
        var requestId = 0;

        function setInvalid(invalid) {
            input.classList.toggle('is-invalid', invalid);
            if (errorEl) errorEl.hidden = !invalid;
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            setInvalid(false);

            var value = input.value.trim();
            if (value === '' || value === original) return;

            var thisRequestId = ++requestId;
            timer = setTimeout(function () {
                var url = checkUrl + '?' + checkParam + '=' + encodeURIComponent(value)
                    + '&exclude_id=' + encodeURIComponent(input.dataset.excludeId || '0');

                fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (thisRequestId !== requestId) return; // réponse obsolète (saisie plus récente entre-temps)
                        setInvalid(data.available === false);
                    })
                    .catch(function () { /* réseau indisponible : ne bloque pas la saisie */ });
            }, DEBOUNCE_MS);
        });
    });
})();
