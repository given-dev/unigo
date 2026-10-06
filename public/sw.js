/* ==========================================================================
   UniGo - service worker
   --------------------------------------------------------------------------
   Offline strategy (deliberately conservative):
     - static assets (CSS / JS / icons / fonts)  -> cache first, refreshed in
       the background, so the shell boots without a network
     - navigation requests                        -> network only, with an
       offline.html fallback page
     - /api/* and any other request               -> never cached

   Authenticated HTML and JSON responses are intentionally NOT cached: a shared
   device must never show one passenger's data to the next signed-in user.
   ========================================================================== */
'use strict';

var VERSION = 'unigo-static-v2';
var SCOPE_URL = new URL('./', self.location.href);
var PRECACHE = [
    './offline.html',
    './manifest.webmanifest',
    './assets/css/unigo.css',
    './assets/css/layout.css',
    './assets/css/icons.css',
    './assets/js/app.js',
    './assets/img/favicon.svg'
];

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(VERSION)
            .then(function (cache) { return cache.addAll(PRECACHE); })
            .then(function () { return self.skipWaiting(); })
            .catch(function () { return self.skipWaiting(); })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys()
            .then(function (keys) {
                return Promise.all(keys.map(function (key) {
                    return key !== VERSION && key.indexOf('unigo-static-') === 0 ? caches.delete(key) : null;
                }));
            })
            .then(function () { return self.clients.claim(); })
    );
});

self.addEventListener('fetch', function (event) {
    var request = event.request;
    if (request.method !== 'GET') {
        return;
    }

    var url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return; // tiles, fonts and CDNs are handled by the browser
    }
    if (url.pathname.indexOf(SCOPE_URL.pathname) !== 0) return;
    var path = url.pathname.slice(SCOPE_URL.pathname.length);

    // Never cache dynamic data.
    if (path.indexOf('api/') === 0 || request.headers.get('X-Requested-With') === 'XMLHttpRequest') {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(function () {
                return caches.match(new URL('offline.html', SCOPE_URL).href).then(function (cached) {
                    return cached || new Response(
                        '<h1>You are offline</h1><p>Reconnect to load UniGo.</p>',
                        { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
                    );
                });
            })
        );
        return;
    }

    if (path.indexOf('assets/') === 0 && /\.(css|js|svg|png|jpe?g|webp|woff2?)$/i.test(path)) {
        event.respondWith(
            caches.match(request).then(function (cached) {
                var network = fetch(request).then(function (response) {
                    if (response && response.ok && response.type === 'basic') {
                        var copy = response.clone();
                        caches.open(VERSION).then(function (cache) { cache.put(request, copy); });
                    }
                    return response;
                }).catch(function () { return cached; });
                return cached || network;
            })
        );
    }
});

self.addEventListener('message', function (event) {
    if (event.data === 'skip-waiting') {
        self.skipWaiting();
    }
});
