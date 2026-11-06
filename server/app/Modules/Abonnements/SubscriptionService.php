<?php

namespace App\Modules\Abonnements;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\Company;
use App\Modules\Abonnements\Models\Subscription;
use App\Modules\Core\Models\Partner;
use App\Modules\Ventes\Models\SalesDocument;
use App\Payments\InvoicePayments;
use App\Services\EntityWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Facturation récurrente : à chaque échéance, une facture est créée depuis les lignes
 * du contrat (validée et envoyée au client avec son lien de paiement si demandé),
 * puis l'échéance avance d'une période. Les échéances manquées sont rattrapées.
 */
class SubscriptionService
{
    public const UNITS = ['week' => 'semaine', 'month' => 'mois', 'year' => 'an'];

    public function periodEnd(Subscription $s, Carbon $start): Carbon
    {
        return $this->advance($s, $start)->subDay();
    }

    public function advance(Subscription $s, Carbon $d): Carbon
    {
        $n = max(1, $s->interval_count);

        return match ($s->interval_unit) {
            'week' => $d->copy()->addWeeks($n),
            'year' => $d->copy()->addYearsNoOverflow($n),
            default => $d->copy()->addMonthsNoOverflow($n),
        };
    }

    public function label(Subscription $s): string
    {
        $n = $s->interval_count;

        return match (true) {
            $s->interval_unit === 'month' && $n === 1 => 'Mensuel',
            $s->interval_unit === 'month' && $n === 3 => 'Trimestriel',
            $s->interval_unit === 'month' && $n === 6 => 'Semestriel',
            $s->interval_unit === 'year' && $n === 1 => 'Annuel',
            $s->interval_unit === 'week' && $n === 1 => 'Hebdomadaire',
            default => 'Tous les '.$n.' '.self::UNITS[$s->interval_unit],
        };
    }

    /** Échéances arrivées (tâche quotidienne). */
    public function runDue(Company $company, ?string $today = null): array
    {
        $today ??= now()->toDateString();
        $out = ['invoices' => 0, 'errors' => 0];
        foreach (Subscription::where('status', 'active')->where('next_date', '<=', $today)->get() as $s) {
            $guard = 0;
            while ($s->status === 'active' && $s->next_date->toDateString() <= $today && $guard++ < 24) {
                if (! $this->generate($company, $s, $today)) {
                    // échéance déjà facturée par un lancement simultané (ou contrat suspendu entre-temps) : pas une erreur
                    if ($s->status === 'active' && $s->next_date->toDateString() <= $today) {
                        $out['errors']++;
                    }
                    break;
                }
                $out['invoices']++;
                $s->refresh();
            }
        }

        return $out;
    }

    /** Facture de l'échéance suivante. */
    /** @param string|null $dueBy tâche planifiée : ne facture que si l'échéance relue sous verrou est toujours arrivée à cette date */
    public function generate(Company $company, Subscription $s, ?string $dueBy = null): ?SalesDocument
    {
        // Contrat verrouillé et relu : deux lancements simultanés (bouton + tâche planifiée) ne facturent jamais deux fois la même période
        [$doc, $periode] = DB::transaction(function () use ($company, $s, $dueBy) {
            if ($fresh = Subscription::whereKey($s->id)->lockForUpdate()->first()) {
                $s->setRawAttributes($fresh->getAttributes(), true);
            }
            if ($dueBy !== null && ($s->status !== 'active' || $s->next_date->toDateString() > $dueBy)) {
                return [null, '']; // déjà facturée par l'autre lancement : on ne facture pas la période suivante en avance
            }

            return $this->generateLocked($company, $s);
        });
        if ($doc && $s->auto_send && $doc->status === 'validated') {
            $this->sendInvoice($company, $s, $doc, $periode);
        }

        return $doc;
    }

