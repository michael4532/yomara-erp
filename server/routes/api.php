<?php

use App\Http\Controllers\Api\Admin\CompanyAdminController;
use App\Http\Controllers\Api\Admin\ModuleAdminController;
use App\Http\Controllers\Api\Admin\PlanAdminController;
use App\Http\Controllers\Api\Admin\PlatformController;
use App\Http\Controllers\Api\Admin\StoreAdminController;
use App\Http\Controllers\Api\Admin\SystemController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MessagingController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PresenceController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\Store\CompanyPaymentController;
use App\Http\Controllers\Api\Store\StoreController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\PublicPaymentController;
use Illuminate\Support\Facades\Route;

Route::get('ping', [AuthController::class, 'ping']);

Route::middleware('installed')->group(function () {

    // ---------- Authentification ----------
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/google', [AuthController::class, 'google'])->middleware('throttle:login');
    // Serveur de mises à jour (plateforme) : interrogé par les installations clientes
    Route::get('updates/latest', [\App\Http\Controllers\Api\UpdateController::class, 'latest'])->middleware('throttle:60,1');
    Route::get('updates/download/{id}/{sha}', [\App\Http\Controllers\Api\UpdateController::class, 'download'])->whereNumber('id')->middleware('throttle:20,1');
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('auth/forgot', [\App\Http\Controllers\PasswordResetController::class, 'request'])->middleware('throttle:5,10');
    // Licences : activation et vérification périodique des installations chez les clients (plateforme SaaS)
    Route::post('license/activate', [\App\Http\Controllers\Api\Licensing\LicenseServerController::class, 'activate'])->middleware('throttle:20,1');
    Route::post('license/link', [\App\Http\Controllers\Api\Licensing\LicenseServerController::class, 'link'])->middleware('throttle:10,1');
    Route::post('license/heartbeat', [\App\Http\Controllers\Api\Licensing\LicenseServerController::class, 'heartbeat'])->middleware('throttle:60,1');
    // Live chat du site de présentation (visiteurs)
    Route::post('livechat', [\App\Http\Controllers\Api\LiveChatController::class, 'start'])->middleware('throttle:10,10');
    Route::get('livechat/{token}', [\App\Http\Controllers\Api\LiveChatController::class, 'show'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:120,1');
    Route::post('livechat/{token}', [\App\Http\Controllers\Api\LiveChatController::class, 'send'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:30,1');
    Route::get('public/contact', fn () => ['contact' => \App\Store\ModuleStore::contact(), 'mode' => config('erp.mode')])->middleware('throttle:60,1');
    Route::get('license/public-key', [\App\Http\Controllers\Api\Licensing\LicenseServerController::class, 'publicKey'])->middleware('throttle:60,1');

    // Flux de données (lien secret) et documentation de l'API
    Route::get('feeds/{token}.{format}', [\App\Http\Controllers\FeedController::class, 'show'])->where(['token' => '[A-Za-z0-9]{40}', 'format' => 'csv|json'])->middleware('throttle:public-pay');
    Route::get('docs', \App\Http\Controllers\ApiDocsController::class);

    // Relevés bancaires reçus par e-mail (webhook du service de réception, jeton par connexion)
    Route::post('bank-inbound/{token}', [\App\Modules\Tresorerie\Bank\BankController::class, 'inbound'])->where('token', '[a-z0-9]{32}')->middleware('throttle:inbound');
    // WhatsApp : réception des messages et statuts (Meta, UltraMsg, Green API), adresse secrète par société
    Route::get('whatsapp-webhook/{token}', [\App\Modules\Whatsapp\WebhookController::class, 'verify'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:inbound');
    Route::post('whatsapp-webhook/{token}', [\App\Modules\Whatsapp\WebhookController::class, 'receive'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:inbound');
    // Meta (Facebook, Instagram) : prospects des publicités, commentaires et messages — webhook unique de l'application de la plateforme
    Route::get('meta-webhook', [\App\Modules\Social\MetaWebhookController::class, 'verify'])->middleware('throttle:inbound');
    Route::post('meta-webhook', [\App\Modules\Social\MetaWebhookController::class, 'receive'])->middleware('throttle:inbound');
    // Prospects envoyés par un formulaire externe (Google Ads, TikTok, LinkedIn, Zapier, Make, WordPress…) : adresse secrète par source
    Route::post('leads-in/{token}', [\App\Modules\Acquisition\PublicLeadController::class, 'receive'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:inbound');
    // WooCommerce : commandes créées / modifiées sur le site (signature HMAC par boutique)
    Route::post('woocommerce-auth/{state}', [\App\Modules\Woocommerce\WooController::class, 'authorizeCallback'])->where('state', '[A-Za-z0-9]{40}')->middleware('throttle:inbound');
    Route::post('woocommerce-webhook/{token}', [\App\Modules\Woocommerce\WooController::class, 'webhook'])->where('token', '[a-z0-9]{40}')->middleware('throttle:inbound');
    // GitHub / GitLab : commits, pull requests et tags font avancer les tâches du module Développement
    Route::post('dev-git/{token}', [\App\Modules\Dev\DevController::class, 'git'])->where('token', '[a-z0-9]{40}')->middleware('throttle:inbound');
    Route::post('shopify-webhook/{token}', [\App\Modules\Shopify\ShopifyController::class, 'webhook'])->where('token', '[a-z0-9]{40}')->middleware('throttle:inbound');

    // Salles de visioconférence (module « visio ») : participants identifiés par leur lien personnel, sans compte
    Route::prefix('visio-room/{code}')->where(['code' => '[a-z]{3}-[a-z]{4}-[a-z]{3}'])->middleware('throttle:visio')->group(function () {
        $v = \App\Modules\Visio\RoomController::class;
        Route::get('/', [$v, 'info']);
        Route::post('join', [$v, 'join'])->middleware('throttle:visio-join');
        Route::get('rtc', [$v, 'rtc']);
        Route::get('poll', [$v, 'poll']);
        Route::post('state', [$v, 'state']);
        Route::post('chat', [$v, 'chat']);
        Route::post('react', [$v, 'react']);
        Route::post('caption', [$v, 'caption']);
        Route::post('signal', [$v, 'signal']);
        Route::post('failure', [$v, 'failure']);
        Route::post('healthy', [$v, 'healthy']);
        Route::post('leave', [$v, 'leave']);
        Route::post('control', [$v, 'control']);
        Route::post('questions', [$v, 'ask']);
        Route::post('questions/{id}/vote', [$v, 'vote'])->whereNumber('id');
        Route::post('questions/{id}/answer', [$v, 'answer'])->whereNumber('id');
        Route::post('polls', [$v, 'createPoll']);
        Route::post('polls/{id}/vote', [$v, 'votePoll'])->whereNumber('id');
        Route::post('polls/{id}/close', [$v, 'closePoll'])->whereNumber('id');
        Route::post('recordings', [$v, 'startRecording']);
        Route::post('recordings/{id}/chunk', [$v, 'chunk'])->whereNumber('id');
        Route::post('recordings/{id}/stop', [$v, 'stopRecording'])->whereNumber('id');
    });

    // Notifications des prestataires de paiement (aucune confiance : le statut est revérifié chez eux)
    Route::match(['get', 'post'], 'payments/{tx}/notify', [PublicPaymentController::class, 'notify'])->whereUuid('tx')->middleware('throttle:public-pay');
    Route::post('payments/webhook/{driver}', [PublicPaymentController::class, 'providerWebhook'])->whereIn('driver', array_keys(\App\Payments\GatewayRegistry::WEBHOOKS))->middleware('throttle:public-pay');
    // Réception des e-mails et messages WhatsApp → tickets du service client
    Route::post('hooks/mail/{token}', [\App\Modules\Helpdesk\Inbound\InboundController::class, 'mail'])->middleware('throttle:inbound');
    Route::get('hooks/whatsapp/{token}', [\App\Modules\Helpdesk\Inbound\InboundController::class, 'verify'])->middleware('throttle:inbound');
    Route::post('hooks/whatsapp/{token}', [\App\Modules\Helpdesk\Inbound\InboundController::class, 'whatsapp'])->middleware('throttle:inbound');
    // SMS de confirmation Mobile Money transférés depuis le téléphone marchand → encaissements rapprochés
    Route::post('hooks/momo/{token}', [\App\Modules\Tresorerie\Momo\MomoController::class, 'hook'])->middleware('throttle:inbound');

    Route::middleware(['auth:sanctum', 'presence'])->group(function () {
        // Tâches personnelles (« Ma journée »)
        Route::get('me/todos', [\App\Http\Controllers\Api\TodoController::class, 'index']);
        Route::post('me/todos', [\App\Http\Controllers\Api\TodoController::class, 'store']);
        Route::put('me/todos/{id}', [\App\Http\Controllers\Api\TodoController::class, 'update'])->whereNumber('id');
        Route::delete('me/todos/{id}', [\App\Http\Controllers\Api\TodoController::class, 'destroy'])->whereNumber('id');
        // Mise à jour de cette installation (administrateur)
        Route::prefix('system/update')->controller(\App\Http\Controllers\Api\UpdateController::class)->group(function () {
            Route::get('status', 'status');
            Route::get('preflight', 'preflight');
            Route::post('install', 'install');
            Route::post('finish', 'finish');
            Route::post('rollback', 'rollback');
        });
        // Sauvegardes de l'installation (administrateur du logiciel)
        Route::prefix('system/backups')->controller(\App\Http\Controllers\Api\BackupController::class)->group(function () {
            Route::get('/', 'index');
            Route::put('/', 'update');
            Route::post('run', 'run');
            Route::post('restore', 'restore');
            Route::post('connect/{provider}', 'connect')->whereIn('provider', ['google', 'dropbox']);
            Route::delete('connect/{provider}', 'disconnect')->whereIn('provider', ['google', 'dropbox']);
            Route::get('remote/{provider}', 'remote')->whereIn('provider', ['google', 'dropbox']);
            Route::get('files/{name}/link', 'link')->where('name', '[A-Za-z0-9._-]+');
            Route::delete('files/{name}', 'destroy')->where('name', '[A-Za-z0-9._-]+');
        });
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/password', [AuthController::class, 'changePassword']);
        // Notifications push : l'application enregistre le jeton Firebase de l'appareil
        Route::post('me/push-token', [\App\Http\Controllers\Api\PushController::class, 'store'])->middleware('throttle:30,1');
        Route::delete('me/push-token', [\App\Http\Controllers\Api\PushController::class, 'destroy']);
        Route::post('me/push-test', [\App\Http\Controllers\Api\PushController::class, 'test'])->middleware('throttle:5,1');
        Route::post('me/delete-account', [\App\Http\Controllers\AccountDeletionController::class, 'fromApp'])->middleware('throttle:5,10');
        Route::post('presence/ping', [PresenceController::class, 'ping']);

        // ---------- Espace société ----------
        Route::middleware('tenant')->group(function () {
            Route::get('bootstrap', BootstrapController::class);
            Route::get('dashboard', DashboardController::class);
            Route::get('me/day', \App\Http\Controllers\Api\MyDayController::class);
            // Guide de démarrage vu (ou à revoir : done=false)
            Route::post('me/onboarded', function (\Illuminate\Http\Request $r) {
                $r->user()->forceFill(['onboarded_at' => $r->boolean('done', true) ? now() : null])->save();

                return ['ok' => true];
            });
            // Langue de l'utilisateur (et, pour un administrateur, langue par défaut de la société)
            Route::put('me/locale', function (\Illuminate\Http\Request $r) {
                $d = $r->validate(['locale' => 'required|in:'.implode(',', \App\Support\Tr::LOCALES), 'company_default' => 'nullable|boolean']);
                $u = $r->user();
                $u->forceFill(['locale' => $d['locale']])->save();
                if (! empty($d['company_default']) && $u->hasPermission('core.settings.manage')) {
                    $c = app('tenant');
                    $c->settings = array_merge($c->settings ?? [], ['locale' => $d['locale']]);
                    $c->save();
                }

                return ['ok' => true, 'locale' => $u->locale];
            });
            // Préférences d'interface (favoris, menu…) : enregistrées par utilisateur, retrouvées à chaque connexion
            Route::put('me/ui-prefs', function (\Illuminate\Http\Request $r) {
                $d = $r->validate(['prefs' => 'required|array|max:40']);
                $u = $r->user();
                $clean = $u->ui_prefs ?? [];
                foreach ($d['prefs'] as $k => $v) {
                    if (! is_string($k) || ! preg_match('/^[a-z0-9_]{1,40}$/', $k)) {
                        continue;
                    }
                    if ($v === null) {
                        unset($clean[$k]);
                    } elseif (is_bool($v)) {
                        $clean[$k] = $v;
                    } elseif (is_array($v)) {
                        $clean[$k] = array_values(array_slice(array_map(fn ($x) => mb_substr((string) $x, 0, 80), array_filter($v, 'is_scalar')), 0, 60));
                    }
                }
                abort_if(strlen(json_encode($clean)) > 20000, 422, 'Préférences trop volumineuses.');
                $u->forceFill(['ui_prefs' => $clean])->save();

                return ['ok' => true];
            })->middleware('throttle:60,1');
            Route::get('dashboard/insights', \App\Http\Controllers\Api\InsightsController::class);
            // Intégrations : webhooks sortants, clés d'API, flux de données
            Route::middleware('perm:core.settings.manage')->prefix('integrations')->group(function () {
                $ic = \App\Http\Controllers\Api\IntegrationsController::class;
                Route::get('/', [$ic, 'index']);
                Route::post('webhooks', [$ic, 'storeEndpoint']);
                Route::put('webhooks/{id}', [$ic, 'updateEndpoint'])->whereNumber('id');
                Route::delete('webhooks/{id}', [$ic, 'destroyEndpoint'])->whereNumber('id');
                Route::post('webhooks/{id}/test', [$ic, 'test'])->whereNumber('id');
                Route::post('webhooks/{id}/secret', [$ic, 'secret'])->whereNumber('id');
                Route::get('webhooks/{id}/deliveries', [$ic, 'deliveries'])->whereNumber('id');
                Route::get('deliveries/{id}', [$ic, 'delivery'])->whereUuid('id');
                Route::post('deliveries/{id}/redeliver', [$ic, 'redeliver'])->whereUuid('id');
                Route::post('keys', [$ic, 'storeKey']);
                Route::delete('keys/{id}', [$ic, 'destroyKey'])->whereNumber('id');
                Route::post('channels', [$ic, 'storeChannel']);
                Route::put('channels/{id}', [$ic, 'updateChannel'])->whereNumber('id');
                Route::delete('channels/{id}', [$ic, 'destroyChannel'])->whereNumber('id');
                Route::post('channels/{id}/test', [$ic, 'testChannel'])->whereNumber('id');
                Route::post('feeds', [$ic, 'storeFeed']);
                Route::delete('feeds/{id}', [$ic, 'destroyFeed'])->whereNumber('id');
            });
            // Encaissement Mobile Money en direct (caisse, factures)
            Route::middleware('perm:pos.use,pos.manage,ventes.payments.manage')->prefix('payments/collect')->group(function () {
                $cc = \App\Http\Controllers\Api\CollectController::class;
                Route::get('gateways', [$cc, 'gateways']);
                Route::post('/', [$cc, 'start']);
                Route::get('{id}', [$cc, 'status'])->whereUuid('id');
                Route::post('{id}/cancel', [$cc, 'cancel'])->whereUuid('id');
            });
            Route::get('notifications', [NotificationController::class, 'index']);
            Route::post('notifications/read', [NotificationController::class, 'read']);
            Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->whereNumber('id');

            Route::get('sync/pull', [SyncController::class, 'pull']);
            Route::post('sync/push', [SyncController::class, 'push']);

            Route::get('company', [CompanyController::class, 'show']);
            Route::put('company', [CompanyController::class, 'update'])->middleware('perm:core.settings.manage');

            // ---------- Canaux d'envoi (e-mail, SMS, WhatsApp) ----------
            Route::get('messaging/setup', [MessagingController::class, 'setup']);
            Route::post('messaging/send', [MessagingController::class, 'send'])->middleware('throttle:60,1');
            Route::middleware('perm:core.settings.manage')->group(function () {
                Route::put('messaging/channels/{channel}', [MessagingController::class, 'update'])->whereIn('channel', ['email', 'sms', 'whatsapp']);
                Route::post('messaging/test', [MessagingController::class, 'test']);
                Route::get('messaging/log', [MessagingController::class, 'log']);
            });

            // ---------- Support client (gratuit) ----------
            Route::prefix('support')->controller(\App\Http\Controllers\Api\SupportController::class)->group(function () {
                Route::get('status', 'status');
                Route::get('conversations', 'index');
                Route::post('conversations', 'store')->middleware('throttle:20,1');
                Route::get('conversations/{id}', 'show')->whereNumber('id');
                Route::post('conversations/{id}/messages', 'reply')->whereNumber('id')->middleware('throttle:60,1');
                Route::post('conversations/{id}/close', 'close')->whereNumber('id');
                Route::put('contacts', 'setContacts');
            });

            // ---------- Boutique des modules ----------
            Route::get('store', [StoreController::class, 'index']);
            // Licence de cette installation (installation unique)
            Route::get('license', [\App\Http\Controllers\Api\Licensing\LocalLicenseController::class, 'show']);
            // Parrainage (plateforme SaaS)
            // Annonce de l'éditeur reçue par la société (notification « announcement »)
            Route::get('announcements/{ref}', function (\Illuminate\Http\Request $r, string $ref) {
                abort_unless(preg_match('/^([ca])(\d+)$/', $ref, $m), 404);
                $col = $m[1] === 'c' ? 'campaign_id' : 'automation_id';
                abort_unless(\App\Models\PlatformDelivery::where($col, (int) $m[2])->where('company_id', app('tenant')->id)->where('user_id', $r->user()->id)->exists(), 404);
                $msg = $m[1] === 'c' ? \App\Models\PlatformCampaign::findOrFail((int) $m[2]) : \App\Models\PlatformAutomation::findOrFail((int) $m[2]);
                $vars = \App\Platform\ClientComms::vars(app('tenant'), $r->user());
                $html = app(\App\Platform\ClientComms::class)->emailHtml($msg, $vars);

                return ['title' => \App\Modules\Marketing\EmailRenderer::vars((string) ($msg->title ?: $msg->subject), $vars), 'text' => \App\Platform\ClientComms::plain($msg, $vars),
                    'html' => $msg->format === 'text' ? null : $html, 'cta_label' => $msg->cta_label, 'cta_url' => $msg->cta_url ? \App\Modules\Marketing\EmailRenderer::vars($msg->cta_url, $vars) : null];
            })->where('ref', '[ca]\d+');
            Route::get('referral', fn () => abort_unless(config('erp.mode') === 'saas', 404) ?? \App\Store\Referrals::summary(app('tenant')));
            Route::middleware('perm:core.settings.manage')->group(function () {
                Route::post('license/activate-local', [\App\Http\Controllers\Api\Licensing\LocalLicenseController::class, 'activate'])->middleware('throttle:10,1');
                Route::post('license/check', [\App\Http\Controllers\Api\Licensing\LocalLicenseController::class, 'check'])->middleware('throttle:10,1');
                // « Mes licences » (plateforme SaaS)
                Route::get('licenses', [\App\Http\Controllers\Api\Licensing\LicenseController::class, 'index']);
                Route::post('licenses', [\App\Http\Controllers\Api\Licensing\LicenseController::class, 'store'])->middleware('throttle:30,1');
                Route::get('licenses/{id}', [\App\Http\Controllers\Api\Licensing\LicenseController::class, 'show'])->whereNumber('id');
                Route::put('licenses/{id}', [\App\Http\Controllers\Api\Licensing\LicenseController::class, 'update'])->whereNumber('id');
                Route::post('licenses/{id}/reset', [\App\Http\Controllers\Api\Licensing\LicenseController::class, 'reset'])->whereNumber('id');
                Route::post('licenses/{id}/revoke', [\App\Http\Controllers\Api\Licensing\LicenseController::class, 'revoke'])->whereNumber('id');
            });
            Route::post('store/orders/{id}/verify', [StoreController::class, 'verify'])->middleware('throttle:store')->whereNumber('id');
            Route::middleware('perm:core.settings.manage')->group(function () {
                Route::post('store/orders', [StoreController::class, 'order'])->middleware('throttle:store');
                Route::post('store/promo', [StoreController::class, 'checkPromo'])->middleware('throttle:store');
                Route::post('store/orders/{id}/declare', [StoreController::class, 'declare'])->whereNumber('id');
                Route::post('store/orders/{id}/cancel', [StoreController::class, 'cancel'])->whereNumber('id');
                Route::post('store/trial/{key}', [StoreController::class, 'trial']);

                // Moyens de paiement de la société (pour ses propres clients)
                Route::get('payments/setup', [CompanyPaymentController::class, 'setup']);
                Route::post('payments/gateways', [CompanyPaymentController::class, 'store']);
                Route::put('payments/gateways/{id}', [CompanyPaymentController::class, 'update'])->whereNumber('id');
                Route::delete('payments/gateways/{id}', [CompanyPaymentController::class, 'destroy'])->whereNumber('id');
                Route::post('payments/gateways/{id}/test', [CompanyPaymentController::class, 'test'])->whereNumber('id');
                Route::put('payments/bank-accounts', [CompanyPaymentController::class, 'bankAccounts']);
            });
            Route::post('payments/pay-link/{id}', [CompanyPaymentController::class, 'payLink'])->middleware(['module:ventes', 'perm:ventes.view,ventes.manage']);
            Route::middleware(['module:ventes', 'perm:ventes.manage,ventes.payments.manage'])->group(function () {
                Route::get('payments/links', [\App\Http\Controllers\Api\Store\PaymentLinkController::class, 'index']);
                Route::post('payments/links', [\App\Http\Controllers\Api\Store\PaymentLinkController::class, 'store']);
                Route::post('payments/links/{id}/cancel', [\App\Http\Controllers\Api\Store\PaymentLinkController::class, 'cancel'])->whereNumber('id');
            });

            Route::middleware('perm:core.users.manage')->group(function () {
                Route::get('users', [UserController::class, 'index']);
                Route::get('users/form-meta', [UserController::class, 'formMeta']);
                Route::post('users', [UserController::class, 'store']);
                Route::put('users/{id}', [UserController::class, 'update'])->whereNumber('id');
                Route::delete('users/{id}', [UserController::class, 'destroy'])->whereNumber('id');
                Route::get('users/{id}/access', [UserController::class, 'access'])->whereNumber('id');
                Route::put('users/{id}/access', [UserController::class, 'updateAccess'])->whereNumber('id');

                Route::get('permissions', [RoleController::class, 'permissions']);
                Route::get('roles', [RoleController::class, 'index']);
                Route::post('roles', [RoleController::class, 'store']);
                Route::put('roles/{id}', [RoleController::class, 'update'])->whereNumber('id');
                Route::delete('roles/{id}', [RoleController::class, 'destroy'])->whereNumber('id');
            });
        });

        // ---------- Super administration ----------
        Route::prefix('admin')->middleware('superadmin')->group(function () {
            // Offre d'essai gratuit (durée standard, offre spéciale datée)
            Route::get('trial-offer', fn () => \App\Platform\SubscriptionLifecycle::offer());
            Route::put('trial-offer', fn (\Illuminate\Http\Request $r) => \App\Platform\SubscriptionLifecycle::saveOffer($r->validate([
                'standard_days' => 'required|integer|min:0|max:365', 'promo_days' => 'nullable|integer|min:0|max:365',
                'promo_from' => 'nullable|date', 'promo_until' => 'nullable|date', 'promo_label' => 'nullable|string|max:120',
            ])));

            // Réseaux sociaux : applications OAuth de la plateforme (Meta, LinkedIn, X, TikTok, Pinterest, Google, Threads)
            Route::get('social-apps', [\App\Modules\Social\SocialController::class, 'apps']);
            Route::put('social-apps/{provider}', [\App\Modules\Social\SocialController::class, 'saveApp']);

            // Ma configuration : tout ce qui doit être réglé pour que les clients utilisent la plateforme sans accroc
            Route::get('setup', fn () => app(\App\Platform\SetupChecklist::class)->run());
            // Logiciels à télécharger déposés sur le serveur (Windows, macOS, Linux, Android, serveur)
            Route::get('downloads', [\App\Http\Controllers\Api\Admin\DownloadsAdminController::class, 'index']);
            Route::post('downloads', [\App\Http\Controllers\Api\Admin\DownloadsAdminController::class, 'store'])->middleware('throttle:600,1');
            Route::delete('downloads/{name}', [\App\Http\Controllers\Api\Admin\DownloadsAdminController::class, 'destroy'])->where('name', '[A-Za-z0-9._+-]+');

            // Communication clients : campagnes (notification / e-mail) et relances automatiques
            Route::controller(\App\Http\Controllers\Api\Admin\ClientCommsController::class)->prefix('comms')->group(function () {
                Route::get('/', 'index');
                Route::get('companies', 'companies');
                Route::post('audience', 'audience');
                Route::post('preview', 'preview');
                Route::post('test', 'test')->middleware('throttle:20,10');
                Route::post('campaigns', 'store');
                Route::get('campaigns/{id}', 'show')->whereNumber('id');
                Route::put('campaigns/{id}', 'update')->whereNumber('id');
                Route::delete('campaigns/{id}', 'destroy')->whereNumber('id');
                Route::post('campaigns/{id}/launch', 'launch')->whereNumber('id');
                Route::post('campaigns/{id}/pause', 'pause')->whereNumber('id');
                Route::post('campaigns/{id}/resume', 'resume')->whereNumber('id');
                Route::post('campaigns/{id}/duplicate', 'duplicate')->whereNumber('id');
                Route::put('automations/{id}', 'updateAutomation')->whereNumber('id');
                Route::post('automations/{id}/toggle', 'toggleAutomation')->whereNumber('id');
                Route::get('automations/{id}/targets', 'automationTargets')->whereNumber('id');
            });
            Route::get('overview', [PlatformController::class, 'overview']);
            Route::get('online', [PlatformController::class, 'online']);
            Route::get('analytics', [PlatformController::class, 'analytics']);
            Route::get('licenses', [\App\Http\Controllers\Api\Admin\LicenseAdminController::class, 'index']);
            Route::get('promo-codes', [\App\Http\Controllers\Api\Admin\PromoAdminController::class, 'index']);
            Route::post('promo-codes', [\App\Http\Controllers\Api\Admin\PromoAdminController::class, 'store']);
            Route::put('promo-codes/{id}', [\App\Http\Controllers\Api\Admin\PromoAdminController::class, 'update'])->whereNumber('id');
            Route::delete('promo-codes/{id}', [\App\Http\Controllers\Api\Admin\PromoAdminController::class, 'destroy'])->whereNumber('id');
            Route::get('promo-codes/{id}/redemptions', [\App\Http\Controllers\Api\Admin\PromoAdminController::class, 'redemptions'])->whereNumber('id');
            Route::get('livechat', [\App\Http\Controllers\Api\Admin\LiveChatAdminController::class, 'index']);
            Route::put('livechat/settings', [\App\Http\Controllers\Api\Admin\LiveChatAdminController::class, 'settings']);
            Route::get('livechat/{id}', [\App\Http\Controllers\Api\Admin\LiveChatAdminController::class, 'show'])->whereNumber('id');
            Route::post('livechat/{id}', [\App\Http\Controllers\Api\Admin\LiveChatAdminController::class, 'reply'])->whereNumber('id');
            Route::post('livechat/{id}/close', [\App\Http\Controllers\Api\Admin\LiveChatAdminController::class, 'close'])->whereNumber('id');
            Route::get('referrals', function () {
                $top = \Illuminate\Support\Facades\DB::table('referral_rewards')->where('kind', 'earned')->selectRaw('referrer_id, count(distinct referee_id) as paying, sum(amount) as earned')->groupBy('referrer_id')->orderByDesc('earned')->limit(50)->get();
                $names = \App\Models\Company::whereIn('id', $top->pluck('referrer_id'))->pluck('name', 'id');
                $counts = \App\Models\Company::whereNotNull('referred_by')->selectRaw('referred_by, count(*) as n')->groupBy('referred_by')->pluck('n', 'referred_by');

                return [
                    'settings' => \App\Store\Referrals::settings(),
                    'stats' => ['referees' => \App\Models\Company::whereNotNull('referred_by')->count(), 'referrers' => $counts->count(),
                        'earned' => round((float) \Illuminate\Support\Facades\DB::table('referral_rewards')->where('kind', 'earned')->sum('amount'), 2),
                        'spent' => round((float) \Illuminate\Support\Facades\DB::table('referral_rewards')->where('kind', 'spent')->sum('amount'), 2)],
                    'top' => $top->map(fn ($t) => ['company' => $names[$t->referrer_id] ?? '—', 'referees' => (int) ($counts[$t->referrer_id] ?? 0), 'paying' => (int) $t->paying, 'earned' => round((float) $t->earned, 2)])->values(),
                ];
            });
            Route::put('referrals/settings', function (\Illuminate\Http\Request $r) {
                $d = $r->validate(['enabled' => 'sometimes|boolean', 'reward_percent' => 'sometimes|numeric|min:0|max:100', 'referee_discount' => 'sometimes|numeric|min:0|max:100', 'reward_months' => 'sometimes|integer|min:0|max:120']);
                \App\Models\PlatformSetting::put('referral.settings', array_replace(\App\Store\Referrals::settings(), $d));

                return ['settings' => \App\Store\Referrals::settings()];
            });
            Route::get('site-analytics', [\App\Http\Controllers\Api\Admin\LicenseAdminController::class, 'site']);
            Route::put('landing-requests/{id}', [\App\Http\Controllers\Api\Admin\LicenseAdminController::class, 'updateRequest'])->whereNumber('id');
            Route::get('licenses/{id}', [\App\Http\Controllers\Api\Admin\LicenseAdminController::class, 'show'])->whereNumber('id');
            Route::post('licenses/signing-key', [\App\Http\Controllers\Api\Admin\LicenseAdminController::class, 'unlockSigning'])->middleware('throttle:5,10');
            Route::post('licenses/{id}/status', [\App\Http\Controllers\Api\Admin\LicenseAdminController::class, 'setStatus'])->whereNumber('id');
            Route::prefix('support')->controller(\App\Http\Controllers\Api\Admin\SupportAdminController::class)->group(function () {
                Route::get('conversations', 'index');
                Route::get('conversations/{id}', 'show')->whereNumber('id');
                Route::post('conversations/{id}/messages', 'reply')->whereNumber('id');
                Route::put('conversations/{id}', 'update')->whereNumber('id');
            });
            Route::get('events', [\App\Http\Controllers\Api\Admin\EventAdminController::class, 'index']);
            Route::prefix('sites')->controller(\App\Http\Controllers\Api\Admin\SiteAdminController::class)->group(function () {
                Route::get('/', 'index');
                Route::put('settings', 'settings');
                Route::post('requests/{id}/quote', 'quote')->whereNumber('id');
                Route::post('requests/{id}/deliver', 'deliver')->whereNumber('id');
                Route::post('requests/{id}/progress', 'progress')->whereNumber('id');
                Route::post('requests/{id}/reject', 'reject')->whereNumber('id');
                Route::post('{id}/status', 'status')->whereNumber('id');
                Route::post('{id}/check-domain', 'checkDomain')->whereNumber('id');
            });
            Route::get('system', [SystemController::class, 'info']);
            Route::get('system/checks', [SystemController::class, 'checks']);
            Route::get('system/env', [SystemController::class, 'env']);
            Route::put('system/env', [SystemController::class, 'saveEnv']);
            Route::post('system/env/test-mail', [SystemController::class, 'testMail']);
            Route::get('releases', [\App\Http\Controllers\Api\Admin\ReleaseAdminController::class, 'index']);
            Route::post('releases/publish-self', [\App\Http\Controllers\Api\Admin\ReleaseAdminController::class, 'publishSelf']);
            Route::post('releases/upload', [\App\Http\Controllers\Api\Admin\ReleaseAdminController::class, 'upload']);
            Route::post('releases/{id}/install', [\App\Http\Controllers\Api\Admin\ReleaseAdminController::class, 'installSelf'])->whereNumber('id');
            Route::put('releases/{id}', [\App\Http\Controllers\Api\Admin\ReleaseAdminController::class, 'update'])->whereNumber('id');
            Route::delete('releases/{id}', [\App\Http\Controllers\Api\Admin\ReleaseAdminController::class, 'destroy'])->whereNumber('id');
            Route::post('system/update', [SystemController::class, 'update']);

            Route::get('modules', [ModuleAdminController::class, 'index']);
            Route::post('modules/refresh', [ModuleAdminController::class, 'refresh']);
            Route::post('modules/bulk-price', [ModuleAdminController::class, 'bulkPrice']);
            Route::put('modules/{key}', [ModuleAdminController::class, 'update']);

            Route::get('plans', [PlanAdminController::class, 'index']);
            Route::post('plans', [PlanAdminController::class, 'store']);
            Route::put('plans/{id}', [PlanAdminController::class, 'update']);
            Route::delete('plans/{id}', [PlanAdminController::class, 'destroy']);

            Route::get('store-demo', [\App\Http\Controllers\Api\Admin\StoreDemoController::class, 'show']);
            Route::post('store-demo', [\App\Http\Controllers\Api\Admin\StoreDemoController::class, 'store'])->middleware('throttle:5,10');
            Route::post('store-demo/password', [\App\Http\Controllers\Api\Admin\StoreDemoController::class, 'password']);
            Route::post('store-demo/disable', [\App\Http\Controllers\Api\Admin\StoreDemoController::class, 'disable']);
            Route::post('store-demo/forget', [\App\Http\Controllers\Api\Admin\StoreDemoController::class, 'forget']);
            Route::get('companies', [CompanyAdminController::class, 'index']);
            Route::post('companies', [CompanyAdminController::class, 'store']);
            Route::get('companies/{id}', [CompanyAdminController::class, 'show']);
            Route::put('companies/{id}', [CompanyAdminController::class, 'update']);
            Route::get('companies/{id}/modules', [CompanyAdminController::class, 'modules']);
            Route::put('companies/{id}/modules/{key}', [CompanyAdminController::class, 'setModule']);
            Route::post('companies/{id}/grant', [CompanyAdminController::class, 'grant'])->whereNumber('id');

            Route::post('modules/{key}/screenshots', [StoreAdminController::class, 'uploadScreenshot']);
            Route::delete('modules/{key}/screenshots', [StoreAdminController::class, 'deleteScreenshot']);
            Route::get('mail-platform', [MessagingController::class, 'platform']);
            Route::put('mail-platform', [MessagingController::class, 'updatePlatform']);
            Route::get('store/orders', [StoreAdminController::class, 'orders']);
            Route::post('store/orders/{id}/approve', [StoreAdminController::class, 'approve']);
            Route::post('store/orders/{id}/reject', [StoreAdminController::class, 'reject']);
            Route::get('store/settings', [StoreAdminController::class, 'settings']);
            Route::put('store/settings', [StoreAdminController::class, 'updateSettings']);
            Route::post('payment-gateways', [StoreAdminController::class, 'storeGateway']);
            Route::put('payment-gateways/{id}', [StoreAdminController::class, 'updateGateway']);
            Route::delete('payment-gateways/{id}', [StoreAdminController::class, 'destroyGateway']);
            Route::post('payment-gateways/{id}/test', [StoreAdminController::class, 'testGateway']);
        });
    });
});
