"""WhatsApp delivery backends.

  console   - print to the terminal (dry runs / testing)
  intent    - open WhatsApp on the phone with the message pre-filled; you tap Send.
              100% free, official, works with any contact from your own number. On Termux it
              posts a notification per message so several messages can queue up.
  cloud_api - Meta WhatsApp Cloud API. Fully automatic. Free for up to 5 verified test
              recipients on a Meta test number; otherwise Meta bills per template message.
"""

from __future__ import annotations

import json
import logging
import re
import shutil
import subprocess
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass

from .config import CloudApiConfig, ConfigError, WhatsAppConfig, secret

log = logging.getLogger(__name__)


class SendError(Exception):
    pass


@dataclass
class Outgoing:
    to: str  # digits only, with country code
    text: str
    title: str = ""


def normalize_phone(raw: str) -> str:
    digits = re.sub(r"\D", "", raw or "")
    if len(digits) < 7:
        raise ConfigError(f"Not a valid international phone number: {raw!r}")
    return digits


def wa_me_url(to: str, text: str) -> str:
    return f"https://wa.me/{normalize_phone(to)}?text={urllib.parse.quote(text, safe='')}"


def truncate(text: str, max_chars: int) -> str:
    if len(text) <= max_chars:
        return text
    return text[: max_chars - 1].rstrip() + "…"


class ConsoleSender:
    name = "console"

    def send(self, msg: Outgoing) -> None:
        print(f"\n===== WhatsApp -> +{msg.to} =====\n{msg.text}\n===== end =====\n")


class IntentSender:
    """Hand the message to the WhatsApp app via a wa.me link."""

    name = "intent"

    def __init__(self, notify: bool | None = None):
        self.notify = shutil.which("termux-notification") is not None if notify is None else notify

    @staticmethod
    def _opener() -> list[str] | None:
        if shutil.which("termux-open-url"):
            return ["termux-open-url"]
        if shutil.which("am"):
            return ["am", "start", "-a", "android.intent.action.VIEW", "-d"]
        if shutil.which("xdg-open"):
            return ["xdg-open"]
        return None

    def send(self, msg: Outgoing) -> None:
        url = wa_me_url(msg.to, msg.text)
        if self.notify:
            # One tappable notification per message; tapping opens WhatsApp pre-filled.
            cmd = [
                "termux-notification",
                "--title", msg.title or f"Email for +{msg.to}",
                "--content", "Tap to open WhatsApp, then press Send",
                "--action", f"termux-open-url '{url}'",
                "--button1", "Open WhatsApp",
                "--button1-action", f"termux-open-url '{url}'",
                "--id", f"email-wa-{abs(hash(url)) % 100000}",
            ]
            try:
                subprocess.run(cmd, check=True, timeout=15)
                log.info("Notification posted for +%s", msg.to)
                return
            except (subprocess.SubprocessError, OSError) as e:
                log.warning("termux-notification failed (%s); opening WhatsApp directly", e)
        opener = self._opener()
        if opener is None:
            raise SendError(
                "No way to open WhatsApp from here. On Termux run `pkg install termux-tools termux-api`, "
                f"or open this link manually: {url}"
            )
        try:
            subprocess.run(opener + [url], check=True, timeout=15)
        except (subprocess.SubprocessError, OSError) as e:
            raise SendError(f"Could not open WhatsApp: {e}") from e
        log.info("WhatsApp opened for +%s; tap Send", msg.to)


_TEMPLATE_PARAM_RE = re.compile(r"[\r\n\t]+|\s{5,}")


def flatten_for_template(text: str) -> str:
    """Meta rejects template parameters containing newlines, tabs or 5+ consecutive spaces."""
    return _TEMPLATE_PARAM_RE.sub(" | ", text).strip(" |")


class CloudApiSender:
    name = "cloud_api"

    def __init__(self, cfg: CloudApiConfig):
        self.cfg = cfg
        self.phone_number_id = secret(cfg.phone_number_id_env)
        self.token = secret(cfg.token_env)

    @property
    def url(self) -> str:
        return f"https://graph.facebook.com/{self.cfg.api_version}/{self.phone_number_id}/messages"

    def payload(self, msg: Outgoing) -> dict:
        if self.cfg.mode == "text":
            return {
                "messaging_product": "whatsapp",
                "to": msg.to,
                "type": "text",
                "text": {"preview_url": False, "body": msg.text[:4096]},
            }
        return {
            "messaging_product": "whatsapp",
            "to": msg.to,
            "type": "template",
            "template": {
                "name": self.cfg.template_name,
                "language": {"code": self.cfg.template_language},
                "components": [
                    {
                        "type": "body",
                        "parameters": [{"type": "text", "text": flatten_for_template(msg.text)[:1024]}],
                    }
                ],
            },
        }

    def send(self, msg: Outgoing) -> None:
        req = urllib.request.Request(
            self.url,
            data=json.dumps(self.payload(msg)).encode("utf-8"),
            headers={"Content-Type": "application/json", "Authorization": f"Bearer {self.token}"},
            method="POST",
        )
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                data = json.loads(resp.read().decode("utf-8"))
        except urllib.error.HTTPError as e:
            body = e.read().decode("utf-8", "replace")
            raise SendError(f"WhatsApp Cloud API HTTP {e.code}: {body[:400]}") from e
        except urllib.error.URLError as e:
            raise SendError(f"WhatsApp Cloud API network error: {e.reason}") from e
        wa_id = (data.get("messages") or [{}])[0].get("id", "?")
        log.info("Sent to +%s via Cloud API (message id %s)", msg.to, wa_id)


def build_sender(cfg: WhatsAppConfig, dry_run: bool = False):
    if dry_run or cfg.backend == "console":
        return ConsoleSender()
    if cfg.backend == "cloud_api":
        return CloudApiSender(cfg.cloud_api)
    return IntentSender()
