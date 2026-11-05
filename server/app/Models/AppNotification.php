<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    protected $fillable = ['company_id', 'user_id', 'type', 'title', 'body', 'module', 'ref', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    /** Notifie un ou plusieurs utilisateurs (doublons et auteur exclus). */
    public static function send(int $companyId, iterable $userIds, string $type, string $title, ?string $body = null, ?string $module = null, ?string $ref = null, ?int $except = null): void
    {
        $now = now();
        $rows = [];
        foreach (collect($userIds)->filter()->unique() as $uid) {
            if ($uid === $except) {
                continue;
            }
            $rows[] = ['company_id' => $companyId, 'user_id' => $uid, 'type' => $type, 'title' => mb_substr($title, 0, 255), 'body' => $body, 'module' => $module, 'ref' => $ref, 'created_at' => $now, 'updated_at' => $now];
        }
        if ($rows) {
            static::insert($rows);
            // Même notification sur les téléphones des destinataires (si Firebase est configuré)
            \App\Support\Push::queue(array_column($rows, 'user_id'), $title, $body, ['type' => $type, 'module' => $module, 'ref' => $ref]);
        }
        \App\Integrations\Channels::forward($companyId, $type, $title, $body);
    }
}
