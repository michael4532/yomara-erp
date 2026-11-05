<?php

namespace App\Modules\MarchesPublics;

use App\Http\Controllers\Controller;
use App\Support\Records\RecordController;
use App\Support\Records\Records;
use Illuminate\Support\Facades\DB;

class MarchesController extends Controller
{
    public function __construct(private MarchesHooks $h) {}

    public function dashboard()
    {
        return ['data' => $this->h->dashboard(app('tenant'))];
    }

    protected function out(string $res, array $row): array
    {
        return ['data' => app(RecordController::class)->present(Records::resource(__DIR__.'/records.php', $res), [$row])[0]];
    }

    protected function guard(callable $fn)
    {
        try {
            return DB::transaction($fn);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Ajoute la liste des pièces habituelles au dossier. */
    public function checklist(int $id)
    {
        return $this->guard(function () use ($id) {
            $c = app('tenant');
            $t = DB::table('mp_tenders')->where('company_id', $c->id)->where('id', $id)->first() ?: throw new \DomainException('Appel d\'offres introuvable.');

            return ['data' => ['created' => $this->h->createChecklist($c, (array) $t)]];
        });
    }

    public function award(int $id)
    {
        return $this->guard(fn () => $this->out('contracts', $this->h->award(app('tenant'), $id)));
    }

    public function invoice(int $id)
    {
        return $this->guard(fn () => $this->out('statements', $this->h->invoice(app('tenant'), $id)));
    }
}
