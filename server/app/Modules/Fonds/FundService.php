<?php

namespace App\Modules\Fonds;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\Sequence;
use App\Models\User;
use App\Modules\Fonds\Models\FundEvent;
use App\Modules\Fonds\Models\FundLine;
use App\Modules\Fonds\Models\FundRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Appels de fonds : un service demande des moyens (argent, matériel, prestation) à sa hiérarchie ou au siège.
 * brouillon → soumis → (explication demandée → répondu) → approuvé / refusé → mise à disposition (partielle) → reçu.
 */
class FundService
{
    public const STATUSES = [
        'draft' => 'Brouillon', 'submitted' => 'En attente de décision', 'question' => 'Explication demandée', 'approved' => 'Approuvé, à fournir',
        'partial' => 'Partiellement fourni', 'provided' => 'Fourni, réception à confirmer', 'closed' => 'Reçu', 'rejected' => 'Refusé', 'cancelled' => 'Annulé',
    ];

    public const TYPES = ['funds' => 'Fonds', 'equipment' => 'Matériel / équipement', 'service' => 'Prestation'];

    public const METHODS = ['bank_transfer' => 'Virement bancaire', 'cash' => 'Espèces (caisse)', 'mobile_money' => 'Mobile Money', 'cheque' => 'Chèque',
        'equipment' => 'Remise de matériel', 'purchase' => 'Achat direct par le siège', 'other' => 'Autre'];

    public const EVENTS = ['created' => 'Création', 'submitted' => 'Envoi au responsable', 'question' => 'Demande d\'explication', 'answer' => 'Réponse du service',
        'approved' => 'Approbation', 'rejected' => 'Refus', 'provided' => 'Mise à disposition', 'closed' => 'Réception confirmée', 'cancelled' => 'Annulation',
        'comment' => 'Commentaire', 'reminder' => 'Relance', 'updated' => 'Modification'];

    public function __construct(private MessagingService $messaging) {}

    // ------------------------------------------------------------------ réglages

    public function settings(Company $c): array
    {
        $s = (array) data_get($c->settings, 'fonds', []);

        return [
            'approvers' => (array) ($s['approvers'] ?? []),       // service => responsable
            'default_approver' => $s['default_approver'] ?? null,
            'providers' => (array) ($s['providers'] ?? []),       // type (funds/equipment/service) => service chargé de fournir
            'remind_days' => (int) ($s['remind_days'] ?? 3),
            'email' => (bool) ($s['email'] ?? true),
            'departments' => $this->departments($c, (array) ($s['departments'] ?? [])),
        ];
    }

    public function saveSettings(Company $c, array $d): array
    {
        $settings = $c->settings ?? [];
        $cur = (array) data_get($settings, 'fonds', []);
        foreach (['approvers', 'providers', 'default_approver', 'remind_days', 'email', 'departments'] as $k) {
            if (array_key_exists($k, $d)) {
                $cur[$k] = $d[$k];
            }
        }
        if (isset($cur['departments'])) {
            $cur['departments'] = array_values(array_unique(array_filter(array_map(fn ($x) => trim((string) $x), (array) $cur['departments']))));
        }
        data_set($settings, 'fonds', $cur);
        $c->update(['settings' => $settings]);

        return $this->settings($c->fresh());
    }

    /** Services connus : réglages, fiches salariés et appels déjà faits. */
    public function departments(Company $c, array $extra = []): array
    {
        $list = collect($extra);
        if (DB::getSchemaBuilder()->hasTable('rh_employees')) {
            $list = $list->merge(DB::table('rh_employees')->where('company_id', $c->id)->whereNotNull('department')->distinct()->pluck('department'));
        }
        $list = $list->merge(DB::table('af_requests')->where('company_id', $c->id)->whereNotNull('department')->distinct()->pluck('department'))
            ->merge(DB::table('af_requests')->where('company_id', $c->id)->whereNotNull('to_department')->distinct()->pluck('to_department'));

        return $list->map(fn ($x) => trim((string) $x))->filter()->unique()->sort()->values()->all();
    }

    // ------------------------------------------------------------------ droits

    public function canSeeAll(User $u): bool
    {
        return $u->hasPermission('fonds.view') || $u->hasPermission('fonds.manage');
    }

    public function visible(User $u)
    {
        $q = FundRequest::query();
        if (! $this->canSeeAll($u)) {
            $q->where(fn ($w) => $w->where('requester_id', $u->id)->orWhere('approver_id', $u->id)->orWhere('provider_id', $u->id));
        }

        return $q;
    }

    protected function assert(bool $ok, string $msg): void
    {
        if (! $ok) {
            throw new \DomainException($msg);
        }
    }

    protected function isApprover(User $u, FundRequest $r): bool
    {
        return $r->approver_id === $u->id || $u->hasPermission('fonds.manage');
    }

    protected function isProvider(User $u, FundRequest $r): bool
    {
        return $r->provider_id === $u->id || $u->hasPermission('fonds.manage') || (! $r->provider_id && $u->hasPermission('fonds.provide'));
    }

    // ------------------------------------------------------------------ saisie

