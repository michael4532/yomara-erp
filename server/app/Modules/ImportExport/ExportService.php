<?php

namespace App\Modules\ImportExport;

use App\Models\Company;
use App\Modules\Achats\Models\PurchaseDocument;
use App\Modules\Core\Models\Partner;
use App\Modules\Core\Models\Product;
use App\Modules\Core\Models\ProductCategory;
use App\Modules\Stock\Models\StockMove;
use App\Modules\Stock\Models\Warehouse;
use App\Modules\Ventes\Models\SalesDocument;
use App\Modules\Ventes\Models\SalesPayment;

/** Listes exportables : [titre, en-têtes, lignes]. Les exports « Clients » et « Articles » se réimportent tels quels. */
class ExportService
{
    public function available(Company $c): array
    {
        return array_filter([
            'partners' => ['Clients & fournisseurs', false, 'core.contacts.view'],
            'products' => ['Articles', false, 'core.products.view'],
            'stock' => $c->hasModule('stock') ? ['Stock par dépôt (valorisé)', false, 'stock.view'] : null,
            'stock_moves' => $c->hasModule('stock') ? ['Mouvements de stock', true, 'stock.view'] : null,
            'sales' => $c->hasModule('ventes') ? ['Documents de vente', true, 'ventes.view'] : null,
            'sales_lines' => $c->hasModule('ventes') ? ['Lignes de vente (par article)', true, 'ventes.view'] : null,
            'payments' => $c->hasModule('ventes') ? ['Encaissements', true, 'ventes.view'] : null,
            'purchases' => $c->hasModule('achats') ? ['Documents d\'achat', true, 'achats.view'] : null,
        ]);
    }

