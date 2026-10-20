/* Minimal service worker: makes the app installable. No caching of personal data;
   every request goes to the network. */
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (event) { event.waitUntil(self.clients.claim()); });
self.addEventListener('fetch', function () { /* network only */ });
