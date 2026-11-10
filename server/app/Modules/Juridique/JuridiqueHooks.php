<?php

namespace App\Modules\Juridique;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Rh\DocRenderer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Règles du service juridique : échéances et préavis des contrats, rédaction depuis un modèle, alertes. */
class JuridiqueHooks
{
    public const KINDS = ['customer' => 'Contrat client', 'supplier' => 'Contrat fournisseur', 'service' => 'Prestation de services', 'employment' => 'Contrat de travail',
        'lease' => 'Bail', 'partnership' => 'Partenariat', 'nda' => 'Confidentialité (NDA)', 'distribution' => 'Distribution / agence', 'loan' => 'Prêt / financement',
        'insurance' => 'Assurance', 'other' => 'Autre'];

    public const CONTRACT_STATUSES = ['draft' => ['Brouillon', '#64748B'], 'negotiation' => ['En négociation', '#D97706'], 'to_sign' => ['À signer', '#9333EA'],
        'active' => ['En vigueur', '#16A34A'], 'expired' => ['Échu', '#94A3B8'], 'terminated' => ['Résilié', '#DC2626']];

    public const PERIODICITY = ['once' => 'Unique', 'monthly' => 'Mensuel', 'quarterly' => 'Trimestriel', 'yearly' => 'Annuel'];

    public const CASE_KINDS = ['commercial' => 'Commercial', 'labour' => 'Social (travail)', 'recovery' => 'Recouvrement', 'criminal' => 'Pénal',
        'administrative' => 'Administratif', 'tax' => 'Fiscal', 'property' => 'Immobilier', 'other' => 'Autre'];

    public const CASE_STATUSES = ['open' => ['Ouvert', '#1F6FEB'], 'amicable' => ['Phase amiable', '#0891B2'], 'proceedings' => ['En procédure', '#D97706'],
        'deliberation' => ['En délibéré', '#9333EA'], 'won' => ['Gagné', '#16A34A'], 'lost' => ['Perdu', '#DC2626'], 'settled' => ['Transigé', '#16A34A'], 'closed' => ['Clos', '#94A3B8']];

    public const ROLES = ['plaintiff' => 'Demandeur', 'defendant' => 'Défendeur', 'advice' => 'Conseil / précontentieux'];

    public const RISKS = ['low' => ['Faible', '#16A34A'], 'medium' => ['Moyen', '#D97706'], 'high' => ['Élevé', '#DC2626']];

    public const DEADLINE_KINDS = ['hearing' => 'Audience', 'filing' => 'Dépôt de conclusions', 'service' => 'Signification', 'renewal' => 'Renouvellement',
        'termination' => 'Préavis de résiliation', 'declaration' => 'Déclaration / formalité', 'meeting' => 'Rendez-vous', 'other' => 'Autre'];

    /** Date à laquelle il faut décider (échéance moins le préavis). */
    public static function decisionDate(array $c): ?Carbon
    {
        if (empty($c['end_date'])) {
            return null;
        }

        return Carbon::parse($c['end_date'])->subDays((int) ($c['notice_days'] ?? 0));
    }

    public function present(string $res, array $row): array
    {
        if ($res === 'contracts') {
            $row['alert'] = null;
            $row['decision_date'] = ($d = self::decisionDate($row)) ? $d->toDateString() : null;
            if ($d && in_array($row['status'], ['active', 'to_sign', 'negotiation'], true)) {
                $days = (int) now()->startOfDay()->diffInDays($d, false);
                $end = (int) now()->startOfDay()->diffInDays(Carbon::parse($row['end_date']), false);
                $row['days_left'] = $end;
                $row['alert'] = match (true) {
                    $end < 0 => 'Échu depuis '.abs($end).' j',
                    $days < 0 => ($row['tacit_renewal'] ? 'Reconduit sauf résiliation — préavis dépassé' : 'Préavis dépassé, échéance dans '.$end.' j'),
                    $days <= 60 => ($row['tacit_renewal'] ? 'Résilier avant le ' : 'Renouveler avant le ').$d->format('d/m/Y'),
                    default => null,
                };
            }
        } elseif ($res === 'cases') {
            $next = DB::table('jr_deadlines')->where('company_id', $row['company_id'])->where('case_id', $row['id'])->where('done', false)->where('due_at', '>=', now()->startOfDay())->orderBy('due_at')->first();
            $row['next_deadline'] = $next ? date('d/m/Y H:i', strtotime($next->due_at)).' · '.$next->title : null;
        }

        return $row;
    }

    public function saving(string $res, array $data, ?array $old, User $u): array
    {
        if ($res === 'contracts' && isset($data['end_date']) && $data['end_date'] !== ($old['end_date'] ?? null)) {
            $data['alerted_on'] = null;
        }

        return $data;
    }

    /** Contrat de travail relié à un salarié : il le reçoit dans « Mon espace » dès qu'il est à signer ou en vigueur. */
    public const EMPLOYEE_VISIBLE = ['to_sign', 'active', 'expired', 'terminated'];

