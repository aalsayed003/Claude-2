/* Middle East TV - a small LG webOS app for free live Arabic channels.
 * Written in plain ES5 so it runs on older webOS (3.x / 4.x) browsers.
 * Channel lists come from the public iptv-org playlists (publicly available streams)
 * and are cached on the TV so the app still opens if the list server is down. */
(function () {
  'use strict';

  var SRC = 'https://iptv-org.github.io/iptv/';
  var GROUPS = [
    { id: 'fav', label: '★ Favourites' },
    { id: 'ara', label: 'All Arabic', url: SRC + 'languages/ara.m3u' },
    { id: 'bh', label: 'Bahrain', url: SRC + 'countries/bh.m3u' },
    { id: 'sa', label: 'Saudi', url: SRC + 'countries/sa.m3u' },
    { id: 'ae', label: 'UAE', url: SRC + 'countries/ae.m3u' },
    { id: 'kw', label: 'Kuwait', url: SRC + 'countries/kw.m3u' },
    { id: 'qa', label: 'Qatar', url: SRC + 'countries/qa.m3u' },
    { id: 'om', label: 'Oman', url: SRC + 'countries/om.m3u' },
    { id: 'eg', label: 'Egypt', url: SRC + 'countries/eg.m3u' },
    { id: 'lb', label: 'Lebanon', url: SRC + 'countries/lb.m3u' },
    { id: 'iq', label: 'Iraq', url: SRC + 'countries/iq.m3u' },
    { id: 'jo', label: 'Jordan', url: SRC + 'countries/jo.m3u' },
    { id: 'news', label: 'News', url: SRC + 'categories/news.m3u', arabicOnly: true },
    { id: 'custom', label: '+ My list' }
  ];
  var CACHE_HOURS = 12;
  var ROW_HEIGHT = 76;
  var STALL_MS = 20000;

  var KEY = {
    LEFT: 37, UP: 38, RIGHT: 39, DOWN: 40, ENTER: 13,
    BACK: 461, BACKSPACE: 8, ESC: 27,
    CH_UP: 33, CH_DOWN: 34,
    RED: 403, GREEN: 404, YELLOW: 405, BLUE: 406
  };

  var state = {
    groupIndex: 1,
    channels: [],
    focus: 0,
    playing: null,
    playingList: [],
    mode: 'list',
    loadToken: 0
  };

  var el = {};
  var hls = null;
  var stallTimer = null;
  var osdTimer = null;

  // ---------- storage (wrapped: storage can be unavailable) ----------
  function load(key, fallback) {
    try {
      var v = window.localStorage.getItem(key);
      return v ? JSON.parse(v) : fallback;
    } catch (e) { return fallback; }
  }
  function save(key, value) {
    try { window.localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* storage full or blocked */ }
  }

  // ---------- playlist ----------
  function attr(line, name) {
    var m = line.match(new RegExp(name + '="([^"]*)"'));
    return m ? m[1] : '';
  }

  function parseM3U(text, arabicOnly) {
    var lines = text.split(/\r?\n/);
    var out = [];
    var seen = {};
    var cur = null;
    for (var i = 0; i < lines.length; i++) {
      var line = lines[i].replace(/^\s+|\s+$/g, '');
      if (!line) continue;
      if (line.indexOf('#EXTINF') === 0) {
        var comma = line.lastIndexOf(',');
        cur = {
          name: comma >= 0 ? line.slice(comma + 1).replace(/^\s+|\s+$/g, '') : 'Channel',
          logo: attr(line, 'tvg-logo'),
          id: attr(line, 'tvg-id'),
          group: attr(line, 'group-title'),
          needsHeaders: false
        };
      } else if (line.indexOf('#EXTVLCOPT') === 0 || line.indexOf('#KODIPROP') === 0) {
        // The TV video player cannot send custom referrer / user-agent headers
        if (cur) cur.needsHeaders = true;
      } else if (line.charAt(0) !== '#' && cur) {
        cur.url = line;
        if (!cur.needsHeaders && !seen[cur.url] && (!arabicOnly || isArabic(cur))) {
          seen[cur.url] = true;
          out.push(cur);
        }
        cur = null;
      }
    }
    return out;
  }

  var ARAB_SUFFIX = /\.(bh|sa|ae|kw|qa|om|eg|lb|iq|jo|sy|ye|ps|ly|tn|dz|ma|sd)(@|$)/i;
  function isArabic(ch) {
    return ARAB_SUFFIX.test(ch.id) || /[؀-ۿ]/.test(ch.name) ||
      /arab|jazeera|alarabiya|asharq|mbc|rotana|sky news arabia/i.test(ch.name);
  }

  function fetchText(url, done) {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.timeout = 20000;
    xhr.onload = function () {
      if (xhr.status >= 200 && xhr.status < 300) done(null, xhr.responseText);
      else done(new Error('HTTP ' + xhr.status));
    };
    xhr.onerror = function () { done(new Error('network')); };
    xhr.ontimeout = function () { done(new Error('timeout')); };
    xhr.send();
  }

  function loadGroup(group, done) {
    if (group.id === 'fav') return done(null, load('favs', []));
    var url = group.url;
    if (group.id === 'custom') {
      url = load('customUrl', '');
      if (!url) return done(null, []);
    }
    var cacheKey = 'pl:' + url;
    var cached = load(cacheKey, null);
    var fresh = cached && (Date.now() - cached.t) < CACHE_HOURS * 3600 * 1000;
    if (fresh) return done(null, cached.list);
    fetchText(url, function (err, text) {
      if (err) {
        if (cached) return done(null, cached.list, true);
        return done(err);
      }
      var list = parseM3U(text, group.arabicOnly);
      save(cacheKey, { t: Date.now(), list: list });
      done(null, list);
    });
  }

  // ---------- UI ----------
  function renderGroups() {
    var html = '';
    for (var i = 0; i < GROUPS.length; i++) {
      html += '<div class="group' + (i === state.groupIndex ? ' active' : '') + '" data-g="' + i + '">' + esc(GROUPS[i].label) + '</div>';
    }
    el.groups.innerHTML = html;
  }

  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function isFav(ch) {
    var favs = load('favs', []);
    for (var i = 0; i < favs.length; i++) if (favs[i].url === ch.url) return true;
    return false;
  }

  function renderList() {
    var list = state.channels;
    var visible = Math.max(1, Math.floor(el.list.clientHeight / ROW_HEIGHT));
    var start = Math.max(0, Math.min(state.focus - Math.floor(visible / 2), list.length - visible));
    var html = '';
    for (var i = start; i < Math.min(list.length, start + visible); i++) {
      var ch = list[i];
      var cls = 'item';
      if (i === state.focus && state.mode === 'list') cls += ' focus';
      if (state.playing && state.playing.url === ch.url) cls += ' playing';
      html += '<div class="' + cls + '" data-i="' + i + '">' +
        (ch.logo ? '<img src="' + esc(ch.logo) + '" onerror="this.style.visibility=\'hidden\'">' : '<div class="noimg"></div>') +
        '<span class="name">' + esc(ch.name) + '</span>' +
        (isFav(ch) ? '<span class="fav">★</span>' : '') +
        '</div>';
    }
    el.list.innerHTML = html;
  }

  function setStatus(text) { el.status.textContent = text; }

  function selectGroup(index) {
    state.groupIndex = (index + GROUPS.length) % GROUPS.length;
    save('groupIndex', state.groupIndex);
    renderGroups();
    var group = GROUPS[state.groupIndex];
    var token = ++state.loadToken;
    state.channels = [];
    state.focus = 0;
    renderList();
    if (group.id === 'custom' && !load('customUrl', '')) {
      setStatus('Press OK or GREEN to add your own M3U playlist link');
      return;
    }
    setStatus('Loading ' + group.label + '…');
    loadGroup(group, function (err, list, offline) {
      if (token !== state.loadToken) return; // user moved on to another category
      if (err) {
        setStatus('Could not load the list. Check the TV internet connection.');
        return;
      }
      state.channels = list;
      if (!list.length) {
        setStatus(group.id === 'fav' ? 'No favourites yet. Press RED on a channel to add it.' : 'No channels found.');
      } else {
        setStatus(list.length + ' channels' + (offline ? ' (saved list, offline)' : ''));
      }
      renderList();
    });
  }

  function moveFocus(delta) {
    if (!state.channels.length) return;
    state.focus = Math.max(0, Math.min(state.channels.length - 1, state.focus + delta));
    renderList();
  }

  function toggleFav() {
    var ch = state.mode === 'full' ? state.playing : state.channels[state.focus];
    if (!ch) return;
    var favs = load('favs', []);
    var kept = [];
    var removed = false;
    for (var i = 0; i < favs.length; i++) {
      if (favs[i].url === ch.url) removed = true; else kept.push(favs[i]);
    }
    if (!removed) kept.push({ name: ch.name, logo: ch.logo, url: ch.url, id: ch.id });
    save('favs', kept);
    showOsd(ch.name, removed ? 'Removed from favourites' : 'Added to favourites ★');
    if (GROUPS[state.groupIndex].id === 'fav') {
      state.channels = kept;
      state.focus = Math.min(state.focus, Math.max(0, kept.length - 1));
    }
    renderList();
  }

  function showOsd(name, info, sticky) {
    el.osdName.textContent = name || '';
    el.osdInfo.textContent = info || '';
    el.osd.style.display = 'block';
    clearTimeout(osdTimer);
    if (!sticky) osdTimer = setTimeout(function () { el.osd.style.display = 'none'; }, 4000);
  }

  function setMode(mode) {
    state.mode = mode;
    document.body.className = mode === 'full' ? 'full' : '';
    renderList();
  }

  // ---------- player ----------
  function stopHls() {
    if (hls) { try { hls.destroy(); } catch (e) { /* ignore */ } hls = null; }
  }

  function canNative() {
    return !!el.video.canPlayType && el.video.canPlayType('application/vnd.apple.mpegurl') !== '';
  }

  function safePlay() {
    var r = el.video.play();
    if (r && r.catch) r.catch(function () { /* interrupted by a newer channel */ });
  }

  var playToken = 0;
  function play(ch) {
    var token = ++playToken;
    stopHls();
    clearTimeout(stallTimer);
    state.playing = ch;
    state.playingList = state.channels.slice();
    save('last', ch);
    el.placeholder.style.display = 'none';
    showOsd(ch.name, 'Connecting…', true);
    renderList();

    var useNativeFirst = canNative();
    var triedOther = false;

    function fail() {
      // Deferred: hls.js must not be destroyed from inside its own error event
      setTimeout(failNow, 0);
    }
    function failNow() {
      if (token !== playToken) return; // a newer channel is playing
      clearTimeout(stallTimer);
      if (!triedOther) { triedOther = true; start(!useNativeFirst); return; }
      showOsd(ch.name, 'This channel is not available right now. Try another one.', true);
    }

    function start(native) {
      stopHls();
      clearTimeout(stallTimer);
      stallTimer = setTimeout(fail, STALL_MS);
      if (native || !(window.Hls && window.Hls.isSupported())) {
        if (!native && !canNative()) { fail(); return; }
        el.video.onerror = fail;
        el.video.src = ch.url;
        safePlay();
      } else {
        el.video.onerror = null;
        el.video.removeAttribute('src');
        el.video.load();
        hls = new window.Hls({ maxBufferLength: 30 });
        hls.on(window.Hls.Events.ERROR, function (evt, data) {
          if (data && data.fatal) fail();
        });
        hls.loadSource(ch.url);
        hls.attachMedia(el.video);
        hls.on(window.Hls.Events.MANIFEST_PARSED, safePlay);
      }
    }

    el.video.onplaying = function () {
      clearTimeout(stallTimer);
      showOsd(ch.name, 'Live');
    };
    start(useNativeFirst);
  }

  function zap(delta) {
    var list = state.playingList.length ? state.playingList : state.channels;
    if (!list.length) return;
    var idx = 0;
    for (var i = 0; i < list.length; i++) if (state.playing && list[i].url === state.playing.url) { idx = i; break; }
    idx = (idx + delta + list.length) % list.length;
    if (list === state.channels) state.focus = idx;
    play(list[idx]);
  }

  // ---------- custom playlist dialog ----------
  function openSettings() {
    el.settings.className = '';
    el.m3uInput.value = load('customUrl', '');
    el.m3uInput.focus();
  }
  function closeSettings() {
    el.settings.className = 'hidden';
    el.m3uInput.blur();
  }
  function settingsOpen() { return el.settings.className !== 'hidden'; }

  // ---------- keys ----------
  function onKey(e) {
    var k = e.keyCode;

    if (settingsOpen()) {
      // Simple remote navigation: input on top, buttons in a row below
      var order = [el.m3uInput, document.getElementById('m3u-save'), document.getElementById('m3u-clear'), document.getElementById('m3u-close')];
      var at = order.indexOf(document.activeElement);
      if (k === KEY.BACK || k === KEY.ESC) closeSettings();
      else if (k === KEY.DOWN && at === 0) order[1].focus();
      else if (k === KEY.UP && at > 0) order[0].focus();
      else if (k === KEY.RIGHT && at > 0 && at < 3) order[at + 1].focus();
      else if (k === KEY.LEFT && at > 1) order[at - 1].focus();
      else return; // typing, OK on a button, cursor moves inside the input
      e.preventDefault();
      return;
    }

    var handled = true;
    if (state.mode === 'full') {
      if (k === KEY.UP || k === KEY.CH_UP) zap(-1);
      else if (k === KEY.DOWN || k === KEY.CH_DOWN) zap(1);
      else if (k === KEY.ENTER || k === KEY.LEFT || k === KEY.RIGHT) showOsd(state.playing ? state.playing.name : '', 'Press BACK for the channel list');
      else if (k === KEY.BACK || k === KEY.BACKSPACE || k === KEY.ESC) setMode('list');
      else if (k === KEY.RED) toggleFav();
      else handled = false;
    } else {
      if (k === KEY.UP) moveFocus(-1);
      else if (k === KEY.DOWN) moveFocus(1);
      else if (k === KEY.CH_UP) moveFocus(-8);
      else if (k === KEY.CH_DOWN) moveFocus(8);
      else if (k === KEY.LEFT) selectGroup(state.groupIndex - 1);
      else if (k === KEY.RIGHT) selectGroup(state.groupIndex + 1);
      else if (k === KEY.ENTER) {
        var ch = state.channels[state.focus];
        if (!ch) { if (GROUPS[state.groupIndex].id === 'custom') openSettings(); }
        else if (state.playing && state.playing.url === ch.url) setMode('full');
        else play(ch);
      }
      else if (k === KEY.RED) toggleFav();
      else if (k === KEY.GREEN) openSettings();
      else if (k === KEY.BACK || k === KEY.BACKSPACE || k === KEY.ESC) {
        if (state.playing) setMode('full');
      }
      else handled = false;
    }
    if (handled) e.preventDefault();
  }

  // ---------- start ----------
  function init() {
    el.groups = document.getElementById('groups');
    el.list = document.getElementById('list');
    el.status = document.getElementById('status');
    el.video = document.getElementById('video');
    el.placeholder = document.getElementById('placeholder');
    el.osd = document.getElementById('osd');
    el.osdName = document.getElementById('osd-name');
    el.osdInfo = document.getElementById('osd-info');
    el.settings = document.getElementById('settings');
    el.m3uInput = document.getElementById('m3u-input');

    document.getElementById('m3u-save').onclick = function () {
      save('customUrl', el.m3uInput.value.replace(/^\s+|\s+$/g, ''));
      closeSettings();
      selectGroup(GROUPS.length - 1);
    };
    document.getElementById('m3u-clear').onclick = function () {
      save('customUrl', '');
      closeSettings();
      selectGroup(GROUPS.length - 1);
    };
    document.getElementById('m3u-close').onclick = closeSettings;

    // Magic Remote pointer / mouse clicks
    el.list.onclick = function (e) {
      var t = e.target;
      while (t && t !== el.list && !t.getAttribute('data-i')) t = t.parentNode;
      if (!t || t === el.list) return;
      state.focus = parseInt(t.getAttribute('data-i'), 10);
      onKey({ keyCode: KEY.ENTER, preventDefault: function () {} });
    };
    el.groups.onclick = function (e) {
      var g = e.target.getAttribute && e.target.getAttribute('data-g');
      if (g !== null && g !== undefined) selectGroup(parseInt(g, 10));
    };
    document.getElementById('stage').onclick = function () {
      if (state.playing) setMode(state.mode === 'full' ? 'list' : 'full');
    };

    document.addEventListener('keydown', onKey, false);
    window.addEventListener('resize', renderList, false);

    var g = load('groupIndex', 1);
    selectGroup(typeof g === 'number' && g >= 0 && g < GROUPS.length ? g : 1);

    var last = load('last', null);
    if (last && last.url) play(last);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, false);
  else init();
})();
