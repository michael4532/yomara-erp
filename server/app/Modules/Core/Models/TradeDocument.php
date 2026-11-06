<?php

namespace App\Modules\Core\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\Syncable;
use Carbon\Carbon;
use App\Models\Sequence;
use App\Modules\Core\Events\DocumentCancelled;
use App\Modules\Core\Events\DocumentValidated;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Document commercial (devis, commande, facture, avoir, bon de commande fournisseur…).
 * Les sous-classes SalesDocument / PurchaseDocument fixent la direction.
 *
 * Cycle de vie : draft → validated → (cancelled)
 *  - un brouillon est librement modifiable (y compris hors ligne) ;
 *  - la validation attribue le numéro définitif côté serveur, fige le document
 *    et déclenche DocumentValidated (le module Stock y génère les mouvements) ;
 *  - le client hors ligne demande une transition en envoyant simplement status.
 */
abstract class TradeDocument extends Model
{
    use \App\Models\Concerns\BelongsToBranch, \App\Models\Concerns\HasCustomFields, Syncable;

    protected $table = 'trade_documents';

    /** 'sale' | 'purchase' */
    public const DIRECTION = '';

    /** type => [libellé, clé de préfixe dans les paramètres, préfixe par défaut] */
    public const TYPES = [];

    /** Types qui génèrent une créance/dette payable. */
    public const PAYABLE_TYPES = [];

    public static array $searchable = ['number', 'reference', 'partner_name'];

    protected $fillable = [
        'type', 'reference', 'partner_id', 'partner_name', 'date', 'due_date', 'status',
        'currency', 'warehouse_id', 'branch_id', 'source_id', 'notes', 'terms', 'pos_session_id', 'rs_order_id', 'rs_bill', 'analytic', 'exchange_rate', 'project_id', 'payment_term_id',
    ];

