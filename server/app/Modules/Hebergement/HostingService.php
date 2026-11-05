<?php

namespace App\Modules\Hebergement;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Core\Models\Partner;
use App\Modules\Core\Models\TradeDocumentLine;
use App\Modules\Hebergement\Models\HostedService;
use App\Modules\Hebergement\Models\Renewal;
use App\Modules\Ventes\Models\SalesDocument;
use App\Payments\InvoicePayments;
use App\Services\EntityWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Échéances des services clients : facture de renouvellement N jours avant l'échéance (regroupée par client),
 * prolongation à l'encaissement, liste « à renouveler chez le fournisseur », rappels au client.
 */
class HostingService
{
    public function settings(Company $c): array
    {
        $days = $c->setting('hosting_reminder_days') ?? [30, 7, 1];

        return ['reminder_days' => array_values(array_unique(array_map('intval', (array) $days))), 'auto_send' => (bool) ($c->setting('hosting_auto_send') ?? true)];
    }

    /** Prochaine période : du lendemain de l'échéance à l'échéance + durée. */
    public function nextPeriod(HostedService $s): array
    {
        $start = $s->expiry_date->copy()->addDay();

        return [$start, $s->expiry_date->copy()->addMonthsNoOverflow(max(1, $s->period_months))];
    }

    public function periodLabel(int $months): string
    {
        return match (true) {
            $months % 12 === 0 => ($months / 12).' an'.($months > 12 ? 's' : ''),
            default => $months.' mois',
        };
    }

    public function line(HostedService $s, Carbon $start, Carbon $end): array
    {
        $label = (HostedService::TYPES[$s->type] ?? 'Service').' '.$s->name.($s->plan ? ' — '.$s->plan : '');

        return ['id' => (string) Str::uuid(), 'product_id' => $s->product_id, 'description' => 'Renouvellement '.$this->periodLabel($s->period_months).' : '.$label.' (du '.$start->format('d/m/Y').' au '.$end->format('d/m/Y').')',
            'quantity' => 1, 'unit_price' => (float) $s->sale_price, 'tax_rate' => (float) $s->tax_rate, 'discount_percent' => 0];
    }

    /**
     * Facture de renouvellement pour un ou plusieurs services du même client.
     *
     * @param  HostedService[]  $services
     */
    public function invoice(Company $company, array $services): SalesDocument
    {
        $first = $services[0];
        foreach ($services as $s) {
            if ($s->partner_id !== $first->partner_id) {
                throw new \DomainException('Les services d\'une facture appartiennent au même client.');
            }
            if ($s->status !== 'active') {
                throw new \DomainException('« '.$s->name.' » n\'est pas actif.');
            }
            if ($s->pendingRenewal()) {
                throw new \DomainException('Le renouvellement de « '.$s->name.' » est déjà facturé.');
            }
        }
        $id = (string) Str::uuid();
        $lines = [];
        $periods = [];
        foreach ($services as $s) {
            [$start, $end] = $this->nextPeriod($s);
            $lines[] = $this->line($s, $start, $end);
            $periods[$s->id] = [$start, $end];
        }
        $names = collect($services)->pluck('name')->implode(', ');
        $data = [
            'type' => 'invoice', 'partner_id' => $first->partner_id, 'date' => now()->toDateString(), 'status' => 'validated', 'lines' => $lines,
            'due_date' => collect($services)->min(fn ($s) => $s->expiry_date->toDateString()) >= now()->toDateString() ? collect($services)->min(fn ($s) => $s->expiry_date->toDateString()) : null,
            'notes' => 'Renouvellement de vos services : '.$names.".\nSans règlement avant l'échéance, le service peut être interrompu.",
        ];
        $data = array_filter($data, fn ($v) => $v !== null);

        return DB::transaction(function () use ($id, $data, $services, $periods, $company) {
            SalesDocument::$systemWrite = true;
            try {
                $res = app(EntityWriter::class)->upsert(SalesDocument::class, $id, $data);
            } finally {
                SalesDocument::$systemWrite = false;
            }
            if ($res['status'] !== EntityWriter::APPLIED) {
                throw new \DomainException('Facture refusée : '.($res['message'] ?? ''));
            }
            foreach ($services as $s) {
                [$start, $end] = $periods[$s->id];
                Renewal::create(['service_id' => $s->id, 'document_id' => $id, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
                    'amount' => $s->sale_price, 'cost' => $s->cost_price, 'status' => 'invoiced']);
                $s->forceFill(['last_error' => null])->save();
            }

            return SalesDocument::find($id);
        });
    }