    public function build(string $entity, ?string $from, ?string $to): array
    {
        $period = fn ($q, string $col = 'date') => $q->when($from, fn ($q) => $q->where($col, '>=', $from))->when($to, fn ($q) => $q->where($col, '<=', $to));
        $types = ['customer' => 'client', 'supplier' => 'fournisseur', 'both' => 'les deux'];
        $docTypes = ['quote' => 'Devis', 'proforma' => 'Facture proforma', 'order' => 'Commande', 'invoice' => 'Facture', 'credit_note' => 'Avoir', 'receipt' => 'Ticket de caisse', 'purchase_order' => 'Bon de commande', 'supplier_invoice' => 'Facture fournisseur'];
        $status = ['draft' => 'Brouillon', 'validated' => 'Validé', 'cancelled' => 'Annulé'];
        $pay = ['unpaid' => 'Non payé', 'partial' => 'Partiel', 'paid' => 'Payé'];

        return match ($entity) {
            'partners' => ['Clients et fournisseurs', ['Nom', 'Type', 'Code', 'Téléphone', 'E-mail', 'Adresse', 'Ville', 'N° contribuable', 'Plafond de crédit', 'Notes'],
                Partner::orderBy('name')->get()->map(fn ($p) => [$p->name, $types[$p->type] ?? $p->type, $p->code, $p->phone, $p->email, $p->address, $p->city, $p->tax_id, $p->credit_limit, $p->notes])],
            'products' => (function () {
                $cats = ProductCategory::pluck('name', 'id');
                $stock = StockMove::selectRaw('product_id, SUM(quantity) q')->groupBy('product_id')->pluck('q', 'product_id');

                return ['Articles', ['Désignation', 'Référence', 'Code-barres', 'Catégorie', 'Type', 'Unité', 'Prix de vente HT', 'TVA', 'Prix de vente TTC', 'Prix d\'achat HT', 'Seuil d\'alerte', 'Stock actuel', 'Actif'],
                    Product::orderBy('name')->get()->map(fn ($p) => [$p->name, $p->sku, $p->barcode, $cats[$p->category_id] ?? null, $p->type === 'service' ? 'service' : 'produit', $p->unit,
                        $p->sale_price, $p->tax_rate, round($p->sale_price * (1 + $p->tax_rate / 100)), $p->purchase_price, $p->min_stock, $p->type === 'product' ? round((float) ($stock[$p->id] ?? 0), 3) : null, $p->active])];
            })(),
            'stock' => (function () {
                $products = Product::where('type', 'product')->get()->keyBy('id');
                $wh = Warehouse::pluck('name', 'id');
                $rows = StockMove::selectRaw('product_id, warehouse_id, SUM(quantity) q')->groupBy('product_id', 'warehouse_id')->get()
                    ->filter(fn ($r) => isset($products[$r->product_id]))
                    ->map(fn ($r) => [$products[$r->product_id]->name, $products[$r->product_id]->sku, $wh[$r->warehouse_id] ?? '', round((float) $r->q, 3), $products[$r->product_id]->unit,
                        $products[$r->product_id]->purchase_price, round($r->q * $products[$r->product_id]->purchase_price)])
                    ->sortBy(0)->values();

                return ['Stock', ['Article', 'Référence', 'Dépôt', 'Quantité', 'Unité', 'Coût unitaire', 'Valeur'], $rows];
            })(),
            'stock_moves' => (function () use ($period) {
                $products = Product::pluck('name', 'id');
                $wh = Warehouse::pluck('name', 'id');

                return ['Mouvements de stock', ['Date', 'Article', 'Dépôt', 'Type', 'Quantité', 'Coût unitaire', 'Référence', 'Notes'],
                    $period(StockMove::query())->orderBy('date')->orderBy('created_at')->get()->map(fn ($m) => [$m->date?->format('d/m/Y'), $products[$m->product_id] ?? '', $wh[$m->warehouse_id] ?? '', $m->type, $m->quantity, $m->unit_cost, $m->reference, $m->notes])];
            })(),
            'sales', 'purchases' => (function () use ($entity, $period, $docTypes, $status, $pay) {
                $q = $entity === 'sales' ? SalesDocument::query() : PurchaseDocument::query();

                return [$entity === 'sales' ? 'Ventes' : 'Achats', ['Date', 'Type', 'Numéro', 'Référence', 'Tiers', 'Total HT', 'TVA', 'Total TTC', 'Payé', 'Reste à payer', 'Statut', 'Paiement', 'Échéance'],
                    $period($q)->orderBy('date')->get()->map(fn ($d) => [$d->date?->format('d/m/Y'), $docTypes[$d->type] ?? $d->type, $d->number, $d->reference, $d->partner_name, $d->subtotal, $d->tax_total, $d->total,
                        $d->amount_paid, $d->balance(), $status[$d->status] ?? $d->status, $pay[$d->payment_status] ?? $d->payment_status, $d->due_date?->format('d/m/Y')])];
            })(),
            'sales_lines' => (function () use ($period, $docTypes) {
                $docs = $period(SalesDocument::query())->where('status', 'validated')->whereIn('type', ['invoice', 'receipt', 'credit_note'])->with('lines')->orderBy('date')->get();
                $rows = [];
                foreach ($docs as $d) {
                    $sign = $d->type === 'credit_note' ? -1 : 1;
                    foreach ($d->lines as $l) {
                        $rows[] = [$d->date?->format('d/m/Y'), $docTypes[$d->type] ?? $d->type, $d->number, $d->partner_name, $l->description, $sign * $l->quantity, $l->unit_price, $l->discount_percent, $l->tax_rate, $sign * $l->subtotal, $sign * $l->total];
                    }
                }

                return ['Lignes de vente', ['Date', 'Type', 'Numéro', 'Client', 'Article', 'Quantité', 'Prix unitaire HT', 'Remise %', 'TVA %', 'Total HT', 'Total TTC'], $rows];
            })(),
            'payments' => (function () use ($period) {
                $docs = SalesDocument::pluck('number', 'id');
                $partners = SalesDocument::pluck('partner_name', 'id');

                return ['Encaissements', ['Date', 'Document', 'Client', 'Mode', 'Montant', 'Référence'],
                    $period(SalesPayment::query())->orderBy('date')->get()->map(fn ($p) => [$p->date?->format('d/m/Y'), $docs[$p->document_id] ?? '', $partners[$p->document_id] ?? '', $p->method, $p->amount, $p->reference])];
            })(),
        };
    }
}
