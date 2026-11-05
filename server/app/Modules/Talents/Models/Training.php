<?php

namespace App\Modules\Talents\Models;

use App\Models\AppNotification;
use App\Models\Concerns\Syncable;
use App\Modules\Rh\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Session du plan de formation : participants, heures, coût, prise en charge (FDFP…). Visible de tous les salariés. */
class Training extends Model
{
    use Syncable;

    protected $table = 'tal_trainings';

    public static string $syncEntity = 'tal_trainings';

    public static string $viewPermission = 'talents.view|talents.manage|rh.self';

    public static string $managePermission = 'talents.manage';

    public const STATUSES = ['planned' => 'Prévue', 'ongoing' => 'En cours', 'done' => 'Réalisée', 'cancelled' => 'Annulée'];

    /** Participants avant l'enregistrement (pour prévenir les nouveaux inscrits). */
    protected array $previousParticipants = [];

    protected $fillable = ['title', 'category', 'provider', 'location', 'start_date', 'end_date', 'hours', 'cost', 'funded', 'funded_amount', 'status', 'participants', 'description'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'hours' => 'float', 'cost' => 'float', 'funded' => 'boolean', 'funded_amount' => 'float', 'participants' => 'array', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'title' => 'required|string|max:160',
            'category' => 'nullable|string|max:80',
            'provider' => 'nullable|string|max:160',
            'location' => 'nullable|string|max:160',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'hours' => 'nullable|numeric|min:0|max:10000',
            'cost' => 'nullable|numeric|min:0',
            'funded' => 'nullable|boolean',
            'funded_amount' => 'nullable|numeric|min:0',
            'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
            'participants' => 'nullable|array|max:500',
            'participants.*.employee_id' => 'required|uuid',
            'participants.*.attended' => 'nullable|boolean',
            'participants.*.rating' => 'nullable|numeric|min:0|max:5',
            'description' => 'nullable|string|max:5000',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $start = $data['start_date'] ?? $this->start_date;
        $end = $data['end_date'] ?? $this->end_date;
        if ($start && $end && \Carbon\Carbon::parse($end)->lt(\Carbon\Carbon::parse($start))) {
            throw new \DomainException('La date de fin précède la date de début.');
        }
        if (isset($data['participants'])) {
            $ids = array_column($data['participants'], 'employee_id');
            if (count($ids) !== count(array_unique($ids))) {
                throw new \DomainException('Un salarié figure deux fois parmi les participants.');
            }
            if (Employee::whereIn('id', $ids)->count() !== count($ids)) {
                throw new \DomainException('Participant inconnu.');
            }
        }
        if ($creating) {
            $data['status'] ??= 'planned';
        }
        $this->previousParticipants = $creating ? [] : array_column((array) $this->participants, 'employee_id');
        if (($data['funded_amount'] ?? $this->funded_amount) > ($data['cost'] ?? $this->cost ?? INF)) {
            throw new \DomainException('La prise en charge dépasse le coût de la formation.');
        }
    }

    public function afterEntityWrite(array $data, bool $created): void
    {
        // Les nouveaux inscrits sont prévenus
        $new = array_diff(array_column((array) $this->participants, 'employee_id'), $this->previousParticipants);
        if ($new && $this->status !== 'cancelled') {
            $users = Employee::whereIn('id', $new)->whereNotNull('user_id')->pluck('user_id')->all();
            AppNotification::send(app('tenant')->id, $users, 'talents.training', 'Inscription à une formation', $this->title.($this->start_date ? ' — à partir du '.$this->start_date->format('d/m/Y') : ''), 'talents', $this->id, auth()->id());
        }
    }
}
