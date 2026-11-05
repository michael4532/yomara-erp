<?php

namespace App\Modules\Qualite;

use App\Http\Controllers\Controller;
use App\Support\Records\RecordController;
use App\Support\Records\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QualiteController extends Controller
{
    public function __construct(private QualiteHooks $h) {}

    public function dashboard()
    {
        return ['data' => $this->h->dashboard(app('tenant'))];
    }

    public function criteria(int $id)
    {
        $p = DB::table('qa_plans')->where('company_id', app('tenant')->id)->where('id', $id)->first();
        abort_unless($p, 404);

        return ['data' => ['id' => $p->id, 'title' => $p->title, 'threshold' => (int) $p->threshold, 'criteria' => QualiteHooks::criteria($p->criteria)]];
    }

    public function answers(Request $r, int $id)
    {
        $d = $r->validate(['answers' => 'required|array|max:200', 'answers.*.value' => 'required|in:ok,nok,na', 'answers.*.comment' => 'nullable|string|max:300', 'notes' => 'nullable|string|max:5000']);
        try {
            $row = DB::transaction(fn () => $this->h->answer(app('tenant'), $r->user(), $id, array_values($d['answers']), $d['notes'] ?? null));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ['data' => app(RecordController::class)->present(Records::resource(__DIR__.'/records.php', 'inspections'), [$row])[0]];
    }
}
