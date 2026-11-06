<?php

namespace App\Modules\Mailbox\Protocol;

/**
 * Client IMAP4rev1 minimal en PHP pur (aucune extension imap requise) : SSL/TLS ou STARTTLS, LOGIN ou XOAUTH2,
 * LIST (SPECIAL-USE), SELECT, UID SEARCH / FETCH / STORE / MOVE, APPEND, EXPUNGE. Noms de dossiers en UTF-7 modifié.
 */
class ImapClient
{
    /** @var resource|null */
    protected $sock = null;

    protected int $tag = 0;

    protected array $caps = [];

    public ?string $selected = null;

    public function __construct(protected int $timeout = 25) {}

    /**
     * Plateforme SaaS : un serveur de messagerie saisi par un client ne peut pas viser le serveur lui-même ni le réseau
     * interne (balayage de ports). Une installation chez le client peut, elle, utiliser un serveur de son réseau local.
     *
     * Renvoie l'adresse à laquelle se connecter : en SaaS, l'IP vérifiée (le nom n'est pas résolu une seconde fois,
     * contre le « DNS rebinding ») ; sinon le nom d'hôte tel quel. Le nom reste utilisé pour TLS (peer_name / SNI).
     */
    public static function guardHost(string $host): string
    {
        if (config('erp.mode') === 'saas' && ! config('erp.mailbox.allow_private_hosts', false)) {
            try {
                $ip = \App\Support\UrlGuard::publicHostIp($host);
            } catch (\DomainException $e) {
                throw new MailProtocolException($e->getMessage());
            }
            if ($ip !== null) {
                return str_contains($ip, ':') ? '['.$ip.']' : $ip;
            }
        }

        return $host;
    }

    public function connect(string $host, int $port = 993, string $security = 'ssl'): static
    {
        $target = self::guardHost($host);
        $ctx = stream_context_create(['ssl' => ['verify_peer' => (bool) config('erp.mailbox.verify_tls', true), 'verify_peer_name' => (bool) config('erp.mailbox.verify_tls', true), 'SNI_enabled' => true, 'peer_name' => $host]]);
        $remote = ($security === 'ssl' ? 'ssl://' : 'tcp://').$target.':'.$port;
        $sock = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (! $sock) {
            throw new MailProtocolException("Connexion impossible à $host:$port ($errstr).");
        }
        stream_set_timeout($sock, $this->timeout);
        $this->sock = $sock;
        $greet = $this->readLine();
        if (! str_starts_with($greet, '* OK') && ! str_starts_with($greet, '* PREAUTH')) {
            throw new MailProtocolException('Réponse inattendue du serveur IMAP : '.trim($greet));
        }
        $this->capability();
        if ($security === 'starttls') {
            $this->command('STARTTLS');
            if (! @stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                throw new MailProtocolException('Échec du chiffrement STARTTLS.');
            }
            $this->capability();
        }

        return $this;
    }

    public function login(string $user, string $password): static
    {
        try {
            $this->command('LOGIN '.$this->quote($user).' '.$this->quote($password));
        } catch (MailProtocolException $e) {
            throw new MailProtocolException('Identifiant ou mot de passe refusé par le serveur IMAP. '.$e->getMessage(), 0, $e);
        }
        $this->capability();

        return $this;
    }

    public function xoauth2(string $user, string $accessToken): static
    {
        $b = base64_encode("user=$user\x01auth=Bearer $accessToken\x01\x01");
        $tag = $this->send('AUTHENTICATE XOAUTH2 '.$b);
        while (true) {
            $line = $this->readLine();
            if (str_starts_with($line, '+')) { // erreur détaillée en base64 : on termine l'échange
                $this->write("\r\n");

                continue;
            }
            if (str_starts_with($line, $tag.' ')) {
                if (! str_starts_with(substr($line, strlen($tag) + 1), 'OK')) {
                    throw new MailProtocolException('Connexion OAuth refusée par le serveur IMAP : '.trim(substr($line, strlen($tag) + 1)));
                }
                break;
            }
        }
        $this->capability();

        return $this;
    }

