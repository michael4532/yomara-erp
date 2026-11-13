<?php

namespace App\Messaging\Providers;

use App\Messaging\Message;
use App\Messaging\SendResult;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/** E-mail par serveur SMTP (celui de la société ou celui de la plateforme). */
class Smtp implements Provider
{
    /** Tests : remplace le transport réel. */
    public static ?\Closure $transportFactory = null;

    public static function transport(array $c): TransportInterface
    {
        if (static::$transportFactory) {
            return (static::$transportFactory)($c);
        }
        $enc = $c['encryption'] ?? 'tls';
        $port = (int) ($c['port'] ?? ($enc === 'ssl' ? 465 : 587));
        if (! empty($c['_tenant'])) {
            return self::tenantTransport($c, $enc, $port);
        }
        $scheme = $enc === 'ssl' || $port === 465 ? 'smtps' : 'smtp';
        $auth = isset($c['username']) && $c['username'] !== '' ? rawurlencode($c['username']).':'.rawurlencode((string) ($c['password'] ?? '')).'@' : '';

        return Transport::fromDsn($scheme.'://'.$auth.$c['host'].':'.$port.($enc === 'none' ? '?auto_tls=false' : ''));
    }

    /**
     * Serveur SMTP saisi par une société : nom d'hôte simple (pas d'injection dans le DSN) et, en SaaS,
     * connexion à l'IP publique vérifiée (pas le serveur lui-même ni le réseau interne), comme la YoMail.
     */
    protected static function tenantTransport(array $c, string $enc, int $port): TransportInterface
    {
        $host = trim((string) $c['host']);
        if (! preg_match('/^[A-Za-z0-9.-]{1,190}$/', $host) || $port < 1 || $port > 65535) {
            throw new \DomainException('Serveur SMTP invalide.');
        }
        $target = $host;
        if (config('erp.mode') === 'saas' && ! config('erp.mailbox.allow_private_hosts', false)) {
            $ip = \App\Support\UrlGuard::publicHostIp($host);
            $target = $ip === null ? $host : (str_contains($ip, ':') ? '['.$ip.']' : $ip);
        }
        $t = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport($target, $port, $enc === 'ssl' || $port === 465 ? true : null);
        if ($enc === 'none') {
            $t->setAutoTls(false);
        }
        $t->getStream()->setStreamOptions(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        if (isset($c['username']) && $c['username'] !== '') {
            $t->setUsername((string) $c['username']);
            $t->setPassword((string) ($c['password'] ?? ''));
        }

        return $t;
    }

    public function send(Message $m, array $c): SendResult
    {
        if (empty($c['host']) || empty($c['from_email'])) {
            return SendResult::fail('Serveur de messagerie non configuré.');
        }
        try {
            $email = (new Email)
                ->from(new Address($c['from_email'], (string) ($c['from_name'] ?? '')))
                ->to(new Address($m->to, (string) ($m->toName ?? '')))
                ->subject((string) $m->subject);
            foreach (array_unique(array_filter($m->cc, fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL) && strcasecmp($a, $m->to) !== 0)) as $cc) {
                $email->addCc($cc);
            }
            if ($reply = $m->replyTo ?? ($c['reply_to'] ?? null)) {
                $email->replyTo($reply);
            }
            if ($m->html) {
                $email->html($m->html);
            }
            $email->text($m->text ?? trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/p>/i', "\n", (string) $m->html)))));
            foreach ($m->attachments as $a) {
                $email->attach($a['data'], $a['name'], $a['mime'] ?? null);
            }
            foreach ($m->headers as $k => $v) {
                $email->getHeaders()->addTextHeader($k, $v);
            }
            $sent = (new Mailer(static::transport($c)))->send($email);

            return SendResult::ok($email->generateMessageId());
        } catch (\Throwable $e) {
            return SendResult::fail($e->getMessage());
        }
    }
}
