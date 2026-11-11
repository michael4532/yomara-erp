<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageBackend extends Model
{
    protected $fillable = ['name', 'provider', 'endpoint', 'region', 'bucket', 'access_key', 'secret_key', 'capacity_mb', 'used_bytes', 'priority', 'is_active', 'last_error', 'last_error_at', 'last_ok_at'];

    protected $hidden = ['access_key', 'secret_key'];

    protected function casts(): array
    {
        return ['access_key' => 'encrypted', 'secret_key' => 'encrypted', 'is_active' => 'boolean', 'capacity_mb' => 'integer', 'used_bytes' => 'integer',
            'priority' => 'integer', 'last_error_at' => 'datetime', 'last_ok_at' => 'datetime'];
    }

    public function isLocal(): bool
    {
        return $this->provider === 'local';
    }

    public function freeBytes(): ?int
    {
        return $this->capacity_mb ? max(0, $this->capacity_mb * 1048576 - $this->used_bytes) : null;
    }
}
