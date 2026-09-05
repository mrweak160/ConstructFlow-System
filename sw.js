const CACHE = 'constructflow-v10';
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
  './auth.js',
  './team.js',
  './create-team.html',
  './setup-account.html',
  './logo/logo.svg',
  './logo/icon-192.png',
  './logo/icon-512.png',
  './logo/favicon.ico',
  './logo/favicon-96.png',
  './logo/apple-touch-icon.png'

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
  if (req.url.includes('/api/')) return;
  e.respondWith(
    fetch(req)
      .then(res => {
        if (req.method === 'GET' && res.ok && new URL(req.url).origin === self.location.origin) {
          const copy = res.clone();
          caches.open(CACHE).then(c => c.put(req, copy));
        }
        return res;
      })
      .catch(() => caches.match(req))
  );
});