    public function has(string $cap): bool
    {
        return in_array(strtoupper($cap), $this->caps, true);
    }

    /** @return array<int, array{name: string, path: string, delimiter: ?string, flags: string[], role: string}> */
    public function listFolders(): array
    {
        $cmd = $this->has('SPECIAL-USE') ? 'LIST "" "*" RETURN (SPECIAL-USE)' : 'LIST "" "*"';
        try {
            [, $lines] = $this->command($cmd);
        } catch (MailProtocolException) {
            [, $lines] = $this->command('LIST "" "*"');
        }
        $out = [];
        foreach ($lines as $l) {
            if (! preg_match('/^\* LIST /i', $l)) {
                continue;
            }
            $p = new ImapParser(substr($l, 7));
            $flags = $p->next();
            $delim = $p->next();
            $path = (string) $p->next();
            $flags = array_map('strtolower', is_array($flags) ? $flags : []);
            if (in_array('\\noselect', $flags, true) || in_array('\\nonexistent', $flags, true)) {
                continue;
            }
            $name = self::decodeName($path);
            $out[] = ['name' => $delim && str_contains($name, $delim) ? substr($name, strrpos($name, $delim) + 1) : $name, 'full' => $name, 'path' => $path, 'delimiter' => $delim, 'flags' => $flags, 'role' => self::role($name, $flags)];
        }

        return $out;
    }

    public static function role(string $name, array $flags): string
    {
        foreach (['\\sent' => 'sent', '\\trash' => 'trash', '\\drafts' => 'drafts', '\\junk' => 'junk', '\\archive' => 'archive'] as $f => $r) {
            if (in_array($f, $flags, true)) {
                return $r;
            }
        }
        $n = mb_strtolower(trim(preg_replace('/^(inbox|\[gmail\]|\[google mail\])[.\/]/i', '', $name)));
        if (strtoupper($name) === 'INBOX') {
            return 'inbox';
        }

        return match (true) {
            in_array($n, ['sent', 'sent items', 'sent messages', 'envoyés', 'éléments envoyés', 'messages envoyés', 'sent mail'], true) => 'sent',
            in_array($n, ['trash', 'deleted', 'deleted items', 'deleted messages', 'corbeille', 'éléments supprimés', 'bin'], true) => 'trash',
            in_array($n, ['drafts', 'draft', 'brouillons'], true) => 'drafts',
            in_array($n, ['junk', 'spam', 'junk e-mail', 'junk email', 'courrier indésirable', 'indésirables', 'pourriel'], true) => 'junk',
            in_array($n, ['archive', 'archives'], true) => 'archive',
            default => 'other',
        };
    }

    /** @return array{exists: int, uidvalidity: int, uidnext: int} */
    public function select(string $path, bool $readOnly = false): array
    {
        [, $lines] = $this->command(($readOnly ? 'EXAMINE ' : 'SELECT ').$this->quote($path));
        $this->selected = $path;
        $r = ['exists' => 0, 'uidvalidity' => 0, 'uidnext' => 0];
        foreach ($lines as $l) {
            if (preg_match('/^\* (\d+) EXISTS/i', $l, $m)) {
                $r['exists'] = (int) $m[1];
            } elseif (preg_match('/UIDVALIDITY (\d+)/i', $l, $m)) {
                $r['uidvalidity'] = (int) $m[1];
            } elseif (preg_match('/UIDNEXT (\d+)/i', $l, $m)) {
                $r['uidnext'] = (int) $m[1];
            }
        }

        return $r;
    }

    /** @return array{messages: int, unseen: int} */
    public function status(string $path): array
    {
        [, $lines] = $this->command('STATUS '.$this->quote($path).' (MESSAGES UNSEEN)');
        $r = ['messages' => 0, 'unseen' => 0];
        foreach ($lines as $l) {
            if (preg_match('/MESSAGES (\d+)/i', $l, $m)) {
                $r['messages'] = (int) $m[1];
            }
            if (preg_match('/UNSEEN (\d+)/i', $l, $m)) {
                $r['unseen'] = (int) $m[1];
            }
        }

        return $r;
    }

