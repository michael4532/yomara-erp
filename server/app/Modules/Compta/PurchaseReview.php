<?php

namespace App\Modules\Compta;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Achats\Models\PurchaseDocument;
use App\Modules\Core\Models\Product;
use App\Modules\Core\Models\TradeDocument;
use App\Modules\Core\Models\TradeDocumentLine;
use Illuminate\Support\Facades\DB;

/**
 * Factures fournisseurs côté comptabilité :
 * nature d'achat (compte) proposée pour chaque ligne, circuit à revoir → approuvée / refusée → comptabilisée.
 */
class PurchaseReview
{
    public const STATUSES = ['to_review' => 'À vérifier', 'approved' => 'Approuvée', 'refused' => 'Refusée', 'posted' => 'Comptabilisée'];

    public static function enabled(Company $c): bool
    {
        return (bool) data_get($c->settings, 'compta.purchase_review', false);
    }

    /** Libellé de la nature d'achat d'un compte (ou intitulé du plan). */
    public static function kindLabel(string $code): string
    {
        $c = app()->bound('tenant') ? app('tenant') : null;
        foreach ($c ? Chart::purchaseKinds($c) : Chart::PURCHASE_KINDS as [$label, $acc]) {
            if ($acc === $code) {
                return $label;
            }
        }

        return ($c ? Referentials::name(Referentials::of($c), $code) : null) ?? Chart::ACCOUNTS[$code] ?? 'Compte '.$code;
    }

    /** Règles mémorisées de la société : [scope][ref_id] => code. */
    public function rules(Company $c): array
    {
        $out = ['product' => [], 'supplier' => [], 'category' => []];
        foreach (DB::table('compta_account_rules')->where('company_id', $c->id)->get(['scope', 'ref_id', 'account_code']) as $r) {
            $out[$r->scope][$r->ref_id] = $r->account_code;
        }

        return $out;
    }

    /**
     * Compte d'achat de chaque ligne : choix de la ligne, sinon compte mémorisé de l'article, du fournisseur
     * ou de la catégorie, sinon marchandises / services selon le type d'article.
     *
     * @return array<string, array{code:string, source:string}>
     */
    public function accounts(Company $c, TradeDocument $doc, ?array $rules = null): array
    {
        $rules ??= $this->rules($c);
        $map = Chart::mapping($c);
        $doc->loadMissing('lines');
        $products = Product::withoutGlobalScopes()->whereIn('id', $doc->lines->pluck('product_id')->filter())->get(['id', 'type', 'category_id'])->keyBy('id');
        $out = [];
        foreach ($doc->lines as $l) {
            $p = $l->product_id ? ($products[$l->product_id] ?? null) : null;
            [$code, $source] = match (true) {
                (bool) $l->account_code => [$l->account_code, 'line'],
                $p && isset($rules['product'][$p->id]) => [$rules['product'][$p->id], 'product'],
                ! $p && $doc->partner_id && isset($rules['supplier'][$doc->partner_id]) => [$rules['supplier'][$doc->partner_id], 'supplier'],
                $p && $p->category_id && isset($rules['category'][$p->category_id]) => [$rules['category'][$p->category_id], 'category'],
                $p && $p->type === 'service' => [$map['purchases_services'], 'default'],
                default => [$map['purchases_goods'], 'default'],
            };
            $out[$l->id] = ['code' => (string) $code, 'source' => $source];
        }

        return $out;
    }

    /** Mémorise les comptes choisis (article, sinon fournisseur) pour les prochaines factures. */
    public function remember(Company $c, TradeDocument $doc, array $codes): void
    {
        $doc->loadMissing('lines');
        foreach ($doc->lines as $l) {
            $code = $codes[$l->id] ?? null;
            if (! $code) {
                continue;
            }
            [$scope, $ref] = $l->product_id ? ['product', $l->product_id] : ($doc->partner_id ? ['supplier', $doc->partner_id] : [null, null]);
            if ($scope) {
                DB::table('compta_account_rules')->updateOrInsert(['company_id' => $c->id, 'scope' => $scope, 'ref_id' => $ref], ['account_code' => $code, 'updated_at' => now(), 'created_at' => now()]);
            }
        }
    }

