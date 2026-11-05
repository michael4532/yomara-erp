<?php

namespace App\Modules\Tresorerie\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\Syncable;
use App\Modules\Tresorerie\TreasuryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Dépense ou recette hors factures (frais bancaires, loyer, salaires, apport…) et virement interne. */
class Operation extends Model
{
    use BelongsToBranch, Syncable;

    protected $table = 'tr_operations';

    public static string $syncEntity = 'tr_operations';

    public static array $searchable = ['label', 'reference'];

    public static string $viewPermission = 'tresorerie.view|tresorerie.operate|tresorerie.manage';

    public static string $managePermission = 'tresorerie.operate|tresorerie.manage';

    /** Catégorie => [libellé, compte comptable de contrepartie, sens habituel (+1 entrée / −1 sortie)] */
    public const CATEGORIES = [
        'bank_fees' => ['Frais bancaires et agios', '631', -1],
        'rent' => ['Loyer et charges', '622', -1],
        'utilities' => ['Eau, électricité, carburant', '605', -1],
        'telecom' => ['Téléphone et internet', '628', -1],
        'supplies' => ['Fournitures et petits achats', '604', -1],
        'transport' => ['Transport et déplacements', '618', -1],
        'maintenance' => ['Entretien et réparations', '624', -1],
        'fees' => ['Honoraires', '632', -1],
        'salaries' => ['Salaires versés', '422', -1],
        'social' => ['Cotisations sociales (CNPS)', '431', -1],
        'taxes' => ['Impôts et taxes', '641', -1],
        'loan' => ['Emprunt (reçu ou remboursé)', '162', 1],
        'interest' => ['Intérêts d\'emprunt', '671', -1],
        'owner' => ['Apport ou retrait de l\'exploitant', '461', 1],
        'other_income' => ['Autre recette', '758', 1],
        'other_expense' => ['Autre dépense', '658', -1],
        'expense_report' => ['Remboursement de notes de frais', '4286', -1],
        'transfer' => ['Virement entre comptes', '585', 0],
    ];

    protected $fillable = ['account_id', 'date', 'amount', 'label', 'category', 'partner_id', 'reference', 'transfer_id', 'notes', 'branch_id'];

    public function branchFallback(): ?string
    {
        return $this->account_id ? \Illuminate\Support\Facades\DB::table('tr_accounts')->where('id', $this->account_id)->value('branch_id') : null;
    }

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'amount' => 'float', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'branch_id' => 'nullable|uuid',
            'account_id' => ['required', 'uuid', Rule::exists('tr_accounts', 'id')->where('company_id', $company)],
            'date' => 'required|date',
            'amount' => 'required|numeric|not_in:0',
            'label' => 'required|string|max:255',
            'category' => 'required|in:'.implode(',', array_keys(self::CATEGORIES)),
            'partner_id' => ['nullable', 'uuid', Rule::exists('partners', 'id')->where('company_id', $company)],
            'reference' => 'nullable|string|max:100',
            'transfer_id' => 'nullable|uuid',
            'notes' => 'nullable|string|max:2000',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if ($creating) {
            $this->user_id = auth()->id();
        }
        if (($data['category'] ?? $this->category) === 'transfer' && empty($data['transfer_id'] ?? $this->transfer_id)) {
            throw new \DomainException('Un virement interne se saisit depuis « Virement entre comptes ».');
        }
        if (! $creating) {
            app(TreasuryService::class)->assertNotReconciled($this, $data);
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        app(TreasuryService::class)->postOperation($this);
    }

    public function beforeEntityDelete(): void
    {
        app(TreasuryService::class)->assertNotReconciled($this, null);
        app(TreasuryService::class)->unpostOperation($this);
    }
}
