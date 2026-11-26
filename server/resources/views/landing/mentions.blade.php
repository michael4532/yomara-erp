@extends('landing.layout')
@section('title', 'Mentions légales — '.$appName)
@section('description', 'Éditeur de '.$appName.' : dénomination, forme juridique, capital, siège, registre du commerce et contact.')

@push('styles')
<style>
.legal-doc{max-width:780px;margin:0 auto;padding:60px 0 90px}
.legal-doc h2{font:700 24px/1.25 var(--display);margin:40px 0 10px}
.legal-doc p,.legal-doc li{color:var(--ink2);font-size:16px;line-height:1.65}
.legal-doc .meta{color:var(--mute);font-size:14px}
.legal-doc dl{display:grid;grid-template-columns:minmax(150px,220px) 1fr;gap:10px 18px;margin:0;background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px 22px}
.legal-doc dt{color:var(--mute);font-size:15px}
.legal-doc dd{margin:0;color:var(--ink);font-size:16px;font-weight:600}
.legal-doc a{color:var(--blue,#1F6FEB);font-weight:600}
@media (max-width:560px){.legal-doc dl{grid-template-columns:1fr;gap:2px}.legal-doc dd{margin-bottom:10px}}
</style>
@endpush

@php $lg = $legal; @endphp

@section('content')
<section class="phead">
  <div class="wrap">
    <div class="crumb"><a href="/">Accueil</a><span>›</span><span>Mentions légales</span></div>
    <h1>Mentions légales</h1>
    <p>Qui édite {{ $appName }} et comment nous joindre.</p>
  </div>
</section>

<div class="wrap">
  <article class="legal-doc">
    <h2>Éditeur</h2>
    <dl>
      <dt>Dénomination</dt><dd>{{ $lg['name'] }}</dd>
      @if($lg['form'])<dt>Forme juridique</dt><dd>{{ $lg['form'] }}</dd>@endif
      @if($lg['capital'])<dt>Capital social</dt><dd>{{ $lg['capital'] }}</dd>@endif
      @if($lg['address'])<dt>Siège social</dt><dd>{{ $lg['address'] }}</dd>@endif
      @if($lg['rccm'])<dt>RCCM</dt><dd>{{ $lg['rccm'] }}</dd>@endif
      @if($lg['ncc'])<dt>Compte contribuable (NCC)</dt><dd>{{ $lg['ncc'] }}</dd>@endif
      @if($lg['director'])<dt>Directeur de la publication</dt><dd>{{ $lg['director'] }}</dd>@endif
      @if($lg['phone'])<dt>Téléphone</dt><dd><a href="tel:{{ preg_replace('/\s+/', '', $lg['phone']) }}">{{ $lg['phone'] }}</a></dd>@endif
      @if($lg['email'])<dt>E-mail</dt><dd><a href="mailto:{{ $lg['email'] }}">{{ $lg['email'] }}</a></dd>@endif
    </dl>

    <h2>Propriété intellectuelle</h2>
    <p>Le nom {{ $appName }}, les logos, les textes et les visuels de ce site appartiennent à {{ $lg['name'] }}. Le code de l'édition libre est publié sous licence AGPL-3.0 ; les marques et logos n'en font pas partie et ne peuvent être réutilisés sans autorisation.</p>

    <h2>Données personnelles</h2>
    <p>Les informations collectées sur ce site et dans l'application sont traitées comme décrit dans la <a href="/confidentialite">politique de confidentialité</a>. L'utilisation du service est régie par les <a href="/conditions">conditions d'utilisation</a>.</p>

    <h2>Nous joindre</h2>
    <p>Pour toute question sur ce site : @if($lg['email'])<a href="mailto:{{ $lg['email'] }}">{{ $lg['email'] }}</a>@else<a href="/contact">notre formulaire de contact</a>@endif.</p>
  </article>
</div>
@endsection
