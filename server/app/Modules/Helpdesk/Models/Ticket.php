<?php

namespace App\Modules\Helpdesk\Models;

use App\Models\Concerns\Syncable;
use App\Models\Sequence;
use App\Modules\Core\Models\Partner;
use App\Modules\Helpdesk\HelpdeskService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Ticket du service client. */
class Ticket extends Model
{
    use Syncable;

    protected $table = 'hd_tickets';

    public static string $syncEntity = 'hd_tickets';

    public static string $viewPermission = 'helpdesk.view|helpdesk.agent|helpdesk.manage';

    public static string $managePermission = 'helpdesk.agent|helpdesk.manage';

    public static array $searchable = ['number', 'subject', 'partner_name', 'contact_name', 'contact_email'];

    public const STATUSES = ['new' => 'Nouveau', 'open' => 'En cours', 'pending' => 'En attente du client', 'resolved' => 'Résolu', 'closed' => 'Fermé'];

    public const PRIORITIES = ['urgent' => 'Urgente', 'high' => 'Haute', 'normal' => 'Normale', 'low' => 'Basse'];

    public const CHANNELS = ['agent' => 'Saisi par l\'équipe', 'phone' => 'Téléphone', 'email' => 'E-mail', 'whatsapp' => 'WhatsApp', 'web' => 'Formulaire en ligne', 'portal' => 'Espace client'];

    protected $fillable = ['partner_id', 'contact_name', 'contact_email', 'contact_phone', 'subject', 'description', 'channel', 'category_id', 'priority', 'status', 'assignee_id', 'pack_id', 'tags', 'sla_policy_id'];

    protected $attributes = ['status' => 'new', 'priority' => 'normal', 'channel' => 'agent'];

    protected $hidden = ['company_id', 'deleted_at', 'token'];

    /** État avant l'écriture (pour les règles appliquées après). */
    public array $before = [];

    /** Écriture faite par le serveur (formulaire public, espace client). */
    public static bool $systemWrite = false;

    protected function casts(): array
    {
        return ['assignee_id' => 'integer', 'first_response_due' => 'datetime', 'resolution_due' => 'datetime', 'first_response_at' => 'datetime', 'resolved_at' => 'datetime',
            'closed_at' => 'datetime', 'pending_since' => 'datetime', 'last_customer_at' => 'datetime', 'last_agent_at' => 'datetime', 'sla_first_breached' => 'boolean',
            'sla_resolve_breached' => 'boolean', 'rating' => 'integer', 'reopened' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $c = app('tenant')->id;

        return [
            'partner_id' => ['nullable', 'uuid', Rule::exists('partners', 'id')->where('company_id', $c)],
            'contact_name' => 'nullable|string|max:120',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:40',
            'subject' => 'required|string|max:200',
            'description' => 'nullable|string|max:20000',
            'channel' => 'in:'.implode(',', array_keys(self::CHANNELS)),
            'category_id' => ['nullable', 'uuid', Rule::exists('hd_categories', 'id')->where('company_id', $c)->whereNull('deleted_at')],
            'priority' => 'in:'.implode(',', array_keys(self::PRIORITIES)),
            'status' => 'in:'.implode(',', array_keys(self::STATUSES)),
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $c)],
            'pack_id' => ['nullable', 'uuid', Rule::exists('pr_packs', 'id')->where('company_id', $c)->whereNull('deleted_at')],
            'sla_policy_id' => ['nullable', 'uuid', Rule::exists('hd_sla_policies', 'id')->where('company_id', $c)->whereNull('deleted_at')],
            'tags' => 'nullable|string|max:255',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $this->before = $creating ? [] : ['status' => $this->status, 'priority' => $this->priority, 'assignee_id' => $this->assignee_id, 'sla_policy_id' => $this->sla_policy_id, 'category_id' => $this->category_id];
        if (! $creating && $this->status === 'closed' && ($data['status'] ?? 'closed') === 'closed' && array_diff(array_keys($data), ['status', 'tags'])) {
            throw new \DomainException('Ticket fermé : rouvrez-le pour le modifier.');
        }
        if ($creating) {
            $this->number = Sequence::next(app('tenant')->id, 'hd_ticket', 'SUP');
            $this->token = Str::random(40);
            $ch = $data['channel'] ?? null;
            if (! static::$systemWrite && in_array($ch, ['web', 'portal'], true)) {
                $ch = 'agent';
            }
            $data['channel'] = $ch ?? (static::$systemWrite ? 'web' : 'agent');
            $data['status'] = in_array($data['status'] ?? 'new', ['new', 'open'], true) ? ($data['status'] ?? 'new') : 'new';
        }
        $pid = array_key_exists('partner_id', $data) ? $data['partner_id'] : $this->partner_id;
        if ($creating || array_key_exists('partner_id', $data)) {
            $p = $pid ? Partner::find($pid) : null;
            $this->partner_name = $p?->name;
            if ($p && $creating) {
                $data['contact_email'] ??= $p->email;
                $data['contact_phone'] ??= $p->phone;
                $data['contact_name'] ??= $p->name;
            }
        }
        if (! $pid && empty($data['contact_email'] ?? $this->contact_email) && empty($data['contact_phone'] ?? $this->contact_phone) && $creating) {
            throw new \DomainException('Indiquez le client ou un moyen de le recontacter (e-mail ou téléphone).');
        }
        $pack = array_key_exists('pack_id', $data) ? $data['pack_id'] : $this->pack_id;
        if ($pack && (array_key_exists('pack_id', $data) || array_key_exists('partner_id', $data))) {
            $pk = \App\Modules\Prestations\Models\Pack::find($pack);
            if (! $pk || $pk->partner_id !== $pid) {
                throw new \DomainException('Le forfait doit appartenir au client du ticket.');
            }
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        app(HelpdeskService::class)->afterTicketWrite($this, $creating);
    }

    public function beforeEntityDelete(): void
    {
        if (! auth()->user()?->hasPermission('helpdesk.manage')) {
            throw new \DomainException('Seul un responsable supprime un ticket : fermez-le plutôt.');
        }
        TimeEntry::where('ticket_id', $this->id)->get()->each->delete();
        Message::where('ticket_id', $this->id)->get()->each->delete();
    }

    public function publicUrl(): string
    {
        return url('/support/'.$this->token);
    }

    public function minutesSpent(): int
    {
        return (int) TimeEntry::where('ticket_id', $this->id)->sum('minutes');
    }

    public function toSyncArray(): array
    {
        return $this->toArray() + ['number' => $this->number, 'partner_name' => $this->partner_name, 'public_url' => $this->publicUrl(), 'minutes' => $this->minutesSpent()];
    }
}
