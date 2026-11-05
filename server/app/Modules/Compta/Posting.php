<?php

namespace App\Modules\Compta;

use App\Models\Company;
use App\Models\Sequence;
use App\Modules\Core\Models\Payment;
use App\Modules\Core\Models\Product;
use App\Modules\Core\Models\TradeDocument;
use App\Modules\Compta\Models\Account;
use App\Modules\Compta\Models\Entry;
use App\Modules\Compta\Models\EntryLine;
use App\Modules\Compta\Models\Journal;
use App\Modules\Achats\Models\PurchaseDocument;
use App\Modules\Achats\Models\PurchasePayment;
use App\Modules\Ventes\Models\SalesDocument;
use App\Modules\Ventes\Models\SalesPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Écritures comptables automatiques (SYSCOHADA) :
 *  - facture / ticket de caisse : D 411 Clients (TTC) / C 701-706 (HT) / C 4431 TVA
 *  - avoir : écriture inverse
 *  - facture fournisseur : D 601-605 (HT) / D 4452 TVA / C 401 Fournisseurs (TTC)
 *  - encaissement : D 571 / 521 / 513 / 5215 / C 411 ; règlement fournisseur : l'inverse
 *  - annulation : contre-passation datée du jour
 * Les écritures d'une période verrouillée ne sont jamais modifiées : on contre-passe.
 */
class Posting
{
    public const PURCHASE_TYPES = ['supplier_invoice'];

    public const SALE_TYPES = ['invoice', 'receipt', 'credit_note'];

    // ------------------------------------------------------------------
    // Documents
    // ------------------------------------------------------------------

    public function postDocument(TradeDocument $doc): ?Entry
    {
        $company = $this->company($doc->company_id);
        if (! $company || ! $company->hasModule('compta') || $doc->status !== 'validated') {
            return null;
        }
        $isSale = $doc->direction === 'sale' && in_array($doc->type, self::SALE_TYPES, true);
        $isPurchase = $doc->direction === 'purchase' && in_array($doc->type, self::PURCHASE_TYPES, true);
        if (! $isSale && ! $isPurchase) {
            return null;
        }
        // Circuit de vérification : la facture fournisseur attend la décision de la comptabilité
        if ($isPurchase && ! $doc->is_opening && PurchaseReview::enabled($company) && $doc->acc_status !== 'posted') {
            app(PurchaseReview::class)->queue($company, $doc);

            return null;
        }

        $entry = $this->upsert($company, 'document', $doc->id, 'doc', function () use ($company, $doc, $isSale) {
            $map = Chart::mapping($company);
            $doc->loadMissing('lines');
            $products = Product::withoutGlobalScopes()->whereIn('id', $doc->lines->pluck('product_id')->filter())->pluck('type', 'id');

            // HT réparti entre marchandises et services
            $base = ['goods' => 0.0, 'services' => 0.0];
            foreach ($doc->lines as $l) {
                $kind = ($products[$l->product_id] ?? 'product') === 'service' ? 'services' : 'goods';
                $base[$kind] += (float) $l->subtotal;
            }
            // Devise étrangère : conversion au cours du document
            $rate = (float) ($doc->exchange_rate ?: 1);
            $dec = \App\Modules\Core\Models\TradeDocumentLine::decimalsFor($company->currency);
            $base = array_map(fn ($v) => round($v * $rate, $dec), $base);
            $total = round((float) $doc->total * $rate, $dec);
            $tax = round($total - $base['goods'] - $base['services'], 2); // garantit l'équilibre
            $partner = $doc->partner_id;
            $label = $this->docLabel($doc);
            $lines = [];

            if ($doc->is_opening) {
                // Reprise de solde d'un ancien logiciel : créance (ou dette) contre compte d'attente, journal des à-nouveaux
                $sign = $isSale ? 1 : -1;
                $lines[] = $this->line($company, $isSale ? $map['customers'] : $map['suppliers'], $sign * $total, $label, $partner);
                $lines[] = $this->line($company, Chart::code($company, '471'), -$sign * $total, 'Reprise des soldes '.$label);

                return ['journal' => 'AN', 'date' => $doc->date, 'label' => 'Reprise '.$label, 'reference' => $doc->number, 'lines' => $lines];
            }

            if ($isSale) {
                $sign = $doc->type === 'credit_note' ? -1 : 1;
                $lines[] = $this->line($company, $map['customers'], $sign * $total, $label, $partner);
                foreach (['goods' => 'sales_goods', 'services' => 'sales_services'] as $k => $acc) {
                    if (abs($base[$k]) > 0.004) {
                        $lines[] = $this->line($company, $map[$acc], -$sign * round($base[$k], 2), $label);
                    }
                }
                if (abs($tax) > 0.004) {
                    $lines[] = $this->line($company, $map['vat_collected'], -$sign * $tax, 'TVA '.$label);
                }
                $journal = 'VE';
            } else {
                // Nature d'achat ligne par ligne (compte choisi, mémorisé ou par défaut) ; TVA des immobilisations en 4451
                $accounts = app(PurchaseReview::class)->accounts($company, $doc);
                $byAccount = [];
                $immoTax = 0.0;
                foreach ($doc->lines as $l) {
                    $code = $accounts[$l->id]['code'];
                    $byAccount[$code] = ($byAccount[$code] ?? 0) + round((float) $l->subtotal * $rate, $dec);
                    if ($code[0] === '2') {
                        $immoTax += round((float) $l->tax_amount * $rate, $dec);
                    }
                }
                $tax = round($total - array_sum($byAccount), 2);
                $immoTax = min(round($immoTax, 2), max(0, $tax));
                foreach ($byAccount as $code => $amount) {
                    if (abs($amount) > 0.004) {
                        $lines[] = $this->line($company, (string) $code, round($amount, 2), $label);
                    }
                }
                if ($immoTax > 0.004) {
                    $lines[] = $this->line($company, Chart::code($company, '4451'), $immoTax, 'TVA sur immobilisations '.$label);
                }
                if (abs($tax - $immoTax) > 0.004) {
                    $lines[] = $this->line($company, $map['vat_deductible'], round($tax - $immoTax, 2), 'TVA '.$label);
                }
                $lines[] = $this->line($company, $map['suppliers'], -$total, $label, $partner);
                $journal = 'AC';
            }

            return ['journal' => $journal, 'date' => $doc->date, 'label' => $label, 'reference' => $doc->number, 'lines' => $lines];
        });
        if ($entry && $isPurchase && $doc->acc_status !== 'posted') {
            app(PurchaseReview::class)->mark($doc, ['acc_status' => 'posted']);
        }

        return $entry;
    }

