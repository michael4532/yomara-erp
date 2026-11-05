<?php

namespace App\Models\Concerns;

use App\Models\SyncChange;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Entité synchronisable hors ligne.
 *  - clé primaire UUID (générable côté client, hors ligne) ;
 *  - suppression douce (la suppression se propage aux autres appareils) ;
 *  - "version" incrémentée à chaque écriture (détection de conflits) ;
 *  - chaque écriture est inscrite dans sync_changes (curseur de pull).
 *
 * Le modèle doit déclarer :
 *   public static string $syncEntity = 'products';
 *   public static function syncRules(): array { ... }  // règles de validation
 */
trait Syncable
{
    use BelongsToCompany, HasUuids, SoftDeletes;

    public static function bootSyncable(): void
    {
        static::saving(function ($model) {
            $model->version = ($model->version ?? 0) + 1;
        });

        static::saved(function ($model) {
            $model->logSyncChange('upsert');
            // Webhooks sortants (Zapier, Make, n8n, sites…) : création si l'insertion vient d'avoir lieu
            $op = $model->wasRecentlyCreated && ! $model->getChanges() ? 'created' : 'updated';
            \App\Integrations\Webhooks::model($model, $op);
            \App\Integrations\Events::fire($model, $op);
        });
        static::deleted(function ($model) {
            $model->logSyncChange('delete');
            \App\Integrations\Webhooks::model($model, 'deleted');
        });
        static::restored(fn ($model) => $model->logSyncChange('upsert'));
    }

    public function logSyncChange(string $op): void
    {
        SyncChange::create([
            'company_id' => $this->company_id,
            'entity' => static::$syncEntity,
            'record_id' => $this->getKey(),
            'op' => $op,
        ]);
    }

    /** Représentation envoyée aux clients (surchargeable). */
    public function toSyncArray(): array
    {
        return $this->toArray();
    }

    /** Champs que le client a le droit d'écrire (par défaut : $fillable). */
    public static function syncWritable(): array
    {
        return (new static)->getFillable();
    }
}
