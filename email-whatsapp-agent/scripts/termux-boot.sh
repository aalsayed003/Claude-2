#!/data/data/com.termux/files/usr/bin/bash
# Auto-start after reboot. Requires the Termux:Boot app (F-Droid).
# Install with:  mkdir -p ~/.termux/boot && cp scripts/termux-boot.sh ~/.termux/boot/email-whatsapp-agent.sh
AGENT_DIR="$HOME/Claude-2/email-whatsapp-agent"   # change if you cloned elsewhere
termux-wake-lock
cd "$AGENT_DIR" && nohup python -m agent >> agent.log 2>&1 &
