# Firebase setup

This makes the game sync through Firebase so it **connects reliably between
phones** (including on cellular), with a shared score and dated log.

There are two parts:

- **Part A — Get it connecting (free, ~10 min, no card, no command line).** This
  is all you need to fix the "won't connect" problem.
- **Part B — Optional: push notifications even when the app is closed.** This one
  needs the Blaze plan (a card, but effectively $0) and the Firebase CLI.

You can stop after Part A and have a fully working, reliable game.

---

## Part A — Get it connecting (free)

### 1. Create the project
1. Go to <https://console.firebase.google.com> → **Add project**.
2. Name it (e.g. `oman-trip`), accept defaults, create. (You can skip Google
   Analytics.)

### 2. Turn on Realtime Database
1. Left menu → **Build → Realtime Database → Create Database**.
2. Pick any location → start in **test mode** (we'll paste exact rules next).

### 3. Paste the security rules
1. In Realtime Database → **Rules** tab.
2. Replace what's there with the contents of **`database.rules.json`** from this
   repo, then **Publish**.

### 4. Register the web app and get the config
1. Project Overview → click the **`</>`** (web) icon → give it a nickname →
   **Register app**.
2. Firebase shows a `const firebaseConfig = { apiKey: "…", databaseURL: "…", … }`.
3. Copy those values into **`firebase-config.js`**, replacing every `PASTE_…`.
   Make sure `databaseURL` is included (looks like
   `https://<project>-default-rtdb.firebaseio.com`).

> Tip: these values are **public and safe to share** — you can paste the whole
> `firebaseConfig` block to me and I'll fill in `firebase-config.js` for you.

### 5. Done — play
Commit `firebase-config.js`, make sure GitHub Pages is on, and open the site on
both phones. It now syncs through Firebase: reliable connection, shared score,
dated log. (The "set up Firebase" notice disappears once the config is filled.)

---

## Part B — Optional: push when the app is closed

Only do this if you want a notification to arrive when your friend moves **while
your app is fully closed**. It needs the **Blaze** plan (pay-as-you-go — asks for
a card, but real usage here stays inside the free allowance, so ~$0).

### 1. Cloud Messaging key
1. Gear → **Project settings → Cloud Messaging**.
2. Under **Web configuration → Web Push certificates → Generate key pair**.
3. Copy the **public key** into `firebase-config.js` as `self.FIREBASE_VAPID_KEY`.

### 2. Install the CLI and point it at your project
```bash
npm install -g firebase-tools
firebase login
```
Edit **`.firebaserc`** → replace `PASTE_PROJECT_ID` with your project id.

### 3. Deploy the push function
```bash
cd functions && npm install && cd ..
firebase deploy --only functions
```
This first deploy is what prompts you to enable **Blaze**. It uploads
`functions/index.js`, which sends the push when someone joins or moves.

### iPhone note
On iOS, web push only works when the site is **added to the Home Screen**
(iOS 16.4+) and you've allowed notifications from inside the installed app.

---

## How it fits together

```
A move ──▶ Realtime Database  ──(live)──▶  both phones update
                     │  (Part B only) writes /rooms/<id>/notify
                     ▼
              relayNotification (Cloud Function) ──▶ FCM push ──▶ closed app
Finished games ──▶ /diaries/<pair>  ──▶  shared score + dated log on both phones
```

## Security note
`database.rules.json` lets anyone with a room link read/write that room, and
anyone read/write the shared diary — no login, which keeps it link-only and
account-free. Fine for a casual game between friends; add Firebase Auth if you
ever want it locked down.
