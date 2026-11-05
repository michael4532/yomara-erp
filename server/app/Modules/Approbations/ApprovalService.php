<?php

namespace App\Modules\Approbations;

use App\Models\AppNotification;
use App\Models\User;
use App\Modules\Approbations\Models\ApprovalRequest;
use App\Modules\Approbations\Models\Decision;
use App\Modules\Approbations\Models\Delegation;
use App\Modules\Approbations\Models\Rule;
use App\Modules\Core\Models\TradeDocument;
use App\Modules\Core\Models\TradeDocumentLine;
use App\Services\EntityWriter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Circuits d'approbation.
 *
 * Un document qui correspond à un circuit n'est pas refusé quand on le valide : il reste en brouillon
 * et part en approbation (même saisi hors ligne). Chaque étape est accordée par l'un des approbateurs
 * (ou par tous) ; après la dernière, le document est validé automatiquement si le circuit le prévoit.
 * Une note de frais envoyée part en approbation de la même façon et devient « approuvée » à la fin.
 */
class ApprovalService
{
    public const TARGETS = [
        'sale.quote' => 'Devis', 'sale.order' => 'Commande client', 'sale.invoice' => 'Facture client', 'sale.credit_note' => 'Avoir client',
        'purchase.rfq' => 'Demande de prix', 'purchase.purchase_order' => 'Bon de commande', 'purchase.supplier_invoice' => 'Facture fournisseur',
        'expense' => 'Note de frais',
    ];

    /** Qui reçoit circuits et demandes à la synchronisation. */
    public const READERS = 'ventes.view|ventes.manage|achats.view|achats.manage|achats.request|notes_frais.self|notes_frais.approve|notes_frais.manage|approbations.view|approbations.manage';

    /** Approbation implicite en cours (validation du document par un approbateur de toutes les étapes). */
    public static bool $finalizing = false;

    public function enabled(): bool
    {
        return app()->bound('tenant') && app('tenant')->hasModule('approbations');
    }

    // ------------------------------------------------------------------ sujet & circuit

    /** Cible, montant HT (monnaie de la société), remise maximale, tiers, catégorie, libellé. */
    public function subject(Model $m): ?array
    {
        if ($m instanceof TradeDocument) {
            $target = $m::DIRECTION.'.'.$m->type;
            if (! isset(self::TARGETS[$target])) {
                return null;
            }
            $discount = (float) ($m->lines()->max('discount_percent') ?? 0);
            $amount = $m->toBase((float) $m->subtotal);
            $cur = app('tenant')->currency;

            return ['target' => $target, 'amount' => $amount, 'discount' => $discount, 'partner_id' => $m->partner_id, 'category_id' => null,
                'label' => self::TARGETS[$target].' '.($m->number ?: 'brouillon').' · '.($m->partner_name ?: 'sans tiers').' · '.$this->money($amount, $cur).' HT'.($discount > 0 ? ' · remise '.rtrim(rtrim(number_format($discount, 2, ',', ' '), '0'), ',').' %' : '')];
        }
        if ($m instanceof \App\Modules\NotesFrais\Models\Expense) {
            $emp = \App\Modules\Rh\Models\Employee::find($m->employee_id);
            $cur = app('tenant')->currency;

            return ['target' => 'expense', 'amount' => (float) $m->amount, 'discount' => 0.0, 'partner_id' => null, 'category_id' => $m->category_id,
                'label' => 'Note de frais · '.trim(($emp?->first_name ?? '').' '.($emp?->last_name ?? '')).' · '.Str::limit((string) $m->description, 60).' · '.$this->money((float) $m->amount, $cur)];
        }

        return null;
    }

    public function money(float $v, string $cur): string
    {
        return number_format($v, TradeDocumentLine::decimalsFor($cur), ',', ' ').' '.(in_array($cur, ['XOF', 'XAF'], true) ? 'F CFA' : $cur);
    }

