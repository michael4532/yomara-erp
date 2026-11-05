<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** Réglages de la plateforme (clé → valeur JSON). */
class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function get(string $key, $default = null)
    {
        if (! Schema::hasTable('platform_settings')) {
            return $default;
        }
        $row = static::find($key);

        return $row ? ($row->value['v'] ?? $default) : $default;
    }

    public static function put(string $key, $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
    }
}
