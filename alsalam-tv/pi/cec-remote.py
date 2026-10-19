#!/usr/bin/env python3
"""Lets the TV's own remote control Al Salam TV through HDMI-CEC (LG calls it SIMPLINK).

1. Announces the Raspberry Pi to the TV as a playback device called "Al Salam TV" and asks
   the TV to switch to its HDMI input.
2. The Linux CEC driver turns remote buttons into key events (KEY_OK, KEY_EXIT, KEY_RED...).
   The browser does not understand all of those, so this script grabs that input device
   and re-sends ordinary keyboard keys the app listens for.
"""
import re
import subprocess
import sys
import time

try:
    import evdev
    from evdev import ecodes as e
except ImportError:
    print("python3-evdev is not installed", file=sys.stderr)
    sys.exit(1)

CEC_DEV = "/dev/cec0"
OSD_NAME = "Al Salam TV"

# CEC remote key -> keyboard key the app understands (see KEYS in app/app.js)
KEYMAP = {
    e.KEY_UP: e.KEY_UP, e.KEY_DOWN: e.KEY_DOWN, e.KEY_LEFT: e.KEY_LEFT, e.KEY_RIGHT: e.KEY_RIGHT,
    e.KEY_OK: e.KEY_ENTER, e.KEY_SELECT: e.KEY_ENTER, e.KEY_ENTER: e.KEY_ENTER,
    e.KEY_EXIT: e.KEY_ESC, e.KEY_BACK: e.KEY_ESC,
    e.KEY_ROOT_MENU: e.KEY_HOME, e.KEY_MENU: e.KEY_HOME, e.KEY_HOMEPAGE: e.KEY_HOME,
    e.KEY_CHANNELUP: e.KEY_PAGEUP, e.KEY_CHANNELDOWN: e.KEY_PAGEDOWN,
    e.KEY_RED: e.KEY_F1, e.KEY_GREEN: e.KEY_F2, e.KEY_YELLOW: e.KEY_F3, e.KEY_BLUE: e.KEY_F4,
    e.KEY_PLAY: e.KEY_ENTER, e.KEY_PAUSE: e.KEY_ENTER, e.KEY_PLAYPAUSE: e.KEY_ENTER,
    e.KEY_FASTFORWARD: e.KEY_RIGHT, e.KEY_REWIND: e.KEY_LEFT,
}


def run(*args):
    try:
        return subprocess.run(args, capture_output=True, text=True, timeout=15).stdout
    except (OSError, subprocess.SubprocessError):
        return ""


def announce():
    """Register as a playback device and become the TV's active input."""
    out = run("cec-ctl", "-d", CEC_DEV, "--playback", "--osd-name", OSD_NAME)
    m = re.search(r"Physical Address\s*:\s*([0-9a-f.]+)", out, re.I)
    phys = m.group(1) if m else ""
    run("cec-ctl", "-d", CEC_DEV, "--to", "0", "--image-view-on")
    if phys and phys != "f.f.f.f":
        run("cec-ctl", "-d", CEC_DEV, "--to", "15", "--active-source", "phys-addr=" + phys)


def find_cec_input():
    for path in evdev.list_devices():
        dev = evdev.InputDevice(path)
        name = dev.name.lower()
        keys = dev.capabilities().get(e.EV_KEY, [])
        if "cec" in name or "hdmi" in name or e.KEY_OK in keys:
            return dev
        dev.close()
    return None


def main():
    announce()
    out = evdev.UInput({e.EV_KEY: sorted(set(KEYMAP.values()))}, name="alsalam-tv-remote")
    while True:
        dev = find_cec_input()
        if not dev:
            time.sleep(10)
            continue
        try:
            dev.grab()
            for ev in dev.read_loop():
                if ev.type != e.EV_KEY or ev.code not in KEYMAP:
                    continue
                if ev.value == 2:  # auto-repeat: the app only needs presses
                    continue
                out.write(e.EV_KEY, KEYMAP[ev.code], ev.value)
                out.syn()
        except OSError:
            time.sleep(5)  # TV switched off or cable unplugged: wait and look again
            announce()


if __name__ == "__main__":
    main()
