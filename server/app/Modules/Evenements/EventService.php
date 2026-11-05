<?php

namespace App\Modules\Evenements;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Core\Models\Partner;
use App\Modules\Core\Models\TradeDocumentLine;
use App\Modules\Evenements\Models\Booking;
use App\Modules\Evenements\Models\Event;
use App\Modules\Evenements\Models\Package;
use App\Modules\Evenements\Models\Space;
use App\Modules\Ventes\Models\SalesDocument;
use App\Services\EntityWriter;
use Illuminate\Support\Str;

class EventService
{
    // ------------------------------------------------------------------ facturation

    public function afterBookingWrite(Booking $b, bool $creating): void
    {
        $b->refresh();
        if ($b->status === 'confirmed' && $b->deposit_invoice_id && ! $b->balance_invoice_id && SalesDocument::find($b->deposit_invoice_id)?->status === 'cancelled') {
            $b->forceFill(['deposit_invoice_id' => null])->save(); // acompte en ligne annulé (option expirée) : nouvel acompte
        }
        if ($b->status === 'confirmed' && ! $b->deposit_invoice_id && ! $b->balance_invoice_id) {
            $this->invoiceOnConfirm($b);
        }
        if ($b->status === 'cancelled') {
            $this->cancelInvoices($b);
        }
        // Quantités vendues des offres : resynchronisées
        foreach (Package::where('event_id', $b->event_id)->get() as $p) {
            $p->touch();
        }
    }

    protected function currency(Event $e): string
    {
        return $e->currency ?: (app('tenant')->currency ?? 'XOF');
    }

    /** Lignes détaillées : emplacements puis offres. */
    public function detailLines(Booking $b, Event $e): array
    {
        $lines = [];
        foreach (Space::whereIn('id', $b->space_ids ?? [])->orderBy('code')->get() as $s) {
            $lines[] = ['description' => trim('Emplacement '.$s->code.($s->category ? ' — '.$s->category : '').($s->area ? ' ('.rtrim(rtrim(number_format($s->area, 2, ',', ''), '0'), ',').' m²)' : '').' — '.$e->name),
                'quantity' => 1, 'unit' => 'stand', 'unit_price' => (float) $s->price];
        }
        foreach ($b->items ?? [] as $it) {
            $lines[] = ['description' => $it['name'].' — '.$e->name, 'quantity' => $it['quantity'], 'unit_price' => (float) $it['unit_price']];
        }

        return array_map(fn ($l) => $l + ['id' => (string) Str::uuid(), 'tax_rate' => (float) $e->tax_rate, 'discount_percent' => (float) $b->discount_percent], $lines);
    }

    protected function createInvoice(Booking $b, Event $e, array $lines, string $note): string
    {
        $id = (string) Str::uuid();
        $data = [
            'type' => 'invoice', 'partner_id' => $b->partner_id, 'date' => now()->toDateString(), 'reference' => $b->number,
            'currency' => $this->currency($e), 'status' => 'validated', 'lines' => $lines,
            'notes' => $e->name.' — '.$e->start_date->format('d/m/Y').($e->end_date->ne($e->start_date) ? ' au '.$e->end_date->format('d/m/Y') : '').($e->venue ? ', '.$e->venue : '')."\n".$note.($e->terms ? "\n".$e->terms : ''),
        ];
        SalesDocument::$systemWrite = true;
        try {
            $res = app(EntityWriter::class)->upsert(SalesDocument::class, $id, $data);
        } finally {
            SalesDocument::$systemWrite = false;
        }
        if ($res['status'] !== EntityWriter::APPLIED) {
            throw new \DomainException('Facture refusée : '.($res['message'] ?? ''));
        }

        return $id;
    }

