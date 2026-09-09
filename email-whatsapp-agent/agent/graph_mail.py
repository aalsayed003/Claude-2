"""Read a Microsoft 365 / Outlook mailbox through the Microsoft Graph API.

Microsoft disabled password (basic-auth) IMAP for Microsoft 365, so Outlook mailboxes need OAuth.
This uses the *device code* flow: the agent prints a code, you open a URL on any browser, sign in
once, and the refresh token is cached locally. No secret, no web server, works inside Termux.
Only the Python standard library is used.
"""

from __future__ import annotations

import json
import logging
import os
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone
from pathlib import Path

from .config import ConfigError, EmailConfig
from .mail import Email

log = logging.getLogger(__name__)

GRAPH = "https://graph.microsoft.com/v1.0"
LOGIN = "https://login.microsoftonline.com"
WELL_KNOWN_FOLDERS = {"inbox", "drafts", "sentitems", "deleteditems", "junkemail", "archive", "outbox"}


class GraphError(Exception):
    pass


def _http(method: str, url: str, *, data: dict | None = None, form: bool = False,
          headers: dict | None = None, timeout: int = 30) -> dict:
    body = None
    hdrs = {"Accept": "application/json", **(headers or {})}
    if data is not None:
        if form:
            body = urllib.parse.urlencode(data).encode()
            hdrs["Content-Type"] = "application/x-www-form-urlencoded"
        else:
            body = json.dumps(data).encode()
            hdrs["Content-Type"] = "application/json"
    req = urllib.request.Request(url, data=body, headers=hdrs, method=method)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = resp.read()
            return json.loads(raw) if raw else {}
    except urllib.error.HTTPError as e:
        text = e.read().decode("utf-8", "replace")
        try:
            payload = json.loads(text)
        except json.JSONDecodeError:
            payload = {"error": text[:400]}
        payload["_status"] = e.code
        return payload
    except urllib.error.URLError as e:
        raise GraphError(f"Network error calling {url}: {e.reason}") from e


class GraphAuth:
    """Device-code OAuth with a JSON token cache."""

    def __init__(self, client_id: str, tenant: str, token_file: str | os.PathLike, scopes: list[str]):
        if not client_id:
            raise ConfigError("email.client_id is required for provider=graph (Entra app registration)")
        self.client_id = client_id
        self.tenant = tenant or "organizations"
        self.token_file = Path(token_file)
        self.scopes = sorted(set(scopes) | {"offline_access"})
        self._cache: dict = {}
        if self.token_file.exists():
            try:
                self._cache = json.loads(self.token_file.read_text(encoding="utf-8"))
            except (OSError, json.JSONDecodeError):
                self._cache = {}

    @property
    def scope_string(self) -> str:
        return " ".join(self.scopes)

    def _save(self, tokens: dict) -> None:
        self._cache = {
            "access_token": tokens.get("access_token", ""),
            "refresh_token": tokens.get("refresh_token", self._cache.get("refresh_token", "")),
            "expires_at": time.time() + float(tokens.get("expires_in", 0)),
            "scopes": self.scopes,
        }
        self.token_file.write_text(json.dumps(self._cache), encoding="utf-8")
        try:
            os.chmod(self.token_file, 0o600)
        except OSError:
            pass

    def access_token(self) -> str:
        cached_scopes = self._cache.get("scopes") or []
        if self._cache.get("access_token") and self._cache.get("expires_at", 0) - 60 > time.time() \
                and cached_scopes == self.scopes:
            return self._cache["access_token"]
        if self._cache.get("refresh_token") and cached_scopes == self.scopes:
            tokens = _http("POST", f"{LOGIN}/{self.tenant}/oauth2/v2.0/token", form=True, data={
                "client_id": self.client_id,
                "grant_type": "refresh_token",
                "refresh_token": self._cache["refresh_token"],
                "scope": self.scope_string,
            })
            if "access_token" in tokens:
                self._save(tokens)
                return tokens["access_token"]
            log.warning("Token refresh failed (%s); signing in again", tokens.get("error_description", tokens))
        return self.device_login()

    def device_login(self) -> str:
        start = _http("POST", f"{LOGIN}/{self.tenant}/oauth2/v2.0/devicecode", form=True, data={
            "client_id": self.client_id,
            "scope": self.scope_string,
        })
        if "device_code" not in start:
            raise GraphError(f"Could not start sign-in: {start.get('error_description') or start}")
        print("\n" + "=" * 60)
        print(start.get("message") or (
            f"Open {start.get('verification_uri')} and enter the code {start.get('user_code')}"))
        print("=" * 60 + "\n", flush=True)
        interval = int(start.get("interval", 5))
        deadline = time.time() + int(start.get("expires_in", 900))
        while time.time() < deadline:
            time.sleep(interval)
            tokens = _http("POST", f"{LOGIN}/{self.tenant}/oauth2/v2.0/token", form=True, data={
                "client_id": self.client_id,
                "grant_type": "urn:ietf:params:oauth:grant-type:device_code",
                "device_code": start["device_code"],
            })
            if "access_token" in tokens:
                self._save(tokens)
                log.info("Signed in to Microsoft 365; token cached in %s", self.token_file)
                return tokens["access_token"]
            err = tokens.get("error", "")
            if err == "authorization_pending":
                continue
            if err == "slow_down":
                interval += 5
                continue
            raise GraphError(f"Sign-in failed: {tokens.get('error_description') or tokens}")
        raise GraphError("Sign-in timed out; run again and enter the code within 15 minutes")


def graph_since(lookback_minutes: int, now: datetime | None = None) -> str:
    now = now or datetime.now(timezone.utc)
    return (now - timedelta(minutes=lookback_minutes)).strftime("%Y-%m-%dT%H:%M:%SZ")


