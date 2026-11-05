<?php

namespace App\Modules\Compta;

use App\Models\Company;
use App\Models\User;
use App\Modules\Compta\Models\Entry;
use App\Modules\Compta\Models\FiscalYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fin d'exercice : contrôles avant clôture, impôt sur le résultat (IS / minimum d'imposition), écriture de clôture des
 * comptes de gestion vers le résultat, verrouillage, affectation du résultat, liasse des états financiers (DSF).
 * Exercice = année civile.
 */
class Closing
{
    public function __construct(protected Posting $posting, protected Statements $st, protected Ledger $ledger) {}

    protected function assert(bool $ok, string $msg): void
    {
        if (! $ok) {
            throw new \DomainException($msg);
        }
    }

    protected function end(Company $c, int $y): string
    {
        return FiscalCalendar::period($c, $y)[1];
    }

    protected function start(Company $c, int $y): string
    {
        return FiscalCalendar::period($c, $y)[0];
    }

    // ------------------------------------------------------------------------------------------------ paramètres fiscaux

    /** Paramètres d'imposition par défaut selon le pays / référentiel (modifiables, à vérifier avec la loi de finances en vigueur). */
    public static function fiscalDefaults(Company $c): array
    {
        $ref = Referentials::of($c);

        return match (true) {
            $ref === 'pcg' => ['is_rate' => 25, 'min_label' => 'Minimum d\'imposition', 'min_rate' => 0, 'min_floor' => 0, 'min_cap' => 0],
            $ref === 'cgnc' => ['is_rate' => 20, 'min_label' => 'Cotisation minimale', 'min_rate' => 0.25, 'min_floor' => 3000, 'min_cap' => 0],
            $ref === 'sycebnl' => ['is_rate' => 0, 'min_label' => 'Impôt minimum forfaitaire (IMF)', 'min_rate' => 0, 'min_floor' => 0, 'min_cap' => 0],
            strtoupper((string) $c->country) === 'CI' => ['is_rate' => 25, 'min_label' => 'Impôt minimum forfaitaire (IMF)', 'min_rate' => 0.5, 'min_floor' => 3000000, 'min_cap' => 35000000],
            default => ['is_rate' => 25, 'min_label' => 'Impôt minimum forfaitaire (IMF)', 'min_rate' => 0.5, 'min_floor' => 0, 'min_cap' => 0],
        };
    }

    public static function fiscalSettings(Company $c): array
    {
        return array_replace(self::fiscalDefaults($c), array_filter((array) data_get($c->settings, 'compta.fiscal', []), fn ($v) => $v !== null));
    }

    public function saveFiscalSettings(Company $c, array $d): array
    {
        $s = $c->settings ?? [];
        foreach (['is_rate', 'min_rate', 'min_floor', 'min_cap'] as $k) {
            if (array_key_exists($k, $d)) {
                data_set($s, 'compta.fiscal.'.$k, $d[$k] === null ? null : max(0, (float) $d[$k]));
            }
        }
        $c->forceFill(['settings' => $s])->save();

        return self::fiscalSettings($c);
    }

    // ------------------------------------------------------------------------------------------------ exercices

    public function year(Company $c, int $y): FiscalYear
    {
        return FiscalYear::firstOrNew(['company_id' => $c->id, 'year' => $y], ['status' => 'open']);
    }

    public function years(Company $c): array
    {
        $first = Entry::min('date');
        $current = FiscalCalendar::yearOf($c, now()->toDateString());
        $from = $first ? FiscalCalendar::yearOf($c, (string) $first) : $current;
        $rows = FiscalYear::get()->keyBy('year');
        $out = [];
        for ($y = $current; $y >= min($from, $current); $y--) {
            $fy = $rows[$y] ?? null;
            [$ps, $pe] = FiscalCalendar::period($c, $y);
            $out[] = ['year' => $y, 'label' => FiscalCalendar::label($c, $y), 'start' => $ps, 'end' => $pe, 'status' => $fy?->status ?? 'open', 'result' => $fy?->status === 'closed' ? $fy->result : $this->st->income($c, $this->start($c, $y), $this->end($c, $y))['result'],
                'closed_at' => $fy?->closed_at, 'allocated' => (bool) $fy?->allocation, 'entries' => Entry::whereBetween('date', [$ps, $pe])->count(), 'finished' => $this->end($c, $y) < now()->toDateString()];
        }

        return $out;
    }

    /** Exécute $fn en levant temporairement un verrouillage qui couvrirait la date (écritures de fin d'exercice datées du 31/12). */
    protected function atYearEnd(Company $c, string $date, callable $fn)
    {
        $lock = $this->posting->lockDate($c);
        if (! $lock || $lock < $date) {
            return $fn();
        }
        $s = $c->settings ?? [];
        data_set($s, 'compta.lock_date', Carbon::parse($date)->subDay()->toDateString());
        $c->forceFill(['settings' => $s])->save();
        try {
            return $fn();
        } finally {
            $s = $c->settings ?? [];
            data_set($s, 'compta.lock_date', $lock);
            $c->forceFill(['settings' => $s])->save();
        }
    }

    // ------------------------------------------------------------------------------------------------ contrôles

