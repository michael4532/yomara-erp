<?php

namespace App\Support;

/** Écrit / met à jour des clés du fichier .env (utilisé par l'installateur). */
class EnvWriter
{
    public static function path(): string
    {
        return base_path('.env');
    }

    public static function ensureExists(): void
    {
        if (! file_exists(static::path())) {
            copy(base_path('.env.example'), static::path());
        }
    }

    public static function set(array $values): void
    {
        static::ensureExists();
        $content = file_get_contents(static::path());

        foreach ($values as $key => $value) {
            $line = $key.'='.static::quote((string) $value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            $content = preg_match($pattern, $content)
                ? preg_replace_callback($pattern, fn () => $line, $content)
                : rtrim($content)."\n".$line."\n";
        }

        file_put_contents(static::path(), $content);
    }

    protected static function quote(string $v): string
    {
        if ($v === '' || preg_match('/^[A-Za-z0-9_.:\/\-+=@]+$/', $v)) {
            return $v;
        }

        // Guillemets simples : valeur littérale (aucune interpolation de $).
        if (! str_contains($v, "'")) {
            return "'".$v."'";
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $v).'"';
    }
}
