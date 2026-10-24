/**
 * Composant UserPicker : pastilles des personnes choisies + recherche pour en ajouter. Les cases à cocher du balisage
 * restent la source de vérité (elles partent avec le formulaire) ; ce module les masque et les pilote. Sans lui, la liste
 * de cases s'affiche telle quelle. Délégation sur le document : valable pour tout sélecteur de la page, y compris bundles.
 */
(function () {
    'use strict';

    function boxes(picker) {
        return Array.prototype.slice.call(picker.querySelectorAll('.user-picker__list input[type="checkbox"]'));
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    function renderChips(picker) {
        var zone = picker.querySelector('[data-user-picker-chips]');
        if (!zone) {
            return;
        }
        zone.textContent = '';
        var chosen = boxes(picker).filter(function (box) { return box.checked; });
        if (chosen.length === 0) {
            zone.appendChild(el('span', 'user-picker__placeholder', zone.getAttribute('data-empty') || ''));
            return;
        }
        chosen.forEach(function (box) {
            var chip = el('span', 'user-picker__chip' + (box.getAttribute('data-owner') ? ' user-picker__chip--owner' : ''));
            chip.appendChild(el('span', 'user-picker__chip-name', box.getAttribute('data-name') || ''));
            if (box.getAttribute('data-owner')) {
                chip.appendChild(el('span', 'user-picker__tag', box.getAttribute('data-owner')));
            }
            var remove = el('button', 'user-picker__chip-remove', '✕');
            remove.type = 'button';
            remove.setAttribute('data-user-picker-remove', box.value);
            remove.setAttribute('aria-label', (zone.getAttribute('data-remove') || '') + ' ' + (box.getAttribute('data-name') || ''));
            chip.appendChild(remove);
            zone.appendChild(chip);
        });
    }

    function renderCount(picker) {
        var counter = picker.querySelector('[data-user-picker-count]');
        if (!counter) {
            return;
        }
        var all = boxes(picker);
        var checked = all.filter(function (box) { return box.checked; }).length;
        var label = counter.getAttribute('data-label') || ':count / :total';
        counter.textContent = label.replace(':count', String(checked)).replace(':total', String(all.length));
    }

    function refresh(picker) {
        renderChips(picker);
        renderCount(picker);
    }

    function closeMenu(picker) {
        var menu = picker.querySelector('[data-user-picker-menu]');
        if (menu) {
            menu.hidden = true;
        }
    }

    function openMenu(picker) {
        var menu = picker.querySelector('[data-user-picker-menu]');
        var input = picker.querySelector('[data-user-picker-search]');
        if (!menu || !input) {
            return;
        }
        var query = input.value.trim().toLowerCase();
        menu.textContent = '';
        boxes(picker).filter(function (box) {
            if (box.checked) {
                return false;
            }
            var haystack = ((box.getAttribute('data-name') || '') + ' ' + (box.getAttribute('data-detail') || '')).toLowerCase();
            return query === '' || haystack.indexOf(query) !== -1;
        }).forEach(function (box) {
            var item = el('li', 'user-picker__option');
            item.setAttribute('role', 'option');
            item.setAttribute('data-user-picker-pick', box.value);
            item.appendChild(el('span', 'user-picker__name', box.getAttribute('data-name') || ''));
            if (box.getAttribute('data-owner')) {
                item.appendChild(el('span', 'user-picker__tag', box.getAttribute('data-owner')));
            }
            if (box.getAttribute('data-detail')) {
                item.appendChild(el('span', 'user-picker__detail', box.getAttribute('data-detail')));
            }
            menu.appendChild(item);
        });
        if (menu.children.length === 0) {
            menu.appendChild(el('li', 'user-picker__option user-picker__option--empty', menu.getAttribute('data-no-match') || ''));
        }
        menu.hidden = false;
    }

    function setChecked(picker, value, state) {
        boxes(picker).forEach(function (box) {
            if (box.value === value) {
                box.checked = state;
            }
        });
    }

    function setAll(picker, state) {
        boxes(picker).forEach(function (box) {
            box.checked = state;
        });
        refresh(picker);
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        var picker = target.closest('[data-user-picker]');

        // Un clic hors d'un sélecteur ferme les listes de propositions ouvertes.
        document.querySelectorAll('[data-user-picker]').forEach(function (other) {
            if (other !== picker) {
                closeMenu(other);
            }
        });
        if (!picker) {
            return;
        }

        var remove = target.closest('[data-user-picker-remove]');
        var pick = target.closest('[data-user-picker-pick]');
        if (remove) {
            setChecked(picker, remove.getAttribute('data-user-picker-remove'), false);
            refresh(picker);
        } else if (pick) {
            setChecked(picker, pick.getAttribute('data-user-picker-pick'), true);
            var input = picker.querySelector('[data-user-picker-search]');
            if (input) {
                input.value = '';
                input.focus();
            }
            refresh(picker);
            openMenu(picker);
        } else if (target.closest('[data-user-picker-all]')) {
            setAll(picker, true);
        } else if (target.closest('[data-user-picker-none]')) {
            setAll(picker, false);
        }
    });

    document.addEventListener('focusin', function (event) {
        var target = event.target;
        if (target instanceof Element && target.matches('[data-user-picker-search]')) {
            openMenu(target.closest('[data-user-picker]'));
        }
    });

    document.addEventListener('input', function (event) {
        var target = event.target;
        if (target instanceof Element && target.matches('[data-user-picker-search]')) {
            openMenu(target.closest('[data-user-picker]'));
        }
    });

    document.addEventListener('keydown', function (event) {
        var target = event.target;
        if (!(target instanceof Element) || !target.matches('[data-user-picker-search]')) {
            return;
        }
        var picker = target.closest('[data-user-picker]');
        if (event.key === 'Escape') {
            closeMenu(picker);
        } else if (event.key === 'Enter') {
            // Entrée ajoute la première proposition (et ne soumet pas le formulaire).
            event.preventDefault();
            var first = picker.querySelector('[data-user-picker-menu] [data-user-picker-pick]');
            if (first) {
                setChecked(picker, first.getAttribute('data-user-picker-pick'), true);
                target.value = '';
                refresh(picker);
                openMenu(picker);
            }
        }
    });

    function init() {
        document.querySelectorAll('[data-user-picker]').forEach(function (picker) {
            picker.classList.add('user-picker--enhanced');
            refresh(picker);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
