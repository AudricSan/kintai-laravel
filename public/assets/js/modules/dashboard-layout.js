/**
 * Tableaux de bord : widgets déplaçables (partiel _dashboard-grid.php).
 *
 * Grille de 12 colonnes en mode « flux » : largeur (dash-w-N) et hauteur minimale (dash-h-N) sont des classes.
 *
 * Le bouton « Personnaliser » appelle window.kintaiDashLayout.setEditing() : en mode édition, chaque widget affiche une
 * barre d'outils — poignée de glisser-déposer, flèches ↑↓ (clavier, tactile), largeur −/+ (1 à 12 colonnes, dans les
 * bornes du widget), hauteur −/+ (1 à 6 lignes) et retrait. Hors édition, chaque widget se replie d'un clic (▾).
 * Chaque changement est enregistré aussitôt, en arrière-plan (POST JSON vers data-save-url) ; « Réinitialiser l'ordre »
 * revient à la disposition par défaut et recharge la page. La densité (compacte / confortable) a son propre bouton.
 *
 * Widgets affichés (POST JSON vers data-widgets-url) : le bouton ✕ d'un widget le retire de la page et le remet dans
 * la liste « Ajouter un widget » ; ajouter un widget le place en fin de tableau de bord, sans rechargement : la page
 * est relue en arrière-plan (le contenu du widget est rendu côté serveur) et seul le nouveau widget est inséré, avec ses
 * dépendances (styles dynamiques, données de graphiques, scripts). Si le widget n'est pas trouvé dans la page relue,
 * la page est rechargée, le mode édition restant ouvert et la page positionnée sur le nouveau widget.
 */
