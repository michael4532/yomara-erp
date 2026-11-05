<?php

namespace App\Modules\ImportExport;

use App\Models\Company;
use App\Modules\Core\Models\Partner;
use App\Modules\Core\Models\Product;
use App\Modules\Core\Models\ProductCategory;
use App\Modules\ImportExport\Models\ImportJob;
use App\Modules\Stock\Models\StockMove;
use App\Modules\Stock\Models\Warehouse;
use App\Modules\Ventes\Models\SalesDocument;
use App\Services\EntityWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reprise de données depuis Excel / CSV. Chaque ligne passe par EntityWriter :
 * mêmes contrôles que la saisie dans l'application (droits mis à part).
 */
class ImportService
{
    public function __construct(protected EntityWriter $writer) {}

    /** Types importables : champs, synonymes d'en-têtes reconnus, clés de rapprochement. */
    public function definitions(Company $company): array
    {
        $defs = [
            'partners' => [
                'label' => 'Clients & fournisseurs',
                'module' => 'core',
                'keys' => ['name' => 'Nom', 'tax_id' => 'N° contribuable', 'email' => 'E-mail', 'phone' => 'Téléphone', 'code' => 'Code'],
                'fields' => [
                    'name' => ['Nom / raison sociale', ['nom', 'raison sociale', 'client', 'fournisseur', 'societe', 'name', 'nom complet', 'tiers'], true],
                    'type' => ['Type (client, fournisseur, les deux)', ['type', 'categorie', 'nature']],
                    'code' => ['Code', ['code', 'code client', 'code tiers', 'reference', 'ref']],
                    'phone' => ['Téléphone', ['telephone', 'tel', 'mobile', 'portable', 'phone', 'contact', 'cellulaire']],
                    'email' => ['E-mail', ['email', 'e mail', 'mail', 'courriel', 'adresse email']],
                    'address' => ['Adresse', ['adresse', 'address', 'rue']],
                    'city' => ['Ville', ['ville', 'commune', 'city', 'localite', 'quartier']],
                    'tax_id' => ['N° contribuable (NCC)', ['ncc', 'n cc', 'cc', 'n contribuable', 'numero contribuable', 'compte contribuable', 'nif', 'tax id', 'ifu', 'rccm']],
                    'credit_limit' => ['Plafond de crédit', ['plafond', 'encours max', 'credit', 'limite de credit']],
                    'notes' => ['Notes', ['notes', 'commentaire', 'observations', 'remarque']],
                ],
            ],
            'products' => [
                'label' => 'Articles (produits & services)',
                'module' => 'core',
                'keys' => ['sku' => 'Référence', 'barcode' => 'Code-barres', 'name' => 'Désignation'],
                'fields' => [
                    'name' => ['Désignation', ['designation', 'libelle', 'nom', 'article', 'produit', 'description courte', 'name'], true],
                    'sku' => ['Référence', ['reference', 'ref', 'code', 'code article', 'sku']],
                    'barcode' => ['Code-barres', ['code barre', 'code barres', 'ean', 'ean13', 'gencod', 'barcode']],
                    'category' => ['Catégorie / famille', ['categorie', 'famille', 'rayon', 'groupe', 'category']],
                    'type' => ['Type (produit / service)', ['type', 'nature']],
                    'unit' => ['Unité', ['unite', 'unit', 'u m', 'conditionnement']],
                    'sale_price' => ['Prix de vente HT', ['prix de vente ht', 'pv ht', 'prix ht', 'prix vente ht', 'tarif ht']],
                    'sale_price_ttc' => ['Prix de vente TTC', ['prix de vente ttc', 'pv ttc', 'prix ttc', 'prix de vente', 'prix', 'pv', 'tarif']],
                    'purchase_price' => ['Prix d\'achat HT', ['prix d achat', 'prix achat', 'pa', 'pa ht', 'cout', 'cout d achat', 'prix de revient']],
                    'tax_rate' => ['TVA (%)', ['tva', 'taux tva', 'taux de tva', 'taxe']],
                    'min_stock' => ['Seuil d\'alerte', ['stock mini', 'stock minimum', 'seuil', 'seuil d alerte', 'alerte']],
                    'stock' => ['Stock actuel', ['stock', 'quantite', 'qte', 'stock actuel', 'quantite en stock', 'stock initial']],
                    'description' => ['Description', ['description', 'details']],
                    'active' => ['Actif (oui / non)', ['actif', 'active', 'en vente']],
                ],
            ],
        ];
        if ($company->hasModule('stock')) {
            $defs['stock'] = [
                'label' => 'Inventaire (quantités comptées)',
                'module' => 'stock',
                'keys' => ['sku' => 'Référence', 'barcode' => 'Code-barres', 'name' => 'Désignation'],
                'fields' => [
                    'product' => ['Article (référence, code-barres ou désignation)', ['article', 'reference', 'ref', 'code', 'code article', 'designation', 'produit', 'code barre', 'ean'], true],
                    'quantity' => ['Quantité comptée', ['quantite', 'qte', 'stock', 'quantite comptee', 'stock reel', 'compte'], true],
                    'warehouse' => ['Dépôt', ['depot', 'magasin', 'entrepot', 'boutique', 'warehouse']],
                    'unit_cost' => ['Coût unitaire', ['cout', 'prix achat', 'pa', 'cout unitaire', 'valeur unitaire']],
                ],
            ];
        }
        if ($company->hasModule('ventes')) {
            $defs['invoices'] = [
                'label' => 'Factures clients impayées (soldes d\'ouverture)',
                'module' => 'ventes',
                'keys' => ['reference' => 'N° de facture d\'origine'],
                'fields' => [
                    'partner' => ['Client', ['client', 'nom', 'raison sociale', 'tiers', 'nom client'], true],
                    'reference' => ['N° de facture d\'origine', ['numero', 'n facture', 'numero facture', 'facture', 'reference', 'ref', 'piece'], true],
                    'date' => ['Date de facture', ['date', 'date facture', 'date de facture', 'emise le'], true],
                    'due_date' => ['Échéance', ['echeance', 'date echeance', 'date d echeance', 'a payer le']],
                    'amount' => ['Montant TTC', ['montant', 'montant ttc', 'total', 'total ttc', 'ttc'], true],
                    'paid' => ['Déjà payé', ['deja paye', 'paye', 'regle', 'acompte', 'montant regle', 'encaisse']],
                ],
            ];
        }

        return $defs;
    }

