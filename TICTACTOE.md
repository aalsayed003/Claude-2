# Tic Tac Toe

Two small, self-contained tic-tac-toe games you can play with a friend over the internet.
No build step, no account, no game server of your own to run.

| File | What it is |
| --- | --- |
| `index.html` | **Live version.** You and your friend see moves appear on both screens in real time. |
| `pass-and-play.html` | **Link-passing version.** Each move produces a link you send back and forth. Works on any network, even when live can't connect. |
| `vendor/peerjs.min.js` | Bundled peer-to-peer library used by the live version (no CDN needed). |

## How the live version works

It's **peer-to-peer** (WebRTC): the two browsers talk directly to each other.
A free public signaling service (PeerJS Cloud) is only used to introduce the two
browsers — the actual game moves never touch any server.

1. You open the page and click **Create a game**.
2. You get a link. Send it to your friend (WhatsApp, iMessage, anything).
3. They open it, and you play live. Moves sync instantly; there's a rematch button.

Because it's peer-to-peer with no relay (TURN) server, a small number of very
restrictive networks (some corporate/school firewalls) may block the direct
connection. If that ever happens, use `pass-and-play.html` instead — it always works.

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
