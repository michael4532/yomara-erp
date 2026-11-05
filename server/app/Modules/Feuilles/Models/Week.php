<?php

namespace App\Modules\Feuilles\Models;

use App\Models\Concerns\Syncable;
use App\Modules\Feuilles\FeuillesService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Feuille de temps d'une personne pour une semaine (lundi → dimanche). */
class Week extends Model
{
    use Syncable;

    protected $table = 'tt_weeks';

    public static string $syncEntity = 'tt_weeks';

    public static string $viewPermission = 'feuilles_temps.submit|feuilles_temps.approve';

    public static string $managePermission = 'feuilles_temps.submit|feuilles_temps.approve';

    public const STATUSES = ['draft' => 'Brouillon', 'submitted' => 'À valider', 'approved' => 'Validée', 'rejected' => 'À corriger'];

    protected $fillable = ['week_start', 'status', 'comment'];

    protected $attributes = ['status' => 'draft'];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'week_start' => 'date:Y-m-d', 'hours' => 'float', 'submitted_at' => 'datetime', 'decided_at' => 'datetime', 'history' => 'array', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return ['week_start' => 'required|date', 'status' => 'in:draft,submitted,approved,rejected', 'comment' => 'nullable|string|max:2000'];
    }

    public static function syncVisibility(Builder $q, $user): void
    {
        if (! $user->hasPermission('feuilles_temps.approve')) {
            $q->where('user_id', $user->id);
        }
    }

    public ?string $fromStatus = null;

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $u = auth()->user();
        $svc = app(FeuillesService::class);
        if ($creating) {
            $this->user_id = $u->id;
            $this->user_name = $u->name;
            $monday = Carbon::parse($data['week_start'])->startOfWeek()->toDateString();
            if (static::where('user_id', $u->id)->where('week_start', $monday)->exists()) {
                throw new \DomainException('Votre feuille de cette semaine existe déjà.');
            }
            $data['week_start'] = $monday;
        } elseif (isset($data['week_start']) && Carbon::parse($data['week_start'])->toDateString() !== $this->week_start->toDateString()) {
            throw new \DomainException('La semaine d\'une feuille ne change pas.');
        }
        $from = $creating ? 'draft' : $this->status;
        $to = $data['status'] ?? $from;
        $this->fromStatus = $from;
        $own = $creating || $this->user_id === $u->id;
        $approver = $u->hasPermission('feuilles_temps.approve');
        if ($from !== $to) {
            $ok = match (true) {
                $own && in_array($from, ['draft', 'rejected'], true) && $to === 'submitted' => true,
                $own && $from === 'submitted' && $to === 'draft' => true,
                ! $own && $approver && $from === 'submitted' && in_array($to, ['approved', 'rejected'], true) => true,
                ! $own && $approver && $from === 'approved' && $to === 'draft' => true,
                default => false,
            };
            if (! $ok) {
                throw new \DomainException(match (true) {
                    $own && in_array($to, ['approved', 'rejected'], true) => 'Vous ne pouvez pas valider votre propre feuille de temps.',
                    $from === 'approved' => 'Feuille validée : seul un responsable peut la rouvrir.',
                    default => 'Changement impossible : '.(self::STATUSES[$from] ?? $from).' → '.(self::STATUSES[$to] ?? $to).'.',
                });
            }
            if ($to === 'rejected' && trim((string) ($data['comment'] ?? '')) === '') {
                throw new \DomainException('Expliquez ce qu\'il faut corriger.');
            }
            $userId = $this->user_id ?? $u->id;
            $hours = $svc->total($userId, $data['week_start'] ?? $this->week_start->toDateString());
            if ($to === 'submitted') {
                if ($hours <= 0) {
                    throw new \DomainException('Aucun temps saisi cette semaine.');
                }
                $this->submitted_at = now();
                $data['comment'] = null;
            }
            if (in_array($to, ['approved', 'rejected'], true)) {
                $this->decided_at = now();
                $this->decided_by = $u->name;
            }
            $this->hours = $hours;
            $this->history = array_merge($this->history ?? [], [['status' => $to, 'at' => now()->toIso8601String(), 'by' => $u->name, 'comment' => $data['comment'] ?? null, 'hours' => $hours]]);
        } elseif (! $own) {
            throw new \DomainException('Seule la personne concernée modifie sa feuille.');
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        if ($this->fromStatus !== null && $this->fromStatus !== $this->status) {
            app(FeuillesService::class)->notify($this, $this->fromStatus);
        }
    }

    public function beforeEntityDelete(): void
    {
        throw new \DomainException('Une feuille de temps ne se supprime pas : remettez-la en brouillon.');
    }

    public function toSyncArray(): array
    {
        return $this->toArray() + ['user_id' => $this->user_id, 'user_name' => $this->user_name, 'hours' => $this->hours];
    }
}