    /** Contrôles avant clôture : [clé, niveau error | warning | ok, titre, détail, nombre, montant, action]. */
    public function checks(Company $c, int $y): array
    {
        $end = $this->end($c, $y);
        $start = $this->start($c, $y);
        $fy = $this->year($c, $y);
        $bal = $this->st->balances($c, null, $end, true);
        $sumPrefix = fn (string $p) => round(array_sum(array_filter($bal, fn ($v, $k) => str_starts_with((string) $k, $p), ARRAY_FILTER_USE_BOTH)), 2);
        $out = [];
        $add = function (string $key, bool $problem, string $level, string $title, string $okText, string $detail, int $count = 0, ?float $amount = null, ?string $action = null) use (&$out) {
            $out[] = ['key' => $key, 'level' => $problem ? $level : 'ok', 'title' => $title, 'detail' => $problem ? $detail : $okText, 'count' => $count, 'amount' => $amount, 'action' => $problem ? $action : null];
        };

        $add('finished', $end >= now()->toDateString(), 'error', 'Exercice terminé', 'L\'exercice est terminé.', 'L\'exercice n\'est pas encore terminé : la clôture se fait après le '.Carbon::parse($end)->format('d/m/Y').'.');
        $prevHas = Entry::whereBetween('date', FiscalCalendar::period($c, $y - 1))->exists();
        $prevClosed = FiscalYear::where('year', $y - 1)->where('status', 'closed')->exists();
        $add('previous', $prevHas && ! $prevClosed, 'error', 'Exercice précédent clôturé', $prevHas ? 'L\'exercice '.FiscalCalendar::label($c, $y - 1).' est clôturé.' : 'Pas d\'exercice précédent.', 'Clôturez d\'abord l\'exercice '.FiscalCalendar::label($c, $y - 1).'.');

        // Factures fournisseurs non comptabilisées (circuit de vérification)
        $pending = DB::table('trade_documents')->where('company_id', $c->id)->whereNull('deleted_at')->where('type', 'supplier_invoice')->where('status', 'validated')
            ->whereBetween('date', [$start, $end])->whereIn('acc_status', ['to_review', 'approved', 'refused']);
        $add('purchases', (clone $pending)->exists(), 'error', 'Factures fournisseurs comptabilisées', 'Toutes les factures fournisseurs de l\'exercice sont comptabilisées.',
            (clone $pending)->count().' facture(s) fournisseur de l\'exercice attendent la comptabilité (à vérifier, approuvées ou refusées).', (clone $pending)->count(), round((float) (clone $pending)->sum('total'), 2), 'purchases');

        $drafts = DB::table('trade_documents')->where('company_id', $c->id)->whereNull('deleted_at')->where('status', 'draft')->whereIn('type', ['invoice', 'credit_note', 'supplier_invoice'])->whereBetween('date', [$start, $end]);
        $add('drafts', (clone $drafts)->exists(), 'warning', 'Pas de facture en brouillon', 'Aucune facture en brouillon sur l\'exercice.',
            (clone $drafts)->count().' facture(s) ou avoir(s) de l\'exercice sont restés en brouillon : validez-les ou supprimez-les.', (clone $drafts)->count(), null, 'documents');

        // Amortissements de l'exercice
        if ($c->hasModule('immobilisations') && Schema::hasTable('im_lines')) {
            $due = DB::table('im_lines as l')->join('im_assets as a', 'a.id', '=', 'l.asset_id')->where('a.company_id', $c->id)->where('a.status', 'running')->whereNull('l.posted_at')->where('l.period_end', '<=', $end);
            $add('depreciation', (clone $due)->exists(), 'warning', 'Dotations aux amortissements passées', 'Les dotations de l\'exercice sont comptabilisées.',
                (clone $due)->count().' dotation(s) aux amortissements de l\'exercice ne sont pas comptabilisées.', (clone $due)->count(), round((float) (clone $due)->sum('l.amount'), 2), 'depreciation');
        }

        // Caisse créditrice, compte d'attente, créances anciennes
        $cash = Chart::code($c, '571');
        $negCash = array_filter($bal, fn ($v, $k) => str_starts_with((string) $k, $cash) && $v < -0.004, ARRAY_FILTER_USE_BOTH);
        $add('cash', (bool) $negCash, 'error', 'Caisse non créditrice', 'Aucune caisse n\'a un solde créditeur.', 'Solde créditeur en caisse ('.implode(', ', array_keys($negCash)).') : une sortie d\'espèces a été enregistrée sans l\'entrée correspondante.', count($negCash), round(array_sum($negCash), 2), 'ledger');
        $suspense = $sumPrefix(Chart::code($c, '471'));
        $add('suspense', abs($suspense) > 0.004, 'warning', 'Compte d\'attente soldé', 'Le compte d\'attente est soldé.', 'Le compte d\'attente '.Chart::code($c, '471').' n\'est pas soldé au 31/12 : reclassez les montants sur leurs comptes définitifs.', 0, $suspense, 'ledger');
        $old = DB::table('trade_documents')->where('company_id', $c->id)->whereNull('deleted_at')->where('direction', 'sale')->where('type', 'invoice')->where('status', 'validated')
            ->where('payment_status', '!=', 'paid')->whereNotNull('due_date')->where('due_date', '<', Carbon::parse($end)->subYear()->toDateString());
        $add('doubtful', (clone $old)->exists(), 'warning', 'Créances anciennes provisionnées', 'Pas de créance client impayée depuis plus d\'un an.',
            (clone $old)->count().' facture(s) client impayée(s) depuis plus d\'un an : envisagez une provision pour créances douteuses ou un passage en perte.', (clone $old)->count(),
            round((float) (clone $old)->selectRaw('SUM(total - amount_paid) s')->value('s'), 2), 'tiers');

        // Rapprochement bancaire
        if ($c->hasModule('tresorerie') && Schema::hasTable('tr_statement_lines')) {
            $open = DB::table('tr_statement_lines')->where('company_id', $c->id)->where('status', 'open')->whereBetween('date', [$start, $end]);
            $add('bank', (clone $open)->exists(), 'warning', 'Relevés bancaires rapprochés', 'Les lignes de relevés de l\'exercice sont rapprochées.',
                (clone $open)->count().' ligne(s) de relevé bancaire de l\'exercice ne sont pas rapprochées.', (clone $open)->count(), null, 'bank');
        }

        // Impôt sur le résultat
        $f = $this->fiscal($c, $y);
        $add('tax', $f['due'] > 0 && abs($f['to_post']) > 0.5, 'warning', 'Impôt sur le résultat comptabilisé', $f['due'] > 0 ? 'L\'impôt de l\'exercice est comptabilisé.' : 'Pas d\'impôt sur le résultat à comptabiliser.',
            'Impôt calculé '.number_format($f['due'], 0, ',', ' ').', déjà comptabilisé '.number_format($f['posted'], 0, ',', ' ').' : comptabilisez l\'impôt avant la clôture.', 0, $f['to_post'], 'tax');
        $add('closed', $fy->status === 'closed', 'info', 'Exercice ouvert', 'L\'exercice est ouvert.', 'L\'exercice est clôturé depuis le '.$fy->closed_at?->format('d/m/Y').'.');

        return $out;
    }

