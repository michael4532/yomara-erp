<?php

namespace App\Modules\Tresorerie\Bank;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Récupération des opérations auprès des sources connectées. */
class Providers
{
    // ---------------------------------------------------------------- Wave Business (API Balance & Reconciliation)
    public const WAVE = 'https://api.wave.com/v1';

    /** @return array{lines: array, balance: ?float, currency: ?string} */
    public static function wave(BankConnection $c, string $from, string $to): array
    {
        $key = (string) ($c->credentials['api_key'] ?? '');
        if ($key === '') {
            throw new \DomainException('Clé API Wave Business manquante.');
        }
        $http = fn () => Http::acceptJson()->timeout(40)->withToken($key);
        $lines = [];
        $day = \Carbon\Carbon::parse($from);
        $end = \Carbon\Carbon::parse($to);
        $guard = 0;
        while ($day->lte($end) && $guard++ < 120) {
            $after = null;
            do {
                $r = $http()->get(self::WAVE.'/transactions', array_filter(['date' => $day->toDateString(), 'after' => $after]));
                if ($r->status() === 401 || $r->status() === 403) {
                    throw new \DomainException('Wave refuse la clé API (droit « Balance & Reconciliation » requis).');
                }
                if (! $r->successful()) {
                    throw new \DomainException('Wave : erreur '.$r->status().' '.($r['message'] ?? ''));
                }
                foreach ((array) ($r['items'] ?? []) as $it) {
                    $amount = (float) ($it['amount'] ?? 0);
                    $who = trim(($it['counterparty_name'] ?? '').' '.($it['counterparty_mobile'] ?? ''));
                    $type = str_replace('_', ' ', (string) ($it['transaction_type'] ?? ''));
                    $lines[] = ['date' => substr((string) ($it['timestamp'] ?? $day->toDateString()), 0, 10), 'amount' => $amount,
                        'label' => trim('Wave '.$type.($who ? ' — '.$who : '').(! empty($it['payment_reason']) ? ' — '.$it['payment_reason'] : '')),
                        'reference' => $it['client_reference'] ?? null, 'external_id' => $it['transaction_id'] ?? null, 'counterparty' => $who ?: null,
                        'balance' => isset($it['balance']) ? (float) $it['balance'] : null];
                    $fee = (float) ($it['fee'] ?? 0);
                    if (abs($fee) >= 0.5) {
                        $lines[] = ['date' => substr((string) ($it['timestamp'] ?? $day->toDateString()), 0, 10), 'amount' => -abs($fee), 'label' => 'Frais Wave'.($who ? ' — '.$who : ''),
                            'reference' => null, 'external_id' => ($it['transaction_id'] ?? '').'-fee', 'counterparty' => null, 'balance' => null];
                    }
                }
                $after = ($r['page_info']['has_next_page'] ?? false) ? ($r['page_info']['end_cursor'] ?? null) : null;
            } while ($after);
            $day->addDay();
        }
        $bal = $http()->get(self::WAVE.'/balance');

        return ['lines' => $lines, 'balance' => $bal->successful() && isset($bal['amount']) ? (float) $bal['amount'] : null, 'currency' => $bal['currency'] ?? null];
    }

    // ---------------------------------------------------------------- GoCardless Bank Account Data (ex-Nordigen)
    public const GC = 'https://bankaccountdata.gocardless.com/api/v2';

    public static function gcCredentials(?BankConnection $c = null): array
    {
        $id = $c?->credentials['secret_id'] ?? null ?: config('erp.bank.gocardless_secret_id');
        $key = $c?->credentials['secret_key'] ?? null ?: config('erp.bank.gocardless_secret_key');
        if (! $id || ! $key) {
            throw new \DomainException('Identifiants GoCardless Bank Account Data manquants (secret_id / secret_key).');
        }

        return [$id, $key];
    }

    public static function gcToken(?BankConnection $c = null): string
    {
        [$id, $key] = self::gcCredentials($c);

        return Cache::remember('gc-token-'.md5($id), 3000, function () use ($id, $key) {
            $r = Http::acceptJson()->timeout(30)->post(self::GC.'/token/new/', ['secret_id' => $id, 'secret_key' => $key]);
            if (! $r->successful() || empty($r['access'])) {
                throw new \DomainException('GoCardless refuse les identifiants ('.$r->status().').');
            }

            return (string) $r['access'];
        });
    }

    public static function gc(?BankConnection $c = null)
    {
        return Http::acceptJson()->timeout(40)->withToken(self::gcToken($c));
    }

