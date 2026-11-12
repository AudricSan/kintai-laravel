// Service worker de Kintai : notifications push (Web Push + VAPID). Servi par la route /sw.js.
//
// Charge utile JSON plate envoyée par le serveur :
// { title, body, type, link, notification_id, reference_id, quiet }

// Base de déploiement déduite de la portée d'enregistrement (ex. '/kintai'), pour fonctionner à la racine du
// domaine comme dans un sous-dossier.
const BASE = new URL(self.registration.scope).pathname.replace(/\/$/, '');

self.addEventListener('install', () => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (err) {
    data = {};
  }
  data = data || {};

  event.waitUntil(
    self.registration.showNotification(data.title || 'Kintai', {
      body: data.body || '',
      icon: BASE + '/images/brand/kintai-192.png',
      badge: BASE + '/images/brand/kintai-192.png',
      data,
      // Heures silencieuses de l'utilisateur (décidées par le serveur à l'envoi) : sans son ni vibration.
      silent: data.quiet === '1',
    })
  );
});

// Le navigateur a renouvelé ou perdu l'abonnement : on se réabonne avec la même clé serveur. L'enregistrement côté
// serveur demande une session (jeton CSRF) que le service worker n'a pas : un onglet ouvert, prévenu ici, s'en charge,
// et de toute façon la prochaine page ouverte.
self.addEventListener('pushsubscriptionchange', (event) => {
  const key = (event.oldSubscription && event.oldSubscription.options && event.oldSubscription.options.applicationServerKey) || null;
  if (!key) return;

  event.waitUntil(
    self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key })
      .then(() => self.clients.matchAll({ type: 'window', includeUncontrolled: true }))
      .then((clients) => clients.forEach((client) => client.postMessage({ type: 'push-resubscribed' })))
      .catch(() => {})
  );
});

// Adresse à ouvrir pour une notification : uniquement une page de CETTE origine. Un lien d'une autre origine
// (charge utile détournée) est ignoré et on retombe sur la liste des notifications.
function targetFor(data) {
  const fallback = self.location.origin + BASE + '/notifications';

  // notification_id : passe par /notifications/{id}/open, qui marque la notification comme lue avant de rediriger.
  if (data.notification_id) {
    return self.location.origin + BASE + '/notifications/' + encodeURIComponent(data.notification_id) + '/open';
  }

  if (typeof data.link === 'string' && data.link.charAt(0) === '/') {
    try {
      const url = new URL(data.link, self.location.origin);
      if (url.origin === self.location.origin) {
        return url.href;
      }
    } catch (err) {
      // lien illisible : repli ci-dessous
    }
  }

  return fallback;
}

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = targetFor(event.notification.data || {});

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
      // Réutilise un onglet déjà ouvert de l'application plutôt que d'en empiler un nouveau.
      const existing = clients.find((client) => 'navigate' in client && 'focus' in client);
      if (existing) {
        return existing.navigate(target).then((client) => client && client.focus());
      }
      if (self.clients.openWindow) {
        return self.clients.openWindow(target);
      }
    })
  );
});
