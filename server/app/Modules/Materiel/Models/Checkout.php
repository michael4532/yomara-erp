<?php

namespace App\Modules\Materiel\Models;

use App\Models\ActivityLog;
use App\Models\AppNotification;
use App\Models\Concerns\Syncable;
use App\Models\Sequence;
use App\Models\User;
use App\Modules\Materiel\MaterielPdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Sortie de matériel : un porteur part avec un ou plusieurs articles (intervention, chantier, tournage),
 * puis les rend, article par article, avec l'état constaté. Le bon de sortie est signé par le porteur
 * et le responsable du département (signature électronique si le module est actif, sinon sur papier).
 */
class Checkout extends Model
{
    use Syncable;

    protected $table = 'mat_checkouts';

    public static string $syncEntity = 'mat_checkouts';

    public static string $viewPermission = 'materiel.use|materiel.view|materiel.manage';

    public static string $managePermission = 'materiel.use|materiel.manage';

    public const STATUSES = ['out' => 'Sorti', 'partial' => 'Retour partiel', 'returned' => 'Rendu', 'cancelled' => 'Annulé'];

    public const CONDITIONS = ['ok' => 'Bon état', 'worn' => 'Usé', 'damaged' => 'Endommagé', 'lost' => 'Perdu'];

    protected $fillable = ['employee_id', 'holder_name', 'department', 'purpose', 'destination', 'partner_id', 'out_at', 'expected_back_at', 'status', 'lines', 'signers', 'notes'];

