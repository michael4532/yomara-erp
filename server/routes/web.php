<?php

use Illuminate\Support\Facades\Route;

// Domaine court des liens (Configuration du serveur › Liens courts) : yma.ci/abc12 ; l'accueil de ce domaine renvoie au site
if ($shortDomain = \App\Modules\Liens\LinkService::shortDomain()) {
    Route::domain($shortDomain)->group(function () {
        $lk = \App\Modules\Liens\PublicLinkController::class;
        Route::get('/', fn () => redirect(rtrim((string) config('app.url'), '/').'/'));
        Route::match(['get', 'post'], '{slug}', [$lk, 'open'])->where('slug', '[A-Za-z0-9-]{3,64}')->middleware('weblang');
        Route::get('{slug}/b/{i}', [$lk, 'button'])->where('slug', '[A-Za-z0-9-]{3,64}')->whereNumber('i');
        Route::get('{slug}/qr.png', [$lk, 'qr'])->where('slug', '[A-Za-z0-9-]{3,64}');
    });
}

// L'interface est l'application Flutter Web compilée dans public/app.
// Site de présentation (SaaS) : vitrine, inscription en ligne, téléchargements. Installation unique : « / » ouvre l'application.
// Pages publiques en plusieurs langues (?lang=en, choix mémorisé, langue du navigateur)
Route::middleware('weblang')->group(function () {
    Route::get('/', [\App\Http\Controllers\LandingController::class, 'home']);
    Route::get('inscription', [\App\Http\Controllers\LandingController::class, 'signupForm']);
    Route::post('inscription', [\App\Http\Controllers\LandingController::class, 'signup'])->middleware('throttle:register');
    Route::post('contact', [\App\Http\Controllers\LandingController::class, 'contactRequest'])->middleware('throttle:6,10');
    Route::get('contact', [\App\Http\Controllers\LandingController::class, 'contactPage']);
    Route::middleware('throttle:20,10')->group(function () {
        Route::get('mot-de-passe/{token}', [\App\Http\Controllers\PasswordResetController::class, 'form'])->where('token', '[A-Za-z0-9]{64}');
        Route::post('mot-de-passe/{token}', [\App\Http\Controllers\PasswordResetController::class, 'reset'])->where('token', '[A-Za-z0-9]{64}');
    });
    // Confidentialité et suppression de compte (Google Play / App Store) : disponibles aussi sur une installation sur serveur
    Route::get('confidentialite', [\App\Http\Controllers\AccountDeletionController::class, 'privacy']);
    Route::get('suppression-compte', [\App\Http\Controllers\AccountDeletionController::class, 'form']);
    Route::post('suppression-compte', [\App\Http\Controllers\AccountDeletionController::class, 'submit'])->middleware('throttle:5,10');
    Route::get('modules', [\App\Http\Controllers\LandingController::class, 'modulesPage']);
    Route::get('tarifs', [\App\Http\Controllers\LandingController::class, 'pricingPage']);
    Route::get('telecharger', [\App\Http\Controllers\LandingController::class, 'downloadPage']);
    Route::get('inscription/bienvenue', [\App\Http\Controllers\LandingController::class, 'welcome']);
    Route::get('telecharger/{os}', [\App\Http\Controllers\LandingController::class, 'download'])->whereIn('os', [...array_keys(\App\Http\Controllers\LandingController::PLATFORMS), 'serveur', 'source', 'github']);
    Route::get('telecharger/{os}/fichier', [\App\Http\Controllers\LandingController::class, 'downloadFile'])->whereIn('os', ['windows', 'mac', 'linux', 'android']);
});
Route::get('robots.txt', fn () => response("User-agent: *\nDisallow: /api/\nDisallow: /app/\n\nSitemap: ".rtrim((string) config('app.url'), '/')."/sitemap.xml\n", 200, ['Content-Type' => 'text/plain']));
// Plan du site pour les moteurs de recherche (pages publiques du site de présentation)
Route::get('sitemap.xml', function () {
    $base = rtrim((string) config('app.url'), '/');
    $pages = ['/', '/modules', '/tarifs', '/telecharger', '/contact', '/inscription', '/confidentialite'];
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($pages as $p) {
        $xml .= '<url><loc>'.e($base.($p === '/' ? '/' : $p)).'</loc><changefreq>weekly</changefreq><priority>'.($p === '/' ? '1.0' : '0.7').'</priority></url>';
    }

    return response($xml.'</urlset>', 200, ['Content-Type' => 'application/xml']);
});
Route::get('/scan', fn () => redirect('/scan/')); // Yomara Scan : contrôle d'entrée (public/scan)

