/* Le service worker du dashboard magasin.
 *
 * Il ne met RIEN en cache : le dashboard vit de chiffres du jour, une page
 * servie depuis un cache serait une page qui ment. Il n'est là que pour une
 * chose — recevoir les notifications quand l'application est fermée.
 *
 * Portée : /dashboard/. Il ne voit pas le reste du cockpit.
 */
'use strict';

// Un nouveau service worker prend la main tout de suite plutôt que d'attendre
// la fermeture de tous les onglets : sinon un correctif d'alerte attendrait
// des jours.
self.addEventListener('install', e => { self.skipWaiting(); });
self.addEventListener('activate', e => { e.waitUntil(self.clients.claim()); });

self.addEventListener('push', e => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (err) { d = { corps: e.data ? e.data.text() : '' }; }
  const titre = d.titre || 'Cockpit — L’Atelier by';
  e.waitUntil(self.registration.showNotification(titre, {
    body: d.corps || '',
    icon: '../assets/img/logo.png',
    badge: '../assets/img/logo.png',
    // Le même tag remplace la notification précédente au lieu d'empiler :
    // trois ruptures de suite ne doivent pas faire trois lignes.
    tag: d.tag || 'cockpit',
    renotify: true,
    data: { url: d.url || 'dashboard/' },
    requireInteraction: !!d.retenir
  }));
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  const cible = (e.notification.data && e.notification.data.url) || 'dashboard/';
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(l => {
    // Un onglet du dashboard est déjà ouvert : on le ramène au premier plan et
    // on l'amène où il faut, plutôt que d'en ouvrir un deuxième.
    for (const c of l) {
      if (c.url.indexOf('/dashboard/') !== -1 && 'focus' in c) {
        if ('navigate' in c) { try { c.navigate(new URL(cible, c.url).toString()); } catch (err) { /* même page */ } }
        return c.focus();
      }
    }
    return self.clients.openWindow(new URL(cible, self.registration.scope).toString());
  }));
});
