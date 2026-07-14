/* Oman Trip — service worker: offline shell + Firebase Cloud Messaging background push */
var CACHE = "oman-ttt-v2";
var ASSETS = [
  "./",
  "./index.html",
  "./firebase-config.js",
  "./vendor/firebase/firebase-app-compat.js",
  "./vendor/firebase/firebase-database-compat.js",
  "./vendor/firebase/firebase-messaging-compat.js",
  "./manifest.webmanifest",
  "./icons/icon-192.png",
  "./icons/icon-512.png",
  "./icons/maskable-512.png"
];

/* Set up Firebase Messaging so pushes arrive when the app is closed.
   No-ops until firebase-config.js is filled in. */
try {
  importScripts("firebase-config.js");
  importScripts("vendor/firebase/firebase-app-compat.js");
  importScripts("vendor/firebase/firebase-messaging-compat.js");
  var cfg = self.FIREBASE_CONFIG;
  if (cfg && cfg.apiKey && cfg.apiKey !== "PASTE_API_KEY" && self.firebase && !firebase.apps.length) {
    firebase.initializeApp(cfg);
    var messaging = firebase.messaging();
    messaging.onBackgroundMessage(function (payload) {
      var d = payload.data || payload.notification || {};
      self.registration.showNotification(d.title || "Oman Trip", {
        body: d.body || "",
        icon: "./icons/icon-192.png",
        badge: "./icons/icon-192.png",
        tag: "oman-ttt",
        renotify: true
      });
    });
  }
} catch (e) { /* messaging not available yet — fine */ }

self.addEventListener("install", function (e) {
  e.waitUntil(
    caches.open(CACHE).then(function (c) {
      // Cache best-effort; a single missing file shouldn't fail the whole install.
      return Promise.all(ASSETS.map(function (a) { return c.add(a).catch(function () {}); }));
    }).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener("activate", function (e) {
  e.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.map(function (k) { if (k !== CACHE) return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); })
  );
});

/* Network-first so players always get the latest build when online. */
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
