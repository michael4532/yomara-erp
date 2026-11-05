<?php

namespace App\Modules\Rapports;

use Illuminate\Database\Query\Builder;

/**
 * Sources de données des rapports : chaque source décrit une table de faits, ses axes d'analyse
 * (dimensions) et ses mesures sous forme d'expressions SQL figées. Les rapports ne contiennent que
 * des clés de ce registre : aucune saisie de l'utilisateur n'arrive dans le SQL.
 *
 * Les montants sont convertis dans la monnaie de la société (cours du document).
 */
class Sources
{
    public const DATE_UNITS = [
        'day' => 'Jour', 'week' => 'Semaine', 'month' => 'Mois', 'quarter' => 'Trimestre', 'year' => 'Année', 'weekday' => 'Jour de la semaine',
    ];

    private static ?array $cache = null;

    private static ?string $driver = null;

    public static function all(): array
    {
        $today = now()->toDateString();
        $driver = (Sql::sqlite() ? 'sqlite' : 'mysql').$today;
        if (self::$cache !== null && self::$driver === $driver) {
            return self::$cache;
        }
        self::$driver = $driver;

        $rate = fn (string $a) => "COALESCE($a.exchange_rate, 1)";
        $sign = "(CASE WHEN d.type = 'credit_note' THEN -1 ELSE 1 END)";
        $yesNo = ['1' => 'Oui', '0' => 'Non'];
        $partnerJoin = ['p' => ['partners as p', 'p.id', '=', 'd.partner_id']];
        $userJoin = fn (string $col) => ['u' => ['users as u', 'u.id', '=', $col]];
        $employeeName = Sql::concat("COALESCE(e.last_name, '')", "' '", "COALESCE(e.first_name, '')");
        $payStatus = ['paid' => 'Payée', 'partial' => 'Partiellement payée', 'unpaid' => 'Non payée'];
        $docSale = function (Builder $q) {
            $q->where('d.direction', 'sale')->where('d.status', 'validated')->whereIn('d.type', ['invoice', 'receipt', 'credit_note'])
                ->whereNull('d.deleted_at')->where(fn ($w) => $w->whereNull('d.is_opening')->orWhere('d.is_opening', false));
        };
        $docPurchase = function (Builder $q) {
            $q->where('d.direction', 'purchase')->where('d.status', 'validated')->where('d.type', 'supplier_invoice')
                ->whereNull('d.deleted_at')->where(fn ($w) => $w->whereNull('d.is_opening')->orWhere('d.is_opening', false));
        };
        $product = [
            'product' => ['label' => 'Article', 'sql' => 'COALESCE(l.product_id, l.description)', 'display' => 'COALESCE(pr.name, l.description)', 'join' => ['pr']],
            'category' => ['label' => 'Catégorie d\'article', 'sql' => 'pr.category_id', 'display' => "COALESCE(c.name, 'Sans catégorie')", 'join' => ['pr', 'c']],
            'template' => ['label' => 'Modèle (variantes regroupées)', 'sql' => 'COALESCE(pr.parent_id, l.product_id, l.description)', 'display' => 'COALESCE(tp.name, pr.name, l.description)', 'join' => ['pr', 'tp']],
        ];
        $productJoins = ['pr' => ['products as pr', 'pr.id', '=', 'l.product_id'], 'c' => ['product_categories as c', 'c.id', '=', 'pr.category_id'], 'tp' => ['products as tp', 'tp.id', '=', 'pr.parent_id']];
        $uq = 'l.quantity * COALESCE(l.unit_factor, 1)'; // quantité en unité de stock (conditionnements)

        $sources = [
            'ventes' => [
                'label' => 'Chiffre d\'affaires', 'group' => 'Ventes', 'module' => 'ventes', 'permission' => 'ventes.view|ventes.manage',
                'description' => 'Factures et tickets de caisse validés, avoirs déduits.',
                'from' => 'trade_documents as d', 'company' => 'd.company_id', 'date' => 'd.date', 'where' => $docSale,
                'joins' => $partnerJoin + $userJoin('d.user_id'),
                'dimensions' => [
                    'partner' => ['label' => 'Client', 'sql' => 'd.partner_id', 'display' => "COALESCE(p.name, d.partner_name, 'Client comptoir')", 'join' => ['p']],
                    'city' => ['label' => 'Ville du client', 'sql' => "COALESCE(p.city, '')", 'join' => ['p'], 'empty' => 'Non renseignée'],
                    'type' => ['label' => 'Type de document', 'sql' => 'd.type', 'labels' => ['invoice' => 'Facture', 'receipt' => 'Ticket de caisse', 'credit_note' => 'Avoir']],
                    'user' => ['label' => 'Vendeur', 'sql' => 'd.user_id', 'display' => "COALESCE(u.name, '—')", 'join' => ['u']],
                    'currency' => ['label' => 'Devise', 'sql' => 'd.currency'],
                    'payment_status' => ['label' => 'Règlement', 'sql' => 'd.payment_status', 'labels' => $payStatus],
                ],
                'measures' => [
                    'subtotal' => ['label' => 'CA HT', 'sql' => "SUM($sign * d.subtotal * {$rate('d')})", 'format' => 'money'],
                    'total' => ['label' => 'CA TTC', 'sql' => "SUM($sign * d.total * {$rate('d')})", 'format' => 'money'],
                    'tax' => ['label' => 'TVA collectée', 'sql' => "SUM($sign * d.tax_total * {$rate('d')})", 'format' => 'money'],
                    'count' => ['label' => 'Nombre de documents', 'sql' => 'COUNT(*)', 'format' => 'number'],
                    'avg' => ['label' => 'Panier moyen TTC', 'sql' => "AVG(CASE WHEN d.type <> 'credit_note' THEN d.total * {$rate('d')} END)", 'format' => 'money', 'additive' => false],
                    'paid' => ['label' => 'Encaissé', 'sql' => "SUM(CASE WHEN d.type <> 'credit_note' THEN d.amount_paid * {$rate('d')} ELSE 0 END)", 'format' => 'money'],
                    'balance' => ['label' => 'Reste à encaisser', 'sql' => "SUM(CASE WHEN d.type = 'invoice' THEN (d.total - d.amount_paid) * {$rate('d')} ELSE 0 END)", 'format' => 'money'],
                ],
            ],
            'ventes_articles' => [
                'label' => 'Ventes par article', 'group' => 'Ventes', 'module' => 'ventes', 'permission' => 'ventes.view|ventes.manage',
                'description' => 'Lignes des factures, tickets et avoirs : quantités, chiffre d\'affaires et marge estimée (lignes dont l\'article a un prix d\'achat).',
                'from' => 'trade_document_lines as l', 'inner' => [['trade_documents as d', 'd.id', '=', 'l.document_id']],
                'company' => 'd.company_id', 'date' => 'd.date', 'where' => $docSale,
                'joins' => $productJoins + $partnerJoin + $userJoin('d.user_id'),
                'dimensions' => $product + [
                    'partner' => ['label' => 'Client', 'sql' => 'd.partner_id', 'display' => "COALESCE(p.name, d.partner_name, 'Client comptoir')", 'join' => ['p']],
                    'user' => ['label' => 'Vendeur', 'sql' => 'd.user_id', 'display' => "COALESCE(u.name, '—')", 'join' => ['u']],
                    'type' => ['label' => 'Type de document', 'sql' => 'd.type', 'labels' => ['invoice' => 'Facture', 'receipt' => 'Ticket de caisse', 'credit_note' => 'Avoir']],
                ],
                'measures' => [
                    'subtotal' => ['label' => 'Ventes HT', 'sql' => "SUM($sign * l.subtotal * {$rate('d')})", 'format' => 'money'],
                    'total' => ['label' => 'Ventes TTC', 'sql' => "SUM($sign * l.total * {$rate('d')})", 'format' => 'money'],
                    'quantity' => ['label' => 'Quantité vendue', 'sql' => "SUM($sign * $uq)", 'format' => 'number'],
                    // Marge calculée sur les seules lignes dont l'article a un prix d'achat (sinon le coût est inconnu).
                    'margin' => ['label' => 'Marge estimée', 'sql' => "SUM(CASE WHEN pr.purchase_price > 0 THEN $sign * (l.subtotal * {$rate('d')} - $uq * pr.purchase_price) ELSE 0 END)", 'format' => 'money', 'join' => ['pr']],
                    'margin_rate' => ['label' => 'Taux de marge', 'sql' => "(100.0 * SUM(CASE WHEN pr.purchase_price > 0 THEN $sign * (l.subtotal * {$rate('d')} - $uq * pr.purchase_price) ELSE 0 END) / NULLIF(SUM(CASE WHEN pr.purchase_price > 0 THEN $sign * l.subtotal * {$rate('d')} ELSE 0 END), 0))", 'format' => 'percent', 'additive' => false, 'join' => ['pr']],
                    'lines' => ['label' => 'Nombre de lignes', 'sql' => 'COUNT(*)', 'format' => 'number'],
                ],
            ],
            'ventes_pipeline' => [
                'label' => 'Devis et commandes', 'group' => 'Ventes', 'module' => 'ventes', 'permission' => 'ventes.view|ventes.manage',
                'description' => 'Devis et commandes clients (hors annulés) et leur transformation.',
                'from' => 'trade_documents as d', 'company' => 'd.company_id', 'date' => 'd.date',
                'where' => fn (Builder $q) => $q->where('d.direction', 'sale')->whereIn('d.type', ['quote', 'order'])->where('d.status', '<>', 'cancelled')->whereNull('d.deleted_at'),
                'joins' => $partnerJoin + $userJoin('d.user_id') + [
                    // Documents issus du devis / de la commande (transformation)
                    'cv' => fn (Builder $q) => $q->leftJoinSub(
                        \Illuminate\Support\Facades\DB::table('trade_documents')->select('source_id')->whereNotNull('source_id')->whereNull('deleted_at')->where('status', '<>', 'cancelled')->groupBy('source_id'),
                        'cv', 'cv.source_id', '=', 'd.id'),
                ],
                'dimensions' => [
                    'type' => ['label' => 'Type', 'sql' => 'd.type', 'labels' => ['quote' => 'Devis', 'order' => 'Commande']],
                    'status' => ['label' => 'Statut', 'sql' => 'd.status', 'labels' => ['draft' => 'Brouillon', 'validated' => 'Validé']],
                    'converted' => ['label' => 'Transformé', 'sql' => "(CASE WHEN cv.source_id IS NULL THEN '0' ELSE '1' END)", 'labels' => $yesNo, 'join' => ['cv']],
                    'partner' => ['label' => 'Client', 'sql' => 'd.partner_id', 'display' => "COALESCE(p.name, d.partner_name, '—')", 'join' => ['p']],
                    'user' => ['label' => 'Commercial', 'sql' => 'd.user_id', 'display' => "COALESCE(u.name, '—')", 'join' => ['u']],
                ],
                'measures' => [
                    'count' => ['label' => 'Nombre', 'sql' => 'COUNT(*)', 'format' => 'number'],
                    'subtotal' => ['label' => 'Montant HT', 'sql' => "SUM(d.subtotal * {$rate('d')})", 'format' => 'money'],
                    'total' => ['label' => 'Montant TTC', 'sql' => "SUM(d.total * {$rate('d')})", 'format' => 'money'],
                    'conversion' => ['label' => 'Taux de transformation', 'sql' => 'AVG(CASE WHEN cv.source_id IS NULL THEN 0 ELSE 100.0 END)', 'format' => 'percent', 'additive' => false, 'join' => ['cv']],
                ],
            ],
            'achats' => [
                'label' => 'Achats facturés', 'group' => 'Achats', 'module' => 'achats', 'permission' => 'achats.view|achats.manage',
                'description' => 'Factures fournisseurs validées.',
                'from' => 'trade_documents as d', 'company' => 'd.company_id', 'date' => 'd.date', 'where' => $docPurchase,
                'joins' => $partnerJoin + $userJoin('d.user_id'),
                'dimensions' => [
                    'partner' => ['label' => 'Fournisseur', 'sql' => 'd.partner_id', 'display' => "COALESCE(p.name, d.partner_name, '—')", 'join' => ['p']],
                    'city' => ['label' => 'Ville du fournisseur', 'sql' => "COALESCE(p.city, '')", 'join' => ['p'], 'empty' => 'Non renseignée'],
                    'user' => ['label' => 'Saisi par', 'sql' => 'd.user_id', 'display' => "COALESCE(u.name, '—')", 'join' => ['u']],
                    'currency' => ['label' => 'Devise', 'sql' => 'd.currency'],
                    'payment_status' => ['label' => 'Règlement', 'sql' => 'd.payment_status', 'labels' => $payStatus],
                ],
                'measures' => [
                    'subtotal' => ['label' => 'Achats HT', 'sql' => "SUM(d.subtotal * {$rate('d')})", 'format' => 'money'],
                    'total' => ['label' => 'Achats TTC', 'sql' => "SUM(d.total * {$rate('d')})", 'format' => 'money'],
                    'tax' => ['label' => 'TVA déductible', 'sql' => "SUM(d.tax_total * {$rate('d')})", 'format' => 'money'],
                    'count' => ['label' => 'Nombre de factures', 'sql' => 'COUNT(*)', 'format' => 'number'],
                    'balance' => ['label' => 'Reste à payer', 'sql' => "SUM((d.total - d.amount_paid) * {$rate('d')})", 'format' => 'money'],
                ],
            ],
            'achats_articles' => [
                'label' => 'Achats par article', 'group' => 'Achats', 'module' => 'achats', 'permission' => 'achats.view|achats.manage',
                'description' => 'Lignes des factures fournisseurs : quantités, montants, prix moyen.',
                'from' => 'trade_document_lines as l', 'inner' => [['trade_documents as d', 'd.id', '=', 'l.document_id']],
                'company' => 'd.company_id', 'date' => 'd.date', 'where' => $docPurchase,
                'joins' => $productJoins + $partnerJoin,
                'dimensions' => $product + [
                    'partner' => ['label' => 'Fournisseur', 'sql' => 'd.partner_id', 'display' => "COALESCE(p.name, d.partner_name, '—')", 'join' => ['p']],
                ],
                'measures' => [
                    'subtotal' => ['label' => 'Achats HT', 'sql' => "SUM(l.subtotal * {$rate('d')})", 'format' => 'money'],
                    'quantity' => ['label' => 'Quantité achetée', 'sql' => "SUM($uq)", 'format' => 'number'],
                    'avg_price' => ['label' => 'Prix moyen HT (unité)', 'sql' => "(SUM(l.subtotal * {$rate('d')}) / NULLIF(SUM($uq), 0))", 'format' => 'money', 'additive' => false],
                    'lines' => ['label' => 'Nombre de lignes', 'sql' => 'COUNT(*)', 'format' => 'number'],
                ],
            ],
            'paiements' => [
                'label' => 'Encaissements et décaissements', 'group' => 'Finance', 'module' => 'ventes', 'permission' => 'ventes.payments.manage|achats.manage|tresorerie.view|tresorerie.manage|compta.view',
                'description' => 'Règlements clients et fournisseurs enregistrés.',
                'from' => 'payments as pm', 'company' => 'pm.company_id', 'date' => 'pm.date',
                'where' => fn (Builder $q) => $q->whereNull('pm.deleted_at'),
                'joins' => ['p' => ['partners as p', 'p.id', '=', 'pm.partner_id'], 'ta' => ['tr_accounts as ta', 'ta.id', '=', 'pm.treasury_account_id']] + $userJoin('pm.user_id'),
                'dimensions' => [
                    'direction' => ['label' => 'Sens', 'sql' => 'pm.direction', 'labels' => ['sale' => 'Encaissement', 'purchase' => 'Décaissement']],
                    'method' => ['label' => 'Moyen de paiement', 'sql' => 'pm.method', 'labels' => ['cash' => 'Espèces', 'mobile_money' => 'Mobile Money', 'card' => 'Carte bancaire', 'bank_transfer' => 'Virement', 'cheque' => 'Chèque', 'loyalty' => 'Points fidélité', 'gift_card' => 'Carte cadeau', 'online' => 'Paiement en ligne', 'other' => 'Autre']],
                    'partner' => ['label' => 'Client / fournisseur', 'sql' => 'pm.partner_id', 'display' => "COALESCE(p.name, '—')", 'join' => ['p']],
                    'account' => ['label' => 'Compte de trésorerie', 'sql' => 'pm.treasury_account_id', 'display' => "COALESCE(ta.name, 'Non affecté')", 'join' => ['ta']],
                    'user' => ['label' => 'Saisi par', 'sql' => 'pm.user_id', 'display' => "COALESCE(u.name, '—')", 'join' => ['u']],
                ],
                'measures' => [
                    'amount' => ['label' => 'Montant', 'sql' => "SUM(pm.amount * {$rate('pm')})", 'format' => 'money'],
                    'in' => ['label' => 'Encaissements', 'sql' => "SUM(CASE WHEN pm.direction = 'sale' THEN pm.amount * {$rate('pm')} ELSE 0 END)", 'format' => 'money'],
                    'out' => ['label' => 'Décaissements', 'sql' => "SUM(CASE WHEN pm.direction = 'purchase' THEN pm.amount * {$rate('pm')} ELSE 0 END)", 'format' => 'money'],
                    'net' => ['label' => 'Solde (entrées − sorties)', 'sql' => "SUM(CASE WHEN pm.direction = 'sale' THEN 1 ELSE -1 END * pm.amount * {$rate('pm')})", 'format' => 'money'],
                    'count' => ['label' => 'Nombre de règlements', 'sql' => 'COUNT(*)', 'format' => 'number'],
                ],
            ],
            'compta' => [
                'label' => 'Écritures comptables', 'group' => 'Finance', 'module' => 'compta', 'permission' => 'compta.view|compta.manage',
                'description' => 'Lignes d\'écritures du grand livre (hors écritures de clôture) : charges, produits, soldes par compte et par journal.',
                'from' => 'compta_entry_lines as el', 'inner' => [['compta_entries as e', 'e.id', '=', 'el.entry_id'], ['compta_accounts as a', 'a.id', '=', 'el.account_id']],
                'company' => 'el.company_id', 'date' => 'e.date', 'where' => fn (Builder $q) => $q->where('e.kind', '!=', 'closing'),
                'joins' => ['j' => ['compta_journals as j', 'j.id', '=', 'e.journal_id'], 'p' => ['partners as p', 'p.id', '=', 'el.partner_id']],
                'dimensions' => [
                    'account' => ['label' => 'Compte', 'sql' => 'a.code', 'display' => Sql::concat('a.code', "' '", 'a.name')],
                    'account2' => ['label' => 'Compte (2 chiffres)', 'sql' => 'SUBSTR(a.code, 1, 2)'],
                    'class' => ['label' => 'Classe', 'sql' => 'SUBSTR(a.code, 1, 1)', 'labels' => ['1' => '1 · Capitaux', '2' => '2 · Immobilisations', '3' => '3 · Stocks', '4' => '4 · Tiers', '5' => '5 · Trésorerie', '6' => '6 · Charges', '7' => '7 · Produits', '8' => '8 · Autres charges et produits', '9' => '9 · Analytique']],
                    'journal' => ['label' => 'Journal', 'sql' => 'e.journal_id', 'display' => Sql::concat("COALESCE(j.code, '')", "' · '", "COALESCE(j.name, '')"), 'join' => ['j']],
                    'partner' => ['label' => 'Tiers', 'sql' => 'el.partner_id', 'display' => "COALESCE(p.name, '—')", 'join' => ['p']],
                    'kind' => ['label' => 'Origine', 'sql' => 'e.kind', 'labels' => ['manual' => 'Saisie manuelle', 'doc' => 'Facture', 'payment' => 'Règlement', 'payroll' => 'Paie', 'op' => 'Trésorerie', 'opening' => 'À-nouveaux', 'approval' => 'Note de frais', 'acquisition' => 'Immobilisation', 'depreciation' => 'Dotation', 'disposal' => 'Cession', 'fx' => 'Change']],
                ],
                'measures' => [
                    'debit' => ['label' => 'Débit', 'sql' => 'SUM(el.debit)', 'format' => 'money'],
                    'credit' => ['label' => 'Crédit', 'sql' => 'SUM(el.credit)', 'format' => 'money'],
                    'balance' => ['label' => 'Solde (débit − crédit)', 'sql' => 'SUM(el.debit - el.credit)', 'format' => 'money'],
                    'expense' => ['label' => 'Charges', 'sql' => "SUM(CASE WHEN SUBSTR(a.code, 1, 1) = '6' OR SUBSTR(a.code, 1, 2) IN ('81', '83', '85', '87', '89') THEN el.debit - el.credit ELSE 0 END)", 'format' => 'money'],
                    'revenue' => ['label' => 'Produits', 'sql' => "SUM(CASE WHEN SUBSTR(a.code, 1, 1) = '7' OR SUBSTR(a.code, 1, 2) IN ('82', '84', '86', '88') THEN el.credit - el.debit ELSE 0 END)", 'format' => 'money'],
                    'result' => ['label' => 'Résultat (produits − charges)', 'sql' => "SUM(CASE WHEN SUBSTR(a.code, 1, 1) IN ('6', '7', '8') THEN el.credit - el.debit ELSE 0 END)", 'format' => 'money'],
                ],
            ],
            'stock' => [
                'label' => 'Mouvements de stock', 'group' => 'Stock', 'module' => 'stock', 'permission' => 'stock.view|stock.manage',
                'description' => 'Entrées et sorties ; sur « Toutes dates », la quantité donne le stock actuel et sa valeur.',
                'from' => 'stock_moves as m', 'inner' => [['products as pr', 'pr.id', '=', 'm.product_id']],
                'company' => 'm.company_id', 'date' => 'm.date', 'where' => fn (Builder $q) => $q->whereNull('m.deleted_at'),
                'joins' => ['c' => ['product_categories as c', 'c.id', '=', 'pr.category_id'], 'w' => ['warehouses as w', 'w.id', '=', 'm.warehouse_id'],
                    'tp' => ['products as tp', 'tp.id', '=', 'pr.parent_id'], 'lt' => ['stock_lots as lt', 'lt.id', '=', 'm.lot_id']],
                'dimensions' => [
                    'product' => ['label' => 'Article', 'sql' => 'm.product_id', 'display' => 'pr.name'],
                    'template' => ['label' => 'Modèle (variantes regroupées)', 'sql' => 'COALESCE(pr.parent_id, m.product_id)', 'display' => 'COALESCE(tp.name, pr.name)', 'join' => ['tp']],
                    'lot' => ['label' => 'Lot / n° de série', 'sql' => 'm.lot_id', 'display' => "COALESCE(lt.number, 'Sans lot')", 'join' => ['lt']],
                    'expiry' => ['label' => 'Mois de péremption', 'sql' => "COALESCE(".Sql::bucket('month', 'lt.expiry_date').", '')", 'join' => ['lt'], 'empty' => 'Sans date', 'date' => 'month'],
                    'category' => ['label' => 'Catégorie d\'article', 'sql' => 'pr.category_id', 'display' => "COALESCE(c.name, 'Sans catégorie')", 'join' => ['c']],
                    'warehouse' => ['label' => 'Entrepôt', 'sql' => 'm.warehouse_id', 'display' => "COALESCE(w.name, '—')", 'join' => ['w']],
                    'type' => ['label' => 'Type de mouvement', 'sql' => 'm.type', 'labels' => ['initial' => 'Stock initial', 'purchase' => 'Achat / réception', 'sale' => 'Vente', 'adjustment' => 'Ajustement / inventaire', 'consumption' => 'Consommation', 'production' => 'Production', 'transfer' => 'Transfert', 'return' => 'Retour']],
                ],
                'measures' => [
                    'quantity' => ['label' => 'Quantité (solde)', 'sql' => 'SUM(m.quantity)', 'format' => 'number'],
                    'in' => ['label' => 'Entrées', 'sql' => 'SUM(CASE WHEN m.quantity > 0 THEN m.quantity ELSE 0 END)', 'format' => 'number'],
                    'out' => ['label' => 'Sorties', 'sql' => 'SUM(CASE WHEN m.quantity < 0 THEN -m.quantity ELSE 0 END)', 'format' => 'number'],
                    'value' => ['label' => 'Valeur au prix d\'achat', 'sql' => 'SUM(m.quantity * COALESCE(pr.purchase_price, 0))', 'format' => 'money'],
                    'sale_value' => ['label' => 'Valeur au prix de vente', 'sql' => 'SUM(m.quantity * COALESCE(pr.sale_price, 0))', 'format' => 'money'],
                ],
            ],
            'notes_frais' => [
                'label' => 'Notes de frais', 'group' => 'Finance', 'module' => 'notes_frais', 'permission' => 'notes_frais.approve|notes_frais.manage',
                'description' => 'Dépenses approuvées ou remboursées.',
                'from' => 'nf_expenses as x', 'company' => 'x.company_id', 'date' => 'x.date',
                'where' => fn (Builder $q) => $q->whereNull('x.deleted_at')->whereIn('x.status', ['approved', 'paid']),
                'joins' => ['cat' => ['nf_categories as cat', 'cat.id', '=', 'x.category_id'], 'e' => ['rh_employees as e', 'e.id', '=', 'x.employee_id']],
                'dimensions' => [
                    'category' => ['label' => 'Catégorie', 'sql' => 'x.category_id', 'display' => "COALESCE(cat.name, '—')", 'join' => ['cat']],
                    'employee' => ['label' => 'Salarié', 'sql' => 'x.employee_id', 'display' => $employeeName, 'join' => ['e']],
                    'department' => ['label' => 'Service', 'sql' => "COALESCE(e.department, '')", 'join' => ['e'], 'empty' => 'Sans service'],
                    'status' => ['label' => 'Statut', 'sql' => 'x.status', 'labels' => ['approved' => 'Approuvée', 'paid' => 'Remboursée']],
                    'project' => ['label' => 'Mission / projet', 'sql' => "COALESCE(x.project, '')", 'empty' => 'Sans mission'],
                ],
                'measures' => [
                    'amount' => ['label' => 'Montant', 'sql' => 'SUM(x.amount)', 'format' => 'money'],
                    'count' => ['label' => 'Nombre de dépenses', 'sql' => 'COUNT(*)', 'format' => 'number'],
                    'avg' => ['label' => 'Dépense moyenne', 'sql' => 'AVG(x.amount)', 'format' => 'money', 'additive' => false],
                ],
            ],
            'paie' => [
                'label' => 'Masse salariale', 'group' => 'Ressources humaines', 'module' => 'paie', 'permission' => 'paie.view|paie.manage',
                'description' => 'Bulletins des paies validées ou payées.',
                'from' => 'paie_payslips as s', 'inner' => [['paie_runs as r', 'r.id', '=', 's.run_id']],
                'company' => 's.company_id', 'date' => Sql::concat('r.period', "'-01'"),
                'where' => fn (Builder $q) => $q->whereIn('r.status', ['validated', 'paid']),
                'joins' => ['e' => ['rh_employees as e', 'e.id', '=', 's.employee_id']],
                'dimensions' => [
                    'department' => ['label' => 'Service', 'sql' => "COALESCE(e.department, '')", 'join' => ['e'], 'empty' => 'Sans service'],
                    'employee' => ['label' => 'Salarié', 'sql' => 's.employee_id', 'display' => $employeeName, 'join' => ['e']],
                    'job' => ['label' => 'Poste', 'sql' => "COALESCE(e.job_title, '')", 'join' => ['e'], 'empty' => 'Non renseigné'],
                    'category' => ['label' => 'Catégorie', 'sql' => "COALESCE(e.category, '')", 'join' => ['e'], 'empty' => 'Non renseignée'],
                    'contract' => ['label' => 'Contrat', 'sql' => "COALESCE(e.contract_type, '')", 'join' => ['e'], 'empty' => 'Non renseigné'],
                    'gender' => ['label' => 'Sexe', 'sql' => "COALESCE(e.gender, '')", 'join' => ['e'], 'labels' => ['M' => 'Homme', 'F' => 'Femme'], 'empty' => 'Non renseigné'],
                ],
                'measures' => [
                    'gross' => ['label' => 'Salaire brut', 'sql' => 'SUM(s.gross)', 'format' => 'money'],
                    'net' => ['label' => 'Net à payer', 'sql' => 'SUM(s.net)', 'format' => 'money'],
                    'cost' => ['label' => 'Coût employeur', 'sql' => 'SUM(s.cost)', 'format' => 'money'],
                    'headcount' => ['label' => 'Salariés payés', 'sql' => 'COUNT(DISTINCT s.employee_id)', 'format' => 'number', 'additive' => false],
                    'avg_net' => ['label' => 'Net moyen', 'sql' => 'AVG(s.net)', 'format' => 'money', 'additive' => false],
                ],
            ],
            'rh' => [
                'label' => 'Effectifs', 'group' => 'Ressources humaines', 'module' => 'rh', 'permission' => 'rh.view|rh.manage',
                'description' => 'Fiches salariés (date d\'embauche comme date de référence ; choisissez « Toutes dates » pour l\'effectif complet).',
                'from' => 'rh_employees as e', 'company' => 'e.company_id', 'date' => 'e.hire_date',
                'where' => fn (Builder $q) => $q->whereNull('e.deleted_at'),
                'joins' => [],
                'dimensions' => [
                    'department' => ['label' => 'Service', 'sql' => "COALESCE(e.department, '')", 'empty' => 'Sans service'],
                    'job' => ['label' => 'Poste', 'sql' => "COALESCE(e.job_title, '')", 'empty' => 'Non renseigné'],
                    'category' => ['label' => 'Catégorie', 'sql' => "COALESCE(e.category, '')", 'empty' => 'Non renseignée'],
                    'contract' => ['label' => 'Contrat', 'sql' => "COALESCE(e.contract_type, '')", 'empty' => 'Non renseigné'],
                    'gender' => ['label' => 'Sexe', 'sql' => "COALESCE(e.gender, '')", 'labels' => ['M' => 'Homme', 'F' => 'Femme'], 'empty' => 'Non renseigné'],
                    'active' => ['label' => 'Situation', 'sql' => "(CASE WHEN e.active = 1 AND (e.end_date IS NULL OR e.end_date >= '$today') THEN '1' ELSE '0' END)", 'labels' => ['1' => 'En poste', '0' => 'Sorti']],
                ],
                'measures' => [
                    'count' => ['label' => 'Effectif', 'sql' => 'COUNT(*)', 'format' => 'number'],
                    'base_salary' => ['label' => 'Salaires de base', 'sql' => 'SUM(COALESCE(e.base_salary, 0))', 'format' => 'money'],
                    'avg_salary' => ['label' => 'Salaire de base moyen', 'sql' => 'AVG(e.base_salary)', 'format' => 'money', 'additive' => false],
                    'seniority' => ['label' => 'Ancienneté moyenne (ans)', 'sql' => 'AVG('.Sql::yearsSince('e.hire_date').')', 'format' => 'decimal', 'additive' => false],
                ],
            ],
            'crm' => [
                'label' => 'Opportunités (CRM)', 'group' => 'Ventes', 'module' => 'crm', 'permission' => 'crm.view|crm.manage',
                'description' => 'Opportunités commerciales (date de création comme date de référence).',
                'from' => 'crm_leads as ld', 'company' => 'ld.company_id', 'date' => 'ld.created_at',
                'where' => fn (Builder $q) => $q->whereNull('ld.deleted_at'),
                'joins' => ['st' => ['crm_stages as st', 'st.id', '=', 'ld.stage_id']] + $userJoin('ld.owner_id'),
                'dimensions' => [
                    'stage' => ['label' => 'Étape', 'sql' => 'ld.stage_id', 'display' => "COALESCE(st.name, '—')", 'join' => ['st']],
                    'status' => ['label' => 'Statut', 'sql' => 'ld.status', 'labels' => ['open' => 'En cours', 'won' => 'Gagnée', 'lost' => 'Perdue']],
                    'owner' => ['label' => 'Commercial', 'sql' => 'ld.owner_id', 'display' => "COALESCE(u.name, 'Non attribuée')", 'join' => ['u']],
                    'source' => ['label' => 'Origine', 'sql' => "COALESCE(ld.source, '')", 'labels' => ['referral' => 'Recommandation', 'prospecting' => 'Prospection', 'event' => 'Événement', 'website' => 'Site web', 'whatsapp' => 'WhatsApp', 'phone' => 'Téléphone', 'social' => 'Réseaux sociaux', 'other' => 'Autre'], 'empty' => 'Non renseignée'],
                    'city' => ['label' => 'Ville', 'sql' => "COALESCE(ld.city, '')", 'empty' => 'Non renseignée'],
                    'priority' => ['label' => 'Priorité', 'sql' => 'ld.priority', 'labels' => ['1' => 'Normale', '2' => 'Haute', '3' => 'Très haute']],
                ],
                'measures' => [
                    'count' => ['label' => 'Nombre d\'opportunités', 'sql' => 'COUNT(*)', 'format' => 'number'],
                    'expected' => ['label' => 'Montant espéré', 'sql' => 'SUM(COALESCE(ld.expected_revenue, 0))', 'format' => 'money'],
                    'weighted' => ['label' => 'Montant pondéré', 'sql' => 'SUM(COALESCE(ld.expected_revenue, 0) * COALESCE(ld.probability, 0) / 100.0)', 'format' => 'money'],
                    'won_amount' => ['label' => 'Montant gagné', 'sql' => "SUM(CASE WHEN ld.status = 'won' THEN COALESCE(ld.expected_revenue, 0) ELSE 0 END)", 'format' => 'money'],
                    'win_rate' => ['label' => 'Taux de réussite', 'sql' => "(100.0 * SUM(CASE WHEN ld.status = 'won' THEN 1 ELSE 0 END) / NULLIF(SUM(CASE WHEN ld.status IN ('won', 'lost') THEN 1 ELSE 0 END), 0))", 'format' => 'percent', 'additive' => false],
                ],
            ],
            'projets' => [
                'label' => 'Temps passés (projets)', 'group' => 'Projets', 'module' => 'projets', 'permission' => 'projets.manage|projets.view',
                'description' => 'Feuilles de temps : heures, part facturable et valeur au taux horaire du projet.',
                'from' => 'prj_timesheets as t', 'inner' => [['prj_projects as pj', 'pj.id', '=', 't.project_id']],
                'company' => 't.company_id', 'date' => 't.date', 'where' => fn (Builder $q) => $q->whereNull('t.deleted_at'),
                'joins' => ['p' => ['partners as p', 'p.id', '=', 'pj.partner_id']] + $userJoin('t.user_id'),
                'dimensions' => [
                    'project' => ['label' => 'Projet', 'sql' => 't.project_id', 'display' => 'pj.name'],
                    'client' => ['label' => 'Client', 'sql' => 'pj.partner_id', 'display' => "COALESCE(p.name, 'Interne')", 'join' => ['p']],
                    'user' => ['label' => 'Intervenant', 'sql' => 't.user_id', 'display' => "COALESCE(u.name, '—')", 'join' => ['u']],
                    'billable' => ['label' => 'Facturable', 'sql' => "(CASE WHEN t.billable = 1 THEN '1' ELSE '0' END)", 'labels' => $yesNo],
                    'invoiced' => ['label' => 'Facturé', 'sql' => "(CASE WHEN t.invoice_id IS NULL THEN '0' ELSE '1' END)", 'labels' => $yesNo],
                ],
                'measures' => [
                    'hours' => ['label' => 'Heures', 'sql' => 'SUM(t.hours)', 'format' => 'decimal'],
                    'billable_hours' => ['label' => 'Heures facturables', 'sql' => 'SUM(CASE WHEN t.billable = 1 THEN t.hours ELSE 0 END)', 'format' => 'decimal'],
                    'value' => ['label' => 'Valeur facturable', 'sql' => 'SUM(CASE WHEN t.billable = 1 THEN t.hours * COALESCE(pj.hourly_rate, 0) ELSE 0 END)', 'format' => 'money'],
                    'billable_rate' => ['label' => 'Part facturable', 'sql' => '(100.0 * SUM(CASE WHEN t.billable = 1 THEN t.hours ELSE 0 END) / NULLIF(SUM(t.hours), 0))', 'format' => 'percent', 'additive' => false],
                ],
            ],
        ];
        // Multi-agences : axe « Agence » sur toutes les sources bâties sur les documents commerciaux
        foreach ($sources as $k => $src) {
            $onDocs = $src['from'] === 'trade_documents as d' || collect($src['inner'] ?? [])->contains(fn ($j) => $j[0] === 'trade_documents as d');
            if ($onDocs) {
                $sources[$k]['joins']['br'] = ['branches as br', 'br.id', '=', 'd.branch_id'];
                $sources[$k]['dimensions']['branch'] = ['label' => 'Agence', 'sql' => 'd.branch_id', 'display' => "COALESCE(br.name, 'Sans agence')", 'join' => ['br']];
                $sources[$k]['branch'] = 'd.branch_id';
            }
        }

        return self::$cache = $sources;
    }