    public static function norm(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($s))));
    }

    /** Correspondance proposée : champ → index de colonne. */
    public function suggest(array $def, array $headers): array
    {
        $map = [];
        $used = [];
        // 1) en-tête identique au libellé ou à un synonyme ; 2) synonyme contenu dans l'en-tête
        foreach ([true, false] as $exact) {
            foreach ($def['fields'] as $field => $f) {
                if (isset($map[$field])) {
                    continue;
                }
                $names = array_map([self::class, 'norm'], array_merge([$f[0], $field], $f[1]));
                foreach ($headers as $i => $h) {
                    $n = self::norm((string) $h);
                    if ($n === '' || in_array($i, $used, true)) {
                        continue;
                    }
                    $hit = $exact ? in_array($n, $names, true) : collect($names)->contains(fn ($s) => strlen($s) > 3 && (str_contains($n, $s) || str_contains($s, $n)));
                    if ($hit) {
                        $map[$field] = $i;
                        $used[] = $i;
                        break;
                    }
                }
            }
        }

        return $map;
    }

    // ---------- Conversion des valeurs ----------

    public static function number($v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = str_replace(["\u{00A0}", "\u{202F}", ' ', 'F', 'CFA', 'FCFA', 'XOF', '%'], '', (string) $v);
        // 1.234,56 / 1,234.56 / 1234,56
        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        } elseif (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $s)) {
            $s = str_replace(',', '', $s);
        } else {
            $s = str_replace(',', '.', $s);
        }

        return is_numeric($s) ? (float) $s : throw new \DomainException('« '.$v.' » n\'est pas un nombre.');
    }

    public static function date($v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v) && $v > 20000 && $v < 80000) { // numéro de série Excel
            return \Carbon\Carbon::create(1899, 12, 30)->addDays((int) $v)->toDateString();
        }
        $s = trim((string) $v);
        foreach (['d/m/Y', 'd/m/y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'Y/m/d', 'd/m/Y H:i', 'Y-m-d H:i:s'] as $f) {
            $d = \DateTime::createFromFormat('!'.$f, $s);
            if ($d && $d->format($f) === $s) {
                return $d->format('Y-m-d');
            }
        }
        throw new \DomainException('Date « '.$v.' » illisible (attendu : jj/mm/aaaa).');
    }

    public static function bool($v, bool $default = true): bool
    {
        if ($v === null || $v === '') {
            return $default;
        }

        return in_array(self::norm((string) $v), ['1', 'oui', 'o', 'yes', 'y', 'vrai', 'true', 'x', 'actif'], true);
    }

    // ---------- Exécution ----------

    /** Importe (ou vérifie, si $dryRun) les lignes du fichier. */
    public function run(ImportJob $job, array $mapping, string $mode, string $key, bool $dryRun, bool $atomic = false): array
    {
        @set_time_limit(600);
        $company = app('tenant');
        $def = $this->definitions($company)[$job->entity] ?? throw new \DomainException('Type d\'import inconnu.');
        foreach ($def['fields'] as $f => $d) {
            if (($d[2] ?? false) && ! isset($mapping[$f]) && ! ($job->entity === 'products' && $f === 'name' && isset($mapping['name']))) {
                throw new \DomainException('Associez une colonne au champ obligatoire « '.$d[0].' ».');
            }
        }
        [, $rows] = Sheet::read(\Illuminate\Support\Facades\Storage::disk('local')->path($job->path));
        $report = ['rows' => count($rows), 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        $ctx = $this->context($job->entity, $key);

        DB::beginTransaction();
        try {
            foreach ($rows as $i => $row) {
                $line = $i + 2; // ligne Excel (en-tête = 1)
                $get = fn (string $f) => isset($mapping[$f]) ? ($row[$mapping[$f]] ?? null) : null;
                try {
                    $res = DB::transaction(fn () => match ($job->entity) {
                        'partners' => $this->partner($get, $mode, $key, $ctx),
                        'products' => $this->product($get, $mode, $key, $ctx, $company),
                        'stock' => $this->stock($get, $key, $ctx),
                        'invoices' => $this->invoice($get, $mode, $ctx),
                    });
                    $report[$res]++;
                } catch (\DomainException $e) {
                    $report['failed']++;
                    if (count($report['errors']) < 500) {
                        $report['errors'][] = ['row' => $line, 'message' => $e->getMessage()];
                    }
                }
            }
            ($dryRun || ($atomic && $report['failed'] > 0)) ? DB::rollBack() : DB::commit();
            $report['rolled_back'] = ! $dryRun && $atomic && $report['failed'] > 0;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $job->forceFill([
            'status' => $dryRun ? 'checked' : 'done', 'rows' => $report['rows'], 'created' => $report['created'], 'updated' => $report['updated'],
            'skipped' => $report['skipped'], 'failed' => $report['failed'], 'errors' => $report['errors'], 'mapping' => $mapping, 'options' => compact('mode', 'key', 'atomic'),
        ])->save();

        return $report + ['dry_run' => $dryRun];
    }

    protected function context(string $entity, string $key): array
    {
        $index = fn ($items, string $field) => $items->filter(fn ($m) => filled($m->{$field}))->keyBy(fn ($m) => self::norm((string) $m->{$field}))->all();

        return match ($entity) {
            'partners' => ['by' => $index($partners = Partner::all(), $key), 'alt' => collect(['code', 'tax_id', 'email', 'phone', 'name'])->mapWithKeys(fn ($f) => [$f => $index($partners, $f)])->all()],
            'products' => ['by' => $index($products = Product::all(), $key), 'alt' => collect(['sku', 'barcode', 'name'])->mapWithKeys(fn ($f) => [$f => $index($products, $f)])->all(),
                'cats' => ProductCategory::all()->keyBy(fn ($c) => self::norm($c->name))->all()],
            'stock' => ['by' => [
                'sku' => $index(Product::all(), 'sku'), 'barcode' => $index(Product::all(), 'barcode'), 'name' => $index(Product::all(), 'name'),
            ], 'wh' => Warehouse::all()->keyBy(fn ($w) => self::norm($w->name))->all()],
            'invoices' => ['partners' => $index(Partner::all(), 'name'), 'refs' => SalesDocument::where('type', 'invoice')->whereNotNull('reference')->pluck('reference')->map(fn ($r) => self::norm($r))->flip()->all()],
        };
    }

    protected function write(string $class, ?string $id, array $data): \Illuminate\Database\Eloquent\Model
    {
        $r = $this->writer->upsert($class, $id ?? (string) Str::uuid(), $data);
        if ($r['status'] !== 'applied') {
            $msg = $r['errors'] ? collect($r['errors'])->flatten()->first() : $r['message'];
            throw new \DomainException((string) $msg);
        }

        return $r['record'];
    }

    /**
     * Fiche existante : par la clé choisie, sinon (clé vide sur cette ligne, ex. article sans référence) par les autres clés
     * dans l'ordre (référence, code-barres, nom…). Évite qu'un fichier réimporté crée des doublons.
     */
    protected function existing(array $ctx, string $key, array $data): ?\Illuminate\Database\Eloquent\Model
    {
        $k = self::norm((string) ($data[$key] ?? ''));
        if ($k !== '') {
            return $ctx['by'][$k] ?? null;
        }
        foreach ($ctx['alt'] ?? [] as $field => $idx) {
            $v = self::norm((string) ($data[$field] ?? ''));
            if ($field !== $key && $v !== '' && isset($idx[$v])) {
                return $idx[$v];
            }
        }

        return null;
    }

    /** Mémorise une fiche créée ou mise à jour pour les lignes suivantes du même fichier. */
    protected function remember(array &$ctx, string $k, \Illuminate\Database\Eloquent\Model $m): void
    {
        if ($k !== '') {
            $ctx['by'][$k] = $m;
        }
        foreach (array_keys($ctx['alt'] ?? []) as $f) {
            if (filled($m->{$f})) {
                $ctx['alt'][$f][self::norm((string) $m->{$f})] = $m;
            }
        }
    }

    protected function partner(\Closure $get, string $mode, string $key, array &$ctx): string
    {
        $name = trim((string) $get('name'));
        if ($name === '') {
            throw new \DomainException('Nom manquant.');
        }
        $type = self::norm((string) $get('type'));
        $data = array_filter([
            'name' => $name,
            'type' => match (true) {
                $type === '' => null,
                str_contains($type, 'deux') || (str_contains($type, 'client') && str_contains($type, 'fourn')) || $type === 'both' => 'both',
                str_contains($type, 'fourn') || $type === 'supplier' || $type === 'f' => 'supplier',
                default => 'customer',
            },
            'code' => $get('code'), 'phone' => $get('phone') !== null ? (string) $get('phone') : null, 'email' => $get('email') ? strtolower((string) $get('email')) : null,
            'address' => $get('address'), 'city' => $get('city'), 'tax_id' => $get('tax_id') !== null ? (string) $get('tax_id') : null,
            'credit_limit' => self::number($get('credit_limit')), 'notes' => $get('notes'),
        ], fn ($v) => $v !== null && $v !== '');
        $k = self::norm((string) ($key === 'name' ? $name : ($data[$key] ?? '')));
        $existing = $this->existing($ctx, $key, $data + ['name' => $name]);

        return $this->apply(Partner::class, $existing, $data + ($existing ? [] : ['type' => 'customer']), $mode, function ($m) use (&$ctx, $k) {
            $this->remember($ctx, $k, $m);
        });
    }

    protected function product(\Closure $get, string $mode, string $key, array &$ctx, Company $company): string
    {
        $name = trim((string) $get('name'));
        if ($name === '') {
            throw new \DomainException('Désignation manquante.');
        }
        $tax = self::number($get('tax_rate'));
        if ($tax !== null && $tax > 0 && $tax < 1) {
            $tax *= 100; // 0,18 → 18 %
        }
        $type = self::norm((string) $get('type'));
        $data = [
            'name' => $name, 'sku' => $get('sku') !== null ? (string) $get('sku') : null, 'barcode' => $get('barcode') !== null ? preg_replace('/\.0+$/', '', (string) $get('barcode')) : null,
            'unit' => $get('unit') ? mb_strtolower((string) $get('unit')) : null, 'purchase_price' => self::number($get('purchase_price')), 'min_stock' => self::number($get('min_stock')),
            'description' => $get('description'), 'type' => $type === '' ? null : (str_contains($type, 'serv') || str_contains($type, 'prestation') ? 'service' : 'product'),
        ];
        $existing0 = null;
        $rate = $tax ?? null;
        $ht = self::number($get('sale_price'));
        $ttc = self::number($get('sale_price_ttc'));
        if ($get('active') !== null) {
            $data['active'] = self::bool($get('active'));
        }
        $k = self::norm((string) ($key === 'name' ? $name : ($data[$key] ?? '')));
        $existing0 = $this->existing($ctx, $key, array_filter($data, fn ($v) => $v !== null && $v !== '') + ['name' => $name]);
        $rate ??= $existing0?->tax_rate ?? (float) ($company->settings['tax_rate'] ?? 18);
        $data['tax_rate'] = $rate;
        if ($ht === null && $ttc !== null) {
            $ht = round($ttc / (1 + $rate / 100), 4);
        }
        $data['sale_price'] = $ht;
        if ($cat = trim((string) $get('category'))) {
            $n = self::norm($cat);
            $ctx['cats'][$n] ??= ProductCategory::create(['name' => $cat]);
            $data['category_id'] = $ctx['cats'][$n]->id;
        }
        $data = array_filter($data, fn ($v) => $v !== null && $v !== '');
        if (! $existing0) {
            $data += ['type' => 'product', 'sale_price' => 0, 'track_stock' => ($data['type'] ?? 'product') === 'product', 'unit' => 'unité'];
        }
        $result = $this->apply(Product::class, $existing0, $data, $mode, function ($m) use (&$ctx, $k) {
            $this->remember($ctx, $k, $m);
        });
        // Stock actuel : mouvement pour atteindre la quantité indiquée
        $qty = self::number($get('stock'));
        if ($qty !== null && $result !== 'skipped' && $company->hasModule('stock')) {
            $p = ($k !== '' ? ($ctx['by'][$k] ?? null) : null) ?? $existing0 ?? Product::where('name', $name)->latest()->first();
            if ($p && $p->type === 'product') {
                $this->setStock($p, Warehouse::defaultFor($company->id), $qty, $p->purchase_price, true);
            }
        }

        return $result;
    }

    protected function stock(\Closure $get, string $key, array &$ctx): string
    {
        $ref = self::norm((string) $get('product'));
        $p = $ctx['by'][$key][$ref] ?? $ctx['by']['sku'][$ref] ?? $ctx['by']['barcode'][$ref] ?? $ctx['by']['name'][$ref] ?? null;
        if (! $p) {
            throw new \DomainException('Article « '.$get('product').' » introuvable.');
        }
        if ($p->type !== 'product') {
            throw new \DomainException('« '.$p->name.' » est un service : pas de stock.');
        }
        $qty = self::number($get('quantity'));
        if ($qty === null) {
            throw new \DomainException('Quantité manquante.');
        }
        $whName = self::norm((string) $get('warehouse'));
        $wh = $whName !== '' ? ($ctx['wh'][$whName] ?? throw new \DomainException('Dépôt « '.$get('warehouse').' » inconnu.')) : Warehouse::defaultFor(app('tenant')->id);

        return $this->setStock($p, $wh, $qty, self::number($get('unit_cost')) ?? $p->purchase_price) ? 'updated' : 'skipped';
    }

    /**
     * Ajuste le stock pour atteindre $qty. $total : la quantité est le stock total de l'article (colonne « Stock actuel »
     * de l'import d'articles, comme dans l'export), l'écart est passé sur le dépôt indiqué ; sinon c'est le stock de ce dépôt.
     */
    protected function setStock(Product $p, Warehouse $wh, float $qty, ?float $cost, bool $total = false): bool
    {
        $current = (float) StockMove::where('product_id', $p->id)->when(! $total, fn ($q) => $q->where('warehouse_id', $wh->id))->sum('quantity');
        $diff = round($qty - $current, 3);
        if (abs($diff) < 0.0005) {
            return false;
        }
        $first = ! StockMove::where('product_id', $p->id)->exists();
        $this->write(StockMove::class, null, ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'quantity' => $diff, 'type' => $first ? 'initial' : 'adjustment',
            'unit_cost' => $cost, 'reference' => 'IMPORT', 'date' => now()->toDateString(), 'notes' => 'Reprise / inventaire importé']);
        if (! $p->track_stock) {
            $p->forceFill(['track_stock' => true])->save();
        }

        return true;
    }

    protected function invoice(\Closure $get, string $mode, array &$ctx): string
    {
        $ref = trim((string) $get('reference'));
        if ($ref === '') {
            throw new \DomainException('N° de facture manquant.');
        }
        if (isset($ctx['refs'][self::norm($ref)])) {
            return 'skipped'; // déjà reprise
        }
        $name = trim((string) $get('partner'));
        if ($name === '') {
            throw new \DomainException('Client manquant.');
        }
        $amount = self::number($get('amount'));
        if (! $amount || $amount <= 0) {
            throw new \DomainException('Montant TTC manquant ou nul.');
        }
        $paid = self::number($get('paid')) ?? 0;
        if ($paid > $amount + 0.009) {
            throw new \DomainException('Le montant déjà payé dépasse le montant de la facture.');
        }
        if ($amount - $paid < 0.01) {
            return 'skipped'; // soldée : rien à reprendre
        }
        $n = self::norm($name);
        $partner = $ctx['partners'][$n] ??= $this->write(Partner::class, null, ['name' => $name, 'type' => 'customer']);
        $date = self::date($get('date')) ?? throw new \DomainException('Date manquante.');
        $doc = $this->write(SalesDocument::class, null, [
            'type' => 'invoice', 'partner_id' => $partner->id, 'date' => $date, 'due_date' => self::date($get('due_date')), 'reference' => $ref,
            'notes' => 'Reprise de solde de la facture '.$ref.' de l\'ancien logiciel'.($paid > 0 ? ' : montant '.number_format($amount, 0, ',', ' ').', déjà réglé '.number_format($paid, 0, ',', ' ').'.' : '.'),
            // Seul le reste dû est repris : les règlements passés appartiennent à l'ancienne comptabilité
            'lines' => [['description' => 'Reste dû sur la facture '.$ref, 'quantity' => 1, 'unit_price' => round($amount - $paid, 2), 'tax_rate' => 0]],
        ]);
        $doc = SalesDocument::find($doc->id);
        $doc->forceFill(['is_opening' => true])->saveQuietly();
        $doc->validateDocument();
        $ctx['refs'][self::norm($ref)] = true;

        return 'created';
    }

    /** Création / mise à jour selon le mode : create (nouveaux seulement), update (existants seulement), upsert. */
    protected function apply(string $class, $existing, array $data, string $mode, \Closure $remember): string
    {
        if ($existing && $mode === 'create') {
            return 'skipped';
        }
        if (! $existing && $mode === 'update') {
            return 'skipped';
        }
        $m = $this->write($class, $existing?->id, $data);
        $remember($m);

        return $existing ? 'updated' : 'created';
    }
}
