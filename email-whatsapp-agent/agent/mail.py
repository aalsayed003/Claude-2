"""Read emails over IMAP (Gmail or any IMAP server) and parse them into Email objects."""

from __future__ import annotations

import email
import html
import imaplib
import logging
import re
from dataclasses import dataclass, field
from datetime import datetime, timedelta, timezone
from email.header import decode_header, make_header
from email.message import Message
from email.utils import parseaddr, parsedate_to_datetime

from .config import EmailConfig

log = logging.getLogger(__name__)


@dataclass
class Email:
    uid: str
    message_id: str
    from_name: str
    from_addr: str
    to: str
    subject: str
    date: datetime | None
    body: str
    attachments: list[str] = field(default_factory=list)
    uidvalidity: str = ""

    @property
    def key(self) -> str:
        """Stable identity used for de-duplication."""
        return self.message_id or f"{self.uidvalidity}:{self.uid}"

    @property
    def from_display(self) -> str:
        return f"{self.from_name} <{self.from_addr}>" if self.from_name else self.from_addr


def _decode(value: str | None) -> str:
    if not value:
        return ""
    try:
        return str(make_header(decode_header(value)))
    except Exception:  # malformed header
        return value


_TAG_RE = re.compile(r"<[^>]+>")
_SCRIPT_RE = re.compile(r"<(script|style)[^>]*>.*?</\1>", re.S | re.I)
_BLANKS_RE = re.compile(r"\n\s*\n\s*\n+")


def html_to_text(raw: str) -> str:
    text = _SCRIPT_RE.sub("", raw)
    text = re.sub(r"<br\s*/?>", "\n", text, flags=re.I)
    text = re.sub(r"</(p|div|tr|li|h\d)>", "\n", text, flags=re.I)
    text = _TAG_RE.sub("", text)
    text = html.unescape(text)
    text = "\n".join(line.strip() for line in text.splitlines())
    return _BLANKS_RE.sub("\n\n", text).strip()


def _part_text(part: Message) -> str:
    payload = part.get_payload(decode=True)
    if payload is None:
        return ""
    charset = part.get_content_charset() or "utf-8"
    try:
        return payload.decode(charset, errors="replace")
    except LookupError:
        return payload.decode("utf-8", errors="replace")


def extract_body(msg: Message) -> tuple[str, list[str]]:
    """Return (plain text body, attachment filenames). Prefers text/plain over text/html."""
    plain: list[str] = []
    html_parts: list[str] = []
    attachments: list[str] = []
    for part in msg.walk():
        if part.is_multipart():
            continue
        disposition = (part.get("Content-Disposition") or "").lower()
        filename = part.get_filename()
        if "attachment" in disposition or (filename and part.get_content_maintype() != "text"):
            attachments.append(_decode(filename) or "(unnamed)")
            continue
        ctype = part.get_content_type()
        if ctype == "text/plain":
            plain.append(_part_text(part))
        elif ctype == "text/html":
            html_parts.append(_part_text(part))
    if plain:
        body = "\n".join(plain)
    elif html_parts:
        body = html_to_text("\n".join(html_parts))
    else:
        body = ""
    return body.strip(), attachments


def parse_email(uid: str, raw: bytes, uidvalidity: str = "") -> Email:
    msg = email.message_from_bytes(raw)
    name, addr = parseaddr(_decode(msg.get("From")))
    date = None
    if msg.get("Date"):
        try:
            date = parsedate_to_datetime(msg["Date"])
        except (TypeError, ValueError):
            date = None
    body, attachments = extract_body(msg)
    return Email(
        uid=uid,
        message_id=(msg.get("Message-ID") or "").strip(),
        from_name=name,
        from_addr=addr.lower(),
        to=_decode(msg.get("To")),
        subject=_decode(msg.get("Subject")),
        date=date,
        body=body,
        attachments=attachments,
        uidvalidity=uidvalidity,
    )


def imap_since(lookback_minutes: int, now: datetime | None = None) -> str:
    """IMAP SINCE has day granularity, e.g. 04-Sep-2026."""
    now = now or datetime.now(timezone.utc)
    return (now - timedelta(minutes=lookback_minutes)).strftime("%d-%b-%Y")


class MailReader:
    def __init__(self, cfg: EmailConfig):
        self.cfg = cfg
        self._conn: imaplib.IMAP4_SSL | None = None
        self.uidvalidity = ""

    def __enter__(self) -> "MailReader":
        self.connect()
        return self

    def __exit__(self, *exc) -> None:
        self.close()

    def connect(self) -> None:
        log.info("Connecting to %s as %s", self.cfg.host, self.cfg.user)
        self._conn = imaplib.IMAP4_SSL(self.cfg.host, self.cfg.port)
        self._conn.login(self.cfg.user, self.cfg.password)
        status, data = self._conn.select(self._quote_folder(self.cfg.folder), readonly=False)
        if status != "OK":
            raise RuntimeError(f"Cannot open folder {self.cfg.folder!r}: {data}")
        try:
            resp = self._conn.response("UIDVALIDITY")[1]
            self.uidvalidity = resp[0].decode() if resp and resp[0] else ""
        except Exception:
            self.uidvalidity = ""

    @staticmethod
    def _quote_folder(folder: str) -> str:
        return f'"{folder}"' if " " in folder or "/" in folder else folder

    def close(self) -> None:
        if self._conn is not None:
            try:
                self._conn.close()
                self._conn.logout()
            except Exception:
                pass
            self._conn = None

    @property
    def conn(self) -> imaplib.IMAP4_SSL:
        if self._conn is None:
            raise RuntimeError("Not connected")
        return self._conn

    def _search(self, *criteria: str) -> list[str]:
        status, data = self.conn.uid("SEARCH", None, *criteria)
        if status != "OK":
            log.warning("IMAP search failed for %s: %s", criteria, data)
            return []
        return [u.decode() for u in (data[0] or b"").split()]

    def base_uids(self) -> set[str]:
        """UIDs from the base search: recent (and unread, if configured) emails."""
        crit = ["SINCE", imap_since(self.cfg.lookback_minutes)]
        if self.cfg.unread_only:
            crit.insert(0, "UNSEEN")
        return set(self._search(*crit))

    def gmail_uids(self, query: str) -> set[str]:
        """UIDs matching a Gmail search string (from:, subject:, label:, newer_than:, ...)."""
        # X-GM-RAW is Gmail's IMAP extension for its full search syntax.
        crit = ["X-GM-RAW", f'"{query}"']
        if self.cfg.unread_only:
            crit.insert(0, "UNSEEN")
        return set(self._search(*crit))

    def cap(self, uids: set[str]) -> list[str]:
        ordered = sorted(uids, key=int)
        if len(ordered) > self.cfg.max_fetch:
            log.warning("%d candidate emails; only the newest %d will be fetched", len(ordered), self.cfg.max_fetch)
            ordered = ordered[-self.cfg.max_fetch :]
        return ordered

    def fetch(self, uid: str) -> Email | None:
        status, data = self.conn.uid("FETCH", uid, "(BODY.PEEK[])")
        if status != "OK" or not data or not isinstance(data[0], tuple):
            log.warning("Could not fetch UID %s", uid)
            return None
        return parse_email(uid, data[0][1], self.uidvalidity)

    def mark_seen(self, uid: str) -> None:
        self.conn.uid("STORE", uid, "+FLAGS", "(\\Seen)")
