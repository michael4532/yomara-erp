<?php

namespace App\Modules\RendezVous;

use App\Modules\RendezVous\Models\Appointment;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Support\ServiceProvider;

class RendezVousModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        Appointment::saved(function (Appointment $a) {
            app(BookingService::class)->afterSave($a);
            app(AgendaBridge::class)->sync($a); // le rendez-vous apparaît dans l'Agenda (et l'Outlook / Google Agenda) du praticien
        });
        Appointment::deleted(fn (Appointment $a) => app(AgendaBridge::class)->sync($a));
        $release = function (SalesDocument $doc) {
            foreach (Appointment::withoutGlobalScopes()->where('company_id', $doc->company_id)->where('invoice_id', $doc->id)->get() as $a) {
                $a->forceFill(['invoice_id' => null])->saveQuietly();
            }
        };
        SalesDocument::deleted($release);
        SalesDocument::updated(function (SalesDocument $doc) use ($release) {
            if ($doc->wasChanged('status') && $doc->status === 'cancelled') {
                $release($doc);
            }
        });
    }
}
