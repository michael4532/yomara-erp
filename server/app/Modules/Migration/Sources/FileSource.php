<?php

namespace App\Modules\Migration\Sources;

use App\Modules\ImportExport\ImportService as I;
use App\Modules\ImportExport\Sheet;

/**
 * Fichiers d'export (Excel ou CSV) d'un autre logiciel : tiers, articles, factures de ventes et d'achats.
 * Les en-têtes de Sage, QuickBooks, Zoho Books, Xero, EBP, Wave, Tally, SAP Business One, Dynamics, Odoo, Dolibarr…
 * sont reconnus ; les factures « une ligne par article » sont regroupées par numéro de facture.
 */
class FileSource extends Source
{
    /** Champ => en-têtes reconnus (normalisés : minuscules, sans accents ni ponctuation). */
    public const FIELDS = [
        'partners' => [
            'id' => ['id', 'contact id', 'customer id', 'vendor id', 'ct num', 'code tiers', 'code client', 'code fournisseur', 'numero de compte', 'cardcode', 'no', 'account number'],
            'name' => ['name', 'nom', 'raison sociale', 'display name', 'company name', 'contactname', 'contact name', 'customer', 'customer name', 'vendor', 'vendor name', 'supplier',
                'supplier name', 'ct intitule', 'intitule', 'cardname', 'tiers', 'client', 'fournisseur', 'societe', 'ledger name', 'nom du tiers', 'customer display name'],
            'type' => ['type', 'contact type', 'type de tiers', 'ct type', 'cardtype', 'nature', 'categorie'],
            'email' => ['email', 'e mail', 'emailid', 'emailaddress', 'email address', 'courriel', 'ct email', 'e mail address', 'primary email'],
            'phone' => ['phone', 'telephone', 'tel', 'mobile', 'mobile phone', 'phonenumber', 'phone number', 'ct telephone', 'phone1', 'portable', 'work phone'],
            'address' => ['address', 'adresse', 'billing address', 'billing street', 'street', 'poaddressline1', 'ct adresse', 'address1', 'billing address line 1', 'adresse de facturation'],
            'city' => ['city', 'ville', 'billing city', 'pocity', 'ct ville', 'commune', 'town'],
            'tax_id' => ['tax id', 'taxnumber', 'tax number', 'vat number', 'tva intra', 'ncc', 'nif', 'ct identifiant', 'gstin', 'federaltaxid', 'n contribuable', 'numero de tva', 'rccm', 'idprof1'],
            'code' => ['code', 'reference', 'ref', 'account no', 'customer code'],
            'notes' => ['notes', 'note', 'remarks', 'commentaire', 'description', 'observations'],
        ],
        'products' => [
            'id' => ['id', 'item id', 'product id', 'itemcode', 'item code', 'ar ref', 'code article', 'reference', 'ref', 'sku', 'internal reference', 'item no', 'no'],
            'name' => ['name', 'item name', 'itemname', 'product name', 'product service name', 'designation', 'libelle', 'ar design', 'item description', 'nom', 'article', 'produit',
                'description', 'label', 'stock item name'],
            'sku' => ['sku', 'item code', 'itemcode', 'ar ref', 'reference', 'ref', 'code', 'code article', 'internal reference', 'default code', 'product code'],
            'barcode' => ['barcode', 'code barre', 'code barres', 'ean', 'ean13', 'ar codebarre', 'upc', 'codebars'],
            'category' => ['category', 'categorie', 'famille', 'fa codefamille', 'item group', 'itemsgroupcode', 'product category', 'group', 'rayon', 'stock group'],
            'type' => ['type', 'product type', 'item type', 'nature', 'type de produit'],
            'unit' => ['unit', 'unite', 'usage unit', 'uom', 'unit of measure', 'stock uom', 'base unit'],
            'sale_price' => ['sale price', 'sales price', 'selling price', 'rate', 'salesunitprice', 'sales unit price', 'price', 'prix de vente', 'prix de vente ht', 'pv ht', 'ar prixven',
                'unit price', 'list price', 'prix ht', 'standard rate', 'tarif'],
            'purchase_price' => ['purchase rate', 'purchase price', 'purchasesunitprice', 'purchases unit price', 'cost', 'cost price', 'prix d achat', 'prix achat', 'ar prixach',
                'pa ht', 'standard price', 'valuation rate', 'unit cost', 'cout'],
            'tax_rate' => ['tax percentage', 'tax rate', 'taux tva', 'tva', 'item tax', 'item tax %', 'vat rate', 'taxe'],
            'stock' => ['stock', 'quantity on hand', 'qty on hand', 'quantityonhand', 'stock on hand', 'quantite en stock', 'qte en stock', 'stock reel', 'onhand', 'closing quantity', 'qty'],
        ],
        'documents' => [
            'number' => ['invoice number', 'invoicenumber', 'invoice no', 'invoice', 'bill number', 'bill', 'numero', 'numero de facture', 'n facture', 'facture', 'num', 'no', 'docnum',
                'do piece', 'piece', 'ref', 'reference facture', 'voucher number', 'document number', 'invoice #'],
            'date' => ['invoice date', 'invoicedate', 'date', 'bill date', 'date facture', 'date de facture', 'docdate', 'do date', 'posting date', 'voucher date', 'transaction date'],
            'due_date' => ['due date', 'duedate', 'echeance', 'date echeance', 'date d echeance', 'docduedate', 'payment due'],
            'partner' => ['customer name', 'customer', 'contactname', 'contact name', 'vendor name', 'vendor', 'supplier name', 'supplier', 'client', 'fournisseur', 'tiers', 'cardname',
                'ct intitule', 'nom client', 'party name', 'party', 'customer display name', 'name'],
            'partner_id' => ['customer id', 'vendor id', 'contact id', 'cardcode', 'ct num', 'code client', 'code tiers', 'code fournisseur', 'do tiers'],
            'description' => ['item name', 'description', 'item description', 'product service', 'product', 'designation', 'libelle', 'line description', 'dl design', 'dscription',
                'article', 'stock item', 'memo'],
            'sku' => ['sku', 'item code', 'itemcode', 'ar ref', 'product code', 'code article', 'reference article'],
            'quantity' => ['quantity', 'qty', 'qte', 'quantite', 'dl qte', 'billed quantity', 'item quantity'],
            'unit_price' => ['item price', 'unitamount', 'unit amount', 'unit price', 'rate', 'price', 'prix unitaire', 'pu ht', 'p u ht', 'dl prixunitaire', 'sales price', 'line rate'],
            'line_total' => ['line amount', 'lineamount', 'item total', 'line total', 'montant ht ligne', 'total ht ligne', 'amount ht', 'linetotal', 'dl montantht'],
            'tax_rate' => ['item tax %', 'item tax', 'tax percentage', 'tax rate', 'taxe', 'tva', 'taux tva', 'vat rate', 'vatpercent'],
            'discount' => ['discount', 'discount %', 'remise', 'remise %', 'discountpercent', 'dl remise01rem valeur'],
            'total' => ['total', 'invoice total', 'total ttc', 'montant ttc', 'grand total', 'doctotal', 'do totalttc', 'total amount', 'amount'],
            'balance' => ['balance', 'balance due', 'amountdue', 'amount due', 'reste a payer', 'reste du', 'solde', 'open balance', 'outstanding amount', 'restant du'],
            'paid' => ['paid', 'amount paid', 'amountpaid', 'deja regle', 'deja paye', 'regle', 'montant regle', 'paidtodate'],
            'status' => ['status', 'invoice status', 'etat', 'statut', 'docstatus'],
            'type' => ['type', 'document type', 'invoice type', 'do type', 'type de piece', 'voucher type'],
            'currency' => ['currency', 'currency code', 'devise', 'currencycode'],
        ],
    ];

