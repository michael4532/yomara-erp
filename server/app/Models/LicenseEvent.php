<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LicenseEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['license_id', 'type', 'ip', 'data', 'created_at'];

    public const LABELS = [
        'created' => 'Installation enregistrée', 'activated' => 'Installation reliée au compte', 'refused' => 'Liaison refusée (autre appareil)',
        'reset' => 'Appareil libéré', 'suspended' => 'Installation suspendue', 'reactivated' => 'Installation réactivée', 'revoked' => 'Installation déliée',
        'renamed' => 'Installation modifiée', 'module' => 'Module activé',
    ];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }
}
