/*
 * Oman Trip — push relay Cloud Function.
 *
 * The web app writes a request to /rooms/{roomId}/notify like:
 *   { to: "O", title: "Ahmed made a move", body: "Your turn", at: <ts> }
 * This function looks up that player's saved FCM token at
 * /rooms/{roomId}/tokens/{to}, sends a push, and clears the request.
 *
 * Data-only message: the service worker (onBackgroundMessage) renders it,
 * which avoids a duplicate auto-notification.
 */
const { onValueWritten } = require("firebase-functions/v2/database");
const { initializeApp } = require("firebase-admin/app");
const { getDatabase } = require("firebase-admin/database");
const { getMessaging } = require("firebase-admin/messaging");

initializeApp();

exports.relayNotification = onValueWritten("/rooms/{roomId}/notify", async (event) => {
  const req = event.data.after.val();
  if (!req || !req.to) return null; // cleared or malformed — nothing to do

  const roomId = event.params.roomId;
  const db = getDatabase();

  let token = null;
  try {
    const tokenSnap = await db.ref(`rooms/${roomId}/tokens/${req.to}`).get();
    token = tokenSnap.val();
    // Clear the request so it doesn't re-fire (this write re-triggers with
    // after=null, which the guard above ignores).
    await db.ref(`rooms/${roomId}/notify`).remove();
  } catch (dbErr) {
    console.error("relay: database error", dbErr);
    return null;
  }

  if (!token) return null; // recipient hasn't enabled notifications

  try {
    await getMessaging().send({
      token,
      data: {
        title: String(req.title || "Oman Trip"),
        body: String(req.body || "It's your move.")
      },
      webpush: {
        headers: { Urgency: "high", TTL: "1800" },
        fcmOptions: { link: "./" }
      },
      android: { priority: "high" }
    });
  } catch (err) {
    // Token likely expired/unregistered — drop it so we stop trying.
    if (err && (err.code === "messaging/registration-token-not-registered" ||
                err.code === "messaging/invalid-registration-token")) {
      await db.ref(`rooms/${roomId}/tokens/${req.to}`).remove();
    } else {
      console.error("relay: send error", err);
    }
  }
  return null;
});