    public function saved(string $res, array $row, ?array $old, User $u): void
    {
        if ($res !== 'contracts' || ($row['kind'] ?? null) !== 'employment' || empty($row['employee_id']) || ! Schema::hasTable('rh_employees')) {
            return;
        }
        $shown = in_array($row['status'] ?? null, ['to_sign', 'active'], true);
        $was = $old && ($old['employee_id'] ?? null) === $row['employee_id'] && in_array($old['status'] ?? null, ['to_sign', 'active'], true) && ($old['status'] ?? null) === $row['status'];
        if (! $shown || $was) {
            return;
        }
        $userId = DB::table('rh_employees')->where('company_id', $row['company_id'])->where('id', $row['employee_id'])->value('user_id');
        if ($userId) {
            AppNotification::send((int) $row['company_id'], [$userId], 'juridique', $row['status'] === 'to_sign' ? 'Votre contrat de travail est prêt à signer' : 'Votre contrat de travail est disponible',
                '« '.$row['title'].' » : consultez-le dans Mon espace.', 'mon_espace', (string) $row['id'], $u->id);
        }
    }

    /** Variables d'un contrat pour les modèles. */
    public function fields(Company $c, array $contract): array
    {
        $na = '[à compléter]';
        $s = $c->settings ?? [];
        $partner = $contract['partner_id'] ? DB::table('partners')->where('company_id', $c->id)->where('id', $contract['partner_id'])->first() : null;
        $money = fn ($v) => $v === null ? $na : number_format((float) $v, 0, ',', ' ').' '.(($c->currency ?? 'XOF') === 'XOF' ? 'F CFA' : $c->currency);
        $f = [
            'societe' => $c->name, 'societe_adresse' => trim(($c->address ? $c->address.', ' : '').($c->city ?? '')) ?: $na, 'societe_ville' => $c->city ?: 'Abidjan',
            'societe_rccm' => $c->trade_register ?: $na, 'societe_ncc' => $c->tax_id ?: $na,
            'representant' => $s['legal_signatory'] ?? ($s['rh_signatory'] ?? $na), 'qualite_representant' => $s['legal_signatory_title'] ?? ($s['rh_signatory_title'] ?? 'Gérant'),
            'tiers' => $partner->name ?? ($contract['counterparty'] ?: $na),
            'tiers_adresse' => $partner ? (trim(($partner->address ?? '').' '.($partner->city ?? '')) ?: $na) : $na,
            'tiers_rccm' => $partner->trade_register ?? $na,
            'objet' => $contract['title'], 'reference' => $contract['reference'] ?: $na,
            'date_debut' => DocRenderer::longDate($contract['start_date']), 'date_fin' => DocRenderer::longDate($contract['end_date']),
            'montant' => $money($contract['amount']), 'periodicite' => mb_strtolower(self::PERIODICITY[$contract['periodicity'] ?? ''] ?? $na),
            'preavis' => $contract['notice_days'] ? $contract['notice_days'].' jours' : $na,
            'date' => DocRenderer::longDate(now()), 'ville' => $c->city ?: 'Abidjan',
        ];
        if ($contract['employee_id'] && $c->hasModule('rh') && class_exists(\App\Modules\Rh\Models\Employee::class)) {
            $e = \App\Modules\Rh\Models\Employee::withoutGlobalScopes()->where('company_id', $c->id)->find($contract['employee_id']);
            if ($e) {
                $f = DocRenderer::fields($c, $e, []) + $f;
                $f['tiers'] = trim($e->last_name.' '.$e->first_name);
            }
        }

        return $f;
    }

    public function draft(Company $c, int $contractId, int $templateId): array
    {
        $contract = (array) DB::table('jr_contracts')->where('company_id', $c->id)->where('id', $contractId)->first() ?: throw new \DomainException('Contrat introuvable.');
        $tpl = DB::table('jr_templates')->where('company_id', $c->id)->where('id', $templateId)->first() ?: throw new \DomainException('Modèle introuvable.');
        $body = DocRenderer::render($tpl->body ?? '', $this->fields($c, $contract));
        DB::table('jr_contracts')->where('id', $contractId)->update(['body' => $body, 'template_id' => $tpl->id, 'updated_at' => now()]);

        return ['body' => $body];
    }

    protected function lawyers(int $companyId): array
    {
        return User::where('company_id', $companyId)->where('is_active', true)->get()->filter(fn ($x) => $x->hasPermission('juridique.manage'))->pluck('id')->all();
    }

