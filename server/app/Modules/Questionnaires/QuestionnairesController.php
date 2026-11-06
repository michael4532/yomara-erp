<?php

namespace App\Modules\Questionnaires;

use App\Http\Controllers\Controller;
use App\Support\Records\RecordController;
use App\Support\Records\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QuestionnairesController extends Controller
{
    public function __construct(private QuestionnaireService $s) {}

    protected function form(int $id): object
    {
        return DB::table('qs_forms')->where('company_id', app('tenant')->id)->where('id', $id)->first() ?: abort(404);
    }

    public function questions(int $id)
    {
        $f = $this->form($id);

        return ['data' => ['questions' => QuestionnaireService::questions($f), 'types' => QuestionnaireService::TYPES, 'url' => QuestionnaireService::url($f), 'locked' => $f->responses_count > 0]];
    }

    public function saveQuestions(Request $r, int $id)
    {
        $f = $this->form($id);
        $d = $r->validate(['questions' => 'present|array|max:100']);
        try {
            $qs = QuestionnaireService::normalize($d['questions']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        if (! $qs && $f->status === 'open') {
            return response()->json(['message' => 'Un questionnaire ouvert doit garder au moins une question.'], 422);
        }
        DB::table('qs_forms')->where('id', $f->id)->update(['questions' => json_encode($qs, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);

        return ['data' => ['questions' => $qs]];
    }

    public function results(Request $r, int $id)
    {
        $d = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        return ['data' => $this->s->results($this->form($id), $d['from'] ?? null, $d['to'] ?? null)];
    }

    /** Saisie d'une réponse dans l'application (enquête sur papier, au comptoir, par téléphone). */
    public function answer(Request $r, int $id)
    {
        abort_unless($r->user()->hasPermission('questionnaires.answer') || $r->user()->hasPermission('questionnaires.manage'), 403);
        $f = $this->form($id);
        $d = $r->validate(['answers' => 'required|array', 'name' => 'nullable|string|max:150', 'email' => 'nullable|email|max:190', 'phone' => 'nullable|string|max:40', 'partner_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('partners')]]);
        if ($f->status === 'closed') {
            return response()->json(['message' => 'Ce questionnaire est fermé.'], 422);
        }
        try {
            $row = DB::transaction(fn () => $this->s->store($f, $d['answers'], $d, 'app', $r->user()));
        } catch (QuestionnaireErrors $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors], 422);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => app(RecordController::class)->present(Records::resource(__DIR__.'/records.php', 'responses'), [$row])[0]], 201);
    }

    public function duplicate(int $id)
    {
        $f = (array) $this->form($id);
        unset($f['id']);
        $nid = DB::table('qs_forms')->insertGetId(array_merge($f, ['title' => Str::limit($f['title'].' (copie)', 200, ''), 'status' => 'draft', 'token' => Str::random(24), 'responses_count' => 0,
            'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]));

        return response()->json(['data' => app(RecordController::class)->present(Records::resource(__DIR__.'/records.php', 'forms'), [(array) DB::table('qs_forms')->find($nid)])[0]], 201);
    }

    /** Export Excel : une ligne par réponse, une colonne par question. */
    public function export(int $id)
    {
        $f = $this->form($id);
        $qs = QuestionnaireService::questions($f);
        $x = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sh = $x->getActiveSheet();
        $sh->setTitle('Réponses');
        $head = array_merge(['Date', 'Nom', 'Téléphone', 'E-mail', 'Satisfaction', 'Reçue par'], array_map(fn ($q) => $q['label'], $qs));
        $sh->fromArray($head, null, 'A1');
        $sh->getStyle('A1:'.\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($head)).'1')->getFont()->setBold(true);
        $row = 2;
        foreach (DB::table('qs_responses')->where('form_id', $f->id)->orderBy('submitted_at')->cursor() as $r) {
            $a = json_decode($r->answers, true) ?: [];
            $sh->fromArray(\App\Support\SafeCell::csvRow(array_merge([date('d/m/Y H:i', strtotime($r->submitted_at)), $r->name, $r->phone, $r->email, QuestionnaireService::MOODS[$r->mood][0] ?? '', QuestionnaireService::SOURCES[$r->source] ?? $r->source],
                array_map(fn ($q) => is_array($a[$q['id']] ?? null) ? implode(', ', $a[$q['id']]) : ($a[$q['id']] ?? ''), $qs))), null, 'A'.$row++, true);
        }
        foreach (range(1, count($head)) as $i) {
            $sh->getColumnDimensionByColumn($i)->setWidth($i <= 6 ? 16 : 30);
        }
        $path = tempnam(sys_get_temp_dir(), 'qs');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($x))->save($path);

        return response()->download($path, Str::slug($f->title).'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }
}
