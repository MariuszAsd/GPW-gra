/* Service worker Maklerii: instalowalna aplikacja (PWA), strona offline i powiadomienia push.
   Strony gry NIE są cache'owane (kursy muszą być świeże) — tylko zasoby statyczne i strona offline. */
const CACHE = 'makleria-v1';
const PRECACHE = ['assets/app.css', 'assets/icon-192.png', 'offline.html'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin === location.origin && url.pathname.includes('/assets/')) {
    // zasoby: z cache, a w tle odświeżenie (po deployu nowe style dojadą przy następnym wejściu)
    e.respondWith(caches.open(CACHE).then(async c => {
      const cached = await c.match(req);
      const net = fetch(req).then(res => { if (res.ok) c.put(req, res.clone()); return res; }).catch(() => cached);
      return cached || net;
    }));
    return;
  }
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match('offline.html')));
  }
});
self.addEventListener('push', e => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (x) { d = { body: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(d.title || 'Makleria', {
    body: d.body || '', icon: 'assets/icon-192.png', badge: 'assets/icon-192.png',
    data: { url: d.url || 'powiadomienia.php' }, tag: d.tag || undefined, renotify: !!d.tag
  }));
});
self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = new URL((e.notification.data && e.notification.data.url) || 'powiadomienia.php', self.registration.scope).href;
  e.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(ws => {
    for (const w of ws) { if ('focus' in w) { w.navigate(url); return w.focus(); } }
    return clients.openWindow(url);
  }));
});
