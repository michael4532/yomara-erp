<?php

namespace App\Modules\NotesFrais;

use App\Http\Controllers\Controller;
use App\Modules\NotesFrais\Models\Expense;
use App\Modules\Rh\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class NotesFraisController extends Controller
{
    public function __construct(protected NotesFraisService $svc) {}

    protected function visible(Request $r, string $id): Expense
    {
        $e = Expense::findOrFail($id);
        $user = $r->user();
        if (! $user->hasPermission('notes_frais.approve|notes_frais.manage') && Employee::where('user_id', $user->id)->value('id') !== $e->employee_id) {
            abort(404);
        }

        return $e;
    }

    /** Photo ou PDF du justificatif (jusqu'à 8 Mo), tant que la dépense n'est pas approuvée. */
    public function uploadReceipt(Request $r, string $id)
    {
        $e = $this->visible($r, $id);
        $r->validate(['file' => 'required|file|max:8192']);
        $ext = strtolower($r->file('file')->getClientOriginalExtension());
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf'], true)) {
            return response()->json(['message' => 'Justificatif attendu en photo (JPG, PNG) ou PDF.'], 422);
        }
        if (in_array($e->status, ['approved', 'paid'], true)) {
            return response()->json(['message' => 'Dépense déjà approuvée : le justificatif ne se change plus.'], 422);
        }
        $old = $e->receipt_path;
        $path = $r->file('file')->storeAs('receipts/'.$e->company_id, $e->id.'-'.Str::random(6).'.'.$ext, 'local');
        $e->forceFill(['receipt_path' => $path, 'receipt_name' => mb_substr($r->file('file')->getClientOriginalName(), 0, 120)])->save();
        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }

        return ['data' => $e->fresh()];
    }

    public function receipt(Request $r, string $id)
    {
        $e = $this->visible($r, $id);
        abort_unless($e->receipt_path && Storage::disk('local')->exists($e->receipt_path), 404, 'Pas de justificatif.');

        // Type déduit de l'extension enregistrée, pas du contenu déposé : un faux justificatif HTML ne s'exécute pas dans le navigateur
        $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][strtolower(pathinfo($e->receipt_path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';

        return response()->file(Storage::disk('local')->path($e->receipt_path), \App\Support\SafeDownload::headers($mime, $e->receipt_name ?: basename($e->receipt_path), true));
    }

    public function pay(Request $r)
    {
        $v = $r->validate([
            'employee_id' => 'required|uuid', 'expense_ids' => 'required|array|min:1', 'expense_ids.*' => 'uuid',
            'via' => 'required|in:treasury,cash,payroll', 'account_id' => 'nullable|uuid', 'method' => 'nullable|in:cash,bank_transfer,mobile_money',
            'date' => 'nullable|date', 'run_id' => 'nullable|integer',
        ]);
        try {
            return ['data' => $this->svc->pay(app('tenant'), $v['employee_id'], $v['expense_ids'], $v)];
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function settings()
    {
        return ['data' => $this->svc->settings(app('tenant'))];
    }

    public function updateSettings(Request $r)
    {
        $v = $r->validate(['receipt_from' => 'sometimes|numeric|min:0', 'payable_account' => ['sometimes', 'regex:/^4\d{2,7}$/']]);
        $c = app('tenant');
        $s = $c->settings ?? [];
        $s['notes_frais'] = array_merge($this->svc->settings($c), $v);
        $c->forceFill(['settings' => $s])->save();

        return $this->settings();
    }
}
