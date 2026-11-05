<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Relance automatique de l'éditeur (fin d'essai, échéance, inactivité…). */
class PlatformAutomation extends Model
{
    protected $fillable = ['trigger', 'name', 'days', 'channels', 'subject', 'preheader', 'title', 'body_text', 'format', 'blocks', 'style', 'html',
        'cta_label', 'cta_url', 'recipients', 'is_active', 'sent', 'last_run_at'];

    protected function casts(): array
    {
        return ['channels' => 'array', 'blocks' => 'array', 'style' => 'array', 'is_active' => 'boolean', 'last_run_at' => 'datetime'];
    }
}
