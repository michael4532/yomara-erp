<?php

namespace App\Modules\Migration\Sources;

use Illuminate\Support\Facades\Http;

/** Odoo (JSON-RPC, base de données + identifiant + clé d'API ou mot de passe). */
class OdooSource extends Source
{
    protected ?int $uid = null;

    protected array $taxes = [];

    public function __construct(protected string $url, protected string $db, protected string $login, protected string $apiKey)
    {
        $this->url = rtrim(trim($url), '/');
    }

    public function key(): string
    {
        return 'odoo';
    }

    protected function rpc(string $service, string $method, array $args): mixed
    {
        try {
            $r = Http::withOptions(\App\Support\UrlGuard::pinned($this->url))->timeout(60)->retry(2, 500, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException, throw: false)->post($this->url.'/jsonrpc', ['jsonrpc' => '2.0', 'method' => 'call', 'id' => random_int(1, 999999),
                'params' => ['service' => $service, 'method' => $method, 'args' => $args]]);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $r = $e->response;
        } catch (\Illuminate\Http\Client\ConnectionException) {
            throw new \DomainException('Impossible de joindre ce serveur Odoo : vérifiez l\'adresse.');
        }
        if (! $r->successful()) {
            throw new \DomainException('Odoo répond '.$r->status().'.');
        }
        if ($e = $r->json('error')) {
            throw new \DomainException('Odoo : '.($e['data']['message'] ?? $e['message'] ?? 'erreur'));
        }

        return $r->json('result');
    }

    protected function uid(): int
    {
        if ($this->uid === null) {
            $uid = $this->rpc('common', 'login', [$this->db, $this->login, $this->apiKey]);
            if (! $uid) {
                throw new \DomainException('Odoo refuse l\'identifiant ou la clé d\'API (base « '.$this->db.' »).');
            }
            $this->uid = (int) $uid;
        }

        return $this->uid;
    }

    protected function call(string $model, string $method, array $args, array $kw = []): mixed
    {
        return $this->rpc('object', 'execute_kw', [$this->db, $this->uid(), $this->apiKey, $model, $method, $args, (object) $kw]);
    }

    protected function search(string $model, array $domain, array $fields): \Generator
    {
        for ($offset = 0; $offset < 500000; $offset += 200) {
            $rows = $this->call($model, 'search_read', [$domain], ['fields' => $fields, 'limit' => 200, 'offset' => $offset, 'order' => 'id asc']);
            if (! $rows) {
                return;
            }
            yield from $rows;
            if (count($rows) < 200) {
                return;
            }
        }
    }

    protected static function m2o(mixed $v): array
    {
        return is_array($v) ? [(string) $v[0], $v[1] ?? null] : [null, null];
    }

    protected function taxRate(array $ids): float
    {
        $t = 0.0;
        foreach ($ids as $id) {
            if (! array_key_exists($id, $this->taxes)) {
                $r = $this->call('account.tax', 'read', [[$id]], ['fields' => ['amount', 'amount_type']]);
                $this->taxes[$id] = ($r[0]['amount_type'] ?? 'percent') === 'percent' ? (float) ($r[0]['amount'] ?? 0) : 0.0;
            }
            $t += $this->taxes[$id];
        }

        return $t;
    }

    public function probe(): array
    {
        $this->uid();
        $c = fn (string $m, array $d) => (int) $this->call($m, 'search_count', [$d]);
        $company = $this->call('res.company', 'search_read', [[]], ['fields' => ['name'], 'limit' => 1]);
        $v = rescue(fn () => $this->rpc('common', 'version', []), [], false);

        return [
            'name' => $company[0]['name'] ?? 'Odoo', 'version' => $v['server_version'] ?? null,
            'counts' => ['partners' => $c('res.partner', [['parent_id', '=', false]]), 'products' => $c('product.product', []),
                'sales' => $c('account.move', [['move_type', 'in', ['out_invoice', 'out_refund']]]), 'purchases' => $c('account.move', [['move_type', 'in', ['in_invoice']]])],
        ];
    }

    public function partners(): iterable
    {
        foreach ($this->search('res.partner', [['parent_id', '=', false]], ['name', 'ref', 'email', 'phone', 'mobile', 'street', 'city', 'vat', 'customer_rank', 'supplier_rank', 'comment']) as $p) {
            $cus = ($p['customer_rank'] ?? 0) > 0;
            $sup = ($p['supplier_rank'] ?? 0) > 0;
            yield ['id' => (string) $p['id'], 'name' => $p['name'] ?: 'Contact '.$p['id'], 'type' => $cus && $sup ? 'both' : ($sup ? 'supplier' : 'customer'),
                'code' => $p['ref'] ?: null, 'email' => $p['email'] ?: null, 'phone' => ($p['phone'] ?: $p['mobile']) ?: null, 'address' => $p['street'] ?: null,
                'city' => $p['city'] ?: null, 'tax_id' => $p['vat'] ?: null, 'notes' => self::clean(is_string($p['comment'] ?? null) ? $p['comment'] : null)];
        }
    }

