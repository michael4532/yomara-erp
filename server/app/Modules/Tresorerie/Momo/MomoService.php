<?php

namespace App\Modules\Tresorerie\Momo;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Core\Models\Partner;
use App\Modules\Tresorerie\Models\Operation;
use App\Modules\Tresorerie\Models\TreasuryAccount;
use App\Modules\Ventes\Models\SalesDocument;
use App\Modules\Ventes\Models\SalesPayment;
use App\Services\EntityWriter;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Encaissements Mobile Money reçus par SMS (application de transfert de SMS sur le téléphone marchand → webhook).
 * Rapprochement automatique avec les factures : n° de facture cité, ou numéro du client + montant d'une échéance / du solde.
 */
class MomoService
{
    public function token(TreasuryAccount $a, bool $renew = false): string
    {
        if (! $a->momo_token || $renew) {
            $a->forceFill(['momo_token' => Str::random(40)])->saveQuietly();
        }

        return $a->momo_token;
    }

    public function url(TreasuryAccount $a): ?string
    {
        return $a->momo_token ? url('/api/hooks/momo/'.$a->momo_token) : null;
    }

    /** Texte et expéditeur depuis les formats courants des applications de transfert de SMS. */
    public static function fromPayload(array $p): array
    {
        $text = $p['message'] ?? $p['text'] ?? $p['body'] ?? $p['content'] ?? $p['msg'] ?? $p['sms'] ?? ($p['data']['text'] ?? null);
        $from = $p['from'] ?? $p['sender'] ?? $p['number'] ?? $p['address'] ?? $p['phone'] ?? ($p['data']['from'] ?? null);
        $at = $p['sent_at'] ?? $p['sentStamp'] ?? $p['timestamp'] ?? $p['date'] ?? $p['receivedStamp'] ?? null;
        $when = null;
        if (is_numeric($at)) {
            $when = Carbon::createFromTimestamp($at > 10000000000 ? intdiv((int) $at, 1000) : (int) $at);
        } elseif (is_string($at)) {
            $when = rescue(fn () => Carbon::parse($at), null, false);
        }

        return [is_string($text) ? $text : null, is_string($from) ? mb_substr($from, 0, 60) : null, $when];
    }

    public function receive(Company $c, TreasuryAccount $a, string $text, ?string $sender = null, ?Carbon $at = null): MomoMessage
    {
        $text = mb_substr(trim($text), 0, 2000);
        $hash = hash('sha256', $a->id.'|'.$text);
        $x = MomoParser::parse($text, $sender);
        $m = new MomoMessage([
            'account_id' => $a->id, 'provider' => $x['provider'], 'sender' => $sender, 'text' => $text, 'hash' => $hash, 'direction' => $x['direction'],
            'amount' => $x['amount'], 'phone' => $x['phone'], 'payer_name' => $x['name'] ? mb_substr($x['name'], 0, 120) : null,
            'reference' => $x['reference'] ? mb_substr($x['reference'], 0, 60) : null, 'memo' => $x['memo'] ? mb_substr($x['memo'], 0, 150) : null,
            'received_at' => ($at && $at->lte(now()->addHour())) ? $at : now(), 'status' => 'pending',
        ]);
        // Doublon : même SMS, ou même référence de transaction sur le compte
        $dup = MomoMessage::where('account_id', $a->id)->where(fn ($q) => $q->where('hash', $hash)->when($m->reference, fn ($q) => $q->orWhere('reference', $m->reference)))->exists();
        if ($dup) {
            $m->status = 'duplicate';
            $m->save();

            return $m;
        }
        if ($m->direction === 'out') {
            $m->status = 'outgoing';
            $m->save();

            return $m;
        }
        $m->save();
        if ($m->direction === 'in' && $m->amount > 0) {
            $this->autoMatch($m);
        }
        if ($m->status === 'pending') {
            $to = User::where('company_id', $c->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('tresorerie.manage'))->pluck('id')->all();
            AppNotification::send($c->id, $to, 'tresorerie', 'Encaissement Mobile Money à rapprocher',
                ($m->amount ? number_format($m->amount, 0, ',', ' ').' '.$c->currency : 'Montant non lu').($m->payer_name || $m->phone ? ' de '.trim(($m->payer_name ?? '').' '.($m->phone ?? '')) : '').' · '.$a->name, 'tresorerie', $m->id);
        }

        return $m;
    }

