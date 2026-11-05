<?php

namespace App\Modules\Rapports\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Envoi programmé d'un rapport ou d'un tableau de bord par e-mail (exécuté avec les droits de son auteur). */
class Schedule extends Model
{
    use BelongsToCompany;

    protected $table = 'rp_schedules';

    public const FREQUENCIES = ['daily' => 'Chaque jour', 'weekly' => 'Chaque semaine', 'monthly' => 'Chaque mois'];

    protected $fillable = ['report_id', 'dashboard_id', 'owner_id', 'frequency', 'weekday', 'monthday', 'hour', 'recipients', 'format', 'period', 'message', 'active', 'next_run_at', 'last_sent_at', 'last_status', 'last_error'];

    protected $hidden = ['company_id'];

    protected function casts(): array
    {
        return ['recipients' => 'array', 'active' => 'boolean', 'weekday' => 'integer', 'monthday' => 'integer', 'hour' => 'integer', 'owner_id' => 'integer',
            'next_run_at' => 'datetime', 'last_sent_at' => 'datetime'];
    }
}
