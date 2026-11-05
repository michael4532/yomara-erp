<?php

namespace App\Modules\Fidelite;

use App\Models\Company;
use App\Modules\Core\Models\Partner;
use App\Modules\Core\Models\TradeDocument;
use App\Modules\Fidelite\Models\GiftCard;
use App\Modules\Fidelite\Models\GiftCardMove;
use App\Modules\Fidelite\Models\LoyaltyCard;
use App\Modules\Fidelite\Models\LoyaltyTransaction;
use App\Modules\Ventes\Models\SalesPayment;

class LoyaltyService
{
    public function settings(Company $company): array
    {
        $s = $company->settings ?? [];

        return [
            'enabled' => (bool) ($s['fid_enabled'] ?? true),
            'per_amount' => max(1, (float) ($s['fid_per_amount'] ?? 1000)),     // 1 point par tranche de …
            'point_value' => max(0.01, (float) ($s['fid_point_value'] ?? 10)),  // valeur d'un point en paiement
            'min_redeem' => max(0, (int) ($s['fid_min_redeem'] ?? 100)),
            'expiry_months' => max(0, (int) ($s['fid_expiry_months'] ?? 12)),
            'welcome' => max(0, (int) ($s['fid_welcome_points'] ?? 0)),
            'auto_enroll' => (bool) ($s['fid_auto_enroll'] ?? true),
            'tiers' => array_values($s['fid_tiers'] ?? [['name' => 'Argent', 'min' => 1000, 'multiplier' => 1.25], ['name' => 'Or', 'min' => 3000, 'multiplier' => 1.5]]),
        ];
    }

    public function cardFor(Company $company, ?string $partnerId, bool $create = false): ?LoyaltyCard
    {
        if (! $partnerId) {
            return null;
        }
        $card = LoyaltyCard::where('partner_id', $partnerId)->first();
        if (! $card && $create) {
            $card = LoyaltyCard::create(['partner_id' => $partnerId]);
        }

        return $card;
    }

    /** Coefficient du niveau atteint. */
    public function tierOf(Company $company, int $earned): ?array
    {
        $best = null;
        foreach ($this->settings($company)['tiers'] as $t) {
            if ($earned >= (int) ($t['min'] ?? 0) && (! $best || (int) $t['min'] >= (int) $best['min'])) {
                $best = $t;
            }
        }

        return $best;
    }

    public function refresh(LoyaltyCard $card): LoyaltyCard
    {
        $company = Company::find($card->company_id);
        $points = (int) LoyaltyTransaction::where('card_id', $card->id)->sum('points');
        $earned = (int) LoyaltyTransaction::where('card_id', $card->id)->where('created_at', '>=', now()->subYear())
            // gains, moins les gains annulés (ticket annulé, part payée en points)
            ->where(fn ($q) => $q->whereIn('type', ['earn', 'bonus'])
                ->orWhere(fn ($q) => $q->where('type', 'reverse')->where('points', '<', 0))
                ->orWhere(fn ($q) => $q->where('type', 'adjust')->whereNotNull('payment_id')))
            ->sum('points');
        $tier = $company ? $this->tierOf($company, $earned) : null;
        $card->forceFill(['points' => $points, 'earned_12m' => $earned, 'tier' => $tier['name'] ?? null])->save();

        return $card;
    }

    /** Ajoute (gain) ou retire (consommation du plus ancien au plus récent) des points. */
    public function move(LoyaltyCard $card, string $type, int $points, array $extra = []): LoyaltyTransaction
    {
        $company = Company::find($card->company_id);
        $months = $company ? $this->settings($company)['expiry_months'] : 0;
        $t = new LoyaltyTransaction(['card_id' => $card->id, 'type' => $type, 'points' => $points, 'note' => $extra['note'] ?? null]);
        $t->forceFill(['amount' => $extra['amount'] ?? 0, 'document_id' => $extra['document_id'] ?? null, 'payment_id' => $extra['payment_id'] ?? null,
            'remaining' => max(0, $points), 'expires_at' => $points > 0 && $months > 0 ? now()->addMonths($months)->toDateString() : null]);
        $t->save();
        if ($points < 0) {
            $this->consume($card, -$points);
        }
        $this->refresh($card);

        return $t;
    }

