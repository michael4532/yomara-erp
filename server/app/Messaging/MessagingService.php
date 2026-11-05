<?php

namespace App\Messaging;

use App\Messaging\Providers\Provider;
use App\Messaging\Providers\Sms;
use App\Messaging\Providers\Smtp;
use App\Messaging\Providers\WhatsApp;
use App\Models\Company;
use App\Models\MessagingChannel;
use App\Models\MessagingLog;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Crypt;

/** Envoi d'un message par le canal configuré de la société (e-mail, SMS, WhatsApp), avec journal. */
class MessagingService
{
    public const PROVIDERS = [
        'email' => [
            'smtp' => ['label' => 'Votre serveur de messagerie (SMTP)', 'fields' => [
                'host' => 'Serveur SMTP (ex. mail.votredomaine.ci)', 'port' => 'Port (587 ou 465)', 'encryption' => 'Chiffrement (tls, ssl, none)',
                'username' => 'Identifiant', 'password' => 'Mot de passe', 'from_email' => 'Adresse d\'expédition', 'from_name' => 'Nom d\'expéditeur', 'reply_to' => 'Adresse de réponse (facultatif)',
            ], 'secret' => ['password'], 'hint' => 'Hébergement cPanel : serveur mail.votredomaine, port 465 (ssl) ou 587 (tls), identifiant = adresse e-mail complète.'],
            'platform' => ['label' => 'Serveur de messagerie de l\'ERP', 'module' => 'mail_server', 'fields' => ['from_name' => 'Nom d\'expéditeur', 'reply_to' => 'Adresse de réponse (vos clients vous répondent ici)'], 'secret' => [],
                'hint' => 'Aucune configuration technique : les e-mails partent par le serveur de la plateforme, dans la limite du quota mensuel.'],
        ],
        'sms' => [
            'orange' => ['label' => 'Orange SMS API', 'module' => 'sms', 'fields' => ['client_id' => 'Client ID', 'client_secret' => 'Client secret', 'sender_number' => 'Numéro expéditeur (ex. 2250700000000)', 'sender_name' => 'Nom expéditeur (si autorisé)'], 'secret' => ['client_secret'], 'docs' => 'https://developer.orange.com/apis/sms'],
            'twilio' => ['label' => 'Twilio', 'module' => 'sms', 'fields' => ['account_sid' => 'Account SID', 'auth_token' => 'Auth token', 'from' => 'Numéro ou nom expéditeur'], 'secret' => ['auth_token'], 'docs' => 'https://www.twilio.com/docs/sms'],
            'infobip' => ['label' => 'Infobip', 'module' => 'sms', 'fields' => ['base_url' => 'URL de base (xxxxx.api.infobip.com)', 'api_key' => 'Clé API', 'sender' => 'Expéditeur'], 'secret' => ['api_key'], 'docs' => 'https://www.infobip.com/docs/api'],
            'africastalking' => ['label' => 'Africa\'s Talking', 'module' => 'sms', 'fields' => ['username' => 'Nom d\'utilisateur (sandbox pour tester)', 'api_key' => 'Clé API', 'sender' => 'Expéditeur (facultatif)'], 'secret' => ['api_key'], 'docs' => 'https://developers.africastalking.com'],
            'vonage' => ['label' => 'Vonage (Nexmo)', 'module' => 'sms', 'fields' => ['api_key' => 'Clé API', 'api_secret' => 'Secret API', 'sender' => 'Expéditeur'], 'secret' => ['api_secret'], 'docs' => 'https://developer.vonage.com/messaging/sms'],
            'http' => ['label' => 'Passerelle HTTP (fournisseur local)', 'module' => 'sms', 'fields' => [
                'method' => 'Méthode (GET ou POST)', 'url' => 'URL avec {to}, {message}, {sender}', 'body' => 'Corps POST (facultatif, mêmes variables)',
                'content_type' => 'Type du corps (application/json…)', 'headers' => 'En-têtes (un par ligne, « Nom: valeur »)', 'sender' => 'Expéditeur', 'success_contains' => 'Texte attendu dans la réponse (facultatif)',
            ], 'secret' => ['headers'], 'hint' => 'Pour tout fournisseur local (LeTexto, NetSMS, etc.) qui donne une URL d\'envoi.'],
        ],
        'whatsapp' => [
            'meta' => ['label' => 'WhatsApp Business — API Cloud (Meta)', 'module' => 'whatsapp', 'fields' => [
                'phone_number_id' => 'Phone number ID', 'waba_id' => 'WhatsApp Business Account ID (modèles)', 'access_token' => 'Jeton d\'accès permanent', 'app_secret' => 'Clé secrète de l\'application (vérification des webhooks)',
                'default_template' => 'Modèle approuvé pour les campagnes (facultatif)', 'template_language' => 'Langue du modèle (fr)',
            ], 'secret' => ['access_token', 'app_secret'], 'docs' => 'https://developers.facebook.com/docs/whatsapp/cloud-api',
                'hint' => 'Les messages libres ne passent que dans les 24 h suivant un message du client ; au-delà, Meta impose un modèle approuvé.'],
            'ultramsg' => ['label' => 'UltraMsg', 'module' => 'whatsapp', 'fields' => ['instance_id' => 'Instance (instance12345)', 'token' => 'Token'], 'secret' => ['token'], 'docs' => 'https://docs.ultramsg.com'],
            'greenapi' => ['label' => 'Green API', 'module' => 'whatsapp', 'fields' => ['id_instance' => 'idInstance', 'api_token' => 'apiTokenInstance', 'api_url' => 'URL API (facultatif)'], 'secret' => ['api_token'], 'docs' => 'https://green-api.com/docs'],
        ],
    ];

