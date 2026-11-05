<?php

namespace App\Modules\Abonnements;

use App\Http\Controllers\Controller;
use App\Modules\Abonnements\Models\Subscription;
use Illuminate\Support\Facades\DB;

class AbonnementsController extends Controller
{
    public function __construct(private SubscriptionService $svc) {}

    public function stats()
    {
        return ['data' => $this->svc->stats(app('tenant'))];
    }

    public function invoices(string $id)
    {
        $s = Subscription::withTrashed()->findOrFail($id);
        $rows = DB::table('ab_invoices as a')->join('trade_documents as d', 'd.id', '=', 'a.document_id')
            ->where('a.subscription_id', $s->id)->orderByDesc('a.period_start')
            ->get(['d.id', 'd.number', 'd.status', 'd.date', 'd.total', 'd.currency', 'd.payment_status', 'a.period_start', 'a.period_end']);

        return ['data' => $rows];
    }

    public function generate(string $id)
    {
        try {
            $doc = $this->svc->generate(app('tenant'), Subscription::findOrFail($id));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        if (! $doc) {
            return response()->json(['message' => Subscription::find($id)->last_error ?? 'Facture refusée.'], 422);
        }

        return ['data' => $doc->fresh('lines')->toSyncArray()];
    }

    public function run()
    {
        return ['data' => $this->svc->runDue(app('tenant'))];
    }
}
