"""Load and validate config.yaml plus secrets from the environment / .env file."""

from __future__ import annotations

import os
import re
from dataclasses import dataclass, field
from pathlib import Path

import yaml


class ConfigError(Exception):
    pass


def load_dotenv(path: str | os.PathLike = ".env") -> None:
    """Minimal .env loader (KEY=VALUE lines). Existing env vars win."""
    p = Path(path)
    if not p.exists():
        return
    for raw in p.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        key = key.strip()
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
            value = value[1:-1]
        os.environ.setdefault(key, value)


def secret(env_name: str, required: bool = True) -> str:
    value = os.environ.get(env_name, "")
    if required and not value:
        raise ConfigError(
            f"Environment variable {env_name} is not set. Put it in .env or export it."
        )
    return value


@dataclass
class EmailConfig:
    host: str = "imap.gmail.com"
    port: int = 993
    user: str = ""
    password_env: str = "EMAIL_PASSWORD"
    folder: str = "INBOX"
    unread_only: bool = True
    lookback_minutes: int = 1440
    mark_as_read: bool = False
    max_fetch: int = 50

    @property
    def password(self) -> str:
        return secret(self.password_env)


@dataclass
class AIConfig:
    provider: str = "none"  # none | gemini | anthropic
    model: str = ""
    api_key_env: str = ""
    max_words: int = 120
    effort: str = "low"  # anthropic only
    timeout_seconds: int = 60

    def __post_init__(self) -> None:
        if self.provider not in ("none", "gemini", "anthropic"):
            raise ConfigError(f"ai.provider must be none, gemini or anthropic (got {self.provider!r})")
        if self.provider == "gemini":
            self.model = self.model or "gemini-2.5-flash"
            self.api_key_env = self.api_key_env or "GEMINI_API_KEY"
        elif self.provider == "anthropic":
            self.model = self.model or "claude-opus-5"
            self.api_key_env = self.api_key_env or "ANTHROPIC_API_KEY"


@dataclass
class CloudApiConfig:
    phone_number_id_env: str = "WA_PHONE_NUMBER_ID"
    token_env: str = "WA_ACCESS_TOKEN"
    mode: str = "template"  # template | text
    template_name: str = "email_forward"
    template_language: str = "en_US"
    api_version: str = "v21.0"

    def __post_init__(self) -> None:
        if self.mode not in ("template", "text"):
            raise ConfigError("whatsapp.cloud_api.mode must be template or text")


@dataclass
class WhatsAppConfig:
    backend: str = "intent"  # intent | cloud_api | console
    cloud_api: CloudApiConfig = field(default_factory=CloudApiConfig)
    max_chars: int = 3000

    def __post_init__(self) -> None:
        if self.backend not in ("intent", "cloud_api", "console"):
            raise ConfigError("whatsapp.backend must be intent, cloud_api or console")


@dataclass
class Match:
    from_: list[str] = field(default_factory=list)
    subject: list[str] = field(default_factory=list)
    subject_regex: str = ""
    body: list[str] = field(default_factory=list)
    body_regex: str = ""
    gmail_query: str = ""
    has_attachment: bool | None = None

    def __post_init__(self) -> None:
        for name in ("subject_regex", "body_regex"):
            pattern = getattr(self, name)
            if pattern:
                try:
                    re.compile(pattern)
                except re.error as e:
                    raise ConfigError(f"Invalid {name} {pattern!r}: {e}") from e


@dataclass
class Rule:
    name: str
    send_to: list[str]
    match: Match = field(default_factory=Match)
    ai_instruction: str = ""
    forward_if: str = ""
    include_body: bool = True
    prefix: str = ""

    def __post_init__(self) -> None:
        if not self.send_to:
            raise ConfigError(f"Rule {self.name!r} has no send_to recipients")


@dataclass
class Config:
    email: EmailConfig
    ai: AIConfig
    whatsapp: WhatsAppConfig
    contacts: dict[str, str]
    rules: list[Rule]
    poll_interval_seconds: int = 120
    state_file: str = "state.json"

    def resolve_recipients(self, rule: Rule) -> list[str]:
        """Map contact aliases to phone numbers; raw numbers pass through."""
        out: list[str] = []
        for entry in rule.send_to:
            number = self.contacts.get(entry, entry)
            if not re.fullmatch(r"\+?[\d\s\-()]{7,20}", number):
                raise ConfigError(
                    f"Rule {rule.name!r}: {entry!r} is neither a contact alias nor a phone number"
                )
            out.append(number)
        return out


def _pick(d: dict, key: str, default):
    return d.get(key, default) if isinstance(d, dict) else default


def _as_list(value) -> list[str]:
    if value is None:
        return []
    if isinstance(value, str):
        return [value]
    return [str(v) for v in value]


def _build_match(raw: dict | None) -> Match:
    raw = raw or {}
    return Match(
        from_=_as_list(raw.get("from")),
        subject=_as_list(raw.get("subject")),
        subject_regex=raw.get("subject_regex", "") or "",
        body=_as_list(raw.get("body")),
        body_regex=raw.get("body_regex", "") or "",
        gmail_query=raw.get("gmail_query", "") or "",
        has_attachment=raw.get("has_attachment"),
    )


def load_config(path: str | os.PathLike) -> Config:
    p = Path(path)
    if not p.exists():
        raise ConfigError(f"Config file not found: {p}. Copy config.example.yaml to config.yaml.")
    raw = yaml.safe_load(p.read_text(encoding="utf-8")) or {}

    email_raw = raw.get("email") or {}
    email = EmailConfig(**{k: v for k, v in email_raw.items() if k in EmailConfig.__dataclass_fields__})
    if not email.user:
        raise ConfigError("email.user is required")

    ai = AIConfig(**{k: v for k, v in (raw.get("ai") or {}).items() if k in AIConfig.__dataclass_fields__})

    wa_raw = raw.get("whatsapp") or {}
    cloud_raw = wa_raw.get("cloud_api") or {}
    whatsapp = WhatsAppConfig(
        backend=wa_raw.get("backend", "intent"),
        cloud_api=CloudApiConfig(**{k: v for k, v in cloud_raw.items() if k in CloudApiConfig.__dataclass_fields__}),
        max_chars=int(wa_raw.get("max_chars", 3000)),
    )

    contacts = {str(k): str(v) for k, v in (raw.get("contacts") or {}).items()}

    rules: list[Rule] = []
    for i, r in enumerate(raw.get("rules") or []):
        if not isinstance(r, dict):
            raise ConfigError(f"rules[{i}] must be a mapping")
        rules.append(
            Rule(
                name=str(r.get("name") or f"rule-{i + 1}"),
                send_to=_as_list(r.get("send_to")),
                match=_build_match(r.get("match")),
                ai_instruction=r.get("ai_instruction", "") or "",
                forward_if=r.get("forward_if", "") or "",
                include_body=bool(r.get("include_body", True)),
                prefix=r.get("prefix", "") or "",
            )
        )
    if not rules:
        raise ConfigError("At least one rule is required under `rules:`")

    cfg = Config(
        email=email,
        ai=ai,
        whatsapp=whatsapp,
        contacts=contacts,
        rules=rules,
        poll_interval_seconds=int(_pick(raw.get("polling"), "interval_seconds", 120)),
        state_file=str(raw.get("state_file", "state.json")),
    )
    for rule in rules:
        cfg.resolve_recipients(rule)  # validate early
        if rule.forward_if and ai.provider == "none":
            raise ConfigError(
                f"Rule {rule.name!r} uses forward_if, which needs ai.provider = gemini or anthropic"
            )
    return cfg