    /** Document annulé : contre-passation de son écriture. */
    public function cancelDocument(TradeDocument $doc): void
    {
        $company = $this->company($doc->company_id);
        if ($company && $company->hasModule('compta')) {
            $this->reverseActive($company, 'document', $doc->id, 'doc', 'Annulation '.$this->docLabel($doc), forceReversal: true);
        }
    }

    // ------------------------------------------------------------------
    // Paiements
    // ------------------------------------------------------------------

    public function postPayment(Payment $p): ?Entry
    {
        $company = $this->company($p->company_id);
        if (! $company || ! $company->hasModule('compta') || $p->trashed()) {
            return null;
        }
        $doc = DB::table('trade_documents')->where('id', $p->document_id)->first(['id', 'direction', 'number', 'reference', 'partner_id', 'partner_name', 'exchange_rate', 'currency']);
        $direction = $p->direction ?: ($doc->direction ?? 'sale');

        return $this->upsert($company, 'payment', $p->id, 'payment', function () use ($company, $p, $doc, $direction) {
            $map = Chart::mapping($company);
            $method = $p->method === 'bank_transfer' ? 'bank' : $p->method;
            $treasury = $map[$method] ?? $map['cash'];
            // Compte de trésorerie choisi (banque, caisse, Mobile Money) : son compte comptable
            if ($p->treasury_account_id && in_array($p->method, ['cash', 'mobile_money', 'card', 'bank_transfer', 'cheque', 'other'], true)) {
                $treasury = DB::table('tr_accounts')->where('id', $p->treasury_account_id)->value('gl_code') ?: $treasury;
            }
            // Devise étrangère : créance soldée au cours du document, trésorerie au cours du jour, écart en 676 / 776
            $dec = \App\Modules\Core\Models\TradeDocumentLine::decimalsFor($company->currency);
            $docRate = (float) ($doc?->exchange_rate ?: 1);
            $payRate = (float) ($p->exchange_rate ?: $docRate);
            $atDoc = round((float) $p->amount * $docRate, $dec);
            $amount = round((float) $p->amount * $payRate, $dec);
            $diff = round($amount - $atDoc, 2);
            $ref = $doc?->number ?? $doc?->reference;
            $who = $doc?->partner_name ? ' '.$doc->partner_name : '';
            $fxLabel = 'Écart de change '.($ref ?? '');

            if ($direction === 'sale') {
                $label = 'Encaissement '.($ref ?? '').$who;
                $lines = [
                    $this->line($company, $treasury, $amount, $label),
                    $this->line($company, $map['customers'], -$atDoc, $label, $doc?->partner_id),
                ];
                if (abs($diff) > 0.004) {
                    $lines[] = $this->line($company, Chart::code($company, $diff > 0 ? '776' : '676'), -$diff, $fxLabel);
                }
            } else {
                $label = 'Règlement '.($ref ?? '').$who;
                $lines = [
                    $this->line($company, $map['suppliers'], $atDoc, $label, $doc?->partner_id),
                    $this->line($company, $treasury, -$amount, $label),
                ];
                if (abs($diff) > 0.004) {
                    $lines[] = $this->line($company, Chart::code($company, $diff > 0 ? '676' : '776'), $diff, $fxLabel);
                }
            }

            return [
                'journal' => Chart::PAYMENT_JOURNALS[$p->method] ?? 'CA',
                'date' => $p->date,
                'label' => trim($label),
                'reference' => $p->reference ?? $ref,
                'lines' => $lines,
            ];
        });
    }

