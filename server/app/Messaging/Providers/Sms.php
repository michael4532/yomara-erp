<?php

namespace App\Messaging\Providers;

use App\Messaging\Message;
use App\Messaging\Phone;
use App\Messaging\SendResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Fournisseurs SMS : Orange (API Orange Developer), Twilio, Infobip, Africa's Talking, Vonage, ou passerelle HTTP locale. */
class Sms implements Provider
{
    public function __construct(protected string $provider) {}

    public function send(Message $m, array $c): SendResult
    {
        $to = Phone::e164($m->to, $c['country_code'] ?? '225');
        if (! $to) {
            return SendResult::fail('Numéro invalide : '.$m->to);
        }
        $text = (string) ($m->text ?? strip_tags((string) $m->html));
        try {
            return match ($this->provider) {
                'orange' => $this->orange($to, $text, $c),
                'twilio' => $this->twilio($to, $text, $c),
                'infobip' => $this->infobip($to, $text, $c),
                'africastalking' => $this->africastalking($to, $text, $c),
                'vonage' => $this->vonage($to, $text, $c),
                'http' => $this->http($to, $text, $c),
                default => SendResult::fail('Fournisseur SMS inconnu.'),
            };
        } catch (\Throwable $e) {
            return SendResult::fail($e->getMessage());
        }
    }

    protected function orange(string $to, string $text, array $c): SendResult
    {
        $token = Cache::remember('orange_sms_token_'.md5($c['client_id'] ?? ''), 3000, function () use ($c) {
            return Http::asForm()->acceptJson()->withBasicAuth($c['client_id'] ?? '', $c['client_secret'] ?? '')
                ->post('https://api.orange.com/oauth/v3/token', ['grant_type' => 'client_credentials'])->json('access_token');
        });
        if (! $token) {
            return SendResult::fail('Orange : authentification refusée.');
        }
        $sender = 'tel:'.(Phone::e164($c['sender_number'] ?? '', $c['country_code'] ?? '225') ?? '+2250000');
        $body = ['outboundSMSMessageRequest' => array_filter([
            'address' => 'tel:'.$to,
            'senderAddress' => $sender,
            'senderName' => $c['sender_name'] ?? null,
            'outboundSMSTextMessage' => ['message' => $text],
        ])];
        $res = Http::acceptJson()->withToken($token)->post('https://api.orange.com/smsmessaging/v1/outbound/'.rawurlencode($sender).'/requests', $body);

        return $res->successful() ? SendResult::ok((string) $res->json('outboundSMSMessageRequest.resourceURL')) : SendResult::fail('Orange : '.($res->json('requestError.serviceException.text') ?? $res->json('message') ?? $res->status()));
    }

    protected function twilio(string $to, string $text, array $c): SendResult
    {
        $res = Http::asForm()->withBasicAuth($c['account_sid'] ?? '', $c['auth_token'] ?? '')
            ->post('https://api.twilio.com/2010-04-01/Accounts/'.($c['account_sid'] ?? '').'/Messages.json', ['To' => $to, 'From' => $c['from'] ?? '', 'Body' => $text]);

        return $res->successful() ? SendResult::ok($res->json('sid')) : SendResult::fail('Twilio : '.($res->json('message') ?? $res->status()));
    }

    protected function infobip(string $to, string $text, array $c): SendResult
    {
        $base = rtrim(preg_replace('#^https?://#', '', (string) ($c['base_url'] ?? 'api.infobip.com')), '/');
        if (! \App\Support\UrlGuard::isPublic('https://'.$base)) {
            return SendResult::fail('Adresse Infobip invalide.');
        }
        $res = Http::acceptJson()->withHeaders(['Authorization' => 'App '.($c['api_key'] ?? '')])
            ->post('https://'.$base.'/sms/2/text/advanced', ['messages' => [['destinations' => [['to' => ltrim($to, '+')]], 'from' => $c['sender'] ?? 'InfoSMS', 'text' => $text]]]);

        return $res->successful() ? SendResult::ok($res->json('messages.0.messageId')) : SendResult::fail('Infobip : '.($res->json('requestError.serviceException.text') ?? $res->status()));
    }

    protected function africastalking(string $to, string $text, array $c): SendResult
    {
        $sandbox = ($c['username'] ?? '') === 'sandbox';
        $res = Http::asForm()->acceptJson()->withHeaders(['apiKey' => $c['api_key'] ?? ''])
            ->post('https://api.'.($sandbox ? 'sandbox.' : '').'africastalking.com/version1/messaging', array_filter(['username' => $c['username'] ?? '', 'to' => $to, 'message' => $text, 'from' => $c['sender'] ?? null]));
        $r = $res->json('SMSMessageData.Recipients.0');

        return $res->successful() && in_array($r['status'] ?? '', ['Success', 'Sent'], true) ? SendResult::ok($r['messageId'] ?? null) : SendResult::fail('Africa\'s Talking : '.($r['status'] ?? $res->json('SMSMessageData.Message') ?? $res->status()));
    }

    protected function vonage(string $to, string $text, array $c): SendResult
    {
        $res = Http::asForm()->acceptJson()->post('https://rest.nexmo.com/sms/json', ['api_key' => $c['api_key'] ?? '', 'api_secret' => $c['api_secret'] ?? '', 'to' => ltrim($to, '+'), 'from' => $c['sender'] ?? 'Info', 'text' => $text, 'type' => 'unicode']);
        $msg = $res->json('messages.0');

        return ($msg['status'] ?? '1') === '0' ? SendResult::ok($msg['message-id'] ?? null) : SendResult::fail('Vonage : '.($msg['error-text'] ?? $res->status()));
    }

    /** Passerelle HTTP générique : URL avec {to}, {message}, {sender} (fournisseurs locaux). */
    protected function http(string $to, string $text, array $c): SendResult
    {
        $digits = ltrim($to, '+');
        $repl = ['{to}' => rawurlencode($digits), '{to_plus}' => rawurlencode($to), '{message}' => rawurlencode($text), '{sender}' => rawurlencode((string) ($c['sender'] ?? ''))];
        $url = strtr((string) ($c['url'] ?? ''), $repl);
        if (! preg_match('#^https?://#', $url) || ! \App\Support\UrlGuard::isPublic($url)) {
            return SendResult::fail('URL de la passerelle invalide ou interne (seuls les serveurs publics sont autorisés).');
        }
        $headers = [];
        foreach (preg_split('/\R/', (string) ($c['headers'] ?? '')) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = array_map('trim', explode(':', $line, 2));
                $headers[$k] = $v;
            }
        }
        $req = Http::withHeaders($headers)->timeout(20)->withOptions(\App\Support\UrlGuard::pinned($url));
        if (strtoupper($c['method'] ?? 'GET') === 'POST') {
            $body = strtr((string) ($c['body'] ?? ''), ['{to}' => $digits, '{to_plus}' => $to, '{message}' => addslashes($text), '{sender}' => (string) ($c['sender'] ?? '')]);
            $res = $req->withBody($body, $c['content_type'] ?? 'application/json')->post($url);
        } else {
            $res = $req->get($url);
        }
        $okText = trim((string) ($c['success_contains'] ?? ''));

        return $res->successful() && ($okText === '' || str_contains($res->body(), $okText)) ? SendResult::ok() : SendResult::fail('Passerelle : '.mb_substr($res->body(), 0, 200));
    }
}
