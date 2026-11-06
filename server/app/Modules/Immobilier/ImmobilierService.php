<?php

namespace App\Modules\Immobilier;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\Company;
use App\Models\Sequence;
use App\Modules\Core\Models\Partner;
use App\Modules\Immobilier\Models\Expense;
use App\Modules\Immobilier\Models\Lease;
use App\Modules\Immobilier\Models\Payout;
use App\Modules\Immobilier\Models\Property;
use App\Modules\Immobilier\Models\Rent;
use App\Modules\Immobilier\Models\Unit;
use App\Modules\Ventes\Models\SalesDocument;
use App\Modules\Ventes\Models\SalesPayment;
use App\Payments\InvoicePayments;
use App\Services\EntityWriter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gestion locative : biens et lots, baux (caution, avance, révision), appels de loyer facturés dans les Ventes,
 * encaissements, quittances, relances, dépenses, relevés de gérance et reversements aux propriétaires.
 */
class ImmobilierService
{
    public const PROPERTY_KINDS = ['immeuble' => 'Immeuble', 'villa' => 'Villa', 'cour_commune' => 'Cour commune', 'residence' => 'Résidence', 'commerce' => 'Local commercial', 'bureaux' => 'Immeuble de bureaux', 'terrain' => 'Terrain'];

    public const UNIT_KINDS = ['studio' => 'Studio', 'appartement' => 'Appartement', 'villa' => 'Villa', 'chambre' => 'Chambre', 'magasin' => 'Magasin', 'bureau' => 'Bureau', 'entrepot' => 'Entrepôt', 'parking' => 'Parking', 'terrain' => 'Terrain'];

    public const UNIT_STATUSES = ['vacant' => 'Libre', 'occupied' => 'Loué', 'works' => 'En travaux'];

    public const EXPENSE_CATEGORIES = ['travaux' => 'Travaux', 'entretien' => 'Entretien', 'taxe' => 'Impôts et taxes', 'assurance' => 'Assurance', 'syndic' => 'Syndic / gardiennage', 'eau_elec' => 'Eau / électricité', 'autre' => 'Autre'];

    public const PERIODICITY = [1 => 'Mensuel', 3 => 'Trimestriel', 6 => 'Semestriel', 12 => 'Annuel'];

    /** Les appels de loyer sont émis quelques jours avant le début de la période. */
    public const DAYS_BEFORE = 5;

    public const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    public function __construct(protected EntityWriter $writer) {}

    public static function money(float $v): string
    {
        return number_format($v, 0, ',', ' ').' F';
    }

    public static function periodLabel(Carbon $start, Carbon $end): string
    {
        if ($start->isSameMonth($end) && $start->day === 1 && $end->isLastOfMonth()) {
            return self::MONTHS[$start->month - 1].' '.$start->year;
        }
        if ($start->day === 1 && $end->isLastOfMonth()) {
            return self::MONTHS[$start->month - 1].($start->year !== $end->year ? ' '.$start->year : '').' à '.self::MONTHS[$end->month - 1].' '.$end->year;
        }

        return 'du '.$start->format('d/m/Y').' au '.$end->format('d/m/Y');
    }

    protected function partner(?string $id, ?string $name, ?string $phone, string $type, ?string $email = null): ?string
    {
        if ($id) {
            return Partner::findOrFail($id)->id;
        }
        if (! filled($name)) {
            return null;
        }
        $p = Partner::create(['name' => Str::limit(trim($name), 160, ''), 'type' => $type, 'phone' => $phone, 'email' => $email,
            'notes' => $type === 'supplier' ? 'Propriétaire (gestion locative)' : 'Locataire (gestion locative)']);

        return $p->id;
    }

    // ------------------------------------------------------------------ biens et lots

    public function saveProperty(?Property $p, array $d): Property
    {
        $p ??= new Property;
        $owner = array_key_exists('owner_id', $d) || filled($d['owner_name'] ?? null)
            ? $this->partner($d['owner_id'] ?? null, $d['owner_name'] ?? null, $d['owner_phone'] ?? null, 'supplier', $d['owner_email'] ?? null) : $p->owner_id;
        $p->fill(array_intersect_key($d, array_flip(['name', 'kind', 'address', 'city', 'district', 'fee_rate', 'notes', 'active'])) + ['owner_id' => $owner]);
        $p->owner_id = $owner;
        if (! $owner) {
            $p->fee_rate = 0;
        }
        $p->save();

        return $p;
    }

    public function saveUnit(?Unit $u, array $d): Unit
    {
        $u ??= new Unit;
        Property::findOrFail($d['property_id'] ?? $u->property_id);
        $u->fill(array_intersect_key($d, array_flip(['property_id', 'reference', 'kind', 'rooms', 'surface', 'floor', 'rent', 'charges', 'notes'])));
        if (isset($d['status']) && $d['status'] !== 'occupied' && ! $this->activeLease($u)) {
            $u->status = $d['status'];
        }
        $u->save();

        return $u;
    }

    public function activeLease(Unit $u): ?Lease
    {
        return $u->id ? Lease::where('unit_id', $u->id)->where('status', 'active')->first() : null;
    }

    // ------------------------------------------------------------------ baux

