import json
import time
from datetime import datetime, timezone
from urllib.parse import parse_qs, unquote, urlsplit

import pytest

from agent import graph_mail
from agent.config import ConfigError, EmailConfig
from agent.graph_mail import GraphAuth, GraphError, GraphReader, graph_since, message_to_email

NURSE_CALL = {
    "id": "AAMk-abc",
    "internetMessageId": "<b2761a41@ASSH-MAIL1.alsalam.local>",
    "subject": "Repeat Nurse Call - WARD 8 NURSE STATION & PHYSIO / 051: Room 811 (called again after 16 min)",
    "from": {"emailAddress": {"name": "nursecall@alsalam.care", "address": "NurseCall@alsalam.care"}},
    "toRecipients": [{"emailAddress": {"name": "Ahmed AlSayed", "address": "CEO@alsalam.care"}}],
    "receivedDateTime": "2026-09-04T10:15:28Z",
    "hasAttachments": False,
    "isRead": False,
    "body": {
        "contentType": "html",
        "content": "<p>The same room has called again shortly after a previous call.</p>"
                   "<table><tr><td><b>Ward</b></td><td>WARD 8 NURSE STATION &amp; PHYSIO</td></tr>"
                   "<tr><td><b>Address</b></td><td>051: Room 811</td></tr>"
                   "<tr><td><b>Time since previous call</b></td><td>16 min</td></tr></table>",
    },
}


def test_message_to_email_from_html():
    mail = message_to_email(NURSE_CALL)
    assert mail.uid == "AAMk-abc"
    assert mail.key == "<b2761a41@ASSH-MAIL1.alsalam.local>"
    assert mail.from_addr == "nursecall@alsalam.care"
    assert mail.from_name == ""  # name identical to address is dropped
    assert mail.to == "CEO@alsalam.care"
    assert mail.date == datetime(2026, 9, 4, 10, 15, 28, tzinfo=timezone.utc)
    assert "Ward  WARD 8 NURSE STATION & PHYSIO" in mail.body
    assert "Address  051: Room 811" in mail.body
    assert mail.attachments == []


def test_message_to_email_text_and_attachments():
    m = dict(NURSE_CALL, body={"contentType": "text", "content": "plain\r\nbody"}, _attachments=[{"name": "x.pdf"}],
             **{"from": {"emailAddress": {"name": "HR", "address": "hr@x.com"}}})
    mail = message_to_email(m)
    assert mail.body == "plain\r\nbody"
    assert mail.from_display == "HR <hr@x.com>"
    assert mail.attachments == ["x.pdf"]


def test_graph_since():
    now = datetime(2026, 9, 4, 12, 0, tzinfo=timezone.utc)
    assert graph_since(90, now) == "2026-09-04T10:30:00Z"


def test_graph_provider_requires_client_id():
    with pytest.raises(ConfigError, match="client_id"):
        EmailConfig(provider="graph", user="a@b.com")
    with pytest.raises(ConfigError):
        EmailConfig(provider="pop3", user="a@b.com")


class FakeHttp:
    """Records requests and replays canned responses keyed by (method, path-prefix)."""

    def __init__(self, routes):
        self.routes = routes
        self.calls = []

    def __call__(self, method, url, *, data=None, form=False, headers=None, timeout=30):
        self.calls.append((method, url, data, headers or {}))
        for (m, prefix), response in self.routes:
            if m == method and url.startswith(prefix):
                return response() if callable(response) else response
        raise AssertionError(f"unexpected call {method} {url}")


def _cfg(tmp_path, **kw):
    base = dict(provider="graph", user="ceo@alsalam.care", client_id="cid", lookback_minutes=60,
                token_file=str(tmp_path / "tok.json"), max_fetch=10)
    base.update(kw)
    return EmailConfig(**base)


def _cached_token(tmp_path, scopes):
    (tmp_path / "tok.json").write_text(json.dumps({
        "access_token": "AT", "refresh_token": "RT", "expires_at": time.time() + 3600, "scopes": scopes,
    }))


def test_reader_lists_fetches_and_marks(tmp_path, monkeypatch):
    _cached_token(tmp_path, sorted({"Mail.Read", "User.Read", "offline_access"}))
    fake = FakeHttp([
        (("GET", f"{graph_mail.GRAPH}/me/mailFolders/inbox/messages"),
         {"value": [{"id": "AAMk-abc", "receivedDateTime": "2026-09-04T10:15:28Z"},
                    {"id": "AAMk-old", "receivedDateTime": "2026-09-04T01:00:00Z"}]}),
        (("GET", f"{graph_mail.GRAPH}/me/messages/AAMk-abc"), NURSE_CALL),
        (("PATCH", f"{graph_mail.GRAPH}/me/messages/AAMk-abc"), {}),
    ])
    monkeypatch.setattr(graph_mail, "_http", fake)
    cfg = _cfg(tmp_path, folder="Inbox")
    with GraphReader(cfg) as reader:
        uids = reader.base_uids()
        assert uids == {"AAMk-abc", "AAMk-old"}
        assert reader.cap(uids) == ["AAMk-old", "AAMk-abc"]  # oldest first
        mail = reader.fetch("AAMk-abc")
        reader.mark_seen("AAMk-abc")

    assert mail.subject.startswith("Repeat Nurse Call")
    list_call = fake.calls[0]
    q = parse_qs(urlsplit(list_call[1]).query)
    assert "isRead eq false" in q["$filter"][0] and "receivedDateTime ge" in q["$filter"][0]
    assert list_call[3]["Authorization"] == "Bearer AT"
    fetch_call = fake.calls[1]
    assert fetch_call[3]["Prefer"] == 'outlook.body-content-type="text"'
    assert fake.calls[2][0] == "PATCH" and fake.calls[2][2] == {"isRead": True}


