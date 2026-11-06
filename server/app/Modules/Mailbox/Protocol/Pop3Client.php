<?php

namespace App\Modules\Mailbox\Protocol;

/** Client POP3 minimal (SSL, STARTTLS/STLS ou clair) : USER/PASS, UIDL, LIST, RETR, DELE. */
class Pop3Client
{
    /** @var resource|null */
    protected $sock = null;

    public function __construct(protected int $timeout = 25) {}

    public function connect(string $host, int $port = 995, string $security = 'ssl'): static
    {
        $target = ImapClient::guardHost($host); // IP vérifiée en SaaS ; le nom reste le peer_name TLS
        $ctx = stream_context_create(['ssl' => ['verify_peer' => (bool) config('erp.mailbox.verify_tls', true), 'verify_peer_name' => (bool) config('erp.mailbox.verify_tls', true), 'SNI_enabled' => true, 'peer_name' => $host]]);
        $sock = @stream_socket_client(($security === 'ssl' ? 'ssl://' : 'tcp://').$target.':'.$port, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (! $sock) {
            throw new MailProtocolException("Connexion impossible à $host:$port ($errstr).");
        }
        stream_set_timeout($sock, $this->timeout);
        $this->sock = $sock;
        $this->expectOk($this->line());
        if ($security === 'starttls') {
            $this->cmd('STLS');
            if (! @stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                throw new MailProtocolException('Échec du chiffrement STLS.');
            }
        }

        return $this;
    }

    public function login(string $user, string $password): static
    {
        try {
            $this->cmd('USER '.$user);
            $this->cmd('PASS '.$password);
        } catch (MailProtocolException $e) {
            throw new MailProtocolException('Identifiant ou mot de passe refusé par le serveur POP3.', 0, $e);
        }

        return $this;
    }

    /** @return array<int, string> numéro => identifiant unique */
    public function uidl(): array
    {
        $this->cmd('UIDL');
        $out = [];
        foreach ($this->multi() as $l) {
            [$n, $u] = array_pad(preg_split('/\s+/', trim($l), 2), 2, null);
            if ($u !== null) {
                $out[(int) $n] = $u;
            }
        }

        return $out;
    }

    /** @return array<int, int> numéro => taille */
    public function sizes(): array
    {
        $this->cmd('LIST');
        $out = [];
        foreach ($this->multi() as $l) {
            [$n, $s] = array_pad(preg_split('/\s+/', trim($l), 2), 2, 0);
            $out[(int) $n] = (int) $s;
        }

        return $out;
    }

    public function retr(int $n): string
    {
        $this->cmd('RETR '.$n);

        return implode("\r\n", $this->multi())."\r\n";
    }

    public function delete(int $n): void
    {
        $this->cmd('DELE '.$n);
    }

    public function quit(): void
    {
        if ($this->sock) {
            try {
                $this->write("QUIT\r\n");
                $this->line();
            } catch (\Throwable) {
            }
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    protected function cmd(string $c): string
    {
        $this->write($c."\r\n");

        return $this->expectOk($this->line());
    }

    protected function expectOk(string $l): string
    {
        if (! str_starts_with($l, '+OK')) {
            throw new MailProtocolException('Le serveur POP3 a refusé : '.trim(substr($l, 4)));
        }

        return $l;
    }

    /** Réponse multiligne terminée par « . », avec désempilage des points. */
    protected function multi(): array
    {
        $out = [];
        while (true) {
            $l = rtrim($this->line(), "\r\n");
            if ($l === '.') {
                return $out;
            }
            $out[] = str_starts_with($l, '..') ? substr($l, 1) : $l;
        }
    }

    protected function write(string $d): void
    {
        if (! $this->sock || @fwrite($this->sock, $d) === false) {
            throw new MailProtocolException('Connexion POP3 interrompue.');
        }
    }

    protected function line(): string
    {
        $l = @fgets($this->sock);
        if ($l === false) {
            throw new MailProtocolException('Le serveur POP3 ne répond plus.');
        }

        return $l;
    }
}
