#!/data/data/com.termux/files/usr/bin/bash
# One-time setup inside Termux (Android). Run: bash scripts/install-termux.sh
set -e

echo "==> Updating packages"
pkg update -y
pkg install -y python python-yaml git termux-api termux-tools

echo "==> Installing Python deps"
# Termux forbids `pip install --upgrade pip`; python-yaml above provides PyYAML prebuilt.
pip install -r "$(dirname "$0")/../requirements.txt"

cd "$(dirname "$0")/.."
[ -f config.yaml ] || cp config.example.yaml config.yaml
[ -f .env ] || cp .env.example .env

echo
echo "Done. Next:"
echo "  1. nano config.yaml   (your email, contacts and rules)"
echo "  2. nano .env          (your Gmail app password)"
echo "  3. python -m agent --test-whatsapp +973XXXXXXXX   (checks WhatsApp hand-off)"
echo "  4. python -m agent --once --dry-run               (checks email + rules)"
echo "  5. bash scripts/run-loop.sh                      (run for real)"
echo
echo "Also install the Termux:API app from F-Droid so notifications work."
