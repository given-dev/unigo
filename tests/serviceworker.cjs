const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

(async () => {
    let checks = 0;
    for (const base of ['http://localhost/', 'http://localhost/unigo/public/']) {
        const listeners = {};
        const context = {
            self: {location: new URL('sw.js', base), addEventListener: (name, callback) => { listeners[name] = callback; },
                skipWaiting: async () => {}, clients: {claim: async () => {}}},
            URL, Response, caches: {}, fetch: async () => new Response('asset'),
        };
        let precached;
        context.caches.open = async () => ({addAll: async paths => { precached = paths; }});
        vm.runInNewContext(fs.readFileSync('public/sw.js', 'utf8'), context);
        let installed;
        listeners.install({waitUntil: promise => { installed = promise; }});
        await installed;
        assert(!precached.some(path => new URL(path, base).href === base), 'Do not precache the dynamic homepage'); checks++;
        assert(precached.some(path => path.includes('offline.html')), 'Precache the offline fallback'); checks++;
        for (const path of ['api/session', 'profile', 'profile.css', 'uploads/private.png']) {
            let intercepted = false;
            listeners.fetch({request: {method: 'GET', url: new URL(path, base).href, mode: 'cors', headers: new Headers()},
                respondWith: () => { intercepted = true; }});
            assert(!intercepted, 'Never cache dynamic or uploaded data: ' + path); checks++;
        }
        let removed = [];
        context.caches.keys = async () => ['other-app-cache', 'unigo-static-v1', context.VERSION];
        context.caches.delete = async key => { removed.push(key); };
        let activated;
        listeners.activate({waitUntil: promise => { activated = promise; }});
        await activated;
        assert.deepEqual(removed, ['unigo-static-v1'], 'Only delete obsolete UniGo caches'); checks++;
    }
    console.log('Service worker regression checks passed:', checks);
})().catch(error => { console.error(error); process.exitCode = 1; });
