# Tic Tac Toe — Oman Trip edition

Play tic-tac-toe live with a friend over one shared link. There are three
versions, in decreasing order of setup:

| File | What it is | Setup |
| --- | --- | --- |
| `index.html` | **Firebase version.** Live sync through Firebase + **push notifications even when the app is closed**. Installs as a phone app. | Needs a free Firebase project — see **[FIREBASE-SETUP.md](FIREBASE-SETUP.md)**. |
| `p2p.html` | **Peer-to-peer version.** Live sync directly between browsers, no server, no account. Notifications only while the app is open/alive. | None. |
| `pass-and-play.html` | **Link-passing version.** Each move makes a link you send back and forth. Works on any network. | None. |

If you just want to play now with zero setup, use `p2p.html`. For the full
installable app with real push notifications, set up `index.html` via
FIREBASE-SETUP.md.

## How the Firebase version works

Moves are written to a Firebase **Realtime Database** room, so both players stay
in sync and the state persists. When a player joins or moves, a **Cloud
Function** (`functions/index.js`) sends a push via Firebase Cloud Messaging to
the other player — which is what lets a notification arrive even when their app
is fully closed.

1. Take a seat (pick Ahmed/Hawah and X/O), which creates a room and a link.
2. Send your friend the link. They take the open seat.
3. Play live. Moves sync instantly; either player can rematch.

See **[FIREBASE-SETUP.md](FIREBASE-SETUP.md)** for the one-time project setup.

## How the peer-to-peer version (`p2p.html`) works

It's **peer-to-peer** (WebRTC): the two browsers talk directly to each other.
A free public signaling service (PeerJS Cloud) only introduces the two browsers —
the actual game moves never touch any server.

1. Take a seat — you get a link. Send it to your friend.
2. They open it, and you play live. Moves sync instantly; there's a rematch button.

Because it's peer-to-peer with no relay (TURN) server, a small number of very
restrictive networks (some corporate/school firewalls) may block the direct
connection. If that ever happens, use `pass-and-play.html` instead — it always works.

## Install it as an app (PWA)

The live version is a Progressive Web App, so it installs to a phone's home
screen and opens fullscreen like a native app.

- **iPhone/iPad (Safari):** open the site → Share → **Add to Home Screen**.
- **Android (Chrome):** open the site → menu → **Install app** (or the install prompt).

`manifest.webmanifest`, `sw.js` (service worker), and `icons/` provide the app
name, icon, and offline shell.

## Notifications

When you take your seat the app asks for notification permission. After that it
alerts you when your friend **joins** and when they **make a move**.

- **`index.html` (Firebase):** pushes arrive **even when the app is fully
  closed**, because moves go through the server and a Cloud Function sends the
  push. This is the recommended setup — see FIREBASE-SETUP.md.
- **`p2p.html` (peer-to-peer):** alerts only fire while the app is **open or
  still running in the background**. If the app is fully closed there's nothing
  running to receive your friend's move, so no notification can arrive.

## Hosting the peer-to-peer / link versions (GitHub Pages)

`p2p.html` and `pass-and-play.html` are pure static files, so GitHub Pages hosts
them for free (the Firebase version can also be hosted here, or on Firebase
Hosting — see FIREBASE-SETUP.md):

1. Push this branch to GitHub.
2. In the repo: **Settings → Pages**.
3. Under **Build and deployment → Source**, pick **Deploy from a branch**.
4. Choose this branch and the **`/ (root)`** folder, then **Save**.
5. After a minute your game is live at `https://<username>.github.io/<repo>/`.

That URL is the link you share to start playing.

## Running it locally

Because the live version loads a script file, open it through a local server
(not `file://`):

```bash
python3 -m http.server 8080
# then visit http://localhost:8080/
```
