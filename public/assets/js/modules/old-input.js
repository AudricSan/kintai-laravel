/**
 * Recopie la saisie d'un formulaire refusé par une erreur de validation (bloc JSON #kintai-old-input, écrit par le
 * layout) dans le formulaire d'origine, retrouvé par son adresse d'envoi. Les mots de passe ne sont jamais conservés
 * côté serveur ; les champs cachés et les fichiers ne sont pas touchés.
 */
(function () {
    'use strict';

    var block = document.getElementById('kintai-old-input');
    if (!block) {
        return;
    }
    var old;
    try {
        old = JSON.parse(block.textContent || '');
    } catch (e) {
        return;
    }
    if (!old || !old.fields || !old.action) {
        return;
    }

    function pathOf(url) {
        try {
            return new URL(url, window.location.href).pathname.replace(/\/+$/, '');
        } catch (e) {
            return '';
        }
    }

    var target = pathOf(old.action);
    var form = null;
    document.querySelectorAll('form').forEach(function (f) {
        if (!form && (f.getAttribute('method') || '').toLowerCase() === 'post' && pathOf(f.action) === target) {
            form = f;
        }
    });
    if (!form) {
        return;
    }

    var fields = old.fields;
    Array.prototype.forEach.call(form.elements, function (el) {
        var name = el.name;
        if (!name || el.disabled) {
            return;
        }
        var type = (el.type || '').toLowerCase();
        if (type === 'hidden' || type === 'password' || type === 'file' || type === 'submit' || type === 'button') {
            return;
        }
        var has = Object.prototype.hasOwnProperty.call(fields, name);
        var value = has ? fields[name] : null;

        if (type === 'checkbox' || type === 'radio') {
            // Case non cochée : absente de l'envoi (sauf champ caché associé, alors « 0 »).
            var values = Array.isArray(value) ? value : (value === null ? [] : [value]);
            el.checked = values.indexOf(el.value) !== -1;
            return;
        }
        if (!has) {
            return;
        }
        if (el.tagName === 'SELECT' && el.multiple) {
            var selected = Array.isArray(value) ? value : [value];
            Array.prototype.forEach.call(el.options, function (o) {
                o.selected = selected.indexOf(o.value) !== -1;
            });
            return;
        }
        // Aucun événement « change » : certains formulaires se soumettent seuls au changement (data-submit-on-change).
        el.value = Array.isArray(value) ? (value[0] || '') : value;
    });
})();
