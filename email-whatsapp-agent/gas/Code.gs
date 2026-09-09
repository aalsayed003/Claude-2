/**
 * Nurse-call alerts -> WhatsApp, running entirely inside Google Apps Script.
 *
 * No phone, no Termux, no server, and no auto-forwarding out of Outlook: a Power Automate
 * flow ("Nurse call") reads the Outlook mailbox directly and appends each matching email's
 * Timestamp/Subject/Body into a Google Sheet. This script polls that sheet on a 1-minute
 * time-driven trigger and sends new alerts to WhatsApp. Two alert types are recognized (see
 * the RULES array below): "Repeat Nurse Call" (the same room called again before the first
 * call was answered) and "Overdue Nurse Call" (a call waited on too long with no response at
 * all). See gas/README.md for setup, including
 * why this is the bridge (a OneDrive/Excel file and a Power Automate HTTP trigger were both
 * tried first and ruled out - see the README's "Why Google Sheets" section) and how the flow
 * is hardened against Google's occasional 429s so an alert is never silently lost.
 *
 * Config lives in Script Properties (Project Settings -> Script Properties), never in code:
 *   WA_PHONE_NUMBER_ID   Meta Cloud API phone number ID
 *   WA_ACCESS_TOKEN      Meta Cloud API access token
 *   NURSE_CALL_WHATSAPP  one or more recipients in international format, comma-separated,
 *                        e.g. +97333592461,+97333000000
 *   SHEET_URL            full URL of the Google Sheet the Power Automate flow writes to
 *   SHEET_NAME           default "Sheet1"
 *   TEMPLATE_NAME        default "email_forward"
 *   TEMPLATE_LANGUAGE    default "en" (must match the template's language in WhatsApp Manager)
 *   GRAPH_API_VERSION    default "v21.0"
 *   LAST_ROW             set automatically; the last sheet row already processed
 *   SEEN_KEYS            set automatically; recent Timestamp+Subject keys already sent
 *
 * SEEN_KEYS exists because Power Automate's retry policy (see gas/README.md) occasionally
 * inserts the same alert into the sheet more than once - the write succeeds on Google's side
 * but the success response is lost, so Power Automate retries and appends a duplicate row with
 * an identical Timestamp and Subject. LAST_ROW alone can't catch that (each duplicate is a real,
 * new row), so a small rolling list of already-sent Timestamp+Subject pairs is kept to skip them.
 */

var SMALL_WORDS = {and: 1, or: 1, of: 1, the: 1, in: 1, at: 1, on: 1, for: 1, to: 1, a: 1, an: 1};
var DEDUPE_LIMIT = 50;

