<?php

namespace App\Modules\Marketing\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Contact extends Model
{
    use BelongsToCompany;

    protected $table = 'mk_contacts';

    protected $fillable = ['first_name', 'last_name', 'company_name', 'email', 'phone', 'tags', 'source', 'partner_id', 'lead_id', 'unsubscribed_email_at', 'unsubscribed_sms_at', 'unsubscribed_whatsapp_at', 'birthday', 'city', 'fields', 'score', 'opt_in', 'consent_at', 'consent_ip', 'last_activity_at'];

    protected $appends = ['full_name'];

    protected function casts(): array
    {
        return ['unsubscribed_email_at' => 'datetime', 'unsubscribed_sms_at' => 'datetime', 'unsubscribed_whatsapp_at' => 'datetime', 'birthday' => 'date:Y-m-d', 'fields' => 'array', 'consent_at' => 'datetime', 'last_activity_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            $c->token ??= Str::random(32);
            $c->email = $c->email ? mb_strtolower(trim($c->email)) : null;
        });
    }

    public function lists(): BelongsToMany
    {
        return $this->belongsToMany(MkList::class, 'mk_list_contact', 'contact_id', 'list_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? '')) ?: ($this->company_name ?? $this->email ?? $this->phone ?? '');
    }

    public function address(string $channel): ?string
    {
        return $channel === 'email' ? $this->email : $this->phone;
    }

    public function unsubscribed(string $channel): bool
    {
        return (bool) $this->{'unsubscribed_'.$channel.'_at'};
    }

    public function vars(): array
    {
        return [
            'prenom' => $this->first_name ?: ($this->full_name ?: ''),
            'nom' => $this->last_name ?? '',
            'nom_complet' => $this->full_name,
            'societe' => $this->company_name ?? '',
            'email' => $this->email ?? '',
            'telephone' => $this->phone ?? '',
            'ville' => $this->city ?? '',
        ] + collect((array) $this->fields)->mapWithKeys(fn ($v, $k) => [\Illuminate\Support\Str::slug($k, '_') => is_scalar($v) ? (string) $v : ''])->all();
    }

    public function tagList(): array
    {
        return array_values(array_filter(array_map(fn ($t) => mb_strtolower(trim($t)), explode(',', (string) $this->tags))));
    }

    /** Engagement : +1 ouverture, +3 clic, +5 formulaire, −10 désinscription. */
    public function bump(int $points): void
    {
        $this->forceFill(['score' => $this->score + $points, 'last_activity_at' => now()])->saveQuietly();
    }
}
