<?php

namespace App\Modules\Portail\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PortalEvent extends Model
{
    use BelongsToCompany;

    public const LABELS = [
        'invite' => 'Invitation envoyée', 'login' => 'Connexion au portail', 'view' => 'Document consulté', 'quote_accepted' => 'Devis accepté en ligne',
        'quote_refused' => 'Devis refusé', 'message' => 'Message du client', 'reply' => 'Réponse de l\'équipe', 'pay' => 'Paiement en ligne lancé',
    ];

    protected $fillable = ['partner_id', 'type', 'document_id', 'data', 'user_id', 'ip', 'read_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime'];
    }

    public function toApi(): array
    {
        return ['id' => $this->id, 'type' => $this->type, 'label' => self::LABELS[$this->type] ?? $this->type, 'document_id' => $this->document_id,
            'data' => $this->data, 'user_id' => $this->user_id, 'read_at' => $this->read_at, 'created_at' => $this->created_at];
    }
}
