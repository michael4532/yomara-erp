<?php

namespace App\Support;

class Installation
{
    public static function lockFile(): string
    {
        return storage_path('app/installed.lock');
    }

    public static function isInstalled(): bool
    {
        return file_exists(static::lockFile());
    }

    public static function markInstalled(array $info = []): void
    {
        @mkdir(dirname(static::lockFile()), 0775, true);
        file_put_contents(static::lockFile(), json_encode($info + ['installed_at' => date('c'), 'version' => config('erp.version')], JSON_PRETTY_PRINT));
    }
}