// Paiement en ligne d'une facture par le client final (lien partagé par la société)
Route::middleware('throttle:public-pay')->group(function () {
    // Liens de paiement libres (acompte, prestation, commande Instagram, don…)
    Route::get('pay/l/{token}', [\App\Http\Controllers\PublicPaymentController::class, 'showLink'])->where('token', '[A-Za-z0-9]{40}');
    Route::post('pay/l/{token}/{gateway}', [\App\Http\Controllers\PublicPaymentController::class, 'startLink'])->where('token', '[A-Za-z0-9]{40}')->whereNumber('gateway');
    Route::get('pay/{token}', [\App\Http\Controllers\PublicPaymentController::class, 'show']);
    Route::post('pay/{token}/{gateway}', [\App\Http\Controllers\PublicPaymentController::class, 'start'])->whereNumber('gateway');
    Route::get('payments/{tx}/return', [\App\Http\Controllers\PublicPaymentController::class, 'back'])->whereUuid('tx');
    Route::get('payments/{tx}/kkiapay', [\App\Http\Controllers\PublicPaymentController::class, 'kkiapay'])->whereUuid('tx');
});

// Sauvegardes : retour de connexion Google Drive / Dropbox, téléchargement d'une archive (lien signé)
// Pages de capture (Publicité & acquisition) et retour de la connexion Google Ads / Search Console
Route::middleware('throttle:portal')->group(function () {
    Route::get('lp/{slug}', [\App\Modules\Acquisition\LandingController::class, 'show'])->where('slug', '[a-z0-9-]{1,80}');
    Route::post('lp/{slug}', [\App\Modules\Acquisition\LandingController::class, 'submit'])->where('slug', '[a-z0-9-]{1,80}');
    Route::get('oauth/acquisition/google/retour', [\App\Modules\Acquisition\AcquisitionController::class, 'googleCallback']);
});
Route::get('oauth/sauvegarde/{provider}/retour', [\App\Http\Controllers\Api\BackupController::class, 'callback'])->whereIn('provider', ['google', 'dropbox']);
Route::get('sauvegardes/fichier/{name}', [\App\Http\Controllers\Api\BackupController::class, 'file'])->where('name', '[A-Za-z0-9._-]+')->name('backups.file')->middleware('signed');

// Campagnes : suivi des ouvertures et clics, désinscription, aperçu
// Communication de l'éditeur à ses clients : ouverture, clic signé, désinscription
Route::middleware('throttle:public-pay')->group(function () {
    Route::get('c/o/{token}.gif', [\App\Http\Controllers\ClientCommsTrackingController::class, 'open']);
    Route::get('c/c/{token}', [\App\Http\Controllers\ClientCommsTrackingController::class, 'click']);
    Route::match(['get', 'post'], 'c/u/{token}', [\App\Http\Controllers\ClientCommsTrackingController::class, 'unsubscribe']);
});

Route::middleware('throttle:public-pay')->group(function () {
    Route::get('m/o/{token}.gif', [\App\Modules\Marketing\TrackingController::class, 'open']);
    Route::get('m/c/{token}', [\App\Modules\Marketing\TrackingController::class, 'click']);
    Route::match(['get', 'post'], 'm/u/{token}', [\App\Modules\Marketing\TrackingController::class, 'unsubscribe']);
    Route::get('m/preview/{key}', [\App\Modules\Marketing\TrackingController::class, 'preview']);
});

