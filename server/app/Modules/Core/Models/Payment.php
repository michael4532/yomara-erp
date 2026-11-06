<?php

namespace App\Modules\Core\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paiement rattaché à un document (encaissement client ou règlement fournisseur).
 * Les sous-classes fixent la direction et le type de document payé.
 */
class Payment extends Model
{
    use Syncable;

    protected $table = 'payments';

    public static string $syncEntity = 'payments';

    public const DIRECTION = '';

    /** Classe du document payé */
    public const DOCUMENT_CLASS = TradeDocument::class;

    public const METHODS = ['cash', 'mobile_money', 'card', 'bank_transfer', 'cheque', 'other', 'loyalty', 'gift_card'];

    public static array $searchable = ['reference'];

    protected $fillable = ['document_id', 'date', 'amount', 'method', 'reference', 'notes', 'treasury_account_id', 'exchange_rate'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'amount' => 'float', 'exchange_rate' => 'float', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        if (static::DIRECTION !== '') {
            static::addGlobalScope('direction', fn (Builder $q) => $q->where('payments.direction', static::DIRECTION));
        }
        static::creating(function (self $p) {
            $p->direction = static::DIRECTION;
            $p->user_id ??= auth()->id();
        });
        static::deleted(fn (self $p) => $p->document()->first()?->refreshPaymentStatus());
    }

    public static function syncRules(): array
    {
        return [
            'document_id' => 'required|uuid',
            'date' => 'required|date',
            'amount' => 'required|numeric|gt:0',
            'method' => 'required|in:'.implode(',', static::METHODS),
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'treasury_account_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('tr_accounts')],
            'exchange_rate' => 'nullable|numeric|gt:0|max:1000000000',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(static::DOCUMENT_CLASS, 'document_id');
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $docClass = static::DOCUMENT_CLASS;
        if (! $creating && isset($data['document_id']) && (string) $data['document_id'] !== (string) $this->document_id) {
            throw new \DomainException('Un paiement ne change pas de facture : supprimez-le et enregistrez-le sur la bonne facture.');
        }
        $doc = $docClass::find($data['document_id'] ?? $this->document_id);

        if (! $doc) {
            throw new \DomainException('Document introuvable.');
        }
        if ($doc->status !== 'validated' || ! in_array($doc->type, $docClass::PAYABLE_TYPES, true)) {
            throw new \DomainException('Seules les factures validées peuvent recevoir un paiement.');
        }

        $already = static::where('document_id', $doc->id)->where($this->getKeyName(), '!=', $this->getKey() ?? '')->sum('amount');
        $amount = (float) ($data['amount'] ?? $this->amount);
        if ($already + $amount > $doc->total + 0.009) {
            throw new \DomainException('Le montant dépasse le reste à payer ('.number_format($doc->total - $already, 0, ',', ' ').').');
        }

        $this->partner_id = $doc->partner_id;

        // Devise étrangère : cours du jour du paiement (écart de change constaté en comptabilité)
        $base = strtoupper((string) app('tenant')->currency);
        if (strtoupper((string) $doc->currency) === $base) {
            $data['exchange_rate'] = 1;
        } elseif (empty($data['exchange_rate']) && ($creating || array_key_exists('date', $data))) {
            $rate = class_exists(\App\Modules\Devises\FxService::class) ? app(\App\Modules\Devises\FxService::class)->rate(app('tenant'), $doc->currency, $data['date'] ?? $this->date?->toDateString()) : null;
            $data['exchange_rate'] = $rate ?? $doc->exchange_rate ?? 1;
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        $this->document()->first()?->refreshPaymentStatus();
    }
}
