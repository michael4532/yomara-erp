<?php

namespace App\Modules\Tickets;

use App\Models\Company;
use App\Models\User;
use App\Modules\Tickets\Models\Department;
use App\Modules\Tickets\Models\Member;

/** Organisation de départ : Direction générale et services usuels (modifiable ensuite). */
class TicketSetup
{
    public const DEPARTMENTS = [
        ['Informatique', 'IT', '#1F6FEB', ['Panne matériel', 'Imprimante', 'Réseau / Internet', 'Logiciel', 'Compte et mot de passe', 'Achat de matériel']],
        ['Ressources humaines', 'RH', '#8E44AD', ['Congés', 'Attestation', 'Paie', 'Formation', 'Autre demande RH']],
        ['Comptabilité & finances', 'FIN', '#12A150', ['Paiement fournisseur', 'Note de frais', 'Avance / caisse', 'Facturation']],
        ['Marketing & communication', 'MKT', '#E8A317', ['Visuel / affiche', 'Publication réseaux sociaux', 'Événement', 'Site web', 'Impression']],
        ['Moyens généraux', 'MG', '#5B6B85', ['Fournitures', 'Entretien / réparation', 'Véhicule', 'Nettoyage', 'Sécurité']],
    ];

    public function __invoke(Company $company): void
    {
        if (Department::withoutGlobalScopes()->where('company_id', $company->id)->exists()) {
            return;
        }
        $admin = User::where('company_id', $company->id)->orderBy('id')->first();
        $dg = new Department(['name' => 'Direction générale', 'code' => 'DG', 'color' => '#0B1B3A', 'accepts_tickets' => true, 'categories' => ['Validation', 'Information', 'Autre'], 'head_user_id' => $admin?->id]);
        $dg->company_id = $company->id;
        $dg->save();
        if ($admin) {
            $m = new Member(['department_id' => $dg->id, 'user_id' => $admin->id, 'role' => 'head']);
            $m->company_id = $company->id;
            $m->save();
        }
        foreach (self::DEPARTMENTS as [$name, $code, $color, $cats]) {
            $d = new Department(['name' => $name, 'code' => $code, 'color' => $color, 'parent_id' => $dg->id, 'categories' => $cats, 'accepts_tickets' => true]);
            $d->company_id = $company->id;
            $d->save();
        }
    }
}
