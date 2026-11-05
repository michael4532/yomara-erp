<?php

namespace App\Modules\Agenda;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Abonnement iCalendar personnel : l'agenda de l'utilisateur dans Google Agenda, Outlook ou le téléphone. */
class FeedController extends Controller
{
    public function show(string $token)
    {
        $row = strlen($token) === 40 ? DB::table('ag_feeds')->where('token_hash', hash('sha256', $token))->first() : null;
        abort_unless($row, 404);
        $user = User::withoutGlobalScopes()->find($row->user_id);
        $company = Company::find($row->company_id);
        abort_unless($user && $user->is_active && $company && $company->status !== 'suspended' && $company->hasModule('agenda'), 404);
        app()->instance('tenant', $company);
        auth()->setUser($user);
        DB::table('ag_feeds')->where('id', $row->id)->update(['last_access_at' => now()]);
        $svc = app(AgendaService::class);

        return response($svc->ics($company, $svc->eventsFor($user), $company->name.' — '.$user->name), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8', 'Cache-Control' => 'private, max-age=900', 'X-Robots-Tag' => 'noindex',
        ]);
    }
}