// Formulaires d'inscription et pages de destination
Route::middleware('throttle:public-pay')->group(function () {
    Route::get('f/confirm/{token}', [\App\Modules\Marketing\PublicFormController::class, 'confirm']);
    Route::get('f/{slug}', [\App\Modules\Marketing\PublicFormController::class, 'show']);
    Route::post('f/{slug}', [\App\Modules\Marketing\PublicFormController::class, 'submit']);
});

// Suivi public d'une livraison
Route::middleware('throttle:public-pay')->get('t/{token}', [\App\Modules\Logistique\TrackingController::class, 'show']);

// Portail client : accès par lien personnel (sans mot de passe)
Route::middleware('throttle:portal')->prefix('portail')->group(function () {
    $c = \App\Modules\Portail\PortalController::class;
    Route::get('/', [$c, 'login']);
    Route::post('/', [$c, 'sendLink'])->middleware('throttle:6,10');
    Route::get('{token}', [$c, 'home']);
    Route::get('{token}/releve', [$c, 'statement']);
    Route::get('{token}/livraisons', [$c, 'deliveries']);
    Route::get('{token}/messages', [$c, 'messages']);
    Route::get('{token}/interventions', [$c, 'interventions']);
    Route::get('{token}/rendez-vous', [$c, 'appointments']);
    Route::get('{token}/fidelite', [$c, 'loyalty']);
    Route::get('{token}/prestations', [$c, 'prestations']);
    Route::get('{token}/support', [$c, 'support']);
    Route::post('{token}/interventions', [$c, 'requestIntervention']);
    Route::post('{token}/messages', [$c, 'postMessage']);
    Route::get('{token}/document/{id}', [$c, 'document'])->whereUuid('id');
    Route::get('{token}/document/{id}/payer', [$c, 'pay'])->whereUuid('id');
    Route::post('{token}/document/{id}/accepter', [$c, 'accept'])->whereUuid('id');
    Route::post('{token}/document/{id}/refuser', [$c, 'refuse'])->whereUuid('id');
    Route::get('{token}/{kind}', [$c, 'documents'])->whereIn('kind', ['factures', 'devis', 'commandes']);
});

// Rapport d'intervention public (lien envoyé au client)
Route::middleware('throttle:public-pay')->get('r/{token}', [\App\Modules\Interventions\ReportController::class, 'show']);

// Prise de rendez-vous en ligne
Route::middleware('throttle:portal')->prefix('rdv')->group(function () {
    $c = \App\Modules\RendezVous\BookingController::class;
    Route::get('a/{token}', [$c, 'manage']);
    Route::get('a/{token}/calendrier.ics', [$c, 'ics']);
    Route::post('a/{token}/annuler', [$c, 'cancel'])->middleware('throttle:10,10');
    Route::get('{slug}', [$c, 'show']);
    Route::get('{slug}/confirmer', [$c, 'confirm']);
    Route::post('{slug}', [$c, 'store'])->middleware('throttle:10,10');
});

// Événements : plan des stands et demande de réservation par les exposants
Route::middleware('throttle:portal')->prefix('salon')->group(function () {
    $c = \App\Modules\Evenements\PublicEventController::class;
    Route::get('{token}', [$c, 'show']);
    Route::get('{token}/r/{btoken}', [$c, 'booking']);
    Route::get('{token}/r/{btoken}/espace', [$c, 'space']);
    Route::post('{token}/r/{btoken}/espace', [$c, 'saveSpace'])->middleware('throttle:20,10');
    Route::get('{token}/exposants', [$c, 'directory']);
    Route::post('{token}', [$c, 'store'])->middleware('throttle:6,10');
});

// Billetterie : achat en ligne, commande avec QR codes, billet
Route::middleware('throttle:portal')->group(function () {
    $c = \App\Modules\Billetterie\PublicTicketController::class;
    Route::get('billetterie/c/{token}', [$c, 'order']);
    Route::get('billetterie/{token}', [$c, 'show']);
    Route::post('billetterie/{token}', [$c, 'buy'])->middleware('throttle:10,10');
    Route::get('billet/{token}', [$c, 'ticket']);
    Route::get('avis/{token}', [$c, 'feedback']);
    Route::post('avis/{token}', [$c, 'saveFeedback'])->middleware('throttle:10,10');
});

