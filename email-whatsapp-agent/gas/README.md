# Nurse-call alerts on Google Apps Script (no phone needed)

Same rule as the Termux agent — "Repeat Nurse Call" alerts forwarded into Gmail get turned into a
short WhatsApp message via the Meta Cloud API — but running on Google's servers under your own
Gmail account instead of on your phone. No Termux, no battery settings, no app permissions to
fight. Free, and checks mail every minute.

## Why this instead of the phone or GitHub Actions

- **Private.** The code lives only in your Google account. Nothing is published anywhere.
- **Free.** Well within Apps Script's free daily quota for ten alerts a day.
- **Fast enough.** Supports a genuine 1-minute schedule — GitHub Actions' shortest reliable
  interval is several minutes with real timing drift, too slow for an urgent repeat-call alert.
- **No app password.** It reads Gmail as you, the way opening gmail.com does — no IMAP, no
  `EMAIL_PASSWORD`.

## Setup (10 minutes)

1. Go to <https://script.google.com> signed in as **aalsayed003@gmail.com** (the mailbox the
   Outlook rule forwards alerts into). Click **New project**.
2. Delete the placeholder code and paste in the contents of `Code.gs` from this folder.
3. Rename the project (top left, "Untitled project") to `nurse-call-agent`.
4. **Project Settings** (gear icon, left sidebar) → **Script Properties** → **Add script property**,
   one at a time:

   | Property | Value |
   |---|---|
   | `WA_PHONE_NUMBER_ID` | `993751480485795` |
   | `WA_ACCESS_TOKEN` | your Meta access token (see note below) |
   | `NURSE_CALL_WHATSAPP` | `+97333592461` |

   `TEMPLATE_NAME`, `TEMPLATE_LANGUAGE`, `GRAPH_API_VERSION`, `PROCESSED_LABEL` and
   `LOOKBACK_DAYS` all have sensible defaults baked into `Code.gs` — only add them here if you
   want to override one.
5. Back in the editor, pick **sendTestMessage** from the function dropdown (top toolbar) and click
   **Run**. The first run asks you to authorize the script — click through **Advanced** →
   **Go to nurse-call-agent (unsafe)** if Google shows the "unverified app" warning (this is
   normal for a script only you use; it isn't submitted for Google's review). Grant it.
6. Check the recipient's WhatsApp for the test message. If it fails, click **Executions** (clock
   icon) in the left sidebar to see the error from the log.
7. Once the test message arrives, set up the schedule: **Triggers** (alarm-clock icon) →
   **+ Add Trigger** → function `checkNurseCalls` → event source **Time-driven** → type
   **Minutes timer** → **Every minute** → **Save**.

That's it — it now checks Gmail every minute and sends any new nurse-call alert, with no phone
involved. You can stop `run-loop.sh` on the phone and uninstall Termux if you like.

## About the access token

The temporary token from Meta's "Try it out" page expires after 24 hours — the trigger will start
failing with an authentication error once it does. When that happens, generate a **permanent**
token once (Business Settings → System Users → add a system user → assign it the WhatsApp asset →
generate a token with `whatsapp_business_messaging` permission and no expiration), then update the
`WA_ACCESS_TOKEN` script property with the new value. No code change needed.

## How it matches an alert

Same three-step pipeline as `agent/main.py`, just in Apps Script:

1. `GmailApp.search('subject:"Repeat Nurse Call" -label:nurse-call-processed newer_than:1d')`
   finds candidates, then a regex confirms the subject really starts with `Repeat Nurse Call`
   (allowing an `FW:`/`Fwd:` prefix from the forward, but not `RE:` replies).
2. A handful of regexes pull the ward, room, gap and call time out of the subject and body —
   the same patterns as `config.yaml`'s `extract:` block, with the same fallback words
   ("the ward", "a room", "a few minutes") if an alert ever looks different.
3. The message is sent through the Meta Cloud API's `email_forward` template — the identical
   API call `agent/whatsapp.py`'s `CloudApiSender` makes — and the thread is labelled
   `nurse-call-processed` so it's never sent twice.

## Editing the wording

The message text is the `renderTemplate_` function's `template` string, near the middle of
`Code.gs`. Edit it directly in the Apps Script editor (or here, then paste the new version in) —
no deploy step, changes take effect on the very next trigger run.
