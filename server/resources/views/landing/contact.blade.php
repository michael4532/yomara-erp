@extends('landing.layout')
@section('title', 'Contacter '.($contact['name'] ?: $appName))
@section('description', 'Démonstration, devis, installation sur votre serveur ou module sur mesure : écrivez à l\'équipe '.$appName.'. Réponse sous 24 h ouvrées.')

@push('styles')
<style>
.reach-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px;margin-top:-46px;position:relative;z-index:2}
.rc{background:#fff;border-radius:20px;padding:22px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:flex-start;transition:transform .25s;animation:rise .7s cubic-bezier(.2,.8,.2,1) both;animation-delay:calc(.35s + var(--i) * .08s)}
a.rc:hover{transform:translateY(-4px)}
.rc .ic{width:46px;height:46px;flex:none;border-radius:14px;display:grid;place-items:center;background:var(--chalk);font-size:22px}
.rc small{display:block;color:var(--mute);font-weight:700;font-size:13px}
.rc b{display:block;font-size:16.5px;overflow-wrap:anywhere}
.rc span{color:var(--ink2);font-size:14px}
.rc.wa .ic{background:#DCFCE7}.rc.ph .ic{background:#E0EAFF}.rc.ml .ic{background:#FFF3CC}.rc.ad .ic{background:#F3E8FF}
.steps-c{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-top:10px}
.steps-c div{border-top:2px solid var(--ink);padding-top:16px}
.steps-c b{font:700 19px/1.2 var(--display);display:block;margin-bottom:6px}
.steps-c p{margin:0;color:var(--ink2);font-size:15px}
@media (max-width:760px){.steps-c{grid-template-columns:1fr}}
</style>
@endpush

@section('content')
<section class="phead" style="padding-bottom:110px">
  <div class="wrap">
    <div class="crumb"><a href="/">Accueil</a><span>›</span><span>Contact</span></div>
    <h1>Parlons de <em>votre entreprise.</em></h1>
    <p>Une démonstration, un devis pour une grande équipe, une installation sur vos serveurs ou un module fait sur mesure : écrivez-nous, une personne de l'équipe vous répond, en général sous 24 h ouvrées.</p>
  </div>
</section>

<div class="wrap">
  <div class="reach-cards">
    @php $i = 0; @endphp
    @if($contact['whatsapp'])<a class="rc wa" style="--i:{{ $i++ }}" href="https://wa.me/{{ preg_replace('/\D+/', '', $contact['whatsapp']) }}" target="_blank" rel="noopener"><span class="ic">💬</span><span><small>WhatsApp</small><b>{{ $contact['whatsapp'] }}</b><span>Le plus rapide</span></span></a>@endif
    @if($contact['phone'])<a class="rc ph" style="--i:{{ $i++ }}" href="tel:{{ preg_replace('/\s+/', '', $contact['phone']) }}"><span class="ic">📞</span><span><small>Téléphone</small><b>{{ $contact['phone'] }}</b>@if($contact['hours'])<span>{{ $contact['hours'] }}</span>@endif</span></a>@endif
    @if($contact['email'])<a class="rc ml" style="--i:{{ $i++ }}" href="mailto:{{ $contact['email'] }}"><span class="ic">✉️</span><span><small>E-mail</small><b>{{ $contact['email'] }}</b><span>Réponse sous 24 h ouvrées</span></span></a>@endif
    @if($contact['address'])<div class="rc ad" style="--i:{{ $i++ }}"><span class="ic">📍</span><span><small>Adresse</small><b>{{ $contact['address'] }}</b></span></div>@endif
    @if($chatEnabled)<a class="rc" style="--i:{{ $i++ }}" href="#" onclick="event.preventDefault();document.getElementById('lcOpen').click()"><span class="ic">⚡</span><span><small>Discussion en direct</small><b>Écrire maintenant</b><span>Réponse en quelques minutes</span></span></a>@endif
  </div>
</div>

<section class="sec" style="padding:80px 0 30px">
  <div class="wrap">
    <div class="sec-head rv" style="margin-bottom:30px"><h2>Ce qui se passe ensuite.</h2></div>
    <div class="steps-c">
      <div class="rv"><b>Nous vous rappelons</b><p>Un conseiller reprend votre demande, par téléphone ou WhatsApp selon votre préférence.</p></div>
      <div class="rv" style="--d:.08s"><b>Démonstration sur vos cas</b><p>Nous vous montrons le logiciel avec vos produits, vos documents et votre façon de travailler.</p></div>
      <div class="rv" style="--d:.16s"><b>Une proposition claire</b><p>Formule, modules utiles, reprise de vos données et formation : le prix est annoncé avant de démarrer.</p></div>
    </div>
  </div>
</section>

@include('landing._contact')
@include('landing._faq')
@include('landing._end')
@endsection

@push('scripts')
<script>
(() => {
  // Demande venue d'une fiche module : message prérempli
  const m = new URLSearchParams(location.search).get('module'), t = document.getElementById('c-msg'), ty = document.getElementById('c-type');
  if (m && t && !t.value) { t.value = 'Bonjour, je souhaite en savoir plus sur le module « ' + m + ' » : '; if (ty && [...ty.options].some(o => o.value === 'demo')) ty.value = 'demo'; }
})();
</script>
@endpush
