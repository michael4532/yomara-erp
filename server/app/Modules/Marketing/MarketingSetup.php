<?php

namespace App\Modules\Marketing;

use App\Models\Company;
use App\Modules\Marketing\Models\MkList;
use App\Modules\Marketing\Models\Template;

/** Liste et modèles de départ (newsletter, promotion, proposition commerciale, invitation, relance). */
class MarketingSetup
{
    public static function starters(): array
    {
        $l = fn (string $key, string $name) => ['name' => $name] + array_intersect_key(EmailLayouts::find($key), array_flip(['subject', 'preheader', 'blocks', 'style']));

        return [
            $l('newsletter', 'Newsletter'),
            $l('promo', 'Promotion'),
            $l('proposal', 'Proposition commerciale'),
            $l('event', 'Invitation à un événement'),
            $l('reminder', 'Relance'),
            ['name' => 'SMS promotion', 'channel' => 'sms', 'text' => '{{entreprise}} : -20% jusqu\'a dimanche sur une selection de produits. A tres vite !'],
            ['name' => 'WhatsApp nouveautés', 'channel' => 'whatsapp', 'text' => "Bonjour {{prenom}} 👋\nDécouvrez les nouveautés de {{entreprise}} cette semaine. Répondez à ce message pour en savoir plus."],
        ];
    }

    public function __invoke(Company $company): void
    {
        if (Template::withoutGlobalScopes()->where('company_id', $company->id)->exists()) {
            return;
        }
        foreach (self::starters() as $t) {
            $tpl = new Template($t + ['channel' => 'email']);
            $tpl->company_id = $company->id;
            $tpl->save();
        }
        $l = new MkList(['name' => 'Clients', 'description' => 'Vos clients (importés depuis les contacts)']);
        $l->company_id = $company->id;
        $l->save();
    }
}
