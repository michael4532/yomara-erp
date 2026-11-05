<?php

namespace App\Modules\Tresorerie\Bank;

use App\Modules\Tresorerie\Models\Operation;
use App\Modules\Tresorerie\Models\StatementLine;
use App\Modules\Tresorerie\Models\TreasuryAccount;
use App\Modules\Tresorerie\TreasuryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Règles de rapprochement appliquées aux lignes de relevé restées sans correspondance après le rapprochement automatique. */
class BankRules
{
    public function rules(TreasuryAccount $acc)
    {
        return DB::table('tr_bank_rules')->where('company_id', $acc->company_id)->where('active', true)
            ->where(fn ($q) => $q->whereNull('account_id')->orWhere('account_id', $acc->id))->orderBy('sort')->orderBy('id')->get();
    }

    public static function matches(object $rule, string $label, float $amount): bool
    {
        if ($rule->direction === 'in' && $amount <= 0 || $rule->direction === 'out' && $amount >= 0) {
            return false;
        }
        $abs = abs($amount);
        if ($rule->amount_min !== null && $abs < (float) $rule->amount_min || $rule->amount_max !== null && $abs > (float) $rule->amount_max) {
            return false;
        }
        $hay = Str::lower(Str::ascii($label));
        foreach (array_filter(array_map('trim', explode(',', (string) $rule->contains))) as $kw) {
            if (str_contains($hay, Str::lower(Str::ascii($kw)))) {
                return true;
            }
        }

        return false;
    }

    public function apply(TreasuryAccount $acc, ?int $onlyRule = null): int
    {
        $rules = $this->rules($acc)->when($onlyRule, fn ($c) => $c->where('id', $onlyRule));
        if ($rules->isEmpty()) {
            return 0;
        }
        $svc = app(TreasuryService::class);
        $n = 0;
        foreach (StatementLine::where('account_id', $acc->id)->where('status', 'open')->orderBy('date')->limit(2000)->get() as $l) {
            $rule = $rules->first(fn ($r) => self::matches($r, $l->label.' '.$l->counterparty, (float) $l->amount));
            if (! $rule) {
                continue;
            }
            try {
                if ($rule->action === 'ignore') {
                    $l->forceFill(['status' => 'ignored', 'rule_id' => $rule->id, 'matched_at' => now(), 'matched_by' => 'auto'])->save();
                } else {
                    $cat = array_key_exists((string) $rule->category, Operation::CATEGORIES) ? $rule->category : ($l->amount > 0 ? 'other_income' : 'other_expense');
                    if (! array_key_exists($cat, Operation::CATEGORIES)) {
                        $cat = array_key_first(Operation::CATEGORIES);
                    }
                    $svc->createOperation($l, $cat, $rule->label ?: null, 'auto');
                    $l->forceFill(['rule_id' => $rule->id])->save();
                }
                DB::table('tr_bank_rules')->where('id', $rule->id)->increment('hits');
                $n++;
            } catch (\DomainException $e) {
                // opération refusée (période close…) : la ligne reste à traiter à la main
            }
        }

        return $n;
    }
}