// Livrables soumis à la validation du client
Route::middleware('throttle:portal')->group(function () {
    $c = \App\Modules\Prestations\PublicDeliverableController::class;
    Route::get('livrable/{token}', [$c, 'show']);
    Route::get('livrable/{token}/fichier', [$c, 'file']);
    Route::get('rapport/{token}', [$c, 'report']);
    Route::post('livrable/{token}', [$c, 'decide'])->middleware('throttle:10,10');
});

// Centre d'aide et suivi des tickets clients (module Service client)
Route::middleware('throttle:portal')->group(function () {
    $c = \App\Modules\Helpdesk\PublicHelpController::class;
    Route::get('aide/{slug}', [$c, 'home']);
    Route::get('aide/{slug}/contact', [$c, 'contact']);
    Route::post('aide/{slug}/contact', [$c, 'store'])->middleware('throttle:6,10');
    Route::get('aide/{slug}/a/{article}', [$c, 'article']);
    Route::post('aide/{slug}/a/{article}/vote', [$c, 'vote'])->middleware('throttle:20,10');
    Route::get('support/{token}', [$c, 'show']);
    Route::post('support/{token}', [$c, 'reply'])->middleware('throttle:10,10');
    Route::post('support/{token}/avis', [$c, 'rate'])->middleware('throttle:10,10');
    Route::get('support/{token}/fichier/{id}', [$c, 'file'])->whereUuid('id');
});

// Portail fournisseurs (appels d'offres, commandes, factures, paiements)
Route::middleware('throttle:portal')->prefix('fournisseur/{token}')->group(function () {
    $c = \App\Modules\Fournisseurs\SupplierPortalController::class;
    Route::get('/', [$c, 'home']);
    Route::get('ao', [$c, 'tenders']);
    Route::get('ao/{id}', [$c, 'tender'])->whereUuid('id');
    Route::post('ao/{id}', [$c, 'bid'])->whereUuid('id')->middleware('throttle:20,10');
    Route::get('commandes', [$c, 'orders']);
    Route::get('commandes/{id}', [$c, 'order'])->whereUuid('id');
    Route::post('commandes/{id}', [$c, 'ack'])->whereUuid('id')->middleware('throttle:20,10');
    Route::get('factures', [$c, 'invoices']);
    Route::post('factures', [$c, 'deposit'])->middleware('throttle:10,10');
    Route::get('paiements', [$c, 'payments']);
});

// Comparatif : un fournisseur consulté répond en ligne (lien personnel)
Route::middleware('throttle:portal')->prefix('offre/{token}')->group(function () {
    $c = \App\Modules\Appro\OfferPortalController::class;
    Route::get('/', [$c, 'show']);
    Route::post('/', [$c, 'submit'])->middleware('throttle:20,10');
    Route::post('decliner', [$c, 'decline'])->middleware('throttle:10,10');
    Route::post('message', [$c, 'message'])->middleware('throttle:10,10');
    Route::get('cahier-des-charges.pdf', [$c, 'specPdf']);
});

// Offres d'emploi et candidatures (module Recrutement)
Route::middleware('throttle:portal')->prefix('emplois')->group(function () {
    $c = \App\Modules\Recrutement\CareersController::class;
    Route::get('{slug}', [$c, 'index']);
    Route::get('{slug}/{id}', [$c, 'show']);
    Route::post('{slug}/{id}', [$c, 'apply'])->middleware('throttle:6,10');
});