    protected $attributes = ['status' => 'draft', 'payment_status' => 'unpaid'];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'schedule' => 'array',
            'subtotal' => 'float',
            'discount_total' => 'float',
            'tax_total' => 'float',
            'total' => 'float',
            'amount_paid' => 'float',
            'stock_posted' => 'boolean',
            'is_opening' => 'boolean',
            'validated_at' => 'datetime',
            'rs_bill' => 'array',
            'analytic' => 'array',
            'exchange_rate' => 'float',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('direction', fn (Builder $q) => $q->where('trade_documents.direction', static::DIRECTION));
        static::creating(function (self $d) {
            $d->direction = static::DIRECTION;
            $d->user_id ??= auth()->id();
            $d->currency ??= app()->bound('tenant') ? app('tenant')->currency : 'XOF';
        });
    }

    /** Agence déduite de l'entrepôt, de la caisse ou du document d'origine (toujours dans la société en cours). */
    public function branchFallback(): ?string
    {
        $DB = \Illuminate\Support\Facades\DB::class;
        $cid = $this->company_id ?? (app()->bound('tenant') ? app('tenant')->id : 0);
        if ($this->warehouse_id && ($b = $DB::table('warehouses')->where('id', $this->warehouse_id)->where('company_id', $cid)->value('branch_id'))) {
            return $b;
        }
        if ($this->pos_session_id && ($b = $DB::table('pos_sessions as s')->join('pos_registers as r', 'r.id', '=', 's.register_id')->where('s.id', $this->pos_session_id)->where('s.company_id', $cid)->where('r.company_id', $cid)->value('r.branch_id'))) {
            return $b;
        }
        if ($this->source_id && ($b = $DB::table('trade_documents')->where('id', $this->source_id)->where('company_id', $cid)->value('branch_id'))) {
            return $b;
        }

        return null;
    }

    public static function syncRules(): array
    {
        return [
            'type' => 'required|in:'.implode(',', array_keys(static::TYPES)),
            'reference' => 'nullable|string|max:100',
            'partner_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('partners')],
            'partner_name' => 'nullable|string|max:255',
            'date' => 'required|date',
            'due_date' => 'nullable|date',
            'status' => 'sometimes|in:draft,validated,cancelled',
            'currency' => 'sometimes|string|size:3',
            'warehouse_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('warehouses')],
            'branch_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('branches')],
            'source_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('trade_documents')],
            'pos_session_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('pos_sessions')],
            'rs_order_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('rs_orders')],
            'rs_bill' => 'nullable|array',
            'rs_bill.*' => 'numeric|min:0',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
            'lines' => 'sometimes|array',
            'lines.*.id' => 'nullable|uuid',
            'lines.*.product_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('products')],
            'lines.*.description' => 'required_with:lines|string|max:255',
            'lines.*.quantity' => 'required_with:lines|numeric',
            'lines.*.unit' => 'nullable|string|max:30',
            'lines.*.unit_price' => 'required_with:lines|numeric|min:0',
            'lines.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'lines.*.analytic' => 'nullable|array',
            'lines.*.unit_factor' => 'nullable|numeric|gt:0|max:100000',
            'lines.*.lots' => 'nullable|array|max:500',
            'lines.*.account_code' => ['nullable', 'regex:/^[1-9][0-9]{1,9}$/'],
            'analytic' => 'nullable|array',
            'exchange_rate' => 'nullable|numeric|gt:0|max:1000000000',
            'project_id' => 'nullable|uuid',
            'payment_term_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('payment_terms')],
        ];
    }

    // ---------- Relations ----------

    public function lines(): HasMany
    {
        return $this->hasMany(TradeDocumentLine::class, 'document_id')->orderBy('sort');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function scopeWithDetails(Builder $q): Builder
    {
        return $q->with('lines');
    }

    // ---------- Crochets d'écriture (EntityWriter) ----------

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $requested = $data['status'] ?? null;
        unset($data['status']); // les transitions passent par validate()/cancel()

        // Ventilation analytique (module Analytique) : contrôlée, sinon ignorée
        if (array_key_exists('analytic', $data)) {
            $data['analytic'] = static::cleanAnalytic($data['analytic']);
        }
        if (isset($data['lines']) && is_array($data['lines'])) {
            foreach ($data['lines'] as &$l) {
                if (is_array($l) && array_key_exists('analytic', $l)) {
                    $l['analytic'] = static::cleanAnalytic($l['analytic']);
                }
            }
            unset($l);
        }

        if (! $creating && $this->status !== 'draft') {
            $editable = array_diff(array_keys($data), ['notes', 'terms', 'reference', 'lines', 'analytic', 'custom', 'project_id']);
            $changesContent = isset($data['lines']) || array_intersect($editable, ['partner_id', 'date', 'type', 'warehouse_id', 'branch_id', 'currency', 'exchange_rate', 'payment_term_id']);
            if ($changesContent) {
                throw new \DomainException('Un document validé ne peut plus être modifié. Créez un avoir ou annulez-le.');
            }
            unset($data['lines']);
        }

        if (! empty($data['partner_id']) && empty($data['partner_name'])) {
            $data['partner_name'] = Partner::find($data['partner_id'])?->name;
        }
        if (! empty($data['project_id']) && (! app()->bound('tenant') || ! app('tenant')->hasModule('projets')
            || ! \Illuminate\Support\Facades\DB::table('prj_projects')->where('id', $data['project_id'])->where('company_id', app('tenant')->id)->whereNull('deleted_at')->exists())) {
            throw new \DomainException('Projet introuvable.');
        }
        $this->resolveCurrency($data, $creating);
        $this->resolvePaymentTerm($data, $creating);

        $this->pendingTransition = $requested;
    }

    /** Types de documents qui portent des conditions de paiement. */
    public const TERM_TYPES = ['quote', 'order', 'invoice', 'purchase_order', 'supplier_invoice'];

    /**
     * Conditions de paiement : celles du tiers (client ou fournisseur), sinon la condition par défaut de la société.
     * Reprises tant que le document est en brouillon et que l'utilisateur n'en a pas choisi.
     */
    protected function resolvePaymentTerm(array &$data, bool $creating): void
    {
        if (! app()->bound('tenant') || ! in_array($data['type'] ?? $this->type, static::TERM_TYPES, true)) {
            return;
        }
        if (! empty($data['payment_term_id'])) {
            if (! PaymentTerm::whereKey($data['payment_term_id'])->exists()) {
                throw new \DomainException('Conditions de paiement introuvables.');
            }

            return;
        }
        $partnerChanged = array_key_exists('partner_id', $data) && $data['partner_id'] !== $this->partner_id;
        if (array_key_exists('payment_term_id', $data) || (! $creating && ! $partnerChanged) || (! $creating && $this->status !== 'draft')) {
            return; // choix explicite (y compris « aucune ») ou rien à revoir
        }
        if (! empty($data['due_date'] ?? ($creating ? null : $this->due_date))) {
            return; // échéance fixée à la main (renouvellement, import…) : elle prime
        }
        $data['payment_term_id'] = static::defaultTermFor($data['partner_id'] ?? $this->partner_id, static::DIRECTION)?->id;
    }

    public static function defaultTermFor(?string $partnerId, string $direction): ?PaymentTerm
    {
        $p = $partnerId ? Partner::find($partnerId) : null;
        $id = $direction === 'purchase' ? $p?->supplier_payment_term_id : $p?->payment_term_id;

        return ($id ? PaymentTerm::find($id) : null) ?? PaymentTerm::where('active', true)->where($direction === 'purchase' ? 'is_default_purchase' : 'is_default_sale', true)->first();
    }

    /** Échéances du document : échéancier calculé, sinon une seule échéance (date d'échéance ou du document). */
    public function installments(): array
    {
        $total = (float) $this->total;
        if ($this->schedule) {
            return $this->schedule;
        }

        return [['date' => ($this->due_date ?? $this->date)->toDateString(), 'percent' => 100.0, 'amount' => $total]];
    }

    /** Échéances restant dues : les règlements soldent d'abord les plus anciennes. */
    public function unpaidInstallments(): array
    {
        $paid = (float) $this->amount_paid;
        $out = [];
        foreach ($this->installments() as $i) {
            $left = round((float) $i['amount'] - $paid, 2);
            $paid = max(0, $paid - (float) $i['amount']);
            if ($left > 0.009) {
                $out[] = ['date' => $i['date'], 'amount' => min($left, (float) $i['amount'])];
            }
        }

        return $out;
    }

    /** Montant échu non réglé à une date. */
    public function overdueAmount(?Carbon $at = null): float
    {
        $at = ($at ?? now())->toDateString();

        return round(collect($this->unpaidInstallments())->filter(fn ($i) => $i['date'] < $at)->sum('amount'), 2);
    }

    /** À payer maintenant : échéances arrivées à terme, sinon la prochaine échéance (paiement en ligne de l'échéance). */
    public function amountDueNow(): float
    {
        $list = $this->unpaidInstallments();
        if (! $list) {
            return 0.0;
        }
        $today = now()->toDateString();
        $due = collect($list)->filter(fn ($i) => $i['date'] <= $today)->sum('amount');

        return round(min($this->balance(), $due > 0 ? $due : (float) $list[0]['amount']), 2);
    }

    /** Première échéance non réglée. */
    public function firstUnpaidDue(): ?Carbon
    {
        $i = $this->unpaidInstallments()[0] ?? null;

        return $i ? Carbon::parse($i['date']) : null;
    }

    /**
     * Devise et cours : devise du tiers par défaut ; cours du jour du document
     * (module Devises) si la devise diffère de celle de la société.
     */
    protected function resolveCurrency(array &$data, bool $creating): void
    {
        if (! app()->bound('tenant')) {
            return;
        }
        $company = app('tenant');
        $base = strtoupper((string) $company->currency);
        if ($creating && empty($data['currency'])) {
            $pc = ! empty($data['partner_id']) ? Partner::find($data['partner_id'])?->currency : null;
            $data['currency'] = $pc && $company->hasModule('devises') ? $pc : $base;
        }
        if (! $creating && ! array_key_exists('currency', $data) && ! array_key_exists('exchange_rate', $data) && ! array_key_exists('date', $data)) {
            return;
        }
        $currency = strtoupper((string) ($data['currency'] ?? $this->currency ?? $base));
        if (array_key_exists('currency', $data)) {
            $data['currency'] = $currency;
        }
        if ($currency === $base) {
            $data['exchange_rate'] = 1;

            return;
        }
        if (! $company->hasModule('devises') || ! class_exists(\App\Modules\Devises\FxService::class)) {
            throw new \DomainException('Activez le module Devises pour établir un document en '.$currency.'.');
        }
        $fx = app(\App\Modules\Devises\FxService::class);
        $fixed = \App\Modules\Devises\FxService::fixedRate($base, $currency);
        if ($fixed !== null) {
            $data['exchange_rate'] = $fixed;
        } elseif (empty($data['exchange_rate']) || ($this->exists && $currency !== $this->currency && ! array_key_exists('exchange_rate', $data))) {
            $data['exchange_rate'] = $fx->require($company, $currency, $data['date'] ?? $this->date?->toDateString());
        }
    }

    /** Montant converti dans la devise de la société. */
    public function toBase(float $amount): float
    {
        return round($amount * (float) ($this->exchange_rate ?: 1), \App\Modules\Core\Models\TradeDocumentLine::decimalsFor(app()->bound('tenant') ? app('tenant')->currency : 'XOF'));
    }

    public static function cleanAnalytic(mixed $value): ?array
    {
        if (! class_exists(\App\Modules\Analytique\Distribution::class) || ! app()->bound('tenant') || ! app('tenant')->hasModule('analytique')) {
            return null;
        }

        return \App\Modules\Analytique\Distribution::clean($value);
    }

    /** @var string|null transition demandée par le client */
    public ?string $pendingTransition = null;

    public function afterEntityWrite(array $data, bool $creating): void
    {
        if (array_key_exists('lines', $data) && $this->status === 'draft') {
            $this->replaceLines($data['lines'] ?? []);
        }
        $this->checkProjectBudget();

        if (! $creating && $this->status === 'validated' && array_key_exists('analytic', $data) && $this->pendingTransition !== 'cancelled'
            && class_exists(\App\Modules\Analytique\AnalyticService::class) && app('tenant')->hasModule('analytique')) {
            app(\App\Modules\Analytique\AnalyticService::class)->syncDocument($this); // ventilation corrigée après validation
        }

        if ($this->pendingTransition === 'validated' && $this->status === 'draft') {
            $this->validateDocument();
        } elseif ($this->pendingTransition === 'cancelled' && $this->status !== 'cancelled') {
            $this->cancelDocument();
        }
    }

    public function beforeEntityDelete(): void
    {
        if ($this->status !== 'draft') {
            throw new \DomainException('Seuls les brouillons peuvent être supprimés. Annulez plutôt le document.');
        }
        $this->lines()->delete();
    }

    // ---------- Métier ----------

    public function replaceLines(array $lines): void
    {
        $this->lines()->delete();
        foreach (array_values($lines) as $i => $l) {
            $line = new TradeDocumentLine([
                'id' => $l['id'] ?? null,
                'product_id' => $l['product_id'] ?? null,
                'description' => $l['description'],
                'quantity' => (float) $l['quantity'],
                'unit' => $l['unit'] ?? null,
                'unit_price' => (float) $l['unit_price'],
                'discount_percent' => (float) ($l['discount_percent'] ?? 0),
                'tax_rate' => (float) ($l['tax_rate'] ?? 0),
                'analytic' => $l['analytic'] ?? null,
                'unit_factor' => (float) ($l['unit_factor'] ?? 1) ?: 1,
                'lots' => \App\Modules\Lots\LotService::cleanSpec($l['lots'] ?? null),
                'account_code' => $l['account_code'] ?? null,
                'sort' => $i,
            ]);
            $line->document_id = $this->id;
            $line->computeTotals($this->decimals());
            $line->save();
        }
        $this->recomputeTotals();
    }

    public function decimals(): int
    {
        return TradeDocumentLine::decimalsFor($this->currency);
    }

    public function recomputeTotals(): void
    {
        $lines = $this->lines()->get();
        $this->subtotal = round($lines->sum('subtotal'), 2);
        $this->discount_total = round($lines->sum(fn ($l) => max(0, $l->grossAmount($this->decimals()) - $l->subtotal)), 2);
        $this->tax_total = round($lines->sum('tax_amount'), 2);
        $this->total = round($lines->sum('total'), 2);
        $this->refreshPaymentStatus(false);
        $this->save();
    }

    public function validateDocument(): void
    {
        if ($this->status !== 'draft') {
            throw new \DomainException('Ce document est déjà validé.');
        }
        if (! $this->lines()->exists()) {
            throw new \DomainException('Ajoutez au moins une ligne avant de valider.');
        }
        // Circuit d'approbation : le document reste en brouillon et part en approbation.
        if (class_exists(\App\Modules\Approbations\ApprovalService::class) && app(\App\Modules\Approbations\ApprovalService::class)->interceptDocument($this)) {
            return;
        }

        [, $settingKey, $defaultPrefix] = static::TYPES[$this->type];
        $company = $this->company()->first();
        $prefix = $company->setting($settingKey) ?: $defaultPrefix;

        $this->number = Sequence::next($this->company_id, static::DIRECTION.'.'.$this->type, $prefix, (int) $this->date->format('Y'));
        $this->status = 'validated';
        $this->validated_at = now();
        $term = $this->payment_term_id ? PaymentTerm::withTrashed()->find($this->payment_term_id) : null;
        if ($term && in_array($this->type, static::PAYABLE_TYPES, true)) {
            // Échéancier des conditions de paiement ; l'échéance du document est la dernière
            $schedule = $term->schedule($this->date->copy(), (float) $this->total, TradeDocumentLine::decimalsFor($this->currency));
            $this->schedule = $schedule;
            $this->due_date = Carbon::parse($schedule[count($schedule) - 1]['date']);
        } elseif (! $this->due_date && in_array($this->type, static::PAYABLE_TYPES, true)) {
            $this->due_date = $this->date->copy()->addDays((int) $company->setting('payment_terms_days', 30));
        }
        $this->save();

        $this->promoteProspect();
        event(new DocumentValidated($this));
        $this->checkProjectBudget();
    }

    /**
     * Prospect → client (comme Dolibarr) : dès qu'une facture ou un ticket de caisse validé lui est attribué,
     * le prospect devient client. Un prospect reste prospect tant qu'il n'a que des devis, proformas ou commandes.
     */
    protected function promoteProspect(): void
    {
        if (static::DIRECTION !== 'sale' || ! $this->partner_id || ! in_array($this->type, static::PAYABLE_TYPES, true)) {
            return;
        }
        $p = Partner::where('id', $this->partner_id)->where('type', 'prospect')->first();
        if ($p) {
            $p->type = 'customer';
            $p->save();
        }
    }

    /** Les achats d'un projet peuvent franchir un seuil du budget : alerte immédiate au chef de projet. */
    protected function checkProjectBudget(): void
    {
        if ($this->project_id && static::DIRECTION === 'purchase' && $this->status === 'validated' && app()->bound('tenant') && app('tenant')->hasModule('projets')) {
            rescue(function () {
                $p = \App\Modules\Projets\Models\Project::find($this->project_id);
                $p && app(\App\Modules\Projets\ProjectCostService::class)->checkAlert($p);
            }, null, false);
        }
    }

    public function cancelDocument(): void
    {
        if ($this->amount_paid > 0) {
            throw new \DomainException('Ce document a déjà des paiements : supprimez-les ou émettez un avoir.');
        }
        $this->status = 'cancelled';
        $this->save();

        event(new DocumentCancelled($this));
    }

    public function refreshPaymentStatus(bool $save = true): void
    {
        $this->amount_paid = round((float) Payment::withoutGlobalScope('direction')
            ->where('document_id', $this->id)->sum('amount'), 2);

        $this->payment_status = match (true) {
            $this->amount_paid <= 0 => 'unpaid',
            $this->amount_paid + 0.009 >= $this->total => 'paid',
            default => 'partial',
        };

        if ($save) {
            $this->save();
        }
    }

    /** Crée un nouveau brouillon d'un autre type à partir de celui-ci (devis → facture…). */
    public function convertTo(string $type): static
    {
        if (! array_key_exists($type, static::TYPES)) {
            throw new \DomainException('Type de document inconnu.');
        }

        $copy = new static;
        $copy->fill([
            'type' => $type,
            'partner_id' => $this->partner_id,
            'partner_name' => $this->partner_name,
            'date' => now()->toDateString(),
            'warehouse_id' => $this->warehouse_id,
            'branch_id' => $this->branch_id,
            'source_id' => $this->id,
            'notes' => $this->notes,
            'terms' => $this->terms,
            'analytic' => $this->analytic,
            'project_id' => $this->project_id,
            'payment_term_id' => in_array($type, static::TERM_TYPES, true) ? $this->payment_term_id : null,
        ]);
        $copy->save();
        $copy->replaceLines($this->lines->map(fn ($l) => $l->only(['product_id', 'description', 'quantity', 'unit', 'unit_price', 'discount_percent', 'tax_rate', 'analytic']))->all());

        return $copy->fresh('lines');
    }

    public function balance(): float
    {
        return round($this->total - $this->amount_paid, 2);
    }

    public function toSyncArray(): array
    {
        $this->loadMissing('lines');

        return $this->toArray() + ['balance' => $this->balance()];
    }
}
