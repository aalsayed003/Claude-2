"""Per-rule message templates: pull fields out of an email with regexes and fill a text template."""

from __future__ import annotations

import re
from datetime import datetime

from .config import Extract, Rule
from .mail import Email

_SMALL_WORDS = {"and", "or", "of", "the", "in", "at", "on", "for", "to", "a", "an"}


def smart_title(text: str) -> str:
    """WARD 8 NURSE STATION & PHYSIO -> Ward 8 Nurse Station & Physio (keeps '&', numbers, small words lower)."""
    words = text.split()
    out = []
    for i, w in enumerate(words):
        lw = w.lower()
        if i and lw in _SMALL_WORDS:
            out.append(lw)
        elif w.isupper() or w.islower():
            out.append(w.capitalize())
        else:
            out.append(w)
    return " ".join(out)


def extract_field(spec: Extract, text: str) -> str:
    m = re.search(spec.pattern, text, re.I | re.M)
    if not m:
        return spec.default
    parts = [g for g in m.groups() if g] if m.groups() else [m.group(0)]
    value = " ".join(p.strip() for p in parts).strip()
    if spec.title:
        value = smart_title(value)
    return value or spec.default


class _Fields(dict):
    def __missing__(self, key: str) -> str:
        return ""


def render(rule: Rule, mail: Email, subject: str, body: str, summary: str) -> str:
    """Fill rule.template. Available: extracted fields, plus {subject} {from} {date} {body} {summary}."""
    haystack = f"{subject}\n{body}"
    fields = _Fields({name: extract_field(spec, haystack) for name, spec in rule.extract.items()})
    fields.update(
        subject=subject,
        sender=mail.from_display,
        date=mail.date.strftime("%d %b %Y %H:%M") if isinstance(mail.date, datetime) else "",
        body=body,
        summary=summary,
    )
    fields["from"] = mail.from_display
    text = rule.template.format_map(fields)
    return re.sub(r"[ \t]+\n", "\n", text).strip()
