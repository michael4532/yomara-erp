<?php

namespace App\Integrations;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/** Canaux d'équipe : les notifications de l'ERP relayées dans Slack, Microsoft Teams, Discord, Telegram ou Google Chat. */
class Channels
{
    public const KINDS = ['slack' => 'Slack', 'teams' => 'Microsoft Teams', 'discord' => 'Discord', 'telegram' => 'Telegram', 'google_chat' => 'Google Chat'];

    /** Rubriques proposées → types de notification relayés. */
    public const TOPICS = [
        'ventes' => ['Ventes & encaissements', ['payment', 'relances', 'portal', 'site_order', 'fne', 'pack']],
        'commercial' => ['Force de vente', ['commercial']],
        'marketing' => ['Marketing & avis clients', ['contenus', 'avis', 'site_request', 'site_message', 'site_review', 'whatsapp']],
        'achats' => ['Achats & stock', ['purchase', 'appro', 'fournisseurs', 'stock', 'production']],
        'approbations' => ['Approbations', ['approval']],
        'finance' => ['Finance & appels de fonds', ['tresorerie', 'compta', 'fonds']],
        'rh' => ['Ressources humaines', ['notes_frais', 'feuilles', 'recrutement', 'rh']],
        'juridique' => ['Juridique & signatures', ['juridique', 'signature']],
        'flotte' => ['Parc automobile', ['flotte']],
        'dev' => ['Développement', ['dev']],
        'projets' => ['Projets & demandes internes', ['projets', 'task', 'ticket', 'ticket_report']],
        'service' => ['Service client & interventions', ['helpdesk', 'intervention', 'restaurant']],
        'activite' => ['Rapports d\'activité', ['activite']],
        'agenda' => ['Agenda', ['agenda']],
    ];

    protected static ?bool $table = null;

    public static function topicOf(string $type): ?string
    {
        foreach (self::TOPICS as $k => [, $types]) {
            if (in_array($type, $types, true)) {
                return $k;
            }
        }

        return null;
    }

    /** Relaye une notification (appelé par AppNotification::send ; une seule fois par événement). */
    public static function forward(int $companyId, string $type, string $title, ?string $body): void
    {
        if (! (self::$table ??= Schema::hasTable('notify_channels'))) {
            return;
        }
        $topic = self::topicOf($type);
        foreach (DB::table('notify_channels')->where('company_id', $companyId)->where('active', true)->get() as $ch) {
            $events = json_decode($ch->events ?? '[]', true) ?: [];
            if (in_array('*', $events, true) || ($topic && in_array($topic, $events, true))) {
                rescue(fn () => self::deliver($ch, $title, $body), null, false);
            }
        }
    }

    public static function config(object $ch): array
    {
        return json_decode(Crypt::decryptString($ch->config), true) ?: [];
    }

    /** Envoie un message ; lève une exception si le service le refuse. */
    public static function deliver(object $ch, string $title, ?string $body): void
    {
        $cfg = self::config($ch);
        $company = DB::table('companies')->where('id', $ch->company_id)->value('name');
        $body = trim((string) $body);
        $plain = $title.($body !== '' ? "\n".$body : '');
        $http = Http::timeout(6)->acceptJson();
        $res = match ($ch->kind) {
            'slack' => $http->post($cfg['url'], ['text' => '*'.$title.'*'.($body !== '' ? "\n".$body : ''), 'username' => 'Yomara ERP']),
            'discord' => $http->post($cfg['url'], ['content' => mb_substr('**'.$title.'**'.($body !== '' ? "\n".$body : ''), 0, 1900), 'username' => 'Yomara ERP']),
            'google_chat' => $http->post($cfg['url'], ['text' => '*'.$title.'*'.($body !== '' ? "\n".$body : '')]),
            'teams' => $http->post($cfg['url'], ['type' => 'message', 'attachments' => [['contentType' => 'application/vnd.microsoft.card.adaptive', 'content' => [
                '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json', 'type' => 'AdaptiveCard', 'version' => '1.4', 'body' => array_values(array_filter([
                    ['type' => 'TextBlock', 'text' => $title, 'weight' => 'Bolder', 'wrap' => true],
                    $body !== '' ? ['type' => 'TextBlock', 'text' => $body, 'wrap' => true] : null,
                    ['type' => 'TextBlock', 'text' => 'Yomara ERP · '.$company, 'isSubtle' => true, 'size' => 'Small'],
                ]))]]]]),
            'telegram' => $http->post('https://api.telegram.org/bot'.$cfg['token'].'/sendMessage', ['chat_id' => $cfg['chat_id'],
                'text' => '<b>'.e($title).'</b>'.($body !== '' ? "\n".e($body) : '')."\n<i>Yomara ERP · ".e($company).'</i>', 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]),
            default => throw new \DomainException('Canal inconnu.'),
        };
        if (! $res->successful() || ($ch->kind === 'telegram' && $res->json('ok') === false)) {
            $err = mb_substr((string) ($res->json('description') ?: $res->json('error') ?: $res->body() ?: 'Erreur '.$res->status()), 0, 300);
            DB::table('notify_channels')->where('id', $ch->id)->update(['last_error' => $err]);
            throw new \DomainException(self::KINDS[$ch->kind].' refuse le message : '.$err);
        }
        DB::table('notify_channels')->where('id', $ch->id)->update(['last_sent_at' => now(), 'last_error' => null, 'sent_count' => DB::raw('sent_count + 1')]);
        unset($plain);
    }

    public static function validateConfig(string $kind, array $in): array
    {
        if ($kind === 'telegram') {
            if (empty($in['token']) || empty($in['chat_id'])) {
                throw new \DomainException('Indiquez le jeton du bot Telegram et l\'identifiant de la conversation.');
            }

            return ['token' => trim($in['token']), 'chat_id' => trim((string) $in['chat_id'])];
        }
        $url = trim((string) ($in['url'] ?? ''));
        $hosts = ['slack' => ['hooks.slack.com'], 'discord' => ['discord.com', 'discordapp.com'], 'google_chat' => ['chat.googleapis.com'],
            'teams' => ['webhook.office.com', 'logic.azure.com', 'powerplatform.com', 'office.com', 'microsoft.com']];
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        if (! str_starts_with($url, 'https://') || ! collect($hosts[$kind] ?? [])->contains(fn ($h) => $host === $h || str_ends_with($host, '.'.$h))) {
            throw new \DomainException('Adresse de webhook '.self::KINDS[$kind].' invalide.');
        }

        return ['url' => $url];
    }

    public static function toApi(object $ch): array
    {
        $cfg = self::config($ch);
        $hint = isset($cfg['url']) ? parse_url($cfg['url'], PHP_URL_HOST).'/…' : 'conversation '.($cfg['chat_id'] ?? '');

        return ['id' => $ch->id, 'kind' => $ch->kind, 'kind_label' => self::KINDS[$ch->kind] ?? $ch->kind, 'name' => $ch->name, 'target' => $hint,
            'events' => json_decode($ch->events ?? '[]', true) ?: [], 'active' => (bool) $ch->active, 'last_sent_at' => $ch->last_sent_at, 'last_error' => $ch->last_error, 'sent_count' => (int) $ch->sent_count];
    }
}
