<?php

namespace App\Modules\Fournisseurs;

use App\Messaging\Message as Mail;
use App\Messaging\MessagingService;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Achats\Models\PurchaseDocument;
use App\Modules\Core\Models\Partner;
use App\Modules\Fournisseurs\Models\Access;
use App\Modules\Fournisseurs\Models\Bid;
use App\Modules\Fournisseurs\Models\OrderAck;
use App\Modules\Fournisseurs\Models\SupplierInvoice;
use App\Modules\Fournisseurs\Models\Tender;
use App\Services\EntityWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FournisseursService
{
    public const INVOICE_EXT = ['pdf', 'png', 'jpg', 'jpeg', 'webp'];

    // ------------------------------------------------------------------ accès

    public function access(Partner $p): Access
    {
        $a = Access::where('partner_id', $p->id)->first();
        if (! $a) {
            $a = Access::create(['partner_id' => $p->id, 'token' => Str::random(40), 'email' => $p->email, 'invited_by' => auth()->user()?->name]);
        } elseif (! $a->active) {
            $a->forceFill(['active' => true, 'token' => Str::random(40)])->save();
        }

        return $a;
    }

    /** Envoie le lien du portail au fournisseur ; renvoie les canaux utilisés. */
    public function invite(Company $c, Partner $p, ?string $intro = null): array
    {
        $a = $this->access($p);

        return $this->notifySupplier($c, $p, 'Votre espace fournisseur — '.$c->name,
            ($intro ?? "Bonjour,\n\n{$c->name} vous ouvre un espace fournisseur : appels d'offres, commandes à confirmer, dépôt de vos factures et suivi des paiements.")."\n\nVotre lien personnel : ".$a->url()."\n\n{$c->name}");
    }

    public function notifySupplier(Company $c, Partner $p, string $subject, string $text): array
    {
        $messaging = app(MessagingService::class);
        $avail = $messaging->available($c);
        $used = [];
        if ($p->email && ($avail['email'] ?? null)) {
            try {
                if ($messaging->send($c, 'email', new Mail(to: $p->email, subject: $subject, html: nl2br(e($text)), text: $text, toName: $p->name), 'portail_fournisseurs')->ok) {
                    $used[] = 'e-mail';
                }
            } catch (\Throwable) {
            }
        }
        if ($p->phone && ($avail['whatsapp'] ?? null)) {
            try {
                if ($messaging->send($c, 'whatsapp', new Mail(to: $p->phone, text: $text, toName: $p->name), 'portail_fournisseurs')->ok) {
                    $used[] = 'WhatsApp';
                }
            } catch (\Throwable) {
            }
        }

        return $used;
    }

    protected function buyers(Company $c)
    {
        return User::where('company_id', $c->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('portail_fournisseurs.manage') || $u->hasPermission('achats.manage'))->pluck('id');
    }

    // ------------------------------------------------------------------ appels d'offres

    public function afterTenderWrite(Tender $t): void
    {
        $c = app('tenant');
        if ($t->fromStatus === 'draft' && $t->status === 'open') {
            foreach (Partner::whereIn('id', $t->invited ?? [])->get() as $p) {
                $a = $this->access($p);
                $this->notifySupplier($c, $p, 'Appel d\'offres '.$t->number.' : '.$t->title,
                    "Bonjour,\n\n{$c->name} vous invite à répondre à l'appel d'offres « {$t->title} » (".count($t->lines)." article(s)).\nDate limite : ".$t->deadline->format('d/m/Y à H:i')
                    .".\n\nConsulter et déposer votre offre : ".$a->url().'/ao/'.$t->id."\n\n{$c->name}");
            }
        }
        if ($t->fromStatus === 'open' && $t->status === 'cancelled') {
            foreach (Partner::whereIn('id', Bid::where('tender_id', $t->id)->pluck('partner_id'))->get() as $p) {
                $this->notifySupplier($c, $p, 'Appel d\'offres '.$t->number.' annulé', "Bonjour,\n\nL'appel d'offres « {$t->title} » est annulé. Merci pour votre offre.\n\n{$c->name}");
            }
        }
    }

    /** Dépôt ou révision d'une offre par le fournisseur, jusqu'à la date limite. */
    public function submitBid(Company $c, Tender $t, Partner $p, array $prices, array $data): Bid
    {
        if (! $t->isOpen()) {
            throw new \DomainException($t->status === 'open' ? 'La date limite est passée : les offres sont closes.' : 'Cet appel d\'offres n\'accepte plus d\'offres.');
        }
        if (! in_array($p->id, $t->invited ?? [], true)) {
            throw new \DomainException('Vous n\'êtes pas invité à cet appel d\'offres.');
        }
        $lines = [];
        $total = 0;
        foreach ($t->lines as $l) {
            $raw = $prices[$l['id']] ?? null;
            $price = $raw === null || $raw === '' ? null : (float) str_replace([' ', ' ', ','], ['', '', '.'], (string) $raw);
            if ($price !== null && $price < 0) {
                throw new \DomainException('Prix négatif refusé.');
            }
            $lines[] = ['line_id' => $l['id'], 'unit_price' => $price, 'available' => $price !== null, 'note' => isset($data['notes'][$l['id']]) ? Str::limit((string) $data['notes'][$l['id']], 200, '') : null];
            $total += $price !== null ? $price * $l['quantity'] : 0;
        }
        if (! array_filter($lines, fn ($l) => $l['available'])) {
            throw new \DomainException('Indiquez au moins un prix.');
        }
        $bid = Bid::where('tender_id', $t->id)->where('partner_id', $p->id)->first() ?? new Bid;
        $new = ! $bid->exists;
        $bid->forceFill(['tender_id' => $t->id, 'partner_id' => $p->id, 'partner_name' => $p->name, 'lines' => $lines, 'total' => round($total, 2),
            'delivery_days' => isset($data['delivery_days']) && $data['delivery_days'] !== '' ? max(0, (int) $data['delivery_days']) : null,
            'valid_until' => $data['valid_until'] ?? null, 'comment' => isset($data['comment']) ? Str::limit((string) $data['comment'], 3000, '') : null,
            'status' => 'submitted', 'submitted_at' => now(), 'revisions' => $new ? 0 : $bid->revisions + 1]);
        $bid->save();
        AppNotification::send($c->id, $this->buyers($c), 'fournisseurs', ($new ? 'Nouvelle offre' : 'Offre révisée').' : '.$p->name, $t->number.' · '.$t->title.' · '.number_format($total, 0, ',', ' ').' '.$t->currency, 'portail_fournisseurs', $t->id);

        return $bid;
    }

    /** Comparaison : meilleur prix par ligne, total des offres complètes. */
    public function compare(Tender $t): array
    {
        $bids = Bid::where('tender_id', $t->id)->get();
        $rows = [];
        foreach ($t->lines as $l) {
            $prices = [];
            foreach ($bids as $b) {
                $bl = collect($b->lines)->firstWhere('line_id', $l['id']);
                $prices[$b->partner_id] = $bl['unit_price'] ?? null;
            }
            $valid = array_filter($prices, fn ($v) => $v !== null);
            $rows[] = ['line' => $l, 'prices' => $prices, 'best' => $valid ? array_keys($valid, min($valid))[0] : null];
        }

        return ['bids' => $bids->map(fn ($b) => $b->toSyncArray() + ['complete' => collect($b->lines)->every(fn ($x) => $x['available'])])->values(), 'rows' => $rows,
            'cheapest' => $bids->filter(fn ($b) => collect($b->lines)->every(fn ($x) => $x['available']))->sortBy('total')->first()?->partner_id];
    }

    /** Attribution : bon de commande (brouillon) au fournisseur retenu, avec ses prix ; les autres sont prévenus. */
    public function award(Company $c, Tender $t, string $partnerId): PurchaseDocument
    {
        if (! in_array($t->status, ['open', 'closed'], true)) {
            throw new \DomainException('Cet appel d\'offres ne peut plus être attribué.');
        }
        $bid = Bid::where('tender_id', $t->id)->where('partner_id', $partnerId)->first();
        if (! $bid) {
            throw new \DomainException('Ce fournisseur n\'a pas déposé d\'offre.');
        }
        $taxRate = (float) ($c->settings['tax_rate'] ?? 18);
        $lines = [];
        foreach ($t->lines as $i => $l) {
            $bl = collect($bid->lines)->firstWhere('line_id', $l['id']);
            if (! ($bl['available'] ?? false)) {
                continue;
            }
            $lines[] = array_filter(['id' => (string) Str::uuid(), 'product_id' => $l['product_id'] ?? null, 'description' => $l['description'], 'quantity' => $l['quantity'], 'unit' => $l['unit'] ?? null,
                'unit_price' => (float) $bl['unit_price'], 'tax_rate' => $taxRate, 'discount_percent' => 0], fn ($v) => $v !== null);
        }
        $id = (string) Str::uuid();
        $res = app(EntityWriter::class)->upsert(PurchaseDocument::class, $id, array_filter(['type' => 'purchase_order', 'partner_id' => $partnerId, 'date' => now()->toDateString(), 'reference' => $t->number,
            'project_id' => $t->project_id, 'currency' => $t->currency, 'notes' => 'Attribué suite à l\'appel d\'offres '.$t->number.' « '.$t->title.' »'.($bid->delivery_days !== null ? ' · livraison sous '.$bid->delivery_days.' jour(s)' : ''), 'lines' => $lines], fn ($v) => $v !== null));
        if ($res['status'] !== EntityWriter::APPLIED) {
            throw new \DomainException('Bon de commande refusé : '.($res['message'] ?? '').' '.json_encode($res['errors'] ?? []));
        }
        $t->forceFill(['status' => 'awarded', 'awarded_partner_id' => $partnerId, 'order_id' => $id])->save();
        foreach (Bid::where('tender_id', $t->id)->get() as $b) {
            $b->forceFill(['status' => $b->partner_id === $partnerId ? 'awarded' : 'lost'])->save();
            $p = Partner::find($b->partner_id);
            if ($p) {
                $won = $b->partner_id === $partnerId;
                $this->notifySupplier($c, $p, 'Appel d\'offres '.$t->number.' : '.($won ? 'offre retenue' : 'résultat'),
                    "Bonjour,\n\n".($won ? "Votre offre pour « {$t->title} » est retenue. Le bon de commande vous sera transmis sur votre espace fournisseur."
                        : "Votre offre pour « {$t->title} » n'a pas été retenue cette fois-ci. Merci pour votre participation.")."\n\n{$c->name}");
            }
        }

        return PurchaseDocument::find($id);
    }

    public function closeExpired(Company $c): int
    {
        $n = 0;
        foreach (Tender::where('status', 'open')->where('deadline', '<', now())->get() as $t) {
            $t->forceFill(['status' => 'closed'])->save();
            $count = Bid::where('tender_id', $t->id)->count();
            AppNotification::send($c->id, $this->buyers($c), 'fournisseurs', 'Appel d\'offres clos : '.$t->number, $t->title.' · '.$count.' offre(s) à comparer', 'portail_fournisseurs', $t->id);
            $n++;
        }

        return $n;
    }

    // ------------------------------------------------------------------ commandes

    /** Bons de commande visibles par le fournisseur : validés, non annulés. */
    public function orders(Partner $p)
    {
        return PurchaseDocument::where('type', 'purchase_order')->where('partner_id', $p->id)->where('status', 'validated')->orderByDesc('date')->limit(50)->get();
    }

    public function acknowledge(Company $c, PurchaseDocument $o, Partner $p, bool $accept, ?string $date, ?string $comment, ?string $by): OrderAck
    {
        if ($o->partner_id !== $p->id || $o->type !== 'purchase_order' || $o->status !== 'validated') {
            throw new \DomainException('Commande introuvable.');
        }
        if ($accept && (! $date || $date < now()->toDateString())) {
            throw new \DomainException('Indiquez la date de livraison prévue (aujourd\'hui ou plus tard).');
        }
        if (! $accept && trim((string) $comment) === '') {
            throw new \DomainException('Indiquez la raison du refus.');
        }
        $ack = new OrderAck;
        $ack->forceFill(['order_id' => $o->id, 'partner_id' => $p->id, 'status' => $accept ? 'accepted' : 'declined', 'delivery_date' => $accept ? $date : null,
            'comment' => $comment ? Str::limit(trim($comment), 2000, '') : null, 'by_name' => $by ? Str::limit($by, 120, '') : null]);
        $ack->save();
        AppNotification::send($c->id, $this->buyers($c), 'fournisseurs', ($accept ? 'Commande confirmée' : 'Commande refusée').' par '.$p->name,
            $o->number.($accept ? ' · livraison le '.\Carbon\Carbon::parse($date)->format('d/m/Y') : ' · '.$comment), 'portail_fournisseurs', $o->id);

        return $ack;
    }

    // ------------------------------------------------------------------ factures

    public function depositInvoice(Company $c, Partner $p, array $d, UploadedFile $file, string $source = 'portal', ?string $by = null): SupplierInvoice
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, self::INVOICE_EXT, true)) {
            throw new \DomainException('Déposez la facture en PDF ou en image (JPG, PNG).');
        }
        $data = (string) file_get_contents($file->getRealPath());
        // Lecture automatique : sert à contrôler ce que le fournisseur a saisi (ou à pré-remplir côté acheteur)
        $read = rescue(fn () => app(InvoiceReader::class)->read($data, $ext), [], false);
        $d['number'] = trim((string) ($d['number'] ?? '')) ?: ($read['number'] ?? '');
        $d['date'] = ($d['date'] ?? null) ?: ($read['date'] ?? null);
        $d['amount'] = ($d['amount'] ?? null) ?: ($read['total'] ?? null);
        $d['order_id'] = ($d['order_id'] ?? null) ?: ($source === 'upload' && ($read['partner_id'] ?? null) === $p->id ? ($read['order_id'] ?? null) : null);
        if ($d['number'] === '' || ! $d['date'] || ! $d['amount']) {
            throw new \DomainException('N° de facture, date et montant TTC sont nécessaires (non trouvés dans le document).');
        }
        $order = ! empty($d['order_id']) ? PurchaseDocument::where('type', 'purchase_order')->where('partner_id', $p->id)->where('status', 'validated')->find($d['order_id']) : null;
        if (! empty($d['order_id']) && ! $order) {
            throw new \DomainException('Bon de commande introuvable.');
        }
        if (SupplierInvoice::where('partner_id', $p->id)->where('number', trim($d['number']))->where('status', '!=', 'rejected')->exists()) {
            throw new \DomainException(($source === 'portal' ? 'Vous avez' : $p->name.' a').' déjà déposé la facture n° '.trim($d['number']).'.');
        }
        $inv = new SupplierInvoice;
        $inv->forceFill(['id' => (string) Str::uuid(), 'partner_id' => $p->id, 'partner_name' => $p->name, 'order_id' => $order?->id, 'number' => Str::limit(trim($d['number']), 60, ''),
            'date' => $d['date'], 'amount' => round((float) $d['amount'], 2), 'currency' => $order?->currency ?? $c->currency, 'comment' => isset($d['comment']) ? Str::limit((string) $d['comment'], 2000, '') : null,
            'status' => 'received', 'source' => $source, 'created_by' => $by ? Str::limit($by, 120, '') : null]);
        $inv->forceFill(['extracted' => $this->extractionSummary($read, $inv, $p)]);
        $path = 'supplier-invoices/'.$c->id.'/'.$inv->id.'.'.$ext;
        Storage::disk('local')->put($path, $data);
        $inv->forceFill(['file_path' => $path, 'file_name' => Str::limit($file->getClientOriginalName(), 180, '')]);
        $inv->save();
        $m = $this->match($inv);
        AppNotification::send($c->id, $this->buyers($c), 'fournisseurs', ($source === 'portal' ? 'Facture déposée par ' : 'Facture importée : ').$p->name, 'N° '.$inv->number.' · '.number_format($inv->amount, 0, ',', ' ').' '.$inv->currency
            .($order ? ' · '.$order->number : '').' · '.$m['label'], 'portail_fournisseurs', $inv->id);

        return $inv;
    }

    /** Ce que la lecture a trouvé, et les écarts avec la saisie. */
    protected function extractionSummary(array $read, SupplierInvoice $inv, Partner $p): ?array
    {
        if (! ($read['readable'] ?? false)) {
            return $read ? ['readable' => false, 'warnings' => []] : null;
        }
        $w = [];
        if ($read['number'] && mb_strtolower($read['number']) !== mb_strtolower($inv->number)) {
            $w[] = 'N° lu sur la facture : '.$read['number'].' (saisi : '.$inv->number.')';
        }
        if ($read['date'] && $read['date'] !== \Carbon\Carbon::parse($inv->date)->toDateString()) {
            $w[] = 'Date lue : '.\Carbon\Carbon::parse($read['date'])->format('d/m/Y');
        }
        if ($read['total'] && abs($read['total'] - $inv->amount) > 1) {
            $w[] = 'Montant lu : '.number_format($read['total'], 0, ',', ' ').' (saisi : '.number_format($inv->amount, 0, ',', ' ').')';
        }
        if ($read['partner_id'] && $read['partner_id'] !== $p->id) {
            $w[] = 'Le document semble émis par '.$read['partner_name'];
        }
        if ($read['ncc'] && $p->tax_id && preg_replace('/\W/', '', strtoupper($p->tax_id)) !== preg_replace('/\W/', '', strtoupper($read['ncc']))) {
            $w[] = 'NCC sur la facture ('.$read['ncc'].') différent de la fiche fournisseur';
        }

        return ['readable' => true, 'number' => $read['number'], 'date' => $read['date'], 'total' => $read['total'], 'subtotal' => $read['subtotal'], 'tax' => $read['tax'],
            'ncc' => $read['ncc'], 'orders' => $read['orders'], 'confidence' => $read['confidence'], 'warnings' => $w];
    }

    public function tolerance(Company $c): float
    {
        return max(0, min(20, (float) data_get($c->settings, 'fournisseurs.match_tolerance', 1)));
    }

    /**
     * Rapprochement à trois : commandé ↔ reçu ↔ facturé.
     * Le montant attendu est la valeur TTC des quantités reçues, moins ce qui a déjà été facturé sur la commande.
     */
    public function match(SupplierInvoice $inv): array
    {
        $order = $inv->order_id ? PurchaseDocument::with('lines')->find($inv->order_id) : null;
        if (! $order) {
            return ['status' => 'no_order', 'label' => 'Sans bon de commande', 'lines' => [], 'ordered' => null, 'received' => null, 'invoiced_before' => 0, 'expected' => null, 'amount' => $inv->amount, 'gap' => null];
        }
        $tol = $this->tolerance(app('tenant'));
        $received = \App\Modules\Achats\Models\PurchaseReceipt::receivedByLine($order->id);
        $tracksReceipts = $received !== [] || $order->lines->contains(fn ($l) => $l->product_id);
        $lines = [];
        $receivedValue = 0.0;
        foreach ($order->lines as $l) {
            $q = (float) $l->quantity;
            $r = $tracksReceipts ? (float) ($received[$l->id] ?? 0) : $q;
            $unitTtc = $q > 0 ? (float) $l->total / $q : 0;
            $receivedValue += $unitTtc * min($r, $q);
            $lines[] = ['description' => $l->description, 'ordered' => $q, 'received' => $r, 'unit' => $l->unit, 'unit_price' => (float) $l->unit_price, 'ordered_total' => (float) $l->total,
                'received_total' => round($unitTtc * min($r, $q), 2), 'state' => $r <= 0 ? 'missing' : ($r + 0.0005 < $q ? 'partial' : 'ok')];
        }
        $before = (float) PurchaseDocument::where('type', 'supplier_invoice')->where('source_id', $order->id)->where('status', '!=', 'cancelled')
            ->when($inv->supplier_invoice_id, fn ($q) => $q->where('id', '!=', $inv->supplier_invoice_id))->sum('total');
        $before += (float) SupplierInvoice::where('order_id', $order->id)->where('status', 'received')->where('id', '!=', $inv->id)
            ->where(fn ($q) => $q->where('created_at', '<', $inv->created_at ?? now())->orWhere(fn ($q) => $q->where('created_at', $inv->created_at ?? now())->where('id', '<', $inv->id)))->sum('amount');
        $ordered = (float) $order->total;
        $expected = round(max(0, $receivedValue - $before), 2);
        $gap = round($inv->amount - $expected, 2);
        $margin = fn ($base) => max(1, abs($base) * $tol / 100);
        [$status, $label] = match (true) {
            $receivedValue <= 0 => ['not_received', 'Rien de reçu sur '.$order->number],
            $inv->amount + $before > $ordered + $margin($ordered) => ['over', 'Dépasse la commande de '.number_format($inv->amount + $before - $ordered, 0, ',', ' ')],
            abs($gap) <= $margin($expected) && $order->receipt_status === 'partial' => ['partial', 'Conforme à la réception partielle'],
            abs($gap) <= $margin($expected) => ['ok', 'Conforme : commandé = reçu = facturé'],
            default => ['amount_gap', ($gap > 0 ? 'Facturé au-delà du reçu : +' : 'Écart avec le reçu : ').number_format($gap, 0, ',', ' ')],
        };

        return ['status' => $status, 'label' => $label, 'order_id' => $order->id, 'order_number' => $order->number, 'lines' => $lines, 'ordered' => $ordered, 'received' => round($receivedValue, 2),
            'invoiced_before' => round($before, 2), 'expected' => $expected, 'amount' => (float) $inv->amount, 'gap' => $gap, 'tolerance' => $tol, 'tracks_receipts' => $tracksReceipts];
    }

    /** Acceptée : facture fournisseur créée en brouillon dans les Achats (lignes du bon de commande ou montant global). */
    public function afterInvoiceDecision(SupplierInvoice $inv): void
    {
        $c = app('tenant');
        $p = Partner::find($inv->partner_id);
        if ($inv->status === 'accepted' && ! $inv->supplier_invoice_id) {
            $order = $inv->order_id ? PurchaseDocument::with('lines')->find($inv->order_id) : null;
            $taxRate = (float) ($c->settings['tax_rate'] ?? 18);
            // Avec des réceptions : on facture ce qui a été reçu (rapprochement à trois)
            $received = $order ? \App\Modules\Achats\Models\PurchaseReceipt::receivedByLine($order->id) : [];
            $qty = fn ($l) => $received !== [] ? min((float) $l->quantity, (float) ($received[$l->id] ?? 0)) : (float) $l->quantity;
            $lines = $order
                ? $order->lines->filter(fn ($l) => $qty($l) > 0)->map(fn ($l) => array_filter(['id' => (string) Str::uuid(), 'product_id' => $l->product_id, 'description' => $l->description, 'quantity' => $qty($l), 'unit' => $l->unit,
                    'unit_price' => (float) $l->unit_price, 'tax_rate' => (float) $l->tax_rate, 'discount_percent' => (float) $l->discount_percent], fn ($v) => $v !== null))->values()->all()
                : [['id' => (string) Str::uuid(), 'description' => 'Facture '.$inv->number, 'quantity' => 1, 'unit_price' => round($inv->amount / (1 + $taxRate / 100), 2), 'tax_rate' => $taxRate, 'discount_percent' => 0]];
            $id = (string) Str::uuid();
            $res = app(EntityWriter::class)->upsert(PurchaseDocument::class, $id, array_filter(['type' => 'supplier_invoice', 'partner_id' => $inv->partner_id, 'date' => $inv->date->toDateString(),
                'reference' => $inv->number, 'source_id' => $order?->id, 'project_id' => $order?->project_id, 'currency' => $inv->currency, 'lines' => $lines ?: [['id' => (string) Str::uuid(), 'description' => 'Facture '.$inv->number, 'quantity' => 1, 'unit_price' => round($inv->amount / (1 + $taxRate / 100), 2), 'tax_rate' => $taxRate, 'discount_percent' => 0]],
                'notes' => 'Déposée sur le portail fournisseur'.($order ? ' · '.$order->number : '').($inv->comment ? ' · '.$inv->comment : '')], fn ($v) => $v !== null));
            if ($res['status'] !== EntityWriter::APPLIED) {
                throw new \DomainException('Facture fournisseur refusée : '.($res['message'] ?? ''));
            }
            $inv->forceFill(['supplier_invoice_id' => $id])->save();
            if ($p) {
                $this->notifySupplier($c, $p, 'Facture '.$inv->number.' reçue', "Bonjour,\n\nVotre facture n° {$inv->number} est enregistrée. Suivez son paiement sur votre espace fournisseur.\n\n{$c->name}");
            }
        } elseif ($inv->status === 'rejected' && $p) {
            $this->notifySupplier($c, $p, 'Facture '.$inv->number.' refusée', "Bonjour,\n\nVotre facture n° {$inv->number} n'a pas pu être acceptée : {$inv->reject_reason}\nVous pouvez déposer une facture corrigée sur votre espace fournisseur.\n\n{$c->name}");
        }
    }

    /** État de paiement vu par le fournisseur. */
    public function invoiceState(SupplierInvoice $inv): array
    {
        $doc = $inv->supplier_invoice_id ? PurchaseDocument::find($inv->supplier_invoice_id) : null;
        $label = match (true) {
            $inv->status === 'rejected' => 'Refusée',
            ! $doc => 'Reçue — en vérification',
            $doc->payment_status === 'paid' => 'Payée',
            $doc->payment_status === 'partial' => 'Payée en partie',
            $doc->status === 'validated' => 'Validée — à payer'.($doc->due_date ? ' le '.$doc->due_date->format('d/m/Y') : ''),
            default => 'Acceptée — en comptabilisation',
        };

        return ['label' => $label, 'paid' => (float) ($doc?->amount_paid ?? 0), 'due' => $doc?->due_date?->toDateString(), 'status' => $doc?->payment_status ?? null];
    }

    public function stats(): array
    {
        return [
            'open_tenders' => Tender::where('status', 'open')->count(),
            'to_award' => Tender::where('status', 'closed')->count(),
            'invoices_received' => SupplierInvoice::where('status', 'received')->count(),
            'acks_pending' => PurchaseDocument::where('type', 'purchase_order')->where('status', 'validated')->where(fn ($q) => $q->whereNull('receipt_status')->orWhere('receipt_status', '!=', 'received'))
                ->whereNotIn('id', OrderAck::select('order_id'))->whereIn('partner_id', Access::where('active', true)->select('partner_id'))->count(),
            'suppliers' => Access::where('active', true)->count(),
        ];
    }
}
