<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', $appName.' — le logiciel de gestion des entreprises d\'Afrique de l\'Ouest')</title>
<meta name="description" content="@yield('description', $appName.' : ventes, caisse, stock, comptabilité SYSCOHADA, paie, CRM, messagerie et visio dans un seul logiciel. En ligne ou installé sur Windows, Mac, Linux, Android et iPhone, même sans connexion.')">
<meta name="theme-color" content="#0A1633">
<link rel="icon" type="image/svg+xml" href="/brand/mark.svg">
<link rel="apple-touch-icon" href="/brand/mark-512.png">
<meta property="og:title" content="{{ $appName }}">
<meta property="og:description" content="Le logiciel de gestion complet, même sans connexion.">
<meta property="og:image" content="{{ url('/landing/tableau-de-bord.webp') }}">
<link rel="preload" href="/fonts/bricolage.woff2" as="font" type="font/woff2" crossorigin>
<style>
@font-face{font-family:"Bricolage Grotesque";src:url(/fonts/bricolage.woff2) format("woff2-variations"),url(/fonts/bricolage.woff2) format("woff2");font-weight:200 800;font-display:swap}
@font-face{font-family:"Instrument Sans";src:url(/fonts/instrument-400.woff2) format("woff2");font-weight:400;font-display:swap}
@font-face{font-family:"Instrument Sans";src:url(/fonts/instrument-500.woff2) format("woff2");font-weight:500;font-display:swap}
@font-face{font-family:"Instrument Sans";src:url(/fonts/instrument-600.woff2) format("woff2");font-weight:600;font-display:swap}
@font-face{font-family:"Instrument Sans";src:url(/fonts/instrument-700.woff2) format("woff2");font-weight:700;font-display:swap}
:root{
  --night:#0A1633;--night2:#13244F;--gold:#FFC531;--amber:#F5A000;--chalk:#F3F5F9;--ink:#111A2E;--ink2:#3A4560;--mute:#6B7690;
  --line:#E2E6EF;--card:#fff;--bg:var(--chalk);--blue:#2E6BE6;--green:#12B76A;
  --shadow:0 1px 2px rgba(10,22,51,.06),0 18px 40px -22px rgba(10,22,51,.35);
  --display:"Bricolage Grotesque","Instrument Sans",system-ui,sans-serif;
}
*{box-sizing:border-box}
html{scroll-behavior:smooth;overflow-x:clip}
@media (prefers-reduced-motion:reduce){html{scroll-behavior:auto}*{animation:none!important;transition:none!important}}
body{margin:0;font-family:"Instrument Sans",system-ui,-apple-system,"Segoe UI",sans-serif;font-size:16.5px;line-height:1.6;color:var(--ink);background:var(--chalk);-webkit-font-smoothing:antialiased;overflow-x:clip}
a{color:inherit;text-decoration:none}
img{max-width:100%;height:auto;display:block}
:focus-visible{outline:3px solid var(--gold);outline-offset:3px;border-radius:6px}
.wrap{max-width:1200px;margin:0 auto;padding:0 24px}
h1,h2,h3{font-family:var(--display);letter-spacing:-.02em;line-height:1.05;margin:0}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:9px;padding:14px 24px;border-radius:999px;font:600 15.5px/1 "Instrument Sans",sans-serif;border:1.5px solid transparent;cursor:pointer;transition:transform .15s,background .15s,border-color .15s,color .15s;white-space:nowrap}
.btn:active{transform:translateY(1px)}
.btn-primary,.btn-gold{background:var(--gold);color:var(--night)}
.btn-primary:hover,.btn-gold:hover{background:#FFD45C}
.btn-ghost{background:transparent;border-color:var(--line);color:var(--ink)}
.btn-ghost:hover{border-color:var(--ink)}
.on-dark .btn-ghost,.phead .btn-ghost{border-color:rgba(255,255,255,.28);color:#fff}
.on-dark .btn-ghost:hover,.phead .btn-ghost:hover{border-color:#fff}
.btn-sm{padding:10px 18px;font-size:14.5px}
.btn-white{background:#fff;color:var(--night)}
.offer-bar{display:block;text-align:center;background:linear-gradient(90deg,#F5A000,#FFC531,#F5A000);background-size:200% 100%;animation:shine 6s linear infinite;color:var(--night);font-size:14.5px;padding:9px 16px;font-weight:500}
.offer-bar u{font-weight:700;margin-left:6px}
.offer-bar .spark{display:inline-block;animation:wig 2.4s ease-in-out infinite}
@keyframes shine{to{background-position:-200% 0}}
@keyframes wig{0%,80%,100%{transform:none}85%{transform:rotate(-14deg)}90%{transform:rotate(12deg)}95%{transform:rotate(-6deg)}}
/* Navigation */
.nav{position:sticky;top:0;z-index:50;background:rgba(10,22,51,.86);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);color:#fff;border-bottom:1px solid rgba(255,255,255,.08)}
.nav .wrap{display:flex;align-items:center;gap:28px;height:70px}
.brand{display:flex;align-items:center;gap:11px;font:700 19px/1 var(--display);letter-spacing:-.01em;white-space:nowrap}
.brand img{width:36px;height:36px}
.nav-links{display:flex;gap:24px;font-weight:500;font-size:15px;color:#C9D3EA;white-space:nowrap}
.nav-links a:hover{color:#fff}
.nav-links a{position:relative}
.nav-links a.on{color:#fff}
.nav-links a.on:after{content:"";position:absolute;left:0;right:0;bottom:-24px;height:3px;border-radius:3px;background:var(--gold)}
@media (max-width:880px){.nav-links a.on:after{display:none}.nav-links a.on{color:var(--gold)}}
.nav-cta{margin-left:auto;display:flex;gap:10px;align-items:center}
.nav-cta .login{color:#C9D3EA;font-weight:600;font-size:15px;padding:0 6px}
.nav-cta .login:hover{color:#fff}
.lang{display:flex;border:1px solid rgba(255,255,255,.18);border-radius:999px;padding:3px;gap:2px}
.lang a{font:700 12px "Instrument Sans",sans-serif;letter-spacing:.04em;color:#AEB9D3;padding:5px 9px;border-radius:999px}
.lang a.on{background:rgba(255,255,255,.14);color:#fff}
.lang a:hover{color:#fff}
.burger{display:none;background:none;border:0;color:#fff;font-size:26px;cursor:pointer;padding:4px}
.nav-links .m{display:none}
@media (max-width:1080px){.nav-links .opt{display:none}}
@media (max-width:880px){
  .nav-links,.nav-cta .login{display:none}
  .nav.open .nav-links{display:flex;position:absolute;top:70px;left:0;right:0;flex-direction:column;background:var(--night);padding:18px 24px 24px;gap:16px;border-bottom:1px solid rgba(255,255,255,.1)}
  .nav.open .nav-links .opt,.nav.open .nav-links .m{display:block}
  .burger{display:block}
}
@media (max-width:460px){.brand span{display:none}.nav .wrap{gap:12px}.nav-cta .btn{padding:10px 14px;font-size:13.5px}}
/* Pied de page */
footer{background:var(--night);color:#AEB9D3;padding:64px 0 30px;font-size:14.5px}
footer .cols{display:grid;grid-template-columns:1.6fr 1fr 1fr 1.3fr;gap:36px}
footer h4{color:#fff;margin:0 0 14px;font:600 15px "Instrument Sans",sans-serif}
footer a{display:block;margin:7px 0}
footer a:hover{color:#fff}
footer .legal{border-top:1px solid rgba(255,255,255,.1);margin-top:44px;padding-top:22px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;color:#7F8BA8;font-size:13.5px}
@media (max-width:820px){footer .cols{grid-template-columns:1fr 1fr}}
@media (max-width:520px){footer .cols{grid-template-columns:1fr}footer a{overflow-wrap:anywhere}}
/* WhatsApp + live chat */
.fab{position:fixed;right:20px;bottom:20px;z-index:80;display:flex;flex-direction:column;gap:12px;align-items:flex-end}
.fab-btn{width:58px;height:58px;border-radius:50%;border:0;cursor:pointer;display:grid;place-items:center;box-shadow:0 14px 30px -10px rgba(10,22,51,.5);transition:transform .2s;position:relative}
.fab-btn:hover{transform:scale(1.07)}
.fab-wa{background:#25D366;color:#fff}
.fab-chat{background:var(--night);color:var(--gold)}
.fab-chat .badge{position:absolute;top:-3px;right:-3px;min-width:20px;height:20px;border-radius:10px;background:#E5484D;color:#fff;font:700 12px/20px "Instrument Sans",sans-serif;padding:0 6px;display:none}
.fab-hint{background:#fff;color:var(--ink);border-radius:14px 14px 4px 14px;padding:10px 14px;font-size:14px;font-weight:600;box-shadow:var(--shadow);max-width:240px;animation:hintIn .5s ease both 2.5s}
@keyframes hintIn{from{opacity:0;transform:translateY(10px)}}
.lc{position:fixed;right:20px;bottom:92px;z-index:81;width:min(370px,calc(100vw - 32px));height:min(540px,calc(100vh - 120px));background:#fff;border-radius:20px;box-shadow:0 30px 80px -20px rgba(10,22,51,.55);display:flex;flex-direction:column;overflow:hidden;transform-origin:bottom right;transition:transform .25s,opacity .25s}
.lc[hidden]{display:flex!important;opacity:0;transform:scale(.85) translateY(20px);pointer-events:none}
.lc-head{background:var(--night);color:#fff;padding:16px 18px;display:flex;gap:12px;align-items:center}
.lc-head img{width:38px;height:38px}
.lc-head b{display:block;font-size:15.5px}.lc-head small{color:#AEB9D3;font-size:12.5px;display:flex;align-items:center;gap:6px}
.lc-head small i{width:8px;height:8px;border-radius:50%;background:#7F8BA8}.lc-head small i.on{background:#12B76A;box-shadow:0 0 0 4px rgba(18,183,106,.2)}
.lc-head button{margin-left:auto;background:none;border:0;color:#fff;font-size:22px;cursor:pointer}
.lc-body{flex:1;overflow:auto;padding:16px;background:var(--chalk);display:flex;flex-direction:column;gap:8px}
.lc-msg{max-width:82%;padding:9px 13px;border-radius:16px;font-size:14.5px;line-height:1.45;white-space:pre-wrap;word-wrap:break-word;animation:msgIn .25s ease both}
@keyframes msgIn{from{opacity:0;transform:translateY(6px)}}
.lc-msg.admin{background:#fff;border:1px solid var(--line);align-self:flex-start;border-bottom-left-radius:4px}
.lc-msg.visitor{background:var(--night);color:#fff;align-self:flex-end;border-bottom-right-radius:4px}
.lc-msg time{display:block;font-size:11px;opacity:.6;margin-top:3px}
.lc-intro{display:grid;gap:8px;padding:12px 16px;border-top:1px solid var(--line);background:#fff}
.lc-intro input{padding:9px 12px;border:1.5px solid var(--line);border-radius:10px;font:inherit;font-size:14px}
.lc-form{display:flex;gap:8px;padding:12px;border-top:1px solid var(--line);background:#fff}
.lc-form textarea{flex:1;resize:none;border:1.5px solid var(--line);border-radius:12px;padding:10px 12px;font:inherit;font-size:14.5px;max-height:110px}
.lc-form button{width:44px;border:0;border-radius:12px;background:var(--gold);color:var(--night);cursor:pointer;font-size:18px}
.lc-alt{font-size:12.5px;color:var(--mute);text-align:center;padding:0 12px 10px;background:#fff}
.lc-alt a{color:#128C7E;font-weight:700}

@include('landing._css')
/* ---------- En-tête des pages intérieures ---------- */
.phead{background:var(--night);color:#fff;position:relative;overflow:hidden;padding:76px 0 64px}
.phead:before,.phead:after{content:"";position:absolute;border-radius:50%;pointer-events:none;filter:blur(2px)}
.phead:before{width:620px;height:620px;right:-180px;top:-300px;background:radial-gradient(closest-side,rgba(255,197,49,.24),transparent 70%);animation:drift 14s ease-in-out infinite alternate}
.phead:after{width:480px;height:480px;left:-200px;bottom:-320px;background:radial-gradient(closest-side,rgba(46,107,230,.35),transparent 70%);animation:drift 18s ease-in-out infinite alternate-reverse}
@keyframes drift{to{transform:translate(-60px,40px) scale(1.08)}}
.phead .wrap{position:relative;z-index:1}
.phead .crumb{color:#AEB9D3;font-size:14px;font-weight:600;margin-bottom:16px;display:flex;gap:8px}
.phead .crumb a:hover{color:#fff}
.phead h1{font-size:clamp(40px,6vw,76px);font-weight:800;line-height:.98;max-width:16ch;animation:rise .8s cubic-bezier(.2,.8,.2,1) both}
.phead h1 em{font-style:normal;color:var(--gold)}
.phead p{color:#C9D3EA;font-size:19px;max-width:58ch;margin:20px 0 0;animation:rise .8s cubic-bezier(.2,.8,.2,1) both .12s}
.phead .hero-cta{margin-top:28px;animation:rise .8s cubic-bezier(.2,.8,.2,1) both .24s}
</style>
@stack('styles')
</head>
<body>
@if(!empty($trialOffer['promo_active']))
<a class="offer-bar" href="/inscription"><span class="spark">🎁</span> <b>{{ $trialOffer['promo_label'] ?: 'Offre spéciale' }}</b> : {{ $trialOffer['promo_days'] }} jours d'essai gratuit @if($trialOffer['promo_until'])pour toute inscription jusqu'au {{ \Illuminate\Support\Carbon::parse($trialOffer['promo_until'])->format('d/m/Y') }}@endif <u>J'en profite</u></a>
@endif
<header class="nav" id="nav">
  <div class="wrap">
    <a class="brand" href="/"><img src="/brand/mark.svg" alt=""><span>{{ $appName }}</span></a>
    <nav class="nav-links" aria-label="Navigation principale">
      @php $here = '/'.trim(request()->path(), '/'); @endphp
      <a href="/modules" @class(['on' => $here === '/modules'])>Modules</a>
      <a href="/tarifs" @class(['on' => $here === '/tarifs'])>Tarifs</a>
      <a href="/telecharger" @class(['on' => $here === '/telecharger'])>Télécharger</a>
      <a href="/contact" @class(['on' => $here === '/contact'])>Contact</a>
      <a href="/app/" class="m">Se connecter</a>
    </nav>
    <div class="nav-cta">
      @php $loc = app()->getLocale(); @endphp
      <div class="lang" role="group" aria-label="Langue">
        @foreach (['fr' => 'FR', 'en' => 'EN'] as $code => $lbl)
          <a href="?lang={{ $code }}" hreflang="{{ $code }}" lang="{{ $code }}" class="{{ $loc === $code ? 'on' : '' }}" title="{{ $code === 'fr' ? 'Français' : 'English' }}">{{ $lbl }}</a>
        @endforeach
      </div>
      <a class="login" href="/app/">Se connecter</a>
      <a class="btn btn-gold btn-sm" href="/inscription">Créer mon entreprise</a>
    </div>
    <button class="burger" aria-label="Ouvrir le menu" aria-expanded="false" onclick="const n=document.getElementById('nav');n.classList.toggle('open');this.setAttribute('aria-expanded',n.classList.contains('open'))">☰</button>
  </div>
</header>

@yield('content')

<footer>
  <div class="wrap">
    <div class="cols">
      <div>
        <div class="brand" style="color:#fff"><img src="/brand/mark.svg" alt=""> {{ $appName }}</div>
        <p style="max-width:330px;margin-top:16px">Le logiciel de gestion des entreprises d'Afrique de l'Ouest, en ligne, sur ordinateur et sur mobile, même sans connexion.</p>
      </div>
      <div>
        <h4>Produit</h4>
        <a href="/#visite">Le logiciel</a>
        <a href="/modules">Modules</a>
        <a href="/tarifs">Tarifs</a>
        <a href="/telecharger">Télécharger</a>
        <a href="/telecharger#licence">Installer sur votre serveur</a>
        <a href="/telecharger/source" rel="nofollow">Code source (licence AGPL)</a>
      </div>
      <div>
        <h4>Compte</h4>
        <a href="/inscription">Créer mon entreprise</a>
        <a href="/app/">Se connecter</a>
        <a href="/contact">Demander un module sur mesure</a>
        <a href="/contact#faq">Questions fréquentes</a>
        <a href="/confidentialite">Confidentialité</a>
        <a href="/suppression-compte">Supprimer mon compte</a>
      </div>
      <div>
        <h4>Nous joindre</h4>
        @if($contact['phone'])<a href="tel:{{ preg_replace('/\s+/', '', $contact['phone']) }}">{{ $contact['phone'] }}</a>@endif
        @if($contact['whatsapp'])<a href="https://wa.me/{{ preg_replace('/\D+/', '', $contact['whatsapp']) }}" target="_blank" rel="noopener">WhatsApp {{ $contact['whatsapp'] }}</a>@endif
        @if($contact['email'])<a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a>@endif
        @if($contact['address'])<span style="display:block;margin:7px 0">{{ $contact['address'] }}</span>@endif
        @if($contact['hours'])<span style="display:block;color:#7F8BA8">{{ $contact['hours'] }}</span>@endif
        @if(! $contact['phone'] && ! $contact['whatsapp'] && ! $contact['email'] && ! $contact['address'])<a href="/contact">Écrire à notre équipe</a>@endif
      </div>
    </div>
    <div class="legal"><span>© {{ date('Y') }} {{ $contact['name'] ?: 'Yomara Technologies' }}. Tous droits réservés.</span><span>{{ $appName }} — version {{ config('erp.version') }} · <a href="?lang=fr" style="display:inline;margin:0">Français</a> · <a href="?lang=en" style="display:inline;margin:0">English</a></span></div>
  </div>
</footer>
@php $wa = $contact['whatsapp'] ? preg_replace('/\D+/', '', $contact['whatsapp']) : null; @endphp
<div class="fab">
  @if($chatEnabled ?? true)<div class="fab-hint" id="fabHint" hidden>Une question ? On vous répond ici.</div>@endif
  @if($wa)<a class="fab-btn fab-wa" href="https://wa.me/{{ $wa }}?text={{ rawurlencode('Bonjour, je souhaite des informations sur '.$appName.'.') }}" target="_blank" rel="noopener" aria-label="Nous écrire sur WhatsApp"><svg width="30" height="30" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M17.5 14.4c-.3-.1-1.8-.9-2-1s-.5-.1-.7.1-.8 1-1 1.2-.4.2-.7.1a8.2 8.2 0 0 1-4-3.5c-.3-.5.3-.5.9-1.6.1-.2 0-.4 0-.5l-.9-2.2c-.2-.6-.5-.5-.7-.5h-.6a1.2 1.2 0 0 0-.8.4 3.5 3.5 0 0 0-1.1 2.6 6 6 0 0 0 1.3 3.2 13.8 13.8 0 0 0 5.3 4.7c2 .9 2.8.9 3.8.8.6-.1 1.8-.8 2.1-1.5s.3-1.3.2-1.5-.3-.2-.6-.3ZM12 21.8a9.8 9.8 0 0 1-5-1.4l-.4-.2-3.7 1 1-3.6-.2-.4A9.8 9.8 0 1 1 12 21.8Zm0-21.6a11.8 11.8 0 0 0-10.1 17.8L.2 24l6.1-1.6A11.8 11.8 0 1 0 12 .2Z"/></svg></a>@endif
  @if($chatEnabled ?? true)<button class="fab-btn fab-chat" id="lcOpen" aria-label="Discuter en direct avec nous" aria-expanded="false"><svg width="28" height="28" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M4 3h16a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H9l-5 4v-4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm3 6.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Zm5 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Zm5 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Z"/></svg><span class="badge" id="lcBadge">0</span></button>@endif
</div>
@if($chatEnabled ?? true)
<section class="lc" id="lc" hidden aria-label="Discussion en direct">
  <div class="lc-head"><img src="/brand/mark.svg" alt=""><div><b>{{ $contact['name'] ?: $appName }}</b><small><i id="lcDot"></i><span id="lcState">Nous répondons en général en quelques minutes</span></small></div><button id="lcClose" aria-label="Fermer">×</button></div>
  <div class="lc-body" id="lcBody"><div class="lc-msg admin">{{ $chatWelcome ?? '' }}</div></div>
  <div class="lc-intro" id="lcIntro"><input id="lcName" placeholder="Votre nom" maxlength="120" autocomplete="name"><input id="lcContact" placeholder="E-mail ou WhatsApp (pour vous recontacter)" maxlength="160"></div>
  <form class="lc-form" id="lcForm"><textarea id="lcText" rows="1" placeholder="Écrivez votre message…" maxlength="4000" required></textarea><button type="submit" aria-label="Envoyer">➤</button></form>
  @if($wa)<div class="lc-alt">Vous préférez WhatsApp ? <a href="https://wa.me/{{ $wa }}" target="_blank" rel="noopener">Écrivez-nous ici</a></div>@endif
</section>
<script>
(() => {
  const $ = id => document.getElementById(id), box = $('lc'), body = $('lcBody'), badge = $('lcBadge');
  const store = { get: k => { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) {} } };
  let token = store.get('ymr_chat'), last = 0, timer = null, open = false;
  const esc = t => t.replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
  const add = m => { if (m.id <= last) return; last = m.id; const d = document.createElement('div'); d.className = 'lc-msg ' + (m.from === 'admin' ? 'admin' : 'visitor'); d.innerHTML = esc(m.body) + '<time>' + new Date(m.at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) + '</time>'; body.appendChild(d); body.scrollTop = body.scrollHeight; if (!open && m.from === 'admin') { badge.style.display = 'block'; badge.textContent = (+badge.textContent || 0) + 1; } };
  const api = (path, opt) => fetch('/api/livechat' + path, Object.assign({ headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' } }, opt)).then(r => r.ok ? r.json() : Promise.reject(r));
  const poll = () => { if (!token) return; api('/' + token + '?after=' + last).then(d => { (d.messages || []).forEach(add); $('lcDot').classList.toggle('on', !!d.online); $('lcState').textContent = d.online ? 'En ligne : nous vous répondons tout de suite' : 'Nous répondons en général en quelques minutes'; }).catch(() => {}); };
  const schedule = () => { clearInterval(timer); if (token) timer = setInterval(poll, open ? 4000 : 25000); };
  const toggle = v => { open = v; box.hidden = !v; $('lcOpen').setAttribute('aria-expanded', v); if (v) { badge.style.display = 'none'; badge.textContent = '0'; $('lcText').focus(); const h = $('fabHint'); if (h) h.hidden = true; poll(); } schedule(); };
  $('lcOpen').addEventListener('click', () => toggle(!open));
  $('lcClose').addEventListener('click', () => toggle(false));
  if (token) { $('lcIntro').style.display = 'none'; poll(); schedule(); } else { setTimeout(() => { const h = $('fabHint'); if (h && !open) h.hidden = false; }, 6000); }
  $('lcText').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('lcForm').requestSubmit(); } });
  $('lcForm').addEventListener('submit', e => {
    e.preventDefault(); const t = $('lcText').value.trim(); if (!t) return; $('lcText').value = '';
    if (!token) {
      const c = $('lcContact').value.trim(), isMail = c.includes('@');
      api('', { method: 'POST', body: JSON.stringify({ name: $('lcName').value.trim() || null, email: isMail ? c : null, phone: !isMail && c ? c : null, page: location.pathname, message: t }) })
        .then(d => { token = d.token; store.set('ymr_chat', token); $('lcIntro').style.display = 'none'; (d.messages || []).forEach(add); schedule(); })
        .catch(() => { $('lcText').value = t; });
    } else {
      api('/' + token, { method: 'POST', body: JSON.stringify({ message: t, after: last }) }).then(d => (d.messages || []).forEach(add)).catch(() => { $('lcText').value = t; });
    }
  });
})();
</script>
@endif
@include('landing._js')
@stack('scripts')
</body>
</html>