    /** Mouvement saisi à la main (bonus, correction) : disponibilité, échéance et solde. */
    public function applyManual(LoyaltyTransaction $t): void
    {
        $card = LoyaltyCard::find($t->card_id);
        if (! $card) {
            return;
        }
        $company = Company::find($card->company_id);
        $months = $company ? $this->settings($company)['expiry_months'] : 0;
        if ($t->points > 0) {
            $t->forceFill(['remaining' => $t->points, 'expires_at' => $months > 0 ? now()->addMonths($months)->toDateString() : null])->saveQuietly();
        } else {
            $this->consume($card, -$t->points);
        }
        $this->refresh($card);
    }

    public function welcome(LoyaltyCard $card): void
    {
        $company = Company::find($card->company_id);
        $w = $company ? $this->settings($company)['welcome'] : 0;
        if ($w > 0 && ! LoyaltyTransaction::where('card_id', $card->id)->exists()) {
            $this->move($card, 'bonus', $w, ['note' => 'Bienvenue dans le programme']);
        }
    }

    protected function consume(LoyaltyCard $card, int $points): void
    {
        foreach (LoyaltyTransaction::where('card_id', $card->id)->where('remaining', '>', 0)->orderByRaw('expires_at is null')->orderBy('expires_at')->orderBy('created_at')->get() as $t) {
            if ($points <= 0) {
                break;
            }
            $take = min($points, $t->remaining);
            $t->forceFill(['remaining' => $t->remaining - $take])->saveQuietly();
            $points -= $take;
        }
    }

    // ---------- Gains ----------

    public function pointsFor(Company $company, float $amount, ?LoyaltyCard $card): int
    {
        $cfg = $this->settings($company);
        $mult = (float) ($card && $card->tier ? (collect($cfg['tiers'])->firstWhere('name', $card->tier)['multiplier'] ?? 1) : 1);

        return (int) floor($amount / $cfg['per_amount'] * $mult);
    }

    public function earn(TradeDocument $doc): void
    {
        $company = Company::find($doc->company_id);
        if (! $company || ! $company->hasModule('fidelite') || $doc->direction !== 'sale' || ! in_array($doc->type, ['receipt', 'invoice'], true) || ! $doc->partner_id || $doc->is_opening) {
            return;
        }
        $cfg = $this->settings($company);
        if (! $cfg['enabled'] || LoyaltyTransaction::where('document_id', $doc->id)->where('type', 'earn')->exists()) {
            return;
        }
        // Une facture issue d'une commande ou d'un ticket déjà récompensé ne rapporte pas deux fois
        if ($doc->source_id && LoyaltyTransaction::where('document_id', $doc->source_id)->where('type', 'earn')->exists()) {
            return;
        }
        $isNew = ! LoyaltyCard::where('partner_id', $doc->partner_id)->exists();
        $card = $this->cardFor($company, $doc->partner_id, $cfg['auto_enroll']);
        if (! $card || $card->status !== 'active') {
            return;
        }
        if ($isNew) {
            $this->welcome($card);
        }
        // Part déjà payée en points : pas de nouveaux points dessus
        $paidWithRewards = (float) SalesPayment::where('document_id', $doc->id)->where('method', 'loyalty')->sum('amount');
        $points = $this->pointsFor($company, max(0, $doc->total - $paidWithRewards), $card->fresh());
        if ($points > 0) {
            $this->move($card, 'earn', $points, ['amount' => $doc->total, 'document_id' => $doc->id, 'note' => ($doc->type === 'receipt' ? 'Ticket ' : 'Facture ').($doc->number ?? $doc->reference)]);
        }
    }

