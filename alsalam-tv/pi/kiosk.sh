#!/bin/bash
# Starts Al Salam TV full screen. Run automatically on the Pi's screen (tty1) after auto-login.
# If the browser ever closes or crashes, it is started again.

URL="http://127.0.0.1:8080/"
PROFILE="$HOME/.alsalam-tv-browser"
BROWSER=$(command -v chromium || command -v chromium-browser)

# Wait for the local server (it starts at boot)
for _ in $(seq 1 60); do
  curl -s -o /dev/null "$URL" && break
  sleep 1
done

while true; do
  # Clear the "restore pages?" state left by a power cut
  sed -i 's/"exited_cleanly":false/"exited_cleanly":true/; s/"exit_type":"Crashed"/"exit_type":"Normal"/' \
    "$PROFILE/Default/Preferences" 2>/dev/null

  # --disable-web-security: many free TV streams do not send CORS headers, which the
  # in-page player needs. This browser only ever shows the local Al Salam TV page.
  cage -d -s -- "$BROWSER" \
    --kiosk --start-fullscreen --noerrdialogs --disable-infobars --no-first-run \
    --disable-translate --disable-features=Translate,MediaRouter \
    --disable-session-crashed-bubble --overscroll-history-navigation=0 --disable-pinch \
    --check-for-update-interval=31536000 --password-store=basic \
    --autoplay-policy=no-user-gesture-required \
    --disable-web-security --user-data-dir="$PROFILE" \
    --ozone-platform=wayland \
    "$URL"
  sleep 3
done
