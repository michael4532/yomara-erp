<?php

namespace App\Modules\Juridique;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JuridiqueController extends Controller
{
    public function __construct(private JuridiqueHooks $h) {}

    public function dashboard()
    {
        return ['data' => $this->h->dashboard(app('tenant'))];
    }

    /** Rédige le texte du contrat à partir d'un modèle (variables remplies avec la société, le tiers, le salarié). */
    public function draft(Request $r, int $id)
    {
        $d = $r->validate(['template_id' => 'required|integer']);
        try {
            return ['data' => $this->h->draft(app('tenant'), $id, (int) $d['template_id'])];
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function fields(int $id)
    {
        $c = app('tenant');
        $k = DB::table('jr_contracts')->where('company_id', $c->id)->where('id', $id)->first();
        abort_unless($k, 404);

        return ['data' => $this->h->fields($c, (array) $k)];
    }
}
