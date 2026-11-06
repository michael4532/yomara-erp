<?php

namespace App\Modules\Fne;

use App\Http\Controllers\Controller;
use App\Modules\Achats\Models\PurchaseDocument;
use App\Modules\Fne\Models\FneCertification;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FneController extends Controller
{
    public function __construct(private FneService $svc) {}

    private function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function settings()
    {
        return ['data' => $this->svc->config()->toPublicArray() + [
            'templates' => FneService::TEMPLATES,
            'payment_methods' => FneService::PAYMENT_METHODS,
            'stats' => $this->svc->stats(),
        ]];
    }

    public function updateSettings(Request $r)
    {
        return $this->guard(function () use ($r) {
            $v = $r->validate([
                'enabled' => 'boolean',
                'environment' => 'in:test,prod',
                'base_url' => 'nullable|url|max:255',
                'api_key' => 'nullable|string|max:500',
                'clear_api_key' => 'boolean',
                'point_of_sale' => 'nullable|string|max:100',
                'establishment' => 'nullable|string|max:100',
                'default_template' => 'in:B2C,B2B,B2G,B2F',
                'zero_tax_code' => 'in:TVAC,TVAD',
                'auto_certify' => 'boolean',
                'certify_receipts' => 'boolean',
                'certify_credit_notes' => 'boolean',
                'commercial_message' => 'nullable|string|max:255',
                'footer' => 'nullable|string|max:255',
                'sticker_alert' => 'integer|min:0|max:100000',
            ]);
            if (! empty($v['base_url']) && ! \App\Support\UrlGuard::isPublic($v['base_url'])) {
                throw new \DomainException('Adresse refusée : seuls les serveurs publics (http/https) sont autorisés, pas le réseau interne.');
            }
            $cfg = $this->svc->config();
            $keyChanged = filled($v['api_key'] ?? null);
            if (! $keyChanged) {
                unset($v['api_key']);
            }
            if ($r->boolean('clear_api_key')) {
                $v['api_key'] = null;
            }
            unset($v['clear_api_key']);
            $cfg->fill($v);
            if (($cfg->environment === 'prod') && blank($cfg->base_url)) {
                throw new \DomainException('Indiquez l\'URL de production communiquée par la DGI.');
            }
            $cfg->company_id = app('tenant')->id;
            $cfg->save();

            // Clé corrigée : les refus pour clé invalide repartent dans la file.
            if ($keyChanged || $cfg->wasChanged(['environment', 'base_url'])) {
                FneCertification::where('status', 'failed')->where('last_status', 401)
                    ->each(function ($c) use ($cfg) {
                        if ($doc = $this->svc->document($c)) {
                            $this->svc->process($this->svc->queue($doc, $cfg));
                        }
                    });
            }

            return $this->settings();
        });
    }

    /** Certification manuelle d'un document (facture validée avant activation, bordereau d'achat…). */
    public function certify(Request $r)
    {
        return $this->guard(function () use ($r) {
            $v = $r->validate([
                'document_id' => 'required|uuid',
                'direction' => 'in:sale,purchase',
                'template' => ['nullable', Rule::in(array_keys(FneService::TEMPLATES))],
                'payment_method' => ['nullable', Rule::in(array_keys(FneService::PAYMENT_METHODS))],
            ]);
            $doc = ($v['direction'] ?? 'sale') === 'purchase'
                ? PurchaseDocument::findOrFail($v['document_id'])
                : SalesDocument::findOrFail($v['document_id']);
            $cfg = $this->svc->config();
            if (! $cfg->enabled) {
                throw new \DomainException('Activez d\'abord la FNE dans les paramètres.');
            }
            $cert = $this->svc->queue($doc, $cfg, array_filter($v, fn ($x) => $x !== null));
            $cert = $this->svc->process($cert);

            return ['data' => $cert->fresh()->toSyncArray()];
        });
    }

    public function retry(Request $r, string $id)
    {
        return $this->guard(function () use ($r, $id) {
            $v = $r->validate([
                'template' => ['nullable', Rule::in(array_keys(FneService::TEMPLATES))],
                'payment_method' => ['nullable', Rule::in(array_keys(FneService::PAYMENT_METHODS))],
            ]);
            $cert = FneCertification::findOrFail($id);
            if ($cert->status === 'certified') {
                throw new \DomainException('Ce document est déjà certifié.');
            }
            $doc = $this->svc->document($cert) ?? abort(404, 'Document introuvable.');
            $cert = $this->svc->process($this->svc->queue($doc, null, array_filter($v, fn ($x) => $x !== null)));

            return ['data' => $cert->fresh()->toSyncArray()];
        });
    }

    public function manual(Request $r, string $id)
    {
        return $this->guard(function () use ($r, $id) {
            $v = $r->validate([
                'reference' => 'required|string|max:64',
                'token' => 'nullable|string|max:2000',
                'fne_invoice_id' => 'nullable|string|max:64',
            ]);

            return ['data' => $this->svc->manual(FneCertification::findOrFail($id), $v)->toSyncArray()];
        });
    }

    public function show(string $id)
    {
        $cert = FneCertification::findOrFail($id);

        return ['data' => $cert->toSyncArray() + ['payload' => $cert->payload, 'response' => $cert->response]];
    }

    public function process()
    {
        return ['data' => $this->svc->processDue(app('tenant'))];
    }

    /** Factures validées sans certification (90 derniers jours). */
    public function uncertified()
    {
        $docs = SalesDocument::where('status', 'validated')->whereIn('type', ['invoice', 'receipt', 'credit_note'])->where('is_opening', false)->whereNull('migrated_from')
            ->where('validated_at', '>=', now()->subDays(90))
            ->whereNotIn('id', FneCertification::select('document_id'))
            ->orderByDesc('validated_at')->limit(300)
            ->get(['id', 'type', 'number', 'partner_name', 'date', 'total', 'currency']);

        return ['data' => $docs];
    }
}
