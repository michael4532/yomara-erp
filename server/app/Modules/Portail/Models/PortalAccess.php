<?php

namespace App\Modules\Portail\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Modules\Core\Models\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PortalAccess extends Model
{
    use BelongsToCompany;

    protected $fillable = ['partner_id', 'token', 'active', 'invited_at', 'invited_via', 'last_login_at', 'login_count'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'invited_at' => 'datetime', 'last_login_at' => 'datetime', 'login_count' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $a) => $a->token ??= Str::random(48));
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function url(string $path = ''): string
    {
        return url('/portail/'.$this->token.($path ? '/'.ltrim($path, '/') : ''));
    }
}
