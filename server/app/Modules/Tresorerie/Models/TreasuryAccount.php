<?php

namespace App\Modules\Tresorerie\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\Syncable;
use App\Modules\Tresorerie\TreasuryService;
use Illuminate\Database\Eloquent\Model;

/** Compte de trésorerie : banque, caisse ou portefeuille Mobile Money. */
class TreasuryAccount extends Model
{
    use BelongsToBranch, Syncable;

    protected $table = 'tr_accounts';

    public static string $syncEntity = 'tr_accounts';

    public static string $viewPermission = 'tresorerie.view|tresorerie.operate|tresorerie.manage|ventes.payments.manage|ventes.manage|pos.use|achats.manage';

    public static string $managePermission = 'tresorerie.manage';

    public const KINDS = ['bank' => 'Banque', 'cash' => 'Caisse', 'mobile_money' => 'Mobile Money'];

    protected $fillable = ['name', 'kind', 'bank_name', 'number', 'opening_balance', 'opening_date', 'gl_code', 'methods', 'low_balance', 'active', 'sort', 'branch_id'];

    /** Un compte sans agence (banque du siège) reste visible de tous. */
    public static bool $branchShared = true;

    protected $hidden = ['momo_token'];

    protected function casts(): array
    {
        return ['opening_balance' => 'float', 'opening_date' => 'date:Y-m-d', 'methods' => 'array', 'low_balance' => 'float', 'active' => 'boolean', 'sort' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'branch_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('branches')],
            'name' => 'required|string|max:100',
            'kind' => 'required|in:bank,cash,mobile_money',
            'bank_name' => 'nullable|string|max:100',
            'number' => 'nullable|string|max:60',
            'opening_balance' => 'nullable|numeric',
            'opening_date' => 'nullable|date',
            'gl_code' => 'nullable|string|max:12|regex:/^5\d{1,10}$/',
            'methods' => 'nullable|array',
            'methods.*' => 'in:cash,mobile_money,card,bank_transfer,cheque,other',
            'low_balance' => 'nullable|numeric',
            'active' => 'nullable|boolean',
            'sort' => 'nullable|integer|min:0',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $kind = $data['kind'] ?? $this->kind;
        if ($creating) {
            $data['gl_code'] ??= app(TreasuryService::class)->nextGlCode($kind);
            $data['methods'] ??= match ($kind) { 'cash' => ['cash', 'other'], 'mobile_money' => ['mobile_money'], default => ['bank_transfer', 'card', 'cheque'] };
            $data['opening_date'] ??= now()->toDateString();
        }
    }

    /** Solde d'ouverture passé en comptabilité (journal des à-nouveaux) pour que la balance et la trésorerie concordent. */
    public function afterEntityWrite(array $data, bool $creating): void
    {
        if ($creating || $this->wasChanged(['opening_balance', 'opening_date', 'gl_code', 'name'])) {
            app(TreasuryService::class)->postOpening($this);
        }
    }

    public function beforeEntityDelete(): void
    {
        app(TreasuryService::class)->postOpening($this, remove: true);
        if (Operation::where('account_id', $this->id)->exists() || \DB::table('payments')->where('treasury_account_id', $this->id)->whereNull('deleted_at')->exists()) {
            throw new \DomainException('Ce compte a des mouvements : désactivez-le plutôt.');
        }
    }
}
