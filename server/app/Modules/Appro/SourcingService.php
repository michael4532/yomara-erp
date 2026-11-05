<?php

namespace App\Modules\Appro;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\Company;
use App\Models\Sequence;
use App\Modules\Achats\Models\PurchaseDocument;
use App\Modules\Achats\Models\PurchaseRequest;
use App\Modules\Achats\Models\SupplierPrice;
use App\Modules\Appro\Models\Comparison;
use App\Modules\Appro\Models\Spec;
use App\Modules\Core\Models\Partner;
use App\Modules\Core\Models\Product;
use App\Services\EntityWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Cahiers des charges et comparatifs de prix entre fournisseurs (avec ou sans portail fournisseurs). */
class SourcingService
{
    public function __construct(private EntityWriter $writer, private MessagingService $messaging) {}

    protected function assert(bool $ok, string $msg): void
    {
        if (! $ok) {
            throw new \DomainException($msg);
        }
    }

    protected function rate(Company $c, ?string $currency): float
    {
        $currency = strtoupper((string) ($currency ?: $c->currency));
        if ($currency === $c->currency) {
            return 1.0;
        }
        $r = class_exists(\App\Modules\Devises\FxService::class) ? app(\App\Modules\Devises\FxService::class)->rate($c, $currency) : null;

        return $r ?: 1.0;
    }

    // ------------------------------------------------------------------ cahiers des charges

    public const SPEC_SECTIONS = [
        ['title' => 'Objet du marché', 'body' => ''],
        ['title' => 'Description des travaux et fournitures', 'body' => ''],
        ['title' => 'Normes et exigences techniques', 'body' => 'Matériel neuf, conforme aux normes NF C 15-100 / CEI en vigueur, avec certificats de conformité.'],
        ['title' => 'Délais et lieu de livraison', 'body' => ''],
        ['title' => 'Garanties et service après-vente', 'body' => 'Garantie minimale de 12 mois pièces et main-d\'œuvre.'],
        ['title' => 'Conditions de paiement', 'body' => ''],
        ['title' => 'Contenu de l\'offre et critères de choix', 'body' => 'Offre chiffrée ligne par ligne, délai de livraison, fiches techniques. Choix sur le prix, le délai et la conformité technique.'],
    ];

    public function saveSpec(Company $c, ?Spec $s, array $d, ?int $userId): Spec
    {
        $s ??= new Spec(['company_id' => $c->id, 'user_id' => $userId, 'sections' => self::SPEC_SECTIONS, 'requirements' => []]);
        foreach (['title', 'site', 'project_id', 'context', 'deadline', 'status'] as $k) {
            if (array_key_exists($k, $d)) {
                $s->{$k} = is_string($d[$k]) ? (trim($d[$k]) ?: null) : $d[$k];
            }
        }
        if (array_key_exists('sections', $d)) {
            $s->sections = array_values(array_filter(array_map(fn ($x) => ['title' => Str::limit(trim((string) ($x['title'] ?? '')), 150, ''), 'body' => trim((string) ($x['body'] ?? ''))], (array) $d['sections']), fn ($x) => $x['title'] !== ''));
        }
        if (array_key_exists('requirements', $d)) {
            $s->requirements = array_values(array_filter(array_map(fn ($x) => ['label' => Str::limit(trim((string) ($x['label'] ?? '')), 250, ''), 'value' => Str::limit(trim((string) ($x['value'] ?? '')), 250, ''),
                'quantity' => isset($x['quantity']) && $x['quantity'] !== '' ? (float) $x['quantity'] : null, 'unit' => isset($x['unit']) ? trim((string) $x['unit']) ?: null : null,
                'mandatory' => (bool) ($x['mandatory'] ?? true)], (array) $d['requirements']), fn ($x) => $x['label'] !== ''));
        }
        $this->assert((bool) $s->title, 'Donnez un titre au cahier des charges.');
        $s->status = in_array($s->status, ['draft', 'published'], true) ? $s->status : 'draft';
        $s->number ??= Sequence::next($c->id, 'appro.spec', 'CDC');
        $s->save();

        return $s->fresh();
    }

