/* Lumeron PWA: offline shell PUBLIC ONLY. No cached authenticated resources. */
'use strict';
const SHELL_CACHE = 'lumeron-public-shell-v1';
const OFFLINE_URL = '/pwa/offline.html';
const SHELL_ASSETS = [OFFLINE_URL];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL_CACHE).then((cache) => cache.addAll(SHELL_ASSETS))
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    // Never delete unrelated caches belonging to other applications on this origin.
    const cacheKeys = await caches.keys();
    await Promise.all(cacheKeys.filter((key) =>
      key.startsWith('lumeron-public-shell-') && key !== SHELL_CACHE
    ).map((key) => caches.delete(key)));
    await self.clients.claim();
  })());
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'LUMERON_ACTIVATE_UPDATE') {
    self.skipWaiting();
  }
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET' || request.mode !== 'navigate') return;
  if (new URL(request.url).origin !== self.location.origin) return;
  // Network-only: authentication, CSRF, commercial license and company identity
  // are always verified on the server. Never cache HTML or JSON responses.
  event.respondWith(fetch(request).catch(async () => {
    const shell = await caches.match(OFFLINE_URL);
    return shell || Response.error();
  }));
});
