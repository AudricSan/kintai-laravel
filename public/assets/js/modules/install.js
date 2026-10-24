/**
 * Installateur web (public/install.php) : affiche les champs MySQL selon le pilote choisi, et réaffiche la page
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
