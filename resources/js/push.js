/**
 * Notifications push du navigateur (Web Push + VAPID) : service worker, permission, abonnement et enregistrement
 * de l'abonnement côté serveur.
 *
 * Chargé sur toutes les pages (importé par app.js) mais n'agit que pour un utilisateur connecté : le layout rend
 * alors `<meta name="kintai-push">` (clé VAPID publique et adresses des routes). Sans clé, le Web Push est
 * désactivé sur l'instance et rien n'est enregistré.
 *
 *  - l'abonnement du navigateur est renvoyé au serveur (au plus toutes les 6 h sauf sur la page des préférences,
 *    qui affiche l'état réel) : rotation de l'abonnement, abonnement perdu côté serveur, navigateur partagé ;
 *  - l'état « activé » n'est affiché qu'après une réponse positive du serveur ;
 *  - à la déconnexion, l'abonnement du navigateur est supprimé (côté navigateur ici, côté serveur via le champ
 *    caché `push_endpoint` du formulaire).
 *
 * Panneau de la page des préférences : `[data-push-panel]` contenant des blocs `[data-push-state="…"]`
 * (unconfigured, unsupported, ios, denied, disabled, enabled, error) et les boutons `[data-push-enable]` / `[data-push-disable]`.
 */

const SYNC_EVERY_MS = 6 * 60 * 60 * 1000;
const SYNC_STORAGE = 'kintai-push-sync';

let lastInit = { panel: undefined, at: 0 };

function config() {
    const meta = document.querySelector('meta[name="kintai-push"]');

    if (!meta) {
        return null;
    }

    return {
        vapidKey: meta.dataset.vapidKey || '',
        swUrl: meta.dataset.swUrl || '/sw.js',
        scope: meta.dataset.scope || '/',
        subscribeUrl: meta.dataset.subscribeUrl || '',
        statusUrl: meta.dataset.statusUrl || '',
        userId: meta.dataset.userId || '',
    };
}

function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');

    return meta ? meta.getAttribute('content') || '' : '';
}

function request(method, url, body) {
    return fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
}

function renderState(state) {
    document.querySelectorAll('[data-push-panel] [data-push-state]').forEach((block) => {
        block.hidden = block.dataset.pushState !== state;
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
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const raw = window.atob((base64String + padding).replace(/-/g, '+').replace(/_/g, '/'));
    const out = new Uint8Array(raw.length);

    for (let i = 0; i < raw.length; i++) {
        out[i] = raw.charCodeAt(i);
    }

    return out;
}

function sameBytes(buffer, bytes) {
    if (!buffer) {
        return false;
    }

    const a = new Uint8Array(buffer);

    return a.length === bytes.length && a.every((value, index) => value === bytes[index]);
}

function supported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

function registration(cfg) {
    return navigator.serviceWorker
        .register(cfg.swUrl, { scope: cfg.scope })
        .then(() => navigator.serviceWorker.ready);
}

function currentSubscription(cfg) {
    return registration(cfg).then((reg) => reg.pushManager.getSubscription());
}

function syncStamp(cfg, subscription) {
    return cfg.userId + '|' + cfg.vapidKey + '|' + subscription.endpoint;
}

function recentlySynced(cfg, subscription) {
    try {
        const saved = JSON.parse(window.localStorage.getItem(SYNC_STORAGE) || 'null');
        const age = saved ? Date.now() - saved.at : -1;

        return !!saved && saved.stamp === syncStamp(cfg, subscription) && age >= 0 && age < SYNC_EVERY_MS;
    } catch (err) {
        return false;
    }
}

function markSynced(cfg, subscription) {
    try {
        window.localStorage.setItem(SYNC_STORAGE, JSON.stringify({ stamp: syncStamp(cfg, subscription), at: Date.now() }));
    } catch (err) {
        // stockage indisponible (navigation privée…) : on resynchronisera, sans conséquence
    }
}

function forgetSynced() {
    try {
        window.localStorage.removeItem(SYNC_STORAGE);
    } catch (err) {
        // idem
    }
}

// Envoie l'abonnement au serveur ; vrai seulement si le serveur répond 2xx.
function sendSubscription(cfg, subscription) {
    const json = subscription.toJSON();

    if (!json.endpoint || !json.keys) {
        return Promise.resolve(false);
    }

    return request('POST', cfg.subscribeUrl, { endpoint: json.endpoint, keys: json.keys })
        .then((response) => {
            if (response.ok) {
                markSynced(cfg, subscription);
            }

            return response.ok;
        })
        .catch(() => false);
}

function subscribe(cfg) {
    const key = urlBase64ToUint8Array(cfg.vapidKey);

    return registration(cfg).then((reg) => reg.pushManager.getSubscription().then((existing) => {
        // Abonnement créé avec une autre clé (clés VAPID régénérées) : il faut le remplacer.
        if (existing && !sameBytes(existing.options && existing.options.applicationServerKey, key)) {
            return existing.unsubscribe().then(() => null);
        }

        return existing;
    }).then((existing) => existing || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key })));
}

