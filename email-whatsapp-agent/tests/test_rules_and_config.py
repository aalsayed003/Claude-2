import pytest

from agent.config import ConfigError, Match, Rule, load_config, load_dotenv
from agent.mail import Email
from agent.rules import matches, matching_rules


def make_mail(**kw) -> Email:
    base = dict(
        uid="1", message_id="<m1>", from_name="Bank Alerts", from_addr="alerts@bank.com",
        to="me@gmail.com", subject="Transaction alert", date=None, body="You spent BHD 20", attachments=[],
    )
    base.update(kw)
    return Email(**base)


def test_match_from_and_subject_case_insensitive():
    m = Match(from_=["ALERTS@bank.com"], subject=["transaction"])
    assert matches(m, make_mail())
    assert not matches(m, make_mail(subject="Newsletter"))
    assert not matches(m, make_mail(from_addr="spam@x.com"))


def test_match_from_can_use_display_name():
    assert matches(Match(from_=["bank alerts"]), make_mail())


def test_match_regex_and_attachment():
    m = Match(subject_regex=r"invoice|payment due", has_attachment=True)
    assert matches(m, make_mail(subject="Payment Due tomorrow", attachments=["a.pdf"]))
    assert not matches(m, make_mail(subject="Payment Due tomorrow"))


def test_empty_match_accepts_everything():
    assert matches(Match(), make_mail())


def test_query_rule_only_matches_server_hits():
    rule = Rule(name="r", send_to=["+97312345678"], match=Match(query="from:hr@x.com"))
    mail = make_mail(uid="10")
    assert matching_rules([rule], mail, {"from:hr@x.com": {"10"}}) == [rule]
    assert matching_rules([rule], mail, {"from:hr@x.com": {"11"}}) == []
    assert matching_rules([rule], mail, None) == []


def test_invalid_regex_rejected():
    with pytest.raises(ConfigError):
        Match(subject_regex="(unclosed")


MINIMAL = """
email:
  user: me@gmail.com
contacts:
  boss: "+973 1234 5678"
rules:
  - name: test
    match:
      subject: [hello]
    send_to: [boss, "+97399999999"]
"""


def test_load_config_resolves_contacts(tmp_path):
    p = tmp_path / "c.yaml"
    p.write_text(MINIMAL)
    cfg = load_config(p)
    assert cfg.email.host == "imap.gmail.com"
    assert cfg.ai.provider == "none"
    assert cfg.whatsapp.backend == "intent"
    assert cfg.resolve_recipients(cfg.rules[0]) == ["+973 1234 5678", "+97399999999"]


def test_load_config_rejects_unknown_contact(tmp_path):
    p = tmp_path / "c.yaml"
    p.write_text(MINIMAL.replace("boss, ", "nobody, "))
    with pytest.raises(ConfigError, match="nobody"):
        load_config(p)


def test_forward_if_requires_ai(tmp_path):
    p = tmp_path / "c.yaml"
    p.write_text(MINIMAL + "    forward_if: urgent\n")
    with pytest.raises(ConfigError, match="forward_if"):
        load_config(p)


def test_ai_defaults(tmp_path):
    p = tmp_path / "c.yaml"
    p.write_text(MINIMAL + "ai:\n  provider: gemini\n")
    cfg = load_config(p)
    assert cfg.ai.model == "gemini-2.5-flash"
    assert cfg.ai.api_key_env == "GEMINI_API_KEY"


def test_example_config_loads():
    from pathlib import Path

    cfg = load_config(Path(__file__).resolve().parents[1] / "config.example.yaml")
    assert len(cfg.rules) == 3


def test_load_dotenv(tmp_path, monkeypatch):
    monkeypatch.delenv("EMAIL_PASSWORD", raising=False)
    env = tmp_path / ".env"
    env.write_text("# comment\nEMAIL_PASSWORD='abcd efgh'\nEMPTY=\n")
    load_dotenv(env)
    import os

    assert os.environ["EMAIL_PASSWORD"] == "abcd efgh"


def test_contact_from_env(tmp_path, monkeypatch):
    p = tmp_path / "c.yaml"
    p.write_text(MINIMAL.replace('boss: "+973 1234 5678"', 'boss: "${BOSS_PHONE}"'))
    monkeypatch.delenv("BOSS_PHONE", raising=False)
    with pytest.raises(ConfigError, match="BOSS_PHONE"):
        load_config(p)
    monkeypatch.setenv("BOSS_PHONE", "+97355555555")
    cfg = load_config(p)
    assert cfg.resolve_recipients(cfg.rules[0])[0] == "+97355555555"


def test_shipped_nurse_call_config(monkeypatch):
    from pathlib import Path

    monkeypatch.setenv("NURSE_CALL_WHATSAPP", "+97333333333")
    cfg = load_config(Path(__file__).resolve().parents[1] / "config.yaml")
    assert cfg.email.provider == "imap"
    rule = cfg.rules[0]
    assert cfg.resolve_recipients(rule) == ["+97333333333"]
    from agent.rules import matches
    from agent.mail import Email

    def mail(subject):
        return Email(uid="1", message_id="<x>", from_name="", from_addr="a@b.com", to="", subject=subject,
                     date=None, body="")

    assert matches(rule.match, mail("Repeat Nurse Call - WARD 9 NURSE STATION / 003: Room 902 (called again after 7 min)"))
    assert matches(rule.match, mail("FW: Repeat Nurse Call - WARD 9 NURSE STATION / 003: Room 902 (called again after 7 min)"))
    assert matches(rule.match, mail("Fwd: Repeat Nurse Call - EMERGENCY / 008: BED 08"))
    assert not matches(rule.match, mail("RE: Repeat Nurse Call - WARD 9"))
    assert not matches(rule.match, mail("Nurse Call summary"))
