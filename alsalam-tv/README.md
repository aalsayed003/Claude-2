# Al Salam TV — hospital TV box

A small Raspberry Pi behind each TV. It starts straight into a screen branded for Al Salam
Specialist Hospital: no Android, no LG menus, no logins. Turn the TV on and the hospital
screen is there.

**Patient room mode** (`"mode": "room"`):
- Welcome screen with the room number, date and time, Wi-Fi details and phone number
- **Live TV**: free Arabic and Gulf channels
- **Health Videos**: the hospital's own education videos
- **Hospital Information**: visiting hours, services, patient rights, contact details
- A scrolling announcement bar at the bottom

**Waiting-area mode** (`"mode": "waiting"`):
- A live channel or a loop of hospital videos, plus rotating notices (doctor schedules,
  promotions) and the announcement bar
- If a channel stops working, it moves to the next one by itself

Everything is in English and Arabic.

---

## 1. What to buy (per TV)

| Item | Notes | Approx. price |
|---|---|---|
| Raspberry Pi 5 (4 GB) | Raspberry Pi 4 (4 GB) also works | BHD 25–30 |
| Official power supply | 27 W USB-C for Pi 5 (15 W for Pi 4) | BHD 5 |
| Case with fan | Any Pi 5 case | BHD 5 |
| microSD card, 32 GB | SanDisk or Samsung "High Endurance" lasts longer | BHD 5 |
| Micro-HDMI to HDMI cable | The Pi has micro-HDMI ports | BHD 3 |
| *Optional:* air-mouse remote (2.4 GHz USB) | Only if the TV's own remote doesn't work with the box (see section 5) | BHD 5 |

You also need, once only, a USB keyboard and a PC to prepare the memory card.

## 2. Prepare the memory card (on any PC)

1. Install **Raspberry Pi Imager** from <https://www.raspberrypi.com/software/>.
2. Choose: Device = your Pi model, OS = **Raspberry Pi OS (other) → Raspberry Pi OS Lite (64-bit)**,
   Storage = the microSD card.
3. Click **Next**. When it offers OS customisation, choose **Edit settings** (newer Imager versions show these as steps instead):
   - Hostname: e.g. `alsalam-room214`
   - Username: `alsalam` and a password (keep it safe)
   - Wi-Fi: the hospital network name and password, country **BH** (or use a network cable)
   - Time zone: **Asia/Bahrain**
   - Services tab: enable **SSH** (lets IT manage it remotely later)
4. Write the card. When it finishes, take the card out and put it back into the PC.
   A drive called **bootfs** appears.
5. Unzip `alsalam-tv.zip` and copy the whole **alsalam-tv** folder onto the **bootfs** drive.
6. Open `alsalam-tv/data/config.json` with Notepad and set the room number, Wi-Fi details and
   texts (see section 4). You can also do this later.

## 3. First start (about 15 minutes, once per box)

1. Put the card in the Pi, connect the Pi to the TV, plug in a USB keyboard, then the power.
2. Wait for the login prompt (the first start takes a few minutes). Log in with the username and password from step 2.3.
3. Type this and press Enter (the Pi needs internet for this step):
   ```
   sudo bash /boot/firmware/alsalam-tv/pi/install.sh
   ```
4. When it finishes, the Pi restarts and shows the Al Salam logo, then the Al Salam screen.
   Unplug the keyboard. From now on, it starts by itself every time it gets power.

**Once everything works**, protect the card against power cuts (recommended for TVs that
are switched off at the wall): plug the keyboard back in, press **Ctrl+Alt+F2**, log in and run
`sudo raspi-config nonint do_overlayfs 0 && sudo reboot`. In this mode nothing on the card
can be damaged, but changes made while it runs are not kept. Edit `config.json` from a PC as
normal; to update the system, turn it off with `sudo raspi-config nonint do_overlayfs 1`.

## 4. Changing the content — `config.json`

