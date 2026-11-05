<?php

namespace App\Modules\Documents\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DocShare extends Model
{
    use BelongsToCompany;

    protected $table = 'dc_shares';

    protected $fillable = ['file_id', 'folder_id', 'expires_at', 'max_downloads'];

    protected $hidden = ['token', 'token_hash', 'password_hash', 'company_id'];

    protected $appends = ['has_password'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_access_at' => 'datetime', 'downloads' => 'integer', 'max_downloads' => 'integer'];
    }

    public function getHasPasswordAttribute(): bool
    {
        return (bool) $this->password_hash;
    }

    public function url(): string
    {
        return rtrim(config('app.url'), '/').'/d/'.$this->token;
    }

    public function active(): bool
    {
        return ! $this->revoked_at && (! $this->expires_at || $this->expires_at->isFuture()) && (! $this->max_downloads || $this->downloads < $this->max_downloads);
    }
}