    /** Crée ou modifie une demande (brouillon, ou demande à compléter après une question). */
    public function save(Company $c, User $u, ?FundRequest $r, array $d): FundRequest
    {
        if ($r) {
            $this->assert($r->requester_id === $u->id || $u->hasPermission('fonds.manage'), 'Seul le demandeur modifie sa demande.');
            $this->assert(in_array($r->status, ['draft', 'question'], true), 'Demande déjà envoyée : elle n\'est plus modifiable.');
        }
        $lines = array_values(array_filter((array) ($d['lines'] ?? []), fn ($l) => trim((string) ($l['description'] ?? '')) !== ''));
        $this->assert(! array_key_exists('lines', $d) || count($lines) > 0, 'Ajoutez au moins un besoin.');
        $this->assert(count($lines) <= 100, '100 lignes au plus.');

        return DB::transaction(function () use ($c, $u, $r, $d, $lines) {
            $creating = ! $r;
            $r ??= new FundRequest(['company_id' => $c->id, 'requester_id' => $u->id, 'status' => 'draft']);
            foreach (['title', 'department', 'to_department', 'priority', 'needed_by', 'purpose', 'project_id'] as $k) {
                if (array_key_exists($k, $d)) {
                    $r->{$k} = is_string($d[$k]) ? (trim($d[$k]) ?: null) : $d[$k];
                }
            }
            if (array_key_exists('approver_id', $d)) {
                $r->approver_id = $d['approver_id'] ? (int) $d['approver_id'] : null;
            }
            $this->assert((bool) $r->title, 'Donnez un titre à la demande.');
            if (! in_array($r->priority, ['normal', 'urgent'], true)) {
                $r->priority = 'normal';
            }
            $r->save();
            if (array_key_exists('lines', $d)) {
                $keep = [];
                foreach ($lines as $i => $l) {
                    $qty = max(0.001, (float) ($l['quantity'] ?? 1));
                    $unit = max(0, (float) ($l['unit_amount'] ?? 0));
                    $line = ! empty($l['id']) ? FundLine::where('request_id', $r->id)->find($l['id']) : null;
                    $line ??= new FundLine(['company_id' => $c->id, 'request_id' => $r->id]);
                    $line->fill([
                        'type' => array_key_exists($l['type'] ?? '', self::TYPES) ? $l['type'] : 'funds',
                        'description' => Str::limit(trim((string) $l['description']), 250, ''),
                        'product_id' => $l['product_id'] ?? null,
                        'quantity' => $qty, 'unit_amount' => $unit, 'amount' => round($qty * $unit, 2),
                        'note' => isset($l['note']) ? Str::limit(trim((string) $l['note']), 250, '') ?: null : $line->note,
                        'sort' => $i,
                    ])->save();
                    $keep[] = $line->id;
                }
                FundLine::where('request_id', $r->id)->whereNotIn('id', $keep)->delete();
            }
            $this->totals($r);
            if ($creating) {
                $this->event($r, $u->id, 'created');
            } elseif ($r->status === 'question') {
                $this->event($r, $u->id, 'updated', 'Besoins modifiés après la demande d\'explication.');
            }

            return $r->fresh('lines');
        });
    }

    protected function totals(FundRequest $r): void
    {
        $lines = FundLine::where('request_id', $r->id)->get();
        $r->forceFill([
            'total_requested' => round($lines->sum('amount'), 2),
            'total_approved' => round($lines->sum(fn ($l) => $l->approved_amount ?? 0), 2),
            'total_provided' => round($lines->sum('provided_amount'), 2),
        ])->save();
    }

    protected function event(FundRequest $r, ?int $userId, string $type, ?string $body = null, ?array $data = null): FundEvent
    {
        return FundEvent::create(['company_id' => $r->company_id, 'request_id' => $r->id, 'user_id' => $userId, 'type' => $type, 'body' => $body, 'data' => $data]);
    }

    protected function notify(FundRequest $r, iterable $userIds, string $title, ?string $body, ?int $actor): void
    {
        AppNotification::send($r->company_id, $userIds, 'fonds', $title, $body, 'fonds', (string) $r->id, $actor);
    }

    protected function label(FundRequest $r): string
    {
        return trim(($r->number ?? '').' — '.$r->title.($r->department ? ' ('.$r->department.')' : ''));
    }

    // ------------------------------------------------------------------ circuit

