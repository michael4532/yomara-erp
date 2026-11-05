<?php

namespace App\Modules\Fidelite\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Carte cadeau ou bon d'achat : un montant prépayé utilisable en une ou plusieurs fois. */
class GiftCard extends Model
{
    use Syncable;

    protected $table = 'gift_cards';

    public static string $syncEntity = 'gift_cards';

    public static string $viewPermission = 'fidelite.view|fidelite.manage|pos.use|ventes.view';

    public static string $managePermission = 'fidelite.manage';

    public static array $searchable = ['code', 'recipient_name'];

    protected $fillable = ['code', 'initial_amount', 'partner_id', 'recipient_name', 'message', 'expires_at', 'status', 'source_document_id', 'kind'];

    protected function casts(): array
    {
        return ['initial_amount' => 'float', 'balance' => 'float', 'expires_at' => 'date:Y-m-d', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'code' => 'nullable|string|max:40',
            'initial_amount' => 'required|numeric|min:1',
            'partner_id' => ['nullable', 'uuid', Rule::exists('partners', 'id')->where('company_id', $company)],
            'recipient_name' => 'nullable|string|max:255',
            'message' => 'nullable|string|max:255',
            'expires_at' => 'nullable|date',
            'status' => 'nullable|in:active,cancelled',
            'source_document_id' => ['nullable', 'uuid', Rule::exists('trade_documents', 'id')->where('company_id', $company)],
            'kind' => 'nullable|in:gift,voucher',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if ($creating) {
            $data['code'] = strtoupper(trim((string) ($data['code'] ?? ''))) ?: null;
            if ($data['code'] && self::where('code', $data['code'])->exists()) {
                throw new \DomainException('Ce code de carte cadeau existe déjà.');
            }

            return;
        }
        // Le montant ne change jamais : seuls l'annulation, l'échéance et le bénéficiaire se modifient
        $data = array_intersect_key($data, array_flip(['status', 'expires_at', 'recipient_name', 'message', 'partner_id']));
        if (($data['status'] ?? null) === 'cancelled' && $this->balance < $this->initial_amount && $this->status !== 'cancelled' && ! (auth()->user()?->hasPermission('fidelite.manage'))) {
            throw new \DomainException('Carte déjà utilisée.');
        }
    }

    public function beforeEntityDelete(): void
    {
        if (GiftCardMove::where('gift_card_id', $this->id)->exists()) {
            throw new \DomainException('Cette carte a déjà servi : annulez-la plutôt.');
        }
    }

    public static function generateCode(): string
    {
        do {
            $code = implode('-', str_split(strtoupper(Str::random(12)), 4));
            $code = strtr($code, ['0' => '8', 'O' => 'P', 'I' => 'K', '1' => '7', 'L' => 'M']); // pas de caractères ambigus
        } while (self::where('code', $code)->exists());

        return $code;
    }

    protected static function booted(): void
    {
        static::creating(function (self $g) {
            $g->code = $g->code ?: self::generateCode();
            $g->balance ??= $g->initial_amount;
            $g->status ??= 'active';
            $g->kind ??= 'gift';
            if (! $g->expires_at) {
                $months = (int) (app('tenant')?->settings['gift_card_months'] ?? 12);
                $g->expires_at = $months > 0 ? now()->addMonths($months)->toDateString() : null;
            }
        });
    }

    public function usable(): bool
    {
        return $this->status === 'active' && $this->balance > 0 && (! $this->expires_at || $this->expires_at->gte(today()));
    }
}
