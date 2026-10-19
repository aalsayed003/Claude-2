/* Al Salam TV - hospital TV screen for Al Salam Specialist Hospital.
 * Two modes, chosen in config.json:
 *   "room"    - patient room: welcome screen, live TV, health videos, hospital information
 *   "waiting" - waiting area: live channel or video loop, rotating notices, news ticker
 * Plain ES5 so the same page also runs in older TV browsers. */
(function () {
  'use strict';

  var CONFIG_URL = 'data/config.json';
  var VIDEOS_API = 'api/videos';
  var CACHE_HOURS = 12;
  var ROW_HEIGHT = 76;
  var STALL_MS = 25000;

  // Remote keys from the TV remote (via HDMI-CEC), an air-mouse remote, a keyboard or a webOS TV
  var KEYS = {
    left: [37], up: [38], right: [39], down: [40],
    ok: [13],
    back: [8, 27, 461, 166, 10009],
    home: [36],
    chUp: [33, 427], chDown: [34, 428],
    red: [403, 112], green: [404, 113], yellow: [405, 114], blue: [406, 115]
  };

  var TILES = [
    { id: 'tv', en: 'Live TV', ar: 'البث المباشر',
      icon: '<svg viewBox="0 0 48 48"><rect x="5" y="11" width="38" height="26" rx="3"/><path d="M17 4l7 7 7-7M16 43h16"/></svg>' },
    { id: 'videos', en: 'Health Videos', ar: 'فيديوهات صحية',
      icon: '<svg viewBox="0 0 48 48"><circle cx="24" cy="24" r="19"/><path d="M20 16l12 8-12 8z"/></svg>' },
    { id: 'info', en: 'Hospital Information', ar: 'معلومات المستشفى',
      icon: '<svg viewBox="0 0 48 48"><circle cx="24" cy="24" r="19"/><path d="M24 22v12M24 15v1"/></svg>' }
  ];

  var config = {};
  var el = {};
  var screen = '';
  var full = false;

  var home = { focus: 0 };
  var tv = { groups: [], groupIndex: 0, channels: [], focus: 0, playing: null, playingList: [], token: 0 };
  var vids = { list: [], focus: 0, playing: false };
  var info = { focus: 0 };
  var waiting = { slide: 0, channels: [], index: 0 };

  // ---------- helpers ----------
  function $(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s === undefined || s === null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  function load(key, fallback) {
    try { var v = window.localStorage.getItem(key); return v ? JSON.parse(v) : fallback; } catch (e) { return fallback; }
  }
  function save(key, value) {
    try { window.localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* storage unavailable */ }
  }
  function is(name, code) { return KEYS[name].indexOf(code) >= 0; }
  function getText(url, done) {
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
  function getJSON(url, done) {
    getText(url, function (err, text) {
      if (err) return done(err);
      try { done(null, JSON.parse(text)); } catch (e) { done(e); }
    });
  }

  // Fit the 1920x1080 design to whatever resolution the TV reports
  function fit() {
    var s = Math.min(window.innerWidth / 1920, window.innerHeight / 1080);
    el.root.style.transform = 'scale(' + s + ')';
    el.root.style.left = Math.round((window.innerWidth - 1920 * s) / 2) + 'px';
    el.root.style.top = Math.round((window.innerHeight - 1080 * s) / 2) + 'px';
  }

  // ---------- header, clock, ticker ----------
  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function tickClock() {
    var d = new Date();
    var h = d.getHours(), ampm = h < 12 ? 'AM' : 'PM';
    el.time.textContent = ((h + 11) % 12 + 1) + ':' + pad(d.getMinutes()) + ' ' + ampm;
    var en = '', ar = '';
    try {
      en = d.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' });
      ar = d.toLocaleDateString('ar-BH', { weekday: 'long', day: 'numeric', month: 'long' });
    } catch (e) { en = d.toDateString(); }
    el.date.innerHTML = esc(en) + (ar ? ' &nbsp;|&nbsp; <span dir="rtl">' + esc(ar) + '</span>' : '');
    // Restart the page once a night (04:00) so a screen left on for months stays fresh
    if (h === 4 && d.getMinutes() === 0 && Date.now() - startedAt > 3600000) window.location.reload();
  }
  var startedAt = Date.now();

  function renderHeader() {
    var r = config.roomNumber && config.mode !== 'waiting' ? String(config.roomNumber) : '';
    el.room.innerHTML = r ? 'Room ' + esc(r) + ' &nbsp;|&nbsp; <span dir="rtl">غرفة ' + esc(r) + '</span>' : '';
  }

  function renderTicker() {
    var list = config.announcements || [];
    var parts = [];
    for (var i = 0; i < list.length; i++) {
      var a = list[i];
      if (a.en) parts.push(esc(a.en));
      if (a.ar) parts.push('<span dir="rtl">' + esc(a.ar) + '</span>');
    }
    el.tickerText.innerHTML = parts.join('<span class="sep">◆</span>');
    var chars = el.tickerText.textContent.length;
    el.tickerText.style.animationDuration = Math.max(20, Math.round(chars / 5)) + 's';
    el.ticker.style.visibility = parts.length ? 'visible' : 'hidden';
  }

  // ---------- screens ----------
  function show(name) {
    if (screen === 'tv' || screen === 'videos') stopVideo();
    screen = name;
    full = false;
    vids.playing = false;
    applyClass();
    if (name === 'home') renderHome();
    if (name === 'tv') openTv();
    if (name === 'videos') openVideos();
    if (name === 'info') renderInfo();
  }
  function applyClass() {
    el.root.className = 's-' + screen + (full ? ' s-full' : '');
  }
  function setFull(on) { full = on; applyClass(); if (screen === 'tv') renderTvList(); }

  // ---------- home ----------
  function renderHome() {
    var w = config.welcome || {};
    el.welcomeEn.textContent = w.en || '';
    el.welcomeAr.textContent = w.ar || '';
    el.welcomeSubEn.textContent = w.subEn || '';
    el.welcomeSubAr.textContent = w.subAr || '';
    var html = '';
    for (var i = 0; i < TILES.length; i++) {
      var t = TILES[i];
      html += '<div class="tile' + (i === home.focus ? ' focus' : '') + '" data-i="' + i + '">' + t.icon +
        '<div class="en">' + esc(t.en) + '</div><div class="ar" dir="rtl">' + esc(t.ar) + '</div></div>';
    }
    el.tiles.innerHTML = html;
    var c = config.contact || {};
    var bits = [];
    if (c.wifiName) bits.push('Wi-Fi / <span dir="rtl">واي فاي</span>: <b>' + esc(c.wifiName) + '</b>' + (c.wifiPassword ? ' &nbsp;·&nbsp; <b>' + esc(c.wifiPassword) + '</b>' : ''));
    if (c.phone) bits.push('Tel / <span dir="rtl">هاتف</span>: <b>' + esc(c.phone) + '</b>');
    if (c.web) bits.push('<b>' + esc(c.web) + '</b>');
    el.contactStrip.innerHTML = bits.join('<span class="sep">|</span>');
  }
  function homeKey(k) {
    if (is('left', k)) home.focus = Math.max(0, home.focus - 1);
    else if (is('right', k)) home.focus = Math.min(TILES.length - 1, home.focus + 1);
    else if (is('ok', k)) { show(TILES[home.focus].id); return true; }
    else return false;
    renderHome();
    return true;
  }

  // ---------- channel lists ----------
  function attr(line, name) {
    var m = line.match(new RegExp(name + '="([^"]*)"'));
    return m ? m[1] : '';
  }
  function blocked(name) {
    var words = config.blockedWords || [];
    var n = name.toLowerCase();
    for (var i = 0; i < words.length; i++) if (words[i] && n.indexOf(String(words[i]).toLowerCase()) >= 0) return true;
    return false;
  }
  function parseM3U(text) {
    var lines = text.split(/\r?\n/), out = [], seen = {}, cur = null;
    for (var i = 0; i < lines.length; i++) {
      var line = lines[i].replace(/^\s+|\s+$/g, '');
      if (!line) continue;
      if (line.indexOf('#EXTINF') === 0) {
        var comma = line.lastIndexOf(',');
        cur = { name: comma >= 0 ? line.slice(comma + 1).replace(/^\s+|\s+$/g, '') : 'Channel', logo: attr(line, 'tvg-logo'), needsHeaders: false };
      } else if (line.indexOf('#EXTVLCOPT') === 0 || line.indexOf('#KODIPROP') === 0) {
        if (cur) cur.needsHeaders = true; // the browser player cannot send custom headers
      } else if (line.charAt(0) !== '#' && cur) {
        cur.url = line;
        if (!cur.needsHeaders && !seen[cur.url] && !blocked(cur.name)) { seen[cur.url] = true; out.push(cur); }
        cur = null;
      }
    }
    return out;
  }
  function loadChannelList(group, done) {
    if (group.list) return done(null, group.list);
    var key = 'pl:' + group.url;
    var cached = load(key, null);
    if (cached && Date.now() - cached.t < CACHE_HOURS * 3600000) return done(null, cached.list);
    getText(group.url, function (err, text) {
      if (err) return cached ? done(null, cached.list, true) : done(err);
      var list = parseM3U(text);
      save(key, { t: Date.now(), list: list });
      done(null, list);
    });
  }
  function buildGroups() {
    var groups = [];
    if (config.channels && config.channels.length) {
      groups.push({ en: 'Recommended', ar: 'قنوات مختارة', list: config.channels });
    }
    var lists = config.channelLists || [];
    for (var i = 0; i < lists.length; i++) if (lists[i].url) groups.push(lists[i]);
    return groups;
  }

  // ---------- live TV (room) ----------
  function openTv() {
    tv.groups = buildGroups();
    renderTvGroups();
    if (!tv.channels.length) selectTvGroup(tv.groupIndex);
    else renderTvList();
    if (tv.playing) playChannel(tv.playing);
  }
  function renderTvGroups() {
    var html = '';
    for (var i = 0; i < tv.groups.length; i++) {
      var g = tv.groups[i];
      html += '<div class="group' + (i === tv.groupIndex ? ' active' : '') + '">' + esc(g.en) + ' · <span dir="rtl">' + esc(g.ar || '') + '</span></div>';
    }
    el.tvGroups.innerHTML = html;
  }
  function selectTvGroup(index) {
    if (!tv.groups.length) { el.tvStatus.textContent = 'No channel lists in config.json'; return; }
    tv.groupIndex = (index + tv.groups.length) % tv.groups.length;
    renderTvGroups();
    var token = ++tv.token;
    tv.channels = [];
    tv.focus = 0;
    renderTvList();
    el.tvStatus.innerHTML = 'Loading… · <span dir="rtl">جاري التحميل…</span>';
    loadChannelList(tv.groups[tv.groupIndex], function (err, list, offline) {
      if (token !== tv.token) return;
      if (err) { el.tvStatus.innerHTML = 'No internet connection · <span dir="rtl">لا يوجد اتصال بالإنترنت</span>'; return; }
      tv.channels = list;
      el.tvStatus.innerHTML = list.length + ' channels · <span dir="rtl">' + list.length + ' قناة</span>' + (offline ? ' (offline)' : '');
      renderTvList();
    });
  }
  function renderTvList() {
    var list = tv.channels;
    var visible = Math.max(1, Math.floor((el.tvList.clientHeight || 600) / ROW_HEIGHT));
    var start = Math.max(0, Math.min(tv.focus - Math.floor(visible / 2), list.length - visible));
    var html = '';
    for (var i = start; i < Math.min(list.length, start + visible); i++) {
      var ch = list[i], cls = 'item';
      if (i === tv.focus && !full) cls += ' focus';
      if (tv.playing && tv.playing.url === ch.url) cls += ' playing';
      html += '<div class="' + cls + '" data-i="' + i + '">' +
        (ch.logo ? '<img src="' + esc(ch.logo) + '" onerror="this.style.visibility=\'hidden\'">' : '<div class="noimg"></div>') +
        '<span class="name">' + esc(ch.name) + '</span></div>';
    }
    el.tvList.innerHTML = html;
  }
  function playChannel(ch) {
    tv.playing = ch;
    tv.playingList = tv.channels.length ? tv.channels.slice() : tv.playingList;
    renderTvList();
    playMedia(ch.url, {
      name: ch.name,
      onFail: function () { showOsd(ch.name, 'Not available now, try another channel · غير متاحة حالياً', true); }
    });
  }
  function zap(delta) {
    var list = tv.playingList.length ? tv.playingList : tv.channels;
    if (!list.length) return;
    var idx = 0;
    for (var i = 0; i < list.length; i++) if (tv.playing && list[i].url === tv.playing.url) { idx = i; break; }
    idx = (idx + delta + list.length) % list.length;
    playChannel(list[idx]);
  }
  function tvKey(k) {
    if (full) {
      if (is('up', k) || is('chUp', k)) zap(-1);
      else if (is('down', k) || is('chDown', k)) zap(1);
      else if (is('ok', k) || is('left', k) || is('right', k)) showOsd(tv.playing ? tv.playing.name : '', 'BACK for the channel list · رجوع لقائمة القنوات');
      else if (is('back', k)) setFull(false);
      else return false;
      return true;
    }
    if (is('up', k)) tv.focus = Math.max(0, tv.focus - 1);
    else if (is('down', k)) tv.focus = Math.min(Math.max(0, tv.channels.length - 1), tv.focus + 1);
    else if (is('chUp', k)) tv.focus = Math.max(0, tv.focus - 8);
    else if (is('chDown', k)) tv.focus = Math.min(Math.max(0, tv.channels.length - 1), tv.focus + 8);
    else if (is('left', k)) { selectTvGroup(tv.groupIndex - 1); return true; }
    else if (is('right', k)) { selectTvGroup(tv.groupIndex + 1); return true; }
    else if (is('ok', k)) {
      var ch = tv.channels[tv.focus];
      if (!ch) return true;
      if (tv.playing && tv.playing.url === ch.url) setFull(true);
      else playChannel(ch);
      return true;
    }
    else if (is('back', k)) { show('home'); return true; }
    else return false;
    renderTvList();
    return true;
  }

  // ---------- health videos (room) ----------
  function prettyName(file) {
    return file.replace(/^.*\//, '').replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ');
  }
  function openVideos() {
    el.videoStatus.innerHTML = 'Loading… · <span dir="rtl">جاري التحميل…</span>';
    var extra = config.videos || [];
    getJSON(VIDEOS_API, function (err, files) {
      var titles = config.videoTitles || {};
      var list = [];
      var i;
      if (!err && files && files.length) {
        for (i = 0; i < files.length; i++) {
          var t = titles[files[i].file] || {};
          list.push({ url: files[i].url, en: t.en || prettyName(files[i].file), ar: t.ar || '' });
        }
      }
      for (i = 0; i < extra.length; i++) list.push(extra[i]);
      vids.list = list;
      vids.focus = Math.min(vids.focus, Math.max(0, list.length - 1));
      el.videoStatus.innerHTML = list.length ? '' :
        'No videos yet. Copy video files into the "videos" folder. · <span dir="rtl">لا توجد فيديوهات بعد</span>';
      renderVideos();
    });
  }
  function renderVideos() {
    var html = '';
    for (var i = 0; i < vids.list.length; i++) {
      var v = vids.list[i];
      html += '<div class="vcard' + (i === vids.focus ? ' focus' : '') + '" data-i="' + i + '"><div class="en">' + esc(v.en) +
        '</div><div class="ar" dir="rtl">' + esc(v.ar) + '</div></div>';
    }
    el.videoGrid.innerHTML = html;
  }
  function videosKey(k) {
    var perRow = 4;
    if (vids.playing) {
      if (is('back', k)) { stopVideo(); vids.playing = false; setFull(false); }
      else if (is('ok', k)) { if (el.video.paused) el.video.play(); else el.video.pause(); }
      else if (is('left', k)) el.video.currentTime = Math.max(0, el.video.currentTime - 15);
      else if (is('right', k)) el.video.currentTime = el.video.currentTime + 15;
      else return false;
      return true;
    }
    var n = vids.list.length;
    if (is('left', k)) vids.focus = Math.max(0, vids.focus - 1);
    else if (is('right', k)) vids.focus = Math.min(n - 1, vids.focus + 1);
    else if (is('up', k)) vids.focus = Math.max(0, vids.focus - perRow);
    else if (is('down', k)) vids.focus = Math.min(n - 1, vids.focus + perRow);
    else if (is('ok', k)) { if (n) playLocalVideo(vids.list[vids.focus]); return true; }
    else if (is('back', k)) { show('home'); return true; }
    else return false;
    if (vids.focus < 0) vids.focus = 0;
    renderVideos();
    return true;
  }
  function playLocalVideo(v) {
    vids.playing = true;
    setFull(true);
    playMedia(v.url, {
      name: v.en,
      onEnded: function () { vids.playing = false; setFull(false); },
      onFail: function () { showOsd(v.en, 'This video cannot be played · لا يمكن تشغيل الفيديو', true); }
    });
  }

  // ---------- information (room) ----------
  function renderInfo() {
    var pages = config.infoPages || [];
    var html = '';
    for (var i = 0; i < pages.length; i++) {
      html += '<div class="menu-item' + (i === info.focus ? ' focus' : '') + '" data-i="' + i + '"><div class="en">' + esc(pages[i].titleEn) +
        '</div><div class="ar" dir="rtl">' + esc(pages[i].titleAr) + '</div></div>';
    }
    el.infoMenu.innerHTML = html;
    var p = pages[info.focus] || {};
    el.infoTitleEn.textContent = p.titleEn || '';
    el.infoTitleAr.textContent = p.titleAr || '';
    el.infoTextEn.textContent = p.en || '';
    el.infoTextAr.textContent = p.ar || '';
  }
  function infoKey(k) {
    var n = (config.infoPages || []).length;
    if (is('up', k)) info.focus = Math.max(0, info.focus - 1);
    else if (is('down', k)) info.focus = Math.min(Math.max(0, n - 1), info.focus + 1);
    else if (is('back', k) || is('left', k)) { show('home'); return true; }
    else return false;
    renderInfo();
    return true;
  }

  // ---------- waiting area ----------
  function startWaiting() {
    var w = config.waiting || {};
    show('waiting');
    nextSlide();
    if (w.main === 'videos') return waitingVideos();
    // Live channel: the configured one first, then fall back through the first channel list
    var first = w.channelUrl ? [{ name: '', url: w.channelUrl }] : [];
    var groups = buildGroups();
    var finish = function (list) {
      waiting.channels = first.concat(list || []);
      waiting.index = 0;
      waitingChannel();
    };
    if (!groups.length) return finish([]);
    loadChannelList(groups[0], function (err, list) { finish(err ? [] : list); });
  }
  function waitingChannel() {
    var list = waiting.channels;
    if (!list.length) { setTimeout(startWaiting, 60000); return; }
    var ch = list[waiting.index % list.length];
    playMedia(ch.url, {
      name: ch.name,
      muted: !!(config.waiting && config.waiting.muted),
      quiet: true,
      // Unattended screen: if a channel fails, move on to the next one by itself
      onFail: function () { waiting.index++; setTimeout(waitingChannel, 2000); }
    });
  }
  function waitingVideos() {
    getJSON(VIDEOS_API, function (err, files) {
      var list = [];
      if (!err && files) for (var i = 0; i < files.length; i++) list.push(files[i].url);
      var extra = config.videos || [];
      for (var j = 0; j < extra.length; j++) list.push(extra[j].url);
      if (!list.length) { setTimeout(waitingVideos, 60000); return; }
      var idx = 0;
      var next = function () {
        playMedia(list[idx % list.length], {
          muted: !!(config.waiting && config.waiting.muted), quiet: true,
          onEnded: function () { idx++; next(); },
          onFail: function () { idx++; setTimeout(next, 2000); }
        });
      };
      next();
    });
  }
  var slideTimer = null;
  function nextSlide() {
    clearTimeout(slideTimer);
    var w = config.waiting || {};
    var slides = w.slides || [];
    if (!slides.length) { el.slide.style.display = 'none'; return; }
    el.slide.style.display = 'block';
    el.slide.className = 'fade';
    setTimeout(function () {
      var s = slides[waiting.slide % slides.length];
      waiting.slide++;
      el.slideTitleEn.textContent = s.titleEn || '';
      el.slideTitleAr.textContent = s.titleAr || '';
      el.slideEn.textContent = s.en || '';
      el.slideAr.textContent = s.ar || '';
      if (s.image) { el.slideImg.src = 'data/' + s.image.replace(/^\/+/, ''); el.slideImg.style.display = 'block'; }
      else el.slideImg.style.display = 'none';
      el.slide.className = '';
    }, 500);
    slideTimer = setTimeout(nextSlide, (w.slideSeconds || 12) * 1000);
  }
  function waitingKey(k) {
    // Staff can change the channel with the remote; nothing else to navigate here
    if (config.waiting && config.waiting.main === 'videos') return false;
    if (is('up', k) || is('chUp', k)) waiting.index = Math.max(0, waiting.index - 1);
    else if (is('down', k) || is('chDown', k)) waiting.index++;
    else return false;
    waitingChannel();
    return true;
  }

  // ---------- player ----------
  var hls = null, stallTimer = null, osdTimer = null, watchdog = null, playToken = 0;

  function stopHls() { if (hls) { try { hls.destroy(); } catch (e) { /* ignore */ } hls = null; } }
  function stopVideo() {
    playToken++;
    stopHls();
    clearTimeout(stallTimer);
    clearInterval(watchdog);
    el.video.onplaying = el.video.onended = el.video.onerror = null;
    el.video.removeAttribute('src');
    try { el.video.load(); } catch (e) { /* ignore */ }
    el.osd.style.display = 'none';
  }
  function safePlay() {
    var r = el.video.play();
    if (r && r.catch) r.catch(function () { /* replaced by a newer request */ });
  }
  function canNativeHls() { return el.video.canPlayType('application/vnd.apple.mpegurl') !== ''; }

  function playMedia(url, opts) {
    stopVideo();
    var token = playToken;
    var isHls = /\.m3u8(\?|$)/i.test(url) || /\/hls\//i.test(url);
    var hlsJs = window.Hls && window.Hls.isSupported();
    var useHlsJs = isHls && hlsJs && !canNativeHls();
    var triedOther = false;
    el.video.muted = !!opts.muted;
    if (!opts.quiet) showOsd(opts.name || '', 'Connecting… · جاري الاتصال', true);

    function fail() { setTimeout(failNow, 0); } // never tear hls.js down inside its own event
    function failNow() {
      if (token !== playToken) return;
      clearTimeout(stallTimer);
      if (isHls && hlsJs && canNativeHls() && !triedOther) { triedOther = true; start(!useHlsJs); return; }
      stopVideo();
      if (opts.onFail) opts.onFail();
    }
    function start(withHlsJs) {
      stopHls();
      clearTimeout(stallTimer);
      stallTimer = setTimeout(fail, STALL_MS);
      if (withHlsJs) {
        el.video.onerror = null;
        el.video.removeAttribute('src');
        hls = new window.Hls({ maxBufferLength: 30 });
        hls.on(window.Hls.Events.ERROR, function (evt, data) { if (data && data.fatal) fail(); });
        hls.on(window.Hls.Events.MANIFEST_PARSED, safePlay);
        hls.loadSource(url);
        hls.attachMedia(el.video);
      } else {
        el.video.onerror = fail;
        el.video.src = url;
        safePlay();
      }
    }
    el.video.onplaying = function () {
      clearTimeout(stallTimer);
      if (!opts.quiet) showOsd(opts.name || '', 'Live · مباشر');
    };
    el.video.onended = function () { if (opts.onEnded) opts.onEnded(); else fail(); };

    // A live stream that freezes for 30 seconds counts as failed (re-connect or next channel)
    var last = -1, still = 0;
    watchdog = setInterval(function () {
      if (token !== playToken || el.video.paused) return;
      if (el.video.currentTime === last) { still += 10; if (still >= 30) fail(); }
      else { still = 0; last = el.video.currentTime; }
    }, 10000);

    start(useHlsJs);
  }
  function showOsd(name, text, sticky) {
    el.osdName.textContent = name || '';
    el.osdInfo.textContent = text || '';
    el.osd.style.display = name || text ? 'block' : 'none';
    clearTimeout(osdTimer);
    if (!sticky) osdTimer = setTimeout(function () { el.osd.style.display = 'none'; }, 4000);
  }

  // ---------- keys and pointer ----------
  function onKey(e) {
    var k = e.keyCode, handled = false;
    if (config.mode === 'waiting') handled = waitingKey(k);
    else if (is('home', k)) { show('home'); handled = true; }
    else if (screen === 'home') handled = homeKey(k);
    else if (screen === 'tv') handled = tvKey(k);
    else if (screen === 'videos') handled = videosKey(k);
    else if (screen === 'info') handled = infoKey(k);
    // Kiosk: never let a remote key reload the page, go "back" in history or open browser features
    if (handled || is('back', k) || (k >= 112 && k <= 123)) e.preventDefault();
  }
  function clickIndex(e, container) {
    var t = e.target;
    while (t && t !== container && !(t.getAttribute && t.getAttribute('data-i'))) t = t.parentNode;
    return t && t !== container ? parseInt(t.getAttribute('data-i'), 10) : -1;
  }
  function press(code) { onKey({ keyCode: code, preventDefault: function () {} }); }
  function bindPointer() {
    el.tiles.onclick = function (e) { var i = clickIndex(e, el.tiles); if (i >= 0) { home.focus = i; press(13); } };
    el.tvList.onclick = function (e) { var i = clickIndex(e, el.tvList); if (i >= 0) { tv.focus = i; press(13); } };
    el.videoGrid.onclick = function (e) { var i = clickIndex(e, el.videoGrid); if (i >= 0) { vids.focus = i; press(13); } };
    el.infoMenu.onclick = function (e) { var i = clickIndex(e, el.infoMenu); if (i >= 0) { info.focus = i; renderInfo(); } };
    el.player.onclick = function () { if (screen === 'tv') setFull(!full); };
    el.logo.onclick = function () { if (config.mode !== 'waiting') show('home'); };
  }

  // ---------- start ----------
  function applyConfig(c) {
    config = c || {};
    renderHeader();
    renderTicker();
  }
  function refreshRemoteConfig() {
    if (!config.remoteConfigUrl) return;
    getJSON(config.remoteConfigUrl, function (err, c) {
      if (err || !c || typeof c !== 'object') return; // keep the local settings
      if (!c.remoteConfigUrl) c.remoteConfigUrl = config.remoteConfigUrl;
      var modeChanged = c.mode !== config.mode;
      save('remoteConfig', c);
      applyConfig(c);
      if (modeChanged) window.location.reload();
    });
  }
  function begin() {
    if (config.mode === 'waiting') startWaiting();
    else show('home');
    setInterval(refreshRemoteConfig, 30 * 60000);
    refreshRemoteConfig();
  }

  function init() {
    var ids = {
      root: 'root', room: 'room', time: 'time', date: 'date', logo: 'logo', tiles: 'tiles', contactStrip: 'contact-strip',
      welcomeEn: 'welcome-en', welcomeAr: 'welcome-ar', welcomeSubEn: 'welcome-sub-en', welcomeSubAr: 'welcome-sub-ar',
      tvGroups: 'tv-groups', tvStatus: 'tv-status', tvList: 'tv-list',
      videoStatus: 'video-status', videoGrid: 'video-grid',
      infoMenu: 'info-menu', infoTitleEn: 'info-title-en', infoTitleAr: 'info-title-ar', infoTextEn: 'info-text-en', infoTextAr: 'info-text-ar',
      slide: 'slide', slideImg: 'slide-img', slideTitleEn: 'slide-title-en', slideTitleAr: 'slide-title-ar', slideEn: 'slide-en', slideAr: 'slide-ar',
      player: 'player', video: 'video', osd: 'osd', osdName: 'osd-name', osdInfo: 'osd-info',
      ticker: 'ticker', tickerText: 'ticker-text'
    };
    for (var k in ids) if (ids.hasOwnProperty(k)) el[k] = $(ids[k]);

    fit();
    window.addEventListener('resize', fit, false);
    document.addEventListener('keydown', onKey, false);
    bindPointer();
    tickClock();
    setInterval(tickClock, 15000);

    getJSON(CONFIG_URL, function (err, c) {
      // Last good remote settings win; the local file is the fallback
      var remote = load('remoteConfig', null);
      if (remote && c && remote.remoteConfigUrl === c.remoteConfigUrl && c.remoteConfigUrl) c = remote;
      if (err || !c) c = remote || {};
      applyConfig(c);
      begin();
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, false);
  else init();
})();
