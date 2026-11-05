<?php

namespace App\Modules\Champs\Models;

use App\Models\Concerns\Syncable;
use App\Modules\Champs\CustomFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Définition d'un champ personnalisé (la valeur est rangée dans la colonne « custom » de l'enregistrement). */
class Field extends Model
{
    use Syncable;

    protected $table = 'cf_fields';

    public static string $syncEntity = 'cf_fields';

    public static string $viewPermission = CustomFields::READERS;

    public static string $managePermission = 'champs.manage';

    protected $fillable = ['entity', 'key', 'label', 'type', 'options', 'required', 'default_value', 'help', 'show_on_pdf', 'show_in_list', 'sort', 'active'];

    protected $attributes = ['required' => false, 'show_on_pdf' => false, 'show_in_list' => false, 'sort' => 0, 'active' => true];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['options' => 'array', 'required' => 'boolean', 'show_on_pdf' => 'boolean', 'show_in_list' => 'boolean', 'active' => 'boolean', 'sort' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'entity' => 'required|in:'.implode(',', array_keys(CustomFields::ENTITIES)),
            'key' => 'nullable|string|max:40',
            'label' => 'required|string|max:120',
            'type' => 'required|in:'.implode(',', array_keys(CustomFields::TYPES)),
            'options' => 'nullable|array|max:100',
            'required' => 'boolean',
            'default_value' => 'nullable|string|max:255',
            'help' => 'nullable|string|max:255',
            'show_on_pdf' => 'boolean',
            'show_in_list' => 'boolean',
            'sort' => 'integer|min:0|max:999',
            'active' => 'boolean',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (! $creating) {
            // La clé, l'objet et le type ne changent plus : les valeurs déjà saisies restent lisibles.
            foreach (['entity', 'key', 'type'] as $k) {
                if (array_key_exists($k, $data) && (string) $data[$k] !== (string) $this->{$k}) {
                    throw new \DomainException('L\'objet, la clé et le type d\'un champ ne se modifient plus après sa création.');
                }
            }
        } else {
            $key = Str::slug((string) ($data['key'] ?? '') ?: (string) $data['label'], '_');
            $key = substr(preg_replace('/[^a-z0-9_]/', '', $key), 0, 40) ?: 'champ';
            if (preg_match('/^\d/', $key)) {
                $key = 'c_'.$key;
            }
            $base = $key;
            $i = 2;
            while (static::withTrashed()->where('entity', $data['entity'])->where('key', $key)->exists()) {
                $key = substr($base, 0, 36).'_'.$i++;
            }
            $data['key'] = $key;
            if (static::where('entity', $data['entity'])->count() >= 50) {
                throw new \DomainException('50 champs au maximum par objet.');
            }
        }
        $type = $data['type'] ?? $this->type;
        if (in_array($type, ['select', 'multiselect'], true)) {
            $opts = array_key_exists('options', $data) ? $data['options'] : $this->options;
            $opts = array_values(array_unique(array_filter(array_map(fn ($o) => mb_substr(trim((string) $o), 0, 80), (array) $opts), fn ($o) => $o !== '')));
            if (! $opts) {
                throw new \DomainException('Indiquez les choix possibles de la liste.');
            }
            $data['options'] = $opts;
        } elseif (array_key_exists('options', $data)) {
            $data['options'] = null;
        }
        if (isset($data['label'])) {
            $data['label'] = trim($data['label']);
        }
        if (array_key_exists('default_value', $data) && $data['default_value'] !== null && $data['default_value'] !== '') {
            $probe = new static(['entity' => $data['entity'] ?? $this->entity, 'type' => $type, 'options' => $data['options'] ?? $this->options, 'label' => $data['label'] ?? $this->label]);
            $c = CustomFields::coerce($probe, $data['default_value']);
            $data['default_value'] = is_array($c) ? implode(', ', $c) : (is_bool($c) ? ($c ? '1' : '0') : (string) $c);
        }
        CustomFields::flush();
    }
}
