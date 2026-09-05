import json

import pytest

from agent.ai import AIError, Decision, NoAI, parse_decision, strip_forward_header, trim_body
from agent.config import AIConfig, CloudApiConfig, ConfigError, Rule
from agent.mail import Email
from agent.main import format_message, process_email
from agent.state import State
from agent.whatsapp import CloudApiSender, Outgoing, flatten_for_template, normalize_phone, truncate, wa_me_url


def test_normalize_phone():
    assert normalize_phone("+973 1234-5678") == "97312345678"
    with pytest.raises(ConfigError):
        normalize_phone("12")


def test_wa_me_url_encodes_text():
    url = wa_me_url("+97312345678", "Hi there & *bold*\nline2")
    assert url.startswith("https://wa.me/97312345678?text=")
    assert "%0A" in url and "&" not in url.split("text=")[1]


def test_truncate_and_flatten():
    assert truncate("abcdef", 4) == "abc…"
    assert flatten_for_template("a\nb\t\tc      d") == "a | b | c | d"


def test_cloud_api_payloads(monkeypatch):
    monkeypatch.setenv("WA_PHONE_NUMBER_ID", "123")
    monkeypatch.setenv("WA_ACCESS_TOKEN", "tok")
    sender = CloudApiSender(CloudApiConfig(mode="template", template_name="email_forward"))
    p = sender.payload(Outgoing(to="97312345678", text="line1\nline2"))
    assert p["type"] == "template"
    assert p["template"]["components"][0]["parameters"][0]["text"] == "line1 | line2"
    assert sender.url == "https://graph.facebook.com/v21.0/123/messages"
    text_sender = CloudApiSender(CloudApiConfig(mode="text"))
    assert text_sender.payload(Outgoing(to="1", text="x\ny"))["text"]["body"] == "x\ny"


def test_parse_decision_tolerates_fences():
    d = parse_decision('```json\n{"forward": false, "reason": "not urgent", "message": "m"}\n```')
    assert d == Decision(forward=False, message="m", reason="not urgent")
    with pytest.raises(AIError):
        parse_decision("no json here")


def test_trim_body():
    assert trim_body("one two three four", 2) == "one two …"
    assert trim_body("one two", 5) == "one two"


def _mail(**kw):
    base = dict(uid="1", message_id="<x>", from_name="Ann", from_addr="ann@x.com", to="", subject="Hi",
                date=None, body=" ".join(["w"] * 300), attachments=["a.pdf"])
    base.update(kw)
    return Email(**base)


def test_format_message():
    rule = Rule(name="r", send_to=["+97312345678"], prefix="💰")
    text = format_message(_mail(), "Summary here", rule, 3000)
    assert text.splitlines()[0] == "💰 📧 *Hi*"
    assert "From: Ann <ann@x.com>" in text
    assert "📎 a.pdf" in text
    assert text.endswith("Summary here")


class RecordingSender:
    name = "rec"

    def __init__(self):
        self.sent = []

    def send(self, msg):
        self.sent.append(msg)


class FakeCfg:
    def __init__(self, rules):
        self.rules = rules
        self.ai = AIConfig(max_words=10)
        self.whatsapp = type("W", (), {"max_chars": 3000})()

    def resolve_recipients(self, rule):
        return rule.send_to


class FailingAI:
    provider = "fail"

    def decide(self, *a, **k):
        raise AIError("boom")


class NoForwardAI:
    provider = "nf"

    def decide(self, *a, **k):
        return Decision(forward=False, message="", reason="not relevant")


def test_process_email_sends_to_all_recipients(tmp_path):
    rule = Rule(name="r", send_to=["+97311111111", "+97322222222"])
    sender = RecordingSender()
    n = process_email(FakeCfg([rule]), _mail(), [rule], NoAI(AIConfig(max_words=10)), sender, State(tmp_path / "s.json"))
    assert n == 2
    assert [m.to for m in sender.sent] == ["97311111111", "97322222222"]
    assert sender.sent[0].text.count("w") == 10


def test_process_email_falls_back_when_ai_fails(tmp_path):
    rule = Rule(name="r", send_to=["+97311111111"])
    sender = RecordingSender()
    n = process_email(FakeCfg([rule]), _mail(), [rule], FailingAI(), sender, State(tmp_path / "s.json"))
    assert n == 1


def test_process_email_skips_when_ai_gate_fails_or_declines(tmp_path):
    rule = Rule(name="r", send_to=["+97311111111"], forward_if="urgent")
    sender = RecordingSender()
    assert process_email(FakeCfg([rule]), _mail(), [rule], FailingAI(), sender, State(tmp_path / "s.json")) == 0
    assert process_email(FakeCfg([rule]), _mail(), [rule], NoForwardAI(), sender, State(tmp_path / "s.json")) == 0
    assert sender.sent == []


def test_state_roundtrip_and_prune(tmp_path):
    path = tmp_path / "state.json"
    s = State(path)
    s.mark("<a>")
    s.save()
    data = json.loads(path.read_text())
    assert "<a>" in data["processed"]
    data["processed"]["<old>"] = 0  # epoch -> older than retention
    path.write_text(json.dumps(data))
    s2 = State(path)
    assert s2.is_processed("<a>")
    assert not s2.is_processed("<old>")
    assert len(s2) == 1


FORWARDED = """From: nursecall@alsalam.care <nursecall@alsalam.care>
Sent: Saturday, 5 September 2026 19:10
To: Ahmed AlSayed <CEO@alsalam.care>; Nurse Shift Supervisor <shift.supervisor@alsalam.care>
Subject: Repeat Nurse Call - WARD 8 NURSE STATION & PHYSIO / 011: Room 806 (called again after 8 min)


The same room has called again shortly after a previous call.

Ward\tWARD 8 NURSE STATION & PHYSIO
Address\t011: Room 806"""


def test_strip_forward_header():
    out = strip_forward_header(FORWARDED)
    assert out.startswith("The same room has called again")
    assert "shift.supervisor" not in out
    assert "Address\t011: Room 806" in out
    assert strip_forward_header("plain body") == "plain body"
    assert strip_forward_header("-----Original Message-----\nFrom: a@b.com\nSubject: x\nhello") == "hello"


def test_format_message_strips_fw_prefix():
    rule = Rule(name="r", send_to=["+97312345678"], prefix="🚨")
    text = format_message(_mail(subject="FW: Repeat Nurse Call - EMERGENCY / 008: BED 08"), "body", rule, 3000)
    assert text.splitlines()[0] == "🚨 📧 *Repeat Nurse Call - EMERGENCY / 008: BED 08*"