    public static function gcInstitutions(string $country, ?BankConnection $c = null): array
    {
        $r = self::gc($c)->get(self::GC.'/institutions/', ['country' => strtolower($country)]);
        if (! $r->successful()) {
            throw new \DomainException('GoCardless : liste des banques indisponible ('.$r->status().').');
        }

        return collect($r->json())->map(fn ($i) => ['id' => $i['id'], 'name' => $i['name'], 'logo' => $i['logo'] ?? null, 'days' => (int) ($i['transaction_total_days'] ?? 90)])->values()->all();
    }

    /** Crée l'accord et la demande d'accès ; renvoie le lien où le client s'authentifie auprès de sa banque. */
    public static function gcLink(BankConnection $c, string $institutionId, string $redirect): array
    {
        $ag = self::gc($c)->post(self::GC.'/agreements/enduser/', ['institution_id' => $institutionId, 'max_historical_days' => 90, 'access_valid_for_days' => 90,
            'access_scope' => ['balances', 'details', 'transactions']]);
        $req = self::gc($c)->post(self::GC.'/requisitions/', array_filter(['redirect' => $redirect, 'institution_id' => $institutionId, 'reference' => $c->id,
            'agreement' => $ag->successful() ? ($ag['id'] ?? null) : null, 'user_language' => 'FR']));
        if (! $req->successful() || empty($req['link'])) {
            throw new \DomainException('GoCardless : demande d\'accès refusée ('.$req->status().') '.json_encode($req->json()));
        }

        return ['requisition' => $req['id'], 'link' => $req['link']];
    }

    /** Après l'authentification : comptes autorisés par la banque. */
    public static function gcAccounts(BankConnection $c): array
    {
        $r = self::gc($c)->get(self::GC.'/requisitions/'.($c->meta['requisition'] ?? '').'/');
        if (! $r->successful()) {
            throw new \DomainException('GoCardless : autorisation introuvable ('.$r->status().').');
        }

        return ['status' => $r['status'] ?? null, 'accounts' => (array) ($r['accounts'] ?? [])];
    }

    public static function gocardless(BankConnection $c, string $from, string $to): array
    {
        $acc = $c->meta['gc_account'] ?? null;
        if (! $acc) {
            $a = self::gcAccounts($c);
            if (! $a['accounts']) {
                throw new \DomainException($a['status'] === 'EX' ? 'Autorisation bancaire expirée : reconnectez la banque.' : 'La banque n\'a pas encore été autorisée.');
            }
            $acc = $a['accounts'][0];
            $c->forceFill(['meta' => array_merge($c->meta ?? [], ['gc_account' => $acc])])->save();
        }
        $r = self::gc($c)->get(self::GC.'/accounts/'.$acc.'/transactions/', ['date_from' => $from, 'date_to' => $to]);
        if ($r->status() === 401 || $r->status() === 403 || ($r['status_code'] ?? null) === 401) {
            throw new \DomainException('Autorisation bancaire expirée : reconnectez la banque.');
        }
        if (! $r->successful()) {
            throw new \DomainException('Banque : erreur '.$r->status().' '.($r['detail'] ?? $r['summary'] ?? ''));
        }
        $lines = [];
        foreach ((array) ($r['transactions']['booked'] ?? []) as $t) {
            $amount = (float) ($t['transactionAmount']['amount'] ?? 0);
            $who = $amount >= 0 ? ($t['debtorName'] ?? null) : ($t['creditorName'] ?? null);
            $info = $t['remittanceInformationUnstructured'] ?? (is_array($t['remittanceInformationUnstructuredArray'] ?? null) ? implode(' ', $t['remittanceInformationUnstructuredArray']) : null)
                ?? $t['additionalInformation'] ?? null;
            $lines[] = ['date' => substr((string) ($t['bookingDate'] ?? $t['valueDate'] ?? ''), 0, 10), 'amount' => $amount, 'label' => trim(($who ? $who.' — ' : '').($info ?? ''), ' —') ?: 'Opération',
                'reference' => $t['endToEndId'] ?? null, 'external_id' => $t['transactionId'] ?? $t['internalTransactionId'] ?? null, 'counterparty' => $who, 'balance' => null];
        }
        $bal = self::gc($c)->get(self::GC.'/accounts/'.$acc.'/balances/');
        $b = collect($bal->successful() ? ($bal['balances'] ?? []) : [])->sortBy(fn ($x) => array_search($x['balanceType'] ?? '', ['interimBooked', 'closingBooked', 'expected', 'interimAvailable']) ?: 9)->first();

        return ['lines' => $lines, 'balance' => $b ? (float) $b['balanceAmount']['amount'] : null, 'currency' => $b['balanceAmount']['currency'] ?? null];
    }
}