// Signature électronique : lien personnel du signataire, vérification publique
Route::middleware('throttle:portal')->group(function () {
    $c = \App\Modules\Signature\SignerController::class;
    Route::get('signer/{token}', [$c, 'show']);
    Route::get('signer/{token}/document', [$c, 'document']);
    Route::post('signer/{token}/code', [$c, 'code'])->middleware(['throttle:signer', 'throttle:signer-code']);
    Route::post('signer/{token}/verifier', [$c, 'verify'])->middleware('throttle:signer');
    Route::post('signer/{token}/signer', [$c, 'sign'])->middleware('throttle:signer');
    Route::post('signer/{token}/refuser', [$c, 'decline'])->middleware('throttle:signer');
    Route::get('signature/verifier', [$c, 'verifyForm']);
    Route::post('signature/verifier', [$c, 'verifyUpload'])->middleware('throttle:public-pay');
});

// Documents (GED) : liens de partage
Route::middleware('throttle:portal')->group(function () {
    $c = \App\Modules\Documents\ShareController::class;
    Route::get('d/{token}', [$c, 'show']);
    Route::post('d/{token}', [$c, 'unlock'])->middleware('throttle:10,10');
    Route::get('d/{token}/telecharger/{file?}', [$c, 'download']);
});

// Agenda : abonnement iCalendar personnel
Route::middleware('throttle:public-pay')->get('agenda/flux/{token}.ics', [\App\Modules\Agenda\FeedController::class, 'show']);

// Boutique en ligne : flux catalogue (Meta Commerce, Google Merchant Center)
Route::middleware('throttle:public-pay')->get('boutique/flux/{token}/{format}', [\App\Modules\Ecommerce\FeedController::class, 'show'])->where('token', '[A-Za-z0-9]{32}')->where('format', 'meta\.csv|google\.xml');

// Sites vitrines (module « sites ») : adresse de secours /site/{adresse}, aperçu signé des modèles
Route::middleware('throttle:portal')->group(function () {
    Route::match(['get', 'post'], 'site/{slug}/{path?}', [\App\Modules\Sites\PublicSiteController::class, 'bySlug'])->where('slug', '[a-z0-9-]+')->where('path', '.*');
    Route::get('sites/modeles/{key}', [\App\Modules\Sites\PublicSiteController::class, 'template'])->name('sites.template');
});

// Réunions & webinaires (module « visio ») : salle dans le navigateur, page d'inscription, rediffusion, compte rendu partagé
Route::middleware('throttle:portal')->group(function () {
    Route::get('visio/rapport/{token}', [\App\Modules\Visio\RoomController::class, 'report'])->where('token', '[A-Za-z0-9]{40}');
    Route::get('visio/{code}', [\App\Modules\Visio\RoomController::class, 'page'])->where('code', '[a-z]{3}-[a-z]{4}-[a-z]{3}');
    $w = \App\Modules\Visio\WebinarController::class;
    Route::get('webinaire/{slug}', [$w, 'show']);
    Route::post('webinaire/{slug}', [$w, 'register'])->middleware('throttle:10,10');
    Route::get('webinaire/{slug}/replay', [$w, 'replay']);
    Route::get('webinaire/{slug}/video', [$w, 'video']);
    Route::get('webinaire/{slug}/calendrier.ics', [$w, 'ics']);
});

// Retour de la banque après autorisation (open banking, module Trésorerie)
Route::middleware('throttle:portal')->get('banque/retour/{id}', [\App\Modules\Tresorerie\Bank\BankController::class, 'callback'])->whereUuid('id');

// Liens courts, smartlinks et QR codes (module Liens) : /l/{alias}
Route::middleware('throttle:240,1')->group(function () {
    $lk = \App\Modules\Liens\PublicLinkController::class;
    Route::match(['get', 'post'], 'l/{slug}', [$lk, 'open'])->where('slug', '[A-Za-z0-9-]{3,64}')->middleware('weblang');
    Route::get('l/{slug}/b/{i}', [$lk, 'button'])->where('slug', '[A-Za-z0-9-]{3,64}')->whereNumber('i');
    Route::get('l/{slug}/qr.png', [$lk, 'qr'])->where('slug', '[A-Za-z0-9-]{3,64}');
});

