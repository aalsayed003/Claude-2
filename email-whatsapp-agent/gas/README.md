# Nurse-call alerts on Google Apps Script (no phone, no auto-forwarding)

"Repeat Nurse Call" and "Overdue Nurse Call" alerts turn into a short WhatsApp message via the
Meta Cloud API — running on Google's servers, not on a phone, and reading Outlook directly
rather than auto-forwarding mail out of the hospital's mailbox. Two free services do the work:

```
Outlook  --[Power Automate]-->  Google Sheet  --[Apps Script, every 1 min]-->  WhatsApp
```

**Power Automate** reads the Outlook mailbox directly with its built-in, free "When a new email
arrives" trigger and appends each matching alert's timestamp/subject/body as a row in a Google
Sheet (also a free, standard action). **Apps Script** (`Code.gs`) checks that sheet once a
minute, extracts the ward/room/timing exactly like the Termux agent did, and sends the WhatsApp
message.

## Why Google Sheets (after two other things didn't work out)

Two alternatives were tried first, on the reasoning that keeping the bridge file inside
Microsoft's own infrastructure would be cleaner. Both hit a wall specific to this tenant's
setup, not something configurable away:

- **A OneDrive Excel file shared as "Anyone with the link."** This tenant's sharing policy
  revokes anonymous links outright ("This link has been removed") — common in healthcare for
  data-governance reasons.
- **A second Power Automate flow exposing the data over its own HTTP trigger.** On this
  tenant's Power Automate license, *any* HTTP-based connector — not just the outbound "HTTP"
  action, but also the inbound "When a HTTP request is received" trigger — requires a Premium
  license to activate.

That leaves Google Sheets as the practical free bridge. Its one real drawback: Power Automate's
Google Sheets connector shares a single Google API quota across *every* Power Automate customer
using it — not just you — so writes occasionally fail with `429 Too many requests`, and a failed
write means that row (and its alert) never lands anywhere unless something catches it. Setup
step 1 below hardens the flow against exactly that, so a 429 causes a delay, not a silent loss.

The retry policy has its own side effect worth knowing about: occasionally "Insert row" actually
succeeds on Google's side, but the success response back to Power Automate is lost, so Power
Automate thinks it failed and retries — appending the *same* alert as a second (or third) row
with an identical Timestamp and Subject. `Code.gs` catches this with a small rolling list of
already-sent Timestamp+Subject pairs (`SEEN_KEYS`, capped at the last 50), so a duplicated row
is skipped instead of triggering a repeat WhatsApp message for the same real event.

## Why this shape

- **No auto-forwarding.** The alert never gets forwarded out of the Outlook mailbox to an
  external inbox — Power Automate reads it from Outlook directly.
- **No admin approval needed.** Power Automate's own Outlook connector is pre-trusted by the
  tenant (confirmed: it connects without the "needs admin approval" screen a custom app hits).
- **Free.** Power Automate's Outlook trigger and Google Sheets "Insert row" action are both on
  the free/standard plan. Only the actual WhatsApp send (a generic outbound web request) needs
  Premium in Power Automate, so that step is deliberately done in Apps Script instead, for free.
- **No phone.** Runs on Google's and Microsoft's own servers on a genuine 1-minute schedule.

## Setup

### 1. The Power Automate flow (reads Outlook, writes to a Sheet)

1. Create a Google Sheet named `nurse-call-inbox` with header row `Timestamp | Subject | Body`.
2. At <https://make.powerautomate.com>, signed in as the Outlook mailbox owner, create an
   **Automated cloud flow** with trigger **When a new email arrives (V3)**.
3. Under the trigger's advanced parameters, set **Subject Filter** to `Nurse Call` (not `Repeat
   Nurse Call`) — the filter is a plain substring match, and `Nurse Call` is the part both
   `Repeat Nurse Call` and `Overdue Nurse Call` subjects share, so this one filter catches both
   alert types. **If your flow already has `Repeat Nurse Call` in this field, update it now** —
   otherwise Overdue Nurse Call alerts will never reach the sheet at all.
4. Add a **Google Sheets → Insert row** action: File `nurse-call-inbox`, Worksheet `Sheet1`,
   mapping `Timestamp` → *Received Time*, `Subject` → *Subject*, `Body` → *Body*.
5. **Harden it against Google's 429s** — click the "Insert row" action → **Settings** (the
   `...` menu, or the gear/Settings tab) → turn on **Retry Policy** → type **Exponential
   Interval**, **Count** `10`, **Minimum Interval** `PT30S`, **Maximum Interval** `PT1H`. This
   makes the flow keep retrying a failed write for up to about an hour instead of giving up
   after a few minutes, which covers the vast majority of transient 429 windows.
6. Add a **Configure run after** branch on a new step that runs only if "Insert row" still fails
   after every retry: add **Outlook → Send an email (V2)**, to yourself, subject like `Nurse-call
   logging failed`, body something generic like `A nurse-call alert could not be logged to the
   tracking sheet after retrying. Check the flow run history.` — deliberately no room/ward/patient
   details in this fallback email, since its only job is to tell you to go look, not to carry the
   alert itself.
7. Save and turn the flow **On**.

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

Same three-step pipeline as `agent/main.py`, just in Apps Script, and two alert types are
recognized — each is one entry in the `RULES` array near the top of `Code.gs`:

- **Repeat Nurse Call** — the same room's call button was pressed again before the first call
  was answered. Subject like `Repeat Nurse Call - WARD 8 NURSE STATION & PHYSIO / 011: Room 806
  (called again after 6 min)`; ward/room/gap are pulled from the subject, call type/time from
  the body table.
- **Overdue Nurse Call** — a call has gone unanswered past the response-time threshold at all,
  whether or not it was ever repeated. Subject like `Overdue Nurse Call - EMERGENCY / 009: BED
  09 (10m 15s)`; ward/room/waiting time are all pulled from the body's Ward/Address/Waiting
  table rows instead, since this subject format doesn't have a clean delimiter to split on.

For either type: a regex on the Subject confirms which rule applies (allowing an `FW:`/`Fwd:`
prefix from a forward, but not `RE:` replies), that rule's own regexes pull the relevant fields
out of the subject+body — with fallback words ("the ward", "a room", "a few minutes", "a while")
if an alert ever looks different — and the message is sent through the Meta Cloud API's
`email_forward` template, the identical API call `agent/whatsapp.py`'s `CloudApiSender` makes.

Rows are never re-processed: a `LAST_ROW` Script Property tracks the last sheet row already
handled, and only rows after it are read on each run.

## Editing the wording

Each rule's message text is its `template` function in the `RULES` array near the top of
`Code.gs`. Edit it directly in the Apps Script editor (or here, then paste the new version in) —
no deploy step, changes take effect on the very next trigger run. Add a new alert type by adding
another entry to `RULES` with its own `match` regex, `extract` function, and `template` function.