    public function createLease(array $d): Lease
    {
        $u = Unit::findOrFail($d['unit_id']);
        if ($this->activeLease($u)) {
            throw new \DomainException('Le lot '.$u->reference.' est déjà loué : terminez d\'abord le bail en cours.');
        }
        $tenant = $this->partner($d['tenant_id'] ?? null, $d['tenant_name'] ?? null, $d['tenant_phone'] ?? null, 'customer', $d['tenant_email'] ?? null)
            ?? throw new \DomainException('Choisissez ou saisissez le locataire.');
        $c = app('tenant');

        return DB::transaction(function () use ($d, $u, $tenant, $c) {
            $start = Carbon::parse($d['start_date']);
            $l = Lease::create([
                'number' => Sequence::next($c->id, 'lease', 'BAIL'), 'unit_id' => $u->id, 'tenant_id' => $tenant, 'usage' => $d['usage'] ?? 'habitation',
                'start_date' => $start->toDateString(), 'end_date' => $d['end_date'] ?? null, 'rent' => (float) ($d['rent'] ?? $u->rent), 'charges' => (float) ($d['charges'] ?? $u->charges),
                'tax_rate' => (float) ($d['tax_rate'] ?? 0), 'months' => (int) ($d['months'] ?? 1), 'due_day' => max(1, min(28, (int) ($d['due_day'] ?? 5))),
                'deposit_amount' => (float) ($d['deposit_amount'] ?? 0), 'advance_months' => (int) ($d['advance_months'] ?? 0),
                'revision_rate' => (float) ($d['revision_rate'] ?? 0), 'next_revision_date' => ! empty($d['revision_rate']) ? ($d['next_revision_date'] ?? $start->copy()->addYear()->toDateString()) : null,
                'guarantor_name' => $d['guarantor_name'] ?? null, 'guarantor_phone' => $d['guarantor_phone'] ?? null, 'notes' => $d['notes'] ?? null, 'status' => 'active',
            ]);
            $u->forceFill(['status' => 'occupied'])->save();
            // Avance : les premiers mois sont appelés tout de suite (et réglés à la signature, avec la caution, si demandé)
            $rents = [];
            for ($i = 0, $months = 0; $months < $l->advance_months && $i < 12; $i++) {
                $r = $this->generate($l->fresh());
                if (! $r) {
                    break;
                }
                $rents[] = $r;
                $months += $l->months;
            }
            if (! empty($d['entry_payment'])) {
                $pay = (array) $d['entry_payment'];
                foreach ($rents as $r) {
                    $this->pay($r, $pay + ['date' => $start->toDateString()]);
                }
                if ($l->deposit_amount > 0) {
                    $this->deposit($l, ['amount' => $l->deposit_amount, 'date' => $start->toDateString()]);
                }
            }

            return $l->fresh();
        });
    }

    public function updateLease(Lease $l, array $d): Lease
    {
        $l->fill(array_intersect_key($d, array_flip(['end_date', 'rent', 'charges', 'tax_rate', 'due_day', 'deposit_amount', 'revision_rate', 'next_revision_date', 'guarantor_name', 'guarantor_phone', 'notes', 'usage'])));
        $l->save();

        return $l;
    }