def test_reader_search_applies_unread_and_lookback_locally(tmp_path, monkeypatch):
    _cached_token(tmp_path, sorted({"Mail.Read", "User.Read", "offline_access"}))
    old = (datetime.now(timezone.utc).replace(microsecond=0)).strftime("2020-%m-%dT%H:%M:%SZ")
    fake = FakeHttp([
        (("GET", f"{graph_mail.GRAPH}/me/mailFolders/inbox/messages"),
         {"value": [{"id": "new", "receivedDateTime": "2999-01-01T00:00:00Z", "isRead": False},
                    {"id": "read", "receivedDateTime": "2999-01-01T00:00:00Z", "isRead": True},
                    {"id": "old", "receivedDateTime": old, "isRead": False}]}),
    ])
    monkeypatch.setattr(graph_mail, "_http", fake)
    reader = GraphReader(_cfg(tmp_path))
    assert reader.search_uids("subject:roster") == {"new"}
    assert '$search="subject:roster"' in unquote(fake.calls[0][1])


def test_reader_resolves_custom_folder_and_reports_errors(tmp_path, monkeypatch):
    _cached_token(tmp_path, sorted({"Mail.Read", "User.Read", "offline_access"}))
    fake = FakeHttp([
        (("GET", f"{graph_mail.GRAPH}/me/mailFolders?"), {"value": [{"id": "FOLDER1"}]}),
        (("GET", f"{graph_mail.GRAPH}/me/mailFolders/FOLDER1/messages"), {"_status": 403, "error": {"code": "denied"}}),
    ])
    monkeypatch.setattr(graph_mail, "_http", fake)
    reader = GraphReader(_cfg(tmp_path, folder="Nurse Calls"))
    with pytest.raises(GraphError, match="403"):
        reader.base_uids()
    assert "displayName eq 'Nurse Calls'" in unquote(fake.calls[0][1])


def test_auth_refreshes_expired_token(tmp_path, monkeypatch):
    scopes = sorted({"Mail.Read", "User.Read", "offline_access"})
    (tmp_path / "tok.json").write_text(json.dumps({
        "access_token": "OLD", "refresh_token": "RT", "expires_at": time.time() - 10, "scopes": scopes,
    }))
    fake = FakeHttp([
        (("POST", f"{graph_mail.LOGIN}/organizations/oauth2/v2.0/token"),
         {"access_token": "NEW", "refresh_token": "RT2", "expires_in": 3600}),
    ])
    monkeypatch.setattr(graph_mail, "_http", fake)
    auth = GraphAuth("cid", "organizations", tmp_path / "tok.json", ["Mail.Read", "User.Read"])
    assert auth.access_token() == "NEW"
    assert fake.calls[0][2]["grant_type"] == "refresh_token"
    saved = json.loads((tmp_path / "tok.json").read_text())
    assert saved["refresh_token"] == "RT2"
    assert auth.access_token() == "NEW" and len(fake.calls) == 1  # cached now


def test_auth_device_flow(tmp_path, monkeypatch, capsys):
    polls = iter([
        {"error": "authorization_pending"},
        {"access_token": "AT", "refresh_token": "RT", "expires_in": 3600},
    ])
    fake = FakeHttp([
        (("POST", f"{graph_mail.LOGIN}/organizations/oauth2/v2.0/devicecode"),
         {"device_code": "DC", "user_code": "ABCD-EFGH", "verification_uri": "https://microsoft.com/devicelogin",
          "message": "Go to https://microsoft.com/devicelogin and enter ABCD-EFGH", "interval": 0, "expires_in": 60}),
        (("POST", f"{graph_mail.LOGIN}/organizations/oauth2/v2.0/token"), lambda: next(polls)),
    ])
    monkeypatch.setattr(graph_mail, "_http", fake)
    monkeypatch.setattr(graph_mail.time, "sleep", lambda s: None)
    auth = GraphAuth("cid", "organizations", tmp_path / "tok.json", ["Mail.Read"])
    assert auth.access_token() == "AT"  # no cache -> device flow
    assert "ABCD-EFGH" in capsys.readouterr().out
    assert fake.calls[0][2]["scope"] == "Mail.Read offline_access"
    assert (tmp_path / "tok.json").exists()


def test_auth_scope_change_forces_new_login(tmp_path, monkeypatch):
    _cached_token(tmp_path, ["Mail.Read", "offline_access"])
    fake = FakeHttp([
        (("POST", f"{graph_mail.LOGIN}/organizations/oauth2/v2.0/devicecode"),
         {"device_code": "DC", "user_code": "X", "verification_uri": "u", "interval": 0, "expires_in": 60}),
        (("POST", f"{graph_mail.LOGIN}/organizations/oauth2/v2.0/token"),
         {"access_token": "AT2", "refresh_token": "RT", "expires_in": 3600}),
    ])
    monkeypatch.setattr(graph_mail, "_http", fake)
    monkeypatch.setattr(graph_mail.time, "sleep", lambda s: None)
    auth = GraphAuth("cid", "organizations", tmp_path / "tok.json", ["Mail.ReadWrite"])
    assert auth.access_token() == "AT2"
    assert fake.calls[0][1].endswith("/devicecode")