// Each rule matches a subject pattern, pulls fields out of the subject+body, and renders its
// own WhatsApp message. Add a new alert type by adding another entry here.
var RULES = [
  {
    match: /^(?:FWD?\s*:\s*)?Repeat Nurse Call/i,
    extract: function (haystack) {
      return {
        ward: extractField_(/Repeat Nurse Call - (.+?) \//i, haystack, {title: true, def: 'the ward'}),
        room: extractField_(/\/ \d+: (.+?) \(called/i, haystack, {title: true, def: 'a room'}),
        gap: extractField_(/called again after (.+?)\)/i, haystack, {def: 'a few minutes'}),
        call_type: extractField_(/^Call type\s+(.+?)\s*$/im, haystack, {def: 'Call'}),
        time: extractField_(/This call was at\s+\S+\s+(\d+:\d+)(?::\d+)?\s*(AM|PM)/i, haystack, {def: ''}),
      };
    },
    template: function (f) {
      return (
        '🚨 *Repeat nurse call: ' + f.room + ', ' + f.ward + '*\n' +
        'The call button was pressed again ' + f.gap + ' after the previous call (' + f.call_type + ' at ' + f.time + ').\n\n' +
        'Could you please check on the patient now? Thank you.'
      );
    },
  },
  {
    match: /^(?:FWD?\s*:\s*)?Overdue Nurse Call/i,
    extract: function (haystack) {
      return {
        ward: extractField_(/^Ward\s+(.+?)\s*$/im, haystack, {title: true, def: 'the ward'}),
        room: extractField_(/^Address\s+(.+?)\s*$/im, haystack, {title: true, def: 'a room'}),
        waiting: extractField_(/^Waiting\s+(.+?)\s*$/im, haystack, {def: 'a while'}),
        call_type: extractField_(/^Call type\s+(.+?)\s*$/im, haystack, {def: 'Call'}),
        time: extractField_(/Called at\s+\S+\s+(\d+:\d+)(?::\d+)?\s*(AM|PM)/i, haystack, {def: ''}),
      };
    },
    template: function (f) {
      return (
        '🚨 *Overdue nurse call: ' + f.room + ', ' + f.ward + '*\n' +
        'This call has been waiting ' + f.waiting + ' without a response (' + f.call_type + ' at ' + f.time + ').\n\n' +
        'Could you please check on the patient now? Thank you.'
      );
    },
  },
];

function checkNurseCalls() {
  var props = PropertiesService.getScriptProperties();
  var recipients = getRecipients_(props);
  var sheetName = props.getProperty('SHEET_NAME') || 'Sheet1';
  var ss = SpreadsheetApp.openByUrl(requireProp_(props, 'SHEET_URL'));
  var sheet = ss.getSheetByName(sheetName);
  if (!sheet) throw new Error('Worksheet "' + sheetName + '" not found in the sheet');

  var lastRow = sheet.getLastRow();
  var startRow = Number(props.getProperty('LAST_ROW') || '1') + 1;
  if (startRow > lastRow) return; // nothing new since the last run

  // Columns: A Timestamp, B Subject, C Body (Power Automate's "Insert row" action appends here).
  var rows = sheet.getRange(startRow, 1, lastRow - startRow + 1, 3).getValues();
  var processedThrough = startRow - 1;

  for (var i = 0; i < rows.length; i++) {
    var subject = String(rows[i][1] || '');
    var bodyRaw = String(rows[i][2] || '');
    var rule = null;
    for (var r = 0; r < RULES.length; r++) {
      if (RULES[r].match.test(subject)) {
        rule = RULES[r];
        break;
      }
    }

    if (rule) {
      var dedupeKey = String(rows[i][0]) + '|' + subject;
      if (!isDuplicate_(props, dedupeKey)) {
        var haystack = subject + '\n' + htmlToText_(bodyRaw);
        var text = rule.template(rule.extract(haystack));
        if (!sendWhatsAppToAll_(props, recipients, text)) {
          break; // stop here so this row (and any after it) is retried on the next run
        }
        markSeen_(props, dedupeKey);
      }
    }
    processedThrough = startRow + i;
  }
  props.setProperty('LAST_ROW', String(processedThrough));
}

function requireProp_(props, name) {
  var value = props.getProperty(name);
  if (!value) throw new Error('Script property ' + name + ' is not set (Project Settings -> Script Properties)');
  return value;
}

function normalizePhone_(raw) {
  return raw.replace(/\D/g, '');
}

function getRecipients_(props) {
  return requireProp_(props, 'NURSE_CALL_WHATSAPP')
    .split(',')
    .map(function (s) { return normalizePhone_(s.trim()); })
    .filter(function (s) { return s.length > 0; });
}

/** Sends to every recipient; returns true only if all sends succeeded (so a failure retries
 * the whole row next run rather than silently skipping the recipients who didn't get it).
 * Tradeoff: if one recipient fails and others succeeded, the succeeded ones get a duplicate
 * on retry - preferred over any recipient silently missing a nurse-call alert. */
function sendWhatsAppToAll_(props, recipients, text) {
  var allOk = true;
  for (var i = 0; i < recipients.length; i++) {
    if (!sendWhatsAppTemplate_(props, recipients[i], text)) allOk = false;
  }
  return allOk;
}

function isDuplicate_(props, key) {
  var seen = JSON.parse(props.getProperty('SEEN_KEYS') || '[]');
  return seen.indexOf(key) !== -1;
}

function markSeen_(props, key) {
  var seen = JSON.parse(props.getProperty('SEEN_KEYS') || '[]');
  seen.push(key);
  if (seen.length > DEDUPE_LIMIT) seen = seen.slice(seen.length - DEDUPE_LIMIT);
  props.setProperty('SEEN_KEYS', JSON.stringify(seen));
}

var HTML_ENTITIES_ = {
  '&nbsp;': ' ', '&lt;': '<', '&gt;': '>', '&quot;': '"', '&#39;': "'", '&apos;': "'", '&amp;': '&',
};

function htmlToText_(html) {
  var text = html.replace(/<(script|style)[^>]*>[\s\S]*?<\/\1>/gi, '');
  text = text.replace(/<br\s*\/?>/gi, '\n');
  text = text.replace(/<\/t[dh]>/gi, '  '); // two spaces between a table label and its value
  text = text.replace(/<\/(p|div|tr|li|h\d)>/gi, '\n');
  text = text.replace(/<[^>]+>/g, '');
  text = text.replace(/&[a-z#0-9]+;/gi, function (m) { return HTML_ENTITIES_[m.toLowerCase()] || m; });
  text = text
    .split('\n')
    .map(function (l) { return l.replace(/[ \t]+$/, '').replace(/^[ \t]+/, ''); })
    .join('\n');
  return text.replace(/\n\s*\n\s*\n+/g, '\n\n').trim();
}

function smartTitle_(text) {
  var words = text.split(/\s+/).filter(function (w) { return w.length > 0; });
  return words
    .map(function (w, i) {
      var lw = w.toLowerCase();
      if (i > 0 && SMALL_WORDS[lw]) return lw;
      if (!/[A-Za-z]/.test(w)) return w;
      var isAllUpper = w === w.toUpperCase();
      var isAllLower = w === w.toLowerCase();
      if (isAllUpper || isAllLower) return w.charAt(0).toUpperCase() + w.slice(1).toLowerCase();
      return w; // mixed case (e.g. "Room") - leave as-is
    })
    .join(' ');
}

function extractField_(regex, text, opts) {
  opts = opts || {};
  var m = text.match(regex);
  if (!m) return opts.def || '';
  var parts = m.length > 1 ? m.slice(1).filter(function (g) { return !!g; }) : [m[0]];
  var value = parts.map(function (p) { return p.trim(); }).join(' ').trim();
  if (opts.title) value = smartTitle_(value);
  return value || opts.def || '';
}

function flattenForTemplate_(text) {
  var out = text.replace(/[\r\n\t]+|\s{5,}/g, ' | ');
  return out.replace(/^[\s|]+|[\s|]+$/g, '');
}

function sendWhatsAppTemplate_(props, toDigits, bodyText) {
  var phoneNumberId = requireProp_(props, 'WA_PHONE_NUMBER_ID');
  var token = requireProp_(props, 'WA_ACCESS_TOKEN');
  var templateName = props.getProperty('TEMPLATE_NAME') || 'email_forward';
  var templateLanguage = props.getProperty('TEMPLATE_LANGUAGE') || 'en';
  var apiVersion = props.getProperty('GRAPH_API_VERSION') || 'v21.0';
  var url = 'https://graph.facebook.com/' + apiVersion + '/' + phoneNumberId + '/messages';

  var payload = {
    messaging_product: 'whatsapp',
    to: toDigits,
    type: 'template',
    template: {
      name: templateName,
      language: {code: templateLanguage},
      components: [{type: 'body', parameters: [{type: 'text', text: flattenForTemplate_(bodyText).slice(0, 1024)}]}],
    },
  };
  var response = UrlFetchApp.fetch(url, {
    method: 'post',
    contentType: 'application/json',
    headers: {Authorization: 'Bearer ' + token},
    payload: JSON.stringify(payload),
    muteHttpExceptions: true,
  });
  var code = response.getResponseCode();
  if (code >= 200 && code < 300) {
    Logger.log('WhatsApp sent: ' + response.getContentText());
    return true;
  }
  Logger.log('WhatsApp send failed (' + code + '): ' + response.getContentText());
  return false;
}

/** Run this manually once to send a test message and trigger the OAuth consent prompt. */
function sendTestMessage() {
  var props = PropertiesService.getScriptProperties();
  var recipients = getRecipients_(props);
  var ok = sendWhatsAppToAll_(props, recipients, 'Test message from the nurse-call Apps Script.');
  Logger.log(ok ? 'Test message sent to all recipients.' : 'Test message failed for at least one recipient - see log above.');
}
