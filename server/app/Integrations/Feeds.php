<?php

namespace App\Integrations;

use App\Models\Company;
use App\Models\User;
use App\Support\BranchScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flux de données publiés par lien secret : CSV (Google Sheets =IMPORTDATA, Excel Power Query, Looker Studio)
 * ou JSON (Power BI, n8n). Les données suivent les droits et les agences du compte propriétaire du flux.
 */
class Feeds
{
    public const DATASETS = [
        'invoices' => ['label' => 'Factures de vente', 'perm' => 'ventes.view|ventes.manage'],
        'sales_lines' => ['label' => 'Ventes ligne par ligne (articles)', 'perm' => 'ventes.view|ventes.manage'],
        'payments' => ['label' => 'Encaissements clients', 'perm' => 'ventes.view|ventes.payments.manage'],
        'customers' => ['label' => 'Clients (chiffre d\'affaires, impayés)', 'perm' => 'core.contacts.view|core.contacts.manage'],
        'products' => ['label' => 'Articles et stock', 'perm' => 'core.products.view|core.products.manage'],
        'purchases' => ['label' => 'Factures fournisseurs', 'perm' => 'achats.view|achats.manage'],
        'expenses' => ['label' => 'Dépenses et recettes de trésorerie', 'perm' => 'tresorerie.view|tresorerie.manage'],
    ];

    public static function allowed(User $u, string $dataset): bool
    {
        foreach (explode('|', self::DATASETS[$dataset]['perm'] ?? '') as $p) {
            if ($u->hasPermission($p)) {
                return true;
            }
        }

        return false;
    }

    protected static function branches($q, string $col, Company $c): void
    {
        static $has = [];
        $table = explode('.', $col)[0];
        $tableName = ['d' => 'trade_documents', 'o' => 'tr_operations'][$table] ?? $table;
        $has[$tableName] ??= Schema::hasColumn($tableName, 'branch_id');
        if ($has[$tableName] && ($ids = BranchScope::allowed()) !== null) {
            $q->where(fn ($w) => $w->whereIn($col, $ids ?: ['__none__'])->orWhereNull($col));
        }
    }