    // ------------------------------------------------------------------------------------------------ impôt sur le résultat

    public function fiscal(Company $c, int $y): array
    {
        $p = self::fiscalSettings($c);
        $fy = $this->year($c, $y);
        $data = (array) $fy->fiscal;
        $income = $this->st->income($c, $this->start($c, $y), $this->end($c, $y));
        $taxCode = Chart::code($c, '891');
        $mov = $this->st->balances($c, $this->start($c, $y), $this->end($c, $y), true);
        $posted = round(array_sum(array_filter($mov, fn ($v, $k) => str_starts_with((string) $k, $taxCode), ARRAY_FILTER_USE_BOTH)), 2);
        $before = round($income['result'] + $posted, 2);
        $reint = array_values(array_filter((array) ($data['reintegrations'] ?? []), fn ($l) => (float) ($l['amount'] ?? 0) != 0));
        $deduc = array_values(array_filter((array) ($data['deductions'] ?? []), fn ($l) => (float) ($l['amount'] ?? 0) != 0));
        $taxable = round($before + array_sum(array_column($reint, 'amount')) - array_sum(array_column($deduc, 'amount')), 2);
        $revenue = (float) ($income['kpis']['revenue']['n'] ?? 0);
        $is = round(max(0, $taxable) * $p['is_rate'] / 100);
        $min = 0.0;
        if ($p['min_rate'] > 0) {
            $min = $revenue * $p['min_rate'] / 100;
            $min = max($min, (float) $p['min_floor']);
            if ($p['min_cap'] > 0) {
                $min = min($min, (float) $p['min_cap']);
            }
            $min = round($min);
        }
        $due = max($is, $min);

        return ['year' => $y, 'settings' => $p, 'revenue' => round($revenue, 2), 'result_before_tax' => $before, 'reintegrations' => $reint, 'deductions' => $deduc,
            'taxable' => $taxable, 'is' => $is, 'minimum' => $min, 'due' => $due, 'basis' => $due <= 0 ? null : ($is >= $min ? 'is' : 'minimum'),
            'posted' => $posted, 'to_post' => round($due - $posted, 2), 'net_result' => round($before - $due, 2), 'tax_account' => $taxCode, 'payable_account' => Chart::code($c, '441')];
    }

    public function saveFiscal(Company $c, int $y, array $reint, array $deduc): array
    {
        $fy = $this->year($c, $y);
        $this->assert($fy->status !== 'closed', 'Exercice clôturé : rouvrez-le pour modifier le calcul de l\'impôt.');
        $clean = fn ($rows) => array_values(array_filter(array_map(fn ($r) => ['label' => mb_substr(trim((string) ($r['label'] ?? '')), 0, 200), 'amount' => round((float) ($r['amount'] ?? 0), 2)], $rows), fn ($r) => $r['label'] !== '' || $r['amount'] != 0));
        $fy->fiscal = ['reintegrations' => $clean($reint), 'deductions' => $clean($deduc)] + (array) $fy->fiscal;
        $fy->save();

        return $this->fiscal($c, $y);
    }

    /** Écriture de l'impôt de l'exercice (charge d'impôt / État, impôt sur les bénéfices), datée du 31/12. */
    public function postTax(Company $c, int $y): array
    {
        $fy = $this->year($c, $y);
        $this->assert($fy->status !== 'closed', 'Exercice clôturé.');
        $f = $this->fiscal($c, $y);
        $existing = round($f['posted'] - (float) Entry::where('source_type', 'year_tax')->where('source_id', (string) $y)->whereNull('reversed_by_id')->sum('total'), 2);
        $amount = round($f['due'] - $existing, 2); // impôt restant après les charges d'impôt saisies par ailleurs
        $this->atYearEnd($c, $this->end($c, $y), function () use ($c, $y, $amount, $f) {
            if ($amount <= 0) {
                $this->posting->cancelExternal($c, 'year_tax', (string) $y, 'income_tax', 'Annulation impôt '.$y, false);

                return;
            }
            $this->posting->postExternal($c, 'OD', $this->end($c, $y), 'Impôt sur le résultat de l\'exercice '.FiscalCalendar::label($c, $y).($f['basis'] === 'minimum' ? ' ('.$f['settings']['min_label'].')' : ''), 'IS-'.$y,
                [[$f['tax_account'], $amount], [$f['payable_account'], -$amount]], 'year_tax', (string) $y, 'income_tax');
        });

        return $this->fiscal($c, $y);
    }