    /** Factures ouvertes (validées, non soldées). */
    protected function openInvoices()
    {
        return SalesDocument::where('status', 'validated')->whereIn('type', ['invoice'])->where('payment_status', '!=', 'paid')->get();
    }

    /** Candidats triés : [doc, raison, score] */
    public function candidates(MomoMessage $m): array
    {
        $amount = (float) $m->amount;
        $text = mb_strtoupper($m->text);
        $out = [];
        $partners = $m->phone ? Partner::whereNotNull('phone')->get()->filter(fn ($p) => MomoParser::samePhone($p->phone, $m->phone))->pluck('id')->all() : [];
        $name = $m->payer_name ? mb_strtolower($m->payer_name) : null;
        foreach ($this->openInvoices() as $d) {
            $bal = $d->balance();
            $now = $d->amountDueNow();
            $fits = $amount > 0 && ($amount <= $bal + 1);
            $exact = $amount > 0 && (abs($amount - $bal) <= 1 || abs($amount - $now) <= 1);
            $score = 0;
            $why = [];
            if ($d->number && str_contains($text, mb_strtoupper($d->number))) {
                $score += 100;
                $why[] = 'n° de facture cité';
            }
            if (in_array($d->partner_id, $partners, true)) {
                $score += 50;
                $why[] = 'numéro du client';
            } elseif ($name && $d->partner_name && (str_contains(mb_strtolower($d->partner_name), $name) || str_contains($name, mb_strtolower($d->partner_name)))) {
                $score += 25;
                $why[] = 'nom du client';
            }
            if ($exact) {
                $score += 40;
                $why[] = abs($amount - $bal) <= 1 ? 'montant = reste dû' : 'montant = échéance';
            } elseif (! $fits) {
                $score -= 60;
            }
            if ($score >= 40) {
                $out[] = ['document' => $d, 'reason' => implode(', ', $why), 'score' => $score, 'exact' => $exact];
            }
        }
        usort($out, fn ($a, $b) => [$b['score'], $a['document']->due_date?->toDateString() ?? ''] <=> [$a['score'], $b['document']->due_date?->toDateString() ?? '']);

        return $out;
    }

    /**
     * Automatique seulement si le rapprochement est sûr : n° de facture cité et montant possible,
     * ou numéro du client + montant exact (reste dû ou échéance), sans autre facture tout aussi probable.
     */
    public function autoMatch(MomoMessage $m): ?SalesPayment
    {
        $c = $this->candidates($m);
        if (! $c) {
            return null;
        }
        $best = $c[0];
        $sure = $best['score'] >= 140 || ($best['score'] >= 90 && $best['exact']);
        $tie = isset($c[1]) && $c[1]['score'] === $best['score'] && $c[1]['exact'] === $best['exact'];
        if (! $sure || ($tie && $best['score'] < 140)) {
            $m->forceFill(['partner_id' => $best['document']->partner_id, 'match_reason' => 'Suggestion : '.$best['document']->number.' ('.$best['reason'].')'])->save();

            return null;
        }

        return $this->assign($m, $best['document'], 'Automatique : '.$best['reason']);
    }