    /** @return int[] */
    public function uidSearch(string $criteria): array
    {
        [, $lines] = $this->command('UID SEARCH '.$criteria);
        $out = [];
        foreach ($lines as $l) {
            if (preg_match('/^\* SEARCH ?(.*)$/i', trim($l), $m) && trim($m[1]) !== '') {
                foreach (preg_split('/\s+/', trim($m[1])) as $u) {
                    $out[] = (int) $u;
                }
            }
        }
        sort($out);

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>> indexé par UID : FLAGS, RFC822.SIZE, BODY[...]…
     */
    public function uidFetch(string $set, string $items): array
    {
        [, $lines] = $this->command("UID FETCH $set ($items)");
        $out = [];
        foreach ($lines as $l) {
            if (! preg_match('/^\* \d+ FETCH /i', $l)) {
                continue;
            }
            $p = new ImapParser(preg_replace('/^\* \d+ FETCH /i', '', $l));
            $list = $p->next();
            if (! is_array($list)) {
                continue;
            }
            $row = [];
            for ($i = 0; $i + 1 < count($list); $i += 2) {
                $k = strtoupper(is_string($list[$i]) ? $list[$i] : '');
                $row[preg_replace('/\.PEEK/', '', $k)] = $list[$i + 1];
            }
            if (isset($row['UID'])) {
                $out[(int) $row['UID']] = $row;
            }
        }

        return $out;
    }

    public function uidStore(string $set, string $mode, array $flags): void
    {
        $this->command("UID STORE $set {$mode}FLAGS.SILENT (".implode(' ', $flags).')');
    }

    public function uidMove(string $set, string $target): void
    {
        if ($this->has('MOVE')) {
            $this->command("UID MOVE $set ".$this->quote($target));

            return;
        }
        $this->command("UID COPY $set ".$this->quote($target));
        $this->uidStore($set, '+', ['\\Deleted']);
        $this->has('UIDPLUS') ? $this->command("UID EXPUNGE $set") : $this->command('EXPUNGE');
    }

    public function uidDelete(string $set): void
    {
        $this->uidStore($set, '+', ['\\Deleted']);
        $this->has('UIDPLUS') ? $this->command("UID EXPUNGE $set") : $this->command('EXPUNGE');
    }

    /** Ajoute un message brut dans un dossier (copie des messages envoyés, brouillons). Renvoie l'UID si le serveur le donne. */
    public function append(string $path, string $raw, array $flags = ['\\Seen']): ?int
    {
        $raw = preg_replace("/\r?\n/", "\r\n", $raw);
        $len = strlen($raw);
        $tag = $this->send('APPEND '.$this->quote($path).' ('.implode(' ', $flags).") {{$len}}");
        $line = $this->readLine();
        if (! str_starts_with($line, '+')) {
            throw new MailProtocolException('APPEND refusé : '.trim($line));
        }
        $this->write($raw."\r\n");
        while (true) {
            $line = $this->readLine();
            if (str_starts_with($line, $tag.' ')) {
                if (! preg_match('/^'.$tag.' OK/i', $line)) {
                    throw new MailProtocolException('APPEND refusé : '.trim($line));
                }

                return preg_match('/APPENDUID \d+ (\d+)/i', $line, $m) ? (int) $m[1] : null;
            }
        }
    }

    public function createFolder(string $name): void
    {
        $this->command('CREATE '.$this->quote(self::encodeName($name)));
    }

    public function logout(): void
    {
        if ($this->sock) {
            try {
                $this->send('LOGOUT');
                $this->readLine();
            } catch (\Throwable) {
            }
            if (is_resource($this->sock)) {
                @fclose($this->sock);
            }
            $this->sock = null;
        }
    }

    public function __destruct()
    {
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
    }

    // ------------------------------------------------------------------ bas niveau

    protected function capability(): void
    {
        [, $lines] = $this->command('CAPABILITY');
        foreach ($lines as $l) {
            if (preg_match('/^\* CAPABILITY (.*)$/i', trim($l), $m)) {
                $this->caps = array_map('strtoupper', preg_split('/\s+/', $m[1]));
            }
        }
    }

    /** @return array{0: string, 1: string[]} statut + réponses non étiquetées (littéraux inclus) */
    public function command(string $cmd): array
    {
        $tag = $this->send($cmd);
        $lines = [];
        while (true) {
            $resp = $this->readResponse();
            if (str_starts_with($resp, $tag.' ')) {
                $rest = substr($resp, strlen($tag) + 1);
                if (! preg_match('/^OK/i', $rest)) {
                    $msg = trim(preg_replace('/^(NO|BAD)\s*/i', '', $rest));
                    throw new MailProtocolException((mb_check_encoding($msg, 'UTF-8') ? $msg : mb_convert_encoding($msg, 'UTF-8', 'ISO-8859-1')) ?: 'Commande refusée.');
                }

                return [trim($rest), $lines];
            }
            if (str_starts_with($resp, '+')) {
                continue;
            }
            $lines[] = $resp;
        }
    }

    protected function send(string $cmd): string
    {
        $tag = 'Y'.(++$this->tag);
        $this->write("$tag $cmd\r\n");

        return $tag;
    }

    protected function write(string $data): void
    {
        if (! $this->sock) {
            throw new MailProtocolException('Connexion IMAP fermée.');
        }
        for ($w = 0, $n = strlen($data); $w < $n;) {
            $r = @fwrite($this->sock, substr($data, $w));
            if ($r === false || $r === 0) {
                throw new MailProtocolException('Écriture impossible vers le serveur IMAP.');
            }
            $w += $r;
        }
    }

    protected function readLine(): string
    {
        $line = @fgets($this->sock);
        if ($line === false) {
            $meta = stream_get_meta_data($this->sock);
            throw new MailProtocolException(($meta['timed_out'] ?? false) ? 'Le serveur IMAP ne répond plus (délai dépassé).' : 'Connexion IMAP interrompue.');
        }

        return $line;
    }

    /** Une réponse complète : ligne + littéraux {n} éventuels, renvoyés tels quels pour l'analyseur. */
    protected function readResponse(): string
    {
        $buf = '';
        while (true) {
            $line = $this->readLine();
            $buf .= $line;
            if (preg_match('/\{(\d+)\}\r\n$/', $line, $m)) {
                $n = (int) $m[1];
                $got = '';
                while (strlen($got) < $n) {
                    $chunk = @fread($this->sock, min(65536, $n - strlen($got)));
                    if ($chunk === false || $chunk === '') {
                        throw new MailProtocolException('Message IMAP tronqué.');
                    }
                    $got .= $chunk;
                }
                $buf .= $got;

                continue;
            }

            return rtrim($buf, "\r\n");
        }
    }

    public function quote(string $s): string
    {
        return '"'.addcslashes(str_replace(["\r", "\n", "\0"], '', $s), '"\\').'"';
    }

    public static function decodeName(string $s): string
    {
        return str_contains($s, '&') ? (mb_convert_encoding($s, 'UTF-8', 'UTF7-IMAP') ?: $s) : $s;
    }

    public static function encodeName(string $s): string
    {
        return preg_match('/[^\x20-\x7E]|&/', $s) ? mb_convert_encoding($s, 'UTF7-IMAP', 'UTF-8') : $s;
    }

    /** Ensemble d'UID compact : 1:5,8,10:12 */
    public static function set(array $uids): string
    {
        sort($uids);
        $out = [];
        $start = $prev = null;
        foreach ($uids as $u) {
            if ($start === null) {
                $start = $prev = $u;
            } elseif ($u === $prev + 1) {
                $prev = $u;
            } else {
                $out[] = $start === $prev ? "$start" : "$start:$prev";
                $start = $prev = $u;
            }
        }
        if ($start !== null) {
            $out[] = $start === $prev ? "$start" : "$start:$prev";
        }

        return implode(',', $out);
    }
}
