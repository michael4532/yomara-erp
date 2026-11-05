<?php

namespace App\Modules\NotesFrais\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    use Syncable;

    protected $table = 'nf_categories';

    public static string $syncEntity = 'nf_categories';

    public static string $viewPermission = 'notes_frais.self|notes_frais.approve|notes_frais.manage';

    public static string $managePermission = 'notes_frais.manage';

    protected $fillable = ['name', 'gl_code', 'max_amount', 'receipt_required', 'active', 'sort'];

    /** Catégories de départ (comptes SYSCOHADA). */
    public const DEFAULTS = [
        ['Transport, taxi, VTC', '618', null, false],
        ['Carburant', '6053', null, true],
        ['Repas et réceptions', '6383', 15000, true],
        ['Hébergement et missions', '6384', null, true],
        ['Fournitures et petit matériel', '6055', null, true],
        ['Téléphone et internet', '628', null, true],
        ['Autres frais', '638', null, true],
    ];

    protected function casts(): array
    {
        return ['max_amount' => 'float', 'receipt_required' => 'boolean', 'active' => 'boolean', 'sort' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'gl_code' => ['required', 'regex:/^6\d{1,7}$/'],
            'max_amount' => 'nullable|numeric|min:0',
            'receipt_required' => 'nullable|boolean',
            'active' => 'nullable|boolean',
            'sort' => 'nullable|integer|min:0',
        ];
    }

    public function beforeEntityDelete(): void
    {
        if (Expense::where('category_id', $this->id)->exists()) {
            throw new \DomainException('Des dépenses utilisent cette catégorie : désactivez-la plutôt.');
        }
    }
}