    /** Facture encaissée : échéances prolongées, renouvellements à faire chez le fournisseur. */
    public function onInvoicePaid(SalesDocument $doc): int
    {
        $n = 0;
        foreach (Renewal::where('document_id', $doc->id)->where('status', 'invoiced')->get() as $r) {
            $s = HostedService::find($r->service_id);
            $r->update(['status' => 'paid', 'paid_at' => now()]);
            if ($s) {
                if ($r->period_end->gt($s->expiry_date)) {
                    $s->expiry_date = $r->period_end->toDateString();
                }
                if ($s->status === 'suspended') {
                    $s->status = 'active';
                }
                $s->last_reminder = null;
                $s->save();
                $this->notifyStaff($s, 'Payé : renouvelez '.$s->name, 'Renouvelez '.(HostedService::TYPES[$s->type] ?? 'le service').' '.$s->name.($s->provider ? ' chez '.$s->provider : '').' jusqu\'au '.$r->period_end->format('d/m/Y').'.');
                $n++;
            }
        }

        return $n;
    }

    public function onInvoiceCancelled(SalesDocument $doc): void
    {
        foreach (Renewal::where('document_id', $doc->id)->where('status', 'invoiced')->get() as $r) {
            $r->update(['status' => 'cancelled']);
            HostedService::find($r->service_id)?->save();
        }
    }

    /** Prolongation sans facture (payée autrement, geste commercial…). */
    public function extend(HostedService $s, ?string $until = null, bool $providerDone = true): Renewal
    {
        [$start, $end] = $this->nextPeriod($s);
        if ($until) {
            $end = Carbon::parse($until);
            if ($end->lte($s->expiry_date)) {
                throw new \DomainException('La nouvelle échéance doit suivre l\'échéance actuelle ('.$s->expiry_date->format('d/m/Y').').');
            }
        }
        if ($p = $s->pendingRenewal()) {
            throw new \DomainException('Un renouvellement est facturé (en attente de paiement) : encaissez ou annulez la facture d\'abord.');
        }
        $r = Renewal::create(['service_id' => $s->id, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(), 'amount' => 0, 'cost' => $s->cost_price,
            'status' => 'manual', 'provider_done_at' => $providerDone ? now() : null, 'provider_done_by' => $providerDone ? auth()->id() : null]);
        $s->expiry_date = $end->toDateString();
        $s->last_reminder = null;
        $s->save();

        return $r;
    }

    public function providerDone(HostedService $s): void
    {
        $n = Renewal::where('service_id', $s->id)->whereIn('status', ['paid', 'manual'])->whereNull('provider_done_at')->update(['provider_done_at' => now(), 'provider_done_by' => auth()->id()]);
        if (! $n) {
            throw new \DomainException('Aucun renouvellement en attente chez le fournisseur.');
        }
        $s->save();
    }

    // ------------------------------------------------------------------ tâche quotidienne

