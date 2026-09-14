// Weeklyst Service Worker — push notificaties + badge

self.addEventListener('push', event => {
  if (!event.data) return;
  let data = {};
  try { data = event.data.json(); } catch(e) { data = { title: 'Weeklyst', body: event.data.text() }; }

  const options = {
    body:     data.body  || 'De lijst is bijgewerkt',
    icon:     data.icon  || '/icon-192.png',
    badge:    data.badge || '/icon-192.png',
    tag:      'weeklyst-update',
    renotify: true,
    data:     { url: '/app.html' },
  };

  event.waitUntil(
    Promise.all([
      // Toon notificatie
      self.registration.showNotification(data.title || 'Weeklyst', options),
      // Zet badge op icoon (iOS 16.4+ en sommige Android)
      navigator.setAppBadge ? navigator.setAppBadge(1) : Promise.resolve(),
    ])
  );
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
      for (const c of list) {
        if (c.url.includes('/app.html') && 'focus' in c) {
          // Wis badge wanneer gebruiker app opent via notificatie
          if (navigator.clearAppBadge) navigator.clearAppBadge();
          return c.focus();
        }
      }
      if (clients.openWindow) return clients.openWindow('/app.html');
    })
  );
});

// Wis badge wanneer app wordt geopend
self.addEventListener('message', event => {
  if (event.data === 'app_opened') {
    if (navigator.clearAppBadge) navigator.clearAppBadge();
  }
});

const CACHE = 'weeklyst-v1.2';
self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(['/app.html', '/lang.js'])));
  self.skipWaiting();
});
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(ks =>
    Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k)))
  ));
  self.clients.claim();
});