    public function submit(Company $c, User $u, FundRequest $r): FundRequest
    {
        $this->assert($r->requester_id === $u->id || $u->hasPermission('fonds.manage'), 'Seul le demandeur envoie sa demande.');
        $this->assert(in_array($r->status, ['draft', 'question'], true), 'Demande déjà envoyée.');
        $this->assert(FundLine::where('request_id', $r->id)->exists(), 'Ajoutez au moins un besoin.');
        $set = $this->settings($c);
        $r->approver_id ??= ($set['approvers'][$r->department] ?? null) ?: $set['default_approver'];
        $this->assert((bool) $r->approver_id, 'Choisissez le responsable qui doit décider.');
        $approver = User::where('company_id', $c->id)->find($r->approver_id);
        $this->assert($approver && $approver->is_active, 'Responsable introuvable.');
        $this->assert($approver->id !== $r->requester_id || $u->hasPermission('fonds.manage'), 'Vous ne pouvez pas approuver votre propre demande : choisissez un autre responsable.');
        $first = $r->status === 'draft';
        $r->number ??= Sequence::next($c->id, 'fonds.request', 'AF');
        $r->forceFill(['status' => 'submitted', 'submitted_at' => $first ? now() : $r->submitted_at, 'reminded_at' => null])->save();
        if ($first) {
            $this->event($r, $u->id, 'submitted', null, ['approver' => $approver->name]);
        }
        $this->notify($r, [$approver->id], $first ? 'Appel de fonds à examiner' : 'Appel de fonds complété',
            $this->label($r).' : '.$this->money($r->total_requested, $c), $u->id);
        if ($first) {
            $this->mail($c, $r, $approver, 'Appel de fonds '.$r->number.' : '.$r->title,
                (User::find($r->requester_id)?->name ?? $u->name).' ('.($r->department ?: 'service').') vous adresse un appel de fonds de '.$this->money($r->total_requested, $c)
                .($r->needed_by ? ', pour le '.$r->needed_by->format('d/m/Y') : '').'. Le détail des besoins est joint en PDF. Ouvrez Yomara ERP pour approuver, demander une explication ou refuser.');
        }

        return $r->fresh('lines');
    }

    public function question(Company $c, User $u, FundRequest $r, string $body): FundRequest
    {
        $this->assert($this->isApprover($u, $r), 'Seul le responsable désigné peut demander une explication.');
        $this->assert($r->status === 'submitted', 'Cette demande n\'attend pas de décision.');
        $this->assert(trim($body) !== '', 'Écrivez votre question.');
        $r->forceFill(['status' => 'question', 'reminded_at' => null])->save();
        $this->event($r, $u->id, 'question', trim($body));
        $this->notify($r, [$r->requester_id], 'Explication demandée sur votre appel de fonds', $this->label($r).' : '.trim($body), $u->id);
        if ($req = User::find($r->requester_id)) {
            $this->mail($c, $r, $req, 'Appel de fonds '.$r->number.' : explication demandée', $u->name.' vous demande une explication : « '.trim($body).' ». Répondez depuis Yomara ERP.', false);
        }

        return $r->fresh('lines');
    }

    public function answer(Company $c, User $u, FundRequest $r, string $body): FundRequest
    {
        $this->assert($r->requester_id === $u->id || $u->hasPermission('fonds.manage'), 'Seul le demandeur répond.');
        $this->assert($r->status === 'question', 'Aucune explication n\'est demandée.');
        $this->assert(trim($body) !== '', 'Écrivez votre réponse.');
        $this->event($r, $u->id, 'answer', trim($body));

        return $this->submit($c, $u, $r);
    }

    /**
     * Approbation : montants accordés ligne par ligne (par défaut la demande), service chargé de fournir.
     *
     * @param  array<int, array{id:int, approved_quantity?:float, approved_amount?:float}>  $lines
     */
    public function approve(Company $c, User $u, FundRequest $r, array $lines, ?int $providerId, ?string $providerDepartment, ?string $note): FundRequest
    {
        $this->assert($this->isApprover($u, $r), 'Seul le responsable désigné décide de cette demande.');
        $this->assert(in_array($r->status, ['submitted', 'question'], true), 'Cette demande n\'attend pas de décision.');
        $by = collect($lines)->keyBy('id');
        $provider = $providerId ? User::where('company_id', $c->id)->where('is_active', true)->find($providerId) : null;
        $this->assert(! $providerId || $provider, 'Personne chargée de fournir introuvable.');

        DB::transaction(function () use ($r, $by) {
            foreach (FundLine::where('request_id', $r->id)->get() as $l) {
                $x = $by[$l->id] ?? null;
                $qty = $x && array_key_exists('approved_quantity', $x) ? max(0, (float) $x['approved_quantity']) : $l->quantity;
                $amount = $x && array_key_exists('approved_amount', $x) ? max(0, (float) $x['approved_amount']) : ($qty == $l->quantity ? $l->amount : round($qty * $l->unit_amount, 2));
                $l->forceFill(['approved_quantity' => $qty, 'approved_amount' => round($amount, 2)])->save();
            }
        });
        $this->totals($r);
        $r->refresh();
        $this->assert($r->total_approved > 0 || FundLine::where('request_id', $r->id)->where('approved_quantity', '>', 0)->exists(), 'Rien n\'est accordé : refusez plutôt la demande.');
        $partial = $r->total_approved + 0.005 < $r->total_requested;
        $r->forceFill(['status' => 'approved', 'decided_at' => now(), 'decision_note' => $note ? trim($note) : null, 'provider_id' => $provider?->id,
            'provider_department' => $providerDepartment ? trim($providerDepartment) : null, 'reminded_at' => null])->save();
        $this->event($r, $u->id, 'approved', $note ? trim($note) : null, ['approved' => $r->total_approved, 'requested' => $r->total_requested, 'partial' => $partial,
            'provider' => $provider?->name, 'provider_department' => $r->provider_department]);
        $this->notify($r, [$r->requester_id], $partial ? 'Appel de fonds approuvé en partie' : 'Appel de fonds approuvé',
            $this->label($r).' : '.$this->money($r->total_approved, $c).' accordés'.($r->provider_department ? ', mis à disposition par '.$r->provider_department : '').'.', $u->id);
        $providers = $provider ? collect([$provider]) : User::where('company_id', $c->id)->where('is_active', true)->get()->filter(fn ($x) => $x->hasPermission('fonds.provide'));
        $this->notify($r, $providers->pluck('id'), 'Moyens à mettre à disposition', $this->label($r).' : '.$this->money($r->total_approved, $c).' approuvés par '.$u->name.'.', $u->id);
        if ($provider) {
            $this->mail($c, $r, $provider, 'À mettre à disposition : appel de fonds '.$r->number, $u->name.' a approuvé l\'appel de fonds de '.($r->department ?: 'un service')
                .' pour '.$this->money($r->total_approved, $c).' et vous demande de mettre les moyens à disposition'.($r->needed_by ? ' avant le '.$r->needed_by->format('d/m/Y') : '').'. Détail en PDF.');
        }

        return $r->fresh('lines');
    }

