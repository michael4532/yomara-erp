<?php

namespace App\Modules\Helpdesk\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

/** Pièce jointe d'un ticket (reçue par e-mail ou WhatsApp, ou déposée par l'équipe). */
class Attachment extends Model
{
    use Syncable;

    protected $table = 'hd_attachments';

    public static string $syncEntity = 'hd_attachments';

    public static string $viewPermission = 'helpdesk.view|helpdesk.agent|helpdesk.manage';

    public static string $managePermission = 'helpdesk.manage';

    protected $fillable = ['public'];

    protected $hidden = ['company_id', 'deleted_at', 'path'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'public' => 'boolean', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return ['public' => 'boolean'];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if ($creating) {
            throw new \DomainException('Les pièces jointes se déposent avec le bouton « Joindre ».');
        }
    }

    public function toSyncArray(): array
    {
        return $this->toArray() + ['ticket_id' => $this->ticket_id, 'message_id' => $this->message_id, 'name' => $this->name, 'mime' => $this->mime, 'size' => $this->size, 'uploaded_by' => $this->uploaded_by];
    }
}