    public function removePayment(Payment $p): void
    {
        $company = $this->company($p->company_id);
        if ($company && $company->hasModule('compta')) {
            $this->reverseActive($company, 'payment', $p->id, 'payment', 'Suppression du paiement');
        }
    }

    // ------------------------------------------------------------------
    // Reprise de l'historique (activation tardive du module)
    // ------------------------------------------------------------------

    /** @return array{documents:int, payments:int} */
    public function rebuild(Company $company): array
    {
        Chart::ensure($company);
        $docs = 0;
        $pays = 0;
        $classes = ['sale' => SalesDocument::class, 'purchase' => PurchaseDocument::class];
        $payClasses = ['sale' => SalesPayment::class, 'purchase' => PurchasePayment::class];

        $rows = DB::table('trade_documents')->where('company_id', $company->id)->where('status', 'validated')
            ->whereNull('deleted_at')->orderBy('date')->get(['id', 'direction']);
        foreach ($rows as $r) {
            $doc = $classes[$r->direction]::withoutGlobalScopes()->find($r->id);
            if ($doc && ! $this->active($company, 'document', $doc->id, 'doc') && $this->postDocument($doc)) {
                $docs++;
            }
        }
        $rows = DB::table('payments')->where('company_id', $company->id)->whereNull('deleted_at')->orderBy('date')->get(['id', 'direction']);
        foreach ($rows as $r) {
            $p = $payClasses[$r->direction]::withoutGlobalScopes()->find($r->id);
            if ($p && ! $this->active($company, 'payment', $p->id, 'payment') && $this->postPayment($p)) {
                $pays++;
            }
        }

        return ['documents' => $docs, 'payments' => $pays];
    }

    // ------------------------------------------------------------------
    // Écritures venant d'autres modules (paie…)
    // ------------------------------------------------------------------

    /**
     * Crée ou remplace l'écriture d'une source externe.
     * $amounts : [code compte => montant] (positif = débit, négatif = crédit), ou
     * liste de [code, montant, libellé?, partner_id?].
     */
    public function postExternal(Company $company, string $journalCode, string $date, string $label, ?string $reference,
        array $amounts, string $sourceType, string $sourceId, string $kind = 'external'): ?Entry
    {
        if (! $company->hasModule('compta')) {
            return null;
        }

        return $this->upsert($company, $sourceType, $sourceId, $kind, function () use ($company, $journalCode, $date, $label, $reference, $amounts) {
            $lines = [];
            foreach ($amounts as $k => $v) {
                [$code, $amount, $lineLabel, $partner] = is_array($v) ? array_pad($v, 4, null) : [(string) $k, $v, null, null];
                if (abs((float) $amount) > 0.004) {
                    $lines[] = $this->line($company, (string) $code, round((float) $amount, 2), $lineLabel ?? $label, $partner);
                }
            }

            return ['journal' => $journalCode, 'date' => $date, 'label' => $label, 'reference' => $reference, 'lines' => $lines];
        });
    }