    public function deposit(Lease $l, array $d): Lease
    {
        $amount = round((float) ($d['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new \DomainException('Montant de caution invalide.');
        }
        $l->forceFill(['deposit_paid' => $l->deposit_paid + $amount, 'deposit_paid_at' => $d['date'] ?? now()->toDateString()])->save();

        return $l;
    }

    /** Fin du bail : lot libéré, caution restituée (moins les retenues justifiées). */
    public function endLease(Lease $l, array $d): Lease
    {
        if ($l->status !== 'active') {
            throw new \DomainException('Ce bail est déjà terminé.');
        }
        $retained = min($l->deposit_paid, max(0, round((float) ($d['deposit_retained'] ?? 0), 2)));

        return DB::transaction(function () use ($l, $d, $retained) {
            $l->forceFill(['status' => 'ended', 'ended_at' => $d['date'] ?? now()->toDateString(), 'end_reason' => $d['reason'] ?? null,
                'deposit_retained' => $retained, 'deposit_returned' => max(0, $l->deposit_paid - $retained), 'deposit_note' => $d['deposit_note'] ?? null])->save();
            Unit::whereKey($l->unit_id)->update(['status' => ! empty($d['works']) ? 'works' : 'vacant']);

            return $l;
        });
    }

    // ------------------------------------------------------------------ appels de loyer

    /** Prochaine période à appeler (périodes calées sur les mois civils ; la première est au prorata). */
    public function nextPeriod(Lease $l): ?array
    {
        $last = Rent::where('lease_id', $l->id)->max('period_end');
        $start = $last ? Carbon::parse($last)->addDay() : $l->start_date->copy();
        if ($l->end_date && $start->gt($l->end_date)) {
            return null;
        }
        $months = max(1, $l->months);
        $end = $start->copy()->startOfMonth()->addMonths($months)->subDay();
        if ($l->end_date && $end->gt($l->end_date)) {
            $end = $l->end_date->copy();
        }
        // Nombre de mois couverts, chaque mois entamé compté au prorata de ses jours
        $factor = 0.0;
        for ($cur = $start->copy()->startOfDay(); $cur->lte($end);) {
            $mEnd = $cur->copy()->endOfMonth()->startOfDay();
            if ($mEnd->gt($end)) {
                $mEnd = $end->copy()->startOfDay();
            }
            $factor += ($mEnd->day - $cur->day + 1) / $cur->daysInMonth;
            $cur = $mEnd->copy()->addDay();
        }

        return [$start, $end, $factor];
    }

    /** Appel de loyer de la période suivante : facture validée au nom du locataire. */
    public function generate(Lease $l): ?Rent
    {
        if ($l->status !== 'active') {
            return null;
        }
        $p = $this->nextPeriod($l);
        if (! $p) {
            return null;
        }
        [$start, $end, $factor] = $p;
        // Révision annuelle du loyer à la date anniversaire
        if ($l->revision_rate > 0 && $l->next_revision_date && $start->gte($l->next_revision_date)) {
            $l->forceFill(['rent' => round($l->rent * (1 + $l->revision_rate / 100)), 'next_revision_date' => $l->next_revision_date->copy()->addYear()->toDateString(),
                'notes' => trim(($l->notes ? $l->notes."\n" : '').'Loyer révisé de '.$l->revision_rate.' % le '.$start->format('d/m/Y').'.')])->save();
        }
        $unit = Unit::findOrFail($l->unit_id);
        $prop = Property::findOrFail($unit->property_id);
        $rent = round($l->rent * $factor);
        $charges = round($l->charges * $factor);
        $due = $start->copy()->day(min($l->due_day, $start->daysInMonth));
        if ($due->lt($start)) {
            $due = $start->copy();
        }
        $label = self::periodLabel($start, $end);
        $lines = [['id' => (string) Str::uuid(), 'description' => 'Loyer '.$unit->reference.' — '.$label, 'quantity' => 1, 'unit_price' => $rent, 'tax_rate' => $l->tax_rate, 'discount_percent' => 0]];
        if ($charges > 0) {
            $lines[] = ['id' => (string) Str::uuid(), 'description' => 'Charges locatives — '.$label, 'quantity' => 1, 'unit_price' => $charges, 'tax_rate' => $l->tax_rate, 'discount_percent' => 0];
        }
        $id = (string) Str::uuid();
        SalesDocument::$systemWrite = true;
        try {
            $res = $this->writer->upsert(SalesDocument::class, $id, [
                'type' => 'invoice', 'partner_id' => $l->tenant_id, 'date' => ($start->lt(now()->startOfDay()) ? $start : now())->toDateString(),
                'due_date' => $due->toDateString(), 'status' => 'validated', 'reference' => $l->number,
                'notes' => 'Appel de loyer '.$label.' · '.$prop->name.' — '.$unit->reference.' · bail '.$l->number, 'lines' => $lines,
            ]);
        } finally {
            SalesDocument::$systemWrite = false;
        }
        if ($res['status'] !== EntityWriter::APPLIED) {
            $l->forceFill(['last_error' => Str::limit('Appel de loyer refusé : '.($res['message'] ?? ''), 250, '')])->save();

            return null;
        }
        $l->forceFill(['last_error' => null])->save();

        return Rent::create(['lease_id' => $l->id, 'unit_id' => $unit->id, 'property_id' => $prop->id, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
            'rent' => $rent, 'charges' => $charges, 'total' => (float) SalesDocument::find($id)->total, 'due_date' => $due->toDateString(), 'invoice_id' => $id]);
    }

    /** Appels de loyer de toutes les périodes qui commencent d'ici quelques jours (rattrapage compris). */
    public function runDue(Company $c, ?string $until = null): array
    {
        $limit = Carbon::parse($until ?? now()->addDays(self::DAYS_BEFORE)->toDateString());
        $out = ['rents' => 0, 'errors' => 0];
        foreach (Lease::where('status', 'active')->get() as $l) {
            for ($guard = 0; $guard < 36; $guard++) {
                $p = $this->nextPeriod($l);
                if (! $p || $p[0]->gt($limit)) {
                    break;
                }
                if (! $this->generate($l)) {
                    $out['errors']++;
                    break;
                }
                $out['rents']++;
                $l->refresh();
            }
        }

        return $out;
    }

    public function pay(Rent $r, array $d): Rent
    {
        $inv = SalesDocument::find($r->invoice_id) ?? throw new \DomainException('Facture du loyer introuvable.');
        $due = $inv->balance();
        if ($due <= 0) {
            throw new \DomainException('Ce loyer est déjà réglé.');
        }
        $amount = round(min($due, (float) ($d['amount'] ?? $due)), 2);
        $method = in_array($d['method'] ?? '', \App\Modules\Core\Models\Payment::METHODS, true) ? $d['method'] : 'cash';
        SalesPayment::$systemWrite = true;
        try {
            $res = $this->writer->upsert(SalesPayment::class, (string) Str::uuid(), array_filter([
                'document_id' => $inv->id, 'date' => $d['date'] ?? now()->toDateString(), 'amount' => $amount, 'method' => $method,
                'reference' => $d['reference'] ?? null, 'notes' => 'Loyer '.$inv->notes, 'treasury_account_id' => $d['treasury_account_id'] ?? null,
            ], fn ($v) => $v !== null));
        } finally {
            SalesPayment::$systemWrite = false;
        }
        if ($res['status'] !== EntityWriter::APPLIED) {
            throw new \DomainException('Encaissement refusé : '.($res['message'] ?? 'erreur inconnue'));
        }

        return $r->fresh();
    }

    public function rentToApi(Rent $r, ?array $ctx = null): array
    {
        $inv = $ctx['invoices'][$r->invoice_id] ?? ($r->invoice_id ? DB::table('trade_documents')->where('id', $r->invoice_id)->first(['id', 'number', 'total', 'amount_paid', 'payment_status', 'status']) : null);
        $lease = $ctx['leases'][$r->lease_id] ?? Lease::find($r->lease_id);
        $unit = $ctx['units'][$r->unit_id] ?? Unit::find($r->unit_id);
        $prop = $ctx['properties'][$r->property_id] ?? Property::find($r->property_id);
        $tenant = $ctx['partners'][$lease?->tenant_id] ?? ($lease ? DB::table('partners')->where('id', $lease->tenant_id)->first(['id', 'name', 'phone', 'email']) : null);
        $paid = $inv ? (float) $inv->amount_paid : 0;
        $total = $inv ? (float) $inv->total : $r->total;
        $balance = round($total - $paid, 2);
        $status = $inv && $inv->status === 'cancelled' ? 'cancelled' : ($balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'));
        $late = $status !== 'paid' && $status !== 'cancelled' && $r->due_date->lt(now()->startOfDay()) ? (int) $r->due_date->diffInDays(now()->startOfDay()) : 0;

        return $r->toArray() + [
            'label' => self::periodLabel($r->period_start, $r->period_end), 'invoice_number' => $inv?->number, 'paid' => $paid, 'balance' => max(0, $balance),
            'status' => $status, 'status_label' => ['paid' => 'Payé', 'partial' => 'Partiel', 'unpaid' => 'À payer', 'cancelled' => 'Annulé'][$status], 'days_late' => $late,
            'lease_number' => $lease?->number, 'unit_reference' => $unit?->reference, 'property_name' => $prop?->name,
            'tenant' => $tenant ? ['id' => $tenant->id, 'name' => $tenant->name, 'phone' => $tenant->phone ?? null] : null,
        ];
    }

    /** Contexte chargé en une fois pour une liste de loyers. */
    public function context($rents): array
    {
        $leases = Lease::whereIn('id', $rents->pluck('lease_id')->unique())->get()->keyBy('id');

        return [
            'invoices' => DB::table('trade_documents')->whereIn('id', $rents->pluck('invoice_id')->filter()->unique())->get(['id', 'number', 'total', 'amount_paid', 'payment_status', 'status'])->keyBy('id')->all(),
            'leases' => $leases->all(), 'units' => Unit::whereIn('id', $rents->pluck('unit_id')->unique())->get()->keyBy('id')->all(),
            'properties' => Property::whereIn('id', $rents->pluck('property_id')->unique())->get()->keyBy('id')->all(),
            'partners' => DB::table('partners')->whereIn('id', $leases->pluck('tenant_id')->unique())->get(['id', 'name', 'phone', 'email'])->keyBy('id')->all(),
        ];
    }

    /** Relance d'un locataire : tous ses loyers échus impayés, par WhatsApp, SMS ou e-mail. */
    public function remind(Company $c, Lease $l): bool
    {
        $rents = Rent::where('lease_id', $l->id)->where('due_date', '<', now()->toDateString())->orderBy('period_start')->get();
        $ctx = $this->context($rents);
        $open = $rents->map(fn ($r) => $this->rentToApi($r, $ctx))->filter(fn ($r) => in_array($r['status'], ['unpaid', 'partial'], true))->values();
        if ($open->isEmpty()) {
            throw new \DomainException('Aucun loyer échu impayé pour ce bail.');
        }
        $p = Partner::find($l->tenant_id);
        $unit = Unit::find($l->unit_id);
        $sum = $open->sum('balance');
        $text = "Bonjour {$p->name}, sauf erreur de notre part, ".($open->count() > 1 ? 'les loyers suivants restent' : 'le loyer suivant reste').' à régler pour le logement '.$unit->reference.' : '
            .$open->map(fn ($r) => $r['label'].' ('.self::money($r['balance']).')')->implode(', ').'. Total : '.self::money($sum).'.';
        $inv = SalesDocument::find($open->last()['invoice_id']);
        if ($inv && InvoicePayments::gateways($c)->count()) {
            $text .= "\nPayer en ligne : ".InvoicePayments::url(app(InvoicePayments::class)->link($inv));
        }
        $text .= "\nMerci de régulariser rapidement. ".$c->name.($c->phone ? ' — '.$c->phone : '');
        $m = app(MessagingService::class);
        $av = $m->available($c);
        $sent = false;
        try {
            if ($p->phone && ($av['whatsapp'] ?? null)) {
                $sent = $m->send($c, 'whatsapp', new Message(to: $p->phone, toName: $p->name, text: $text), 'loyer')->ok;
            } elseif ($p->phone && ($av['sms'] ?? null)) {
                $sent = $m->send($c, 'sms', new Message(to: $p->phone, toName: $p->name, text: $text), 'loyer')->ok;
            } elseif ($p->email && ($av['email'] ?? null)) {
                $sent = $m->send($c, 'email', new Message(to: $p->email, toName: $p->name, subject: 'Loyer impayé — '.$unit->reference, html: nl2br(e($text)), text: $text, replyTo: $c->email), 'loyer')->ok;
            }
        } catch (\Throwable) {
            $sent = false;
        }
        if (! $sent) {
            throw new \DomainException('Message non envoyé : configurez WhatsApp, SMS ou l\'e-mail dans les canaux de messagerie, ou utilisez le lien WhatsApp.');
        }
        Rent::whereIn('id', $open->pluck('id'))->update(['reminded_at' => now(), 'reminders' => DB::raw('reminders + 1')]);

        return true;
    }

    public function whatsappLink(Lease $l, array $open): ?string
    {
        $p = Partner::find($l->tenant_id);
        $digits = preg_replace('/\D/', '', (string) $p?->phone);
        if (! $digits) {
            return null;
        }
        $wa = strlen($digits) === 10 ? '225'.$digits : $digits;

        return 'https://wa.me/'.$wa.'?text='.rawurlencode('Bonjour '.$p->name.', il reste '.self::money(array_sum(array_column($open, 'balance'))).' de loyer à régler ('.implode(', ', array_column($open, 'label')).'). Merci.');
    }

    // ------------------------------------------------------------------ quittance / avis d'échéance

    public function rentPdf(Company $c, Rent $r): string
    {
        $a = $this->rentToApi($r);
        $l = Lease::findOrFail($r->lease_id);
        $unit = Unit::findOrFail($r->unit_id);
        $prop = Property::findOrFail($r->property_id);
        $tenant = Partner::find($l->tenant_id);
        $owner = $prop->owner_id ? Partner::find($prop->owner_id) : null;
        $paid = $a['status'] === 'paid';
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $payments = DB::table('payments')->where('document_id', $r->invoice_id)->whereNull('deleted_at')->orderBy('date')->get(['date', 'amount', 'method', 'reference']);
        $methods = ['cash' => 'espèces', 'mobile_money' => 'Mobile Money', 'card' => 'carte', 'bank_transfer' => 'virement', 'cheque' => 'chèque', 'other' => 'autre'];
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(16, 16, 16);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->AddPage();
        $title = $paid ? 'QUITTANCE DE LOYER' : ($a['status'] === 'partial' ? 'REÇU PARTIEL DE LOYER' : 'AVIS D\'ÉCHÉANCE');
        $html = '<table cellpadding="3"><tr><td width="55%"><b style="font-size:13pt">'.$e($c->name).'</b><br>'.$e($c->address).($c->city ? '<br>'.$e($c->city) : '').($c->phone ? '<br>Tél. '.$e($c->phone) : '')
            .($owner ? '<br><span style="color:#555">Pour le compte de '.$e($owner->name).', propriétaire</span>' : '').'</td>'
            .'<td width="45%" align="right"><b style="font-size:14pt;color:#1f6feb">'.$title.'</b><br>Période : '.$e($a['label']).'<br>Facture '.$e($a['invoice_number']).'<br>Bail '.$e($l->number).'</td></tr></table><br><br>'
            .'<table cellpadding="6" border="0.3"><tr><td width="50%" bgcolor="#f3f6fb"><b>Locataire</b><br>'.$e($tenant?->name).($tenant?->phone ? '<br>'.$e($tenant->phone) : '').'</td>'
            .'<td width="50%" bgcolor="#f3f6fb"><b>Logement loué</b><br>'.$e($prop->name).' — '.$e($unit->reference).'<br>'.$e(trim(($prop->address ?: '').' '.($prop->district ?: '').' '.($prop->city ?: ''))).'</td></tr></table><br><br>'
            .'<table cellpadding="5" border="0.3"><tr bgcolor="#1f2a44" color="#ffffff" style="font-weight:bold"><td width="70%">Détail</td><td width="30%" align="right">Montant</td></tr>'
            .'<tr><td>Loyer '.$e($a['label']).'</td><td align="right">'.self::money($r->rent).'</td></tr>'
            .($r->charges > 0 ? '<tr><td>Charges locatives</td><td align="right">'.self::money($r->charges).'</td></tr>' : '')
            .($r->total > $r->rent + $r->charges + 0.5 ? '<tr><td>TVA</td><td align="right">'.self::money($r->total - $r->rent - $r->charges).'</td></tr>' : '')
            .'<tr bgcolor="#eef2f8" style="font-weight:bold"><td>Total</td><td align="right">'.self::money($r->total).'</td></tr>'
            .'<tr><td>Déjà réglé</td><td align="right">'.self::money($a['paid']).'</td></tr>'
            .'<tr style="font-weight:bold"><td>Reste à payer</td><td align="right">'.self::money($a['balance']).'</td></tr></table><br><br>';
        if ($paid) {
            $last = $payments->last();
            $html .= '<p>Je soussigné(e), '.$e($c->name).($owner ? ', mandataire de '.$e($owner->name) : '').', déclare avoir reçu de <b>'.$e($tenant?->name).'</b> la somme de <b>'.self::money($r->total).'</b> au titre du loyer et des charges de la période '.$e($a['label'])
                .' pour le logement désigné ci-dessus, et lui en donne quittance, sous réserve de tous mes droits.</p>'
                .'<p>Règlement'.($payments->count() > 1 ? 's' : '').' : '.$e($payments->map(fn ($p) => Carbon::parse($p->date)->format('d/m/Y').' — '.self::money((float) $p->amount).' ('.($methods[$p->method] ?? $p->method).($p->reference ? ' '.$p->reference : '').')')->implode(' ; ')).'</p>'
                .'<br><p>Fait à '.$e($c->city ?: 'Abidjan').', le '.($last ? Carbon::parse($last->date)->format('d/m/Y') : now()->format('d/m/Y')).'</p><br><br><p align="right">Signature et cachet</p>';
        } else {
            $html .= '<p>Merci de régler la somme de <b>'.self::money($a['balance']).'</b> au plus tard le <b>'.$r->due_date->format('d/m/Y').'</b>, en espèces, par Mobile Money ou par virement, en rappelant la référence '.$e($a['invoice_number']).'.</p>'
                .($a['days_late'] > 0 ? '<p style="color:#b91c1c"><b>Échéance dépassée de '.$a['days_late'].' jour(s).</b></p>' : '')
                .'<p style="font-size:8pt;color:#555">Ce document n\'est pas une quittance. La quittance est délivrée après paiement complet du loyer et des charges.</p>';
        }
        $pdf->writeHTML($html);

        return $pdf->Output('', 'S');
    }

    // ------------------------------------------------------------------ tableau de bord

    public function dashboard(Company $c, string $month): array
    {
        $start = Carbon::parse($month.'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $units = Unit::all();
        $occupied = $units->where('status', 'occupied')->count();
        $rents = Rent::whereBetween('period_start', [$start->toDateString(), $end->toDateString()])->get(); // loyers appelés pour les périodes qui commencent dans le mois
        $ctx = $this->context($rents);
        $rows = $rents->map(fn ($r) => $this->rentToApi($r, $ctx))->where('status', '!=', 'cancelled');
        $allRents = Rent::where('due_date', '<', now()->toDateString())->get();
        $actx = $this->context($allRents);
        $overdue = $allRents->map(fn ($r) => $this->rentToApi($r, $actx))->filter(fn ($r) => in_array($r['status'], ['unpaid', 'partial'], true));
        $invoiceIds = Rent::pluck('invoice_id')->filter()->all();
        $collected = $invoiceIds ? (float) DB::table('payments')->whereIn('document_id', $invoiceIds)->whereNull('deleted_at')->whereBetween('date', [$start->toDateString(), $end->toDateString()])->sum('amount') : 0;
        $months = [];
        for ($m = $start->copy()->subMonths(11); $m->lte($start); $m->addMonth()) {
            $ms = $m->copy()->startOfMonth()->toDateString();
            $me = $m->copy()->endOfMonth()->toDateString();
            $months[] = ['month' => $m->format('Y-m'), 'billed' => (float) Rent::whereBetween('period_start', [$ms, $me])->sum('total'),
                'collected' => $invoiceIds ? (float) DB::table('payments')->whereIn('document_id', $invoiceIds)->whereNull('deleted_at')->whereBetween('date', [$ms, $me])->sum('amount') : 0];
        }
        $byTenant = $overdue->groupBy('lease_id')->map(fn ($g) => ['lease_id' => $g->first()['lease_id'], 'lease_number' => $g->first()['lease_number'], 'tenant' => $g->first()['tenant'],
            'unit' => $g->first()['unit_reference'], 'property' => $g->first()['property_name'], 'count' => $g->count(), 'balance' => $g->sum('balance'), 'days_late' => $g->max('days_late')])
            ->sortByDesc('balance')->values();
        $billed = (float) $rows->sum('total');

        return [
            'month' => $start->format('Y-m'), 'properties' => Property::count(), 'units' => $units->count(), 'occupied' => $occupied, 'vacant' => $units->where('status', 'vacant')->count(),
            'works' => $units->where('status', 'works')->count(), 'occupancy' => $units->count() ? round($occupied * 100 / $units->count(), 1) : null,
            'rent_roll' => (float) Lease::where('status', 'active')->get()->sum(fn ($l) => ($l->rent + $l->charges) / max(1, $l->months)),
            'vacant_loss' => (float) $units->where('status', '!=', 'occupied')->sum(fn ($u) => $u->rent + $u->charges),
            'billed' => $billed, 'paid_of_month' => (float) $rows->sum('paid'), 'collected' => $collected,
            'collection_rate' => $billed > 0 ? round($rows->sum('paid') * 100 / $billed, 1) : null,
            'overdue_total' => (float) $overdue->sum('balance'), 'overdue_tenants' => $byTenant->count(), 'overdue' => $byTenant->take(20)->all(),
            'deposits' => (float) Lease::where('status', 'active')->sum('deposit_paid'),
            'ending' => Lease::where('status', 'active')->whereNotNull('end_date')->where('end_date', '<=', now()->addDays(60)->toDateString())->orderBy('end_date')->get()
                ->map(fn ($l) => $this->leaseToApi($l))->values()->all(),
            'revisions' => Lease::where('status', 'active')->where('revision_rate', '>', 0)->whereNotNull('next_revision_date')->where('next_revision_date', '<=', now()->addDays(45)->toDateString())->get()
                ->map(fn ($l) => $this->leaseToApi($l))->values()->all(),
            'months' => $months,
        ];
    }

    public function leaseToApi(Lease $l): array
    {
        $u = Unit::find($l->unit_id);
        $p = $u ? Property::find($u->property_id) : null;
        $t = DB::table('partners')->where('id', $l->tenant_id)->first(['id', 'name', 'phone', 'email']);
        $rents = Rent::where('lease_id', $l->id)->get();
        $ctx = $this->context($rents);
        $api = $rents->map(fn ($r) => $this->rentToApi($r, $ctx));
        $open = $api->filter(fn ($r) => in_array($r['status'], ['unpaid', 'partial'], true) && $r['days_late'] > 0)->values()->all();

        return $l->toArray() + [
            'unit_reference' => $u?->reference, 'property_id' => $p?->id, 'property_name' => $p?->name, 'tenant' => $t ? (array) $t : null,
            'periodicity' => self::PERIODICITY[$l->months] ?? $l->months.' mois', 'monthly' => $l->rent + $l->charges,
            'rents_count' => $rents->count(), 'billed' => (float) $api->sum('total'), 'paid' => (float) $api->sum('paid'), 'overdue' => (float) array_sum(array_column($open, 'balance')),
            'overdue_count' => count($open), 'next_period' => ($n = $l->status === 'active' ? $this->nextPeriod($l) : null) ? $n[0]->toDateString() : null,
            'deposit_due' => max(0, $l->deposit_amount - $l->deposit_paid), 'whatsapp_url' => $open ? $this->whatsappLink($l, $open) : null,
        ];
    }

    // ------------------------------------------------------------------ propriétaires : relevé de gérance

    public function statement(?string $ownerId, string $from, string $to): array
    {
        $props = Property::when($ownerId, fn ($q) => $q->where('owner_id', $ownerId), fn ($q) => $q->whereNull('owner_id'))->orderBy('name')->get();
        $out = [];
        $tot = ['billed' => 0, 'collected' => 0, 'fees' => 0, 'expenses' => 0, 'net' => 0, 'overdue' => 0];
        foreach ($props as $p) {
            $rents = Rent::where('property_id', $p->id)->get();
            $ctx = $this->context($rents);
            $lines = [];
            foreach (Unit::where('property_id', $p->id)->orderBy('reference')->get() as $u) {
                $ur = $rents->where('unit_id', $u->id);
                $ids = $ur->pluck('invoice_id')->filter()->all();
                $billed = (float) $ur->filter(fn ($r) => $r->period_start->toDateString() <= $to && $r->period_end->toDateString() >= $from)->sum('total');
                $collected = $ids ? (float) DB::table('payments')->whereIn('document_id', $ids)->whereNull('deleted_at')->whereBetween('date', [$from, $to])->sum('amount') : 0;
                $overdue = (float) $ur->filter(fn ($r) => $r->due_date->toDateString() <= $to)->map(fn ($r) => $this->rentToApi($r, $ctx))->whereIn('status', ['unpaid', 'partial'])->sum('balance');
                $lease = Lease::where('unit_id', $u->id)->where('start_date', '<=', $to)->orderByDesc('start_date')->first();
                $tenant = $lease ? DB::table('partners')->where('id', $lease->tenant_id)->value('name') : null;
                $lines[] = ['unit' => $u->reference, 'tenant' => $tenant, 'billed' => $billed, 'collected' => $collected, 'overdue' => $overdue];
            }
            $collected = array_sum(array_column($lines, 'collected'));
            $fees = round($collected * $p->fee_rate / 100);
            $expenses = Expense::where('property_id', $p->id)->where('owner_charge', true)->whereBetween('date', [$from, $to])->orderBy('date')->get();
            $exp = (float) $expenses->sum('amount');
            $row = ['id' => $p->id, 'name' => $p->name, 'fee_rate' => $p->fee_rate, 'units' => $lines, 'billed' => array_sum(array_column($lines, 'billed')), 'collected' => $collected,
                'fees' => $fees, 'expenses' => $exp, 'expense_lines' => $expenses->map(fn ($x) => ['date' => $x->date->toDateString(), 'label' => $x->label, 'amount' => $x->amount])->values()->all(),
                'net' => $collected - $fees - $exp, 'overdue' => array_sum(array_column($lines, 'overdue'))];
            foreach (array_keys($tot) as $k) {
                $tot[$k] += $row[$k];
            }
            $out[] = $row;
        }
        $paidOut = $ownerId ? (float) Payout::where('owner_id', $ownerId)->where('period_from', '>=', $from)->where('period_to', '<=', $to)->sum('amount') : 0;
        $owner = $ownerId ? DB::table('partners')->where('company_id', app('tenant')->id)->where('id', $ownerId)->first(['id', 'name', 'phone', 'email']) : null;

        return ['owner' => $owner ? (array) $owner : null, 'from' => $from, 'to' => $to, 'properties' => $out, 'totals' => $tot, 'paid_out' => $paidOut, 'to_pay' => max(0, $tot['net'] - $paidOut),
            'payouts' => $ownerId ? Payout::where('owner_id', $ownerId)->orderByDesc('paid_at')->limit(24)->get()->toArray() : []];
    }

    public function statementPdf(Company $c, array $s): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $m = fn ($v) => self::money((float) $v);
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->setPrintHeader(false);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->AddPage();
        $html = '<table cellpadding="3"><tr><td width="55%"><b style="font-size:12pt">'.$e($c->name).'</b><br>Gestion locative'.($c->phone ? '<br>Tél. '.$e($c->phone) : '').'</td>'
            .'<td width="45%" align="right"><b style="font-size:12pt;color:#1f6feb">RELEVÉ DE GÉRANCE</b><br>'.($s['owner'] ? 'Propriétaire : <b>'.$e($s['owner']['name']).'</b><br>' : 'Biens de la société<br>')
            .'Du '.Carbon::parse($s['from'])->format('d/m/Y').' au '.Carbon::parse($s['to'])->format('d/m/Y').'</td></tr></table><br>';
        $th = '<tr bgcolor="#1f2a44" color="#ffffff" style="font-weight:bold"><td width="22%">Lot</td><td width="30%">Locataire</td><td width="16%" align="right">Appelé</td><td width="16%" align="right">Encaissé</td><td width="16%" align="right">Impayés</td></tr>';
        foreach ($s['properties'] as $p) {
            $html .= '<h4>'.$e($p['name']).($p['fee_rate'] > 0 ? ' · honoraires '.$e(rtrim(rtrim(number_format($p['fee_rate'], 2, ',', ''), '0'), ',')).' %' : '').'</h4><table cellpadding="3" border="0.3">'.$th;
            foreach ($p['units'] as $u) {
                $html .= '<tr><td>'.$e($u['unit']).'</td><td>'.$e($u['tenant'] ?: 'Libre').'</td><td align="right">'.$m($u['billed']).'</td><td align="right">'.$m($u['collected']).'</td><td align="right">'.($u['overdue'] > 0 ? '<span style="color:#b91c1c">'.$m($u['overdue']).'</span>' : '—').'</td></tr>';
            }
            $html .= '<tr bgcolor="#eef2f8" style="font-weight:bold"><td colspan="2">Total</td><td align="right">'.$m($p['billed']).'</td><td align="right">'.$m($p['collected']).'</td><td align="right">'.$m($p['overdue']).'</td></tr></table>';
            if ($p['expense_lines']) {
                $html .= '<p><b>Dépenses à la charge du propriétaire</b></p><table cellpadding="3" border="0.3">';
                foreach ($p['expense_lines'] as $x) {
                    $html .= '<tr><td width="18%">'.Carbon::parse($x['date'])->format('d/m/Y').'</td><td width="62%">'.$e($x['label']).'</td><td width="20%" align="right">'.$m($x['amount']).'</td></tr>';
                }
                $html .= '</table>';
            }
        }
        $t = $s['totals'];
        $html .= '<br><table cellpadding="4" border="0.3" width="60%">'
            .'<tr><td width="65%">Loyers et charges encaissés</td><td width="35%" align="right">'.$m($t['collected']).'</td></tr>'
            .'<tr><td>Honoraires de gérance</td><td align="right">− '.$m($t['fees']).'</td></tr>'
            .'<tr><td>Dépenses et travaux</td><td align="right">− '.$m($t['expenses']).'</td></tr>'
            .'<tr bgcolor="#eef2f8" style="font-weight:bold"><td>Net revenant au propriétaire</td><td align="right">'.$m($t['net']).'</td></tr>'
            .($s['paid_out'] > 0 ? '<tr><td>Déjà reversé sur la période</td><td align="right">− '.$m($s['paid_out']).'</td></tr><tr style="font-weight:bold"><td>Reste à reverser</td><td align="right">'.$m($s['to_pay']).'</td></tr>' : '')
            .'</table><p style="color:#555">Impayés locataires à la fin de la période : '.$m($t['overdue']).' (non compris dans le net : ils seront reversés après encaissement).</p>';
        $pdf->writeHTML($html);

        return $pdf->Output('', 'S');
    }

    public function savePayout(array $d): Payout
    {
        $s = $this->statement($d['owner_id'], $d['period_from'], $d['period_to']);
        $amount = round((float) ($d['amount'] ?? $s['to_pay']), 2);
        if ($amount <= 0) {
            throw new \DomainException('Rien à reverser sur cette période.');
        }

        return Payout::create(['owner_id' => $d['owner_id'], 'period_from' => $d['period_from'], 'period_to' => $d['period_to'], 'collected' => $s['totals']['collected'],
            'fees' => $s['totals']['fees'], 'expenses' => $s['totals']['expenses'], 'amount' => $amount, 'paid_at' => $d['paid_at'] ?? now()->toDateString(),
            'method' => $d['method'] ?? 'bank_transfer', 'reference' => $d['reference'] ?? null, 'notes' => $d['notes'] ?? null]);
    }
}
