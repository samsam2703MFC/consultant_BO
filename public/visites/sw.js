/* Le service worker de l'application terrain.
 *
 * Deux rôles : garder la COQUILLE (page, script, styles, logo) pour que
 * l'application s'ouvre sans réseau — les données, elles, vivent en
 * IndexedDB côté module, jamais ici ; et recevoir les notifications quand
 * l'application est fermée.
 *
 * L'API n'est jamais mise en cache par le worker : une lecture périmée
 * servie comme fraîche est une lecture qui ment. Les photos téléversées
 * (uploads/) sont gardées une fois vues.
 * Portée : /visites/.
 */
'use strict';

const VERSION = 'visites-v1';
const COQUILLE = ['./', 'index.html', 'manifest.json', '../assets/js/visites.js', '../assets/ds/global.css', '../assets/img/logo.png'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(VERSION).then(c => c.addAll(COQUILLE).catch(() => null)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== VERSION).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', e => {
  const u = new URL(e.request.url);
  if (e.request.method !== 'GET' || u.origin !== location.origin) { return; }
  if (u.pathname.includes('/api/')) { return; }
  const photo = u.pathname.includes('/uploads/');
  // Coquille et polices : le réseau d'abord (une correction arrive tout de
  // suite), le cache en secours ; photos : le cache d'abord.
  if (photo) {
    e.respondWith(caches.open(VERSION).then(async c => { const r = await c.match(e.request); if (r) { return r; } const n = await fetch(e.request); if (n.ok) { c.put(e.request, n.clone()); } return n; }));
    return;
  }
  e.respondWith(fetch(e.request).then(r => { if (r.ok) { caches.open(VERSION).then(c => c.put(e.request, r.clone())); } return r; })
    .catch(() => caches.match(e.request).then(r => r || caches.match('./'))));
});

self.addEventListener('push', e => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (err) { d = { corps: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(d.titre || 'Visites — L’Atelier by', {
    body: d.corps || '', icon: '../assets/img/logo.png', badge: '../assets/img/logo.png', tag: d.tag || 'visites', renotify: true,
    data: { url: d.url || '' },
  }));
});
self.addEventListener('notificationclick', e => {
  e.notification.close();
  const cible = new URL('../' + ((e.notification.data && e.notification.data.url) || 'visites/'), self.registration.scope).href;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(cs => {
    const c = cs.find(x => x.url.startsWith(self.registration.scope));
    if (c) { c.navigate(cible); return c.focus(); }
    return self.clients.openWindow(cible);
  }));
});
