<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromoCode extends Model
{
    protected $fillable = ['code', 'description', 'type', 'value', 'applies_to', 'target', 'usage', 'valid_from', 'valid_until', 'max_uses', 'uses', 'once_per_user', 'is_active', 'batch', 'created_by'];

    public const APPLIES = ['all' => 'Tout (formules et modules)', 'plan' => 'Formules', 'module' => 'Modules et packs'];

    public const USAGES = ['any' => 'Nouvel abonnement ou renouvellement', 'new' => 'Nouvel abonnement uniquement', 'renew' => 'Renouvellement uniquement'];

    protected function casts(): array
    {
        return ['value' => 'float', 'valid_from' => 'datetime', 'valid_until' => 'datetime', 'max_uses' => 'integer', 'uses' => 'integer', 'once_per_user' => 'boolean', 'is_active' => 'boolean'];
    }

    public function isValidNow(): bool
    {
        return $this->is_active && (! $this->valid_from || $this->valid_from->lte(now())) && (! $this->valid_until || $this->valid_until->gte(now()))
            && (! $this->max_uses || $this->uses < $this->max_uses);
    }

    public function label(): string
    {
        return $this->type === 'percent' ? rtrim(rtrim(number_format($this->value, 2, ',', ''), '0'), ',').' %' : number_format($this->value, 0, ',', ' ').' F';
    }
}
