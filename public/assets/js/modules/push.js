/**
 * Notifications push natives (Web Push + VAPID, sans Firebase) : permission, abonnement du navigateur et
 * enregistrement de l'abonnement côté serveur.
 *
 * Chargé sur toutes les pages connectées quand le web push est opérationnel (#push-meta rendu par le layout) :
 *  - l'abonnement du navigateur est renvoyé au serveur (mise à jour silencieuse), ce qui couvre une rotation de
 *    l'abonnement, un abonnement perdu côté serveur ou un navigateur partagé entre deux comptes. Hors de l'onglet
 *    Notifications du profil (qui montre l'état réel), pas plus d'une fois toutes les 6 heures par compte, clé et
 *    abonnement : sans cela, chaque page chargée coûtait deux requêtes et une écriture en base ;
 *  - l'état « activé » n'est affiché qu'après une réponse positive du serveur ;
 *  - à la déconnexion, l'abonnement de ce navigateur est supprimé (serveur et navigateur).
 *
 * Les blocs d'état (#push-status-*) n'existent que dans l'onglet Notifications du profil : ailleurs, seule la
 * synchronisation s'exécute.
 */
(function () {
    'use strict';

    var meta = document.getElementById('push-meta');
    if (!meta) return;

    var vapidKey       = meta.dataset.vapidKey || '';
    var subscribeUrl   = meta.dataset.subscribeUrl || '';
    var unsubscribeUrl = meta.dataset.unsubscribeUrl || '';
    var statusUrl      = meta.dataset.statusUrl || '';
    var userId         = meta.dataset.userId || '';

    // Délai minimal entre deux resynchronisations silencieuses d'un même abonnement.
    var SYNC_EVERY_MS  = 6 * 60 * 60 * 1000;
    var SYNC_STORAGE   = 'kintai-push-sync';

    var blocks = {
        unsupported: document.getElementById('push-status-unsupported'),
        ios:         document.getElementById('push-status-ios'),
        denied:      document.getElementById('push-status-denied'),
        disabled:    document.getElementById('push-status-disabled'),
        enabled:     document.getElementById('push-status-enabled'),
        error:       document.getElementById('push-status-error'),
    };

    function renderState(state) {
        Object.keys(blocks).forEach(function (key) {
            if (blocks[key]) blocks[key].hidden = key !== state;
        });
    }

    function isIos() {
        return /iPad|iPhone|iPod/.test(navigator.userAgent)
            || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }

    function isInstalledPwa() {
        return navigator.standalone === true
            || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
    }

    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - base64String.length % 4) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var rawData = window.atob(base64);
        var out = new Uint8Array(rawData.length);
        for (var i = 0; i < rawData.length; ++i) out[i] = rawData.charCodeAt(i);
        return out;
    }

    function sameBytes(buffer, bytes) {
        if (!buffer) return false;
        var a = new Uint8Array(buffer);
        if (a.length !== bytes.length) return false;
        for (var i = 0; i < a.length; i++) if (a[i] !== bytes[i]) return false;
        return true;
    }

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(body),
        });
    }

    // Adresse de l'abonnement dans les formulaires de déconnexion : le serveur supprime alors cet abonnement.
    function rememberEndpoint(endpoint) {
        document.querySelectorAll('form[data-push-logout] input[name="push_endpoint"]').forEach(function (input) {
            input.value = endpoint || '';
        });
    }

    function getSubscription() {
        return navigator.serviceWorker.ready.then(function (registration) {
            return registration.pushManager.getSubscription();
        });
    }

    // Vrai quand l'état affiché doit venir du serveur (onglet Notifications du profil) : pas de resynchronisation différée.
    function showsState() {
        return Object.keys(blocks).some(function (key) { return blocks[key] !== null; });
    }

    // Empreinte de ce qui a été synchronisé : compte, clé du serveur et abonnement. Un changement de l'un d'eux
    // (autre compte sur ce navigateur, clés VAPID régénérées, nouvel abonnement) force une nouvelle synchronisation.
    function syncStamp(subscription) {
        return userId + '|' + vapidKey + '|' + subscription.endpoint;
    }

    function recentlySynced(subscription) {
        try {
            var saved = JSON.parse(window.localStorage.getItem(SYNC_STORAGE) || 'null');
            return !!saved && saved.stamp === syncStamp(subscription)
                && typeof saved.at === 'number' && Date.now() - saved.at >= 0 && Date.now() - saved.at < SYNC_EVERY_MS;
        } catch (err) {
            return false;
        }
    }

    function markSynced(subscription) {
        try {
            window.localStorage.setItem(SYNC_STORAGE, JSON.stringify({ stamp: syncStamp(subscription), at: Date.now() }));
        } catch (err) { /* stockage indisponible (navigation privée…) : on resynchronisera, sans conséquence */ }
    }

    function forgetSynced() {
        try { window.localStorage.removeItem(SYNC_STORAGE); } catch (err) { /* idem */ }
    }

    // Envoie l'abonnement au serveur ; vrai seulement si le serveur répond 2xx.
    function sendSubscription(subscription) {
        var json = subscription.toJSON();
        if (!json.endpoint || !json.keys) return Promise.resolve(false);
        return post(subscribeUrl, { endpoint: json.endpoint, keys: json.keys }).then(function (response) {
            if (response.ok) {
                rememberEndpoint(json.endpoint);
                markSynced(subscription);
            }
            return response.ok;
        }).catch(function () { return false; });
    }

    function subscribe() {
        var key = urlBase64ToUint8Array(vapidKey);
        return navigator.serviceWorker.ready.then(function (registration) {
            return registration.pushManager.getSubscription().then(function (existing) {
                // Abonnement créé avec une autre clé (clés VAPID régénérées) : il faut le remplacer.
                if (existing && !sameBytes(existing.options && existing.options.applicationServerKey, key)) {
                    return existing.unsubscribe().then(function () { return null; });
                }
                return existing;
            }).then(function (existing) {
                return existing || registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
            });
        });
    }

    function enable() {
        return Notification.requestPermission().then(function (permission) {
            if (permission !== 'granted') {
                renderState(permission === 'denied' ? 'denied' : 'disabled');
                return;
            }
            return subscribe().then(function (subscription) {
                return sendSubscription(subscription).then(function (ok) {
                    if (ok) {
                        renderState('enabled');
                        return;
                    }
                    // Le serveur n'a pas enregistré l'abonnement : on ne prétend pas que c'est activé.
                    return subscription.unsubscribe().catch(function () {}).then(function () { renderState('error'); });
                });
            });
        }).catch(function () { renderState('error'); });
    }

    function disable() {
        return getSubscription().then(function (subscription) {
            if (!subscription) return;
            var endpoint = subscription.endpoint;
            return post(unsubscribeUrl, { endpoint: endpoint }).catch(function () {}).then(function () {
                return subscription.unsubscribe();
            });
        }).then(function () {
            rememberEndpoint('');
            forgetSynced();
            renderState('disabled');
        }).catch(function () { renderState('error'); });
    }

    // Synchronisation au chargement : renvoie l'abonnement existant au serveur, puis affiche l'état réel.
    function sync() {
        if (Notification.permission === 'denied') {
            renderState('denied');
            return;
        }
        getSubscription().then(function (subscription) {
            if (!subscription) {
                renderState('disabled');
                return;
            }
            // Abonnement fait avec une ancienne clé VAPID : inutilisable. On le remplace sans rien demander.
            if (!sameBytes(subscription.options && subscription.options.applicationServerKey, urlBase64ToUint8Array(vapidKey))) {
                if (Notification.permission === 'granted') {
                    return subscribe().then(sendSubscription).then(function (ok) { renderState(ok ? 'enabled' : 'disabled'); });
                }
                return subscription.unsubscribe().then(function () { renderState('disabled'); });
            }
            // Déjà synchronisé il y a peu et aucun état à afficher sur cette page : rien à envoyer. On garde quand même
            // l'adresse pour le formulaire de déconnexion, qui supprime l'abonnement côté serveur.
            if (!showsState() && recentlySynced(subscription)) {
                rememberEndpoint(subscription.endpoint);
                return;
            }
            return sendSubscription(subscription).then(function (ok) {
                if (!ok) {
                    renderState('disabled');
                    return;
                }
                // L'état affiché vient du serveur : cet abonnement est-il bien enregistré pour ce compte ?
                return post(statusUrl, { endpoint: subscription.endpoint })
                    .then(function (response) { return response.json(); })
                    .then(function (data) { renderState(data && data.subscribed ? 'enabled' : 'disabled'); });
            });
        }).catch(function () { renderState('disabled'); });
    }

    // Déconnexion : l'abonnement de ce navigateur est supprimé côté navigateur avant l'envoi du formulaire (le serveur
    // supprime le sien grâce à l'adresse du champ caché).
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-push-logout') || form.dataset.pushDone === '1') return;
        e.preventDefault();
        forgetSynced();
        var done = function () { form.dataset.pushDone = '1'; form.submit(); };
        // Jamais bloquant : au bout d'une seconde la déconnexion a lieu quoi qu'il arrive.
        var timer = setTimeout(done, 1000);
        getSubscription().then(function (subscription) {
            return subscription ? subscription.unsubscribe() : null;
        }).catch(function () {}).then(function () {
            clearTimeout(timer);
            done();
        });
    });

    var enableBtn  = document.getElementById('push-enable-btn');
    var disableBtn = document.getElementById('push-disable-btn');

    if (enableBtn) {
        enableBtn.addEventListener('click', function () {
            enableBtn.disabled = true;
            enable().then(function () { enableBtn.disabled = false; });
        });
    }
    if (disableBtn) {
        disableBtn.addEventListener('click', function () {
            disableBtn.disabled = true;
            disable().then(function () { disableBtn.disabled = false; });
        });
    }

    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        // iOS : le web push n'existe que dans une application installée sur l'écran d'accueil (iOS 16.4 et suivants).
        renderState(isIos() && !isInstalledPwa() ? 'ios' : 'unsupported');
        return;
    }

    // Le service worker signale qu'il a dû se réabonner (pushsubscriptionchange) : on resynchronise.
    navigator.serviceWorker.addEventListener('message', function (e) {
        if (e.data && e.data.type === 'push-resubscribed') sync();
    });

    sync();
})();
