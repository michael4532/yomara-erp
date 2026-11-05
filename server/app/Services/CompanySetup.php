<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CompanySetup
{
    /** Rôles créés pour chaque nouvelle société (modifiables ensuite). */
    public const DEFAULT_ROLES = [
        'Administrateur' => ['*'],
        'Commercial' => ['visio.use', 'mailbox.use', 'whatsapp.use', 'avis.view', 'avis.reply', 'woo.view', 'visio.webinar', 'core.contacts.view', 'core.contacts.manage', 'core.products.view', 'ventes.view', 'ventes.manage', 'ventes.validate', 'ventes.payments.manage', 'crm.*', 'stock.view', 'portail.manage', 'achats.request'],
        'Caissier' => ['pos.use', 'core.products.view', 'core.contacts.view', 'core.contacts.manage', 'stock.view', 'etiquettes.print'],
        'Magasinier' => ['core.products.*', 'stock.*', 'achats.view', 'achats.receive', 'achats.request', 'etiquettes.*'],
        'Acheteur' => ['core.contacts.*', 'core.products.*', 'achats.view', 'achats.manage', 'achats.request', 'achats.receive', 'stock.view'],
        'Comptable' => ['visio.use', 'mailbox.use', 'whatsapp.use', 'achats.request', 'core.contacts.view', 'ventes.view', 'ventes.payments.*', 'achats.view', 'compta.*', 'tresorerie.*', 'paie.view', 'import_export.export', 'stock.view'],
        'RH / Paie' => ['visio.use', 'mailbox.use', 'whatsapp.use', 'achats.request', 'rh.*', 'paie.*', 'pointage.*', 'talents.*', 'core.contacts.view'],
        'Direction' => ['visio.use', 'mailbox.use', 'whatsapp.use', 'avis.reply', 'visio.webinar', 'achats.approve', 'achats.request', '*.view', 'tickets.view_all', 'crm.view', 'paie.view', 'compta.view'],
        'Lecture seule' => ['*.view'],
        'Équipe projet' => ['visio.use', 'mailbox.use', 'whatsapp.use', 'achats.request', 'projets.view', 'projets.contribute', 'core.contacts.view', 'tickets.*'],
        'Livreur' => ['logistique.deliver', 'core.contacts.view'],
        'Technicien' => ['interventions.do', 'core.contacts.view', 'core.products.view'],
        'Responsable SAV' => ['mailbox.use', 'whatsapp.use', 'achats.request', 'interventions.*', 'stock.view', 'ventes.view', 'core.contacts.*', 'core.products.view'],
        'Accueil / réception' => ['mailbox.use', 'whatsapp.use', 'avis.view', 'avis.reply', 'rendez_vous.*', 'core.contacts.*', 'ventes.view', 'achats.request'],
        'Praticien' => ['rendez_vous.own', 'core.contacts.view'],
        'Serveur' => ['restaurant.serve', 'pos.use', 'core.products.view', 'core.contacts.view'],
        'Cuisine / bar' => ['restaurant.kitchen', 'core.products.view'],
        'Responsable de salle' => ['avis.view', 'avis.reply', 'restaurant.*', 'pos.*', 'core.products.*', 'core.contacts.*', 'stock.view', 'ventes.view', 'achats.request'],
        'Responsable de production' => ['production.*', 'stock.*', 'core.products.*', 'achats.view', 'achats.request'],
        'Opérateur de production' => ['production.view', 'production.operate', 'stock.view', 'core.products.view'],
        'Logisticien' => ['logistique.*', 'stock.view', 'ventes.view', 'core.contacts.view'],
        'Salarié' => ['rh.self', 'pointage.self', 'achats.request'],
        'Borne de pointage' => ['pointage.kiosk'],
        'Chef de projet' => ['visio.use', 'mailbox.use', 'whatsapp.use', 'achats.request', 'projets.*', 'core.contacts.view', 'ventes.view', 'crm.view', 'tickets.*'],
    ];

    /**
     * @param array $company  champs de la société
     * @param array $owner    name, email, password
     */
    public function create(array $company, array $owner, ?Plan $plan = null, ?string $status = null): array
    {
        return DB::transaction(function () use ($company, $owner, $plan, $status) {
            $c = Company::create(array_merge([
                'currency' => config('erp.defaults.currency'),
                'country' => config('erp.defaults.country'),
                'settings' => [
                    'tax_rate' => config('erp.defaults.tax_rate'),
                    'timezone' => config('erp.defaults.timezone'),
                    'invoice_prefix' => 'FAC',
                    'quote_prefix' => 'DEV',
                    'order_prefix' => 'CMD',
                    'credit_prefix' => 'AV',
                    'receipt_prefix' => 'TK',
                    'receipt_footer' => 'Merci de votre visite !',
                    'purchase_prefix' => 'BCF',
                    'supplier_invoice_prefix' => 'FF',
                    'payment_terms_days' => 30,
                    'invoice_footer' => null,
                ],
            ], $company, [
                'plan_id' => $plan?->id,
                'status' => $status ?? 'active',
                'trial_ends_at' => $status === 'trial' ? now()->addDays(\App\Platform\SubscriptionLifecycle::trialDays()) : null,
            ]));
            // Référentiel comptable d'après le pays (SYSCOHADA, PCG, CGNC…), fixé dès la création
            if (! data_get($c->settings, 'compta.referential')) {
                $s = $c->settings ?? [];
                data_set($s, 'compta.referential', \App\Modules\Compta\Referentials::forCountry($c->country));
                $c->forceFill(['settings' => $s])->saveQuietly();
            }

            $adminRole = null;
            foreach (self::DEFAULT_ROLES as $name => $perms) {
                $role = Role::withoutGlobalScopes()->create([
                    'company_id' => $c->id, 'name' => $name, 'permissions' => $perms, 'is_system' => $name === 'Administrateur',
                ]);
                $adminRole ??= $role;
            }

            $user = User::create([
                'company_id' => $c->id,
                'role_id' => $adminRole->id,
                'name' => $owner['name'],
                'email' => $owner['email'],
                'phone' => $owner['phone'] ?? null,
                'password' => $owner['password'],
                'is_super_admin' => (bool) ($owner['is_super_admin'] ?? false),
            ]);
            // langue choisie à l'inscription (site en anglais, application en anglais) : e-mails dans cette langue
            if (app()->getLocale() !== 'fr' && \Illuminate\Support\Facades\Schema::hasColumn('users', 'locale')) {
                $user->forceFill(['locale' => app()->getLocale()])->saveQuietly();
            }

            return ['company' => $c, 'user' => $user];
        });
    }
}