    /** Éditeurs reconnus et où trouver l'export. */
    public const VENDORS = [
        'sage' => ['Sage 100 / Saari / Sage 50', 'Fichier › Exporter › Format paramétrable (Tiers, Articles, Documents de vente ligne à ligne), au format Excel ou texte séparé.'],
        'quickbooks' => ['QuickBooks', 'Rapports › Liste des clients / fournisseurs / produits et services › Exporter vers Excel ; Ventes › Factures : rapport « Détail des ventes par client » exporté.'],
        'zoho' => ['Zoho Books / Zoho Invoice', 'Paramètres › Exporter les données : Contacts, Articles, Factures, Factures fournisseurs (CSV ou XLSX).'],
        'xero' => ['Xero', 'Contacts › Exporter ; Produits et services › Exporter ; Ventes › Factures › Exporter (CSV).'],
        'ebp' => ['EBP Gestion commerciale / Compta', 'Listes Clients, Fournisseurs, Articles : bouton Exporter (Excel) ; Ventes › Factures : export de la liste détaillée.'],
        'wave' => ['Wave', 'Paramètres › Exportations de données : clients, fournisseurs, produits ; Factures › exporter en CSV.'],
        'tally' => ['Tally / TallyPrime', 'Display › List of Accounts / Stock Items › Export (Excel) ; Day Book (Sales Vouchers) en Excel.'],
        'sap' => ['SAP Business One', 'Partenaires, Articles, Factures clients : clic droit sur la liste › Exporter vers Excel.'],
        'dynamics' => ['Microsoft Dynamics 365 / Business Central / NAV', 'Listes Clients, Fournisseurs, Articles, Factures vente validées : « Ouvrir dans Excel ».'],
        'odoo_csv' => ['Odoo (fichiers)', 'Liste › sélectionner tout › Action › Exporter (XLSX), pour Contacts, Articles et Factures avec leurs lignes.'],
        'dolibarr_csv' => ['Dolibarr (fichiers)', 'Outils › Exports : Tiers, Produits, Factures clients et lignes de factures.'],
        'excel' => ['Excel ou autre logiciel', 'Un fichier par type : une ligne par client, par article, ou par ligne de facture (avec le numéro de facture répété).'],
    ];

