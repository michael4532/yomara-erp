<?php

namespace App\Modules\Logistique\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Tournée : un livreur, un véhicule, une date, des livraisons dans un ordre. */
class Tour extends Model
{
    use Syncable;

    protected $table = 'lg_tours';

    public static string $syncEntity = 'lg_tours';

    public static string $viewPermission = 'logistique.view|logistique.deliver|logistique.manage';

    public static string $managePermission = 'logistique.manage';

    protected $fillable = ['name', 'date', 'driver_id', 'vehicle_id', 'status', 'notes'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'driver_id' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'name' => 'nullable|string|max:120',
            'date' => 'required|date',
            'driver_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $company)],
            'vehicle_id' => ['nullable', 'uuid', Rule::exists('lg_vehicles', 'id')->where('company_id', $company)],
            'status' => 'nullable|in:planned,in_progress,done',
            'notes' => 'nullable|string|max:2000',
        ];
    }

    public function beforeEntityDelete(): void
    {
        foreach (Delivery::where('tour_id', $this->id)->get() as $d) {
            $d->tour_id = null;
            $d->save();
        }
    }

    /** Avance automatiquement avec les livraisons. */
    public function refreshStatus(): void
    {
        $rows = Delivery::where('tour_id', $this->id)->whereNotIn('status', ['cancelled'])->get(['status']);
        if ($rows->isEmpty()) {
            return;
        }
        $open = $rows->whereIn('status', ['draft', 'planned', 'in_transit'])->count();
        $status = $open === 0 ? 'done' : ($rows->whereNotIn('status', ['draft', 'planned'])->count() > 0 ? 'in_progress' : $this->status);
        if ($status !== $this->status) {
            $this->status = $status;
            $this->started_at ??= $status !== 'planned' ? now() : null;
            $this->finished_at = $status === 'done' ? now() : null;
            $this->save();
        }
    }
}