    /** Encaissement sur la facture (au plus le reste dû) ; l'éventuel surplus reste à traiter. */
    public function assign(MomoMessage $m, SalesDocument $d, string $reason, ?string $by = null): SalesPayment
    {
        if ($m->payment_id || $m->status === 'matched') {
            throw new \DomainException('Cet encaissement est déjà rapproché.');
        }
        if (! $m->amount || $m->amount <= 0) {
            throw new \DomainException('Montant non lu : enregistrez l\'encaissement à la main.');
        }
        $amount = round(min((float) $m->amount, $d->balance()), 2);
        if ($amount <= 0) {
            throw new \DomainException('Cette facture est déjà soldée.');
        }
        $label = MomoParser::PROVIDERS[$m->provider] ?? 'Mobile Money';
        $r = app(EntityWriter::class)->upsert(SalesPayment::class, (string) Str::uuid(), [
            'document_id' => $d->id, 'date' => $m->received_at->toDateString(), 'amount' => $amount, 'method' => 'mobile_money',
            'reference' => Str::limit(trim($label.' '.($m->reference ?? '').' '.($m->phone ?? '')), 95, ''), 'treasury_account_id' => $m->account_id,
            'notes' => 'SMS : '.Str::limit($m->text, 180),
        ]);
        if ($r['status'] !== EntityWriter::APPLIED) {
            throw new \DomainException($r['message'] ?? 'Encaissement refusé.');
        }
        $extra = round((float) $m->amount - $amount, 2);
        $m->forceFill(['status' => 'matched', 'document_id' => $d->id, 'partner_id' => $d->partner_id, 'payment_id' => $r['record']->id,
            'match_reason' => Str::limit($reason.($extra > 0 ? ' · surplus '.number_format($extra, 0, ',', ' ').' non affecté' : ''), 160, ''), 'handled_by' => $by])->save();
        // Le client n'avait pas de téléphone : on retient celui du paiement pour la prochaine fois
        $p = Partner::find($d->partner_id);
        if ($p && ! $p->phone && $m->phone) {
            $p->forceFill(['phone' => $m->phone])->save();
        }

        return $r['record'];
    }

    /** Encaissement hors facture (apport, remboursement…) : opération de trésorerie. */
    public function toOperation(MomoMessage $m, string $category, ?string $label, ?string $by = null): Operation
    {
        if ($m->status === 'matched') {
            throw new \DomainException('Cet encaissement est déjà rapproché.');
        }
        $r = app(EntityWriter::class)->upsert(Operation::class, (string) Str::uuid(), [
            'account_id' => $m->account_id, 'date' => $m->received_at->toDateString(), 'amount' => $m->direction === 'out' ? -abs((float) $m->amount) : abs((float) $m->amount),
            'label' => $label ?: Str::limit('Mobile Money '.($m->payer_name ?? $m->phone ?? ''), 250, ''), 'category' => $category, 'reference' => $m->reference,
        ]);
        if ($r['status'] !== EntityWriter::APPLIED) {
            throw new \DomainException($r['message'] ?? 'Opération refusée.');
        }
        $m->forceFill(['status' => 'matched', 'operation_id' => $r['record']->id, 'match_reason' => 'Opération de trésorerie', 'handled_by' => $by])->save();

        return $r['record'];
    }

    public function toArray(MomoMessage $m, bool $withCandidates = false): array
    {
        $doc = $m->document_id ? SalesDocument::find($m->document_id) : null;

        return $m->toArray() + [
            'provider_label' => MomoParser::PROVIDERS[$m->provider] ?? null,
            'document_number' => $doc?->number,
            'partner_name' => $m->partner_id ? Partner::withTrashed()->find($m->partner_id)?->name : null,
            'candidates' => $withCandidates && $m->status === 'pending' ? array_map(fn ($x) => ['id' => $x['document']->id, 'number' => $x['document']->number, 'partner_name' => $x['document']->partner_name,
                'balance' => $x['document']->balance(), 'due_now' => $x['document']->amountDueNow(), 'reason' => $x['reason'], 'exact' => $x['exact']], array_slice($this->candidates($m), 0, 5)) : [],
        ];
    }
}
