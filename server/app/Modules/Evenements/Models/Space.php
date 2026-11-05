<?php

namespace App\Modules\Evenements\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Stand / emplacement d'un événement, placé sur le plan (grille). */
class Space extends Model
{
    use Syncable;

    protected $table = 'ev_spaces';

    public static string $syncEntity = 'ev_spaces';

    public static string $viewPermission = 'evenements.view|evenements.sell|evenements.manage';

    public static string $managePermission = 'evenements.manage';

    protected $fillable = ['event_id', 'code', 'category', 'zone', 'area', 'price', 'x', 'y', 'w', 'h', 'features', 'blocked', 'notes', 'width_m', 'depth_m'];

    protected $attributes = ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'price' => 0, 'blocked' => false];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['area' => 'float', 'width_m' => 'float', 'depth_m' => 'float', 'price' => 'float', 'x' => 'integer', 'y' => 'integer', 'w' => 'integer', 'h' => 'integer', 'blocked' => 'boolean', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $c = app('tenant')->id;

        return [
            'event_id' => ['required', 'uuid', Rule::exists('ev_events', 'id')->where('company_id', $c)->whereNull('deleted_at')],
            'code' => 'required|string|max:20',
            'category' => 'nullable|string|max:80',
            'zone' => 'nullable|string|max:80',
            'area' => 'nullable|numeric|min:0|max:100000',
            'price' => 'numeric|min:0',
            'x' => 'integer|min:0|max:79',
            'y' => 'integer|min:0|max:79',
            'w' => 'integer|min:1|max:80',
            'h' => 'integer|min:1|max:80',
            'features' => 'nullable|string|max:255',
            'blocked' => 'boolean',
            'notes' => 'nullable|string|max:255',
            'width_m' => 'nullable|numeric|min:0.5|max:1000',
            'depth_m' => 'nullable|numeric|min:0.5|max:1000',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (! $creating) {
            unset($data['event_id']);
        }
        // Dimensions réelles : la surface en découle
        $w = array_key_exists('width_m', $data) ? $data['width_m'] : $this->width_m;
        $d = array_key_exists('depth_m', $data) ? $data['depth_m'] : $this->depth_m;
        if ($w && $d && (array_key_exists('width_m', $data) || array_key_exists('depth_m', $data))) {
            $data['area'] = round((float) $w * (float) $d, 2);
        }
        $eventId = $data['event_id'] ?? $this->event_id;
        $event = Event::find($eventId);
        $code = trim((string) ($data['code'] ?? $this->code));
        $data['code'] = $code;
        if (static::where('event_id', $eventId)->where('id', '<>', (string) $this->id)->whereRaw('lower(code) = ?', [mb_strtolower($code)])->exists()) {
            throw new \DomainException('Le code « '.$code.' » est déjà utilisé sur ce plan.');
        }
        [$x, $y, $w, $h] = [(int) ($data['x'] ?? $this->x), (int) ($data['y'] ?? $this->y), (int) ($data['w'] ?? $this->w ?? 1), (int) ($data['h'] ?? $this->h ?? 1)];
        if ($event && ($x + $w > $event->plan_cols || $y + $h > $event->plan_rows)) {
            throw new \DomainException('L\'emplacement '.$code.' dépasse du plan ('.$event->plan_cols.' × '.$event->plan_rows.').');
        }
        foreach (static::where('event_id', $eventId)->where('id', '<>', (string) $this->id)->get(['code', 'x', 'y', 'w', 'h']) as $o) {
            if ($x < $o->x + $o->w && $o->x < $x + $w && $y < $o->y + $o->h && $o->y < $y + $h) {
                throw new \DomainException('L\'emplacement '.$code.' chevauche '.$o->code.' sur le plan.');
            }
        }
        // Prix et surface par défaut : ceux de la catégorie
        if ($creating && $event && ! empty($data['category'])) {
            $cat = collect($event->categories ?? [])->firstWhere('name', $data['category']);
            if ($cat && ! isset($data['price']) && isset($cat['price'])) {
                $data['price'] = $cat['price'];
            }
            if ($cat && ! isset($data['area']) && isset($cat['area'])) {
                $data['area'] = $cat['area'];
            }
        }
    }

    public function beforeEntityDelete(): void
    {
        $b = Booking::activeWithSpace($this->event_id, $this->id);
        if ($b) {
            throw new \DomainException('L\'emplacement '.$this->code.' figure dans la réservation '.$b->number.'.');
        }
    }
}
