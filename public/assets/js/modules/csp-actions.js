/**
 * Actions déclaratives — remplace les attributs onclick=/onchange=/oninput= des vues.
 *
 * La Content-Security-Policy interdit tout script inline sans nonce, attributs d'événements compris
 * (SecurityHeadersMiddleware) : un onclick="…" injecté par une faille XSS ne s'exécuterait pas, mais
 * ceux écrits par les vues non plus. Les vues posent donc des attributs data-* et ce module, chargé
 * une fois par layout, les exécute par délégation d'événements.
 *
 * Attributs reconnus :
 *   data-on-click="fn"      appelle la fonction globale fn au clic
 *   data-on-change="fn"     idem au changement de valeur
 *   data-on-input="fn"      idem à la saisie
 *   data-args='["a", 2]'    arguments JSON de fn ; les jetons "@this", "@value" et "@checked" sont
 *                           remplacés par l'élément, sa valeur et son état coché
 *   data-submit-on-change   soumet le formulaire de l'élément quand sa valeur change
 *   data-submit-form="id"   soumet le formulaire #id quand la valeur change
 *   data-goto="url"         navigue vers url au clic
 *   data-stop-propagation   le clic ne remonte pas aux ancêtres (dont data-goto/data-on-click)
 *
 * Fonctions intégrées (data-on-click|change|input="@nom") :
 *   @print, @close, @select, @removeParent  window.print(), window.close(), sélection du champ, retrait du parent
 *   @copy                                 copie data-copy-url dans le presse-papiers (✓ sur le bouton)
 *   @cssVar                               data-target="id" data-var="--x" : pose la valeur du champ dans cette variable CSS
 *   @toggleRows                           data-target=".sélecteur" : affiche/masque les lignes selon l'état coché
 *   @setFormAction                        data-target="id" data-action-template="/x/{value}/y" : change l'action du formulaire
 *   @toggleChecks                         data-target=".sélecteur" : coche tout, ou décoche tout si tout est déjà coché
 *
 * Seules les fonctions globales écrites par l'application sont appelables, jamais une chaîne évaluée : ce
 * module n'utilise ni eval ni new Function, sans quoi il rouvrirait la brèche que la CSP referme.
 *
 * Garde-fous contre le détournement (un attribut data-* peut être injecté même quand un <script> ne le peut
 * pas, et ne doit pas redevenir un moyen d'exécuter du code) :
 *   - les fonctions natives du navigateur sont refusées (data-on-click="eval", "setTimeout", "open"…) ;
 *   - data-goto refuse tout schéma autre que http(s) (javascript:, data:…).
 */
(function () {
    'use strict';

    var NAME_RE = /^[A-Za-z_$][\w$]*$/;

    // Fonction écrite par l'application (et non native) : eval, setTimeout, open… affichent [native code].
    function isAppFunction(name) {
        if (!Object.prototype.hasOwnProperty.call(window, name)) return false;
        var fn = window[name];
        if (typeof fn !== 'function') return false;
        return !/\[native code\]/.test(Function.prototype.toString.call(fn));
    }

    // Adresse de navigation sûre : http(s) uniquement.
    function isSafeUrl(value) {
        try {
            var url = new URL(value, window.location.href);
            return url.protocol === 'http:' || url.protocol === 'https:';
        } catch (e) {
            return false;
        }
    }

    var builtins = {
        print: function () { window.print(); },
        close: function () { window.close(); },
        select: function (el) { if (el.select) el.select(); },
        removeParent: function (el) { if (el.parentElement) el.parentElement.remove(); },
        // Copie data-copy-url dans le presse-papiers et le confirme par un ✓ sur le bouton.
        copy: function (el) {
            if (!navigator.clipboard) return;
            navigator.clipboard.writeText(el.dataset.copyUrl || '');
            el.textContent = '✓';
        },
        cssVar: function (el) {
            var target = document.getElementById(el.dataset.target || '');
            if (target && el.dataset.var) target.style.setProperty(el.dataset.var, el.value);
        },
        toggleRows: function (el) {
            if (!el.dataset.target) return;
            document.querySelectorAll(el.dataset.target).forEach(function (row) {
                row.style.display = el.checked ? '' : 'none';
            });
        },
        toggleChecks: function (el) {
            if (!el.dataset.target) return;
            var boxes = Array.prototype.filter.call(
                document.querySelectorAll(el.dataset.target),
                function (box) { return !box.disabled; }
            );
            var allChecked = boxes.every(function (box) { return box.checked; });
            boxes.forEach(function (box) { box.checked = !allChecked; });
        },
        setFormAction: function (el) {
            var form = document.getElementById(el.dataset.target || '');
            var template = el.dataset.actionTemplate;
            if (form && template) form.action = template.replace('{value}', encodeURIComponent(el.value));
        }
    };

    function parseArgs(el) {
        var raw = el.getAttribute('data-args');
        if (!raw) return [];
        var list;
        try { list = JSON.parse(raw); } catch (e) { return []; }
        if (!Array.isArray(list)) return [];
        return list.map(function (arg) {
            if (arg === '@this') return el;
            if (arg === '@value') return el.value;
            if (arg === '@checked') return el.checked;
            return arg;
        });
    }

    function run(el, attr) {
        var name = el.getAttribute(attr);
        if (!name) return;
        if (name.charAt(0) === '@') {
            var builtin = builtins[name.slice(1)];
            if (builtin) builtin(el);
            return;
        }
        if (!NAME_RE.test(name) || !isAppFunction(name)) return;
        window[name].apply(el, parseArgs(el));
    }

    document.addEventListener('click', function (e) {
        // On remonte de la cible vers la racine, comme le ferait la propagation native : un élément
        // data-stop-propagation arrête la remontée, exactement comme l'ancien event.stopPropagation().
        var node = e.target instanceof Element ? e.target : null;
        for (; node; node = node.parentElement) {
            if (node.hasAttribute('data-on-click')) {
                // Un lien <a href="#"> qui déclenche une action ne doit pas naviguer.
                if (node.tagName === 'A') e.preventDefault();
                run(node, 'data-on-click');
            }
            if (node.hasAttribute('data-goto')) {
                var target = node.getAttribute('data-goto');
                if (isSafeUrl(target)) window.location.href = target;
                return;
            }
            if (node.hasAttribute('data-stop-propagation')) return;
        }
    });

    document.addEventListener('change', function (e) {
        var el = e.target instanceof Element ? e.target : null;
        if (!el) return;
        var actionEl = el.closest('[data-on-change], [data-submit-on-change], [data-submit-form]');
        if (!actionEl) return;

        if (actionEl.hasAttribute('data-on-change')) run(actionEl, 'data-on-change');
        if (actionEl.hasAttribute('data-submit-on-change') && actionEl.form) actionEl.form.submit();
        if (actionEl.hasAttribute('data-submit-form')) {
            var form = document.getElementById(actionEl.getAttribute('data-submit-form'));
            if (form) form.submit();
        }
    });

    document.addEventListener('input', function (e) {
        var el = e.target instanceof Element ? e.target.closest('[data-on-input]') : null;
        if (el) run(el, 'data-on-input');
    });
})();
