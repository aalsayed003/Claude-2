# Firebase setup — real push notifications

The live game (`index.html`) uses **Firebase** so moves sync through a server
and your friend gets a **push notification even when the app is closed**. This
is a one-time setup. Budget ~15 minutes.

You'll create a free Firebase project, paste two things into
`firebase-config.js`, and deploy a small Cloud Function that sends the pushes.

> **Heads up on billing:** Cloud Functions require Firebase's **Blaze
> (pay-as-you-go)** plan, which asks for a card. For a game like this you'll
> stay comfortably inside the free monthly allowances, so the practical cost is
> **$0** — but the card is required to deploy functions.

---

## 1. Create the project

1. Go to <https://console.firebase.google.com> → **Add project**.
2. Name it (e.g. `oman-trip`), accept defaults, create.

## 2. Realtime Database

1. Left menu → **Build → Realtime Database → Create Database**.
2. Pick a location, start in **locked mode** (we deploy rules later).

## 3. Register the web app and copy its config

1. Project Overview → the **`</>`** (web) icon → give it a nickname → **Register**.
2. Firebase shows a `firebaseConfig = { apiKey: …, databaseURL: …, … }` object.
3. Copy those values into **`firebase-config.js`** in this repo, replacing every
   `PASTE_…` placeholder. Make sure `databaseURL` is included (it looks like
   `https://<project>-default-rtdb.firebaseio.com`).

## 4. Cloud Messaging key (for push)

1. Gear icon → **Project settings → Cloud Messaging** tab.
2. Under **Web configuration → Web Push certificates**, click **Generate key pair**.
3. Copy the **public key** string into `firebase-config.js` as
   `self.FIREBASE_VAPID_KEY`.

## 5. Install the CLI and point it at your project

```bash
npm install -g firebase-tools
firebase login
```

Edit **`.firebaserc`** and replace `PASTE_PROJECT_ID` with your project id
(shown in Project settings).

## 6. Deploy the database rules and the push function

```bash
cd functions && npm install && cd ..
firebase deploy --only database,functions
```

The first `functions` deploy is what prompts you to enable the **Blaze** plan.
This uploads `functions/index.js` (the `relayNotification` push relay) and the
rules in `database.rules.json`.

## 7. Put the game online

Either option gives you the shareable HTTPS link:

- **GitHub Pages** — Settings → Pages → deploy this branch, root folder.
- **Firebase Hosting** — `firebase deploy --only hosting` (config is already in
  `firebase.json`). Your link becomes `https://<project>.web.app`.

## 8. Play

1. Open the link on two phones, **Add to Home Screen** (install as an app).
2. Take a seat; when prompted, **allow notifications**.
3. Share the room link with your friend. Now moves sync live **and** push even
   when the app is closed.

### iPhone note

On iOS, web push only works when the site is **installed to the Home Screen**
(iOS 16.4+) and you've allowed notifications from inside the installed app.

---

## How it fits together

```
Your move ──▶ Realtime Database (/rooms/<id>)
                    │  write /rooms/<id>/notify {to, title, body}
                    ▼
             relayNotification (Cloud Function)
                    │  reads /rooms/<id>/tokens/<to>, sends FCM
                    ▼
           Friend's phone  ◀── push (even if app closed)
```

- `firebase-config.js` — your project's public config + VAPID key.
- `functions/index.js` — sends the push when a move/join is written.
- `database.rules.json` — access rules for `/rooms`.
- `sw.js` — service worker that shows the push when the app is in the background/closed.

## Security note

`database.rules.json` currently allows anyone with a room id to read/write that
room (no login), which keeps the game link-only and account-free. Room ids are
random, but this is not private — fine for a casual game between friends. Add
Firebase Auth if you ever need it locked down.