    protected function casts(): array
    {
        return [
            'out_at' => 'datetime', 'expected_back_at' => 'datetime', 'back_at' => 'datetime', 'lines' => 'array', 'signers' => 'array',
            'holder_user_id' => 'integer', 'created_by' => 'integer', 'version' => 'integer',
        ];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'employee_id' => ['nullable', 'uuid', Rule::exists('rh_employees', 'id')->where('company_id', $company)],
            'holder_name' => 'nullable|string|max:150',
            'department' => 'nullable|string|max:120',
            'purpose' => 'required|string|max:200',
            'destination' => 'nullable|string|max:200',
            'partner_id' => ['nullable', 'string', 'max:40', \App\Support\TenantRef::of('partners')],
            'out_at' => 'nullable|date',
            'expected_back_at' => 'nullable|date',
            'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
            'lines' => 'required|array|min:1|max:100',
            'lines.*.item_id' => 'required|uuid',
            'lines.*.condition_out' => ['nullable', Rule::in(array_keys(self::CONDITIONS))],
            'lines.*.returned' => 'nullable|boolean',
            'lines.*.condition_in' => ['nullable', Rule::in(array_keys(self::CONDITIONS))],
            'lines.*.note' => 'nullable|string|max:500',
            'signers' => 'nullable|array|max:6',
            'signers.*.name' => 'required|string|max:150',
            'signers.*.label' => 'nullable|string|max:120',
            'signers.*.employee_id' => ['nullable', 'uuid', Rule::exists('rh_employees', 'id')->where('company_id', $company)],
            'signers.*.user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $company)],
            'signers.*.email' => 'nullable|email|max:190',
            'notes' => 'nullable|string|max:5000',
        ];
    }

    public static function isStaff($u): bool
    {
        return ! $u || $u->hasPermission('materiel.manage');
    }

    /** Sortie en cours contenant cet article (non rendu). */
    public static function openFor(string $itemId, ?string $except = null): ?self
    {
        return static::whereIn('status', ['out', 'partial'])->where('id', '!=', $except ?? '')->get()
            ->first(fn ($c) => collect($c->lines)->contains(fn ($l) => ($l['item_id'] ?? null) === $itemId && empty($l['returned'])));
    }

    public static function syncVisibility(Builder $q, $user): void
    {
        if (! $user->hasPermission('materiel.view|materiel.manage')) {
            $mine = Schema::hasTable('rh_employees') ? DB::table('rh_employees')->where('company_id', app('tenant')->id)->where('user_id', $user->id)->pluck('id')->all() : [];
            $q->where(fn ($w) => $w->where('holder_user_id', $user->id)->orWhere('created_by', $user->id)->orWhereIn('employee_id', $mine));
        }
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $user = auth()->user();
        $staff = self::isStaff($user);
        $previous = $creating ? null : $this->status;

        if ($creating) {
            $own = $user && Schema::hasTable('rh_employees') ? DB::table('rh_employees')->where('company_id', app('tenant')->id)->where('user_id', $user->id)->whereNull('deleted_at')->value('id') : null;
            if (! $staff) {
                // Sans le droit de gestion : on sort du matériel pour soi
                $data['employee_id'] = $own;
                $data['holder_name'] = $own ? null : $user->name;
                unset($data['signers']);
                $this->holder_user_id = $user->id;
            } elseif (empty($data['employee_id']) && blank($data['holder_name'] ?? null)) {
                throw new \DomainException('Indiquez qui emporte le matériel.');
            }
            $emp = ! empty($data['employee_id']) ? DB::table('rh_employees')->where('id', $data['employee_id'])->first() : null;
            if ($emp?->user_id) {
                $this->holder_user_id = $emp->user_id;
            }
            $data['department'] = trim((string) ($data['department'] ?? '')) ?: ($emp->department ?? null);

            // Articles : disponibles, une seule fois, figés sur le bon (nom, n° de série)
            $lines = [];
            $seen = [];
            foreach ($data['lines'] as $l) {
                $item = Item::find($l['item_id']);
                if (! $item) {
                    throw new \DomainException('Matériel introuvable.');
                }
                if (isset($seen[$item->id])) {
                    throw new \DomainException($item->name.' figure deux fois sur la sortie.');
                }
                $seen[$item->id] = true;
                if ($item->status !== 'available' || self::openFor($item->id)) {
                    $open = self::openFor($item->id);
                    throw new \DomainException($item->name.' n\'est pas disponible'.($open ? ' (sorti sur '.$open->number.')' : ' ('.(Item::STATUSES[$item->status] ?? $item->status).')').'.');
                }
                $lines[] = ['item_id' => $item->id, 'name' => $item->name, 'code' => $item->code, 'serial' => $item->serial, 'condition_out' => $l['condition_out'] ?? 'ok',
                    'returned' => false, 'returned_at' => null, 'condition_in' => null, 'note' => $l['note'] ?? null];
            }
            $data['lines'] = $lines;
            $data['status'] = 'out';
            $data['out_at'] = $data['out_at'] ?? now()->toDateTimeString();
            if (! empty($data['expected_back_at']) && $data['expected_back_at'] < $data['out_at']) {
                throw new \DomainException('Le retour prévu précède la sortie.');
            }
            $data['signers'] = $this->defaultSigners($data, $emp);
            $this->number = Sequence::next($this->company_id ?? app('tenant')->id, 'mat.checkout', 'BS');
            $this->created_by = $user?->id;

            return;
        }

        // Mise à jour : retours article par article, prolongation, annulation
        $mine = $user && ($this->holder_user_id === $user->id || $this->created_by === $user->id);
        if (! $staff && ! $mine) {
            throw new \DomainException('Cette sortie ne vous concerne pas.');
        }
        if (in_array($previous, ['returned', 'cancelled'], true)) {
            throw new \DomainException('Cette sortie est close.');
        }
        foreach (['employee_id', 'holder_name', 'out_at', 'signers', 'department'] as $k) {
            unset($data[$k]);
        }
        if (($data['status'] ?? null) === 'cancelled') {
            if (collect($this->lines)->contains(fn ($l) => ! empty($l['returned']))) {
                throw new \DomainException('Des articles sont déjà rendus : enregistrez le retour des autres.');
            }
            $data = ['status' => 'cancelled', 'notes' => $data['notes'] ?? $this->notes];

            return;
        }
        if (isset($data['lines'])) {
            $incoming = collect($data['lines'])->keyBy('item_id');
            $lines = [];
            foreach ($this->lines as $old) {
                $in = $incoming[$old['item_id']] ?? null;
                if (! empty($old['returned'])) {
                    $lines[] = $old; // un retour enregistré ne s'annule pas

                    continue;
                }
                if ($in && ! empty($in['returned'])) {
                    $old['returned'] = true;
                    $old['returned_at'] = now()->toDateTimeString();
                    $old['condition_in'] = $in['condition_in'] ?? 'ok';
                    $old['note'] = $in['note'] ?? $old['note'];
                    $old['received_by'] = $user?->name;
                }
                $lines[] = $old;
            }
            $data['lines'] = $lines;
            $left = collect($lines)->filter(fn ($l) => empty($l['returned']))->count();
            $data['status'] = $left === 0 ? 'returned' : ($left < count($lines) ? 'partial' : 'out');
            if ($left === 0) {
                $this->back_at = now();
            }
        } else {
            unset($data['status']);
        }
    }

    /** Signataires du bon : le porteur et le(s) responsable(s) du département. */
    protected function defaultSigners(array $data, ?object $emp): array
    {
        if (! empty($data['signers'])) {
            return array_values($data['signers']);
        }
        $out = [];
        if ($emp) {
            $out[] = ['name' => trim($emp->last_name.' '.$emp->first_name), 'label' => 'Porteur du matériel', 'employee_id' => $emp->id, 'user_id' => $emp->user_id, 'email' => $emp->email];
        } elseif (! empty($data['holder_name'])) {
            $u = auth()->user();
            $out[] = ['name' => $data['holder_name'], 'label' => 'Porteur du matériel', 'user_id' => $u?->id, 'email' => $u?->email];
        }
        foreach (self::departmentManagers($data['department'] ?? null, $emp) as $m) {
            if (! collect($out)->contains(fn ($x) => ($x['employee_id'] ?? null) === $m->id)) {
                $out[] = ['name' => trim($m->last_name.' '.$m->first_name), 'label' => 'Responsable du département', 'employee_id' => $m->id, 'user_id' => $m->user_id, 'email' => $m->email];
            }
        }

        return $out;
    }

    /** Responsables du département : ceux des réglages du module, sinon le responsable direct du porteur. */
    public static function departmentManagers(?string $department, ?object $emp): array
    {
        if (! Schema::hasTable('rh_employees')) {
            return [];
        }
        $map = (array) data_get(app('tenant')->settings, 'materiel.managers', []);
        $key = collect(array_keys($map))->first(fn ($k) => mb_strtolower(trim($k)) === mb_strtolower(trim((string) $department)));
        if ($key !== null && ! empty($map[$key])) {
            return DB::table('rh_employees')->whereIn('id', (array) $map[$key])->whereNull('deleted_at')->get()->all();
        }
        if ($emp && class_exists(\App\Modules\Rh\RequestNotifier::class)) {
            $m = \App\Modules\Rh\RequestNotifier::managerOf(\App\Modules\Rh\Models\Employee::find($emp->id));

            return $m ? [(object) $m->only(['id', 'first_name', 'last_name', 'user_id', 'email'])] : [];
        }

        return [];
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        $user = auth()->user();
        // Statut des articles : sortis à la création, puis selon l'état constaté au retour
        $prev = $creating ? [] : ($this->wasChanged('lines') ? ($this->getPrevious()['lines'] ?? []) : $this->lines);
        $before = collect(is_string($prev) ? json_decode($prev, true) : (array) $prev)->keyBy('item_id');
        foreach ($this->lines as $l) {
            $was = $before[$l['item_id']] ?? null;
            $target = match (true) {
                $creating => 'out',
                $this->status === 'cancelled' && empty($l['returned']) => 'available',
                ! empty($l['returned']) && empty($was['returned']) => match ($l['condition_in'] ?? 'ok') {
                    'lost' => 'lost',
                    'damaged' => 'repair',
                    default => 'available',
                },
                default => null,
            };
            if ($target && ($item = Item::find($l['item_id'])) && $item->status !== $target) {
                $item->forceFill(['status' => $target])->save();
            }
        }
        if ($creating) {
            ActivityLog::record('materiel.checkout', $this, ['number' => $this->number, 'items' => count($this->lines)]);
            $this->requestSignature();
        } elseif ($this->wasChanged('status')) {
            ActivityLog::record('materiel.'.$this->status, $this, ['number' => $this->number]);
            $bad = collect($this->lines)->filter(fn ($l) => in_array($l['condition_in'] ?? null, ['damaged', 'lost'], true));
            if ($bad->isNotEmpty()) {
                $staff = User::where('company_id', $this->company_id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('materiel.manage'))->pluck('id')->all();
                AppNotification::send($this->company_id, $staff, 'materiel', 'Matériel rendu endommagé ou perdu', $this->number.' : '.$bad->map(fn ($l) => $l['name'].' ('.self::CONDITIONS[$l['condition_in']].')')->implode(', '), 'materiel', $this->id, $user?->id);
            }
        }
    }

    /** Bon de sortie envoyé en signature électronique (module Signature), sinon il s'imprime et se signe sur papier. */
    public function requestSignature(): ?string
    {
        $c = app('tenant');
        $signers = array_values(array_filter((array) $this->signers, fn ($s) => ! empty($s['user_id']) || ! empty($s['email'])));
        if (! $c->hasModule('signature') || ! $signers || ! class_exists(\App\Modules\Signature\SignatureService::class)) {
            return null;
        }
        $by = auth()->user() ?? User::where('company_id', $c->id)->first();
        try {
            $svc = app(\App\Modules\Signature\SignatureService::class);
            $r = $svc->create($c, $by, app(MaterielPdf::class)->checkout($c, $this), 'Bon de sortie '.$this->number.'.pdf', [
                'title' => 'Bon de sortie de matériel '.$this->number, 'message' => 'Merci de signer le bon de sortie du matériel : '.collect($this->lines)->pluck('name')->implode(', ').'.',
                'source_type' => 'mat_checkout', 'source_id' => $this->id, 'expires_days' => 7,
            ], array_map(fn ($s) => ['name' => $s['name'], 'label' => $s['label'] ?? null, 'email' => $s['email'] ?? null, 'user_id' => $s['user_id'] ?? null], $signers));
            $svc->send($c, $r);
            $this->forceFill(['signature_id' => $r->id, 'signature_status' => 'sent'])->save();

            return $r->id;
        } catch (\Throwable $e) {
            report($e);
            $this->forceFill(['signature_status' => 'failed'])->save();

            return null;
        }
    }

    public static function signatureChanged(\App\Modules\Signature\Models\SignRequest $r): void
    {
        $map = ['completed' => 'signed', 'declined' => 'declined', 'cancelled' => 'cancelled', 'expired' => 'expired', 'pending' => 'sent'];
        $c = static::withoutGlobalScopes()->where('id', $r->source_id)->first();
        if ($c && isset($map[$r->status]) && $c->signature_status !== $map[$r->status]) {
            $c->forceFill(['signature_status' => $map[$r->status]])->save();
        }
    }

    public function beforeEntityDelete(): void
    {
        throw new \DomainException('Une sortie ne se supprime pas : annulez-la.');
    }
}