    /** À la confirmation : acompte (ou facture complète si pas d'acompte). */
    public function invoiceOnConfirm(Booking $b): void
    {
        $e = $b->event;
        $a = $b->amounts($e);
        if ($a['total_ht'] <= 0) {
            return;
        }
        $pct = (float) $e->deposit_percent;
        if ($pct > 0 && $pct < 100) {
            $dec = TradeDocumentLine::decimalsFor($this->currency($e));
            $amount = round($a['total_ht'] * $pct / 100, $dec);
            $id = $this->createInvoice($b, $e, [[
                'id' => (string) Str::uuid(), 'description' => 'Acompte '.rtrim(rtrim(number_format($pct, 2, ',', ''), '0'), ',').' % — réservation '.$b->number.' — '.$e->name,
                'quantity' => 1, 'unit_price' => $amount, 'tax_rate' => (float) $e->tax_rate, 'discount_percent' => 0,
            ]], 'Acompte à la réservation '.$b->number.'. Solde facturé '.$e->balance_days.' jours avant l\'ouverture.');
            $b->forceFill(['deposit_invoice_id' => $id, 'last_error' => null])->save();
        } else {
            $id = $this->createInvoice($b, $e, $this->detailLines($b, $e), 'Réservation '.$b->number.'.');
            $b->forceFill(['balance_invoice_id' => $id, 'last_error' => null])->save();
        }
    }

    /** Acompte payé en ligne : l'option devient une réservation confirmée. */
    public function onDepositPaid(Booking $b): void
    {
        if (in_array($b->status, ['option', 'expired'], true)) {
            Booking::$systemWrite = true;
            try {
                $res = app(EntityWriter::class)->upsert(Booking::class, $b->id, ['status' => 'confirmed']);
            } finally {
                Booking::$systemWrite = false;
            }
            if ($res['status'] === EntityWriter::APPLIED) {
                $this->notifyStaff($b->fresh(), 'Acompte payé en ligne, réservation confirmée', false);
            } else {
                $b->forceFill(['last_error' => mb_substr('Acompte payé mais confirmation impossible : '.$res['message'], 0, 255)])->save();
                $this->notifyStaff($b->fresh(), 'Acompte payé : confirmation à vérifier', false);
            }
        }
    }

    public function payUrl(Booking $b): ?string
    {
        $doc = $b->deposit_invoice_id ? SalesDocument::find($b->deposit_invoice_id) : null;

        return $doc && $doc->status === 'validated' && $doc->payment_status !== 'paid' ? \App\Payments\InvoicePayments::url(app(\App\Payments\InvoicePayments::class)->link($doc)) : null;
    }

    /** Facture de solde : détail complet moins l'acompte facturé. */
    public function invoiceBalance(Booking $b): SalesDocument
    {
        if ($b->status !== 'confirmed') {
            throw new \DomainException('Seule une réservation confirmée se facture.');
        }
        if ($b->balance_invoice_id) {
            throw new \DomainException('Le solde est déjà facturé.');
        }
        $e = $b->event;
        $lines = $this->detailLines($b, $e);
        $dep = $b->deposit_invoice_id ? SalesDocument::find($b->deposit_invoice_id) : null;
        if ($dep && $dep->status !== 'cancelled') {
            $lines[] = ['id' => (string) Str::uuid(), 'description' => 'Acompte déjà facturé ('.$dep->number.')', 'quantity' => -1, 'unit_price' => (float) $dep->subtotal,
                'tax_rate' => (float) $e->tax_rate, 'discount_percent' => 0];
        }
        $id = $this->createInvoice($b, $e, $lines, 'Solde de la réservation '.$b->number.'.');
        $b->forceFill(['balance_invoice_id' => $id, 'last_error' => null])->save();

        return SalesDocument::find($id);
    }

    public function assertCancellable(Booking $b): void
    {
        foreach (SalesDocument::whereIn('id', array_filter([$b->deposit_invoice_id, $b->balance_invoice_id]))->get() as $d) {
            if ($d->status !== 'cancelled' && (float) $d->amount_paid > 0) {
                throw new \DomainException('La facture '.$d->number.' a reçu des paiements : établissez un avoir avant d\'annuler la réservation.');
            }
        }
    }

