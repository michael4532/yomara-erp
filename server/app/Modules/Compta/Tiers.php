<?php

namespace App\Modules\Compta;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/** Comptes de tiers : code auxiliaire de chaque client / fournisseur, balance et grand livre auxiliaires. */
class Tiers
{
    public const SIDES = ['customers' => ['41', 'CLI', 'Clients'], 'suppliers' => ['40', 'FRS', 'Fournisseurs']];

    /** Attribue un code auxiliaire aux tiers qui n'en ont pas (CLI00001, FRS00001, TIE00001 pour les deux). */
    public function ensureCodes(Company $c): void
    {
        $missing = DB::table('partners')->where('company_id', $c->id)->whereNull('deleted_at')->whereNull('aux_code')->orderBy('created_at')->orderBy('id')->get(['id', 'type']);
        if ($missing->isEmpty()) {
            return;
        }
        DB::transaction(function () use ($c, $missing) {
            $next = [];
            foreach ($missing as $p) {
                $prefix = match ($p->type) { 'supplier' => 'FRS', 'both' => 'TIE', default => 'CLI' };
                if (! isset($next[$prefix])) {
                    $last = DB::table('partners')->where('company_id', $c->id)->where('aux_code', 'like', $prefix.'%')->lockForUpdate()->pluck('aux_code')
                        ->map(fn ($code) => (int) substr($code, 3))->max() ?? 0;
                    $next[$prefix] = $last;
                }
                $next[$prefix]++;
                DB::table('partners')->where('id', $p->id)->update(['aux_code' => $prefix.str_pad((string) $next[$prefix], 5, '0', STR_PAD_LEFT)]);
            }
        });
    }

    protected function lines(Company $c, string $side)
    {
        return DB::table('compta_entry_lines as l')
            ->join('compta_entries as e', 'e.id', '=', 'l.entry_id')
            ->join('compta_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $c->id)->whereNotNull('l.partner_id')
            ->where('a.code', 'like', Referentials::tiers(Referentials::of($c))[$side].'%');
    }

    /** Balance auxiliaire : solde d'ouverture, mouvements et solde de chaque tiers. */
    public function balance(Company $c, string $side, string $from, string $to, ?string $q = null): array
    {
        $this->ensureCodes($c);
        $open = (clone $this->lines($c, $side))->where('e.date', '<', $from)->groupBy('l.partner_id')
            ->selectRaw('l.partner_id, SUM(l.debit - l.credit) as s')->pluck('s', 'partner_id');
        $mov = (clone $this->lines($c, $side))->whereBetween('e.date', [$from, $to])->groupBy('l.partner_id')
            ->selectRaw('l.partner_id, SUM(l.debit) as d, SUM(l.credit) as c, MAX(e.date) as last')->get()->keyBy('partner_id');
        $ids = $open->keys()->merge($mov->keys())->unique();
        $partners = DB::table('partners')->whereIn('id', $ids)->when($q, fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%$q%")->orWhere('aux_code', 'like', "%$q%")))
            ->get(['id', 'name', 'aux_code', 'phone', 'email'])->keyBy('id');
        $rows = [];
        foreach ($partners as $id => $p) {
            $o = round((float) ($open[$id] ?? 0), 2);
            $d = round((float) ($mov[$id]->d ?? 0), 2);
            $cr = round((float) ($mov[$id]->c ?? 0), 2);
            $close = round($o + $d - $cr, 2);
            if (abs($o) < 0.005 && abs($d) < 0.005 && abs($cr) < 0.005) {
                continue;
            }
            $rows[] = ['partner_id' => $id, 'aux_code' => $p->aux_code, 'name' => $p->name, 'phone' => $p->phone, 'email' => $p->email, 'opening' => $o, 'debit' => $d, 'credit' => $cr,
                'balance' => $close, 'due' => $side === 'customers' ? max(0, $close) : max(0, -$close), 'last' => $mov[$id]->last ?? null];
        }
        usort($rows, fn ($a, $b) => strcmp((string) $a['aux_code'], (string) $b['aux_code']));
        $col = fn ($k) => round(array_sum(array_column($rows, $k)), 2);

        return ['side' => $side, 'label' => self::SIDES[$side][2], 'from' => $from, 'to' => $to, 'rows' => $rows,
            'totals' => ['opening' => $col('opening'), 'debit' => $col('debit'), 'credit' => $col('credit'), 'balance' => $col('balance'), 'due' => $col('due')]];
    }

    /** Grand livre d'un tiers, solde progressif. */
    public function ledger(Company $c, string $side, string $partnerId, string $from, string $to): array
    {
        $this->ensureCodes($c);
        $p = DB::table('partners')->where('company_id', $c->id)->where('id', $partnerId)->first(['id', 'name', 'aux_code', 'phone', 'email']);
        if (! $p) {
            throw new \DomainException('Tiers introuvable.');
        }
        $base = (clone $this->lines($c, $side))->where('l.partner_id', $partnerId);
        $running = $opening = round((float) (clone $base)->where('e.date', '<', $from)->sum(DB::raw('l.debit - l.credit')), 2);
        $rows = (clone $base)->whereBetween('e.date', [$from, $to])->join('compta_journals as j', 'j.id', '=', 'e.journal_id')
            ->orderBy('e.date')->orderBy('e.id')->get(['e.id as entry_id', 'e.date', 'e.number', 'e.reference', 'j.code as journal', 'a.code as account', 'l.label', 'l.debit', 'l.credit'])
            ->map(function ($r) use (&$running) {
                $running = round($running + (float) $r->debit - (float) $r->credit, 2);

                return ['entry_id' => $r->entry_id, 'date' => $r->date, 'number' => $r->number, 'reference' => $r->reference, 'journal' => $r->journal, 'account' => $r->account,
                    'label' => $r->label, 'debit' => round((float) $r->debit, 2), 'credit' => round((float) $r->credit, 2), 'balance' => $running];
            })->values();

        return ['partner' => (array) $p, 'side' => $side, 'from' => $from, 'to' => $to, 'opening' => $opening, 'rows' => $rows,
            'debit' => round($rows->sum('debit'), 2), 'credit' => round($rows->sum('credit'), 2), 'closing' => $running];
    }
}
