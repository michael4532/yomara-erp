<?php

namespace App\Modules\NotesFrais;

use App\Modules\Paie\Models\Payslip;
use Illuminate\Support\ServiceProvider;

class NotesFraisModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! class_exists(Payslip::class)) {
            return;
        }
        // Ligne de remboursement retirée du bulletin, ou bulletin supprimé : les dépenses sont à rembourser de nouveau
        Payslip::saved(function (Payslip $s) {
            if (! collect((array) $s->items)->contains(fn ($i) => ($i['source'] ?? null) === 'notes_frais')) {
                app(NotesFraisService::class)->releasePayslipItems($s->id);
            }
        });
        Payslip::deleted(fn (Payslip $s) => app(NotesFraisService::class)->releasePayslipItems($s->id));
    }
}