    /** @return array{0: array<int, string>, 1: iterable<array>} en-têtes et lignes */
    public static function build(Company $c, User $u, string $dataset, int $days = 0): array
    {
        app()->instance('branch_scope', BranchScope::allowedFor($u, $c));
        $since = $days > 0 ? now()->subDays($days)->toDateString() : null;
        $docs = fn (string $dir, array $types) => DB::table('trade_documents as d')->where('d.company_id', $c->id)->whereNull('d.deleted_at')->where('d.direction', $dir)
            ->whereIn('d.type', $types)->where('d.status', 'validated')->when($since, fn ($q) => $q->where('d.date', '>=', $since));
        switch ($dataset) {
            case 'invoices':
                $q = $docs('sale', ['invoice', 'receipt', 'credit_note']);
                self::branches($q, 'd.branch_id', $c);

                return [['Numéro', 'Type', 'Date', 'Échéance', 'Client', 'Total HT', 'TVA', 'Total TTC', 'Payé', 'Reste dû', 'Statut paiement', 'Devise'],
                    $q->orderBy('d.date')->limit(50000)->get()->map(fn ($d) => [$d->number, ['invoice' => 'Facture', 'receipt' => 'Ticket', 'credit_note' => 'Avoir'][$d->type] ?? $d->type, $d->date, $d->due_date,
                        $d->partner_name, (float) $d->subtotal, (float) $d->tax_total, (float) $d->total, (float) $d->amount_paid, round((float) $d->total - (float) $d->amount_paid, 2),
                        ['paid' => 'Payée', 'partial' => 'Partielle', 'unpaid' => 'Impayée'][$d->payment_status] ?? $d->payment_status, $d->currency])];
            case 'sales_lines':
                $q = $docs('sale', ['invoice', 'receipt'])->join('trade_document_lines as l', 'l.document_id', '=', 'd.id')
                    ->leftJoin('products as p', 'p.id', '=', 'l.product_id')->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id');
                self::branches($q, 'd.branch_id', $c);

                return [['Date', 'Numéro', 'Client', 'Référence', 'Article', 'Catégorie', 'Quantité', 'Prix unitaire', 'Remise %', 'Total HT', 'TVA', 'Total TTC'],
                    $q->orderBy('d.date')->limit(100000)->get(['d.date', 'd.number', 'd.partner_name', 'p.sku', 'l.description', 'pc.name as cat', 'l.quantity', 'l.unit_price', 'l.discount_percent', 'l.subtotal', 'l.tax_amount', 'l.total'])
                        ->map(fn ($l) => [$l->date, $l->number, $l->partner_name, $l->sku, $l->description, $l->cat ?? 'Non classé', (float) $l->quantity, (float) $l->unit_price, (float) $l->discount_percent,
                            (float) $l->subtotal, (float) $l->tax_amount, (float) $l->total])];
            case 'payments':
                $q = DB::table('payments as pay')->join('trade_documents as d', 'd.id', '=', 'pay.document_id')->where('pay.company_id', $c->id)->where('pay.direction', 'sale')
                    ->whereNull('pay.deleted_at')->when($since, fn ($w) => $w->where('pay.date', '>=', $since));
                self::branches($q, 'd.branch_id', $c);

                return [['Date', 'Facture', 'Client', 'Montant', 'Moyen', 'Référence'],
                    $q->orderBy('pay.date')->limit(50000)->get(['pay.date', 'd.number', 'd.partner_name', 'pay.amount', 'pay.method', 'pay.reference'])
                        ->map(fn ($p) => [$p->date, $p->number, $p->partner_name, (float) $p->amount, ['cash' => 'Espèces', 'mobile_money' => 'Mobile Money', 'card' => 'Carte', 'bank_transfer' => 'Virement', 'cheque' => 'Chèque'][$p->method] ?? $p->method, $p->reference])];
            case 'customers':
                $tot = DB::table('trade_documents')->where('company_id', $c->id)->whereNull('deleted_at')->where('direction', 'sale')->whereIn('type', ['invoice', 'receipt'])->where('status', 'validated')
                    ->groupBy('partner_id')->selectRaw('partner_id, SUM(total) ca, SUM(total - amount_paid) du, COUNT(*) n, MAX(date) last')->get()->keyBy('partner_id');

                return [['Nom', 'Code', 'Téléphone', 'E-mail', 'Ville', 'Factures', 'Chiffre d\'affaires TTC', 'Reste dû', 'Dernier achat'],
                    DB::table('partners')->where('company_id', $c->id)->whereNull('deleted_at')->whereIn('type', ['customer', 'both'])->orderBy('name')->limit(50000)->get()
                        ->map(fn ($p) => [$p->name, $p->code, $p->phone, $p->email, $p->city, (int) ($tot[$p->id]->n ?? 0), (float) ($tot[$p->id]->ca ?? 0), round((float) ($tot[$p->id]->du ?? 0), 2), $tot[$p->id]->last ?? null])];
            case 'products':
                $stock = DB::table('stock_moves')->where('company_id', $c->id)->whereNull('deleted_at')->groupBy('product_id')->selectRaw('product_id, SUM(quantity) q')->pluck('q', 'product_id');

                return [['Référence', 'Code-barres', 'Article', 'Catégorie', 'Type', 'Prix de vente', 'Prix d\'achat', 'TVA %', 'Stock', 'Stock minimum', 'Valeur du stock'],
                    DB::table('products as p')->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')->where('p.company_id', $c->id)->whereNull('p.deleted_at')->orderBy('p.name')->limit(50000)
                        ->get(['p.*', 'pc.name as cat'])->map(fn ($p) => [$p->sku, $p->barcode, $p->name, $p->cat ?? 'Non classé', $p->type === 'service' ? 'Service' : 'Produit', (float) $p->sale_price,
                            (float) $p->purchase_price, (float) $p->tax_rate, $p->track_stock ? (float) ($stock[$p->id] ?? 0) : null, (float) $p->min_stock,
                            $p->track_stock ? round((float) ($stock[$p->id] ?? 0) * (float) $p->purchase_price, 2) : null])];
            case 'purchases':
                $q = $docs('purchase', ['supplier_invoice', 'supplier_credit']);
                self::branches($q, 'd.branch_id', $c);

                return [['Numéro', 'Référence fournisseur', 'Date', 'Échéance', 'Fournisseur', 'Total HT', 'TVA', 'Total TTC', 'Payé', 'Reste dû'],
                    $q->orderBy('d.date')->limit(50000)->get()->map(fn ($d) => [$d->number, $d->reference, $d->date, $d->due_date, $d->partner_name, (float) $d->subtotal, (float) $d->tax_total,
                        (float) $d->total, (float) $d->amount_paid, round((float) $d->total - (float) $d->amount_paid, 2)])];
            case 'expenses':
                if (! Schema::hasTable('tr_operations')) {
                    return [['Date', 'Compte', 'Catégorie', 'Libellé', 'Montant'], []];
                }
                $cats = class_exists(\App\Modules\Tresorerie\Models\Operation::class) ? \App\Modules\Tresorerie\Models\Operation::CATEGORIES : [];
                $q = DB::table('tr_operations as o')->leftJoin('tr_accounts as a', 'a.id', '=', 'o.account_id')->where('o.company_id', $c->id)->whereNull('o.deleted_at')
                    ->when($since, fn ($w) => $w->where('o.date', '>=', $since));
                self::branches($q, 'o.branch_id', $c);

                return [['Date', 'Compte', 'Catégorie', 'Libellé', 'Montant', 'Référence'],
                    $q->orderBy('o.date')->limit(50000)->get(['o.date', 'a.name as acc', 'o.category', 'o.label', 'o.amount', 'o.reference'])
                        ->map(fn ($o) => [$o->date, $o->acc, $cats[$o->category][0] ?? $o->category, $o->label, (float) $o->amount, $o->reference])];
        }
        throw new \DomainException('Jeu de données inconnu.');
    }
}
