/**
 * Nurse-call alerts -> WhatsApp, running entirely inside Google Apps Script.
 *
 * No phone, no Termux, no server: this runs on Google's infrastructure under your own
 * Gmail account, on a 1-minute time-driven trigger. See gas/README.md for setup.
 *
 * Config lives in Script Properties (Project Settings -> Script Properties), never in code:
 *   WA_PHONE_NUMBER_ID   Meta Cloud API phone number ID
 *   WA_ACCESS_TOKEN      Meta Cloud API access token
 *   NURSE_CALL_WHATSAPP  recipient in international format, e.g. +97333592461
 *   TEMPLATE_NAME        default "email_forward"
 *   TEMPLATE_LANGUAGE    default "en_US"
 *   GRAPH_API_VERSION    default "v21.0"
 *   PROCESSED_LABEL      default "nurse-call-processed"
 *   LOOKBACK_DAYS        default "1"
 */

var MATCH_RE = /^(?:FWD?\s*:\s*)?Repeat Nurse Call/i;
var SMALL_WORDS = {and: 1, or: 1, of: 1, the: 1, in: 1, at: 1, on: 1, for: 1, to: 1, a: 1, an: 1};

function checkNurseCalls() {
  var props = PropertiesService.getScriptProperties();
  var recipient = normalizePhone_(requireProp_(props, 'NURSE_CALL_WHATSAPP'));
  var labelName = props.getProperty('PROCESSED_LABEL') || 'nurse-call-processed';
  var lookbackDays = props.getProperty('LOOKBACK_DAYS') || '1';

  var label = GmailApp.getUserLabelByName(labelName);
  if (!label) label = GmailApp.createLabel(labelName);

  var query = 'subject:"Repeat Nurse Call" -label:' + labelName + ' newer_than:' + lookbackDays + 'd';
  var threads = GmailApp.search(query, 0, 50);

  threads.forEach(function (thread) {
    var sentAny = false;
    var sendFailed = false;
    thread.getMessages().forEach(function (message) {
      var subject = message.getSubject() || '';
      if (!MATCH_RE.test(subject)) return;

      var body = message.getPlainBody() || '';
      var haystack = subject + '\n' + body;
      var fields = {
        ward: extractField_(/Repeat Nurse Call - (.+?) \//i, haystack, {title: true, def: 'the ward'}),
        room: extractField_(/\/ \d+: (.+?) \(called/i, haystack, {title: true, def: 'a room'}),
        gap: extractField_(/called again after (.+?)\)/i, haystack, {def: 'a few minutes'}),
        call_type: extractField_(/^Call type\s+(.+?)\s*$/im, haystack, {def: 'Call'}),
        time: extractField_(/This call was at\s+\S+\s+(\d+:\d+)(?::\d+)?\s*(AM|PM)/i, haystack, {def: ''}),
      };

      var text = renderTemplate_(fields);
      if (sendWhatsAppTemplate_(props, recipient, text)) {
        sentAny = true;
      } else {
        sendFailed = true;
      }
    });
    // Only mark the thread processed once every matching message in it sent successfully,
    // so a failed send is retried on the next run instead of being silently dropped.
    if (sentAny && !sendFailed) thread.addLabel(label);
  });
}

function requireProp_(props, name) {
  var value = props.getProperty(name);
  if (!value) throw new Error('Script property ' + name + ' is not set (Project Settings -> Script Properties)');
  return value;
}

function normalizePhone_(raw) {
  return raw.replace(/\D/g, '');
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

function renderTemplate_(fields) {
  var template =
    '*Repeat nurse call: {room}, {ward}*\n' +
    'The call button was pressed again {gap} after the previous call ({call_type} at {time}).\n\n' +
    "Could you please check on the patient now and send me a quick update here once they've been seen? Thank you.";
  return template.replace(/\{(\w+)\}/g, function (_, key) {
    return fields[key] !== undefined ? fields[key] : '';
  });
}

function flattenForTemplate_(text) {
  var out = text.replace(/[\r\n\t]+|\s{5,}/g, ' | ');
  return out.replace(/^[\s|]+|[\s|]+$/g, '');
}

function sendWhatsAppTemplate_(props, toDigits, bodyText) {
  var phoneNumberId = requireProp_(props, 'WA_PHONE_NUMBER_ID');
  var token = requireProp_(props, 'WA_ACCESS_TOKEN');
  var templateName = props.getProperty('TEMPLATE_NAME') || 'email_forward';
  var templateLanguage = props.getProperty('TEMPLATE_LANGUAGE') || 'en_US';
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
  var recipient = normalizePhone_(requireProp_(props, 'NURSE_CALL_WHATSAPP'));
  var ok = sendWhatsAppTemplate_(props, recipient, 'Test message from the nurse-call Apps Script.');
  Logger.log(ok ? 'Test message sent.' : 'Test message failed - see log above.');
}
