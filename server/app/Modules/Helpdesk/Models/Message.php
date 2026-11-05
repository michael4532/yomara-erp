<?php

namespace App\Modules\Helpdesk\Models;

use App\Models\Concerns\Syncable;
use App\Modules\Helpdesk\HelpdeskService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Message d'un ticket : réponse au client, note interne, message du client ou événement. */
class Message extends Model
{
    use Syncable;

    protected $table = 'hd_messages';

    public static string $syncEntity = 'hd_messages';

    public static string $viewPermission = 'helpdesk.view|helpdesk.agent|helpdesk.manage';

    public static string $managePermission = 'helpdesk.agent|helpdesk.manage';

    protected $fillable = ['ticket_id', 'body', 'internal'];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['internal' => 'boolean', 'user_id' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'ticket_id' => ['required', 'uuid', Rule::exists('hd_tickets', 'id')->where('company_id', app('tenant')->id)->whereNull('deleted_at')],
            'body' => 'required|string|max:20000',
            'internal' => 'boolean',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (! $creating) {
            throw new \DomainException('Un message envoyé ne se modifie plus.');
        }
        $t = Ticket::find($data['ticket_id']);
        if ($t->status === 'closed' && empty($data['internal'])) {
            throw new \DomainException('Ticket fermé : rouvrez-le pour répondre au client.');
        }
        $u = auth()->user();
        $this->author_type = 'agent';
        $this->user_id = $u?->id;
        $this->author_name = $u?->name;
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        app(HelpdeskService::class)->afterAgentMessage($this);
    }

    public function beforeEntityDelete(): void
    {
        throw new \DomainException('Un message ne se supprime pas.');
    }

    public function toSyncArray(): array
    {
        return $this->toArray() + ['author_type' => $this->author_type, 'user_id' => $this->user_id, 'author_name' => $this->author_name, 'sent_via' => $this->sent_via];
    }
}