    public function reject(Company $c, User $u, FundRequest $r, string $note): FundRequest
    {
        $this->assert($this->isApprover($u, $r), 'Seul le responsable désigné décide de cette demande.');
        $this->assert(in_array($r->status, ['submitted', 'question'], true), 'Cette demande n\'attend pas de décision.');
        $this->assert(trim($note) !== '', 'Indiquez le motif du refus.');
        $r->forceFill(['status' => 'rejected', 'decided_at' => now(), 'decision_note' => trim($note)])->save();
        $this->event($r, $u->id, 'rejected', trim($note));
        $this->notify($r, [$r->requester_id], 'Appel de fonds refusé', $this->label($r).' : '.trim($note), $u->id);

        return $r->fresh('lines');
    }

    /**
     * Mise à disposition (en une ou plusieurs fois). Fonds : virement entre comptes de trésorerie si les deux comptes sont indiqués.
     *
     * @param  array<int, array{id:int, quantity?:float, amount?:float}>  $items
     */
    public function provide(Company $c, User $u, FundRequest $r, array $items, array $d): FundRequest
    {
        $this->assert($this->isProvider($u, $r), 'Cette mise à disposition a été confiée à quelqu\'un d\'autre.');
        $this->assert(in_array($r->status, ['approved', 'partial'], true), 'Cette demande n\'est pas à fournir.');
        $method = array_key_exists($d['method'] ?? '', self::METHODS) ? $d['method'] : 'other';
        $date = isset($d['date']) ? Carbon::parse($d['date'])->toDateString() : now()->toDateString();
        $lines = FundLine::where('request_id', $r->id)->get()->keyBy('id');
        $given = [];
        $total = 0.0;
        foreach ($items as $it) {
            $l = $lines[(int) ($it['id'] ?? 0)] ?? null;
            if (! $l) {
                continue;
            }
            $leftQty = max(0, (float) $l->approved_quantity - $l->provided_quantity);
            $leftAmt = max(0, (float) $l->approved_amount - $l->provided_amount);
            $amt = array_key_exists('amount', $it) ? min($leftAmt, max(0, (float) $it['amount'])) : null;
            $qty = array_key_exists('quantity', $it) ? min($leftQty, max(0, (float) $it['quantity']))
                : ($amt !== null && $leftAmt > 0 ? round($leftQty * $amt / $leftAmt, 3) : $leftQty);
            $amt ??= $leftQty > 0 ? round($leftAmt * $qty / $leftQty, 2) : $leftAmt;
            if ($qty <= 0 && $amt <= 0) {
                continue;
            }
            $given[] = ['id' => $l->id, 'description' => $l->description, 'type' => $l->type, 'quantity' => $qty, 'amount' => round($amt, 2)];
            $total += $amt;
        }
        $this->assert((bool) $given, 'Indiquez ce qui est mis à disposition.');
        $funds = round(array_sum(array_map(fn ($g) => $g['type'] === 'funds' ? $g['amount'] : 0, $given)), 2);

        return DB::transaction(function () use ($c, $u, $r, $lines, $given, $total, $funds, $method, $date, $d) {
            foreach ($given as $g) {
                $l = $lines[$g['id']];
                $l->forceFill(['provided_quantity' => round($l->provided_quantity + $g['quantity'], 3), 'provided_amount' => round($l->provided_amount + $g['amount'], 2)])->save();
            }
            $transfer = null;
            if ($funds > 0 && ! empty($d['source_account_id']) && ! empty($d['dest_account_id']) && $c->hasModule('tresorerie')) {
                $transfer = $this->transfer($c, $r, $funds, $date, (string) $d['source_account_id'], (string) $d['dest_account_id'], $d['reference'] ?? null);
            }
            $this->totals($r);
            $r->refresh();
            $done = FundLine::where('request_id', $r->id)->get()->every(fn ($l) => $l->provided_quantity + 0.0005 >= (float) $l->approved_quantity && $l->provided_amount + 0.005 >= (float) $l->approved_amount);
            $r->forceFill(['status' => $done ? 'provided' : 'partial', 'provided_at' => $done ? now() : $r->provided_at, 'provider_id' => $r->provider_id ?? $u->id, 'reminded_at' => null])->save();
            $this->event($r, $u->id, 'provided', isset($d['note']) ? trim((string) $d['note']) ?: null : null, [
                'items' => $given, 'total' => round($total, 2), 'method' => $method, 'method_label' => self::METHODS[$method], 'date' => $date, 'reference' => $d['reference'] ?? null, 'transfer_id' => $transfer,
            ]);
            $this->notify($r, [$r->requester_id], $done ? 'Moyens mis à disposition : confirmez la réception' : 'Mise à disposition partielle',
                $this->label($r).' : '.$this->money($total, $c).' ('.self::METHODS[$method].').', $u->id);

            return $r->fresh('lines');
        });
    }

