<?php

namespace App\Modules\Etiquettes\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

/** Modèle d'étiquette personnalisé (les formats courants sont fournis par l'application). */
class LabelTemplate extends Model
{
    use Syncable;

    protected $table = 'lb_templates';

    public static string $syncEntity = 'lb_templates';

    public static string $viewPermission = 'etiquettes.print|etiquettes.manage';

    public static string $managePermission = 'etiquettes.manage';

    protected $fillable = ['name', 'kind', 'page_w', 'page_h', 'cols', 'rows', 'label_w', 'label_h', 'margin_top', 'margin_left', 'gap_x', 'gap_y', 'style', 'fields'];

    protected function casts(): array
    {
        return [
            'page_w' => 'float', 'page_h' => 'float', 'cols' => 'integer', 'rows' => 'integer', 'label_w' => 'float', 'label_h' => 'float',
            'margin_top' => 'float', 'margin_left' => 'float', 'gap_x' => 'float', 'gap_y' => 'float', 'fields' => 'array', 'version' => 'integer',
        ];
    }

    public static function syncRules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'kind' => 'required|in:sheet,roll',
            'page_w' => 'required|numeric|min:10|max:600',
            'page_h' => 'required|numeric|min:10|max:900',
            'cols' => 'required|integer|min:1|max:20',
            'rows' => 'required|integer|min:1|max:40',
            'label_w' => 'required|numeric|min:10|max:600',
            'label_h' => 'required|numeric|min:5|max:900',
            'margin_top' => 'nullable|numeric|min:0|max:100',
            'margin_left' => 'nullable|numeric|min:0|max:100',
            'gap_x' => 'nullable|numeric|min:0|max:50',
            'gap_y' => 'nullable|numeric|min:0|max:50',
            'style' => 'nullable|in:product,shelf,barcode',
            'fields' => 'nullable|array',
            'fields.*' => 'in:name,price,barcode,sku,unit_price,company,date,category',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $g = fn ($k) => (float) ($data[$k] ?? $this->{$k} ?? 0);
        $w = $g('margin_left') + $g('cols') * $g('label_w') + ($g('cols') - 1) * $g('gap_x');
        $h = $g('margin_top') + $g('rows') * $g('label_h') + ($g('rows') - 1) * $g('gap_y');
        if ($w > $g('page_w') + 0.5 || $h > $g('page_h') + 0.5) {
            throw new \DomainException('Les étiquettes dépassent de la page : vérifiez les dimensions, les marges et le nombre de colonnes / lignes.');
        }
    }
}
