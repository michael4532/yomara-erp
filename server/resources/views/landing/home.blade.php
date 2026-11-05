@extends('landing.layout')
@php
  $tour = [
    ['tableau-de-bord', 'Tableau de bord', 'Vos chiffres du jour, sans ouvrir un tableur.', ['Chiffre d\'affaires, marge, trésorerie et dépenses du mois', 'Comparaison avec l\'an dernier, mois par mois', 'Rendez-vous et tâches de la journée en tête de page']],
    ['ventes', 'Ventes & factures', 'Du devis à l\'encaissement, au même endroit.', ['Devis, commandes, factures et avoirs numérotés', 'Paiement par Wave, Orange Money, MTN ou Moov', 'Relances automatiques et facture certifiée FNE']],
    ['stock', 'Stock', 'Chaque vente sort le stock, chaque achat le remplit.', ['Plusieurs dépôts et boutiques', 'Alertes de rupture et inventaires', 'Lots, dates de péremption et codes-barres']],
    ['crm', 'Clients & prospects', 'Suivez chaque affaire jusqu\'à la signature.', ['Pipeline par étape avec montants et probabilité', 'Relances, rendez-vous et historique des échanges', 'Campagnes e-mail, SMS et WhatsApp']],
    ['comptabilite', 'Comptabilité', 'Les écritures se passent de saisie.', ['Plan SYSCOHADA révisé', 'Ventes, achats et caisse comptabilisés tout seuls', 'Bilan, compte de résultat, déclarations']],
    ['paie', 'Paie & RH', 'Les bulletins du mois en quelques clics.', ['Dossiers salariés, congés et absences', 'Bulletins conformes, CNPS et ITS', 'Pointage, recrutement et espace salarié']],
  ];
  $notes = [
    ['green', 'Paiement Wave reçu', 'Facture FAC-0142, 250 000 FCFA'],
    ['gold', 'Stock bas', 'Riz parfumé 25 kg : 4 sacs restants'],
    ['blue', 'Nouveau client', 'Boutique Awa vient de s\'inscrire'],
    ['green', 'Bulletins de paie prêts', '24 salariés, novembre'],
    ['blue', 'Publication planifiée', 'Facebook, Instagram et LinkedIn à 10:00'],
  ];
  $words = ['Même sans connexion.', 'Sur votre téléphone.', 'Dans un seul logiciel.', 'Payée en Mobile Money.'];
@endphp

