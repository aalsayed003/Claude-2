/* Oman Trip — service worker: offline shell, notification handling, future push */
var CACHE = "oman-ttt-v1";
var ASSETS = [
  "./",
  "./index.html",
  "./vendor/peerjs.min.js",
  "./manifest.webmanifest",
  "./icons/icon-192.png",
  "./icons/icon-512.png",
  "./icons/maskable-512.png"
];

self.addEventListener("install", function (e) {
  e.waitUntil(
    caches.open(CACHE).then(function (c) { return c.addAll(ASSETS); })
      .then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener("activate", function (e) {
  e.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.map(function (k) { if (k !== CACHE) return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); })
  );
});

/* Network-first so players always get the latest build when online;
   the cache is only a fallback when offline. */
self.addEventListener("fetch", function (e) {
  var req = e.request;
  if (req.method !== "GET") return;
  e.respondWith(
    fetch(req).then(function (res) {
      if (res && res.ok && new URL(req.url).origin === self.location.origin) {
        var copy = res.clone();
        caches.open(CACHE).then(function (c) { c.put(req, copy); });
      }
      return res;
    }).catch(function () { return caches.match(req); })
  );
});

/* Tapping a notification focuses the game (or opens it). */
self.addEventListener("notificationclick", function (e) {
  e.notification.close();
  e.waitUntil(
    self.clients.matchAll({ type: "window", includeUncontrolled: true }).then(function (cs) {
      for (var i = 0; i < cs.length; i++) { if ("focus" in cs[i]) return cs[i].focus(); }
      if (self.clients.openWindow) return self.clients.openWindow("./");
    })
  );
});

/* Web Push — only fires if a backend push server is added later. */
self.addEventListener("push", function (e) {
  var data = { title: "Oman Trip", body: "It's your move." };
  try { if (e.data) data = Object.assign(data, e.data.json()); } catch (_) {}
  e.waitUntil(self.registration.showNotification(data.title, {
    body: data.body,
    icon: "./icons/icon-192.png",
    badge: "./icons/icon-192.png",
    tag: "oman-ttt"
  }));
});
