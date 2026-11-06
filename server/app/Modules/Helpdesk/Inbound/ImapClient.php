<?php

namespace App\Modules\Helpdesk\Inbound;

/**
 * Client IMAP minimal (sans l'extension imap de PHP, absente de PHP 8.4) :
 * connexion, sélection du dossier, recherche des messages au-delà d'un UID, lecture du message brut.
 */
class ImapClient
{
    /** @var resource|null */
    protected $s = null;

    protected int $tag = 0;

    public function __construct(protected string $host, protected int $port = 993, protected string $encryption = 'ssl', protected int $timeout = 20) {}

    public function connect(string $user, string $password): void
    {
        // En SaaS : pas de connexion vers le réseau interne de la plateforme (même garde que la Boîte mail)
        // (connexion à l'IP vérifiée ; le nom d'hôte reste le peer_name TLS / SNI)
        try {
            $target = \App\Modules\Mailbox\Protocol\ImapClient::guardHost($this->host);
        } catch (\Throwable $e) {
            throw new \RuntimeException($e->getMessage());
        }
        $remote = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://').$target.':'.$this->port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $this->host]]);
        $s = @stream_socket_client($remote, $errno, $err, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (! $s) {
            throw new \RuntimeException('Connexion impossible à '.$this->host.':'.$this->port.' ('.($err ?: 'délai dépassé').').');
        }
        stream_set_timeout($s, $this->timeout);
        $this->s = $s;
        $this->line();
        if ($this->encryption === 'tls') {
            $this->command('STARTTLS');
            if (! stream_socket_enable_crypto($this->s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('Échec du chiffrement TLS.');
            }
        }
        $this->command('LOGIN '.$this->quote($user).' '.$this->quote($password), 'Identifiant ou mot de passe refusé par le serveur.');
    }

    public function select(string $folder): int
    {
        $lines = $this->command('SELECT '.$this->quote($folder), 'Dossier introuvable : '.$folder);
        foreach ($lines as $l) {
            if (preg_match('/UIDVALIDITY (\d+)/', $l, $m)) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    /** @return int[] UID des messages plus récents que $afterUid */
    public function uidsAfter(int $afterUid): array
    {
        $lines = $this->command('UID SEARCH UID '.($afterUid + 1).':*');
        $uids = [];
        foreach ($lines as $l) {
            if (str_starts_with($l, '* SEARCH')) {
                foreach (preg_split('/\s+/', trim(substr($l, 8))) as $u) {
                    if (ctype_digit($u) && (int) $u > $afterUid) {
                        $uids[] = (int) $u;
                    }
                }
            }
        }
        sort($uids);

        return $uids;
    }

    public function fetchRaw(int $uid): ?string
    {
        $tag = $this->send('UID FETCH '.$uid.' (BODY.PEEK[])');
        $raw = null;
        while (($l = $this->line()) !== null) {
            if (preg_match('/\{(\d+)\}\r?\n?$/', $l, $m)) {
                $raw = $this->read((int) $m[1]);

                continue;
            }
            if (str_starts_with($l, $tag.' ')) {
                if (! str_starts_with($l, $tag.' OK')) {
                    throw new \RuntimeException('Lecture du message refusée.');
                }
                break;
            }
        }

        return $raw;
    }

    public function markSeen(int $uid): void
    {
        $this->command('UID STORE '.$uid.' +FLAGS (\\Seen)');
    }

    public function logout(): void
    {
        if ($this->s) {
            try {
                $this->send('LOGOUT');
            } catch (\Throwable) {
            }
            fclose($this->s);
            $this->s = null;
        }
    }

    protected function quote(string $v): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $v).'"';
    }

    protected function send(string $cmd): string
    {
        $tag = 'Y'.(++$this->tag);
        fwrite($this->s, $tag.' '.$cmd."\r\n");

        return $tag;
    }

    /** @return string[] lignes non étiquetées */
    protected function command(string $cmd, ?string $error = null): array
    {
        $tag = $this->send($cmd);
        $out = [];
        while (($l = $this->line()) !== null) {
            if (str_starts_with($l, $tag.' ')) {
                if (! str_starts_with($l, $tag.' OK')) {
                    throw new \RuntimeException($error ?? ('Commande refusée : '.trim(substr($l, strlen($tag) + 1))));
                }

                return $out;
            }
            $out[] = rtrim($l, "\r\n");
        }
        throw new \RuntimeException('Le serveur IMAP ne répond plus.');
    }

    protected function line(): ?string
    {
        $l = fgets($this->s);

        return $l === false ? null : $l;
    }

    protected function read(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n && ! feof($this->s)) {
            $chunk = fread($this->s, min(8192, $n - strlen($buf)));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buf .= $chunk;
        }

        return $buf;
    }
}
