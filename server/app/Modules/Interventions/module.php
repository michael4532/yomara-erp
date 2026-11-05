<?php

use App\Modules\Interventions\Dashboard;
use App\Modules\Interventions\InterventionsModuleProvider;
use App\Modules\Interventions\Models\Contract;
use App\Modules\Interventions\Models\Equipment;
use App\Modules\Interventions\Models\Intervention;

return [
    'key' => 'interventions',
    'name' => 'Interventions terrain',
    'description' => 'Demandes et fiches d\'intervention, planning des techniciens, application technicien hors ligne (temps, pièces, rapport, photos, signature du client), parc d\'équipements des clients, contrats de maintenance avec visites préventives automatiques, facturation et rapport en ligne.',
    'category' => 'Opérations',
    'icon' => 'engineering',
    'depends' => ['core'],
    'sort' => 56,
    'provider' => InterventionsModuleProvider::class,
    'dashboard' => Dashboard::class,
    'permissions' => [
        'interventions.view' => 'Consulter les interventions, équipements et contrats',
        'interventions.do' => 'Technicien : réaliser ses interventions (temps, pièces, rapport, signature)',
        'interventions.manage' => 'Planifier les interventions, gérer équipements et contrats, facturer',
    ],
    'sync' => [
        'it_equipments' => Equipment::class,
        'it_contracts' => Contract::class,
        'it_interventions' => Intervention::class,
    ],
];