def message_to_email(m: dict) -> Email:
    sender = (m.get("from") or {}).get("emailAddress") or {}
    date = None
    if m.get("receivedDateTime"):
        try:
            date = datetime.fromisoformat(m["receivedDateTime"].replace("Z", "+00:00"))
        except ValueError:
            date = None
    body = (m.get("body") or {}).get("content", "") or ""
    if (m.get("body") or {}).get("contentType", "").lower() == "html":
        from .mail import html_to_text
        body = html_to_text(body)
    to = ", ".join(
        (r.get("emailAddress") or {}).get("address", "") for r in m.get("toRecipients") or []
    )
    name = sender.get("name") or ""
    addr = (sender.get("address") or "").lower()
    return Email(
        uid=m["id"],
        message_id=(m.get("internetMessageId") or "").strip(),
        from_name="" if name.lower() == addr else name,
        from_addr=addr,
        to=to,
        subject=m.get("subject") or "",
        date=date,
        body=body.strip(),
        attachments=[a.get("name", "(unnamed)") for a in m.get("_attachments") or []],
    )


class GraphReader:
    """Same interface as mail.MailReader, backed by Microsoft Graph."""

    SELECT = "id,internetMessageId,subject,from,toRecipients,receivedDateTime,body,hasAttachments,isRead"

    def __init__(self, cfg: EmailConfig):
        self.cfg = cfg
        scope = "Mail.ReadWrite" if cfg.mark_as_read else "Mail.Read"
        self.auth = GraphAuth(cfg.client_id, cfg.tenant, cfg.token_file, [scope, "User.Read"])
        self._folder_path: str | None = None
        self._received: dict[str, str] = {}
        self.uidvalidity = "graph"

    def __enter__(self) -> "GraphReader":
        self.auth.access_token()
        return self

    def __exit__(self, *exc) -> None:
        pass

    def _get(self, path: str, params: dict | None = None, headers: dict | None = None) -> dict:
        url = f"{GRAPH}{path}"
        if params:
            url += "?" + urllib.parse.urlencode(params, quote_via=urllib.parse.quote)
        hdrs = {"Authorization": f"Bearer {self.auth.access_token()}", **(headers or {})}
        data = _http("GET", url, headers=hdrs)
        if data.get("_status"):
            raise GraphError(f"Graph GET {path} -> HTTP {data['_status']}: {json.dumps(data.get('error'))[:300]}")
        return data

    def _folder(self) -> str:
        if self._folder_path is None:
            name = self.cfg.folder.strip()
            key = name.replace(" ", "").lower()
            if key in WELL_KNOWN_FOLDERS:
                self._folder_path = f"/me/mailFolders/{key}"
            else:
                data = self._get("/me/mailFolders", {"$filter": f"displayName eq '{name}'", "$select": "id"})
                items = data.get("value") or []
                if not items:
                    raise ConfigError(f"Outlook folder {name!r} not found")
                self._folder_path = f"/me/mailFolders/{items[0]['id']}"
        return self._folder_path

    def _remember(self, items: list[dict]) -> set[str]:
        for m in items:
            self._received[m["id"]] = m.get("receivedDateTime", "")
        return {m["id"] for m in items}

    def base_uids(self) -> set[str]:
        flt = f"receivedDateTime ge {graph_since(self.cfg.lookback_minutes)}"
        if self.cfg.unread_only:
            flt += " and isRead eq false"
        data = self._get(f"{self._folder()}/messages", {
            "$filter": flt,
            "$select": "id,receivedDateTime",
            "$orderby": "receivedDateTime desc",
            "$top": str(self.cfg.max_fetch),
        })
        return self._remember(data.get("value") or [])

    def search_uids(self, query: str) -> set[str]:
        """Outlook KQL search, e.g. 'subject:roster from:hr@x.com'. Graph forbids $filter with $search,
        so the unread / lookback limits are applied locally."""
        data = self._get(f"{self._folder()}/messages", {
            "$search": f'"{query}"',
            "$select": "id,receivedDateTime,isRead",
            "$top": str(self.cfg.max_fetch),
        })
        since = graph_since(self.cfg.lookback_minutes)
        items = [
            m for m in data.get("value") or []
            if m.get("receivedDateTime", "") >= since and not (self.cfg.unread_only and m.get("isRead"))
        ]
        return self._remember(items)

    def cap(self, uids: set[str]) -> list[str]:
        ordered = sorted(uids, key=lambda u: self._received.get(u, ""))  # oldest first
        if len(ordered) > self.cfg.max_fetch:
            log.warning("%d candidate emails; only the newest %d will be fetched", len(ordered), self.cfg.max_fetch)
            ordered = ordered[-self.cfg.max_fetch:]
        return ordered

    def fetch(self, uid: str) -> Email | None:
        try:
            m = self._get(f"/me/messages/{uid}", {"$select": self.SELECT},
                          headers={"Prefer": 'outlook.body-content-type="text"'})
        except GraphError as e:
            log.warning("Could not fetch message %s: %s", uid[:12], e)
            return None
        if m.get("hasAttachments"):
            try:
                att = self._get(f"/me/messages/{uid}/attachments", {"$select": "name"})
                m["_attachments"] = att.get("value") or []
            except GraphError as e:
                log.warning("Could not list attachments for %s: %s", uid[:12], e)
        return message_to_email(m)

    def mark_seen(self, uid: str) -> None:
        url = f"{GRAPH}/me/messages/{uid}"
        data = _http("PATCH", url, data={"isRead": True},
                     headers={"Authorization": f"Bearer {self.auth.access_token()}"})
        if data.get("_status"):
            log.warning("Could not mark %s as read: %s", uid[:12], data.get("error"))
