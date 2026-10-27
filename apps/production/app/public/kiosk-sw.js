/*
 * Tablet grupy (PWA) : powłoka aplikacji w pamięci podręcznej, aby tablet uruchamiał się z ikony
 * także przy słabej sieci. Zawsze najpierw sieć (nowa wersja po wdrożeniu), pamięć tylko awaryjnie.
 * API nie jest buforowane : dane i zapisy zawsze idą do serwera (zapis rejestrowany raz dzięki client_uuid).
 */
const CACHE = 'tfb-kiosk-v4';
const BASE = new URL('./', self.location).pathname;
const SHELL = ['kiosk', 'assets/css/kiosk.css', 'assets/js/workshop/kiosk.js', 'resources/lang/ui/pl/kiosk.json', 'resources/lang/ui/fr/kiosk.json',
    'assets/kiosk/icon-192.png', 'assets/kiosk/icon-512.png', 'assets/kiosk/manifest.webmanifest'].map(p => BASE + p);

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then(c => Promise.all(SHELL.map(u => c.add(new Request(u, {credentials: 'include'})).catch(() => null)))).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k.startsWith('tfb-kiosk-') && k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    const url = new URL(req.url);
    if (req.method !== 'GET' || url.origin !== self.location.origin || url.pathname.includes('/api/')) return;
    const path = url.pathname;
    // Strona tabletu (/kiosk albo /tablet/production?team=N) : jedna kopia powłoki bez parametrów.
    const page = path === BASE + 'kiosk' || path === BASE + 'tablet/production' ? path : null;
    if (!page && !SHELL.includes(path)) return;
    event.respondWith(fetch(req).then((res) => {
        if (res.ok) { const copy = res.clone(); caches.open(CACHE).then(c => c.put(page || req, copy)); }
        return res;
    }).catch(() => caches.match(page || req)));
});