    public function saveRule(Company $c, string $scope, string $ref, ?string $code): void
    {
        if (! $code) {
            DB::table('compta_account_rules')->where(['company_id' => $c->id, 'scope' => $scope, 'ref_id' => $ref])->delete();

            return;
        }
        DB::table('compta_account_rules')->updateOrInsert(['company_id' => $c->id, 'scope' => $scope, 'ref_id' => $ref], ['account_code' => $code, 'updated_at' => now(), 'created_at' => now()]);
    }

    /** Facture validée alors que le circuit est actif : en attente de vérification par la comptabilité. */
    public function queue(Company $c, TradeDocument $doc): void
    {
        if ($doc->acc_status && $doc->acc_status !== 'refused') {
            return;
        }
        $this->mark($doc, ['acc_status' => 'to_review']);
        $users = User::where('company_id', $c->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('compta.manage'))->pluck('id');
        AppNotification::send($c->id, $users, 'compta', 'Facture fournisseur à vérifier',
            trim(($doc->number ?? '').' '.($doc->partner_name ?? '')).' — '.number_format((float) $doc->total, 0, ',', ' ').' '.$doc->currency, 'compta', $doc->id, auth()->id());
    }

    /** Statut comptable modifié sans toucher au document (figé) ; les applications le reçoivent à la prochaine synchronisation. */
    public function mark(TradeDocument $doc, array $attrs): void
    {
        DB::table('trade_documents')->where('id', $doc->id)->update($attrs + ['updated_at' => now(), 'version' => DB::raw('version + 1')]);
        $doc->forceFill($attrs);
        $doc->logSyncChange('upsert');
    }

    /**
     * Décision de la comptabilité : approve, refuse (motif obligatoire) ou post (approuve et comptabilise).
     * $lines : [line_id => compte] ; $remember : mémoriser les comptes pour les prochaines factures.
     */
    public function decide(Company $c, PurchaseDocument $doc, string $decision, ?string $note, array $lines = [], bool $remember = true, ?int $userId = null): PurchaseDocument
    {
        if ($doc->type !== 'supplier_invoice' || $doc->status !== 'validated') {
            throw new \DomainException('Seule une facture fournisseur validée passe en comptabilité.');
        }
        if (! in_array($decision, ['approve', 'refuse', 'post'], true)) {
            throw new \DomainException('Décision inconnue.');
        }
        if ($decision === 'refuse' && ! trim((string) $note)) {
            throw new \DomainException('Indiquez le motif du refus.');
        }
        $doc->loadMissing('lines');
        $known = $doc->lines->pluck('id')->all();
        $codes = [];
        foreach ($lines as $id => $code) {
            if (! in_array($id, $known, true)) {
                continue;
            }
            $code = trim((string) $code);
            if ($code !== '' && ! preg_match('/^[1-9][0-9]{1,9}$/', $code)) {
                throw new \DomainException('Compte « '.$code.' » invalide.');
            }
            if ($code !== '' && ! in_array($code[0], ['2', '6'], true)) {
                throw new \DomainException('Le compte « '.$code.' » doit être un compte de charge (classe 6) ou d\'immobilisation (classe 2).');
            }
            $codes[$id] = $code ?: null;
        }

        return DB::transaction(function () use ($c, $doc, $decision, $note, $codes, $remember, $userId) {
            foreach ($codes as $id => $code) {
                TradeDocumentLine::where('id', $id)->update(['account_code' => $code]);
            }
            $doc->unsetRelation('lines');
            if ($codes && $c->hasModule('analytique') && class_exists(\App\Modules\Analytique\AnalyticService::class)) {
                app(\App\Modules\Analytique\AnalyticService::class)->syncDocument($doc); // analytique sur la nature d'achat retenue
            }
            if ($remember && $codes) {
                $this->remember($c, $doc, array_filter($codes));
            }
            $by = $userId ?? auth()->id();
            if ($decision === 'refuse') {
                if ($doc->acc_status === 'posted') {
                    throw new \DomainException('Facture déjà comptabilisée : contre-passez l\'écriture ou annulez la facture.');
                }
                $this->mark($doc, ['acc_status' => 'refused', 'acc_note' => trim((string) $note), 'acc_reviewed_by' => $by, 'acc_reviewed_at' => now()]);
                $buyers = User::where('company_id', $c->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('achats.manage'))->pluck('id')->push($doc->user_id)->filter()->unique()->values();
                AppNotification::send($c->id, $buyers, 'compta', 'Facture fournisseur refusée par la comptabilité', trim(($doc->number ?? '').' '.($doc->partner_name ?? '')).' : '.trim((string) $note), 'achats', $doc->id, $by);
            } elseif ($decision === 'approve') {
                $this->mark($doc, ['acc_status' => $doc->acc_status === 'posted' ? 'posted' : 'approved', 'acc_note' => $note ? trim($note) : $doc->acc_note, 'acc_reviewed_by' => $by, 'acc_reviewed_at' => now()]);
                if ($doc->acc_status === 'posted') {
                    app(Posting::class)->postDocument($doc->fresh());
                }
            } else {
                $this->mark($doc, ['acc_status' => 'posted', 'acc_reviewed_by' => $doc->acc_reviewed_by ?? $by, 'acc_reviewed_at' => $doc->acc_reviewed_at ?? now()] + ($note ? ['acc_note' => trim($note)] : []));
                if (! app(Posting::class)->postDocument($doc->fresh())) {
                    throw new \DomainException('Écriture impossible : vérifiez le montant de la facture.');
                }
            }

            return $doc->fresh();
        });
    }