Turn off the Pi, put the memory card in a PC, and edit `alsalam-tv/data/config.json` on the
**bootfs** drive with Notepad. Keep the quotes and commas exactly as they are.

| Setting | What it does |
|---|---|
| `"mode"` | `"room"` for patient rooms, `"waiting"` for waiting areas |
| `"roomNumber"` | Shown at the top, e.g. `"214"` |
| `"welcome"` | Welcome text (English and Arabic) |
| `"contact"` | Phone, website, `wifiName`, `wifiPassword` |
| `"infoPages"` | The Hospital Information pages: add, remove or edit them |
| `"announcements"` | The scrolling bar at the bottom |
| `"channels"` | Optional hand-picked channel list shown first as "Recommended": `{"name": "...", "url": "...m3u8"}` |
| `"channelLists"` | Free channel lists (by country) |
| `"blockedWords"` | Hide channels whose name contains these words, e.g. `["music", "movies"]` |
| `"videoTitles"` | Nice titles for videos: `"hand-washing.mp4": {"en": "Hand washing", "ar": "غسل اليدين"}` |
| `"waiting"` | Waiting area: `"main": "tv"` or `"videos"`, a fixed `channelUrl`, `muted`, and the `slides` |
| `"remoteConfigUrl"` | Optional: a web link to a config.json that all boxes read every 30 minutes (one place to update all TVs) |

**Videos:** copy MP4 files into `alsalam-tv/data/videos`. The card has about 400 MB free
there. For more, use a USB stick: name it **ALSALAMTV**, make a folder **videos** on it,
copy the files in and plug it into the Pi.

**Slides (waiting area):** copy images into `alsalam-tv/data/slides` and add them to
`"slides"` in config.json, e.g. `"image": "slides/doctors.jpg"`.

## 5. Remote control

The box listens to the **TV's own remote** through HDMI-CEC. On the LG TV, turn on
**Settings → General → Devices → HDMI Settings → SIMPLINK (HDMI-CEC)**. The Pi then appears
as "Al Salam TV" and the remote's arrows, OK and Back work.

| Button | Action |
|---|---|
| ◀ ▶ ▲ ▼ / OK | Move and select |
| BACK / EXIT | Go back (from full screen to the channel list, then home) |
| HOME / MENU | Home screen (on some LG remotes this opens the TV's own menu instead) |
| CH+ / CH− | Change channel |

Some TVs send only some buttons over HDMI-CEC. If the TV remote doesn't work well, plug a
small **air-mouse remote** (USB dongle) into the Pi. It works straight away.

## 6. Problems

| Problem | Fix |
|---|---|
| Black screen, no logo | Check the power supply and the HDMI cable; try the other HDMI port on the Pi (use the one next to the power socket) |
| "No internet connection" | Check the Wi-Fi name and password in Raspberry Pi Imager, or use a network cable |
| A channel doesn't play | Some free streams are geo-blocked or not live all day; choose another or remove it with `blockedWords` |
| The TV remote does nothing | Turn on SIMPLINK on the TV (section 5) or use an air-mouse remote |
| Need to get to the system | Plug in a keyboard and press **Ctrl+Alt+F2**, or connect with SSH |

## Notes for IT

- Files: `app/` (the screen, HTML/JS), `pi/` (server, kiosk start-up, TV-remote bridge,
  installer), `data/` (editable settings and media, kept on the boot partition).
- Installed to `/opt/alsalam-tv`. Services: `alsalam-server` (local web server on
  127.0.0.1:8080) and `alsalam-cec` (TV remote). The browser runs full screen under `cage`
  on tty1 after auto-login, restarted automatically if it closes.
- To try the screen on a PC: `python3 pi/server.py` in this folder, then open
  <http://127.0.0.1:8080>.
- Channel lists come from the public iptv-org project, which lists streams that broadcasters
  make freely available. Before showing TV in public or patient areas, check with the hospital's
  legal/compliance team that this is allowed for public viewing in Bahrain. Paid channels
  (beIN, OSN and so on) need a commercial licence from the provider.
