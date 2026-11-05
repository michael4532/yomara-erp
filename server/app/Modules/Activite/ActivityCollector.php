<?php

namespace App\Modules\Activite;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rassemble tout ce qu'une personne a fait dans l'ERP sur une période, module par module
 * (ventes, CRM, visites, publications, contrats, sorties, tâches, tickets, interventions, réunions, développement…),
 * plus les activités qu'elle a ajoutées elle-même (« Ajouter au rapport »).
 */
class ActivityCollector
{
    /** Ordre et libellés des rubriques du rapport. */
    public const SECTIONS = [
        'ventes' => 'Ventes', 'commercial' => 'Terrain commercial', 'crm' => 'Relation client (CRM)', 'contenus' => 'Marketing & communication', 'juridique' => 'Juridique',
        'dev' => 'Développement', 'projets' => 'Projets', 'tickets' => 'Demandes internes', 'helpdesk' => 'Service client', 'interventions' => 'Interventions',
        'flotte' => 'Déplacements & parc automobile', 'achats' => 'Achats', 'fonds' => 'Appels de fonds', 'agenda' => 'Réunions & rendez-vous', 'autres' => 'Autres activités',
    ];

    protected array $cols = [];

    protected int $cid;

    protected function t(string $table)
    {
        $q = DB::table($table)->where($table.'.company_id', $this->cid);
        if ($this->cols[$table] ??= Schema::hasColumn($table, 'deleted_at')) {
            $q->whereNull($table.'.deleted_at');
        }

        return $q;
    }

    protected function has(Company $c, string $module, string $table): bool
    {
        return ($module === 'core' || $c->hasModule($module)) && Schema::hasTable($table);
    }

    protected static function money(?float $v, Company $c): string
    {
        return $v === null ? '' : number_format($v, 0, ',', ' ').' '.(in_array($c->currency, ['XOF', 'XAF', null], true) ? 'F CFA' : $c->currency);
    }

    protected static function num($v): string
    {
        $f = round((float) $v, 2);

        return fmod($f, 1.0) == 0.0 ? (string) (int) $f : str_replace('.', ',', (string) $f);
    }

    protected static function d($v): string
    {
        return $v ? Carbon::parse($v)->toDateString() : now()->toDateString();
    }

