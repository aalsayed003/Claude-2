from datetime import datetime, timezone
from email.message import EmailMessage
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from email.mime.application import MIMEApplication

from agent.mail import html_to_text, imap_since, parse_email


def _raw(msg) -> bytes:
    return msg.as_bytes()


def test_parse_plain_email():
    m = EmailMessage()
    m["From"] = "HR Team <hr@company.com>"
    m["To"] = "me@gmail.com"
    m["Subject"] = "Roster for next week"
    m["Date"] = "Thu, 03 Sep 2026 10:15:00 +0300"
    m["Message-ID"] = "<abc@company.com>"
    m.set_content("Hello,\nHere is the roster.\n")
    mail = parse_email("42", _raw(m), "999")
    assert mail.from_name == "HR Team"
    assert mail.from_addr == "hr@company.com"
    assert mail.subject == "Roster for next week"
    assert mail.body == "Hello,\nHere is the roster."
    assert mail.key == "<abc@company.com>"
    assert mail.date.year == 2026
    assert mail.attachments == []


def test_parse_html_only_and_attachment():
    outer = MIMEMultipart("mixed")
    outer["From"] = "billing@vendor.com"
    outer["Subject"] = "=?utf-8?q?Invoice_=23123?="
    outer.attach(MIMEText("<html><body><p>Amount due: <b>BHD 500</b></p><br>Thanks</body></html>", "html"))
    pdf = MIMEApplication(b"%PDF-1.4", _subtype="pdf")
    pdf.add_header("Content-Disposition", "attachment", filename="invoice.pdf")
    outer.attach(pdf)
    mail = parse_email("7", _raw(outer))
    assert mail.subject == "Invoice #123"
    assert "Amount due: BHD 500" in mail.body
    assert mail.attachments == ["invoice.pdf"]
    assert mail.from_name == ""
    assert mail.from_display == "billing@vendor.com"


def test_key_falls_back_to_uid_when_no_message_id():
    m = EmailMessage()
    m["From"] = "a@b.com"
    m["Subject"] = "x"
    m.set_content("body")
    mail = parse_email("5", _raw(m), "123")
    assert mail.key == "123:5"


def test_html_to_text_strips_scripts_and_collapses_blank_lines():
    html = "<style>p{}</style><script>alert(1)</script><p>One</p><p>Two</p><br><br><br>Three &amp; four"
    assert html_to_text(html) == "One\nTwo\n\nThree & four"


def test_imap_since_format():
    now = datetime(2026, 9, 4, 12, 0, tzinfo=timezone.utc)
    assert imap_since(60, now) == "04-Sep-2026"
    assert imap_since(24 * 60 * 3, now) == "01-Sep-2026"
