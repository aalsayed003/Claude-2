/*
 * Firebase configuration for the Oman Trip game.
 *
 * These values are safe to commit: Firebase web config is public by design.
 * `self` works in both the page and the service worker, so keep it as `self`.
 */
self.FIREBASE_CONFIG = {
  apiKey: "AIzaSyBgFDZN_RYipWxGCfsmh51pKmC2nbXD1fY",
  authDomain: "oman-6e33f.firebaseapp.com",
  databaseURL: "https://oman-6e33f-default-rtdb.firebaseio.com",
  projectId: "oman-6e33f",
  storageBucket: "oman-6e33f.firebasestorage.app",
  messagingSenderId: "697636525267",
  appId: "1:697636525267:web:0862a50a5db7279ebcb026",
  measurementId: "G-DT40PGH8LC"
};

// Web Push certificate key pair — only needed for Part B (push notifications).
// Firebase console → Project settings → Cloud Messaging → Web configuration.
// The game works fully without this; leave as-is until you set up push.
self.FIREBASE_VAPID_KEY = "PASTE_VAPID_PUBLIC_KEY";