    /** Virement interne de trésorerie (compte du siège → compte du service). */
    protected function transfer(Company $c, FundRequest $r, float $amount, string $date, string $from, string $to, ?string $reference): ?string
    {
        $acc = DB::table('tr_accounts')->where('company_id', $c->id)->whereIn('id', [$from, $to])->whereNull('deleted_at')->pluck('name', 'id');
        $this->assert($acc->count() === 2 && $from !== $to, 'Choisissez deux comptes de trésorerie différents.');
        $tid = (string) Str::uuid();
        $w = app(\App\Services\EntityWriter::class);
        $label = 'Appel de fonds '.$r->number.' — '.$r->title;
        foreach ([[$from, -$amount], [$to, $amount]] as [$account, $amt]) {
            $res = $w->upsert(\App\Modules\Tresorerie\Models\Operation::class, (string) Str::uuid(), ['account_id' => $account, 'date' => $date, 'amount' => $amt, 'label' => Str::limit($label, 250, ''),
                'category' => 'transfer', 'reference' => $reference ?: $r->number, 'transfer_id' => $tid, 'notes' => 'Mise à disposition de fonds'.($r->department ? ' pour '.$r->department : '')]);
            $this->assert($res['status'] === \App\Services\EntityWriter::APPLIED, 'Virement impossible : '.json_encode($res['errors'] ?? $res['message'] ?? $res));
        }

        return $tid;
    }

    public function confirm(Company $c, User $u, FundRequest $r, ?string $note): FundRequest
    {
        $this->assert($r->requester_id === $u->id || $u->hasPermission('fonds.manage'), 'Seul le demandeur confirme la réception.');
        $this->assert(in_array($r->status, ['provided', 'partial'], true), 'Rien n\'a encore été mis à disposition.');
        $r->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        $this->event($r, $u->id, 'closed', $note ? trim($note) : null, ['received' => $r->total_provided]);
        $this->notify($r, array_filter([$r->approver_id, $r->provider_id]), 'Réception confirmée', $this->label($r).' : '.$this->money($r->total_provided, $c).' reçus.', $u->id);

        return $r->fresh('lines');
    }

    public function cancel(Company $c, User $u, FundRequest $r, ?string $note): FundRequest
    {
        $this->assert($r->requester_id === $u->id || $u->hasPermission('fonds.manage'), 'Seul le demandeur annule sa demande.');
        $this->assert(in_array($r->status, ['draft', 'submitted', 'question', 'approved'], true), 'Cette demande ne peut plus être annulée.');
        $this->assert($r->total_provided <= 0, 'Des moyens ont déjà été fournis.');
        $r->forceFill(['status' => 'cancelled', 'closed_at' => now()])->save();
        $this->event($r, $u->id, 'cancelled', $note ? trim($note) : null);
        $this->notify($r, array_filter([$r->approver_id, $r->provider_id]), 'Appel de fonds annulé', $this->label($r), $u->id);

        return $r->fresh('lines');
    }

    public function comment(Company $c, User $u, FundRequest $r, string $body): FundRequest
    {
        $this->assert(trim($body) !== '', 'Écrivez votre message.');
        $this->event($r, $u->id, 'comment', trim($body));
        $this->notify($r, collect([$r->requester_id, $r->approver_id, $r->provider_id])->filter()->reject(fn ($id) => $id === $u->id), 'Nouveau message sur un appel de fonds', $this->label($r).' : '.Str::limit(trim($body), 140), $u->id);

        return $r->fresh('lines');
    }