    // ------------------------------------------------------------------------------------------------ clôture

    public function close(Company $c, int $y, ?User $u): FiscalYear
    {
        $fy = $this->year($c, $y);
        $this->assert($fy->status !== 'closed', 'L\'exercice '.FiscalCalendar::label($c, $y).' est déjà clôturé.');
        $errors = array_filter($this->checks($c, $y), fn ($x) => $x['level'] === 'error');
        $this->assert(! $errors, 'Clôture impossible : '.implode(' ', array_column($errors, 'detail')));
        $end = $this->end($c, $y);
        $classes = Referentials::resultClasses(Referentials::of($c));
        $mov = $this->st->balances($c, $this->start($c, $y), $end, true);
        $lines = [];
        $result = 0.0;
        foreach ($mov as $code => $s) {
            $code = (string) $code;
            if (in_array($code[0], $classes, true) && abs($s) > 0.004) {
                $lines[] = [$code, -$s, 'Solde du compte '.$code];
                $result -= $s;
            }
        }
        $result = round($result, 2);
        if (abs($result) > 0.004) {
            $lines[] = $result > 0 ? [Chart::code($c, '131'), -$result, 'Résultat de l\'exercice '.FiscalCalendar::label($c, $y).' (bénéfice)'] : [Chart::code($c, '139'), -$result, 'Résultat de l\'exercice '.FiscalCalendar::label($c, $y).' (perte)'];
        }
        DB::transaction(function () use ($c, $y, $u, $fy, $lines, $result, $end) {
            $previous = $this->posting->lockDate($c);
            if ($lines) {
                $this->atYearEnd($c, $end, fn () => $this->posting->postExternal($c, 'CL', $end, 'Clôture de l\'exercice '.FiscalCalendar::label($c, $y).' : solde des comptes de gestion', 'CL-'.$y, $lines, 'year', (string) $y, 'closing'));
            }
            $s = $c->settings ?? [];
            data_set($s, 'compta.lock_date', $previous && $previous > $end ? $previous : $end);
            $c->forceFill(['settings' => $s])->save();
            $fy->forceFill(['status' => 'closed', 'result' => $result, 'previous_lock' => $previous, 'closed_at' => now(), 'closed_by' => $u?->id])->save();
        });

        return $fy->fresh();
    }

    public function reopen(Company $c, int $y): FiscalYear
    {
        $fy = $this->year($c, $y);
        $this->assert($fy->status === 'closed', 'L\'exercice n\'est pas clôturé.');
        $this->assert(! $fy->allocation, 'Le résultat est déjà affecté : annulez d\'abord l\'affectation.');
        $this->assert(! FiscalYear::where('year', $y + 1)->where('status', 'closed')->exists(), 'L\'exercice '.FiscalCalendar::label($c, $y + 1).' est clôturé : rouvrez-le d\'abord.');
        DB::transaction(function () use ($c, $y, $fy) {
            $s = $c->settings ?? [];
            data_set($s, 'compta.lock_date', $fy->previous_lock);
            $c->forceFill(['settings' => $s])->save();
            $this->atYearEnd($c, $this->end($c, $y), fn () => $this->posting->cancelExternal($c, 'year', (string) $y, 'closing', 'Réouverture de l\'exercice '.$y, false));
            $fy->forceFill(['status' => 'open', 'result' => null, 'closed_at' => null, 'closed_by' => null])->save();
        });

        return $fy->fresh();
    }

    // ------------------------------------------------------------------------------------------------ affectation du résultat

    /** Proposition d'affectation : réserve légale (OHADA 10 % jusqu'à 20 % du capital ; PCG et CGNC 5 % jusqu'à 10 %), le reste en report à nouveau. */
    public function allocationProposal(Company $c, int $y): array
    {
        $fy = $this->year($c, $y);
        $ref = Referentials::of($c);
        $result = (float) ($fy->status === 'closed' ? $fy->result : $this->st->income($c, $this->start($c, $y), $this->end($c, $y))['result']);
        $bal = $this->st->balances($c, null, $this->end($c, $y));
        $sum = fn (string $p) => array_sum(array_filter($bal, fn ($v, $k) => str_starts_with((string) $k, $p), ARRAY_FILTER_USE_BOTH));
        $capital = round(-$sum(Chart::code($c, '101')), 2);
        $legalNow = round(-$sum(Chart::code($c, '111')), 2);
        $priorLoss = round(max(0, $sum(Chart::code($c, '129'))), 2);
        [$rate, $cap] = in_array($ref, ['pcg', 'cgnc'], true) ? [5, 10] : [10, 20];
        $legal = 0.0;
        if ($result > 0 && $capital > 0 && $ref !== 'sycebnl') {
            $legal = round(min(max(0, $result - $priorLoss) * $rate / 100, max(0, $capital * $cap / 100 - $legalNow)));
        }

        return ['year' => $y, 'result' => round($result, 2), 'capital' => $capital, 'legal_reserve_now' => $legalNow, 'prior_loss' => $priorLoss, 'rate' => $rate, 'cap' => $cap,
            'proposal' => $result > 0 ? ['legal_reserve' => $legal, 'other_reserves' => 0, 'dividends' => 0, 'report' => round($result - $legal, 2)] : ['report' => round($result, 2)],
            'allocation' => $fy->allocation, 'allocation_date' => $fy->allocation_date?->toDateString(),
            'accounts' => ['result' => Chart::code($c, $result >= 0 ? '131' : '139'), 'legal_reserve' => Chart::code($c, '111'), 'other_reserves' => Chart::code($c, '118'),
                'dividends' => Chart::code($c, '465'), 'report' => Chart::code($c, $result >= 0 ? '121' : '129')]];
    }

