/*
 * Firebase configuration — FILL THIS IN with your own project's values.
 * See FIREBASE-SETUP.md for step-by-step instructions.
 *
 * These values are safe to commit: Firebase web config is public by design.
 * `self` works in both the page and the service worker, so keep it as `self`.
 */
self.FIREBASE_CONFIG = {
  apiKey: "PASTE_API_KEY",
  authDomain: "PASTE_PROJECT_ID.firebaseapp.com",
  databaseURL: "https://PASTE_PROJECT_ID-default-rtdb.firebaseio.com",
  projectId: "PASTE_PROJECT_ID",
  messagingSenderId: "PASTE_SENDER_ID",
  appId: "PASTE_APP_ID"
};

// Web Push certificate key pair — "Public key" from
// Firebase console → Project settings → Cloud Messaging → Web configuration.
self.FIREBASE_VAPID_KEY = "PASTE_VAPID_PUBLIC_KEY";
