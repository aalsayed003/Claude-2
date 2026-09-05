"""Optional AI step: decide whether to forward and write a short WhatsApp-ready summary.

Providers:
  none      - no API calls; forwards a trimmed copy of the email body (100% free)
  gemini    - Google Gemini API (has a free tier), called over plain HTTPS
  anthropic - Claude via the official `anthropic` SDK (paid)
"""

from __future__ import annotations

import json
import logging
import re
import urllib.error
import urllib.request
from dataclasses import dataclass

from .config import AIConfig, ConfigError, secret
from .mail import Email

log = logging.getLogger(__name__)

MAX_BODY_CHARS_TO_MODEL = 12000


@dataclass
class Decision:
    forward: bool
    message: str
    reason: str = ""


class AIError(Exception):
    pass


_FORWARD_HEADER_RE = re.compile(
    r"^\s*(?:-{2,}\s*(?:Original Message|Forwarded message)\s*-{2,}\s*)?"
    r"From:.*?^Subject:[^\n]*\n",
    re.S | re.I | re.M,
)


_NOISE_LINE_RE = re.compile(
    r"^(?:Sent from (?:Outlook|my|Mail) [^\n]*|_{5,}|-{5,}|Get Outlook for [^\n]*)\s*$\n?",
    re.I | re.M,
)


def strip_forward_header(body: str) -> str:
    """Drop the From/Sent/To/Subject block Outlook and Gmail prepend to forwarded emails."""
    return _FORWARD_HEADER_RE.sub("", body, count=1).strip()


def clean_body(body: str) -> str:
    """strip_forward_header plus mobile signatures and divider lines, with blank lines collapsed."""
    text = strip_forward_header(body)
    text = _NOISE_LINE_RE.sub("", text)
    return re.sub(r"\n\s*\n\s*\n+", "\n\n", text).strip()


def trim_body(body: str, max_words: int) -> str:
    words = body.split()
    if len(words) <= max_words:
        return body.strip()
    return " ".join(words[:max_words]).strip() + " …"


def _system_prompt(max_words: int) -> str:
    return (
        "You turn emails into short WhatsApp messages for busy people. "
        f"Write in plain language, at most {max_words} words, no greetings, no sign-off. "
        "Keep every date, time, amount, name and reference number that matters. "
        "Use short lines; WhatsApp supports *bold* but not headings or markdown tables. "
        "Never invent facts that are not in the email. "
        "Respond ONLY with a JSON object: "
        '{"forward": true|false, "reason": "<one line>", "message": "<the WhatsApp text>"}'
    )


def _user_prompt(mail: Email, instruction: str, forward_if: str) -> str:
    body = mail.body[:MAX_BODY_CHARS_TO_MODEL]
    gate = (
        f"Set forward=true only if this condition holds: {forward_if}"
        if forward_if
        else "Set forward=true (this email already matched the user's filters)."
    )
    extra = f"Extra instruction for the message: {instruction}" if instruction else ""
    attachments = ", ".join(mail.attachments) if mail.attachments else "none"
    return (
        f"{gate}\n{extra}\n\n"
        f"From: {mail.from_display}\nSubject: {mail.subject}\nDate: {mail.date}\n"
        f"Attachments: {attachments}\n\n--- EMAIL BODY ---\n{body}\n--- END ---"
    )


_JSON_RE = re.compile(r"\{.*\}", re.S)


def parse_decision(text: str) -> Decision:
    """Parse the model's JSON reply; tolerate code fences or stray prose around it."""
    m = _JSON_RE.search(text or "")
    if not m:
        raise AIError(f"Model did not return JSON: {text[:200]!r}")
    try:
        data = json.loads(m.group(0))
    except json.JSONDecodeError as e:
        raise AIError(f"Model returned invalid JSON: {e}") from e
    return Decision(
        forward=bool(data.get("forward", True)),
        message=str(data.get("message", "")).strip(),
        reason=str(data.get("reason", "")).strip(),
    )


class NoAI:
    provider = "none"

    def __init__(self, cfg: AIConfig):
        self.cfg = cfg

    def decide(self, mail: Email, instruction: str = "", forward_if: str = "") -> Decision:
        return Decision(forward=True, message=trim_body(clean_body(mail.body), self.cfg.max_words), reason="no AI")


class Gemini:
    provider = "gemini"
    endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent"

    def __init__(self, cfg: AIConfig):
        self.cfg = cfg
        self.api_key = secret(cfg.api_key_env)

    def _call(self, system: str, user: str) -> str:
        url = self.endpoint.format(model=self.cfg.model)
        payload = {
            "system_instruction": {"parts": [{"text": system}]},
            "contents": [{"role": "user", "parts": [{"text": user}]}],
            "generationConfig": {"temperature": 0.2, "responseMimeType": "application/json"},
        }
        req = urllib.request.Request(
            url,
            data=json.dumps(payload).encode("utf-8"),
            headers={"Content-Type": "application/json", "x-goog-api-key": self.api_key},
            method="POST",
        )
        try:
            with urllib.request.urlopen(req, timeout=self.cfg.timeout_seconds) as resp:
                data = json.loads(resp.read().decode("utf-8"))
        except urllib.error.HTTPError as e:
            raise AIError(f"Gemini HTTP {e.code}: {e.read().decode('utf-8', 'replace')[:300]}") from e
        except urllib.error.URLError as e:
            raise AIError(f"Gemini network error: {e.reason}") from e
        try:
            return data["candidates"][0]["content"]["parts"][0]["text"]
        except (KeyError, IndexError, TypeError) as e:
            raise AIError(f"Unexpected Gemini response: {json.dumps(data)[:300]}") from e

    def decide(self, mail: Email, instruction: str = "", forward_if: str = "") -> Decision:
        text = self._call(_system_prompt(self.cfg.max_words), _user_prompt(mail, instruction, forward_if))
        return parse_decision(text)


class Claude:
    provider = "anthropic"

    def __init__(self, cfg: AIConfig):
        self.cfg = cfg
        try:
            import anthropic  # noqa: F401 - optional dependency
        except ImportError as e:
            raise ConfigError("ai.provider=anthropic needs `pip install anthropic`") from e
        self._anthropic = anthropic
        self.client = anthropic.Anthropic(api_key=secret(cfg.api_key_env), timeout=cfg.timeout_seconds)

    def decide(self, mail: Email, instruction: str = "", forward_if: str = "") -> Decision:
        anthropic = self._anthropic
        try:
            response = self.client.beta.messages.create(
                model=self.cfg.model,
                max_tokens=1024,
                betas=["server-side-fallback-2026-07-01"],
                fallbacks="default",
                output_config={"effort": self.cfg.effort},
                system=_system_prompt(self.cfg.max_words),
                messages=[{"role": "user", "content": _user_prompt(mail, instruction, forward_if)}],
            )
        except anthropic.RateLimitError as e:
            raise AIError(f"Claude rate limited: {e.message}") from e
        except anthropic.APIStatusError as e:
            raise AIError(f"Claude API error {e.status_code}: {e.message}") from e
        except anthropic.APIConnectionError as e:
            raise AIError(f"Claude network error: {e}") from e
        if response.stop_reason == "refusal":
            raise AIError("Claude declined to process this email")
        text = "".join(block.text for block in response.content if block.type == "text")
        return parse_decision(text)


def build_ai(cfg: AIConfig):
    if cfg.provider == "gemini":
        return Gemini(cfg)
    if cfg.provider == "anthropic":
        return Claude(cfg)
    return NoAI(cfg)