    public function runDaily(Company $company, ?string $today = null): array
    {
        $today = Carbon::parse($today ?? now()->toDateString());
        $cfg = $this->settings($company);
        $out = ['invoices' => 0, 'reminders' => 0, 'errors' => 0];

        // 1. Factures de renouvellement, regroupées par client
        $due = HostedService::where('status', 'active')->where('auto_renew', true)->get()
            ->filter(fn ($s) => $s->expiry_date->copy()->subDays($s->renew_days_before)->lte($today) && ! $s->pendingRenewal()
                && ! Renewal::where('service_id', $s->id)->where('status', '<>', 'cancelled')->where('period_start', $s->expiry_date->copy()->addDay()->toDateString())->exists());
        foreach ($due->groupBy('partner_id') as $group) {
            try {
                $doc = $this->invoice($company, $group->values()->all());
                $out['invoices']++;
                if ($cfg['auto_send']) {
                    $this->remind($company, $group->first(), $doc, $group->values()->all());
                }
            } catch (\DomainException $e) {
                foreach ($group as $s) {
                    $s->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 255)])->save();
                }
                $out['errors']++;
            }
        }

        // 2. Rappels d'échéance (paliers J-30, J-7, J-1…) et services expirés
        $thresholds = $cfg['reminder_days'];
        rsort($thresholds);
        foreach (HostedService::where('status', 'active')->get() as $s) {
            $left = (int) $today->diffInDays($s->expiry_date->copy()->startOfDay(), false);
            if ($left < 0) {
                $key = $s->expiry_date->toDateString().'|expired';
                if ($s->last_reminder !== $key) {
                    $s->forceFill(['last_reminder' => $key])->save();
                    $this->notifyStaff($s, 'Échéance dépassée : '.$s->name, ($s->partner_name ?? '').' · expiré depuis le '.$s->expiry_date->format('d/m/Y'));
                }
                continue;
            }
            $hit = null;
            foreach ($thresholds as $d) {
                if ($left <= $d) {
                    $hit = $d;
                }
            }
            if ($hit === null || ! $s->reminders) {
                continue;
            }
            $key = $s->expiry_date->toDateString().'|'.$hit;
            if ($s->last_reminder === $key || (str_starts_with((string) $s->last_reminder, $s->expiry_date->toDateString().'|') && $this->lower($s->last_reminder, $hit))) {
                continue;
            }
            $p = $s->pendingRenewal();
            $doc = $p?->document_id ? SalesDocument::find($p->document_id) : null;
            if ($doc && $doc->payment_status === 'paid') {
                continue;
            }
            $s->forceFill(['last_reminder' => $key])->save();
            if ($this->remind($company, $s, $doc, [$s], $left)) {
                $out['reminders']++;
            }
        }

        return $out;
    }

    /** Palier déjà envoyé plus proche de l'échéance (ex. J-7 envoyé, J-30 inutile). */
    protected function lower(?string $last, int $hit): bool
    {
        $parts = explode('|', (string) $last);

        return isset($parts[1]) && is_numeric($parts[1]) && (int) $parts[1] <= $hit;
    }

    /** Message au client (e-mail, et WhatsApp si configuré) avec lien de paiement de la facture. */
    public function remind(Company $company, HostedService $s, ?SalesDocument $doc, array $services, ?int $left = null): bool
    {
        $p = Partner::find($s->partner_id);
        if (! $p) {
            return false;
        }
        $messaging = app(MessagingService::class);
        $avail = $messaging->available($company);
        $list = collect($services)->map(fn ($x) => '- '.(HostedService::TYPES[$x->type] ?? 'Service').' '.$x->name.' : échéance le '.$x->expiry_date->format('d/m/Y'))->implode("\n");
        $link = null;
        $amount = '';
        if ($doc && $doc->status === 'validated') {
            $link = InvoicePayments::url(app(InvoicePayments::class)->link($doc));
            $amount = number_format((float) $doc->total - (float) $doc->amount_paid, TradeDocumentLine::decimalsFor($doc->currency), ',', ' ').' '.(in_array($doc->currency, ['XOF', 'XAF'], true) ? 'F CFA' : $doc->currency);
        }
        $when = $left === null ? 'arrivent bientôt à échéance' : ($left === 0 ? 'expirent aujourd\'hui' : 'expirent dans '.$left.' jour'.($left > 1 ? 's' : ''));
        $text = "Bonjour {$p->name},\n\nVos services {$when} :\n{$list}\n\n"
            .($doc ? "Facture de renouvellement {$doc->number} : {$amount}.".($link ? "\nPayer en ligne : {$link}" : '') : 'Contactez-nous pour les renouveler.')
            ."\n\nSans renouvellement, votre site, vos e-mails ou votre nom de domaine peuvent être interrompus.\n\nMerci de votre confiance,\n{$company->name}";
        $sent = false;
        if ($p->email && ($avail['email'] ?? null)) {
            $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;line-height:1.5">'.nl2br(e($text)).($link ? '<p><a href="'.e($link).'" style="display:inline-block;background:#1f6feb;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">Payer le renouvellement</a></p>' : '').'</div>';
            try {
                $sent = $messaging->send($company, 'email', new Message(to: $p->email, subject: 'Renouvellement : '.collect($services)->pluck('name')->implode(', '), html: $html, text: $text, toName: $p->name), 'hebergement')->ok || $sent;
            } catch (\Throwable) {
            }
        }
        if ($p->phone && ($avail['whatsapp'] ?? null)) {
            try {
                $sent = $messaging->send($company, 'whatsapp', new Message(to: $p->phone, text: $text, toName: $p->name), 'hebergement')->ok || $sent;
            } catch (\Throwable) {
            }
        }

        return $sent;
    }

    public function notifyStaff(HostedService $s, string $title, string $body): void
    {
        $company = app('tenant');
        $users = User::where('company_id', $company->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('hebergement.manage'))->pluck('id');
        AppNotification::send($company->id, $users, 'hosting', $title, $body, 'hebergement', $s->id); // à faire : aussi pour l'auteur de l'action
    }

    // ------------------------------------------------------------------ indicateurs

    public function stats(Company $company): array
    {
        $all = HostedService::where('status', 'active')->get();
        $mrr = $all->sum(fn ($s) => $s->sale_price / max(1, $s->period_months));
        $cost = $all->sum(fn ($s) => $s->cost_price / max(1, $s->period_months));
        $byType = [];
        foreach ($all as $s) {
            $byType[$s->type] ??= ['type' => $s->type, 'label' => HostedService::TYPES[$s->type], 'count' => 0, 'monthly' => 0.0];
            $byType[$s->type]['count']++;
            $byType[$s->type]['monthly'] += $s->sale_price / max(1, $s->period_months);
        }
        $year = now()->year;
        $renewed = Renewal::whereIn('status', ['paid'])->whereYear('paid_at', $year)->get();

        return [
            'active' => $all->count(),
            'expiring_30' => $all->filter(fn ($s) => $s->daysLeft() >= 0 && $s->daysLeft() <= 30)->count(),
            'expired' => $all->filter(fn ($s) => $s->daysLeft() < 0)->count(),
            'unpaid_renewals' => Renewal::where('status', 'invoiced')->count(),
            'provider_todo' => Renewal::whereIn('status', ['paid', 'manual'])->whereNull('provider_done_at')->count(),
            'mrr' => round($mrr, 0), 'arr' => round($mrr * 12, 0), 'margin_monthly' => round($mrr - $cost, 0),
            'margin_rate' => $mrr > 0 ? round(100 * ($mrr - $cost) / $mrr, 1) : 0,
            'renewed_year' => round($renewed->sum('amount'), 0), 'renewed_year_margin' => round($renewed->sum(fn ($r) => $r->amount - $r->cost), 0),
            'by_type' => array_values(array_map(fn ($t) => $t + ['monthly' => round($t['monthly'], 0)], $byType)),
            'clients' => $all->pluck('partner_id')->unique()->count(),
        ];
    }

    public function history(HostedService $s): array
    {
        $rows = Renewal::where('service_id', $s->id)->orderByDesc('period_start')->get();
        $docs = SalesDocument::whereIn('id', $rows->pluck('document_id')->filter())->get()->keyBy('id');
        $users = User::whereIn('id', $rows->pluck('provider_done_by')->filter())->pluck('name', 'id');

        return $rows->map(fn ($r) => ['id' => $r->id, 'status' => $r->status, 'period_start' => $r->period_start->toDateString(), 'period_end' => $r->period_end->toDateString(),
            'amount' => $r->amount, 'cost' => $r->cost, 'paid_at' => $r->paid_at?->toIso8601String(), 'provider_done_at' => $r->provider_done_at?->toIso8601String(),
            'provider_done_by' => $users[$r->provider_done_by] ?? null, 'document_id' => $r->document_id, 'document_number' => $docs[$r->document_id]?->number ?? null,
            'payment_status' => $docs[$r->document_id]?->payment_status ?? null])->all();
    }
}
