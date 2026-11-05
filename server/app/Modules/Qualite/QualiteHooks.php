<?php

namespace App\Modules\Qualite;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Qualité : contrôles, non-conformités, actions correctives et préventives. */
class QualiteHooks
{
    public const NC_STATUSES = ['open' => ['Ouverte', '#DC2626'], 'analysis' => ['Analyse des causes', '#D97706'], 'action' => ['Actions en cours', '#1F6FEB'],
        'verification' => ['Vérification', '#9333EA'], 'closed' => ['Clôturée', '#16A34A'], 'cancelled' => ['Sans suite', '#94A3B8']];

    public const SOURCES = ['internal' => 'Contrôle interne', 'customer' => 'Réclamation client', 'supplier' => 'Fournisseur', 'audit' => 'Audit', 'incident' => 'Incident / accident', 'staff' => 'Remontée du personnel'];

    public const SEVERITIES = ['minor' => ['Mineure', '#D97706'], 'major' => ['Majeure', '#DC2626'], 'critical' => ['Critique', '#7F1D1D']];

    public const CAUSES = ['method' => 'Méthode', 'labour' => 'Main-d\'œuvre', 'material' => 'Matière / fourniture', 'machine' => 'Matériel / machine', 'environment' => 'Milieu / environnement', 'measure' => 'Mesure'];

    public const ACTION_KINDS = ['corrective' => 'Corrective', 'preventive' => 'Préventive', 'improvement' => 'Amélioration'];

    public const ACTION_STATUSES = ['todo' => ['À faire', '#64748B'], 'in_progress' => ['En cours', '#D97706'], 'done' => ['Réalisée', '#1F6FEB'], 'verified' => ['Vérifiée efficace', '#16A34A'], 'cancelled' => ['Abandonnée', '#94A3B8']];

    public const SCOPES = ['reception' => 'Réception', 'production' => 'Production', 'finished' => 'Produit fini', 'hygiene' => 'Hygiène', 'safety' => 'Sécurité', 'service' => 'Service client', 'audit' => 'Audit interne'];

    public const FREQUENCIES = ['each' => 'À chaque lot / livraison', 'daily' => 'Chaque jour', 'weekly' => 'Chaque semaine', 'monthly' => 'Chaque mois'];

    public const RESULTS = ['pending' => ['À remplir', '#64748B'], 'ok' => ['Conforme', '#16A34A'], 'reserve' => ['Conforme avec réserve', '#D97706'], 'nok' => ['Non conforme', '#DC2626']];

    public static function criteria(?string $text): array
    {
        return array_values(array_filter(array_map(fn ($l) => trim(ltrim(trim($l), '-•*')), preg_split('/\r?\n/', (string) $text)), fn ($l) => $l !== ''));
    }

    protected static function days(?string $date): ?int
    {
        return $date ? (int) now()->startOfDay()->diffInDays(Carbon::parse($date)->startOfDay(), false) : null;
    }

    public function present(string $res, array $row): array
    {
        if ($res === 'nonconformities') {
            $a = DB::table('qa_actions')->where('nc_id', $row['id'])->where('status', '!=', 'cancelled');
            $row['actions_total'] = (clone $a)->count();
            $row['actions_done'] = (clone $a)->whereIn('status', ['done', 'verified'])->count();
            $row['alert'] = null;
            if (! in_array($row['status'], ['closed', 'cancelled'], true)) {
                $d = self::days($row['due_date']);
                $row['alert'] = $d !== null && $d < 0 ? 'En retard de '.abs($d).' j' : ($row['actions_total'] ? $row['actions_done'].'/'.$row['actions_total'].' action(s) faite(s)' : ($row['status'] === 'open' ? 'À analyser' : null));
            }
        } elseif ($res === 'actions') {
            $d = self::days($row['due_date']);
            $row['alert'] = in_array($row['status'], ['todo', 'in_progress'], true) && $d !== null ? ($d < 0 ? 'En retard de '.abs($d).' j' : ($d <= 3 ? 'Échéance dans '.$d.' j' : null)) : null;
        } elseif ($res === 'inspections') {
            $row['answers'] = is_string($row['answers']) ? (json_decode($row['answers'], true) ?: []) : ($row['answers'] ?? []);
        } elseif ($res === 'plans') {
            $i = DB::table('qa_inspections')->where('plan_id', $row['id'])->where('result', '!=', 'pending')->where('done_at', '>=', now()->subDays(30));
            $n = (clone $i)->count();
            $ok = (clone $i)->whereIn('result', ['ok', 'reserve'])->count();
            $row['criteria_count'] = count(self::criteria($row['criteria']));
            $row['stats'] = $n ? $n.' contrôle(s) · '.round($ok / $n * 100).' % conformes' : null;
        }

        return $row;
    }

