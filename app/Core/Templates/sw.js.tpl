const CACHE = 'kintai-__ASSET_VERSION__';

// Base de déploiement déduite du scope d'enregistrement (ex. '/kintai') plutôt
// que codée en dur, pour fonctionner aussi bien à la racine du domaine que
// sous un sous-répertoire (installation self-hosted dans un sous-dossier).
const BASE = new URL(self.registration.scope).pathname.replace(/\/$/, '');

const OFFLINE_URL = BASE + '/offline.html';
const ASSETS = [
  BASE + '/assets/css/app.css',
  BASE + '/assets/js/app.js',
  BASE + '/assets/js/modules/notifications.js',
  BASE + '/assets/js/modules/timeclock.js',
  BASE + '/assets/img/kintai-192.png',
  OFFLINE_URL,
];

self.addEventListener('install', (e) => {
  self.skipWaiting();
  e.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(ASSETS))
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);

  // Ignore le cross-origin, et tout ce qui n'est pas HTTP(S) (extensions...).
  if (url.origin !== self.location.origin) return;

  // Navigation (ouverture/rechargement d'une page) : réseau d'abord pour rester à
  // jour, dernière version connue en secours, puis une page hors-ligne dédiée si
  // rien n'a jamais été mis en cache pour cette URL (ex. tout premier lancement
  // hors-ligne).
  //
  // Les requêtes de navigation non-GET (soumission de <form method="POST">) sont
  // exclues : le navigateur les expose au Service Worker avec redirect: 'manual',
  // donc fetch(e.request) résout avec une réponse "opaque redirect" (status 0)
  // que Chrome ne sait pas suivre pour une navigation POST — respondWith() avec
  // cette réponse bloque silencieusement la navigation (aucune erreur, la page
  // reste affichée telle quelle). On laisse le navigateur gérer ces requêtes
  // nativement, sans interception.
  if (e.request.mode === 'navigate' && e.request.method !== 'GET') return;

  if (e.request.mode === 'navigate') {
    e.respondWith(
      fetch(e.request)
        .then((res) => {
          if (res.ok) {
            const copy = res.clone();
            caches.open(CACHE).then((cache) => cache.put(e.request, copy));
          }
          return res;
        })
        .catch(() => caches.match(e.request).then((cached) => cached || caches.match(OFFLINE_URL)))
    );
    return;
  }

  if (e.request.method !== 'GET') return;

  // Appels API → réseau d'abord, secours cache (dernières données connues hors-ligne).
  if (url.pathname.startsWith(BASE + '/api/')) {
    e.respondWith(networkFirst(e.request));
    return;
  }

  // Assets statiques → cache d'abord (immuables entre deux déploiements).
  if (url.pathname.startsWith(BASE + '/assets/')) {
    e.respondWith(cacheFirst(e.request));
    return;
  }

  // Reste (pages dynamiques hors navigation, ex. requêtes fetch d'un module JS) :
  // réseau uniquement, sans mise en cache implicite de contenu potentiellement sensible.
});

async function networkFirst(req) {
  try {
    const res = await fetch(req);
    if (res.ok) {
      const cache = await caches.open(CACHE);
      cache.put(req, res.clone());
    }
    return res;
  } catch {
    const cached = await caches.match(req);
    return cached || new Response(JSON.stringify({ error: 'offline' }), {
      status: 503,
      headers: { 'Content-Type': 'application/json' },
    });
  }
}

// ── Web push natif (VAPID) ──────────────────────────────────────────────────
// WebPushService (côté serveur) envoie directement au navigateur une charge utile JSON plate :
// { title, body, type, link, notification_id, reference_id }.
self.addEventListener('push', (e) => {
  let payload = {};
  try { payload = e.data ? e.data.json() : {}; } catch (err) { payload = {}; }

  const data  = payload || {};
  const title = data.title || 'Kintai';
  const body  = data.body || '';

  e.waitUntil(
    self.registration.showNotification(title, {
      body,
      icon: BASE + '/assets/img/kintai-192.png',
      badge: BASE + '/assets/img/kintai-192.png',
      data,
      // Heures silencieuses de l'utilisateur (décidées par le serveur à l'envoi) : affichée sans son ni vibration.
      silent: data.quiet === '1',
    })
  );
});

// Le navigateur a renouvelé ou perdu l'abonnement : on se réabonne avec la même clé serveur. L'enregistrement côté
// serveur demande une session (jeton CSRF) que le service worker n'a pas ; il est donc fait par push.js, prévenu
// ici, dans un onglet ouvert, et de toute façon à la prochaine page ouverte.
self.addEventListener('pushsubscriptionchange', (e) => {
  const key = (e.oldSubscription && e.oldSubscription.options && e.oldSubscription.options.applicationServerKey) || null;
  if (!key) return;
  e.waitUntil(
    self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key })
      .then(() => self.clients.matchAll({ type: 'window', includeUncontrolled: true }))
      .then((clients) => clients.forEach((c) => c.postMessage({ type: 'push-resubscribed' })))
      .catch(() => {})
  );
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const data = e.notification.data || {};
  // notification_id présent : passe par /notifications/{id}/open pour marquer la
  // notification comme lue avant de rediriger (même mécanisme que le clic dans le
  // dropdown web). Sans ça (notification système ancienne, ou notification_id
  // absent), retombe sur le lien brut, puis sur la liste des notifications.
  const target = data.notification_id
    ? BASE + '/notifications/' + encodeURIComponent(data.notification_id) + '/open'
    : (data.link ? BASE + data.link : BASE + '/notifications');

  e.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
      // Réutilise un onglet déjà ouvert de l'app s'il y en a un, plutôt que d'en
      // empiler un nouveau à chaque notification cliquée.
      const existing = clients.find((c) => 'navigate' in c && 'focus' in c);
      if (existing) {
        return existing.navigate(target).then((c) => c && c.focus());
      }
      if (self.clients.openWindow) {
        return self.clients.openWindow(target);
      }
    })
  );
});

async function cacheFirst(req) {
  const cached = await caches.match(req);
  if (cached) return cached;
  try {
    const res = await fetch(req);
    if (res.ok) {
      const cache = await caches.open(CACHE);
      cache.put(req, res.clone());
    }
    return res;
  } catch {
    return new Response('Offline', { status: 503 });
  }
}
