<?php

namespace App\Modules\Ia;

use App\Models\Company;
use App\Models\User;
use App\Support\BranchScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Outils de lecture mis à la disposition de l'assistant : chaque outil vérifie les droits de l'utilisateur
 * et les agences auxquelles il a accès. Aucun outil ne modifie de donnée.
 */
class AiTools
{
    public function __construct(protected Company $c, protected User $u) {}

    protected function can(string $perms, string $what): void
    {
        if (! $this->u->hasPermission($perms)) {
            throw new \DomainException('L\'utilisateur n\'a pas accès à '.$what.'.');
        }
    }

    protected function period(array $a): array
    {
        $from = isset($a['du']) ? Carbon::parse($a['du'])->toDateString() : now()->startOfMonth()->toDateString();
        $to = isset($a['au']) ? Carbon::parse($a['au'])->toDateString() : now()->toDateString();

        return [$from, $to];
    }

    protected function docs(string $direction)
    {
        $q = DB::table('trade_documents as d')->where('d.company_id', $this->c->id)->whereNull('d.deleted_at')->where('d.direction', $direction)->where('d.status', 'validated');
        if (($ids = BranchScope::filter()) !== null) {
            $q->whereIn('d.branch_id', $ids ?: ['__none__']);
        }

        return $q;
    }

    protected function sales()
    {
        return $this->docs('sale')->whereIn('d.type', ['invoice', 'receipt', 'credit_note'])->where(fn ($w) => $w->whereNull('d.is_opening')->orWhere('d.is_opening', false));
    }

    protected const SIGN = "(CASE WHEN d.type = 'credit_note' THEN -1 ELSE 1 END)";

    protected const RATE = 'COALESCE(d.exchange_rate, 1)';

    public function definitions(): array
    {
        $period = ['du' => ['type' => 'string', 'description' => 'Date de début AAAA-MM-JJ (défaut : début du mois)'], 'au' => ['type' => 'string', 'description' => 'Date de fin AAAA-MM-JJ (défaut : aujourd\'hui)']];
        $all = [
            ['ventes.view|ventes.manage|pos.use', 'ventes_resume', 'Chiffre d\'affaires d\'une période : HT, TTC, nombre de factures et de tickets, panier moyen, encaissé, reste à encaisser, avoirs.', $period],
            ['ventes.view|ventes.manage', 'ventes_par_mois', 'Chiffre d\'affaires HT mois par mois sur une année.', ['annee' => ['type' => 'integer']]],
            ['ventes.view|ventes.manage', 'meilleurs_articles', 'Articles les plus vendus d\'une période (chiffre d\'affaires, quantités, marge estimée).', $period + ['limite' => ['type' => 'integer'], 'critere' => ['type' => 'string', 'enum' => ['ca', 'quantite', 'marge']]]],
            ['ventes.view|ventes.manage', 'meilleurs_clients', 'Clients qui achètent le plus sur une période.', $period + ['limite' => ['type' => 'integer']]],
            ['ventes.view|ventes.manage|relances.view|relances.manage', 'factures_impayees', 'Factures de vente non soldées (client, montant dû, échéance, retard en jours), les plus anciennes ou les plus fortes d\'abord.', ['en_retard_seulement' => ['type' => 'boolean'], 'limite' => ['type' => 'integer'], 'tri' => ['type' => 'string', 'enum' => ['retard', 'montant']]]],
            ['core.contacts.view|ventes.view|achats.view', 'rechercher_tiers', 'Cherche un client ou un fournisseur par nom, téléphone ou NCC ; renvoie ses coordonnées et ce qu\'il doit ou ce qu\'on lui doit.', ['texte' => ['type' => 'string']]],
            ['ventes.view|achats.view', 'document', 'Détail d\'une facture, d\'un devis, d\'une commande ou d\'une facture fournisseur à partir de son numéro.', ['numero' => ['type' => 'string']]],
            ['achats.view|achats.manage', 'achats_resume', 'Achats d\'une période : total HT, par fournisseur, factures fournisseurs à payer.', $period],
            ['stock.view|stock.manage', 'stock_alertes', 'Articles en rupture ou sous leur stock minimum, avec la quantité disponible.', ['limite' => ['type' => 'integer']]],
            ['stock.view|stock.manage', 'stock_article', 'Quantité en stock d\'un article par dépôt.', ['article' => ['type' => 'string']]],
            ['tresorerie.view|tresorerie.manage|tresorerie.operate', 'tresorerie_soldes', 'Soldes actuels des caisses, comptes bancaires et Mobile Money.', []],
            ['tresorerie.view|tresorerie.manage|tresorerie.operate', 'depenses', 'Dépenses diverses d\'une période par catégorie (loyer, électricité, transport…).', $period],
            ['agences.view|agences.manage', 'agences_comparatif', 'Comparatif des agences sur une période : CA, marge, dépenses, résultat estimé, trésorerie, stock.', $period],
        ];
        $out = [];
        foreach ($all as [$perm, $name, $desc, $props]) {
            $module = match (true) {
                str_starts_with($name, 'stock') => 'stock', str_starts_with($name, 'tresorerie') || $name === 'depenses' => 'tresorerie',
                str_starts_with($name, 'achats') => 'achats', $name === 'agences_comparatif' => 'agences', $name === 'rechercher_tiers' => 'core', default => 'ventes',
            };
            if (($module === 'core' || $this->c->hasModule($module)) && $this->u->hasPermission($perm)) {
                $out[] = ['name' => $name, 'description' => $desc, 'parameters' => ['type' => 'object', 'properties' => (object) $props]];
            }
        }

        return $out;
    }

