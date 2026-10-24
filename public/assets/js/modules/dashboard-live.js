/**
 * Tableaux de bord : rafraîchissement automatique des widgets et mode mural (partiel _dashboard-grid.php).
 *
 * Rafraîchissement : chaque widget peut déclarer un intervalle (data-widget-refresh, en secondes ; 0 = jamais). Un seul
 * minuteur vérifie toutes les 15 s quels widgets sont échus, relit alors la page en arrière-plan (les droits, les magasins
 * visibles et les données sont donc exactement ceux du chargement initial) et ne remplace que le contenu des widgets
 * dont le HTML a changé. Rien ne se passe onglet caché, en mode édition, pour un widget replié ni quand le focus est dans
 * le widget (pas de perte de saisie). « Actualiser » force un passage. Un échec est silencieux (le contenu reste), sauf
 * une session expirée : un message clair remplace alors l'écran figé.
 *
 * Mode mural (bouton « Activer le mode mural ») : plein écran (API Fullscreen), barre latérale et en-tête masquées
 * (classe dash-wall sur <html>), écran maintenu allumé quand le navigateur le permet (Screen Wake Lock). L'activation
 * demande de choisir un mot de passe (boîte data-wall-lock) ; la sortie, par le bouton « Quitter le mode mural », Échap
 * ou la fin du plein écran, le redemande. Il reste derrière la session de l'utilisateur.
 *
 * Contournements fermés : le verrou (empreinte salée du mot de passe, jamais le mot de passe) survit à un rechargement
 * de l'onglet (sessionStorage), si bien que F5 ne sort plus du mode mural ; les raccourcis de rechargement, de
 * navigation et d'outils de développement sont interceptés tant que le mode est actif ; une confirmation est demandée avant
 * de quitter la page ; les widgets sont inertes (aucun clic, seul « Quitter » répond) ; Échap est verrouillé en plein écran quand le navigateur le permet (Keyboard Lock).
 */