    public function products(): iterable
    {
        $fields = ['name', 'default_code', 'barcode', 'categ_id', 'type', 'list_price', 'standard_price', 'taxes_id', 'uom_id', 'qty_available', 'active'];
        foreach ($this->search('product.product', ['|', ['active', '=', true], ['active', '=', false]], $fields) as $p) {
            yield ['id' => (string) $p['id'], 'name' => $p['name'], 'sku' => $p['default_code'] ?: null, 'barcode' => $p['barcode'] ?: null,
                'category' => self::m2o($p['categ_id'])[1], 'type' => ($p['type'] ?? '') === 'service' ? 'service' : 'product', 'unit' => self::m2o($p['uom_id'] ?? null)[1],
                'sale_price' => (float) $p['list_price'], 'purchase_price' => (float) $p['standard_price'], 'tax_rate' => $this->taxRate($p['taxes_id'] ?? []),
                'stock' => isset($p['qty_available']) ? (float) $p['qty_available'] : null, 'active' => (bool) $p['active']];
        }
    }

    public function documents(string $direction): iterable
    {
        $types = $direction === 'sale' ? ['out_invoice', 'out_refund'] : ['in_invoice'];
        $fields = ['name', 'move_type', 'partner_id', 'invoice_date', 'invoice_date_due', 'amount_total', 'amount_residual', 'state', 'currency_id', 'ref'];
        $batch = [];
        foreach ($this->search('account.move', [['move_type', 'in', $types]], $fields) as $m) {
            $batch[] = $m;
            if (count($batch) === 100) {
                yield from $this->withLines($batch, $direction);
                $batch = [];
            }
        }
        if ($batch) {
            yield from $this->withLines($batch, $direction);
        }
    }

    protected function withLines(array $moves, string $direction): \Generator
    {
        $ids = array_column($moves, 'id');
        $fields = ['move_id', 'name', 'product_id', 'quantity', 'price_unit', 'discount', 'tax_ids'];
        try {
            $lines = $this->call('account.move.line', 'search_read', [[['move_id', 'in', $ids], ['display_type', '=', 'product']]], ['fields' => $fields]);
        } catch (\DomainException) {
            // Odoo 15 et antérieurs : lignes de facture = exclude_from_invoice_tab faux
            $lines = $this->call('account.move.line', 'search_read', [[['move_id', 'in', $ids], ['exclude_from_invoice_tab', '=', false], ['display_type', '=', false]]], ['fields' => $fields]);
        }
        $by = [];
        foreach ($lines as $l) {
            $by[self::m2o($l['move_id'])[0]][] = $l;
        }
        foreach ($moves as $m) {
            $total = (float) $m['amount_total'];
            $paid = max(0, $total - (float) $m['amount_residual']);
            yield [
                'id' => (string) $m['id'], 'number' => $m['name'] && $m['name'] !== '/' ? $m['name'] : null, 'direction' => $direction,
                'type' => $m['move_type'] === 'out_refund' ? 'credit_note' : 'invoice',
                'partner_id' => self::m2o($m['partner_id'])[0], 'partner_name' => self::m2o($m['partner_id'])[1],
                'date' => self::date($m['invoice_date']), 'due_date' => self::date($m['invoice_date_due']),
                'status' => match ($m['state']) { 'draft' => 'draft', 'cancel' => 'cancelled', default => 'validated' },
                'currency' => self::m2o($m['currency_id'])[1], 'reference' => $m['ref'] ?: null, 'total' => $total, 'paid' => $paid,
                'payments' => $paid > 0 ? [['date' => self::date($m['invoice_date']), 'amount' => $paid, 'method' => 'other', 'reference' => 'Règlements Odoo']] : [],
                'lines' => array_map(fn ($l) => ['description' => self::clean($l['name'] ?? null) ?? 'Ligne', 'product_id' => self::m2o($l['product_id'])[0],
                    'quantity' => abs((float) $l['quantity']) ?: 1, 'unit_price' => abs((float) $l['price_unit']), 'tax_rate' => $this->taxRate($l['tax_ids'] ?? []),
                    'discount' => (float) ($l['discount'] ?? 0)], $by[(string) $m['id']] ?? []),
            ];
        }
    }
}