    public function match(array $s): ?Rule
    {
        foreach (Rule::where('active', true)->orderBy('sort')->orderByDesc('min_amount')->get() as $r) {
            if (! in_array($s['target'], $r->targets ?? [], true)) {
                continue;
            }
            if ($r->min_amount !== null && $s['amount'] < $r->min_amount - 0.004) {
                continue;
            }
            if ($r->max_amount !== null && $s['amount'] > $r->max_amount + 0.004) {
                continue;
            }
            if ($r->discount_over !== null && $s['discount'] <= $r->discount_over) {
                continue;
            }
            if ($r->partner_ids && ! in_array((string) $s['partner_id'], $r->partner_ids, true)) {
                continue;
            }
            if ($r->category_ids && ! in_array((string) $s['category_id'], $r->category_ids, true)) {
                continue;
            }

            return $r;
        }

        return null;
    }

    /** Dernière approbation encore valable (montant et remise non augmentés depuis). */
    public function approvedFor(Model $m, array $s): ?ApprovalRequest
    {
        $r = ApprovalRequest::where('record_id', $m->getKey())->where('status', 'approved')->latest('decided_at')->first();

        return $r && $s['amount'] <= $r->amount + 0.01 && $s['discount'] <= $r->discount + 0.001 ? $r : null;
    }

    // ------------------------------------------------------------------ documents

    /**
     * Appelé à la validation d'un document. Renvoie vrai si la validation est différée
     * (le document reste en brouillon, en attente d'approbation).
     */
    public function interceptDocument(TradeDocument $doc): bool
    {
        if (! $this->enabled() || self::$finalizing) {
            return false;
        }
        $user = auth()->user();
        if (! $user || (property_exists($doc, 'systemWrite') && $doc::$systemWrite)) {
            return false; // écritures automatiques (abonnements, caisse…) : pas de circuit
        }
        $s = $this->subject($doc);
        if (! $s || ! ($rule = $this->match($s)) || $this->approvedFor($doc, $s)) {
            return false;
        }

        return $this->submit($doc, $s, $rule, $user);
    }

    /** Crée (ou conserve) la demande. Renvoie vrai si l'objet doit attendre. */
    private function submit(Model $m, array $s, Rule $rule, User $user): bool
    {
        $pending = ApprovalRequest::where('record_id', $m->getKey())->where('status', 'pending')->first();
        if ($pending) {
            if ($s['amount'] <= $pending->amount + 0.01 && $s['discount'] <= $pending->discount + 0.001) {
                $pending->forceFill(['label' => $s['label'], 'amount' => $s['amount'], 'discount' => $s['discount']])->save();

                return true;
            }
            // Montant ou remise revus à la hausse : le circuit repart du début.
            $pending->forceFill(['status' => 'cancelled', 'decided_at' => now(), 'note' => 'Remplacée : montant ou remise modifiés'])->save();
        }

        $req = new ApprovalRequest([
            'rule_id' => $rule->id, 'rule_name' => $rule->name, 'target' => $s['target'], 'record_id' => $m->getKey(), 'label' => $s['label'],
            'amount' => $s['amount'], 'discount' => $s['discount'], 'currency' => app('tenant')->currency, 'requested_by' => $user->id,
            'status' => 'pending', 'steps' => $rule->steps, 'auto_validate' => $rule->auto_validate, 'reminder_hours' => $rule->reminder_hours,
            'current_step' => 0, 'step_started_at' => now(),
        ]);
        $req->id = (string) Str::uuid();
        $req->company_id = app('tenant')->id;

        // Approbateur de toutes les étapes : accord implicite, sans attente.
        if ($this->canApproveAll($req, $user)) {
            $req->forceFill(['status' => 'approved', 'decided_at' => now(), 'current_step' => count($req->steps) - 1])->save();
            Decision::create(['request_id' => $req->id, 'step' => 0, 'user_id' => $user->id, 'decision' => 'implicit', 'comment' => 'Validé par un approbateur de toutes les étapes']);

            return false;
        }
        $req->save();
        Decision::create(['request_id' => $req->id, 'step' => 0, 'user_id' => $user->id, 'decision' => 'submitted']);
        $this->notifyStep($req);

        return true;
    }