// Réseaux sociaux : retour de connexion OAuth (Meta, Threads, LinkedIn, X, TikTok, Pinterest, Google)
Route::middleware('throttle:portal')->get('oauth/social/{provider}/retour', \App\Modules\Social\OAuthCallbackController::class)->whereIn('provider', ['meta', 'threads', 'linkedin', 'x', 'tiktok', 'pinterest', 'google']);

// Boîte mail : retour de connexion Microsoft 365 / Google, pièces jointes par lien signé
Route::middleware('throttle:portal')->get('oauth/{provider}/retour', \App\Modules\Mailbox\OAuthCallbackController::class)->whereIn('provider', ['microsoft', 'google']);
Route::middleware('throttle:300,1')->get('mailbox/media/{name}', [\App\Modules\Mailbox\MailboxController::class, 'media'])->where('name', '[A-Za-z0-9]{32}\.(png|jpg|gif|webp)');
Route::middleware('throttle:portal')->get('application-mobile', [\App\Modules\Mailbox\MailboxController::class, 'mobilePage']);
Route::middleware(['signed', 'throttle:120,1'])->get('mailbox/fichier/{id}/{att}', [\App\Modules\Mailbox\MailboxController::class, 'signedAttachment'])->whereNumber('id')->name('mailbox.attachment');

// WhatsApp : fichiers envoyés (récupérés par le fournisseur) et pièces des conversations (lien signé)
Route::middleware('throttle:300,1')->get('whatsapp/fichier/{name}', [\App\Modules\Whatsapp\WebhookController::class, 'file']);
Route::middleware(['signed', 'throttle:300,1'])->get('whatsapp/media/{id}', [\App\Modules\Whatsapp\InboxController::class, 'media'])->whereNumber('id')->name('whatsapp.media');

// Avis Google : retour de connexion, page d'avis reçue par le client, QR code du comptoir et affiche
Route::middleware('throttle:portal')->group(function () {
    $a = \App\Modules\AvisGoogle\AvisController::class;
    Route::get('oauth/google-business/retour', [$a, 'oauthCallback']);
    Route::get('note/l/{token}', [$a, 'qr'])->where('token', '[a-z0-9]{32}');
    Route::get('note/l/{token}/affiche', [$a, 'poster'])->where('token', '[a-z0-9]{32}');
    Route::get('note/{token}', [$a, 'landing'])->where('token', '[a-z0-9]{40}');
    Route::post('note/{token}', [$a, 'feedback'])->where('token', '[a-z0-9]{40}')->middleware('throttle:10,10');
});

// Questionnaires et sondages : réponse sans compte (lien ou QR code)
Route::middleware('throttle:portal')->prefix('q')->group(function () {
    $c = \App\Modules\Questionnaires\PublicSurveyController::class;
    Route::get('{token}', [$c, 'show']);
    Route::post('{token}', [$c, 'submit'])->middleware('throttle:20,10');
});

// Retour de la connexion en un clic d'une boutique WooCommerce (l'application suit l'état de son côté)
Route::get('woocommerce/connecte', function (\Illuminate\Http\Request $r) {
    $ok = $r->query('success') !== '0';

    return response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>WooCommerce</title>'
        .'<body style="font-family:Segoe UI,Arial,sans-serif;background:#F5F7FB;color:#0B1530;display:grid;place-items:center;min-height:100vh;margin:0">'
        .'<div style="background:#fff;border-radius:14px;padding:32px 36px;max-width:440px;box-shadow:0 6px 24px rgba(11,21,48,.08)"><h1 style="font-size:22px;margin:0 0 10px">'
        .($ok ? 'Boutique autorisée' : 'Connexion refusée').'</h1><p style="line-height:1.5;margin:0">'
        .($ok ? 'Revenez dans '.e(config('erp.name', 'Yomara ERP')).' : la connexion se termine toute seule. Vous pouvez fermer cet onglet.' : 'L\'accès n\'a pas été accordé. Recommencez depuis le logiciel si besoin.')
        .'</p></div>')->header('Content-Type', 'text/html; charset=utf-8');
});