    public function allocate(Company $c, int $y, array $d, string $date): FiscalYear
    {
        $fy = $this->year($c, $y);
        $this->assert($fy->status === 'closed', 'Clôturez d\'abord l\'exercice.');
        $this->assert(! $fy->allocation, 'Le résultat est déjà affecté.');
        $this->assert($date > $this->end($c, $y), 'L\'affectation se date après la fin de l\'exercice (date de l\'assemblée).');
        $this->assert(! $this->posting->locked($c, Carbon::parse($date)), 'Cette date est dans une période verrouillée.');
        $r = round((float) $fy->result, 2);
        $p = $this->allocationProposal($c, $y)['accounts'];
        $parts = ['legal_reserve' => 0.0, 'other_reserves' => 0.0, 'dividends' => 0.0, 'report' => 0.0];
        if ($r > 0) {
            foreach ($parts as $k => $_) {
                $parts[$k] = round(max(0, (float) ($d[$k] ?? 0)), 2);
            }
            $this->assert(abs(array_sum($parts) - $r) < 0.01, 'La répartition ('.number_format(array_sum($parts), 0, ',', ' ').') doit être égale au bénéfice ('.number_format($r, 0, ',', ' ').').');
            $lines = [[$p['result'], $r, 'Affectation du résultat '.$y]];
            foreach (['legal_reserve' => 'Réserve légale', 'other_reserves' => 'Autres réserves', 'dividends' => 'Dividendes à payer', 'report' => 'Report à nouveau'] as $k => $label) {
                if ($parts[$k] > 0) {
                    $lines[] = [$p[$k], -$parts[$k], $label.' '.$y];
                }
            }
        } else {
            $parts['report'] = $r;
            $lines = [[$p['report'], -$r, 'Report à nouveau débiteur '.$y], [$p['result'], $r, 'Affectation de la perte '.$y]];
        }
        DB::transaction(function () use ($c, $y, $fy, $parts, $date, $lines, $r) {
            if (abs($r) > 0.004) {
                $this->posting->postExternal($c, 'OD', $date, 'Affectation du résultat de l\'exercice '.FiscalCalendar::label($c, $y), 'AFF-'.$y, $lines, 'year_allocation', (string) $y, 'allocation');
            }
            $fy->forceFill(['allocation' => $parts, 'allocation_date' => $date])->save();
        });

        return $fy->fresh();
    }

    public function cancelAllocation(Company $c, int $y): FiscalYear
    {
        $fy = $this->year($c, $y);
        $this->assert((bool) $fy->allocation, 'Pas d\'affectation à annuler.');
        $this->posting->cancelExternal($c, 'year_allocation', (string) $y, 'allocation', 'Annulation de l\'affectation du résultat '.$y, false);
        $fy->forceFill(['allocation' => null, 'allocation_date' => null])->save();

        return $fy->fresh();
    }

    // ------------------------------------------------------------------------------------------------ liasse (DSF)

