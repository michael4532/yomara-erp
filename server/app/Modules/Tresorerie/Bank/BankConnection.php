<?php

namespace App\Modules\Tresorerie\Bank;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Connexion d'un compte de trésorerie à sa source de relevés (API Wave Business, banque européenne, boîte e-mail). */
class BankConnection extends Model
{
    use BelongsToCompany, HasUuids;

    protected $table = 'tr_bank_connections';

    protected $guarded = ['id', 'company_id'];

    protected $hidden = ['credentials'];

    public const PROVIDERS = [
        'wave' => 'Wave Business (API)',
        'gocardless' => 'Banque européenne (open banking)',
        'email' => 'Relevés reçus par e-mail',
    ];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'meta' => 'array', 'sync_from' => 'date', 'last_sync_at' => 'datetime', 'next_sync_at' => 'datetime',
            'imported' => 'integer', 'last_balance' => 'float'];
    }

    public function inboundAddress(): ?string
    {
        if (! $this->inbound_token) {
            return null;
        }
        $domain = config('erp.bank.inbound_domain');

        return $domain ? 'releves+'.$this->inbound_token.'@'.$domain : null;
    }

    public function inboundUrl(): ?string
    {
        return $this->inbound_token ? url('/api/bank-inbound/'.$this->inbound_token) : null;
    }
}