    /** Tables (alias) de chaque source portant des champs personnalisés. */
    public const CUSTOM = [
        'ventes' => ['d' => 'sales_documents', 'p' => 'partners'],
        'ventes_articles' => ['pr' => 'products', 'd' => 'sales_documents', 'p' => 'partners'],
        'ventes_pipeline' => ['d' => 'sales_documents', 'p' => 'partners'],
        'achats' => ['d' => 'purchase_documents', 'p' => 'partners'],
        'achats_articles' => ['pr' => 'products', 'd' => 'purchase_documents', 'p' => 'partners'],
        'paiements' => ['p' => 'partners'],
        'compta' => ['p' => 'partners'],
        'stock' => ['pr' => 'products'],
        'notes_frais' => ['e' => 'rh_employees'],
        'paie' => ['e' => 'rh_employees'],
        'rh' => ['e' => 'rh_employees'],
        'crm' => ['ld' => 'crm_leads'],
        'projets' => ['pj' => 'prj_projects', 'p' => 'partners'],
    ];

    /** Source avec les champs personnalisés de la société (regroupements, filtres et sommes). */
    public static function get(string $key): ?array
    {
        $src = self::all()[$key] ?? null;
        if (! $src || ! class_exists(\App\Modules\Champs\CustomFields::class) || ! \App\Modules\Champs\CustomFields::enabled()) {
            return $src;
        }
        $sqlite = Sql::sqlite();
        foreach (self::CUSTOM[$key] ?? [] as $alias => $entity) {
            $join = isset($src['joins'][$alias]) ? [$alias] : [];
            $prefix = \App\Modules\Champs\CustomFields::ENTITIES[$entity];
            foreach (\App\Modules\Champs\CustomFields::forEntity($entity) as $f) {
                if (! preg_match('/^[a-z0-9_]{1,40}$/', $f->key)) {
                    continue;
                }
                $raw = $sqlite ? "json_extract($alias.custom, '$.{$f->key}')" : "JSON_UNQUOTE(JSON_EXTRACT($alias.custom, '$.{$f->key}'))";
                $k = 'cf_'.$alias.'_'.$f->key;
                $label = $prefix.' › '.$f->label;
                if (in_array($f->type, ['number', 'money'], true)) {
                    $num = $sqlite ? "CAST($raw AS REAL)" : "CAST($raw AS DECIMAL(20,4))";
                    $src['measures'][$k] = ['label' => 'Somme · '.$label, 'sql' => "SUM(COALESCE($num, 0))", 'format' => $f->type === 'money' ? 'money' : 'decimal', 'join' => $join];
                    $src['measures'][$k.'_avg'] = ['label' => 'Moyenne · '.$label, 'sql' => "AVG($num)", 'format' => $f->type === 'money' ? 'money' : 'decimal', 'additive' => false, 'join' => $join];
                    continue;
                }
                if (in_array($f->type, ['textarea', 'multiselect'], true)) {
                    continue;
                }
                $dim = ['label' => $label, 'sql' => "COALESCE($raw, '')", 'join' => $join, 'empty' => 'Non renseigné'];
                if ($f->type === 'checkbox') {
                    $dim['sql'] = "(CASE WHEN $raw IN ('1', 'true') THEN '1' ELSE '0' END)";
                    $dim['labels'] = ['1' => 'Oui', '0' => 'Non'];
                } elseif ($f->type === 'select') {
                    $dim['labels'] = array_combine($f->options ?? [], $f->options ?? []) ?: [];
                }
                $src['dimensions'][$k] = $dim;
            }
        }

        return $src;
    }