    public function liasse(Company $c, int $y): array
    {
        $from = $this->start($c, $y);
        $end = $this->end($c, $y);
        $ref = Referentials::of($c);
        $ohada = in_array($ref, ['syscohada', 'syscohada_smt', 'sycebnl'], true);
        $accounts = DB::table('compta_accounts')->where('company_id', $c->id)->pluck('name', 'code');
        $open = $this->st->balances($c, null, Carbon::parse($from)->subDay()->toDateString());
        $close = $this->st->balances($c, null, $end);
        $mov = DB::table('compta_entry_lines as l')->join('compta_entries as e', 'e.id', '=', 'l.entry_id')->join('compta_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $c->id)->whereBetween('e.date', [$from, $end])->groupBy('a.code')->selectRaw('a.code, SUM(l.debit) d, SUM(l.credit) c')->get()->keyBy('code');
        // Balance générale de l'exercice (après écriture de clôture)
        $codes = collect(array_keys($open))->merge(array_keys($close))->merge($mov->keys())->map(fn ($x) => (string) $x)->unique()->sort()->values();
        $balance = [];
        foreach ($codes as $code) {
            $o = $open[$code] ?? 0.0;
            $k = $close[$code] ?? 0.0;
            $d = (float) ($mov[$code]->d ?? 0);
            $cr = (float) ($mov[$code]->c ?? 0);
            if (abs($o) < 0.005 && abs($k) < 0.005 && $d < 0.005 && $cr < 0.005) {
                continue;
            }
            $balance[] = ['code' => $code, 'name' => $accounts[$code] ?? '', 'open_debit' => max(0, $o), 'open_credit' => max(0, -$o), 'debit' => round($d, 2), 'credit' => round($cr, 2),
                'close_debit' => max(0, round($k, 2)), 'close_credit' => max(0, round(-$k, 2))];
        }
        // Note : immobilisations et amortissements (mouvements de l'exercice)
        $fixed = [];
        foreach ($balance as $b) {
            if ($b['code'][0] !== '2') {
                continue;
            }
            $isDep = in_array(substr($b['code'], 0, 2), ['28', '29'], true);
            $fixed[] = ['code' => $b['code'], 'name' => $b['name'], 'kind' => $isDep ? 'amortissement' : 'valeur brute',
                'opening' => $isDep ? round($b['open_credit'] - $b['open_debit'], 2) : round($b['open_debit'] - $b['open_credit'], 2),
                'increase' => $isDep ? $b['credit'] : $b['debit'], 'decrease' => $isDep ? $b['debit'] : $b['credit'],
                'closing' => $isDep ? round($b['close_credit'] - $b['close_debit'], 2) : round($b['close_debit'] - $b['close_credit'], 2)];
        }
        // Note : créances et dettes à la clôture (classes de tiers)
        $tiers = array_values(array_filter(array_map(fn ($b) => ['code' => $b['code'], 'name' => $b['name'], 'debit' => $b['close_debit'], 'credit' => $b['close_credit']],
            array_filter($balance, fn ($b) => $b['code'][0] === '4' || ($ref === 'cgnc' && str_starts_with($b['code'], '34')))), fn ($x) => $x['debit'] > 0 || $x['credit'] > 0));
        // Chiffre d'affaires par mois
        $revPrefixes = $ref === 'cgnc' ? ['711', '712'] : ['70'];
        $monthExpr = DB::connection()->getDriverName() === 'sqlite' ? "strftime('%m', e.date)" : 'MONTH(e.date)';
        $byMonth = DB::table('compta_entry_lines as l')->join('compta_entries as e', 'e.id', '=', 'l.entry_id')->join('compta_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $c->id)->where('e.kind', '!=', 'closing')->whereBetween('e.date', [$from, $end])
            ->where(fn ($q) => array_map(fn ($p) => $q->orWhere('a.code', 'like', $p.'%'), $revPrefixes))
            ->groupByRaw($monthExpr)->selectRaw("$monthExpr m, SUM(l.credit - l.debit) s")->pluck('s', 'm');
        $months = [];
        $sm = FiscalCalendar::startMonth($c);
        foreach (array_map(fn ($i) => ($sm - 1 + $i) % 12 + 1, range(0, 11)) as $m) {
            $months[] = ['month' => $m, 'amount' => round((float) ($byMonth[$m] ?? $byMonth[str_pad((string) $m, 2, '0', STR_PAD_LEFT)] ?? 0), 2)];
        }
        $fy = $this->year($c, $y);
        $staff = $c->hasModule('rh') && Schema::hasTable('rh_employees') ? DB::table('rh_employees')->where('company_id', $c->id)->whereNull('deleted_at')->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $end))->where(fn ($q) => $q->whereNull('hire_date')->orWhere('hire_date', '<=', $end))->count() : null;

        return [
            'title' => $ohada ? 'Déclaration statistique et fiscale (DSF) — états financiers '.Referentials::LIST[$ref]['short'] : 'Liasse des états financiers — '.Referentials::LIST[$ref]['short'],
            'year' => $y, 'from' => $from, 'to' => $end, 'status' => $fy->status, 'referential' => ['key' => $ref] + Referentials::LIST[$ref],
            'identification' => [
                ['Dénomination sociale', $c->name], ['Numéro de compte contribuable (NCC)', $c->tax_id], ['Registre du commerce (RCCM)', $c->trade_register],
                ['Adresse', trim(($c->address ?? '').' '.($c->city ?? ''))], ['Pays', $c->country], ['Téléphone', $c->phone], ['E-mail', $c->email],
                ['Exercice', 'du '.Carbon::parse($from)->format('d/m/Y').' au '.Carbon::parse($end)->format('d/m/Y')], ['Durée (mois)', 12],
                ['Référentiel comptable', Referentials::LIST[$ref]['label']], ['Monnaie', $c->currency], ['Effectif à la clôture', $staff],
                ['Exercice clôturé', $fy->status === 'closed' ? 'oui, le '.$fy->closed_at?->format('d/m/Y') : 'non (états provisoires)'],
            ],
            'income' => $this->st->income($c, $from, $end), 'sheet' => $this->st->sheet($c, $end), 'cashflow' => $this->st->cashFlow($c, $from, $end),
            'fixed_assets' => $fixed, 'tiers' => $tiers, 'revenue_months' => $months, 'fiscal' => $this->fiscal($c, $y), 'allocation' => $this->allocationProposal($c, $y), 'balance' => $balance,
        ];
    }

