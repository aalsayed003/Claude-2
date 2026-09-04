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


def test_gmail_query_rule_only_matches_server_hits():
    rule = Rule(name="r", send_to=["+97312345678"], match=Match(gmail_query="from:hr@x.com"))
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