    /** Relances automatiques : décisions et mises à disposition qui traînent. */
    public function remind(Company $c): int
    {
        $days = max(1, $this->settings($c)['remind_days']);
        $n = 0;
        $late = FundRequest::withoutGlobalScopes()->where('company_id', $c->id)->whereIn('status', ['submitted', 'approved', 'partial'])
            ->where(fn ($q) => $q->whereNull('reminded_at')->orWhere('reminded_at', '<', now()->subDays($days)))->get();
        foreach ($late as $r) {
            $since = $r->status === 'submitted' ? $r->submitted_at : ($r->decided_at ?? $r->updated_at);
            if (! $since || $since->gt(now()->subDays($days))) {
                continue;
            }
            $to = $r->status === 'submitted' ? [$r->approver_id] : ($r->provider_id ? [$r->provider_id] : User::where('company_id', $c->id)->where('is_active', true)->get()->filter(fn ($x) => $x->hasPermission('fonds.provide'))->pluck('id')->all());
            $this->notify($r, $to, $r->status === 'submitted' ? 'Appel de fonds en attente de votre décision' : 'Appel de fonds en attente de mise à disposition',
                $this->label($r).' depuis le '.$since->format('d/m/Y').($r->priority === 'urgent' ? ' (urgent)' : '').'.', null);
            $r->forceFill(['reminded_at' => now()])->saveQuietly();
            $this->event($r, null, 'reminder', $r->status === 'submitted' ? 'Relance du responsable' : 'Relance du service chargé de fournir');
            $n++;
        }

        return $n;
    }

    // ------------------------------------------------------------------ e-mail et PDF

    protected function mail(Company $c, FundRequest $r, User $to, string $subject, string $text, bool $attach = true): void
    {
        if (! $to->email || ! $this->settings($c)['email'] || ! ($this->messaging->available($c)['email'] ?? false)) {
            return;
        }
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rows = '';
        foreach (FundLine::where('request_id', $r->id)->orderBy('sort')->get() as $l) {
            $rows .= '<tr><td style="padding:6px;border-bottom:1px solid #eee">'.$e($l->description).'</td><td style="padding:6px;border-bottom:1px solid #eee;text-align:right">'.$e(rtrim(rtrim(number_format($l->quantity, 3, ',', ' '), '0'), ','))
                .'</td><td style="padding:6px;border-bottom:1px solid #eee;text-align:right">'.$e($this->money($l->amount, $c)).'</td></tr>';
        }
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1d2433"><p>Bonjour '.$e($to->name).',</p><p>'.$e($text).'</p>'
            .'<table style="border-collapse:collapse;width:100%;max-width:640px"><tr style="background:#f2f5fa"><th style="padding:6px;text-align:left">Besoin</th><th style="padding:6px;text-align:right">Qté</th><th style="padding:6px;text-align:right">Montant</th></tr>'.$rows
            .'<tr><td style="padding:6px"><b>Total</b></td><td></td><td style="padding:6px;text-align:right"><b>'.$e($this->money($r->total_requested, $c)).'</b></td></tr></table>'
            .'<p><a href="'.$e(url('/app/')).'" style="display:inline-block;background:#1f6feb;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none">Ouvrir Yomara ERP</a></p><p style="color:#888">'.$e($c->name).'</p></div>';
        $att = $attach ? [['name' => 'appel-de-fonds-'.Str::slug($r->number ?? 'brouillon').'.pdf', 'data' => $this->pdf($c, $r), 'mime' => 'application/pdf']] : [];
        rescue(fn () => $this->messaging->send($c, 'email', new Message($to->email, $subject, $html, strip_tags($text), $att, $to->name), 'fonds'), null, false);
    }

