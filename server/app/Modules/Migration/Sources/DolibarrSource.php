<?php

namespace App\Modules\Migration\Sources;

use Illuminate\Support\Facades\Http;

/** Dolibarr (API REST, clé DOLAPIKEY d'un utilisateur, module « API / Web services » activé). */
class DolibarrSource extends Source
{
    public const METHODS = ['LIQ' => 'cash', 'VIR' => 'bank_transfer', 'CHQ' => 'cheque', 'CB' => 'card', 'PRE' => 'bank_transfer', 'TIP' => 'bank_transfer', 'VAD' => 'card', 'TRA' => 'bank_transfer'];

    protected string $base;

    public function __construct(string $url, protected string $apiKey)
    {
        $url = rtrim(trim($url), '/');
        $url = preg_replace('#/api/index\.php.*$#', '', $url);
        $this->base = $url.'/api/index.php';
    }

    public function key(): string
    {
        return 'dolibarr';
    }

    protected function get(string $path, array $query = [], bool $allow404 = true): ?array
    {
        try {
            $r = Http::withHeaders(['DOLAPIKEY' => $this->apiKey, 'Accept' => 'application/json'])->withOptions(\App\Support\UrlGuard::pinned($this->base))->timeout(40)->retry(2, 500, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException, throw: false)->get($this->base.'/'.$path, $query);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $r = $e->response;
        } catch (\Illuminate\Http\Client\ConnectionException) {
            throw new \DomainException('Impossible de joindre ce Dolibarr : vérifiez l\'adresse.');
        }
        if ($r->status() === 404 && $allow404) {
            return null; // Dolibarr répond 404 quand la liste est vide
        }
        if ($r->status() === 401 || $r->status() === 403) {
            throw new \DomainException('Dolibarr refuse la clé d\'API (vérifiez la clé et les droits de l\'utilisateur).');
        }
        if (! $r->successful()) {
            throw new \DomainException('Dolibarr ne répond pas correctement ('.$r->status().') sur '.$path.'. Le module « API / Web services (serveur REST) » est-il activé ?');
        }
        $j = $r->json();

        return is_array($j) ? $j : [];
    }

    protected function pages(string $path, array $query = []): \Generator
    {
        for ($page = 0; $page < 2000; $page++) {
            $rows = $this->get($path, $query + ['limit' => 100, 'page' => $page, 'sortfield' => 't.rowid', 'sortorder' => 'ASC']);
            if (! $rows) {
                return;
            }
            yield from $rows;
            if (count($rows) < 100) {
                return;
            }
        }
    }

    public function probe(): array
    {
        try {
            $status = $this->get('status', [], false);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            throw new \DomainException('Impossible de joindre ce Dolibarr : vérifiez l\'adresse.');
        }
        $count = fn (string $p) => count($this->get($p, ['limit' => 100]) ?? []);
        $info = $this->get('setup/company') ?? [];

        return [
            'name' => $info['name'] ?? 'Dolibarr',
            'version' => $status['success']['dolibarr_version'] ?? null,
            'counts' => ['partners' => $count('thirdparties'), 'products' => $count('products'), 'sales' => $count('invoices'), 'purchases' => $count('supplierinvoices')],
            'capped' => 100,
        ];
    }

    public function partners(): iterable
    {
        foreach ($this->pages('thirdparties') as $t) {
            $client = in_array((string) ($t['client'] ?? '0'), ['1', '2', '3'], true);
            $supplier = (string) ($t['fournisseur'] ?? '0') === '1';
            yield [
                'id' => (string) $t['id'], 'name' => self::clean($t['name'] ?? null) ?? ('Tiers '.$t['id']),
                'type' => $client && $supplier ? 'both' : ($supplier ? 'supplier' : 'customer'),
                'code' => $t['code_client'] ?? $t['code_fournisseur'] ?? null, 'email' => $t['email'] ?? null, 'phone' => $t['phone'] ?? ($t['phone_mobile'] ?? null),
                'address' => self::clean(trim(($t['address'] ?? '').' '.($t['zip'] ?? ''))), 'city' => $t['town'] ?? null,
                'tax_id' => $t['idprof1'] ?? ($t['tva_intra'] ?? null) ?: null, 'notes' => self::clean($t['note_public'] ?? null),
            ];
        }
    }

