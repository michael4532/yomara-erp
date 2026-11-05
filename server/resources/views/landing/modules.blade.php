@extends('landing.layout')
@section('title', 'Les modules de '.$appName.' : le rôle de chacun, expliqué')
@section('description', $moduleCount.' modules à activer selon vos besoins : ventes, caisse, stock, comptabilité, paie, CRM, marketing, réseaux sociaux… Découvrez le rôle de chaque module de '.$appName.'.')

@push('styles')
<style>
.phead .search{display:block;margin-top:30px;max-width:560px;position:relative;animation:rise .8s cubic-bezier(.2,.8,.2,1) both .3s}
.phead .search input{width:100%;padding:17px 20px 17px 52px;border-radius:999px;border:0;font:500 16.5px "Instrument Sans",sans-serif;color:var(--ink);box-shadow:0 20px 50px -20px rgba(0,0,0,.6)}
.phead .search input:focus{outline:3px solid var(--gold)}
.phead .search svg{position:absolute;left:19px;top:50%;transform:translateY(-50%);color:var(--mute)}
.core-strip{background:#fff;border-bottom:1px solid var(--line);padding:18px 0;font-size:14.5px;color:var(--ink2)}
.core-strip b{color:var(--ink)}
.core-strip span{display:inline-block;background:var(--chalk);border-radius:999px;padding:3px 11px;margin:3px 2px;font-weight:600;font-size:13.5px}

.explorer{display:grid;grid-template-columns:250px minmax(0,1fr);gap:40px;padding:56px 0 100px;align-items:start}
.cats-nav{position:sticky;top:96px;display:flex;flex-direction:column;gap:2px}
.cats-nav button{appearance:none;border:0;background:none;text-align:left;padding:10px 14px;border-radius:12px;font:600 15px "Instrument Sans",sans-serif;color:var(--ink2);cursor:pointer;display:flex;justify-content:space-between;gap:10px;transition:background .2s,color .2s}
.cats-nav button i{font-style:normal;color:var(--mute);font-weight:600;font-size:13px}
.cats-nav button:hover{background:#E9EDF5}
.cats-nav button[aria-pressed="true"]{background:var(--night);color:#fff}
.cats-nav button[aria-pressed="true"] i{color:var(--gold)}
.result-line{display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin-bottom:18px;color:var(--mute);font-size:14.5px}
.result-line b{color:var(--ink);font:700 22px/1.2 var(--display)}
.grid-m{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px}
.mcard{appearance:none;text-align:left;font:inherit;color:inherit;background:#fff;border:1px solid var(--line);border-radius:20px;padding:20px;cursor:pointer;display:flex;flex-direction:column;gap:12px;position:relative;overflow:hidden;
  transition:transform .25s cubic-bezier(.2,.8,.2,1),box-shadow .25s,border-color .25s;animation:cardIn .5s cubic-bezier(.2,.8,.2,1) both;animation-delay:calc(var(--i) * 25ms)}
@keyframes cardIn{from{opacity:0;transform:translateY(16px) scale(.98)}}
.mcard:hover{transform:translateY(-4px);box-shadow:var(--shadow);border-color:transparent}
.mcard:after{content:"";position:absolute;right:-40px;top:-40px;width:110px;height:110px;border-radius:50%;background:var(--c);opacity:.08;transition:transform .45s}
.mcard:hover:after{transform:scale(2.2)}
.mtile{width:46px;height:46px;border-radius:14px;background:var(--c);color:#fff;display:grid;place-items:center;font:800 17px var(--display);letter-spacing:-.02em;transition:transform .35s cubic-bezier(.2,.8,.2,1)}
.mcard:hover .mtile{transform:rotate(-8deg) scale(1.06)}
.mcard b{font:700 18px/1.2 var(--display)}
.mcard p{margin:0;color:var(--ink2);font-size:14.5px;line-height:1.5;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.mcard .meta{margin-top:auto;display:flex;gap:6px;flex-wrap:wrap}
.pill{font-size:12.5px;font-weight:700;border-radius:999px;padding:3px 10px;background:var(--chalk);color:var(--ink2)}
.pill.ok{background:#DCFAE6;color:#067647}
.pill.gold{background:#FFF3CC;color:#8A5B00}
.empty{padding:60px 20px;text-align:center;color:var(--mute)}
@media (max-width:900px){
  .explorer{grid-template-columns:1fr;gap:20px;padding-top:28px}
  .cats-nav{position:static;flex-direction:row;overflow-x:auto;gap:8px;padding-bottom:4px;scrollbar-width:none;margin:0 -24px;padding-left:24px;padding-right:24px}
  .cats-nav button{flex:0 0 auto;background:#fff;border:1px solid var(--line);border-radius:999px;padding:8px 14px}
}

/* Fiche du module */
.scrim{position:fixed;inset:0;background:rgba(10,22,51,.45);backdrop-filter:blur(3px);z-index:90;opacity:0;pointer-events:none;transition:opacity .3s}
.scrim.on{opacity:1;pointer-events:auto}
.sheet{position:fixed;top:0;right:0;bottom:0;width:min(560px,100vw);background:#fff;z-index:91;transform:translateX(105%);transition:transform .45s cubic-bezier(.2,.8,.2,1);display:flex;flex-direction:column;box-shadow:-30px 0 80px -30px rgba(10,22,51,.5)}
.sheet.on{transform:none}
.sheet-head{padding:26px 28px 22px;background:var(--night);color:#fff;position:relative;overflow:hidden}
.sheet-head:after{content:"";position:absolute;right:-80px;top:-80px;width:260px;height:260px;border-radius:50%;background:radial-gradient(closest-side,var(--c),transparent 70%);opacity:.55}
.sheet-head>*{position:relative;z-index:1}
.sheet-top{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:22px}
.sheet-top small{color:#AEB9D3;font-weight:700;font-size:13.5px}
.sheet-top .nav-btns{display:flex;gap:6px}
.sheet-top button{width:38px;height:38px;border-radius:50%;border:1px solid rgba(255,255,255,.25);background:transparent;color:#fff;cursor:pointer;font-size:18px;display:grid;place-items:center;transition:background .2s}
.sheet-top button:hover{background:rgba(255,255,255,.12)}
.sheet-head .mtile{width:58px;height:58px;font-size:21px;border-radius:18px;margin-bottom:16px;animation:pop2 .6s cubic-bezier(.2,.8,.2,1) both .1s}
@keyframes pop2{from{transform:scale(.4) rotate(-20deg);opacity:0}}
.sheet-head h2{font-size:clamp(28px,4vw,38px);font-weight:800}
.sheet-head p{color:#DCE3F2;font-size:17px;margin:12px 0 0;line-height:1.5}
.sheet-body{flex:1;overflow:auto;padding:26px 28px 30px}
.sheet-body h3{font:700 13.5px "Instrument Sans",sans-serif;color:var(--mute);margin:26px 0 10px}
.sheet-body h3:first-child{margin-top:0}
.sheet-body .long p{margin:0 0 12px;color:var(--ink2);line-height:1.65}
.feat{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:1fr 1fr;gap:8px}
.feat li{position:relative;padding:9px 12px 9px 36px;background:var(--chalk);border-radius:12px;font-size:14px;font-weight:500;animation:cardIn .4s both;animation-delay:calc(.15s + var(--i) * 30ms)}
.feat li:before{content:"";position:absolute;left:12px;top:13px;width:14px;height:14px;border-radius:50%;background:var(--c)}
.feat li:after{content:"";position:absolute;left:16.5px;top:16px;width:3px;height:6px;border:solid #fff;border-width:0 2px 2px 0;transform:rotate(45deg)}
@media (max-width:520px){.feat{grid-template-columns:1fr}}
.offer{display:grid;gap:10px;background:var(--chalk);border-radius:16px;padding:18px}
.offer .row{display:flex;justify-content:space-between;gap:12px;align-items:baseline;flex-wrap:wrap}
.offer .row b{font:800 22px var(--display)}
.offer .plans-in{display:flex;gap:6px;flex-wrap:wrap}
.sheet-foot{padding:16px 28px;border-top:1px solid var(--line);display:flex;gap:10px;flex-wrap:wrap}
.sheet-foot .btn{flex:1}
@media (max-width:700px){
  .sheet{top:auto;width:100vw;height:88vh;border-radius:24px 24px 0 0;transform:translateY(105%)}
  .sheet-head{border-radius:24px 24px 0 0}
  .sheet.on{transform:none}
}
</style>
@endpush

@section('content')
<section class="phead">
  <div class="wrap">
    <div class="crumb"><a href="/">Accueil</a><span>›</span><span>Modules</span></div>
    <h1>{{ $moduleCount }} modules. <em>Chacun a un rôle.</em></h1>
    <p>Touchez un module pour lire sa fiche : à quoi il sert, ce qu'il fait au quotidien, dans quelle formule il est inclus et combien il coûte seul.</p>
    <label class="search">
      <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M10 2a8 8 0 0 1 6.3 12.9l5.4 5.4-1.4 1.4-5.4-5.4A8 8 0 1 1 10 2Zm0 2a6 6 0 1 0 0 12 6 6 0 0 0 0-12Z"/></svg>
      <input id="q" type="search" placeholder="Rechercher : caisse, paie, stock, WhatsApp…" autocomplete="off" aria-label="Rechercher un module">
    </label>
  </div>
</section>

@if(count($core))
<div class="core-strip"><div class="wrap"><b>Toujours inclus, sans rien payer :</b> @foreach($core as $c)<span>{{ $c }}</span>@endforeach</div></div>
@endif

<div class="wrap explorer">
  <nav class="cats-nav" id="cats" aria-label="Catégories">
    <button type="button" data-cat="" aria-pressed="true">Tous les modules <i>{{ $moduleCount }}</i></button>
    @foreach($cats as $c => $n)<button type="button" data-cat="{{ $c }}" aria-pressed="false">{{ $c }} <i>{{ $n }}</i></button>@endforeach
  </nav>
  <div>
    <div class="result-line"><b id="title">Tous les modules</b><span id="count"></span></div>
    <div class="grid-m" id="grid" aria-live="polite"></div>
  </div>
</div>

<div class="scrim" id="scrim"></div>
<aside class="sheet" id="sheet" role="dialog" aria-modal="true" aria-labelledby="sName" aria-hidden="true">
  <div class="sheet-head">
    <div class="sheet-top"><small id="sCat"></small><div class="nav-btns"><button id="sPrev" aria-label="Module précédent">‹</button><button id="sNext" aria-label="Module suivant">›</button><button id="sClose" aria-label="Fermer">×</button></div></div>
    <div class="mtile" id="sTile"></div>
    <h2 id="sName"></h2>
    <p id="sTag"></p>
  </div>
  <div class="sheet-body" id="sBody"></div>
  <div class="sheet-foot">
    <a class="btn btn-gold" id="sTry" href="/inscription">Essayer gratuitement</a>
    <a class="btn btn-ghost" id="sAsk" href="/contact">Poser une question</a>
  </div>
</aside>

@include('landing._end')
@endsection

@push('scripts')
<script>
(() => {
  const MODS = @json($mods), TRIAL = {{ (int) $trialDays }}, CUR = @json($cur);
  @php
    $L = ['results' => 'Résultats', 'all' => 'Tous les modules', 'one' => '{} module', 'many' => '{} modules', 'incl1' => 'Inclus dans {} formule', 'incl' => 'Inclus dans {} formules',
      'free' => 'Gratuit', 'alone' => '{} / mois seul', 'none' => 'Aucun module ne correspond à « {} ».', 'ask' => 'Demandez-le sur mesure', 'what' => 'Ce qu\'il fait pour vous',
      'inplans' => 'Inclus dans les formules :', 'is' => 'Ce module est', 'orAlone' => 'Ou seul, en plus de votre formule', 'price' => 'Prix du module', 'month' => '/ mois',
      'quote' => 'Sur devis', 'trial' => '{} jours d\'essai gratuit à l\'inscription, sans carte bancaire.'];
    $L = array_map(fn ($v) => \App\Support\Tr::exactOrSelf($v), $L);
  @endphp
  const L = @json($L), fill = (s, v) => s.replace('{}', v);
  const palette = ['#2E6BE6', '#F5A000', '#12B76A', '#9333EA', '#E5484D', '#0EA5E9', '#D946EF', '#0F766E', '#B45309', '#64748B'];
  const cats = [...new Set(MODS.map(m => m.category))];
  const color = c => palette[cats.indexOf(c) % palette.length];
  const initials = n => n.replace(/[«»&()]/g, ' ').split(/[\s\-']+/).filter(w => w.length > 2 || /^[A-Z]{2,}$/.test(w)).slice(0, 2).map(w => w[0]).join('').toUpperCase() || n.slice(0, 2).toUpperCase();
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const money = n => Math.round(n).toLocaleString('fr-FR').replace(/ /g, ' ');
  const norm = s => s.normalize('NFD').replace(/\p{Mn}/gu, '').toLowerCase();
  const $ = id => document.getElementById(id);
  let cat = '', q = '', shown = [], current = -1;

  const params = new URLSearchParams(location.search), hash = new URLSearchParams(location.hash.slice(1));
  q = params.get('q') || ''; cat = hash.get('categorie') || '';
  $('q').value = q;

  function render() {
    const words = norm(q).split(/\s+/).filter(Boolean);
    shown = MODS.filter(m => (!cat || m.category === cat) && words.every(w => norm(m.name + ' ' + m.tagline + ' ' + m.description + ' ' + m.features.join(' ') + ' ' + m.category).includes(w)));
    $('title').textContent = cat || (q ? L.results : L.all);
    $('count').textContent = fill(shown.length > 1 ? L.many : L.one, shown.length);
    document.querySelectorAll('#cats button').forEach(b => b.setAttribute('aria-pressed', b.dataset.cat === cat ? 'true' : 'false'));
    $('grid').innerHTML = shown.length ? shown.map((m, i) => `
      <button class="mcard" type="button" data-i="${i}" style="--c:${color(m.category)};--i:${Math.min(i, 24)}">
        <span class="mtile">${esc(initials(m.name))}</span>
        <b>${esc(m.name)}</b>
        <p>${esc(m.tagline)}</p>
        <span class="meta">
          ${m.plans.length ? `<span class="pill ok">${fill(m.plans.length > 1 ? L.incl : L.incl1, m.plans.length)}</span>` : ''}
          ${m.free ? `<span class="pill gold">${L.free}</span>` : (m.price ? `<span class="pill">${fill(L.alone, money(m.price) + ' ' + CUR)}</span>` : '')}
        </span>
      </button>`).join('') : `<div class="empty">${esc(fill(L.none, q))} <a href="/contact" style="color:var(--blue);font-weight:700">${L.ask}</a>.</div>`;
  }

  function open(i) {
    const m = shown[i]; if (!m) return; current = i;
    const c = color(m.category), sheet = $('sheet');
    sheet.style.setProperty('--c', c);
    $('sCat').textContent = m.category;
    const tile = $('sTile'); tile.textContent = initials(m.name); tile.style.background = c; tile.style.animation = 'none'; tile.offsetWidth; tile.style.animation = '';
    $('sName').textContent = m.name; $('sTag').textContent = m.tagline;
    const paras = (m.long || m.description).split(/\n\s*\n/).filter(Boolean);
    $('sBody').innerHTML = `
      <h3>À quoi sert ce module</h3>
      <div class="long">${paras.map(p => `<p>${esc(p)}</p>`).join('')}</div>
      ${m.features.length ? `<h3>${L.what}</h3><ul class="feat">${m.features.map((f, k) => `<li style="--i:${k}">${esc(f)}</li>`).join('')}</ul>` : ''}
      <h3>Comment l'obtenir</h3>
      <div class="offer">
        ${m.plans.length ? `<div><div style="font-size:14px;color:var(--ink2);margin-bottom:8px">${L.inplans}</div><div class="plans-in">${m.plans.map(p => `<span class="pill ok">${esc(p)}</span>`).join('')}</div></div>` : ''}
        <div class="row"><span style="color:var(--ink2)">${m.free ? L.is : (m.plans.length ? L.orAlone : L.price)}</span><b>${m.free ? L.free : (m.price ? money(m.price) + ' ' + CUR + ' <small style="font:600 13px Instrument Sans;color:var(--mute)">' + L.month + '</small>' : L.quote)}</b></div>
        ${TRIAL > 0 && !m.free ? `<div style="font-size:13.5px;color:var(--mute)">${fill(L.trial, TRIAL)}</div>` : ''}
      </div>`;
    $('sBody').scrollTop = 0;
    $('sTry').href = '/inscription?module=' + encodeURIComponent(m.key);
    $('sAsk').href = '/contact?module=' + encodeURIComponent(m.name) + '#formulaire';
    $('sPrev').disabled = i === 0; $('sNext').disabled = i === shown.length - 1;
    sheet.classList.add('on'); $('scrim').classList.add('on'); sheet.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    history.replaceState(null, '', location.pathname + location.search + '#module=' + m.key);
    setTimeout(() => $('sClose').focus(), 50);
  }
  function close() {
    $('sheet').classList.remove('on'); $('scrim').classList.remove('on'); $('sheet').setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    history.replaceState(null, '', location.pathname + location.search + (cat ? '#categorie=' + encodeURIComponent(cat) : ''));
    const card = document.querySelector(`.mcard[data-i="${current}"]`); if (card) card.focus();
  }

  $('grid').addEventListener('click', e => { const b = e.target.closest('.mcard'); if (b) open(+b.dataset.i); });
  $('cats').addEventListener('click', e => { const b = e.target.closest('button'); if (!b) return; cat = b.dataset.cat; history.replaceState(null, '', location.pathname + location.search + (cat ? '#categorie=' + encodeURIComponent(cat) : '')); render(); if (innerWidth < 900) $('grid').scrollIntoView({ behavior: 'smooth', block: 'start' }); });
  let t; $('q').addEventListener('input', e => { clearTimeout(t); t = setTimeout(() => { q = e.target.value.trim(); if (q) cat = ''; render(); }, 120); });
  $('sClose').addEventListener('click', close); $('scrim').addEventListener('click', close);
  $('sPrev').addEventListener('click', () => open(current - 1)); $('sNext').addEventListener('click', () => open(current + 1));
  document.addEventListener('keydown', e => {
    if (!$('sheet').classList.contains('on')) return;
    if (e.key === 'Escape') close(); else if (e.key === 'ArrowRight') open(Math.min(current + 1, shown.length - 1)); else if (e.key === 'ArrowLeft') open(Math.max(current - 1, 0));
  });
  render();
  // Lien direct vers une fiche : /modules#module=paie
  const direct = hash.get('module');
  if (direct) { const i = shown.findIndex(m => m.key === direct); if (i >= 0) setTimeout(() => open(i), 300); }
})();
</script>
@endpush
