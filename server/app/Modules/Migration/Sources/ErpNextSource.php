<?php

namespace App\Modules\Migration\Sources;

use Illuminate\Support\Facades\Http;

/** ERPNext / Frappe (API REST, clé et secret d'API d'un utilisateur). */
class ErpNextSource extends Source
{
    public const METHODS = ['Cash' => 'cash', 'Bank Draft' => 'bank_transfer', 'Wire Transfer' => 'bank_transfer', 'Cheque' => 'cheque', 'Credit Card' => 'card'];

    public function __construct(protected string $url, protected string $apiKey, protected string $apiSecret)
    {
        $this->url = rtrim(trim($url), '/');
    }

    public function key(): string
    {
        return 'erpnext';
    }

    protected function get(string $path, array $query = []): mixed
    {
        try {
            $r = Http::withHeaders(['Authorization' => 'token '.$this->apiKey.':'.$this->apiSecret, 'Accept' => 'application/json'])
                ->withOptions(\App\Support\UrlGuard::pinned($this->url))->timeout(40)->retry(2, 500, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException, throw: false)->get($this->url.'/api/'.$path, $query);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $r = $e->response;
        } catch (\Illuminate\Http\Client\ConnectionException) {
            throw new \DomainException('Impossible de joindre ce serveur ERPNext : vérifiez l\'adresse.');
        }
        if (in_array($r->status(), [401, 403], true)) {
            throw new \DomainException('ERPNext refuse la clé ou le secret d\'API.');
        }
        if (! $r->successful()) {
            throw new \DomainException('ERPNext répond '.$r->status().' sur '.$path.'.');
        }

        return $r->json('data') ?? $r->json('message') ?? [];
    }

    protected function list(string $doctype, array $fields, array $filters = []): \Generator
    {
        for ($start = 0; $start < 500000; $start += 200) {
            $rows = $this->get('resource/'.rawurlencode($doctype), ['fields' => json_encode($fields), 'filters' => json_encode($filters), 'limit_start' => $start, 'limit_page_length' => 200, 'order_by' => 'creation asc']);
            if (! $rows) {
                return;
            }
            yield from $rows;
            if (count($rows) < 200) {
                return;
            }
        }
    }

    public function probe(): array
    {
        $count = fn (string $dt, array $f = []) => (int) $this->get('method/frappe.client.get_count', ['doctype' => $dt, 'filters' => json_encode($f)]);
        $company = $this->get('resource/Company', ['limit_page_length' => 1]);

        return ['name' => $company[0]['name'] ?? 'ERPNext', 'version' => null,
            'counts' => ['partners' => $count('Customer') + $count('Supplier'), 'products' => $count('Item'),
                'sales' => $count('Sales Invoice', [['docstatus', '=', 1]]), 'purchases' => $count('Purchase Invoice', [['docstatus', '=', 1]])]];
    }

    public function partners(): iterable
    {
        foreach ($this->list('Customer', ['name', 'customer_name', 'email_id', 'mobile_no', 'tax_id', 'customer_details']) as $c) {
            yield ['id' => 'C:'.$c['name'], 'name' => $c['customer_name'] ?: $c['name'], 'type' => 'customer', 'code' => $c['name'], 'email' => $c['email_id'] ?? null,
                'phone' => $c['mobile_no'] ?? null, 'address' => null, 'city' => null, 'tax_id' => $c['tax_id'] ?? null, 'notes' => $c['customer_details'] ?? null];
        }
        foreach ($this->list('Supplier', ['name', 'supplier_name', 'email_id', 'mobile_no', 'tax_id']) as $s) {
            yield ['id' => 'S:'.$s['name'], 'name' => $s['supplier_name'] ?: $s['name'], 'type' => 'supplier', 'code' => $s['name'], 'email' => $s['email_id'] ?? null,
                'phone' => $s['mobile_no'] ?? null, 'address' => null, 'city' => null, 'tax_id' => $s['tax_id'] ?? null, 'notes' => null];
        }
    }

    public function products(): iterable
    {
        $stock = [];
        foreach ($this->list('Bin', ['item_code', 'actual_qty']) as $b) {
            $stock[$b['item_code']] = ($stock[$b['item_code']] ?? 0) + (float) $b['actual_qty'];
        }
        foreach ($this->list('Item', ['name', 'item_code', 'item_name', 'item_group', 'stock_uom', 'standard_rate', 'valuation_rate', 'is_stock_item', 'disabled', 'description']) as $i) {
            yield ['id' => $i['name'], 'name' => $i['item_name'] ?: $i['item_code'], 'sku' => $i['item_code'], 'barcode' => null, 'category' => $i['item_group'] ?? null,
                'type' => ($i['is_stock_item'] ?? 1) ? 'product' : 'service', 'unit' => $i['stock_uom'] ?? null, 'sale_price' => (float) ($i['standard_rate'] ?? 0),
                'purchase_price' => (float) ($i['valuation_rate'] ?? 0), 'tax_rate' => null, 'stock' => $stock[$i['name']] ?? null, 'active' => ! ($i['disabled'] ?? 0),
                'description' => self::clean($i['description'] ?? null)];
        }
    }

    public function documents(string $direction): iterable
    {
        $dt = $direction === 'sale' ? 'Sales Invoice' : 'Purchase Invoice';
        foreach ($this->list($dt, ['name'], [['docstatus', 'in', [1, 2]]]) as $row) {
            $d = $this->get('resource/'.rawurlencode($dt).'/'.rawurlencode($row['name']));
            $tax = 0.0;
            foreach ($d['taxes'] ?? [] as $t) {
                $tax += (float) ($t['rate'] ?? 0);
            }
            $party = $direction === 'sale' ? $d['customer'] ?? null : $d['supplier'] ?? null;
            $total = abs((float) ($d['grand_total'] ?? 0));
            $paid = max(0, $total - abs((float) ($d['outstanding_amount'] ?? 0)));
            yield [
                'id' => $d['name'], 'number' => $d['name'], 'direction' => $direction, 'type' => ($d['is_return'] ?? 0) ? 'credit_note' : 'invoice',
                'partner_id' => $party ? ($direction === 'sale' ? 'C:' : 'S:').$party : null, 'partner_name' => $d['customer_name'] ?? $d['supplier_name'] ?? $party,
                'date' => self::date($d['posting_date'] ?? null), 'due_date' => self::date($d['due_date'] ?? null),
                'status' => (int) ($d['docstatus'] ?? 1) === 2 ? 'cancelled' : 'validated', 'currency' => $d['currency'] ?? null,
                'reference' => $d['po_no'] ?? $d['bill_no'] ?? null, 'total' => $total, 'paid' => $paid,
                'payments' => $paid > 0 ? [['date' => self::date($d['posting_date'] ?? null), 'amount' => $paid, 'method' => self::METHODS[$d['mode_of_payment'] ?? ''] ?? 'other', 'reference' => 'Règlements ERPNext']] : [],
                'lines' => array_map(fn ($l) => ['description' => self::clean($l['item_name'] ?? $l['description'] ?? null) ?? 'Ligne', 'product_id' => $l['item_code'] ?? null,
                    'quantity' => abs((float) ($l['qty'] ?? 1)) ?: 1, 'unit_price' => abs((float) ($l['rate'] ?? 0)), 'tax_rate' => $tax, 'discount' => 0], $d['items'] ?? []),
            ];
        }
    }
}
