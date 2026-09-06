"""Decide which rules an email matches."""

from __future__ import annotations

import re

from .config import Match, Rule
from .mail import Email


def _any_in(needles: list[str], haystack: str) -> bool:
    hay = haystack.lower()
    return any(n.lower() in hay for n in needles)


def matches(match: Match, mail: Email) -> bool:
    """All configured conditions must hold (AND); inside a list, any value matches (OR).

    A rule whose match block only has query relies on the server-side search
    having returned the email, so it accepts everything here.
    """
    if match.from_ and not _any_in(match.from_, f"{mail.from_name} {mail.from_addr}"):
        return False
    if match.subject and not _any_in(match.subject, mail.subject):
        return False
    if match.subject_regex and not re.search(match.subject_regex, mail.subject, re.I):
        return False
    if match.body and not _any_in(match.body, mail.body):
        return False
    if match.body_regex and not re.search(match.body_regex, mail.body, re.I | re.S):
        return False
    if match.has_attachment is not None and bool(mail.attachments) != match.has_attachment:
        return False
    return True


def matching_rules(rules: list[Rule], mail: Email, search_hits: dict[str, set[str]] | None = None) -> list[Rule]:
    """Return rules that match `mail`.

    `search_hits` maps a rule's server-side query to the set of UIDs the server returned for it,
    so a rule with a query only matches emails that came back from that query.
    """
    out: list[Rule] = []
    for rule in rules:
        q = rule.match.query
        if q:
            if search_hits is None or mail.uid not in search_hits.get(q, set()):
                continue
        if matches(rule.match, mail):
            out.append(rule)
    return out
