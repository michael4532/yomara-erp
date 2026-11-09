@extends('landing.layout')
@section('title', 'Conditions d\'utilisation — '.$appName)
@section('description', 'Les règles d\'utilisation de '.$appName.' : compte, abonnement, données de votre entreprise, services connectés et responsabilités.')

@push('styles')
<style>
.legal-doc{max-width:780px;margin:0 auto;padding:60px 0 90px}
.legal-doc h2{font:700 24px/1.25 var(--display);margin:40px 0 10px}
.legal-doc p,.legal-doc li{color:var(--ink2);font-size:16px;line-height:1.65}
.legal-doc ul{padding-left:20px}
.legal-doc .meta{color:var(--mute);font-size:14px}
.legal-doc .box{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px 20px;margin-top:18px}
.legal-doc a{color:var(--blue,#1F6FEB);font-weight:600}
</style>
@endpush

@php $who = $contact['name'] ?: 'Yomara Technologies'; @endphp

@section('content')
<section class="phead">
  <div class="wrap">
    <div class="crumb"><a href="/">Accueil</a><span>›</span><span>Conditions d'utilisation</span></div>
    <h1>Conditions d'utilisation</h1>
    <p>Les règles qui s'appliquent quand votre entreprise utilise {{ $appName }}, sur le web, sur ordinateur et sur mobile.</p>
  </div>
</section>

<div class="wrap">
  <article class="legal-doc">
    <p class="meta">Dernière mise à jour : 9 novembre 2026</p>

    <h2>Le service</h2>
    <p>{{ $appName }} est un logiciel de gestion d'entreprise édité par {{ $who }}, Abidjan, Côte d'Ivoire{{ $contact['address'] ? ' ('.$contact['address'].')' : '' }}. Il comprend des fonctions de base et des modules (ventes, caisse, stock, comptabilité, paie, réseaux sociaux…) que chaque entreprise active selon ses besoins. En créant un compte ou en utilisant l'application, vous acceptez ces conditions ainsi que notre <a href="/confidentialite">politique de confidentialité</a>.</p>

    <h2>Votre compte</h2>
    <ul>
      <li>La personne qui crée l'espace d'une entreprise déclare être autorisée à engager cette entreprise.</li>
      <li>Vous gardez vos identifiants secrets et êtes responsable des actions faites avec votre compte. Prévenez-nous sans attendre en cas d'accès non autorisé.</li>
      <li>L'administrateur de l'entreprise choisit qui accède à quoi : il crée les utilisateurs et leur donne leurs droits.</li>
    </ul>

    <h2>Abonnement et paiement</h2>
    <ul>
      <li>Une formule gratuite donne accès aux fonctions de base. Les formules et modules payants sont décrits sur la page <a href="/tarifs">Tarifs</a>, avec leurs prix au moment de la commande.</li>
      <li>Le paiement se fait par Mobile Money, carte ou virement, au mois ou à l'année, sans engagement : vous pouvez arrêter un module à la fin de la période payée.</li>
      <li>Sans renouvellement, les modules payants s'arrêtent à la fin de la période ; les données de votre entreprise restent conservées.</li>
    </ul>

    <h2>Les données de votre entreprise</h2>
    <p>Votre entreprise reste propriétaire de toutes les données qu'elle saisit (clients, factures, stock, salariés…). {{ $who }} les héberge et les traite uniquement pour fournir le service, comme décrit dans la <a href="/confidentialite">politique de confidentialité</a>. Vous pouvez les exporter à tout moment. Vous êtes responsable de leur exactitude et du respect des lois qui s'appliquent à votre activité (fiscalité, droit du travail, protection des données de vos clients et salariés).</p>

    <h2 id="services-connectes">Services connectés (réseaux sociaux, paiement, boutique…)</h2>
    <p>Certains modules se connectent à des services d'autres sociétés que votre entreprise choisit de relier : réseaux sociaux (YouTube, Google Business Profile, Facebook, Instagram, Threads, LinkedIn, X, TikTok, Pinterest…), moyens de paiement, boutiques en ligne, messagerie. Quand vous connectez un de ces services :</p>
    <ul>
      <li>vous devez avoir le droit d'utiliser le compte connecté, et vous restez soumis aux conditions de ce service ;</li>
      <li>vous êtes responsable des contenus que vous publiez par {{ $appName }} (textes, images, vidéos) et de leur conformité aux règles du réseau et à la loi ;</li>
      <li>vous pouvez déconnecter le service à tout moment dans {{ $appName }}.</li>
    </ul>
    <div class="box" id="youtube">
      <b>YouTube</b>
      <p style="margin:6px 0 0">{{ $appName }} utilise les Services API YouTube. En connectant une chaîne YouTube, vous acceptez d'être lié par les <a href="https://www.youtube.com/t/terms" target="_blank" rel="noopener">Conditions d'utilisation de YouTube</a>. Les <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">Règles de confidentialité de Google</a> s'appliquent aux données traitées par YouTube.</p>
    </div>

    <h2>Utilisation acceptable</h2>
    <p>Il est interdit d'utiliser {{ $appName }} pour une activité illégale, pour envoyer des messages non sollicités en masse, pour publier des contenus trompeurs, haineux ou qui violent les droits d'autrui, pour tenter d'accéder aux données d'une autre entreprise ou pour perturber le fonctionnement du service. En cas de manquement grave, l'accès peut être suspendu après information de l'administrateur de l'entreprise, sauf urgence.</p>

    <h2>Disponibilité</h2>
    <p>Nous faisons le nécessaire pour que le service soit disponible en permanence et sauvegardé chaque jour. Des interruptions peuvent avoir lieu pour la maintenance ou à cause de services extérieurs (hébergement, réseau, réseaux sociaux, moyens de paiement). Les applications installées continuent de fonctionner sans connexion et se synchronisent au retour du réseau.</p>

    <h2>Responsabilité</h2>
    <p>{{ $appName }} est un outil d'aide à la gestion : les décisions prises et les documents émis restent sous la responsabilité de votre entreprise. Dans la limite permise par la loi, la responsabilité de {{ $who }} est limitée aux sommes payées pour le service au cours des douze derniers mois et ne couvre pas les dommages indirects (perte de chiffre d'affaires, d'image ou de clientèle).</p>

    <h2>Fin de l'utilisation</h2>
    <p>Vous pouvez fermer le compte de votre entreprise à tout moment (<a href="/suppression-compte">demande de suppression</a>). Exportez d'abord vos données : elles sont ensuite effacées sous 30 jours, sauf les pièces que la loi oblige à conserver.</p>

    <h2>Modifications et droit applicable</h2>
    <p>Nous pouvons modifier ces conditions ; la date en haut de la page indique la dernière version et les administrateurs des entreprises sont prévenus en cas de changement important. Ces conditions sont soumises au droit ivoirien. En cas de désaccord, nous cherchons d'abord une solution amiable :
    @if($contact['email'])<a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a>.@else<a href="/contact">écrivez-nous</a>.@endif</p>
  </article>
</div>
@endsection
