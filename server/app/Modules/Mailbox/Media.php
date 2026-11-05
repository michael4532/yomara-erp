<?php

namespace App\Modules\Mailbox;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Images publiques des e-mails (logos et photos des signatures, photos de profil) : elles doivent rester joignables
 * par les messageries des destinataires, d'où une adresse publique au nom imprévisible.
 */
class Media
{
    public const DIR = 'mailbox-media';

    public static function store(UploadedFile|string $file, string $ext = 'png'): string
    {
        $bytes = $file instanceof UploadedFile ? $file->get() : $file;
        if ($file instanceof UploadedFile) {
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'png');
        }
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        if (! in_array($ext, ['png', 'jpg', 'gif', 'webp'], true) || @getimagesizefromstring($bytes) === false) {
            throw new \DomainException('Image non reconnue : utilisez un fichier PNG, JPG, GIF ou WebP.');
        }
        $name = Str::random(32).'.'.$ext;
        Storage::disk('local')->put(self::DIR.'/'.$name, $bytes);

        return url('/mailbox/media/'.$name);
    }

    public static function name(?string $url): ?string
    {
        return $url && preg_match('#/mailbox/media/([A-Za-z0-9]{32}\.(png|jpg|gif|webp))$#', $url, $m) ? $m[1] : null;
    }

    public static function path(string $name): ?string
    {
        $p = self::DIR.'/'.$name;

        return preg_match('/^[A-Za-z0-9]{32}\.(png|jpg|gif|webp)$/', $name) && Storage::disk('local')->exists($p) ? Storage::disk('local')->path($p) : null;
    }

    public static function delete(?string $url): void
    {
        if ($n = self::name($url)) {
            Storage::disk('local')->delete(self::DIR.'/'.$n);
        }
    }
}
