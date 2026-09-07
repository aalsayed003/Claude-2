# Nurse-call alerts on Google Apps Script (no phone, no auto-forwarding)

"Repeat Nurse Call" alerts turn into a short WhatsApp message via the Meta Cloud API — running
on Google's servers, not on a phone, and reading Outlook directly rather than auto-forwarding
mail out of the hospital's mailbox. Two free services do the work:

```
Outlook  --[Power Automate]-->  OneDrive Excel  --[Apps Script, every 1 min]-->  WhatsApp
```

**Power Automate** reads the Outlook mailbox directly with its built-in, free "When a new email
arrives" trigger and appends each matching alert's timestamp/subject/body as a row in an Excel
table stored in OneDrive (also a free, standard action). **Apps Script** (`Code.gs`) downloads
that file once a minute, extracts the ward/room/timing exactly like the Termux agent did, and
sends the WhatsApp message.

## Why OneDrive Excel, not Google Sheets

An earlier version of this used a Google Sheet as the bridge. It worked, but Power Automate's
Google Sheets connector shares a single Google API quota across *every* Power Automate customer
using that connector — not just you — so writes started failing intermittently with
`429 Too many requests sent to the Google Sheets API`, with runs retrying for 11–15 minutes
before giving up. A failed write means the row never lands in the sheet, so Apps Script never
sees that alert and no WhatsApp message goes out — not acceptable for something time-sensitive.

Switching the bridge file to OneDrive/Excel keeps the write side entirely inside Microsoft's
own infrastructure (Outlook → OneDrive, same tenant), so there's no cross-vendor quota to hit.
Apps Script then reads the file straight off its "anyone with the link" download URL and parses
the raw `.xlsx` itself — no Microsoft Graph API, no OAuth, no admin-consent wall.

## Why this shape

- **No auto-forwarding.** The alert never gets forwarded out of the Outlook mailbox to an
  external inbox — Power Automate reads it from Outlook directly.
- **No admin approval needed.** Power Automate's Outlook and Excel Online connectors are
  pre-trusted by the tenant (confirmed: they connect without the "needs admin approval" screen
  a custom app registration hits). Apps Script never talks to Microsoft Graph at all — it just
  downloads a shared file over plain HTTPS.
- **Free.** Power Automate's Outlook trigger and "Add a row into a table" (Excel Online) action
  are both on the free/standard plan. Only the actual WhatsApp send (a generic outbound web
  request) is Premium in Power Automate, so that step is deliberately done in Apps Script
  instead, where it's free.
- **No phone.** Runs on Google's and Microsoft's own servers on a genuine 1-minute schedule.

## Setup

### 1. The Excel file in OneDrive

1. In OneDrive, create a new Excel workbook, e.g. `nurse-call-inbox.xlsx`.
2. Add a header row `Timestamp | Subject | Body`, select those three header cells, then
   **Insert → Table** (so Power Automate can target it as a table). Name the table
   `NurseCallTable` (Table Design → Table Name).
3. Click **Share** on the file → change the link setting to **Anyone with the link** → **Can
   view** → **Copy link**. This depends on your tenant allowing anonymous/"Anyone" links for
   OneDrive — if that option isn't available, see *If "Anyone" links are blocked* below.
4. Take the copied link and add a download flag to the end: append `&download=1` if the link
   already has a `?`, otherwise `?download=1`. Save this full URL — it's the `EXCEL_DOWNLOAD_URL`
   script property below.

### 2. The Power Automate flow (reads Outlook, writes to the Excel table)

1. At <https://make.powerautomate.com>, signed in as the Outlook mailbox owner, create an
   **Automated cloud flow** with trigger **When a new email arrives (V3)**.
2. Under the trigger's advanced parameters, set **Subject Filter** to `Repeat Nurse Call`.
3. Add an **Excel Online (Business) → Add a row into a table** action: pick the OneDrive
   location and `nurse-call-inbox.xlsx`, table `NurseCallTable`, mapping `Timestamp` →
   *Received Time*, `Subject` → *Subject*, `Body` → *Body*.