    // ------------------------------------------------------------------ notes de frais

    /** Après l'envoi d'une note de frais : ouverture du circuit si une règle s'applique. */
    public function expenseSubmitted(\App\Modules\NotesFrais\Models\Expense $e): void
    {
        $user = auth()->user();
        if (! $this->enabled() || ! $user) {
            return;
        }
        $s = $this->subject($e);
        if ($s && ($rule = $this->match($s))) {
            $req = ApprovalRequest::where('record_id', $e->id)->where('status', 'pending')->first();
            $req?->forceFill(['status' => 'cancelled', 'decided_at' => now(), 'note' => 'Note de frais renvoyée'])->save();
            $new = new ApprovalRequest([
                'rule_id' => $rule->id, 'rule_name' => $rule->name, 'target' => 'expense', 'record_id' => $e->id, 'label' => $s['label'], 'amount' => $s['amount'],
                'discount' => 0, 'currency' => app('tenant')->currency, 'requested_by' => $user->id, 'status' => 'pending', 'steps' => $rule->steps,
                'auto_validate' => true, 'reminder_hours' => $rule->reminder_hours, 'current_step' => 0, 'step_started_at' => now(),
            ]);
            $new->id = (string) Str::uuid();
            $new->company_id = app('tenant')->id;
            $new->save();
            Decision::create(['request_id' => $new->id, 'step' => 0, 'user_id' => $user->id, 'decision' => 'submitted']);
            $this->notifyStep($new);
        }
    }

    /** Une décision directe sur une note de frais en circuit est refusée (elle se prend dans « Approbations »). */
    public function expensePending(string $id): ?ApprovalRequest
    {
        return $this->enabled() ? ApprovalRequest::where('record_id', $id)->where('status', 'pending')->first() : null;
    }

    public function expenseDraft(string $id): void
    {
        ApprovalRequest::where('record_id', $id)->where('status', 'pending')->get()
            ->each(fn ($r) => $r->forceFill(['status' => 'cancelled', 'decided_at' => now(), 'note' => 'Reprise en brouillon par le demandeur'])->save());
    }

    // ------------------------------------------------------------------ approbateurs

    /** Approbateurs « titulaires » d'une étape (hors demandeur), escalade comprise. */
    public function baseApprovers(ApprovalRequest $r, int $step): Collection
    {
        $s = $r->steps[$step] ?? null;
        if (! $s) {
            return collect();
        }
        $roles = $s['roles'] ?? [];
        if (! empty($s['escalate_after_hours']) && $step === $r->current_step && $r->step_started_at && $r->step_started_at->copy()->addHours($s['escalate_after_hours'])->lte(now())) {
            $roles = array_merge($roles, $s['escalate_roles'] ?? []);
        }
        $ids = $s['users'] ?? [];
        if (! empty($s['manager'])) {
            $ids = array_merge($ids, $this->managersOf((int) $r->requested_by, $r->company_id));
        }
        $users = User::where('company_id', $r->company_id)->where('is_active', true)
            ->where(fn ($q) => $q->whereIn('id', $ids ?: [0])->orWhereIn('role_id', $roles ?: [0]))
            ->where('id', '<>', (int) $r->requested_by)->get();
        if ($users->isEmpty()) {
            // Personne pour décider : les responsables des circuits prennent le relais.
            $users = User::where('company_id', $r->company_id)->where('is_active', true)->where('id', '<>', (int) $r->requested_by)->get()
                ->filter(fn ($u) => ! $u->is_super_admin && $u->hasPermission('approbations.manage'))->values();
        }

        return $users->pluck('id')->map(fn ($i) => (int) $i);
    }