    /** Document annulé : les points gagnés sont retirés. */
    public function reverseDocument(TradeDocument $doc): void
    {
        foreach (LoyaltyTransaction::where('document_id', $doc->id)->where('type', 'earn')->get() as $t) {
            if (LoyaltyTransaction::where('document_id', $doc->id)->where('type', 'reverse')->exists()) {
                break;
            }
            $card = LoyaltyCard::find($t->card_id);
            if ($card) {
                $this->move($card, 'reverse', -min($t->points, max(0, $card->points)), ['document_id' => $doc->id, 'note' => 'Annulation '.($doc->number ?? '')]);
            }
        }
    }

    // ---------- Paiements en points et en carte cadeau ----------

    /** Contrôle avant l'enregistrement d'un paiement (lève une exception métier si impossible). */
    public function checkPayment(SalesPayment $p): void
    {
        if (! in_array($p->method, ['loyalty', 'gift_card'], true)) {
            return;
        }
        $company = Company::find($p->company_id ?? app('tenant')->id);
        if (! $company?->hasModule('fidelite')) {
            throw new \DomainException('Le module Fidélité n\'est pas activé.');
        }
        if ($p->method === 'loyalty') {
            [$card, $points] = $this->loyaltyFor($company, $p);
            $cfg = $this->settings($company);
            if ($card->points < $points) {
                throw new \DomainException('Points insuffisants : '.$card->points.' points disponibles, '.$points.' nécessaires.');
            }
            if ($points < $cfg['min_redeem'] && $card->points < $cfg['min_redeem']) {
                throw new \DomainException('Il faut au moins '.$cfg['min_redeem'].' points pour payer en points.');
            }
            $p->reference = $card->number;
        } else {
            $g = GiftCard::where('code', strtoupper(trim((string) $p->reference)))->first();
            if (! $g) {
                throw new \DomainException('Carte cadeau inconnue : vérifiez le code.');
            }
            if (! $g->usable()) {
                throw new \DomainException('Carte cadeau '.($g->status === 'cancelled' ? 'annulée' : ($g->balance <= 0 ? 'épuisée' : 'expirée')).'.');
            }
            if ($g->balance + 0.009 < $p->amount) {
                throw new \DomainException('Solde de la carte cadeau insuffisant : '.number_format($g->balance, 0, ',', ' ').' restants.');
            }
            $p->reference = $g->code;
        }
    }

    /** @return array{0: LoyaltyCard, 1: int} */
    protected function loyaltyFor(Company $company, SalesPayment $p): array
    {
        $card = $p->reference ? LoyaltyCard::where('number', $p->reference)->first() : null;
        if (! $card) {
            $doc = \App\Modules\Ventes\Models\SalesDocument::find($p->document_id);
            $card = $this->cardFor($company, $doc?->partner_id);
        }
        if (! $card || $card->status !== 'active') {
            throw new \DomainException('Aucune carte de fidélité active pour ce client.');
        }

        return [$card, (int) ceil(round($p->amount / $this->settings($company)['point_value'], 4))];
    }

    public function recordPayment(SalesPayment $p): void
    {
        $company = Company::find($p->company_id);
        if (! $company) {
            return;
        }
        if ($p->method === 'loyalty') {
            [$card, $points] = $this->loyaltyFor($company, $p);
            $this->move($card, 'redeem', -$points, ['amount' => $p->amount, 'document_id' => $p->document_id, 'payment_id' => $p->id, 'note' => 'Paiement en points']);
            // Pas de points gagnés sur la part payée en points
            $earn = LoyaltyTransaction::where('document_id', $p->document_id)->where('type', 'earn')->first();
            if ($earn) {
                $minus = min($this->pointsFor($company, $p->amount, $card), $earn->points);
                if ($minus > 0) {
                    $this->move($card, 'adjust', -$minus, ['document_id' => $p->document_id, 'payment_id' => $p->id, 'note' => 'Pas de points sur la part payée en points']);
                }
            }
        } elseif ($p->method === 'gift_card') {
            $g = GiftCard::where('code', $p->reference)->first();
            if ($g) {
                GiftCardMove::create(['gift_card_id' => $g->id, 'amount' => -$p->amount, 'payment_id' => $p->id, 'document_id' => $p->document_id, 'note' => 'Utilisation']);
                $this->refreshGift($g);
            }
        }
    }

