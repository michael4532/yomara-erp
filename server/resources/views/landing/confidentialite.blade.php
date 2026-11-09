@extends('landing.layout')
@section('title', 'Politique de confidentialité — '.$appName)
@section('description', 'Quelles données '.$appName.' collecte, pourquoi, avec qui elles sont partagées, combien de temps elles sont gardées et comment les supprimer.')

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
    <div class="crumb"><a href="/">Accueil</a><span>›</span><span>Confidentialité</span></div>
    <h1>Politique de confidentialité</h1>
    <p>Ce que {{ $appName }} fait de vos données, sur le site, dans l'application web, sur ordinateur et sur mobile.</p>
  </div>
</section>

<div class="wrap">
  <article class="legal-doc">
    <p class="meta">Dernière mise à jour : 9 novembre 2026</p>

    <h2>Qui sommes-nous</h2>
    <p>{{ $appName }} est un logiciel de gestion d'entreprise édité par {{ $who }}, Abidjan, Côte d'Ivoire{{ $contact['address'] ? ' ('.$contact['address'].')' : '' }}. Pour toute question sur vos données :
    @if($contact['email'])<a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a>.@else<a href="/contact">notre formulaire de contact</a>.@endif</p>

    <h2>Deux rôles différents</h2>
    <p>Chaque entreprise cliente reste propriétaire des données qu'elle saisit (clients, factures, stock, salariés…). Pour ces données, l'entreprise est responsable du traitement et {{ $who }} agit pour son compte, comme hébergeur et prestataire. Pour les comptes utilisateurs, l'abonnement et la facturation du service, {{ $who }} est responsable du traitement.</p>

    <h2>Les données collectées</h2>
    <ul>
      <li><b>Compte utilisateur :</b> nom, adresse e-mail, téléphone (facultatif), mot de passe (enregistré chiffré, jamais lisible), fonction et droits dans l'entreprise.</li>
      <li><b>Entreprise :</b> nom, coordonnées, logo, formule et modules choisis, historique des paiements de l'abonnement.</li>
      <li><b>Données de gestion saisies par l'entreprise :</b> clients, fournisseurs, produits, documents de vente et d'achat, stock, comptabilité, ressources humaines et paie, agenda, fichiers joints. Elles peuvent contenir des données personnelles des clients et salariés de l'entreprise.</li>
      <li><b>Données techniques :</b> adresse IP, type d'appareil et de navigateur, journaux de connexion et d'activité, utilisés pour la sécurité et le dépannage.</li>
      <li><b>Notifications (application mobile) :</b> si vous les acceptez, un identifiant de notification de l'appareil (Firebase Cloud Messaging, Google) est enregistré pour vous envoyer les alertes de votre entreprise. Il est supprimé à la déconnexion.</li>
      <li><b>Connexion avec Google (facultative) :</b> nous recevons seulement votre adresse e-mail Google vérifiée, pour ouvrir le compte qui a la même adresse.</li>
      <li><b>Appareil photo (application mobile) :</b> utilisé uniquement quand vous scannez un code-barres, un QR code ou photographiez un justificatif. Les images ne quittent l'appareil que si vous les enregistrez dans l'application.</li>
    </ul>
    <p>L'application ne contient pas de publicité, ne vend aucune donnée et n'utilise pas votre position.</p>

    <h2>Pourquoi nous les utilisons</h2>
    <ul>
      <li>Fournir le service : connexion, synchronisation entre vos appareils, fonctionnement hors ligne, sauvegardes.</li>
      <li>Envoyer les messages que vous déclenchez (factures, devis, relances, notifications) par e-mail ou WhatsApp.</li>
      <li>Facturer l'abonnement et les modules.</li>
      <li>Assurer la sécurité : détection des connexions suspectes, journal des actions.</li>
      <li>Répondre à vos demandes d'assistance.</li>
    </ul>

    <h2>Avec qui elles sont partagées</h2>
    <p>Uniquement avec les prestataires nécessaires au service, et seulement pour ce service : hébergement des serveurs, envoi des e-mails, messages WhatsApp et notifications (Firebase Cloud Messaging de Google), prestataires de paiement (Mobile Money, carte) lorsque vous encaissez ou payez, et les services que votre entreprise choisit de connecter (par exemple Google, WooCommerce ou un stockage de sauvegarde). Nous ne partageons pas vos données à des fins publicitaires.</p>

    <h2 id="reseaux-sociaux">Réseaux sociaux connectés (YouTube, Google, Facebook, Instagram, LinkedIn, TikTok…)</h2>
    <p>Le module <b>Réseaux sociaux</b> permet à une entreprise de connecter ses propres comptes pour y publier ses contenus depuis {{ $appName }}. La connexion se fait sur la page officielle du réseau, avec l'accord explicite de la personne qui connecte le compte ; nous ne voyons jamais son mot de passe.</p>
    <ul>
      <li><b>Données reçues :</b> l'identifiant et le nom du compte, de la chaîne ou de la page, la photo de profil, et une clé d'accès (jeton) permettant de publier pour ce compte. Pour YouTube : la liste des vidéos publiées depuis {{ $appName }} et leurs statistiques publiques (vues, « J'aime », commentaires).</li>
      <li><b>Utilisation :</b> uniquement publier les contenus que l'entreprise programme, afficher ses comptes et les statistiques de ses publications dans son espace. Ces données ne sont ni vendues, ni partagées avec des tiers, ni utilisées pour de la publicité.</li>
      <li><b>Conservation :</b> les jetons sont chiffrés sur nos serveurs. Les statistiques sont actualisées régulièrement ; les données reçues d'un réseau sont supprimées au plus tard 30 jours après la déconnexion du compte ou à la demande de l'entreprise.</li>
      <li><b>Déconnexion :</b> à tout moment dans Réseaux sociaux › Comptes › Déconnecter, ce qui supprime immédiatement les jetons enregistrés.</li>
    </ul>
    <div class="box" id="youtube">
      <b>Services API YouTube</b>
      <p style="margin:6px 0 0">{{ $appName }} utilise les Services API YouTube pour publier des vidéos et afficher leurs statistiques. En connectant une chaîne YouTube, vous acceptez les <a href="https://www.youtube.com/t/terms" target="_blank" rel="noopener">Conditions d'utilisation de YouTube</a>. Les données sont aussi traitées selon les <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">Règles de confidentialité de Google</a>. Vous pouvez à tout moment retirer l'accès de {{ $appName }} à votre compte Google depuis la <a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener">page des autorisations de sécurité de Google</a>.</p>
    </div>

    <h2>Sécurité</h2>
    <p>Les échanges sont chiffrés (HTTPS). Les mots de passe sont enregistrés chiffrés. Chaque utilisateur n'accède qu'aux modules et données autorisés par son entreprise. Les données sont sauvegardées chaque jour.</p>

    <h2>Durée de conservation</h2>
    <p>Les données sont gardées tant que le compte de l'entreprise est actif. Après la fermeture du compte ou une demande de suppression, elles sont effacées sous 30 jours, sauf les pièces que la loi oblige à conserver (par exemple les factures et écritures comptables, conservées le temps exigé par la réglementation fiscale), puis supprimées des sauvegardes au fil de leur rotation.</p>

    <h2>Vos droits et la suppression du compte</h2>
    <p>Vous pouvez demander à consulter, corriger, exporter ou supprimer vos données. Un utilisateur peut aussi s'adresser directement à l'administrateur de son entreprise.</p>
    <div class="box">
      <b>Supprimer votre compte</b>
      <p style="margin:6px 0 0">Dans l'application : menu du compte › <b>Supprimer mon compte</b>. Sans l'application : <a href="/suppression-compte">page de demande de suppression</a>. Le compte est désactivé et les données sont effacées sous 30 jours.</p>
    </div>

    <h2>Enfants</h2>
    <p>{{ $appName }} est un outil professionnel destiné aux entreprises. Il ne s'adresse pas aux enfants.</p>

    <h2>Modifications</h2>
    <p>Nous pouvons mettre à jour cette politique. La date en haut de la page indique la dernière version ; en cas de changement important, les administrateurs des entreprises sont prévenus.</p>
  </article>
</div>
@endsection
