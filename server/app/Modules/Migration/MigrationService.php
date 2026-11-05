<?php

namespace App\Modules\Migration;

use App\Models\Company;
use App\Modules\Achats\Models\PurchaseDocument;
use App\Modules\Achats\Models\PurchasePayment;
use App\Modules\Core\Models\Partner;
use App\Modules\Core\Models\Product;
use App\Modules\Core\Models\ProductCategory;
use App\Modules\ImportExport\ImportService;
use App\Modules\Migration\Models\MigrationJob;
use App\Modules\Migration\Sources\DolibarrSource;
use App\Modules\Migration\Sources\ErpNextSource;
use App\Modules\Migration\Sources\FileSource;
use App\Modules\Migration\Sources\OdooSource;
use App\Modules\Migration\Sources\Source;
use App\Modules\Stock\Models\StockMove;
use App\Modules\Stock\Models\Warehouse;
use App\Modules\Ventes\Models\SalesDocument;
use App\Modules\Ventes\Models\SalesPayment;
use App\Services\EntityWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Migration depuis un autre logiciel. Tiers et articles passent par EntityWriter (mêmes contrôles que la saisie) ;
 * les factures historiques sont reprises validées avec leur numéro d'origine et leurs règlements, sans effet de bord
 * (pas de mouvement de stock, d'écriture comptable, de certification FNE ni de points de fidélité) : marquées migrated_from.
 * Chaque enregistrement repris est mémorisé (mig_links) : relancer la migration met à jour sans créer de doublon.
 */
class MigrationService
{
    public const SOURCES = [
        'dolibarr' => ['Dolibarr', 'API REST', ['url' => 'Adresse de Dolibarr', 'api_key' => 'Clé d\'API (DOLAPIKEY)']],
        'odoo' => ['Odoo', 'JSON-RPC', ['url' => 'Adresse d\'Odoo', 'database' => 'Base de données', 'login' => 'Identifiant (e-mail)', 'api_key' => 'Clé d\'API ou mot de passe']],
        'erpnext' => ['ERPNext / Frappe', 'API REST', ['url' => 'Adresse d\'ERPNext', 'api_key' => 'Clé d\'API', 'api_secret' => 'Secret d\'API']],
    ];

    public function __construct(protected EntityWriter $writer) {}

    public function source(MigrationJob $job): Source
    {
        $s = $job->secret ?? [];

        return match ($job->source) {
            'dolibarr' => new DolibarrSource($s['url'] ?? '', $s['api_key'] ?? ''),
            'odoo' => new OdooSource($s['url'] ?? '', $s['database'] ?? '', $s['login'] ?? '', $s['api_key'] ?? ''),
            'erpnext' => new ErpNextSource($s['url'] ?? '', $s['api_key'] ?? '', $s['api_secret'] ?? ''),
            'file' => new FileSource(collect($job->files ?? [])->map(fn ($p) => Storage::disk('local')->path($p))->all(), $job->vendor ?? 'excel'),
            default => throw new \DomainException('Source inconnue.'),
        };
    }

    // ------------------------------------------------------------------ Correspondances

    protected array $links = [];

    protected string $tag;

    protected function link(string $entity, ?string $ext): ?string
    {
        if ($ext === null || $ext === '') {
            return null;
        }
        $this->links[$entity] ??= DB::table('mig_links')->where('company_id', app('tenant')->id)->where('source', $this->tag)->where('entity', $entity)->pluck('local_id', 'external_id')->all();

        return $this->links[$entity][$ext] ?? null;
    }

    protected function remember(string $entity, string $ext, string $local): void
    {
        DB::table('mig_links')->updateOrInsert(['company_id' => app('tenant')->id, 'source' => $this->tag, 'entity' => $entity, 'external_id' => $ext],
            ['local_id' => $local, 'updated_at' => now(), 'created_at' => now()]);
        $this->links[$entity][$ext] = $local;
    }

    protected function write(string $class, ?string $id, array $data)
    {
        $r = $this->writer->upsert($class, $id ?? (string) Str::uuid(), $data);
        if ($r['status'] !== EntityWriter::APPLIED) {
            throw new \DomainException((string) ($r['errors'] ? collect($r['errors'])->flatten()->first() : $r['message']));
        }

        return $r['record'];
    }

    // ------------------------------------------------------------------ Exécution

    protected MigrationJob $job;

    protected array $counts = [];

    protected array $warnings = [];

    protected float $rate = 1.0;

    protected function progress(string $stage, int $done): void
    {
        if ($done % 20 === 0) {
            $this->job->forceFill(['progress' => ['stage' => $stage, 'done' => $done], 'counts' => $this->counts])->save();
        }
    }

    protected function count(string $entity, string $what): void
    {
        $this->counts[$entity][$what] = ($this->counts[$entity][$what] ?? 0) + 1;
    }

    protected function warn(string $m): void
    {
        if (count($this->warnings) < 300) {
            $this->warnings[] = $m;
        }
    }

    public function run(MigrationJob $job): void
    {
        @set_time_limit(0);
        $this->job = $job;
        $company = app('tenant');
        $o = $job->options ?? [];
        $this->rate = max(0.000001, (float) ($o['rate'] ?? 1) ?: 1);
        $this->tag = $job->source === 'file' ? 'file:'.($job->vendor ?? 'excel') : $job->source.':'.md5(strtolower(rtrim((string) ($job->secret['url'] ?? ''), '/')));
        $src = $this->source($job);
        $what = $o['what'] ?? ['partners', 'products', 'stock', 'sales', 'purchases'];
        $since = $o['since'] ?? null;
        $job->forceFill(['status' => 'running', 'started_at' => now(), 'error' => null])->save();
        try {
            if (in_array('partners', $what, true)) {
                $i = 0;
                foreach ($src->partners() as $p) {
                    $this->guard('partners', fn () => $this->partner($p));
                    $this->progress('partners', ++$i);
                }
            }
            if (in_array('products', $what, true)) {
                $cats = ProductCategory::all()->keyBy(fn ($c) => ImportService::norm($c->name))->all();
                $wh = $company->hasModule('stock') && in_array('stock', $what, true) ? Warehouse::defaultFor($company->id) : null;
                $i = 0;
                foreach ($src->products() as $p) {
                    $this->guard('products', fn () => $this->product($p, $cats, $wh));
                    $this->progress('products', ++$i);
                }
            }
            foreach (['sales' => 'sale', 'purchases' => 'purchase'] as $key => $dir) {
                if (! in_array($key, $what, true) || ($dir === 'purchase' && ! $company->hasModule('achats'))) {
                    continue;
                }
                $i = 0;
                foreach ($src->documents($dir) as $d) {
                    if ($since && $d['date'] && $d['date'] < $since) {
                        $this->count($key, 'skipped');

                        continue;
                    }
                    $this->guard($key, fn () => $this->document($d, $key));
                    $this->progress($key, ++$i);
                }
            }
            foreach ($src->warnings as $w) {
                $this->warn($w);
            }
            $job->forceFill(['status' => 'done', 'finished_at' => now(), 'counts' => $this->counts, 'warnings' => $this->warnings, 'progress' => ['stage' => 'done', 'done' => 0],
                'secret' => $job->source === 'file' ? null : $job->secret])->save();
        } catch (\Throwable $e) {
            $job->forceFill(['status' => 'failed', 'finished_at' => now(), 'counts' => $this->counts, 'warnings' => $this->warnings, 'error' => mb_substr($e->getMessage(), 0, 480)])->save();
            if (! $e instanceof \DomainException && ! $e instanceof \Illuminate\Http\Client\RequestException) {
                report($e);
            }
        }
        if ($job->source === 'file') {
            foreach ($job->files ?? [] as $p) {
                Storage::disk('local')->delete($p);
            }
        }
    }

    protected function guard(string $entity, \Closure $fn): void
    {
        try {
            $this->count($entity, DB::transaction($fn));
        } catch (\DomainException|\Illuminate\Validation\ValidationException $e) {
            $this->count($entity, 'failed');
            $this->warn(\App\Modules\Migration\Sources\FileSource::LABELS[$entity].' : '.$e->getMessage());
        }
    }

    protected function partner(array $p): string
    {
        $local = $this->link('partner', $p['id']);
        $existing = $local ? Partner::find($local) : null;
        if (! $existing) {
            // Rapprochement avec un tiers déjà présent (n° contribuable, e-mail, puis nom)
            $existing = (filled($p['tax_id'] ?? null) ? Partner::where('tax_id', $p['tax_id'])->first() : null)
                ?? (filled($p['email'] ?? null) ? Partner::where('email', strtolower($p['email']))->first() : null)
                ?? Partner::where('name', $p['name'])->first();
        }
        $data = array_filter([
            'name' => mb_substr($p['name'], 0, 190), 'type' => $existing && $existing->type !== $p['type'] ? 'both' : $p['type'], 'code' => $p['code'] ?? null,
            'email' => filled($p['email'] ?? null) && filter_var(trim($p['email']), FILTER_VALIDATE_EMAIL) ? strtolower(trim($p['email'])) : null,
            'phone' => $p['phone'] ?? null, 'address' => $p['address'] ?? null, 'city' => $p['city'] ?? null, 'tax_id' => $p['tax_id'] ?? null, 'notes' => $p['notes'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
        $m = $this->write(Partner::class, $existing?->id, $existing ? array_intersect_key($data, array_flip(['type', 'email', 'phone', 'address', 'city', 'tax_id', 'code'])) + ['name' => $existing->name] : $data);
        $this->remember('partner', $p['id'], $m->id);

        return $existing ? 'updated' : 'created';
    }

    protected function product(array $p, array &$cats, ?Warehouse $wh): string
    {
        $local = $this->link('product', $p['id']);
        $existing = $local ? Product::find($local) : null;
        if (! $existing) {
            $existing = (filled($p['sku'] ?? null) ? Product::where('sku', $p['sku'])->first() : null)
                ?? (filled($p['barcode'] ?? null) ? Product::where('barcode', $p['barcode'])->first() : null);
        }
        $data = [
            'name' => mb_substr($p['name'], 0, 190), 'sku' => $p['sku'] ?? null, 'barcode' => $p['barcode'] ?? null, 'type' => $p['type'] ?? 'product',
            'unit' => filled($p['unit'] ?? null) ? mb_strtolower(mb_substr($p['unit'], 0, 30)) : null,
            'sale_price' => round(((float) ($p['sale_price'] ?? 0)) * $this->rate, 4), 'purchase_price' => round(((float) ($p['purchase_price'] ?? 0)) * $this->rate, 4),
            'tax_rate' => $p['tax_rate'] ?? null, 'description' => $p['description'] ?? null, 'active' => $p['active'] ?? true,
        ];
        if ($cat = trim((string) ($p['category'] ?? ''))) {
            $n = ImportService::norm($cat);
            $cats[$n] ??= ProductCategory::create(['name' => mb_substr($cat, 0, 120)]);
            $data['category_id'] = $cats[$n]->id;
        }
        $data = array_filter($data, fn ($v) => $v !== null && $v !== '');
        if (! $existing) {
            $data += ['tax_rate' => (float) (app('tenant')->settings['tax_rate'] ?? 18), 'unit' => 'unité', 'track_stock' => ($data['type'] ?? 'product') === 'product'];
        }
        $m = $this->write(Product::class, $existing?->id, $data);
        $this->remember('product', $p['id'], $m->id);
        if (filled($p['sku'] ?? null)) {
            $this->remember('product_sku', mb_strtolower($p['sku']), $m->id);
        }
        // Stock : un mouvement d'ouverture pour atteindre la quantité de l'ancien logiciel
        if ($wh && $m->type === 'product' && ($p['stock'] ?? null) !== null) {
            $current = (float) StockMove::where('product_id', $m->id)->where('warehouse_id', $wh->id)->sum('quantity');
            $diff = round((float) $p['stock'] - $current, 3);
            if (abs($diff) >= 0.0005) {
                $this->write(StockMove::class, null, ['product_id' => $m->id, 'warehouse_id' => $wh->id, 'quantity' => $diff, 'type' => $current == 0 ? 'initial' : 'adjustment',
                    'unit_cost' => (float) $m->purchase_price, 'reference' => 'MIGRATION', 'date' => now()->toDateString(), 'notes' => 'Stock repris de l\'ancien logiciel']);
                $this->count('stock', 'updated');
            }
        }

        return $existing ? 'updated' : 'created';
    }

    protected function document(array $d, string $key): string
    {
        $sale = $d['direction'] === 'sale';
        $class = $sale ? SalesDocument::class : PurchaseDocument::class;
        if ($d['status'] === 'cancelled') {
            return 'skipped';
        }
        if (! $sale && $d['type'] === 'credit_note') {
            $this->warn('Avoir fournisseur '.($d['number'] ?? $d['id']).' non repris (à saisir en déduction).');

            return 'skipped';
        }
        if ($local = $this->link($key, $d['id'])) {
            // Déjà repris : on met seulement à jour les règlements (une facture payée depuis la dernière migration)
            $doc = $class::find($local);
            if ($doc && $doc->status === 'validated') {
                return $this->payments($doc, $d) ? 'updated' : 'skipped';
            }

            return 'skipped';
        }
        // Tiers
        $partner = $this->link('partner', $d['partner_id']);
        if (! $partner && filled($d['partner_name'])) {
            $partner = Partner::where('name', $d['partner_name'])->value('id')
                ?? $this->write(Partner::class, null, ['name' => mb_substr($d['partner_name'], 0, 190), 'type' => $sale ? 'customer' : 'supplier'])->id;
            if ($d['partner_id']) {
                $this->remember('partner', $d['partner_id'], $partner);
            }
        }
        if (! $partner && ! $sale) {
            throw new \DomainException('Facture fournisseur '.($d['number'] ?? $d['id']).' sans fournisseur.');
        }
        if (! $d['date']) {
            throw new \DomainException('Facture '.($d['number'] ?? $d['id']).' sans date.');
        }
        $lines = [];
        foreach ($d['lines'] as $l) {
            $pid = $this->link('product', $l['product_id'] ?? null) ?? (filled($l['sku'] ?? null) ? $this->link('product_sku', mb_strtolower($l['sku'])) : null);
            $lines[] = ['id' => (string) Str::uuid(), 'product_id' => $pid, 'description' => mb_substr((string) $l['description'], 0, 1000) ?: 'Ligne',
                'quantity' => round((float) $l['quantity'], 3) ?: 1, 'unit_price' => round((float) $l['unit_price'] * $this->rate, 4),
                'tax_rate' => max(0, min(100, (float) ($l['tax_rate'] ?? 0))), 'discount_percent' => max(0, min(100, (float) ($l['discount'] ?? 0)))];
        }
        if (! $lines) {
            if (! $d['total']) {
                return 'skipped';
            }
            $lines[] = ['id' => (string) Str::uuid(), 'description' => 'Facture '.$d['number'], 'quantity' => 1, 'unit_price' => round($d['total'] * $this->rate, 2), 'tax_rate' => 0, 'discount_percent' => 0];
        }
        $type = $sale ? ($d['type'] === 'credit_note' ? 'credit_note' : 'invoice') : 'supplier_invoice';
        $rec = $this->write($class, null, array_filter(['type' => $type, 'partner_id' => $partner, 'date' => $d['date'], 'due_date' => $d['due_date'] ?? null,
            'reference' => $d['reference'] ? mb_substr($d['reference'], 0, 100) : null, 'lines' => $lines,
            'notes' => 'Repris de '.$this->label().($d['number'] ? ' (n° '.$d['number'].')' : '').'.'], fn ($v) => $v !== null));
        $doc = $class::find($rec->id);
        if ($d['status'] === 'validated') {
            // Validé avec le numéro d'origine, sans déclencher stock, comptabilité, FNE ni fidélité
            $doc->forceFill(['status' => 'validated', 'number' => $d['number'] ?: 'MIG-'.Str::upper(Str::random(6)), 'validated_at' => now(), 'migrated_from' => $this->label(),
                'version' => (int) $doc->version + 1])->saveQuietly();
            $doc->logSyncChange('upsert');
            if ($d['total'] && abs((float) $doc->total - $d['total'] * $this->rate) > max(1, $d['total'] * $this->rate * 0.005)) {
                $this->warn('Facture '.$d['number'].' : total recalculé '.number_format((float) $doc->total, 2, ',', ' ').' au lieu de '.number_format($d['total'] * $this->rate, 2, ',', ' ').' (arrondis ou taxes différentes).');
            }
            if ($d['type'] !== 'credit_note') {
                $this->payments($doc, $d);
            }
        }
        $this->remember($key, $d['id'], $doc->id);

        return 'created';
    }

    /** Ajoute les règlements manquants (sans écriture comptable ni mouvement de trésorerie automatique). */
    protected function payments($doc, array $d): bool
    {
        $already = (float) DB::table('payments')->where('document_id', $doc->id)->whereNull('deleted_at')->sum('amount');
        $srcPaid = array_sum(array_column($d['payments'], 'amount'));
        $dec = \App\Modules\Core\Models\TradeDocumentLine::decimalsFor($doc->currency ?: app('tenant')->currency);
        $target = min((float) $doc->total, round($srcPaid * $this->rate, $dec));
        // Soldée dans l'ancien logiciel : soldée ici aussi, malgré les arrondis de conversion ou de devise sans décimales
        if ($d['total'] && $srcPaid >= $d['total'] - 0.01) {
            $target = (float) $doc->total;
        }
        if ($target - $already < 0.01) {
            return false;
        }
        $cls = $doc instanceof SalesDocument ? SalesPayment::class : PurchasePayment::class;
        $left = $target - $already;
        $list = $already > 0 ? [['date' => now()->toDateString(), 'amount' => $left / $this->rate, 'method' => 'other', 'reference' => 'Complément de règlements']] : $d['payments'];
        $list = array_values($list);
        foreach ($list as $k => $p) {
            // Le dernier règlement absorbe les écarts d'arrondi de la conversion
            $amount = min($left, round((float) $p['amount'] * $this->rate, $dec));
            if ($k === count($list) - 1 && $target >= (float) $doc->total) {
                $amount = round($left, 2);
            }
            if ($amount < 0.01) {
                continue;
            }
            $pay = new $cls;
            $pay->forceFill(['id' => (string) Str::uuid(), 'company_id' => $doc->company_id, 'direction' => $cls::DIRECTION, 'document_id' => $doc->id, 'partner_id' => $doc->partner_id,
                'date' => $p['date'] ?: $doc->date->toDateString(), 'amount' => $amount, 'method' => in_array($p['method'] ?? '', \App\Modules\Core\Models\Payment::METHODS, true) ? $p['method'] : 'other',
                'reference' => mb_substr((string) ($p['reference'] ?? 'Migration'), 0, 100), 'notes' => 'Règlement repris de '.$this->label(), 'version' => 1])->saveQuietly();
            $pay->logSyncChange('upsert');
            $left -= $amount;
        }
        $doc->refreshPaymentStatus(false);
        $doc->forceFill(['version' => (int) $doc->version + 1])->saveQuietly();
        $doc->logSyncChange('upsert');

        return true;
    }

    protected function label(): string
    {
        return $this->job->source === 'file' ? (FileSource::VENDORS[$this->job->vendor ?? 'excel'][0] ?? 'fichiers') : self::SOURCES[$this->job->source][0];
    }
}