    /** Le service achats renvoie une facture refusée après correction ou explication. */
    public function resubmit(Company $c, PurchaseDocument $doc, string $reply): PurchaseDocument
    {
        if ($doc->acc_status !== 'refused') {
            throw new \DomainException('Cette facture n\'a pas été refusée.');
        }
        $this->mark($doc, ['acc_status' => null, 'acc_note' => trim((string) $doc->acc_note)."\n— Réponse : ".trim($reply)]);
        $this->queue($c, $doc);

        return $doc->fresh();
    }

    public function toApi(Company $c, PurchaseDocument $doc, ?array $rules = null): array
    {
        $acc = $this->accounts($c, $doc, $rules);
        $entry = \App\Modules\Compta\Models\Entry::withoutGlobalScopes()->where('company_id', $c->id)->where(['source_type' => 'document', 'source_id' => $doc->id, 'kind' => 'doc'])
            ->whereNull('reversed_by_id')->latest('id')->first(['id', 'number', 'date']);

        return [
            'id' => $doc->id, 'number' => $doc->number, 'reference' => $doc->reference, 'date' => $doc->date?->toDateString(), 'due_date' => $doc->due_date?->toDateString(),
            'partner_id' => $doc->partner_id, 'partner_name' => $doc->partner_name, 'currency' => $doc->currency,
            'subtotal' => (float) $doc->subtotal, 'tax_total' => (float) $doc->tax_total, 'total' => (float) $doc->total, 'payment_status' => $doc->payment_status,
            'acc_status' => $doc->acc_status, 'acc_status_label' => self::STATUSES[$doc->acc_status] ?? null, 'acc_note' => $doc->acc_note, 'acc_reviewed_at' => $doc->acc_reviewed_at,
            'entry' => $entry ? ['id' => $entry->id, 'number' => $entry->number] : null,
            'lines' => $doc->lines->map(fn ($l) => ['id' => $l->id, 'description' => $l->description, 'quantity' => (float) $l->quantity, 'subtotal' => (float) $l->subtotal,
                'tax_amount' => (float) $l->tax_amount, 'product_id' => $l->product_id, 'account_code' => $acc[$l->id]['code'], 'account_source' => $acc[$l->id]['source'],
                'account_label' => self::kindLabel($acc[$l->id]['code'])])->values()->all(),
        ];
    }
}
