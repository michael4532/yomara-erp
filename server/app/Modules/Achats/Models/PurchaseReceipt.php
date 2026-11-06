<?php

namespace App\Modules\Achats\Models;

use App\Models\Concerns\Syncable;
use App\Models\Sequence;
use App\Modules\Core\Models\TradeDocumentLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Réception (totale ou partielle) d'un bon de commande fournisseur : entrée en stock. */
class PurchaseReceipt extends Model
{
    use Syncable;

    public static string $syncEntity = 'purchase_receipts';

    public static string $viewPermission = 'achats.view|achats.receive';

    public static string $managePermission = 'achats.receive|achats.manage';

    protected $fillable = ['order_id', 'warehouse_id', 'date', 'supplier_reference', 'lines', 'notes', 'status'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'lines' => 'array', 'user_id' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'order_id' => ['required', 'uuid', Rule::exists('trade_documents', 'id')->where('company_id', $company)->where('direction', 'purchase')->where('type', 'purchase_order')],
            'warehouse_id' => ['nullable', 'uuid', Rule::exists('warehouses', 'id')->where('company_id', $company)],
            'date' => 'required|date',
            'supplier_reference' => 'nullable|string|max:80',
            'lines' => 'required|array|min:1|max:200',
            'lines.*.order_line_id' => 'required|uuid',
            'lines.*.quantity' => 'required|numeric|min:0',
            'lines.*.lots' => 'nullable|array|max:500',
            'notes' => 'nullable|string|max:2000',
            'status' => 'nullable|in:done,cancelled',
        ];
    }

    /** Quantités déjà reçues par ligne de commande : [line_id => qty]. */
    public static function receivedByLine(string $orderId, ?string $except = null): array
    {
        $out = [];
        foreach (static::where('order_id', $orderId)->where('status', 'done')->when($except, fn ($q) => $q->where('id', '!=', $except))->get() as $r) {
            foreach ($r->lines ?? [] as $l) {
                $out[$l['order_line_id']] = round(($out[$l['order_line_id']] ?? 0) + (float) $l['quantity'], 3);
            }
        }

        return $out;
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (! $creating) {
            // Une réception ne se modifie pas : on l'annule (le stock est corrigé) et on en refait une
            $data = array_intersect_key($data, array_flip(['status', 'notes', 'supplier_reference']));
            if (($data['status'] ?? $this->status) === 'done' && $this->status === 'cancelled') {
                throw new \DomainException('Une réception annulée ne se réactive pas : créez-en une nouvelle.');
            }

            return;
        }
        $order = PurchaseDocument::find($data['order_id']);
        if (! $order || $order->status !== 'validated') {
            throw new \DomainException('Seul un bon de commande validé peut être réceptionné.');
        }
        $lines = TradeDocumentLine::where('document_id', $order->id)->get()->keyBy('id');
        $done = self::receivedByLine($order->id);
        $tolerance = (float) (app('tenant')->setting('purchase_receipt_tolerance') ?? 0); // % de surplus accepté
        $clean = [];
        foreach ($data['lines'] as $l) {
            $line = $lines->get($l['order_line_id']);
            if (! $line) {
                throw new \DomainException('Ligne de commande inconnue.');
            }
            $qty = round((float) $l['quantity'], 3);
            if ($qty <= 0) {
                continue;
            }
            $remaining = round($line->quantity * (1 + $tolerance / 100) - ($done[$line->id] ?? 0), 3);
            if ($qty > $remaining + 0.0005) {
                throw new \DomainException('« '.$line->description.' » : '.$qty.' reçus pour '.max(0, round($line->quantity - ($done[$line->id] ?? 0), 3)).' restant à recevoir.');
            }
            $done[$line->id] = round(($done[$line->id] ?? 0) + $qty, 3); // même ligne répétée dans la réception : cumulée

            $clean[] = array_filter(['order_line_id' => $line->id, 'product_id' => $line->product_id, 'description' => $line->description, 'quantity' => $qty, 'unit' => $line->unit,
                'unit_factor' => (float) ($line->unit_factor ?: 1) != 1.0 ? (float) $line->unit_factor : null,
                'lots' => \App\Modules\Lots\LotService::cleanSpec($l['lots'] ?? null)], fn ($v) => $v !== null);
        }
        if (! $clean) {
            throw new \DomainException('Indiquez au moins une quantité reçue.');
        }
        $data['lines'] = $clean;
        $data['warehouse_id'] ??= $order->warehouse_id;
        $this->partner_id = $order->partner_id;
    }

    protected static function booted(): void
    {
        static::creating(function (self $r) {
            $r->number ??= Sequence::next($r->company_id, 'purchase_receipt', 'REC');
            $r->user_id ??= auth()->id();
            $r->status ??= 'done';
        });
    }
}
