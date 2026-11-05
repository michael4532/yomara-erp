@extends('portail.layout')
@section('title', 'Espace client')
@section('content')
<div style="max-width:440px;margin:40px auto">
  <div class="row" style="margin-bottom:20px"><img class="logo" src="/brand/mark-512.png" alt="" style="padding:0;background:none"><div><strong>Espace client</strong><div class="muted">Factures, devis, commandes et livraisons</div></div></div>
  <div class="card">
    @if($sent)
      <div class="flash ok">Si cette adresse ou ce numéro est connu, votre lien d'accès vient d'être envoyé. Pensez à vérifier vos courriers indésirables.</div>
    @endif
    <h1>Recevoir mon lien d'accès</h1>
    <p class="muted">Votre fournisseur vous a envoyé un lien personnel. Vous l'avez perdu ? Indiquez l'e-mail ou le numéro de téléphone qu'il connaît : nous vous le renvoyons.</p>
    <form method="post" action="{{ url('/portail') }}">
      @csrf
      <label for="contact">E-mail ou téléphone</label>
      <input type="text" id="contact" name="contact" required autocomplete="email" placeholder="vous@exemple.ci ou 07 07 07 07 07">
      <div style="margin-top:16px"><button class="btn" type="submit" style="width:100%">Recevoir mon lien</button></div>
    </form>
  </div>
</div>
@endsection
