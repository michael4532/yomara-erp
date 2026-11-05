<?php

namespace App\Modules\Production;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\Bom;

class ProductionController extends Controller
{
    public function needs(ProductionService $svc)
    {
        return response()->json(['data' => $svc->needs()]);
    }

    public function cost(string $id, ProductionService $svc)
    {
        return response()->json(['data' => $svc->bomCost(Bom::findOrFail($id))]);
    }
}
