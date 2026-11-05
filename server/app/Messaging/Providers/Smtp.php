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
        $scheme = $enc === 'ssl' || $port === 465 ? 'smtps' : 'smtp';
        $auth = isset($c['username']) && $c['username'] !== '' ? rawurlencode($c['username']).':'.rawurlencode((string) ($c['password'] ?? '')).'@' : '';

        return Transport::fromDsn($scheme.'://'.$auth.$c['host'].':'.$port.($enc === 'none' ? '?auto_tls=false' : ''));
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