4. Save and turn the flow **On**.

(If you still have the old "Google Sheets — Insert row" action from a previous setup, delete it
and replace it with the Excel Online action above — don't run both.)

The email bodies land as raw HTML (with the full forwarded-header chain if a message was
forwarded more than once) — `Code.gs`'s `htmlToText_` strips that down to the same plain-text
shape the field-extraction regexes expect, verified against a real captured alert.

### 3. The Apps Script (reads the file, sends WhatsApp)

1. Go to <https://script.google.com>, **New project**, paste in `Code.gs` from this folder,
   rename the project `nurse-call-agent`.
2. **Project Settings** → **Script Properties** → add:

   | Property | Value |
   |---|---|
   | `WA_PHONE_NUMBER_ID` | `993751480485795` |
   | `WA_ACCESS_TOKEN` | your Meta access token (see note below) |
   | `NURSE_CALL_WHATSAPP` | `+97333592461` |
   | `EXCEL_DOWNLOAD_URL` | the OneDrive share link from step 1.4, ending in `download=1` |

   `TEMPLATE_NAME`, `TEMPLATE_LANGUAGE` and `GRAPH_API_VERSION` all have sensible defaults baked
   into `Code.gs` — only add them if you want to override one.
3. Pick **sendTestMessage** from the function dropdown and click **Run** once, to trigger the
   authorization prompt (click through **Advanced** → **Go to nurse-call-agent (unsafe)** if
   Google shows the "unverified app" warning — normal for a script only you use) and confirm the
   WhatsApp send works on its own.
4. Pick **checkNurseCalls** and **Run** it once — it should process any row already sitting in
   the table and send that alert. Check **Executions** (clock icon) for errors.
5. Set up the schedule: **Triggers** (alarm-clock icon) → **+ Add Trigger** → function
   `checkNurseCalls` → event source **Time-driven** → type **Minutes timer** → **Every minute** →
   **Save**.

That's the whole system live, with no phone involved anywhere, and nothing routed through
Google's Sheets API.

### If "Anyone" links are blocked

Some tenants disable anonymous/"Anyone" sharing links tenant-wide for data-governance reasons.
If OneDrive's share dialog only offers "People in your organization" or "Specific people", the
`EXCEL_DOWNLOAD_URL` approach above won't work as-is — come back and we'll look at reading the
file through a Microsoft Graph share-link call instead (needs a one-time OAuth consent, which
may or may not need admin approval depending on how the tenant treats read-only file scopes).

## About the access token

The temporary token from Meta's "Try it out" page expires after 24 hours — the trigger will start
failing with an authentication error once it does. When that happens, generate a **permanent**
token once (Business Settings → System Users → add a system user → assign it the WhatsApp asset →
generate a token with `whatsapp_business_messaging` permission and no expiration), then update the
`WA_ACCESS_TOKEN` script property with the new value. No code change needed.

## How it matches an alert

Same three-step pipeline as `agent/main.py`, just in Apps Script:

1. Each new row's Subject is checked against a regex confirming it really starts with
   `Repeat Nurse Call` (allowing an `FW:`/`Fwd:` prefix from the forward, but not `RE:` replies).
2. A handful of regexes pull the ward, room, gap and call time out of the subject and body —
   the same patterns as `config.yaml`'s `extract:` block, with the same fallback words
   ("the ward", "a room", "a few minutes") if an alert ever looks different.
3. The message is sent through the Meta Cloud API's `email_forward` template — the identical
   API call `agent/whatsapp.py`'s `CloudApiSender` makes.

Rows are never re-processed: a `LAST_ROW` Script Property tracks the highest table row already
handled, and only rows after it are read on each run.

## Editing the wording

The message text is the `renderTemplate_` function's `template` string, near the middle of
`Code.gs`. Edit it directly in the Apps Script editor (or here, then paste the new version in) —
no deploy step, changes take effect on the very next trigger run.