    protected function cancelInvoices(Booking $b): void
    {
        foreach (SalesDocument::whereIn('id', array_filter([$b->deposit_invoice_id, $b->balance_invoice_id]))->get() as $d) {
            if (in_array($d->status, ['validated', 'draft'], true) && (float) $d->amount_paid == 0.0) {
                SalesDocument::$systemWrite = true;
                try {
                    $res = app(EntityWriter::class)->upsert(SalesDocument::class, $d->id, ['status' => 'cancelled']);
                } finally {
                    SalesDocument::$systemWrite = false;
                }
                if ($res['status'] !== EntityWriter::APPLIED) {
                    throw new \DomainException('Annulation de la facture '.$d->number.' impossible : '.($res['message'] ?? ''));
                }
            }
        }
    }

    // ------------------------------------------------------------------ tâches quotidiennes

    /** Options expirées, soldes à facturer, options qui expirent demain. */
    public function runDaily(Company $company, ?string $today = null): array
    {
        $today ??= now()->toDateString();
        $out = ['expired' => 0, 'balances' => 0, 'errors' => 0];
        foreach (Booking::where('status', 'option')->whereNotNull('option_until')->where('option_until', '<', $today)->get() as $b) {
            $b->status = 'expired';
            $b->save();
            $this->cancelInvoices($b); // acompte en ligne non payé
            $out['expired']++;
            $this->notifyStaff($b, 'Option expirée, emplacements libérés', false);
        }
        foreach (Booking::where('status', 'option')->where('option_until', now()->parse($today)->addDay()->toDateString())->get() as $b) {
            $this->notifyStaff($b, 'Option à confirmer avant demain', false);
        }
        $events = Event::whereIn('status', ['open', 'closed'])->get()->filter(fn ($e) => $e->start_date->copy()->subDays($e->balance_days)->toDateString() <= $today && $e->end_date->toDateString() >= $today);
        foreach ($events as $e) {
            foreach (Booking::where('event_id', $e->id)->where('status', 'confirmed')->whereNotNull('deposit_invoice_id')->whereNull('balance_invoice_id')->get() as $b) {
                try {
                    $this->invoiceBalance($b);
                    $out['balances']++;
                } catch (\DomainException $ex) {
                    $b->forceFill(['last_error' => mb_substr($ex->getMessage(), 0, 255)])->save();
                    $out['errors']++;
                }
            }
        }

        return $out;
    }

    public function notifyStaff(Booking $b, string $title, bool $excludeActor = true): void
    {
        $company = app('tenant');
        $users = User::where('company_id', $company->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('evenements.manage') || $u->hasPermission('evenements.sell'))->pluck('id');
        $e = $b->event;
        AppNotification::send($company->id, $users, 'event_booking', $title.' : '.($b->exhibitor_name ?: $b->partner_name),
            trim($b->number.' · '.($e?->name ?? '').' · '.$this->spaceCodes($b)), 'evenements', $b->id, $excludeActor ? auth()->id() : null);
    }

    public function spaceCodes(Booking $b): string
    {
        return Space::whereIn('id', $b->space_ids ?? [])->orderBy('code')->pluck('code')->implode(', ');
    }

    // ------------------------------------------------------------------ statistiques

