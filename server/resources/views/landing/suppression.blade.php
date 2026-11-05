@extends('landing.layout')
@section('title', 'Supprimer mon compte — '.$appName)
@section('description', 'Demandez la suppression de votre compte '.$appName.' ou de toute votre entreprise et de ses données.')

@push('styles')
<style>
.del{display:grid;grid-template-columns:1.05fr .95fr;gap:40px;padding:60px 0 90px;align-items:start}
.del h2{font:700 22px/1.25 var(--display);margin:0 0 10px}
.del p,.del li{color:var(--ink2);font-size:16px;line-height:1.6}
.del ol{padding-left:20px}
.del .box{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px 20px;margin-top:22px}
.cform .opt{display:flex;gap:10px;align-items:flex-start;border:1.5px solid var(--line);border-radius:12px;padding:12px 14px;margin-top:8px;cursor:pointer;font-weight:600}
.cform .opt input{width:auto;margin-top:4px}
.cform .opt small{display:block;font-weight:400;color:var(--mute)}
@media (max-width:860px){.del{grid-template-columns:1fr}}
</style>
@endpush

@section('content')
<section class="phead">
  <div class="wrap">
    <div class="crumb"><a href="/">Accueil</a><span>›</span><span>Suppression de compte</span></div>
    <h1>Supprimer mon compte</h1>
    <p>Supprimez votre compte utilisateur {{ $appName }}, ou toute votre entreprise et ses données.</p>
  </div>
</section>

<div class="wrap">
  <div class="del">
    <div>
      <h2>Depuis l'application</h2>
      <ol>
        <li>Ouvrez {{ $appName }} et connectez-vous.</li>
        <li>Ouvrez le menu de votre compte (votre nom ou votre photo).</li>
        <li>Choisissez <b>Supprimer mon compte</b>, puis confirmez avec votre mot de passe.</li>
      </ol>

      <h2 style="margin-top:30px">Ce qui est supprimé</h2>
      <p><b>Compte utilisateur :</b> il est désactivé tout de suite et déconnecté de tous vos appareils. Votre nom, e-mail, téléphone et préférences sont effacés sous 30 jours. Les documents que vous avez créés pour l'entreprise (factures, ventes…) restent à l'entreprise, sans vos données personnelles.</p>
      <p><b>Entreprise entière :</b> réservé à l'administrateur. Nous vous contactons pour confirmer, puis tous les comptes, documents, fichiers et données de l'entreprise sont supprimés sous 30 jours.</p>

      <div class="box">
        <b>Ce qui peut être conservé</b>
        <p style="margin:6px 0 0">Les pièces que la loi oblige à garder (factures, écritures comptables, paiements de l'abonnement) le temps exigé par la réglementation, puis supprimées. Les sauvegardes sont effacées au fil de leur rotation, sous 30 jours. Plus de détails dans notre <a href="/confidentialite">politique de confidentialité</a>.</p>
      </div>
    </div>

    <form class="cform" method="post" action="/suppression-compte">
      @csrf
      <h3>Demander la suppression sans l'application</h3>
      <p style="margin:0;color:var(--mute);font-size:14.5px">Un e-mail de confirmation est envoyé à l'adresse du compte.</p>
      @if(session('deletion_ok'))<div class="okmsg" style="margin-top:14px">Demande reçue. Vous allez recevoir un e-mail de confirmation ; elle est traitée sous 30 jours.</div>@endif
      @if($errors->any())<div class="errmsg" style="margin-top:14px">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
      <label for="d-email">E-mail du compte</label>
      <input id="d-email" type="email" name="email" value="{{ old('email') }}" required maxlength="160" autocomplete="email">
      <div class="r2">
        <div><label for="d-name">Nom</label><input id="d-name" name="name" value="{{ old('name') }}" maxlength="120" autocomplete="name"></div>
        <div><label for="d-company">Entreprise</label><input id="d-company" name="company" value="{{ old('company') }}" maxlength="160" autocomplete="organization"></div>
      </div>
      <label>Que faut-il supprimer ?</label>
      <label class="opt"><input type="radio" name="scope" value="user" @checked(old('scope', 'user') === 'user')><span>{{ $scopes['user'] }}<small>Mes données personnelles et mon accès.</small></span></label>
      <label class="opt"><input type="radio" name="scope" value="company" @checked(old('scope') === 'company')><span>{{ $scopes['company'] }}<small>Réservé à l'administrateur de l'entreprise.</small></span></label>
      <label for="d-msg">Précisions (facultatif)</label>
      <textarea id="d-msg" name="message" rows="3" maxlength="3000">{{ old('message') }}</textarea>
      <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
      <button class="btn" type="submit">Envoyer la demande de suppression</button>
    </form>
  </div>
</div>
@endsection