    public function liasseBook(Company $c, int $y): string
    {
        $l = $this->liasse($c, $y);
        $n = fn ($v) => $v === null ? '' : round((float) $v, 2);
        $sheets = [];
        $sheets['Identification'] = [['Rubrique', 'Valeur'], array_merge([[$l['title'], '']], $l['identification']), [0]];
        $assets = [];
        $bold = [];
        foreach ($l['sheet']['assets'] as $i => $r) {
            $assets[] = [$r['ref'], $r['label'], $n($r['gross']), $n($r['amort']), $n($r['net']), $n($r['n1'])];
            if ($r['total']) {
                $bold[] = $i;
            }
        }
        $sheets['Bilan actif'] = [['Réf.', 'ACTIF', 'Brut', 'Amort. et dépréc.', 'Net N', 'Net N-1'], $assets, $bold];
        $liab = [];
        $bold = [];
        foreach ($l['sheet']['liabilities'] as $i => $r) {
            $liab[] = [$r['ref'], $r['label'], $n($r['n']), $n($r['n1'])];
            if ($r['total']) {
                $bold[] = $i;
            }
        }
        $sheets['Bilan passif'] = [['Réf.', 'PASSIF', 'Net N', 'Net N-1'], $liab, $bold];
        $inc = [];
        $bold = [];
        foreach ($l['income']['rows'] as $i => $r) {
            $inc[] = [$r['ref'], ($r['sign'] ?? '') === '-' ? '(−) '.$r['label'] : $r['label'], $n($r['n']), $n($r['n1'])];
            if ($r['total']) {
                $bold[] = $i;
            }
        }
        $sheets['Compte de résultat'] = [['Réf.', $l['income']['title'], 'Exercice N', 'Exercice N-1'], $inc, $bold];
        $cf = [];
        $bold = [];
        foreach ($l['cashflow']['rows'] as $i => $r) {
            $cf[] = [$r['ref'], $r['label'], $n($r['amount'])];
            if ($r['total']) {
                $bold[] = $i;
            }
        }
        $sheets['Flux de trésorerie'] = [['Réf.', $l['cashflow']['title'], 'Montant'], $cf, $bold];
        $sheets['Note immobilisations'] = [['Compte', 'Intitulé', 'Nature', 'Début d\'exercice', 'Augmentations', 'Diminutions', 'Fin d\'exercice'],
            array_map(fn ($r) => [$r['code'], $r['name'], $r['kind'], $n($r['opening']), $n($r['increase']), $n($r['decrease']), $n($r['closing'])], $l['fixed_assets'])];
        $sheets['Note créances et dettes'] = [['Compte', 'Intitulé', 'Solde débiteur', 'Solde créditeur'], array_map(fn ($r) => [$r['code'], $r['name'], $n($r['debit']), $n($r['credit'])], $l['tiers'])];
        $mn = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $ca = array_map(fn ($r) => [$mn[$r['month'] - 1], $n($r['amount'])], $l['revenue_months']);
        $ca[] = ['TOTAL', $n(array_sum(array_column($l['revenue_months'], 'amount')))];
        $sheets['Chiffre d\'affaires'] = [['Mois', 'Chiffre d\'affaires HT'], $ca, [12]];
        $f = $l['fiscal'];
        $fr = [['Résultat comptable avant impôt', $n($f['result_before_tax'])], ['RÉINTÉGRATIONS', '']];
        foreach ($f['reintegrations'] as $r) {
            $fr[] = ['  '.$r['label'], $n($r['amount'])];
        }
        $fr[] = ['DÉDUCTIONS', ''];
        foreach ($f['deductions'] as $r) {
            $fr[] = ['  '.$r['label'], $n(-$r['amount'])];
        }
        $fr = array_merge($fr, [['Résultat fiscal', $n($f['taxable'])], ['Impôt sur les bénéfices ('.$f['settings']['is_rate'].' %)', $n($f['is'])],
            [$f['settings']['min_label'].' ('.$f['settings']['min_rate'].' % du chiffre d\'affaires '.number_format($f['revenue'], 0, ',', ' ').')', $n($f['minimum'])],
            ['IMPÔT DÛ', $n($f['due'])], ['Impôt comptabilisé', $n($f['posted'])], ['Résultat net après impôt', $n($f['net_result'])]]);
        $sheets['Résultat fiscal'] = [['Détermination du résultat fiscal', 'Montant'], $fr, [count($fr) - 4]];
        $sheets['Balance générale'] = [['Compte', 'Intitulé', 'Solde d\'ouverture débit', 'Solde d\'ouverture crédit', 'Mouvements débit', 'Mouvements crédit', 'Solde final débit', 'Solde final crédit'],
            array_map(fn ($b) => [$b['code'], $b['name'], $n($b['open_debit']), $n($b['open_credit']), $n($b['debit']), $n($b['credit']), $n($b['close_debit']), $n($b['close_credit'])], $l['balance'])];
        $path = tempnam(sys_get_temp_dir(), 'dsf').'.xlsx';
        \App\Modules\ImportExport\Sheet::writeBook($path, $sheets);

        return $path;
    }

