<?php

namespace App\Modules\Flotte;

use App\Models\Company;

/** Indicateurs de l'accueil : véhicules disponibles et demandes à traiter. */
class Dashboard
{
    public function __invoke(Company $company): array
    {
        $d = app(FlotteHooks::class)->dashboard($company);

        return [
            ['key' => 'fl_available', 'label' => 'Véhicules disponibles', 'value' => $d['available'], 'format' => 'number', 'icon' => 'directions_car'],
            ['key' => 'fl_requests', 'label' => 'Demandes de véhicule à traiter', 'value' => $d['requests'], 'format' => 'number', 'icon' => 'route'],
        ];
    }
}
