/**
 * Nurse-call alerts -> WhatsApp, running entirely inside Google Apps Script.
 *
 * No phone, no Termux, no server, and no auto-forwarding out of Outlook: a Power Automate
 * flow ("Nurse call") reads the Outlook mailbox directly and appends each matching email's
 * Timestamp/Subject/Body as a row in an Excel table stored in OneDrive. This script polls
 * that file on a 1-minute time-driven trigger and sends new alerts to WhatsApp.
 *
 * The file is read via its "Anyone with the link" download URL and parsed as a raw .xlsx
 * (a zip of XML) with UrlFetchApp + Utilities.unzip + XmlService - no Microsoft Graph OAuth,
 * so no admin-consent wall. (An earlier version read a Google Sheet instead, but Power
 * Automate's Google Sheets connector shares a Google API quota across all Power Automate
 * customers and started failing with 429 "Too many requests" - see gas/README.md.)
 *
 * Config lives in Script Properties (Project Settings -> Script Properties), never in code:
 *   WA_PHONE_NUMBER_ID    Meta Cloud API phone number ID
 *   WA_ACCESS_TOKEN       Meta Cloud API access token
 *   NURSE_CALL_WHATSAPP   recipient in international format, e.g. +97333592461
 *   EXCEL_DOWNLOAD_URL    the OneDrive Excel file's share link with &download=1 appended
 *   TEMPLATE_NAME         default "email_forward"
 *   TEMPLATE_LANGUAGE     default "en_US"
 *   GRAPH_API_VERSION     default "v21.0"
 *   LAST_ROW              set automatically; the last table row already processed
 */

var MATCH_RE = /^(?:FWD?\s*:\s*)?Repeat Nurse Call/i;
var SMALL_WORDS = {and: 1, or: 1, of: 1, the: 1, in: 1, at: 1, on: 1, for: 1, to: 1, a: 1, an: 1};
var XLSX_NS = XmlService.getNamespace('http://schemas.openxmlformats.org/spreadsheetml/2006/main');

function checkNurseCalls() {
  var props = PropertiesService.getScriptProperties();
  var recipient = normalizePhone_(requireProp_(props, 'NURSE_CALL_WHATSAPP'));
  var rowsByNumber = fetchExcelRows_(requireProp_(props, 'EXCEL_DOWNLOAD_URL'));

  // Columns: A Timestamp, B Subject, C Body (Power Automate's "Add a row into a table" appends here).
  var rowNumbers = Object.keys(rowsByNumber)
    .map(Number)
    .filter(function (n) { return n > 1; }); // row 1 is the header
  var lastRow = rowNumbers.length ? Math.max.apply(null, rowNumbers) : 1;
  var startRow = Number(props.getProperty('LAST_ROW') || '1') + 1;
  if (startRow > lastRow) return; // nothing new since the last run

  var processedThrough = startRow - 1;

  for (var rowNum = startRow; rowNum <= lastRow; rowNum++) {
    var row = rowsByNumber[rowNum] || [];
    var subject = String(row[1] || '');
    var bodyRaw = String(row[2] || '');

    if (MATCH_RE.test(subject)) {
      var haystack = subject + '\n' + htmlToText_(bodyRaw);
      var fields = {
        ward: extractField_(/Repeat Nurse Call - (.+?) \//i, haystack, {title: true, def: 'the ward'}),
        room: extractField_(/\/ \d+: (.+?) \(called/i, haystack, {title: true, def: 'a room'}),
        gap: extractField_(/called again after (.+?)\)/i, haystack, {def: 'a few minutes'}),
        call_type: extractField_(/^Call type\s+(.+?)\s*$/im, haystack, {def: 'Call'}),
        time: extractField_(/This call was at\s+\S+\s+(\d+:\d+)(?::\d+)?\s*(AM|PM)/i, haystack, {def: ''}),
      };
      var text = renderTemplate_(fields);
      if (!sendWhatsAppTemplate_(props, recipient, text)) {
        break; // stop here so this row (and any after it) is retried on the next run
      }
    }
    processedThrough = rowNum;
  }
  props.setProperty('LAST_ROW', String(processedThrough));
}

/** Downloads the OneDrive Excel file and returns {rowNumber: [colA, colB, colC]} for every used cell. */
function fetchExcelRows_(url) {
  var response = UrlFetchApp.fetch(url, {muteHttpExceptions: true, followRedirects: true});
  if (response.getResponseCode() !== 200) {
    throw new Error(
      'Could not download the Excel file (HTTP ' + response.getResponseCode() + '). ' +
      'Check EXCEL_DOWNLOAD_URL and that the OneDrive link is still shared as "Anyone with the link".'
    );
  }
  var blob = response.getBlob().setContentType('application/zip');
  var files = Utilities.unzip(blob);

  var sharedStrings = [];
  var sheetXml = null;
  for (var i = 0; i < files.length; i++) {
    var name = files[i].getName();
    if (name === 'xl/sharedStrings.xml') {
      sharedStrings = parseSharedStrings_(files[i].getDataAsString());
    } else if (name === 'xl/worksheets/sheet1.xml') {
      sheetXml = files[i].getDataAsString();
    }
  }
  if (!sheetXml) throw new Error('Downloaded file has no xl/worksheets/sheet1.xml - is EXCEL_DOWNLOAD_URL a real .xlsx file?');
  return parseSheetXml_(sheetXml, sharedStrings);
}

function parseSharedStrings_(xml) {
  var root = XmlService.parse(xml).getRootElement();
  return root.getChildren('si', XLSX_NS).map(function (si) {
    var runs = si.getChildren('r', XLSX_NS);
    if (runs.length > 0) {
      return runs.map(function (r) {
        var t = r.getChild('t', XLSX_NS);
        return t ? t.getText() : '';
      }).join('');
    }
    var t = si.getChild('t', XLSX_NS);
    return t ? t.getText() : '';
  });
}

function colLetterToNumber_(letters) {
  var n = 0;
  for (var i = 0; i < letters.length; i++) n = n * 26 + (letters.charCodeAt(i) - 64);
  return n;
}

function parseSheetXml_(xml, sharedStrings) {
  var root = XmlService.parse(xml).getRootElement();
  var sheetData = root.getChild('sheetData', XLSX_NS);
  var rowsByNumber = {};
  if (!sheetData) return rowsByNumber;

  var rowEls = sheetData.getChildren('row', XLSX_NS);
  for (var i = 0; i < rowEls.length; i++) {
    var rowEl = rowEls[i];
    var rowNum = Number(rowEl.getAttribute('r').getValue());
    var cells = rowEl.getChildren('c', XLSX_NS);
    var row = [];
    for (var j = 0; j < cells.length; j++) {
      var c = cells[j];
      var ref = c.getAttribute('r').getValue();
      var col = colLetterToNumber_(ref.match(/^[A-Z]+/)[0]);
      var vEl = c.getChild('v', XLSX_NS);
      var value = vEl ? vEl.getText() : '';
      var type = c.getAttribute('t');
      if (type && type.getValue() === 's') value = sharedStrings[Number(value)] || '';
      row[col - 1] = value;
    }
    rowsByNumber[rowNum] = row;
  }
  return rowsByNumber;
}

function requireProp_(props, name) {
  var value = props.getProperty(name);
  if (!value) throw new Error('Script property ' + name + ' is not set (Project Settings -> Script Properties)');
  return value;
}

function normalizePhone_(raw) {
  return raw.replace(/\D/g, '');
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
