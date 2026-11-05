<?php

namespace App\Modules\Mailbox\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/** Compte de messagerie : IMAP, POP3, Microsoft 365 (Graph) ou Google (IMAP + OAuth). */
class Account extends Model
{
    use BelongsToCompany;

    protected $table = 'mb_accounts';

    protected $guarded = ['id', 'company_id'];

    protected $hidden = ['credentials'];

    public const PROVIDERS = ['imap' => 'IMAP / SMTP', 'pop3' => 'POP3 / SMTP', 'microsoft' => 'Microsoft 365 / Outlook', 'google' => 'Gmail / Google Workspace'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'state' => 'array', 'shared_with' => 'array', 'user_id' => 'integer', 'sync_days' => 'integer', 'calendar_sync' => 'boolean',
            'last_sync_at' => 'datetime', 'next_sync_at' => 'datetime'];
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class, 'account_id');
    }

    public function secret(): array
    {
        return $this->credentials ? (json_decode(rescue(fn () => Crypt::decryptString($this->credentials), '{}', false), true) ?: []) : [];
    }

    public function setSecret(array $v): static
    {
        $this->credentials = Crypt::encryptString(json_encode($v));

        return $this;
    }

    public function setting(string $k, $default = null)
    {
        return $this->settings[$k] ?? $default;
    }

    public function stateValue(string $k, $default = null)
    {
        return $this->state[$k] ?? $default;
    }

    public function putState(array $v): void
    {
        $this->state = array_merge($this->state ?? [], $v);
    }

    public function isOAuth(): bool
    {
        return in_array($this->provider, ['microsoft', 'google'], true);
    }

    /** L'utilisateur peut-il lire cette boîte ? (la sienne ou une boîte partagée qui lui est attribuée) */
    public function readableBy(int $userId): bool
    {
        return $this->user_id === $userId || ($this->user_id === null && in_array($userId, array_map('intval', $this->shared_with ?? []), true));
    }

    public static function forUser(int $userId)
    {
        return static::query()->where(fn ($q) => $q->where('user_id', $userId)->orWhere(fn ($q) => $q->whereNull('user_id')->whereJsonContains('shared_with', $userId)));
    }
}
