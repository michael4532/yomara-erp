<?php

namespace App\Modules\Pos\Models;

use App\Models\Concerns\Syncable;
use App\Modules\Pos\PosReport;
use Illuminate\Database\Eloquent\Model;

/**
 * Session de caisse : ouverte avec un fond de caisse, clôturée avec le montant
 * compté. L'ouverture et la clôture fonctionnent hors ligne ; à la synchro le
 * serveur recalcule l'attendu et l'écart à partir des tickets réellement reçus.
 */
class PosSession extends Model
{
    use Syncable;

    public static string $syncEntity = 'pos_sessions';

    public static array $searchable = ['user_name', 'notes'];

    public static string $viewPermission = 'pos.use';

    public static string $managePermission = 'pos.use';

    protected $fillable = ['register_id', 'status', 'opened_at', 'closed_at', 'opening_cash', 'counted_cash', 'notes'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'float',
            'counted_cash' => 'float',
            'expected_cash' => 'float',
            'difference' => 'float',
            'total_sales' => 'float',
            'tickets_count' => 'integer',
            'version' => 'integer',
        ];
    }

    public static function syncRules(): array
    {
        return [
            'register_id' => ['required', 'uuid', \App\Support\TenantRef::of('pos_registers')],
            'status' => 'sometimes|in:open,closed',
            'opened_at' => 'nullable|date',
            'closed_at' => 'nullable|date',
            'opening_cash' => 'sometimes|numeric|min:0',
            'counted_cash' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ];
    }

    /** pos.use : le caissier ne voit (et ne clôture) que ses propres sessions ; pos.manage voit tous les rapports. */
    public static function syncVisibility(\Illuminate\Database\Eloquent\Builder $q, $user): void
    {
        if (! $user->hasPermission('pos.manage')) {
            $q->where('user_id', $user->id);
        }
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (! $creating && $this->status === 'closed') {
            unset($data['status'], $data['counted_cash'], $data['opening_cash'], $data['register_id']);
            if (array_diff(array_keys($data), ['notes', 'closed_at'])) {
                throw new \DomainException('Cette session de caisse est déjà clôturée.');
            }
        }
        if ($creating) {
            $user = auth()->user();
            $this->user_id = $user?->id;
            $this->user_name = $user?->name;
            $data['opened_at'] ??= now()->toIso8601String();
        }
        if (($data['status'] ?? null) === 'closed') {
            $data['closed_at'] ??= now()->toIso8601String();
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        if ($this->status === 'closed') {
            $this->refreshTotals();
        }
    }

    /** Recalcule les chiffres de clôture depuis les tickets reçus par le serveur. */
    public function refreshTotals(): void
    {
        $r = app(PosReport::class)->forSession($this);
        $this->total_sales = $r['total_sales'];
        $this->tickets_count = $r['tickets_count'];
        $this->expected_cash = $r['expected_cash'];
        $this->difference = $this->counted_cash !== null ? round($this->counted_cash - $r['expected_cash'], 2) : null;
        $this->saveQuietly();
        $this->logSyncChange('upsert');
    }

    public function beforeEntityDelete(): void
    {
        throw new \DomainException('Une session de caisse ne se supprime pas.');
    }
}