    /** Annule l'écriture d'une source externe : contre-passation ($force ou période verrouillée), sinon suppression. */
    public function cancelExternal(Company $company, string $sourceType, string $sourceId, string $kind, string $label, bool $force = true): void
    {
        if ($company->hasModule('compta')) {
            $this->reverseActive($company, $sourceType, $sourceId, $kind, $label, forceReversal: $force);
        }
    }

    // ------------------------------------------------------------------
    // Écritures manuelles
    // ------------------------------------------------------------------

    /**
     * @param  array<int, array{account_id:int, debit:float, credit:float, label?:string, partner_id?:string}>  $lines
     */
    public function manual(Company $company, int $journalId, string $date, string $label, ?string $reference, array $lines, ?int $userId = null): Entry
    {
        Chart::ensure($company);
        if ($this->locked($company, Carbon::parse($date))) {
            throw new \DomainException('La période est verrouillée jusqu\'au '.Carbon::parse($this->lockDate($company))->format('d/m/Y').'.');
        }
        $journal = Journal::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($journalId);
        $prepared = [];
        foreach ($lines as $l) {
            $acc = Account::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($l['account_id']);
            $prepared[] = [
                'account_id' => $acc->id,
                'partner_id' => $l['partner_id'] ?? null,
                'label' => $l['label'] ?? $label,
                'debit' => round((float) ($l['debit'] ?? 0), 2),
                'credit' => round((float) ($l['credit'] ?? 0), 2),
            ];
        }

        return $this->create($company, $journal->code, Carbon::parse($date), $label, $reference, $prepared, 'manual', null, 'manual', $userId);
    }

    /** Contre-passe une écriture (manuelle ou automatique). */
    public function reverse(Entry $entry, ?string $date = null, ?string $label = null): Entry
    {
        if ($entry->reversed_by_id) {
            throw new \DomainException('Cette écriture est déjà contre-passée.');
        }
        $company = $this->company($entry->company_id);
        $when = $date ? Carbon::parse($date) : now();
        if ($this->locked($company, $when)) {
            $when = Carbon::parse($this->lockDate($company))->addDay();
        }

        return DB::transaction(function () use ($company, $entry, $when, $label) {
            $lines = $entry->lines->map(fn (EntryLine $l) => [
                'account_id' => $l->account_id, 'partner_id' => $l->partner_id, 'label' => $l->label,
                'debit' => $l->credit, 'credit' => $l->debit,
            ])->all();
            $journal = Journal::withoutGlobalScopes()->find($entry->journal_id);
            $rev = $this->create($company, $journal->code, $when, $label ?? 'Contre-passation '.$entry->number, $entry->number, $lines,
                $entry->source_type, $entry->source_id, 'reversal', auth()->id(), $entry->id);
            $entry->forceFill(['reversed_by_id' => $rev->id])->save();

            return $rev;
        });
    }

    // ------------------------------------------------------------------
    // Interne
    // ------------------------------------------------------------------

    public function lockDate(Company $company): ?string
    {
        return data_get($company->settings, 'compta.lock_date');
    }

    public function locked(Company $company, Carbon $date): bool
    {
        $lock = $this->lockDate($company);

        return $lock && $date->toDateString() <= $lock;
    }

    protected function company(int $id): ?Company
    {
        return app()->bound('tenant') && app('tenant')->id === $id ? app('tenant') : Company::find($id);
    }

    protected function docLabel(TradeDocument $doc): string
    {
        $type = $doc::TYPES[$doc->type][0] ?? 'Document';

        return trim($type.' '.($doc->number ?? $doc->reference ?? '').($doc->partner_name ? ' '.$doc->partner_name : ''));
    }

    /** Ligne d'écriture : montant > 0 = débit, < 0 = crédit. */
    protected function line(Company $company, string $code, float $amount, string $label, ?string $partner = null): array
    {
        $acc = $this->account($company, $code);

        return [
            'account_id' => $acc->id,
            'partner_id' => $partner,
            'label' => mb_substr($label, 0, 255),
            'debit' => $amount > 0 ? round($amount, 2) : 0,
            'credit' => $amount < 0 ? round(-$amount, 2) : 0,
        ];
    }

