<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Payments\PaymentLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Liens de paiement libres d'une société. */
class PaymentLinkController extends Controller
{
    public function __construct(protected PaymentLinks $links) {}

    public function index()
    {
        $c = app('tenant');

        return ['data' => DB::table('pay_links')->where('company_id', $c->id)->orderByDesc('id')->limit(300)->get()->map(fn ($l) => $this->links->toApi($l))->values(),
            'online_methods' => \App\Payments\InvoicePayments::gateways($c)->filter->isOnline()->count()];
    }

    public function store(Request $r)
    {
        $d = $r->validate(['label' => 'required|string|max:160', 'description' => 'nullable|string|max:2000', 'amount' => 'nullable|numeric|min:1|max:1000000000',
            'min_amount' => 'nullable|numeric|min:1', 'tax_rate' => 'nullable|numeric|min:0|max:50', 'partner_id' => 'nullable|uuid', 'customer_name' => 'nullable|string|max:160',
            'customer_phone' => 'nullable|string|max:40', 'customer_email' => 'nullable|email|max:160', 'reusable' => 'boolean', 'expires_on' => 'nullable|date|after_or_equal:today']);
        if (! empty($d['partner_id']) && ($p = DB::table('partners')->where('company_id', app('tenant')->id)->where('id', $d['partner_id'])->first(['name', 'phone', 'email']))) {
            $d += ['customer_name' => $p->name, 'customer_phone' => $p->phone, 'customer_email' => $p->email];
        }

        return response()->json(['data' => $this->links->toApi($this->links->create(app('tenant'), $d, $r->user()->id))], 201);
    }

    public function cancel(int $id)
    {
        $n = DB::table('pay_links')->where('company_id', app('tenant')->id)->where('id', $id)->where('status', 'open')->update(['status' => 'cancelled', 'updated_at' => now()]);
        abort_unless($n, 404);

        return ['ok' => true];
    }
}