    public function run(string $name, array $a): array
    {
        $allowed = array_column($this->definitions(), 'name');
        if (! in_array($name, $allowed, true)) {
            throw new \DomainException('Outil indisponible pour cet utilisateur : '.$name.'.');
        }

        return $this->{'t_'.$name}($a);
    }

    protected function t_ventes_resume(array $a): array
    {
        [$from, $to] = $this->period($a);
        $s = self::SIGN;
        $r = self::RATE;
        $row = $this->sales()->whereBetween('d.date', [$from, $to])->selectRaw("SUM($s * d.subtotal * $r) ht, SUM($s * d.total * $r) ttc, SUM(CASE WHEN d.type = 'invoice' THEN 1 ELSE 0 END) factures,
            SUM(CASE WHEN d.type = 'receipt' THEN 1 ELSE 0 END) tickets, SUM(CASE WHEN d.type = 'credit_note' THEN d.total * $r ELSE 0 END) avoirs,
            SUM(CASE WHEN d.type <> 'credit_note' THEN d.amount_paid * $r ELSE 0 END) encaisse, SUM(CASE WHEN d.type = 'invoice' THEN (d.total - d.amount_paid) * $r ELSE 0 END) reste")->first();
        $n = (int) $row->factures + (int) $row->tickets;

        return ['periode' => compact('from', 'to'), 'monnaie' => $this->c->currency, 'ca_ht' => round((float) $row->ht), 'ca_ttc' => round((float) $row->ttc), 'factures' => (int) $row->factures,
            'tickets_caisse' => (int) $row->tickets, 'panier_moyen_ttc' => $n ? round(((float) $row->ttc + (float) $row->avoirs) / $n) : 0, 'avoirs_ttc' => round((float) $row->avoirs),
            'encaisse' => round((float) $row->encaisse), 'reste_a_encaisser' => round((float) $row->reste)];
    }

    protected function t_ventes_par_mois(array $a): array
    {
        $y = (int) ($a['annee'] ?? now()->year);
        $s = self::SIGN;
        $r = self::RATE;
        $m = \App\Modules\Rapports\Sql::bucket('month', 'd.date');
        $rows = $this->sales()->whereBetween('d.date', ["$y-01-01", "$y-12-31"])->groupBy(\Illuminate\Support\Facades\DB::raw($m))->selectRaw("$m mois, SUM($s * d.subtotal * $r) ht, COUNT(*) n")->orderBy('mois')->get();

        return ['annee' => $y, 'monnaie' => $this->c->currency, 'mois' => $rows->map(fn ($x) => ['mois' => $x->mois, 'ca_ht' => round((float) $x->ht), 'documents' => (int) $x->n])->all()];
    }

    protected function t_meilleurs_articles(array $a): array
    {
        [$from, $to] = $this->period($a);
        $lim = max(1, min(30, (int) ($a['limite'] ?? 10)));
        $s = self::SIGN;
        $r = self::RATE;
        $order = match ($a['critere'] ?? 'ca') { 'quantite' => 'qte', 'marge' => 'marge', default => 'ca' };
        $rows = $this->sales()->join('trade_document_lines as l', 'l.document_id', '=', 'd.id')->leftJoin('products as p', 'p.id', '=', 'l.product_id')->whereBetween('d.date', [$from, $to])
            ->groupBy(DB::raw('COALESCE(p.name, l.description)'))->selectRaw("COALESCE(p.name, l.description) article, SUM($s * l.quantity) qte, SUM($s * l.subtotal * $r) ca,
                SUM(CASE WHEN p.purchase_price > 0 THEN $s * (l.subtotal * $r - l.quantity * COALESCE(l.unit_factor, 1) * p.purchase_price) ELSE 0 END) marge")
            ->orderByDesc($order)->limit($lim)->get();

        return ['periode' => compact('from', 'to'), 'articles' => $rows->map(fn ($x) => ['article' => $x->article, 'quantite' => round((float) $x->qte, 2), 'ca_ht' => round((float) $x->ca), 'marge_estimee' => round((float) $x->marge)])->all()];
    }

    protected function t_meilleurs_clients(array $a): array
    {
        [$from, $to] = $this->period($a);
        $lim = max(1, min(30, (int) ($a['limite'] ?? 10)));
        $s = self::SIGN;
        $r = self::RATE;
        // clients identifiés seulement (les ventes au comptoir sans client ne comptent pas)
        $rows = $this->sales()->join('partners as p', 'p.id', '=', 'd.partner_id')->whereBetween('d.date', [$from, $to])
            ->groupBy(DB::raw("COALESCE(p.name, d.partner_name, 'Client comptoir')"))->selectRaw("COALESCE(p.name, d.partner_name, 'Client comptoir') client, SUM($s * d.subtotal * $r) ca, COUNT(*) n")
            ->orderByDesc('ca')->limit($lim)->get();

        return ['periode' => compact('from', 'to'), 'clients' => $rows->map(fn ($x) => ['client' => $x->client, 'ca_ht' => round((float) $x->ca), 'documents' => (int) $x->n])->all()];
    }

    protected function t_factures_impayees(array $a): array
    {
        $lim = max(1, min(50, (int) ($a['limite'] ?? 15)));
        $today = now()->toDateString();
        $q = $this->docs('sale')->leftJoin('partners as p', 'p.id', '=', 'd.partner_id')->where('d.type', 'invoice')->whereIn('d.payment_status', ['unpaid', 'partial']);
        if (! empty($a['en_retard_seulement'])) {
            $q->where('d.due_date', '<', $today);
        }
        $total = (clone $q)->selectRaw('SUM((d.total - d.amount_paid) * '.self::RATE.') s, COUNT(*) n')->first();
        $rows = $q->select(['d.number', 'd.date', 'd.due_date', 'd.total', 'd.amount_paid', 'd.exchange_rate', DB::raw("COALESCE(p.name, d.partner_name) client"), 'p.phone'])
            ->when(($a['tri'] ?? 'retard') === 'montant', fn ($w) => $w->orderByRaw('(d.total - d.amount_paid) DESC'), fn ($w) => $w->orderBy('d.due_date'))->limit($lim)->get();

        return ['nombre' => (int) $total->n, 'total_du' => round((float) $total->s), 'monnaie' => $this->c->currency, 'factures' => $rows->map(fn ($x) => [
            'numero' => $x->number, 'client' => $x->client, 'telephone' => $x->phone, 'date' => $x->date, 'echeance' => $x->due_date,
            'du' => round(((float) $x->total - (float) $x->amount_paid) * (float) ($x->exchange_rate ?: 1)),
            'retard_jours' => $x->due_date && $x->due_date < $today ? Carbon::parse($x->due_date)->diffInDays(now()->startOfDay()) : 0,
        ])->all()];
    }

    protected function t_rechercher_tiers(array $a): array
    {
        $t = trim((string) ($a['texte'] ?? ''));
        if (mb_strlen($t) < 2) {
            throw new \DomainException('Texte de recherche trop court.');
        }
        $rows = DB::table('partners')->where('company_id', $this->c->id)->whereNull('deleted_at')
            ->where(fn ($w) => $w->where('name', 'like', "%$t%")->orWhere('phone', 'like', "%$t%")->orWhere('tax_id', 'like', "%$t%")->orWhere('email', 'like', "%$t%"))
            ->limit(5)->get(['id', 'name', 'type', 'phone', 'email', 'city', 'tax_id']);

        return ['resultats' => $rows->map(function ($p) {
            $due = $this->docs('sale')->where('d.partner_id', $p->id)->where('d.type', 'invoice')->sum(DB::raw('(d.total - d.amount_paid) * '.self::RATE));
            $owe = $this->docs('purchase')->where('d.partner_id', $p->id)->where('d.type', 'supplier_invoice')->sum(DB::raw('(d.total - d.amount_paid) * '.self::RATE));
            $last = $this->sales()->where('d.partner_id', $p->id)->max('d.date');

            return ['nom' => $p->name, 'type' => $p->type, 'telephone' => $p->phone, 'email' => $p->email, 'ville' => $p->city, 'ncc' => $p->tax_id,
                'nous_doit' => round((float) $due), 'nous_lui_devons' => round((float) $owe), 'dernier_achat' => $last];
        })->all()];
    }

    protected function t_document(array $a): array
    {
        $num = trim((string) ($a['numero'] ?? ''));
        $q = DB::table('trade_documents as d')->where('d.company_id', $this->c->id)->whereNull('d.deleted_at')->where(fn ($w) => $w->where('d.number', $num)->orWhere('d.reference', $num))
            ->whereIn('d.direction', array_keys(array_filter(['sale' => $this->u->hasPermission('ventes.view|ventes.manage'), 'purchase' => $this->u->hasPermission('achats.view|achats.manage')])));
        if (($ids = BranchScope::filter()) !== null) {
            $q->whereIn('d.branch_id', $ids ?: ['__none__']);
        }
        $d = $q->first();
        if (! $d) {
            return ['trouve' => false];
        }
        $lines = DB::table('trade_document_lines')->where('document_id', $d->id)->orderBy('sort')->get(['description', 'quantity', 'unit_price', 'tax_rate', 'subtotal']);

        return ['trouve' => true, 'numero' => $d->number, 'type' => $d->type, 'sens' => $d->direction === 'sale' ? 'vente' : 'achat', 'tiers' => $d->partner_name ?? DB::table('partners')->where('id', $d->partner_id)->value('name'),
            'date' => $d->date, 'echeance' => $d->due_date, 'statut' => $d->status, 'reglement' => $d->payment_status, 'total_ht' => (float) $d->subtotal, 'total_ttc' => (float) $d->total,
            'paye' => (float) $d->amount_paid, 'devise' => $d->currency, 'lignes' => $lines->map(fn ($l) => ['designation' => $l->description, 'quantite' => (float) $l->quantity, 'prix_unitaire_ht' => (float) $l->unit_price, 'tva' => (float) $l->tax_rate, 'total_ht' => (float) $l->subtotal])->all()];
    }

    protected function t_achats_resume(array $a): array
    {
        [$from, $to] = $this->period($a);
        $q = $this->docs('purchase')->where('d.type', 'supplier_invoice');
        $tot = (clone $q)->whereBetween('d.date', [$from, $to])->selectRaw('SUM(d.subtotal * '.self::RATE.') ht, COUNT(*) n')->first();
        $by = (clone $q)->whereBetween('d.date', [$from, $to])->leftJoin('partners as p', 'p.id', '=', 'd.partner_id')->groupBy(DB::raw('COALESCE(p.name, d.partner_name)'))
            ->selectRaw('COALESCE(p.name, d.partner_name) fournisseur, SUM(d.subtotal * '.self::RATE.') ht')->orderByDesc('ht')->limit(10)->get();
        $due = (clone $q)->whereIn('d.payment_status', ['unpaid', 'partial'])->sum(DB::raw('(d.total - d.amount_paid) * '.self::RATE));

        return ['periode' => compact('from', 'to'), 'achats_ht' => round((float) $tot->ht), 'factures' => (int) $tot->n, 'a_payer_aux_fournisseurs' => round((float) $due),
            'par_fournisseur' => $by->map(fn ($x) => ['fournisseur' => $x->fournisseur, 'ht' => round((float) $x->ht)])->all()];
    }

    protected function stockLevels()
    {
        $q = DB::table('stock_moves as m')->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')->where('m.company_id', $this->c->id)->whereNull('m.deleted_at');
        if (($ids = BranchScope::filter()) !== null) {
            $q->where(fn ($w) => $w->whereIn('w.branch_id', $ids ?: ['__none__'])->orWhereNull('w.branch_id'));
        }

        return $q;
    }

    protected function t_stock_alertes(array $a): array
    {
        $lim = max(1, min(50, (int) ($a['limite'] ?? 20)));
        $qty = $this->stockLevels()->groupBy('m.product_id')->selectRaw('m.product_id, SUM(m.quantity) q')->pluck('q', 'product_id');
        $products = DB::table('products')->where('company_id', $this->c->id)->whereNull('deleted_at')->where('type', 'product')->where('active', true)
            ->get(['id', 'name', 'min_stock', 'unit']);
        $rows = [];
        foreach ($products as $p) {
            $q = (float) ($qty[$p->id] ?? 0);
            if ($q <= 0 || ($p->min_stock > 0 && $q < $p->min_stock)) {
                $rows[] = ['article' => $p->name, 'quantite' => round($q, 2), 'stock_minimum' => (float) $p->min_stock, 'unite' => $p->unit, 'rupture' => $q <= 0];
            }
        }
        usort($rows, fn ($x, $y) => $x['quantite'] <=> $y['quantite']);

        return ['nombre' => count($rows), 'articles' => array_slice($rows, 0, $lim)];
    }

    protected function t_stock_article(array $a): array
    {
        $t = trim((string) ($a['article'] ?? ''));
        $p = DB::table('products')->where('company_id', $this->c->id)->whereNull('deleted_at')->where(fn ($w) => $w->where('name', 'like', "%$t%")->orWhere('sku', $t)->orWhere('barcode', $t))->first(['id', 'name', 'unit', 'sale_price', 'purchase_price']);
        if (! $p) {
            return ['trouve' => false];
        }
        $rows = $this->stockLevels()->where('m.product_id', $p->id)->groupBy('w.name')->selectRaw('w.name depot, SUM(m.quantity) q')->get();

        return ['trouve' => true, 'article' => $p->name, 'unite' => $p->unit, 'prix_vente_ht' => (float) $p->sale_price, 'total' => round($rows->sum('q'), 2),
            'par_depot' => $rows->map(fn ($x) => ['depot' => $x->depot, 'quantite' => round((float) $x->q, 2)])->all()];
    }

    protected function t_tresorerie_soldes(array $a): array
    {
        $svc = app(\App\Modules\Tresorerie\TreasuryService::class);
        $accounts = \App\Modules\Tresorerie\Models\TreasuryAccount::where('active', true)->orderBy('sort')->get();

        return ['monnaie' => $this->c->currency, 'comptes' => $accounts->map(fn ($x) => ['compte' => $x->name, 'type' => $x->kind, 'solde' => round($svc->balance($x))])->all(),
            'total' => round($accounts->sum(fn ($x) => $svc->balance($x)))];
    }

    protected function t_depenses(array $a): array
    {
        [$from, $to] = $this->period($a);
        $cats = \App\Modules\Tresorerie\Models\Operation::CATEGORIES;
        $rows = \App\Modules\Tresorerie\Models\Operation::where('amount', '<', 0)->whereNull('transfer_id')->whereBetween('date', [$from, $to])
            ->groupBy('category')->selectRaw('category, SUM(-amount) s, COUNT(*) n')->orderByDesc('s')->get();

        return ['periode' => compact('from', 'to'), 'total' => round((float) $rows->sum('s')), 'par_categorie' => $rows->map(fn ($x) => ['categorie' => $cats[$x->category][0] ?? ($x->category ?: 'Autre'), 'montant' => round((float) $x->s), 'operations' => (int) $x->n])->all()];
    }

    protected function t_agences_comparatif(array $a): array
    {
        [$from, $to] = $this->period($a);
        $s = app(\App\Modules\Agences\BranchService::class)->summary($this->c, $from, $to);

        return ['periode' => compact('from', 'to'), 'agences' => array_map(fn ($r) => array_intersect_key($r, array_flip(['name', 'sales', 'margin', 'expenses', 'result', 'cash', 'stock_value', 'receivables', 'tickets', 'invoices'])), $s['rows'])];
    }
}