    public function pdf(Company $c, FundRequest $r): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $m = fn ($v) => $e($this->money((float) $v, $c));
        $q = fn ($v) => $e(rtrim(rtrim(number_format((float) $v, 3, ',', ' '), '0'), ','));
        $users = User::whereIn('id', array_filter([$r->requester_id, $r->approver_id, $r->provider_id]))->pluck('name', 'id');
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('Yomara ERP');
        $pdf->SetTitle('Appel de fonds '.($r->number ?? ''));
        $pdf->setPrintHeader(false);
        $pdf->setFooterFont(['dejavusans', '', 7]);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 9);
        $decided = in_array($r->status, ['approved', 'partial', 'provided', 'closed'], true);
        $html = '<table><tr><td width="58%"><b style="font-size:13pt">'.$e($c->name).'</b><br><span style="color:#666">'.$e(trim(($c->address ?? '').' '.($c->city ?? '').' '.($c->phone ?? ''))).'</span></td>'
            .'<td width="42%" align="right"><b style="font-size:16pt;color:#1f6feb">APPEL DE FONDS</b><br><b>'.$e($r->number ?? 'Brouillon').'</b><br>'.$e(($r->submitted_at ?? $r->created_at)?->format('d/m/Y')).'</td></tr></table><br>'
            .'<table cellpadding="5" style="border:0.3px solid #cfd6e4"><tr bgcolor="#f2f5fa"><td width="50%"><span style="color:#666">Service demandeur</span><br><b>'.$e($r->department ?: '—').'</b><br>'.$e($users[$r->requester_id] ?? '').'</td>'
            .'<td width="50%"><span style="color:#666">Adressé à</span><br><b>'.$e($r->to_department ?: 'Direction').'</b><br>'.$e($users[$r->approver_id] ?? '').'</td></tr>'
            .'<tr><td colspan="2"><span style="color:#666">Objet</span><br><b style="font-size:11pt">'.$e($r->title).'</b>'.($r->priority === 'urgent' ? ' <b style="color:#c0392b">— URGENT</b>' : '')
            .($r->needed_by ? '<br>Pour le : <b>'.$e($r->needed_by->format('d/m/Y')).'</b>' : '').($r->purpose ? '<br><br>'.nl2br($e($r->purpose)) : '').'</td></tr></table><br><br>';
        $html .= '<table cellpadding="5" border="0.3"><tr bgcolor="#1f2a44" color="#ffffff" style="font-weight:bold"><td width="'.($decided ? '34' : '46').'%">Besoin</td><td width="12%">Nature</td><td width="9%" align="right">Qté</td><td width="'.($decided ? '13' : '15').'%" align="right">Prix unitaire</td><td width="'.($decided ? '14' : '18').'%" align="right">Montant</td>'
            .($decided ? '<td width="18%" align="right">Accordé</td>' : '').'</tr>';
        foreach (FundLine::where('request_id', $r->id)->orderBy('sort')->get() as $l) {
            $html .= '<tr><td>'.$e($l->description).($l->note ? '<br><span style="color:#777;font-size:7.5pt">'.$e($l->note).'</span>' : '').'</td><td>'.$e(self::TYPES[$l->type] ?? '').'</td><td align="right">'.$q($l->quantity).'</td><td align="right">'.$m($l->unit_amount).'</td><td align="right">'.$m($l->amount).'</td>'
                .($decided ? '<td align="right">'.$m($l->approved_amount).'</td>' : '').'</tr>';
        }
        $html .= '<tr bgcolor="#f2f5fa" style="font-weight:bold"><td colspan="4" align="right">TOTAL</td><td align="right">'.$m($r->total_requested).'</td>'.($decided ? '<td align="right">'.$m($r->total_approved).'</td>' : '').'</tr></table>';
        if ($r->decision_note) {
            $html .= '<p><b>Décision :</b> '.nl2br($e($r->decision_note)).'</p>';
        }
        $html .= '<br><br><table cellpadding="6"><tr><td width="33%" style="border-top:0.3px solid #999">Le demandeur<br><br><br></td><td width="34%" style="border-top:0.3px solid #999">Le responsable (accord)<br><br><br></td><td width="33%" style="border-top:0.3px solid #999">Mise à disposition<br><br><br></td></tr></table>';
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output('', 'S');
    }

    public function money(float $v, Company $c): string
    {
        $cur = in_array($c->currency, ['XOF', 'XAF', null], true) ? 'F CFA' : $c->currency;

        return number_format($v, 0, ',', ' ').' '.$cur;
    }

    // ------------------------------------------------------------------ restitution

    public function toApi(FundRequest $r, User $u, bool $full = false): array
    {
        $names = User::whereIn('id', array_filter([$r->requester_id, $r->approver_id, $r->provider_id]))->pluck('name', 'id');
        $out = [
            'id' => $r->id, 'number' => $r->number, 'title' => $r->title, 'department' => $r->department, 'to_department' => $r->to_department,
            'requester_id' => $r->requester_id, 'requester' => $names[$r->requester_id] ?? null, 'approver_id' => $r->approver_id, 'approver' => $names[$r->approver_id] ?? null,
            'provider_id' => $r->provider_id, 'provider' => $names[$r->provider_id] ?? null, 'provider_department' => $r->provider_department,
            'priority' => $r->priority, 'needed_by' => $r->needed_by?->toDateString(), 'purpose' => $r->purpose, 'status' => $r->status, 'status_label' => self::STATUSES[$r->status] ?? $r->status,
            'total_requested' => $r->total_requested, 'total_approved' => $r->total_approved, 'total_provided' => $r->total_provided, 'decision_note' => $r->decision_note,
            'submitted_at' => $r->submitted_at, 'decided_at' => $r->decided_at, 'provided_at' => $r->provided_at, 'closed_at' => $r->closed_at, 'created_at' => $r->created_at,
            'late' => $r->needed_by && $r->needed_by->lt(now()->startOfDay()) && ! in_array($r->status, ['closed', 'rejected', 'cancelled', 'provided'], true),
            'kinds' => FundLine::where('request_id', $r->id)->distinct()->pluck('type')->all(),
            'can' => [
                'edit' => in_array($r->status, ['draft', 'question'], true) && ($r->requester_id === $u->id || $u->hasPermission('fonds.manage')),
                'submit' => $r->status === 'draft' && ($r->requester_id === $u->id || $u->hasPermission('fonds.manage')),
                'answer' => $r->status === 'question' && ($r->requester_id === $u->id || $u->hasPermission('fonds.manage')),
                'decide' => in_array($r->status, ['submitted', 'question'], true) && $this->isApprover($u, $r),
                'provide' => in_array($r->status, ['approved', 'partial'], true) && $this->isProvider($u, $r),
                'confirm' => in_array($r->status, ['provided', 'partial'], true) && ($r->requester_id === $u->id || $u->hasPermission('fonds.manage')),
                'cancel' => in_array($r->status, ['draft', 'submitted', 'question', 'approved'], true) && $r->total_provided <= 0 && ($r->requester_id === $u->id || $u->hasPermission('fonds.manage')),
            ],
        ];
        if ($full) {
            $out['lines'] = FundLine::where('request_id', $r->id)->orderBy('sort')->orderBy('id')->get()->map(fn ($l) => $l->only(['id', 'type', 'description', 'product_id', 'quantity', 'unit_amount', 'amount',
                'approved_quantity', 'approved_amount', 'provided_quantity', 'provided_amount', 'note']) + ['type_label' => self::TYPES[$l->type] ?? $l->type])->values();
            $ev = FundEvent::where('request_id', $r->id)->orderBy('id')->get();
            $who = User::whereIn('id', $ev->pluck('user_id')->filter())->pluck('name', 'id');
            $out['events'] = $ev->map(fn ($e) => ['id' => $e->id, 'type' => $e->type, 'label' => self::EVENTS[$e->type] ?? $e->type, 'body' => $e->body, 'data' => $e->data,
                'user' => $who[$e->user_id] ?? null, 'at' => $e->created_at])->values();
        }

        return $out;
    }

    /** Tableau de bord : volumes, montants, délais, services, en attente. */
    public function dashboard(Company $c, User $u, string $from, string $to): array
    {
        $all = $this->visible($u)->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->where('status', '!=', 'draft')->get();
        $open = $this->visible($u)->whereIn('status', ['submitted', 'question', 'approved', 'partial', 'provided'])->get();
        $delays = $all->filter(fn ($r) => $r->submitted_at && $r->decided_at)->map(fn ($r) => $r->submitted_at->diffInHours($r->decided_at));
        $provideDelays = $all->filter(fn ($r) => $r->decided_at && $r->provided_at)->map(fn ($r) => $r->decided_at->diffInHours($r->provided_at));
        $byDept = $all->groupBy(fn ($r) => $r->department ?: 'Sans service')->map(fn ($g, $k) => ['department' => $k, 'count' => $g->count(),
            'requested' => round($g->sum('total_requested'), 2), 'approved' => round($g->whereNotIn('status', ['rejected', 'cancelled'])->sum('total_approved'), 2), 'provided' => round($g->sum('total_provided'), 2)])
            ->sortByDesc('requested')->values();
        $start = Carbon::parse($to)->startOfMonth()->subMonths(11);
        $hist = $this->visible($u)->where('status', '!=', 'draft')->where('created_at', '>=', $start)->get()->groupBy(fn ($r) => $r->created_at->format('Y-m'));
        $months = [];
        for ($m = $start->copy(); $m->lte(Carbon::parse($to)); $m->addMonth()) {
            $g = $hist->get($m->format('Y-m'), collect());
            $months[] = ['month' => $m->format('Y-m'), 'requested' => round($g->sum('total_requested'), 2), 'provided' => round($g->sum('total_provided'), 2)];
        }
        $decided = $all->whereIn('status', ['approved', 'partial', 'provided', 'closed', 'rejected']);

        return [
            'from' => $from, 'to' => $to,
            'kpis' => [
                'count' => $all->count(), 'requested' => round($all->sum('total_requested'), 2),
                'approved' => round($all->whereNotIn('status', ['rejected', 'cancelled'])->sum('total_approved'), 2), 'provided' => round($all->sum('total_provided'), 2),
                'approval_rate' => $decided->count() ? round($decided->where('status', '!=', 'rejected')->count() * 100 / $decided->count()) : null,
                'decision_hours' => $delays->count() ? round($delays->avg()) : null, 'provision_hours' => $provideDelays->count() ? round($provideDelays->avg()) : null,
                'open' => $open->count(), 'open_amount' => round($open->sum(fn ($r) => in_array($r->status, ['approved', 'partial'], true) ? $r->total_approved - $r->total_provided : $r->total_requested), 2),
                'late' => $open->filter(fn ($r) => $r->needed_by && $r->needed_by->lt(now()->startOfDay()))->count(),
            ],
            'by_status' => collect(self::STATUSES)->map(fn ($l, $k) => ['status' => $k, 'label' => $l, 'count' => $all->where('status', $k)->count()])->values()->filter(fn ($x) => $x['count'] > 0 && $x['status'] !== 'draft')->values(),
            'by_department' => $byDept, 'months' => $months,
            'mine' => [
                'decide' => $open->filter(fn ($r) => in_array($r->status, ['submitted'], true) && $this->isApprover($u, $r))->count(),
                'provide' => $open->filter(fn ($r) => in_array($r->status, ['approved', 'partial'], true) && $this->isProvider($u, $r))->count(),
                'answer' => $open->where('status', 'question')->where('requester_id', $u->id)->count(),
                'confirm' => $open->where('status', 'provided')->where('requester_id', $u->id)->count(),
            ],
            'urgent' => $open->where('priority', 'urgent')->sortBy('needed_by')->take(6)->map(fn ($r) => $this->toApi($r, $u))->values(),
        ];
    }
}