    public function stats(Event $e): array
    {
        $spaces = Space::where('event_id', $e->id)->get();
        $bookings = Booking::where('event_id', $e->id)->get();
        $active = $bookings->whereIn('status', Booking::ACTIVE);
        $taken = [];
        foreach ($active as $b) {
            foreach ($b->space_ids ?? [] as $sid) {
                $taken[$sid] = $b->status;
            }
        }
        $sellable = $spaces->where('blocked', false);
        $byCat = [];
        foreach ($sellable as $s) {
            $k = $s->category ?: 'Sans catégorie';
            $byCat[$k] ??= ['category' => $k, 'total' => 0, 'confirmed' => 0, 'option' => 0, 'free' => 0, 'revenue' => 0.0];
            $byCat[$k]['total']++;
            $st = $taken[$s->id] ?? 'free';
            $byCat[$k][$st]++;
            if ($st === 'confirmed') {
                $byCat[$k]['revenue'] += (float) $s->price;
            }
        }
        $sum = fn ($set) => round($set->sum(fn ($b) => $b->amounts($e)['total_ht']), 2);
        $confirmed = $active->where('status', 'confirmed');
        $invoiced = 0.0;
        $paid = 0.0;
        foreach ($confirmed as $b) {
            foreach ($b->invoices() as $i) {
                if ($i['status'] !== 'cancelled') {
                    $invoiced += $i['total'];
                    $paid += $i['amount_paid'];
                }
            }
        }
        $area = (float) $sellable->sum('area');
        $areaTaken = (float) $sellable->filter(fn ($s) => isset($taken[$s->id]))->sum('area');
        $packages = Package::where('event_id', $e->id)->orderBy('sort')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'kind' => $p->kind, 'quantity' => $p->quantity, 'sold' => $p->sold()]);

        return [
            'spaces' => $sellable->count(), 'blocked' => $spaces->count() - $sellable->count(),
            'confirmed_spaces' => $sellable->filter(fn ($s) => ($taken[$s->id] ?? null) === 'confirmed')->count(),
            'option_spaces' => $sellable->filter(fn ($s) => ($taken[$s->id] ?? null) === 'option')->count(),
            'free_spaces' => $sellable->filter(fn ($s) => ! isset($taken[$s->id]))->count(),
            'occupancy' => $sellable->count() ? round(100 * $sellable->filter(fn ($s) => isset($taken[$s->id]))->count() / $sellable->count(), 1) : 0,
            'area' => round($area, 2), 'area_taken' => round($areaTaken, 2),
            'exhibitors' => $confirmed->pluck('partner_id')->unique()->count(), 'checked_in' => $confirmed->whereNotNull('checked_in_at')->count(),
            'revenue_confirmed' => $sum($confirmed), 'revenue_options' => $sum($active->where('status', 'option')),
            'invoiced' => round($invoiced, 2), 'paid' => round($paid, 2),
            'options_expiring' => $active->where('status', 'option')->filter(fn ($b) => $b->option_until && $b->option_until->lte(now()->addDays(2)))->count(),
            'by_category' => array_values($byCat), 'packages' => $packages,
            'currency' => $this->currency($e),
        ];
    }

    /**
     * Plan des stands avec l'état de chacun : libre, bloqué, option, réservé (confirmé, non soldé), payé.
     */
    public function planState(Event $e): array
    {
        $bookings = Booking::where('event_id', $e->id)->whereIn('status', Booking::ACTIVE)->get();
        $byspace = [];
        foreach ($bookings as $b) {
            foreach ($b->space_ids ?? [] as $sid) {
                $byspace[$sid] = $b;
            }
        }
        $pay = [];
        foreach ($bookings as $b) {
            $inv = collect($b->invoices())->where('status', '!=', 'cancelled');
            $total = $b->amounts($e)['total'];
            $paid = (float) $inv->sum('amount_paid');
            $pay[$b->id] = ['paid' => round($paid, 2), 'total' => $total, 'state' => $paid <= 0 ? 'unpaid' : ($paid + 0.5 >= $total ? 'paid' : 'partial')];
        }
        $rows = [];
        $count = ['free' => 0, 'blocked' => 0, 'option' => 0, 'reserved' => 0, 'partial' => 0, 'paid' => 0];
        foreach (Space::where('event_id', $e->id)->orderBy('code')->get() as $s) {
            $b = $byspace[$s->id] ?? null;
            $state = match (true) {
                $s->blocked => 'blocked',
                ! $b => 'free',
                $b->status === 'option' => 'option',
                ($pay[$b->id]['state'] ?? '') === 'paid' => 'paid',
                ($pay[$b->id]['state'] ?? '') === 'partial' => 'partial',
                default => 'reserved',
            };
            $count[$state]++;
            $rows[] = ['id' => $s->id, 'code' => $s->code, 'category' => $s->category, 'zone' => $s->zone, 'area' => $s->area, 'width_m' => $s->width_m, 'depth_m' => $s->depth_m,
                'price' => $s->price, 'x' => $s->x, 'y' => $s->y, 'w' => $s->w, 'h' => $s->h, 'state' => $state,
                'booking_id' => $b?->id, 'exhibitor' => $b?->exhibitor_name, 'option_until' => $b?->option_until?->toDateString(),
                'paid' => $b ? ($pay[$b->id]['paid'] ?? 0) : null, 'total' => $b ? ($pay[$b->id]['total'] ?? 0) : null];
        }

        return ['spaces' => $rows, 'counts' => $count, 'cols' => $e->plan_cols, 'rows' => $e->plan_rows, 'currency' => $this->currency($e)];
    }

    /** Règlement reçu hors ligne (espèces, virement, Mobile Money) : solde des factures de la réservation. */
    public function markPaid(Booking $b, string $method, ?string $reference = null): float
    {
        if ($b->status !== 'confirmed') {
            throw new \DomainException('Confirmez d\'abord la réservation : ses factures sont alors émises.');
        }
        $e = $b->event ?? Event::find($b->event_id);
        if (! $b->balance_invoice_id && $b->deposit_invoice_id) {
            $this->invoiceBalance($b->fresh());
            $b = $b->fresh();
        }
        $done = 0.0;
        foreach ($b->invoices() as $i) {
            if ($i['status'] !== 'validated' || $i['total'] - $i['amount_paid'] < 0.01) {
                continue;
            }
            $amount = round($i['total'] - $i['amount_paid'], 2);
            $r = app(\App\Services\EntityWriter::class)->upsert(\App\Modules\Ventes\Models\SalesPayment::class, (string) \Illuminate\Support\Str::uuid(), [
                'document_id' => $i['id'], 'date' => now()->toDateString(), 'amount' => $amount, 'method' => $method, 'reference' => $reference ?: 'Stand '.$this->spaceCodes($b),
            ]);
            if ($r['status'] !== \App\Services\EntityWriter::APPLIED) {
                throw new \DomainException($r['message'] ?? 'Encaissement refusé.');
            }
            $done += $amount;
        }
        if ($done <= 0) {
            throw new \DomainException('Rien à encaisser : la réservation est déjà soldée.');
        }

        return round($done, 2);
    }

    // ------------------------------------------------------------------ espace exposant / sponsor

    public const PROFILE_FIELDS = ['tagline', 'description', 'website', 'email', 'phone', 'whatsapp', 'address', 'facebook', 'instagram', 'linkedin', 'tiktok', 'youtube', 'products', 'offer', 'sector'];

    public function saveProfile(Booking $b, array $d, ?\Illuminate\Http\UploadedFile $logo = null, ?bool $publish = null): Booking
    {
        $p = (array) ($b->profile ?? []);
        foreach (self::PROFILE_FIELDS as $k) {
            if (array_key_exists($k, $d)) {
                $v = trim((string) $d[$k]);
                $p[$k] = $v === '' ? null : \Illuminate\Support\Str::limit($v, in_array($k, ['description', 'products'], true) ? 4000 : 300, '');
            }
        }
        foreach (['website', 'facebook', 'instagram', 'linkedin', 'tiktok', 'youtube'] as $k) {
            if (! empty($p[$k]) && ! preg_match('#^https?://#i', $p[$k])) {
                $p[$k] = 'https://'.ltrim($p[$k], '/');
            }
        }
        $b->forceFill(['profile' => array_filter($p, fn ($v) => $v !== null)]);
        if ($logo) {
            $ext = strtolower($logo->getClientOriginalExtension());
            if (! in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true) || $logo->getSize() > 2 * 1024 * 1024) {
                throw new \DomainException('Logo : image PNG, JPG ou WEBP de 2 Mo au plus.');
            }
            $path = 'events/'.$b->company_id.'/logos/'.$b->id.'.'.$ext;
            \Illuminate\Support\Facades\Storage::disk('public')->put($path, (string) file_get_contents($logo->getRealPath()));
            $b->forceFill(['logo_path' => $path]);
        }
        if ($publish !== null) {
            $b->forceFill(['profile_published' => $publish]);
        }
        $b->saveQuietly();

        return $b;
    }

    public function logoUrl(Booking $b): ?string
    {
        return $b->logo_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($b->logo_path).'?v='.($b->updated_at?->timestamp ?? 0) : null;
    }

    /** Annuaire public : sponsors par formule, puis exposants (fiches publiées des réservations confirmées). */
    public function directory(Event $e): array
    {
        $packages = Package::where('event_id', $e->id)->get()->keyBy('id');
        $out = ['sponsors' => [], 'exhibitors' => []];
        foreach (Booking::where('event_id', $e->id)->where('status', 'confirmed')->where('profile_published', true)->orderBy('exhibitor_name')->get() as $b) {
            $sponsor = collect($b->items ?? [])->map(fn ($i) => $packages[$i['package_id'] ?? ''] ?? null)->filter(fn ($p) => $p && $p->kind === 'sponsor')->sortByDesc('price')->first();
            $row = ['booking' => $b, 'profile' => (array) $b->profile, 'logo' => $this->logoUrl($b), 'stands' => $this->spaceCodes($b), 'tier' => $sponsor?->name, 'tier_price' => (float) ($sponsor?->price ?? 0)];
            $sponsor ? $out['sponsors'][] = $row : $out['exhibitors'][] = $row;
        }
        usort($out['sponsors'], fn ($a, $b) => $b['tier_price'] <=> $a['tier_price']);

        return $out;
    }

    // ------------------------------------------------------------------ page publique

    public function findOrCreatePartner(array $c): Partner
    {
        $digits = preg_replace('/\D/', '', (string) ($c['phone'] ?? ''));
        $p = null;
        if (! empty($c['email'])) {
            $p = Partner::whereRaw('lower(email) = ?', [mb_strtolower($c['email'])])->first();
        }
        if (! $p && strlen($digits) >= 8) {
            $p = Partner::where('phone', 'like', '%'.implode('%', str_split(substr($digits, -6))))->get()
                ->first(fn ($x) => substr(preg_replace('/\D/', '', (string) $x->phone), -8) === substr($digits, -8));
        }

        return $p ?? Partner::create(['name' => $c['company'], 'type' => 'customer', 'phone' => $c['phone'] ?? null, 'email' => $c['email'] ?? null,
            'notes' => 'Créé par une demande de stand en ligne']);
    }

    /** Demande d'un exposant depuis la page publique : option sur les emplacements choisis. */
    public function publicRequest(Event $e, array $d): Booking
    {
        if ($e->status !== 'open' || ! $e->public_enabled) {
            throw new \DomainException('Les réservations en ligne sont fermées pour cet événement.');
        }
        $partner = $this->findOrCreatePartner($d);
        $items = [];
        foreach ($d['packages'] ?? [] as $pid => $q) {
            if ((int) $q > 0) {
                $p = Package::where('event_id', $e->id)->where('active', true)->where('online', true)->find($pid);
                if ($p) {
                    $items[] = ['package_id' => $p->id, 'quantity' => min(50, (int) $q)];
                }
            }
        }
        $id = (string) Str::uuid();
        Booking::$systemWrite = true;
        try {
            $res = app(EntityWriter::class)->upsert(Booking::class, $id, [
                'event_id' => $e->id, 'partner_id' => $partner->id, 'status' => 'option', 'space_ids' => array_values($d['spaces'] ?? []), 'items' => $items,
                'exhibitor_name' => $d['company'], 'contact_name' => $d['name'], 'contact_phone' => $d['phone'], 'contact_email' => $d['email'] ?? null,
                'notes' => trim('Demande en ligne. '.($d['message'] ?? '')),
            ]);
        } finally {
            Booking::$systemWrite = false;
        }
        if ($res['status'] !== EntityWriter::APPLIED) {
            throw new \DomainException($res['message'] === 'Données invalides.' ? 'Vérifiez votre demande.' : $res['message']);
        }
        $b = Booking::find($id);
        $b->forceFill(['source' => 'online'])->saveQuietly();
        if ($e->online_deposit && $b->amounts($e)['total_ht'] > 0) {
            $this->invoiceOnConfirm($b); // acompte payable en ligne tout de suite ; confirmation au paiement
        }
        $this->notifyStaff($b, 'Nouvelle demande de stand en ligne', false);

        return $b->fresh();
    }
}