    protected function account(Company $company, string $code): Account
    {
        Chart::ensure($company);

        return Account::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $company->id, 'code' => $code],
            ['name' => Referentials::name(Referentials::of($company), $code) ?? Chart::ACCOUNTS[$code] ?? 'Compte '.$code, 'is_system' => false, 'active' => true],
        );
    }

    protected function active(Company $company, string $sourceType, string $sourceId, string $kind): ?Entry
    {
        return Entry::withoutGlobalScopes()->where('company_id', $company->id)
            ->where(['source_type' => $sourceType, 'source_id' => $sourceId, 'kind' => $kind])
            ->whereNull('reversed_by_id')->latest('id')->first();
    }

    /**
     * Crée ou remplace l'écriture automatique d'une source.
     * Période ouverte : remplacement. Période verrouillée : contre-passation + nouvelle écriture.
     */
    protected function upsert(Company $company, string $sourceType, string $sourceId, string $kind, callable $build): ?Entry
    {
        return DB::transaction(function () use ($company, $sourceType, $sourceId, $kind, $build) {
            $spec = $build();
            $lines = array_values(array_filter($spec['lines'], fn ($l) => $l['debit'] > 0 || $l['credit'] > 0));
            if (! $lines) {
                return null;
            }
            $date = Carbon::parse($spec['date']);
            $existing = $this->active($company, $sourceType, $sourceId, $kind);

            if ($existing) {
                if ($this->sameLines($existing, $lines) && $existing->date->toDateString() === $date->toDateString()) {
                    return $existing;
                }
                if ($this->locked($company, $existing->date)) {
                    $this->reverse($existing, null, 'Correction '.$existing->number);
                } else {
                    $existing->lines()->delete();
                    $existing->delete();
                }
            }
            if ($this->locked($company, $date)) {
                $date = Carbon::parse($this->lockDate($company))->addDay();
            }

            return $this->create($company, $spec['journal'], $date, $spec['label'], $spec['reference'], $lines, $sourceType, $sourceId, $kind);
        });
    }

    protected function reverseActive(Company $company, string $sourceType, string $sourceId, string $kind, string $label, bool $forceReversal = false): void
    {
        $existing = $this->active($company, $sourceType, $sourceId, $kind);
        if (! $existing) {
            return;
        }
        if (! $forceReversal && ! $this->locked($company, $existing->date)) {
            $existing->lines()->delete();
            $existing->delete();

            return;
        }
        $this->reverse($existing, null, $label);
    }

    protected function sameLines(Entry $e, array $lines): bool
    {
        $norm = fn ($rows) => collect($rows)->map(fn ($l) => $l['account_id'].'|'.($l['partner_id'] ?? '').'|'.number_format($l['debit'], 2).'|'.number_format($l['credit'], 2))->sort()->values()->all();

        return $norm($e->lines->map->only(['account_id', 'partner_id', 'debit', 'credit'])->all()) === $norm($lines);
    }

    protected function create(Company $company, string $journalCode, Carbon $date, string $label, ?string $reference, array $lines,
        ?string $sourceType, ?string $sourceId, string $kind, ?int $userId = null, ?int $reverses = null): Entry
    {
        $debit = round(array_sum(array_column($lines, 'debit')), 2);
        $credit = round(array_sum(array_column($lines, 'credit')), 2);
        if (count($lines) < 2 || abs($debit - $credit) > 0.004 || $debit <= 0) {
            throw new \DomainException(sprintf('Écriture déséquilibrée (débit %s / crédit %s).', number_format($debit, 2, ',', ' '), number_format($credit, 2, ',', ' ')));
        }
        foreach ($lines as $l) {
            if (($l['debit'] > 0) === ($l['credit'] > 0)) {
                throw new \DomainException('Chaque ligne doit avoir soit un débit, soit un crédit.');
            }
        }

        Chart::ensure($company);
        $journal = Journal::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $company->id, 'code' => $journalCode],
            ['name' => Chart::JOURNALS[$journalCode][0] ?? $journalCode, 'type' => Chart::JOURNALS[$journalCode][1] ?? 'misc'],
        );
        $entry = Entry::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'journal_id' => $journal->id,
            'number' => Sequence::next($company->id, 'compta.'.$journal->code, $journal->code, (int) $date->format('Y')),
            'date' => $date->toDateString(),
            'label' => mb_substr($label, 0, 255),
            'reference' => $reference,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'kind' => $kind,
            'reverses_id' => $reverses,
            'user_id' => $userId ?? auth()->id(),
            'total' => $debit,
        ]);
        foreach ($lines as $l) {
            EntryLine::create($l + ['entry_id' => $entry->id, 'company_id' => $company->id]);
        }

        return $entry->load('lines');
    }
}
