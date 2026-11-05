<?php

namespace App\Messaging\Providers;

use App\Messaging\Message;
use App\Messaging\Phone;
use App\Messaging\SendResult;
use Illuminate\Support\Facades\Http;

/** WhatsApp : API Cloud officielle de Meta, UltraMsg, Green API. */
class WhatsApp implements Provider
{
    public function __construct(protected string $provider) {}

    public function send(Message $m, array $c): SendResult
    {
        $to = Phone::digits($m->to, $c['country_code'] ?? '225');
        if (! $to) {
            return SendResult::fail('Numéro invalide : '.$m->to);
        }
        $text = (string) ($m->text ?? strip_tags((string) $m->html));
        try {
            return match ($this->provider) {
                'meta' => $this->meta($to, $text, $m, $c),
                'ultramsg' => $this->ultramsg($to, $text, $m, $c),
                'greenapi' => $this->greenapi($to, $text, $m, $c),
                default => SendResult::fail('Fournisseur WhatsApp inconnu.'),
            };
        } catch (\Throwable $e) {
            return SendResult::fail($e->getMessage());
        }
    }

    /** Hors fenêtre de 24 h, Meta impose un modèle approuvé : il est utilisé s'il est indiqué. */
    protected function meta(string $to, string $text, Message $m, array $c): SendResult
    {
        $version = $c['api_version'] ?? 'v21.0';
        $template = $m->template ?? ($c['default_template'] ?? null);
        if ($template) {
            $body = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => [
                'name' => $template, 'language' => ['code' => $c['template_language'] ?? 'fr'],
                'components' => [['type' => 'body', 'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], $m->templateParams ?: [$text])]],
            ]];
        } elseif ($m->mediaUrl && str_starts_with((string) $m->mediaMime, 'image/')) {
            $body = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'image', 'image' => array_filter(['link' => $m->mediaUrl, 'caption' => mb_substr($text, 0, 1000) ?: null])];
        } elseif ($m->mediaUrl) {
            $body = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'document', 'document' => array_filter(['link' => $m->mediaUrl, 'caption' => mb_substr($text, 0, 1000) ?: null, 'filename' => $m->mediaName])];
        } else {
            $body = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['preview_url' => true, 'body' => $text]];
        }
        $res = Http::acceptJson()->withToken($c['access_token'] ?? '')->post('https://graph.facebook.com/'.$version.'/'.($c['phone_number_id'] ?? '').'/messages', $body);

        return $res->successful() ? SendResult::ok($res->json('messages.0.id')) : SendResult::fail('WhatsApp (Meta) : '.($res->json('error.message') ?? $res->status()));
    }

    protected function ultramsg(string $to, string $text, Message $m, array $c): SendResult
    {
        $base = 'https://api.ultramsg.com/'.($c['instance_id'] ?? '');
        $res = $m->mediaUrl
            ? (str_starts_with((string) $m->mediaMime, 'image/')
                ? Http::asForm()->post($base.'/messages/image', ['token' => $c['token'] ?? '', 'to' => '+'.$to, 'image' => $m->mediaUrl, 'caption' => $text])
                : Http::asForm()->post($base.'/messages/document', ['token' => $c['token'] ?? '', 'to' => '+'.$to, 'document' => $m->mediaUrl, 'filename' => $m->mediaName ?: 'document.pdf', 'caption' => $text]))
            : Http::asForm()->post($base.'/messages/chat', ['token' => $c['token'] ?? '', 'to' => '+'.$to, 'body' => $text]);
        $ok = $res->successful() && ($res->json('sent') === 'true' || $res->json('sent') === true);

        return $ok ? SendResult::ok((string) $res->json('id')) : SendResult::fail('UltraMsg : '.($res->json('error') ? json_encode($res->json('error')) : $res->status()));
    }

    protected function greenapi(string $to, string $text, Message $m, array $c): SendResult
    {
        $host = rtrim($c['api_url'] ?? 'https://api.green-api.com', '/');
        if (! \App\Support\UrlGuard::isPublic($host)) {
            return SendResult::fail('Adresse de l\'API invalide.');
        }
        $id = $c['id_instance'] ?? '';
        $token = $c['api_token'] ?? '';
        $res = $m->mediaUrl
            ? Http::acceptJson()->post("$host/waInstance$id/sendFileByUrl/$token", ['chatId' => $to.'@c.us', 'urlFile' => $m->mediaUrl, 'fileName' => $m->mediaName ?: 'document.pdf', 'caption' => $text])
            : Http::acceptJson()->post("$host/waInstance$id/sendMessage/$token", ['chatId' => $to.'@c.us', 'message' => $text]);

        return $res->successful() && $res->json('idMessage') ? SendResult::ok($res->json('idMessage')) : SendResult::fail('Green API : '.($res->json('message') ?? $res->status()));
    }
}
