/* Le service worker de l'application de contrôle sur tablette : garder la COQUILLE (page, script,
 * styles, logo) pour qu'elle s'ouvre sans réseau. Les données vivent en IndexedDB côté module
 * (visites.js), jamais ici ; l'API n'est jamais mise en cache par le worker. Portée : /controle/. */
'use strict';

const VERSION = 'controle-v2';
// Le graphique de l'année (étape KPI) : les modules de Résultat › Année, chargés par visites.js, et leur style.
const COQUILLE = ['./', 'index.html', 'manifest.json', '../assets/js/visites.js', '../assets/ds/global.css', '../assets/css/controles-photo.css', '../assets/img/logo.png',
  '../assets/css/clients-annee.css', '../assets/js/clients-annee-vals.js', '../assets/js/clients-annee-tpl.js', '../assets/js/api.js'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(VERSION).then(c => c.addAll(COQUILLE).catch(() => null)).then(() => self.skipWaiting()));
});
// Les caches du site sont partagés entre ses applications : on ne retire que les anciennes versions des nôtres.
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k.startsWith('controle-') && k !== VERSION).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', e => {
  const u = new URL(e.request.url);
  if (e.request.method !== 'GET' || u.origin !== location.origin) { return; }
  if (u.pathname.includes('/api/')) { return; }
  const photo = u.pathname.includes('/uploads/');
  if (photo) {
    e.respondWith(caches.open(VERSION).then(async c => { const r = await c.match(e.request); if (r) { return r; } const n = await fetch(e.request); if (n.ok) { c.put(e.request, n.clone()); } return n; }));
    return;
  }
  // Le réseau d'abord (une correction arrive tout de suite), le cache en secours.
  e.respondWith(fetch(e.request).then(r => { if (r.ok) { caches.open(VERSION).then(c => c.put(e.request, r.clone())); } return r; })
    .catch(() => caches.match(e.request).then(r => r || caches.match('./'))));
});
