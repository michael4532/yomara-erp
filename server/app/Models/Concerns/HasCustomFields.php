<?php

namespace App\Models\Concerns;

use App\Modules\Champs\CustomFields;

/**
 * Champs personnalisés (module « champs ») : valeurs rangées dans la colonne JSON « custom ».
 * Contrôle des types à chaque enregistrement ; champs obligatoires et valeurs par défaut pour les
 * saisies des utilisateurs (synchronisation et API), pas pour les écritures automatiques.
 */
trait HasCustomFields
{
    public function initializeHasCustomFields(): void
    {
        $this->mergeFillable(['custom']);
        $this->mergeCasts(['custom' => 'array']);
    }

    public static function bootHasCustomFields(): void
    {
        static::saving(function ($m) {
            if (! CustomFields::enabled() || ! $m->isDirty('custom')) {
                return;
            }
            $prev = $m->exists ? json_decode((string) ($m->getRawOriginal('custom') ?? 'null'), true) : null;
            $new = $m->custom;
            // Les clés absentes de l'envoi gardent leur valeur ; une clé envoyée vide efface.
            $merged = is_array($prev) ? array_merge($prev, is_array($new) ? $new : []) : $new;
            $m->custom = CustomFields::clean($m->customEntity(), $merged, is_array($merged) ? $merged : null);
        });
    }

    public function customEntity(): string
    {
        return static::$syncEntity;
    }

    /** Appelé par EntityWriter avant l'écriture. */
    public function customEntityWrite(array &$data, bool $creating): void
    {
        if (! CustomFields::enabled() || (property_exists($this, 'systemWrite') && static::$systemWrite)) {
            return;
        }
        if (array_key_exists('custom', $data) && ! is_array($data['custom']) && $data['custom'] !== null) {
            throw new \DomainException('Champs personnalisés : format invalide.');
        }
        CustomFields::required($this->customEntity(), $data, $creating ? null : ($this->custom ?? []), $creating);
    }
}
