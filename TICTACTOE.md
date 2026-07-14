# Tic Tac Toe

Two small, self-contained tic-tac-toe games you can play with a friend over the internet.
No build step, no account, no game server of your own to run.

| File | What it is | Setup |
| --- | --- | --- |
| `index.html` | **Firebase version** — the "game diary": live play synced through Firebase (reliable on mobile/cellular), **push notifications even when closed**, a running **score by player** (Ahmed vs Hawah), and a **dated log of every game** shared between both players. | Needs a free Firebase project — see **[FIREBASE-SETUP.md](FIREBASE-SETUP.md)**. |
| `p2p.html` | **Peer-to-peer version** — same game/diary, no server or setup, but the direct connection can fail on some mobile networks. | None. |
| `pass-and-play.html` | **Link-passing version.** Each move makes a link you send back and forth. Works on any network. | None. |

Until `firebase-config.js` is filled in, `index.html` shows a "set up Firebase
first" notice. If you want to play right now with zero setup, use `p2p.html`.

## How the Firebase version works

Moves are written to a Firebase **Realtime Database** room, so both phones stay
in sync no matter their network — this is what fixes the "won't connect on
cellular" problem of pure peer-to-peer. Finished games are saved to a shared
diary in the database, so the **score and dated log are the same on both
phones** and persist across sessions. A **Cloud Function** sends a push when a
friend joins or moves, so you can be notified even when the app is closed.

See **[FIREBASE-SETUP.md](FIREBASE-SETUP.md)** for the one-time project setup.

## How the peer-to-peer version works

It's **peer-to-peer** (WebRTC): the two browsers talk directly to each other.
A free public signaling service (PeerJS Cloud) is only used to introduce the two
browsers — the actual game moves never touch any server.

1. You open the page and click **Create a game**.
2. You get a link. Send it to your friend (WhatsApp, iMessage, anything).
3. They open it, and you play live. Moves sync instantly; there's a rematch button.

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
alerts you when:

- your friend **joins** the game, and
- your friend **makes a move** (so you know it's your turn).

**Important limitation:** because the game is peer-to-peer with no server, these
alerts only fire while the app is **open or still running in the background**.
If the app is fully closed, nothing is running to receive your friend's move, so
no notification can arrive. True "notify even when the app is closed" push
requires a backend push server (e.g. Firebase Cloud Messaging), which would mean
routing the game through a server instead of pure peer-to-peer. The service
worker already has a `push` handler ready for that if it's added later.

## Hosting it (GitHub Pages)

The whole thing is static files, so GitHub Pages hosts it for free:

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
