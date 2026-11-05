<?php

namespace App\Modules\Marketing\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Scénario automatique : un déclencheur puis une suite d'étapes (envois, attentes, conditions, actions). */
class Automation extends Model
{
    use BelongsToCompany;

    protected $table = 'mk_automations';

    protected $fillable = ['name', 'trigger', 'trigger_config', 'steps', 'status', 'allow_reentry', 'stats', 'user_id'];

    public const TRIGGERS = [
        'list_join' => 'Ajout à une liste',
        'form' => 'Inscription par un formulaire',
        'tag' => 'Étiquette ajoutée',
        'birthday' => 'Anniversaire',
        'lead_ads' => 'Prospect reçu (publicité, page de capture)',
        'purchase' => 'Achat (facture ou commande validée)',
        'inactive_customer' => 'Client sans achat depuis…',
        'manual' => 'Ajout manuel',
    ];

    public const STEPS = [
        'email' => 'Envoyer un e-mail',
        'sms' => 'Envoyer un SMS',
        'whatsapp' => 'Envoyer un WhatsApp',
        'wait' => 'Attendre',
        'condition' => 'Condition (si / sinon)',
        'add_list' => 'Ajouter à une liste',
        'remove_list' => 'Retirer d\'une liste',
        'add_tag' => 'Ajouter une étiquette',
        'remove_tag' => 'Retirer une étiquette',
        'notify' => 'Prévenir l\'équipe',
        'crm_lead' => 'Créer une opportunité CRM',
        'exit' => 'Fin du scénario',
    ];

    public const CONDITIONS = [
        'opened' => 'a ouvert le dernier e-mail',
        'clicked' => 'a cliqué dans le dernier e-mail',
        'has_tag' => 'a l\'étiquette',
        'in_list' => 'est dans la liste',
        'has_email' => 'a une adresse e-mail',
        'has_phone' => 'a un numéro de téléphone',
        'score_min' => 'a un score au moins égal à',
        'purchased' => 'a acheté depuis l\'entrée dans le scénario',
        'spent_min' => 'a dépensé au total au moins',
    ];

    protected function casts(): array
    {
        return ['trigger_config' => 'array', 'steps' => 'array', 'stats' => 'array', 'allow_reentry' => 'boolean'];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class, 'automation_id');
    }

    public function refreshStats(): void
    {
        $runs = fn () => AutomationRun::withoutGlobalScopes()->where('automation_id', $this->id);
        $msgs = fn () => CampaignMessage::withoutGlobalScopes()->where('automation_id', $this->id);
        $sent = $msgs()->where('status', 'sent')->count();
        $this->stats = [
            'entered' => $runs()->count(),
            'active' => $runs()->where('status', 'active')->count(),
            'done' => $runs()->whereIn('status', ['done', 'exited'])->count(),
            'failed' => $runs()->where('status', 'failed')->count(),
            'sent' => $sent,
            'opened' => $msgs()->whereNotNull('opened_at')->count(),
            'clicked' => $msgs()->whereNotNull('clicked_at')->count(),
            'open_rate' => $sent ? round($msgs()->whereNotNull('opened_at')->where('channel', 'email')->count() * 100 / max(1, $msgs()->where('status', 'sent')->where('channel', 'email')->count()), 1) : null,
        ];
        $this->saveQuietly();
    }
}
