#!/data/data/com.termux/files/usr/bin/bash
# Keep the agent polling in the background with a wake lock so Android doesn't kill it.
cd "$(dirname "$0")/.."
termux-wake-lock 2>/dev/null || true
exec python -m agent "$@"