    public function liassePdf(Company $c, int $y): string
    {
        $l = $this->liasse($c, $y);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $m = fn ($v) => $v === null ? '' : $e(number_format((float) $v, 0, ',', ' '));
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('Yomara ERP');
        $pdf->SetTitle($l['title'].' '.$y);
        $pdf->setPrintHeader(false);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->SetFont('dejavusans', '', 8.5);
        $head = fn (string $t) => '<table cellpadding="3"><tr><td width="60%"><b style="font-size:11pt">'.$e($c->name).'</b><br>NCC '.$e($c->tax_id ?? '—').' · RCCM '.$e($c->trade_register ?? '—').'</td><td width="40%" align="right"><b style="font-size:11pt;color:#1f6feb">'.$e($t).'</b><br>Exercice '.FiscalCalendar::label($c, $y).'</td></tr></table><br>';
        // Page de garde
        $pdf->AddPage();
        $html = '<br><br><h1 style="text-align:center;color:#1f2a44">'.$e($l['title']).'</h1><p style="text-align:center;font-size:12pt">Exercice du '.Carbon::parse($l['from'])->format('d/m/Y').' au '.Carbon::parse($l['to'])->format('d/m/Y').'</p><br>'
            .'<table cellpadding="5" border="0.3">';
        foreach ($l['identification'] as [$k, $v]) {
            $html .= '<tr><td width="45%" bgcolor="#f2f5fa">'.$e($k).'</td><td width="55%"><b>'.$e($v ?? '—').'</b></td></tr>';
        }
        $html .= '</table>'.($l['status'] !== 'closed' ? '<p style="color:#b45309"><b>États provisoires :</b> l\'exercice n\'est pas clôturé.</p>' : '');
        $pdf->writeHTML($html, true, false, true, false, '');
        // Bilan
        $pdf->AddPage();
        $html = $head($l['sheet']['title']).'<table cellpadding="3" border="0.3"><tr bgcolor="#1f2a44" color="#ffffff" style="font-weight:bold"><td width="7%">Réf.</td><td width="41%">ACTIF</td><td width="13%" align="right">Brut</td><td width="13%" align="right">Amort.</td><td width="13%" align="right">Net N</td><td width="13%" align="right">Net N-1</td></tr>';
        foreach ($l['sheet']['assets'] as $r) {
            $html .= '<tr'.($r['total'] ? ' bgcolor="#eef2f8" style="font-weight:bold"' : '').'><td>'.$e($r['ref']).'</td><td>'.$e($r['label']).'</td><td align="right">'.$m($r['gross']).'</td><td align="right">'.$m($r['amort']).'</td><td align="right">'.$m($r['net']).'</td><td align="right">'.$m($r['n1']).'</td></tr>';
        }
        $html .= '</table><br><table cellpadding="3" border="0.3"><tr bgcolor="#1f2a44" color="#ffffff" style="font-weight:bold"><td width="7%">Réf.</td><td width="67%">PASSIF</td><td width="13%" align="right">Net N</td><td width="13%" align="right">Net N-1</td></tr>';
        foreach ($l['sheet']['liabilities'] as $r) {
            $html .= '<tr'.($r['total'] ? ' bgcolor="#eef2f8" style="font-weight:bold"' : '').'><td>'.$e($r['ref']).'</td><td>'.$e($r['label']).'</td><td align="right">'.$m($r['n']).'</td><td align="right">'.$m($r['n1']).'</td></tr>';
        }
        $pdf->writeHTML($html.'</table>', true, false, true, false, '');
        // Compte de résultat
        $pdf->AddPage();
        $html = $head($l['income']['title']).'<table cellpadding="3" border="0.3"><tr bgcolor="#1f2a44" color="#ffffff" style="font-weight:bold"><td width="8%">Réf.</td><td width="62%">Libellé</td><td width="15%" align="right">Exercice N</td><td width="15%" align="right">Exercice N-1</td></tr>';
        foreach ($l['income']['rows'] as $r) {
            $html .= '<tr'.($r['total'] ? ' bgcolor="#eef2f8" style="font-weight:bold"' : '').'><td>'.$e($r['ref']).'</td><td>'.(($r['sign'] ?? '') === '-' ? '(−) ' : '').$e($r['label']).'</td><td align="right">'.$m($r['n']).'</td><td align="right">'.$m($r['n1']).'</td></tr>';
        }
        $pdf->writeHTML($html.'</table>', true, false, true, false, '');
        // Flux de trésorerie
        $pdf->AddPage();
        $html = $head($l['cashflow']['title']).'<table cellpadding="3" border="0.3"><tr bgcolor="#1f2a44" color="#ffffff" style="font-weight:bold"><td width="8%">Réf.</td><td width="72%">Libellé</td><td width="20%" align="right">Montant</td></tr>';
        foreach ($l['cashflow']['rows'] as $r) {
            $html .= '<tr'.($r['total'] ? ' bgcolor="#eef2f8" style="font-weight:bold"' : '').'><td>'.$e($r['ref']).'</td><td>'.$e($r['label']).'</td><td align="right">'.$m($r['amount']).'</td></tr>';
        }
        $pdf->writeHTML($html.'</table>', true, false, true, false, '');
        // Notes et résultat fiscal
        $pdf->AddPage();
        $html = $head('Notes annexes').'<h3>Immobilisations et amortissements</h3><table cellpadding="3" border="0.3"><tr bgcolor="#eeeeee" style="font-weight:bold"><td width="10%">Compte</td><td width="32%">Intitulé</td><td width="14%" align="right">Début</td><td width="14%" align="right">Augmentations</td><td width="14%" align="right">Diminutions</td><td width="16%" align="right">Fin</td></tr>';
        foreach ($l['fixed_assets'] as $r) {
            $html .= '<tr><td>'.$e($r['code']).'</td><td>'.$e($r['name']).'</td><td align="right">'.$m($r['opening']).'</td><td align="right">'.$m($r['increase']).'</td><td align="right">'.$m($r['decrease']).'</td><td align="right">'.$m($r['closing']).'</td></tr>';
        }
        if (! $l['fixed_assets']) {
            $html .= '<tr><td colspan="6">Aucune immobilisation.</td></tr>';
        }
        $f = $l['fiscal'];
        $html .= '</table><br><h3>Détermination du résultat fiscal</h3><table cellpadding="3" border="0.3" width="70%"><tr><td width="70%">Résultat comptable avant impôt</td><td width="30%" align="right">'.$m($f['result_before_tax']).'</td></tr>';
        foreach ($f['reintegrations'] as $r) {
            $html .= '<tr><td>+ '.$e($r['label']).'</td><td align="right">'.$m($r['amount']).'</td></tr>';
        }
        foreach ($f['deductions'] as $r) {
            $html .= '<tr><td>− '.$e($r['label']).'</td><td align="right">'.$m($r['amount']).'</td></tr>';
        }
        $html .= '<tr style="font-weight:bold"><td>Résultat fiscal</td><td align="right">'.$m($f['taxable']).'</td></tr><tr><td>Impôt sur les bénéfices ('.$e($f['settings']['is_rate']).' %)</td><td align="right">'.$m($f['is']).'</td></tr>'
            .'<tr><td>'.$e($f['settings']['min_label']).'</td><td align="right">'.$m($f['minimum']).'</td></tr><tr style="font-weight:bold" bgcolor="#eef2f8"><td>Impôt dû</td><td align="right">'.$m($f['due']).'</td></tr></table>';
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output('', 'S');
    }
}
