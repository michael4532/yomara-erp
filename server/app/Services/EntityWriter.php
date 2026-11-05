<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Point d'écriture unique des entités synchronisables.
 * Utilisé à la fois par l'API REST classique et par la synchronisation hors ligne,
 * pour que les règles métier soient appliquées de la même façon dans les deux cas.
 *
 * Crochets optionnels sur le modèle :
 *   beforeEntityWrite(array &$data, bool $creating): void
 *   afterEntityWrite(array $data, bool $creating): void
 *   beforeEntityDelete(): void    (lever une exception pour refuser)
 */
class EntityWriter
{
    public const APPLIED = 'applied';
    public const CONFLICT = 'conflict';
    public const REJECTED = 'rejected';

    /**
     * @return array{status:string, record:?Model, message:?string, errors:?array}
     */
    public function upsert(string $class, ?string $id, array $data, ?int $baseVersion = null): array
    {
        /** @var Model $existing */
        $existing = $id ? $class::withTrashed()->find($id) : null;
        $creating = ! $existing;

        if ($existing && $baseVersion !== null && (int) $existing->version !== $baseVersion) {
            return $this->result(self::CONFLICT, $existing, 'Cet enregistrement a été modifié ailleurs entre-temps.');
        }

        $rules = $class::syncRules();
        if (! $creating) {
            // Mise à jour partielle : seuls les champs envoyés sont validés.
            $rules = array_map(fn ($r) => match (true) {
                is_string($r) => str_starts_with($r, 'sometimes') ? $r : 'sometimes|'.$r,
                is_array($r) => in_array('sometimes', $r, true) ? $r : array_merge(['sometimes'], $r),
                default => $r,
            }, $rules);
        }

        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            return $this->result(self::REJECTED, $existing, 'Données invalides.', $validator->errors()->toArray());
        }

        try {
            $record = DB::transaction(function () use ($class, $id, $data, $existing, $creating) {
                $model = $existing ?? new $class;
                if ($creating && $id) {
                    $model->{$model->getKeyName()} = $id;
                }

                if (method_exists($model, 'customEntityWrite')) {
                    $model->customEntityWrite($data, $creating);
                }
                if (method_exists($model, 'beforeEntityWrite')) {
                    $model->beforeEntityWrite($data, $creating);
                }

                $model->fill(array_intersect_key($data, array_flip($class::syncWritable())));
                if ($model->exists && method_exists($model, 'trashed') && $model->trashed()) {
                    $model->deleted_at = null;
                }
                $model->save();

                if (method_exists($model, 'afterEntityWrite')) {
                    $model->afterEntityWrite($data, $creating);
                }

                return $model->fresh();
            });
        } catch (ValidationException $e) {
            return $this->result(self::REJECTED, $existing, $e->getMessage(), $e->errors());
        } catch (\DomainException $e) {
            return $this->result(self::REJECTED, $existing?->fresh(), $e->getMessage());
        }

        return $this->result(self::APPLIED, $record);
    }

    public function delete(string $class, string $id, ?int $baseVersion = null): array
    {
        $existing = $class::withTrashed()->find($id);
        if (! $existing || $existing->trashed()) {
            return $this->result(self::APPLIED, null); // déjà supprimé : idempotent
        }

        if ($baseVersion !== null && (int) $existing->version !== $baseVersion) {
            return $this->result(self::CONFLICT, $existing, 'Cet enregistrement a été modifié ailleurs entre-temps.');
        }

        try {
            DB::transaction(function () use ($existing) {
                if (method_exists($existing, 'beforeEntityDelete')) {
                    $existing->beforeEntityDelete();
                }
                $existing->delete();
            });
        } catch (\DomainException $e) {
            return $this->result(self::REJECTED, $existing, $e->getMessage());
        }

        return $this->result(self::APPLIED, null);
    }

    protected function result(string $status, ?Model $record, ?string $message = null, ?array $errors = null): array
    {
        return compact('status', 'record', 'message', 'errors');
    }
}