    /**
     * Supérieur hiérarchique du demandeur : son responsable direct (fiche utilisateur) ; à défaut, les titulaires de la fonction
     * au-dessus de la sienne dans l'organigramme (en remontant tant que la fonction est vide).
     */
    public function managersOf(int $userId, int $companyId): array
    {
        $u = User::where('company_id', $companyId)->find($userId);
        if (! $u) {
            return [];
        }
        if ($u->manager_id && User::where('company_id', $companyId)->where('is_active', true)->whereKey($u->manager_id)->exists()) {
            return [(int) $u->manager_id];
        }
        $role = $u->role_id ? \App\Models\Role::withoutGlobalScopes()->where('company_id', $companyId)->find($u->role_id) : null;
        foreach ($role?->ancestorIds() ?? [] as $rid) {
            $ids = User::where('company_id', $companyId)->where('is_active', true)->where('role_id', $rid)->where('id', '<>', $userId)->pluck('id')->map(fn ($i) => (int) $i)->all();
            if ($ids) {
                return $ids;
            }
        }

        return [];
    }

    /** Titulaires dont l'utilisateur peut tenir lieu aujourd'hui (lui-même et ceux qui lui ont délégué). */
    public function representedBy(int $userId, Collection $base): Collection
    {
        $out = $base->contains($userId) ? collect([$userId]) : collect();
        $delegators = Delegation::active()->where('delegate_id', $userId)->pluck('user_id')->map(fn ($i) => (int) $i);

        return $out->merge($delegators->intersect($base))->unique()->values();
    }

    /** Personnes pouvant agir maintenant sur l'étape en cours (titulaires restants + délégués). */
    public function actors(ApprovalRequest $r): array
    {
        if ($r->status !== 'pending') {
            return [];
        }
        $base = $this->remaining($r);
        $delegates = Delegation::active()->whereIn('user_id', $base)->pluck('delegate_id')->map(fn ($i) => (int) $i);

        return $base->merge($delegates)->reject(fn ($i) => $i === (int) $r->requested_by)->unique()->values()->all();
    }

    /** Titulaires de l'étape courante n'ayant pas encore accordé (mode « tous »). */
    private function remaining(ApprovalRequest $r): Collection
    {
        $base = $this->baseApprovers($r, $r->current_step);
        if (($r->steps[$r->current_step]['mode'] ?? 'any') !== 'all') {
            return $base;
        }
        $done = Decision::where('request_id', $r->id)->where('step', $r->current_step)->where('decision', 'approved')->get()
            ->map(fn ($d) => (int) ($d->on_behalf_of ?: $d->user_id));

        return $base->diff($done)->values();
    }

    public function involves(ApprovalRequest $r, $user): bool
    {
        foreach (array_keys($r->steps ?? []) as $i) {
            $base = $this->baseApprovers($r, $i);
            if ($base->contains((int) $user->id) || Delegation::active()->where('delegate_id', $user->id)->whereIn('user_id', $base)->exists()) {
                return true;
            }
        }

        return Decision::where('request_id', $r->id)->where('user_id', $user->id)->exists();
    }

