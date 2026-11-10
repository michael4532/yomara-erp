<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#0B1220">
<title>{{ $m->title }} · {{ $c->name }}</title>
<link rel="stylesheet" href="{{ asset('vendor/visio/room.css') }}?v={{ $ver }}">
</head>
<body>
<div id="app" class="boot"><div class="spinner"></div></div>
<script>
window.VISIO = {
  code: @json($m->code),
  token: @json($token),
  name: @json($name),
  api: @json(url('/api/visio-room/'.$m->code)),
  base: @json(url('/')),
  sdk: { agora: @json(asset('vendor/visio/agora-4.24.9.js')), zego: @json(asset('vendor/visio/zego-3.13.0.js')) },
  embedded: window.self !== window.top
};
</script>
<div id="get-app" class="getapp hidden" role="dialog" aria-labelledby="ga-title">
  <div class="ga-card card">
    <img class="ga-logo" src="{{ url('/brand/mark-512.png') }}" alt="" width="56" height="56">
    <p class="ga-meet">{{ $m->title }} · {{ $c->name }}</p>
    <h1 id="ga-title">Rejoindre avec l'application Yomara</h1>
    <p class="ga-text" id="ga-text">Vos réunions, votre agenda et vos messages au même endroit, avec les notifications sur votre appareil.</p>
    <a class="ga-btn primary hidden" id="ga-open" href="#">Ouvrir dans l'application</a>
    <a class="ga-btn" id="ga-store" href="#" target="_blank" rel="noopener"></a>
    <button class="ga-link" id="ga-web" type="button">Continuer dans le navigateur</button>
  </div>
</div>
<style>
.getapp { position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; padding: 20px 16px; background: var(--bg, #070B16); }
.getapp.hidden { display: none; }
.ga-card { width: 100%; max-width: 420px; padding: 30px 26px 22px; text-align: center; }
.ga-logo { border-radius: 14px; display: block; margin: 0 auto 16px; }
.ga-meet { margin: 0 0 6px; color: var(--muted, #93A1BC); font-size: 13px; }
.getapp h1 { margin: 0 0 10px; font-size: 22px; line-height: 1.25; }
.ga-text { margin: 0 0 22px; color: var(--muted, #93A1BC); line-height: 1.5; }
.ga-btn { display: block; padding: 14px 16px; border-radius: 14px; margin-bottom: 10px; font-weight: 700; text-decoration: none; color: var(--text, #EEF2FA); background: rgba(255, 255, 255, .07); border: 1px solid var(--line, rgba(255, 255, 255, .09)); }
.ga-btn.primary { background: var(--accent, #4F7DFF); border-color: transparent; color: #fff; }
.ga-btn:focus-visible, .ga-link:focus-visible { outline: 3px solid rgba(79, 125, 255, .6); outline-offset: 2px; }
.ga-link { margin-top: 6px; background: none; border: 0; padding: 10px; color: #9DB6FF; font-weight: 600; cursor: pointer; }
</style>
<script>
(function () {
  var V = window.VISIO, room = @json(asset('vendor/visio/room.js').'?v='.$ver);
  var stores = @json($stores), deeplinks = @json($deeplinks);
  function start() { var s = document.createElement('script'); s.src = room; document.body.appendChild(s); }
  var q = new URLSearchParams(location.search), seen = false;
  try { seen = sessionStorage.getItem('visio_web_' + V.code) === '1'; } catch (e) {}
  var ua = navigator.userAgent;
  var os = /Android/i.test(ua) ? 'android' : (/iPhone|iPad|iPod/i.test(ua) || (/Macintosh/i.test(ua) && navigator.maxTouchPoints > 1)) ? 'ios'
    : /Windows/i.test(ua) ? 'windows' : /Macintosh|Mac OS X/i.test(ua) ? 'mac' : 'other';
  var store = { android: [stores.android_store, 'Télécharger sur Google Play'], ios: [stores.ios, 'Télécharger sur l\'App Store'],
    windows: [stores.windows_store, 'Télécharger sur le Microsoft Store'], mac: [stores.mac_store, 'Télécharger sur le Mac App Store'] }[os];
  // Déjà dans l'application, lien ouvert par l'application elle-même, ou choix déjà fait : directement la salle
  if (V.embedded || q.has('app') || q.has('web') || seen || !store || !store[0]) return start();

  var box = document.getElementById('get-app'), open = document.getElementById('ga-open'), st = document.getElementById('ga-store');
  st.href = store[0]; st.textContent = store[1];
  if (deeplinks) {
    var path = 'visio/' + V.code + '?s=' + encodeURIComponent(V.base) + (V.token ? '&t=' + encodeURIComponent(V.token) : '');
    open.classList.remove('hidden');
    open.href = os === 'android'
      ? 'intent://' + path + '#Intent;scheme=yomara;package=com.yomaratechnologies.yomara_erp;S.browser_fallback_url=' + encodeURIComponent(store[0]) + ';end'
      : 'yomara://' + path;
    open.onclick = function () {
      // Application absente : la page reste affichée, on met en avant le téléchargement
      setTimeout(function () { if (!document.hidden) { document.getElementById('ga-text').textContent = 'L\'application ne s\'est pas ouverte ? Installez-la, puis rouvrez le lien de la réunion.'; st.classList.add('primary'); } }, 1800);
    };
  } else {
    st.classList.add('primary');
  }
  box.classList.remove('hidden');
  document.getElementById('ga-web').onclick = function () {
    try { sessionStorage.setItem('visio_web_' + V.code, '1'); } catch (e) {}
    box.classList.add('hidden'); start();
  };
})();
</script>
</body>
</html>
