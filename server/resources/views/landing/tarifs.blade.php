@extends('landing.layout')
@section('title', 'Tarifs de '.$appName.' : tous vos employés inclus')
@section('description', 'Formules de '.$appName.' : utilisateurs illimités, paiement Mobile Money, carte ou virement, sans engagement. Commencez gratuitement.')

@section('content')
<section class="phead">
  <div class="wrap">
    <div class="crumb"><a href="/">Accueil</a><span>›</span><span>Tarifs</span></div>
    <h1>Tous vos employés inclus. <em>Vous payez les modules.</em></h1>
    <p>Le nombre d'utilisateurs n'est jamais limité. Chaque formule regroupe des modules, moins cher que de les prendre un par un. Sans engagement, payable en Mobile Money, carte ou virement.</p>
    <div class="hero-cta"><a class="btn btn-gold" href="/inscription">Créer mon entreprise</a><a class="btn btn-ghost" href="/modules">Voir le rôle de chaque module</a></div>
  </div>
</section>

<div style="padding-top:70px"></div>
@include('landing._plans')
@include('landing._licence')

<section class="sec" style="padding-top:20px" id="faq">
  <div class="wrap">
    <div class="sec-head rv"><h2>Questions sur les prix.</h2></div>
    <div class="faq">
      <details><summary>Le nombre d'utilisateurs est-il limité ?</summary><p>Non, jamais. Que votre entreprise compte 5 ou 2 000 personnes, tout le monde peut travailler sur {{ $appName }}. Seuls les modules sont payants.</p></details>
      <details><summary>Puis-je ajouter un seul module à ma formule ?</summary><p>Oui. Depuis « Boutique de modules » dans le logiciel, ajoutez n'importe quel module au mois ou à l'année. Il s'active immédiatement.</p></details>
      <details><summary>Comment payer mon abonnement ?</summary><p>Par Mobile Money (Wave, Orange Money, MTN, Moov), carte bancaire ou virement, directement depuis l'application.</p></details>
      <details><summary>Puis-je arrêter quand je veux ?</summary><p>Oui, les formules sont mensuelles et sans engagement. Vous pouvez exporter vos données en Excel ou PDF à tout moment.</p></details>
      <details><summary>Comment fonctionne le parrainage ?</summary><p>Depuis votre compte, menu « Parrainage », partagez votre lien. Les entreprises invitées ont une remise sur leur premier achat, et vous gagnez du crédit sur chacun de leurs achats.</p></details>
    </div>
  </div>
</section>
@include('landing._end')
@endsection
