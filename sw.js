const CACHE = 'constructflow-v120';
const ASSETS = [
  './',
  './index.html',
  './login.html',
  './team.html',
  './inspector.html',
  './supervisor.html',
  './fieldworker.html',
  './admin.html',
  './style.css',
  './style-auth.css',
  './app.js',
  './print.html',
  './print.css',
  './print.js',
  './auth.js',
  './team.js',
  './create-team.html',
  './setup-account.html',
  './logo/logo.png',
  './img/hero-inspector.webp',
  './img/about-site.webp',
  './fonts/inter-variable-latin.woff2',
  './logo/icon-192.png',
  './logo/icon-512.png',
  './logo/favicon.ico',
  './logo/favicon-96.png',
  './logo/apple-touch-icon.png',
  'terms.html',
  'privacy.html'

];

self.addEventListener('install', e => {
  // Fetched past the browser's own HTTP cache. addAll() otherwise goes
  // through it, so bumping CACHE above could fill the new cache with the
  // very files the bump was meant to replace.
  e.waitUntil(caches.open(CACHE).then(c =>
    c.addAll(ASSETS.map(u => new Request(u, { cache: 'reload' })))
  ));
  self.skipWaiting(); // activate the new SW immediately instead of waiting for all tabs to close
});

self.addEventListener('activate', e => e.waitUntil(
  caches.keys()
    .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
    .then(() => self.clients.claim()) // take control of already-open tabs right away
));

// Two strategies, because they want opposite things.
//
//   Static assets (js, css, images, fonts) are versioned by CACHE above, so a
//   cached copy is always the right copy. Serve it straight from cache and
//   never wait on the network — this is what makes navigation feel instant
//   instead of paying a ~300ms round trip per file.
//
//   HTML stays network-first, so a deploy appears without waiting for the
//   next cache version, and still works offline from cache.
//
//   /api/ is never touched. Stale data would be worse than slow data.
const STATIC_RE = /\.(?:js|css|png|jpg|jpeg|webp|svg|ico|woff2?|ttf)$/i;

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;
  if (req.url.includes('/api/')) return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  if (STATIC_RE.test(url.pathname)) {
    e.respondWith(
      caches.match(req).then(hit => hit || fetch(req).then(res => {
        if (res.ok) { const copy = res.clone(); caches.open(CACHE).then(c => c.put(req, copy)); }
        return res;
      }))
    );
    return;
  }

  e.respondWith(
    fetch(req)
      .then(res => {
        if (res.ok) { const copy = res.clone(); caches.open(CACHE).then(c => c.put(req, copy)); }
        return res;
      })
      .catch(() => caches.match(req))
  );
});
