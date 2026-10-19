#!/bin/bash
# Al Salam TV - turns a fresh Raspberry Pi OS Lite into a TV box that starts straight into
# the Al Salam screen. Run once, with the Pi connected to the internet:
#
#   sudo bash /boot/firmware/alsalam-tv/pi/install.sh
#
# Options:  --readonly   also protect the memory card against power cuts (recommended once
#                        everything works; turn off with: sudo raspi-config nonint do_overlayfs 1)
set -e

if [ "$(id -u)" != 0 ]; then echo "Please run with sudo"; exit 1; fi

SRC="$(cd "$(dirname "$0")/.." && pwd)"
DEST=/opt/alsalam-tv
TV_USER="${SUDO_USER:-$(getent passwd 1000 | cut -d: -f1)}"
TV_HOME=$(getent passwd "$TV_USER" | cut -d: -f6)
BOOT=/boot/firmware; [ -d "$BOOT" ] || BOOT=/boot
READONLY=0; [ "$1" = "--readonly" ] && READONLY=1

if [ -z "$TV_USER" ] || [ "$TV_USER" = root ]; then echo "Could not find the normal Pi user"; exit 1; fi
echo "==> Installing Al Salam TV for user '$TV_USER' from $SRC"

echo "==> Installing software (this takes a few minutes)"
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends cage python3 python3-evdev v4l-utils curl \
  fonts-noto-core plymouth plymouth-themes
apt-get install -y --no-install-recommends chromium || apt-get install -y --no-install-recommends chromium-browser

echo "==> Copying the app to $DEST"
mkdir -p "$DEST"
rm -rf "$DEST/app" "$DEST/pi"
cp -r "$SRC/app" "$SRC/pi" "$DEST/"
chmod +x "$DEST/pi/"*.sh "$DEST/pi/"*.py
# The editable settings and videos stay on the boot partition, so they can be changed from any PC
if [ ! -d "$BOOT/alsalam-tv/data" ]; then
  mkdir -p "$BOOT/alsalam-tv"
  cp -r "$SRC/data" "$BOOT/alsalam-tv/"
fi

echo "==> Background services"
cat > /etc/systemd/system/alsalam-server.service <<EOF
[Unit]
Description=Al Salam TV local server
After=local-fs.target

[Service]
ExecStart=/usr/bin/python3 $DEST/pi/server.py
Restart=always
User=$TV_USER

[Install]
WantedBy=multi-user.target
EOF

cat > /etc/systemd/system/alsalam-cec.service <<EOF
[Unit]
Description=Al Salam TV - TV remote over HDMI-CEC
After=multi-user.target

[Service]
ExecStart=/usr/bin/python3 $DEST/pi/cec-remote.py
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable alsalam-server.service alsalam-cec.service

echo "==> USB stick for large videos (label it ALSALAMTV)"
mkdir -p /mnt/alsalamtv
if ! grep -q 'LABEL=ALSALAMTV' /etc/fstab; then
  echo 'LABEL=ALSALAMTV /mnt/alsalamtv auto ro,nofail,x-systemd.automount,x-systemd.device-timeout=3 0 0' >> /etc/fstab
fi

echo "==> Start straight into Al Salam TV after power-on"
usermod -aG video,render,input "$TV_USER" || true
raspi-config nonint do_boot_behaviour B2   # console auto-login on the TV screen
PROFILE_FILE="$TV_HOME/.bash_profile"
touch "$PROFILE_FILE"
if ! grep -q alsalam-tv "$PROFILE_FILE"; then
  cat >> "$PROFILE_FILE" <<EOF

# Al Salam TV: on the TV screen (not over SSH), start the full-screen app
if [ "\$(tty)" = "/dev/tty1" ]; then exec $DEST/pi/kiosk.sh; fi
EOF
fi
chown "$TV_USER": "$PROFILE_FILE"

echo "==> Al Salam boot screen"
raspi-config nonint do_boot_splash 0 || true
if [ -d /usr/share/plymouth/themes/pix ]; then
  cp "$DEST/pi/splash.png" /usr/share/plymouth/themes/pix/splash.png
fi
grep -q '^disable_splash=1' "$BOOT/config.txt" || echo 'disable_splash=1' >> "$BOOT/config.txt"
CMDLINE="$BOOT/cmdline.txt"
for opt in quiet splash logo.nologo vt.global_cursor_default=0 consoleblank=0 loglevel=3; do
  grep -qw -- "$opt" "$CMDLINE" || sed -i "1 s/\$/ $opt/" "$CMDLINE"
done
update-initramfs -u >/dev/null 2>&1 || true

if [ "$READONLY" = 1 ]; then
  echo "==> Protecting the memory card (read-only mode)"
  raspi-config nonint do_overlayfs 0
fi

echo
echo "Done. Restarting in 10 seconds - the TV will show Al Salam TV."
sleep 10
reboot