(function () {
    'use strict';

    var grid = document.querySelector('[data-dash-grid]');
    if (!grid) return;

    var saveUrl = grid.getAttribute('data-save-url');
    var widgetsUrl = grid.getAttribute('data-widgets-url');
    var EDIT_FLAG = 'kintai.dashEditing';
    var saveTimer = null;
    var dragged = null;

    function items() {
        return Array.prototype.slice.call(grid.querySelectorAll(':scope > [data-widget-key]'));
    }

    function save() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(function () {
            var order = [];
            var widths = {};
            var heights = {};
            var collapsed = [];
            items().forEach(function (item) {
                var key = item.getAttribute('data-widget-key');
                order.push(key);
                widths[key] = parseInt(item.getAttribute('data-widget-width'), 10) || 12;
                heights[key] = parseInt(item.getAttribute('data-widget-height'), 10) || 1;
                if (item.getAttribute('data-widget-collapsed') === '1') collapsed.push(key);
            });
            post({ order: order, widths: widths, heights: heights, collapsed: collapsed, density: grid.getAttribute('data-density') || 'comfortable' });
        }, 300);
    }

    // Renvoie une promesse résolue avec la réponse, ou avec null en cas d'échec (signalé sur la grille).
    function post(body, url) {
        return fetch(url || saveUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (res) {
            if (!res.ok) throw new Error(String(res.status));
            return res;
        }).catch(function () {
            grid.classList.add('dash-grid--save-error');
            grid.setAttribute('title', grid.getAttribute('data-label-error') || '');
            return null;
        });
    }

    function addMenu() {
        return document.querySelector('[data-dash-add-menu]');
    }

    function refreshAddMenu() {
        var menu = addMenu();
        if (!menu) return;
        var empty = menu.querySelector('[data-dash-add-empty]');
        if (empty) empty.hidden = menu.querySelector('[data-dash-add]') !== null;
    }

    // Retrait : le widget quitte la page et revient dans la liste « Ajouter un widget ».
    function removeWidget(item) {
        var key = item.getAttribute('data-widget-key');
        post({ remove: key }, widgetsUrl).then(function (res) {
            if (!res) return;
            var menu = addMenu();
            var list = menu && menu.querySelector('.dash-add-widget__menu');
            if (list) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'dash-add-widget__item';
                btn.setAttribute('role', 'menuitem');
                btn.setAttribute('data-dash-add', key);
                btn.textContent = item.getAttribute('data-widget-label') || key;
                list.insertBefore(btn, list.querySelector('[data-dash-add-empty]'));
            }
            item.remove();
            refreshAddMenu();
            window.dispatchEvent(new Event('resize'));
        });
    }

    // Nonce CSP de la page courante (lu sur un élément existant : l'attribut est masqué, la propriété reste lisible).
    function pageNonce(selector) {
        var el = document.querySelector(selector);
        return el ? (el.nonce || el.getAttribute('nonce') || '') : '';
    }

    // Styles dynamiques (style_class()) de la page relue absents de la page courante.
    function importStyles(doc) {
        var known = Array.prototype.map.call(document.querySelectorAll('style'), function (s) { return s.textContent; });
        var nonce = pageNonce('style[nonce]') || pageNonce('script[nonce]');
        Array.prototype.forEach.call(doc.querySelectorAll('style'), function (s) {
            if (known.indexOf(s.textContent) !== -1) return;
            var style = document.createElement('style');
            if (nonce) style.nonce = nonce;
            style.textContent = s.textContent;
            document.head.appendChild(style);
        });
    }

    // Blocs de données JSON (ex. #kintai-stats-data) : remplacés ou ajoutés. Renvoie true si l'un d'eux a changé.
    function importData(doc) {
        var changed = false;
        Array.prototype.forEach.call(doc.querySelectorAll('script[type="application/json"][id]'), function (s) {
            var current = document.getElementById(s.id);
            if (current && current.textContent === s.textContent) return;
            var data = document.createElement('script');
            data.type = 'application/json';
            data.id = s.id;
            data.textContent = s.textContent;
            if (current) current.replaceWith(data); else document.body.appendChild(data);
            changed = true;
        });
        return changed;
    }

    // Recrée un script (ceux insérés depuis un document analysé ne s'exécutent pas), avec le nonce de la page.
    function cloneScript(s) {
        var script = document.createElement('script');
        Array.prototype.forEach.call(s.attributes, function (a) {
            if (a.name !== 'nonce') script.setAttribute(a.name, a.value);
        });
        var nonce = pageNonce('script[nonce]');
        if (nonce) script.nonce = nonce;
        script.textContent = s.textContent;
        return script;
    }

    // Scripts externes de la page relue pas encore chargés ici (ex. stats-charts.js, dashboard.js).
    function importScripts(doc) {
        var loaded = Array.prototype.map.call(document.querySelectorAll('script[src]'), function (s) { return s.src; });
        Array.prototype.forEach.call(doc.querySelectorAll('script[src]'), function (s) {
            var src = new URL(s.getAttribute('src'), window.location.href).href;
            if (loaded.indexOf(src) === -1) document.body.appendChild(cloneScript(s));
        });
    }

    // Insère le widget rendu dans la page relue ; false s'il n'y figure pas.
    function insertWidget(doc, key) {
        var section = doc.getElementById('dash-widget-' + key);
        if (!section || !section.hasAttribute('data-widget-key')) return false;

        importStyles(doc);
        var dataChanged = importData(doc);
        var item = document.importNode(section, true);
        Array.prototype.forEach.call(item.querySelectorAll('script'), function (s) {
            s.replaceWith(s.type === 'application/json' ? s : cloneScript(s));
        });
        grid.appendChild(item);
        setSizeButtons(item);
        importScripts(doc);
        if (dataChanged && window.kintaiStatsCharts) window.kintaiStatsCharts.refresh();
        window.dispatchEvent(new Event('resize'));
        item.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        return true;
    }

    function reloadOn(key) {
        try { sessionStorage.setItem(EDIT_FLAG, '1'); } catch (err) { /* stockage indisponible */ }
        window.location.hash = 'dash-widget-' + key;
        window.location.reload();
    }

    function addWidget(btn) {
        var key = btn.getAttribute('data-dash-add');
        btn.disabled = true;
        post({ add: key }, widgetsUrl).then(function (res) {
            if (!res) { btn.disabled = false; return; }
            var url = window.location.href.split('#')[0];
            return fetch(url, { headers: { 'Accept': 'text/html' }, cache: 'no-store', credentials: 'same-origin' })
                .then(function (page) {
                    if (!page.ok) throw new Error(String(page.status));
                    return page.text();
                })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    if (!insertWidget(doc, key)) { reloadOn(key); return; }
                    btn.remove();
                    refreshAddMenu();
                    var menu = addMenu();
                    if (menu) menu.open = false;
                })
                .catch(function () { reloadOn(key); });
        });
    }

    function setEditing(on) {
        grid.classList.toggle('dash-grid--editing', !!on);
        items().forEach(function (item) { item.draggable = false; });
    }

    // Classe dash-w-N / dash-h-N, attribut, affichage de la valeur et boutons −/+ désactivés aux bornes.
    function setSize(item, dimension, value) {
        var isWidth = dimension === 'width';
        var min = isWidth ? (parseInt(item.getAttribute('data-widget-min'), 10) || 1) : 1;
        var max = isWidth ? (parseInt(item.getAttribute('data-widget-max'), 10) || 12) : 6;
        value = Math.max(min, Math.min(max, value));
        var prefix = isWidth ? 'dash-w-' : 'dash-h-';
        Array.prototype.slice.call(item.classList).forEach(function (c) {
            if (c.indexOf(prefix) === 0) item.classList.remove(c);
        });
        item.classList.add(prefix + value);
        item.setAttribute(isWidth ? 'data-widget-width' : 'data-widget-height', String(value));
        var label = item.querySelector('[data-dash-size-value="' + dimension + '"]');
        if (label) label.textContent = isWidth ? value + '/12' : value + '\u2195';
        Array.prototype.forEach.call(item.querySelectorAll('[data-dash-size="' + dimension + '"]'), function (b) {
            var step = parseInt(b.getAttribute('data-step'), 10);
            b.disabled = (step < 0 && value <= min) || (step > 0 && value >= max);
        });
        // Les graphiques (Chart.js) se recalent sur leur nouveau conteneur.
        window.dispatchEvent(new Event('resize'));
    }

    function setCollapsed(item, collapsed) {
        item.classList.toggle('dash-grid__item--collapsed', collapsed);
        item.setAttribute('data-widget-collapsed', collapsed ? '1' : '0');
        var btn = item.querySelector('[data-dash-fold]');
        if (btn) {
            var text = btn.getAttribute(collapsed ? 'data-label-expand' : 'data-label-collapse') || '';
            btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            btn.setAttribute('title', text);
            btn.setAttribute('aria-label', text + ' — ' + (item.getAttribute('data-widget-label') || ''));
        }
        window.dispatchEvent(new Event('resize'));
    }

    function setDensity(density) {
        grid.classList.toggle('dash-grid--compact', density === 'compact');
        grid.setAttribute('data-density', density);
        var btn = document.querySelector('[data-dash-density]');
        if (btn) {
            btn.setAttribute('aria-pressed', density === 'compact' ? 'true' : 'false');
        }
        window.dispatchEvent(new Event('resize'));
    }

    // État initial des boutons −/+ (bornes) et du bouton de densité.
    items().forEach(function (item) {
        setSizeButtons(item);
    });
    var densityButton = document.querySelector('[data-dash-density]');
    if (densityButton) {
        densityButton.setAttribute('aria-pressed', grid.getAttribute('data-density') === 'compact' ? 'true' : 'false');
    }

    function setSizeButtons(item) {
        ['width', 'height'].forEach(function (dimension) {
            var current = parseInt(item.getAttribute(dimension === 'width' ? 'data-widget-width' : 'data-widget-height'), 10) || 1;
            var min = dimension === 'width' ? (parseInt(item.getAttribute('data-widget-min'), 10) || 1) : 1;
            var max = dimension === 'width' ? (parseInt(item.getAttribute('data-widget-max'), 10) || 12) : 6;
            Array.prototype.forEach.call(item.querySelectorAll('[data-dash-size="' + dimension + '"]'), function (b) {
                var step = parseInt(b.getAttribute('data-step'), 10);
                b.disabled = (step < 0 && current <= min) || (step > 0 && current >= max);
            });
        });
    }

    // ── Flèches, taille et repli ─────────────────────────────────────────────
    grid.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-dash-move], [data-dash-size], [data-dash-remove], [data-dash-fold]');
        if (!btn || !grid.contains(btn)) return;
        var item = btn.closest('[data-widget-key]');
        if (!item) return;

        if (btn.hasAttribute('data-dash-remove')) {
            removeWidget(item);
            return;
        }
        if (btn.hasAttribute('data-dash-fold')) {
            setCollapsed(item, item.getAttribute('data-widget-collapsed') !== '1');
            save();
            return;
        }
        if (btn.hasAttribute('data-dash-size')) {
            var dimension = btn.getAttribute('data-dash-size');
            var key = dimension === 'width' ? 'data-widget-width' : 'data-widget-height';
            setSize(item, dimension, (parseInt(item.getAttribute(key), 10) || 1) + parseInt(btn.getAttribute('data-step'), 10));
        } else if (btn.getAttribute('data-dash-move') === 'up') {
            var prev = item.previousElementSibling;
            if (!prev) return;
            grid.insertBefore(item, prev);
        } else {
            var next = item.nextElementSibling;
            if (!next) return;
            grid.insertBefore(next, item);
        }
        btn.focus();
        save();
    });

    // ── Glisser-déposer (souris) : seul l'appui sur la poignée rend le widget déplaçable ─────────────────────────
    grid.addEventListener('pointerdown', function (e) {
        var handle = e.target.closest('[data-dash-handle]');
        if (!handle || !grid.classList.contains('dash-grid--editing')) return;
        var item = handle.closest('[data-widget-key]');
        if (item) item.draggable = true;
    });

    grid.addEventListener('dragstart', function (e) {
        var item = e.target.closest && e.target.closest('[data-widget-key]');
        if (!item || !item.draggable) return;
        dragged = item;
        item.classList.add('dash-grid__item--dragging');
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', item.getAttribute('data-widget-key')); } catch (err) { /* IE */ }
    });

    grid.addEventListener('dragover', function (e) {
        if (!dragged) return;
        e.preventDefault();
        var target = e.target.closest('[data-widget-key]');
        if (!target || target === dragged) return;
        var rect = target.getBoundingClientRect();
        // Widget d'au plus 6 colonnes : avant/après selon la moitié gauche/droite ; sinon selon la moitié haute/basse.
        var after = (parseInt(target.getAttribute('data-widget-width'), 10) || 12) <= 6
            ? e.clientX > rect.left + rect.width / 2
            : e.clientY > rect.top + rect.height / 2;
        grid.insertBefore(dragged, after ? target.nextElementSibling : target);
    });

    grid.addEventListener('drop', function (e) {
        if (dragged) e.preventDefault();
    });

    grid.addEventListener('dragend', function () {
        if (!dragged) return;
        dragged.classList.remove('dash-grid__item--dragging');
        dragged.draggable = false;
        dragged = null;
        save();
    });

    // ── Panneau « Personnaliser » : réinitialisation et ajout d'un widget ──────────────────────────────────────
    document.addEventListener('click', function (e) {
        var add = e.target.closest('[data-dash-add]');
        if (add) {
            addWidget(add);
            return;
        }
        var density = e.target.closest('[data-dash-density]');
        if (density) {
            setDensity(grid.getAttribute('data-density') === 'compact' ? 'comfortable' : 'compact');
            save();
            return;
        }
        var btn = e.target.closest('[data-dash-reset]');
        if (!btn) return;
        btn.disabled = true;
        post({ reset: true }).then(function () { window.location.reload(); });
    });

    window.kintaiDashLayout = { setEditing: setEditing };

    // Après l'ajout d'un widget : rouvrir le panneau « Personnaliser » (et donc le mode édition).
    var reopen = false;
    try {
        reopen = sessionStorage.getItem(EDIT_FLAG) === '1';
        sessionStorage.removeItem(EDIT_FLAG);
    } catch (err) { /* stockage indisponible */ }
    if (reopen) {
        var panel = document.querySelector('.dash-customize-panel.hidden');
        if (panel) {
            panel.classList.remove('hidden');
            setEditing(true);
        }
    }
}());
