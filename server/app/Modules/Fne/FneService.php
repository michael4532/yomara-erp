<?php

namespace App\Modules\Fne;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Achats\Models\PurchaseDocument;
use App\Modules\Core\Models\Partner;
use App\Modules\Core\Models\Payment;
use App\Modules\Core\Models\Product;
use App\Modules\Core\Models\TradeDocument;
use App\Modules\Fne\Models\FneCertification;
use App\Modules\Fne\Models\FneConfig;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Facture normalisée électronique (FNE) — DGI Côte d'Ivoire.
 * API : POST /external/invoices/sign (facture de vente ou bordereau d'achat)
 *       POST /external/invoices/{id}/refund (avoir sur une facture certifiée)
 *
 * Chaque document validé reçoit une certification « pending » ; l'envoi est
 * tenté aussitôt puis relancé automatiquement (coupure réseau, serveur DGI
 * indisponible). Une erreur de contenu (400) ou de clé (401) passe en « failed »
 * et attend une correction.
 */
class FneService
{
    public const TEMPLATES = ['B2C' => 'Particulier', 'B2B' => 'Entreprise (NCC)', 'B2G' => 'Administration', 'B2F' => 'Client à l\'étranger'];

    public const PAYMENT_METHODS = ['cash' => 'Espèces', 'card' => 'Carte bancaire', 'check' => 'Chèque', 'mobile-money' => 'Mobile money', 'transfer' => 'Virement', 'deferred' => 'À terme (crédit)'];

    /** Moyens de paiement Yomara → codes FNE */
    public const METHOD_MAP = ['cash' => 'cash', 'mobile_money' => 'mobile-money', 'card' => 'card', 'bank_transfer' => 'transfer', 'cheque' => 'check', 'other' => 'cash', 'loyalty' => 'cash', 'gift_card' => 'cash'];

    /** Délais entre deux tentatives (minutes). */
    public const BACKOFF = [1, 2, 5, 10, 30, 60, 120, 240, 360];

    public function config(?Company $company = null): FneConfig
    {
        $company ??= app('tenant');

        return FneConfig::withoutGlobalScopes()->firstOrNew(['company_id' => $company->id]);
    }

    // ------------------------------------------------------------------
    // Mise en file
    // ------------------------------------------------------------------

    /** Appelé à la validation d'un document : crée la certification si le paramétrage le prévoit. */
    public function onValidated(TradeDocument $doc): ?FneCertification
    {
        $company = $doc->company()->first();
        if (! $company?->hasModule('fne') || $doc::DIRECTION !== 'sale') {
            return null;
        }
        $cfg = $this->config($company);
        if (! $cfg->enabled || ! $cfg->auto_certify) {
            return null;
        }
        $wanted = match ($doc->type) {
            'invoice' => true,
            'receipt' => $cfg->certify_receipts,
            'credit_note' => $cfg->certify_credit_notes,
            default => false,
        };
        if (! $wanted || $doc->is_opening || $doc->migrated_from) {
            return null;
        }

        $cert = $this->queue($doc, $cfg);
        $this->dispatch($cert);

        return $cert;
    }

    /** Crée (ou renvoie) la certification d'un document validé. */
    public function queue(TradeDocument $doc, ?FneConfig $cfg = null, array $options = []): FneCertification
    {
        if ($doc->status !== 'validated') {
            throw new \DomainException('Seul un document validé peut être certifié.');
        }
        $purchase = $doc::DIRECTION === 'purchase';
        if (! $purchase && ! in_array($doc->type, ['invoice', 'receipt', 'credit_note'], true)) {
            throw new \DomainException('Seuls les factures, tickets de caisse et avoirs sont certifiés.');
        }
        if ($purchase && $doc->type !== 'supplier_invoice') {
            throw new \DomainException('Seules les factures fournisseur peuvent faire l\'objet d\'un bordereau d\'achat FNE.');
        }
        $cfg ??= $this->config();

        $cert = FneCertification::where('document_id', $doc->id)->first();
        if ($cert && $cert->status === 'certified') {
            return $cert;
        }
        $cert ??= new FneCertification;
        $cert->document_id = $doc->id;
        $cert->document_number = $doc->number;
        $cert->document_type = $purchase ? 'purchase' : $doc->type;
        $cert->invoice_type = $purchase ? 'purchase' : ($doc->type === 'credit_note' ? 'refund' : 'sale');
        $cert->environment = $cfg->environment;
        $cert->status = 'pending';
        $cert->template = $options['template'] ?? $cert->template;
        $cert->payment_method = $options['payment_method'] ?? $cert->payment_method;
        $cert->next_attempt_at = now();
        $cert->last_error = null;
        $cert->requested_by ??= auth()->id();
        $cert->save();

        return $cert;
    }

    /** Envoi immédiat (commande, tests) ou juste après la réponse HTTP (les paiements du même lot sont alors enregistrés). */
    public function dispatch(FneCertification $cert): void
    {
        if (app()->runningInConsole()) {
            $this->process($cert);

            return;
        }
        $id = $cert->id;
        $company = app('tenant');
        app()->terminating(function () use ($id, $company) {
            app()->instance('tenant', $company);
            if ($c = FneCertification::find($id)) {
                $this->process($c);
            }
        });
    }

    // ------------------------------------------------------------------
    // Envoi
    // ------------------------------------------------------------------

    public function process(FneCertification $cert): FneCertification
    {
        $lock = Cache::lock('fne:'.$cert->id, 90);
        if (! $lock->get()) {
            return $cert;
        }
        try {
            $cert->refresh();
            if ($cert->status !== 'pending') {
                return $cert;
            }
            $cfg = $this->config();
            if (! $cfg->ready()) {
                $this->postpone($cert, null, 'Connexion FNE non configurée (clé API ou URL de production manquante).', 30);

                return $cert;
            }
            $doc = $this->document($cert);
            if (! $doc || $doc->status !== 'validated') {
                return $this->fail($cert, null, 'Le document n\'est plus validé (annulé ou supprimé).');
            }

            try {
                [$url, $payload, $items] = $cert->invoice_type === 'refund'
                    ? $this->refundRequest($cert, $doc, $cfg)
                    : $this->signRequest($cert, $doc, $cfg);
            } catch (WaitException $e) {
                $this->postpone($cert, null, $e->getMessage(), 5);

                return $cert;
            } catch (\DomainException $e) {
                return $this->fail($cert, null, $e->getMessage());
            }

            $cert->payload = $payload;
            $cert->attempts++;

            try {
                // adresse saisie par la société : jamais le serveur lui-même ni le réseau interne (SSRF)
                $pin = \App\Support\UrlGuard::pinned($cfg->baseUrl().$url);
            } catch (\DomainException $e) {
                return $this->fail($cert, null, $e->getMessage());
            }

            try {
                $res = Http::withToken((string) $cfg->api_key)->acceptJson()->asJson()->withOptions($pin)
                    ->connectTimeout(8)->timeout(25)
                    ->post($cfg->baseUrl().$url, $payload);
            } catch (ConnectionException $e) {
                $msg = $e->getMessage();
                if (preg_match('/resolve host|Failed to connect|Connection refused|Connection timed out|Could not connect|Network is unreachable/i', $msg)) {
                    $this->postpone($cert, null, 'Serveur FNE injoignable : nouvel essai automatique.');

                    return $cert;
                }

                // La requête est peut-être arrivée : pas de renvoi automatique (risque de double certification).
                return $this->fail($cert, null, 'Délai de réponse dépassé. Vérifiez sur le portail FNE si la facture a été certifiée avant de relancer (ou saisissez sa référence).');
            }

            $body = $res->json() ?? [];
            if ($res->successful()) {
                return $this->certified($cert, $cfg, $body, $items);
            }
            $message = $this->errorMessage($res->status(), $body);
            if ($res->serverError() || $res->status() === 429) {
                $this->postpone($cert, $res->status(), $message);

                return $cert;
            }

            return $this->fail($cert, $res->status(), $message, $body);
        } finally {
            $lock->release();
        }
    }

    /** Relance les certifications en attente arrivées à échéance. */
    public function processDue(?Company $company = null, int $limit = 100): array
    {
        $done = ['certified' => 0, 'failed' => 0, 'pending' => 0];
        $due = FneCertification::where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderByRaw("CASE WHEN invoice_type = 'refund' THEN 1 ELSE 0 END")->orderBy('created_at')
            ->limit($limit)->get();
        foreach ($due as $cert) {
            $done[$this->process($cert)->status]++;
        }
        $this->checkStickers($company ?? app('tenant'));

        return $done;
    }

    public function document(FneCertification $cert): ?TradeDocument
    {
        return $cert->document_type === 'purchase'
            ? PurchaseDocument::with('lines')->find($cert->document_id)
            : SalesDocument::with('lines')->find($cert->document_id);
    }

    // ------------------------------------------------------------------
    // Construction des requêtes
    // ------------------------------------------------------------------

    public function signRequest(FneCertification $cert, TradeDocument $doc, FneConfig $cfg): array
    {
        $this->assertCurrency($doc);
        $foreign = strtoupper((string) $doc->currency) !== 'XOF';
        $rate = $foreign ? (float) $doc->exchange_rate : 1.0;
        $partner = $doc->partner_id ? Partner::find($doc->partner_id) : null;
        // template / payment_method d'une certification non aboutie = choix forcé par l'utilisateur
        $template = $cert->template ?: $this->templateFor($partner, $cfg, $doc);
        $payment = $cert->payment_method ?: $this->paymentMethodFor($doc);

        if ($template === 'B2B' && blank($partner?->tax_id)) {
            throw new \DomainException('Facture B2B : renseignez le NCC (compte contribuable) du client « '.($doc->partner_name ?: 'sans nom').' ».');
        }

        $items = [];
        $lineMap = [];
        $products = Product::withoutGlobalScopes()->whereIn('id', $doc->lines->pluck('product_id')->filter())->pluck('sku', 'id');
        foreach ($doc->lines as $line) {
            if ($line->quantity == 0) {
                continue;
            }
            if ($line->quantity < 0 || $line->unit_price < 0) {
                throw new \DomainException('La ligne « '.$line->description.' » a une quantité ou un prix négatif : utilisez une remise ou un avoir.');
            }
            $item = array_filter([
                'taxes' => [$this->taxCode((float) $line->tax_rate, $cfg)],
                'reference' => $line->product_id ? ($products[$line->product_id] ?? null) : null,
                'description' => Str::limit($line->description, 250, ''),
                'quantity' => (float) $line->quantity,
                'amount' => round((float) $line->unit_price * $rate, 2), // en francs CFA
                'discount' => (float) $line->discount_percent ?: null,
                'measurementUnit' => $line->unit,
            ], fn ($v) => $v !== null && $v !== '');
            $items[] = $item;
            $lineMap[] = ['line_id' => $line->id, 'quantity' => (float) $line->quantity, 'product_id' => $line->product_id, 'description' => $line->description];
        }
        if (! $items) {
            throw new \DomainException('Aucune ligne à certifier.');
        }

        $seller = $doc->user_id ? User::withoutGlobalScopes()->find($doc->user_id) : null;
        $payload = array_filter([
            'invoiceType' => $cert->invoice_type === 'purchase' ? 'purchase' : 'sale',
            'paymentMethod' => $payment,
            'template' => $template,
            'isRne' => false,
            'clientNcc' => in_array($template, ['B2B', 'B2G'], true) ? ($partner?->tax_id ?: null) : null,
            'clientCompanyName' => Str::limit($doc->partner_name ?: ($cert->invoice_type === 'purchase' ? 'Fournisseur' : 'Client comptant'), 120, ''),
            'clientPhone' => $partner?->phone,
            'clientEmail' => $partner?->email,
            'clientSellerName' => $seller?->name,
            'pointOfSale' => $cfg->point_of_sale,
            'establishment' => $cfg->establishment,
            'commercialMessage' => $cfg->commercial_message,
            'footer' => $cfg->footer,
            'foreignCurrency' => $foreign ? strtoupper((string) $doc->currency) : null,
            'foreignCurrencyRate' => $foreign ? $rate : null,
            'items' => $items,
        ], fn ($v) => $v !== null && $v !== '');

        return ['/external/invoices/sign', $payload, $lineMap];
    }

    /** Avoir : renvoie vers la facture d'origine certifiée, ligne à ligne. */
    public function refundRequest(FneCertification $cert, TradeDocument $doc, FneConfig $cfg): array
    {
        $source = $doc->source_id ? SalesDocument::find($doc->source_id) : null;
        if (! $source || ! in_array($source->type, ['invoice', 'receipt'], true)) {
            throw new \DomainException('Cet avoir n\'est rattaché à aucune facture : créez-le depuis la facture d\'origine pour pouvoir le certifier.');
        }
        $parent = FneCertification::where('document_id', $source->id)->first();
        if (! $parent) {
            throw new \DomainException('La facture d\'origine '.$source->number.' n\'a pas été certifiée FNE : certifiez-la d\'abord.');
        }
        if ($parent->status === 'pending') {
            throw new WaitException('En attente de la certification de la facture d\'origine '.$source->number.'.');
        }
        if ($parent->status !== 'certified' || blank($parent->fne_invoice_id)) {
            throw new \DomainException('La facture d\'origine '.$source->number.' n\'a pas pu être certifiée : corrigez-la d\'abord.');
        }
        $cert->parent_id = $parent->id;

        // Quantités déjà remboursées par d'autres avoirs certifiés
        $used = [];
        foreach (FneCertification::where('parent_id', $parent->id)->where('status', 'certified')->where('id', '!=', $cert->id)->get() as $other) {
            foreach ($other->items ?? [] as $it) {
                $used[$it['fne_id']] = ($used[$it['fne_id']] ?? 0) + (float) $it['quantity'];
            }
        }

        $parentItems = collect($parent->items ?? []);
        $out = [];
        $map = [];
        foreach ($doc->lines as $line) {
            $qty = abs((float) $line->quantity);
            if ($qty == 0) {
                continue;
            }
            $match = $parentItems->first(function ($it) use ($line, $used, $qty) {
                $same = $line->product_id ? ($it['product_id'] ?? null) === $line->product_id : mb_strtolower(trim($it['description'] ?? '')) === mb_strtolower(trim($line->description));

                return $same && ! empty($it['fne_id']) && ((float) $it['quantity'] - ($used[$it['fne_id']] ?? 0)) + 0.0001 >= $qty;
            });
            if (! $match) {
                throw new \DomainException('La ligne « '.$line->description.' » (quantité '.$qty.') ne correspond à aucune ligne restant à rembourser sur la facture '.$source->number.'.');
            }
            $used[$match['fne_id']] = ($used[$match['fne_id']] ?? 0) + $qty;
            $out[] = ['id' => $match['fne_id'], 'quantity' => $qty];
            $map[] = ['line_id' => $line->id, 'fne_id' => $match['fne_id'], 'quantity' => $qty, 'product_id' => $line->product_id, 'description' => $line->description];
        }
        if (! $out) {
            throw new \DomainException('Aucune ligne à rembourser.');
        }

        return ['/external/invoices/'.rawurlencode($parent->fne_invoice_id).'/refund', ['items' => $out], $map];
    }

    public function templateFor(?Partner $partner, FneConfig $cfg, TradeDocument $doc): string
    {
        if ($partner && filled($partner->country) && ! $this->isDomestic($partner->country, $doc)) {
            return 'B2F';
        }
        if ($partner && filled($partner->tax_id) && $partner->is_company !== false) {
            return $cfg->default_template === 'B2G' ? 'B2G' : 'B2B';
        }

        return in_array($cfg->default_template, ['B2B', 'B2G'], true) ? 'B2C' : $cfg->default_template;
    }

    private function isDomestic(string $country, TradeDocument $doc): bool
    {
        $c = Str::of($country)->ascii()->lower()->replaceMatches('/[^a-z]/', '')->value();
        $home = Str::of((string) ($doc->company()->first()?->country ?: 'CI'))->ascii()->lower()->replaceMatches('/[^a-z]/', '')->value();

        return in_array($c, ['ci', 'civ', 'cotedivoire', 'ivorycoast', $home], true);
    }

    public function paymentMethodFor(TradeDocument $doc): string
    {
        $payments = Payment::withoutGlobalScope('direction')->where('document_id', $doc->id)->get();
        if ($payments->isEmpty()) {
            return 'deferred';
        }

        // Moyen principal (le plus gros montant) ; paiement partiel à la facturation → à terme si moins de la moitié réglée
        if ($payments->sum('amount') < $doc->total / 2) {
            return 'deferred';
        }

        return self::METHOD_MAP[$payments->sortByDesc('amount')->first()->method] ?? 'cash';
    }

    public function taxCode(float $rate, FneConfig $cfg): string
    {
        return match (true) {
            abs($rate - 18) < 0.001 => 'TVA',
            abs($rate - 9) < 0.001 => 'TVAB',
            abs($rate) < 0.001 => $cfg->zero_tax_code === 'TVAC' ? 'TVAC' : 'TVAD',
            default => throw new \DomainException('Taux de TVA '.rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',').' % non admis par la FNE (18 %, 9 % ou 0 % uniquement).'),
        };
    }

    private function assertCurrency(TradeDocument $doc): void
    {
        if (strtoupper((string) $doc->currency) !== 'XOF' && (float) $doc->exchange_rate <= 0) {
            throw new \DomainException('Cours de change manquant pour ce document en '.$doc->currency.'.');
        }
    }

    // ------------------------------------------------------------------
    // Résultats
    // ------------------------------------------------------------------

    private function certified(FneCertification $cert, FneConfig $cfg, array $body, array $lineMap): FneCertification
    {
        $inv = $body['invoice'] ?? $body['data']['invoice'] ?? [];
        $fneItems = array_values($inv['items'] ?? []);
        foreach ($lineMap as $i => &$m) {
            $m['fne_id'] ??= $fneItems[$i]['id'] ?? null;
        }
        unset($m);

        $token = $body['token'] ?? $inv['token'] ?? null;
        $cert->status = 'certified';
        $cert->template = $cert->payload['template'] ?? $cert->template;
        $cert->payment_method = $cert->payload['paymentMethod'] ?? $cert->payment_method;
        $cert->fne_invoice_id = $inv['id'] ?? $body['id'] ?? $cert->fne_invoice_id;
        $cert->reference = $body['reference'] ?? $inv['reference'] ?? null;
        $cert->ncc = $body['ncc'] ?? null;
        $cert->token = $token;
        $cert->verify_url = $this->verifyUrl($cfg, $token);
        $cert->fiscal_stamp = $inv['fiscalStamp'] ?? $body['fiscalStamp'] ?? null;
        $cert->amount = isset($inv['amount']) ? (float) $inv['amount'] : null;
        $cert->vat_amount = isset($inv['vatAmount']) ? (float) $inv['vatAmount'] : null;
        $w = $body['warning'] ?? null;
        $cert->warning = is_bool($w) ? ($w ? 'Stock de stickers faible' : null) : (Str::limit((string) $w, 250, '') ?: null);
        $cert->sticker_balance = isset($body['balance_sticker']) ? (int) $body['balance_sticker'] : null;
        $cert->items = $lineMap;
        $cert->response = $body;
        $cert->last_status = 200;
        $cert->last_error = null;
        $cert->next_attempt_at = null;
        $cert->certified_at = now();
        $cert->save();

        if ($cert->sticker_balance !== null) {
            $cfg->sticker_balance = $cert->sticker_balance;
            $cfg->sticker_balance_at = now();
            $cfg->save();
        }

        return $cert;
    }

    public function verifyUrl(FneConfig $cfg, ?string $token): ?string
    {
        if (blank($token)) {
            return null;
        }
        if (Str::startsWith($token, ['http://', 'https://'])) {
            return $token;
        }

        return $cfg->siteUrl().'/fr/verification/'.rawurlencode($token);
    }

    private function postpone(FneCertification $cert, ?int $status, string $message, ?int $minutes = null): void
    {
        $minutes ??= self::BACKOFF[min(max(0, $cert->attempts - 1), count(self::BACKOFF) - 1)];
        $cert->status = 'pending';
        $cert->last_status = $status;
        $cert->last_error = $message;
        $cert->next_attempt_at = now()->addMinutes($minutes);
        $cert->save();
    }

    private function fail(FneCertification $cert, ?int $status, string $message, ?array $body = null): FneCertification
    {
        $cert->status = 'failed';
        $cert->last_status = $status;
        $cert->last_error = $message;
        $cert->next_attempt_at = null;
        if ($body !== null) {
            $cert->response = $body;
        }
        $cert->save();

        $company = app('tenant');
        AppNotification::send($company->id, $this->managers($company), 'fne', 'Certification FNE refusée : '.($cert->document_number ?: 'document'), $message, 'fne', $cert->document_id);

        return $cert;
    }

    private function errorMessage(int $status, array $body): string
    {
        $msg = $body['message'] ?? $body['error'] ?? null;
        if (is_array($msg)) {
            $msg = implode(' ; ', array_map(fn ($m) => is_string($m) ? $m : json_encode($m, JSON_UNESCAPED_UNICODE), $msg));
        }

        return match (true) {
            $status === 401 => 'Clé API FNE refusée (401) : vérifiez la clé dans les paramètres FNE.'.($msg ? ' — '.$msg : ''),
            $status === 400 => 'Données refusées par la FNE (400) : '.($msg ?: 'requête invalide'),
            $status >= 500 => 'Serveur FNE indisponible ('.$status.') : nouvel essai automatique.',
            default => 'Réponse FNE '.$status.($msg ? ' : '.$msg : ''),
        };
    }

    /** Certification enregistrée à la main (facture certifiée sur le portail DGI). */
    public function manual(FneCertification $cert, array $data): FneCertification
    {
        if ($cert->status === 'certified') {
            throw new \DomainException('Ce document est déjà certifié.');
        }
        $cfg = $this->config();
        $cert->status = 'certified';
        $cert->reference = $data['reference'];
        $cert->token = $data['token'] ?? null;
        $cert->verify_url = $this->verifyUrl($cfg, $cert->token);
        $cert->fne_invoice_id = $data['fne_invoice_id'] ?? null;
        $cert->last_error = 'Saisie manuelle par '.(auth()->user()?->name ?? 'un administrateur');
        $cert->next_attempt_at = null;
        $cert->certified_at = now();
        $cert->save();

        return $cert;
    }

    // ------------------------------------------------------------------
    // Stickers, rappels
    // ------------------------------------------------------------------

    public function checkStickers(Company $company): void
    {
        $cfg = $this->config($company);
        if (! $cfg->exists || $cfg->sticker_balance === null || $cfg->sticker_balance > $cfg->sticker_alert) {
            return;
        }
        if ($cfg->sticker_alerted_at && $cfg->sticker_alerted_at->gt(now()->subDay())) {
            return;
        }
        AppNotification::send($company->id, $this->managers($company), 'fne', 'Stock de stickers FNE faible', 'Il reste '.$cfg->sticker_balance.' sticker(s) électronique(s). Rechargez votre compte sur le portail FNE de la DGI.', 'fne');
        $cfg->sticker_alerted_at = now();
        $cfg->save();
    }

    private function managers(Company $company): array
    {
        return User::withoutGlobalScopes()->where('company_id', $company->id)->where('is_active', true)->get()
            ->filter(fn ($u) => $u->hasPermission('fne.manage'))->pluck('id')->all();
    }

    public function stats(): array
    {
        $cfg = $this->config();

        return [
            'pending' => FneCertification::where('status', 'pending')->count(),
            'failed' => FneCertification::where('status', 'failed')->count(),
            'certified_month' => FneCertification::where('status', 'certified')->where('certified_at', '>=', now()->startOfMonth())->count(),
            'uncertified' => SalesDocument::where('status', 'validated')->whereIn('type', ['invoice', 'receipt'])->where('is_opening', false)->whereNull('migrated_from')
                ->where('validated_at', '>=', now()->subDays(90))
                ->whereNotIn('id', FneCertification::select('document_id'))->count(),
            'sticker_balance' => $cfg->sticker_balance,
            'sticker_alert' => $cfg->sticker_alert,
        ];
    }
}
