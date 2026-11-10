/*
 * Yomara — salle de réunion (navigateur).
 *
 * Trois moteurs vidéo interchangeables : ZEGOCLOUD, Agora et un maillage pair-à-pair de secours.
 * Caméra, micro et partage d'écran sont capturés une seule fois par la page puis confiés au moteur actif :
 * en cas de bascule (décidée par le serveur), on quitte l'ancien moteur et on rejoint le nouveau sans
 * redemander les autorisations ni perdre la conversation, les sous-titres ou l'enregistrement.
 */
(function () {
  'use strict';
  var V = window.VISIO;
  var $app = document.getElementById('app');

  // ------------------------------------------------------------------ utilitaires
  function h(tag, attrs) {
    var el = document.createElement(tag);
    if (attrs) for (var k in attrs) {
      var v = attrs[k];
      if (v == null || v === false) continue;
      if (k === 'class') el.className = v;
      else if (k === 'html') el.innerHTML = v;
      else if (k === 'text') el.textContent = v;
      else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
      else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
      else el.setAttribute(k, v === true ? '' : v);
    }
    for (var i = 2; i < arguments.length; i++) add(el, arguments[i]);
    return el;
  }
  function add(el, c) {
    if (c == null || c === false) return;
    if (Array.isArray(c)) { c.forEach(function (x) { add(el, x); }); return; }
    el.appendChild(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); return el; }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function initials(n) { n = String(n || '?').trim().split(/\s+/); return ((n[0] || '?')[0] + (n.length > 1 ? n[n.length - 1][0] : '')).toUpperCase(); }
  var palette = ['#2563EB', '#7C3AED', '#DB2777', '#EA580C', '#059669', '#0891B2', '#CA8A04', '#4F46E5', '#BE123C', '#0D9488'];
  function color(id) { return palette[Math.abs(+id || 0) % palette.length]; }
  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  function loadScript(src) {
    return new Promise(function (ok, ko) {
      if (document.querySelector('script[data-src="' + src + '"]')) return ok();
      var s = document.createElement('script'); s.src = src; s.async = true; s.dataset.src = src;
      s.onload = function () { ok(); }; s.onerror = function () { ko(new Error('Chargement impossible : ' + src)); };
      document.head.appendChild(s);
    });
  }
  var ICON = {
    mic: 'M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.92V21h2v-3.08A7 7 0 0 0 19 11h-2z',
    micoff: 'M19 11h-1.7c0 .74-.16 1.43-.43 2.05l1.23 1.23c.56-.98.9-2.09.9-3.28zm-4.02.17L9 5.18V5a3 3 0 0 1 6 0v6c0 .06-.01.11-.02.17zM4.27 3 3 4.27l6.01 6.01V11a3 3 0 0 0 3 3c.22 0 .44-.03.65-.08l1.66 1.66c-.71.33-1.5.52-2.31.52a5 5 0 0 1-5-5H5a7 7 0 0 0 6 6.92V21h2v-3.08c.91-.13 1.77-.45 2.54-.9L19.73 21 21 19.73 4.27 3z',
    cam: 'M17 10.5V7a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-3.5l4 4v-11l-4 4z',
    camoff: 'M21 6.5l-4 4V7a1 1 0 0 0-1-1H9.82L21 17.18V6.5zM3.27 2 2 3.27 4.73 6H4a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h12c.21 0 .39-.08.54-.18L19.73 21 21 19.73 3.27 2z',
    screen: 'M20 18c1.1 0 2-.9 2-2V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v10c0 1.1.9 2 2 2H0v2h24v-2h-4zm-7-3.53v-2.19c-2.78 0-4.61.85-6 2.72.56-2.67 2.11-5.33 6-5.87V7l4 3.73-4 3.74z',
    hand: 'M21 7a1.5 1.5 0 0 0-3 0v5h-1V4.5a1.5 1.5 0 0 0-3 0V11h-1V3.5a1.5 1.5 0 0 0-3 0V12H9V6.5a1.5 1.5 0 0 0-3 0v9.71l-2.04-1.72a1.6 1.6 0 0 0-2.18 2.31L7.4 22h11.1A2.5 2.5 0 0 0 21 19.5V7z',
    smile: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm-3.5 7a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3zm7 0a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3zM12 17.5c-2.33 0-4.31-1.46-5.11-3.5h10.22c-.8 2.04-2.78 3.5-5.11 3.5z',
    chat: 'M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2z',
    people: 'M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5C15 14.17 10.33 13 8 13zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z',
    cc: 'M19 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm-8 7H9.5v-.5h-2v3h2V13H11v1a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1v-4a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v1zm7 0h-1.5v-.5h-2v3h2V13H18v1a1 1 0 0 1-1 1h-3a1 1 0 0 1-1-1v-4a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v1z',
    more: 'M6 10a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm12 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm-6 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4z',
    phone: 'M12 9c-1.6 0-3.15.25-4.6.72v3.1c0 .39-.23.74-.56.9-.98.49-1.87 1.12-2.66 1.85-.18.18-.43.28-.7.28a1 1 0 0 1-.71-.29L.29 13.08A.96.96 0 0 1 0 12.37c0-.28.11-.53.29-.71C3.34 8.78 7.46 7 12 7s8.66 1.78 11.71 4.67c.18.18.29.43.29.71 0 .28-.11.53-.29.7l-2.48 2.48a1 1 0 0 1-.71.29c-.27 0-.52-.11-.7-.28a11.3 11.3 0 0 0-2.67-1.85.99.99 0 0 1-.56-.9v-3.1A15.1 15.1 0 0 0 12 9z',
    qa: 'M11 18h2v-2h-2v2zm1-16a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16zm0-14a4 4 0 0 0-4 4h2a2 2 0 1 1 4 0c0 2-3 1.75-3 5h2c0-2.25 3-2.5 3-5a4 4 0 0 0-4-4z',
    poll: 'M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z',
    rec: 'M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10zm0-5a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16z',
    speaker: 'M3 9v6h4l5 5V4L7 9H3zm13.5 3A4.5 4.5 0 0 0 14 7.97v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06a7.01 7.01 0 0 1 0 13.42v2.06A9 9 0 0 0 14 3.23z',
    gear: 'M19.14 12.94a7.14 7.14 0 0 0 0-1.88l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.49.49 0 0 0-.59-.22l-2.39.96a7 7 0 0 0-1.63-.94l-.36-2.54a.48.48 0 0 0-.48-.41h-3.84a.48.48 0 0 0-.48.41l-.36 2.54c-.59.24-1.13.56-1.63.94l-2.39-.96a.49.49 0 0 0-.59.22L2.71 8.87a.48.48 0 0 0 .12.61l2.03 1.58a7.14 7.14 0 0 0 0 1.88l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.04.7 1.63.94l.36 2.54c.05.24.25.41.48.41h3.84c.24 0 .44-.17.48-.41l.36-2.54a7 7 0 0 0 1.63-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32a.49.49 0 0 0-.12-.61l-2.03-1.58zM12 15.6a3.6 3.6 0 1 1 0-7.2 3.6 3.6 0 0 1 0 7.2z'
  };
  function svg(name, cls) { return h('span', { html: '<svg viewBox="0 0 24 24" class="' + (cls || '') + '"><path d="' + ICON[name] + '"/></svg>', style: { display: 'inline-flex' } }); }

  // ------------------------------------------------------------------ API
  var S = { token: V.token, me: null, info: null, rtc: null, epoch: -1, provider: null, cursor: 0, parts: {}, count: 0, lobby: [], questions: [], polls: [],
    remote: {}, chat: [], unread: { chat: 0, qa: 0, polls: 0 }, captions: [], transcript: [], panel: null, layout: 'auto', status: 'scheduled',
    recording: false, spotlight: null, chatEnabled: true, locked: false, quality: 3, joined: false, ended: false, pinned: null, speaking: {}, interval: 1500,
    captionsOn: false, transcribe: false, publishing: false, switching: false, failCount: 0 };
  var L = { mic: null, cam: null, screen: null, micOn: true, camOn: true, devices: { audio: null, video: null, out: null }, stream: null };

  function api(method, path, body, extra) {
    var opt = { method: method, headers: { 'Accept': 'application/json', 'X-Visio-Token': S.token || '' } };
    if (body instanceof Blob || body instanceof ArrayBuffer) { opt.body = body; opt.headers['Content-Type'] = 'application/octet-stream'; }
    else if (body) { opt.body = JSON.stringify(body); opt.headers['Content-Type'] = 'application/json'; }
    var url = V.api + path;
    if (method === 'GET' && S.token) url += (url.indexOf('?') < 0 ? '?' : '&') + 't=' + encodeURIComponent(S.token);
    if (extra && extra.keepalive) opt.keepalive = true;
    return fetch(url, opt).then(function (r) {
      if (r.status === 204) return null;
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) { var e = new Error(j.message || ('Erreur ' + r.status)); e.status = r.status; throw e; }
        return j;
      });
    });
  }

  // ------------------------------------------------------------------ toasts, menus, fenêtres
  var $toasts = h('div', { class: 'toast-wrap' }); document.body.appendChild($toasts);
  function toast(text, action, ms) {
    var t = h('div', { class: 'toast' }, h('span', { text: text }), action ? h('button', { class: 'mini p', onclick: function () { action.fn(); t.remove(); } }, action.label) : null);
    $toasts.appendChild(t); setTimeout(function () { t.remove(); }, ms || 4500);
  }
  var openMenu = null;
  function menu(anchor, items, up) {
    closeMenu();
    var m = h('div', { class: 'menu' });
    items.forEach(function (it) {
      if (!it) return;
      if (it === '-') { m.appendChild(h('hr')); return; }
      if (it.el) { m.appendChild(it.el); return; }
      m.appendChild(h('button', { class: it.danger ? 'danger' : '', onclick: function (e) { e.stopPropagation(); closeMenu(); it.fn(); } }, it.label));
    });
    document.body.appendChild(m);
    var r = anchor.getBoundingClientRect();
    var mw = m.offsetWidth, mh = m.offsetHeight;
    var left = Math.min(window.innerWidth - mw - 8, Math.max(8, r.left + r.width / 2 - mw / 2));
    var top = up === false ? r.bottom + 6 : r.top - mh - 8;
    if (top < 8) top = r.bottom + 6;
    m.style.left = left + 'px'; m.style.top = top + 'px';
    openMenu = m;
    setTimeout(function () { document.addEventListener('click', closeMenu, { once: true }); }, 0);
  }
  function closeMenu() { if (openMenu) { openMenu.remove(); openMenu = null; } }
  function modal(title, body, actions) {
    var close = function () { m.remove(); };
    var m = h('div', { class: 'modal', onclick: function (e) { if (e.target === m) close(); } },
      h('div', { class: 'card' }, h('h3', { text: title }), body,
        h('div', { class: 'acts' }, (actions || [{ label: 'Fermer' }]).map(function (a) {
          return h('button', { class: 'btn' + (a.primary ? ' p' : ''), onclick: function () { if (a.fn && a.fn() === false) return; close(); } }, a.label);
        }))));
    document.body.appendChild(m);
    return close;
  }

  // ------------------------------------------------------------------ médias locaux
  function constraintsAudio() { return { deviceId: L.devices.audio ? { exact: L.devices.audio } : undefined, echoCancellation: true, noiseSuppression: true, autoGainControl: true }; }
  function constraintsVideo() { return { deviceId: L.devices.video ? { exact: L.devices.video } : undefined, width: { ideal: 1280 }, height: { ideal: 720 }, frameRate: { ideal: 24, max: 30 } }; }
  async function getMic() {
    if (L.mic && L.mic.readyState === 'live') return L.mic;
    var s = await navigator.mediaDevices.getUserMedia({ audio: constraintsAudio() });
    L.mic = s.getAudioTracks()[0]; watchLevel('me', L.mic);
    return L.mic;
  }
  async function getCam() {
    if (L.cam && L.cam.readyState === 'live') return L.cam;
    var s = await navigator.mediaDevices.getUserMedia({ video: constraintsVideo() });
    L.camRaw = s.getVideoTracks()[0];
    L.cam = L.camRaw;
    if (BG.mode !== 'none') { try { L.cam = await BG.wrap(L.camRaw); } catch (e) { console.warn('arrière-plan', e); toast('Arrière-plan indisponible sur cet appareil.'); BG.mode = 'none'; } }
    return L.cam;
  }
  function stopCam() { BG.stop(); if (L.camRaw && L.camRaw !== L.cam) L.camRaw.stop(); if (L.cam) { L.cam.stop(); L.cam = null; } L.camRaw = null; }

  // ------------------------------------------------------------------ arrière-plans virtuels (comme Zoom) : flou ou image
  // Découpage de la personne par MediaPipe Selfie Segmentation (servi par le serveur, aucun service externe).
  var BG = (function () {
    var KEY = 'visio-bg';
    var saved = {}; try { saved = JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) {}
    var st = { mode: saved.mode || 'none', image: saved.image || null, preset: saved.preset || null };
    var seg = null, segReady = null, video = null, canvas = null, ctx = null, running = false, bgImg = null, out = null, timer = null, mask = null;
    var base = (window.VISIO.base || '').replace(/\/$/, '') + '/vendor/visio/mediapipe/';
    // Fonds proposés (dessinés, pas d'images à télécharger)
    var PRESETS = [
      { id: 'nuit', label: 'Bleu nuit', g: ['#0B1B3A', '#1F4FB8'] },
      { id: 'aurore', label: 'Aurore', g: ['#5B3FD8', '#E5532D'] },
      { id: 'lagune', label: 'Lagune', g: ['#00695C', '#26C6DA'] },
      { id: 'savane', label: 'Savane', g: ['#E59A3C', '#F6C453'] },
      { id: 'studio', label: 'Studio clair', g: ['#E8EDF5', '#FFFFFF'] },
      { id: 'bureau', label: 'Bureau', office: true },
      { id: 'yomara', label: 'Yomara', brand: true }
    ];
    function save() { try { localStorage.setItem(KEY, JSON.stringify({ mode: st.mode, image: st.mode === 'image' && !st.preset ? st.image : null, preset: st.preset })); } catch (e) {} }
    function drawPreset(p, w, hgt) {
      var c = document.createElement('canvas'); c.width = w; c.height = hgt; var x = c.getContext('2d');
      if (p.office) {
        var g = x.createLinearGradient(0, 0, 0, hgt); g.addColorStop(0, '#d9dfe7'); g.addColorStop(1, '#b9c2cf'); x.fillStyle = g; x.fillRect(0, 0, w, hgt);
        x.fillStyle = 'rgba(255,255,255,.55)'; for (var i = 0; i < 3; i++) x.fillRect(w * (0.08 + i * 0.3), hgt * 0.12, w * 0.22, hgt * 0.42);
        x.fillStyle = '#8a6f52'; x.fillRect(0, hgt * 0.78, w, hgt * 0.22);
        x.fillStyle = '#2e7d32'; x.beginPath(); x.arc(w * 0.9, hgt * 0.62, hgt * 0.12, 0, Math.PI * 2); x.fill();
      } else if (p.brand) {
        var g2 = x.createLinearGradient(0, 0, w, hgt); g2.addColorStop(0, '#0B1B3A'); g2.addColorStop(1, '#12306B'); x.fillStyle = g2; x.fillRect(0, 0, w, hgt);
        x.fillStyle = '#FFC531'; x.font = '900 ' + Math.round(hgt * 0.09) + 'px system-ui, sans-serif'; x.fillText((S.info && S.info.company) || 'Yomara', w * 0.06, hgt * 0.14);
      } else {
        var g3 = x.createLinearGradient(0, 0, w, hgt); g3.addColorStop(0, p.g[0]); g3.addColorStop(1, p.g[1]); x.fillStyle = g3; x.fillRect(0, 0, w, hgt);
        x.fillStyle = 'rgba(255,255,255,.07)'; for (var k = 0; k < 6; k++) { x.beginPath(); x.arc(w * ((k * 37) % 100) / 100, hgt * ((k * 53) % 100) / 100, hgt * (0.1 + (k % 3) * 0.08), 0, Math.PI * 2); x.fill(); }
      }
      return c;
    }
    function loadSeg() {
      if (segReady) return segReady;
      segReady = new Promise(function (ok, ko) {
        var sc = document.createElement('script'); sc.src = base + 'selfie_segmentation.js';
        sc.onload = function () {
          try {
            seg = new window.SelfieSegmentation({ locateFile: function (f) { return base + f; } });
            seg.setOptions({ modelSelection: 1, selfieMode: false });
            seg.onResults(function (r) { mask = r.segmentationMask; paint(); });
            seg.initialize().then(ok, ko);
          } catch (e) { ko(e); }
        };
        sc.onerror = function () { ko(new Error('module de découpage introuvable')); };
        document.head.appendChild(sc);
      });
      return segReady;
    }
    function background() {
      if (st.mode !== 'image') return null;
      if (st.preset) { var p = PRESETS.find(function (x) { return x.id === st.preset; }); return p ? drawPreset(p, canvas.width, canvas.height) : null; }
      return bgImg;
    }
    var bgCanvas = null;
    function paint() {
      if (!ctx || !video) return;
      var w = canvas.width, hh = canvas.height;
      ctx.save();
      ctx.clearRect(0, 0, w, hh);
      if (mask) {
        ctx.drawImage(mask, 0, 0, w, hh);
        ctx.globalCompositeOperation = 'source-in';
        ctx.drawImage(video, 0, 0, w, hh);
        ctx.globalCompositeOperation = 'destination-over';
        if (st.mode === 'blur' || !(bgCanvas || bgImg)) { ctx.filter = 'blur(' + (st.mode === 'blur' ? 14 : 0) + 'px)'; ctx.drawImage(video, 0, 0, w, hh); ctx.filter = 'none'; }
        else { var b = bgCanvas || bgImg; var r = Math.max(w / b.width, hh / b.height); ctx.drawImage(b, (w - b.width * r) / 2, (hh - b.height * r) / 2, b.width * r, b.height * r); }
      } else ctx.drawImage(video, 0, 0, w, hh);
      ctx.restore();
    }
    async function loop() {
      if (!running) return;
      try { if (video.readyState >= 2) await seg.send({ image: video }); } catch (e) {}
      timer = setTimeout(loop, 1000 / 24);
    }
    async function wrap(raw) {
      stop();
      await loadSeg();
      var set = raw.getSettings();
      video = document.createElement('video'); video.muted = true; video.playsInline = true; video.srcObject = new MediaStream([raw]);
      await video.play().catch(function () {});
      canvas = document.createElement('canvas'); canvas.width = set.width || 1280; canvas.height = set.height || 720;
      ctx = canvas.getContext('2d');
      bgCanvas = st.mode === 'image' ? background() : null;
      running = true; loop();
      out = canvas.captureStream(24).getVideoTracks()[0];
      return out;
    }
    function stop() { running = false; if (timer) clearTimeout(timer); timer = null; if (out) { out.stop(); out = null; } if (video) { video.srcObject = null; video = null; } mask = null; }
    async function choose(mode, opts) {
      st.mode = mode; st.preset = opts && opts.preset || null;
      if (opts && opts.image) { st.image = opts.image; bgImg = await new Promise(function (ok) { var im = new Image(); im.onload = function () { ok(im); }; im.onerror = function () { ok(null); }; im.src = opts.image; }); }
      save();
      // Caméra active : on rebranche le flux traité (aperçu et réunion)
      if (L.cam) { stopCam(); if (L.camOn) await getCam().catch(function () { L.camOn = false; }); }
      if (preVideo && !S.joined) preVideo.srcObject = L.cam ? new MediaStream([L.cam]) : null;
      if (S.joined) { await publishLocal(); scheduleRender(); }
    }
    if (st.mode === 'image' && st.image && !st.preset) { var im0 = new Image(); im0.onload = function () { bgImg = im0; }; im0.src = st.image; }
    return { get mode() { return st.mode; }, set mode(v) { st.mode = v; save(); }, wrap: wrap, stop: stop, choose: choose, presets: PRESETS, state: st, thumb: function (p) { return drawPreset(p, 160, 90).toDataURL(); } };
  })();

  function backgroundDialog() {
    var grid = h('div', { class: 'bg-grid' });
    function tile(label, active, fn, styleBg, icon) {
      var t = h('button', { class: 'bg-tile' + (active ? ' on' : ''), title: label, onclick: async function () { grid.querySelectorAll('.bg-tile').forEach(function (x) { x.classList.remove('on'); }); t.classList.add('on'); t.classList.add('busy'); await fn(); t.classList.remove('busy'); } },
        h('span', { class: 'bg-prev', style: styleBg ? { backgroundImage: 'url(' + styleBg + ')' } : null, text: icon || '' }), h('small', { text: label }));
      grid.appendChild(t);
    }
    var st = BG.state;
    tile('Aucun', st.mode === 'none', function () { return BG.choose('none'); }, null, '⦸');
    tile('Flou', st.mode === 'blur', function () { return BG.choose('blur'); }, null, '◍');
    BG.presets.forEach(function (p) { tile(p.label, st.mode === 'image' && st.preset === p.id, function () { return BG.choose('image', { preset: p.id }); }, BG.thumb(p)); });
    if (st.mode === 'image' && st.image && !st.preset) tile('Mon image', true, function () { return BG.choose('image', { image: st.image }); }, st.image);
    var file = h('input', { type: 'file', accept: 'image/*', style: { display: 'none' }, onchange: function () {
      var f = file.files && file.files[0]; if (!f) return;
      var rd = new FileReader();
      rd.onload = function () {
        // Image réduite (1280 px) pour rester légère
        var im = new Image(); im.onload = function () { var c = document.createElement('canvas'); var r = Math.min(1, 1280 / im.width); c.width = im.width * r; c.height = im.height * r; c.getContext('2d').drawImage(im, 0, 0, c.width, c.height); BG.choose('image', { image: c.toDataURL('image/jpeg', 0.85) }); closeDlg(); toast('Arrière-plan appliqué.'); }; im.src = rd.result;
      };
      rd.readAsDataURL(f);
    } });
    var closeDlg = modal('Arrière-plan', h('div', null,
      h('p', { class: 'muted', text: 'Flou ou image derrière vous, comme sur Zoom. Le découpage se fait sur votre appareil.' }), grid,
      h('button', { class: 'btn', style: { marginTop: '12px' }, onclick: function () { file.click(); } }, 'Ajouter mon image…'), file), [{ label: 'Fermer' }]);
  }

  // Niveau sonore (indicateur « parle » et vue orateur)
  var AC = null;
  function audioCtx() { if (!AC) { try { AC = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) { AC = null; } } if (AC && AC.state === 'suspended') AC.resume(); return AC; }
  var meters = {}, mutedTalk = 0, mutedHint = 0;
  function watchLevel(key, track) {
    var ctx = audioCtx(); if (!ctx || !track) return;
    try {
      if (meters[key]) meters[key].src.disconnect();
      var src = ctx.createMediaStreamSource(new MediaStream([track]));
      var an = ctx.createAnalyser(); an.fftSize = 512; src.connect(an);
      meters[key] = { src: src, an: an, buf: new Uint8Array(an.frequencyBinCount), level: 0 };
    } catch (e) { /* navigateur sans Web Audio */ }
  }
  function levels() {
    var loudest = null, max = 0;
    for (var k in meters) {
      var m = meters[k]; m.an.getByteFrequencyData(m.buf);
      var sum = 0; for (var i = 0; i < m.buf.length; i++) sum += m.buf[i];
      m.level = sum / m.buf.length;
      var talking = m.level > 18 && (k !== 'me' || L.micOn);
      var tile = document.querySelector('[data-tile="' + k + '"]');
      if (talking !== !!S.speaking[k]) { S.speaking[k] = talking; if (tile) tile.classList.toggle('speaking', talking); }
      if (tile) tile.style.setProperty('--lvl', talking ? Math.min(1, m.level / 60).toFixed(2) : '0');
      // Micro coupé mais la personne parle : on le lui signale (comme Teams ou Meet)
      if (k === 'me' && S.joined && !L.micOn && S.me && S.me.publish && m.level > 24) {
        mutedTalk++;
        if (mutedTalk >= 6 && Date.now() - mutedHint > 25000) { mutedHint = Date.now(); toast('Vous parlez ? Votre micro est coupé.', { label: 'Activer', fn: function () { setMic(true); } }, 5000); }
      } else if (k === 'me') mutedTalk = 0;
      if (talking && m.level > max && k !== 'me') { max = m.level; loudest = k; }
    }
    if (loudest && loudest !== S.activeSpeaker) { S.activeSpeaker = loudest; if (S.layout !== 'grid') scheduleRender(); }
    var pm = document.getElementById('pre-meter'); if (pm && meters.me) pm.style.height = Math.min(100, meters.me.level * 2.2) + '%';
  }
  setInterval(levels, 200);

  // ------------------------------------------------------------------ moteurs vidéo
  function remoteSet(pid, kind, track) {
    pid = String(pid);
    var r = S.remote[pid] || (S.remote[pid] = {});
    if (kind === 'audio') {
      if (r.audioEl) { r.audioEl.srcObject = null; r.audioEl.remove(); r.audioEl = null; }
      r.audio = track || null;
      if (track) {
        if (r.selfPlayed !== true) { r.audioEl = h('audio', { autoplay: true }); r.audioEl.srcObject = new MediaStream([track]); document.body.appendChild(r.audioEl); if (L.devices.out && r.audioEl.setSinkId) r.audioEl.setSinkId(L.devices.out).catch(function () {}); r.audioEl.play().catch(function () {}); }
        watchLevel(pid, track); Rec.addAudio(pid, track);
      } else { delete meters[pid]; Rec.removeAudio(pid); }
    } else {
      r[kind] = track ? new MediaStream([track]) : null;
    }
    scheduleRender();
  }
  function remoteClear() {
    for (var pid in S.remote) { var r = S.remote[pid]; if (r.audioEl) { r.audioEl.srcObject = null; r.audioEl.remove(); } delete meters[pid]; Rec.removeAudio(pid); }
    S.remote = {}; scheduleRender();
  }

  // Agora
  function AgoraEngine(cfg) {
    var self = this, client = null, sclient = null, aTrack = null, vTrack = null, sTrack = null, bad = 0, recoTimer = null;
    var SCREEN = 100000000;
    function pidOf(uid) { uid = +uid; return uid >= SCREEN ? { pid: uid - SCREEN, screen: true } : { pid: uid, screen: false }; }
    this.name = 'agora';
    this.join = async function () {
      await loadScript(V.sdk.agora);
      var A = window.AgoraRTC;
      try { A.setLogLevel(3); A.disableLogUpload && A.disableLogUpload(); } catch (e) {}
      client = A.createClient({ mode: cfg.mode || 'rtc', codec: 'vp8' });
      if (cfg.mode === 'live') await client.setClientRole(cfg.publish ? 'host' : 'audience');
      client.on('user-published', async function (user, type) {
        var who = pidOf(user.uid); if (who.pid === S.me.id) return;
        try { await client.subscribe(user, type); } catch (e) { return; }
        if (type === 'video') remoteSet(who.pid, who.screen ? 'screen' : 'cam', user.videoTrack.getMediaStreamTrack());
        if (type === 'audio' && !who.screen) remoteSet(who.pid, 'audio', user.audioTrack.getMediaStreamTrack());
      });
      client.on('user-unpublished', function (user, type) {
        var who = pidOf(user.uid);
        if (type === 'video') remoteSet(who.pid, who.screen ? 'screen' : 'cam', null);
        if (type === 'audio' && !who.screen) remoteSet(who.pid, 'audio', null);
      });
      client.on('user-left', function (user) { var who = pidOf(user.uid); remoteSet(who.pid, who.screen ? 'screen' : 'cam', null); if (!who.screen) remoteSet(who.pid, 'audio', null); });
      client.on('connection-state-change', function (cur, prev, reason) {
        if (cur === 'RECONNECTING') { if (!recoTimer) recoTimer = setTimeout(function () { self.onTrouble && self.onTrouble('disconnected', 'reconnexion Agora > 12 s'); }, 12000); }
        else if (cur === 'CONNECTED') { clearTimeout(recoTimer); recoTimer = null; }
        else if (cur === 'DISCONNECTED' && reason && reason !== 'LEAVE') { self.onTrouble && self.onTrouble('disconnected', String(reason)); }
      });
      client.on('network-quality', function (q) {
        var worst = Math.max(q.uplinkNetworkQuality || 0, q.downlinkNetworkQuality || 0);
        S.quality = worst === 0 ? 3 : worst <= 2 ? 3 : worst <= 4 ? 2 : 1; updateNet();
        if (worst >= 5) { if (++bad >= 8) { bad = 0; self.onTrouble && self.onTrouble('unstable', 'qualité réseau Agora ' + worst); } } else bad = 0;
      });
      client.on('token-privilege-will-expire', async function () { try { var c = await api('GET', '/rtc'); if (c.provider === 'agora') { await client.renewToken(c.token); if (sclient) await sclient.renewToken(c.screen_token); } } catch (e) {} });
      await client.join(cfg.app_id, cfg.channel, cfg.token, cfg.uid);
    };
    this.publish = async function (audio, video, screen) {
      var A = window.AgoraRTC; if (!client || !cfg.publish) return;
      // Audio / caméra : on ne republie que ce qui change
      if ((aTrack && aTrack.getMediaStreamTrack()) !== audio) {
        if (aTrack) { await client.unpublish(aTrack).catch(function () {}); aTrack.close(); aTrack = null; }
        if (audio) { aTrack = A.createCustomAudioTrack({ mediaStreamTrack: audio }); await client.publish(aTrack); }
      }
      if ((vTrack && vTrack.getMediaStreamTrack()) !== video) {
        if (vTrack) { await client.unpublish(vTrack).catch(function () {}); vTrack.close(); vTrack = null; }
        if (video) { vTrack = A.createCustomVideoTrack({ mediaStreamTrack: video, bitrateMax: 1500 }); await client.publish(vTrack); }
      }
      if ((sTrack && sTrack.getMediaStreamTrack()) !== screen) {
        if (sTrack) { await sclient.unpublish(sTrack).catch(function () {}); sTrack.close(); sTrack = null; }
        if (screen) {
          if (!sclient) { sclient = A.createClient({ mode: cfg.mode || 'rtc', codec: 'vp8' }); if (cfg.mode === 'live') await sclient.setClientRole('host'); await sclient.join(cfg.app_id, cfg.channel, cfg.screen_token, cfg.screen_uid); }
          sTrack = A.createCustomVideoTrack({ mediaStreamTrack: screen, optimizationMode: 'detail', bitrateMax: 2500 }); await sclient.publish(sTrack);
        } else if (sclient) { await sclient.leave().catch(function () {}); sclient = null; }
      }
    };
    this.setRole = async function (publish) { cfg.publish = publish; if (cfg.mode === 'live' && client) { if (!publish) await this.publish(null, null, null); await client.setClientRole(publish ? 'host' : 'audience'); } };
    this.leave = async function () {
      clearTimeout(recoTimer);
      [aTrack, vTrack, sTrack].forEach(function (t) { if (t) try { t.close(); } catch (e) {} });
      if (sclient) await sclient.leave().catch(function () {});
      if (client) { client.removeAllListeners(); await client.leave().catch(function () {}); }
      client = sclient = aTrack = vTrack = sTrack = null;
    };
  }

  // ZEGOCLOUD
  function ZegoEngine(cfg) {
    var self = this, zg = null, pub = {}, playing = {}, recoTimer = null, pubFail = 0;
    this.name = 'zego';
    function parse(id) { var m = /e\d+_(\d+)_(cam|screen)$/.exec(id); return m ? { pid: +m[1], kind: m[2] } : null; }
    this.join = async function () {
      await loadScript(V.sdk.zego);
      var Z = window.ZegoExpressEngine;
      zg = new Z(cfg.app_id, cfg.server);
      try { zg.setLogConfig({ logLevel: 'error', remoteLogLevel: 'disable', logURL: '' }); } catch (e) {}
      try { zg.setDebugVerbose(false); } catch (e) {}
      zg.on('roomStreamUpdate', async function (roomID, type, list) {
        for (var i = 0; i < list.length; i++) {
          var sid = list[i].streamID, who = parse(sid); if (!who || who.pid === S.me.id) continue;
          if (sid.indexOf(cfg.stream_prefix + '_') !== 0) continue;
          if (type === 'ADD') {
            try {
              var ms = await zg.startPlayingStream(sid); playing[sid] = who;
              var vt = ms.getVideoTracks()[0], at = ms.getAudioTracks()[0];
              if (vt) remoteSet(who.pid, who.kind, vt);
              if (at && who.kind === 'cam') remoteSet(who.pid, 'audio', at);
            } catch (e) { self.onTrouble && self.onTrouble('media', 'lecture ' + (e && e.msg || e)); }
          } else {
            try { zg.stopPlayingStream(sid); } catch (e) {}
            delete playing[sid]; remoteSet(who.pid, who.kind, null); if (who.kind === 'cam') remoteSet(who.pid, 'audio', null);
          }
        }
      });
      zg.on('roomStateChanged', function (roomID, reason, code) {
        if (reason === 'RECONNECTING') { if (!recoTimer) recoTimer = setTimeout(function () { self.onTrouble && self.onTrouble('disconnected', 'reconnexion ZEGO > 12 s'); }, 12000); }
        else if (reason === 'RECONNECTED' || reason === 'LOGINED') { clearTimeout(recoTimer); recoTimer = null; }
        else if (reason === 'RECONNECT_FAILED' || reason === 'KICKOUT') { self.onTrouble && self.onTrouble('disconnected', reason + ' ' + code); }
      });
      zg.on('publisherStateUpdate', function (r) { if (r.state === 'NO_PUBLISH' && r.errorCode) { if (++pubFail >= 3) { pubFail = 0; self.onTrouble && self.onTrouble('unstable', 'publication ZEGO ' + r.errorCode); } } else if (r.state === 'PUBLISHING') pubFail = 0; });
      zg.on('publishQualityUpdate', function (sid, st) { var q = st && st.video ? st.video.videoQuality : null; if (q != null) { S.quality = q <= 1 ? 3 : q <= 3 ? 2 : 1; updateNet(); } });
      zg.on('tokenWillExpire', async function () { try { var c = await api('GET', '/rtc'); if (c.provider === 'zego') zg.renewToken(c.token); } catch (e) {} });
      var ok = await zg.loginRoom(cfg.room, cfg.token, { userID: cfg.user_id, userName: cfg.user_name }, { userUpdate: true });
      if (ok === false) throw new Error('Connexion ZEGO refusée');
    };
    async function setStream(kind, tracks) {
      var sid = cfg.stream_prefix + '_' + S.me.id + '_' + kind;
      var cur = pub[kind];
      var same = cur && tracks.length === cur.tracks.length && tracks.every(function (t, i) { return cur.tracks[i] === t; });
      if (same) return;
      if (cur) { try { zg.stopPublishingStream(sid); zg.destroyStream(cur.stream); } catch (e) {} delete pub[kind]; }
      if (!tracks.length) return;
      var stream = await zg.createStream({ custom: { source: new MediaStream(tracks), bitrate: kind === 'screen' ? 2000 : 1200 } });
      zg.startPublishingStream(sid, stream, { videoCodec: 'VP8' });
      pub[kind] = { stream: stream, tracks: tracks };
    }
    this.publish = async function (audio, video, screen) {
      if (!zg || !cfg.publish) return;
      await setStream('cam', [audio, video].filter(Boolean));
      await setStream('screen', screen ? [screen] : []);
    };
    this.setRole = async function (publish) { cfg.publish = publish; if (!publish && zg) { await setStream('cam', []); await setStream('screen', []); } };
    this.leave = async function () {
      clearTimeout(recoTimer);
      if (!zg) return;
      for (var k in pub) { try { zg.stopPublishingStream(cfg.stream_prefix + '_' + S.me.id + '_' + k); zg.destroyStream(pub[k].stream); } catch (e) {} }
      for (var sid in playing) { try { zg.stopPlayingStream(sid); } catch (e) {} }
      try { zg.logoutRoom(cfg.room); } catch (e) {}
      ['roomStreamUpdate', 'roomStateChanged', 'publisherStateUpdate', 'publishQualityUpdate', 'tokenWillExpire'].forEach(function (ev) { try { zg.off(ev); } catch (e) {} });
      try { zg.destroyEngine(); } catch (e) {}
      pub = {}; playing = {}; zg = null;
    };
  }

  // Pair-à-pair (secours) : maillage WebRTC, signalisation par le serveur, 3 voies fixes (audio, caméra, écran)
  function P2PEngine(cfg) {
    var self = this, pcs = {}, tracks = { audio: null, video: null, screen: null }, epoch = cfg.epoch;
    this.name = 'p2p';
    function send(to, data) { data.epoch = epoch; return api('POST', '/signal', { to: to, data: data }).catch(function () {}); }
    function gather(pc) {
      return new Promise(function (ok) {
        if (pc.iceGatheringState === 'complete') return ok();
        var t = setTimeout(ok, 2500);
        pc.addEventListener('icegatheringstatechange', function () { if (pc.iceGatheringState === 'complete') { clearTimeout(t); ok(); } });
      });
    }
    function make(pid, initiator) {
      var pc = new RTCPeerConnection({ iceServers: cfg.ice || [] });
      var entry = { pc: pc, pid: pid, restarted: 0, initiator: initiator, offeredAt: 0 };
      pcs[pid] = entry;
      if (initiator) { pc.addTransceiver('audio', { direction: 'sendrecv' }); pc.addTransceiver('video', { direction: 'sendrecv' }); pc.addTransceiver('video', { direction: 'sendrecv' }); }
      pc.ontrack = function (ev) {
        var idx = pc.getTransceivers().indexOf(ev.transceiver);
        var kind = idx === 0 ? 'audio' : idx === 1 ? 'cam' : 'screen';
        remoteSet(pid, kind, ev.track);
        ev.track.onunmute = function () { remoteSet(pid, kind, ev.track); };
      };
      pc.onconnectionstatechange = function () {
        if (pc.connectionState === 'failed') {
          if (initiator && entry.restarted++ < 2) offer(pid, true);
          else if (entry.restarted > 2) self.onTrouble && self.onTrouble('unstable', 'pair-à-pair impossible');
        }
      };
      return entry;
    }
    function attach(entry) {
      var tr = entry.pc.getTransceivers();
      [tracks.audio, tracks.video, tracks.screen].forEach(function (t, i) { if (tr[i]) { tr[i].direction = 'sendrecv'; tr[i].sender.replaceTrack(cfg.publish ? t : null).catch(function () {}); } });
    }
    async function offer(pid, restart) {
      var e = pcs[pid] || make(pid, true);
      attach(e);
      var o = await e.pc.createOffer({ iceRestart: !!restart });
      await e.pc.setLocalDescription(o); await gather(e.pc);
      e.offeredAt = Date.now();
      send(pid, { type: 'offer', sdp: e.pc.localDescription.sdp });
    }
    this.join = async function () { this.sync(); };
    this.sync = function () {
      if (!S.me) return;
      var online = {};
      Object.keys(S.parts).forEach(function (k) { if (+k !== S.me.id) online[k] = 1; });
      Object.keys(online).forEach(function (pid) {
        var e = pcs[pid];
        if (!e && S.me.id < +pid) offer(+pid);
        // Offre restée sans réponse (l'autre navigateur changeait de moteur) : on la renvoie
        else if (e && e.initiator && e.pc.signalingState === 'have-local-offer' && e.offeredAt && Date.now() - e.offeredAt > 6000) { e.pc.close(); delete pcs[pid]; offer(+pid); }
      });
      Object.keys(pcs).forEach(function (pid) { if (!online[pid]) { pcs[pid].pc.close(); delete pcs[pid]; remoteSet(pid, 'cam', null); remoteSet(pid, 'screen', null); remoteSet(pid, 'audio', null); } });
    };
    this.signal = async function (from, data) {
      if (data.epoch !== epoch) return;
      // Le serveur supprime les blancs de fin de chaîne : une description SDP doit finir par CRLF
      if (data.sdp && !/\r\n$/.test(data.sdp)) data.sdp = data.sdp.replace(/\s+$/, '') + '\r\n';
      if (data.type === 'offer') {
        var e = pcs[from]; if (e) { e.pc.close(); }
        e = make(from, false);
        await e.pc.setRemoteDescription({ type: 'offer', sdp: data.sdp });
        attach(e);
        var a = await e.pc.createAnswer(); await e.pc.setLocalDescription(a); await gather(e.pc);
        send(from, { type: 'answer', sdp: e.pc.localDescription.sdp });
      } else if (data.type === 'answer' && pcs[from]) {
        await pcs[from].pc.setRemoteDescription({ type: 'answer', sdp: data.sdp }).catch(function () {});
      }
    };
    this.publish = async function (audio, video, screen) { tracks = { audio: audio, video: video, screen: screen }; for (var pid in pcs) attach(pcs[pid]); };
    this.setRole = async function (publish) { cfg.publish = publish; for (var pid in pcs) attach(pcs[pid]); };
    this.leave = async function () { for (var pid in pcs) pcs[pid].pc.close(); pcs = {}; };
  }

  var engine = null, pendingSignals = [];
  async function connect(cfg, attempt) {
    attempt = attempt || 0;
    S.switching = true; scheduleRender();
    if (engine) { try { await engine.leave(); } catch (e) {} engine = null; }
    remoteClear();
    S.rtc = cfg; S.epoch = cfg.epoch; S.provider = cfg.provider;
    var E = cfg.provider === 'agora' ? AgoraEngine : cfg.provider === 'zego' ? ZegoEngine : P2PEngine;
    var eng = new E(cfg);
    eng.onTrouble = function (kind, detail) { trouble(eng, kind, detail); };
    try {
      await withTimeout(eng.join(), 15000);
      engine = eng;
      S.switching = false; S.failCount = 0;
      if (eng.signal) { var waiting = pendingSignals.filter(function (f) { return f.data.epoch === cfg.epoch; }); pendingSignals = []; waiting.forEach(function (f) { eng.signal(f.from, f.data); }); }
      api('POST', '/healthy', { provider: cfg.provider }).catch(function () {});
      await publishLocal();
    } catch (e) {
      try { await eng.leave(); } catch (x) {}
      if (attempt >= 3) { S.switching = false; toast('Connexion vidéo impossible pour le moment. Nouvel essai…'); setTimeout(function () { api('GET', '/rtc').then(function (c) { connect(c, 0); }).catch(function () {}); }, 8000); return; }
      // Le serveur bascule vers le fournisseur suivant et renvoie les nouveaux paramètres
      var r = await api('POST', '/failure', { provider: cfg.provider, epoch: cfg.epoch, kind: 'join', detail: String(e && (e.message || e.msg || e.code) || e).slice(0, 280) }).catch(function () { return null; });
      if (r && r.rtc) return connect(r.rtc, attempt + 1);
      await sleep(2000);
      return connect(cfg, attempt + 1);
    }
    scheduleRender();
  }
  function withTimeout(p, ms) { return Promise.race([p, new Promise(function (_, ko) { setTimeout(function () { ko(new Error('délai dépassé')); }, ms); })]); }
  var lastTrouble = 0;
  function trouble(eng, kind, detail) {
    if (eng !== engine || Date.now() - lastTrouble < 15000) return;
    lastTrouble = Date.now();
    api('POST', '/failure', { provider: S.provider, epoch: S.epoch, kind: kind, detail: detail }).then(function (r) {
      if (r && r.epoch !== S.epoch && r.rtc) connect(r.rtc);
    }).catch(function () {});
  }
  async function publishLocal() {
    if (!engine || !S.me) return;
    var can = S.me.publish;
    var a = can && L.micOn && L.mic && L.mic.readyState === 'live' ? L.mic : null;
    var v = can && L.camOn && L.cam && L.cam.readyState === 'live' ? L.cam : null;
    var s = can && L.screen && L.screen.readyState === 'live' ? L.screen : null;
    try { await engine.publish(a, v, s); } catch (e) { console.warn('publication', e); }
  }

  // ------------------------------------------------------------------ transcription (reconnaissance vocale du navigateur)
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  var recog = null, recogWanted = false, interim = '';
  function startRecognition() {
    if (!SR || recog || !S.transcribe || !S.me || !S.me.publish) return;
    recogWanted = true;
    recog = new SR(); recog.lang = 'fr-FR'; recog.continuous = true; recog.interimResults = true;
    recog.onresult = function (ev) {
      interim = '';
      for (var i = ev.resultIndex; i < ev.results.length; i++) {
        var txt = ev.results[i][0].transcript.trim();
        if (ev.results[i].isFinal) { if (txt && L.micOn) api('POST', '/caption', { text: txt, lang: 'fr-FR' }).catch(function () {}); }
        else interim += txt + ' ';
      }
      renderCaptions();
    };
    recog.onerror = function (e) { if (e.error === 'not-allowed' || e.error === 'service-not-allowed') recogWanted = false; };
    recog.onend = function () { recog = null; interim = ''; if (recogWanted && L.micOn && S.joined) setTimeout(startRecognition, 400); };
    try { recog.start(); } catch (e) { recog = null; }
  }
  function stopRecognition() { recogWanted = false; if (recog) { try { recog.stop(); } catch (e) {} recog = null; } interim = ''; }

  // ------------------------------------------------------------------ enregistrement (organisateur) : composition sur canvas + mixage audio
  var Rec = {
    on: false, id: null, mr: null, seq: 0, queue: Promise.resolve(), canvas: null, timer: null, dest: null, sources: {}, started: 0,
    addAudio: function (key, track) {
      if (!this.dest || !track) return;
      try {
        this.removeAudio(key);
        var s = AC.createMediaStreamSource(new MediaStream([track]));
        if (key === 'me') { this.meGain = AC.createGain(); this.meGain.gain.value = L.micOn ? 1 : 0; s.connect(this.meGain); this.meGain.connect(this.dest); }
        else s.connect(this.dest);
        this.sources[key] = s;
      } catch (e) {}
    },
    setMeGain: function (on) { if (this.meGain) this.meGain.gain.value = on ? 1 : 0; },
    removeAudio: function (key) { if (this.sources[key]) { try { this.sources[key].disconnect(); } catch (e) {} delete this.sources[key]; } },
    start: async function () {
      if (this.on) return;
      if (!window.MediaRecorder) { toast('Votre navigateur ne permet pas l\'enregistrement.'); return; }
      var mime = ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm', 'video/mp4'].filter(function (m) { return MediaRecorder.isTypeSupported(m); })[0];
      if (!mime) { toast('Format d\'enregistrement non pris en charge.'); return; }
      var r = await api('POST', '/recordings', { mime: mime.indexOf('mp4') >= 0 ? 'video/mp4' : 'video/webm' });
      this.id = r.id; this.seq = 0; this.on = true; this.started = Date.now();
      var ctx = audioCtx(); this.dest = ctx.createMediaStreamDestination(); this.sources = {};
      if (L.mic) this.addAudio('me', L.mic);
      for (var pid in S.remote) if (S.remote[pid].audio) this.addAudio(pid, S.remote[pid].audio);
      var cv = this.canvas = document.createElement('canvas'); cv.width = 1280; cv.height = 720;
      var g = cv.getContext('2d'), self = this;
      this.timer = setInterval(function () { self.draw(g); }, 1000 / 15);
      var stream = new MediaStream([cv.captureStream(15).getVideoTracks()[0], this.dest.stream.getAudioTracks()[0]]);
      this.mr = new MediaRecorder(stream, { mimeType: mime, videoBitsPerSecond: 1500000 });
      this.mr.ondataavailable = function (e) { if (e.data && e.data.size) { var n = self.seq++, id = self.id; self.queue = self.queue.then(function () { return self.upload(id, n, e.data); }); } };
      this.mr.start(5000);
      S.recording = true; scheduleRender();
    },
    upload: async function (id, n, blob) {
      for (var i = 0; i < 4; i++) {
        try { await fetch(V.api + '/recordings/' + id + '/chunk?seq=' + n, { method: 'POST', headers: { 'X-Visio-Token': S.token, 'Content-Type': 'application/octet-stream', 'Accept': 'application/json' }, body: blob }).then(function (r) { if (!r.ok && r.status !== 409) throw new Error(r.status); }); return; }
        catch (e) { await sleep(1500 * (i + 1)); }
      }
    },
    stop: async function () {
      if (!this.on) return;
      this.on = false; clearInterval(this.timer);
      var self = this, id = this.id, dur = Math.round((Date.now() - this.started) / 1000);
      await new Promise(function (ok) { self.mr.onstop = ok; try { self.mr.stop(); } catch (e) { ok(); } });
      await this.queue;
      for (var k in this.sources) this.removeAudio(k);
      this.dest = null;
      await api('POST', '/recordings/' + id + '/stop', { duration: dur }).catch(function () {});
      S.recording = false; scheduleRender();
      toast('Enregistrement sauvegardé : il est disponible dans la fiche de la réunion.');
    },
    draw: function (g) {
      var W = 1280, H = 720; g.fillStyle = '#0B1220'; g.fillRect(0, 0, W, H);
      var tiles = tileList(), shot = tiles.filter(function (t) { return t.kind === 'screen'; })[0];
      function paint(t, x, y, w, hh) {
        g.fillStyle = '#172238'; g.fillRect(x, y, w, hh);
        var el = document.querySelector('[data-tile="' + t.key + '"] video');
        if (el && el.videoWidth && t.video) {
          var r = Math.min(w / el.videoWidth, hh / el.videoHeight), vw = el.videoWidth * r, vh = el.videoHeight * r;
          if (t.kind !== 'screen') { r = Math.max(w / el.videoWidth, hh / el.videoHeight); vw = el.videoWidth * r; vh = el.videoHeight * r; }
          g.save(); g.beginPath(); g.rect(x, y, w, hh); g.clip(); g.drawImage(el, x + (w - vw) / 2, y + (hh - vh) / 2, vw, vh); g.restore();
        } else {
          g.fillStyle = color(t.pid); g.beginPath(); g.arc(x + w / 2, y + hh / 2, Math.min(w, hh) / 6, 0, 7); g.fill();
          g.fillStyle = '#fff'; g.font = 'bold ' + Math.round(Math.min(w, hh) / 8) + 'px sans-serif'; g.textAlign = 'center'; g.textBaseline = 'middle'; g.fillText(initials(t.name), x + w / 2, y + hh / 2);
        }
        g.fillStyle = 'rgba(0,0,0,.55)'; g.font = '14px sans-serif'; var tw = g.measureText(t.name).width; g.fillRect(x + 8, y + hh - 30, tw + 16, 22);
        g.fillStyle = '#fff'; g.textAlign = 'left'; g.textBaseline = 'middle'; g.fillText(t.name, x + 16, y + hh - 19);
      }
      if (shot) {
        paint(shot, 0, 0, 1040, H);
        tiles.filter(function (t) { return t !== shot; }).slice(0, 5).forEach(function (t, i) { paint(t, 1048, i * 144, 232, 136); });
      } else {
        var n = Math.min(tiles.length, 9), cols = Math.ceil(Math.sqrt(n)), rows = Math.ceil(n / cols), w = W / cols, hh = H / rows;
        tiles.slice(0, 9).forEach(function (t, i) { paint(t, (i % cols) * w + 3, Math.floor(i / cols) * hh + 3, w - 6, hh - 6); });
      }
      var cap = S.captions.slice(-2);
      if (cap.length) { g.font = '22px sans-serif'; g.textAlign = 'center'; cap.forEach(function (c, i) { var txt = c.name + ' : ' + c.text; var tw = Math.min(1180, g.measureText(txt).width); g.fillStyle = 'rgba(0,0,0,.7)'; g.fillRect(W / 2 - tw / 2 - 12, H - 90 + i * 36, tw + 24, 32); g.fillStyle = '#fff'; g.fillText(txt, W / 2, H - 74 + i * 36, 1180); }); }
    }
  };

  // ------------------------------------------------------------------ boucle de présence
  var pollTimer = null, pollFails = 0;
  async function poll() {
    clearTimeout(pollTimer);
    if (S.ended) return;
    try {
      var d = await api('GET', '/poll?since=' + S.cursor);
      pollFails = 0; setBanner(null);
      apply(d);
    } catch (e) {
      pollFails++;
      if (e.status === 403 || e.status === 404) { finish(e.message || 'Accès retiré.'); return; }
      if (pollFails >= 3) setBanner('Connexion au serveur perdue — reconnexion…');
    }
    pollTimer = setTimeout(poll, pollFails ? Math.min(8000, 1500 * pollFails) : S.interval);
  }
  function apply(d) {
    S.status = d.status; S.interval = d.interval || 1500; S.startedAt = d.started_at || S.startedAt; if (d.now) S.skew = d.now - Date.now() / 1000; S.count = d.count; S.locked = d.locked; S.chatEnabled = d.chat; S.spotlight = d.spotlight;
    if (d.recording !== S.recording && !Rec.on) S.recording = d.recording;
    var prevRole = S.me && S.me.role;
    S.me = Object.assign(S.me || {}, d.me);
    if (prevRole && prevRole !== S.me.role) onRole();
    S.parts = {}; d.participants.forEach(function (p) { S.parts[p.id] = p; });
    S.lobby = d.lobby || [];
    if (d.questions) { var nq = d.questions.length - S.questions.length; if (nq > 0 && S.panel !== 'qa' && S.questions.length) S.unread.qa += nq; S.questions = d.questions; }
    var openPolls = (d.polls || []).filter(function (p) { return p.status === 'open' && p.mine == null; }).length;
    if (openPolls && S.panel !== 'polls' && !isHost()) S.unread.polls = openPolls;
    S.polls = d.polls || [];
    d.feed.forEach(onFeed);
    S.cursor = Math.max(S.cursor, d.cursor || 0);
    if (d.status === 'ended') { finish('La réunion est terminée.'); return; }
    if (S.joined && engine && engine.sync) engine.sync();
    if (S.joined && d.epoch !== S.epoch && !S.switching) api('GET', '/rtc').then(function (c) { connect(c); }).catch(function () {});
    if (S.me.status === 'removed') finish('L\'organisateur vous a retiré de la réunion.');
    scheduleRender();
  }
  function nameOf(id) { var p = S.parts[id]; return p ? p.name : 'Participant'; }
  function onFeed(f) {
    if (f.kind === 'chat') {
      S.chat.push(f); if (S.panel !== 'chat' && f.from !== S.me.id) { S.unread.chat++; if (!S.panel) toast((f.name || 'Participant') + ' : ' + f.body.slice(0, 80), { label: 'Ouvrir', fn: function () { openPanel('chat'); } }); }
    } else if (f.kind === 'caption') {
      var c = { name: f.name || nameOf(f.from), text: f.body, at: Date.now(), time: f.at, from: f.from };
      S.captions.push(c); S.transcript.push(c); if (S.captions.length > 6) S.captions.shift();
      renderCaptions(); if (S.panel === 'transcript') renderPanel();
    } else if (f.kind === 'reaction') {
      if (f.from !== S.me.id) float(f.body, f.name || nameOf(f.from));
    } else if (f.kind === 'system') {
      if (f.body === 'joined' && f.from !== S.me.id && S.joined && S.count < 25) toast((f.data && f.data.name) + ' a rejoint la réunion', null, 2500);
      else if (f.body === 'lobby' && isHost()) toast((f.data && f.data.name) + ' attend dans la salle d\'attente', { label: 'Admettre', fn: function () { control('admit', f.from); } }, 9000);
      else if (f.body === 'hand' && isHost() && f.from !== S.me.id) toast('✋ ' + (f.data && f.data.name) + ' lève la main', null, 3500);
      else if (f.body === 'poll' && !isHost()) toast('Nouveau sondage', { label: 'Voter', fn: function () { openPanel('polls'); } }, 8000);
      else if (f.body === 'record_on' && !isHost()) toast('🔴 L\'enregistrement de la réunion a commencé.');
    } else if (f.kind === 'signal') {
      if (!f.data) return;
      if (engine && engine.signal && f.data.epoch === S.epoch) engine.signal(f.from, f.data);
      else if (f.data.epoch >= S.epoch) { pendingSignals.push(f); if (pendingSignals.length > 50) pendingSignals.shift(); }
    } else if (f.kind === 'control') {
      var forMe = !f.to || f.to === S.me.id;
      if (!forMe) return;
      switch (f.body) {
        case 'mute': setMic(false); toast('L\'organisateur a coupé votre micro.'); break;
        case 'mute_all': if (!f.data || f.data.except !== S.me.id) { if (!isHost()) { setMic(false); toast('L\'organisateur a coupé tous les micros.'); } } break;
        case 'cam_off': setCam(false); toast('L\'organisateur a coupé votre caméra.'); break;
        case 'stop_screen': stopScreen(); break;
        case 'removed': finish('L\'organisateur vous a retiré de la réunion.'); break;
        case 'denied': finish('L\'organisateur ne vous a pas admis.'); break;
        case 'ended': finish('L\'organisateur a mis fin à la réunion.'); break;
        case 'admitted': if (!S.joined) enterRoom(); break;
        case 'role': S.me.role = f.data.role; onRole(); break;
      }
    }
  }
  function onRole() {
    S.me.publish = S.me.role !== 'attendee';
    if (S.me.publish) toast('Vous pouvez maintenant prendre la parole : activez votre micro et votre caméra.');
    if (engine && engine.setRole) engine.setRole(S.me.publish).then(publishLocal);
    if (!S.me.publish) { setMic(false); setCam(false); stopScreen(); stopRecognition(); }
    scheduleRender();
  }
  function isHost() { return S.me && (S.me.role === 'host' || S.me.role === 'cohost'); }
  function control(action, target, value) { return api('POST', '/control', { action: action, target: target, value: value }).then(function () { poll(); }).catch(function (e) { toast(e.message); }); }
  function float(emoji, who) {
    var st = document.querySelector('.stage'); if (!st) return;
    var e = h('div', { class: 'floating', style: { left: (8 + Math.random() * 72) + '%' } }, h('span', { class: 'em', text: emoji }), who ? h('span', { class: 'who', text: who }) : null);
    st.appendChild(e); setTimeout(function () { e.remove(); }, 3600);
  }
  function setBanner(text) { S.banner = text; var b = document.getElementById('banner'); if (b) { b.classList.toggle('hidden', !text); b.textContent = text || ''; } }

  // ------------------------------------------------------------------ actions de l'utilisateur
  async function setMic(on) {
    if (on && !S.me.publish) return;
    L.micOn = on;
    if (on) { try { await getMic(); } catch (e) { toast('Micro inaccessible : autorisez-le dans le navigateur.'); L.micOn = false; } }
    Rec.setMeGain(L.micOn);
    await publishLocal();
    api('POST', '/state', { mic: L.micOn }).catch(function () {});
    if (L.micOn && S.transcribe) startRecognition(); else stopRecognition();
    scheduleRender();
  }
  async function setCam(on) {
    if (on && !S.me.publish) return;
    L.camOn = on;
    if (on) { try { await getCam(); } catch (e) { toast('Caméra inaccessible : autorisez-la dans le navigateur.'); L.camOn = false; } } else stopCam();
    await publishLocal();
    api('POST', '/state', { cam: L.camOn }).catch(function () {});
    scheduleRender();
  }
  async function toggleScreen() {
    if (L.screen) return stopScreen();
    if (!navigator.mediaDevices.getDisplayMedia) { toast('Le partage d\'écran n\'est pas disponible sur cet appareil.'); return; }
    try {
      var s = await navigator.mediaDevices.getDisplayMedia({ video: { frameRate: { ideal: 15, max: 30 }, width: { max: 1920 }, height: { max: 1080 } }, audio: false });
      L.screen = s.getVideoTracks()[0];
      try { L.screen.contentHint = 'detail'; } catch (e) {}
      L.screen.onended = stopScreen;
      await publishLocal();
      api('POST', '/state', { screen: true }).catch(function () {});
      scheduleRender();
    } catch (e) { /* annulé */ }
  }
  async function stopScreen() {
    if (!L.screen) return;
    L.screen.onended = null; L.screen.stop(); L.screen = null;
    await publishLocal();
    api('POST', '/state', { screen: false }).catch(function () {});
    scheduleRender();
  }
  function toggleHand() { var on = !S.me.hand; S.me.hand = on; api('POST', '/state', { hand: on }).catch(function () {}); scheduleRender(); }
  function react(e) { api('POST', '/react', { emoji: e }).catch(function () {}); float(e, 'Vous'); }
  async function leave(endAll) {
    if (endAll) { await control('end'); }
    if (Rec.on) await Rec.stop();
    await api('POST', '/leave', null, { keepalive: true }).catch(function () {});
    finish(endAll ? 'Vous avez mis fin à la réunion.' : 'Vous avez quitté la réunion.', true);
  }
  async function finish(text, byMe) {
    if (S.ended) return;
    S.ended = true; clearTimeout(pollTimer);
    if (Rec.on) await Rec.stop();
    stopRecognition();
    if (engine) { try { await engine.leave(); } catch (e) {} engine = null; }
    remoteClear();
    [L.mic, L.cam, L.screen].forEach(function (t) { if (t) t.stop(); });
    L.mic = L.cam = L.screen = null;
    clear($app).className = '';
    $app.appendChild(h('div', { class: 'center' },
      h('div', { class: 'pulse', style: { animation: 'none' } }, svg('phone')),
      h('h2', { text: text }),
      h('p', { text: S.info ? S.info.title : '' }),
      byMe && S.status !== 'ended' ? h('button', { class: 'big', style: { maxWidth: '260px' }, onclick: function () { location.reload(); } }, 'Revenir dans la réunion') : null,
      h('p', { class: 'note', text: isHost() ? 'Le compte rendu (présence, transcription, résumé et actions) est préparé automatiquement et envoyé aux participants.' : 'Merci de votre participation.' })
    ));
    try { window.parent && window.parent !== window && window.parent.postMessage({ type: 'yomara-visio', event: 'left' }, '*'); } catch (e) {}
  }
  window.addEventListener('pagehide', function () {
    if (S.joined && !S.ended && navigator.sendBeacon) { var f = new FormData(); f.append('t', S.token); navigator.sendBeacon(V.api + '/leave', f); }
  });

  // ------------------------------------------------------------------ écran d'accueil
  async function start() {
    try { S.info = await api('GET', '/'); } catch (e) { return fatal(e.message || 'Réunion introuvable.'); }
    if (S.info.status === 'cancelled') return fatal('Cette réunion a été annulée.');
    S.transcribe = !!(S.info.features && S.info.features.transcribe) && !!SR;
    prejoin();
  }
  function fatal(text) { clear($app).className = ''; $app.appendChild(h('div', { class: 'center' }, h('h2', { text: text }), h('p', { text: 'Vérifiez le lien reçu ou contactez l\'organisateur.' }))); }

  var preVideo = null;
  async function prejoin(error) {
    clear($app).className = '';
    var info = S.info, me = info.me, watcher = me && me.role === 'attendee';
    L.camOn = !watcher; L.micOn = !watcher;
    preVideo = h('video', { autoplay: true, muted: true, playsinline: true });
    var off = h('div', { class: 'off' }, h('div', { class: 'av-big', text: initials(me ? me.name : (localStorage.getItem('visio-name') || '?')) }), h('div', { text: watcher ? 'Vous assistez au webinaire en tant que spectateur' : 'Votre caméra est désactivée' }));
    var micB = h('button', { class: 'rb glass' }), camB = h('button', { class: 'rb glass' });
    var micSel = h('select', { class: 'dev' }), camSel = h('select', { class: 'dev' }), outSel = h('select', { class: 'dev' });
    var hasOut = 'setSinkId' in HTMLMediaElement.prototype;
    function paint() {
      clear(micB).appendChild(svg(L.micOn ? 'mic' : 'micoff')); micB.classList.toggle('off', !L.micOn); micB.title = L.micOn ? 'Couper le micro' : 'Activer le micro';
      clear(camB).appendChild(svg(L.camOn ? 'cam' : 'camoff')); camB.classList.toggle('off', !L.camOn); camB.title = L.camOn ? 'Couper la caméra' : 'Activer la caméra';
      preVideo.classList.toggle('hidden', !L.camOn || !L.cam); off.classList.toggle('hidden', L.camOn && !!L.cam);
    }
    async function fillDevices() {
      var list = await navigator.mediaDevices.enumerateDevices().catch(function () { return []; });
      function fill(sel, kind, cur, label) {
        clear(sel);
        list.filter(function (d) { return d.kind === kind; }).forEach(function (d, i) { sel.appendChild(h('option', { value: d.deviceId, selected: d.deviceId === cur ? true : null }, d.label || (label + ' ' + (i + 1)))); });
        sel.parentNode && sel.parentNode.classList.toggle('hidden', !sel.options.length);
      }
      fill(micSel, 'audioinput', L.mic && L.mic.getSettings().deviceId, 'Micro');
      fill(camSel, 'videoinput', L.cam && L.cam.getSettings().deviceId, 'Caméra');
      fill(outSel, 'audiooutput', L.devices.out, 'Haut-parleur');
    }
    async function refresh() {
      if (!watcher) {
        try { if (L.micOn) await getMic(); } catch (e) { L.micOn = false; }
        try { if (L.camOn) await getCam(); else stopCam(); } catch (e) { L.camOn = false; }
        preVideo.srcObject = L.cam ? new MediaStream([L.cam]) : null;
        fillDevices();
      }
      paint();
    }
    micB.onclick = function () { L.micOn = !L.micOn; refresh(); };
    camB.onclick = function () { L.camOn = !L.camOn; refresh(); };
    micSel.onchange = function () { L.devices.audio = micSel.value; if (L.mic) { L.mic.stop(); L.mic = null; } refresh(); };
    camSel.onchange = function () { L.devices.video = camSel.value; if (L.cam) { L.cam.stop(); L.cam = null; } refresh(); };
    outSel.onchange = function () { L.devices.out = outSel.value; };
    var nameIn = h('input', { value: me ? me.name : (localStorage.getItem('visio-name') || ''), placeholder: 'Votre nom', maxlength: 120, disabled: me && me.role !== 'guest' ? true : null });
    var pwdIn = info.password ? h('input', { placeholder: 'Code d\'accès', maxlength: 40 }) : null;
    var go = h('button', { class: 'big' }, info.status === 'live' ? 'Rejoindre maintenant' : (watcher ? 'Entrer dans le webinaire' : 'Rejoindre la réunion'));
    var err = h('div', { class: 'err' + (error ? '' : ' hidden'), text: error || '' });
    go.onclick = async function () {
      if (!me && !nameIn.value.trim()) { err.textContent = 'Indiquez votre nom.'; err.classList.remove('hidden'); return; }
      go.disabled = true; go.textContent = 'Connexion…';
      try { localStorage.setItem('visio-name', nameIn.value.trim()); } catch (e) {}
      audioCtx();
      try {
        var r = await api('POST', '/join', { name: nameIn.value.trim() || null, password: pwdIn ? pwdIn.value : null, client: navigator.userAgent.slice(0, 80) });
        S.token = r.token; S.me = r.me; S.cursor = r.cursor || 0; S.rtc = r.rtc;
        try { if (!V.token) history.replaceState(null, '', location.pathname + '?t=' + encodeURIComponent(r.token)); } catch (e) {}
        if (r.me.status === 'lobby') return lobby();
        enterRoom(r.rtc);
      } catch (e) { go.disabled = false; go.textContent = 'Rejoindre la réunion'; err.textContent = e.message; err.classList.remove('hidden'); }
    };
    var when = info.starts_at ? new Date(info.starts_at) : null;
    var present = info.present || [];
    var who = present.length ? h('div', { class: 'present' }, h('div', { class: 'stack' }, present.slice(0, 4).map(function (n, i) { return h('b', { text: initials(n), style: { background: color(i + 3) } }); })),
      h('span', { text: present.length === 1 ? present[0] + ' est déjà dans la réunion' : present[0] + ' et ' + (present.length - 1) + ' autre' + (present.length > 2 ? 's' : '') + ' sont déjà là' })) : h('div', { class: 'present muted', text: info.status === 'live' ? 'La réunion est en cours' : 'Personne n\'est encore arrivé' });
    function chip(icon, sel) { return h('label', { class: 'devchip' }, svg(icon), sel); }
    $app.appendChild(h('div', { class: 'prejoin' }, h('div', { class: 'box' },
      h('div', { class: 'pv-col' },
        h('div', { class: 'preview' }, preVideo, off, h('div', { class: 'meter' }, h('i', { id: 'pre-meter' })), watcher ? null : h('div', { class: 'bar' }, micB, camB)),
        watcher ? null : h('div', { class: 'devices' }, chip('mic', micSel), chip('cam', camSel), hasOut ? chip('speaker', outSel) : null,
          h('button', { class: 'devchip bgbtn', onclick: function () { backgroundDialog(); } }, svg('cam'), h('span', { text: 'Arrière-plan' })))),
      h('div', { class: 'card join' },
        h('div', { class: 'brandline' }, h('div', { class: 'lg', html: info.logo ? '<img src="' + esc(info.logo) + '" alt="">' : esc(initials(info.company)) }), h('span', { text: info.company }),
          info.status === 'live' ? h('span', { class: 'live' }, h('i'), 'En direct') : null),
        h('div', { class: 'kicker', text: info.type === 'webinar' ? 'Webinaire' : 'Prêt à rejoindre ?' }),
        h('h1', { text: info.title }),
        h('div', { class: 'sub', html: (info.host ? 'Organisée par <b>' + esc(info.host) + '</b> · ' : '') + (when ? esc(when.toLocaleString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' })) + ' · ' + info.duration + ' min' : '') }),
        who,
        h('label', { text: 'Votre nom' }), nameIn,
        pwdIn ? [h('label', { text: 'Code d\'accès' }), pwdIn] : null,
        go, err,
        h('div', { class: 'note', text: 'Aucune installation : la réunion fonctionne dans ce navigateur, sur ordinateur et sur téléphone.' + (S.transcribe ? ' Sous-titres et transcription en français disponibles.' : '') })
      ))));
    refresh();
  }

  function lobby() {
    clear($app).className = '';
    $app.appendChild(h('div', { class: 'center' }, h('div', { class: 'pulse' }, svg('people')), h('h2', { text: 'Salle d\'attente' }),
      h('p', { text: 'L\'organisateur va vous admettre dans un instant. Gardez cette page ouverte.' })));
    (function wait() {
      if (S.joined || S.ended) return;
      api('GET', '/poll?since=' + S.cursor).then(function (d) {
        S.cursor = d.cursor; S.me = d.me;
        if (d.me.status === 'admitted') return enterRoom();
        if (d.me.status === 'denied' || d.me.status === 'removed') return finish('L\'organisateur ne vous a pas admis.');
        setTimeout(wait, 2000);
      }).catch(function (e) { if (e.status === 403) return finish(e.message); setTimeout(wait, 3000); });
    })();
  }

  async function enterRoom(rtc) {
    if (S.joined) return;
    S.joined = true;
    if (!rtc) { try { rtc = await api('GET', '/rtc'); } catch (e) { S.joined = false; return prejoin(e.message); } }
    if (preVideo) preVideo.srcObject = null;
    S.me.publish = S.me.role !== 'attendee';
    if (!S.me.publish) { L.micOn = false; L.camOn = false; stopCam(); if (L.mic) { L.mic.stop(); L.mic = null; } }
    roomShell();
    api('POST', '/state', { mic: L.micOn && !!L.mic, cam: L.camOn && !!L.cam }).catch(function () {});
    poll();
    connect(rtc);
    if (L.micOn && S.transcribe) startRecognition();
    if (isHost() && S.info.features && S.info.features.record_auto) setTimeout(function () { Rec.start().catch(function () {}); }, 4000);
    if (S.info.type === 'webinar' && !isHost()) openPanel('qa');
    try { window.parent && window.parent !== window && window.parent.postMessage({ type: 'yomara-visio', event: 'joined' }, '*'); } catch (e) {}
  }

  // ------------------------------------------------------------------ salle
  var $stage, $side, $controls, $top, $captions, renderQueued = false, clock = null, pip = { x: null, y: null }, lastStageSig = null;
  function roomShell() {
    clear($app).className = '';
    $top = h('div', { class: 'top' });
    $stage = h('div', { class: 'stage' });
    $captions = h('div', { class: 'captions' });
    $side = h('div', { class: 'side hidden' });
    $controls = h('div', { class: 'dock-wrap' });
    $app.appendChild(h('div', { class: 'room' }, $top, h('div', { class: 'main' }, $stage, $side), $controls));
    document.addEventListener('keydown', function (e) {
      if (/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) return;
      if (e.key === 'm' || e.key === 'M') setMic(!L.micOn);
      else if (e.key === 'v' || e.key === 'V') setCam(!L.camOn);
      else if (e.key === 'h' || e.key === 'H') toggleHand();
    });
    clock = setInterval(tick, 1000);
    render();
  }
  function fmtDur(sec) { sec = Math.max(0, Math.floor(sec)); var hh = Math.floor(sec / 3600), mm = Math.floor(sec % 3600 / 60), ss = sec % 60; return (hh ? hh + ':' : '') + (hh && mm < 10 ? '0' : '') + mm + ':' + (ss < 10 ? '0' : '') + ss; }
  function tick() {
    var t = document.getElementById('elapsed');
    if (t && S.startedAt) t.textContent = fmtDur(Date.now() / 1000 - S.startedAt + (S.skew || 0));
    var c = document.getElementById('clock'); if (c) c.textContent = new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
  }
  function scheduleRender() { if (renderQueued || !$stage) return; renderQueued = true; requestAnimationFrame(function () { renderQueued = false; render(); }); }

  function tileList() {
    var list = [];
    var meP = S.me;
    if (meP && meP.publish) {
      list.push({ key: 'me', pid: meP.id, name: meP.name, me: true, kind: 'cam', video: L.camOn && L.cam ? new MediaStream([L.cam]) : null, mic: L.micOn && !!L.mic, hand: meP.hand, role: meP.role });
      if (L.screen) list.push({ key: 'me-screen', pid: meP.id, name: 'Votre écran', me: true, kind: 'screen', video: new MediaStream([L.screen]), mic: true });
    }
    Object.keys(S.parts).forEach(function (id) {
      var p = S.parts[id]; if (+id === (meP && meP.id)) return;
      var r = S.remote[id] || {};
      var pub = p.role !== 'attendee';
      if (pub) list.push({ key: String(id), pid: +id, name: p.name, kind: 'cam', video: p.cam && r.cam ? r.cam : null, mic: p.mic, hand: p.hand, role: p.role, connecting: S.switching && (p.cam || p.mic) });
      if (r.screen && p.screen) list.push({ key: id + '-screen', pid: +id, name: 'Écran de ' + p.name, kind: 'screen', video: r.screen, mic: true });
    });
    return list;
  }
  var videoCache = {};
  function tileEl(t, big, mini) {
    var el = h('div', { class: 'tile' + (t.me ? ' me' : '') + (t.kind === 'screen' ? ' screen' : '') + (S.speaking[t.key] ? ' speaking' : '') + (mini ? ' mini' : ''), 'data-tile': t.key,
      style: { '--c': color(t.pid) } });
    if (t.video) {
      var v = videoCache[t.key];
      if (!v) v = videoCache[t.key] = h('video', { autoplay: true, playsinline: true, muted: true });
      v.className = (t.kind === 'screen' || (big && t.kind === 'screen') ? 'contain' : 'cam');
      var tr = t.video.getVideoTracks()[0];
      if (!v.srcObject || v.srcObject.getVideoTracks()[0] !== tr) { v.srcObject = t.video; v.play().catch(function () {}); }
      el.appendChild(v);
    } else {
      delete videoCache[t.key];
      el.appendChild(h('div', { class: 'av' }, h('div', { class: 'ring' }), h('b', { text: initials(t.name) })));
    }
    if (t.connecting) el.appendChild(h('div', { class: 'conn' }, h('div', { class: 'spinner sm' }), 'Reconnexion…'));
    if (t.hand) el.appendChild(h('div', { class: 'hand', text: '✋' }));
    if (t.role === 'host' || t.role === 'cohost') el.appendChild(h('div', { class: 'tag', text: t.role === 'host' ? 'Organisateur' : 'Co-organisateur' }));
    var lvl = h('span', { class: 'lvl' }, h('i'), h('i'), h('i'));
    el.appendChild(h('div', { class: 'nm' }, t.kind === 'cam' ? (t.mic ? lvl : h('span', { class: 'mut' }, svg('micoff'))) : svg('screen'), h('span', { text: t.me && t.kind === 'cam' ? t.name + ' (vous)' : t.name })));
    if (!mini) {
      var acts = h('div', { class: 'acts' },
        h('button', { title: S.pinned === t.key ? 'Désépingler' : 'Épingler', onclick: function (e) { e.stopPropagation(); S.pinned = S.pinned === t.key ? null : t.key; scheduleRender(); }, html: S.pinned === t.key ? '📍' : '📌' }),
        t.video ? h('button', { title: 'Plein écran', onclick: function (e) { e.stopPropagation(); var vv = el.querySelector('video'); (vv.requestFullscreen ? vv.requestFullscreen() : Promise.reject()).catch(function () {}); }, html: '⛶' }) : null);
      el.appendChild(acts);
    }
    el.ondblclick = function () { S.pinned = S.pinned === t.key ? null : t.key; scheduleRender(); };
    return el;
  }
  function makePip(el) {
    el.classList.add('pip');
    var W = $stage.clientWidth, H = $stage.clientHeight, w = Math.min(240, Math.max(140, W * 0.22)), hh = w * 9 / 16;
    if (pip.x == null) { pip.x = W - w - 18; pip.y = H - hh - 18; }
    pip.x = Math.max(8, Math.min(W - w - 8, pip.x)); pip.y = Math.max(8, Math.min(H - hh - 8, pip.y));
    Object.assign(el.style, { width: w + 'px', height: hh + 'px', left: pip.x + 'px', top: pip.y + 'px' });
    el.onpointerdown = function (e) {
      if (e.target.closest('button')) return;
      var sx = e.clientX - pip.x, sy = e.clientY - pip.y; el.setPointerCapture(e.pointerId); el.classList.add('drag');
      el.onpointermove = function (ev) { pip.x = Math.max(8, Math.min(W - w - 8, ev.clientX - sx)); pip.y = Math.max(8, Math.min(H - hh - 8, ev.clientY - sy)); el.style.left = pip.x + 'px'; el.style.top = pip.y + 'px'; };
      el.onpointerup = function () { el.onpointermove = null; el.classList.remove('drag'); };
    };
    return el;
  }
  function render() {
    if (!$stage || S.ended) return;
    renderTop(); renderControls();
    var tiles = tileList();
    var shot = tiles.filter(function (t) { return t.kind === 'screen'; })[0];
    var spot = S.spotlight ? tiles.filter(function (t) { return t.pid === S.spotlight && t.kind === 'cam'; })[0] : null;
    var pinned = S.pinned ? tiles.filter(function (t) { return t.key === S.pinned; })[0] : null;
    var focus = pinned || shot || spot || (S.layout === 'speaker' && tiles.length > 2 ? tiles.filter(function (t) { return t.key === S.activeSpeaker; })[0] || tiles[1] : null);
    // Rien n'a changé à l'écran (cas de chaque passage de présence, toutes les 1,5 s) : on ne reconstruit pas les vignettes.
    // Les reconstruire décrochait les vidéos du document, d'où un écran noir à intervalles réguliers.
    var sig = JSON.stringify([tiles.map(function (t) { var tr = t.video && t.video.getVideoTracks()[0]; return [t.key, t.kind, tr ? tr.id : 0, t.mic, t.hand, t.role, t.connecting, t.name]; }),
      focus && focus.key, S.pinned, S.layout, !!S.banner, isHost() ? S.lobby.map(function (l) { return l.id + l.name; }) : 0, $stage.clientWidth, $stage.clientHeight, S.info && S.info.type]);
    if (sig === lastStageSig && $stage.firstChild) {
      renderCaptions();
      if (S.panel) renderPanel(true);
      return;
    }
    lastStageSig = sig;
    clear($stage);
    $stage.appendChild(h('div', { id: 'banner', class: 'banner' + (S.banner ? '' : ' hidden'), text: S.banner || '' }));
    if (S.lobby.length && isHost()) {
      $stage.appendChild(h('div', { class: 'banner lobby', style: { top: S.banner ? '58px' : '14px' } }, h('span', { class: 'dot' }), S.lobby.length === 1 ? S.lobby[0].name + ' attend dans la salle d\'attente' : S.lobby.length + ' personnes attendent dans la salle d\'attente',
        h('button', { class: 'ghost', onclick: function () { openPanel('people'); } }, 'Voir'), h('button', { onclick: function () { control(S.lobby.length === 1 ? 'admit' : 'admit_all', S.lobby.length === 1 ? S.lobby[0].id : null); } }, S.lobby.length === 1 ? 'Admettre' : 'Tout admettre')));
    }
    var mine = tiles.filter(function (t) { return t.key === 'me'; })[0];
    if (!tiles.length || (tiles.length === 1 && mine)) {
      var alone = h('div', { class: 'alone' },
        mine ? (function () { var e = tileEl(mine, true); e.classList.add('solo'); return e; })() : null,
        h('div', { class: 'invite card' },
          h('div', { class: 'pulse' }, svg('people')),
          h('h2', { text: S.info.type === 'webinar' ? (isHost() ? 'Votre webinaire est ouvert' : 'Le webinaire va commencer') : 'Vous êtes le premier arrivé' }),
          h('p', { text: S.info.type === 'webinar' ? (isHost() ? 'Les inscrits arrivent par leur lien personnel. Lancez l\'enregistrement quand vous êtes prêt.' : 'Les intervenants vont arriver. Posez déjà vos questions dans l\'onglet Q&R.') : 'Les autres participants apparaîtront ici dès leur arrivée.' }),
          S.info.type !== 'webinar' ? h('div', { class: 'linkbox' }, h('span', { text: (V.base + '/visio/' + V.code).replace(/^https?:\/\//, '') }), h('button', { class: 'btn p', onclick: copyLink }, 'Copier le lien')) : null));
      $stage.appendChild(alone);
    } else if (focus) {
      var others = tiles.filter(function (t) { return t !== focus; });
      $stage.appendChild(h('div', { class: 'focus' }, h('div', { class: 'big-tile' }, tileEl(focus, true)),
        others.length ? h('div', { class: 'strip' }, others.map(function (t) { return tileEl(t); })) : null));
    } else if (tiles.length === 2 && mine && S.layout !== 'grid') {
      // Face-à-face : l'autre en grand, soi-même en vignette déplaçable (comme FaceTime ou Meet)
      var other = tiles.filter(function (t) { return t !== mine; })[0];
      $stage.appendChild(h('div', { class: 'duo' }, tileEl(other, true)));
      $stage.appendChild(makePip(tileEl(mine, false, true)));
    } else {
      var MAX = 16, shown = tiles.length > MAX ? tiles.slice(0, MAX - 1) : tiles, extra = tiles.length - shown.length;
      var n = shown.length + (extra ? 1 : 0), w = $stage.clientWidth - 24 || 1000, hh = ($stage.clientHeight || 600) - 24, gap = 12;
      var best = { cols: 1, size: 0 };
      for (var cols = 1; cols <= n; cols++) {
        var rows = Math.ceil(n / cols), tw = (w - (cols - 1) * gap) / cols, th = (hh - (rows - 1) * gap) / rows;
        var size = Math.min(tw, th * 16 / 9); if (size > best.size) best = { cols: cols, size: size, rows: rows };
      }
      // Grille centrée (la dernière rangée incomplète reste au milieu, comme Meet)
      var tw0 = Math.floor(best.size), th0 = Math.floor(best.size * 9 / 16);
      var g = h('div', { class: 'grid', style: { maxWidth: (best.cols * tw0 + (best.cols - 1) * gap + 24) + 'px' } });
      shown.forEach(function (t) { var e = tileEl(t); e.style.width = tw0 + 'px'; e.style.height = th0 + 'px'; g.appendChild(e); });
      if (extra) g.appendChild(h('div', { class: 'tile more', style: { width: tw0 + 'px', height: th0 + 'px' }, onclick: function () { openPanel('people'); } }, h('div', { class: 'stack' }, tiles.slice(MAX - 1, MAX + 2).map(function (t) { return h('b', { text: initials(t.name), style: { background: color(t.pid) } }); })), h('span', { text: '+' + extra + ' participant' + (extra > 1 ? 's' : '') })));
      $stage.appendChild(g);
    }
    $stage.appendChild($captions);
    // Vidéos replacées dans la page : on s'assure qu'elles continuent de jouer
    $stage.querySelectorAll('video').forEach(function (v) { if (v.paused) v.play().catch(function () {}); });
    renderCaptions();
    if (S.panel) renderPanel(true);
  }
  function renderTop() {
    clear($top);
    var net = h('span', { class: 'net q' + S.quality, title: ['', 'Connexion faible', 'Connexion moyenne', 'Bonne connexion'][S.quality] }, h('i'), h('i'), h('i'));
    var list = Object.keys(S.parts).map(function (k) { return S.parts[k]; });
    var stack = h('button', { class: 'stack-btn', title: 'Participants', onclick: function () { openPanel('people'); } },
      h('div', { class: 'stack' }, list.slice(0, 4).map(function (p) { return h('b', { text: initials(p.name), style: { background: color(p.id) } }); })),
      h('span', { text: String(S.count || list.length || 1) }));
    $top.appendChild(h('div', { class: 'title' },
      h('div', { class: 'lg', text: initials(S.info.company) }),
      h('div', { class: 'tt' }, h('div', { class: 't', text: S.info.title }), h('div', { class: 's' }, S.info.type === 'webinar' ? 'Webinaire' : V.code, S.startedAt ? [' · ', h('span', { id: 'elapsed', text: fmtDur(Date.now() / 1000 - S.startedAt + (S.skew || 0)) })] : null))));
    if (S.recording) $top.appendChild(h('span', { class: 'chip rec' }, h('i'), 'REC'));
    if (S.locked) $top.appendChild(h('span', { class: 'chip', text: '🔒 Verrouillée' }));
    if (S.transcribe && recogWanted) $top.appendChild(h('span', { class: 'chip cc', text: 'Transcription' }));
    $top.appendChild(h('span', { class: 'sp' }));
    if (S.switching) $top.appendChild(h('span', { class: 'chip soft' }, h('div', { class: 'spinner sm' }), 'Optimisation…'));
    $top.appendChild(stack);
    $top.appendChild(net);
    $top.appendChild(h('span', { class: 'chip clock', id: 'clock', text: new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) }));
  }
  function updateNet() { var n = document.querySelector('.net'); if (n) n.className = 'net q' + S.quality; }
  function db(icon, label, onclick, cls, badge, tip) {
    return h('button', { class: 'db ' + (cls || ''), title: tip || label, onclick: onclick }, h('span', { class: 'ic' }, svg(icon), badge ? h('span', { class: 'badge', text: badge }) : null), h('span', { class: 'lb', text: label }));
  }
  function renderControls() {
    clear($controls);
    var can = S.me.publish;
    var main = [], side = [], narrow = window.innerWidth < 640;
    if (narrow) {
      if (can) {
        main.push(db(L.micOn ? 'mic' : 'micoff', 'Micro', function () { setMic(!L.micOn); }, L.micOn ? '' : 'off'));
        main.push(db(L.camOn ? 'cam' : 'camoff', 'Caméra', function () { setCam(!L.camOn); }, L.camOn ? '' : 'off'));
      }
      main.push(db('hand', 'Main', toggleHand, S.me.hand ? 'on warm' : ''));
      main.push(db('chat', 'Discussion', function () { openPanel('chat'); }, S.panel === 'chat' ? 'on' : '', S.unread.chat || null));
      var unreadMore = (S.unread.qa || 0) + (S.unread.polls || 0) + (isHost() ? S.lobby.length : 0);
      var moreN = db('more', 'Plus', function (e) { e.stopPropagation(); moreMenu(moreN, true); }, '', unreadMore || null);
      main.push(moreN);
      var lv = h('button', { class: 'leave', title: 'Quitter', onclick: function (e) { e.stopPropagation(); if (isHost()) menu(e.currentTarget, [{ label: 'Quitter la réunion', fn: function () { leave(false); } }, { label: 'Mettre fin pour tous', danger: true, fn: function () { leave(true); } }]); else leave(false); } }, svg('phone'));
      $controls.appendChild(h('div', { class: 'dock' }, main, h('span', { class: 'sep' }), lv));
      return;
    }
    if (can) {
      main.push(db(L.micOn ? 'mic' : 'micoff', L.micOn ? 'Micro' : 'Muet', function () { setMic(!L.micOn); }, L.micOn ? '' : 'off', null, (L.micOn ? 'Couper' : 'Activer') + ' le micro (M)'));
      main.push(db(L.camOn ? 'cam' : 'camoff', 'Caméra', function () { setCam(!L.camOn); }, L.camOn ? '' : 'off', null, (L.camOn ? 'Couper' : 'Activer') + ' la caméra (V)'));
      if ((S.info.features.screen || isHost()) && navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia) main.push(db('screen', L.screen ? 'Arrêter' : 'Partager', toggleScreen, L.screen ? 'on' : '', null, L.screen ? 'Arrêter le partage d\'écran' : 'Partager l\'écran'));
    }
    main.push(db('hand', 'Main', toggleHand, S.me.hand ? 'on warm' : '', null, (S.me.hand ? 'Baisser' : 'Lever') + ' la main (H)'));
    if (S.info.features.reactions) {
      var rx = db('smile', 'Réagir', function (e) {
        e.stopPropagation();
        menu(rx, [{ el: h('div', { class: 'emojis' }, ['👍', '👏', '❤️', '😂', '😮', '🎉', '🙏', '🔥'].map(function (x) { return h('button', { onclick: function () { react(x); closeMenu(); } }, x); })) }]);
      });
      main.push(rx);
    }
    main.push(db('cc', 'Sous-titres', function () { S.captionsOn = !S.captionsOn; if (S.captionsOn && !SR) toast('Votre navigateur ne produit pas de sous-titres ; ceux des autres participants s\'afficheront.'); renderCaptions(); renderControls(); }, S.captionsOn ? 'on' : ''));
    var more = db('more', 'Plus', function (e) { e.stopPropagation(); moreMenu(more); });
    main.push(more);
    side.push(db('people', 'Personnes', function () { openPanel('people'); }, S.panel === 'people' ? 'on' : '', S.lobby.length && isHost() ? S.lobby.length : null));
    side.push(db('chat', 'Discussion', function () { openPanel('chat'); }, S.panel === 'chat' ? 'on' : '', S.unread.chat || null));
    if (S.info.type === 'webinar' || S.questions.length) side.push(db('qa', 'Q&R', function () { openPanel('qa'); }, S.panel === 'qa' ? 'on' : '', S.unread.qa || null));
    side.push(db('poll', 'Sondages', function () { openPanel('polls'); }, S.panel === 'polls' ? 'on' : '', S.unread.polls || null));
    var leaveB = h('button', { class: 'leave', title: 'Quitter la réunion', onclick: function (e) {
      e.stopPropagation();
      if (isHost()) menu(e.currentTarget, [{ label: 'Quitter la réunion', fn: function () { leave(false); } }, { label: 'Mettre fin pour tous', danger: true, fn: function () { leave(true); } }]);
      else leave(false);
    } }, svg('phone'), h('span', { text: 'Quitter' }));
    $controls.appendChild(h('div', { class: 'dock' }, main, h('span', { class: 'sep' }), side, h('span', { class: 'sep' }), leaveB));
  }
  function moreMenu(anchor, narrow) {
    var host = isHost();
    menu(anchor, [
      narrow && S.me.publish && navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia && (S.info.features.screen || host) ? { label: L.screen ? 'Arrêter le partage d\'écran' : 'Partager l\'écran', fn: toggleScreen } : null,
      narrow && S.info.features.reactions ? { el: h('div', { class: 'emojis' }, ['👍', '👏', '❤️', '😂', '🎉', '🙏'].map(function (x) { return h('button', { onclick: function () { react(x); closeMenu(); } }, x); })) } : null,
      narrow ? { label: (S.captionsOn ? 'Masquer' : 'Afficher') + ' les sous-titres', fn: function () { S.captionsOn = !S.captionsOn; renderCaptions(); } } : null,
      narrow ? { label: 'Participants' + (host && S.lobby.length ? ' (' + S.lobby.length + ' en attente)' : ''), fn: function () { openPanel('people'); } } : null,
      narrow && (S.info.type === 'webinar' || S.questions.length) ? { label: 'Questions' + (S.unread.qa ? ' (' + S.unread.qa + ')' : ''), fn: function () { openPanel('qa'); } } : null,
      narrow ? { label: 'Sondages' + (S.unread.polls ? ' (' + S.unread.polls + ')' : ''), fn: function () { openPanel('polls'); } } : null,
      narrow ? '-' : null,
      { label: S.layout === 'speaker' ? 'Vue mosaïque' : 'Vue orateur', fn: function () { S.layout = S.layout === 'speaker' ? 'grid' : 'speaker'; S.pinned = null; scheduleRender(); } },
      { label: 'Transcription en direct', fn: function () { openPanel('transcript'); } },
      { label: 'Plein écran', fn: function () { if (document.fullscreenElement) document.exitFullscreen(); else document.documentElement.requestFullscreen().catch(function () {}); } },
      { label: 'Micro, caméra et haut-parleur…', fn: devicesDialog },
      S.me && S.me.publish ? { label: 'Arrière-plan (flou, image)…', fn: backgroundDialog } : null,
      { label: 'Copier le lien d\'invitation', fn: copyLink },
      SR && S.me.publish ? { label: recogWanted ? 'Arrêter ma transcription' : 'Transcrire ma voix', fn: function () { if (recogWanted) { stopRecognition(); S.transcribe = false; } else { S.transcribe = true; startRecognition(); } renderTop(); } } : null,
      host ? '-' : null,
      host ? { label: Rec.on ? '⏹ Arrêter l\'enregistrement' : '⏺ Enregistrer la réunion', fn: function () { (Rec.on ? Rec.stop() : Rec.start()).catch(function (e) { toast(e.message); }); } } : null,
      host ? { label: 'Couper tous les micros', fn: function () { control('mute_all'); } } : null,
      host ? { label: S.locked ? 'Déverrouiller la réunion' : 'Verrouiller la réunion', fn: function () { control('lock', null, !S.locked); } } : null,
      host ? { label: S.chatEnabled ? 'Désactiver la conversation' : 'Activer la conversation', fn: function () { control('chat', null, !S.chatEnabled); } } : null,
      host ? { label: 'Créer un sondage', fn: pollDialog } : null,
      host && S.spotlight ? { label: 'Retirer la mise en avant', fn: function () { control('spotlight', null); } } : null,
      host ? '-' : null,
      host ? { label: 'Mettre fin à la réunion pour tous', danger: true, fn: function () { leave(true); } } : null
    ]);
  }
  function copyLink() {
    var url = V.base + '/visio/' + V.code;
    (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject()).then(function () { toast('Lien copié : ' + url); }).catch(function () { modal('Lien d\'invitation', h('input', { value: url, readonly: true, onclick: function (e) { e.target.select(); } })); });
  }
  function renderCaptions() {
    if (!$captions) return;
    clear($captions);
    if (!S.captionsOn) return;
    var now = Date.now();
    S.captions.filter(function (c) { return now - c.at < 9000; }).slice(-2).forEach(function (c) { $captions.appendChild(h('div', { html: '<b>' + esc(c.name) + '</b>' + esc(c.text) })); });
    if (interim) $captions.appendChild(h('div', { html: '<b>Vous</b>' + esc(interim), style: { opacity: '.8' } }));
  }
  setInterval(function () { if (S.captionsOn) renderCaptions(); }, 2000);

  // ------------------------------------------------------------------ panneau latéral
  var chatInput = null, chatTo = '', panelSig = '';
  function openPanel(name) {
    S.panel = S.panel === name ? null : name;
    if (S.panel === 'chat') S.unread.chat = 0;
    if (S.panel === 'qa') S.unread.qa = 0;
    if (S.panel === 'polls') S.unread.polls = 0;
    renderPanel(); renderControls();
  }
  function renderPanel(soft) {
    if (!$side) return;
    $side.classList.toggle('hidden', !S.panel);
    if (!S.panel) return;
    // Ne pas effacer un champ en cours de saisie lors d'un rafraîchissement
    var sig = JSON.stringify([S.panel, S.parts, S.lobby, S.chat.length, S.questions, S.polls, S.transcript.length, S.chatEnabled, S.spotlight, S.me && S.me.role, S.count, S.unread]);
    if (soft && sig === panelSig) return;
    if (soft && $side.contains(document.activeElement) && /INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) { softUpdate(); panelSig = sig; return; }
    panelSig = sig;
    var tabs = [['people', 'Participants'], ['chat', 'Conversation'], (S.info.type === 'webinar' || S.questions.length) ? ['qa', 'Q&R'] : null, ['polls', 'Sondages'], ['transcript', 'Transcription']].filter(Boolean);
    var hd = h('div', { class: 'hd' }, tabs.map(function (t) {
      var n = t[0] === 'chat' ? S.unread.chat : t[0] === 'qa' ? S.unread.qa : t[0] === 'polls' ? S.unread.polls : 0;
      return h('button', { class: 'tab' + (S.panel === t[0] ? ' on' : ''), onclick: function () { S.panel = t[0]; if (t[0] === 'chat') S.unread.chat = 0; if (t[0] === 'qa') S.unread.qa = 0; if (t[0] === 'polls') S.unread.polls = 0; renderPanel(); renderControls(); } }, t[1], n ? h('span', { class: 'n', text: n }) : null);
    }), h('button', { class: 'x', onclick: function () { S.panel = null; renderPanel(); renderControls(); } }, '×'));
    var bd = h('div', { class: 'bd', id: 'side-bd' }), ft = h('div', { class: 'ft' });
    var keepScroll = soft && document.getElementById('side-bd') ? document.getElementById('side-bd').scrollTop : null;
    clear($side); $side.appendChild(hd); $side.appendChild(bd);
    if (S.panel === 'people') panelPeople(bd, ft);
    else if (S.panel === 'chat') panelChat(bd, ft);
    else if (S.panel === 'qa') panelQA(bd, ft);
    else if (S.panel === 'polls') panelPolls(bd, ft);
    else if (S.panel === 'transcript') panelTranscript(bd, ft);
    if (ft.childNodes.length) $side.appendChild(ft);
    if (S.panel === 'chat' || S.panel === 'transcript') bd.scrollTop = bd.scrollHeight;
    else if (keepScroll != null) bd.scrollTop = keepScroll;
  }
  function softUpdate() {
    var bd = document.getElementById('side-bd'); if (!bd) return;
    if (S.panel === 'chat') { var stick = bd.scrollTop + bd.clientHeight >= bd.scrollHeight - 30; clear(bd); chatMessages(bd); if (stick) bd.scrollTop = bd.scrollHeight; }
  }
  function prow(p, right) {
    return h('div', { class: 'prow' }, h('div', { class: 'a', text: initials(p.name), style: { background: color(p.id) } }),
      h('div', { class: 'n' }, h('div', { text: p.name + (p.id === S.me.id ? ' (vous)' : '') }), h('small', { text: ({ host: 'Organisateur', cohost: 'Co-organisateur', panelist: 'Intervenant', attendee: 'Spectateur', guest: 'Invité', member: 'Membre' })[p.role] || '' })),
      h('div', { class: 'ic' }, p.hand ? h('span', { text: '✋' }) : null, p.screen ? svg('screen') : null, p.role !== 'attendee' ? svg(p.mic ? 'mic' : 'micoff', p.mic ? '' : 'bad') : null, p.role !== 'attendee' ? svg(p.cam ? 'cam' : 'camoff', p.cam ? '' : 'bad') : null), right);
  }
  function panelPeople(bd) {
    if (isHost() && S.lobby.length) {
      bd.appendChild(h('div', { class: 'sec' }, 'Salle d\'attente (' + S.lobby.length + ')', h('button', { class: 'mini p', onclick: function () { control('admit_all'); } }, 'Tout admettre')));
      S.lobby.forEach(function (p) {
        bd.appendChild(h('div', { class: 'prow' }, h('div', { class: 'a', text: initials(p.name), style: { background: color(p.id) } }), h('div', { class: 'n' }, h('div', { text: p.name }), h('small', { text: p.email || 'Invité' })),
          h('button', { class: 'mini p', onclick: function () { control('admit', p.id); } }, 'Admettre'), h('button', { class: 'mini r', onclick: function () { control('deny', p.id); } }, 'Refuser')));
      });
    }
    var list = Object.keys(S.parts).map(function (k) { return S.parts[k]; });
    var hands = list.filter(function (p) { return p.hand; }).sort(function (a, b) { return a.hand - b.hand; });
    if (hands.length) {
      bd.appendChild(h('div', { class: 'sec' }, 'Mains levées (' + hands.length + ')', isHost() ? h('button', { class: 'mini', onclick: function () { control('lower_all'); } }, 'Tout baisser') : null));
      hands.forEach(function (p) { bd.appendChild(prow(p, isHost() ? (S.info.type === 'webinar' && p.role === 'attendee' ? h('button', { class: 'mini p', onclick: function () { control('promote', p.id); } }, 'Donner la parole') : h('button', { class: 'mini', onclick: function () { control('lower_hand', p.id); } }, 'Baisser')) : null)); });
    }
    var speakers = list.filter(function (p) { return p.role !== 'attendee'; }), watchers = list.filter(function (p) { return p.role === 'attendee'; });
    bd.appendChild(h('div', { class: 'sec' }, (S.info.type === 'webinar' ? 'Intervenants' : 'Dans la réunion') + ' (' + speakers.length + ')', isHost() && S.info.type !== 'webinar' ? h('button', { class: 'mini', onclick: function () { control('mute_all'); } }, 'Couper tous les micros') : null));
    speakers.forEach(function (p) { bd.appendChild(prow(p, isHost() && p.id !== S.me.id ? kebab(p) : null)); });
    if (S.info.type === 'webinar') {
      bd.appendChild(h('div', { class: 'sec', text: 'Spectateurs (' + Math.max(watchers.length, (S.count || 0) - speakers.length) + ')' }));
      watchers.slice(0, 300).forEach(function (p) { bd.appendChild(prow(p, isHost() ? kebab(p) : null)); });
    }
    if (isHost()) bd.appendChild(h('button', { class: 'btn', style: { width: '100%', marginTop: '14px' }, onclick: copyLink }, 'Copier le lien d\'invitation'));
  }
  function kebab(p) {
    var b = h('button', { class: 'kebab', text: '⋮' });
    b.onclick = function (e) {
      e.stopPropagation();
      menu(b, [
        p.mic ? { label: 'Couper son micro', fn: function () { control('mute', p.id); } } : null,
        p.cam ? { label: 'Couper sa caméra', fn: function () { control('cam_off', p.id); } } : null,
        p.screen ? { label: 'Arrêter son partage d\'écran', fn: function () { control('stop_screen', p.id); } } : null,
        { label: S.spotlight === p.id ? 'Retirer la mise en avant' : 'Mettre en avant pour tous', fn: function () { control('spotlight', S.spotlight === p.id ? null : p.id); } },
        { label: 'Message privé', fn: function () { chatTo = String(p.id); S.panel = 'chat'; renderPanel(); } },
        p.role === 'attendee' ? { label: 'Donner la parole', fn: function () { control('promote', p.id); } } : null,
        S.info.type === 'webinar' && p.role === 'panelist' ? { label: 'Repasser en spectateur', fn: function () { control('demote', p.id); } } : null,
        S.me.role === 'host' && p.role !== 'cohost' && p.role !== 'attendee' ? { label: 'Nommer co-organisateur', fn: function () { control('cohost', p.id, true); } } : null,
        S.me.role === 'host' && p.role === 'cohost' ? { label: 'Retirer co-organisateur', fn: function () { control('cohost', p.id, false); } } : null,
        p.hand ? { label: 'Baisser sa main', fn: function () { control('lower_hand', p.id); } } : null,
        '-',
        { label: 'Retirer de la réunion', danger: true, fn: function () { control('remove', p.id); } }
      ], false);
    };
    return b;
  }
  function chatMessages(bd) {
    if (!S.chat.length) bd.appendChild(h('div', { class: 'sys', text: 'Les messages sont visibles par les participants présents. Ils figurent dans le compte rendu.' }));
    S.chat.forEach(function (m) {
      var mine = m.from === S.me.id;
      var priv = m.to ? (mine ? 'à ' + nameOf(m.to) + ' (privé)' : 'privé') : '';
      bd.appendChild(h('div', { class: 'msg' + (mine ? ' mine' : '') }, h('div', { class: 'h', html: (mine ? '' : '<b>' + esc(m.name || 'Participant') + '</b> · ') + esc(m.at) + (priv ? ' <span class="pv">' + esc(priv) + '</span>' : '') }),
        h('div', { class: 'b', html: esc(m.body).replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>') })));
    });
  }
  function panelChat(bd, ft) {
    chatMessages(bd);
    if (!S.chatEnabled && !isHost()) { ft.appendChild(h('div', { class: 'sys', text: 'La conversation est désactivée par l\'organisateur.' })); return; }
    var sel = h('select', { onchange: function (e) { chatTo = e.target.value; } }, h('option', { value: '' }, 'À : tout le monde'),
      Object.keys(S.parts).filter(function (k) { return +k !== S.me.id; }).map(function (k) { return h('option', { value: k, selected: chatTo === k ? true : null }, 'À : ' + S.parts[k].name + ' (privé)'); }));
    chatInput = h('textarea', { rows: 2, placeholder: 'Écrire un message…', maxlength: 2000, onkeydown: function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendChat(); } } });
    ft.appendChild(sel); ft.appendChild(h('div', { style: { display: 'flex', gap: '8px' } }, chatInput, h('button', { class: 'btn p', onclick: sendChat }, 'Envoyer')));
    setTimeout(function () { chatInput && chatInput.focus(); }, 0);
  }
  function sendChat() {
    var t = chatInput.value.trim(); if (!t) return;
    chatInput.value = '';
    api('POST', '/chat', { body: t, to: chatTo ? +chatTo : null }).then(function () { poll(); }).catch(function (e) { toast(e.message); });
  }
  function panelQA(bd, ft) {
    var mod = isHost() || S.me.role === 'panelist';
    if (!S.questions.length) bd.appendChild(h('div', { class: 'sys', text: 'Posez vos questions : les plus votées remontent en haut de la liste.' }));
    S.questions.forEach(function (q) {
      var acts = mod ? h('span', { style: { display: 'flex', gap: '6px' } },
        q.status !== 'live' ? h('button', { class: 'mini', onclick: function () { qaUpdate(q.id, { status: 'live' }); } }, 'À l\'antenne') : null,
        h('button', { class: 'mini', onclick: function () { answerDialog(q); } }, q.answer ? 'Modifier la réponse' : 'Répondre'),
        q.status !== 'hidden' ? h('button', { class: 'mini r', onclick: function () { qaUpdate(q.id, { status: 'hidden' }); } }, 'Masquer') : h('button', { class: 'mini', onclick: function () { qaUpdate(q.id, { status: 'open' }); } }, 'Afficher')) : null;
      bd.appendChild(h('div', { class: 'qa' + (q.status === 'live' ? ' live' : ''), style: q.status === 'hidden' ? { opacity: .5 } : null },
        q.status === 'live' ? h('div', { style: { color: '#FBBF24', fontSize: '11px', fontWeight: 700, marginBottom: '4px' }, text: 'EN COURS DE RÉPONSE' }) : null,
        h('div', { class: 'q', text: q.body }),
        q.answer ? h('div', { class: 'ans', text: q.answer }) : null,
        h('div', { class: 'm' }, h('span', { text: q.by || 'Participant' }), h('button', { class: 'vote' + (q.voted ? ' on' : ''), onclick: function () { api('POST', '/questions/' + q.id + '/vote').then(poll); } }, '▲ ' + q.votes), acts)));
    });
    var inp = h('textarea', { rows: 2, placeholder: 'Votre question…', maxlength: 1000 });
    var anon = h('input', { type: 'checkbox', style: { width: 'auto' } });
    ft.appendChild(inp);
    ft.appendChild(h('div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } }, h('label', { style: { display: 'flex', gap: '6px', alignItems: 'center', color: 'var(--muted)', fontSize: '12px' } }, anon, 'Anonyme'),
      h('button', { class: 'btn p', onclick: function () { var t = inp.value.trim(); if (!t) return; api('POST', '/questions', { body: t, anonymous: anon.checked }).then(function () { inp.value = ''; poll(); toast('Question envoyée.'); }).catch(function (e) { toast(e.message); }); } }, 'Envoyer')));
  }
  function qaUpdate(id, body) { api('POST', '/questions/' + id + '/answer', body).then(poll).catch(function (e) { toast(e.message); }); }
  function answerDialog(q) {
    var t = h('textarea', { rows: 4, maxlength: 2000 }); t.value = q.answer || '';
    modal('Répondre par écrit', h('div', null, h('p', { text: q.body, style: { color: 'var(--muted)' } }), t), [{ label: 'Annuler' }, { label: 'Publier', primary: true, fn: function () { qaUpdate(q.id, { answer: t.value.trim() }); } }]);
  }
  function panelPolls(bd, ft) {
    if (!S.polls.length) bd.appendChild(h('div', { class: 'sys', text: isHost() ? 'Lancez un sondage : les résultats s\'affichent en direct.' : 'Aucun sondage pour le moment.' }));
    S.polls.forEach(function (p) {
      var sel = (p.mine || []).slice(), total = p.counts ? p.counts.reduce(function (a, b) { return a + b; }, 0) : 0;
      var box = h('div', { class: 'poll' }, h('div', { style: { fontWeight: 700 }, text: p.question }), h('div', { style: { color: 'var(--muted)', fontSize: '12px', marginTop: '2px' }, text: (p.status === 'open' ? 'En cours' : 'Clos') + ' · ' + p.voters + ' votant' + (p.voters > 1 ? 's' : '') + (p.multiple ? ' · plusieurs choix' : '') }));
      p.options.forEach(function (o, i) {
        var pct = p.counts && total ? Math.round(p.counts[i] * 100 / total) : 0;
        var b = h('button', { class: 'opt' + (sel.indexOf(i) >= 0 ? ' sel' : ''), disabled: p.status !== 'open' || (p.mine != null && !p.multiple) ? true : null },
          p.counts ? h('i', { style: { width: pct + '%' } }) : null, h('span', null, h('span', { text: o }), p.counts ? h('b', { text: pct + ' %' }) : null));
        b.onclick = function () {
          if (p.multiple) { var k = sel.indexOf(i); if (k >= 0) sel.splice(k, 1); else sel.push(i); b.classList.toggle('sel'); }
          else api('POST', '/polls/' + p.id + '/vote', { choices: [i] }).then(poll).catch(function (e) { toast(e.message); });
        };
        box.appendChild(b);
      });
      if (p.multiple && p.status === 'open') box.appendChild(h('button', { class: 'btn p', style: { marginTop: '10px' }, onclick: function () { if (sel.length) api('POST', '/polls/' + p.id + '/vote', { choices: sel }).then(poll).catch(function (e) { toast(e.message); }); } }, 'Valider'));
      if (isHost() && p.status === 'open') box.appendChild(h('button', { class: 'mini', style: { marginTop: '10px' }, onclick: function () { api('POST', '/polls/' + p.id + '/close').then(poll); } }, 'Clore le sondage'));
      bd.appendChild(box);
    });
    if (isHost()) ft.appendChild(h('button', { class: 'btn p', onclick: pollDialog }, '+ Nouveau sondage'));
  }
  function pollDialog() {
    var q = h('input', { placeholder: 'Question', maxlength: 255 });
    var opts = h('div');
    function addOpt(v) { opts.appendChild(h('input', { placeholder: 'Réponse ' + (opts.childNodes.length + 1), maxlength: 120, value: v || '', style: { marginTop: '8px' } })); }
    addOpt('Oui'); addOpt('Non');
    var multi = h('input', { type: 'checkbox', style: { width: 'auto' } });
    modal('Nouveau sondage', h('div', null, h('label', { text: 'Question' }), q, h('label', { text: 'Réponses' }), opts,
      h('button', { class: 'mini', style: { marginTop: '8px' }, onclick: function () { if (opts.childNodes.length < 8) addOpt(); } }, '+ Ajouter une réponse'),
      h('label', { style: { display: 'flex', gap: '8px', alignItems: 'center' } }, multi, 'Plusieurs réponses possibles')),
      [{ label: 'Annuler' }, { label: 'Lancer', primary: true, fn: function () {
        var o = Array.prototype.map.call(opts.querySelectorAll('input'), function (i) { return i.value.trim(); }).filter(Boolean);
        if (!q.value.trim() || o.length < 2) { toast('Une question et au moins deux réponses.'); return false; }
        api('POST', '/polls', { question: q.value.trim(), options: o, multiple: multi.checked }).then(function () { S.panel = 'polls'; poll(); }).catch(function (e) { toast(e.message); });
      } }]);
  }
  function panelTranscript(bd, ft) {
    if (!S.transcript.length) bd.appendChild(h('div', { class: 'sys', text: SR ? 'La transcription apparaît ici au fil de la réunion (reconnaissance vocale en français de chaque participant). Elle alimente le compte rendu.' : 'Votre navigateur ne transcrit pas votre voix ; la transcription des autres participants apparaît ici.' }));
    S.transcript.forEach(function (c) { bd.appendChild(h('div', { class: 'tline', html: '<small>' + esc(c.time || '') + '</small><b>' + esc(c.name) + '</b> ' + esc(c.text) })); });
    ft.appendChild(h('button', { class: 'btn', onclick: function () {
      var txt = S.transcript.map(function (c) { return '[' + (c.time || '') + '] ' + c.name + ' : ' + c.text; }).join('\n');
      var a = h('a', { href: URL.createObjectURL(new Blob([txt], { type: 'text/plain' })), download: 'transcription.txt' }); document.body.appendChild(a); a.click(); a.remove();
    } }, 'Télécharger la transcription'));
  }

  async function devicesDialog() {
    var list = await navigator.mediaDevices.enumerateDevices().catch(function () { return []; });
    function sel(kind, cur) { return h('select', null, list.filter(function (d) { return d.kind === kind; }).map(function (d, i) { return h('option', { value: d.deviceId, selected: d.deviceId === cur || (!cur && i === 0) ? true : null }, d.label || (kind + ' ' + (i + 1))); })); }
    var a = sel('audioinput', L.mic && L.mic.getSettings().deviceId), v = sel('videoinput', L.cam && L.cam.getSettings().deviceId), o = sel('audiooutput', L.devices.out);
    var hasOut = 'setSinkId' in HTMLMediaElement.prototype;
    modal('Appareils', h('div', null, h('label', { text: 'Micro' }), a, h('label', { text: 'Caméra' }), v, hasOut ? [h('label', { text: 'Haut-parleur' }), o] : null),
      [{ label: 'Annuler' }, { label: 'Appliquer', primary: true, fn: async function () {
        L.devices.audio = a.value || null; L.devices.video = v.value || null; L.devices.out = hasOut ? (o.value || null) : null;
        if (L.mic) { L.mic.stop(); L.mic = null; if (L.micOn) await getMic().catch(function () {}); }
        if (L.cam) { L.cam.stop(); L.cam = null; if (L.camOn) await getCam().catch(function () {}); }
        if (preVideo && !S.joined) preVideo.srcObject = L.cam ? new MediaStream([L.cam]) : null;
        for (var k in S.remote) if (S.remote[k].audioEl && L.devices.out && S.remote[k].audioEl.setSinkId) S.remote[k].audioEl.setSinkId(L.devices.out).catch(function () {});
        if (Rec.on && L.mic) Rec.addAudio('me', L.mic);
        await publishLocal(); scheduleRender();
      } }]);
  }

  window.addEventListener('resize', scheduleRender);
  if (!navigator.mediaDevices || !window.RTCPeerConnection) { fatal('Ce navigateur ne permet pas la visioconférence. Utilisez Chrome, Edge, Firefox ou Safari récents.'); return; }
  start();
})();