    private function canApproveAll(ApprovalRequest $r, User $user): bool
    {
        $copy = clone $r;
        $copy->requested_by = 0; // l'auteur est ici l'approbateur lui-même
        foreach (array_keys($r->steps) as $i) {
            $copy->current_step = $i;
            $base = $this->baseApprovers($copy, $i);
            if (! $base->contains((int) $user->id) || (($r->steps[$i]['mode'] ?? 'any') === 'all' && $base->count() > 1)) {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------ décisions

    public function decide(ApprovalRequest $r, User $user, string $decision, ?string $comment = null): ApprovalRequest
    {
        return DB::transaction(function () use ($r, $user, $decision, $comment) {
            $r = ApprovalRequest::lockForUpdate()->findOrFail($r->id);
            if ($r->status !== 'pending') {
                throw new \DomainException('Cette demande est déjà '.['approved' => 'approuvée', 'rejected' => 'refusée', 'cancelled' => 'annulée'][$r->status].'.');
            }
            if ((int) $r->requested_by === (int) $user->id) {
                throw new \DomainException('Vous ne pouvez pas approuver votre propre demande.');
            }
            $remaining = $this->remaining($r);
            $for = $this->representedBy((int) $user->id, $remaining);
            if ($for->isEmpty()) {
                throw new \DomainException('Cette étape (« '.($r->steps[$r->current_step]['name'] ?? '').' ») ne vous revient pas.');
            }
            $onBehalf = $for->contains((int) $user->id) ? null : $for->first();
            if ($decision === 'rejected') {
                if (blank($comment)) {
                    throw new \DomainException('Indiquez le motif du refus.');
                }
                Decision::create(['request_id' => $r->id, 'step' => $r->current_step, 'user_id' => $user->id, 'on_behalf_of' => $onBehalf, 'decision' => 'rejected', 'comment' => $comment]);
                $r->forceFill(['status' => 'rejected', 'decided_at' => now()])->save();
                $this->applyRejection($r, $user, $comment);
                AppNotification::send($r->company_id, [$r->requested_by], 'approval', 'Demande refusée : '.$r->label, $user->name.' : '.$comment, 'approbations', $r->id);

                return $r;
            }
            Decision::create(['request_id' => $r->id, 'step' => $r->current_step, 'user_id' => $user->id, 'on_behalf_of' => $onBehalf, 'decision' => 'approved', 'comment' => $comment]);
            if (($r->steps[$r->current_step]['mode'] ?? 'any') === 'all' && $this->remaining($r)->isNotEmpty()) {
                $r->touch(); // mode « tous » : d'autres accords sont attendus

                return $r;
            }
            if ($r->current_step + 1 < count($r->steps)) {
                $r->forceFill(['current_step' => $r->current_step + 1, 'step_started_at' => now(), 'last_reminded_at' => null, 'escalated_at' => null])->save();
                $this->notifyStep($r);

                return $r;
            }
            $r->forceFill(['status' => 'approved', 'decided_at' => now()])->save();
            $this->applyApproval($r, $user);

            return $r->fresh();
        });
    }

    public function cancel(ApprovalRequest $r, User $user): void
    {
        if ($r->status !== 'pending') {
            throw new \DomainException('Seule une demande en attente peut être annulée.');
        }
        if ((int) $r->requested_by !== (int) $user->id && ! $user->hasPermission('approbations.manage')) {
            throw new \DomainException('Seul le demandeur ou un responsable peut annuler la demande.');
        }
        Decision::create(['request_id' => $r->id, 'step' => $r->current_step, 'user_id' => $user->id, 'decision' => 'cancelled']);
        $r->forceFill(['status' => 'cancelled', 'decided_at' => now()])->save();
        if ($r->target === 'expense') {
            $this->writeExpense($r, ['status' => 'draft'], $user);
        }
    }

    private function applyApproval(ApprovalRequest $r, User $user): void
    {
        $msg = 'Demande approuvée : '.$r->label;
        if ($r->target === 'expense') {
            $err = $this->writeExpense($r, ['status' => 'approved'], $user);
        } elseif ($r->auto_validate) {
            $class = str_starts_with($r->target, 'sale.') ? \App\Modules\Ventes\Models\SalesDocument::class : \App\Modules\Achats\Models\PurchaseDocument::class;
            $doc = $class::find($r->record_id);
            $err = null;
            if ($doc && $doc->status === 'draft') {
                // Validation avec les droits du demandeur (c'est lui qui l'avait demandée).
                $prev = auth()->user();
                $requester = User::where('company_id', $r->company_id)->where('is_active', true)->find($r->requested_by);
                auth()->setUser($requester ?? $user);
                try {
                    $res = app(EntityWriter::class)->upsert($class, $doc->id, ['status' => 'validated']);
                } finally {
                    $prev ? auth()->setUser($prev) : null;
                }
                if ($res['status'] !== EntityWriter::APPLIED) {
                    $err = $res['message'] ?? 'refus';
                } else {
                    $r->forceFill(['label' => $this->subject($doc->fresh())['label']])->save();
                    $msg = 'Demande approuvée et document validé : '.$r->label;
                }
            }
        }
        if (! empty($err)) {
            $r->forceFill(['note' => mb_substr('Validation automatique impossible : '.$err, 0, 255)])->save();
            $msg .= ' (à valider manuellement : '.$err.')';
        }
        AppNotification::send($r->company_id, [$r->requested_by], 'approval', $msg, 'Dernier accord : '.$user->name, 'approbations', $r->id);
    }

    private function applyRejection(ApprovalRequest $r, User $user, string $comment): void
    {
        if ($r->target === 'expense') {
            $this->writeExpense($r, ['status' => 'rejected', 'decision_note' => $comment], $user);
        }
    }

    private function writeExpense(ApprovalRequest $r, array $data, User $user): ?string
    {
        $prev = auth()->user();
        auth()->setUser($user);
        \App\Modules\NotesFrais\Models\Expense::$workflowDecision = true;
        try {
            $res = app(EntityWriter::class)->upsert(\App\Modules\NotesFrais\Models\Expense::class, $r->record_id, $data);

            return $res['status'] === EntityWriter::APPLIED ? null : ($res['message'] ?? 'refus');
        } finally {
            \App\Modules\NotesFrais\Models\Expense::$workflowDecision = false;
            $prev ? auth()->setUser($prev) : null;
        }
    }

    private function notifyStep(ApprovalRequest $r): void
    {
        $step = $r->steps[$r->current_step]['name'] ?? '';
        AppNotification::send($r->company_id, $this->actors($r), 'approval', 'À approuver : '.$r->label,
            'Circuit « '.$r->rule_name.' » — étape '.($r->current_step + 1).'/'.count($r->steps).' : '.$step, 'approbations', $r->id);
    }

    /** Relances et escalades (tâche horaire). */
    public function remind(\App\Models\Company $company): array
    {
        $out = ['reminded' => 0, 'escalated' => 0];
        foreach (ApprovalRequest::where('status', 'pending')->get() as $r) {
            $step = $r->steps[$r->current_step] ?? [];
            if (! empty($step['escalate_after_hours']) && ! $r->escalated_at && $r->step_started_at?->copy()->addHours($step['escalate_after_hours'])->lte(now())) {
                $r->forceFill(['escalated_at' => now()])->save();
                Decision::create(['request_id' => $r->id, 'step' => $r->current_step, 'decision' => 'escalated', 'comment' => 'Sans réponse depuis '.$step['escalate_after_hours'].' h']);
                AppNotification::send($company->id, $this->actors($r), 'approval', 'Escalade — à approuver : '.$r->label, 'Sans réponse depuis '.$step['escalate_after_hours'].' h.', 'approbations', $r->id);
                $out['escalated']++;

                continue;
            }
            if ($r->reminder_hours > 0) {
                $since = $r->last_reminded_at ?? $r->step_started_at;
                if ($since && $since->copy()->addHours($r->reminder_hours)->lte(now())) {
                    AppNotification::send($company->id, $this->actors($r), 'approval', 'Rappel — à approuver : '.$r->label, 'En attente depuis le '.$r->step_started_at->format('d/m/Y à H:i').'.', 'approbations', $r->id);
                    $r->forceFill(['last_reminded_at' => now()])->save();
                    $out['reminded']++;
                }
            }
        }

        return $out;
    }

    public function inbox(User $user): Collection
    {
        return ApprovalRequest::where('status', 'pending')->orderBy('created_at')->get()
            ->filter(fn ($r) => (int) $r->requested_by !== (int) $user->id && in_array((int) $user->id, $this->actors($r), true))->values();
    }
}
