<?php
// Faux serveur IMAP (sans chiffrement) pour les tests : php fake_imap.php <port> <dossier de messages .eml> <fichier d'état>
[$self, $port, $dir, $state] = $argv;
$srv = stream_socket_server('tcp://127.0.0.1:'.$port, $errno, $err);
if (! $srv) {
    fwrite(STDERR, $err);
    exit(1);
}
file_put_contents($state, 'ready');
$files = glob($dir.'/*.eml');
sort($files);
$msgs = [];
foreach ($files as $i => $f) {
    $msgs[$i + 101] = file_get_contents($f); // UID 101, 102…
}
$deadline = time() + 25;
while (time() < $deadline && ($c = @stream_socket_accept($srv, 5))) {
    fwrite($c, "* OK fake IMAP ready\r\n");
    while (($line = fgets($c)) !== false) {
        [$tag, $cmd] = array_pad(explode(' ', trim($line), 2), 2, '');
        $u = strtoupper($cmd);
        if (str_starts_with($u, 'LOGIN')) {
            fwrite($c, str_contains($cmd, '"secret"') ? "$tag OK LOGIN completed\r\n" : "$tag NO [AUTHENTICATIONFAILED] Invalid credentials\r\n");
        } elseif (str_starts_with($u, 'SELECT')) {
            fwrite($c, '* '.count($msgs)." EXISTS\r\n* OK [UIDVALIDITY 7] UIDs valid\r\n$tag OK [READ-WRITE] SELECT completed\r\n");
        } elseif (str_starts_with($u, 'UID SEARCH')) {
            preg_match('/UID (\d+):\*/', $u, $m);
            $from = (int) ($m[1] ?? 1);
            $found = array_filter(array_keys($msgs), fn ($x) => $x >= $from) ?: [max(array_keys($msgs))];
            fwrite($c, '* SEARCH '.implode(' ', $found)."\r\n$tag OK SEARCH completed\r\n");
        } elseif (str_starts_with($u, 'UID FETCH')) {
            preg_match('/UID FETCH (\d+)/', $u, $m);
            $raw = $msgs[(int) $m[1]] ?? '';
            fwrite($c, '* 1 FETCH (UID '.$m[1].' BODY[] {'.strlen($raw)."}\r\n".$raw.")\r\n$tag OK FETCH completed\r\n");
        } elseif (str_starts_with($u, 'UID STORE')) {
            file_put_contents($state, $u."\n", FILE_APPEND);
            fwrite($c, "$tag OK STORE completed\r\n");
        } elseif (str_starts_with($u, 'LOGOUT')) {
            fwrite($c, "* BYE\r\n$tag OK LOGOUT completed\r\n");
            break;
        } else {
            fwrite($c, "$tag BAD unknown\r\n");
        }
    }
    fclose($c);
}
