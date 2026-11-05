<?php

namespace App\Modules\Signature\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SignRequest extends Model
{
    use BelongsToCompany, HasUuids;

    protected $table = 'sg_requests';

    protected static function booted(): void
    {
        // Bon de sortie de matériel : son état de signature suit la demande
        static::saved(function (self $r) {
            if ($r->source_type === 'mat_checkout' && $r->wasChanged('status') && class_exists(\App\Modules\Materiel\Models\Checkout::class)) {
                \App\Modules\Materiel\Models\Checkout::signatureChanged($r);
            }
        });
    }

    protected $fillable = ['title', 'message', 'sequential', 'expires_at', 'source_type', 'source_id'];

    protected $hidden = ['original_path', 'signed_path', 'company_id'];

    public const STATUSES = ['draft' => 'Brouillon', 'pending' => 'En cours', 'completed' => 'Signé', 'declined' => 'Refusé', 'cancelled' => 'Annulé', 'expired' => 'Expiré'];

    protected function casts(): array
    {
        return ['sequential' => 'boolean', 'stamped' => 'boolean', 'expires_at' => 'date:Y-m-d', 'sent_at' => 'datetime', 'completed_at' => 'datetime', 'reminded_at' => 'datetime'];
    }

    public function signers(): HasMany
    {
        return $this->hasMany(Signer::class, 'request_id')->orderBy('position')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SignEvent::class, 'request_id')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'pending';
    }
}
