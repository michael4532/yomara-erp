<?php

namespace App\Modules\Fidelite;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Partner;
use App\Modules\Fidelite\Models\GiftCard;
use App\Modules\Fidelite\Models\LoyaltyCard;
use App\Modules\Fidelite\Models\LoyaltyTransaction;
use Carbon\Carbon;
use Illuminate\Http\Request;

class FideliteController extends Controller
{
    public function __construct(protected LoyaltyService $service) {}

    public function settings()
    {
        return ['data' => $this->service->settings(app('tenant'))];
    }

    /** Recherche à la caisse : n° de carte, téléphone du client ou code de carte cadeau. */
    public function lookup(Request $r)
    {
        $q = trim((string) $r->validate(['q' => 'required|string|max:60'])['q']);
        $gift = GiftCard::where('code', strtoupper($q))->first();
        $card = LoyaltyCard::where('number', strtoupper($q))->first();
        if (! $card) {
            $digits = preg_replace('/\D/', '', $q);
            if (strlen($digits) >= 8) {
                $partner = Partner::where('phone', 'like', '%'.implode('%', str_split(substr($digits, -6))))->get()->first(fn ($p) => substr(preg_replace('/\D/', '', (string) $p->phone), -8) === substr($digits, -8));
                $card = $partner ? LoyaltyCard::where('partner_id', $partner->id)->first() : null;
            }
        }

        return [
            'card' => $card, 'partner' => $card ? Partner::find($card->partner_id) : null,
            'gift_card' => $gift, 'point_value' => $this->service->settings(app('tenant'))['point_value'],
        ];
    }

    public function stats(Request $r)
    {
        $d = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);
        $from = Carbon::parse($d['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($d['to'] ?? now())->endOfDay();
        $cfg = $this->service->settings(app('tenant'));
        $tx = LoyaltyTransaction::whereBetween('created_at', [$from, $to])->get();
        $earners = $tx->where('type', 'earn')->pluck('card_id')->unique();

        return [
            'members' => LoyaltyCard::where('status', 'active')->count(),
            'new_members' => LoyaltyCard::whereBetween('created_at', [$from, $to])->count(),
            'active_members' => $earners->count(),
            'earned' => (int) $tx->whereIn('type', ['earn', 'bonus'])->sum('points'),
            'redeemed' => (int) -$tx->where('type', 'redeem')->sum('points'),
            'expired' => (int) -$tx->where('type', 'expire')->sum('points'),
            'loyal_sales' => round($tx->where('type', 'earn')->sum('amount'), 2),
            'points_outstanding' => (int) LoyaltyCard::where('status', 'active')->sum('points'),
            'points_value' => round(LoyaltyCard::where('status', 'active')->sum('points') * $cfg['point_value'], 2),
            'gift_outstanding' => round((float) GiftCard::where('status', 'active')->sum('balance'), 2),
            'gift_sold' => round((float) GiftCard::whereBetween('created_at', [$from, $to])->where('kind', 'gift')->sum('initial_amount'), 2),
            'tiers' => LoyaltyCard::where('status', 'active')->get()->groupBy(fn ($c) => $c->tier ?: 'Membre')->map->count(),
            'top' => LoyaltyCard::where('status', 'active')->orderByDesc('earned_12m')->limit(10)->get()->map(fn ($c) => [
                'card' => $c->number, 'name' => Partner::find($c->partner_id)?->name, 'points' => $c->points, 'earned_12m' => $c->earned_12m, 'tier' => $c->tier,
            ]),
        ];
    }
}