    /** Alertes quotidiennes : contrats à renouveler ou résilier, contrats échus, audiences proches. */
    public function alerts(Company $c): int
    {
        $n = 0;
        foreach (DB::table('jr_contracts')->where('company_id', $c->id)->whereIn('status', ['active', 'to_sign', 'negotiation'])->whereNotNull('end_date')->get() as $k) {
            $row = (array) $k;
            $d = self::decisionDate($row);
            if (Carbon::parse($row['end_date'])->lt(now()->startOfDay()) && $row['status'] === 'active' && ! $row['tacit_renewal']) {
                DB::table('jr_contracts')->where('id', $k->id)->update(['status' => 'expired', 'updated_at' => now()]);
                AppNotification::send($c->id, $k->owner_id ? [$k->owner_id] : $this->lawyers($c->id), 'juridique', 'Contrat échu', '« '.$k->title.' » est arrivé à échéance le '.date('d/m/Y', strtotime($k->end_date)).'.', 'juridique', (string) $k->id);
                $n++;
            } elseif ($d && $d->lte(now()->addDays(30)) && ! $k->alerted_on) {
                $what = $row['tacit_renewal'] ? 'résilier (sinon reconduction tacite)' : 'renouveler';
                AppNotification::send($c->id, $k->owner_id ? [$k->owner_id] : $this->lawyers($c->id), 'juridique', 'Contrat à '.($row['tacit_renewal'] ? 'résilier ou laisser reconduire' : 'renouveler'),
                    '« '.$k->title.' » : décision avant le '.$d->format('d/m/Y').' pour '.$what.' (échéance le '.date('d/m/Y', strtotime($k->end_date)).').', 'juridique', (string) $k->id);
                DB::table('jr_contracts')->where('id', $k->id)->update(['alerted_on' => now()->toDateString()]);
                $n++;
            }
        }
        foreach (DB::table('jr_deadlines')->where('company_id', $c->id)->where('done', false)->whereBetween('due_at', [now(), now()->addDays(3)->endOfDay()])->get() as $dl) {
            $soon = Carbon::parse($dl->due_at)->lte(now()->addDay()->endOfDay());
            if ($dl->reminded_at && (! $soon || Carbon::parse($dl->reminded_at)->gte(now()->subHours(20)))) {
                continue;
            }
            AppNotification::send($c->id, $dl->owner_id ? [$dl->owner_id] : $this->lawyers($c->id), 'juridique', ($soon ? 'Demain : ' : 'Dans 3 jours : ').(self::DEADLINE_KINDS[$dl->kind] ?? 'Échéance'),
                $dl->title.' — '.date('d/m/Y à H:i', strtotime($dl->due_at)).($dl->place ? ', '.$dl->place : '').'.', 'juridique', (string) $dl->id);
            DB::table('jr_deadlines')->where('id', $dl->id)->update(['reminded_at' => now()]);
            $n++;
        }
        // contrats tacitement reconduits : échéance dépassée → prolongée de la même durée
        foreach (DB::table('jr_contracts')->where('company_id', $c->id)->where('status', 'active')->where('tacit_renewal', true)->where('end_date', '<', now()->toDateString())->get() as $k) {
            $start = $k->start_date ? Carbon::parse($k->start_date) : null;
            $end = Carbon::parse($k->end_date);
            $months = $start ? max(1, (int) round($start->diffInMonths($end))) : 12;
            DB::table('jr_contracts')->where('id', $k->id)->update(['start_date' => $end->copy()->addDay()->toDateString(), 'end_date' => $end->copy()->addMonths($months)->toDateString(), 'alerted_on' => null, 'updated_at' => now()]);
        }

        return $n;
    }

    public function dashboard(Company $c): array
    {
        $contracts = collect(DB::table('jr_contracts')->where('company_id', $c->id)->get())->map(fn ($x) => $this->present('contracts', (array) $x));
        $cases = DB::table('jr_cases')->where('company_id', $c->id)->whereNotIn('status', ['won', 'lost', 'settled', 'closed']);

        return [
            'active' => $contracts->where('status', 'active')->count(),
            'to_sign' => $contracts->whereIn('status', ['to_sign', 'negotiation'])->count(),
            'alerts' => $contracts->filter(fn ($x) => $x['alert'] !== null)->count(),
            'contract_value' => (float) $contracts->where('status', 'active')->sum('amount'),
            'open_cases' => (clone $cases)->count(),
            'at_stake' => (float) (clone $cases)->sum('amount_at_stake'),
            'high_risk' => (clone $cases)->where('risk', 'high')->count(),
            'deadlines_week' => DB::table('jr_deadlines')->where('company_id', $c->id)->where('done', false)->whereBetween('due_at', [now()->startOfDay(), now()->addDays(7)->endOfDay()])->count(),
            'upcoming' => DB::table('jr_deadlines')->where('company_id', $c->id)->where('done', false)->where('due_at', '>=', now()->startOfDay())->orderBy('due_at')->limit(6)->get(['id', 'title', 'kind', 'due_at', 'place']),
            'renewals' => $contracts->filter(fn ($x) => $x['alert'] !== null)->sortBy('decision_date')->take(6)->map(fn ($x) => ['id' => $x['id'], 'title' => $x['title'], 'alert' => $x['alert'], 'end_date' => $x['end_date']])->values(),
        ];
    }

    /** Fiches salariés : les contrats de travail enregistrés au juridique (pour le dossier RH). */
    public static function forEmployee(int $companyId, string $employeeId): array
    {
        if (! Schema::hasTable('jr_contracts')) {
            return [];
        }

        return DB::table('jr_contracts')->where('company_id', $companyId)->where('employee_id', $employeeId)->orderByDesc('start_date')
            ->get(['id', 'title', 'kind', 'status', 'start_date', 'end_date', 'amount'])->map(fn ($x) => (array) $x)->all();
    }
}