    /** @param array<string, string> $files type (partners|products|sales|purchases) => chemin du fichier */
    public function __construct(protected array $files, protected string $vendor = 'excel') {}

    public function key(): string
    {
        return 'file:'.$this->vendor;
    }

    /** Colonnes reconnues : champ => index. */
    public static function mapHeaders(string $kind, array $headers): array
    {
        $map = [];
        $used = [];
        $norm = array_map(fn ($h) => I::norm((string) $h), $headers);
        foreach ([true, false] as $exact) {
            foreach (self::FIELDS[$kind] as $field => $names) {
                if (isset($map[$field])) {
                    continue;
                }
                foreach ($names as $n) {
                    foreach ($norm as $i => $h) {
                        if ($h === '' || isset($used[$i])) {
                            continue;
                        }
                        if ($exact ? $h === $n : (strlen($n) > 4 && str_starts_with($h, $n.' '))) {
                            $map[$field] = $i;
                            if ($field !== 'id') {
                                $used[$i] = true; // l'identifiant peut partager sa colonne (code, référence)
                            }
                            continue 3;
                        }
                    }
                }
            }
        }

        return $map;
    }

    protected function rows(string $type): array
    {
        if (empty($this->files[$type])) {
            return [[], []];
        }
        [$headers, $rows] = Sheet::read($this->files[$type], 60000);
        $kind = in_array($type, ['sales', 'purchases'], true) ? 'documents' : $type;

        return [self::mapHeaders($kind, $headers), $rows, $headers];
    }

    public function probe(): array
    {
        $counts = [];
        $columns = [];
        foreach (['partners', 'products', 'sales', 'purchases'] as $t) {
            if (empty($this->files[$t])) {
                continue;
            }
            [$map, $rows, $headers] = $this->rows($t);
            $kind = in_array($t, ['sales', 'purchases'], true) ? 'documents' : $t;
            $required = $kind === 'documents' ? ['number', 'partner'] : ['name'];
            foreach ($required as $r) {
                if (! isset($map[$r])) {
                    throw new \DomainException('Fichier « '.self::LABELS[$t].' » : colonne « '.self::REQUIRED_LABELS[$r].' » introuvable. Colonnes lues : '.implode(', ', array_slice(array_filter($headers), 0, 12)).'.');
                }
            }
            $counts[$t] = $kind === 'documents' ? count(array_unique(array_map(fn ($r) => trim((string) ($r[$map['number']] ?? '')), $rows))) : count($rows);
            $columns[$t] = collect($map)->map(fn ($i) => $headers[$i])->all();
        }

        return ['name' => self::VENDORS[$this->vendor][0] ?? 'Fichiers', 'version' => null, 'counts' => $counts, 'columns' => $columns];
    }

