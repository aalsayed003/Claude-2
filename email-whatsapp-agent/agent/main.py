"""Orchestrator: poll the mailbox, match rules, (optionally) summarize, send to WhatsApp."""

from __future__ import annotations

import argparse
import logging
import sys
import time
from datetime import datetime

from . import __version__
from .ai import AIError, NoAI, build_ai
from .config import Config, ConfigError, Rule, load_config, load_dotenv
from .mail import Email, MailReader
from .rules import matching_rules
from .state import State
from .whatsapp import Outgoing, SendError, build_sender, normalize_phone, truncate

log = logging.getLogger("agent")


def format_message(mail: Email, summary: str, rule: Rule, max_chars: int) -> str:
    when = mail.date.strftime("%d %b %Y %H:%M") if isinstance(mail.date, datetime) else ""
    header = f"{rule.prefix} " if rule.prefix else ""
    lines = [f"{header}📧 *{mail.subject or '(no subject)'}*", f"From: {mail.from_display}"]
    if when:
        lines.append(when)
    if mail.attachments:
        lines.append("📎 " + ", ".join(mail.attachments))
    if rule.include_body and summary:
        lines.append("")
        lines.append(summary)
    return truncate("\n".join(lines), max_chars)


def process_email(cfg: Config, mail: Email, rules: list[Rule], ai, sender, state: State) -> int:
    """Run every matching rule for one email. Returns number of messages sent."""
    sent = 0
    for rule in rules:
        try:
            decision = ai.decide(mail, rule.ai_instruction, rule.forward_if)
        except AIError as e:
            if rule.forward_if:
                log.error("AI failed on %r for rule %r; skipping because forward_if needs it: %s", mail.subject, rule.name, e)
                continue
            log.warning("AI failed on %r; forwarding a plain excerpt instead: %s", mail.subject, e)
            decision = NoAI(cfg.ai).decide(mail)
        if not decision.forward:
            log.info("Rule %r: AI chose not to forward %r (%s)", rule.name, mail.subject, decision.reason)
            continue
        text = format_message(mail, decision.message, rule, cfg.whatsapp.max_chars)
        for number in cfg.resolve_recipients(rule):
            to = normalize_phone(number)
            try:
                sender.send(Outgoing(to=to, text=text, title=f"{rule.name}: {mail.subject[:40]}"))
                sent += 1
            except SendError as e:
                log.error("Rule %r: could not send to +%s: %s", rule.name, to, e)
    return sent


def run_once(cfg: Config, ai, sender, state: State) -> int:
    gmail_queries = [r.match.gmail_query for r in cfg.rules if r.match.gmail_query]
    total_sent = 0
    with MailReader(cfg.email) as reader:
        candidates = reader.base_uids()
        gmail_hits = {q: reader.gmail_uids(q) for q in gmail_queries}
        for hits in gmail_hits.values():
            candidates |= hits
        uids = reader.cap(candidates)
        log.info("%d candidate email(s) in %s", len(uids), cfg.email.folder)
        for uid in uids:
            mail = reader.fetch(uid)
            if mail is None:
                continue
            if state.is_processed(mail.key):
                continue
            rules = matching_rules(cfg.rules, mail, gmail_hits)
            if not rules:
                state.mark(mail.key)  # never re-evaluate an email that matched nothing
                continue
            log.info("Email %r from %s matched %s", mail.subject, mail.from_addr, [r.name for r in rules])
            total_sent += process_email(cfg, mail, rules, ai, sender, state)
            state.mark(mail.key)
            if cfg.email.mark_as_read:
                reader.mark_seen(uid)
            state.save()
    state.save()
    return total_sent


def send_test(cfg: Config, sender, number: str) -> None:
    text = f"✅ Test from email-whatsapp-agent v{__version__} at {datetime.now():%d %b %Y %H:%M}"
    sender.send(Outgoing(to=normalize_phone(number), text=text, title="Agent test"))


def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(prog="agent", description="Forward matching emails to WhatsApp.")
    p.add_argument("--config", default="config.yaml", help="path to config.yaml")
    p.add_argument("--env", default=".env", help="path to .env with secrets")
    p.add_argument("--once", action="store_true", help="run a single poll and exit (default: loop)")
    p.add_argument("--interval", type=int, help="seconds between polls (overrides config)")
    p.add_argument("--dry-run", action="store_true", help="print messages instead of sending")
    p.add_argument("--test-whatsapp", metavar="PHONE", help="send a test message to PHONE and exit")
    p.add_argument("-v", "--verbose", action="store_true")
    return p


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    logging.basicConfig(
        level=logging.DEBUG if args.verbose else logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
        datefmt="%H:%M:%S",
    )
    load_dotenv(args.env)
    try:
        cfg = load_config(args.config)
        sender = build_sender(cfg.whatsapp, dry_run=args.dry_run)
        if args.test_whatsapp:
            send_test(cfg, sender, args.test_whatsapp)
            return 0
        ai = build_ai(cfg.ai)
    except ConfigError as e:
        log.error("Config problem: %s", e)
        return 2

    state = State(cfg.state_file)
    log.info("AI provider: %s | WhatsApp backend: %s | rules: %d", ai.provider, sender.name, len(cfg.rules))
    interval = args.interval or cfg.poll_interval_seconds

    while True:
        try:
            sent = run_once(cfg, ai, sender, state)
            log.info("Poll finished: %d message(s) sent", sent)
        except KeyboardInterrupt:
            return 0
        except Exception:  # keep the loop alive on flaky mobile networks
            log.exception("Poll failed")
        if args.once:
            return 0
        try:
            time.sleep(interval)
        except KeyboardInterrupt:
            return 0


if __name__ == "__main__":
    sys.exit(main())