    public function saving(string $res, array $data, ?array $old, User $u): array
    {
        $c = app('tenant');
        $manage = $u->hasPermission('qualite.manage');
        if ($res === 'nonconformities') {
            if ($old && ! $manage && ! $u->hasPermission('qualite.inspect') && ! ((int) $old['reporter_id'] === $u->id && $old['status'] === 'open') && (int) $old['owner_id'] !== $u->id) {
                throw new \DomainException('Vous ne pouvez modifier que vos déclarations encore ouvertes.');
            }
            if (! $old) {
                if (! $manage) {
                    $data['reporter_id'] = $u->id;
                    unset($data['status']);
                }
                $data['status'] ??= 'open';
                $data['number'] = 'NC-'.now()->format('Y').'-'.str_pad((string) (DB::table('qa_nonconformities')->where('company_id', $c->id)->whereYear('created_at', now()->year)->count() + 1), 4, '0', STR_PAD_LEFT);
                $data['due_date'] ??= now()->addDays(['critical' => 2, 'major' => 7][$data['severity'] ?? 'minor'] ?? 30)->toDateString();
            }
            $status = $data['status'] ?? $old['status'] ?? 'open';
            if ($status === 'closed' && ($old['status'] ?? null) !== 'closed') {
                $open = $old ? DB::table('qa_actions')->where('nc_id', $old['id'])->whereIn('status', ['todo', 'in_progress'])->count() : 0;
                if ($open) {
                    throw new \DomainException($open.' action(s) ne sont pas encore réalisées : terminez-les ou abandonnez-les avant de clôturer.');
                }
                if (! ($data['effective'] ?? $old['effective'] ?? false)) {
                    throw new \DomainException('Vérifiez d\'abord que les actions ont été efficaces (case « Actions efficaces ») avant de clôturer.');
                }
                $data['closed_on'] = now()->toDateString();
            } elseif ($status !== 'closed' && ($old['status'] ?? null) === 'closed') {
                $data['closed_on'] = null;
            }
        } elseif ($res === 'actions') {
            if (($data['status'] ?? null) && in_array($data['status'], ['done', 'verified'], true) && empty($data['done_on']) && empty($old['done_on'])) {
                $data['done_on'] = now()->toDateString();
            }
            if (($data['status'] ?? null) === 'verified' && ! $manage) {
                throw new \DomainException('Seul le responsable qualité vérifie l\'efficacité d\'une action.');
            }
            if (isset($data['due_date']) && $data['due_date'] !== ($old['due_date'] ?? null)) {
                $data['reminded_on'] = null;
            }
        } elseif ($res === 'plans' && array_key_exists('criteria', $data) && ! self::criteria($data['criteria'])) {
            throw new \DomainException('Indiquez au moins un critère à vérifier.');
        } elseif ($res === 'inspections' && ! $old) {
            $p = DB::table('qa_plans')->where('company_id', $c->id)->where('id', $data['plan_id'])->first() ?: throw new \DomainException('Plan de contrôle introuvable.');
            $data['title'] = $p->title;
        }

        return $data;
    }

    public function saved(string $res, array $row, ?array $old, User $u): void
    {
        $c = app('tenant');
        if ($res === 'nonconformities') {
            if (! $old && in_array($row['severity'], ['major', 'critical'], true)) {
                AppNotification::send($c->id, array_diff($this->managers($c->id), [$u->id]), 'qualite', 'Non-conformité '.mb_strtolower(self::SEVERITIES[$row['severity']][0]).' : '.Str::limit($row['title'], 80),
                    $row['number'].' — '.(self::SOURCES[$row['source']] ?? '').', signalée par '.$u->name.'.', 'qualite', (string) $row['id'], $u->id);
            }
            if ($row['owner_id'] && (int) $row['owner_id'] !== (int) ($old['owner_id'] ?? 0) && (int) $row['owner_id'] !== $u->id) {
                AppNotification::send($c->id, [(int) $row['owner_id']], 'qualite', 'Non-conformité à traiter', $row['number'].' — '.Str::limit($row['title'], 90).($row['due_date'] ? ' (avant le '.date('d/m/Y', strtotime($row['due_date'])).')' : '').'.', 'qualite', (string) $row['id'], $u->id);
            }
        } elseif ($res === 'actions') {
            if ($row['owner_id'] && (int) $row['owner_id'] !== (int) ($old['owner_id'] ?? 0) && (int) $row['owner_id'] !== $u->id) {
                AppNotification::send($c->id, [(int) $row['owner_id']], 'qualite', 'Action qualité confiée', Str::limit($row['title'], 100).($row['due_date'] ? ' — échéance le '.date('d/m/Y', strtotime($row['due_date'])) : '').'.', 'qualite', (string) $row['id'], $u->id);
            }
            // première action : la non-conformité passe « actions en cours » ; toutes faites → « vérification »
            if ($row['nc_id'] && ($nc = DB::table('qa_nonconformities')->where('id', $row['nc_id'])->first()) && ! in_array($nc->status, ['closed', 'cancelled'], true)) {
                $left = DB::table('qa_actions')->where('nc_id', $nc->id)->whereIn('status', ['todo', 'in_progress'])->count();
                $next = $left ? 'action' : 'verification';
                if (in_array($nc->status, ['open', 'analysis', 'action'], true) && $nc->status !== $next) {
                    DB::table('qa_nonconformities')->where('id', $nc->id)->update(['status' => $next, 'updated_at' => now()]);
                    if ($next === 'verification' && $nc->owner_id && (int) $nc->owner_id !== $u->id) {
                        AppNotification::send($c->id, [(int) $nc->owner_id], 'qualite', 'Actions réalisées : vérifier l\'efficacité', $nc->number.' — '.Str::limit($nc->title, 90).'.', 'qualite', (string) $nc->id, $u->id);
                    }
                }
            }
        }
    }