    public static function platformMail(): array
    {
        $c = (array) PlatformSetting::get('mail.platform', []);
        if (! empty($c['password_enc'])) {
            try {
                $c['password'] = Crypt::decryptString($c['password_enc']);
            } catch (\Throwable) {
                $c['password'] = null;
            }
        }
        unset($c['password_enc']);

        return $c;
    }

    public static function platformMailReady(): bool
    {
        $c = self::platformMail();

        return ! empty($c['host']) && ! empty($c['from_email']);
    }

    /** Quota mensuel du serveur de la plateforme pour une société. */
    public static function platformUsage(Company $company): array
    {
        $quota = (int) (self::platformMail()['quota'] ?? 1000);
        $used = MessagingLog::withoutGlobalScopes()->where('company_id', $company->id)->where('provider', 'platform')->where('status', 'sent')
            ->where('created_at', '>=', now()->startOfMonth())->count();

        return ['quota' => $quota, 'used' => $used, 'left' => max(0, $quota - $used)];
    }

    protected function channelRow(Company $company, string $channel): ?MessagingChannel
    {
        return MessagingChannel::withoutGlobalScopes()->where(['company_id' => $company->id, 'channel' => $channel])->first();
    }

    /** Canaux utilisables par la société. */
    public function available(Company $company): array
    {
        $out = [];
        foreach (array_keys(self::PROVIDERS) as $channel) {
            $out[$channel] = $this->usable($company, $channel);
        }

        return $out;
    }

    public function usable(Company $company, string $channel): ?string
    {
        $row = $this->channelRow($company, $channel);
        if (! $row || ! $row->enabled) {
            return null;
        }
        $meta = self::PROVIDERS[$channel][$row->provider] ?? null;
        if (! $meta) {
            return null;
        }
        if (! empty($meta['module']) && ! $company->hasModule($meta['module'])) {
            return null;
        }
        if ($row->provider === 'platform' && ! self::platformMailReady()) {
            return null;
        }

        return $row->provider;
    }

    /** @return array{0: Provider, 1: array, 2: string} */
    protected function resolve(Company $company, string $channel): array
    {
        $provider = $this->usable($company, $channel);
        if (! $provider) {
            throw new \DomainException(match ($channel) {
                'email' => 'Aucun serveur d\'e-mail configuré (Paramètres → Canaux d\'envoi).',
                'sms' => 'Les SMS ne sont pas configurés ou le module SMS n\'est pas activé.',
                default => 'WhatsApp n\'est pas configuré ou le module WhatsApp n\'est pas activé.',
            });
        }
        $config = (array) $this->channelRow($company, $channel)->settings;
        if ($provider === 'platform') {
            if (self::platformUsage($company)['left'] <= 0) {
                throw new \DomainException('Quota mensuel d\'e-mails du serveur de l\'ERP atteint.');
            }
            $p = self::platformMail();
            $config = array_merge($p, array_filter(['from_name' => $config['from_name'] ?? $company->name, 'reply_to' => $config['reply_to'] ?? $company->email]));
        }
        $impl = match ($channel) {
            'email' => new Smtp,
            'sms' => new Sms($provider),
            default => new WhatsApp($provider),
        };

        return [$impl, $config, $provider];
    }

    public function send(Company $company, string $channel, Message $m, ?string $context = null, ?int $userId = null): SendResult
    {
        [$impl, $config, $provider] = $this->resolve($company, $channel);
        $r = $impl->send($m, $config);
        MessagingLog::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'user_id' => $userId, 'channel' => $channel, 'provider' => $provider,
            'to' => mb_substr($m->to, 0, 190), 'subject' => $m->subject ? mb_substr($m->subject, 0, 190) : null,
            'status' => $r->ok ? 'sent' : 'failed', 'provider_id' => $r->id ? mb_substr($r->id, 0, 190) : null, 'error' => $r->error, 'context' => $context,
        ]);

        return $r;
    }
}