    /** Paiement supprimé : points ou montant rendus. */
    public function refundPayment(SalesPayment $p): void
    {
        foreach (LoyaltyTransaction::where('payment_id', $p->id)->get() as $t) {
            $card = LoyaltyCard::find($t->card_id);
            if ($card && $t->points < 0) {
                // Le retrait « pas de points sur la part payée en points » est rendu comme un gain (compte pour le niveau), l'utilisation comme une annulation.
                $t->type === 'adjust'
                    ? $this->move($card, 'adjust', -$t->points, ['document_id' => $p->document_id, 'payment_id' => $p->id, 'note' => 'Paiement en points annulé'])
                    : $this->move($card, 'reverse', -$t->points, ['document_id' => $p->document_id, 'note' => 'Paiement annulé']);
            }
        }
        foreach (GiftCardMove::where('payment_id', $p->id)->get() as $m) {
            GiftCardMove::create(['gift_card_id' => $m->gift_card_id, 'amount' => -$m->amount, 'document_id' => $p->document_id, 'note' => 'Paiement annulé']);
            $g = GiftCard::find($m->gift_card_id);
            if ($g) {
                $this->refreshGift($g);
            }
        }
    }

    public function refreshGift(GiftCard $g): void
    {
        $balance = round($g->initial_amount + (float) GiftCardMove::where('gift_card_id', $g->id)->sum('amount'), 2);
        $status = $g->status === 'cancelled' ? 'cancelled' : ($balance <= 0.009 ? 'used' : ($g->expires_at && $g->expires_at->lt(today()) ? 'expired' : 'active'));
        $g->forceFill(['balance' => max(0, $balance), 'status' => $status])->save();
    }

    // ---------- Expiration ----------

    public function expire(Company $company): int
    {
        $n = 0;
        foreach (LoyaltyTransaction::where('remaining', '>', 0)->whereNotNull('expires_at')->whereDate('expires_at', '<', today())->get()->groupBy('card_id') as $cardId => $rows) {
            $card = LoyaltyCard::find($cardId);
            if (! $card) {
                continue;
            }
            $pts = min((int) $rows->sum('remaining'), max(0, $card->points));
            foreach ($rows as $t) {
                $t->forceFill(['remaining' => 0])->saveQuietly();
            }
            if ($pts > 0) {
                $t = new LoyaltyTransaction(['card_id' => $card->id, 'type' => 'expire', 'points' => -$pts, 'note' => 'Points expirés']);
                $t->save();
                $this->refresh($card);
                $n += $pts;
            }
        }
        foreach (GiftCard::where('status', 'active')->whereNotNull('expires_at')->whereDate('expires_at', '<', today())->get() as $g) {
            $g->forceFill(['status' => 'expired'])->save();
        }

        return $n;
    }

    public function summary(Company $company, Partner $partner): ?array
    {
        $card = $this->cardFor($company, $partner->id);
        if (! $card) {
            return null;
        }
        $cfg = $this->settings($company);
        $next = collect($cfg['tiers'])->sortBy('min')->first(fn ($t) => (int) $t['min'] > $card->earned_12m);

        return [
            'card' => $card, 'cfg' => $cfg, 'value' => round($card->points * $cfg['point_value'], 2), 'next' => $next,
            'history' => LoyaltyTransaction::where('card_id', $card->id)->latest('created_at')->limit(30)->get(),
            'expiring' => (int) LoyaltyTransaction::where('card_id', $card->id)->where('remaining', '>', 0)->whereNotNull('expires_at')->whereDate('expires_at', '<=', today()->addDays(30))->sum('remaining'),
        ];
    }
}
