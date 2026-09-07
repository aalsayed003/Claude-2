# Nurse-call alerts on Google Apps Script (no phone, no auto-forwarding)

"Repeat Nurse Call" alerts turn into a short WhatsApp message via the Meta Cloud API — running
on Google's servers, not on a phone, and reading Outlook directly rather than auto-forwarding
mail out of the hospital's mailbox. Two free services do the work:

```
Outlook  --[Power Automate]-->  Google Sheet  --[Apps Script, every 1 min]-->  WhatsApp
```

**Power Automate** reads the Outlook mailbox directly with its built-in, free "When a new email
arrives" trigger and appends each matching alert's timestamp/subject/body as a row in a Google
Sheet (also a free, standard action). **Apps Script** (`Code.gs`) checks that sheet once a minute,
extracts the ward/room/timing exactly like the Termux agent did, and sends the WhatsApp message.

## Why this shape

- **No auto-forwarding.** The alert never gets forwarded out of the Outlook mailbox to an
  external inbox — Power Automate reads it from Outlook directly.
- **No admin approval needed.** Power Automate's own Outlook connector is pre-trusted by the
  tenant (confirmed: it connects without the "needs admin approval" screen a custom app hits).
- **Free.** Power Automate's Outlook trigger and Google Sheets "Insert row" action are both on
  the free/standard plan — no Premium connector involved. Only the actual WhatsApp send (a
  generic outbound web request) is Premium in Power Automate, so that step is deliberately done
  in Apps Script instead, where it's free.
- **No phone.** Runs on Google's and Microsoft's own servers on a genuine 1-minute schedule.

## Setup

### 1. The Power Automate flow (reads Outlook, writes to a Sheet)

1. Create a Google Sheet named `nurse-call-inbox` with header row `Timestamp | Subject | Body`.
2. At <https://make.powerautomate.com>, signed in as the Outlook mailbox owner, create an
   **Automated cloud flow** with trigger **When a new email arrives (V3)**.
3. Under the trigger's advanced parameters, set **Subject Filter** to `Repeat Nurse Call`.
4. Add a **Google Sheets → Insert row** action: File `nurse-call-inbox`, Worksheet `Sheet1`,
   mapping `Timestamp` → *Received Time*, `Subject` → *Subject*, `Body` → *Body*.
5. Save and turn the flow **On**.

The email bodies land as raw HTML (with the full forwarded-header chain if a message was
forwarded more than once) — `Code.gs`'s `htmlToText_` strips that down to the same plain-text
shape the field-extraction regexes expect, verified against a real captured alert.

### 2. The Apps Script (reads the Sheet, sends WhatsApp)

1. Go to <https://script.google.com>, signed in as the same Google account that owns the Sheet.
   **New project**, paste in `Code.gs` from this folder, rename the project `nurse-call-agent`.
2. **Project Settings** → **Script Properties** → add:

   | Property | Value |
   |---|---|
   | `WA_PHONE_NUMBER_ID` | `993751480485795` |
   | `WA_ACCESS_TOKEN` | your Meta access token (see note below) |
   | `NURSE_CALL_WHATSAPP` | `+97333592461` |
   | `SHEET_URL` | the `nurse-call-inbox` sheet's full URL (copy from the browser address bar) |

   `SHEET_NAME`, `TEMPLATE_NAME`, `TEMPLATE_LANGUAGE` and `GRAPH_API_VERSION` all have sensible
   defaults baked into `Code.gs` — only add them if you want to override one.
3. Pick **sendTestMessage** from the function dropdown and click **Run** once, to trigger the
   authorization prompt (click through **Advanced** → **Go to nurse-call-agent (unsafe)** if
   Google shows the "unverified app" warning — normal for a script only you use) and confirm the
   WhatsApp send works on its own.
4. Pick **checkNurseCalls** and **Run** it once — it should process any row already sitting in the
   sheet and send that alert. Check **Executions** (clock icon) for errors.
5. Set up the schedule: **Triggers** (alarm-clock icon) → **+ Add Trigger** → function
   `checkNurseCalls` → event source **Time-driven** → type **Minutes timer** → **Every minute** →
   **Save**.

That's the whole system live, with no phone involved anywhere.

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