    /** @return array{0: ?SalesDocument, 1: string} */
    protected function generateLocked(Company $company, Subscription $s): array
    {
        if ($s->status !== 'active') {
            throw new \DomainException('Ce contrat n\'est pas actif.');
        }
        $start = $s->next_date->copy();
        $end = $this->periodEnd($s, $start);
        $date = $s->in_advance ? $start : $end;
        $id = (string) Str::uuid();
        $periode = 'Période du '.$start->format('d/m/Y').' au '.$end->format('d/m/Y');
        $data = [
            'type' => 'invoice', 'partner_id' => $s->partner_id, 'date' => $date->toDateString(),
            'reference' => $s->number, 'notes' => $s->name.' — '.$periode.($s->notes ? "\n".$s->notes : ''),
            'currency' => $s->currency ?: $company->currency, 'analytic' => $s->analytic,
            'lines' => array_map(fn ($l) => $l + ['id' => (string) Str::uuid(), 'description' => $l['description'].' ('.$start->format('d/m/Y').' – '.$end->format('d/m/Y').')'], $s->lines ?? []),
            'status' => $s->auto_validate ? 'validated' : null,
        ];
        $data = array_filter($data, fn ($v) => $v !== null);
        SalesDocument::$systemWrite = true;
        try {
            $res = app(EntityWriter::class)->upsert(SalesDocument::class, $id, $data);
        } finally {
            SalesDocument::$systemWrite = false;
        }
        if ($res['status'] !== EntityWriter::APPLIED) {
            $s->forceFill(['last_error' => mb_substr($res['message'] ?? 'Facture refusée', 0, 255)])->saveQuietly();

            return [null, $periode];
        }
        $doc = SalesDocument::find($id);
        DB::table('ab_invoices')->insert(['company_id' => $company->id, 'subscription_id' => $s->id, 'document_id' => $id,
            'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $next = $this->advance($s, $start);
        $count = $s->generated_count + 1;
        $ended = ($s->max_invoices && $count >= $s->max_invoices) || ($s->end_date && $next->gt($s->end_date));
        $s->forceFill(['next_date' => $next->toDateString(), 'generated_count' => $count, 'last_invoice_id' => $id, 'last_error' => null, 'status' => $ended ? 'ended' : 'active'])->save();

        return [$doc, $periode];
    }

    public function sendInvoice(Company $company, Subscription $s, SalesDocument $doc, string $periode): bool
    {
        $p = Partner::find($s->partner_id);
        $messaging = app(MessagingService::class);
        if (! $p?->email || ! ($messaging->available($company)['email'] ?? null)) {
            return false;
        }
        $pay = app(InvoicePayments::class);
        $link = InvoicePayments::url($pay->link($doc));
        $amount = number_format((float) $doc->total, \App\Modules\Core\Models\TradeDocumentLine::decimalsFor($doc->currency), ',', ' ').' '.(in_array($doc->currency, ['XOF', 'XAF'], true) ? 'F CFA' : $doc->currency);
        $text = "Bonjour {$p->name},\n\nVoici votre facture {$doc->number} pour « {$s->name} » ({$periode}) : {$amount}, à régler avant le ".$doc->due_date?->format('d/m/Y').".\n\nConsulter et payer en ligne : {$link}\n\nMerci de votre confiance,\n{$company->name}";
        $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;line-height:1.5">'.nl2br(e($text)).'<p><a href="'.e($link).'" style="display:inline-block;background:#1f6feb;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">Voir et payer la facture</a></p></div>';
        try {
            return $messaging->send($company, 'email', new Message(to: $p->email, subject: 'Facture '.$doc->number.' — '.$s->name, html: $html, text: $text, toName: $p->name), 'abonnement')->ok;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Revenu récurrent mensuel (HT, devise de la société). */
    public function stats(Company $company): array
    {
        $mrr = 0.0;
        $active = Subscription::where('status', 'active')->get();
        $fx = class_exists(\App\Modules\Devises\FxService::class) ? app(\App\Modules\Devises\FxService::class) : null;
        foreach ($active as $s) {
            $factor = match ($s->interval_unit) {
                'week' => 52 / 12 / $s->interval_count,
                'year' => 1 / 12 / $s->interval_count,
                default => 1 / $s->interval_count,
            };
            $rate = (! $s->currency || $s->currency === $company->currency) ? 1 : ($fx?->rate($company, $s->currency) ?? 0);
            $mrr += $s->periodAmount() * $factor * $rate;
        }
        $due7 = Subscription::where('status', 'active')->where('next_date', '<=', now()->addDays(7)->toDateString())->count();

        return [
            'active' => $active->count(),
            'paused' => Subscription::where('status', 'paused')->count(),
            'mrr' => round($mrr, 0),
            'arr' => round($mrr * 12, 0),
            'due_7_days' => $due7,
            'errors' => Subscription::whereNotNull('last_error')->where('status', 'active')->count(),
        ];
    }
}
