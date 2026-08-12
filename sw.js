const CACHE = 'constructflow-v6';
const ASSETS = [
  './',
  './index.html',
  './login.html',
  './inspector.html',
  './supervisor.html',
  './fieldworker.html',
  './admin.html',
  './style.css',
  './app.js',
];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(ASSETS)));
  self.skipWaiting(); // activate the new SW immediately instead of waiting for all tabs to close
});

self.addEventListener('activate', e => e.waitUntil(
  caches.keys()
    .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
    .then(() => self.clients.claim()) // take control of already-open tabs right away
));

self.addEventListener('fetch', e => {
  const req = e.request;

  // Never let the SW cache/intercept API calls — those must always hit the
  // server fresh, or dashboards will silently show stale data.
  if (req.url.includes('/api/')) return;

  // Network-first for navigations (HTML pages) so a code change shows up
  // on the very next load instead of being served from a stale cache.
  if (req.mode === 'navigate') {
    e.respondWith(
      fetch(req).catch(() => caches.match(req))
    );
    return;
  }

  // Cache-first for static assets (css/js/icons) is fine.
  e.respondWith(caches.match(req).then(r => r || fetch(req)));
});
