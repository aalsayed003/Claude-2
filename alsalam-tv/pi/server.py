#!/usr/bin/env python3
"""Local web server for Al Salam TV (runs on the Raspberry Pi, localhost only).

  /            -> the app (app/ folder)
  /data/...    -> the editable folder on the memory card's boot partition
                  (config.json, videos/, slides/)
  /usb/...     -> a USB stick labelled ALSALAMTV, if plugged in (for large video files)
  /api/videos  -> JSON list of the video files found in data/videos and usb/videos
"""
import json
import mimetypes
import os
import re
import sys
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import quote, unquote, urlparse

HERE = os.path.dirname(os.path.abspath(__file__))
APP_DIR = os.path.abspath(os.path.join(HERE, "..", "app"))
DATA_CANDIDATES = [
    "/boot/firmware/alsalam-tv/data",  # Raspberry Pi OS Bookworm and later
    "/boot/alsalam-tv/data",           # older Raspberry Pi OS
    os.path.abspath(os.path.join(HERE, "..", "data")),  # running from the project folder
]
USB_DIR = "/mnt/alsalamtv"
VIDEO_EXT = (".mp4", ".m4v", ".webm", ".mkv", ".mov")
PORT = int(os.environ.get("ALSALAM_PORT", "8080"))

mimetypes.add_type("application/vnd.apple.mpegurl", ".m3u8")
mimetypes.add_type("video/mp4", ".m4v")
mimetypes.add_type("video/x-matroska", ".mkv")


def data_dir():
    if os.environ.get("ALSALAM_DATA"):
        return os.environ["ALSALAM_DATA"]
    for d in DATA_CANDIDATES:
        if os.path.isdir(d):
            return d
    return DATA_CANDIDATES[-1]


def list_videos():
    out = []
    for prefix, root in (("data", data_dir()), ("usb", USB_DIR)):
        folder = os.path.join(root, "videos")
        if not os.path.isdir(folder):
            continue
        for name in sorted(os.listdir(folder), key=str.lower):
            if name.startswith(".") or not name.lower().endswith(VIDEO_EXT):
                continue
            out.append({"file": name, "url": "%s/videos/%s" % (prefix, quote(name))})
    return out


class Handler(SimpleHTTPRequestHandler):
    def log_message(self, fmt, *args):  # keep the system journal quiet
        pass

    def end_headers(self):
        self.send_header("Cache-Control", "no-store")
        super().end_headers()

    def translate_path(self, path):
        path = unquote(urlparse(path).path)
        for prefix, root in (("/data/", data_dir()), ("/usb/", USB_DIR)):
            if path.startswith(prefix):
                return self._safe(root, path[len(prefix):])
        return self._safe(APP_DIR, path.lstrip("/") or "index.html")

    @staticmethod
    def _safe(root, rel):
        full = os.path.realpath(os.path.join(root, rel))
        if full != os.path.realpath(root) and not full.startswith(os.path.realpath(root) + os.sep):
            return os.path.join(root, "__denied__")
        return full

    def do_GET(self):
        if urlparse(self.path).path == "/api/videos":
            body = json.dumps(list_videos()).encode("utf-8")
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return
        path = self.translate_path(self.path)
        if os.path.isfile(path) and self.headers.get("Range"):
            return self._send_range(path)
        super().do_GET()

    def _send_range(self, path):
        """Byte ranges, so health videos can be skipped forward/back."""
        size = os.path.getsize(path)
        m = re.match(r"bytes=(\d*)-(\d*)", self.headers.get("Range", ""))
        if not m or (not m.group(1) and not m.group(2)):
            return super().do_GET()
        if m.group(1):
            start = int(m.group(1))
            end = int(m.group(2)) if m.group(2) else size - 1
        else:  # "bytes=-500" means the last 500 bytes
            start = max(0, size - int(m.group(2)))
            end = size - 1
        end = min(end, size - 1)
        if start > end:
            self.send_response(416)
            self.send_header("Content-Range", "bytes */%d" % size)
            self.end_headers()
            return
        length = end - start + 1
        self.send_response(206)
        self.send_header("Content-Type", self.guess_type(path))
        self.send_header("Accept-Ranges", "bytes")
        self.send_header("Content-Range", "bytes %d-%d/%d" % (start, end, size))
        self.send_header("Content-Length", str(length))
        self.end_headers()
        with open(path, "rb") as f:
            f.seek(start)
            remaining = length
            try:
                while remaining > 0:
                    chunk = f.read(min(256 * 1024, remaining))
                    if not chunk:
                        break
                    self.wfile.write(chunk)
                    remaining -= len(chunk)
            except (BrokenPipeError, ConnectionResetError):
                pass  # the player closed the connection (seek or channel change)


def main():
    host = sys.argv[1] if len(sys.argv) > 1 else "127.0.0.1"
    server = ThreadingHTTPServer((host, PORT), Handler)
    print("Al Salam TV on http://%s:%d (data: %s)" % (host, PORT, data_dir()), flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main()
