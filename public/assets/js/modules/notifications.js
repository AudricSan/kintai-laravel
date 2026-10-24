/**
 * Notifications : dropdown cloche, toasts et polling.
 *
 * - Curseur = id de la dernière notification vue (attribué par la base), jamais l'horloge du navigateur : rien
 *   n'est perdu ni affiché deux fois. Il est mémorisé dans le navigateur (par utilisateur) pour afficher, à la
 *   page suivante, les toasts des notifications arrivées entre-temps.
 * - Un seul onglet visible interroge le serveur (verrou Web Locks) et diffuse le résultat aux autres onglets
 *   (BroadcastChannel) : un seul jeu de toasts, une seule requête toutes les 15 s, aucune quand tout est caché.
 * - Le panneau de la cloche (liste + actions) est rendu par le serveur (partiel _notif_panel.php) et remplacé
 *   tel quel ; « Tout marquer lu » / « Tout supprimer » passent en AJAX, sans quitter la page.
 */
(function () {
    'use strict';

    var meta = document.getElementById('notif-meta');
    if (!meta) return;

    var POLL_INTERVAL = 15000;
    var MAX_TOASTS    = 3;

    var pollUrl  = meta.dataset.pollUrl || '';
    var userId   = meta.dataset.userId || '0';
    var pageLast = parseInt(meta.dataset.lastId || '0', 10) || 0;
    var unread   = parseInt(meta.dataset.unread || '0', 10) || 0;
    var STORAGE  = 'kintai-notif-last-id-' + userId;
    var channel  = 'BroadcastChannel' in window ? new BroadcastChannel('kintai-notifications-' + userId) : null;

    // ── Curseur ──────────────────────────────────────────────────────────────

    function readStoredCursor() {
        try {
            var v = parseInt(localStorage.getItem(STORAGE) || '', 10);
            return isNaN(v) ? null : v;
        } catch (e) { return null; }
    }

    function storeCursor(id) {
        try { localStorage.setItem(STORAGE, String(id)); } catch (e) {}
    }

    // Dernier id vu sur une page précédente : s'il est plus ancien que celui de cette page, le premier poll
    // affichera les toasts des notifications arrivées entre-temps. Plus récent (notifications supprimées depuis)
    // ou absent (première visite) : on part de cette page, sans toast.
    var stored = readStoredCursor();
    var cursor = stored !== null && stored <= pageLast ? stored : pageLast;
    if (stored === null) storeCursor(cursor);

    // ── Helpers ──────────────────────────────────────────────────────────────

    function escapeHtml(str) {
        var d = document.createElement('div');
        d.textContent = str || '';
        return d.innerHTML;
    }

    // ── Toasts ───────────────────────────────────────────────────────────────

    var toastContainer = document.getElementById('toast-container');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'toast-container';
        toastContainer.className = 'toast-container';
        document.body.appendChild(toastContainer);
    }

    function showToast(title, body, link) {
        var toast = document.createElement(link ? 'a' : 'div');
        toast.className = 'toast';
        if (link) toast.href = link;
        toast.innerHTML =
            '<div>' +
                '<div class="toast__title">' + escapeHtml(title) + '</div>' +
                (body ? '<div class="toast__body">' + escapeHtml(body) + '</div>' : '') +
            '</div>';
        toastContainer.appendChild(toast);

        setTimeout(function () {
            toast.classList.add('toast--fading');
            toast.addEventListener('animationend', function () {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            });
        }, 4000);
    }

    // ── Badge et panneau ─────────────────────────────────────────────────────

    function updateBadge(count, label) {
        var toggle = document.getElementById('notif-toggle');
        if (!toggle) return;

        var badge = toggle.querySelector('.notif-dropdown__badge');
        if (count > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'notif-dropdown__badge';
                toggle.appendChild(badge);
            }
            badge.textContent = label || (count > 99 ? '99+' : String(count));
        } else if (badge) {
            badge.parentNode.removeChild(badge);
        }
    }

    // Applique un état reçu du serveur (directement ou d'un autre onglet). Seul l'onglet qui a fait la requête
    // affiche les toasts.
    function applyState(state, withToasts) {
        if (typeof state.last_id === 'number' && state.last_id > cursor) {
            cursor = state.last_id;
            storeCursor(cursor);
        }
        if (typeof state.unread_count === 'number') {
            unread = state.unread_count;
            updateBadge(unread, state.badge);
        }
        if (typeof state.panel_html === 'string') {
            var panel = document.getElementById('notif-panel');
            if (panel) panel.innerHTML = state.panel_html;
        }
        if (withToasts && state.notifications && state.notifications.length) {
            state.notifications.slice(-MAX_TOASTS).forEach(function (n) {
                showToast(n.title, n.body, n.link);
            });
        }
    }

    if (channel) {
        channel.onmessage = function (e) {
            if (e.data && e.data.kind === 'state') applyState(e.data.state, false);
        };
    }

    // ── Polling ──────────────────────────────────────────────────────────────

    var inFlight     = false;
    var pendingForce = false;

    // force : redemande le panneau même si rien n'a changé côté compteur (après une action locale). Une demande
    // forcée pendant un poll en cours est rejouée juste après, pour ne pas perdre le rafraîchissement.
    function poll(force) {
        if (!pollUrl) return Promise.resolve();
        if (inFlight) {
            if (force) pendingForce = true;
            return Promise.resolve();
        }
        inFlight = true;

        var url = pollUrl + '?after_id=' + encodeURIComponent(cursor) + '&count=' + (force ? -1 : unread);

        return fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) return;
                applyState(data, true);
                if (channel) {
                    channel.postMessage({ kind: 'state', state: {
                        last_id: data.last_id, unread_count: data.unread_count, badge: data.badge, panel_html: data.panel_html,
                    } });
                }
            })
            .catch(function () {}) // réseau indisponible : on réessaiera au prochain tour
            .then(function () {
                inFlight = false;
                if (pendingForce) {
                    pendingForce = false;
                    return poll(true);
                }
            });
    }

    var timer = null;

    function startPolling() {
        if (timer !== null) return;
        poll(false);
        timer = setInterval(function () { poll(false); }, POLL_INTERVAL);
    }

    function stopPolling() {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    }

    // Élection d'un onglet unique : le verrou n'est demandé que par un onglet visible, et rendu dès qu'il est
    // caché, pour qu'un autre onglet visible prenne le relais.
    // Web Locks n'existe qu'en contexte sécurisé (HTTPS ou localhost) : une instance servie en HTTP simple
    // (intranet) retombe sur un bail dans localStorage, renouvelé par l'onglet élu et repris par un autre
    // onglet visible s'il n'est plus renouvelé (onglet fermé, caché ou figé).
    var hasLocks    = !!(navigator.locks && navigator.locks.request);
    var releaseLock = null;
    var lockAbort   = null;

    var LEASE_KEY   = 'kintai-notif-leader-' + userId;
    var LEASE_TTL   = 20000;
    var LEASE_TICK  = 5000;
    var tabId       = Math.random().toString(36).slice(2) + Date.now().toString(36);
    var leaseTimer  = null;

    function readLease() {
        try { return JSON.parse(localStorage.getItem(LEASE_KEY) || 'null'); } catch (e) { return null; }
    }

    function leaseTick() {
        var lease = readLease();
        var now   = Date.now();
        if (!lease || lease.id === tabId || now - (lease.ts || 0) > LEASE_TTL) {
            try { localStorage.setItem(LEASE_KEY, JSON.stringify({ id: tabId, ts: now })); } catch (e) {
                startPolling(); // stockage indisponible : chaque onglet visible interroge lui-même le serveur
                return;
            }
        }
        // Relu après écriture : si deux onglets ont écrit en même temps, seul le dernier garde le bail.
        var current = readLease();
        if (current && current.id === tabId) startPolling();
        else stopPolling();
    }

    function becomeCandidate() {
        if (!hasLocks) {
            if (leaseTimer === null) {
                leaseTick();
                leaseTimer = setInterval(leaseTick, LEASE_TICK);
            }
            return;
        }
        if (releaseLock || lockAbort) return;

        lockAbort = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var options = lockAbort ? { signal: lockAbort.signal } : {};

        navigator.locks.request('kintai-notif-poll-' + userId, options, function () {
            lockAbort = null;
            return new Promise(function (resolve) {
                releaseLock = resolve;
                startPolling();
            });
        }).catch(function () {
            lockAbort = null; // demande annulée (onglet caché avant d'obtenir le verrou)
        });
    }

    function resign() {
        stopPolling();
        if (leaseTimer !== null) {
            clearInterval(leaseTimer);
            leaseTimer = null;
            var lease = readLease();
            if (lease && lease.id === tabId) {
                try { localStorage.removeItem(LEASE_KEY); } catch (e) {}
            }
        }
        if (lockAbort) {
            lockAbort.abort();
            lockAbort = null;
        }
        if (releaseLock) {
            releaseLock();
            releaseLock = null;
        }
    }

    if (pollUrl) {
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') becomeCandidate();
            else resign();
        });
        // Onglet fermé ou navigation : rendre la main tout de suite plutôt qu'attendre l'expiration du bail.
        window.addEventListener('pagehide', resign);
        window.addEventListener('pageshow', function (e) {
            if (e.persisted && document.visibilityState === 'visible') becomeCandidate(); // retour par le cache arrière
        });
        if (document.visibilityState === 'visible') becomeCandidate();
    }

    // ── Dropdown ─────────────────────────────────────────────────────────────

    var toggle   = document.getElementById('notif-toggle');
    var dropdown = document.getElementById('notif-dropdown');

    if (toggle && dropdown) {
        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            document.querySelectorAll('.topbar-nav-group.is-open').forEach(function (g) { g.classList.remove('is-open'); });
            document.getElementById('user-dropdown')?.classList.remove('user-dropdown--open');
            dropdown.classList.toggle('notif-dropdown--open');
        });

        document.addEventListener('click', function (e) {
            if (!dropdown.contains(e.target)) {
                dropdown.classList.remove('notif-dropdown--open');
            }
        });

        // Actions du panneau en AJAX (après la modale de confirmation pour « Tout supprimer », qui relance le
        // submit via requestSubmit) : le panneau est ensuite rafraîchi et l'état diffusé aux autres onglets.
        // Le panneau étant remplacé à chaque rafraîchissement, l'écoute est déléguée.
        dropdown.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form.matches || !form.matches('form[data-notif-ajax]')) return;
            e.preventDefault();

            fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(form),
            })
                .then(function (r) {
                    if (!r.ok) throw new Error('http_' + r.status);
                    return poll(true);
                })
                .catch(function () { form.submit(); }); // repli : envoi classique du formulaire
        });
    }

})();
