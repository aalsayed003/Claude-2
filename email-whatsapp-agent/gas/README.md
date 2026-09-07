# Nurse-call alerts on Google Apps Script (no phone, no auto-forwarding)

"Repeat Nurse Call" alerts turn into a short WhatsApp message via the Meta Cloud API — running
on Google's servers, not on a phone, and reading Outlook directly rather than auto-forwarding
mail out of the hospital's mailbox. Two Power Automate flows plus one Apps Script do the work:

```
Outlook --[Flow 1: Power Automate]--> OneDrive Excel <--[Flow 2: HTTP trigger]-- Apps Script --> WhatsApp
```

**Flow 1** reads the Outlook mailbox directly with its built-in, free "When a new email arrives"
trigger and appends each matching alert's timestamp/subject/body as a row in an Excel table
stored in OneDrive. **Flow 2** is a tiny flow that does nothing but read that table and hand the
rows back as JSON whenever it's called — over Power Automate's own free HTTP trigger. **Apps
Script** (`Code.gs`) calls Flow 2 once a minute, extracts the ward/room/timing exactly like the
Termux agent did, and sends the WhatsApp message.

## Why two flows and an HTTP trigger, not a shared file

Two simpler designs were tried first and both hit a wall:

- **A Google Sheet as the bridge.** Power Automate's Google Sheets connector shares a single
  Google API quota across *every* Power Automate customer using it — not just you — so writes
  started failing intermittently with `429 Too many requests`, with runs retrying for 11–15
  minutes before giving up. A failed write means the row never lands anywhere, so the alert is
  silently dropped — not acceptable for something time-sensitive.
- **An OneDrive Excel file shared as "Anyone with the link."** This tenant's sharing policy
  revokes anonymous links (you'll see "This link has been removed" instead of a working
  download), which is a common data-governance setting in healthcare — so a public/anonymous
  download URL isn't available here.

The fix that avoids both: keep the Excel table in OneDrive (native Microsoft, no cross-vendor
quota), but instead of Apps Script downloading the file directly, a second flow exposes it over
Power Automate's own **"When an HTTP request is received"** trigger. That trigger (and its
paired "Response" action) is the built-in **Request connector**, which is Standard/free — not
the generic outbound "HTTP" action, which is the one Premium piece in Power Automate. Apps
Script just calls that trigger's auto-generated URL, the same way it already calls the Meta
WhatsApp API. No Microsoft Graph OAuth is involved anywhere, so there's no admin-consent wall,
and no file needs to be shared publicly.

## Why this shape

- **No auto-forwarding.** The alert never gets forwarded out of the Outlook mailbox to an
  external inbox — Flow 1 reads it from Outlook directly.
- **No admin approval needed.** Power Automate's Outlook, Excel Online, and Request connectors
  are all pre-trusted by the tenant (confirmed: the Outlook trigger connects without the "needs
  admin approval" screen a custom app registration hits). Apps Script never talks to Microsoft
  Graph — it just calls a Power Automate webhook over plain HTTPS.
- **Free.** Every connector and action used (Outlook trigger, Excel Online, Request/Response)
  is Standard. Only the actual WhatsApp send (a generic outbound web request) is Premium in
  Power Automate, so that step is deliberately done in Apps Script instead, where it's free.
- **No phone.** Runs on Google's and Microsoft's own servers on a genuine 1-minute schedule.

## Setup

### 1. The Excel file in OneDrive

1. In OneDrive, create a new Excel workbook, e.g. `nurse-call-inbox.xlsx`.
2. Add a header row `Timestamp | Subject | Body`, select those three header cells, then
   **Insert → Table**.
3. Click into the table so the **Table Design** tab appears in the ribbon. On the left is a
   **Table Name** box (defaults to `Table1`) — click it, type `NurseCallTable`, press Enter.
   That's the name you'll pick in both flows below.

### 2. Flow 1 — reads Outlook, writes to the Excel table

1. At <https://make.powerautomate.com>, signed in as the Outlook mailbox owner, create an
   **Automated cloud flow** named `Nurse call` with trigger **When a new email arrives (V3)**.
2. Under the trigger's advanced parameters, set **Subject Filter** to `Repeat Nurse Call`.
3. Add an **Excel Online (Business) → Add a row into a table** action: pick the OneDrive
   location and `nurse-call-inbox.xlsx`, table `NurseCallTable`, mapping `Timestamp` →
   *Received Time*, `Subject` → *Subject*, `Body` → *Body*.
4. Save and turn the flow **On**.

(If you have an old "Google Sheets — Insert row" action from an earlier attempt, delete it and
replace it with the Excel Online action above.)

### 3. Flow 2 — hands the table back as JSON on request

1. Create a second **Automated cloud flow** named `Nurse call - list rows` with trigger
   **When a HTTP request is received**. Leave the **Request Body JSON Schema** field empty.
2. Add an **Excel Online (Business) → List rows present in a table** action: same file
   `nurse-call-inbox.xlsx`, table `NurseCallTable`.
3. Add a **Response** action: **Status Code** `200`, **Body** set to the *value* output of the
   "List rows present in a table" action (from the dynamic content picker).
4. Save the flow, then turn it **On**. Open the trigger step — Power Automate now shows an
   **HTTP POST URL**. Copy it; this is a long, secret, auto-generated link that only this flow
   knows how to answer — treat it like a password. This is your `ROWS_API_URL` script property.

### 4. The Apps Script

1. Go to <https://script.google.com>, **New project**, paste in `Code.gs` from this folder,
   rename the project `nurse-call-agent`.
2. **Project Settings** → **Script Properties** → add:

   | Property | Value |
   |---|---|
   | `WA_PHONE_NUMBER_ID` | `993751480485795` |
   | `WA_ACCESS_TOKEN` | your Meta access token (see note below) |
   | `NURSE_CALL_WHATSAPP` | `+97333592461` |
   | `ROWS_API_URL` | the HTTP POST URL copied from Flow 2's trigger |

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

That's the whole system live, with no phone involved anywhere, no Google Sheets API in the
loop, and no file shared publicly.

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

Rows are never re-processed: a `LAST_INDEX` Script Property tracks how many rows (in the order
Flow 2 returns them, which is table order — new rows are always appended last) have already
been handled, and only rows after that count are read on each run.

## Editing the wording

The message text is the `renderTemplate_` function's `template` string, near the middle of
`Code.gs`. Edit it directly in the Apps Script editor (or here, then paste the new version in) —
no deploy step, changes take effect on the very next trigger run.
