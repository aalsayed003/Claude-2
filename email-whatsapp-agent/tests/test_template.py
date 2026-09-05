from pathlib import Path

from agent.ai import clean_body
from agent.config import Extract, Rule, load_config
from agent.mail import Email
from agent.main import format_message
from agent.template import extract_field, render, smart_title

ALERT_BODY = """Sent from Outlook for Android<https://aka.ms/AAb9ysg>
________________________________
From: nursecall@alsalam.care <nursecall@alsalam.care>
Sent: Saturday, 5 September 2026 19:10
To: Ahmed AlSayed <CEO@alsalam.care>
Subject: Repeat Nurse Call - WARD 8 NURSE STATION & PHYSIO / 011: Room 806 (called again after 8 min)


The same room has called again shortly after a previous call.

Ward    WARD 8 NURSE STATION & PHYSIO
Address 011: Room 806
Channel 004: 8THNurseStation
Call type       Call
This call was at        9/5/2026, 7:10:51 PM
Time since previous call        8 min"""


def _mail(subject="FW: Repeat Nurse Call - WARD 8 NURSE STATION & PHYSIO / 011: Room 806 (called again after 8 min)",
          body=ALERT_BODY):
    return Email(uid="1", message_id="<x>", from_name="Ahmed AlSayed", from_addr="ceo@alsalam.care", to="",
                 subject=subject, date=None, body=body)


def test_clean_body_removes_signature_divider_and_header():
    out = clean_body(ALERT_BODY)
    assert out.startswith("The same room has called again")
    assert "Sent from Outlook" not in out and "____" not in out and "From:" not in out
    assert "Time since previous call        8 min" in out


def test_smart_title():
    assert smart_title("WARD 8 NURSE STATION & PHYSIO") == "Ward 8 Nurse Station & Physio"
    assert smart_title("EMERGENCY") == "Emergency"
    assert smart_title("BED 08") == "Bed 08"


def test_extract_field_groups_title_default():
    assert extract_field(Extract(pattern=r"at\s+(\d+:\d+):\d+ (AM|PM)"), "at 7:10:51 PM") == "7:10 PM"
    assert extract_field(Extract(pattern=r"nothing", default="n/a"), "text") == "n/a"
    assert extract_field(Extract(pattern=r"WARD \d+", title=True), "in WARD 9 now") == "Ward 9"


def test_shipped_nurse_call_template_renders(monkeypatch):
    monkeypatch.setenv("NURSE_CALL_WHATSAPP", "+97333333333")
    cfg = load_config(Path(__file__).resolve().parents[1] / "config.yaml")
    rule = cfg.rules[0]
    text = format_message(_mail(), "", rule, 3000)
    assert text.splitlines()[0] == "🚨 *Repeat nurse call: Room 806, Ward 8 Nurse Station & Physio*"
    assert "pressed again 8 min after the previous call (Call at 7:10 PM)" in text
    assert "send me a quick update" in text
    assert "FW:" not in text and "Sent from" not in text and "nursecall@" not in text


def test_shipped_template_survives_missing_fields(monkeypatch):
    monkeypatch.setenv("NURSE_CALL_WHATSAPP", "+97333333333")
    cfg = load_config(Path(__file__).resolve().parents[1] / "config.yaml")
    text = format_message(_mail(subject="Repeat Nurse Call", body="odd body"), "", cfg.rules[0], 3000)
    assert "a room, the ward" in text
    assert "a few minutes" in text


def test_render_builtin_fields():
    rule = Rule(name="r", send_to=["+97312345678"], template="{subject} | {from} | {summary} | {missing}!")
    out = render(rule, _mail(), "Subj", "body", "sum")
    assert out == "Subj | Ahmed AlSayed <ceo@alsalam.care> | sum | !"