(function () {
    'use strict';

    var grid = document.querySelector('[data-dash-grid]');
    if (!grid) return;

    var TICK_MS = 15000;
    var lastRun = {};
    var running = false;
    var expired = false;
    var lastUpdate = Date.now();
    var wakeLock = null;

    var labelEl = document.querySelector('[data-dash-updated]');
    var refreshBtn = document.querySelector('[data-dash-refresh-now]');
    var wallBtn = document.querySelector('[data-dash-wall]');
    var wallExit = document.querySelector('[data-dash-wall-exit]');
    // Le mode mural est réservé aux écrans de bureau et de tablette : même seuil que le CSS (téléphone ≤ 768 px).
    var phone = window.matchMedia('(max-width: 768px)');

    function items() {
        return Array.prototype.slice.call(grid.querySelectorAll(':scope > [data-widget-key]'));
    }

    function intervalOf(item) {
        return parseInt(item.getAttribute('data-widget-refresh'), 10) || 0;
    }

    function isEditing() {
        return grid.classList.contains('dash-grid--editing');
    }

    function isDue(item, force) {
        if (intervalOf(item) <= 0 || item.getAttribute('data-widget-collapsed') === '1') return false;
        if (item.contains(document.activeElement) && document.activeElement !== document.body) return false;
        if (force) return true;
        var key = item.getAttribute('data-widget-key');
        return Date.now() - (lastRun[key] || 0) >= intervalOf(item) * 1000;
    }

    function showUpdated() {
        if (!labelEl) return;
        var minutes = Math.floor((Date.now() - lastUpdate) / 60000);
        var text = minutes < 1
            ? (labelEl.getAttribute('data-label-now') || '')
            : (labelEl.getAttribute('data-label-ago') || '').replace(':n', String(minutes));
        labelEl.textContent = text;
    }

    function showExpired() {
        expired = true;
        if (labelEl) {
            labelEl.textContent = labelEl.getAttribute('data-label-expired') || '';
            labelEl.classList.add('dash-updated--error');
        }
    }

    function refresh(force) {
        if (running || expired) return;
        if (!force && (document.visibilityState !== 'visible' || isEditing())) return;
        var due = items().filter(function (item) { return isDue(item, force); });
        if (due.length === 0) return;

        running = true;
        if (refreshBtn) refreshBtn.disabled = true;
        var url = window.location.href.split('#')[0];
        fetch(url, { headers: { 'Accept': 'text/html' }, cache: 'no-store', credentials: 'same-origin' })
            .then(function (res) {
                // Session expirée : redirection vers la connexion ou refus.
                if (res.status === 401 || res.status === 403 || (res.redirected && /\/login/.test(res.url))) {
                    showExpired();
                    return null;
                }
                if (!res.ok) throw new Error(String(res.status));
                return res.text();
            })
            .then(function (html) {
                if (html === null) return;
                var doc = new DOMParser().parseFromString(html, 'text/html');
                due.forEach(function (item) {
                    var key = item.getAttribute('data-widget-key');
                    lastRun[key] = Date.now();
                    var fresh = doc.getElementById('dash-widget-' + key);
                    var from = fresh && fresh.querySelector(':scope > .dash-grid__content');
                    var to = item.querySelector(':scope > .dash-grid__content');
                    if (!from || !to || from.innerHTML === to.innerHTML) return;
                    to.innerHTML = from.innerHTML;
                });
                lastUpdate = Date.now();
                showUpdated();
                window.dispatchEvent(new Event('resize'));
            })
            .catch(function () { /* échec silencieux : le contenu actuel reste affiché */ })
            .then(function () {
                running = false;
                if (refreshBtn) refreshBtn.disabled = false;
            });
    }

    setInterval(function () { refresh(false); showUpdated(); }, TICK_MS);
    document.addEventListener('visibilitychange', function () {
        // Retour sur l'onglet : rattrape tout de suite ce qui est échu.
        if (document.visibilityState === 'visible') {
            refresh(false);
            acquireWakeLock();
        }
    });
    items().forEach(function (item) { lastRun[item.getAttribute('data-widget-key')] = Date.now(); });
    showUpdated();

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-dash-refresh-now]')) {
            refresh(true);
        } else if (e.target.closest('[data-dash-wall]')) {
            enterWall();
        } else if (e.target.closest('[data-dash-wall-exit]')) {
            requestExit();
        }
    });

    // ── Mode mural ───────────────────────────────────────────────────────────
    function acquireWakeLock() {
        if (!document.documentElement.classList.contains('dash-wall') || !('wakeLock' in navigator) || wakeLock) return;
        navigator.wakeLock.request('screen').then(function (lock) {
            wakeLock = lock;
            lock.addEventListener('release', function () { wakeLock = null; });
        }).catch(function () { /* refusé : l'écran peut s'éteindre, sans conséquence */ });
    }

    function releaseWakeLock() {
        if (wakeLock) {
            wakeLock.release().catch(function () { /* déjà libéré */ });
            wakeLock = null;
        }
    }

    function setWall(on) {
        document.documentElement.classList.toggle('dash-wall', on);
        if (wallBtn) wallBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
        // « Quitter » n'est visible et utilisable qu'en mode mural.
        if (wallExit) { wallExit.hidden = !on; wallExit.disabled = !on; }
        // Les widgets deviennent inertes (ni clic, ni focus clavier, ni lecteur d'écran) : seul « Quitter » reste actif.
        grid.inert = on;
        if (on) acquireWakeLock(); else releaseWakeLock();
        window.dispatchEvent(new Event('resize'));
    }

    function inWall() {
        return document.documentElement.classList.contains('dash-wall');
    }

    function goFullscreen() {
        var el = document.documentElement;
        if (el.requestFullscreen && !document.fullscreenElement) {
            el.requestFullscreen().then(lockKeyboard).catch(function () { /* plein écran refusé : le mode mural reste actif dans la fenêtre */ });
        }
    }

    // Keyboard Lock (Chromium, plein écran) : Échap n'est plus intercepté par le navigateur pour quitter le plein écran.
    function lockKeyboard() {
        try {
            if (navigator.keyboard && navigator.keyboard.lock) navigator.keyboard.lock(['Escape']).catch(function () { /* non pris en charge */ });
        } catch (err) { /* sans conséquence */ }
    }

    function unlockKeyboard() {
        try {
            if (navigator.keyboard && navigator.keyboard.unlock) navigator.keyboard.unlock();
        } catch (err) { /* sans conséquence */ }
    }

    // ── Mot de passe du mode mural ───────────────────────────────────────────
    // Choisi à l'activation, redemandé pour quitter. Il reste en mémoire dans cette page : ni stocké, ni envoyé. C'est un
    // verrou contre un geste involontaire sur un écran de magasin, pas une protection contre quelqu'un qui maîtrise le
    // navigateur ; la session de l'utilisateur reste, elle, la vraie barrière d'accès.
    var wallHash = null; // « sel:empreinte » du mot de passe, aussi gardé dans sessionStorage pour survivre à un F5
    var MIN_PASSWORD = 4;
    var STORE_KEY = 'kintai.dashWall';
    var lock = document.querySelector('[data-wall-lock]');

    function lockText(name) {
        var texts = lock && lock.querySelector('[data-wall-lock-texts]');
        return (texts && texts.getAttribute('data-' + name)) || '';
    }

    function storeHash(value) {
        try {
            if (value) sessionStorage.setItem(STORE_KEY, value); else sessionStorage.removeItem(STORE_KEY);
        } catch (err) { /* stockage indisponible : le verrou ne survivra pas à un rechargement */ }
    }

    function loadHash() {
        try { return sessionStorage.getItem(STORE_KEY); } catch (err) { return null; }
    }

    // Repli quand SubtleCrypto est absent (page non sécurisée) : cyrb53, suffisant pour un verrou anti-geste.
    function fallbackHash(text) {
        var h1 = 0xdeadbeef, h2 = 0x41c6ce57, i, ch;
        for (i = 0; i < text.length; i++) {
            ch = text.charCodeAt(i);
            h1 = Math.imul(h1 ^ ch, 2654435761);
            h2 = Math.imul(h2 ^ ch, 1597334677);
        }
        h1 = Math.imul(h1 ^ (h1 >>> 16), 2246822507) ^ Math.imul(h2 ^ (h2 >>> 13), 3266489909);
        h2 = Math.imul(h2 ^ (h2 >>> 16), 2246822507) ^ Math.imul(h1 ^ (h1 >>> 13), 3266489909);
        return (4294967296 * (2097151 & h2) + (h1 >>> 0)).toString(16);
    }

    // Promesse de l'empreinte « sel:hex » du mot de passe ; sel fourni, sinon tiré au hasard.
    function hashPassword(password, salt) {
        salt = salt || Math.random().toString(36).slice(2) + Date.now().toString(36);
        var text = salt + ':' + password;
        if (window.crypto && window.crypto.subtle && window.TextEncoder) {
            return window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(text)).then(function (buf) {
                var hex = Array.prototype.map.call(new Uint8Array(buf), function (b) {
                    return ('0' + b.toString(16)).slice(-2);
                }).join('');
                return salt + ':' + hex;
            });
        }
        return Promise.resolve(salt + ':' + fallbackHash(text));
    }

    // mode : « set » (choisir le mot de passe) ou « unlock » (le redonner). onOk à la validation, onCancel sinon.
    function askPassword(mode, onOk, onCancel) {
        var form = lock.querySelector('[data-wall-lock-form]');
        var pwd = lock.querySelector('[data-wall-lock-password]');
        var confirmRow = lock.querySelector('[data-wall-lock-confirm-row]');
        var confirm = lock.querySelector('[data-wall-lock-confirm]');
        var error = lock.querySelector('[data-wall-lock-error]');
        var cancelBtn = lock.querySelector('[data-wall-lock-cancel]');
        var setting = mode === 'set';
        var done = false;

        lock.querySelector('[data-wall-lock-title]').textContent = lockText(mode + '-title');
        lock.querySelector('[data-wall-lock-hint]').textContent = lockText(mode + '-hint');
        lock.querySelector('[data-wall-lock-submit]').textContent = lockText(mode + '-submit');
        confirmRow.hidden = !setting;
        confirm.required = setting;
        pwd.value = '';
        confirm.value = '';
        error.hidden = true;

        function finish(ok) {
            if (done) return;
            done = true;
            form.removeEventListener('submit', onSubmit);
            lock.removeEventListener('cancel', onEsc);
            cancelBtn.removeEventListener('click', onCancelClick);
            pwd.value = '';
            confirm.value = '';
            if (lock.open) lock.close();
            if (ok) onOk(); else onCancel();
        }

        function fail(key) {
            error.textContent = lockText(key);
            error.hidden = false;
            pwd.value = '';
            confirm.value = '';
            pwd.focus();
        }

        function onSubmit(e) {
            e.preventDefault();
            if (setting) {
                if (pwd.value.length < MIN_PASSWORD) return fail('err-short');
                if (pwd.value !== confirm.value) return fail('err-mismatch');
                return hashPassword(pwd.value).then(function (h) {
                    wallHash = h;
                    storeHash(h);
                    finish(true);
                });
            }
            hashPassword(pwd.value, wallHash.split(':')[0]).then(function (h) {
                if (h !== wallHash) return fail('err-wrong');
                finish(true);
            });
        }

        function onEsc(e) { e.preventDefault(); finish(false); }
        function onCancelClick() { finish(false); }

        form.addEventListener('submit', onSubmit);
        lock.addEventListener('cancel', onEsc);
        cancelBtn.addEventListener('click', onCancelClick);
        lock.showModal();
    }

    function enterWall() {
        if (phone.matches || !lock || lock.open) return;
        askPassword('set', function () {
            setWall(true);
            goFullscreen();
        }, function () { /* annulé : le mode mural n'est pas activé */ });
    }

    // Sortie réelle, une fois le mot de passe donné.
    function exitWall() {
        if (document.fullscreenElement && document.exitFullscreen) {
            document.exitFullscreen().catch(function () { /* déjà sorti */ });
        }
        wallHash = null;
        storeHash(null);
        unlockKeyboard();
        setWall(false);
    }

    // Demande de sortie (bouton, Échap, fin du plein écran) : le mot de passe est exigé. Annuler garde le mode mural et
    // remet le plein écran, le navigateur l'ayant quitté de lui-même sur Échap.
    function requestExit() {
        if (!inWall() || !lock || lock.open) return;
        if (wallHash === null) { exitWall(); return; }
        askPassword('unlock', exitWall, goFullscreen);
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && inWall() && !document.fullscreenElement) requestExit();
    });

    // Raccourcis qui feraient sortir du mode mural sans mot de passe : rechargement (F5, Ctrl/Cmd+R), plein écran (F11),
    // barre d'adresse, historique, outils de développement, code source. Interceptés en capture tant que le mode est actif.
    function isWallBypassKey(e) {
        var key = (e.key || '').toLowerCase();
        var mod = e.ctrlKey || e.metaKey;
        if (key === 'f5' || key === 'f11' || key === 'f12' || key === 'browserrefresh') return true;
        if (e.altKey && (key === 'arrowleft' || key === 'arrowright' || key === 'home')) return true;
        if (mod && ['r', 'l', 'u', 's', 'p', 'o', 'k', 'e'].indexOf(key) !== -1) return true;
        if (mod && e.shiftKey && ['i', 'j', 'c', 'r', 'delete'].indexOf(key) !== -1) return true;
        return false;
    }

    // Filet pour les navigateurs sans « inert » : en mode mural, tout clic hors du bouton « Quitter » et de la boîte du mot de
    // passe est annulé (liens, boutons et menus des widgets, clic du milieu, menu contextuel).
    function blockWallPointer(e) {
        if (!inWall() || !e.target.closest) return;
        if (e.target.closest('[data-dash-wall-exit], [data-wall-lock]')) return;
        e.preventDefault();
        e.stopPropagation();
    }
    ['click', 'auxclick', 'dblclick', 'contextmenu', 'submit'].forEach(function (type) {
        document.addEventListener(type, blockWallPointer, true);
    });

    document.addEventListener('keydown', function (e) {
        if (!inWall() || !isWallBypassKey(e)) return;
        e.preventDefault();
        e.stopPropagation();
    }, true);

    // Dernier filet (clic sur recharger, Ctrl+W, fermeture) : le navigateur demande confirmation avant de quitter la page.
    window.addEventListener('beforeunload', function (e) {
        if (!inWall()) return;
        e.preventDefault();
        e.returnValue = '';
    });

    // Rechargement de l'onglet : le mode mural est rétabli avec le même verrou (le plein écran, lui, exige un geste).
    (function restoreWall() {
        var saved = loadHash();
        if (!saved || !lock || phone.matches) return;
        wallHash = saved;
        setWall(true);
        var resume = function () {
            document.removeEventListener('pointerdown', resume, true);
            document.removeEventListener('keydown', resume, true);
            goFullscreen();
        };
        document.addEventListener('pointerdown', resume, true);
        document.addEventListener('keydown', resume, true);
    }());

    // Le bouton est caché sur téléphone ; l'état de départ du bouton « Quitter » est aussi désactivé.
    if (wallBtn) wallBtn.disabled = phone.matches;
    if (wallExit) wallExit.disabled = true;
    // Un redimensionnement ne doit pas lever le verrou : on ne met à jour que le bouton d'activation.
    var onPhoneChange = function () {
        if (wallBtn) wallBtn.disabled = phone.matches;
    };
    if (phone.addEventListener) phone.addEventListener('change', onPhoneChange);
    else if (phone.addListener) phone.addListener(onPhoneChange);

    // Échap (ou tout autre moyen de quitter le plein écran) demande aussi le mot de passe.
    document.addEventListener('fullscreenchange', function () {
        if (!document.fullscreenElement && inWall()) requestExit();
    });
}());
