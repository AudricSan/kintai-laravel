/**
 * Assistant de configuration, étape « Bundles » (sélection guidée) :
 * - recherche instantanée dans les cartes (les thèmes vides se replient) ;
 * - compteur de bundles choisis ; au-delà de la place du quota, un message explicite dit combien seront installés
 *   maintenant (les premiers de la liste, par ordre de priorité) et que les autres attendront une licence — la sélection
 *   n'est pas bloquée ;
 * - prérequis (data-requires) : cocher un bundle coche ceux qu'il demande en le disant, décocher un prérequis prévient.
 * Le serveur vérifie et applique le quota de nouveau.
 */
(function () {
    'use strict';

    var form = document.getElementById('setup-bundles-form');
    if (!form) return;

    var room = parseInt(form.getAttribute('data-room') || '', 10);
    var counter = document.getElementById('setup-bundles-count');
    var quotaBox = document.getElementById('setup-bundles-quota');
    var quotaText = document.getElementById('setup-bundles-quota-text');
    var search = document.getElementById('setup-bundles-search');
    var boxes = Array.prototype.slice.call(form.querySelectorAll('input[type="checkbox"][name="bundles[]"]'));
    var live = counter;

    function byValue(slug) {
        return boxes.filter(function (b) { return b.value === slug; })[0] || null;
    }

    function fill(template, vars) {
        return Object.keys(vars).reduce(function (text, key) {
            return text.split(':' + key).join(vars[key]);
        }, template || '');
    }

    function checkedCount() {
        return boxes.filter(function (b) { return b.checked; }).length;
    }

    function sync() {
        var count = checkedCount();
        counter.textContent = fill(form.getAttribute('data-label-selected'), { count: count });
        if (!isNaN(room) && count > room) {
            quotaBox.hidden = false;
            quotaText.textContent = fill(form.getAttribute('data-label-over'), { count: count, room: room, later: count - room });
        } else {
            quotaBox.hidden = true;
        }
    }

    function announce(message) {
        if (live) live.textContent = message;
    }

    boxes.forEach(function (box) {
        box.addEventListener('change', function () {
            var requires = (box.getAttribute('data-requires') || '').split(',').filter(Boolean);
            if (box.checked) {
                var added = [];
                requires.forEach(function (slug) {
                    var dep = byValue(slug);
                    if (dep && !dep.checked) {
                        dep.checked = true;
                        added.push(dep.getAttribute('data-name') || slug);
                    }
                });
                sync();
                if (added.length) {
                    announce(fill(form.getAttribute('data-label-requires'), { name: box.getAttribute('data-name') || box.value, list: added.join(', ') }));
                }
                return;
            }
            // Décocher un prérequis : prévient quels bundles cochés en ont besoin (sans les décocher).
            var dependants = boxes.filter(function (other) {
                return other.checked && (other.getAttribute('data-requires') || '').split(',').indexOf(box.value) !== -1;
            }).map(function (other) { return other.getAttribute('data-name') || other.value; });
            sync();
            if (dependants.length) {
                announce(fill(form.getAttribute('data-label-required-by'), { name: box.getAttribute('data-name') || box.value, list: dependants.join(', ') }));
            }
        });
    });

    if (search) {
        search.addEventListener('input', function () {
            var term = search.value.trim().toLowerCase();
            Array.prototype.forEach.call(form.querySelectorAll('[data-bundle-card]'), function (card) {
                card.hidden = term !== '' && (card.getAttribute('data-search') || '').indexOf(term) === -1;
            });
            Array.prototype.forEach.call(form.querySelectorAll('[data-theme-group]'), function (group) {
                group.hidden = group.querySelectorAll('[data-bundle-card]:not([hidden])').length === 0;
            });
        });
    }

    sync();
}());
