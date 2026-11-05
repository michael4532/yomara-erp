<?php

namespace App\Modules\Rh\Models;

use App\Models\ActivityLog;
use App\Models\AppNotification;
use App\Models\Concerns\Syncable;
use App\Models\Sequence;
use App\Models\User;
use App\Modules\Rh\DocRenderer;
use App\Modules\Rh\DocTemplates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Document RH établi pour un salarié (attestation, certificat, contrat, avertissement, solde de tout compte).
 * Le texte est figé à l'émission. Le salarié peut demander une attestation, ou l'obtenir seul si le modèle est en libre-service.
 */
class HrDocument extends Model
{
    use Syncable;

    protected $table = 'rh_documents';

    public static string $syncEntity = 'rh_documents';

    public static string $viewPermission = 'rh.view|rh.manage|rh.self';

    public static string $managePermission = 'rh.manage|rh.self';

    protected $fillable = ['employee_id', 'template_id', 'type', 'title', 'date', 'body', 'data', 'status', 'note'];

    public const PREFIXES = ['attestation' => 'ATT', 'certificate' => 'CT', 'contract' => 'CTR', 'warning' => 'AV', 'stc' => 'STC', 'other' => 'DOC'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'data' => 'array', 'issued_at' => 'datetime', 'requested_by' => 'integer', 'issued_by' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'employee_id' => ['required', 'uuid', Rule::exists('rh_employees', 'id')->where('company_id', $company)],
            'template_id' => ['nullable', 'uuid', Rule::exists('rh_doc_templates', 'id')->where('company_id', $company)],
            'type' => ['required', Rule::in(array_keys(DocTemplates::TYPES))],
            'title' => 'required|string|max:160',
            'date' => 'required|date',
            'body' => 'nullable|string|max:100000',
            'data' => 'nullable|array',
            'status' => 'nullable|in:requested,draft,issued,cancelled',
            'note' => 'nullable|string|max:255',
        ];
    }

    public static function syncVisibility(Builder $q, $user): void
    {
        if (! $user->hasPermission('rh.view|rh.manage')) {
            // Le salarié voit ses demandes et ses documents émis (pas les brouillons de la RH)
            $q->whereIn('employee_id', Employee::where('user_id', $user->id)->select('id'))->whereIn('status', ['requested', 'issued']);
        }
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $user = auth()->user();
        $manager = ! $user || $user->hasPermission('rh.manage');
        $previous = $creating ? null : $this->status;
        $status = $data['status'] ?? ($creating ? 'issued' : $this->status);

        if (! $manager) {
            $own = Employee::where('user_id', $user->id)->first();
            if (! $creating || ! $own || ($data['employee_id'] ?? null) !== $own->id) {
                throw new \DomainException('Vous pouvez seulement demander un document pour vous-même.');
            }
            $tpl = DocTemplate::find($data['template_id'] ?? null);
            $rendered = $tpl && $tpl->active && $tpl->self_service ? DocRenderer::render($tpl->body, DocRenderer::fields(app('tenant'), $own)) : null;
            if ($rendered !== null && ! str_contains($rendered, '[à compléter]')) {
                // Libre-service : le texte est établi par le serveur à partir de la fiche, jamais par l'appareil
                $data = ['employee_id' => $own->id, 'template_id' => $tpl->id, 'type' => $tpl->type, 'title' => $tpl->name, 'date' => now()->toDateString(),
                    'body' => $rendered, 'status' => 'issued', 'note' => null];
                $status = 'issued';
            } elseif ($rendered !== null) {
                // Fiche incomplète (date de naissance, RCCM…) : la demande part à la RH au lieu d'un document à trous
                $data = ['employee_id' => $own->id, 'template_id' => $tpl->id, 'type' => $tpl->type, 'title' => $tpl->name, 'date' => now()->toDateString(),
                    'body' => null, 'status' => 'requested', 'note' => trim(($data['note'] ?? '').' (fiche incomplète : la RH complète le document)')];
                $status = 'requested';
            } else {
                $type = $tpl?->type ?? ($data['type'] ?? 'attestation');
                if (! in_array($type, ['attestation', 'certificate', 'other'], true)) {
                    throw new \DomainException('Ce document se demande directement au service RH.');
                }
                $data = ['employee_id' => $own->id, 'template_id' => $tpl?->id, 'type' => $type, 'title' => $tpl?->name ?? DocTemplates::TYPES[$type],
                    'date' => now()->toDateString(), 'body' => null, 'status' => 'requested', 'note' => $data['note'] ?? null];
                $status = 'requested';
            }
            $this->requested_by = $user->id;
        } else {
            if ($previous === 'issued' && $status === 'issued' && (array_key_exists('body', $data) && $data['body'] !== $this->body || array_key_exists('data', $data))) {
                throw new \DomainException('Document déjà émis : annulez-le et établissez-en un nouveau.');
            }
            if ($status === 'issued' && blank($data['body'] ?? $this->body)) {
                throw new \DomainException('Le document est vide.');
            }
            if ($previous === 'cancelled') {
                throw new \DomainException('Document annulé.');
            }
        }
        if ($status === 'issued' && $previous !== 'issued') {
            $type = $data['type'] ?? $this->type;
            $this->number ??= Sequence::next($this->company_id ?? app('tenant')->id, 'rh.doc.'.$type, self::PREFIXES[$type] ?? 'DOC');
            $this->issued_by = $user?->id;
            $this->issued_at = now();
        }
        $data['status'] = $status;
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        if (! $creating && ! $this->wasChanged('status')) {
            return;
        }
        $emp = Employee::withTrashed()->find($this->employee_id);
        $user = auth()->user();
        ActivityLog::record('rh.document.'.$this->status, $this, ['title' => $this->title]);
        if ($this->status === 'requested') {
            $managers = User::where('company_id', $this->company_id)->where('is_active', true)->where('id', '!=', $user?->id ?? 0)->get()->filter(fn ($u) => $u->hasPermission('rh.manage'))->pluck('id')->all();
            AppNotification::send($this->company_id, $managers, 'rh.document', 'Demande de document', ($emp?->full_name ?? '').' : '.$this->title.($this->note ? ' — '.$this->note : ''), 'rh', $this->id);
        } elseif ($this->status === 'cancelled' && ($this->getPrevious()['status'] ?? null) === 'requested' && $emp?->user_id && $emp->user_id !== $user?->id) {
            AppNotification::send($this->company_id, [$emp->user_id], 'rh.document', 'Demande de document refusée', $this->title.($this->note ? ' — '.$this->note : ''), 'rh', $this->id);
        } elseif ($this->status === 'issued' && $emp?->user_id && $emp->user_id !== $user?->id) {
            AppNotification::send($this->company_id, [$emp->user_id], 'rh.document', 'Document disponible', $this->title.' ('.$this->number.') est disponible dans votre espace.', 'rh', $this->id);
        }
    }

    public function beforeEntityDelete(): void
    {
        if ($this->status === 'issued') {
            throw new \DomainException('Un document émis ne se supprime pas : annulez-le.');
        }
        $user = auth()->user();
        if ($user && ! $user->hasPermission('rh.manage')) {
            throw new \DomainException('Seul le service RH supprime un document.');
        }
    }
}