    public function deleting(string $res, array $row, User $u): void
    {
        if ($res === 'nonconformities') {
            DB::table('qa_actions')->where('nc_id', $row['id'])->delete();
            DB::table('qa_inspections')->where('nc_id', $row['id'])->update(['nc_id' => null]);
        } elseif ($res === 'plans' && DB::table('qa_inspections')->where('plan_id', $row['id'])->exists()) {
            throw new \DomainException('Ce plan a déjà servi : désactivez-le plutôt que de le supprimer.');
        }
    }

    protected function managers(int $companyId): array
    {
        return User::where('company_id', $companyId)->where('is_active', true)->get()->filter(fn ($x) => $x->hasPermission('qualite.manage'))->pluck('id')->all();
    }

    /** Enregistre les réponses critère par critère : taux de conformité, résultat, non-conformité si besoin. */
    public function answer(Company $c, User $u, int $id, array $answers, ?string $notes): array
    {
        $i = (array) (DB::table('qa_inspections')->where('company_id', $c->id)->where('id', $id)->lockForUpdate()->first() ?: throw new \DomainException('Contrôle introuvable.'));
        $p = DB::table('qa_plans')->where('id', $i['plan_id'])->first();
        $crit = self::criteria($p->criteria);
        $list = [];
        $ok = $nok = $na = 0;
        $failed = [];
        foreach ($crit as $k => $label) {
            $a = $answers[$k] ?? [];
            $v = in_array($a['value'] ?? null, ['ok', 'nok', 'na'], true) ? $a['value'] : null;
            if ($v === null) {
                throw new \DomainException('Répondez à tous les critères (« '.Str::limit($label, 60).' »).');
            }
            $v === 'ok' ? $ok++ : ($v === 'nok' ? $nok++ : $na++);
            if ($v === 'nok') {
                $failed[] = $label.(empty($a['comment']) ? '' : ' : '.$a['comment']);
            }
            $list[] = ['label' => $label, 'value' => $v, 'comment' => isset($a['comment']) ? Str::limit((string) $a['comment'], 300, '') : null];
        }
        $score = $ok + $nok ? (int) round($ok / ($ok + $nok) * 100) : 100;
        $result = $nok === 0 ? 'ok' : ($score >= (int) $p->threshold ? 'reserve' : 'nok');
        $set = ['answers' => json_encode($list, JSON_UNESCAPED_UNICODE), 'score' => $score, 'result' => $result, 'updated_at' => now()];
        if ($notes !== null) {
            $set['notes'] = $notes;
        }
        if ($result === 'nok' && $p->auto_nc && ! $i['nc_id']) {
            $n = 'NC-'.now()->format('Y').'-'.str_pad((string) (DB::table('qa_nonconformities')->where('company_id', $c->id)->whereYear('created_at', now()->year)->count() + 1), 4, '0', STR_PAD_LEFT);
            $set['nc_id'] = DB::table('qa_nonconformities')->insertGetId([
                'company_id' => $c->id, 'created_by' => $u->id, 'owner_id' => $p->owner_id, 'number' => $n, 'title' => 'Contrôle non conforme : '.$p->title.($i['subject'] ? ' — '.$i['subject'] : ''),
                'source' => 'internal', 'severity' => $score < 50 ? 'major' : 'minor', 'status' => 'open', 'detected_on' => substr((string) $i['done_at'], 0, 10), 'reporter_id' => $u->id,
                'partner_id' => $i['partner_id'], 'product' => $i['subject'], 'inspection_id' => $i['id'], 'description' => "Critères non conformes :\n- ".implode("\n- ", $failed),
                'due_date' => now()->addDays($score < 50 ? 7 : 30)->toDateString(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            AppNotification::send($c->id, $p->owner_id ? [(int) $p->owner_id] : $this->managers($c->id), 'qualite', 'Contrôle non conforme', $p->title.($i['subject'] ? ' — '.$i['subject'] : '').' : '.$score.' % ('.$n.' ouverte).', 'qualite', (string) $set['nc_id'], $u->id);
        }
        DB::table('qa_inspections')->where('id', $id)->update($set);

        return (array) DB::table('qa_inspections')->find($id);
    }

    /** Rappels : actions en retard (chaque semaine), contrôles périodiques non faits. */
    public function alerts(Company $c): int
    {
        $n = 0;
        foreach (DB::table('qa_actions')->where('company_id', $c->id)->whereIn('status', ['todo', 'in_progress'])->whereNotNull('due_date')->where('due_date', '<=', now()->toDateString())->get() as $a) {
            if ($a->reminded_on && Carbon::parse($a->reminded_on)->gt(now()->subDays(7))) {
                continue;
            }
            AppNotification::send($c->id, $a->owner_id ? [(int) $a->owner_id] : $this->managers($c->id), 'qualite', 'Action qualité à réaliser', Str::limit($a->title, 100).' — échéance le '.date('d/m/Y', strtotime($a->due_date)).'.', 'qualite', (string) $a->id);
            DB::table('qa_actions')->where('id', $a->id)->update(['reminded_on' => now()->toDateString()]);
            $n++;
        }
        $period = ['daily' => now()->startOfDay(), 'weekly' => now()->startOfWeek(), 'monthly' => now()->startOfMonth()];
        foreach (DB::table('qa_plans')->where('company_id', $c->id)->where('active', true)->whereIn('frequency', array_keys($period))->get() as $p) {
            $since = $period[$p->frequency];
            // rappel en fin de période (après 15 h pour un contrôle quotidien, à partir du jeudi / du 20 sinon)
            $late = match ($p->frequency) {
                'daily' => now()->hour >= 15,
                'weekly' => now()->dayOfWeekIso >= 4,
                default => now()->day >= 20,
            };
            if (! $late || ($p->reminded_on && Carbon::parse($p->reminded_on)->gte($since)) || DB::table('qa_inspections')->where('plan_id', $p->id)->where('done_at', '>=', $since)->exists()) {
                continue;
            }
            AppNotification::send($c->id, $p->owner_id ? [(int) $p->owner_id] : $this->managers($c->id), 'qualite', 'Contrôle à faire', $p->title.' ('.mb_strtolower(self::FREQUENCIES[$p->frequency]).') n\'a pas encore été réalisé.', 'qualite', (string) $p->id);
            DB::table('qa_plans')->where('id', $p->id)->update(['reminded_on' => now()->toDateString()]);
            $n++;
        }

        return $n;
    }

    public function dashboard(Company $c): array
    {
        $nc = DB::table('qa_nonconformities')->where('company_id', $c->id);
        $open = (clone $nc)->whereNotIn('status', ['closed', 'cancelled']);
        $ins = DB::table('qa_inspections')->where('company_id', $c->id)->where('result', '!=', 'pending')->where('done_at', '>=', now()->subDays(30));
        $n = (clone $ins)->count();
        $closed = (clone $nc)->where('status', 'closed')->whereNotNull('closed_on')->where('closed_on', '>=', now()->subYear()->toDateString())->get(['detected_on', 'closed_on']);

        return [
            'open' => (clone $open)->count(),
            'critical' => (clone $open)->whereIn('severity', ['major', 'critical'])->count(),
            'complaints' => (clone $nc)->where('source', 'customer')->where('detected_on', '>=', now()->subDays(30)->toDateString())->count(),
            'actions_late' => DB::table('qa_actions')->where('company_id', $c->id)->whereIn('status', ['todo', 'in_progress'])->where('due_date', '<', now()->toDateString())->count(),
            'actions_open' => DB::table('qa_actions')->where('company_id', $c->id)->whereIn('status', ['todo', 'in_progress'])->count(),
            'inspections' => $n,
            'conformity' => $n ? round((clone $ins)->whereIn('result', ['ok', 'reserve'])->count() / $n * 100) : null,
            'cost_year' => (float) (clone $nc)->where('detected_on', '>=', now()->startOfYear()->toDateString())->sum('cost'),
            'closing_days' => $closed->count() ? round($closed->avg(fn ($x) => Carbon::parse($x->detected_on)->diffInDays(Carbon::parse($x->closed_on))), 1) : null,
            'by_source' => (clone $nc)->where('detected_on', '>=', now()->subYear()->toDateString())->selectRaw('source, count(*) as n')->groupBy('source')->orderByDesc('n')->get()
                ->map(fn ($x) => ['label' => self::SOURCES[$x->source] ?? $x->source, 'n' => (int) $x->n])->all(),
        ];
    }
}