    public const LABELS = ['partners' => 'Clients & fournisseurs', 'products' => 'Articles', 'sales' => 'Factures de ventes', 'purchases' => 'Factures d\'achats'];

    public const REQUIRED_LABELS = ['name' => 'nom', 'number' => 'numéro de facture', 'partner' => 'client / fournisseur'];

    protected static function v(array $row, array $map, string $f): mixed
    {
        if (! isset($map[$f])) {
            return null;
        }
        $v = $row[$map[$f]] ?? null;

        return is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;
    }

    protected static function n(mixed $v): ?float
    {
        try {
            return I::number($v);
        } catch (\DomainException) {
            return null;
        }
    }

    protected static function d(mixed $v): ?string
    {
        try {
            return I::date($v);
        } catch (\DomainException) {
            foreach (['m/d/Y', 'd M Y', 'M d, Y', 'Y-m-d\TH:i:s', 'd-M-Y', 'd/m/Y H:i:s'] as $f) {
                $d = \DateTime::createFromFormat('!'.$f, trim((string) $v));
                if ($d) {
                    return $d->format('Y-m-d');
                }
            }

            return null;
        }
    }

    public function partners(): iterable
    {
        [$map, $rows] = $this->rows('partners');
        foreach ($rows as $i => $r) {
            $name = self::v($r, $map, 'name');
            if (! $name) {
                continue;
            }
            $t = I::norm((string) self::v($r, $map, 'type'));
            yield ['id' => (string) (self::v($r, $map, 'id') ?? 'L'.$i.':'.$name), 'name' => (string) $name,
                'type' => match (true) {
                    $t === '' => 'customer',
                    str_contains($t, 'both') || str_contains($t, 'deux') || (str_contains($t, 'client') && str_contains($t, 'fourn')) => 'both',
                    str_contains($t, 'vendor') || str_contains($t, 'supplier') || str_contains($t, 'fourn') || in_array($t, ['s', 'f', '1', 'creditors', 'sundry creditors'], true) => 'supplier',
                    default => 'customer',
                },
                'code' => self::v($r, $map, 'code'), 'email' => self::v($r, $map, 'email'), 'phone' => ($p = self::v($r, $map, 'phone')) !== null ? (string) $p : null,
                'address' => self::v($r, $map, 'address'), 'city' => self::v($r, $map, 'city'), 'tax_id' => ($x = self::v($r, $map, 'tax_id')) !== null ? (string) $x : null,
                'notes' => self::v($r, $map, 'notes')];
        }
    }

    public function products(): iterable
    {
        [$map, $rows] = $this->rows('products');
        foreach ($rows as $i => $r) {
            $name = self::v($r, $map, 'name') ?? self::v($r, $map, 'sku');
            if (! $name) {
                continue;
            }
            $t = I::norm((string) self::v($r, $map, 'type'));
            $tax = self::n(self::v($r, $map, 'tax_rate'));
            yield ['id' => (string) (self::v($r, $map, 'id') ?? self::v($r, $map, 'sku') ?? 'L'.$i.':'.$name), 'name' => (string) $name,
                'sku' => ($s = self::v($r, $map, 'sku')) !== null ? (string) $s : null, 'barcode' => ($b = self::v($r, $map, 'barcode')) !== null ? preg_replace('/\.0+$/', '', (string) $b) : null,
                'category' => self::v($r, $map, 'category'), 'type' => str_contains($t, 'serv') || str_contains($t, 'prestation') || $t === 'non inventory' ? 'service' : 'product',
                'unit' => self::v($r, $map, 'unit'), 'sale_price' => self::n(self::v($r, $map, 'sale_price')) ?? 0, 'purchase_price' => self::n(self::v($r, $map, 'purchase_price')) ?? 0,
                'tax_rate' => $tax !== null && $tax > 0 && $tax < 1 ? $tax * 100 : $tax, 'stock' => self::n(self::v($r, $map, 'stock')), 'active' => true];
        }
    }

