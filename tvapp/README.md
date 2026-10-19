# Middle East TV — LG webOS app

A small LG smart TV app for watching free live Arabic channels (Bahrain, Saudi, UAE, Kuwait,
Qatar, Oman, Egypt, Lebanon, Iraq, Jordan, Arabic news). Channel lists are loaded from the
public [iptv-org](https://github.com/iptv-org/iptv) playlists, which only list publicly available
streams. The lists are refreshed every 12 hours and saved on the TV.

Ready-to-install file: `dist/com.alsayed.mideasttv_1.0.0_all.ipk`

## Remote control

| Button | In the channel list | Full screen |
|---|---|---|
| ▲ / ▼ | Move up/down | Previous/next channel |
| CH+ / CH− | Jump 8 channels | Previous/next channel |
| ◀ / ▶ | Change category | Show channel name |
| OK | Play; press again for full screen | Show channel name |
| BACK | Return to full screen | Back to the list |
| RED | Add/remove favourite | Add/remove favourite |
| GREEN | Add your own M3U playlist link | — |

The Magic Remote pointer works too: click a channel to play it, and click the video to switch between full screen and the list.
The app remembers the last channel and opens on it.

## Installing on the TV (one-time, about 20 minutes)

LG only installs apps from its store, so a personal app goes in through **Developer Mode** (free).

### 1. On the TV
1. On a phone or PC, create a free account at <https://webostv.developer.lge.com> (Sign in → Create account).
2. On the TV, open **LG Content Store**, search **Developer Mode**, install and open it.
3. Sign in with that account, turn **Dev Mode Status** ON. The TV restarts.
4. Open Developer Mode again and turn **Key Server** ON. Write down the **IP address** and **Passphrase** it shows.

The PC and the TV must be on the same Wi-Fi/network.

### 2. On a Windows or Mac computer
1. Install **Node.js** (LTS) from <https://nodejs.org>.
2. Open Command Prompt (Windows) or Terminal (Mac) and run:
   ```
   npm install -g @webos-tools/cli
   ```
3. Register the TV (replace the IP with the one from step 1.4):
   ```
   ares-setup-device -a tv -i "host=192.168.1.50" -i "port=9922" -i "username=prisoner"
   ares-novacom --device tv --getkey
   ```
   Enter the **Passphrase** when asked.
4. Download `com.alsayed.mideasttv_1.0.0_all.ipk` from this folder and install it:
   ```
   ares-install --device tv com.alsayed.mideasttv_1.0.0_all.ipk
   ares-launch --device tv com.alsayed.mideasttv
   ```

"Middle East TV" now appears in the TV's app bar.

### Keeping it installed
The Developer Mode session lasts **50 hours**. Open the Developer Mode app and press
**Extend** before it runs out. If it expires, the TV removes the app; turn Dev Mode back
on and run the `ares-install` command again.

## Notes
- Some streams are geo-blocked or not live all day. If a channel fails, the app says so after
  about 20 seconds; try another one.
- Channels that need special headers are left out because the TV player can't send them.
- To change the app: edit the files in `app/`, then rebuild with
  `ares-package --no-minify app -o dist`.