    public function specPdf(Company $c, Spec $s): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $pdf = Pdf::make('Cahier des charges '.$s->number);
        $html = Pdf::head($c, 'CAHIER DES CHARGES', $s->number)
            .'<h2 style="color:#1f2a44">'.$e($s->title).'</h2>'.($s->site ? '<p>Lieu / chantier : <b>'.$e($s->site).'</b></p>' : '')
            .($s->deadline ? '<p>Date limite de remise des offres : <b>'.$e($s->deadline->format('d/m/Y')).'</b></p>' : '').($s->context ? '<p>'.nl2br($e($s->context)).'</p>' : '');
        foreach ((array) $s->sections as $i => $x) {
            if (trim((string) $x['body']) === '') {
                continue;
            }
            $html .= '<h3 style="color:#1f6feb">'.($i + 1).'. '.$e($x['title']).'</h3><p>'.nl2br($e($x['body'])).'</p>';
        }
        if ($s->requirements) {
            $html .= '<h3 style="color:#1f6feb">Spécifications et quantités</h3><table cellpadding="4" border="0.3"><tr bgcolor="#eeeeee" style="font-weight:bold"><td width="6%">N°</td><td width="38%">Désignation</td><td width="34%">Caractéristiques exigées</td><td width="10%" align="right">Qté</td><td width="12%">Exigence</td></tr>';
            foreach ($s->requirements as $i => $r) {
                $html .= '<tr><td>'.($i + 1).'</td><td>'.$e($r['label']).'</td><td>'.$e($r['value']).'</td><td align="right">'.$e($r['quantity'] !== null ? rtrim(rtrim(number_format((float) $r['quantity'], 2, ',', ' '), '0'), ',').' '.($r['unit'] ?? '') : '').'</td><td>'.($r['mandatory'] ? 'Obligatoire' : 'Souhaitée').'</td></tr>';
            }
            $html .= '</table>';
        }
        $html .= '<br><p style="color:#666">Merci d\'adresser votre offre à '.$e($c->name).($c->email ? ' ('.$e($c->email).')' : '').'.</p>';
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output('', 'S');
    }

    /** Envoie le cahier des charges aux fournisseurs (e-mail avec le PDF). */
    public function sendSpec(Company $c, Spec $s, array $partnerIds, ?string $message): array
    {
        $this->assert($this->messaging->available($c)['email'] ?? false, 'Configurez l\'envoi d\'e-mails (Paramètres → Messagerie).');
        $pdf = $this->specPdf($c, $s);
        $sent = [];
        $skipped = [];
        foreach (Partner::whereIn('id', $partnerIds)->get() as $p) {
            if (! $p->email) {
                $skipped[] = $p->name;

                continue;
            }
            $html = '<p>Bonjour,</p><p>'.nl2br(htmlspecialchars($message ?: 'Nous vous consultons pour le projet « '.$s->title.' ». Vous trouverez ci-joint notre cahier des charges. Merci de nous faire parvenir votre meilleure offre'.($s->deadline ? ' avant le '.$s->deadline->format('d/m/Y') : '').'.')).'</p><p>Cordialement,<br>'.htmlspecialchars($c->name).'</p>';
            $r = rescue(fn () => $this->messaging->send($c, 'email', new Message($p->email, 'Consultation : '.$s->title.' ('.$s->number.')', $html, null,
                [['name' => 'cahier-des-charges-'.Str::slug($s->number).'.pdf', 'data' => $pdf, 'mime' => 'application/pdf']], $p->name), 'appro'), null, false);
            if ($r?->ok) {
                $sent[] = ['partner_id' => $p->id, 'name' => $p->name, 'at' => now()->toDateTimeString()];
            } else {
                $skipped[] = $p->name;
            }
        }
        $s->forceFill(['sent_to' => array_values(array_merge((array) $s->sent_to, $sent)), 'status' => 'published'])->save();

        return ['sent' => count($sent), 'skipped' => $skipped];
    }

    // ------------------------------------------------------------------ comparatifs

    /** Crée un comparatif depuis une demande d'achat, un cahier des charges ou des lignes saisies. */
    public function saveComparison(Company $c, ?Comparison $cmp, array $d, ?int $userId): Comparison
    {
        $cmp ??= new Comparison(['company_id' => $c->id, 'user_id' => $userId, 'status' => 'open', 'offers' => []]);
        $this->assert($cmp->status === 'open', 'Comparatif attribué : il n\'est plus modifiable.');
        foreach (['title', 'deadline', 'notes', 'request_id', 'spec_id', 'instructions'] as $k) {
            if (array_key_exists($k, $d)) {
                $cmp->{$k} = is_string($d[$k]) ? (trim($d[$k]) ?: null) : $d[$k];
            }
        }
        if (! $cmp->exists && empty($d['lines'])) {
            if ($cmp->request_id && ($req = PurchaseRequest::find($cmp->request_id))) {
                $d['lines'] = array_map(fn ($l) => ['product_id' => $l['product_id'] ?? null, 'description' => $l['description'], 'quantity' => $l['quantity'] ?? 1, 'unit' => $l['unit'] ?? null], (array) $req->lines);
                $cmp->title ??= $req->title;
            } elseif ($cmp->spec_id && ($spec = Spec::find($cmp->spec_id))) {
                $d['lines'] = array_map(fn ($r) => ['description' => trim($r['label'].($r['value'] ? ' — '.$r['value'] : '')), 'quantity' => $r['quantity'] ?? 1, 'unit' => $r['unit'] ?? null], (array) $spec->requirements);
                $cmp->title ??= $spec->title;
            }
        }
        if (array_key_exists('lines', $d)) {
            $old = collect((array) $cmp->lines)->keyBy('key');
            $cmp->lines = array_values(array_map(fn ($l) => ['key' => ! empty($l['key']) && $old->has($l['key']) ? $l['key'] : Str::random(8), 'product_id' => $l['product_id'] ?? null,
                'description' => Str::limit(trim((string) $l['description']), 250, ''), 'quantity' => max(0.001, (float) ($l['quantity'] ?? 1)), 'unit' => $l['unit'] ?? null],
                array_filter((array) $d['lines'], fn ($l) => trim((string) ($l['description'] ?? '')) !== '')));
        }
        if (array_key_exists('offers', $d)) {
            $keys = array_column((array) $cmp->lines, 'key');
            $before = collect((array) $cmp->offers)->keyBy('key');
            $cmp->offers = array_values(array_map(function ($o) use ($c, $keys, $before) {
                $p = ! empty($o['partner_id']) ? Partner::find($o['partner_id']) : null;
                $prices = [];
                foreach ((array) ($o['prices'] ?? []) as $k => $v) {
                    if (in_array($k, $keys, true) && $v !== null && $v !== '') {
                        $prices[$k] = max(0, (float) $v);
                    }
                }
                $cur = strtoupper((string) ($o['currency'] ?? $c->currency)) ?: $c->currency;

                return ['key' => $o['key'] ?? Str::random(8), 'partner_id' => $p?->id, 'partner_name' => $p?->name ?? Str::limit(trim((string) ($o['partner_name'] ?? 'Fournisseur')), 120, ''),
                    'currency' => $cur, 'rate' => (float) ($o['rate'] ?? 0) > 0 ? (float) $o['rate'] : $this->rate($c, $cur), 'delay_days' => isset($o['delay_days']) && $o['delay_days'] !== '' ? (int) $o['delay_days'] : null,
                    'extra' => max(0, (float) ($o['extra'] ?? 0)), 'payment_terms' => isset($o['payment_terms']) ? Str::limit(trim((string) $o['payment_terms']), 150, '') ?: null : null,
                    'validity' => $o['validity'] ?? null, 'notes' => isset($o['notes']) ? Str::limit(trim((string) $o['notes']), 500, '') ?: null : null, 'compliant' => (bool) ($o['compliant'] ?? true), 'prices' => $prices]
                    // Réponse en ligne : origine, date de dépôt et remarques par ligne conservées quand l'acheteur enregistre la grille
                    + array_intersect_key((array) ($before[$o['key'] ?? ''] ?? []), array_flip(['source', 'submitted_at', 'line_notes']));
            }, (array) $d['offers']));
        }
        $this->assert((bool) $cmp->title, 'Donnez un titre au comparatif.');
        $cmp->number ??= Sequence::next($c->id, 'appro.comparison', 'CMP');
        $cmp->save();

        return $cmp->fresh();
    }

    /** Ajoute une offre par fournisseur ayant un tarif connu (tarifs fournisseurs et derniers achats). */
    public function prefill(Company $c, Comparison $cmp): Comparison
    {
        $lines = collect((array) $cmp->lines)->filter(fn ($l) => ! empty($l['product_id']));
        $this->assert($lines->isNotEmpty(), 'Aucune ligne liée à un article du catalogue.');
        $offers = collect((array) $cmp->offers)->keyBy('partner_id');
        foreach (SupplierPrice::whereIn('product_id', $lines->pluck('product_id'))->get()->groupBy('partner_id') as $pid => $prices) {
            $o = $offers->get($pid) ?? ['key' => Str::random(8), 'partner_id' => $pid, 'partner_name' => Partner::find($pid)?->name ?? 'Fournisseur', 'currency' => $c->currency, 'rate' => 1.0,
                'delay_days' => $prices->max('lead_days'), 'extra' => 0, 'payment_terms' => null, 'validity' => null, 'notes' => 'Prérempli avec les tarifs connus', 'compliant' => true, 'prices' => []];
            foreach ($lines as $l) {
                $sp = $prices->firstWhere('product_id', $l['product_id']);
                if ($sp && ! isset($o['prices'][$l['key']])) {
                    $o['prices'][$l['key']] = (float) $sp->price;
                }
            }
            $offers[$pid] = $o;
        }
        $cmp->offers = $offers->values()->all();
        $cmp->save();

        return $cmp->fresh();
    }

    /** Analyse : total de chaque offre en devise de la société, meilleur prix par ligne, offre la moins chère, la plus rapide. */
    public function analyze(Company $c, Comparison $cmp): array
    {
        $lines = (array) $cmp->lines;
        $offers = (array) $cmp->offers;
        $res = [];
        foreach ($offers as $o) {
            $total = 0.0;
            $missing = 0;
            foreach ($lines as $l) {
                if (isset($o['prices'][$l['key']])) {
                    $total += $o['prices'][$l['key']] * $l['quantity'] * $o['rate'];
                } else {
                    $missing++;
                }
            }
            $total += $o['extra'];
            $res[$o['key']] = ['key' => $o['key'], 'total' => round($total, 2), 'missing' => $missing, 'complete' => $missing === 0];
        }
        $best = [];
        foreach ($lines as $l) {
            $min = null;
            foreach ($offers as $o) {
                if (isset($o['prices'][$l['key']])) {
                    $v = $o['prices'][$l['key']] * $o['rate'];
                    if ($min === null || $v < $min[1]) {
                        $min = [$o['key'], $v];
                    }
                }
            }
            $best[$l['key']] = $min ? ['offer' => $min[0], 'unit' => round($min[1], 2)] : null;
        }
        $complete = collect($res)->where('complete', true)->filter(fn ($r) => collect($offers)->firstWhere('key', $r['key'])['compliant'] ?? true);
        $cheapest = $complete->sortBy('total')->first();
        $fastest = collect($offers)->filter(fn ($o) => $o['delay_days'] !== null && ($res[$o['key']]['complete'] ?? false))->sortBy('delay_days')->first();
        $mixed = round(array_sum(array_map(fn ($l) => ($best[$l['key']]['unit'] ?? 0) * $l['quantity'], $lines)), 2);
        $max = $complete->max('total');

        return ['offers' => array_values($res), 'best_lines' => $best, 'cheapest' => $cheapest['key'] ?? null, 'fastest' => $fastest['key'] ?? null,
            'best_mixed_total' => $mixed, 'spread' => $cheapest && $max ? round($max - $cheapest['total'], 2) : 0];
    }

    /** Attribue le comparatif à une offre et prépare le bon de commande. */
    public function award(Company $c, Comparison $cmp, string $offerKey, ?string $note): Comparison
    {
        $this->assert($cmp->status === 'open', 'Comparatif déjà attribué.');
        $o = collect((array) $cmp->offers)->firstWhere('key', $offerKey);
        $this->assert((bool) $o, 'Offre introuvable.');
        $this->assert((bool) $o['partner_id'], 'Cette offre n\'est pas liée à une fiche fournisseur : créez le fournisseur et choisissez-le dans l\'offre.');
        $lines = collect((array) $cmp->lines)->filter(fn ($l) => isset($o['prices'][$l['key']]));
        $this->assert($lines->isNotEmpty(), 'Cette offre ne chiffre aucune ligne.');
        $products = Product::whereIn('id', $lines->pluck('product_id')->filter())->get()->keyBy('id');
        $local = $o['currency'] === $c->currency;
        $res = $this->writer->upsert(PurchaseDocument::class, $id = (string) Str::uuid(), [
            'type' => 'purchase_order', 'partner_id' => $o['partner_id'], 'date' => now()->toDateString(), 'due_date' => $o['delay_days'] ? now()->addDays($o['delay_days'])->toDateString() : null,
            'currency' => $o['currency'], 'exchange_rate' => $local ? null : $o['rate'], 'reference' => $cmp->number, 'notes' => 'Comparatif '.$cmp->number.' — '.$cmp->title.($o['payment_terms'] ? "\nConditions : ".$o['payment_terms'] : ''),
            'lines' => $lines->map(fn ($l) => ['id' => (string) Str::uuid(), 'product_id' => $l['product_id'], 'description' => $l['description'], 'quantity' => $l['quantity'], 'unit' => $l['unit'] ?? $products->get($l['product_id'] ?? '')?->unit,
                'unit_price' => $o['prices'][$l['key']], 'tax_rate' => $local ? ($products->get($l['product_id'] ?? '') ? (float) $products->get($l['product_id'])->tax_rate : (float) ($c->settings['tax_rate'] ?? 18)) : 0, 'discount_percent' => 0])->values()->all(),
        ]);
        $this->assert($res['status'] === EntityWriter::APPLIED, 'Bon de commande refusé : '.($res['message'] ?? json_encode($res['errors'] ?? $res)));
        // Les prix obtenus enrichissent les tarifs fournisseurs
        foreach ($lines as $l) {
            if ($l['product_id'] && $local) {
                $sp = SupplierPrice::where('partner_id', $o['partner_id'])->where('product_id', $l['product_id'])->first();
                rescue(fn () => $this->writer->upsert(SupplierPrice::class, $sp?->id ?? (string) Str::uuid(), ['partner_id' => $o['partner_id'], 'product_id' => $l['product_id'], 'price' => $o['prices'][$l['key']],
                    'lead_days' => $o['delay_days'] ?? $sp?->lead_days]), null, false);
            }
        }
        $cmp->forceFill(['status' => 'awarded', 'awarded' => $offerKey, 'award_note' => $note ? trim($note) : null, 'order_id' => $id])->save();

        return $cmp->fresh();
    }

    public function comparisonToApi(Company $c, Comparison $cmp, bool $full = false): array
    {
        $a = $this->analyze($c, $cmp);
        $win = $cmp->awarded ? collect($a['offers'])->firstWhere('key', $cmp->awarded) : null;
        $out = $cmp->only(['id', 'number', 'title', 'status', 'request_id', 'spec_id', 'notes', 'instructions', 'awarded', 'award_note', 'order_id', 'source_type', 'source_label']) + [
            'deadline' => $cmp->deadline?->toDateString(), 'created_at' => $cmp->created_at, 'lines_count' => count((array) $cmp->lines), 'offers_count' => count((array) $cmp->offers),
            'cheapest' => $a['cheapest'], 'best_total' => $a['cheapest'] ? collect($a['offers'])->firstWhere('key', $a['cheapest'])['total'] : null,
            'awarded_total' => $win['total'] ?? null, 'awarded_partner' => $cmp->awarded ? (collect((array) $cmp->offers)->firstWhere('key', $cmp->awarded)['partner_name'] ?? null) : null,
            'spread' => $a['spread'], 'order_number' => $cmp->order_id ? PurchaseDocument::find($cmp->order_id)?->number : null,
        ];
        if ($full) {
            $out['lines'] = $cmp->lines ?? [];
            $out['offers'] = $cmp->offers ?? [];
            $out['analysis'] = $a;
            $out['history'] = $this->priceHistory(collect((array) $cmp->lines)->pluck('product_id')->filter()->values()->all());
        }

        return $out;
    }

    /** Derniers prix payés par article et par fournisseur (bons de commande validés). */
    public function priceHistory(array $productIds): array
    {
        if (! $productIds) {
            return [];
        }
        $rows = DB::table('trade_document_lines as l')->join('trade_documents as d', 'd.id', '=', 'l.document_id')
            ->where('d.company_id', app('tenant')->id)->where('d.direction', 'purchase')->whereIn('d.type', ['purchase_order', 'supplier_invoice'])->where('d.status', 'validated')->whereNull('d.deleted_at')
            ->whereIn('l.product_id', $productIds)->orderByDesc('d.date')->get(['l.product_id', 'd.partner_id', 'd.partner_name', 'd.date', 'l.unit_price', 'd.exchange_rate', 'd.currency', 'd.type']);
        $out = [];
        foreach ($rows->groupBy('product_id') as $pid => $g) {
            // Commandes en priorité (sinon factures), pour ne pas compter deux fois la commande et sa facture
            $out[$pid] = $g->groupBy('partner_id')->map(fn ($x) => $x->where('type', $x->contains('type', 'purchase_order') ? 'purchase_order' : 'supplier_invoice')->values())->map(fn ($x) => ['partner' => $x->first()->partner_name, 'last_price' => round((float) $x->first()->unit_price * (float) ($x->first()->exchange_rate ?: 1), 2),
                'last_date' => $x->first()->date, 'count' => $x->count(), 'min' => round($x->min(fn ($y) => (float) $y->unit_price * (float) ($y->exchange_rate ?: 1)), 2)])->values()->all();
        }

        return $out;
    }

    public function comparisonPdf(Company $c, Comparison $cmp): string
    {
        $a = $this->analyze($c, $cmp);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $m = fn ($v) => $e(number_format((float) $v, 0, ',', ' '));
        $offers = (array) $cmp->offers;
        $pdf = Pdf::make('Comparatif '.$cmp->number, true);
        $w = max(8, (int) floor(60 / max(1, count($offers))));
        $html = Pdf::head($c, 'COMPARATIF DES OFFRES', $cmp->number.' — '.$cmp->title).'<table cellpadding="4" border="0.3"><tr bgcolor="#1f2a44" color="#ffffff" style="font-weight:bold"><td width="30%">Désignation</td><td width="8%" align="right">Qté</td>';
        foreach ($offers as $o) {
            $html .= '<td width="'.$w.'%" align="right">'.$e($o['partner_name']).($o['currency'] !== $c->currency ? '<br>('.$e($o['currency']).')' : '').'</td>';
        }
        $html .= '</tr>';
        foreach ((array) $cmp->lines as $l) {
            $html .= '<tr><td>'.$e($l['description']).'</td><td align="right">'.$e(rtrim(rtrim(number_format($l['quantity'], 2, ',', ' '), '0'), ',')).'</td>';
            foreach ($offers as $o) {
                $best = ($a['best_lines'][$l['key']]['offer'] ?? null) === $o['key'];
                $html .= '<td align="right"'.($best ? ' bgcolor="#e3f6ea"' : '').'>'.(isset($o['prices'][$l['key']]) ? $e(number_format($o['prices'][$l['key']], ($o['currency'] ?? $c->currency) === $c->currency && floor($o['prices'][$l['key']]) == $o['prices'][$l['key']] ? 0 : 2, ',', ' ')) : '—').'</td>';
            }
            $html .= '</tr>';
        }
        $html .= '<tr style="font-weight:bold" bgcolor="#f2f5fa"><td colspan="2">TOTAL ('.$e($c->currency).', frais compris)</td>';
        foreach ($offers as $o) {
            $r = collect($a['offers'])->firstWhere('key', $o['key']);
            $html .= '<td align="right"'.($a['cheapest'] === $o['key'] ? ' bgcolor="#c8efd6"' : '').'>'.$m($r['total']).($r['missing'] ? '<br><span style="color:#c0392b">incomplète</span>' : '').'</td>';
        }
        $html .= '</tr><tr><td colspan="2">Délai (jours)</td>';
        foreach ($offers as $o) {
            $html .= '<td align="right">'.$e($o['delay_days'] ?? '—').'</td>';
        }
        $html .= '</tr><tr><td colspan="2">Conditions</td>';
        foreach ($offers as $o) {
            $html .= '<td align="right" style="font-size:7pt">'.$e($o['payment_terms'] ?? '').($o['compliant'] ? '' : '<br><b style="color:#c0392b">Non conforme</b>').'</td>';
        }
        $html .= '</tr></table>';
        if ($cmp->status === 'awarded') {
            $win = collect($offers)->firstWhere('key', $cmp->awarded);
            $html .= '<p><b>Offre retenue : '.$e($win['partner_name'] ?? '').'</b>'.($cmp->award_note ? ' — '.$e($cmp->award_note) : '').'</p>';
        }
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output('', 'S');
    }
}