    public function documents(string $direction): iterable
    {
        [$map, $rows] = $this->rows($direction === 'sale' ? 'sales' : 'purchases');
        $docs = [];
        foreach ($rows as $r) {
            $num = trim((string) self::v($r, $map, 'number'));
            if ($num === '') {
                continue;
            }
            $d = &$docs[$num];
            if (! $d) {
                $type = I::norm((string) self::v($r, $map, 'type'));
                $status = I::norm((string) self::v($r, $map, 'status'));
                $d = ['id' => $num, 'number' => $num, 'direction' => $direction,
                    'type' => str_contains($type, 'avoir') || str_contains($type, 'credit') || str_contains($type, 'refund') || str_contains($type, 'return') ? 'credit_note' : 'invoice',
                    'partner_id' => ($pid = self::v($r, $map, 'partner_id')) !== null ? (string) $pid : null, 'partner_name' => (string) self::v($r, $map, 'partner'),
                    'date' => self::d(self::v($r, $map, 'date')), 'due_date' => self::d(self::v($r, $map, 'due_date')),
                    'status' => match (true) {
                        in_array($status, ['draft', 'brouillon'], true) => 'draft',
                        str_contains($status, 'void') || str_contains($status, 'annul') || str_contains($status, 'cancel') || str_contains($status, 'deleted') => 'cancelled',
                        default => 'validated',
                    },
                    'currency' => self::v($r, $map, 'currency'), 'reference' => null, 'total' => null, 'balance' => null, 'paid' => null, 'payments' => [], 'lines' => []];
            }
            $d['total'] ??= self::n(self::v($r, $map, 'total'));
            $d['balance'] ??= self::n(self::v($r, $map, 'balance'));
            $d['paid'] ??= self::n(self::v($r, $map, 'paid'));
            $qty = self::n(self::v($r, $map, 'quantity'));
            $price = self::n(self::v($r, $map, 'unit_price'));
            $lineTotal = self::n(self::v($r, $map, 'line_total'));
            if ($price === null && $lineTotal !== null) {
                $price = $lineTotal / (($qty ?: 1));
            }
            if ($price !== null || self::v($r, $map, 'description') !== null) {
                $tax = self::n(self::v($r, $map, 'tax_rate'));
                $d['lines'][] = ['description' => (string) (self::v($r, $map, 'description') ?? 'Ligne'), 'sku' => ($s = self::v($r, $map, 'sku')) !== null ? (string) $s : null,
                    'product_id' => null, 'quantity' => abs($qty ?? 1) ?: 1, 'unit_price' => abs($price ?? 0),
                    'tax_rate' => $tax !== null && $tax > 0 && $tax < 1 ? $tax * 100 : ($tax ?? 0), 'discount' => self::n(self::v($r, $map, 'discount')) ?? 0];
            }
            unset($d);
        }
        foreach ($docs as $d) {
            $d['total'] = $d['total'] !== null ? abs($d['total']) : null;
            if ($d['paid'] === null && $d['balance'] !== null && $d['total'] !== null) {
                $d['paid'] = max(0, $d['total'] - abs($d['balance']));
            }
            $d['payments'] = ($d['paid'] ?? 0) > 0 ? [['date' => $d['date'], 'amount' => $d['paid'], 'method' => 'other', 'reference' => 'Règlements repris']] : [];
            if (! $d['lines'] && $d['total']) {
                $d['lines'][] = ['description' => 'Facture '.$d['number'], 'product_id' => null, 'quantity' => 1, 'unit_price' => $d['total'], 'tax_rate' => 0, 'discount' => 0];
            }
            yield $d;
        }
    }
}
