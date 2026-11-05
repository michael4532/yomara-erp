<?php

namespace App\Modules\Signature\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SignEvent extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $table = 'sg_events';

    protected $fillable = ['request_id', 'signer_id', 'user_id', 'type', 'ip', 'user_agent', 'data'];

    protected $hidden = ['company_id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    public const LABELS = [
        'created' => 'Demande créée', 'sent' => 'Lien envoyé', 'link_shared' => 'Lien à transmettre manuellement', 'viewed' => 'Document ouvert',
        'downloaded' => 'Document téléchargé', 'otp_sent' => 'Code de vérification envoyé', 'otp_failed' => 'Code erroné', 'verified' => 'Identité vérifiée',
        'signed' => 'Document signé', 'declined' => 'Signature refusée', 'reminded' => 'Relance envoyée', 'completed' => 'Toutes les signatures recueillies',
        'sealed' => 'Document scellé', 'cancelled' => 'Demande annulée', 'expired' => 'Demande expirée',
    ];
}
