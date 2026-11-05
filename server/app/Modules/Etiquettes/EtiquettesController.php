<?php

namespace App\Modules\Etiquettes;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Product;
use Illuminate\Http\Request;

class EtiquettesController extends Controller
{
    /** Attribue un code EAN-13 interne (préfixe 20) et, aux articles vendus au kilo, un code balance (PLU). */
    public function barcodes(Request $request)
    {
        $v = $request->validate(['product_ids' => 'nullable|array', 'product_ids.*' => 'uuid', 'plu' => 'nullable|boolean']);
        $q = Product::where('active', true)->when($v['product_ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids));
        $done = ['barcodes' => 0, 'plu' => 0];
        foreach ($q->get() as $p) {
            $changes = [];
            if (blank($p->barcode)) {
                $changes['barcode'] = Barcodes::nextInternal();
                $done['barcodes']++;
            }
            if (($v['plu'] ?? true) && blank($p->plu) && in_array(mb_strtolower((string) $p->unit), ['kg', 'kilo', 'kilogramme'], true)) {
                $changes['plu'] = Barcodes::nextPlu();
                $done['plu']++;
            }
            if ($changes) {
                $p->forceFill($changes)->save();
            }
        }

        return response()->json(['data' => $done]);
    }
}