@push('styles')
<style>
/* Titre : la fin de la phrase change toute seule */
.hero h1 .rot{display:inline-block;color:var(--gold);min-height:1em}
@keyframes caret{50%{opacity:0}}
/* Fond du haut : halos lents */
.hero:before{content:"";position:absolute;width:640px;height:640px;left:-260px;bottom:-200px;background:radial-gradient(closest-side,rgba(46,107,230,.30),transparent 70%);pointer-events:none;animation:drift 16s ease-in-out infinite alternate}
.hero:after{animation:drift 20s ease-in-out infinite alternate-reverse}
/* Notifications qui défilent sur l'écran du logiciel */
.chip-live{transition:opacity .45s,transform .45s}
.chip-live.out{opacity:0;transform:translateY(-10px)}
.chip-live .dot.gold{background:var(--gold);box-shadow:0 0 0 5px rgba(255,197,49,.25)}
.chip-live .dot.blue{background:var(--blue);box-shadow:0 0 0 5px rgba(46,107,230,.2)}
.chip-kpi{position:absolute;right:26%;bottom:8%;background:var(--night);color:#fff;border-radius:16px;padding:14px 18px;box-shadow:0 20px 50px -18px rgba(0,0,0,.7),0 0 0 1px rgba(255,255,255,.1);animation:rise 1s cubic-bezier(.2,.8,.2,1) both 1.1s,bob 6s ease-in-out infinite 2.2s;min-width:190px}
.chip-kpi small{color:#AEB9D3;font-size:12.5px;display:block}
.chip-kpi b{font:800 26px/1.1 var(--display);display:block}
.chip-kpi i{display:flex;align-items:flex-end;gap:4px;height:30px;margin-top:8px}
.chip-kpi i span{flex:1;background:var(--gold);border-radius:3px 3px 0 0;transform-origin:bottom;animation:grow 1.2s cubic-bezier(.2,.8,.2,1) both;animation-delay:calc(1.3s + var(--k) * .08s)}
@keyframes grow{from{transform:scaleY(0)}}
@keyframes bob{50%{transform:translateY(-8px)}}
.stage .screen,.stage .phone{will-change:transform}
@media (max-width:900px){.chip-kpi{display:none}}
/* Modules qui défilent */
.marquee{overflow:hidden;mask-image:linear-gradient(90deg,transparent,#000 8%,#000 92%,transparent);-webkit-mask-image:linear-gradient(90deg,transparent,#000 8%,#000 92%,transparent);display:grid;gap:12px;margin:0 -24px 46px}
.marquee .row{display:flex;gap:12px;width:max-content;animation:slide 60s linear infinite}
.marquee .row.r2{animation-direction:reverse;animation-duration:75s}
.marquee:hover .row{animation-play-state:paused}
.marquee a{background:#fff;border:1px solid var(--line);border-radius:999px;padding:10px 18px;font-weight:600;font-size:15px;white-space:nowrap;transition:background .2s,color .2s,border-color .2s}
.marquee a:hover{background:var(--night);color:#fff;border-color:var(--night)}
@keyframes slide{to{transform:translateX(-50%)}}
.cats{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.cat{background:#fff;border:1px solid var(--line);border-radius:20px;padding:24px;display:flex;flex-direction:column;gap:10px;transition:transform .25s,box-shadow .25s,border-color .25s;position:relative;overflow:hidden}
.cat:before{content:"";position:absolute;inset:auto -40% -60% auto;width:180px;height:180px;border-radius:50%;background:var(--c);opacity:.12;transition:transform .5s}
.cat:hover{transform:translateY(-4px);box-shadow:var(--shadow);border-color:transparent}
.cat:hover:before{transform:scale(1.6)}
.cat b{font:700 21px/1.2 var(--display)}
.cat small{color:var(--mute);font-weight:600}
.cat span{color:var(--ink2);font-size:14.5px}
.cat em{font-style:normal;font-weight:700;color:var(--blue);margin-top:auto}
@media (max-width:900px){.cats{grid-template-columns:1fr 1fr}}
@media (max-width:560px){.cats{grid-template-columns:1fr}}
/* Trois portes d'entrée */
.doors{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
.door{border-radius:24px;padding:30px;display:flex;flex-direction:column;gap:12px;min-height:280px;position:relative;overflow:hidden;transition:transform .25s}
.door:hover{transform:translateY(-5px)}
.door h3{font-size:30px;font-weight:800}
.door p{margin:0;font-size:16px;max-width:34ch}
.door .go{margin-top:auto;font-weight:700;display:flex;align-items:center;gap:8px}
.door .go:after{content:"→";transition:transform .2s}
.door:hover .go:after{transform:translateX(6px)}
.door.d1{background:var(--night);color:#fff}.door.d1 p{color:#C9D3EA}.door.d1 b{color:var(--gold)}
.door.d2{background:#fff;border:1px solid var(--line)}
.door.d3{background:var(--gold);color:var(--night)}
.door .big{font:800 46px/1 var(--display);letter-spacing:-.02em}
.door .icons{display:flex;gap:10px;color:var(--ink)}
.door .icons svg{width:30px;height:30px;padding:6px;border-radius:10px;background:var(--chalk);transition:transform .3s}
.door:hover .icons svg{transform:translateY(-4px)}
.door:hover .icons svg:nth-child(2){transition-delay:.05s}.door:hover .icons svg:nth-child(3){transition-delay:.1s}.door:hover .icons svg:nth-child(4){transition-delay:.15s}.door:hover .icons svg:nth-child(5){transition-delay:.2s}.door:hover .icons svg:nth-child(6){transition-delay:.25s}
@media (max-width:900px){.doors{grid-template-columns:1fr}.door{min-height:0}}
</style>
@endpush

@section('content')
<section class="hero on-dark">
  <div class="wrap">
    <div class="hero-top">
      <h1>Toute votre entreprise. <span class="rot">Dans un seul logiciel.</span></h1>
      <div class="hero-side">
        <p>Ventes, caisse, stock, comptabilité, paie, clients et messagerie dans un seul logiciel, pensé pour les entreprises d'Afrique de l'Ouest.</p>
        <div class="hero-cta">
          <a class="btn btn-gold" href="/inscription">Créer mon entreprise</a>
          <a class="btn btn-ghost" href="/telecharger">Télécharger</a>
        </div>
      </div>
    </div>
    <ul class="platforms" aria-label="Disponible sur">
      <li style="--i:0">{!! $osIcons['web'] !!} Navigateur</li>
      <li style="--i:1">{!! $osIcons['windows'] !!} Windows</li>
      <li style="--i:2">{!! $osIcons['mac'] !!} Mac</li>
      <li style="--i:3">{!! $osIcons['linux'] !!} Linux</li>
      <li style="--i:4">{!! $osIcons['android'] !!} Android</li>
      <li style="--i:5">{!! $osIcons['ios'] !!} iPhone et iPad</li>
    </ul>
    <div class="stage" aria-hidden="true">
      <div class="screen">
        <div class="bar"><i></i><i></i><i></i><span>{{ preg_replace('#^https?://#', '', url('/app/')) }}</span></div>
        <img src="/landing/tableau-de-bord.webp" alt="" width="1440" height="900" fetchpriority="high">
      </div>
      <div class="phone"><img src="/landing/mobile.webp" alt="" width="390" height="844"></div>
      <div class="chip-live" id="chipLive" data-notes='@json($notes)'><span class="dot"></span><span><b>Paiement Wave reçu</b><span class="t">Facture FAC-0142, 250 000 FCFA</span></span></div>
      <div class="chip-kpi"><small>Chiffre d'affaires du mois</small><b data-count="18450000" data-suffix=" F">18 450 000 F</b><i>@foreach([38,52,44,61,57,70,66,84] as $k => $h)<span style="height:{{ $h }}%;--k:{{ $k }}"></span>@endforeach</i></div>
    </div>
  </div>
</section>

<div class="proof">
  <div class="wrap">
    <div><b data-count="{{ $moduleCount }}">{{ $moduleCount }}</b><span>modules à activer selon vos besoins</span></div>
    <div><b>6</b><span>écrans : web, PC, Mac, Linux, Android, iPhone</span></div>
    <div><b>0</b><span>installation pour démarrer en ligne</span></div>
    <div><b>{{ $trialDays > 0 ? $trialDays.' j' : '2 min' }}</b><span>{{ $trialDays > 0 ? 'd\'essai gratuit, sans carte' : 'pour créer votre espace' }}</span></div>
  </div>
</div>

<section class="sec" id="visite">
  <div class="wrap">
    <div class="sec-head rv">
      <h2>Ce que vous voyez, c'est le vrai logiciel.</h2>
      <p>Chaque service travaille dans le même outil : une vente met à jour le stock, la comptabilité et le tableau de bord à l'instant.</p>
    </div>
    <div class="tour">
      <div class="tabs" role="tablist" aria-label="Écrans du logiciel">
        @foreach($tour as $i => [$img, $title, $line, $points])
          <button class="tab" role="tab" id="t-{{ $img }}" aria-controls="viewer" aria-selected="{{ $i === 0 ? 'true' : 'false' }}" data-img="{{ $img }}">
            <b>{{ $title }}</b><span>{{ $line }}</span>
            <ul>@foreach($points as $pt)<li>{{ $pt }}</li>@endforeach</ul>
          </button>
        @endforeach
      </div>
      <div class="viewer rv" id="viewer" role="tabpanel">
        <div class="bar"><i></i><i></i><i></i></div>
        @foreach($tour as $i => [$img, $title])
          <img src="/landing/{{ $img }}.webp" alt="Écran {{ $title }} de {{ $appName }}" data-img="{{ $img }}" class="{{ $i === 0 ? 'on' : '' }}" loading="{{ $i === 0 ? 'eager' : 'lazy' }}" width="1440" height="900">
        @endforeach
      </div>
    </div>
  </div>
</section>

<section class="sec local on-dark">
  <div class="wrap">
    <div class="sec-head rv">
      <h2>Fait pour la façon dont on travaille ici.</h2>
      <p>Pas un logiciel étranger traduit : les paiements, les normes et les contraintes du terrain sont dans le produit.</p>
    </div>
    <div class="facts">
      <div class="fact rv"><h3>Vos clients paient comme ils veulent.</h3><p>Liens de paiement et encaissements rapprochés automatiquement avec vos factures.</p><div class="tags"><span>Wave</span><span>Orange Money</span><span>MTN MoMo</span><span>Moov Money</span><span>Carte</span></div></div>
      <div class="fact rv"><h3>Internet coupe, vous continuez.</h3><p>Le logiciel installé garde une copie de vos données. Vous vendez, encaissez et saisissez ; tout se synchronise au retour du réseau.</p></div>
      <div class="fact rv"><h3>Vos factures certifiées par la DGI.</h3><p>Facture normalisée (FNE) envoyée à la validation, QR code imprimé, avoirs liés à la facture d'origine.</p></div>
      <div class="fact rv"><h3>Une comptabilité SYSCOHADA.</h3><p>Plan comptable révisé, écritures générées par les ventes, les achats et la caisse, états financiers prêts pour votre expert-comptable.</p></div>
    </div>
  </div>
</section>

<section class="sec" id="modules" style="padding-bottom:70px">
  <div class="wrap">
    <div class="sec-head rv">
      <h2>{{ $moduleCount }} modules. Vous n'activez que ceux qu'il vous faut.</h2>
      <p>Touchez un module pour découvrir à quoi il sert. Chaque fiche explique son rôle, ce qu'il fait au quotidien et dans quelle formule il est inclus.</p>
    </div>
    @php $half = (int) ceil(count($marquee) / 2); $r1 = array_slice($marquee, 0, $half); $r2 = array_slice($marquee, $half); @endphp
    <div class="marquee rv" aria-label="Quelques modules">
      <div class="row">@foreach([...$r1, ...$r1] as $n)<a href="/modules?q={{ urlencode($n) }}" @if($loop->index >= count($r1)) aria-hidden="true" tabindex="-1" @endif>{{ $n }}</a>@endforeach</div>
      <div class="row r2">@foreach([...$r2, ...$r2] as $n)<a href="/modules?q={{ urlencode($n) }}" @if($loop->index >= count($r2)) aria-hidden="true" tabindex="-1" @endif>{{ $n }}</a>@endforeach</div>
    </div>
    @php $palette = ['#2E6BE6', '#F5A000', '#12B76A', '#9333EA', '#E5484D', '#0EA5E9', '#D946EF', '#64748B']; @endphp
    <div class="cats">
      @foreach($categories->sortByDesc(fn ($m) => $m->count())->take(6) as $cat => $mods)
        <a class="cat rv" href="/modules#categorie={{ urlencode($cat) }}" style="--c:{{ $palette[$loop->index % count($palette)] }};--d:{{ ($loop->index % 3) * .08 }}s">
          <small>{{ $mods->count() }} modules</small>
          <b>{{ $cat }}</b>
          <span>{{ $mods->take(4)->pluck('name')->implode(', ') }}…</span>
          <em>Voir les modules</em>
        </a>
      @endforeach
    </div>
    <div class="hero-cta" style="margin-top:28px"><a class="btn btn-primary" href="/modules">Explorer les {{ $moduleCount }} modules</a></div>
  </div>
</section>

<section class="sec" style="padding-top:20px">
  <div class="wrap">
    <div class="doors">
      <a class="door d1 rv" href="/tarifs">
        <small style="color:#AEB9D3;font-weight:700">Tarifs</small>
        <h3>{!! $hasFree ? 'Gratuit pour <b>démarrer</b>.' : 'Simple et <b>clair</b>.' !!}</h3>
        <p>{{ $planCount }} formules, tous vos employés inclus.@if($fromPrice) Formules complètes à partir de {{ $fmt($fromPrice) }} {{ $cur }} par mois.@endif @if($trialDays > 0){{ $trialDays }} jours d'essai.@endif</p>
        <span class="go">Voir les tarifs</span>
      </a>
      <a class="door d2 rv" href="/telecharger" style="--d:.08s">
        <small style="color:var(--mute);font-weight:700">Télécharger</small>
        <h3>Sur tous vos écrans.</h3>
        <div class="icons">@foreach(['web', 'windows', 'mac', 'linux', 'android', 'ios'] as $k){!! $osIcons[$k] !!}@endforeach</div>
        <p>Navigateur, Windows, Mac, Linux, Android et iPhone : le même compte partout, même sans internet.</p>
        <span class="go">Installer l'application</span>
      </a>
      <a class="door d3 rv" href="/contact" style="--d:.16s">
        <small style="font-weight:700;opacity:.7">Contact</small>
        <h3>Un besoin précis ?</h3>
        <p>Démonstration, devis, installation sur votre serveur ou module fait sur mesure : parlons-en.</p>
        <span class="go">Nous écrire</span>
      </a>
    </div>
  </div>
</section>

@include('landing._end')
@endsection

@push('scripts')
<script>
(() => {
  const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;
  // Fin du titre : effacée puis retapée, mot après mot
  const rot = document.getElementById('rot');
  if (rot && !calm) {
    const words = JSON.parse(rot.dataset.words); let w = 0;
    const type = (txt, i, back) => {
      rot.textContent = txt.slice(0, i);
      if (!back && i < txt.length) return setTimeout(() => type(txt, i + 1, false), 55);
      if (!back) return setTimeout(() => type(txt, txt.length, true), 2600);
      if (i > 0) return setTimeout(() => type(txt, i - 1, true), 28);
      w = (w + 1) % words.length; setTimeout(() => type(words[w], 0, false), 250);
    };
    setTimeout(() => type(words[0], words[0].length, true), 3200);
  }
  // Notifications de l'écran : une nouvelle toutes les 3,5 s
  const chip = document.getElementById('chipLive');
  if (chip && !calm) {
    const notes = JSON.parse(chip.dataset.notes); let n = 0;
    setInterval(() => {
      chip.classList.add('out');
      setTimeout(() => { n = (n + 1) % notes.length; const [c, t, d] = notes[n]; chip.querySelector('.dot').className = 'dot ' + c; chip.querySelector('b').textContent = t; chip.querySelector('.t').textContent = d; chip.classList.remove('out'); }, 450);
    }, 3500);
  }
  // Léger relief : l'écran et le téléphone suivent la souris
  const stage = document.querySelector('.stage');
  if (stage && !calm && matchMedia('(pointer:fine)').matches) {
    const scr = stage.querySelector('.screen'), ph = stage.querySelector('.phone');
    stage.parentElement.addEventListener('mousemove', e => {
      const r = stage.getBoundingClientRect(), x = (e.clientX - r.left) / r.width - .5, y = (e.clientY - r.top) / r.height - .5;
      scr.style.transform = `translate(${x * -10}px, ${y * -6}px)`; ph.style.transform = `translate(${x * 18}px, ${y * 12}px)`;
    });
  }
})();
</script>
@endpush
