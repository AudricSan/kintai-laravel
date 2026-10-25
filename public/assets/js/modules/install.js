/**
 * Installateur web (public/install.php) : affiche les champs de l'installation neuve ou de l'importation selon le
 * mode choisi (ceux qui sont masqués sont désactivés, donc ni requis ni envoyés), les champs MySQL selon le pilote
 * choisi, et réaffiche la page
 * dans la langue sélectionnée (envoi du formulaire avec switch_language : rien n'est installé, les champs saisis
 * sont conservés, sauf les mots de passe).
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-install-form]');
    if (!form) {
        return;
    }

    var mysql = form.querySelector('[data-install-mysql]');
    var drivers = form.querySelectorAll('[data-install-driver]');

    function syncDriver() {
        var checked = form.querySelector('[data-install-driver]:checked');
        if (mysql) {
            mysql.hidden = !checked || checked.value !== 'mysql';
        }
    }

    drivers.forEach(function (radio) {
        radio.addEventListener('change', syncDriver);
    });
    syncDriver();

    var fresh = form.querySelector('[data-install-fresh]');
    var importing = form.querySelector('[data-install-import]');
    var submit = form.querySelector('button[type="submit"]');
    var freshLabel = submit ? submit.textContent : '';

    // Pilote indisponible sur ce serveur : reste désactivé quel que soit le mode.
    form.querySelectorAll('input:disabled').forEach(function (field) {
        field.setAttribute('data-install-locked', '1');
    });

    function toggle(group, visible) {
        if (!group) {
            return;
        }
        group.hidden = !visible;
        group.querySelectorAll('input, select, textarea').forEach(function (field) {
            field.disabled = !visible || field.hasAttribute('data-install-locked');
        });
    }

    function syncMode() {
        var checked = form.querySelector('[data-install-mode]:checked');
        var isImport = !!checked && checked.value === 'import';
        toggle(fresh, !isImport);
        toggle(importing, isImport);
        if (!isImport) {
            syncDriver();
        }
        if (submit) {
            submit.textContent = isImport ? form.getAttribute('data-import-label') : freshLabel;
        }
    }

    form.querySelectorAll('[data-install-mode]').forEach(function (radio) {
        radio.addEventListener('change', syncMode);
    });
    syncMode();

    var language = form.querySelector('[data-install-language]');
    if (language) {
        language.addEventListener('change', function () {
            var flag = document.createElement('input');
            flag.type = 'hidden';
            flag.name = 'switch_language';
            flag.value = '1';
            form.appendChild(flag);
            // submit() et non requestSubmit() : les champs encore vides ne doivent pas bloquer le changement de langue.
            form.submit();
        });
    }
})();