    /** Dimensions de la source, y compris les découpages de la date de référence. */
    public static function dimensions(array $src): array
    {
        $out = [];
        foreach (self::DATE_UNITS as $unit => $label) {
            $out[$unit] = ['label' => $label, 'sql' => Sql::bucket($unit, $src['date']), 'date' => $unit];
        }

        return $out + $src['dimensions'];
    }

    /** Sources accessibles à l'utilisateur (module actif + droit de consultation des données). */
    public static function accessible($company, $user): array
    {
        $out = [];
        foreach (self::all() as $k => $s) {
            if ($company->hasModule($s['module']) && $user->hasPermission($s['permission'])) {
                $out[$k] = self::get($k);
            }
        }

        return $out;
    }

    /** Description publique (constructeur de rapports dans l'application). */
    public static function describe(string $key, array $src): array
    {
        $dims = [];
        foreach (self::dimensions($src) as $k => $d) {
            $dims[] = ['key' => $k, 'label' => $d['label'], 'date' => isset($d['date']), 'values' => isset($d['labels']) ? $d['labels'] : null];
        }
        $measures = [];
        foreach ($src['measures'] as $k => $m) {
            $measures[] = ['key' => $k, 'label' => $m['label'], 'format' => $m['format'], 'additive' => $m['additive'] ?? true];
        }

        return ['key' => $key, 'label' => $src['label'], 'group' => $src['group'], 'description' => $src['description'], 'dimensions' => $dims, 'measures' => $measures];
    }
}
