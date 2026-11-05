<?php

namespace App\Support;

/**
 * En-têtes de téléchargement sûrs pour les fichiers déposés par les utilisateurs : affichage dans le navigateur
 * seulement pour les PDF, images et médias ; tout le reste (HTML, SVG, scripts…) part en pièce jointe, sans exécution.
 */
class SafeDownload
{
    public const INLINE = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'video/mp4', 'video/webm', 'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/mp4', 'text/plain'];

    public static function headers(?string $mime, string $name, bool $inline = false): array
    {
        $mime = strtolower(trim(explode(';', (string) $mime)[0])) ?: 'application/octet-stream';
        $safe = in_array($mime, self::INLINE, true);
        $name = str_replace(['"', "\n", "\r"], '', $name);

        return [
            'Content-Type' => $safe ? $mime : 'application/octet-stream',
            'Content-Disposition' => ($inline && $safe ? 'inline' : 'attachment').'; filename="'.$name.'"; filename*=UTF-8\'\''.rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox",
        ];
    }
}
