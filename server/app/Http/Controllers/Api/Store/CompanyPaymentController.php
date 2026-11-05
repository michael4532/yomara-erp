<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\PaymentGateway;
use App\Modules\Ventes\Models\SalesDocument;
use App\Payments\GatewayRegistry;
use App\Payments\InvoicePayments;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Moyens de paiement d'une société pour ses propres clients (ses clés d'API) et coordonnées bancaires. */
class CompanyPaymentController extends Controller
{
    public function setup()
    {
        $company = app('tenant');

        return [
            'drivers' => GatewayRegistry::catalog(InvoicePayments::allowedDrivers()),
            'gateways' => PaymentGateway::where('company_id', $company->id)->orderBy('sort')->orderBy('id')->get()->map->toAdmin()->values(),
            'treasury_accounts' => \Illuminate\Support\Facades\Schema::hasTable('tr_accounts') ? \Illuminate\Support\Facades\DB::table('tr_accounts')->where('company_id', $company->id)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']) : [],
            'bank_accounts' => (array) data_get($company->settings, 'bank_accounts', []),
            'payment_instructions' => data_get($company->settings, 'payment_instructions'),
        ];
    }

    public static function gatewayRules(array $allowed, bool $creating): array
    {
        return [
            'driver' => [$creating ? 'required' : 'sometimes', Rule::in($allowed)],
            'label' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'mode' => 'sometimes|in:test,live',
            'credentials' => 'sometimes|array',
            'credentials.*' => 'nullable|string|max:2000',
            'instructions' => 'nullable|string|max:3000',
            'enabled' => 'sometimes|boolean',
            'sort' => 'sometimes|integer|min:0|max:999',
            'treasury_account_id' => 'sometimes|nullable|uuid',
        ];
    }

    /** Fusionne les clés : un champ laissé vide conserve la valeur enregistrée. */
    public static function fill(PaymentGateway $gw, array $data): void
    {
        if (isset($data['credentials'])) {
            $current = (array) $gw->credentials;
            foreach ($data['credentials'] as $k => $v) {
                if ($v !== null && $v !== '') {
                    $current[$k] = $v;
                }
            }
            $data['credentials'] = $current;
        }
        $gw->fill($data);
        if ($gw->driver === 'merchant_link' && ! $gw->publicUrl()) {
            abort(response()->json(['message' => 'Indiquez un lien de paiement valide commençant par https://', 'errors' => ['credentials.url' => ['Lien https obligatoire.']]], 422));
        }
    }

    /** Vérifie les clés auprès du prestataire, sans créer de paiement. */
    public static function checkGateway(PaymentGateway $gw): \Illuminate\Http\JsonResponse
    {
        $meta = \App\Payments\GatewayRegistry::meta($gw->driver);
        if (empty($meta['online'])) {
            return response()->json(['ok' => true, 'message' => $gw->driver === 'merchant_link' ? 'Lien enregistré : '.$gw->publicUrl() : 'Moyen de paiement manuel : rien à vérifier.']);
        }
        try {
            $msg = \App\Payments\GatewayRegistry::make($gw)->check();
        } catch (\App\Payments\PaymentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Prestataire injoignable : '.\Illuminate\Support\Str::limit($e->getMessage(), 160)], 422);
        }

        return response()->json(['ok' => true, 'message' => $msg]);
    }

    public function test(int $id)
    {
        return self::checkGateway(PaymentGateway::where('company_id', app('tenant')->id)->findOrFail($id));
    }

    public function store(Request $request)
    {
        $data = $request->validate(self::gatewayRules(InvoicePayments::allowedDrivers(), true));
        $gw = new PaymentGateway(['company_id' => app('tenant')->id]);
        self::fill($gw, $data);
        $gw->company_id = app('tenant')->id;
        $gw->save();
        ActivityLog::record('payments.gateway.created', $gw, ['driver' => $gw->driver]);

        return response()->json($gw->toAdmin(), 201);
    }

    public function update(Request $request, int $id)
    {
        $gw = PaymentGateway::where('company_id', app('tenant')->id)->findOrFail($id);
        self::fill($gw, $request->validate(self::gatewayRules(InvoicePayments::allowedDrivers(), false)));
        $gw->save();

        return $gw->toAdmin();
    }

    public function destroy(int $id)
    {
        PaymentGateway::where('company_id', app('tenant')->id)->findOrFail($id)->delete();

        return ['ok' => true];
    }

    public function bankAccounts(Request $request)
    {
        $data = $request->validate([
            'bank_accounts' => 'present|array|max:10',
            'bank_accounts.*.bank' => 'required|string|max:120',
            'bank_accounts.*.holder' => 'nullable|string|max:120',
            'bank_accounts.*.account' => 'nullable|string|max:80',
            'bank_accounts.*.iban' => 'nullable|string|max:60',
            'bank_accounts.*.swift' => 'nullable|string|max:20',
            'bank_accounts.*.branch' => 'nullable|string|max:120',
            'bank_accounts.*.on_invoices' => 'nullable|boolean',
            'payment_instructions' => 'nullable|string|max:1000',
        ]);
        $company = app('tenant');
        $settings = $company->settings ?? [];
        $settings['bank_accounts'] = array_values($data['bank_accounts']);
        $settings['payment_instructions'] = $data['payment_instructions'] ?? null;
        $company->update(['settings' => $settings]);
        ActivityLog::record('payments.bank_accounts.updated', $company);

        return $this->setup();
    }

    /** Lien public « Payer en ligne » d'une facture. */
    public function payLink(string $id, InvoicePayments $payments)
    {
        $doc = SalesDocument::findOrFail($id);
        if (! in_array($doc->type, ['invoice', 'receipt'], true) || $doc->status !== 'validated') {
            return response()->json(['message' => 'Validez la facture avant de partager un lien de paiement.'], 422);
        }
        $online = InvoicePayments::gateways(app('tenant'))->filter->isOnline()->count();

        return ['url' => InvoicePayments::url($payments->link($doc)), 'online_methods' => $online];
    }
}
