# Email → WhatsApp agent (runs on your phone)

A small agent that lives on your Android phone, watches your inbox for the emails you care
about, optionally summarizes them with an AI model, and sends them to chosen WhatsApp contacts.

No server, no subscription. It runs inside [Termux](https://termux.dev) (a free Linux terminal
for Android) and uses only free services by default.

```
Gmail / any IMAP  ──▶  rules (from / subject / regex / Gmail search)  ──▶  AI summary (optional)  ──▶  WhatsApp
```

## The honest constraint first

WhatsApp has no free, official, fully-automatic way to message arbitrary contacts from your own
number. So you pick one of three delivery backends, and the choice decides how much you tap:

| Backend | Free? | Automatic? | Any contact? | Setup | Notes |
|---|---|---|---|---|---|
| `intent` (default) | Yes | **One tap per message** | Yes, from your own number | None | Agent posts a notification; tap it → WhatsApp opens with the text pre-filled → press Send |
| `cloud_api` | Free for **up to 5 recipients** on Meta's test number | Yes | Only recipients you verified in Meta's dashboard | 30 min | Official WhatsApp Cloud API. Messages come from a Meta test number, not yours. More than 5 contacts = paid per message + business verification |
| `console` | Yes | n/a | n/a | None | Prints only. For testing |

Unofficial "linked device" libraries (Baileys, whatsapp-web.js) *are* free and automatic, but
they violate WhatsApp's terms and accounts do get banned. They are deliberately not included.
If you accept that risk, the `whatsapp.py` sender interface is one method and easy to extend.

The AI step is optional and also free by default:

| `ai.provider` | Cost | What it does |
|---|---|---|
| `none` (default) | Free | Forwards the subject + first *N* words of the body |
| `gemini` | Free tier (rate-limited) | Rewrites the email as a short WhatsApp message; can also decide whether it is worth forwarding (`forward_if`) |
| `anthropic` | Paid | Same, using Claude via the official SDK |

## What you need

- An Android phone (iOS cannot run background scripts like this; see the bottom of this page).
- **Termux** and **Termux:API**, both from [F-Droid](https://f-droid.org/packages/com.termux/)
  (the Play Store versions are outdated and broken). Termux:Boot too if you want auto-start after reboot.
- A mailbox the agent can read:
  - **Gmail / any IMAP server**: Gmail needs 2-step verification so you can create an **App Password**
    (Google account → Security → App passwords).
  - **Outlook / Microsoft 365**: Microsoft blocks plain IMAP passwords, so the agent uses the
    Microsoft Graph API with a one-time browser sign-in. See *Outlook / Microsoft 365* below.

## Install (10 minutes)

On the phone, open Termux:

```bash
pkg install -y git
git clone https://github.com/aalsayed003/Claude-2.git
cd Claude-2/email-whatsapp-agent
bash scripts/install-termux.sh
```

Then edit two files:

```bash
nano .env          # EMAIL_PASSWORD=<your 16-char Gmail app password>   (Gmail / IMAP only)
nano config.yaml   # your email address, contacts and rules (see below)
```

The repo ships a ready `config.yaml` for the Al Salam nurse-call alerts (Outlook → one WhatsApp
number, which you put in `.env` as `NURSE_CALL_WHATSAPP`); copy `config.example.yaml` over it if you
want to start from the generic template instead.

Test the WhatsApp hand-off and the email side separately:

```bash
python -m agent --test-whatsapp +97312345678     # should open WhatsApp with a test message
python -m agent --once --dry-run                 # reads mail, prints what it *would* send
```

Run it for real:

```bash
bash scripts/run-loop.sh                         # polls every 2 minutes, keeps a wake lock
```

Keep it alive: Android Settings → Apps → Termux → Battery → **Unrestricted**. To auto-start after a
reboot, install Termux:Boot and run:

```bash
mkdir -p ~/.termux/boot && cp scripts/termux-boot.sh ~/.termux/boot/email-whatsapp-agent.sh
```

## Writing rules

`config.yaml` is the whole brain of the agent. Full reference in `config.example.yaml`.

```yaml
contacts:
  me: "+97300000000"
  finance_team: "+97300000001"

rules:
  - name: Bank alerts to me
    match:
      from: ["alerts@bank.com"]          # substring, case-insensitive; matches name or address
      subject: ["transaction", "OTP"]    # any of these
    send_to: [me]

  - name: Invoices to finance
    match:
      subject_regex: "invoice|payment due"
      has_attachment: true
    send_to: [finance_team, me]
    ai_instruction: "Mention the amount, supplier and due date."
    prefix: "💰"

  - name: HR roster
    match:
      query: "from:hr@company.com subject:roster newer_than:1d"   # server-side search
    send_to: [hr_manager]
    forward_if: "the email announces a change to next week's roster"     # needs an AI provider
```

- Every condition inside `match` must hold; inside a list, any value matches.
- `query` runs a server-side search: Gmail's own syntax (`from:`, `subject:`, `label:`,
  `has:attachment`, `newer_than:`) on Gmail/IMAP, Outlook KQL (`from:`, `subject:`, `received>=`)
  on Microsoft 365.
