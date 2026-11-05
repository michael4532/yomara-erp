<?php

namespace App\Modules\Marketing\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationRun extends Model
{
    use BelongsToCompany;

    protected $table = 'mk_automation_runs';

    protected $fillable = ['automation_id', 'contact_id', 'cursor', 'status', 'next_run_at', 'last_message_id', 'history', 'error'];

    protected function casts(): array
    {
        return ['next_run_at' => 'datetime', 'history' => 'array'];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class, 'automation_id')->withoutGlobalScopes();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id')->withoutGlobalScopes();
    }
}