    /** @return array<int, array{key:string,section:string,date:string,title:string,details:?string,amount:?float,minutes:?int,manual?:bool,entry_id?:int}> */
    public function collect(Company $c, User $u, string $from, string $to): array
    {
        $this->cid = $c->id;
        $uid = $u->id;
        $end = $to.' 23:59:59';
        $items = [];
        $add = function (string $section, string $key, $date, string $title, ?string $details = null, ?float $amount = null, ?int $minutes = null) use (&$items) {
            $items[$key] = ['key' => $key, 'section' => $section, 'date' => self::d($date), 'title' => $title, 'details' => $details ? trim($details) : null, 'amount' => $amount, 'minutes' => $minutes];
        };
        $partners = fn ($ids) => DB::table('partners')->whereIn('id', collect($ids)->filter()->unique()->values())->pluck('name', 'id');

        // Ventes et achats : documents validés par la personne
        $docTypes = ['quote' => 'Devis', 'order' => 'Commande client', 'invoice' => 'Facture', 'credit_note' => 'Avoir', 'delivery' => 'Bon de livraison', 'proforma' => 'Facture proforma',
            'purchase_order' => 'Bon de commande', 'bill' => 'Facture fournisseur', 'receipt' => 'Ticket de caisse'];
        foreach (['ventes' => 'sale', 'achats' => 'purchase'] as $mod => $dir) {
            if (! $this->has($c, $mod, 'trade_documents')) {
                continue;
            }
            $docs = $this->t('trade_documents')->where('direction', $dir)->where('user_id', $uid)->where('status', '!=', 'draft')->where('type', '!=', 'receipt')
                ->whereBetween('date', [$from, $to])->orderBy('date')->limit(300)->get(['id', 'type', 'number', 'partner_id', 'partner_name', 'date', 'total', 'status']);
            $names = $partners($docs->pluck('partner_id'));
            foreach ($docs as $x) {
                $add($mod, $mod.'.doc:'.$x->id, $x->date, ($docTypes[$x->type] ?? ucfirst($x->type)).' '.($x->number ?? '').' — '.($names[$x->partner_id] ?? $x->partner_name ?? 'client comptoir'),
                    $x->status === 'cancelled' ? 'Annulé' : null, (float) $x->total);
            }
        }
        if ($this->has($c, 'pos', 'trade_documents')) {
            $pos = $this->t('trade_documents')->where('direction', 'sale')->where('type', 'receipt')->where('user_id', $uid)->where('status', 'validated')->whereBetween('date', [$from, $to])
                ->selectRaw('date, COUNT(*) as n, SUM(total) as total')->groupBy('date')->get();
            foreach ($pos as $p) {
                $add('ventes', 'pos:'.$p->date, $p->date, 'Caisse : '.$p->n.' vente(s) au comptoir', null, (float) $p->total);
            }
        }

        // CRM : activités réalisées, opportunités gagnées
        if ($this->has($c, 'crm', 'crm_activities')) {
            $types = ['call' => 'Appel', 'meeting' => 'Rendez-vous', 'email' => 'E-mail', 'whatsapp' => 'WhatsApp', 'task' => 'Tâche', 'visit' => 'Visite'];
            foreach ($this->t('crm_activities')->where('owner_id', $uid)->where('done', true)->whereBetween('done_at', [$from, $end])->orderBy('done_at')->limit(200)->get() as $a) {
                $add('crm', 'crm_act:'.$a->id, $a->done_at, ($types[$a->type] ?? 'Activité').' : '.$a->subject, $a->notes);
            }
            foreach ($this->t('crm_leads')->where('owner_id', $uid)->where('status', 'won')->whereBetween('won_at', [$from, $end])->get() as $l) {
                $add('crm', 'crm_won:'.$l->id, $l->won_at, 'Opportunité gagnée : '.$l->title, null, (float) $l->expected_revenue);
            }
            foreach ($this->t('crm_leads')->where('owner_id', $uid)->whereBetween('created_at', [$from, $end])->get() as $l) {
                $add('crm', 'crm_new:'.$l->id, $l->created_at, 'Nouvelle opportunité : '.$l->title, $l->company_name, (float) $l->expected_revenue ?: null);
            }
        }

        // Terrain commercial : visites et comptes rendus
        if ($this->has($c, 'commercial', 'cm_visits')) {
            $out = \App\Modules\Commercial\CommercialHooks::OUTCOMES;
            $visits = $this->t('cm_visits')->where('user_id', $uid)->whereIn('status', ['done', 'missed'])->whereBetween('planned_at', [$from, $end])->orderBy('planned_at')->get();
            $names = $partners($visits->pluck('partner_id'));
            foreach ($visits as $v) {
                $who = $names[$v->partner_id] ?? $v->prospect;
                $add('commercial', 'commercial.visits:'.$v->id, $v->planned_at, ($v->status === 'missed' ? 'Visite (client absent)' : 'Visite').' — '.$who.' : '.$v->title,
                    trim(($v->outcome ? 'Résultat : '.($out[$v->outcome][0] ?? $v->outcome).'. ' : '').($v->report ?? '').($v->next_step ? ' Prochaine étape : '.$v->next_step.($v->next_date ? ' le '.date('d/m', strtotime($v->next_date)) : '').'.' : '')),
                    $v->amount !== null ? (float) $v->amount : null);
            }
            foreach ($this->t('cm_tours')->where('user_id', $uid)->whereIn('status', ['done', 'in_progress'])->whereBetween('date', [$from, $to])->get() as $t) {
                $add('commercial', 'commercial.tours:'.$t->id, $t->date, 'Tournée : '.$t->title.($t->zone ? ' ('.$t->zone.')' : ''), $t->summary);
            }
        }

        // Marketing : publications, validations, actions, veille, presse
        if ($this->has($c, 'contenus', 'mc_posts')) {
            $nets = \App\Modules\Contenus\ContenusHooks::NETWORKS;
            foreach ($this->t('mc_posts')->where('owner_id', $uid)->where('status', 'published')->whereBetween('scheduled_at', [$from, $end])->orderBy('scheduled_at')->get() as $p) {
                $n = collect(json_decode($p->networks ?? '[]', true) ?: [])->map(fn ($k) => $nets[$k] ?? $k)->implode(', ');
                $add('contenus', 'contenus.posts:'.$p->id, $p->scheduled_at, 'Publication : '.$p->title.($n ? ' ('.$n.')' : ''),
                    $p->reach ? 'Portée '.number_format($p->reach, 0, ',', ' ').($p->engagements ? ', '.number_format($p->engagements, 0, ',', ' ').' interactions' : '') : null);
            }
            foreach ($this->t('mc_posts')->where('owner_id', $uid)->whereIn('status', ['writing', 'review', 'approved'])->whereBetween('updated_at', [$from, $end])->get() as $p) {
                $add('contenus', 'contenus.posts:'.$p->id, $p->updated_at, 'Préparation de la publication : '.$p->title, 'Statut : '.(\App\Modules\Contenus\ContenusHooks::POST_STATUSES[$p->status][0] ?? $p->status));
            }
            foreach ($this->t('mc_posts')->where('validator_id', $uid)->where('owner_id', '!=', $uid)->whereBetween('validated_at', [$from, $end])->get() as $p) {
                $add('contenus', 'mc_val:'.$p->id, $p->validated_at, 'Validation de la publication : '.$p->title);
            }
            foreach ($this->t('mc_actions')->where('owner_id', $uid)->where(fn ($q) => $q->whereBetween('start_date', [$from, $to])->orWhereBetween('updated_at', [$from, $end]))->get() as $a) {
                $add('contenus', 'contenus.actions:'.$a->id, $a->start_date ?? $a->updated_at, 'Action marketing : '.$a->title, $a->results, $a->spent !== null ? (float) $a->spent : null);
            }
            foreach ($this->t('mc_observations')->where('owner_id', $uid)->whereBetween('observed_on', [$from, $to])->get() as $o) {
                $add('contenus', 'contenus.observations:'.$o->id, $o->observed_on, 'Veille concurrentielle : '.$o->title, $o->response ? 'Réponse proposée : '.$o->response : $o->details);
            }
            foreach ($this->t('mc_press')->where('owner_id', $uid)->whereBetween('date', [$from, $to])->get() as $p) {
                $add('contenus', 'contenus.press:'.$p->id, $p->date, (\App\Modules\Contenus\ContenusHooks::PRESS_KINDS[$p->kind] ?? 'Presse').' : '.$p->title, $p->media);
            }
        }

        // Juridique
        if ($this->has($c, 'juridique', 'jr_contracts')) {
            foreach ($this->t('jr_contracts')->where(fn ($q) => $q->where('created_by', $uid)->orWhere('owner_id', $uid))->whereBetween('updated_at', [$from, $end])->get() as $k) {
                $add('juridique', 'juridique.contracts:'.$k->id, $k->updated_at, (Carbon::parse($k->created_at)->gte($from) ? 'Contrat enregistré : ' : 'Contrat suivi : ').$k->title,
                    'Statut : '.(\App\Modules\Juridique\JuridiqueHooks::CONTRACT_STATUSES[$k->status][0] ?? $k->status));
            }
            foreach ($this->t('jr_deadlines')->where('owner_id', $uid)->whereBetween('due_at', [$from, $end])->get() as $d) {
                $add('juridique', 'juridique.deadlines:'.$d->id, $d->due_at, (\App\Modules\Juridique\JuridiqueHooks::DEADLINE_KINDS[$d->kind] ?? 'Échéance').' : '.$d->title, ($d->done ? 'Fait' : 'Non fait').($d->notes ? ' — '.$d->notes : ''));
            }
            foreach ($this->t('jr_cases')->where(fn ($q) => $q->where('created_by', $uid)->orWhere('owner_id', $uid))->whereBetween('updated_at', [$from, $end])->get() as $k) {
                $add('juridique', 'juridique.cases:'.$k->id, $k->updated_at, 'Dossier : '.$k->title, 'Étape : '.(\App\Modules\Juridique\JuridiqueHooks::CASE_STATUSES[$k->status][0] ?? $k->status));
            }
        }

        // Développement
        if ($this->has($c, 'dev', 'dev_items')) {
            foreach ($this->t('dev_items')->where('assignee_id', $uid)->where('status', 'done')->whereBetween('done_at', [$from, $end])->orderBy('done_at')->get() as $i) {
                $add('dev', 'dev.items:'.$i->id, $i->done_at, 'Terminé : '.trim(($i->number ?? '').' '.$i->title), trim((\App\Modules\Dev\DevHooks::KINDS[$i->kind][0] ?? '').($i->points ? ' · '.self::num($i->points).' pts' : '').($i->pr_url ? ' · '.$i->pr_url : '')));
            }
            foreach ($this->t('dev_items')->where('assignee_id', $uid)->whereIn('status', ['in_progress', 'review', 'testing'])->whereBetween('updated_at', [$from, $end])->get() as $i) {
                $add('dev', 'dev.items:'.$i->id, $i->updated_at, 'En cours : '.trim(($i->number ?? '').' '.$i->title), 'Statut : '.(\App\Modules\Dev\DevHooks::STATUSES[$i->status][0] ?? $i->status));
            }
            foreach ($this->t('dev_releases')->where('owner_id', $uid)->where('status', 'released')->whereBetween('date', [$from, $to])->get() as $r) {
                $add('dev', 'dev.releases:'.$r->id, $r->date, 'Mise en production de la version '.$r->title);
            }
        }

        // Projets : tâches terminées et temps passé
        if ($this->has($c, 'projets', 'prj_tasks')) {
            foreach ($this->t('prj_tasks')->where('assignee_id', $uid)->whereBetween('done_at', [$from, $end])->get() as $t) {
                $add('projets', 'prj_task:'.$t->id, $t->done_at, 'Tâche terminée : '.$t->title);
            }
            $ts = $this->t('prj_timesheets')->where('user_id', $uid)->whereBetween('date', [$from, $to])->get();
            if ($ts->isNotEmpty()) {
                $tasks = DB::table('prj_tasks')->whereIn('id', $ts->pluck('task_id')->filter())->pluck('title', 'id');
                foreach ($ts->groupBy('task_id') as $tid => $g) {
                    $add('projets', 'prj_time:'.($tid ?: 'x'), $g->max('date'), 'Temps passé : '.($tasks[$tid] ?? 'projet').' — '.self::num($g->sum('hours')).' h',
                        $g->pluck('description')->filter()->unique()->implode(' ; '), null, (int) round($g->sum('hours') * 60));
                }
            }
        }

        // Demandes internes, service client, interventions
        if ($this->has($c, 'tickets', 'tk_tickets')) {
            foreach ($this->t('tk_tickets')->where('assignee_id', $uid)->whereBetween('done_at', [$from, $end])->get() as $t) {
                $add('tickets', 'tk:'.$t->id, $t->done_at, 'Demande traitée '.$t->number.' : '.$t->subject);
            }
        }
        if ($this->has($c, 'helpdesk', 'hd_tickets')) {
            foreach ($this->t('hd_tickets')->where('assignee_id', $uid)->whereBetween('resolved_at', [$from, $end])->get() as $t) {
                $add('helpdesk', 'hd:'.$t->id, $t->resolved_at, 'Ticket client résolu '.$t->number.' : '.$t->subject);
            }
        }
        if ($this->has($c, 'interventions', 'it_interventions')) {
            $its = $this->t('it_interventions')->where('technician_id', $uid)->where('status', 'done')->whereBetween('finished_at', [$from, $end])->get();
            $names = $partners($its->pluck('partner_id'));
            foreach ($its as $i) {
                $add('interventions', 'it:'.$i->id, $i->finished_at, 'Intervention '.($i->number ?? '').' — '.($names[$i->partner_id] ?? '').' : '.$i->title, $i->report, null, $i->worked_minutes);
            }
        }

        // Parc automobile
        if ($this->has($c, 'flotte', 'fl_trips')) {
            $veh = DB::table('fl_vehicles')->where('company_id', $c->id)->pluck('title', 'id');
            foreach ($this->t('fl_trips')->where(fn ($q) => $q->where('driver_id', $uid)->orWhere('requester_id', $uid))->where('status', 'returned')->whereBetween('back_at', [$from, $end])->get() as $t) {
                $add('flotte', 'flotte.trips:'.$t->id, $t->out_at, 'Déplacement : '.$t->title.($t->destination ? ' ('.$t->destination.')' : ''),
                    trim(($veh[$t->vehicle_id] ?? '').($t->back_km !== null ? ' · '.max(0, $t->back_km - $t->out_km).' km' : '').($t->report ? ' · '.$t->report : '')));
            }
            $decided = $this->t('fl_trips')->where('decided_by', $uid)->whereBetween('updated_at', [$from, $end])->count();
            if ($decided) {
                $add('flotte', 'fl_decided', $to, 'Demandes de véhicule traitées : '.$decided);
            }
        }

        // Appels de fonds
        if ($this->has($c, 'fonds', 'af_requests')) {
            foreach ($this->t('af_requests')->where('requester_id', $uid)->whereBetween('submitted_at', [$from, $end])->get() as $r) {
                $add('fonds', 'af_req:'.$r->id, $r->submitted_at, 'Appel de fonds '.$r->number.' : '.$r->title, null, (float) $r->total_requested);
            }
            foreach ($this->t('af_requests')->where('approver_id', $uid)->whereBetween('decided_at', [$from, $end])->get() as $r) {
                $add('fonds', 'af_dec:'.$r->id, $r->decided_at, 'Décision sur l\'appel de fonds '.$r->number.' : '.$r->title, null, (float) $r->total_approved);
            }
        }

        // Réunions de l'agenda
        if ($this->has($c, 'agenda', 'ag_events')) {
            foreach ($this->t('ag_events')->where('status', '!=', 'cancelled')->whereBetween('starts_at', [$from, $end])->whereIn('category', ['meeting', 'appointment', 'training', 'travel'])->orderBy('starts_at')->get() as $e) {
                $att = json_decode($e->attendee_ids ?? '[]', true) ?: [];
                if ((int) $e->owner_id === $uid || in_array($uid, array_map('intval', $att), true)) {
                    $add('agenda', 'ag:'.$e->id, $e->starts_at, 'Réunion : '.$e->title, $e->location);
                }
            }
        }

        // Activités ajoutées par la personne (bouton « Ajouter au rapport » ou saisie libre)
        foreach (DB::table('ra_entries')->where('company_id', $c->id)->where('user_id', $uid)->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('id')->get() as $e) {
            $key = $e->source_module && $e->source_id ? $e->source_module.'.'.$e->source_type.':'.$e->source_id : 'entry:'.$e->id;
            $section = isset(self::SECTIONS[$e->source_module ?? '']) ? $e->source_module : 'autres';
            unset($items[$key]);
            $items['entry:'.$e->id] = ['key' => 'entry:'.$e->id, 'section' => $section, 'date' => $e->date, 'title' => $e->title, 'details' => $e->details, 'amount' => null, 'minutes' => $e->minutes, 'manual' => true, 'entry_id' => $e->id];
        }

        $order = array_flip(array_keys(self::SECTIONS));

        return collect($items)->sortBy([fn ($a, $b) => ($order[$a['section']] ?? 99) <=> ($order[$b['section']] ?? 99), fn ($a, $b) => strcmp($a['date'], $b['date'])])->values()->all();
    }
}