- `forward_if` lets the model act as a gate: it reads the email and only forwards when the
  condition is true. Rules without it always forward once they match.
- `extract` + `template` turn a machine-generated alert into a human message without any AI:
  each `extract` entry is a regex run over the subject and body (first capture group, or all
  groups joined with a space; `title: true` fixes SHOUTING CASE; `default` fills in when it
  doesn't match), and `template` uses them as `{field}` placeholders alongside the built-ins
  `{subject}`, `{from}`, `{date}`, `{body}` and `{summary}`. See the nurse-call rule in
  `config.yaml` for a worked example.
- Each email is forwarded at most once per rule. Processed IDs live in `state.json`, so restarts
  never double-send.

## Outlook / Microsoft 365

Microsoft 365 rejects password logins over IMAP, so the agent talks to the Microsoft Graph API
instead. It needs an *app registration* in your Microsoft tenant (free, five minutes) and a
one-time sign-in from the phone.

1. Go to <https://entra.microsoft.com> → **App registrations** → **New registration**.
   Name: `email-whatsapp-agent`. Supported account types: *Accounts in this organizational
   directory only*. Leave Redirect URI empty. Register.
2. On the app's **Authentication** page → *Advanced settings* → **Allow public client flows: Yes** → Save.
3. On **API permissions** → *Add a permission* → *Microsoft Graph* → *Delegated* → tick `Mail.Read`
   (and `Mail.ReadWrite` if you set `mark_as_read: true`). `User.Read` is already there.
4. Copy the **Application (client) ID** from the Overview page into `config.yaml`:
   ```yaml
   email:
     provider: graph
     user: CEO@alsalam.care
     client_id: "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
     tenant: alsalam.care        # your organisation's domain, or the Directory (tenant) ID
   ```
   A single-tenant app must name its tenant here; the generic `organizations` value gives
   error AADSTS50059.
5. Sign in once from the phone:
   ```bash
   python -m agent --login
   ```
   It prints a code and the URL `https://microsoft.com/devicelogin`. Open the URL in any browser,
   enter the code, sign in with the mailbox account and accept the permissions. The refresh token is
   cached in `graph_token.json` (git-ignored) and renews itself; you only repeat this if IT revokes it.

If your IT team has disabled "users can register applications", ask them to create the app
registration for you with the settings above, or to approve it once (some tenants show
*"Need admin approval"* at step 5). No secret or certificate is involved, so the app cannot be used
by anyone who doesn't sign in themselves.

**Plan B without any admin help (what the shipped config uses):** create an Outlook rule that
redirects the matching emails to a Gmail address and run the agent against Gmail with
`provider: imap`. In Outlook on the web: gear icon → **Mail** → **Rules** → **Add new rule**;
condition *Subject includes* `Repeat Nurse Call`; action **Redirect to** your Gmail address
(falls back to *Forward to* if Redirect is missing; the shipped rule accepts the `FW:` prefix).
Some organisations block external auto-forwarding, so send yourself a test and check it arrives
in Gmail, and check the Gmail spam folder the first time.

### The Al Salam nurse-call setup

The committed `config.yaml` is already set for this: an Outlook rule redirects the alerts (subjects
starting with `Repeat Nurse Call`, roughly ten a day) to Gmail, the agent reads Gmail over IMAP,
pulls the ward/room/gap/time out of the alert with the `extract` block, and sends a short human
message (not the raw table) through the WhatsApp Cloud API to the number in `.env` under
`NURSE_CALL_WHATSAPP`. The message looks like:

```
*Repeat nurse call: Room 806, Ward 8 Nurse Station & Physio*
The call button was pressed again 8 min after the previous call (Call at 7:10 PM).

Could you please check on the patient now and send me a quick update here once they've been seen? Thank you.
```

Phone numbers and personal addresses are kept out of git on purpose: this repository is public.

**Running this without a phone at all:** `gas/` has the same rule ported to Google Apps Script,
running on a 1-minute schedule under your own Gmail account instead of on Termux — no phone,
no battery/permission settings, and it's private since nothing is published. See `gas/README.md`.

## Turning on AI (free tier)

1. Get a key at <https://aistudio.google.com/apikey> (free, no card).
2. In `.env`: `GEMINI_API_KEY=...`
3. In `config.yaml`:
   ```yaml
   ai:
     provider: gemini
     max_words: 120
   ```

The model receives the email and returns a JSON decision (`forward`, `reason`, `message`).
If the model is down or rate-limited, rules without `forward_if` fall back to a plain excerpt;
rules with `forward_if` skip that email and log an error, since the gate cannot be evaluated.

To use Claude instead: `pip install anthropic`, put `ANTHROPIC_API_KEY` in `.env`, and set
`provider: anthropic`. The default model is `claude-opus-5` with low effort; override with
`model:` and `effort:`. Note that installing the `anthropic` package on Termux may require
`pkg install rust binutils` because of its `pydantic-core` dependency.

## Fully automatic delivery with the WhatsApp Cloud API

Use this when you want zero taps and have at most 5 recipients.

1. Create a Meta developer account and an app at <https://developers.facebook.com>, add the
   **WhatsApp** product. The dashboard's *API Setup* page gives you a **test phone number**, a
   **Phone number ID**, and a temporary **access token** (generate a permanent one via a System
   User under Business Settings when you're happy).
2. On the same page, add each recipient under **To** → *Manage phone number list*. They receive an
   OTP on WhatsApp and must confirm. Maximum 5.
3. Create a message template (WhatsApp → Message templates), category *Utility*, name
   `email_forward`, body: `{{1}}`. Approval usually takes minutes.
4. In `.env`: `WA_PHONE_NUMBER_ID=...` and `WA_ACCESS_TOKEN=...`
5. In `config.yaml`:
   ```yaml
   whatsapp:
     backend: cloud_api
     cloud_api:
       mode: template
       template_name: email_forward
   ```

Template parameters cannot contain line breaks, so the agent flattens the message into
`subject | from | summary`. If a recipient replies to the test number, a 24-hour window opens in
which `mode: text` works and keeps the line breaks.

## Command line

```
python -m agent [--config config.yaml] [--env .env] [--once] [--interval N] [--dry-run]
                [--test-whatsapp +PHONE] [--login] [-v]
```

## Layout

```
agent/config.py     config.yaml + .env loading and validation
agent/mail.py       IMAP connection, search, parsing (plain/HTML, attachments)
agent/graph_mail.py Microsoft 365 / Outlook via Microsoft Graph (device-code sign-in)
agent/rules.py      rule matching
agent/ai.py         none / gemini / anthropic decision + summary
agent/whatsapp.py   console / intent / cloud_api senders
agent/state.py      de-duplication store
agent/main.py       polling loop and CLI
config.yaml         the live Al Salam nurse-call configuration
scripts/            Termux install, run loop, boot script
tests/              pytest suite (no network needed)
```

Run the tests on any machine:

```bash
pip install -r requirements-dev.txt
python -m pytest
```

## No-code alternative (no Termux)

If you'd rather not run a script, the free tier of **MacroDroid** can do a simpler version:
trigger *Notification received* from Gmail containing your keyword → action *Open URL*
`https://wa.me/<number>?text=[notification]` → *UI Interaction: click "Send"*. It can't summarize
and breaks when WhatsApp changes its layout, but it needs zero code.

## iPhone

iOS does not allow a long-running background script. The closest equivalents are the Shortcuts
app (which can open WhatsApp with a pre-filled message but needs you to trigger it) or running
this agent on any always-on machine (an old laptop, a Raspberry Pi, a free-tier cloud VM) with
`backend: cloud_api`.