    public function products(): iterable
    {
        foreach ($this->pages('products') as $p) {
            yield [
                'id' => (string) $p['id'], 'name' => self::clean($p['label'] ?? null) ?? ($p['ref'] ?? 'Article'), 'sku' => $p['ref'] ?? null, 'barcode' => $p['barcode'] ?? null,
                'category' => null, 'type' => (string) ($p['type'] ?? '0') === '1' ? 'service' : 'product', 'unit' => null,
                'sale_price' => self::num($p['price'] ?? 0), 'purchase_price' => self::num(($p['cost_price'] ?? null) ?: ($p['pmp'] ?? 0)),
                'tax_rate' => self::num($p['tva_tx'] ?? 0), 'stock' => isset($p['stock_reel']) ? self::num($p['stock_reel']) : null,
                'active' => (string) ($p['status'] ?? '1') === '1', 'description' => self::clean($p['description'] ?? null),
            ];
        }
    }

    public function documents(string $direction): iterable
    {
        $path = $direction === 'sale' ? 'invoices' : 'supplierinvoices';
        foreach ($this->pages($path) as $f) {
            $type = (string) ($f['type'] ?? '0');
            $status = (string) ($f['status'] ?? $f['statut'] ?? $f['fk_statut'] ?? '1');
            $lines = [];
            foreach ($f['lines'] ?? [] as $l) {
                $lines[] = [
                    'description' => self::clean(($l['product_label'] ?? null) ? $l['product_label'].(($l['desc'] ?? '') && $l['desc'] !== $l['product_label'] ? ' — '.$l['desc'] : '') : ($l['desc'] ?? $l['description'] ?? $l['label'] ?? null)) ?? 'Ligne',
                    'product_id' => ($l['fk_product'] ?? null) ? (string) $l['fk_product'] : null,
                    'quantity' => abs(self::num($l['qty'] ?? 1)) ?: 1, 'unit_price' => abs(self::num($l['subprice'] ?? $l['pu_ht'] ?? 0)),
                    'tax_rate' => self::num($l['tva_tx'] ?? 0), 'discount' => self::num($l['remise_percent'] ?? 0),
                ];
            }
            $payments = [];
            if ($status !== '0') {
                foreach ($this->get($path.'/'.$f['id'].'/payments') ?? [] as $p) {
                    $payments[] = ['date' => self::date($p['date'] ?? null) ?? self::date($f['date'] ?? null), 'amount' => abs(self::num($p['amount'] ?? 0)),
                        'method' => self::METHODS[$p['type'] ?? ''] ?? 'other', 'reference' => $p['num'] ?? ($p['ref'] ?? null)];
                }
            }
            yield [
                'id' => (string) $f['id'], 'number' => $f['ref'] ?? null, 'direction' => $direction,
                'type' => $type === '2' ? 'credit_note' : 'invoice',
                'partner_id' => isset($f['socid']) ? (string) $f['socid'] : null, 'partner_name' => null,
                'date' => self::date($f['date'] ?? $f['datef'] ?? null), 'due_date' => self::date($f['date_lim_reglement'] ?? $f['date_echeance'] ?? null),
                'status' => match ($status) { '0' => 'draft', '3' => 'cancelled', default => 'validated' },
                'currency' => $f['multicurrency_code'] ?? null, 'reference' => $f['ref_client'] ?? $f['ref_supplier'] ?? null,
                'total' => abs(self::num($f['total_ttc'] ?? 0)), 'paid' => array_sum(array_column($payments, 'amount')), 'payments' => $payments, 'lines' => $lines,
            ];
        }
    }
}
