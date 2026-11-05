<?php

namespace App\Modules\TableauxExcel;

use App\Models\Company;
use App\Support\BranchScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modèles de tableaux de bord Excel : chaque modèle extrait ses données (dans la limite des agences de l'utilisateur)
 * et décrit filtres, indicateurs, graphiques et classements pour XlsxDashboard.
 */
class Templates
{
    public const MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

    public const AGING = ['Non échu', '1 à 30 j', '31 à 60 j', '61 à 90 j', 'Plus de 90 j'];

    public const STATUSES = ['Rupture', 'Sous le seuil', 'Normal', 'Surstock', 'Sans vente (90 j)'];

    /** Catalogue (affiché dans l'application). */
    public static function catalog(): array
    {
        return [
            'direction' => ['name' => 'Synthèse direction', 'icon' => 'insights', 'requires' => ['ventes'], 'period' => true,
                'description' => 'Chiffre d\'affaires, charges, résultat estimé et trésorerie nette, comparés à l\'année précédente ; courbe mensuelle N / N-1, charges par nature, encaissements et décaissements, chiffre d\'affaires par agence, meilleurs clients.',
                'filters' => ['Année', 'Mois', 'Agence'], 'charts' => 4],
            'ventes' => ['name' => 'Analyse des ventes', 'icon' => 'shopping_cart', 'requires' => ['ventes'], 'period' => true,
                'description' => 'Chiffre d\'affaires, marge et taux de marge, quantités, par mois, catégorie, article, client et agence, avec la comparaison à l\'année précédente.',
                'filters' => ['Année', 'Mois', 'Agence', 'Catégorie'], 'charts' => 4],
            'tresorerie' => ['name' => 'Trésorerie & encaissements', 'icon' => 'account_balance_wallet', 'requires' => ['ventes'], 'period' => true,
                'description' => 'Entrées et sorties d\'argent par mois, flux net, sorties par catégorie, encaissements par moyen de paiement et par compte, principaux clients payeurs.',
                'filters' => ['Année', 'Mois', 'Compte / moyen'], 'charts' => 3],
            'creances' => ['name' => 'Clients & créances', 'icon' => 'request_quote', 'requires' => ['ventes'], 'period' => false,
                'description' => 'Balance âgée des factures impayées (non échu, 30, 60, 90 jours et plus), principaux débiteurs, reste dû par agence, factures à relancer.',
                'filters' => ['Agence', 'Ancienneté'], 'charts' => 3],
            'stock' => ['name' => 'Stock & rotation', 'icon' => 'inventory_2', 'requires' => ['stock'], 'period' => false,
                'description' => 'Valeur du stock par catégorie, articles en rupture ou sous le seuil, ventes des 90 derniers jours et couverture en jours, articles qui immobilisent le plus de valeur.',
                'filters' => ['Catégorie', 'Statut'], 'charts' => 3],
            'achats' => ['name' => 'Achats & fournisseurs', 'icon' => 'local_shipping', 'requires' => ['achats'], 'period' => true,
                'description' => 'Achats HT par mois comparés à l\'année précédente, par fournisseur et par catégorie, reste à payer aux fournisseurs.',
                'filters' => ['Année', 'Mois', 'Agence', 'Catégorie'], 'charts' => 3],
        ];
    }

    public static function available(Company $c): array
    {
        return array_filter(self::catalog(), fn ($t) => collect($t['requires'])->every(fn ($m) => $c->hasModule($m)));
    }

    protected Company $c;

    protected ?array $branches;

    protected array $branchNames = [];

    protected array $partnerNames = [];

    protected bool $rates;

    /** @param array{template:string, year?:?int, theme?:string, title?:?string, protect?:bool} $o */
    public function spec(Company $c, array $o): array
    {
        $this->c = $c;
        $this->branches = BranchScope::filter();
        $this->rates = Schema::hasColumn('trade_documents', 'exchange_rate');
        $this->branchNames = Schema::hasTable('branches') ? DB::table('branches')->where('company_id', $c->id)->pluck('name', 'id')->all() : [];
        $tpl = $o['template'];
        $meta = self::catalog()[$tpl] ?? throw new \DomainException('Modèle inconnu.');
        $year = (int) ($o['year'] ?? now()->year);
        $spec = match ($tpl) {
            'direction' => $this->direction($year),
            'ventes' => $this->ventes($year),
            'tresorerie' => $this->tresorerie($year),
            'creances' => $this->creances(),
            'stock' => $this->stock(),
            'achats' => $this->achats($year),
        };
        $scope = $this->branches ? ' · '.implode(', ', array_map(fn ($b) => $this->branchNames[$b] ?? '', $this->branches)) : '';
        $period = $meta['period'] ? 'Exercices '.($year - 1).' et '.$year.($year === now()->year ? ' (au '.now()->format('d/m/Y').')' : '') : 'Situation au '.now()->format('d/m/Y');

        return $spec + [
            'title' => ($o['title'] ?? null) ?: $meta['name'],
            'subtitle' => $period.$scope.' · montants HT en '.$this->currencyLabel(),
            'company' => $c->name,
            'currency' => $c->currency ?: 'XOF',
            'theme' => $o['theme'] ?? 'ocean',
            'protect' => (bool) ($o['protect'] ?? false),
        ];
    }

    protected function currencyLabel(): string
    {
        return in_array($this->c->currency, ['XOF', 'XAF', null, ''], true) ? 'F CFA' : $this->c->currency;
    }

    // ------------------------------------------------------------------ Outils

    protected function docs(string $direction)
    {
        return DB::table('trade_documents as d')->where('d.company_id', $this->c->id)->where('d.direction', $direction)->where('d.status', 'validated')->whereNull('d.deleted_at')
            ->when(Schema::hasColumn('trade_documents', 'is_opening'), fn ($q) => $q->where(fn ($w) => $w->whereNull('d.is_opening')->orWhere('d.is_opening', false)))
            ->when($this->branches, fn ($q) => $q->whereIn('d.branch_id', $this->branches));
    }

    protected function rate(): string
    {
        return $this->rates ? 'COALESCE(d.exchange_rate, 1)' : '1';
    }

    protected function partner(?string $id, string $fallback = 'Client comptoir'): string
    {
        if (! $id) {
            return $fallback;
        }
        if (! array_key_exists($id, $this->partnerNames)) {
            $this->partnerNames[$id] = (string) DB::table('partners')->where('id', $id)->value('name');
        }

        return $this->partnerNames[$id] ?: $fallback;
    }

    protected function preloadPartners(iterable $ids): void
    {
        $ids = collect($ids)->filter()->unique()->diff(array_keys($this->partnerNames))->values();
        foreach ($ids->chunk(500) as $chunk) {
            $this->partnerNames += DB::table('partners')->whereIn('id', $chunk)->pluck('name', 'id')->all();
        }
    }

    protected function branch(?string $id): string
    {
        return $id ? ($this->branchNames[$id] ?? 'Agence') : 'Siège / sans agence';
    }

    /** Libellé court et sur une ligne (désignations libres parfois très longues). */
    protected function label(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', strtok($s, "\n") ?: $s));

        return mb_strlen($s) > 48 ? rtrim(mb_substr($s, 0, 46)).'…' : $s;
    }

    protected function month(string $date): string
    {
        $m = (int) substr($date, 5, 2);

        return sprintf('%02d · %s', $m, self::MONTHS[$m - 1]);
    }

    protected function months(): array
    {
        return array_map(fn ($m) => sprintf('%02d · %s', $m, self::MONTHS[$m - 1]), range(1, 12));
    }

    protected function period(int $year): array
    {
        return [($year - 1).'-01-01', min($year.'-12-31', now()->toDateString())];
    }

    // ------------------------------------------------------------------ Ventes

    protected function salesLines(int $year): array
    {
        [$from, $to] = $this->period($year);
        $rows = $this->docs('sale')->whereIn('d.type', ['invoice', 'receipt', 'credit_note'])->whereBetween('d.date', [$from, $to])
            ->join('trade_document_lines as l', 'l.document_id', '=', 'd.id')
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')
            ->orderByDesc('d.date')->limit(30000)
            ->selectRaw('d.date, d.number, d.type, d.partner_id, d.branch_id, '.$this->rate().' rate, l.product_id, l.description, l.quantity, l.subtotal, p.name pname, p.purchase_price pp, pc.name cat')
            ->get();
        $this->preloadPartners($rows->pluck('partner_id'));
        $out = [];
        foreach ($rows->reverse() as $r) {
            $sign = $r->type === 'credit_note' ? -1 : 1;
            $ca = round($sign * (float) $r->subtotal * (float) $r->rate, 2);
            $cost = (float) $r->pp > 0 ? round($sign * (float) $r->quantity * (float) $r->pp, 2) : null;
            $date = substr((string) $r->date, 0, 10);
            $out[] = [
                'annee' => substr($date, 0, 4), 'mois' => $this->month($date), 'date' => $date, 'piece' => (string) $r->number,
                'client' => $this->partner($r->partner_id), 'agence' => $this->branch($r->branch_id),
                'categorie' => $r->product_id ? ($r->cat ?: 'Articles non classés') : 'Prestations & services',
                'article' => $this->label($r->pname ?: (trim((string) $r->description) ?: 'Ligne libre')),
                'qte' => $sign * (float) $r->quantity, 'ca' => $ca, 'cout' => $cost ?? 0, 'marge' => $cost === null ? 0 : $ca - $cost, 'base' => $cost === null ? 0 : $ca,
            ];
        }

        return $out;
    }

    protected function ventes(int $year): array
    {
        $y = (string) $year;

        return [
            'columns' => [
                'annee' => ['label' => 'Année', 'width' => 8], 'mois' => ['label' => 'Mois', 'width' => 11], 'date' => ['label' => 'Date', 'type' => 'date', 'width' => 11],
                'piece' => ['label' => 'Pièce', 'width' => 16], 'client' => ['label' => 'Client', 'width' => 26], 'agence' => ['label' => 'Agence', 'width' => 18],
                'categorie' => ['label' => 'Catégorie', 'width' => 20], 'article' => ['label' => 'Article', 'width' => 30], 'qte' => ['label' => 'Quantité', 'type' => 'number', 'width' => 10],
                'ca' => ['label' => 'CA HT', 'type' => 'money'], 'cout' => ['label' => 'Coût d\'achat', 'type' => 'money'], 'marge' => ['label' => 'Marge', 'type' => 'money'],
                'base' => ['label' => 'CA avec coût connu', 'type' => 'money', 'width' => 16],
            ],
            'rows' => $this->salesLines($year),
            'year' => 'annee',
            'filters' => [
                ['col' => 'annee', 'label' => 'Année', 'default' => $y, 'sort' => 'desc'],
                ['col' => 'mois', 'label' => 'Mois', 'values' => $this->months()],
                ['col' => 'agence', 'label' => 'Agence'],
                ['col' => 'categorie', 'label' => 'Catégorie'],
            ],
            'kpis' => [
                ['label' => 'Chiffre d\'affaires HT', 'sum' => 'ca', 'compare' => true],
                ['label' => 'Marge brute', 'sum' => 'marge', 'compare' => true],
                ['label' => 'Taux de marge', 'ratio' => [1, 4], 'format' => 'pct', 'compare' => true, 'note' => 'Sur les articles dont le coût est connu'],
                ['label' => 'Quantités vendues', 'sum' => 'qte', 'format' => 'number', 'compare' => true],
                ['label' => 'base', 'sum' => 'base', 'hidden' => true],
            ],
            'charts' => [
                ['title' => 'Chiffre d\'affaires par mois — comparaison annuelle', 'type' => 'line', 'wide' => true, 'gap_future' => true, 'by' => 'mois', 'categories' => $this->months(),
                    'series' => [['label' => '"CA "', 'sum' => 'ca', 'year' => 'current'], ['label' => '"CA "', 'sum' => 'ca', 'year' => 'previous']]],
                ['title' => 'Répartition par catégorie', 'type' => 'doughnut', 'by' => 'categorie', 'top' => 6, 'series' => [['label' => 'CA HT', 'sum' => 'ca']]],
                ['title' => 'Top 10 articles', 'type' => 'bar', 'by' => 'article', 'top' => 10, 'series' => [['label' => 'CA HT', 'sum' => 'ca']]],
                ['title' => 'Chiffre d\'affaires et marge par agence', 'type' => 'column', 'by' => 'agence', 'series' => [['label' => 'CA HT', 'sum' => 'ca'], ['label' => 'Marge', 'sum' => 'marge']]],
                ['title' => 'Marge par mois', 'type' => 'column', 'by' => 'mois', 'categories' => $this->months(), 'ignore' => ['mois'], 'series' => [['label' => 'Marge', 'sum' => 'marge']]],
            ],
            'tables' => [
                ['title' => 'Meilleurs clients', 'by' => 'client', 'sum' => 'ca', 'top' => 10, 'value_label' => 'CA HT'],
                ['title' => 'Articles les plus rentables', 'by' => 'article', 'sum' => 'marge', 'top' => 10, 'value_label' => 'Marge'],
            ],
        ];
    }

    // ------------------------------------------------------------------ Direction

    protected function direction(int $year): array
    {
        [$from, $to] = $this->period($year);
        $rows = [];
        $add = function (string $date, string $flux, string $nature, string $cat, string $tiers, ?string $branch, float $amount) use (&$rows) {
            if (abs($amount) < 0.005) {
                return;
            }
            $rows[] = ['annee' => substr($date, 0, 4), 'mois' => $this->month($date), 'date' => $date, 'flux' => $flux, 'nature' => $nature, 'categorie' => $cat,
                'tiers' => $tiers, 'agence' => $this->branch($branch), 'montant' => round($amount, 2)];
        };
        $sales = $this->docs('sale')->whereIn('d.type', ['invoice', 'receipt', 'credit_note'])->whereBetween('d.date', [$from, $to])
            ->selectRaw("d.date, d.partner_id, d.branch_id, (CASE WHEN d.type = 'credit_note' THEN -1 ELSE 1 END) * d.subtotal * ".$this->rate().' v')->orderBy('d.date')->get();
        $this->preloadPartners($sales->pluck('partner_id'));
        foreach ($sales as $s) {
            $add(substr((string) $s->date, 0, 10), 'Chiffre d\'affaires', 'Produit', 'Ventes', $this->partner($s->partner_id), $s->branch_id, (float) $s->v);
        }
        if ($this->c->hasModule('achats')) {
            $buys = $this->docs('purchase')->whereIn('d.type', ['supplier_invoice', 'supplier_credit'])->whereBetween('d.date', [$from, $to])
                ->selectRaw("d.date, d.partner_id, d.branch_id, (CASE WHEN d.type = 'supplier_credit' THEN -1 ELSE 1 END) * d.subtotal * ".$this->rate().' v')->get();
            $this->preloadPartners($buys->pluck('partner_id'));
            foreach ($buys as $b) {
                $add(substr((string) $b->date, 0, 10), 'Charges', 'Charge', 'Achats de marchandises et services', $this->partner($b->partner_id, 'Fournisseur'), $b->branch_id, (float) $b->v);
            }
        }
        $ops = $this->operations($from, $to);
        foreach ($ops as $o) {
            if ((float) $o->amount < 0) {
                $add(substr((string) $o->date, 0, 10), 'Charges', 'Charge', \App\Modules\Tresorerie\Models\Operation::CATEGORIES[$o->category][0] ?? 'Autres dépenses', $o->label, $o->branch_id, -(float) $o->amount);
                $add(substr((string) $o->date, 0, 10), 'Décaissements', 'Flux', \App\Modules\Tresorerie\Models\Operation::CATEGORIES[$o->category][0] ?? 'Autres dépenses', $o->label, $o->branch_id, -(float) $o->amount);
            } else {
                $add(substr((string) $o->date, 0, 10), 'Encaissements', 'Flux', \App\Modules\Tresorerie\Models\Operation::CATEGORIES[$o->category][0] ?? 'Autres recettes', $o->label, $o->branch_id, (float) $o->amount);
            }
        }
        foreach ($this->payments($from, $to) as $p) {
            $in = $p->direction === 'sale';
            $add(substr((string) $p->date, 0, 10), $in ? 'Encaissements' : 'Décaissements', 'Flux', $in ? 'Règlements clients' : 'Règlements fournisseurs',
                $this->partner($p->partner_id, $in ? 'Client' : 'Fournisseur'), $p->branch_id, (float) $p->v);
        }
        usort($rows, fn ($a, $b) => strcmp($a['date'], $b['date']));
        $months = $this->months();

        return [
            'columns' => [
                'annee' => ['label' => 'Année', 'width' => 8], 'mois' => ['label' => 'Mois', 'width' => 11], 'date' => ['label' => 'Date', 'type' => 'date', 'width' => 11],
                'flux' => ['label' => 'Flux', 'width' => 18], 'nature' => ['label' => 'Nature', 'width' => 10], 'categorie' => ['label' => 'Catégorie', 'width' => 30],
                'tiers' => ['label' => 'Tiers / libellé', 'width' => 30], 'agence' => ['label' => 'Agence', 'width' => 18], 'montant' => ['label' => 'Montant', 'type' => 'money', 'width' => 16],
            ],
            'rows' => $rows,
            'year' => 'annee',
            'filters' => [
                ['col' => 'annee', 'label' => 'Année', 'default' => (string) $year, 'sort' => 'desc'],
                ['col' => 'mois', 'label' => 'Mois', 'values' => $months],
                ['col' => 'agence', 'label' => 'Agence'],
            ],
            'kpis' => [
                ['label' => 'Chiffre d\'affaires HT', 'sum' => 'montant', 'where' => ['flux' => 'Chiffre d\'affaires'], 'compare' => true],
                ['label' => 'Charges (achats et dépenses)', 'sum' => 'montant', 'where' => ['nature' => 'Charge'], 'compare' => true, 'lower_is_better' => true],
                ['label' => 'Résultat estimé', 'diff' => [0, 1], 'compare' => true, 'note' => 'Chiffre d\'affaires moins charges'],
                ['label' => 'Trésorerie nette (flux)', 'diff' => [4, 5], 'compare' => true],
                ['label' => 'enc', 'sum' => 'montant', 'where' => ['flux' => 'Encaissements'], 'hidden' => true],
                ['label' => 'dec', 'sum' => 'montant', 'where' => ['flux' => 'Décaissements'], 'hidden' => true],
            ],
            'charts' => [
                ['title' => 'Chiffre d\'affaires par mois — comparaison annuelle', 'type' => 'line', 'wide' => true, 'gap_future' => true, 'by' => 'mois', 'categories' => $months,
                    'series' => [['label' => '"CA "', 'sum' => 'montant', 'where' => ['flux' => 'Chiffre d\'affaires'], 'year' => 'current'],
                        ['label' => '"CA "', 'sum' => 'montant', 'where' => ['flux' => 'Chiffre d\'affaires'], 'year' => 'previous']]],
                ['title' => 'Charges par nature', 'type' => 'doughnut', 'by' => 'categorie', 'top' => 6, 'series' => [['label' => 'Charges', 'sum' => 'montant', 'where' => ['nature' => 'Charge']]]],
                ['title' => 'Encaissements et décaissements par mois', 'type' => 'column', 'by' => 'mois', 'categories' => $months,
                    'series' => [['label' => 'Encaissements', 'sum' => 'montant', 'where' => ['flux' => 'Encaissements']], ['label' => 'Décaissements', 'sum' => 'montant', 'where' => ['flux' => 'Décaissements']]]],
                ['title' => 'Chiffre d\'affaires et charges par agence', 'type' => 'bar', 'by' => 'agence',
                    'series' => [['label' => 'Chiffre d\'affaires', 'sum' => 'montant', 'where' => ['flux' => 'Chiffre d\'affaires']], ['label' => 'Charges', 'sum' => 'montant', 'where' => ['nature' => 'Charge']]]],
            ],
            'tables' => [
                ['title' => 'Meilleurs clients', 'by' => 'tiers', 'sum' => 'montant', 'where' => ['flux' => 'Chiffre d\'affaires'], 'top' => 10, 'value_label' => 'CA HT'],
                ['title' => 'Principaux postes de charges', 'by' => 'categorie', 'sum' => 'montant', 'where' => ['nature' => 'Charge'], 'top' => 10, 'value_label' => 'Charges'],
            ],
        ];
    }

    protected function operations(string $from, string $to)
    {
        if (! $this->c->hasModule('tresorerie') || ! Schema::hasTable('tr_operations')) {
            return collect();
        }

        return DB::table('tr_operations as o')->join('tr_accounts as a', 'a.id', '=', 'o.account_id')->where('o.company_id', $this->c->id)->whereNull('o.deleted_at')->whereNull('o.transfer_id')
            ->whereBetween('o.date', [$from, $to])
            ->when($this->branches && Schema::hasColumn('tr_operations', 'branch_id'), fn ($q) => $q->whereIn('o.branch_id', $this->branches))
            ->selectRaw('o.date, o.amount, o.category, o.label, a.name account, '.(Schema::hasColumn('tr_operations', 'branch_id') ? 'o.branch_id' : 'NULL as branch_id'))->orderBy('o.date')->get();
    }

    protected function payments(string $from, string $to)
    {
        $rate = Schema::hasColumn('payments', 'exchange_rate') ? 'COALESCE(p.exchange_rate, 1)' : '1';

        return DB::table('payments as p')->join('trade_documents as d', 'd.id', '=', 'p.document_id')->where('p.company_id', $this->c->id)->whereNull('p.deleted_at')
            ->whereBetween('p.date', [$from, $to])->when($this->branches, fn ($q) => $q->whereIn('d.branch_id', $this->branches))
            ->selectRaw("p.date, p.direction, p.method, p.partner_id, d.branch_id, d.number, p.amount * {$rate} v")->orderBy('p.date')->get();
    }

    // ------------------------------------------------------------------ Trésorerie

    public const METHODS = ['cash' => 'Espèces', 'mobile_money' => 'Mobile Money', 'card' => 'Carte bancaire', 'bank_transfer' => 'Virement', 'cheque' => 'Chèque',
        'loyalty' => 'Points fidélité', 'gift_card' => 'Carte cadeau', 'other' => 'Autre moyen'];

    protected function tresorerie(int $year): array
    {
        [$from, $to] = $this->period($year);
        $rows = [];
        $payments = $this->payments($from, $to);
        $this->preloadPartners($payments->pluck('partner_id'));
        foreach ($payments as $p) {
            $in = $p->direction === 'sale';
            $date = substr((string) $p->date, 0, 10);
            $rows[] = ['annee' => substr($date, 0, 4), 'mois' => $this->month($date), 'date' => $date, 'compte' => self::METHODS[$p->method] ?? ucfirst((string) $p->method),
                'sens' => $in ? 'Entrée' : 'Sortie', 'categorie' => $in ? 'Règlements clients' : 'Règlements fournisseurs', 'libelle' => ($in ? 'Facture ' : 'Facture fournisseur ').$p->number,
                'tiers' => $this->partner($p->partner_id, $in ? 'Client comptoir' : 'Fournisseur'), 'agence' => $this->branch($p->branch_id),
                'entree' => $in ? round((float) $p->v, 2) : 0, 'sortie' => $in ? 0 : round((float) $p->v, 2)];
        }
        foreach ($this->operations($from, $to) as $o) {
            $date = substr((string) $o->date, 0, 10);
            $in = (float) $o->amount > 0;
            $rows[] = ['annee' => substr($date, 0, 4), 'mois' => $this->month($date), 'date' => $date, 'compte' => (string) $o->account, 'sens' => $in ? 'Entrée' : 'Sortie',
                'categorie' => \App\Modules\Tresorerie\Models\Operation::CATEGORIES[$o->category][0] ?? ($in ? 'Autres recettes' : 'Autres dépenses'), 'libelle' => (string) $o->label,
                'tiers' => '—', 'agence' => $this->branch($o->branch_id), 'entree' => $in ? (float) $o->amount : 0, 'sortie' => $in ? 0 : -(float) $o->amount];
        }
        usort($rows, fn ($a, $b) => strcmp($a['date'], $b['date']));
        $months = $this->months();
        $kpis = [
            ['label' => 'Entrées d\'argent', 'sum' => 'entree', 'compare' => true],
            ['label' => 'Sorties d\'argent', 'sum' => 'sortie', 'compare' => true, 'lower_is_better' => true],
            ['label' => 'Flux net', 'diff' => [0, 1], 'compare' => true],
        ];
        if ($this->c->hasModule('tresorerie')) {
            $svc = app(\App\Modules\Tresorerie\TreasuryService::class);
            $cash = \App\Modules\Tresorerie\Models\TreasuryAccount::where('active', true)->get()->sum(fn ($a) => $svc->balance($a));
            $kpis[] = ['label' => 'Solde des comptes', 'static' => round($cash, 2), 'note' => 'Caisses, banques et Mobile Money au '.now()->format('d/m/Y')];
        } else {
            $kpis[] = ['label' => 'Opérations', 'format' => 'int', 'compare' => true];
        }

        return [
            'columns' => [
                'annee' => ['label' => 'Année', 'width' => 8], 'mois' => ['label' => 'Mois', 'width' => 11], 'date' => ['label' => 'Date', 'type' => 'date', 'width' => 11],
                'compte' => ['label' => 'Compte / moyen', 'width' => 20], 'sens' => ['label' => 'Sens', 'width' => 9], 'categorie' => ['label' => 'Catégorie', 'width' => 28],
                'libelle' => ['label' => 'Libellé', 'width' => 30], 'tiers' => ['label' => 'Tiers', 'width' => 24], 'agence' => ['label' => 'Agence', 'width' => 18],
                'entree' => ['label' => 'Entrée', 'type' => 'money'], 'sortie' => ['label' => 'Sortie', 'type' => 'money'],
            ],
            'rows' => $rows,
            'year' => 'annee',
            'filters' => [
                ['col' => 'annee', 'label' => 'Année', 'default' => (string) $year, 'sort' => 'desc'],
                ['col' => 'mois', 'label' => 'Mois', 'values' => $months],
                ['col' => 'compte', 'label' => 'Compte / moyen'],
                ['col' => 'agence', 'label' => 'Agence'],
            ],
            'kpis' => $kpis,
            'charts' => [
                ['title' => 'Entrées et sorties par mois', 'type' => 'column', 'wide' => true, 'by' => 'mois', 'categories' => $months,
                    'series' => [['label' => 'Entrées', 'sum' => 'entree'], ['label' => 'Sorties', 'sum' => 'sortie']]],
                ['title' => 'Sorties par catégorie', 'type' => 'doughnut', 'by' => 'categorie', 'top' => 6, 'series' => [['label' => 'Sorties', 'sum' => 'sortie']]],
                ['title' => 'Entrées par compte et moyen de paiement', 'type' => 'bar', 'by' => 'compte', 'top' => 8, 'series' => [['label' => 'Entrées', 'sum' => 'entree']]],
            ],
            'tables' => [
                ['title' => 'Principaux clients payeurs', 'by' => 'tiers', 'sum' => 'entree', 'where' => ['categorie' => 'Règlements clients'], 'top' => 10, 'value_label' => 'Encaissé'],
                ['title' => 'Principales sorties', 'by' => 'categorie', 'sum' => 'sortie', 'top' => 10, 'value_label' => 'Payé'],
            ],
        ];
    }

    // ------------------------------------------------------------------ Créances

    protected function creances(): array
    {
        $today = now()->startOfDay();
        $docs = $this->docs('sale')->where('d.type', 'invoice')->where('d.payment_status', '!=', 'paid')
            ->selectRaw('d.number, d.date, d.due_date, d.partner_id, d.branch_id, d.total * '.$this->rate().' total, d.amount_paid * '.$this->rate().' paid')->orderBy('d.due_date')->get();
        $this->preloadPartners($docs->pluck('partner_id'));
        $rows = [];
        foreach ($docs as $d) {
            $rest = round((float) $d->total - (float) $d->paid, 2);
            if ($rest <= 0.5) {
                continue;
            }
            $due = $d->due_date ? \Carbon\Carbon::parse($d->due_date) : \Carbon\Carbon::parse($d->date);
            $late = $due->lt($today) ? (int) $due->diffInDays($today) : 0;
            $tranche = $late <= 0 ? self::AGING[0] : ($late <= 30 ? self::AGING[1] : ($late <= 60 ? self::AGING[2] : ($late <= 90 ? self::AGING[3] : self::AGING[4])));
            $rows[] = ['client' => $this->partner($d->partner_id), 'piece' => (string) $d->number, 'agence' => $this->branch($d->branch_id), 'date' => substr((string) $d->date, 0, 10),
                'echeance' => $due->toDateString(), 'retard' => $late, 'tranche' => $tranche, 'total' => round((float) $d->total, 2), 'paye' => round((float) $d->paid, 2), 'reste' => $rest];
        }

        return [
            'columns' => [
                'client' => ['label' => 'Client', 'width' => 28], 'piece' => ['label' => 'Facture', 'width' => 16], 'agence' => ['label' => 'Agence', 'width' => 18],
                'date' => ['label' => 'Date', 'type' => 'date', 'width' => 11], 'echeance' => ['label' => 'Échéance', 'type' => 'date', 'width' => 11],
                'retard' => ['label' => 'Jours de retard', 'type' => 'int', 'width' => 12], 'tranche' => ['label' => 'Ancienneté', 'width' => 14],
                'total' => ['label' => 'Montant TTC', 'type' => 'money'], 'paye' => ['label' => 'Déjà payé', 'type' => 'money'], 'reste' => ['label' => 'Reste dû', 'type' => 'money'],
            ],
            'rows' => $rows,
            'filters' => [
                ['col' => 'agence', 'label' => 'Agence'],
                ['col' => 'tranche', 'label' => 'Ancienneté', 'values' => self::AGING],
                ['col' => 'client', 'label' => 'Client'],
            ],
            'kpis' => [
                ['label' => 'Reste dû par les clients', 'sum' => 'reste'],
                ['label' => 'Dont échu', 'sum' => 'reste', 'minus' => ['tranche' => self::AGING[0]], 'note' => 'Factures dont l\'échéance est passée'],
                ['label' => 'Dont plus de 60 jours', 'sum' => 'reste', 'where' => ['tranche' => [self::AGING[3], self::AGING[4]]], 'note' => 'À relancer en priorité'],
                ['label' => 'Factures impayées', 'format' => 'int', 'note' => 'Nombre de factures non soldées'],
            ],
            'charts' => [
                ['title' => 'Balance âgée', 'type' => 'column', 'by' => 'tranche', 'categories' => self::AGING, 'series' => [['label' => 'Reste dû', 'sum' => 'reste']]],
                ['title' => 'Reste dû par agence', 'type' => 'doughnut', 'by' => 'agence', 'top' => 6, 'series' => [['label' => 'Reste dû', 'sum' => 'reste']]],
                ['title' => 'Principaux débiteurs', 'type' => 'bar', 'wide' => true, 'by' => 'client', 'top' => 10, 'series' => [['label' => 'Reste dû', 'sum' => 'reste']]],
            ],
            'tables' => [
                ['title' => 'Clients à relancer en priorité', 'by' => 'client', 'sum' => 'reste', 'where' => ['tranche' => [self::AGING[2], self::AGING[3], self::AGING[4]]], 'top' => 10, 'value_label' => 'Échu +30 j'],
                ['title' => 'Plus gros encours', 'by' => 'client', 'sum' => 'reste', 'top' => 10, 'value_label' => 'Reste dû'],
            ],
        ];
    }

    // ------------------------------------------------------------------ Stock

    protected function stock(): array
    {
        $levels = app(\App\Modules\Stock\StockService::class)->levels()->groupBy('product_id')->map(fn ($r) => $r->sum('quantity'));
        $sold = $this->docs('sale')->whereIn('d.type', ['invoice', 'receipt'])->where('d.date', '>=', now()->subDays(90)->toDateString())
            ->join('trade_document_lines as l', 'l.document_id', '=', 'd.id')->whereNotNull('l.product_id')->groupBy('l.product_id')->selectRaw('l.product_id, SUM(l.quantity) q')->pluck('q', 'product_id');
        $cats = DB::table('product_categories')->where('company_id', $this->c->id)->pluck('name', 'id');
        $products = DB::table('products')->where('company_id', $this->c->id)->whereNull('deleted_at')->where('type', 'product')->where('track_stock', true)->where('active', true)
            ->orderBy('name')->limit(20000)->get(['id', 'name', 'sku', 'category_id', 'min_stock', 'purchase_price']);
        $rows = [];
        foreach ($products as $p) {
            $q = (float) ($levels[$p->id] ?? 0);
            $s90 = (float) ($sold[$p->id] ?? 0);
            $daily = $s90 / 90;
            $cover = $daily > 0 ? (int) round(max(0, $q) / $daily) : null;
            $status = $q <= 0 ? 'Rupture' : ($q <= (float) $p->min_stock ? 'Sous le seuil' : ($daily == 0 ? 'Sans vente (90 j)' : ($cover > 180 ? 'Surstock' : 'Normal')));
            $rows[] = ['article' => $p->name, 'ref' => (string) $p->sku, 'categorie' => $cats[$p->category_id] ?? 'Sans catégorie', 'statut' => $status,
                'stock' => $q, 'seuil' => (float) $p->min_stock, 'pa' => (float) $p->purchase_price, 'valeur' => round(max(0, $q) * (float) $p->purchase_price, 2),
                'vendus' => $s90, 'couverture' => $cover];
        }

        return [
            'columns' => [
                'article' => ['label' => 'Article', 'width' => 32], 'ref' => ['label' => 'Référence', 'width' => 14], 'categorie' => ['label' => 'Catégorie', 'width' => 20],
                'statut' => ['label' => 'Statut', 'width' => 13], 'stock' => ['label' => 'Stock', 'type' => 'number', 'width' => 10], 'seuil' => ['label' => 'Seuil d\'alerte', 'type' => 'number', 'width' => 11],
                'pa' => ['label' => 'Prix d\'achat', 'type' => 'money'], 'valeur' => ['label' => 'Valeur', 'type' => 'money'], 'vendus' => ['label' => 'Vendus (90 j)', 'type' => 'number', 'width' => 12],
                'couverture' => ['label' => 'Couverture (jours)', 'type' => 'int', 'width' => 14],
            ],
            'rows' => $rows,
            'filters' => [
                ['col' => 'categorie', 'label' => 'Catégorie'],
                ['col' => 'statut', 'label' => 'Statut', 'values' => self::STATUSES],
            ],
            'kpis' => [
                ['label' => 'Valeur du stock (prix d\'achat)', 'sum' => 'valeur'],
                ['label' => 'Articles suivis', 'format' => 'int', 'note' => 'Articles actifs gérés en stock'],
                ['label' => 'Ruptures et alertes', 'format' => 'int', 'where' => ['statut' => ['Rupture', 'Sous le seuil']], 'note' => 'À réapprovisionner'],
                ['label' => 'Quantités vendues (90 j)', 'sum' => 'vendus', 'format' => 'number'],
            ],
            'charts' => [
                ['title' => 'Valeur du stock par catégorie', 'type' => 'doughnut', 'by' => 'categorie', 'top' => 6, 'series' => [['label' => 'Valeur', 'sum' => 'valeur']]],
                ['title' => 'Articles par statut', 'type' => 'column', 'by' => 'statut', 'categories' => self::STATUSES, 'format' => 'int', 'series' => [['label' => 'Articles']]],
                ['title' => 'Articles qui immobilisent le plus de valeur', 'type' => 'bar', 'wide' => true, 'by' => 'article', 'top' => 10, 'series' => [['label' => 'Valeur', 'sum' => 'valeur']]],
            ],
            'tables' => [
                ['title' => 'Meilleures ventes (90 jours)', 'by' => 'article', 'sum' => 'vendus', 'top' => 10, 'value_label' => 'Quantité', 'format' => 'number'],
                ['title' => 'Stock dormant (surstock ou sans vente)', 'by' => 'article', 'sum' => 'valeur', 'where' => ['statut' => ['Surstock', 'Sans vente (90 j)']], 'top' => 10, 'value_label' => 'Valeur'],
            ],
        ];
    }

    // ------------------------------------------------------------------ Achats

    protected function achats(int $year): array
    {
        [$from, $to] = $this->period($year);
        $lines = $this->docs('purchase')->whereIn('d.type', ['supplier_invoice', 'supplier_credit'])->whereBetween('d.date', [$from, $to])
            ->join('trade_document_lines as l', 'l.document_id', '=', 'd.id')
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')
            ->orderBy('d.date')->limit(30000)
            ->selectRaw('d.date, d.number, d.type, d.partner_id, d.branch_id, '.$this->rate().' rate, l.product_id, l.description, l.quantity, l.subtotal, p.name pname, pc.name cat')->get();
        $this->preloadPartners($lines->pluck('partner_id'));
        $rows = [];
        foreach ($lines as $r) {
            $sign = $r->type === 'supplier_credit' ? -1 : 1;
            $date = substr((string) $r->date, 0, 10);
            $rows[] = ['annee' => substr($date, 0, 4), 'mois' => $this->month($date), 'date' => $date, 'piece' => (string) $r->number,
                'fournisseur' => $this->partner($r->partner_id, 'Fournisseur'), 'agence' => $this->branch($r->branch_id),
                'categorie' => $r->product_id ? ($r->cat ?: 'Articles non classés') : 'Frais et services',
                'article' => $this->label($r->pname ?: (trim((string) $r->description) ?: 'Ligne libre')),
                'qte' => $sign * (float) $r->quantity, 'montant' => round($sign * (float) $r->subtotal * (float) $r->rate, 2)];
        }
        $due = (float) $this->docs('purchase')->where('d.type', 'supplier_invoice')->where('d.payment_status', '!=', 'paid')->sum(DB::raw('(d.total - d.amount_paid) * '.$this->rate()));
        $months = $this->months();

        return [
            'columns' => [
                'annee' => ['label' => 'Année', 'width' => 8], 'mois' => ['label' => 'Mois', 'width' => 11], 'date' => ['label' => 'Date', 'type' => 'date', 'width' => 11],
                'piece' => ['label' => 'Pièce', 'width' => 16], 'fournisseur' => ['label' => 'Fournisseur', 'width' => 26], 'agence' => ['label' => 'Agence', 'width' => 18],
                'categorie' => ['label' => 'Catégorie', 'width' => 20], 'article' => ['label' => 'Désignation', 'width' => 30], 'qte' => ['label' => 'Quantité', 'type' => 'number', 'width' => 10],
                'montant' => ['label' => 'Montant HT', 'type' => 'money'],
            ],
            'rows' => $rows,
            'year' => 'annee',
            'filters' => [
                ['col' => 'annee', 'label' => 'Année', 'default' => (string) $year, 'sort' => 'desc'],
                ['col' => 'mois', 'label' => 'Mois', 'values' => $months],
                ['col' => 'agence', 'label' => 'Agence'],
                ['col' => 'categorie', 'label' => 'Catégorie'],
            ],
            'kpis' => [
                ['label' => 'Achats HT', 'sum' => 'montant', 'compare' => true, 'lower_is_better' => true],
                ['label' => 'Quantités achetées', 'sum' => 'qte', 'format' => 'number', 'compare' => true],
                ['label' => 'Lignes d\'achat', 'format' => 'int', 'compare' => true],
                ['label' => 'Reste à payer aux fournisseurs', 'static' => round($due, 2), 'note' => 'Toutes factures non soldées au '.now()->format('d/m/Y')],
            ],
            'charts' => [
                ['title' => 'Achats par mois — comparaison annuelle', 'type' => 'line', 'wide' => true, 'gap_future' => true, 'by' => 'mois', 'categories' => $months,
                    'series' => [['label' => '"Achats "', 'sum' => 'montant', 'year' => 'current'], ['label' => '"Achats "', 'sum' => 'montant', 'year' => 'previous']]],
                ['title' => 'Top 10 fournisseurs', 'type' => 'bar', 'by' => 'fournisseur', 'top' => 10, 'series' => [['label' => 'Achats HT', 'sum' => 'montant']]],
                ['title' => 'Achats par catégorie', 'type' => 'doughnut', 'by' => 'categorie', 'top' => 6, 'series' => [['label' => 'Achats HT', 'sum' => 'montant']]],
            ],
            'tables' => [
                ['title' => 'Principaux fournisseurs', 'by' => 'fournisseur', 'sum' => 'montant', 'top' => 10, 'value_label' => 'Achats HT'],
                ['title' => 'Articles les plus achetés', 'by' => 'article', 'sum' => 'montant', 'top' => 10, 'value_label' => 'Achats HT'],
            ],
        ];
    }
}
