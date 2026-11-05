@extends('landing.layout')
@section('title', 'Télécharger '.$appName.' : Windows, Mac, Linux, Android, iPhone')
@section('description', 'Installez '.$appName.' sur votre ordinateur ou votre téléphone, ou sur votre propre serveur. Le même compte partout, même sans internet.')

@section('content')
<section class="phead">
  <div class="wrap">
    <div class="crumb"><a href="/">Accueil</a><span>›</span><span>Télécharger</span></div>
    <h1>Un seul compte. <em>Tous vos écrans.</em></h1>
    <p>Installez l'application pour travailler même quand internet coupe : vos ventes et saisies sont gardées sur l'appareil puis envoyées dès que la connexion revient.</p>
    <div class="hero-cta"><a class="btn btn-gold" href="/app/">Ouvrir la version web</a><a class="btn btn-ghost" href="#licence">Installer sur mon serveur</a></div>
  </div>
</section>
@include('landing._downloads')
<div style="padding-top:90px"></div>
@include('landing._licence')
@include('landing._end')
@endsection
