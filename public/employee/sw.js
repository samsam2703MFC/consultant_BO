/* Le service worker de l'app employés : son chemin se déduit de l'adresse du script (l'app vit sous /consulant_bo/employee). */
const BASE = self.location.pathname.replace(/sw\.js$/, "");
const VERSION = "v1.2.0";
const STATIC_CACHE = `static-${VERSION}`;
const RUNTIME_CACHE = `runtime-${VERSION}`;

// Podmień/rozszerz listę pod swoje kluczowe pliki:
const PRECACHE_URLS = [
    BASE + "assets/bootstrap-icons/bootstrap-icons.css",
    BASE + "assets/atelier/app.css",
    BASE + "assets/atelier/marque.css",
];

self.addEventListener("install", (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE).then((cache) => cache.addAll(PRECACHE_URLS))
    );
    self.skipWaiting();
});

self.addEventListener("activate", (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(
            keys
                .filter((k) => ![STATIC_CACHE, RUNTIME_CACHE].includes(k))
                .map((k) => caches.delete(k))
        );
        await self.clients.claim();
    })());
});

function isGET(req) {
    return req.method === "GET";
}

function isStaticAsset(url) {
    return (
        url.pathname.startsWith(BASE + "assets/") ||
        url.pathname.endsWith(".css") ||
        url.pathname.endsWith(".js") ||
        url.pathname.endsWith(".png") ||
        url.pathname.endsWith(".jpg") ||
        url.pathname.endsWith(".jpeg") ||
        url.pathname.endsWith(".svg") ||
        url.pathname.endsWith(".webp") ||
        url.pathname.endsWith(".woff2") ||
        url.pathname.endsWith(".woff") ||
        url.pathname.endsWith(".ttf")
    );
}

function isApiGET(url) {
    return (
        url.pathname.startsWith(BASE + "ajax/") ||
        url.pathname.startsWith("/api/")
    );
}

self.addEventListener("fetch", (event) => {
    const req = event.request;
    if (!isGET(req)) return; // nie cache’ujemy POST/PUT/DELETE

    const url = new URL(req.url);

    // Nie ruszamy innych originów poza fontami
    const isSameOrigin = url.origin === self.location.origin;
    const isGoogleFonts = url.origin.includes("fonts.googleapis.com") || url.origin.includes("fonts.gstatic.com");

    // 1) Les feuilles de style et les scripts : le réseau d'abord, le cache en secours (hors ligne).
    //    Une vieille feuille de style gardée après un déploiement cassait l'écran (07/10/2026).
    if (isSameOrigin && isStaticAsset(url) && (url.pathname.endsWith(".css") || url.pathname.endsWith(".js"))) {
        event.respondWith((async () => {
            const cache = await caches.open(RUNTIME_CACHE);
            try {
                const res = await fetch(req);
                if (res && res.status === 200) cache.put(req, res.clone());
                return res;
            } catch (e) {
                const cached = await cache.match(req);
                if (cached) return cached;
                throw e;
            }
        })());
        return;
    }

    // 1 bis) Cache-first pour les images et les polices
    if (isSameOrigin && isStaticAsset(url)) {
        event.respondWith((async () => {
            const cached = await caches.match(req);
            if (cached) return cached;
            const res = await fetch(req);
            const cache = await caches.open(RUNTIME_CACHE);
            cache.put(req, res.clone());
            return res;
        })());
        return;
    }

    // 2) Network-first dla GET z API (żeby dane były świeże)
    if (isSameOrigin && isApiGET(url)) {
        event.respondWith((async () => {
            const cache = await caches.open(RUNTIME_CACHE);
            try {
                const res = await fetch(req);
                // cache tylko jeśli 200
                if (res && res.status === 200) cache.put(req, res.clone());
                return res;
            } catch (e) {
                const cached = await cache.match(req);
                if (cached) return cached;
                return new Response(JSON.stringify({ error: "offline" }), {
                    status: 503,
                    headers: { "Content-Type": "application/json" }
                });
            }
        })());
        return;
    }

    // 3) Fonts runtime cache
    if (isGoogleFonts) {
        event.respondWith((async () => {
            const cached = await caches.match(req);
            if (cached) return cached;
            const res = await fetch(req);
            const cache = await caches.open(RUNTIME_CACHE);
            cache.put(req, res.clone());
            return res;
        })());
        return;
    }

    // 4) Default: network fallback to cache
    event.respondWith((async () => {
        try {
            return await fetch(req);
        } catch (e) {
            const cached = await caches.match(req);
            if (cached) return cached;

            // opcjonalny fallback na stronę offline
            if (req.mode === "navigate") {
                return Response.redirect(BASE + "offline", 302);
            }
            throw e;
        }
    })());
});
