/* Service worker do SAA: exibe os avisos de ausência recebidos por Web Push. */
'use strict';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

/* Só abre caminhos do próprio sistema; nunca uma URL externa vinda do payload. */
function safePath(value) {
  return typeof value === 'string' && /^\/(?!\/)[A-Za-z0-9_\-\/]*$/.test(value) ? value : '/notificacoes';
}

self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (error) {
    data = {};
  }
  const title = typeof data.title === 'string' && data.title !== '' ? data.title : 'Aviso da escola';
  event.waitUntil(self.registration.showNotification(title, {
    body: typeof data.body === 'string' ? data.body : '',
    tag: typeof data.tag === 'string' ? data.tag : undefined,
    icon: '/assets/icon-192.png',
    badge: '/assets/badge-96.png',
    lang: 'pt-BR',
    data: { url: safePath(data.url) },
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = new URL(safePath(event.notification.data && event.notification.data.url), self.location.origin).href;
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
    for (const client of windows) {
      if (client.url === target && 'focus' in client) {
        return client.focus();
      }
    }
    return self.clients.openWindow(target);
  }));
});