function enable(cfg) {
    return Notification.requestPermission().then((permission) => {
        if (permission !== 'granted') {
            renderState(permission === 'denied' ? 'denied' : 'disabled');

            return undefined;
        }

        return subscribe(cfg).then((subscription) => sendSubscription(cfg, subscription).then((ok) => {
            if (ok) {
                renderState('enabled');

                return undefined;
            }

            // Le serveur n'a pas enregistré l'abonnement : on ne prétend pas que c'est activé.
            return subscription.unsubscribe().catch(() => {}).then(() => renderState('error'));
        }));
    }).catch(() => renderState('error'));
}

function disable(cfg) {
    return currentSubscription(cfg).then((subscription) => {
        if (!subscription) {
            return undefined;
        }

        return request('DELETE', cfg.subscribeUrl, { endpoint: subscription.endpoint })
            .catch(() => {})
            .then(() => subscription.unsubscribe());
    }).then(() => {
        forgetSynced();
        renderState('disabled');
    }).catch(() => renderState('error'));
}

// Synchronisation au chargement : renvoie l'abonnement existant au serveur, puis affiche l'état réel.
function sync(cfg, showsState) {
    if (Notification.permission === 'denied') {
        renderState('denied');

        return Promise.resolve();
    }

    return currentSubscription(cfg).then((subscription) => {
        if (!subscription) {
            renderState('disabled');

            return undefined;
        }

        // Abonnement fait avec une ancienne clé VAPID : inutilisable. On le remplace sans rien demander.
        if (!sameBytes(subscription.options && subscription.options.applicationServerKey, urlBase64ToUint8Array(cfg.vapidKey))) {
            if (Notification.permission === 'granted') {
                return subscribe(cfg).then((fresh) => sendSubscription(cfg, fresh)).then((ok) => renderState(ok ? 'enabled' : 'disabled'));
            }

            return subscription.unsubscribe().then(() => renderState('disabled'));
        }

        // Déjà synchronisé il y a peu et aucun état à afficher sur cette page : rien à envoyer.
        if (!showsState && recentlySynced(cfg, subscription)) {
            return undefined;
        }

        return sendSubscription(cfg, subscription).then((ok) => {
            if (!ok) {
                renderState('disabled');

                return undefined;
            }

            // L'état affiché vient du serveur : cet abonnement est-il bien enregistré pour ce compte ?
            return request('GET', cfg.statusUrl + '?endpoint=' + encodeURIComponent(subscription.endpoint))
                .then((response) => response.json())
                .then((data) => renderState(data && data.subscribed ? 'enabled' : 'disabled'));
        });
    }).catch(() => renderState('disabled'));
}

function init() {
    const cfg = config();
    const panel = document.querySelector('[data-push-panel]');

    // Appelé au chargement puis à chaque navigation Livewire : une seule exécution par page.
    if (lastInit.panel === panel && Date.now() - lastInit.at < 1000) {
        return;
    }

    lastInit = { panel, at: Date.now() };

    if (!cfg) {
        return;
    }

    if (cfg.vapidKey === '') {
        renderState('unconfigured');

        return;
    }

    if (!supported()) {
        // iOS : le Web Push n'existe que dans une application installée sur l'écran d'accueil (iOS 16.4 et suivants).
        renderState(isIos() && !isInstalledPwa() ? 'ios' : 'unsupported');

        return;
    }

    sync(cfg, panel !== null);
}

document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const button = target ? target.closest('[data-push-enable], [data-push-disable]') : null;
    const cfg = config();

    if (!button || !cfg || cfg.vapidKey === '' || !supported()) {
        return;
    }

    button.disabled = true;
    const done = () => { button.disabled = false; };

    if (button.hasAttribute('data-push-enable')) {
        enable(cfg).then(done);
    } else {
        disable(cfg).then(done);
    }
});

// Déconnexion : l'abonnement du navigateur est supprimé avant l'envoi du formulaire ; le serveur supprime le sien
// grâce à l'adresse du champ caché. Jamais bloquant : au bout d'une seconde la déconnexion a lieu quoi qu'il arrive.
document.addEventListener('submit', (event) => {
    const form = event.target;
    const cfg = config();

    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-push-logout') || form.dataset.pushDone === '1') {
        return;
    }

    if (!cfg || cfg.vapidKey === '' || !supported()) {
        return;
    }

    event.preventDefault();
    forgetSynced();

    const done = () => {
        form.dataset.pushDone = '1';
        form.submit();
    };
    const timer = setTimeout(done, 1000);

    currentSubscription(cfg).then((subscription) => {
        if (!subscription) {
            return null;
        }

        const input = form.querySelector('input[name="push_endpoint"]');

        if (input) {
            input.value = subscription.endpoint;
        }

        return subscription.unsubscribe();
    }).catch(() => {}).then(() => {
        clearTimeout(timer);
        done();
    });
});

// Le service worker signale qu'il a dû se réabonner (pushsubscriptionchange) : on resynchronise.
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', (event) => {
        const cfg = config();

        if (cfg && event.data && event.data.type === 'push-resubscribed') {
            sync(cfg, document.querySelector('[data-push-panel]') !== null);
        }
    });
}

document.addEventListener('livewire:navigated', init);

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
