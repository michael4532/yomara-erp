<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Modules\Core\Models\Partner;
use App\Modules\Core\Models\Product;
use App\Modules\Core\Models\ProductCategory;
use App\Modules\Pos\Models\PosRegister;
use App\Modules\Stock\Models\StockMove;
use App\Modules\Stock\Models\Warehouse;
use App\Services\CompanySetup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Données de démonstration : php artisan db:seed --class=DemoSeeder  (admin@demo.ci / password) */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlatformSeeder::class);

        if (User::where('email', 'admin@demo.ci')->exists()) {
            return;
        }

        ['company' => $company] = app(CompanySetup::class)->create(
            ['name' => 'Démo SARL', 'email' => 'contact@demo.ci', 'phone' => '+225 01 00 00 00 00', 'city' => 'Abidjan'],
            ['name' => 'Super Admin', 'email' => 'admin@demo.ci', 'password' => 'password', 'is_super_admin' => true],
            Plan::where('code', 'entreprise')->first(),
        );

        $this->catalog($company);
        $this->tickets($company);
        $this->store();
        $this->marketing($company);
        $this->projets($company);
        $this->logistique($company);
        $this->portail($company);
        $this->interventions($company);
        $this->achats($company);
        $this->rendezVous($company);
        $this->fidelite($company);
        $this->restaurantMenu($company);
        $this->production($company);
        $this->restaurant($company);
        $this->etiquettes($company);
        $this->espaceSalarie($company);
        $this->tresorerie($company);
        $this->pointage($company);
        $this->notesFrais($company);
        $this->recrutement($company);
        $this->documents($company);
        $this->agenda($company);
        $this->signature($company);
        $this->analytique($company);
        $this->immobilisations($company);
        $this->devisesRelancesAbonnements($company);
        $this->fne($company);
        $this->rapports($company);
        $this->approbationsChamps($company);
        $this->articlesAvances($company);
        $this->evenementsHebergement($company);
        $this->billetterieForfaits($company);
        $this->planningRapports($company);
        $this->serviceClient($company);
        $this->receptionFournisseurs($company);
        $this->rentabiliteProjets($company);
        $this->conditionsPaiement($company);
        $this->encaissementsMobileMoney($company);
        $this->previsionTresorerie($company);
        $this->sitesVitrines($company);
        $this->comptaCabinet($company);
        $this->appelsFondsRecouvrement($company);
        $this->importationsLocations($company);
        $this->groupeConsolidation($company);
        $this->agencesDemo($company);
        $this->assistantIa($company);
        $this->boutiqueEnLigne($company);
        $this->gestionLocative($company);
        $this->chantiersBtp($company);
        $this->historiqueAnneePrecedente($company);
        $this->reunionsWebinaires($company);
        $this->banqueConnectee($company);
        $this->integrations($company);
        $this->boiteMail($company);
        $this->whatsappInbox($company);
        $this->avisGoogle($company);
        $this->woocommerce($company);
        $this->shopifyDemo($company);
        $this->paymentLinks($company);
        $this->servicesDemo($company);
        $this->marchesDemo($company);
        $this->locationDemo($company);
        $this->maintenanceDemo($company);
        $this->v083Demo($company);
    }

    /** Pilotage (objectifs), qualité (plans, contrôles, non-conformités, actions), questionnaires (satisfaction, NPS). */
    protected function v083Demo(Company $company): void
    {
        $DB = \Illuminate\Support\Facades\DB::class;
        if (! \Illuminate\Support\Facades\Schema::hasTable('qa_plans') || $DB::table('qa_plans')->where('company_id', $company->id)->exists()) {
            return;
        }
        $company = $company->fresh();
        app()->instance('tenant', $company);
        app(\App\Support\ModuleRegistry::class)->ensureSetup($company, ['pilotage', 'qualite', 'questionnaires']);
        $admin = User::where('company_id', $company->id)->orderBy('id')->first();
        $role = fn ($n) => \App\Models\Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $base = ['company_id' => $company->id, 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()];
        // ---------------------------------------------------------------- pilotage : objectifs
        $y = (int) now()->year;
        $rev = (float) app(\App\Modules\Pilotage\PilotageService::class)->monthly($company, ($y - 1).'-01-01', ($y - 1).'-12-31')['revenue'] ? array_sum(app(\App\Modules\Pilotage\PilotageService::class)->monthly($company, ($y - 1).'-01-01', ($y - 1).'-12-31')['revenue']) : 0;
        $ytd = array_sum(app(\App\Modules\Pilotage\PilotageService::class)->monthly($company, $y.'-01-01', now()->toDateString())['revenue']);
        $annual = round(max($rev * 1.15, $ytd / max(1, now()->month) * 12 * 1.1), -6);
        $DB::table('pl_targets')->insert($base + ['title' => 'Année '.$y, 'year' => $y, 'month' => null, 'revenue' => $annual, 'margin' => round($annual * 0.3, -5), 'notes' => 'Objectif validé en comité de direction.']);
        $DB::table('pl_targets')->insert($base + ['title' => 'Décembre '.$y, 'year' => $y, 'month' => 12, 'revenue' => round($annual * 0.14, -5), 'notes' => 'Fêtes de fin d\'année.']);
        // ---------------------------------------------------------------- qualité
        $resp = User::firstOrCreate(['email' => 'qualite@demo.ci'], ['company_id' => $company->id, 'name' => 'Diabaté Mariam', 'password' => 'demo1234', 'role_id' => $role('Responsable qualité'), 'is_active' => true]);
        $ctrl = User::firstOrCreate(['email' => 'controle@demo.ci'], ['company_id' => $company->id, 'name' => 'Yao Désiré', 'password' => 'demo1234', 'role_id' => $role('Contrôleur qualité'), 'is_active' => true]);
        $someone = User::where('company_id', $company->id)->whereNotIn('id', [$admin->id, $resp->id, $ctrl->id])->orderBy('id')->first() ?? $admin;
        $plans = [];
        foreach ([
            ['Réception des marchandises', 'reception', 'each', 80, "Bon de livraison conforme à la commande\nQuantités vérifiées\nEmballages intacts\nDates de péremption supérieures à 3 mois\nTempérature conforme (produits frais)"],
            ['Hygiène de la cuisine', 'hygiene', 'daily', 100, "Plans de travail nettoyés et désinfectés\nChambres froides entre 0 et 4 °C\nTenues et charlottes portées\nPoubelles vidées et fermées\nAucun produit périmé"],
            ['Contrôle produit fini avant expédition', 'finished', 'each', 90, "Aspect et couleur\nPoids conforme (± 2 %)\nÉtiquetage complet\nScellé présent"],
            ['Sécurité du magasin', 'safety', 'monthly', 100, "Extincteurs accessibles et vérifiés\nIssues de secours dégagées\nTrousse de secours complète\nÉclairage de sécurité fonctionnel"],
        ] as [$title, $scope, $freq, $th, $crit]) {
            $plans[$scope] = $DB::table('qa_plans')->insertGetId($base + ['owner_id' => $resp->id, 'title' => $title, 'scope' => $scope, 'frequency' => $freq, 'threshold' => $th, 'criteria' => $crit, 'auto_nc' => true, 'active' => true]);
        }
        $h = app(\App\Modules\Qualite\QualiteHooks::class);
        $suppliers = $DB::table('partners')->where('company_id', $company->id)->whereIn('type', ['supplier', 'both'])->whereNull('deleted_at')->pluck('id')->all();
        $insp = function ($plan, $subject, $daysAgo, $values, $partner = null) use ($DB, $base, $ctrl, $h, $company) {
            $id = $DB::table('qa_inspections')->insertGetId(array_merge($base, ['owner_id' => $ctrl->id, 'plan_id' => $plan, 'title' => $DB::table('qa_plans')->where('id', $plan)->value('title'),
                'done_at' => now()->subDays($daysAgo)->setTime(8 + $daysAgo % 8, 30), 'subject' => $subject, 'partner_id' => $partner, 'result' => 'pending']));
            if ($values) {
                $h->answer($company, $ctrl, $id, array_map(fn ($v) => ['value' => $v, 'comment' => $v === 'nok' ? 'Constaté lors du contrôle' : null], $values), null);
            }
        };
        foreach (range(1, 18) as $k) {
            $insp($plans['hygiene'], 'Cuisine — service du midi', $k, $k === 6 ? ['ok', 'nok', 'ok', 'ok', 'ok'] : ['ok', 'ok', 'ok', 'ok', 'ok']);
        }
        foreach ([[2, ['ok', 'ok', 'ok', 'ok', 'na']], [5, ['ok', 'nok', 'ok', 'ok', 'na']], [9, ['ok', 'ok', 'nok', 'nok', 'nok']], [14, ['ok', 'ok', 'ok', 'ok', 'ok']], [21, ['ok', 'ok', 'ok', 'nok', 'ok']]] as $i => [$d, $v]) {
            $insp($plans['reception'], 'Livraison BL-'.(4410 + $i), $d, $v, $suppliers[$i % max(1, count($suppliers))] ?? null);
        }
        $insp($plans['finished'], 'Lot JUS-0412', 3, ['ok', 'ok', 'ok', 'ok']);
        $insp($plans['finished'], 'Lot JUS-0413', 1, ['ok', 'nok', 'ok', 'ok']);
        $insp($plans['safety'], 'Magasin de Marcory', 25, ['ok', 'ok', 'nok', 'ok']);
        $insp($plans['reception'], 'Livraison BL-4416', 0, []);
        $n = (int) $DB::table('qa_nonconformities')->where('company_id', $company->id)->count();
        $nc = function (array $r) use ($DB, $base, &$n, $resp) {
            $n++;

            return $DB::table('qa_nonconformities')->insertGetId(array_merge($base, ['owner_id' => $resp->id, 'number' => 'NC-'.now()->format('Y').'-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT)], $r));
        };
        $customers = $DB::table('partners')->where('company_id', $company->id)->whereIn('type', ['customer', 'both'])->whereNull('deleted_at')->pluck('id')->all();
        $c1 = $nc(['title' => 'Client livré avec des jus au goût altéré', 'source' => 'customer', 'severity' => 'major', 'status' => 'action', 'detected_on' => now()->subDays(6)->toDateString(), 'reporter_id' => $someone->id,
            'partner_id' => $customers[0] ?? null, 'product' => 'Jus d\'ananas 1 L — lot JUS-0409', 'description' => 'Le client signale 12 bouteilles au goût fermenté sur 48 livrées.', 'containment' => 'Lot JUS-0409 bloqué en stock, bouteilles remplacées chez le client.',
            'cause_category' => 'material', 'root_cause' => "Pourquoi le jus a fermenté ? Rupture de la chaîne du froid.\nPourquoi ? Camion frigorifique en panne 3 heures.\nPourquoi ? Pas de contrôle de température au chargement.", 'cost' => 186000, 'due_date' => now()->addDay()->toDateString()]);
        $c2 = $nc(['title' => 'Écart de quantité récurrent chez un fournisseur', 'source' => 'supplier', 'severity' => 'minor', 'status' => 'analysis', 'detected_on' => now()->subDays(9)->toDateString(), 'reporter_id' => $ctrl->id,
            'partner_id' => $suppliers[0] ?? null, 'product' => 'Bouteilles PET 1 L', 'description' => '3 livraisons sur 5 avec des cartons manquants.', 'cost' => 45000, 'due_date' => now()->addDays(20)->toDateString()]);
        $nc(['title' => 'Chute d\'un employé dans la réserve (sol mouillé)', 'source' => 'incident', 'severity' => 'major', 'status' => 'closed', 'detected_on' => now()->subDays(40)->toDateString(), 'reporter_id' => $someone->id,
            'description' => 'Glissade sans gravité, 1 jour d\'arrêt.', 'cause_category' => 'environment', 'root_cause' => 'Fuite de la climatisation non signalée.', 'effective' => true, 'cost' => 35000, 'closed_on' => now()->subDays(20)->toDateString(),
            'due_date' => now()->subDays(33)->toDateString()]);
        $nc(['title' => 'Temps d\'attente trop long en caisse le samedi', 'source' => 'customer', 'severity' => 'minor', 'status' => 'open', 'detected_on' => now()->subDays(2)->toDateString(), 'reporter_id' => $someone->id,
            'description' => 'Trois clients se sont plaints d\'une attente de plus de 20 minutes.', 'due_date' => now()->addDays(28)->toDateString()]);
        foreach ([
            [$c1, 'Contrôler la température du camion à chaque chargement', 'corrective', 'done', -2, $ctrl->id, 'Thermomètre enregistreur installé, relevé sur le bon de chargement.'],
            [$c1, 'Réviser le groupe froid du camion', 'corrective', 'in_progress', 3, $resp->id, null],
            [$c1, 'Former les livreurs à la chaîne du froid', 'preventive', 'todo', -1, $resp->id, null],
            [$c2, 'Exiger le pointage contradictoire à la livraison', 'corrective', 'todo', 10, $ctrl->id, null],
        ] as [$ncId, $title, $kind, $st, $due, $owner, $result]) {
            $DB::table('qa_actions')->insert($base + ['owner_id' => $owner, 'nc_id' => $ncId, 'title' => $title, 'kind' => $kind, 'status' => $st, 'due_date' => now()->addDays($due)->toDateString(),
                'done_on' => $st === 'done' ? now()->subDay()->toDateString() : null, 'result' => $result]);
        }
        // ---------------------------------------------------------------- questionnaires
        $svc = app(\App\Modules\Questionnaires\QuestionnaireService::class);
        $mk = function ($title, $kind, $status, $desc) use ($DB, $base, $admin) {
            return $DB::table('qs_forms')->insertGetId($base + ['owner_id' => $admin->id, 'title' => $title, 'kind' => $kind, 'status' => $status, 'token' => \Illuminate\Support\Str::random(24),
                'questions' => json_encode(\App\Modules\Questionnaires\QuestionnaireService::starter($kind), JSON_UNESCAPED_UNICODE), 'description' => $desc,
                'thank_you' => 'Merci pour vos réponses ! Elles nous aident à nous améliorer.', 'ask_contact' => true, 'alert_unhappy' => true]);
        };
        $sat = $mk('Votre avis sur notre service', 'satisfaction', 'open', 'Une minute pour nous dire comment s\'est passée votre visite.');
        $nps = $mk('Nous recommanderiez-vous ?', 'nps', 'open', null);
        $mk('Évaluation de la formation caisse', 'evaluation', 'draft', 'Formation du personnel de caisse — session de novembre.');
        $names = ['Awa K.', 'Koffi B.', null, 'Mariam T.', 'Jean-Marc A.', null, 'Fatou D.', 'Serge Y.', null, 'Aminata S.', 'Ibrahim O.', null, 'Christelle N.', 'Paul E.', null, 'Rokia C.', 'Hervé G.', 'Nadège L.', null, 'Moussa F.', 'Adjoua M.', 'Eric Z.'];
        $comments = ['Très bon accueil, merci !', 'Un peu d\'attente à la caisse.', 'Produits frais et de qualité.', 'Parking difficile.', 'Personnel souriant.', 'Les prix ont augmenté.', null, 'Parfait comme d\'habitude.'];
        $f = $DB::table('qs_forms')->find($sat);
        $qs = \App\Modules\Questionnaires\QuestionnaireService::questions($f);
        foreach ($names as $i => $name) {
            $g = [5, 4, 5, 3, 5, 4, 4, 2, 5, 4, 5, 3, 4, 5, 1, 4, 5, 4, 3, 5, 4, 5][$i];
            $ans = [$qs[0]['id'] => $g, $qs[1]['id'] => min(5, $g + ($i % 2)), $qs[2]['id'] => max(1, $g - ($i % 3 === 0 ? 1 : 0)), $qs[3]['id'] => $g, $qs[4]['id'] => min(10, $g * 2 - ($i % 2)),
                $qs[5]['id'] => $comments[$i % count($comments)]];
            $r = $svc->store($f, array_filter($ans, fn ($v) => $v !== null), ['name' => $name, 'phone' => $name ? '07 '.str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT).' 45 67 89' : null], $i % 4 === 0 ? 'app' : 'link');
            $DB::table('qs_responses')->where('id', $r['id'])->update(['submitted_at' => now()->subDays(28 - $i)->setTime(10 + $i % 8, 15)]);
            $f = $DB::table('qs_forms')->find($sat);
        }
        $f = $DB::table('qs_forms')->find($nps);
        $qn = \App\Modules\Questionnaires\QuestionnaireService::questions($f);
        foreach ([10, 9, 8, 10, 6, 9, 7, 10, 9, 4, 8, 10] as $i => $v) {
            $r = $svc->store($f, [$qn[0]['id'] => $v, $qn[1]['id'] => $v >= 9 ? 'Service rapide et fiable.' : ($v >= 7 ? 'Bien, mais peut mieux faire.' : 'Livraison en retard.')], [], 'link');
            $DB::table('qs_responses')->where('id', $r['id'])->update(['submitted_at' => now()->subDays(20 - $i)->setTime(15, 0)]);
            $f = $DB::table('qs_forms')->find($nps);
        }
        app()->forgetInstance('tenant');
    }

    /** Maintenance : équipements du siège et de l'entrepôt, plans préventifs, historique de pannes, demandes en cours. */
    protected function maintenanceDemo(Company $company): void
    {
        $DB = \Illuminate\Support\Facades\DB::class;
        if (! \Illuminate\Support\Facades\Schema::hasTable('mt_equipment') || $DB::table('mt_equipment')->where('company_id', $company->id)->exists()) {
            return;
        }
        $company = $company->fresh();
        app()->instance('tenant', $company);
        app(\App\Support\ModuleRegistry::class)->ensureSetup($company, ['maintenance']);
        $admin = User::where('company_id', $company->id)->orderBy('id')->first();
        $role = fn ($n) => \App\Models\Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $tech = User::firstOrCreate(['email' => 'maintenance@demo.ci'], ['company_id' => $company->id, 'name' => 'Ouattara Ibrahim', 'password' => 'demo1234', 'role_id' => $role('Technicien maintenance'), 'is_active' => true]);
        $chef = User::firstOrCreate(['email' => 'chef.maintenance@demo.ci'], ['company_id' => $company->id, 'name' => 'Kouamé Serge', 'password' => 'demo1234', 'role_id' => $role('Responsable maintenance'), 'is_active' => true]);
        $someone = User::where('company_id', $company->id)->whereNotIn('id', [$admin->id, $tech->id, $chef->id])->orderBy('id')->first() ?? $admin;
        $eq = [];
        foreach ([
            ['Groupe électrogène 100 kVA', 'GE-01', 'Énergie', 'Siège — Marcory', 'SDMO', 'J100K', 'high', 'h', 3420, -900, 300],
            ['Climatiseur salle serveurs', 'CLIM-SRV', 'Froid & climatisation', 'Siège — Marcory', 'Daikin', 'FTXM50', 'high', null, null, -700, 60],
            ['Chambre froide entrepôt', 'CF-01', 'Froid & climatisation', 'Entrepôt — Yopougon', 'Carrier', 'CF-40', 'high', null, null, -1500, -100],
            ['Chariot élévateur', 'CHAR-01', 'Manutention', 'Entrepôt — Yopougon', 'Toyota', '8FBE15', 'medium', 'h', 6115, -2000, -500],
            ['Compresseur d\'air', 'COMP-01', 'Production', 'Atelier', 'Atlas Copco', 'GA11', 'medium', 'h', 8890, -1800, -400],
            ['Onduleur central 20 kVA', 'UPS-01', 'Énergie', 'Siège — Marcory', 'APC', 'Galaxy VS', 'high', null, null, -400, 330],
            ['Photocopieur multifonction', 'COPY-02', 'Bureautique', 'Siège — Marcory', 'Canon', 'iR-ADV C5535', 'low', 'cycles', 182400, -600, 120],
        ] as [$title, $code, $cat, $loc, $brand, $model, $crit, $unit, $meter, $since, $warranty]) {
            $eq[$code] = $DB::table('mt_equipment')->insertGetId(['company_id' => $company->id, 'created_by' => $admin->id, 'owner_id' => $tech->id, 'title' => $title, 'code' => $code, 'category' => $cat,
                'location' => $loc, 'brand' => $brand, 'model' => $model, 'criticality' => $crit, 'status' => 'running', 'meter_unit' => $unit, 'meter_value' => $meter,
                'commissioned_on' => now()->addDays($since)->toDateString(), 'warranty_until' => now()->addDays($warranty)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $plans = [];
        foreach ([
            ['GE-01', 'Vidange, filtres à huile et à gasoil', 'meter', 250, 'h', 3200, -40, "Vidanger l'huile moteur\nRemplacer filtre à huile et filtre à gasoil\nContrôler le niveau du liquide de refroidissement\nEssai en charge 30 minutes"],
            ['GE-01', 'Essai mensuel en charge', 'date', 1, 'months', null, -26, "Démarrage manuel\nBasculement sur le groupe\nRelever tension, fréquence, température"],
            ['CLIM-SRV', 'Nettoyage des filtres et contrôle du gaz', 'date', 3, 'months', null, -85, "Couper l'alimentation\nNettoyer les filtres\nContrôler la pression du gaz\nVérifier l'écoulement des condensats"],
            ['CF-01', 'Contrôle froid et dégivrage', 'date', 1, 'months', null, -12, "Relever la température\nDégivrer l'évaporateur\nVérifier les joints de porte"],
            ['CHAR-01', 'Révision 500 heures', 'meter', 500, 'h', 5700, -120, "Contrôler les freins\nGraisser le mât\nVérifier la batterie et le chargeur"],
            ['COMP-01', 'Entretien 2 000 heures', 'meter', 2000, 'h', 7000, -200, "Remplacer le séparateur d'huile\nRemplacer le filtre à air\nVidange"],
            ['UPS-01', 'Contrôle annuel des batteries', 'date', 12, 'months', null, -330, "Test d'autonomie\nMesure des batteries\nNettoyage"],
        ] as [$code, $title, $trigger, $every, $unit, $lastMeter, $lastDays, $check]) {
            $last = now()->addDays($lastDays)->toDateString();
            $plans[$code.$title] = $DB::table('mt_plans')->insertGetId(['company_id' => $company->id, 'created_by' => $admin->id, 'equipment_id' => $eq[$code], 'title' => $title, 'trigger' => $trigger, 'every' => $every,
                'unit' => $unit, 'lead_days' => 7, 'last_done_on' => $last, 'last_meter' => $lastMeter,
                'next_due_on' => $trigger === 'date' ? \App\Modules\Maintenance\MaintenanceHooks::nextDate($last, $every, $unit) : null, 'next_meter' => $trigger === 'meter' ? $lastMeter + $every : null,
                'assignee_id' => $tech->id, 'estimated_minutes' => 90, 'checklist' => $check, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $n = 0;
        $ot = function (array $r) use ($DB, $company, $admin, &$n) {
            $n++;

            return $DB::table('mt_orders')->insertGetId($r + ['company_id' => $company->id, 'created_by' => $admin->id, 'number' => 'OT-'.now()->format('Y').'-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                'updated_at' => now()]);
        };
        // historique : pannes réparées sur l'année
        foreach ([
            ['CF-01', 'Chambre froide à +8 °C : compresseur en défaut', 'electrical', 'urgent', -210, 6.5, 12, 185000, 45000, 0, 'Relais thermique remplacé, contacteur nettoyé.'],
            ['CHAR-01', 'Fuite hydraulique sur le vérin de levage', 'hydraulic', 'high', -150, 26, 4, 95000, 30000, 0, 'Joints du vérin remplacés.'],
            ['GE-01', 'Le groupe ne démarre pas lors de la coupure', 'electrical', 'urgent', -95, 3, 2.5, 120000, 25000, 0, 'Batterie de démarrage remplacée.'],
            ['COMP-01', 'Surchauffe et arrêt du compresseur', 'lack', 'high', -60, 9, 5, 60000, 35000, 0, 'Radiateur colmaté : nettoyage complet, rappel du préventif.'],
            ['CLIM-SRV', 'Climatiseur salle serveurs en panne', 'mechanical', 'urgent', -33, 4, 3, 0, 20000, 150000, 'Moteur de ventilateur remplacé par le prestataire Froid Service CI.'],
            ['COPY-02', 'Bourrages papier à répétition', 'wear', 'normal', -18, null, 1, 45000, 10000, 0, 'Rouleaux d\'entraînement remplacés.'],
        ] as [$code, $title, $cause, $prio, $daysAgo, $down, $hours, $parts, $labour, $ext, $report]) {
            $created = now()->addDays($daysAgo)->setTime(9, 15);
            $ot(['title' => $title, 'equipment_id' => $eq[$code], 'kind' => 'corrective', 'priority' => $prio, 'status' => 'done', 'equipment_stopped' => $down !== null, 'requester_id' => $someone->id, 'assignee_id' => $tech->id,
                'planned_on' => $created->toDateString(), 'started_at' => $created->copy()->addHour(), 'finished_at' => $created->copy()->addHours((int) ceil(($down ?? $hours) + 1)), 'downtime_hours' => $down,
                'labour_hours' => $hours, 'labour_cost' => $labour, 'parts_cost' => $parts, 'external_cost' => $ext ?: null, 'cause' => $cause, 'report' => $report, 'created_at' => $created]);
        }
        // préventif réalisé
        foreach ([['GE-01Essai mensuel en charge', 'GE-01', -26], ['CLIM-SRVNettoyage des filtres et contrôle du gaz', 'CLIM-SRV', -85], ['CF-01Contrôle froid et dégivrage', 'CF-01', -12], ['GE-01Vidange, filtres à huile et à gasoil', 'GE-01', -40]] as [$pk, $code, $d]) {
            $at = now()->addDays($d)->setTime(10, 0);
            $ot(['title' => explode($code, $pk, 2)[1].' — '.$DB::table('mt_equipment')->where('id', $eq[$code])->value('title'), 'equipment_id' => $eq[$code], 'plan_id' => $plans[$pk], 'kind' => 'preventive', 'priority' => 'normal',
                'status' => 'done', 'assignee_id' => $tech->id, 'planned_on' => $at->toDateString(), 'started_at' => $at, 'finished_at' => $at->copy()->addMinutes(80), 'labour_hours' => 1.5, 'labour_cost' => 15000,
                'report' => 'Contrôles effectués, RAS.', 'created_at' => $at->copy()->subDays(7)]);
        }
        // en cours : panne du chariot (à l'arrêt), demande non traitée, attente de pièces
        $ot(['title' => 'Le chariot élévateur ne lève plus les charges lourdes', 'equipment_id' => $eq['CHAR-01'], 'kind' => 'corrective', 'priority' => 'high', 'status' => 'in_progress', 'equipment_stopped' => true,
            'requester_id' => $someone->id, 'assignee_id' => $tech->id, 'planned_on' => now()->toDateString(), 'started_at' => now()->subHours(2), 'description' => 'Signalé par le magasinier : bruit anormal de la pompe.', 'created_at' => now()->subHours(5)]);
        $DB::table('mt_equipment')->where('id', $eq['CHAR-01'])->update(['status' => 'down']);
        $ot(['title' => 'Porte de la chambre froide ferme mal', 'equipment_id' => $eq['CF-01'], 'kind' => 'corrective', 'priority' => 'normal', 'status' => 'requested', 'requester_id' => $someone->id,
            'description' => 'Le joint semble abîmé en bas à droite.', 'created_at' => now()->subHours(20)]);
        $ot(['title' => 'Remplacement du tambour d\'impression', 'equipment_id' => $eq['COPY-02'], 'kind' => 'corrective', 'priority' => 'low', 'status' => 'on_hold', 'requester_id' => $someone->id, 'assignee_id' => $tech->id,
            'planned_on' => now()->subDays(3)->toDateString(), 'started_at' => now()->subDays(3), 'report' => 'Tambour commandé chez le fournisseur (délai 5 jours).', 'created_at' => now()->subDays(4)]);
        $ot(['title' => 'Contrôle des extincteurs', 'kind' => 'inspection', 'priority' => 'normal', 'status' => 'planned', 'assignee_id' => $tech->id, 'planned_on' => now()->addDays(4)->toDateString(), 'created_at' => now()->subDay()]);
        // relevés de compteur récents ; le compresseur approche de son entretien
        foreach ([['GE-01', 3380, -14], ['GE-01', 3420, -1], ['CHAR-01', 6115, -2], ['COMP-01', 8890, -1], ['COPY-02', 182400, -3]] as [$code, $v, $d]) {
            $DB::table('mt_readings')->insert(['company_id' => $company->id, 'created_by' => $tech->id, 'equipment_id' => $eq[$code], 'value' => $v, 'read_on' => now()->addDays($d)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        }
        app(\App\Modules\Maintenance\MaintenanceHooks::class)->generate($company);
        app()->forgetInstance('tenant');
    }

    /** Location de matériel : parc événementiel et BTP, contrats à toutes les étapes (dont un retour en retard). */
    protected function locationDemo(Company $company): void
    {
        $DB = \Illuminate\Support\Facades\DB::class;
        if (! \Illuminate\Support\Facades\Schema::hasTable('lo_items') || $DB::table('lo_items')->where('company_id', $company->id)->exists()) {
            return;
        }
        $company = $company->fresh();
        app()->instance('tenant', $company);
        app(\App\Support\ModuleRegistry::class)->ensureSetup($company, ['location']);
        $admin = User::where('company_id', $company->id)->orderBy('id')->first();
        $role = \App\Models\Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Gestionnaire location')->value('id');
        $yao = User::firstOrCreate(['email' => 'location@demo.ci'], ['company_id' => $company->id, 'name' => 'Yao Location', 'password' => 'demo1234', 'role_id' => $role, 'is_active' => true]);
        $h = app(\App\Modules\Location\LocationHooks::class);
        $items = [];
        foreach ([
            ['Groupe électrogène 20 kVA', 'Énergie', 'GE-20-01', 1, 45000, 250000, 800000, 300000, 'Dépôt Yopougon'], ['Groupe électrogène 60 kVA', 'Énergie', 'GE-60-01', 1, 120000, 650000, 2200000, 750000, 'Dépôt Yopougon'],
            ['Sonorisation complète 2 000 W', 'Son & lumière', 'SONO-01', 2, 75000, 400000, null, 200000, 'Magasin Marcory'], ['Chapiteau 10 × 20 m', 'Événementiel', null, 3, 150000, null, null, 250000, 'Dépôt Yopougon'],
            ['Chaise pliante', 'Mobilier', null, 400, 300, 1500, null, 1000, 'Magasin Marcory'], ['Table ronde 10 places', 'Mobilier', null, 40, 2500, 12000, null, 10000, 'Magasin Marcory'],
            ['Bétonnière 350 L', 'BTP', 'BET-350-02', 2, 25000, 140000, 450000, 150000, 'Dépôt Yopougon'], ['Échafaudage (module 2 m)', 'BTP', null, 60, 1500, 8000, 25000, 15000, 'Dépôt Yopougon'],
            ['Climatiseur mobile', 'Confort', null, 6, 20000, 110000, null, 100000, 'Magasin Marcory'],
        ] as [$title, $cat, $ref, $qty, $day, $week, $month, $dep, $loc]) {
            $items[$title] = $DB::table('lo_items')->insertGetId(['company_id' => $company->id, 'created_by' => $admin->id, 'title' => $title, 'category' => $cat, 'reference' => $ref, 'quantity' => $qty,
                'rate_day' => $day, 'rate_week' => $week, 'rate_month' => $month, 'deposit' => $dep, 'replacement_value' => $dep * 5, 'status' => $title === 'Climatiseur mobile' ? 'available' : 'available', 'location' => $loc,
                'created_at' => now(), 'updated_at' => now()]);
        }
        $DB::table('lo_items')->where('id', $items['Bétonnière 350 L'])->update(['quantity' => 2]);
        $partners = $DB::table('partners')->where('company_id', $company->id)->whereNull('deleted_at')->whereIn('type', ['customer', 'both'])->orderBy('name')->pluck('id')->all();
        $n = 0;
        $make = function (array $r, array $lines, array $steps) use ($DB, $company, $admin, $yao, $h, &$n) {
            $n++;
            $id = $DB::table('lo_rentals')->insertGetId($r + ['company_id' => $company->id, 'created_by' => $admin->id, 'owner_id' => $yao->id, 'number' => 'LOC-'.now()->format('Y').'-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                'status' => 'quote', 'tax_rate' => 18, 'created_at' => now(), 'updated_at' => now()]);
            $row = (array) $DB::table('lo_rentals')->find($id);
            $days = $h::days($row['start_at'], $row['end_at']);
            foreach ($lines as [$item, $qty, $pricing, $out]) {
                $it = $DB::table('lo_items')->find($item);
                $price = $h::rateFor($it, $pricing);
                $DB::table('lo_lines')->insert(['company_id' => $company->id, 'created_by' => $admin->id, 'rental_id' => $id, 'item_id' => $item, 'quantity' => $qty, 'pricing' => $pricing, 'unit_price' => $price,
                    'amount' => $qty * $price * $h::periods($pricing, $days), 'condition_out' => $out, 'created_at' => now(), 'updated_at' => now()]);
            }
            $h->recompute($id);
            foreach ($steps as [$action, $in]) {
                try {
                    $h->action($company, $admin, $id, $action, $in);
                } catch (\Throwable) {
                }
            }

            return $id;
        };
        $d = fn ($days, $h = 8) => now()->startOfDay()->addDays($days)->setTime($h, 0)->toDateTimeString();
        $make(['partner_id' => $partners[0] ?? null, 'start_at' => $d(-12), 'end_at' => $d(-9, 18), 'delivery' => true, 'delivery_fee' => 35000, 'delivery_address' => 'Hôtel Ivoire, Cocody'],
            [[$items['Chapiteau 10 × 20 m'], 2, 'day', 'Neuf'], [$items['Chaise pliante'], 300, 'day', null], [$items['Table ronde 10 places'], 30, 'day', null], [$items['Sonorisation complète 2 000 W'], 1, 'day', 'Bon état']],
            [['reserve', ['deposit_received' => true, 'deposit_method' => 'transfer']]]);
        // contrats passés : sortis et rendus (via SQL pour dater dans le passé)
        $past = $DB::table('lo_rentals')->where('company_id', $company->id)->orderByDesc('id')->value('id');
        $DB::table('lo_rentals')->where('id', $past)->update(['status' => 'returned', 'out_at' => $d(-12), 'returned_at' => $d(-9, 17), 'deposit_status' => 'refunded']);
        if ($company->hasModule('ventes') && ($partners[0] ?? null)) {
            try {
                $h->action($company, $admin, $past, 'invoice', []);
            } catch (\Throwable) {
            }
        }
        $late = $make(['customer_name' => 'Entreprise Bâti Plus', 'phone' => '07 08 45 12 33', 'start_at' => $d(-10), 'end_at' => $d(-1, 18)],
            [[$items['Bétonnière 350 L'], 1, 'week', 'Bon état, révisée'], [$items['Échafaudage (module 2 m)'], 24, 'week', null]], []);
        $DB::table('lo_rentals')->where('id', $late)->update(['status' => 'out', 'out_at' => $d(-10), 'deposit_amount' => 510000, 'deposit_status' => 'received', 'deposit_method' => 'cash']);
        $cur = $make(['partner_id' => $partners[1] ?? null, 'start_at' => $d(-2), 'end_at' => $d(3, 18), 'delivery' => true, 'delivery_fee' => 50000, 'delivery_address' => 'Chantier Riviera Palmeraie'],
            [[$items['Groupe électrogène 60 kVA'], 1, 'week', 'Bon état — 1 245 h au compteur']], []);
        $DB::table('lo_rentals')->where('id', $cur)->update(['status' => 'out', 'out_at' => $d(-2), 'deposit_amount' => 750000, 'deposit_status' => 'received', 'deposit_method' => 'cheque']);
        $make(['customer_name' => 'Mariage Kouadio & Ama', 'phone' => '05 55 12 98 76', 'start_at' => $d(1, 7), 'end_at' => $d(2, 12), 'discount_percent' => 10],
            [[$items['Chaise pliante'], 250, 'day', null], [$items['Table ronde 10 places'], 25, 'day', null], [$items['Chapiteau 10 × 20 m'], 1, 'day', null], [$items['Groupe électrogène 20 kVA'], 1, 'day', null]],
            [['reserve', ['deposit_received' => true, 'deposit_method' => 'mobile_money']]]);
        $make(['partner_id' => $partners[2] ?? null, 'start_at' => $d(8), 'end_at' => $d(10, 18)],
            [[$items['Climatiseur mobile'], 4, 'day', null], [$items['Sonorisation complète 2 000 W'], 1, 'day', null]], []);
        $make(['customer_name' => 'Église Bethel Yopougon', 'phone' => '01 02 03 04 05', 'start_at' => $d(5), 'end_at' => $d(5, 22)],
            [[$items['Chaise pliante'], 120, 'flat', null], [$items['Sonorisation complète 2 000 W'], 1, 'flat', null]], [['reserve', []]]);
        app()->forgetInstance('tenant');
    }

    /** Appels d'offres & marchés : veille, dossier en préparation, offre déposée, marché en exécution avec décomptes et cautions. */
    protected function marchesDemo(Company $company): void
    {
        $DB = \Illuminate\Support\Facades\DB::class;
        if (! \Illuminate\Support\Facades\Schema::hasTable('mp_tenders') || $DB::table('mp_tenders')->where('company_id', $company->id)->exists()) {
            return;
        }
        $company = $company->fresh();
        app()->instance('tenant', $company);
        app(\App\Support\ModuleRegistry::class)->ensureSetup($company, ['marches_publics']);
        $admin = User::where('company_id', $company->id)->orderBy('id')->first();
        $role = \App\Models\Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Chargé des marchés')->value('id');
        $awa = User::firstOrCreate(['email' => 'marches@demo.ci'], ['company_id' => $company->id, 'name' => 'Awa Bamba', 'password' => 'demo1234', 'role_id' => $role, 'is_active' => true]);
        $ins = fn ($t, array $row) => $DB::table($t)->insertGetId($row + ['company_id' => $company->id, 'created_by' => $admin->id, 'owner_id' => $awa->id, 'created_at' => now(), 'updated_at' => now()]);
        $h = app(\App\Modules\MarchesPublics\MarchesHooks::class);
        foreach ([
            ['Attestation de régularité fiscale', 'tax', 'ARF-2026-118452', -60, 25], ['Attestation CNPS', 'cnps', 'CNPS-0458812', -20, 70], ['Registre du commerce', 'rccm', 'CI-ABJ-2019-B-04512', -2400, null],
            ['Déclaration fiscale d\'existence', 'dfe', 'DFE-1904512 K', -2400, null], ['Attestation de non-faillite', 'bankruptcy', 'TC-NF-2026-0912', -70, 20], ['Attestation de capacité financière — SGCI', 'bank', 'SGCI-AT-2026-77', -30, 150],
            ['Attestation de bonne exécution — Mairie du Plateau', 'reference', null, -200, null],
        ] as [$title, $kind, $num, $issued, $exp]) {
            $ins('mp_documents', ['title' => $title, 'kind' => $kind, 'number' => $num, 'issued_on' => now()->addDays($issued)->toDateString(), 'expires_on' => $exp === null ? null : now()->addDays($exp)->toDateString()]);
        }
        $partners = $DB::table('partners')->where('company_id', $company->id)->whereNull('deleted_at')->whereIn('type', ['customer', 'both'])->orderBy('name')->pluck('id')->all();
        $t = [];
        foreach ([
            ['Fourniture et installation de matériel informatique pour les lycées de la région du Gbêkê', 'AO n° 2026-0147/MENA', 'Ministère de l\'Éducation nationale', 'open', 'supplies', 'preparing', 9, 185000000, 3500000, 'Lot 2 — Bouaké', 'high'],
            ['Maintenance du réseau informatique et de la téléphonie (3 ans)', 'DC n° 045/2026/PAA', 'Port Autonome d\'Abidjan', 'quotation', 'services', 'go', 4, 42000000, null, null, 'medium'],
            ['Équipement en vidéosurveillance des marchés municipaux', 'AO n° 12/2026/MC', 'Mairie de Cocody', 'open', 'supplies', 'submitted', -6, 96000000, 2000000, null, 'medium'],
            ['Assistance à maîtrise d\'ouvrage — schéma directeur informatique', 'AMI n° 03/2026/CIE', 'CIE', 'ami', 'intellectual', 'watch', 21, null, null, null, null],
            ['Câblage des bureaux de la Direction régionale', 'AO n° 07/2026/DGI', 'Direction générale des impôts', 'restricted', 'works', 'lost', -40, 38000000, 750000, null, 'low'],
            ['Fourniture de postes de travail et onduleurs', 'AO n° 2026-0032/MSHP', 'Ministère de la Santé', 'open', 'supplies', 'won', -75, 64000000, 1200000, null, 'high'],
        ] as $i => [$title, $ref, $buyer, $proc, $sector, $st, $days, $budget, $bond, $lot, $chance]) {
            $t[$i] = $ins('mp_tenders', ['title' => $title, 'reference' => $ref, 'buyer' => $buyer, 'partner_id' => $partners[$i % max(1, count($partners))] ?? null, 'procedure' => $proc, 'sector' => $sector,
                'status' => $st, 'lot' => $lot, 'published_on' => now()->addDays($days - 30)->toDateString(), 'questions_deadline' => now()->addDays($days - 10)->toDateString(),
                'submission_at' => now()->addDays($days)->setTime(10, 0)->toDateTimeString(), 'opening_at' => now()->addDays($days)->setTime(11, 0)->toDateTimeString(),
                'estimated_amount' => $budget, 'our_bid' => $budget ? round($budget * 0.94, -4) : null, 'bid_bond_amount' => $bond, 'dao_cost' => $budget ? 100000 : null, 'validity_days' => 90, 'chance' => $chance,
                'winner' => $st === 'lost' ? 'Afrique Réseaux SA' : null, 'winning_amount' => $st === 'lost' ? 33900000 : ($st === 'won' ? 59800000 : null),
                'notes' => $i === 0 ? 'Critères : conformité technique puis prix le plus bas. Visite de site obligatoire. Concurrents attendus : 4 à 6.' : null]);
            if (in_array($st, ['preparing', 'go', 'submitted'], true)) {
                $h->createChecklist($company, (array) $DB::table('mp_tenders')->find($t[$i]));
            }
        }
        // dossier en préparation : quelques pièces avancées ; offre déposée : tout est prêt
        $DB::table('mp_pieces')->where('tender_id', $t[0])->whereIn('title', ['Lettre de soumission signée', 'Méthodologie et organisation', 'Achat du dossier d\'appel d\'offres (reçu)'])->update(['status' => 'ready']);
        $DB::table('mp_pieces')->where('tender_id', $t[0])->whereIn('title', ['Bordereau des prix unitaires', 'Planning d\'exécution'])->update(['status' => 'in_progress']);
        $DB::table('mp_pieces')->where('tender_id', $t[2])->update(['status' => 'ready']);
        $DB::table('mp_pieces')->where('tender_id', $t[1])->where('title', 'like', 'CV%')->update(['owner_id' => $admin->id]);
        // marché en exécution
        $k = $h->award($company, $t[5]);
        $DB::table('mp_contracts')->where('id', $k['id'])->update(['number' => 'M-2026-0451/MSHP', 'amount' => 59800000, 'status' => 'in_progress', 'signed_on' => now()->subDays(50)->toDateString(),
            'start_order_on' => now()->subDays(40)->toDateString(), 'duration_days' => 60, 'planned_end' => now()->addDays(20)->toDateString(), 'advance_percent' => 15, 'retention_percent' => 5, 'penalty_per_day' => 1]);
        $DB::table('mp_contracts')->where('id', $k['id'])->update(['partner_id' => $partners[0] ?? null]);
        $k2 = $ins('mp_contracts', ['title' => 'Fourniture de matériel de bureau — Mairie du Plateau', 'number' => 'M-2025-118/MP', 'buyer' => 'Mairie du Plateau', 'partner_id' => $partners[1] ?? null,
            'status' => 'provisional', 'amount' => 28500000, 'tax_rate' => 18, 'signed_on' => now()->subMonths(13)->toDateString(), 'start_order_on' => now()->subMonths(12)->toDateString(), 'duration_days' => 45,
            'planned_end' => now()->subMonths(12)->addDays(45)->toDateString(), 'retention_percent' => 5, 'warranty_months' => 12, 'provisional_acceptance_on' => now()->subMonths(11)->addDays(3)->toDateString()]);
        $stm = function ($cid, $amount, $pct, $status, $daysAgo) use ($h, $DB, $company, $admin, $awa) {
            $data = $h->saving('statements', ['contract_id' => $cid, 'amount' => $amount, 'progress_percent' => $pct, 'status' => $status, 'period_end' => now()->subDays($daysAgo)->toDateString()], null, $admin);
            $data += ['submitted_on' => now()->subDays($daysAgo - 2)->toDateString(), 'paid_on' => $status === 'paid' ? now()->subDays(max(0, $daysAgo - 25))->toDateString() : null];

            return $DB::table('mp_statements')->insertGetId($data + ['company_id' => $company->id, 'created_by' => $admin->id, 'owner_id' => $awa->id, 'created_at' => now(), 'updated_at' => now()]);
        };
        $stm($k2, 28500000, 100, 'paid', 320);
        $stm($k['id'], 23920000, 40, 'paid', 25);
        $s2 = $stm($k['id'], 14950000, 65, 'approved', 3);
        if ($company->hasModule('ventes') && ($partners[0] ?? null)) {
            try {
                $h->invoice($company, $s2);
            } catch (\Throwable) {
            }
        }
        foreach ([
            ['bid', $t[2], null, 'SGCI', 'CS-2026-0812', 2000000, 25000, -10, 80, 'issued'], ['bid', $t[0], null, 'SGCI', null, 3500000, 35000, 0, 120, 'requested'],
            ['bid', $t[4], null, 'Ecobank', 'CS-2026-0411', 750000, 15000, -50, 40, 'issued'], ['advance', null, $k['id'], 'SGCI', 'CA-2026-0153', 8970000, 90000, -45, 75, 'issued'],
            ['performance', null, $k['id'], 'SGCI', 'CBE-2026-0154', 1794000, 30000, -45, 200, 'issued'], ['retention', null, $k2, 'NSIA Banque', 'CRG-2025-0097', 1425000, 20000, -330, 18, 'issued'],
        ] as [$kind, $tid, $cid, $bank, $ref, $amount, $fees, $issued, $exp, $st]) {
            $ins('mp_bonds', ['kind' => $kind, 'tender_id' => $tid, 'contract_id' => $cid, 'bank' => $bank, 'reference' => $ref, 'amount' => $amount, 'fees' => $fees,
                'issued_on' => now()->addDays($issued)->toDateString(), 'expires_on' => now()->addDays($exp)->toDateString(), 'status' => $st]);
        }
        app()->forgetInstance('tenant');
    }

    /** Lot d'intégration : connexions bancaires (Wave Business, relevés par e-mail), règles de classement, MTN MoMo direct en test. */
    /** WooCommerce : boutique de démonstration (aucun appel au site), produits en ligne, commandes importées à différents stades. */
    protected function woocommerce(Company $company): void
    {
        $company = $company->fresh();
        if (! $company->hasModule('woocommerce') || ! \Illuminate\Support\Facades\Schema::hasTable('woo_stores')) {
            return;
        }
        $DB = \Illuminate\Support\Facades\DB::class;
        if ($DB::table('woo_stores')->where('company_id', $company->id)->where('platform', 'woocommerce')->exists()) {
            return;
        }
        app()->instance('tenant', $company);
        $sync = app(\App\Modules\Woocommerce\WooSync::class);
        $sid = $DB::table('woo_stores')->insertGetId(['company_id' => $company->id, 'platform' => 'woocommerce', 'name' => 'Boutique en ligne Démo SARL', 'url' => 'https://boutique.demo-sarl.ci',
            'credentials' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['key' => 'ck_demo', 'secret' => 'cs_demo'])), 'token' => Str::lower(Str::random(40)), 'webhook_secret' => Str::random(40),
            'status' => 'active', 'settings' => json_encode(array_replace(\App\Modules\Woocommerce\WooSync::DEFAULTS, ['stock_buffer' => 1])),
            'info' => json_encode(['demo' => true, 'currency' => 'XOF', 'prices_include_tax' => true, 'calc_taxes' => true, 'version' => '9.4.1', 'webhooks' => ['order.created' => 41, 'order.updated' => 42]]),
            'last_products_sync_at' => now()->subMinutes(3), 'last_orders_sync_at' => now()->subMinutes(3), 'last_webhook_at' => now()->subMinutes(26), 'created_at' => now()->subWeeks(6), 'updated_at' => now()]);
        $store = $DB::table('woo_stores')->find($sid);
        $products = Product::where('active', true)->where('is_template', false)->where('sale_price', '>', 0)->where('type', 'product')->orderBy('name')->limit(14)->get();
        foreach ($products as $i => $p) {
            $DB::table('woo_links')->insert(['company_id' => $company->id, 'store_id' => $sid, 'entity' => 'product', 'local_id' => $p->id, 'remote_id' => $i < 12 ? 1200 + $i : null,
                'hash' => $i < 12 ? md5((string) $i) : null, 'synced_at' => $i < 12 ? now()->subMinutes(3 + $i) : null, 'error' => $i === 12 ? 'Image refusée par le site (format WebP non autorisé)' : null,
                'created_at' => now()->subWeeks(6), 'updated_at' => now()]);
        }
        $customers = [['Mariam', 'Cissé', 'mariam.cisse@gmail.com', '07 58 21 44 90', 'Cocody Riviera 3'], ['Yannick', 'Assi', 'yannick.assi@yahoo.fr', '05 44 12 78 63', 'Marcory Zone 4'],
            ['Fatim', 'Dosso', 'fatim.dosso@gmail.com', '01 02 33 45 67', 'Yopougon Selmer'], ['Jean-Paul', 'Kacou', 'jpkacou@outlook.com', '07 07 88 19 20', 'Plateau, rue du Commerce'],
            ['Aminata', 'Bakayoko', 'amina.bakayoko@gmail.com', '05 05 61 70 82', 'Bingerville'], ['Serge', 'Tapé', 'serge.tape@gmail.com', '07 49 30 11 05', 'Angré 8e tranche']];
        $plan = [['processing', 'cod', 0.2], ['on-hold', 'bacs', 0.6], ['completed', 'cinetpay', 1.3], ['completed', 'cod', 2.2], ['processing', 'cinetpay', 3.0], ['cancelled', 'cod', 4.5], ['completed', 'cinetpay', 6.0], ['completed', 'cod', 9.0]];
        $titles = ['cod' => 'Paiement à la livraison', 'bacs' => 'Virement bancaire', 'cinetpay' => 'Mobile Money (CinetPay)'];
        $sold = $products->take(12)->values();
        foreach ($plan as $k => [$status, $pm, $ago]) {
            $cu = $customers[$k % count($customers)];
            $items = [];
            foreach ([$sold[$k % $sold->count()], $sold[($k * 5 + 3) % $sold->count()]] as $j => $p) {
                $qty = $j === 0 ? 1 + ($k % 3) : 1;
                $ht = round((float) $p->sale_price * $qty);
                $items[] = ['product_id' => 1200 + $sold->search(fn ($x) => $x->id === $p->id), 'variation_id' => 0, 'name' => $p->name, 'sku' => $p->sku, 'quantity' => $qty, 'total' => (string) $ht, 'total_tax' => (string) round($ht * (float) $p->tax_rate / 100)];
            }
            $at = now()->subDays((int) floor($ago))->subHours((int) (($ago - floor($ago)) * 24) + 1);
            $total = collect($items)->sum(fn ($i) => (float) $i['total'] + (float) $i['total_tax']) + 1500;
            $o = ['id' => 4810 + $k, 'number' => (string) (4810 + $k), 'status' => $status, 'currency' => 'XOF', 'total' => (string) $total, 'date_created' => $at->format('Y-m-d\TH:i:s'),
                'date_paid' => $pm === 'cinetpay' ? $at->format('Y-m-d\TH:i:s') : null, 'date_completed' => $status === 'completed' ? $at->copy()->addDay()->format('Y-m-d\TH:i:s') : null,
                'payment_method' => $pm, 'payment_method_title' => $titles[$pm], 'transaction_id' => $pm === 'cinetpay' ? 'CP'.(880000 + $k * 137) : '', 'customer_note' => $k === 0 ? 'Livrer après 17 h svp' : '',
                'billing' => ['first_name' => $cu[0], 'last_name' => $cu[1], 'email' => $cu[2], 'phone' => $cu[3], 'address_1' => $cu[4], 'city' => 'Abidjan', 'company' => ''],
                'shipping' => ['address_1' => $cu[4], 'city' => 'Abidjan'], 'line_items' => $items, 'shipping_lines' => [['method_title' => 'Livraison Abidjan', 'total' => '1500', 'total_tax' => '0']],
                'fee_lines' => [], 'coupon_lines' => $k === 4 ? [['code' => 'NOEL10']] : []];
            try {
                $sync->importOrder($company, $store, $status === 'cancelled' ? ['status' => 'processing'] + $o : $o);
                if ($status === 'cancelled') {
                    $sync->importOrder($company, $store, $o);
                }
            } catch (\Throwable $e) {
                // données de démonstration : on continue
            }
        }
    }

    /** Shopify : boutique de démonstration (aucun appel), produits en ligne, commandes importées. */
    protected function shopifyDemo(Company $company): void
    {
        $company = $company->fresh();
        if (! $company->hasModule('shopify') || ! \Illuminate\Support\Facades\Schema::hasTable('woo_stores')) {
            return;
        }
        $DB = \Illuminate\Support\Facades\DB::class;
        if ($DB::table('woo_stores')->where('company_id', $company->id)->where('platform', 'shopify')->exists()) {
            return;
        }
        app()->instance('tenant', $company);
        $sync = app(\App\Modules\Shopify\ShopifySync::class);
        $sid = $DB::table('woo_stores')->insertGetId(['company_id' => $company->id, 'platform' => 'shopify', 'name' => 'Démo SARL Store', 'url' => 'https://demo-sarl.myshopify.com',
            'credentials' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['key' => 'ck_demo', 'secret' => 'cs_demo'])), 'token' => Str::lower(Str::random(40)), 'webhook_secret' => Str::random(40),
            'status' => 'active', 'settings' => json_encode(array_replace(\App\Modules\Woocommerce\WooSync::DEFAULTS, ['stock_buffer' => 1])),
            'info' => json_encode(['demo' => true, 'currency' => 'XOF', 'prices_include_tax' => true, 'calc_taxes' => true, 'version' => 'Shopify 2024-10', 'location_id' => 61, 'webhooks' => ['orders/create' => 51, 'orders/updated' => 52]]),
            'last_products_sync_at' => now()->subMinutes(3), 'last_orders_sync_at' => now()->subMinutes(3), 'last_webhook_at' => now()->subMinutes(26), 'created_at' => now()->subWeeks(6), 'updated_at' => now()]);
        $store = $DB::table('woo_stores')->find($sid);
        $products = Product::where('active', true)->where('is_template', false)->where('sale_price', '>', 0)->where('type', 'product')->orderByDesc('name')->limit(8)->get();
        foreach ($products as $i => $p) {
            $DB::table('woo_links')->insert(['company_id' => $company->id, 'store_id' => $sid, 'entity' => 'product', 'local_id' => $p->id, 'remote_id' => 44100 + $i, 'remote_parent_id' => 81000 + $i,
                'hash' => md5((string) $i), 'synced_at' => now()->subMinutes(2 + $i), 'error' => null,
                'created_at' => now()->subWeeks(6), 'updated_at' => now()]);
        }
        $customers = [['Mariam', 'Cissé', 'mariam.cisse@gmail.com', '07 58 21 44 90', 'Cocody Riviera 3'], ['Yannick', 'Assi', 'yannick.assi@yahoo.fr', '05 44 12 78 63', 'Marcory Zone 4'],
            ['Fatim', 'Dosso', 'fatim.dosso@gmail.com', '01 02 33 45 67', 'Yopougon Selmer'], ['Jean-Paul', 'Kacou', 'jpkacou@outlook.com', '07 07 88 19 20', 'Plateau, rue du Commerce'],
            ['Aminata', 'Bakayoko', 'amina.bakayoko@gmail.com', '05 05 61 70 82', 'Bingerville'], ['Serge', 'Tapé', 'serge.tape@gmail.com', '07 49 30 11 05', 'Angré 8e tranche']];
        $plan = [['processing', 'cinetpay', 0.4], ['processing', 'cod', 1.1], ['completed', 'cinetpay', 2.5], ['completed', 'cod', 5.0]];
        $titles = ['cod' => 'Cash on Delivery (COD)', 'bacs' => 'Virement bancaire', 'cinetpay' => 'Wave'];
        $sold = $products->values();
        foreach ($plan as $k => [$status, $pm, $ago]) {
            $cu = $customers[$k % count($customers)];
            $items = [];
            foreach ([$sold[$k % $sold->count()], $sold[($k * 5 + 3) % $sold->count()]] as $j => $p) {
                $qty = $j === 0 ? 1 + ($k % 3) : 1;
                $ht = round((float) $p->sale_price * $qty);
                $items[] = ['product_id' => 0, 'variation_id' => 44100 + $sold->search(fn ($x) => $x->id === $p->id), 'name' => $p->name, 'sku' => $p->sku, 'quantity' => $qty, 'total' => (string) $ht, 'total_tax' => (string) round($ht * (float) $p->tax_rate / 100)];
            }
            $at = now()->subDays((int) floor($ago))->subHours((int) (($ago - floor($ago)) * 24) + 1);
            $total = collect($items)->sum(fn ($i) => (float) $i['total'] + (float) $i['total_tax']) + 1500;
            $o = ['id' => 5500000 + $k, 'number' => (string) (1021 + $k), 'status' => $status, 'currency' => 'XOF', 'total' => (string) $total, 'date_created' => $at->format('Y-m-d\TH:i:s'),
                'date_paid' => $pm === 'cinetpay' ? $at->format('Y-m-d\TH:i:s') : null, 'date_completed' => $status === 'completed' ? $at->copy()->addDay()->format('Y-m-d\TH:i:s') : null,
                'payment_method' => $pm, 'payment_method_title' => $titles[$pm], 'transaction_id' => $pm === 'cinetpay' ? 'CP'.(880000 + $k * 137) : '', 'customer_note' => $k === 0 ? 'Livrer après 17 h svp' : '',
                'billing' => ['first_name' => $cu[0], 'last_name' => $cu[1], 'email' => $cu[2], 'phone' => $cu[3], 'address_1' => $cu[4], 'city' => 'Abidjan', 'company' => ''],
                'shipping' => ['address_1' => $cu[4], 'city' => 'Abidjan'], 'line_items' => $items, 'shipping_lines' => [['method_title' => 'Livraison Abidjan', 'total' => '1500', 'total_tax' => '0']],
                'fee_lines' => [], 'coupon_lines' => $k === 4 ? [['code' => 'NOEL10']] : []];
            try {
                $sync->importOrder($company, $store, $status === 'cancelled' ? ['status' => 'processing'] + $o : $o);
                if ($status === 'cancelled') {
                    $sync->importOrder($company, $store, $o);
                }
            } catch (\Throwable $e) {
                // données de démonstration : on continue
            }
        }
    }

    /** Liens de paiement : acompte, commande Instagram payée, lien permanent de cotisation. */
    protected function paymentLinks(Company $company): void
    {
        $DB = \Illuminate\Support\Facades\DB::class;
        if (! \Illuminate\Support\Facades\Schema::hasTable('pay_links') || $DB::table('pay_links')->where('company_id', $company->id)->exists()) {
            return;
        }
        $admin = User::where('company_id', $company->id)->orderBy('id')->first();
        foreach ([
            ['Acompte installation réseau — Clinique Les Palmiers', 'Acompte de 40 % sur le devis DEV-2026-00118.', 1250000, null, false, 'open', 0, 0, 'Clinique Les Palmiers', 1],
            ['Robe wax taille M (Instagram)', null, 18500, null, false, 'paid', 1, 18500, 'Mariam Cissé', 3],
            ['Cotisation annuelle club des clients Pro', 'Accès aux prix Pro et à la livraison offerte pendant un an.', 25000, null, true, 'open', 7, 175000, null, 20],
            ['Formation Excel avancé — 2 jours', 'Montant libre : acompte ou totalité (minimum 20 000 F).', null, 20000, true, 'open', 4, 145000, null, 12],
            ['Dépannage imprimante — M. Yao', null, 15000, null, false, 'cancelled', 0, 0, 'Koffi Yao', 30],
        ] as [$label, $desc, $amount, $min, $reusable, $status, $count, $collected, $cust, $ago]) {
            $DB::table('pay_links')->insert(['company_id' => $company->id, 'token' => Str::random(40), 'label' => $label, 'description' => $desc, 'amount' => $amount, 'min_amount' => $min,
                'currency' => $company->currency ?? 'XOF', 'tax_rate' => 0, 'customer_name' => $cust, 'reusable' => $reusable, 'status' => $status, 'payments_count' => $count, 'collected' => $collected,
                'paid_at' => $count ? now()->subDays(max(0, $ago - 2)) : null, 'user_id' => $admin?->id, 'created_at' => now()->subDays($ago), 'updated_at' => now()]);
        }
    }

    /** Espaces de service : marketing (stratégie, calendrier éditorial, veille), juridique (contrats, contentieux), parc automobile. */
    protected function servicesDemo(Company $company): void
    {
        $company = $company->fresh();
        $DB = \Illuminate\Support\Facades\DB::class;
        if (! \Illuminate\Support\Facades\Schema::hasTable('mc_posts') || $DB::table('mc_posts')->where('company_id', $company->id)->exists()) {
            return;
        }
        app(\App\Support\ModuleRegistry::class)->ensureSetup($company, ['contenus', 'juridique', 'flotte']);
        $admin = User::where('company_id', $company->id)->orderBy('id')->first();
        $role = fn ($n) => \App\Models\Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $mk = fn ($name, $email, $r) => User::firstOrCreate(['email' => $email], ['company_id' => $company->id, 'name' => $name, 'password' => 'demo1234', 'role_id' => $role($r), 'is_active' => true]);
        $mariam = $mk('Mariam Kouassi', 'marketing@demo.ci', 'Marketing & communication');
        $aicha = $mk('Me Aïcha Diallo', 'juriste@demo.ci', 'Juriste');
        $konan = $mk('Konan Parc auto', 'parc@demo.ci', 'Responsable parc auto');
        $com = User::where('company_id', $company->id)->whereHas('role', fn ($q) => $q->where('name', 'Commercial'))->where('id', '!=', $admin->id)->first() ?? $mk('Koffi Commercial', 'commercial@demo.ci', 'Commercial');
        $tech = User::where('company_id', $company->id)->where('email', 'ibrahim@demo.ci')->first() ?? $admin;
        $ins = fn ($t, array $row) => $DB::table($t)->insertGetId($row + ['company_id' => $company->id, 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $j = fn (array $a) => json_encode($a);
        $at = fn (int $days, int $h, int $m = 0) => now()->startOfDay()->addDays($days)->setTime($h, $m)->format('Y-m-d H:i:s');

        // ---------------------------------------------------------------- marketing
        $plan = $ins('mc_plans', ['title' => 'Lancement des 3 agences — '.now()->translatedFormat('F Y'), 'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->addMonths(2)->endOfMonth()->toDateString(),
            'status' => 'active', 'owner_id' => $mariam->id, 'budget' => 3500000, 'channels' => $j(['facebook', 'instagram', 'tiktok', 'radio', 'billboard', 'event']),
            'objective' => "Faire connaître les agences de Bouaké, San-Pédro et Korhogo.\n+30 % de demandes de devis sur le trimestre ; 5 000 abonnés Instagram.",
            'targets' => "PME et commerçants des trois villes (25-50 ans)\nResponsables achats des hôtels et cliniques", 'message' => 'Le même service qu\'à Abidjan, près de chez vous. Ton : proche, professionnel, chiffres concrets.',
            'kpis' => "Demandes de devis par agence\nCoût par contact\nAbonnés et taux d'interaction"]);
        $plan2 = $ins('mc_plans', ['title' => 'Notoriété marque employeur', 'period_start' => now()->subMonths(3)->startOfMonth()->toDateString(), 'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
            'status' => 'done', 'owner_id' => $mariam->id, 'budget' => 600000, 'channels' => $j(['linkedin', 'site']), 'objective' => 'Attirer des techniciens qualifiés.']);
        foreach ([
            [-6, 9, 'Témoignage client : Clinique Les Palmiers', ['facebook', 'linkedin'], 'video', 'published', 4200, 310],
            [-4, 18, 'Coulisses : préparation de l\'agence de Bouaké', ['instagram', 'tiktok'], 'video', 'published', 9800, 1240],
            [-2, 12, 'Astuce : 3 erreurs à éviter avec votre réseau Wi-Fi', ['facebook', 'instagram'], 'carousel', 'published', 3100, 180],
            [0, 18, 'Live : questions-réponses avec le directeur technique', ['facebook'], 'live', 'approved', null, null],
            [1, 10, 'Ouverture de l\'agence de Bouaké — J-7', ['facebook', 'instagram', 'whatsapp'], 'post', 'review', null, null],
            [2, 12, 'Offre de lancement : -15 % sur les installations', ['facebook', 'instagram', 'tiktok'], 'post', 'writing', null, null],
            [3, 9, 'Portrait d\'équipe : le chef d\'agence de San-Pédro', ['linkedin'], 'article', 'writing', null, null],
            [5, 17, 'Jeu concours : gagnez un audit réseau gratuit', ['instagram', 'facebook'], 'carousel', 'idea', null, null],
            [7, 10, 'Inauguration de l\'agence de Bouaké (photos)', ['facebook', 'instagram', 'linkedin'], 'post', 'idea', null, null],
            [9, 19, 'Newsletter du mois', ['newsletter'], 'newsletter', 'idea', null, null],
            [12, 12, 'Cas client : hôtel équipé en 48 h', ['linkedin', 'site'], 'article', 'idea', null, null],
            [15, 18, 'Reel : avant / après d\'une salle serveur', ['instagram', 'tiktok'], 'video', 'idea', null, null],
        ] as [$d, $h, $title, $nets, $format, $status, $reach, $eng]) {
            $ins('mc_posts', ['title' => $title, 'plan_id' => $plan, 'networks' => $j($nets), 'format' => $format, 'scheduled_at' => $at($d, $h), 'status' => $status, 'owner_id' => $mariam->id,
                'caption' => $status === 'idea' ? null : $title.'. Rendez-vous sur notre page pour en savoir plus !', 'hashtags' => '#Abidjan #Bouaké #PME #Digital',
                'validator_id' => in_array($status, ['approved', 'published']) ? $admin->id : null, 'validated_at' => in_array($status, ['approved', 'published']) ? now()->subDays(max(1, -$d + 1)) : null,
                'reach' => $reach, 'engagements' => $eng]);
        }
        foreach ([
            ['Campagne Facebook & Instagram — Bouaké', 'meta_ads', 'running', -10, 20, 800000, 520000, 64, 2400000],
            ['Spots Radio Jam (Bouaké)', 'radio', 'planned', 3, 17, 900000, 0, null, null],
            ['Affichage 4×3 carrefour Commerce', 'billboard', 'planned', 5, 35, 1200000, 0, null, null],
            ['Stand au salon des PME de San-Pédro', 'event', 'done', -25, -23, 450000, 470000, 38, 3150000],
        ] as [$title, $ch, $st, $s1, $s2, $budget, $spent, $leads, $sales]) {
            $ins('mc_actions', ['title' => $title, 'plan_id' => $plan, 'channel' => $ch, 'status' => $st, 'start_date' => now()->addDays($s1)->toDateString(), 'end_date' => now()->addDays($s2)->toDateString(),
                'budget' => $budget, 'spent' => $spent, 'leads' => $leads, 'sales' => $sales, 'owner_id' => $mariam->id, 'results' => $st === 'done' ? '38 contacts qualifiés, 6 devis signés.' : null]);
        }
        $c1 = $ins('mc_competitors', ['title' => 'NetCom Services', 'website' => 'https://netcom.example.ci', 'sector' => 'Réseaux & sécurité — Abidjan, Bouaké', 'positioning' => 'low', 'threat' => 'high', 'owner_id' => $mariam->id,
            'offer' => 'Installation réseau, vidéosurveillance, maintenance au forfait.', 'prices' => 'Forfait maintenance PME : 45 000 F/mois (nous : 55 000 F)', 'strengths' => "Prix bas\nTrès présents sur TikTok", 'weaknesses' => "Délais d'intervention longs\nPeu de références hôtels / cliniques",
            'socials' => "Facebook : 18 k abonnés\nTikTok : 32 k abonnés"]);
        $c2 = $ins('mc_competitors', ['title' => 'Groupe TechPro CI', 'website' => 'https://techpro.example.ci', 'sector' => 'Intégrateur national', 'positioning' => 'high', 'threat' => 'medium', 'owner_id' => $mariam->id,
            'offer' => 'Grands comptes, data centers, câblage.', 'strengths' => 'Références grands comptes', 'weaknesses' => 'Peu présents hors Abidjan, prix élevés']);
        $c3 = $ins('mc_competitors', ['title' => 'Bouaké Informatique', 'sector' => 'Revendeur local — Bouaké', 'positioning' => 'mid', 'threat' => 'medium', 'owner_id' => $com->id, 'offer' => 'Matériel et dépannage.']);
        foreach ([
            [$c1, -1, 'price', 'high', 'NetCom lance -20 % sur la vidéosurveillance jusqu\'à fin du mois', 'Affiches en ville et sponsorisation TikTok.', 'Mettre en avant notre délai d\'intervention garanti 4 h.', $com->id],
            [$c1, -5, 'campaign', 'medium', 'Série de vidéos TikTok « Installe ta caméra toi-même »', '32 k vues sur la dernière vidéo.', null, $mariam->id],
            [$c3, -3, 'event', 'high', 'Bouaké Informatique annonce des portes ouvertes le jour de notre inauguration', 'Vu sur leur page Facebook.', 'Avancer notre inauguration ou ajouter une animation.', $com->id],
            [$c2, -9, 'hiring', 'low', 'TechPro recrute 5 techniciens à San-Pédro', 'Offres sur LinkedIn : ils préparent une agence.', 'Surveiller, sécuriser nos clients de San-Pédro.', $mariam->id],
            [$c2, -15, 'product', 'medium', 'TechPro propose une offre cloud de sauvegarde pour PME', null, null, $mariam->id],
        ] as [$cid, $d, $kind, $impact, $title, $details, $resp, $owner]) {
            $ins('mc_observations', ['title' => $title, 'competitor_id' => $cid, 'observed_on' => now()->addDays($d)->toDateString(), 'kind' => $kind, 'impact' => $impact, 'details' => $details, 'response' => $resp, 'owner_id' => $owner]);
        }
        foreach ([
            ['Communiqué : ouverture de 3 agences à l\'intérieur du pays', 'release', 'sent', 'Fraternité Matin, Abidjan.net, Linfodrome', -2, 'Rédaction économie', 150000],
            ['Interview du DG sur Radio Côte d\'Ivoire', 'interview', 'published', 'RCI — Matinale éco', -12, 'M. Kouadio (journaliste)', 400000],
            ['Note interne : organisation des équipes pour l\'inauguration', 'internal', 'todo', 'Tout le personnel', 4, null, null],
        ] as [$title, $kind, $st, $media, $d, $contact, $aud]) {
            $ins('mc_press', ['title' => $title, 'kind' => $kind, 'status' => $st, 'media' => $media, 'date' => now()->addDays($d)->toDateString(), 'contact' => $contact, 'audience' => $aud, 'owner_id' => $mariam->id]);
        }

        // ---------------------------------------------------------------- juridique
        $partners = $DB::table('partners')->where('company_id', $company->id)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'type']);
        $sup = $partners->firstWhere('type', 'supplier') ?? $partners->first();
        $cus = $partners->whereIn('type', ['customer', 'both'])->values();
        $tpl = $DB::table('jr_templates')->where('company_id', $company->id)->pluck('id', 'kind');
        $ctr = [];
        foreach ([
            ['Maintenance informatique annuelle', 'customer', 'active', $cus[0]->id ?? null, null, -300, 65, true, 60, 6600000, 'yearly', 'CT-2026-014'],
            ['Fourniture de matériel réseau', 'supplier', 'active', $sup->id ?? null, null, -200, 25, false, 30, 18000000, 'once', 'CF-2026-007'],
            ['Bail du siège — Cocody Angré', 'lease', 'active', null, 'SCI Les Palmiers', -700, 395, true, 90, 1200000, 'monthly', 'BAIL-2024-01'],
            ['Accord de confidentialité — projet data center', 'nda', 'to_sign', $cus[1]->id ?? null, null, 0, 730, false, null, null, null, 'NDA-2026-03'],
            ['Partenariat distribution — Bouaké', 'distribution', 'negotiation', null, 'Ets Konaté & Fils', 10, 375, true, 60, null, null, null],
            ['Contrat de prestation — audit sécurité', 'service', 'active', $cus[2]->id ?? null, null, -40, 50, false, 15, 2400000, 'once', 'CT-2026-021'],
            ['Assurance flotte automobile', 'insurance', 'active', null, 'NSIA Assurances', -330, 35, true, 30, 2850000, 'yearly', 'POL-778812'],
            ['Contrat de location de photocopieurs', 'supplier', 'expired', null, 'Bureau Plus CI', -800, -70, false, 30, 95000, 'monthly', null],
        ] as [$title, $kind, $st, $pid, $cp, $start, $end, $tacit, $notice, $amount, $per, $ref]) {
            $id = $ins('jr_contracts', ['title' => $title, 'kind' => $kind, 'status' => $st, 'partner_id' => $pid, 'counterparty' => $cp, 'start_date' => now()->addDays($start)->toDateString(),
                'signed_on' => in_array($st, ['active', 'expired']) ? now()->addDays($start - 5)->toDateString() : null, 'end_date' => now()->addDays($end)->toDateString(), 'tacit_renewal' => $tacit,
                'notice_days' => $notice, 'amount' => $amount, 'periodicity' => $per, 'reference' => $ref, 'owner_id' => $aicha->id]);
            $ctr[$kind] ??= $id;
        }
        if (isset($tpl['service'], $ctr['service'])) {
            app(\App\Modules\Juridique\JuridiqueHooks::class)->draft($company, $ctr['service'], $tpl['service']);
        }
        if ($DB::getSchemaBuilder()->hasTable('rh_employees') && ($emp = $DB::table('rh_employees')->where('company_id', $company->id)->whereNull('deleted_at')->orderBy('hire_date')->first())) {
            $ins('jr_contracts', ['title' => 'Contrat de travail — '.$emp->first_name.' '.$emp->last_name, 'kind' => 'employment', 'status' => 'active', 'employee_id' => (string) $emp->id,
                'start_date' => $emp->hire_date, 'owner_id' => $aicha->id, 'reference' => 'CDI-'.$emp->matricule]);
        }
        $case1 = $ins('jr_cases', ['title' => 'Recouvrement — factures impayées Hôtel Lagune', 'kind' => 'recovery', 'role' => 'plaintiff', 'status' => 'proceedings', 'opposing_party' => 'Hôtel Lagune SARL',
            'court' => 'Tribunal de Commerce d\'Abidjan', 'lawyer' => 'Me Kouamé (avocat)', 'reference' => 'RG 1452/2026', 'amount_at_stake' => 4750000, 'costs' => 350000, 'risk' => 'medium',
            'opened_on' => now()->subMonths(3)->toDateString(), 'summary' => 'Trois factures de maintenance impayées malgré deux mises en demeure. Injonction de payer déposée.', 'owner_id' => $aicha->id]);
        $case2 = $ins('jr_cases', ['title' => 'Litige prud\'homal — ancien technicien', 'kind' => 'labour', 'role' => 'defendant', 'status' => 'amicable', 'opposing_party' => 'M. Y. (ancien salarié)',
            'court' => 'Tribunal du Travail d\'Abidjan', 'amount_at_stake' => 2100000, 'risk' => 'high', 'opened_on' => now()->subMonth()->toDateString(),
            'summary' => 'Contestation du motif de licenciement. Tentative de conciliation à l\'inspection du travail.', 'owner_id' => $aicha->id]);
        $ins('jr_cases', ['title' => 'Contestation d\'un redressement de patente', 'kind' => 'tax', 'role' => 'plaintiff', 'status' => 'won', 'opposing_party' => 'Direction générale des impôts',
            'amount_at_stake' => 850000, 'risk' => 'low', 'opened_on' => now()->subMonths(8)->toDateString(), 'outcome' => 'Redressement annulé.', 'owner_id' => $aicha->id]);
        foreach ([
            ['Audience — injonction de payer Hôtel Lagune', 'hearing', $at(3, 9), $case1, null, 'Tribunal de Commerce, salle 4'],
            ['Conciliation à l\'inspection du travail', 'meeting', $at(6, 10), $case2, null, 'Inspection du travail du Plateau'],
            ['Dépôt des conclusions en réponse', 'filing', $at(12, 17), $case1, null, null],
            ['Décider du renouvellement — maintenance annuelle', 'renewal', $at(5, 9), null, $ctr['customer'] ?? null, null],
        ] as [$title, $kind, $due, $case, $contract, $place]) {
            $ins('jr_deadlines', ['title' => $title, 'kind' => $kind, 'due_at' => $due, 'case_id' => $case, 'contract_id' => $contract, 'place' => $place, 'done' => false, 'owner_id' => $aicha->id]);
        }

        // ---------------------------------------------------------------- parc automobile
        $v = [];
        foreach ([
            ['Hilux commerciale', '4521 HK 01', 'pickup', 'Toyota', 'Hilux', 'diesel', 5, 'available', 86420, $com->id, 230, 120, 90000, 40],
            ['Fourgon technique', '1187 GH 01', 'van', 'Renault', 'Master', 'diesel', 3, 'available', 142300, $tech->id, 11, 150, 143000, 20],
            ['Corolla direction', '7740 JK 01', 'car', 'Toyota', 'Corolla', 'petrol', 5, 'available', 38900, null, 300, 280, 45000, 100],
            ['Moto livraison', '0991 MT 01', 'moto', 'Haojue', 'HJ125', 'petrol', 2, 'maintenance', 21500, null, 60, -4, 22000, 10],
            ['Pick-up Bouaké', '3310 BK 02', 'pickup', 'Mitsubishi', 'L200', 'diesel', 5, 'available', 61200, null, 180, 200, 65000, 70],
        ] as [$title, $plate, $kind, $brand, $model, $fuel, $seats, $st, $km, $driver, $ins1, $insp, $svc, $svcDays]) {
            $v[$title] = $ins('fl_vehicles', ['title' => $title, 'plate' => $plate, 'kind' => $kind, 'brand' => $brand, 'model' => $model, 'fuel' => $fuel, 'seats' => $seats, 'status' => $st, 'odometer' => $km,
                'driver_id' => $driver, 'insurance_until' => now()->addDays($ins1)->toDateString(), 'inspection_until' => now()->addDays($insp)->toDateString(), 'next_service_km' => $svc, 'next_service_date' => now()->addDays($svcDays)->toDateString()]);
        }
        $n = 0;
        $trip = function (array $r) use ($ins, $company, &$n) {
            $n++;

            return $ins('fl_trips', $r + ['number' => 'SV-'.now()->format('Y').'-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT)]);
        };
        $cusName = fn ($i) => $cus[$i]->id ?? null;
        // sorties terminées du mois
        foreach ([
            [-12, 'Hilux commerciale', $com->id, 'Visite clients Plateau et Cocody', 'customer', 'Plateau, Cocody', 0, 85640, 85712, 'RAS. Deux devis à préparer.'],
            [-9, 'Fourgon technique', $tech->id, 'Installation réseau — Clinique Les Palmiers', 'intervention', 'Marcory', 1, 141820, 141876, 'Installation terminée.'],
            [-6, 'Hilux commerciale', $com->id, 'Prospection zone industrielle de Yopougon', 'customer', 'Yopougon', 2, 85712, 85868, 'Plaquette remise à 9 entreprises.'],
            [-4, 'Corolla direction', $admin->id, 'Rendez-vous banque et notaire', 'admin', 'Plateau', null, 38760, 38842, null],
            [-3, 'Fourgon technique', $tech->id, 'Dépannage serveur — Hôtel Lagune', 'intervention', 'Grand-Bassam', 0, 141876, 142030, 'Pneu arrière à surveiller.'],
            [-2, 'Hilux commerciale', $com->id, 'Livraison et démonstration — Supermarché', 'delivery', 'Bingerville', 1, 85868, 86010, null],
        ] as [$d, $veh, $who, $title, $purpose, $dest, $ci, $k1, $k2, $report]) {
            $start = now()->startOfDay()->addDays($d)->setTime(8, 30);
            $tid = $trip(['title' => $title, 'vehicle_id' => $v[$veh], 'requester_id' => $who, 'driver_id' => $who, 'purpose' => $purpose, 'destination' => $dest, 'partner_id' => $ci === null ? null : $cusName($ci),
                'passengers' => $purpose === 'customer' ? 'Stagiaire commercial' : null, 'planned_start' => $start, 'planned_end' => $start->copy()->addHours(6), 'status' => 'returned', 'decided_by' => $konan->id,
                'out_at' => $start->copy()->addMinutes(10), 'out_km' => $k1, 'back_at' => $start->copy()->addHours(5)->addMinutes(40), 'back_km' => $k2, 'fuel_level' => 'half', 'report' => $report]);
            if ($k2 - $k1 > 100) {
                $ins('fl_fuel', ['vehicle_id' => $v[$veh], 'trip_id' => $tid, 'driver_id' => $who, 'date' => $start->toDateString(), 'liters' => 30, 'amount' => 25500, 'odometer' => $k2, 'station' => 'Total Riviera', 'payment' => 'card', 'full_tank' => false]);
            }
        }
        // en cours, approuvées, demandées
        $out = now()->subHours(3)->startOfHour();
        $trip(['title' => 'Tournée clients Bouaké (préparation inauguration)', 'vehicle_id' => $v['Pick-up Bouaké'], 'requester_id' => $com->id, 'driver_id' => $com->id, 'purpose' => 'mission', 'destination' => 'Bouaké — centre et zone industrielle',
            'passengers' => 'Mariam (marketing)', 'planned_start' => $out, 'planned_end' => now()->addHours(5)->startOfHour(), 'status' => 'out', 'decided_by' => $konan->id, 'out_at' => $out->copy()->addMinutes(5), 'out_km' => 61200]);
        $DB::table('fl_vehicles')->where('id', $v['Pick-up Bouaké'])->update(['status' => 'out']);
        $late = now()->subHours(6);
        $trip(['title' => 'Intervention caméras — Supermarché Angré', 'vehicle_id' => $v['Fourgon technique'], 'requester_id' => $tech->id, 'driver_id' => $tech->id, 'purpose' => 'intervention', 'destination' => 'Angré 8e tranche',
            'planned_start' => $late, 'planned_end' => now()->subHour()->subMinutes(20), 'status' => 'out', 'decided_by' => $konan->id, 'out_at' => $late->copy()->addMinutes(10), 'out_km' => 142300]);
        $DB::table('fl_vehicles')->where('id', $v['Fourgon technique'])->update(['status' => 'out']);
        $tom = now()->startOfDay()->addDay()->setTime(9, 0);
        $rivieraTrip = $trip(['title' => 'Visite prospects Riviera 3', 'vehicle_id' => $v['Hilux commerciale'], 'requester_id' => $com->id, 'driver_id' => $com->id, 'purpose' => 'customer', 'destination' => 'Riviera 3', 'partner_id' => $cusName(3),
            'planned_start' => $tom, 'planned_end' => $tom->copy()->addHours(4), 'status' => 'approved', 'decided_by' => $konan->id, 'decision_note' => 'OK, rendre le véhicule avant 14 h.']);
        $trip(['title' => 'Salon des PME — transport du stand', 'requester_id' => $mariam->id, 'purpose' => 'mission', 'destination' => 'Parc des expositions', 'passengers' => '2 hôtesses',
            'planned_start' => $tom->copy()->addDays(2)->setTime(7, 0), 'planned_end' => $tom->copy()->addDays(2)->setTime(19, 0), 'status' => 'requested', 'notes' => 'Besoin d\'un véhicule avec grand coffre.']);
        $trip(['title' => 'Démonstration solution Wi-Fi — Hôtel Ivoire', 'requester_id' => $com->id, 'purpose' => 'customer', 'destination' => 'Cocody', 'partner_id' => $cusName(4),
            'planned_start' => $tom->copy()->addDay()->setTime(14, 0), 'planned_end' => $tom->copy()->addDay()->setTime(17, 30), 'status' => 'requested']);
        foreach ([
            ['Hilux commerciale', -20, 52, 44200, 85590, 'Total Riviera'], ['Hilux commerciale', -5, 48, 40800, 85940, 'Shell Cocody'],
            ['Fourgon technique', -15, 60, 51000, 141700, 'Petro Ivoire Marcory'], ['Fourgon technique', -1, 55, 46750, 142290, 'Petro Ivoire Marcory'],
            ['Corolla direction', -8, 35, 30800, 38700, 'Total Plateau'], ['Pick-up Bouaké', -1, 58, 49300, 61180, 'Oryx Bouaké'],
        ] as [$veh, $d, $l, $amount, $km, $station]) {
            $ins('fl_fuel', ['vehicle_id' => $v[$veh], 'driver_id' => $admin->id, 'date' => now()->addDays($d)->toDateString(), 'liters' => $l, 'amount' => $amount, 'odometer' => $km, 'station' => $station, 'payment' => 'card', 'full_tank' => true]);
        }
        foreach ([
            ['Vidange + filtres', 'Fourgon technique', 'service', -30, 85000, 138100, 'Garage Auto Plus', null, 143000],
            ['Remplacement embrayage', 'Moto livraison', 'repair', -2, 65000, 21500, 'Garage Moto Treichville', null, null],
            ['Assurance tous risques', 'Corolla direction', 'insurance', -65, 640000, null, 'NSIA', 300, null],
            ['Visite technique', 'Hilux commerciale', 'inspection', -60, 25000, 84000, 'SICTA Yopougon', 120, null],
            ['4 pneus neufs', 'Hilux commerciale', 'tyres', -18, 360000, 85600, 'Pneus Service Plus', null, null],
            ['Péages autoroute du Nord', 'Pick-up Bouaké', 'parking', -1, 12000, null, null, null, null],
        ] as [$title, $veh, $kind, $d, $amount, $km, $garage, $nextDays, $nextKm]) {
            $ins('fl_costs', ['title' => $title, 'vehicle_id' => $v[$veh], 'kind' => $kind, 'date' => now()->addDays($d)->toDateString(), 'amount' => $amount, 'odometer' => $km, 'garage' => $garage,
                'next_date' => $nextDays ? now()->addDays($nextDays)->toDateString() : null, 'next_km' => $nextKm]);
        }

        // ---------------------------------------------------------------- force de vente
        if (! \Illuminate\Support\Facades\Schema::hasTable('cm_visits')) {
            return;
        }
        app(\App\Support\ModuleRegistry::class)->ensureSetup($company, ['commercial']);
        $com2 = $mk('Yao Kouadio', 'commercial2@demo.ci', 'Commercial');
        $mk('Sandrine Aka', 'dircom@demo.ci', 'Responsable commercial');
        $invoices = $DB::table('trade_documents')->where('company_id', $company->id)->where('direction', 'sale')->where('type', 'invoice')->where('status', 'validated')
            ->whereNull('deleted_at')->where('date', '>=', now()->startOfMonth()->toDateString())->orderBy('date')->pluck('id');
        foreach ($invoices as $i => $docId) {
            if ($i % 3 !== 2) {
                $DB::table('trade_documents')->where('id', $docId)->update(['user_id' => $i % 3 === 0 ? $com->id : $com2->id]);
            }
        }
        $perf = app(\App\Modules\Commercial\CommercialHooks::class);
        foreach ([[$com, 1.25, 20, 3, 8], [$com2, 0.9, 16, 2, 6]] as [$who, $ratio, $vt, $ct, $qt]) {
            $done = $perf->performance($company->id, $who->id, now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString())['revenue'];
            $goal = max(1000000, round(($done ?: 1500000) * $ratio / 100000) * 100000);
            $ins('cm_targets', ['user_id' => $who->id, 'period_kind' => 'month', 'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(),
                'revenue_target' => $goal, 'visits_target' => $vt, 'customers_target' => $ct, 'quotes_target' => $qt, 'title' => $who->name.' — '.ucfirst(now()->locale('fr')->translatedFormat('F Y'))]);
        }
        $tourToday = $ins('cm_tours', ['title' => 'Riviera et Cocody', 'user_id' => $com->id, 'date' => now()->addDay()->toDateString(), 'zone' => 'Riviera 3, Riviera Palmeraie, Cocody Danga', 'status' => 'planned',
            'trip_id' => $rivieraTrip, 'notes' => 'Apporter les plaquettes Wi-Fi et la tablette de démonstration.']);
        $tourPast = $ins('cm_tours', ['title' => 'Zone industrielle de Yopougon', 'user_id' => $com->id, 'date' => now()->subDays(6)->toDateString(), 'zone' => 'Yopougon ZI', 'status' => 'done',
            'summary' => '9 entreprises visitées, 3 devis demandés, 1 commande.']);
        $tourYao = $ins('cm_tours', ['title' => 'Plateau — banques et assurances', 'user_id' => $com2->id, 'date' => now()->toDateString(), 'zone' => 'Plateau', 'status' => 'in_progress']);
        $pn = fn ($i) => $cus[$i]->id ?? null;
        foreach ([
            [$com, $tourPast, -6, 9, 'Présentation vidéosurveillance', $pn(0), null, 'done', 'quote', 1800000, 'Besoin de 12 caméras pour l\'entrepôt. Compare avec NetCom (moins cher).', 'Envoyer le devis', 2],
            [$com, $tourPast, -6, 11, 'Prospection porte-à-porte', null, 'Imprimerie Nouvelle', 'done', 'interested', 650000, 'Réseau vieillissant, budget au prochain trimestre.', 'Rappeler', 20],
            [$com, $tourPast, -6, 14, 'Démonstration Wi-Fi invités', $pn(1), null, 'done', 'order', 950000, 'Bon de commande signé sur place.', null, null],
            [$com, $tourPast, -6, 16, 'Visite de courtoisie', $pn(2), null, 'missed', null, null, 'Responsable absent, carte laissée.', 'Reprendre rendez-vous', 3],
            [$com, null, -2, 10, 'Relance devis maintenance', $pn(3), null, 'done', 'follow_up', 2400000, 'Décision en comité la semaine prochaine.', 'Relancer après le comité', 6],
            [$com, $tourToday, 1, 9, 'Démonstration solution Wi-Fi', $pn(4), null, 'planned', null, 1200000, null, null, null],
            [$com, $tourToday, 1, 11, 'Présentation de l\'offre', null, 'Pharmacie des Palmiers', 'planned', null, null, null, null, null],
            [$com, $tourToday, 1, 15, 'Signature du contrat de maintenance', $pn(0), null, 'planned', null, 1800000, null, null, null],
            [$com2, $tourYao, 0, 9, 'Audit réseau agence', $pn(5), null, 'done', 'quote', 3200000, 'Trois agences à équiper, appel d\'offres en préparation.', 'Préparer la réponse', 4],
            [$com2, $tourYao, 0, 11, 'Rendez-vous DSI', null, 'Banque Atlantique (agence Plateau)', 'planned', null, null, null, null, null],
            [$com2, $tourYao, 0, 15, 'Recouvrement facture de septembre', $pn(6), null, 'planned', null, 450000, null, null, null],
            [$com2, null, -1, 10, 'Présentation sauvegarde cloud', $pn(7), null, 'planned', null, null, null, null, null],
            [$com2, null, -4, 15, 'Réclamation installation', $pn(2), null, 'done', 'complaint', null, 'Coupures fréquentes depuis l\'installation : technicien à envoyer.', 'Planifier l\'intervention', 1],
        ] as [$who, $tour, $d, $h, $title, $pid, $prospect, $st, $outcome, $amount, $report, $next, $nextDays]) {
            $ins('cm_visits', ['title' => $title, 'user_id' => $who->id, 'tour_id' => $tour, 'partner_id' => $pid, 'prospect' => $pid ? null : ($prospect ?? 'Prospect'), 'kind' => $outcome === 'complaint' ? 'visit' : ($prospect ? 'prospecting' : 'visit'),
                'planned_at' => $at($d, $h), 'status' => $st, 'done_at' => $st === 'done' ? $at($d, $h + 1) : null, 'outcome' => $outcome, 'amount' => $amount, 'report' => $report,
                'next_step' => $next, 'next_date' => $nextDays ? now()->addDays($d + $nextDays)->toDateString() : null]);
        }

        // ---------------------------------------------------------------- développement
        if (\Illuminate\Support\Facades\Schema::hasTable('dev_items')) {
            app(\App\Support\ModuleRegistry::class)->ensureSetup($company, ['dev', 'activite']);
            $kader = $mk('Kader Bamba', 'dev@demo.ci', 'Développeur');
            $ines = $mk('Inès Koffi', 'dev2@demo.ci', 'Développeur');
            $lead = $mk('Hervé N\'Guessan', 'cto@demo.ci', 'Responsable technique');
            $app1 = $ins('dev_apps', ['title' => 'Application mobile clients', 'stack' => 'Flutter, Firebase', 'status' => 'active', 'owner_id' => $lead->id, 'repo_url' => 'https://git.example.ci/demo/app-clients', 'prod_url' => 'https://app.demo.ci', 'staging_url' => 'https://recette.app.demo.ci']);
            $app2 = $ins('dev_apps', ['title' => 'Site e-commerce', 'stack' => 'Laravel, Vue.js, MySQL', 'status' => 'maintenance', 'owner_id' => $lead->id, 'repo_url' => 'https://git.example.ci/demo/boutique', 'prod_url' => 'https://boutique.demo.ci']);
            $sp1 = $ins('dev_sprints', ['title' => 'Sprint 7', 'app_id' => $app1, 'status' => 'closed', 'start_date' => now()->startOfWeek()->subWeeks(2)->toDateString(), 'end_date' => now()->startOfWeek()->subDays(3)->toDateString(), 'goal' => 'Paiement Mobile Money dans l\'application', 'retro' => 'Bonne vélocité ; la recette a pris du retard.']);
            $sp2 = $ins('dev_sprints', ['title' => 'Sprint 8', 'app_id' => $app1, 'status' => 'active', 'start_date' => now()->startOfWeek()->toDateString(), 'end_date' => now()->startOfWeek()->addDays(11)->toDateString(), 'goal' => 'Suivi de commande en temps réel et notifications']);
            $rel1 = $ins('dev_releases', ['title' => '2.3.0', 'app_id' => $app1, 'environment' => 'production', 'status' => 'released', 'date' => now()->startOfWeek()->addDays(1)->toDateString(), 'owner_id' => $lead->id]);
            $rel2 = $ins('dev_releases', ['title' => '2.4.0', 'app_id' => $app1, 'environment' => 'staging', 'status' => 'planned', 'date' => now()->startOfWeek()->addDays(11)->toDateString(), 'owner_id' => $lead->id]);
            $n = 0;
            foreach ([
                ['Paiement Orange Money et Wave', 'story', 'done', 'high', $sp1, $rel1, $kader, 8, -9, 'feature/mobile-money'],
                ['Écran de confirmation de paiement', 'story', 'done', 'normal', $sp1, $rel1, $ines, 3, -8, 'feature/paiement-ok'],
                ['Crash au retour arrière sur Android 10', 'bug', 'done', 'critical', $sp1, $rel1, $kader, 2, -2, 'fix/back-crash'],
                ['Suivi de la commande sur une carte', 'story', 'in_progress', 'high', $sp2, $rel2, $kader, 8, null, 'feature/suivi-carte'],
                ['Notifications push à chaque étape de la livraison', 'story', 'review', 'normal', $sp2, $rel2, $ines, 5, null, 'feature/push'],
                ['Prix barré mal affiché sur iPhone SE', 'bug', 'testing', 'normal', $sp2, $rel2, $ines, 1, null, 'fix/prix-barre'],
                ['Historique des commandes avec filtres', 'story', 'todo', 'normal', $sp2, $rel2, null, 5, null, null],
                ['Mise à jour de Flutter et des dépendances', 'techdebt', 'todo', 'low', $sp2, null, $kader, 3, null, null],
                ['Mode sombre', 'improvement', 'todo', 'low', $sp2, null, null, 3, null, null],
                ['Lenteur de la page catalogue (2 000 produits)', 'bug', 'backlog', 'high', null, null, null, 5, null, null],
                ['Connexion avec Google', 'story', 'backlog', 'normal', null, null, null, 5, null, null],
                ['Erreur 500 à l\'export des commandes', 'bug', 'in_progress', 'critical', null, null, $ines, 2, null, 'fix/export-500'],
            ] as [$title, $kind, $st, $prio, $sp, $rel, $who, $pts, $doneDays, $branch]) {
                $n++;
                $ins('dev_items', ['title' => $title, 'number' => '#'.str_pad((string) (40 + $n), 4, '0', STR_PAD_LEFT), 'kind' => $kind, 'status' => $st, 'priority' => $prio,
                    'app_id' => str_contains($title, 'export') || str_contains($title, 'catalogue') ? $app2 : $app1, 'sprint_id' => $sp, 'release_id' => $rel, 'assignee_id' => $who?->id, 'reporter_id' => $lead->id,
                    'points' => $pts, 'branch' => $branch, 'pr_url' => $branch && in_array($st, ['review', 'testing', 'done']) ? 'https://git.example.ci/demo/app-clients/pulls/'.(100 + $n) : null,
                    'started_at' => in_array($st, ['in_progress', 'review', 'testing', 'done']) ? now()->subDays(4) : null, 'done_at' => $doneDays !== null ? now()->addDays($doneDays) : null,
                    'description' => $kind === 'bug' ? "Étapes : ouvrir l'application, …\nAttendu : …\nObtenu : …" : null]);
            }
            app(\App\Modules\Dev\DevHooks::class)->changelog($company, $rel1);
            foreach ([$app1, $app2] as $aid) {
                $DB::table('dev_apps')->where('id', $aid)->update(['git_provider' => $aid === $app1 ? 'github' : 'gitlab', 'git_token' => Str::lower(Str::random(40)), 'git_secret' => Str::random(40), 'git_last_event_at' => now()->subMinutes(35)]);
            }
            foreach ([['#0044', 'commit', 'Carte : affichage du livreur en temps réel', 'Kader Bamba', 50], ['#0044', 'commit', 'Carte : recentrage automatique', 'Kader Bamba', 35],
                ['#0045', 'pr_opened', 'Notifications push à chaque étape (#45)', 'ines-koffi', 120], ['#0043', 'pr_merged', 'Fix crash retour arrière Android 10 (#43)', 'kader-b', 2900]] as [$num, $kind, $title, $who, $ago]) {
                if ($iid = $DB::table('dev_items')->where('company_id', $company->id)->where('number', $num)->value('id')) {
                    $DB::table('dev_item_events')->insert(['company_id' => $company->id, 'item_id' => $iid, 'kind' => $kind, 'title' => $title, 'author' => $who,
                        'url' => 'https://github.com/demo/app-clients/'.($kind === 'commit' ? 'commit/'.substr(md5($title), 0, 7) : 'pull/'.(100 + (int) ltrim($num, '#'))), 'created_at' => now()->subMinutes($ago)]);
                }
            }
        }

        // ---------------------------------------------------------------- rapports d'activité
        if (\Illuminate\Support\Facades\Schema::hasTable('ra_reports')) {
            app(\App\Support\ModuleRegistry::class)->ensureSetup($company, ['activite']);
            foreach ([$com, $com2, $mariam, $aicha, $konan] as $who) {
                $DB::table('users')->where('id', $who->id)->update(['supervisor_id' => $admin->id]);
            }
            [$mon] = \App\Modules\Activite\ReportService::week();
            foreach ([
                [$admin, 0, 'Réunion avec la banque pour la ligne de crédit', 'Accord de principe pour 25 M F CFA, dossier à compléter.', 90],
                [$admin, 1, 'Entretiens de recrutement — 2 techniciens', 'Deux candidats retenus pour un second entretien.', 180],
                [$admin, 2, 'Visite du futur local de Bouaké', 'Bail à négocier, travaux d\'électricité à prévoir.', 240],
                [$com, 0, 'Appels de relance des devis en attente', '6 clients joints, 2 relances à refaire.', 120],
                [$mariam, 1, 'Shooting photo de l\'équipe pour la campagne', null, 180],
            ] as [$who, $d, $title, $details, $min]) {
                $DB::table('ra_entries')->insert(['company_id' => $company->id, 'user_id' => $who->id, 'date' => \Illuminate\Support\Carbon::parse($mon)->addDays($d)->toDateString(), 'title' => $title, 'details' => $details,
                    'minutes' => $min, 'created_at' => now(), 'updated_at' => now()]);
            }
            $svc = app(\App\Modules\Activite\ReportService::class);
            [$pmon, $pfri] = \App\Modules\Activite\ReportService::week(now()->subWeek()->startOfWeek()->toDateString());
            app()->instance('tenant', $company);
            foreach ([[$com, 'sent', 'Semaine dense : tournée de Yopougon (9 entreprises), 3 devis demandés et une commande signée.', 'Concurrent NetCom très agressif sur les prix.', 'Relancer les 3 devis, préparer la tournée Riviera.'],
                [$mariam, 'approved', 'Lancement de la campagne de Bouaké et préparation de l\'inauguration.', null, 'Inauguration de Bouaké, bilan de la campagne Facebook.'],
                [$com2, 'returned', 'Rendez-vous au Plateau.', null, null]] as [$who, $st, $sum, $diff, $next]) {
                $r = $svc->save($company, $who, $pmon, $pfri, ['summary' => $sum, 'difficulties' => $diff, 'next_steps' => $next]);
                $items = array_values(array_filter($svc->items($company, $r, $who, $pmon, $pfri), fn ($i) => $i['included'] ?? true));
                $DB::table('ra_reports')->where('id', $r->id)->update(['status' => $st, 'items' => json_encode($items, JSON_UNESCAPED_UNICODE), 'supervisor_id' => $admin->id, 'sent_at' => now()->subDays(1)->setTime(17, 40),
                    'reviewed_by' => $st === 'sent' ? null : $admin->id, 'reviewed_at' => $st === 'sent' ? null : now()->subHours(20),
                    'review_comment' => $st === 'returned' ? 'Détaille les rendez-vous et leurs résultats, s\'il te plaît.' : ($st === 'approved' ? 'Très bon travail, bravo.' : null)]);
            }
        }
    }

    /** Avis Google : fiche connectée, avis (dont un négatif à traiter), demandes d'avis, publications, statistiques et historique de la note. */
    protected function avisGoogle(Company $company): void
    {
        $company = $company->fresh();
        if (! $company->hasModule('avis_google') || ! \Illuminate\Support\Facades\Schema::hasTable('gb_locations')) {
            return;
        }
        $DB = \Illuminate\Support\Facades\DB::class;
        if ($DB::table('gb_locations')->where('company_id', $company->id)->exists()) {
            return;
        }
        $admin = User::where('company_id', $company->id)->orderBy('id')->first();
        $DB::table('gb_settings')->updateOrInsert(['company_id' => $company->id], ['mode' => 'api', 'connected_email' => 'contact.demo.sarl@gmail.com', 'account_name' => 'accounts/104857395021',
            'requests' => json_encode(['enabled' => true, 'triggers' => ['invoice.paid', 'receipt.created', 'appointment.completed'], 'channels' => ['whatsapp', 'sms', 'email'], 'delay_hours' => 2, 'cooldown_days' => 90,
                'message' => "Bonjour {nom}, merci pour votre confiance ! Votre avis compte beaucoup pour l'équipe {societe} : {lien} (30 secondes)."]),
            'alerts' => json_encode(['max_rating' => 2, 'whatsapp' => '+225 07 07 07 07 07', 'email' => null]), 'last_sync_at' => now()->subMinutes(12), 'created_at' => now()->subMonths(5), 'updated_at' => now()]);

        // historique de la note et statistiques (90 jours)
        $hist = [];
        $days = [];
        $n = 112;
        for ($i = 180; $i >= 0; $i -= 6) {
            $hist[now()->subDays($i)->toDateString()] = ['r' => round(4.28 + (180 - $i) / 180 * 0.33, 2), 'n' => $n += 2];
        }
        $tot = ['views' => 0, 'calls' => 0, 'website' => 0, 'directions' => 0];
        for ($i = 30; $i >= 1; $i--) {
            $d = now()->subDays($i);
            $we = $d->isWeekend() ? 1.45 : 1;
            $v = ['views' => (int) round((52 + 18 * sin($i / 3) + (30 - $i)) * $we), 'calls' => (int) round((3 + ($i % 4)) * $we), 'website' => 2 + ($i % 5), 'directions' => (int) round((6 + ($i % 6)) * $we)];
            $days[$d->toDateString()] = $v;
            foreach ($v as $k => $x) {
                $tot[$k] += $x;
            }
        }
        $locId = $DB::table('gb_locations')->insertGetId(['company_id' => $company->id, 'location_name' => 'locations/7781254003311', 'place_id' => 'ChIJd8BlQ2BZwokRAFUEcm_qrcA',
            'title' => $company->name, 'address' => 'Cocody Riviera 2, carrefour Anono, Abidjan', 'phone' => '+225 27 22 41 00 00', 'website' => 'https://demo-sarl.ci',
            'description' => 'Matériel informatique, fournitures de bureau et services aux entreprises à Abidjan. Livraison rapide, installation et maintenance sur site.',
            'hours' => json_encode([]), 'maps_uri' => 'https://maps.google.com/?cid=13886710429357740544', 'review_uri' => 'https://search.google.com/local/writereview?placeid=ChIJd8BlQ2BZwokRAFUEcm_qrcA',
            'rating' => 4.61, 'review_count' => $n, 'token' => \App\Modules\AvisGoogle\GoogleProfile::newToken(), 'metrics' => json_encode($tot + ['qr_scans' => 37, 'days' => $days]),
            'history' => json_encode($hist), 'last_sync_at' => now()->subMinutes(12), 'created_at' => now()->subMonths(5), 'updated_at' => now()]);

        $reviews = [
            ['Koffi Yao', 5, 'Très bon accueil, conseils au top pour le choix de nos ordinateurs. Livraison le lendemain et installation propre. Je recommande !', 0.2, null],
            ['Mariam Diallo', 2, 'Commande annoncée pour mardi, reçue vendredi sans que personne ne me prévienne. Le matériel est bien mais le suivi laisse à désirer.', 0.6, null],
            ['Serge Kouamé', 4, 'Bons prix sur les cartouches et le papier. Un peu d\'attente à la caisse le samedi matin.', 1.5, null],
            ['Aïcha Bamba', 5, 'Le technicien est venu réparer notre imprimante en moins de deux heures. Service sérieux.', 2.2, 'Merci beaucoup Aïcha ! Nous transmettons vos compliments à Ismaël, notre technicien. À très bientôt.'],
            ['Jean-Marc Gnagne', 5, null, 3.1, 'Merci Jean-Marc pour vos 5 étoiles !'],
            ['Fatou Coulibaly', 3, 'Choix correct mais certains articles étaient en rupture. Le personnel a proposé de les commander.', 4.0, 'Bonjour Fatou, merci pour votre retour. Nous avons renforcé le réassort des articles les plus demandés ; n\'hésitez pas à nous appeler avant de passer.'],
            ['Ibrahim Ouattara', 5, 'Devis reçu en une heure, facture normalisée sans problème. Fournisseur fiable pour notre cabinet.', 6.0, 'Merci Ibrahim, ravis de compter le cabinet parmi nos clients.'],
            ['Christelle Aka', 4, 'Boutique bien rangée, vendeurs aimables. Parking un peu juste.', 9.0, 'Merci Christelle ! Des places supplémentaires sont disponibles derrière le bâtiment.'],
            ['Moussa Traoré', 1, 'Ordinateur tombé en panne après 3 semaines, j\'ai dû revenir deux fois.', 14.0, 'Bonjour Moussa, nous sommes désolés pour ce désagrément. L\'appareil a été échangé sous garantie ; notre responsable SAV reste joignable au 27 22 41 00 00.'],
            ['Nadège Kra', 5, 'Toujours un plaisir, merci à toute l\'équipe.', 20.0, 'Merci Nadège !'],
            ['Patrick Ehui', 5, 'Commande en ligne retirée en boutique en 30 minutes. Pratique.', 27.0, 'Merci Patrick, à bientôt !'],
            ['Awa Konaté', 4, 'Bon rapport qualité-prix.', 35.0, 'Merci Awa !'],
        ];
        foreach ($reviews as $k => [$author, $rating, $comment, $ago, $reply]) {
            $at = now()->subDays((int) floor($ago))->subHours((int) (($ago - floor($ago)) * 24) + 2);
            $DB::table('gb_reviews')->insert(['company_id' => $company->id, 'location_id' => $locId, 'review_id' => 'AbFvOq'.str_pad((string) $k, 6, '0', STR_PAD_LEFT).'demo', 'author' => $author,
                'rating' => $rating, 'comment' => $comment, 'reviewed_at' => $at, 'reply' => $reply, 'replied_at' => $reply ? $at->copy()->addHours(3 + $k * 2) : null, 'replied_by' => $reply ? $admin?->id : null,
                'source' => 'api', 'alerted_at' => $rating <= 2 ? $at->copy()->addMinutes(30) : null, 'created_at' => $at, 'updated_at' => $at]);
        }

        $reqs = [
            ['Koffi Yao', 'whatsapp', '+2250707112233', 'clicked', 1, null, null], ['Fatou Coulibaly', 'sms', '+2250505667788', 'sent', 1, null, null],
            ['Société Ivoire Négoce', 'email', 'achats@ivoire-negoce.ci', 'sent', 2, null, null], ['Bintou Sangaré', 'whatsapp', '+2250785960718', 'feedback', 3, 3, 'Le livreur était en retard mais très poli. Pensez à prévenir par SMS.'],
            ['Désiré Ahoua', 'whatsapp', '+2250511223366', 'clicked', 4, null, null], ['Aminata Touré', 'whatsapp', '+2250747586970', 'scheduled', 0, null, null],
            ['Cabinet Kouadio & Associés', 'email', 'contact@kouadio-associes.ci', 'clicked', 6, null, null], ['Yves Koné', 'sms', '+2250102030405', 'failed', 7, null, null],
            ['Marcelle Djè', 'whatsapp', '+2250708091011', 'sent', 9, null, null], ['Ange Brou', 'whatsapp', '+2250506070809', 'clicked', 12, null, null],
        ];
        foreach ($reqs as [$name, $ch, $to, $st, $ago, $fr, $fb]) {
            $at = now()->subDays($ago)->subHours(3);
            $DB::table('gb_requests')->insert(['company_id' => $company->id, 'location_id' => $locId, 'name' => $name, 'channel' => $ch, 'to' => $to, 'token' => \App\Modules\AvisGoogle\GoogleProfile::newToken(40),
                'context' => $ch === 'email' ? 'invoice.paid' : 'receipt.created', 'status' => $st, 'error' => $st === 'failed' ? 'Numéro injoignable' : null,
                'send_at' => $st === 'scheduled' ? now()->addHour() : $at, 'sent_at' => $st === 'scheduled' ? null : $at, 'clicked_at' => in_array($st, ['clicked', 'feedback'], true) ? $at->copy()->addMinutes(40) : null,
                'feedback_rating' => $fr, 'feedback' => $fb, 'created_at' => $at, 'updated_at' => $at]);
        }

        foreach ([
            ['OFFER', 'Rentrée : -15 % sur les fournitures', 'Jusqu\'au 15 novembre, -15 % sur les ramettes, classeurs et cartouches pour les entreprises. Commandez par WhatsApp, livraison le lendemain à Abidjan.', 'ORDER', 'https://demo-sarl.ci/boutique', 3, 'RENTREE15'],
            ['STANDARD', null, 'Nouveau : installation et maintenance de vos imprimantes sur site, sous 4 heures à Cocody, Plateau et Marcory.', 'CALL', null, 11, null],
            ['EVENT', 'Atelier « Facture normalisée »', 'Samedi 10 h : atelier gratuit pour les commerçants, mise en place de la facture normalisée avec votre logiciel. Places limitées.', 'SIGN_UP', 'https://demo-sarl.ci/atelier', 18, null],
        ] as [$type, $title, $summary, $cta, $url, $ago, $coupon]) {
            $at = now()->subDays($ago);
            $DB::table('gb_posts')->insert(['company_id' => $company->id, 'location_id' => $locId, 'type' => $type, 'title' => $title, 'summary' => $summary, 'cta_type' => $cta, 'cta_url' => $url,
                'starts_on' => $type !== 'STANDARD' ? $at->toDateString() : null, 'ends_on' => $type !== 'STANDARD' ? $at->copy()->addDays(21)->toDateString() : null, 'coupon' => $coupon,
                'google_name' => 'accounts/104857395021/locations/7781254003311/localPosts/'.(9000 + $ago), 'status' => 'published', 'user_id' => $admin?->id, 'published_at' => $at, 'created_at' => $at, 'updated_at' => $at]);
        }
    }

    /** WhatsApp : conversations clients (texte, photo, document), modèles approuvés, réponses rapides, horaires et messages automatiques. */
    protected function whatsappInbox(Company $company): void
    {
        $company = $company->fresh();
        if (! $company->hasModule('whatsapp') || ! \Illuminate\Support\Facades\Schema::hasTable('wa_conversations')) {
            return;
        }
        app()->instance('tenant', $company);
        $DB = \Illuminate\Support\Facades\DB::class;
        $inbox = app(\App\Modules\Whatsapp\Inbox::class);
        $s = $inbox->settings($company);
        $DB::table('wa_settings')->where('id', $s->id)->update(['display_phone' => '+225 07 00 11 22 33',
            'welcome_message' => "Bonjour {nom} Bienvenue chez {societe} ! Un conseiller vous répond dans quelques minutes.",
            'away_message' => "Merci pour votre message. Nous sommes actuellement fermés ({horaires}) : nous vous répondons dès l'ouverture."]);
        $admin = User::where('company_id', $company->id)->where('email', 'admin@demo.ci')->value('id');
        $agents = User::where('company_id', $company->id)->where('is_active', true)->where('id', '!=', $admin)->limit(2)->pluck('id')->all();
        $partners = $DB::table('partners')->where('company_id', $company->id)->whereNull('deleted_at')->whereNotNull('phone')->where('type', '!=', 'supplier')->limit(5)->get(['id', 'name', 'phone']);
        $chats = [
            ['Bonjour, vous avez encore le modèle en promotion ?', [['in', 'Bonjour, vous avez encore le modèle en promotion ?', 50], ['out', 'Bonjour ! Oui, il en reste 12 en stock au prix promo jusqu\'à samedi.', 45, 'read'], ['in', 'Super, je passe en prendre 3 demain.', 12]], 'open', 1],
            ['Facture reçue merci', [['out', 'Bonjour, voici votre facture du mois. Merci de votre confiance !', 300, 'read', 'document', 'Facture-FA-2026-0142.pdf'], ['in', 'Facture reçue merci', 280], ['in', 'Je règle par Orange Money ce soir', 279]], 'open', 0],
            ['Livraison à Cocody possible ?', [['in', 'Livraison à Cocody possible ?', 8], ['in', 'C\'est pour une commande de 20 cartons', 7]], 'open', 2],
            ['Photo du colis abîmé', [['in', 'Le colis est arrivé abîmé', 1500], ['in', null, 1499, null, 'image', 'colis.jpg'], ['out', 'Nous sommes désolés ! Un remplacement part demain matin.', 1450, 'delivered']], 'pending', 0],
            ['Merci pour tout', [['in', 'Merci pour tout, à la prochaine !', 4000], ['out', 'Avec plaisir, à très bientôt', 3990, 'read']], 'closed', 0],
        ];
        foreach ($chats as $i => [$preview, $msgs, $status, $unread]) {
            $p = $partners[$i] ?? null;
            $phone = $p ? (\App\Messaging\Phone::digits($p->phone) ?? '22507000000'.$i) : '2250700000'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $conv = \App\Modules\Whatsapp\Models\Conversation::withoutGlobalScopes()->create(['company_id' => $company->id, 'phone' => $phone, 'name' => $p->name ?? 'Client WhatsApp '.($i + 1),
                'partner_id' => $p->id ?? null, 'assigned_to' => $i === 2 ? null : ($i % 2 ? ($agents[0] ?? $admin) : $admin), 'status' => $status, 'unread' => $unread,
                'labels' => [['Commande'], ['Paiement'], ['Livraison'], ['Réclamation'], []][$i]]);
            $last = null;
            foreach ($msgs as $row) {
                [$dir, $body, $minAgo, $st, $type, $file] = array_pad($row, 6, null);
                $at = now()->subMinutes($minAgo);
                $path = null;
                if (($type ?? null) === 'image') {
                    $png = imagecreatetruecolor(320, 240);
                    imagefill($png, 0, 0, imagecolorallocate($png, 214, 196, 160));
                    imagefilledrectangle($png, 60, 50, 260, 190, imagecolorallocate($png, 160, 120, 70));
                    ob_start();
                    imagejpeg($png);
                    $path = $inbox->storeFile($company, ob_get_clean(), 'image/jpeg', 'colis.jpg');
                } elseif (($type ?? null) === 'document') {
                    $path = $inbox->storeFile($company, '%PDF-1.4 facture de démonstration', 'application/pdf', $file);
                }
                $m = new \App\Modules\Whatsapp\Models\WaMessage(['conversation_id' => $conv->id, 'direction' => $dir, 'type' => $type ?? 'text', 'body' => $body, 'media_path' => $path,
                    'media_mime' => $type === 'image' ? 'image/jpeg' : ($type === 'document' ? 'application/pdf' : null), 'file_name' => $file ?? null,
                    'status' => $dir === 'in' ? 'received' : ($st ?? 'sent'), 'user_id' => $dir === 'out' ? $admin : null, 'provider_id' => 'demo.'.$i.'.'.$minAgo]);
                $m->company_id = $company->id;
                $m->created_at = $at;
                $m->updated_at = $at;
                $m->save();
                $last = [$dir, $at, \App\Modules\Whatsapp\Inbox::preview($m->type, $body, $file ?? null)];
                if ($dir === 'in') {
                    $conv->last_inbound_at = $at;
                }
            }
            $conv->forceFill(['last_direction' => $last[0], 'last_message_at' => $last[1], 'last_preview' => $last[2]])->save();
        }
        foreach ([['relance_facture', 'UTILITY', 'Bonjour {{1}}, votre facture {{2}} de {{3}} est arrivée à échéance. Vous pouvez la régler par Mobile Money ou virement. Merci !', 3],
            ['confirmation_commande', 'UTILITY', 'Bonjour {{1}}, votre commande {{2}} est confirmée et sera livrée le {{3}}.', 3],
            ['promo_semaine', 'MARKETING', 'Cette semaine chez nous : {{1}}. Répondez STOP pour ne plus recevoir nos offres.', 1]] as [$n, $cat, $b, $k]) {
            $DB::table('wa_templates')->insert(['company_id' => $company->id, 'name' => $n, 'language' => 'fr', 'category' => $cat, 'status' => 'APPROVED', 'body' => $b, 'params' => $k,
                'components' => json_encode([['type' => 'BODY', 'text' => $b]]), 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([['tarifs', 'Nos tarifs sont disponibles ici : https://demo-sarl.ci/tarifs. Souhaitez-vous un devis personnalisé ?'],
            ['livraison', 'Nous livrons partout à Abidjan sous 24 h (gratuit dès 50 000 F CFA), et à l\'intérieur du pays sous 72 h.'],
            ['paiement', 'Vous pouvez régler par Orange Money, MTN MoMo, Wave, carte bancaire ou virement. Le lien de paiement est sur votre facture.'],
            ['merci', 'Merci pour votre confiance Bonne journée !']] as [$k, $b]) {
            $DB::table('wa_quick_replies')->insert(['company_id' => $company->id, 'shortcut' => $k, 'body' => $b, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** YoMail : compte de démonstration (en pause, sans serveur) avec messages clients, envoyés, signature et modèles. */
    protected function boiteMail(Company $company): void
    {
        $company = $company->fresh();
        if (! $company->hasModule('mailbox') || ! \Illuminate\Support\Facades\Schema::hasTable('mb_accounts')) {
            return;
        }
        app()->instance('tenant', $company);
        $admin = User::where('company_id', $company->id)->where('email', 'admin@demo.ci')->first();
        if (! $admin) {
            return;
        }
        $acc = new \App\Modules\Mailbox\Models\Account(['provider' => 'imap', 'email' => 'direction@demo-sarl.ci', 'display_name' => $company->name.' — Direction', 'color' => '#2E6BE6',
            'user_id' => $admin->id, 'status' => 'paused', 'error' => 'Compte de démonstration : ajoutez votre vraie boîte (Microsoft 365, Gmail ou hébergement) pour envoyer et recevoir.',
            'sync_days' => 30, 'settings' => ['imap_host' => 'mail.demo-sarl.ci', 'imap_port' => 993, 'imap_security' => 'ssl', 'smtp_host' => 'mail.demo-sarl.ci', 'smtp_port' => 465, 'smtp_security' => 'ssl']]);
        $acc->company_id = $company->id;
        $acc->setSecret(['password' => 'demo']);
        $acc->save();
        $S = \App\Modules\Mailbox\Store::class;
        $f = [];
        foreach (['INBOX' => ['Boîte de réception', 'inbox'], 'Sent' => ['Éléments envoyés', 'sent'], 'Drafts' => ['Brouillons', 'drafts'], 'Archive' => ['Archives', 'archive'], 'Trash' => ['Corbeille', 'trash'], 'Clients' => ['Clients', 'other']] as $rid => [$n, $r]) {
            $f[$r] = $S::folder($acc, $rid, $n, $r);
        }
        $partners = \Illuminate\Support\Facades\DB::table('partners')->where('company_id', $company->id)->whereNull('deleted_at')->whereNotNull('email')->where('type', '!=', 'supplier')->limit(6)->get(['name', 'email']);
        $p = fn ($i) => $partners[$i % max(1, $partners->count())] ?? (object) ['name' => 'Client', 'email' => 'client@exemple.ci'];
        $me = ['name' => $acc->display_name, 'email' => $acc->email];
        $mails = [
            [0, 'inbox', 'Commande de 40 cartons — livraison vendredi ?', '<p>Bonjour,</p><p>Suite à notre échange, nous souhaitons commander <b>40 cartons</b> de la référence habituelle. Pouvez-vous confirmer une livraison vendredi matin à notre dépôt de Yopougon ?</p><p>Merci de nous envoyer la facture pro forma.</p><p>Cordialement,</p>', 0.4, false, true, true],
            [1, 'inbox', 'Relevé de compte et paiement Mobile Money', '<p>Bonjour,</p><p>Nous avons réglé la facture du mois par Orange Money ce matin (référence en pièce jointe). Merci de nous confirmer la bonne réception.</p>', 2, false, false, true],
            [2, 'inbox', 'Demande de devis — équipement de 3 agences', '<p>Bonjour,</p><p>Nous ouvrons trois nouvelles agences à Bouaké, San-Pédro et Korhogo. Pourriez-vous nous faire une proposition chiffrée pour l\'équipement complet ?</p><p>Nous aimerions une réponse avant la fin de la semaine. Pouvons-nous en discuter le '.now()->addDays(4)->format('d/m/Y').' à 15h, ou le '.now()->addDays(6)->format('d/m/Y').' à 10h30 ?</p><p>Budget indicatif : 4 500 000 FCFA.</p>', 5, true, false, false],
            [3, 'inbox', 'Invitation : réunion de lancement du projet', '<p>Bonjour à tous,</p><p>Je vous propose une réunion de lancement <b>mardi à 10 h</b> en visioconférence. L\'ordre du jour : planning, budget, équipe.</p>', 26, true, false, false],
            [4, 'inbox', 'Réclamation livraison incomplète', '<p>Bonjour,</p><p>La livraison d\'hier était incomplète : il manque 6 unités sur le bon de livraison. Merci de régulariser rapidement.</p>', 30, true, true, false],
            [0, 'sent', 'RE: Commande de 40 cartons — livraison vendredi ?', '<p>Bonjour,</p><p>C\'est noté : livraison <b>vendredi entre 8 h et 10 h</b>. Vous trouverez la facture pro forma en pièce jointe.</p><p>Bonne journée,</p>', 0.2, true, false, true],
            [2, 'sent', 'Proposition commerciale — équipement des agences', '<p>Bonjour,</p><p>Comme convenu, voici notre proposition pour l\'équipement de vos trois agences, avec un planning d\'installation sur quatre semaines.</p>', 49, true, false, true],
            [1, 'archive', 'Contrat annuel signé', '<p>Bonjour,</p><p>Veuillez trouver ci-joint le contrat annuel signé. Merci pour votre réactivité.</p>', 240, true, false, true],
        ];
        foreach ($mails as $i => [$pi, $role, $subject, $html, $hoursAgo, $seen, $flagged, $att]) {
            $c = $p($pi);
            $contact = ['name' => $c->name, 'email' => mb_strtolower($c->email)];
            $sent = $role === 'sent';
            $mid = '<demo-'.$i.'-'.\Illuminate\Support\Str::random(8).'@demo-sarl.ci>';
            $S::upsert($acc, $f[$role], 'demo-'.$i, [
                'message_id' => $mid, 'subject' => $subject, 'from_name' => $sent ? $me['name'] : $contact['name'], 'from_email' => $sent ? $me['email'] : $contact['email'],
                'to' => [$sent ? $contact : $me], 'cc' => [], 'date' => now()->subMinutes((int) ($hoursAgo * 60)), 'html' => $html,
                'thread' => sha1('demo-thread-'.$pi.'-'.preg_replace('/^RE: /', '', $subject)),
                'attachments' => $att ? [['id' => '0', 'name' => $sent ? 'Facture-proforma.pdf' : 'Document.pdf', 'mime' => 'application/pdf', 'size' => 84211, 'cid' => null, 'inline' => false]] : [],
            ], ['seen' => $seen, 'flagged' => $flagged], 4000);
        }
        foreach ($f as $folder) {
            $S::counts($folder);
        }
        $DB = \Illuminate\Support\Facades\DB::class;
        $sig = '<table cellpadding="0" cellspacing="0" style="font-family:Segoe UI,Arial,sans-serif;font-size:13px;color:#1B1F24"><tr><td style="border-left:3px solid #2E6BE6;padding-left:12px">'
            .'<div style="font-size:15px;font-weight:700;color:#0B1B3A">'.e($admin->name).'</div><div style="color:#5B6B85">Directeur général</div>'
            .'<div style="font-weight:600;color:#2E6BE6;margin-top:2px">'.e($company->name).'</div><div style="margin-top:6px">Tél. +225 27 22 00 00 00 · <a href="mailto:direction@demo-sarl.ci" style="color:#2E6BE6;text-decoration:none">direction@demo-sarl.ci</a></div>'
            .'<div style="color:#5B6B85;margin-top:2px">Abidjan, Plateau</div></td></tr></table>';
        $DB::table('mb_signatures')->insert(['company_id' => $company->id, 'user_id' => $admin->id, 'name' => 'Signature direction', 'html' => $sig, 'is_default' => true, 'on_reply' => true,
            'fields' => json_encode(['name' => $admin->name, 'title' => 'Directeur général', 'company' => $company->name, 'phone' => '+225 27 22 00 00 00', 'email' => 'direction@demo-sarl.ci', 'address' => 'Abidjan, Plateau', 'color' => '#2E6BE6', 'mode' => 'form']),
            'created_at' => now(), 'updated_at' => now()]);
        foreach ([['Accusé de réception de commande', 'Votre commande est bien reçue', '<p>Bonjour,</p><p>Nous accusons réception de votre commande. Elle sera préparée sous 24 h et vous serez prévenu dès l\'expédition.</p><p>Merci pour votre confiance.</p>'],
            ['Relance de devis', 'Votre devis', '<p>Bonjour,</p><p>Avez-vous pu consulter notre devis ? Nous restons à votre disposition pour en discuter ou l\'adapter à vos besoins.</p>']] as [$n, $sub, $h]) {
            $DB::table('mb_templates')->insert(['company_id' => $company->id, 'user_id' => null, 'name' => $n, 'subject' => $sub, 'html' => $h, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Intégrations : un webhook Zapier avec son historique d'envois, deux flux de données pour Google Sheets / Power BI. */
    protected function integrations(Company $company): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('wh_endpoints')) {
            return;
        }
        $DB = \Illuminate\Support\Facades\DB::class;
        $admin = User::where('company_id', $company->id)->where('email', 'admin@demo.ci')->value('id');
        $id = $DB::table('wh_endpoints')->insertGetId(['company_id' => $company->id, 'url' => 'https://hooks.zapier.com/hooks/catch/1234567/demo-yomara/', 'description' => 'Zapier — factures payées vers Google Sheets et Slack',
            'events' => json_encode(['invoice.paid', 'invoice.validated', 'payment.received', 'partners.created']), 'secret' => \Illuminate\Support\Facades\Crypt::encryptString(\App\Integrations\Webhooks::secret()),
            'active' => true, 'last_success_at' => now()->subHours(1), 'created_by' => $admin, 'created_at' => now()->subDays(20), 'updated_at' => now()->subHours(1)]);
        $docs = $DB::table('trade_documents')->where('company_id', $company->id)->where('direction', 'sale')->where('type', 'invoice')->whereNull('deleted_at')->orderByDesc('date')->limit(8)->get();
        foreach ($docs as $i => $d) {
            $ev = $i % 3 === 0 ? 'invoice.paid' : ($i % 3 === 1 ? 'invoice.validated' : 'payment.received');
            $at = now()->subHours(3 + $i * 9);
            $fail = $i === 4;
            $DB::table('wh_deliveries')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'endpoint_id' => $id, 'company_id' => $company->id, 'event' => $ev,
                'payload' => json_encode(['id' => (string) \Illuminate\Support\Str::uuid(), 'event' => $ev, 'created_at' => $at->toIso8601String(), 'company' => ['id' => $company->id, 'name' => $company->name],
                    'data' => ['id' => $d->id, 'number' => $d->number, 'partner_name' => $d->partner_name, 'total' => (float) $d->total, 'currency' => $company->currency ?? 'XOF']], JSON_UNESCAPED_UNICODE),
                'status' => 'success', 'attempts' => $fail ? 2 : 1, 'response_code' => 200, 'response_body' => '{"status":"success","attempt":"'.substr(md5((string) $i), 0, 8).'"}',
                'duration_ms' => 180 + $i * 37, 'delivered_at' => $at, 'created_at' => $fail ? $at->copy()->subMinute() : $at, 'updated_at' => $at]);
        }
        foreach ([['Factures — Google Sheets du comptable', 'invoices', 0, 42], ['Lignes de vente — Power BI', 'sales_lines', 365, 17]] as [$n, $ds, $days, $hits]) {
            $DB::table('data_feeds')->insert(['company_id' => $company->id, 'user_id' => $admin, 'name' => $n, 'dataset' => $ds, 'token' => \Illuminate\Support\Str::random(40),
                'days' => $days, 'hits' => $hits, 'last_used_at' => now()->subHours(2), 'created_at' => now()->subDays(15), 'updated_at' => now()->subHours(2)]);
        }
    }

    protected function banqueConnectee(Company $company): void
    {
        $company = $company->fresh();
        if (! $company->hasModule('tresorerie') || ! \Illuminate\Support\Facades\Schema::hasTable('tr_bank_connections')) {
            return;
        }
        app()->instance('tenant', $company);
        $DB = \Illuminate\Support\Facades\DB::class;
        $acc = $DB::table('tr_accounts')->where('company_id', $company->id)->whereNull('deleted_at')->get()->keyBy('kind');
        $admin = User::where('company_id', $company->id)->where('email', 'admin@demo.ci')->value('id');
        if ($m = $acc['mobile_money'] ?? null) {
            \App\Modules\Tresorerie\Bank\BankConnection::create(['provider' => 'wave', 'account_id' => $m->id, 'label' => 'Wave Business boutique', 'status' => 'active',
                'credentials' => ['api_key' => 'wave_ci_prod_demo'], 'sync_from' => now()->subDays(60)->toDateString(), 'last_sync_at' => now()->subHours(2), 'next_sync_at' => now()->addHours(2),
                'imported' => 184, 'last_balance' => 1_284_500, 'created_by' => $admin]);
        }
        if ($b = $acc['bank'] ?? null) {
            \App\Modules\Tresorerie\Bank\BankConnection::create(['provider' => 'email', 'account_id' => $b->id, 'label' => 'Relevés SGCI (MT940)', 'status' => 'active',
                'inbound_token' => \App\Modules\Tresorerie\Bank\BankSync::token(), 'last_sync_at' => now()->subDays(1)->setTime(6, 5), 'imported' => 97, 'created_by' => $admin]);
        }
        foreach ([['Frais bancaires', 'frais, agios, commission, tenue de compte', 'out', 'bank_fees', 14], ['Téléphone et internet', 'orange, mtn, moov, starlink', 'out', 'telecom', 6],
            ['Électricité et eau', 'cie, sodeci', 'out', 'utilities', 4], ['Cotisations CNPS', 'cnps', 'out', 'social', 2]] as $k => [$n, $kw, $dir, $cat, $hits]) {
            $DB::table('tr_bank_rules')->insert(['company_id' => $company->id, 'name' => $n, 'contains' => $kw, 'direction' => $dir, 'action' => 'operation', 'category' => $cat,
                'active' => true, 'hits' => $hits, 'sort' => $k, 'created_at' => now(), 'updated_at' => now()]);
        }
        if (! \App\Models\PaymentGateway::where('company_id', $company->id)->where('driver', 'mtn_momo')->exists()) {
            \App\Models\PaymentGateway::create(['company_id' => $company->id, 'driver' => 'mtn_momo', 'label' => 'MTN MoMo (paiement direct)', 'mode' => 'test', 'enabled' => true, 'sort' => 5,
                'credentials' => ['subscription_key' => 'demo', 'api_user' => '00000000-0000-4000-8000-000000000000', 'api_key' => 'demo', 'target_environment' => 'mtnivorycoast']]);
        }
    }

    /** Réunions & webinaires : réunion passée avec compte rendu, réunions à venir, webinaire à venir avec inscrits, webinaire passé. */
    protected function reunionsWebinaires(Company $company): void
    {
        $company = $company->fresh();
        if (! $company->hasModule('visio') || ! \Illuminate\Support\Facades\Schema::hasTable('vis_meetings')) {
            return;
        }
        app()->instance('tenant', $company);
        $DB = \Illuminate\Support\Facades\DB::class;
        $svc = app(\App\Modules\Visio\VisioService::class);
        $admin = User::where('company_id', $company->id)->where('email', 'admin@demo.ci')->first() ?? User::where('company_id', $company->id)->orderBy('id')->first();
        $team = User::where('company_id', $company->id)->where('id', '!=', $admin->id)->where('is_active', true)->whereIn('email', ['nadia@demo.ci', 'ousmane@demo.ci', 'mariam.traore@demo.ci', 'caisse@demo.ci'])->get();
        $P = \App\Modules\Visio\Models\Participant::class;
        $mk = function (array $a) use ($admin) {
            $m = new \App\Modules\Visio\Models\Meeting;
            $m->fill($a + ['code' => \App\Modules\Visio\VisioService::code(), 'host_id' => $admin->id, 'options' => \App\Modules\Visio\VisioService::DEFAULTS, 'type' => 'meeting']);
            $m->save();

            return $m;
        };
        $feed = function ($m, $p, string $kind, string $body, $at, array $data = null) use ($DB) {
            $DB::table('vis_feed')->insert(['meeting_id' => $m->id, 'participant_id' => $p?->id, 'kind' => $kind, 'body' => $body, 'data' => $data ? json_encode($data) : null, 'created_at' => $at]);
        };

        // 1. Revue commerciale tenue il y a 3 jours : transcription, conversation, sondage, compte rendu « IA », actions en tâches
        $start = now()->subDays(3)->setTime(10, 0);
        $m = $mk(['title' => 'Revue commerciale mensuelle', 'description' => "1. Chiffre d'affaires du mois et objectifs\n2. Relances clients\n3. Promotion de fin d'année", 'starts_at' => $start, 'duration_minutes' => 45,
            'status' => 'ended', 'started_at' => $start->copy()->addMinutes(2), 'ended_at' => $start->copy()->addMinutes(41), 'provider' => 'zego', 'epoch' => 1, 'switches' => 1]);
        $host = $svc->participantFor($m, $admin);
        $people = [$host];
        foreach ($team->take(2) as $u) {
            $people[] = $svc->participantFor($m, $u);
        }
        $people[] = $P::create(['meeting_id' => $m->id, 'company_id' => $company->id, 'name' => 'Ibrahim Koné', 'email' => 'i.kone@hotel-ivoire.ci', 'organization' => 'Hôtel Ivoire', 'role' => 'guest',
            'token' => \App\Modules\Visio\VisioService::token(), 'status' => 'invited', 'invited_at' => $start->copy()->subDay()]);
        foreach ($people as $i => $p) {
            $p->forceFill(['status' => 'left', 'first_joined_at' => $start->copy()->addMinutes(2 + $i), 'joined_at' => $start->copy()->addMinutes(2 + $i), 'left_at' => $start->copy()->addMinutes(41 - ($i === 3 ? 12 : 0)),
                'seconds_total' => (39 - $i - ($i === 3 ? 12 : 0)) * 60, 'last_seen_at' => $start->copy()->addMinutes(41)])->save();
        }
        $names = array_map(fn ($p) => $p, $people);
        $lines = [
            [0, 'Bonjour à tous, merci d\'être là. On commence par le chiffre d\'affaires du mois.'],
            [0, 'Nous sommes à 92 % de l\'objectif, avec une belle progression sur les boissons et l\'épicerie fine.'],
            [1, 'Côté achats, les délais fournisseurs se sont améliorés, mais le riz parfumé reste en tension.'],
            [3, 'Pour l\'Hôtel Ivoire, nous aurons besoin de doubler les volumes pour les fêtes de décembre.'],
            [0, 'Très bien. Nous avons décidé de lancer la promotion de fin d\'année le 1er décembre, avec 10 % sur les coffrets.'],
            [2, 'Je vais préparer le réassort des coffrets et vérifier le stock du dépôt de Yopougon avant vendredi.'],
            [1, 'Je m\'occupe de négocier une livraison supplémentaire de riz avec le fournisseur.'],
            [0, 'Il faut aussi relancer les trois factures en retard de plus de 60 jours.'],
            [3, 'De notre côté, nous validons la commande prévisionnelle dès réception du devis.'],
            [0, 'Parfait, on envoie le devis révisé à l\'Hôtel Ivoire cette semaine. Merci à tous.'],
        ];
        foreach ($lines as $k => [$who, $text]) {
            $feed($m, $people[min($who, count($people) - 1)], 'caption', $text, $start->copy()->addMinutes(4 + $k * 3));
        }
        $feed($m, $people[min(3, count($people) - 1)], 'chat', 'Je partage le lien de notre planning des fêtes après la réunion.', $start->copy()->addMinutes(20));
        $feed($m, $people[1] ?? $host, 'chat', 'Merci ! Le fichier des relances est dans la GED, dossier Recouvrement.', $start->copy()->addMinutes(28));
        $feed($m, null, 'system', 'switch', $start->copy()->addMinutes(18), ['from' => 'agora', 'to' => 'zego', 'reason' => 'connexion instable', 'epoch' => 1]);
        $DB::table('vis_polls')->insert(['meeting_id' => $m->id, 'question' => 'Durée de la promotion de fin d\'année ?', 'options' => json_encode(['2 semaines', '3 semaines', 'Tout décembre']),
            'multiple' => false, 'status' => 'closed', 'votes' => json_encode(array_combine(array_map(fn ($p) => (string) $p->id, $people), array_map(fn ($i) => [$i % 3 === 0 ? 2 : 1], array_keys($people)))),
            'created_at' => $start->copy()->addMinutes(25), 'updated_at' => $start->copy()->addMinutes(27)]);
        $rid = $DB::table('vis_recordings')->insertGetId(['meeting_id' => $m->id, 'company_id' => $company->id, 'participant_id' => $host->id, 'path' => 'visio/demo/absent.webm', 'mime' => 'video/webm',
            'size' => 48_750_000, 'duration_seconds' => 2280, 'chunks' => 456, 'status' => 'ready', 'token' => \App\Modules\Visio\VisioService::token(), 'created_at' => $start->copy()->addMinutes(3), 'updated_at' => $start->copy()->addMinutes(41)]);
        app(\App\Modules\Visio\ReportService::class)->generate($company, $m, false);
        $DB::table('vis_reports')->where('meeting_id', $m->id)->update([
            'source' => 'ai', 'sent_at' => $start->copy()->addMinutes(43),
            'summary' => "Le chiffre d'affaires du mois atteint 92 % de l'objectif, porté par les boissons et l'épicerie fine. Les délais fournisseurs s'améliorent, mais le riz parfumé reste en tension. "
                ."L'Hôtel Ivoire prévoit de doubler ses volumes pour les fêtes de décembre et validera sa commande prévisionnelle à réception du devis révisé. "
                .'La promotion de fin d\'année démarrera le 1er décembre (10 % sur les coffrets) ; le sondage penche pour une durée de trois semaines. Trois factures de plus de 60 jours doivent être relancées.',
            'decisions' => json_encode(['Lancer la promotion de fin d\'année le 1er décembre : 10 % sur les coffrets', 'Envoyer le devis révisé à l\'Hôtel Ivoire cette semaine'], JSON_UNESCAPED_UNICODE),
            'actions' => json_encode([
                ['title' => 'Préparer le réassort des coffrets et vérifier le stock du dépôt de Yopougon', 'owner' => $people[2]->name ?? null, 'due' => now()->addDays(2)->toDateString()],
                ['title' => 'Négocier une livraison supplémentaire de riz parfumé', 'owner' => $people[1]->name ?? null, 'due' => null],
                ['title' => 'Relancer les trois factures en retard de plus de 60 jours', 'owner' => $admin->name, 'due' => now()->addDay()->toDateString()],
                ['title' => 'Envoyer le devis révisé à l\'Hôtel Ivoire', 'owner' => $admin->name, 'due' => now()->addDays(1)->toDateString()],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        // 2. Réunions à venir
        $w = $mk(['title' => 'Point hebdo équipe', 'description' => 'Tour de table, priorités de la semaine, points bloquants.', 'starts_at' => now()->addDay()->setTime(9, 30), 'duration_minutes' => 30]);
        $svc->syncInvitees($w, $team->pluck('id')->all(), []);
        $svc->syncAgenda($company, $w);
        $x = $mk(['title' => 'Présentation de l\'offre à la SIB', 'description' => 'Démonstration et proposition tarifaire pour les agences.', 'starts_at' => now()->addDays(3)->setTime(15, 0), 'duration_minutes' => 60,
            'options' => array_merge(\App\Modules\Visio\VisioService::DEFAULTS, ['lobby' => true, 'record_auto' => true])]);
        $svc->syncInvitees($x, $team->take(1)->pluck('id')->all(), [['name' => 'Awa Bamba', 'email' => 'a.bamba@sib.ci', 'organization' => 'SIB'], ['name' => 'Serge Aka', 'email' => 's.aka@sib.ci', 'organization' => 'SIB']]);
        $svc->syncAgenda($company, $x);

        // 3. Webinaire à venir avec inscrits
        $web = $mk(['type' => 'webinar', 'title' => 'Facture normalisée (FNE) : ce qui change pour les PME', 'slug' => 'facture-normalisee-fne-pme', 'color' => '#7C3AED',
            'description' => "Obligations, calendrier et mise en conformité pas à pas.\nDémonstration en direct : émettre une facture certifiée en 30 secondes depuis Yomara.\nQuestions-réponses avec un expert-comptable.",
            'starts_at' => now()->addDays(6)->setTime(11, 0), 'duration_minutes' => 60, 'capacity' => 150, 'create_leads' => true,
            'options' => array_merge(\App\Modules\Visio\VisioService::DEFAULTS, ['lobby' => false, 'guests' => false, 'record_auto' => true])]);
        $svc->syncInvitees($web, [], [['name' => 'Me Clarisse N\'Guessan', 'email' => 'c.nguessan@cabinet-expert.ci', 'organization' => 'Cabinet N\'Guessan & Associés']]);
        $first = ['Fatou', 'Koffi', 'Aminata', 'Yao', 'Mariam', 'Ibrahim', 'Adjoua', 'Sékou', 'Awa', 'Moussa', 'Christelle', 'Kouamé', 'Salimata', 'Didier', 'Rokia', 'Hervé', 'Nadège', 'Bakary'];
        $last = ['Diallo', 'Kouassi', 'Traoré', 'Koné', 'Ouattara', 'Bamba', 'Yao', 'Coulibaly', 'N\'Dri', 'Touré', 'Aka', 'Kra', 'Cissé', 'Doumbia'];
        $orgs = ['Boutique Le Palmier', 'Pharmacie des Deux Plateaux', 'SARL Ivoire Distribution', 'Quincaillerie du Nord', 'Cabinet Kra', 'Maquis Chez Tantie', 'Imprimerie Moderne', 'Garage Central', null];
        $src = ['facebook', 'whatsapp', 'linkedin', 'email', null];
        for ($i = 0; $i < 37; $i++) {
            $fn = $first[$i % count($first)];
            $ln = $last[($i * 7) % count($last)];
            $P::create(['meeting_id' => $web->id, 'company_id' => $company->id, 'name' => "$fn $ln", 'email' => strtolower(\Illuminate\Support\Str::ascii($fn.'.'.$ln)).$i.'@exemple.ci',
                'phone' => '+225 07 '.str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT).' '.mt_rand(10, 99).' '.mt_rand(10, 99).' '.mt_rand(10, 99), 'organization' => $orgs[$i % count($orgs)],
                'role' => 'attendee', 'token' => \App\Modules\Visio\VisioService::token(), 'status' => 'registered', 'source' => $src[$i % count($src)], 'created_at' => now()->subDays(mt_rand(0, 8))->subHours(mt_rand(0, 20))]);
        }
        $svc->participantFor($web, $admin);
        $svc->syncAgenda($company, $web);

        // 4. Webinaire passé : présence, questions votées, compte rendu
        $ws = now()->subDays(20)->setTime(11, 0);
        $old = $mk(['type' => 'webinar', 'title' => 'Bien démarrer avec la caisse et le stock', 'slug' => 'demarrer-caisse-stock', 'starts_at' => $ws, 'duration_minutes' => 60, 'status' => 'ended',
            'started_at' => $ws->copy()->addMinutes(1), 'ended_at' => $ws->copy()->addMinutes(58), 'provider' => 'agora', 'description' => 'Paramétrer ses articles, vendre au comptoir, suivre son stock.']);
        $oh = $svc->participantFor($old, $admin);
        $oh->forceFill(['first_joined_at' => $ws, 'left_at' => $ws->copy()->addMinutes(58), 'seconds_total' => 3420, 'status' => 'left'])->save();
        $att = [];
        for ($i = 0; $i < 24; $i++) {
            $here = $i % 5 !== 4;
            $att[] = $P::create(['meeting_id' => $old->id, 'company_id' => $company->id, 'name' => $first[($i + 3) % count($first)].' '.$last[($i * 5) % count($last)],
                'email' => 'inscrit'.$i.'@exemple.ci', 'organization' => $orgs[($i + 2) % count($orgs)], 'role' => 'attendee', 'token' => \App\Modules\Visio\VisioService::token(),
                'status' => $here ? 'left' : 'registered', 'source' => $src[$i % count($src)], 'first_joined_at' => $here ? $ws->copy()->addMinutes($i % 7) : null,
                'left_at' => $here ? $ws->copy()->addMinutes(40 + $i % 18) : null, 'seconds_total' => $here ? (38 + $i % 18) * 60 : 0, 'created_at' => $ws->copy()->subDays(1 + $i % 6)]);
        }
        foreach ([['Peut-on gérer plusieurs dépôts avec des prix différents ?', 9, 'Oui : chaque dépôt a son stock et vous pouvez définir des listes de prix par agence.'],
            ['La caisse fonctionne-t-elle sans internet ?', 14, 'Oui, entièrement hors ligne ; la synchronisation se fait au retour de la connexion.'],
            ['Comment imprimer les étiquettes code-barres ?', 5, null]] as $k => [$q, $v, $a]) {
            $DB::table('vis_questions')->insert(['meeting_id' => $old->id, 'participant_id' => $att[$k * 3]->id, 'body' => $q, 'votes' => $v, 'voters' => json_encode([]), 'answer' => $a,
                'status' => $a ? 'answered' : 'open', 'anonymous' => false, 'created_at' => $ws->copy()->addMinutes(20 + $k * 8), 'updated_at' => $ws->copy()->addMinutes(30 + $k * 8)]);
        }
        $DB::table('vis_polls')->insert(['meeting_id' => $old->id, 'question' => 'Utilisez-vous déjà un logiciel de caisse ?', 'options' => json_encode(['Oui', 'Non, un cahier', 'Non, Excel']), 'multiple' => false,
            'status' => 'closed', 'votes' => json_encode(collect($att)->filter(fn ($p) => $p->first_joined_at)->mapWithKeys(fn ($p, $i) => [(string) $p->id => [$i % 3]])->all()), 'created_at' => $ws->copy()->addMinutes(10), 'updated_at' => $ws->copy()->addMinutes(12)]);
        app(\App\Modules\Visio\ReportService::class)->generate($company, $old, false);

        // Consommation du mois et une bascule automatique
        foreach (['zego' => 74_400, 'agora' => 5_100] as $prov => $sec) {
            $DB::table('vis_usage')->insert(['company_id' => $company->id, 'provider' => $prov, 'month' => now()->format('Y-m'), 'seconds' => $sec, 'switches' => $prov === 'zego' ? 1 : 0, 'created_at' => now(), 'updated_at' => now()]);
        }
        $DB::table('vis_settings')->insert(['company_id' => $company->id, 'priority' => 'zego,agora,p2p', 'zego_budget_minutes' => 10000, 'agora_budget_minutes' => 10000, 'p2p_fallback' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Historique de l'année précédente (factures soldées par agence) : comparaisons N / N-1 crédibles sur le tableau de bord analytique. */
    protected function historiqueAnneePrecedente(Company $company): void
    {
        $company = $company->fresh();
        app()->instance('tenant', $company);
        app()->instance('branch_scope', null);
        app()->instance('branch_current', null);
        $admin = User::where('company_id', $company->id)->where('email', 'admin@demo.ci')->first();
        auth()->setUser($admin);
        $w = app(\App\Services\EntityWriter::class);
        $DB = \Illuminate\Support\Facades\DB::class;
        $customers = $DB::table('partners')->where('company_id', $company->id)->whereIn('type', ['customer', 'both'])->whereNull('deleted_at')->orderBy('name')->limit(8)->pluck('id')->all();
        $codes = $DB::table('branches')->where('company_id', $company->id)->pluck('code', 'id');
        mt_srand(2025);
        for ($i = 11; $i >= 0; $i--) {
            $m = now()->startOfMonth()->subMonthsNoOverflow($i);
            $to = $i === 0 ? now() : $m->copy()->endOfMonth();
            $rows = $DB::table('trade_documents')->where('company_id', $company->id)->where('direction', 'sale')->where('status', 'validated')->whereIn('type', ['invoice', 'receipt'])
                ->whereNull('deleted_at')->whereBetween('date', [$m->toDateString(), $to->toDateString()])->groupBy('branch_id')->selectRaw('branch_id, SUM(subtotal) v')->get();
            foreach ($rows as $r) {
                // Siège en progression, Marcory en forte hausse, Yopougon en léger recul
                $f = match ($codes[$r->branch_id] ?? null) { 'MRC' => .62, 'YOP' => 1.07, default => .78 } * (mt_rand(88, 112) / 100);
                $target = round((float) $r->v * $f, -3);
                if ($target < 50000) {
                    continue;
                }
                $day = $m->copy()->subYear()->addDays($i === 0 ? max(0, min(9, now()->day - 2)) : mt_rand(3, 24));
                $parts = $target > 4000000 ? 3 : 1;
                for ($k = 0; $k < $parts; $k++) {
                    $amount = round($target / $parts, -2);
                    $id = (string) \Illuminate\Support\Str::uuid();
                    $res = $w->upsert(\App\Modules\Ventes\Models\SalesDocument::class, $id, ['type' => 'invoice', 'partner_id' => $customers ? $customers[($i + $k) % count($customers)] : null,
                        'date' => $day->toDateString(), 'due_date' => $day->copy()->addDays(30)->toDateString(), 'status' => 'validated', 'branch_id' => $r->branch_id,
                        'lines' => [['id' => (string) \Illuminate\Support\Str::uuid(), 'description' => 'Ventes '.$day->translatedFormat('F Y').' (historique)', 'quantity' => 1, 'unit_price' => $amount, 'tax_rate' => 0, 'discount_percent' => 0]]]);
                    if ($res['status'] === \App\Services\EntityWriter::APPLIED) {
                        $w->upsert(\App\Modules\Ventes\Models\SalesPayment::class, (string) \Illuminate\Support\Str::uuid(), ['document_id' => $id, 'date' => $day->copy()->addDays(12)->toDateString(), 'amount' => $amount, 'method' => 'bank_transfer']);
                    }
                }
            }
        }
        // Charges de l'année précédente : dépenses de trésorerie et factures fournisseurs, un peu en dessous de cette année
        $since = now()->startOfMonth()->subMonthsNoOverflow(11)->toDateString();
        foreach ($DB::table('tr_operations')->where('company_id', $company->id)->whereNull('deleted_at')->whereNull('transfer_id')->where('amount', '<', 0)->where('date', '>=', $since)->get() as $o) {
            $w->upsert(\App\Modules\Tresorerie\Models\Operation::class, (string) \Illuminate\Support\Str::uuid(), ['account_id' => $o->account_id, 'date' => \Carbon\Carbon::parse($o->date)->subYear()->toDateString(),
                'amount' => round((float) $o->amount * mt_rand(80, 95) / 100, -2), 'label' => $o->label, 'category' => $o->category, 'branch_id' => $o->branch_id ?? null]);
        }
        $suppliers = $DB::table('partners')->where('company_id', $company->id)->whereIn('type', ['supplier', 'both'])->whereNull('deleted_at')->orderBy('name')->limit(5)->pluck('id')->all();
        if ($suppliers && $company->hasModule('achats')) {
            $buys = $DB::table('trade_documents')->where('company_id', $company->id)->where('direction', 'purchase')->where('type', 'supplier_invoice')->where('status', 'validated')
                ->whereNull('deleted_at')->where('date', '>=', $since)->selectRaw("substr(date, 1, 7) m, SUM(subtotal) v")->groupByRaw('substr(date, 1, 7)')->get();
            foreach ($buys as $k => $b) {
                $day = \Carbon\Carbon::parse($b->m.'-12')->subYear();
                $amount = round((float) $b->v * mt_rand(75, 92) / 100, -3);
                if ($amount < 10000) {
                    continue;
                }
                $id = (string) \Illuminate\Support\Str::uuid();
                $res = $w->upsert(\App\Modules\Achats\Models\PurchaseDocument::class, $id, ['type' => 'supplier_invoice', 'partner_id' => $suppliers[$k % count($suppliers)], 'date' => $day->toDateString(),
                    'due_date' => $day->copy()->addDays(30)->toDateString(), 'status' => 'validated', 'reference' => 'HIST-'.$day->format('Ym'),
                    'lines' => [['id' => (string) \Illuminate\Support\Str::uuid(), 'description' => 'Achats '.$day->translatedFormat('F Y').' (historique)', 'quantity' => 1, 'unit_price' => $amount, 'tax_rate' => 0, 'discount_percent' => 0]]]);
                if ($res['status'] === \App\Services\EntityWriter::APPLIED) {
                    rescue(fn () => $w->upsert(\App\Modules\Achats\Models\PurchasePayment::class, (string) \Illuminate\Support\Str::uuid(), ['document_id' => $id, 'date' => $day->copy()->addDays(20)->toDateString(), 'amount' => $amount, 'method' => 'bank_transfer']), report: false);
                }
            }
        }
        // « Ma journée » : tâches personnelles de l'administrateur
        if (\Illuminate\Support\Facades\Schema::hasTable('user_todos')) {
            foreach ([['Valider les bons de commande de la semaine', 0, true], ['Appeler Hôtel Ivoire pour la facture en retard', 0, false], ['Préparer la réunion commerciale de lundi', 2, false],
                ['Envoyer le relevé de gérance aux propriétaires', 1, true], ['Renouveler l\'assurance du véhicule de livraison', 5, false]] as $k => [$title, $days, $imp]) {
                $DB::table('user_todos')->insert(['company_id' => $company->id, 'user_id' => $admin->id, 'title' => $title, 'due_date' => now()->addDays($days)->toDateString(),
                    'important' => $imp, 'my_day' => true, 'sort' => $k, 'created_at' => now(), 'updated_at' => now()]);
            }
            $DB::table('user_todos')->insert(['company_id' => $company->id, 'user_id' => $admin->id, 'title' => 'Faire le point de caisse', 'done_at' => now()->subHours(2), 'created_at' => now(), 'updated_at' => now()]);
        }
        // Migration déjà réalisée depuis l'ancien logiciel (historique affiché dans « Migration »)
        if (\Illuminate\Support\Facades\Schema::hasTable('mig_jobs')) {
            \App\Modules\Migration\Models\MigrationJob::create(['source' => 'file', 'vendor' => 'sage', 'label' => 'Sage 100 / Saari / Sage 50', 'status' => 'done', 'user_id' => $admin->id,
                'options' => ['what' => ['partners', 'products', 'stock', 'sales']], 'started_at' => now()->subMonths(10)->setTime(9, 12), 'finished_at' => now()->subMonths(10)->setTime(9, 14),
                'counts' => ['partners' => ['created' => 46, 'updated' => 3], 'products' => ['created' => 128], 'stock' => ['updated' => 97], 'sales' => ['created' => 312, 'skipped' => 4]],
                'warnings' => ['Facture FA00318 : total recalculé 118 001,00 au lieu de 118 000,00 (arrondis ou taxes différentes).'], 'progress' => ['stage' => 'done', 'done' => 0]]);
        }
        // Tableaux de bord Excel enregistrés (envoi du lundi et du 1er du mois)
        if ($company->hasModule('tableaux_excel') && \Illuminate\Support\Facades\Schema::hasTable('xl_workbooks')) {
            foreach ([['Synthèse direction du lundi', 'direction', 'ocean', 'weekly', ['dg@demo.ci']], ['Créances à relancer', 'creances', 'corail', 'monthly', ['compta@demo.ci', 'dg@demo.ci']],
                ['Stock & rotation', 'stock', 'nuit', 'none', []], ['Analyse des ventes', 'ventes', 'emeraude', 'none', []]] as [$n, $t, $th, $f, $to]) {
                \App\Modules\TableauxExcel\Models\Workbook::create(['name' => $n, 'template' => $t, 'theme' => $th, 'frequency' => $f, 'recipients' => $to, 'owner_id' => $admin->id,
                    'downloads' => mt_rand(2, 14), 'last_generated_at' => now()->subDays(mt_rand(1, 6)), 'last_sent_at' => $f === 'weekly' ? now()->startOfWeek()->setTime(7, 10) : null, 'last_status' => $f === 'weekly' ? 'sent' : null]);
            }
        }
        auth()->forgetUser();
        app()->forgetInstance('tenant');
    }

    /** BTP : un chantier en cours (bordereau, avance, 2 situations, achats, journal), une étude avec devis, un chantier réceptionné avec retenue à libérer. */
    protected function chantiersBtp(Company $company): void
    {
        $company = $company->fresh();
        if (! $company->hasModule('btp')) {
            return;
        }
        app()->instance('tenant', $company);
        app()->instance('branch_scope', null);
        app()->instance('branch_current', null);
        $admin = User::where('company_id', $company->id)->where('email', 'admin@demo.ci')->first();
        auth()->setUser($admin);
        $svc = app(\App\Modules\Btp\BtpService::class);
        $item = fn (string $lot, string $code, string $des, string $unit, float $q, float $cost, float $k = 1.4) => ['lot' => $lot, 'code' => $code, 'designation' => $des, 'unit' => $unit, 'quantity' => $q, 'unit_cost' => $cost, 'unit_price' => round($cost * $k / 50) * 50];
        $villa = [
            $item('Terrassement', '1.1', 'Décapage et nivellement de la plateforme', 'm2', 320, 900), $item('Terrassement', '1.2', 'Fouilles en rigole pour semelles', 'm3', 85, 4500),
            $item('Gros œuvre', '2.1', 'Béton de propreté dosé à 150 kg', 'm3', 9, 48000), $item('Gros œuvre', '2.2', 'Béton armé pour semelles et longrines', 'm3', 32, 95000),
            $item('Gros œuvre', '2.3', 'Maçonnerie d\'agglos creux de 15', 'm2', 640, 8200), $item('Gros œuvre', '2.4', 'Dalle pleine en béton armé (ép. 16 cm)', 'm2', 210, 26000),
            $item('Charpente & couverture', '3.1', 'Charpente métallique et couverture bac alu', 'm2', 230, 21000),
            $item('Plomberie', '4.1', 'Plomberie sanitaire complète (3 salles d\'eau, cuisine)', 'ff', 1, 2600000, 1.35),
            $item('Électricité', '5.1', 'Électricité courants fort et faible', 'ff', 1, 3100000, 1.35),
            $item('Finitions', '6.1', 'Carrelage grès cérame 60x60', 'm2', 280, 11500), $item('Finitions', '6.2', 'Peinture intérieure et extérieure', 'm2', 1250, 2300),
        ];
        $s = $svc->saveSite(null, ['name' => 'Villa duplex Bingerville', 'client_name' => 'M. Aka Jean-Baptiste', 'client_phone' => '+225 07 07 08 09 10', 'address' => 'Cité Ebrié, lot 214',
            'city' => 'Bingerville', 'advance_rate' => 15, 'retention_rate' => 5, 'start_date' => now()->subMonths(4)->toDateString(), 'end_date' => now()->addMonths(5)->toDateString()]);
        $svc->saveItems($s, $villa);
        $svc->quote($s->fresh());
        $svc->accept($s->fresh(), ['reference' => 'CT-2026-031', 'start_date' => now()->subMonths(4)->startOfMonth()->toDateString()]);
        $svc->advance($s->fresh());
        $items = \App\Modules\Btp\Models\Item::where('site_id', $s->id)->get()->keyBy('code');
        $pg = fn (array $p) => collect($p)->mapWithKeys(fn ($v, $code) => [$items[$code]->id => $items[$code]->quantity * $v])->all();
        rescue(fn () => $svc->createSituation($s->fresh(), $pg(['1.1' => 1, '1.2' => 1, '2.1' => 1, '2.2' => 1, '2.3' => .35]), now()->subMonths(2)->endOfMonth()->toDateString()), null, false);
        rescue(fn () => $svc->createSituation($s->fresh(), $pg(['2.3' => 1, '2.4' => 1, '3.1' => .6, '4.1' => .3, '5.1' => .25]), now()->subMonth()->endOfMonth()->toDateString()), null, false);
        // Paiement de l'avance et de la 1re situation
        foreach ([$s->fresh()->advance_invoice_id, \App\Modules\Btp\Models\Situation::where('site_id', $s->id)->where('number', 1)->value('invoice_id')] as $k => $invId) {
            $inv = $invId ? \App\Modules\Ventes\Models\SalesDocument::find($invId) : null;
            if ($inv) {
                rescue(fn () => app(\App\Services\EntityWriter::class)->upsert(\App\Modules\Ventes\Models\SalesPayment::class, (string) \Illuminate\Support\Str::uuid(),
                    ['document_id' => $inv->id, 'date' => $inv->date->copy()->addDays(12)->toDateString(), 'amount' => $inv->balance(), 'method' => 'bank_transfer', 'reference' => 'VIR-AKA-'.($k + 1)]), null, false);
            }
        }
        // Achats du chantier (factures fournisseurs rattachées au projet)
        $sup = fn (string $name) => Partner::firstOrCreate(['company_id' => $company->id, 'name' => $name], ['type' => 'supplier', 'is_company' => true]);
        foreach ([
            ['Cimenterie du Sud', 75, [['Ciment CPJ 45 — sacs de 50 kg', 820, 5200], ['Fer à béton HA 10 et 12', 3.2, 640000]]],
            ['Briqueterie d\'Anyama', 50, [['Agglos creux de 15', 7200, 375]]],
            ['Sable & Gravier Bingerville', 40, [['Sable lagunaire (camion 15 m³)', 12, 85000], ['Gravier 5/15 (camion 15 m³)', 8, 160000]]],
            ['Métal Toiture CI', 20, [['Charpente métallique et tôles bac alu', 1, 3150000]]],
        ] as [$name, $ago, $lines]) {
            $sid = $sup($name)->id;
            rescue(fn () => app(\App\Services\EntityWriter::class)->upsert(\App\Modules\Achats\Models\PurchaseDocument::class, (string) \Illuminate\Support\Str::uuid(), [
                'type' => 'supplier_invoice', 'partner_id' => $sid, 'date' => now()->subDays($ago)->toDateString(), 'due_date' => now()->subDays($ago - 30)->toDateString(), 'status' => 'validated',
                'reference' => 'F-'.(4100 + $ago), 'project_id' => $s->project_id,
                'lines' => array_map(fn ($l) => ['id' => (string) \Illuminate\Support\Str::uuid(), 'description' => $l[0], 'quantity' => $l[1], 'unit_price' => $l[2], 'tax_rate' => 18, 'discount_percent' => 0], $lines),
            ]), null, false);
        }
        foreach ([
            [26, 'soleil', 14, 'Fouilles en rigole terminées, ferraillage des semelles.', null], [19, 'pluie', 9, 'Coulage des semelles et longrines.', 'Pluie l\'après-midi : coulage reporté au lendemain matin.'],
            [12, 'soleil', 16, 'Élévation des murs du RDC (agglos de 15), 60 % réalisés.', 'Livraison de 3 600 agglos.'], [6, 'nuageux', 15, 'Coffrage et ferraillage de la dalle haute du RDC.', null],
            [2, 'harmattan', 12, 'Pose de la charpente métallique côté nord.', 'Visite du maître d\'ouvrage : demande une fenêtre supplémentaire au salon (avenant à chiffrer).'],
        ] as [$ago, $w, $n, $done, $inc]) {
            \App\Modules\Btp\Models\Log::create(['site_id' => $s->id, 'date' => now()->subDays($ago)->toDateString(), 'weather' => $w, 'workers' => $n, 'work_done' => $done, 'incidents' => $inc, 'user_id' => $admin->id]);
        }

        // Étude en cours : réhabilitation de bureaux
        $b = $svc->saveSite(null, ['name' => 'Réhabilitation des bureaux — 4e étage', 'client_name' => 'Groupe Ivoire Assurances', 'client_phone' => '+225 27 20 25 30 00', 'address' => 'Plateau, boulevard de la République',
            'city' => 'Abidjan', 'advance_rate' => 20, 'retention_rate' => 5]);
        $svc->saveItems($b, [
            $item('Démolition', '1.1', 'Dépose des cloisons et faux plafonds existants', 'm2', 380, 2500), $item('Cloisons & plafonds', '2.1', 'Cloisons en plaques de plâtre', 'm2', 260, 14500),
            $item('Cloisons & plafonds', '2.2', 'Faux plafond en dalles 60x60', 'm2', 410, 9800), $item('Électricité', '3.1', 'Réseau électrique, prises et luminaires LED', 'ff', 1, 6800000, 1.3),
            $item('Climatisation', '4.1', 'Climatiseurs split 24 000 BTU posés', 'u', 14, 520000, 1.25), $item('Revêtements', '5.1', 'Sol PVC en lames', 'm2', 410, 12000),
        ]);
        $svc->quote($b->fresh());

        // Chantier réceptionné : retenue de garantie à libérer
        $c = $svc->saveSite(null, ['name' => 'Clôture et guérite — usine de Yopougon', 'client_name' => 'Agro-Industries du Banco', 'address' => 'Zone industrielle de Yopougon', 'city' => 'Abidjan',
            'retention_rate' => 5, 'start_date' => now()->subMonths(9)->toDateString()]);
        $svc->saveItems($c, [
            $item('Clôture', '1.1', 'Mur de clôture en agglos (h. 2,50 m)', 'ml', 420, 38000), $item('Clôture', '1.2', 'Portail coulissant motorisé', 'u', 2, 2400000, 1.3),
            $item('Guérite', '2.1', 'Guérite de gardiennage (12 m²) clé en main', 'ff', 1, 5200000, 1.3),
        ]);
        $svc->accept($c->fresh(), ['reference' => 'BC-ADB-0419', 'start_date' => now()->subMonths(9)->toDateString()]);
        $ci = \App\Modules\Btp\Models\Item::where('site_id', $c->id)->get()->keyBy('code');
        rescue(fn () => $svc->createSituation($c->fresh(), [$ci['1.1']->id => 420, $ci['1.2']->id => 1], now()->subMonths(6)->endOfMonth()->toDateString()), null, false);
        rescue(fn () => $svc->createSituation($c->fresh(), [$ci['1.2']->id => 2, $ci['2.1']->id => 1], now()->subMonths(4)->endOfMonth()->toDateString()), null, false);
        $svc->receive($c->fresh(), now()->subMonths(3)->toDateString());
        foreach (\App\Modules\Btp\Models\Situation::where('site_id', $c->id)->get() as $x) {
            $inv = \App\Modules\Ventes\Models\SalesDocument::find($x->invoice_id);
            rescue(fn () => app(\App\Services\EntityWriter::class)->upsert(\App\Modules\Ventes\Models\SalesPayment::class, (string) \Illuminate\Support\Str::uuid(),
                ['document_id' => $inv->id, 'date' => $inv->date->copy()->addDays(20)->toDateString(), 'amount' => $inv->balance(), 'method' => 'bank_transfer']), null, false);
        }
        auth()->logout();
    }

    /** Gestion locative : 4 biens (2 en gérance pour des propriétaires), baux en cours avec historique des loyers, quelques retards, dépenses, un reversement. */
    protected function gestionLocative(Company $company): void
    {
        $company = $company->fresh();
        if (! $company->hasModule('immobilier')) {
            return;
        }
        app()->instance('tenant', $company);
        app()->instance('branch_scope', null);
        app()->instance('branch_current', null);
        $svc = app(\App\Modules\Immobilier\ImmobilierService::class);
        $today = now()->startOfDay();
        $m = fn (int $ago, int $day = 1) => $today->copy()->startOfMonth()->subMonthsNoOverflow($ago)->day($day)->toDateString();
        $props = [
            ['Résidence Les Palmiers', 'immeuble', 'Cocody Angré 8e tranche', ['M. Kouassi Bernard', '+225 07 07 11 22 33'], 8, [
                ['A1', 'appartement', 3, 95, 180000, 15000], ['A2', 'appartement', 2, 70, 130000, 10000], ['A3', 'studio', 1, 32, 75000, 5000],
                ['B1', 'appartement', 4, 120, 250000, 20000], ['B2', 'studio', 1, 30, 75000, 5000], ['Magasin RDC', 'magasin', null, 45, 200000, 0]]],
            ['Villa Riviera Palmeraie', 'villa', 'Riviera Palmeraie', ['Mme Diabaté Fanta', '+225 05 05 44 55 66'], 10, [['Villa', 'villa', 5, 260, 450000, 0]]],
            ['Cour commune Sideci', 'cour_commune', 'Yopougon Sideci', null, 0, [
                ['Chambre 1', 'chambre', 1, 16, 35000, 0], ['Chambre 2', 'chambre', 1, 16, 35000, 0], ['Chambre 3', 'chambre', 1, 18, 40000, 0], ['Chambre 4', 'chambre', 1, 18, 40000, 0]]],
            ['Immeuble Plateau — Bureaux', 'bureaux', 'Plateau, avenue Chardy', null, 0, [['Bureau 1', 'bureau', 4, 110, 600000, 50000], ['Bureau 2', 'bureau', 3, 85, 450000, 40000]]],
        ];
        $units = [];
        foreach ($props as [$name, $kind, $district, $owner, $fee, $lots]) {
            $p = $svc->saveProperty(null, ['name' => $name, 'kind' => $kind, 'district' => $district, 'city' => 'Abidjan', 'fee_rate' => $fee]
                + ($owner ? ['owner_name' => $owner[0], 'owner_phone' => $owner[1]] : []));
            foreach ($lots as [$ref, $uk, $rooms, $surface, $rent, $charges]) {
                $units[$ref] = $svc->saveUnit(null, ['property_id' => $p->id, 'reference' => $ref, 'kind' => $uk, 'rooms' => $rooms, 'surface' => $surface, 'rent' => $rent, 'charges' => $charges]);
            }
        }
        $units['Chambre 4']->forceFill(['status' => 'works'])->save();
        // [lot, locataire, téléphone, début, mois payés d'avance, caution, périodicité, usage, TVA, révision, mois impayés à la fin, garant]
        $leases = [
            ['A1', 'Koné Aminata', '+225 05 05 66 77 88', $m(14, 15), 2, 360000, 1, 'habitation', 0, 5, 0, 'Koné Drissa'],
            ['A2', 'Yao Kouamé Serge', '+225 07 48 59 60 71', $m(9), 2, 260000, 1, 'habitation', 0, 0, 0, null],
            ['A3', 'Traoré Mariam', '+225 01 02 03 04 05', $m(7, 10), 2, 150000, 1, 'habitation', 0, 0, 2, null],
            ['B1', 'Famille N\'Guessan', '+225 07 77 88 99 00', $m(20), 3, 500000, 1, 'habitation', 0, 5, 1, null],
            ['Magasin RDC', 'Pharmacie du Carrefour', '+225 27 22 44 55 66', $m(11), 3, 600000, 3, 'professionnel', 0, 0, 0, null],
            ['Villa', 'M. et Mme Bamba', '+225 05 66 77 88 99', $m(5), 3, 900000, 1, 'habitation', 0, 0, 0, null],
            ['Chambre 1', 'Ouattara Ibrahim', '+225 07 12 34 56 78', $m(10), 2, 70000, 1, 'habitation', 0, 0, 0, null],
            ['Chambre 2', 'Gnagne Yves', '+225 05 87 65 43 21', $m(6), 2, 70000, 1, 'habitation', 0, 0, 1, null],
            ['Chambre 3', 'Kra Linda', '+225 01 55 66 77 88', $m(3), 2, 80000, 1, 'habitation', 0, 0, 0, null],
            ['Bureau 1', 'Cabinet Akwaba Conseil SARL', '+225 27 20 30 40 50', $m(16), 3, 1800000, 3, 'professionnel', 18, 5, 0, null],
        ];
        foreach ($leases as [$ref, $tenant, $phone, $start, $adv, $dep, $months, $usage, $tva, $rev, $late, $gar]) {
            $l = rescue(fn () => $svc->createLease(['unit_id' => $units[$ref]->id, 'tenant_name' => $tenant, 'tenant_phone' => $phone, 'start_date' => $start,
                'rent' => $units[$ref]->rent, 'charges' => $units[$ref]->charges, 'months' => $months, 'usage' => $usage, 'tax_rate' => $tva, 'deposit_amount' => $dep,
                'advance_months' => $adv, 'revision_rate' => $rev, 'due_day' => 5, 'guarantor_name' => $gar, 'entry_payment' => ['method' => $usage === 'professionnel' ? 'bank_transfer' : 'cash']]), null, false);
            if (! $l) {
                continue;
            }
            $svc->runDue($company);
            $rents = \App\Modules\Immobilier\Models\Rent::where('lease_id', $l->id)->orderBy('period_start')->get();
            $due = $rents->filter(fn ($r) => $r->due_date->lte($today));
            $unpaid = $late ? $due->slice(-$late)->pluck('id')->all() : [];
            foreach ($rents as $i => $r) {
                if ($r->due_date->gt($today->copy()->addDays(3)) || (in_array($r->id, $unpaid, true) && $ref !== 'Chambre 2')) {
                    continue;
                }
                $api = $svc->rentToApi($r);
                if ($api['balance'] <= 0) {
                    continue;
                }
                $date = $r->due_date->copy()->subDays(($i * 7) % 6)->addDays($i % 4 === 3 ? 9 : 0);
                if ($date->gt($today)) {
                    $date = $today->copy();
                }
                $partial = $ref === 'Chambre 2' && in_array($r->id, $unpaid, true);
                rescue(fn () => $svc->pay($r, ['amount' => $partial ? 20000 : null, 'method' => $i % 3 === 0 ? 'mobile_money' : ($usage === 'professionnel' ? 'bank_transfer' : 'cash'),
                    'reference' => $i % 3 === 0 ? 'OM-'.(480000 + $r->id * 13) : null, 'date' => $date->toDateString()]), null, false);
            }
        }
        $byRef = fn (string $ref) => $units[$ref];
        foreach ([
            ['A3', 'Réparation de la chasse d\'eau', 'travaux', 18000, 1, 'Plomberie Express'], [null, 'Gardiennage de la résidence', 'syndic', 60000, 1, 'Sécurité Plus', 'Résidence Les Palmiers'],
            [null, 'Peinture de la cage d\'escalier', 'travaux', 145000, 2, 'Déco Bâtiment', 'Résidence Les Palmiers'], ['Villa', 'Entretien de la piscine', 'entretien', 40000, 1, 'Aqua Services'],
            [null, 'Impôt foncier (acompte)', 'taxe', 120000, 3, null, 'Immeuble Plateau — Bureaux'], ['Chambre 4', 'Réfection du sol et de l\'électricité', 'travaux', 95000, 0, 'Électro Bâtiment'],
        ] as $x) {
            $u = $x[0] ? $byRef($x[0]) : null;
            $pid = $u?->property_id ?? \App\Modules\Immobilier\Models\Property::where('name', $x[6])->value('id');
            \App\Modules\Immobilier\Models\Expense::create(['property_id' => $pid, 'unit_id' => $u?->id, 'date' => $m($x[4], 12), 'label' => $x[1], 'category' => $x[2], 'amount' => $x[3],
                'owner_charge' => true, 'supplier' => $x[5]]);
        }
        $kouassi = \App\Modules\Immobilier\Models\Property::where('name', 'Résidence Les Palmiers')->value('owner_id');
        foreach ([2, 1] as $ago) {
            rescue(fn () => $svc->savePayout(['owner_id' => $kouassi, 'period_from' => $m($ago), 'period_to' => $today->copy()->startOfMonth()->subMonthsNoOverflow($ago)->endOfMonth()->toDateString(),
                'paid_at' => $m($ago - 1, 8), 'method' => 'bank_transfer', 'reference' => 'VIR-'.$today->copy()->subMonthsNoOverflow($ago)->format('my')]), null, false);
        }
    }

    /** Boutique en ligne : commandes WhatsApp, Facebook, Instagram et téléphone à toutes les étapes (préparation, expédition avec livreur, retrait, encaissement Mobile Money). */
    protected function boutiqueEnLigne(Company $company): void
    {
        $company = $company->fresh();
        if (! $company->hasModule('ecommerce')) {
            return;
        }
        app()->instance('tenant', $company);
        app()->instance('branch_scope', null);
        app()->instance('branch_current', null);
        $ec = app(\App\Modules\Ecommerce\EcommerceService::class);
        $ec->saveSettings($company, ['whatsapp_number' => '07 00 00 00 00', 'brand' => 'Démo SARL', 'notify_customer' => false]);
        $site = \App\Modules\Sites\Models\Site::first();
        if (! $site) {
            return;
        }
        // Commandes du site antérieures au module, déjà remises et payées : facturées et réglées comme les nouvelles
        foreach (\App\Modules\Sites\Models\SiteOrder::whereNull('invoice_id')->whereIn('status', ['shipped', 'delivered'])->where('payment_status', 'paid')->get() as $old) {
            rescue(function () use ($ec, $old) {
                $old->forceFill(['payment_status' => 'unpaid'])->save();
                $ec->pay($old, ['method' => $old->payment_mode === 'momo' ? 'mobile_money' : 'cash', 'date' => $old->created_at->toDateString()]);
                $old->forceFill(['shipped_at' => $old->created_at->copy()->addHours(2), 'confirmed_at' => $old->created_at->copy()->addMinutes(30)])->save();
            }, fn () => $old->forceFill(['payment_status' => 'paid'])->save(), false);
        }
        $pids = $site->content['product_ids'] ?? [];
        $drivers = User::where('company_id', $company->id)->whereIn('email', ['ibrahim@demo.ci', 'serge@demo.ci'])->pluck('id', 'email');
        $zone1 = 'Marcory, Koumassi, Treichville';
        $zone2 = 'Plateau, Cocody, Yopougon';
        foreach ([
            // [canal, client, téléphone, livraison, adresse, paiement, articles, étape, heures, livreur, réf. Mobile Money]
            ['whatsapp', 'Aminata Touré', '+225 07 47 58 69 70', $zone2, 'Riviera Palmeraie, rue des Jardins', 'momo', [0 => 1, 1 => 2], 'shipped_paid', 5, 'ibrahim@demo.ci', 'OM-2611-48213'],
            ['facebook', 'Yves Gnagne', '+225 05 63 74 85 96', $zone1, 'Treichville avenue 21, immeuble Kanaga', 'cod', [2 => 3, 5 => 6], 'shipped', 3, 'serge@demo.ci', null],
            ['instagram', 'Naomi Kouadio', '+225 01 23 34 45 56', $zone2, 'Cocody Danga, villa 12', 'cod', [3 => 2, 6 => 1], 'new', 30, null, null],
            ['telephone', 'Restaurant Le Baobab', '+225 27 22 41 00 00', 'pickup', null, 'cod', [0 => 4, 7 => 24], 'ready', 2, null, null],
            ['whatsapp', 'Bintou Sangaré', '+225 07 85 96 07 18', $zone1, 'Koumassi Remblais, cité SIR', 'cod', [1 => 2, 4 => 3], 'delivered', 70, 'ibrahim@demo.ci', null],
            ['whatsapp', 'Désiré Ahoua', '+225 05 11 22 33 66', 'pickup', null, 'momo', [5 => 12], 'confirmed', 4, null, null],
            ['facebook', 'Clarisse Yapi', '+225 01 77 88 99 00', $zone2, 'Yopougon Maroc, pharmacie Saint-Joseph', 'cod', [1 => 1, 4 => 2], 'preparing', 7, null, null],
        ] as [$ch, $nm, $ph, $zone, $addr, $pay, $items, $step, $hours, $driver, $ref]) {
            $cart = [];
            foreach ($items as $i => $q) {
                if (isset($pids[$i])) {
                    $cart[] = ['id' => $pids[$i], 'quantity' => $q];
                }
            }
            if (! $cart) {
                continue;
            }
            $o = rescue(fn () => $ec->createManual(['channel' => $ch, 'name' => $nm, 'phone' => $ph, 'delivery' => $zone, 'address' => $addr, 'payment' => $pay, 'items' => $cart,
                'confirm' => $step !== 'new']), null, false);
            if (! $o) {
                continue;
            }
            app()->instance('tenant', $company);
            $at = now()->subHours($hours);
            $o->forceFill(['created_at' => $at, 'confirmed_at' => $step !== 'new' ? $at->copy()->addMinutes(20) : null])->save();
            $driverId = $driver ? ($drivers[$driver] ?? null) : null;
            rescue(function () use ($ec, $o, $step, $ref, $driverId, $at) {
                if ($step === 'shipped_paid') {
                    $o = $ec->pay($o, ['method' => 'mobile_money', 'reference' => $ref]);
                }
                if ($step === 'preparing') {
                    $ec->prepare($o);
                }
                if (in_array($step, ['shipped_paid', 'shipped', 'ready', 'delivered'], true)) {
                    $o = $ec->ship($o->fresh(), array_filter(['driver_id' => $driverId]));
                    $o->forceFill(['shipped_at' => $at->copy()->addMinutes($step === 'delivered' ? 150 : 75)])->save();
                }
                if ($step === 'delivered') {
                    $o = $ec->deliver($o->fresh(), ['collected' => true, 'method' => 'cash', 'signed_by' => 'Bintou']);
                    $o->forceFill(['delivered_at' => $at->copy()->addHours(4)])->save();
                }
            }, null, false);
        }
    }

    /** Assistant IA : réglages de démonstration et une conversation enregistrée (réponses calculées avec les outils sur les données de la démo). */
    protected function assistantIa(Company $company): void
    {
        $company = $company->fresh();
        if (! $company->hasModule('ia')) {
            return;
        }
        app()->instance('tenant', $company);
        app()->instance('branch_scope', null);
        app()->instance('branch_current', null);
        $admin = User::where('company_id', $company->id)->where('email', 'admin@demo.ci')->first();
        \Illuminate\Support\Facades\DB::table('ia_settings')->updateOrInsert(['company_id' => $company->id], ['provider' => 'anthropic', 'model' => 'claude-haiku-5-5',
            'api_key' => \Illuminate\Support\Facades\Crypt::encryptString('sk-demo-a-remplacer'), 'monthly_limit' => 500, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $tools = new \App\Modules\Ia\AiTools($company, $admin);
        $f = fn ($v) => number_format((float) $v, 0, ',', ' ').' F CFA';
        $imp = $tools->run('factures_impayees', ['en_retard_seulement' => true, 'limite' => 5]);
        $a1 = 'Vous avez **'.$imp['nombre'].' factures en retard** pour un total de **'.$f($imp['total_du']).'**. Les 5 plus anciennes :'."\n\n| Facture | Client | Échéance | Retard | Reste dû |\n|---|---|---|---|---|\n"
            .implode("\n", array_map(fn ($x) => '| '.$x['numero'].' | '.$x['client'].' | '.\Illuminate\Support\Carbon::parse($x['echeance'])->format('d/m/Y').' | '.$x['retard_jours'].' j | '.$f($x['du']).' |', $imp['factures']))
            ."\n\nPour les relancer, ouvrez **Relances & recouvrement**, ou demandez-moi de rédiger la relance d'une facture dans l'onglet Rédaction.";
        $top = $tools->run('meilleurs_clients', ['du' => now()->startOfYear()->toDateString(), 'limite' => 5]);
        $sales = $tools->run('ventes_resume', ['du' => now()->startOfYear()->toDateString()]);
        $a2 = 'Depuis le 1er janvier, le chiffre d\'affaires est de **'.$f($sales['ca_ht']).' HT** ('.$sales['factures'].' factures et '.$sales['tickets_caisse'].' tickets). Vos 5 meilleurs clients :'."\n\n"
            .implode("\n", array_map(fn ($i, $x) => ($i + 1).'. **'.$x['client'].'** : '.$f($x['ca_ht']).' HT ('.$x['documents'].' document'.($x['documents'] > 1 ? 's' : '').')', array_keys($top['clients']), $top['clients']))
            ."\n\nÀ eux cinq, ils représentent ".($sales['ca_ht'] > 0 ? round(100 * array_sum(array_column($top['clients'], 'ca_ht')) / $sales['ca_ht']) : 0).' % des ventes.';
        $conv = \Illuminate\Support\Facades\DB::table('ia_conversations')->insertGetId(['company_id' => $company->id, 'user_id' => $admin->id, 'title' => 'Impayés en retard et meilleurs clients', 'created_at' => now()->subHour(), 'updated_at' => now()->subMinutes(50)]);
        foreach ([
            ['user', 'Quelles sont les 5 factures impayées les plus en retard ?', null, 60],
            ['assistant', $a1, [['name' => 'factures_impayees', 'args' => ['en_retard_seulement' => true, 'limite' => 5]]], 59],
            ['user', 'Et quels sont nos 5 meilleurs clients cette année ?', null, 52],
            ['assistant', $a2, [['name' => 'meilleurs_clients', 'args' => []], ['name' => 'ventes_resume', 'args' => []]], 51],
        ] as [$role, $content, $calls, $ago]) {
            \Illuminate\Support\Facades\DB::table('ia_messages')->insert(['conversation_id' => $conv, 'company_id' => $company->id, 'user_id' => $admin->id, 'role' => $role, 'content' => $content,
                'tools' => $calls ? json_encode($calls) : null, 'tokens_in' => $role === 'assistant' ? 1800 : 0, 'tokens_out' => $role === 'assistant' ? 240 : 0, 'created_at' => now()->subMinutes($ago), 'updated_at' => now()->subMinutes($ago)]);
        }
        app()->forgetInstance('tenant');
    }

    /** Multi-agences : siège de Cocody (données existantes), agences de Marcory et de Yopougon avec dépôt, caisse, équipe et ventes. */
    protected function agencesDemo(Company $company): void
    {
        $company = $company->fresh();
        app(\App\Support\ModuleRegistry::class)->flush();
        if (! $company->hasModule('agences')) {
            return;
        }
        app()->instance('tenant', $company);
        app()->instance('branch_scope', null);
        app()->instance('branch_current', null);
        $admin = User::where('company_id', $company->id)->where('email', 'admin@demo.ci')->first();
        auth()->setUser($admin);
        $w = app(\App\Services\EntityWriter::class);
        $put = function (string $class, array $data, ?string $id = null) use ($w) {
            $r = $w->upsert($class, $id ??= (string) \Illuminate\Support\Str::uuid(), $data);
            if ($r['status'] !== \App\Services\EntityWriter::APPLIED) {
                throw new \RuntimeException(class_basename($class).' : '.json_encode($r));
            }

            return $id;
        };
        $B = \App\Modules\Agences\Models\Branch::class;
        $W = \App\Modules\Stock\Models\Warehouse::class;
        $A = \App\Modules\Tresorerie\Models\TreasuryAccount::class;
        $mainWh = $W::defaultFor($company->id);
        $mainCash = $A::where('kind', 'cash')->orderBy('sort')->first();
        $siege = $put($B, ['name' => 'Siège Cocody', 'code' => 'SIE', 'city' => 'Abidjan', 'address' => 'Boulevard Latrille, Cocody', 'phone' => '+225 27 21 00 00 00',
            'is_head_office' => true, 'manager_id' => $admin->id, 'warehouse_id' => $mainWh->id, 'cash_account_id' => $mainCash?->id]);
        // Les données existantes sont celles du siège
        foreach (['trade_documents', 'pos_registers'] as $t) {
            \Illuminate\Support\Facades\DB::table($t)->where('company_id', $company->id)->whereNull('branch_id')->update(['branch_id' => $siege]);
        }
        \Illuminate\Support\Facades\DB::table('warehouses')->where('id', $mainWh->id)->update(['branch_id' => $siege]);
        // Comptes de trésorerie existants (caisse, banques, Mobile Money) : ceux du siège
        $accIds = \Illuminate\Support\Facades\DB::table('tr_accounts')->where('company_id', $company->id)->whereNull('branch_id')->pluck('id');
        \Illuminate\Support\Facades\DB::table('tr_accounts')->whereIn('id', $accIds)->update(['branch_id' => $siege]);
        \Illuminate\Support\Facades\DB::table('tr_operations')->where('company_id', $company->id)->whereIn('account_id', $accIds)->update(['branch_id' => $siege]);
        // Prix d'achat des articles vendus au siège qui n'en avaient pas (marge estimée)
        \Illuminate\Support\Facades\DB::table('products')->where('company_id', $company->id)->where('type', 'product')->where('sale_price', '>', 0)->where('purchase_price', 0)
            ->update(['purchase_price' => \Illuminate\Support\Facades\DB::raw('ROUND(sale_price * 0.7)')]);
        $roles = \App\Models\Role::where('company_id', $company->id)->pluck('id', 'name');
        $products = \App\Modules\Core\Models\Product::whereNull('parent_id')->where('is_template', false)->where('type', 'product')->where('sale_price', '>', 0)->where('purchase_price', '>', 0)
            ->orderByDesc('sale_price')->take(6)->get();
        foreach ([['Agence Marcory', 'MRC', 'Zone 4, Marcory', 'marcory@demo.ci', 'Mariam Koné (Marcory)', 1.35], ['Agence Yopougon', 'YOP', 'Siporex, Yopougon', 'yopougon@demo.ci', 'Ibrahim Traoré (Yopougon)', 0.8]] as [$name, $code, $addr, $email, $who, $f]) {
            $wh = $put($W, ['name' => 'Dépôt '.$name, 'code' => $code]);
            $cash = $put($A, ['name' => 'Caisse '.$name, 'kind' => 'cash', 'methods' => ['cash'], 'opening_date' => now()->startOfYear()->toDateString(), 'opening_balance' => 150000]);
            $user = User::create(['company_id' => $company->id, 'role_id' => $roles['Caissier'] ?? $admin->role_id, 'name' => $who, 'email' => $email, 'password' => 'password']);
            $b = $put($B, ['name' => $name, 'code' => $code, 'city' => 'Abidjan', 'address' => $addr, 'manager_id' => $user->id, 'warehouse_id' => $wh, 'cash_account_id' => $cash]);
            \Illuminate\Support\Facades\DB::table('warehouses')->where('id', $wh)->update(['branch_id' => $b]);
            \Illuminate\Support\Facades\DB::table('tr_accounts')->where('id', $cash)->update(['branch_id' => $b]);
            \Illuminate\Support\Facades\DB::table('branch_user')->insert(['branch_id' => $b, 'user_id' => $user->id]);
            $reg = $put(\App\Modules\Pos\Models\PosRegister::class, ['name' => 'Caisse '.$name, 'code' => $code, 'warehouse_id' => $wh, 'branch_id' => $b]);
            foreach ($products as $p) {
                $put(\App\Modules\Stock\Models\StockMove::class, ['product_id' => $p->id, 'warehouse_id' => $wh, 'quantity' => 120, 'type' => 'initial', 'date' => now()->startOfYear()->toDateString(), 'unit_cost' => (float) $p->purchase_price]);
            }
            // Ventes sur l'année : tickets payés en espèces et quelques factures
            mt_srand(crc32($code));
            for ($m = 9; $m >= 0; $m--) {
                foreach (range(1, 3) as $k) {
                    $day = now()->subMonths($m)->startOfMonth()->addDays(mt_rand(1, 24));
                    if ($day->isFuture()) {
                        continue;
                    }
                    $lines = [];
                    foreach ($products->random(2) as $p) {
                        $lines[] = ['id' => (string) \Illuminate\Support\Str::uuid(), 'product_id' => $p->id, 'description' => $p->name, 'quantity' => max(1, (int) round(mt_rand(2, 6) * $f)), 'unit_price' => (float) $p->sale_price, 'tax_rate' => (float) $p->tax_rate, 'discount_percent' => 0];
                    }
                    $doc = $put(\App\Modules\Ventes\Models\SalesDocument::class, ['type' => $k === 3 ? 'invoice' : 'receipt', 'date' => $day->toDateString(), 'due_date' => $day->copy()->addDays(30)->toDateString(),
                        'status' => 'validated', 'warehouse_id' => $wh, 'branch_id' => $b, 'lines' => $lines]);
                    $d = \App\Modules\Ventes\Models\SalesDocument::find($doc);
                    if ($k !== 3 || $m > 1) {
                        $put(\App\Modules\Ventes\Models\SalesPayment::class, ['document_id' => $doc, 'date' => $day->toDateString(), 'amount' => (float) $d->total, 'method' => 'cash']);
                    }
                }
                // Dépenses de l'agence : loyer et électricité
                $day = now()->subMonths($m)->startOfMonth()->addDays(4);
                if (! $day->isFuture()) {
                    $put(\App\Modules\Tresorerie\Models\Operation::class, ['account_id' => $cash, 'date' => $day->toDateString(), 'amount' => -round(160000 * $f, -3), 'label' => 'Loyer '.$name, 'category' => 'rent', 'branch_id' => $b]);
                    $put(\App\Modules\Tresorerie\Models\Operation::class, ['account_id' => $cash, 'date' => $day->copy()->addDays(6)->toDateString(), 'amount' => -round(mt_rand(30, 50) * 1000 * $f, -3), 'label' => 'Électricité CIE', 'category' => 'utilities', 'branch_id' => $b]);
                }
            }
        }
        auth()->forgetUser();
        app()->forgetInstance('tenant');
    }

    /** Groupe : filiale « Démo Distribution Bouaké » détenue à 80 %, ventes et dettes réciproques, consolidation. */
    protected function groupeConsolidation(Company $company): void
    {
        $company = $company->fresh();
        if (! $company->hasModule('compta')) {
            return;
        }
        $admin = User::where('company_id', $company->id)->where('email', 'admin@demo.ci')->first();
        ['company' => $sub, 'user' => $subUser] = app(CompanySetup::class)->create(
            ['name' => 'Démo Distribution Bouaké', 'email' => 'bouake@demo.ci', 'phone' => '+225 27 31 00 00 00', 'city' => 'Bouaké'],
            ['name' => 'Gérant Bouaké', 'email' => 'bouake@demo.ci', 'password' => 'password'],
            Plan::where('code', 'entreprise')->first(),
        );
        $company->forceFill(['tax_id' => $company->tax_id ?: '1834567 B'])->save();
        $sub->forceFill(['tax_id' => '2209876 M'])->save();
        app(\App\Support\ModuleRegistry::class)->flush();
        $w = app(\App\Services\EntityWriter::class);
        $posting = app(\App\Modules\Compta\Posting::class);
        $y = now()->year;
        $put = function (string $class, array $data) use ($w) {
            $r = $w->upsert($class, $id = (string) \Illuminate\Support\Str::uuid(), $data);
            if ($r['status'] !== \App\Services\EntityWriter::APPLIED) {
                throw new \RuntimeException(class_basename($class).' : '.json_encode($r));
            }

            return $id;
        };
        $line = fn (string $desc, float $q, float $price) => ['id' => (string) \Illuminate\Support\Str::uuid(), 'description' => $desc, 'quantity' => $q, 'unit_price' => $price, 'tax_rate' => 18, 'discount_percent' => 0];
        $partner = fn (string $type, string $name, ?string $ncc = null) => $put(\App\Modules\Core\Models\Partner::class, ['type' => $type, 'is_company' => true, 'name' => $name, 'tax_id' => $ncc, 'city' => 'Abidjan']);

        // Filiale : capital 20 M, ventes à Démo SARL (12 M HT) et à ses clients de Bouaké, achats de marchandises
        app()->instance('tenant', $sub->fresh());
        auth()->setUser($subUser);
        $posting->postExternal($sub, 'OD', $y.'-01-03', 'Apport en capital', 'STATUTS', [['521', 20000000], ['101', -20000000]], 'demo_capital', 'bouake');
        $mother = $partner('customer', 'Démo SARL', $company->tax_id);
        $local = $partner('customer', 'Grossiste Koko Bouaké');
        $supplier = $partner('supplier', 'Moulins de Bouaké');
        $V = \App\Modules\Ventes\Models\SalesDocument::class;
        foreach ([[$mother, -95, [$line('Riz parfumé — sacs de 25 kg', 600, 12500), $line('Huile de palme — bidons 20 L', 150, 30000)]],
            [$local, -80, [$line('Riz parfumé — sacs de 25 kg', 1400, 13000)]], [$local, -35, [$line('Huile de palme — bidons 20 L', 420, 31000)]]] as [$p, $days, $lines]) {
            $put($V, ['type' => 'invoice', 'partner_id' => $p, 'date' => now()->addDays($days)->toDateString(), 'due_date' => now()->addDays($days + 30)->toDateString(), 'status' => 'validated', 'lines' => $lines]);
        }
        $put(\App\Modules\Achats\Models\PurchaseDocument::class, ['type' => 'supplier_invoice', 'partner_id' => $supplier, 'partner_name' => 'Moulins de Bouaké', 'date' => now()->subDays(100)->toDateString(),
            'status' => 'validated', 'reference' => 'MB-2026-118', 'lines' => [$line('Riz et huile — approvisionnement', 1, 24000000)]]);

        // Mère : titres de participation (80 % pour 17 M) et achat à la filiale
        app()->instance('tenant', $company->fresh());
        auth()->setUser($admin);
        $posting->postExternal($company, 'OD', $y.'-01-02', 'Capital social libéré', 'STATUTS', [['521', 30000000], ['101', -30000000]], 'demo_capital', 'demo');
        $posting->postExternal($company, 'OD', $y.'-01-03', 'Acquisition de 80 % de Démo Distribution Bouaké', 'ACTE-2026-01', [['261', 17000000], ['521', -17000000]], 'demo_titres', 'bouake');
        $subPartner = $partner('supplier', 'Démo Distribution Bouaké', $sub->tax_id);
        $D = \App\Modules\Achats\Models\PurchaseDocument::class;
        $id = $put($D, ['type' => 'supplier_invoice', 'partner_id' => $subPartner, 'partner_name' => 'Démo Distribution Bouaké', 'date' => now()->subDays(95)->toDateString(), 'due_date' => now()->subDays(65)->toDateString(),
            'status' => 'validated', 'reference' => 'DDB-0007', 'lines' => [$line('Riz parfumé — sacs de 25 kg', 600, 12500), $line('Huile de palme — bidons 20 L', 150, 30000)]]);
        $doc = $D::find($id);
        if ($doc && $doc->status === 'validated' && ! \App\Modules\Compta\Models\Entry::where('source_id', $id)->exists()) {
            app(\App\Modules\Compta\PurchaseReview::class)->decide($company, $doc, 'post', null, [$doc->lines->first()->id => '601'], true, $admin->id);
        }
        // Inventaire : 5 M de marchandises en stock chez Démo SARL, dont 3 M achetées à la filiale
        $posting->postExternal($company, 'OD', now()->subDays(5)->toDateString(), 'Inventaire des marchandises', 'INV-2026', [['31', 5000000], ['6031', -5000000]], 'demo_inventaire', 'demo');
        // Dividende versé par la filiale (2 M) : éliminé automatiquement grâce au tiers sur l'écriture de la mère
        $posting->postExternal($company, 'OD', now()->subDays(60)->toDateString(), 'Dividende reçu de Démo Distribution Bouaké', 'AGO-DDB', [['521', 2000000], ['772', -2000000, null, $subPartner]], 'demo_dividende', 'bouake');
        app()->instance('tenant', $sub->fresh());
        $posting->postExternal($sub, 'OD', now()->subDays(60)->toDateString(), 'Dividendes versés (2 M à Démo SARL, 0,5 M aux autres associés)', 'AGO-DDB', [['121', 2500000], ['521', -2500000]], 'demo_dividende', 'bouake');
        app()->instance('tenant', $company->fresh());
        $member = \App\Modules\Compta\Models\GroupMember::create(['parent_id' => $company->id, 'member_id' => $sub->id, 'interest_pct' => 80, 'control_pct' => 80, 'method' => 'ig',
            'investment_cost' => 17000000, 'equity_at_acquisition' => 20000000, 'acquisition_date' => $y.'-01-03', 'goodwill_years' => 5]);
        // Achat de 10 % complémentaires il y a un mois (80 % → 90 %)
        $posting->postExternal($company, 'OD', now()->subDays(30)->toDateString(), 'Achat de 10 % de Démo Distribution Bouaké', 'ACTE-2026-07', [['261', 3800000], ['521', -3800000]], 'demo_titres2', 'bouake');
        $member->stakes()->create(['date' => now()->subDays(30)->toDateString(), 'change_pct' => 10, 'amount' => 3800000, 'note' => 'Rachat des parts d\'un associé fondateur']);
        // Marge interne : Démo SARL a encore en stock 3 M de marchandises achetées à la filiale
        app(\App\Modules\Compta\Consolidation::class)->saveAdjustment($company, \App\Modules\Compta\FiscalCalendar::period($company, \App\Modules\Compta\FiscalCalendar::yearOf($company, now()->toDateString()))[1],
            ['kind' => 'stock_margin', 'seller_id' => $sub->id, 'buyer_id' => $company->id, 'amount' => 3000000], $admin->id);
        auth()->forgetUser();
        app()->forgetInstance('tenant');
    }

    /** Achats techniques d'un électricien : importations (coût de revient), cahier des charges, comparatifs, locations, historique fournisseurs. */
    protected function importationsLocations(Company $company): void
    {
        $company = $company->fresh();
        if (! $company->hasModule('appro')) {
            return;
        }
        app()->instance('tenant', $company);
        $admin = User::where('company_id', $company->id)->where('email', 'admin@demo.ci')->first();
        $yao = User::where('company_id', $company->id)->where('email', 'yao@demo.ci')->first() ?? $admin;
        auth()->setUser($admin);
        $w = app(\App\Services\EntityWriter::class);
        $put = function (string $class, array $data, ?string $id = null) use ($w) {
            $id ??= (string) \Illuminate\Support\Str::uuid();
            $r = $w->upsert($class, $id, $data);
            if ($r['status'] !== \App\Services\EntityWriter::APPLIED) {
                throw new \RuntimeException(class_basename($class).' : '.json_encode($r));
            }

            return $id;
        };
        $D = \App\Modules\Achats\Models\PurchaseDocument::class;
        $wh = Warehouse::defaultFor($company->id);
        $sup = fn (string $name, string $city, string $country, ?string $email, ?string $phone = null) => Partner::create(['name' => $name, 'type' => 'supplier', 'is_company' => true, 'city' => $city, 'country' => $country, 'email' => $email, 'phone' => $phone]);
        $sz = $sup('Shenzhen Brightway Lighting Co.', 'Shenzhen', 'CN', 'export@brightway-lighting.example', '+86 755 2300 1188');
        $ly = $sup('Énergie Matériel Lyon', 'Lyon', 'FR', 'export@energie-materiel.example', '+33 4 78 00 12 34');
        $ep = $sup('Électro Plateau', 'Abidjan', 'CI', 'ventes@electroplateau.example', '+225 27 20 21 30 40');
        $ca = $sup('Comptoir Électrique d\'Adjamé', 'Abidjan', 'CI', 'contact@comptoir-adjame.example', '+225 27 20 37 11 00');
        $loc = $sup('Locatech Abidjan', 'Abidjan', 'CI', 'location@locatech.example', '+225 27 21 25 60 60');
        $int = $sup('Intérim Bâtiment CI', 'Abidjan', 'CI', 'agence@interim-batiment.example');
        $tg = $sup('Tranchées & Réseaux SARL', 'Bingerville', 'CI', null, '+225 07 08 55 44 33');
        $cat = ProductCategory::firstOrCreate(['company_id' => $company->id, 'name' => 'Matériel électrique']);
        $prod = [];
        foreach ([
            ['cable', 'Câble R2V 3G2,5 mm² (couronne 100 m)', 'couronne', 62000, 41000],
            ['disj', 'Disjoncteur bipolaire 20 A', 'u', 6500, 3600],
            ['proj', 'Projecteur LED 100 W IP65', 'u', 28000, 15500],
            ['tab', 'Tableau électrique 4 rangées 52 modules', 'u', 98000, 61000],
            ['gaine', 'Gaine ICTA Ø20 (couronne 100 m)', 'couronne', 14500, 8900],
            ['diff', 'Interrupteur différentiel 40 A 30 mA', 'u', 34000, 21500],
        ] as [$k, $n, $unit, $sp, $pp]) {
            $p = Product::create(['name' => $n, 'type' => 'product', 'category_id' => $cat->id, 'sale_price' => $sp, 'purchase_price' => $pp, 'tax_rate' => 18, 'unit' => $unit, 'track_stock' => true, 'min_stock' => 5]);
            StockMove::create(['product_id' => $p->id, 'warehouse_id' => $wh->id, 'quantity' => 25, 'type' => 'initial', 'unit_cost' => $pp, 'date' => now()->subMonths(14)->toDateString()]);
            $prod[$k] = $p;
        }

        // ---------------------------------------------------------------- historique d'achats locaux sur 14 mois
        $line = fn (Product $p, float $q, float $price) => ['id' => (string) \Illuminate\Support\Str::uuid(), 'product_id' => $p->id, 'description' => $p->name, 'quantity' => $q, 'unit' => $p->unit, 'unit_price' => $price, 'tax_rate' => 18, 'discount_percent' => 0];
        $buy = function (Partner $s, int $daysAgo, int $promised, int $took, array $lines, bool $complete = true) use ($put, $D) {
            \Carbon\Carbon::setTestNow(now()->subDays($daysAgo)->setTime(9, 0));
            $id = $put($D, ['type' => 'purchase_order', 'partner_id' => $s->id, 'date' => now()->toDateString(), 'due_date' => now()->addDays($promised)->toDateString(), 'lines' => $lines]);
            $D::find($id)->validateDocument();
            $po = $D::with('lines')->find($id);
            \Carbon\Carbon::setTestNow(now()->addDays($took));
            $put(\App\Modules\Achats\Models\PurchaseReceipt::class, ['order_id' => $po->id, 'date' => now()->toDateString(), 'supplier_reference' => 'BL-'.random_int(1000, 9999),
                'lines' => $po->lines->values()->map(fn ($l, $i) => ['order_line_id' => $l->id, 'quantity' => $complete || $i > 0 ? $l->quantity : max(1, floor($l->quantity / 2))])->all()]);
            $inv = $put($D, ['type' => 'supplier_invoice', 'partner_id' => $s->id, 'date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(), 'reference' => 'F-'.random_int(10000, 99999), 'lines' => array_map(fn ($l) => ['id' => (string) \Illuminate\Support\Str::uuid()] + $l, $lines)]);
            $D::find($inv)->validateDocument();
            \Carbon\Carbon::setTestNow();
        };
        // Électro Plateau : fiable, prix du câble en hausse
        foreach ([[420, 41000], [330, 42500], [240, 44000], [150, 45500], [60, 47000], [20, 48500]] as $i => [$ago, $price]) {
            $buy($ep, $ago, 3, $i === 3 ? 4 : 2, [$line($prod['cable'], 10, $price), $line($prod['disj'], 40, 3600 + $i * 50), $line($prod['gaine'], 8, 8900)]);
        }
        // Comptoir d'Adjamé : moins cher, souvent en retard et livraisons partielles
        foreach ([[380, 39500, 9, false], [270, 40500, 6, true], [180, 42000, 10, false], [90, 43500, 8, true], [35, 44500, 7, false]] as [$ago, $price, $took, $full]) {
            $buy($ca, $ago, 4, $took, [$line($prod['cable'], 12, $price), $line($prod['diff'], 10, 20500)], $full);
        }

        // ---------------------------------------------------------------- importations
        $imp = app(\App\Modules\Appro\ImportService::class);
        $usd = app(\App\Modules\Devises\FxService::class)->rate($company, 'USD') ?: 605.0;
        $f1 = $imp->save($company, null, ['title' => 'Projecteurs LED et gaines — Chine', 'partner_id' => $sz->id, 'origin_country' => 'Chine', 'currency' => 'USD', 'exchange_rate' => $usd, 'incoterm' => 'FOB',
            'transport' => 'sea', 'allocation' => 'value', 'vat_rate' => 18, 'order_date' => now()->subDays(38)->toDateString(), 'eta' => now()->addDays(9)->toDateString(), 'forwarder' => 'Transit Océan Abidjan',
            'tracking' => 'MSKU 448 120 7', 'status' => 'in_transit',
            'lines' => [['product_id' => $prod['proj']->id, 'description' => $prod['proj']->name, 'hs_code' => '9405.42', 'quantity' => 300, 'unit_price' => 14.5, 'weight' => 1.8, 'duty_rate' => 20],
                ['product_id' => $prod['gaine']->id, 'description' => $prod['gaine']->name, 'hs_code' => '3917.32', 'quantity' => 200, 'unit_price' => 7.2, 'weight' => 3.5, 'duty_rate' => 10],
                ['description' => 'Spots encastrables LED 7 W (lot de 10)', 'hs_code' => '9405.11', 'quantity' => 80, 'unit_price' => 18, 'weight' => 1.2, 'duty_rate' => 20]],
            'costs' => [['type' => 'freight', 'label' => 'Conteneur 20 pieds Shenzhen – Abidjan', 'amount' => 1850000], ['type' => 'insurance', 'amount' => 48000], ['type' => 'transit', 'amount' => 350000],
                ['type' => 'port', 'label' => 'Manutention et magasinage', 'amount' => 285000], ['type' => 'customs_other', 'label' => 'Redevance statistique et PCS', 'amount' => 62000],
                ['type' => 'local_transport', 'label' => 'Camion port – dépôt', 'amount' => 120000], ['type' => 'bank', 'label' => 'Frais de transfert', 'amount' => 35000]]], $yao->id);
        $f1->forceFill(['created_at' => now()->subDays(40)])->save();
        $f2 = $imp->save($company, null, ['title' => 'Tableaux et différentiels — France', 'partner_id' => $ly->id, 'origin_country' => 'France', 'currency' => 'EUR', 'incoterm' => 'EXW', 'transport' => 'air',
            'allocation' => 'weight', 'vat_rate' => 18, 'order_date' => now()->subDays(52)->toDateString(), 'eta' => now()->subDays(30)->toDateString(), 'forwarder' => 'Air Fret Express', 'tracking' => 'LTA 057-1123 4410', 'status' => 'received',
            'lines' => [['product_id' => $prod['tab']->id, 'description' => $prod['tab']->name, 'hs_code' => '8537.10', 'quantity' => 30, 'unit_price' => 72, 'weight' => 6.5, 'duty_rate' => 10],
                ['product_id' => $prod['diff']->id, 'description' => $prod['diff']->name, 'hs_code' => '8536.30', 'quantity' => 60, 'unit_price' => 24, 'weight' => 0.4, 'duty_rate' => 10]],
            'costs' => [['type' => 'freight', 'label' => 'Fret aérien Lyon – Abidjan', 'amount' => 640000], ['type' => 'insurance', 'amount' => 22000], ['type' => 'transit', 'amount' => 180000],
                ['type' => 'port', 'label' => 'Frais aéroportuaires', 'amount' => 95000], ['type' => 'local_transport', 'amount' => 40000]]], $yao->id);
        $f2->forceFill(['arrived_at' => now()->subDays(29)->toDateString(), 'created_at' => now()->subDays(53)])->save();
        $imp->apply($company, $f2->fresh());
        $f3 = $imp->save($company, null, ['title' => 'Câbles armés et chemins de câbles — Turquie', 'origin_country' => 'Turquie', 'currency' => 'EUR', 'incoterm' => 'CFR', 'transport' => 'sea', 'vat_rate' => 18,
            'order_date' => now()->subDays(60)->toDateString(), 'eta' => now()->subDays(4)->toDateString(), 'forwarder' => 'Transit Lagune', 'status' => 'customs', 'notes' => 'Déclaration en douane déposée, en attente du bon à enlever.',
            'lines' => [['description' => 'Câble armé U1000 RVFV 4x16 mm² (touret 500 m)', 'hs_code' => '8544.49', 'quantity' => 4, 'unit_price' => 3900, 'weight' => 520, 'duty_rate' => 20],
                ['description' => 'Chemin de câbles galvanisé 300 mm (3 m)', 'hs_code' => '7308.90', 'quantity' => 120, 'unit_price' => 38, 'weight' => 9, 'duty_rate' => 20]],
            'costs' => [['type' => 'freight', 'amount' => 980000], ['type' => 'insurance', 'amount' => 30000], ['type' => 'transit', 'amount' => 210000], ['type' => 'port', 'amount' => 160000]]], $yao->id);
        $imp->save($company, null, ['title' => 'Groupes électrogènes 20 kVA — Chine (devis)', 'partner_id' => $sz->id, 'origin_country' => 'Chine', 'currency' => 'USD', 'exchange_rate' => $usd, 'incoterm' => 'FOB', 'transport' => 'sea',
            'lines' => [['description' => 'Groupe électrogène diesel 20 kVA insonorisé', 'hs_code' => '8502.11', 'quantity' => 3, 'unit_price' => 4200, 'weight' => 620, 'duty_rate' => 5]],
            'costs' => [['type' => 'freight', 'amount' => 1200000], ['type' => 'transit', 'amount' => 250000]]], $yao->id);

        // ---------------------------------------------------------------- cahier des charges et comparatifs
        $src = app(\App\Modules\Appro\SourcingService::class);
        $sections = \App\Modules\Appro\SourcingService::SPEC_SECTIONS;
        $sections[0]['body'] = 'Fourniture du matériel électrique pour l\'électrification du bâtiment R+3 de la clinique (éclairage, prises, tableaux divisionnaires).';
        $spec = $src->saveSpec($company, null, ['title' => 'Électrification du bâtiment R+3 — Clinique Les Palmiers', 'site' => 'Cocody Angré, Abidjan', 'deadline' => now()->addDays(6)->toDateString(), 'status' => 'published',
            'context' => 'Extension de la clinique : 3 niveaux, 42 chambres. Livraison sur chantier en deux lots.', 'sections' => $sections,
            'requirements' => [
                ['label' => 'Câble R2V 3G2,5 mm²', 'value' => 'Norme NF C 32-321, cuivre, couronne de 100 m', 'quantity' => 40, 'unit' => 'couronne'],
                ['label' => 'Disjoncteur bipolaire 20 A', 'value' => 'Courbe C, pouvoir de coupure 6 kA', 'quantity' => 150, 'unit' => 'u'],
                ['label' => 'Interrupteur différentiel 40 A 30 mA', 'value' => 'Type A', 'quantity' => 24, 'unit' => 'u'],
                ['label' => 'Tableau électrique 4 rangées', 'value' => '52 modules minimum, porte pleine', 'quantity' => 6, 'unit' => 'u'],
                ['label' => 'Garantie', 'value' => '12 mois minimum sur tout le matériel', 'mandatory' => true],
            ]], $yao->id);
        $spec->forceFill(['sent_to' => [['id' => $ep->id, 'name' => $ep->name, 'email' => $ep->email, 'at' => now()->subDays(3)->toIso8601String()], ['id' => $ca->id, 'name' => $ca->name, 'email' => $ca->email, 'at' => now()->subDays(3)->toIso8601String()],
            ['id' => $ly->id, 'name' => $ly->name, 'email' => $ly->email, 'at' => now()->subDays(3)->toIso8601String()]]])->save();
        $L = fn (Product $p, float $q) => ['product_id' => $p->id, 'description' => $p->name, 'quantity' => $q, 'unit' => $p->unit];
        $cmp = $src->saveComparison($company, null, ['title' => 'Électrification R+3 — Clinique Les Palmiers', 'spec_id' => $spec->id, 'deadline' => now()->addDays(6)->toDateString(),
            'lines' => [$L($prod['cable'], 40), $L($prod['disj'], 150), $L($prod['diff'], 24), $L($prod['tab'], 6)]], $yao->id);
        $k = array_column($cmp->lines, 'key');
        $eur = app(\App\Modules\Devises\FxService::class)->rate($company, 'EUR') ?: 655.957;
        $cmp = $src->saveComparison($company, $cmp, ['offers' => [
            ['partner_id' => $ep->id, 'currency' => 'XOF', 'delay_days' => 3, 'payment_terms' => '30 jours fin de mois', 'prices' => [$k[0] => 48500, $k[1] => 3750, $k[2] => 21000, $k[3] => 64000]],
            ['partner_id' => $ca->id, 'currency' => 'XOF', 'delay_days' => 8, 'payment_terms' => 'Comptant', 'prices' => [$k[0] => 45000, $k[1] => 3500, $k[2] => 20500]],
            ['partner_id' => $ly->id, 'currency' => 'EUR', 'rate' => $eur, 'delay_days' => 21, 'extra' => 450000, 'payment_terms' => '50 % à la commande', 'prices' => [$k[0] => 66, $k[1] => 5.1, $k[2] => 29, $k[3] => 86]],
        ]], $yao->id);
        // Consultation en ligne : liens envoyés, deux offres saisies par les fournisseurs, une question en cours
        $off = app(\App\Modules\Appro\OfferService::class);
        $cmp->forceFill(['instructions' => 'Livraison sur le chantier de Cocody Angré en deux lots. Joindre les fiches techniques et indiquer la marque proposée.'])->save();
        \Carbon\Carbon::setTestNow(now()->subDays(3)->setTime(10, 15));
        $off->invite($company, $cmp->fresh(), [$ep->id, $ca->id, $ly->id], null, $yao, []);
        \Carbon\Carbon::setTestNow();
        $tok = fn (Partner $p) => \App\Modules\Appro\Models\OfferInvite::where('comparison_id', $cmp->id)->where('partner_id', $p->id)->first();
        \Carbon\Carbon::setTestNow(now()->subDays(2)->setTime(16, 40));
        $off->submit($company, $tok($ep), $cmp->fresh(), ['currency' => 'XOF', 'price' => [$k[0] => 48500, $k[1] => 3750, $k[2] => 21000, $k[3] => 64000],
            'note' => [$k[0] => 'Nexans, stock disponible', $k[3] => 'Hager 4 rangées'], 'delay_days' => 3, 'payment_terms' => '30 jours fin de mois', 'compliant' => 1, 'comment' => 'Livraison en deux lots incluse.']);
        \Carbon\Carbon::setTestNow(now()->addDay()->setTime(9, 5));
        $off->submit($company, $tok($ly), $cmp->fresh(), ['currency' => 'EUR', 'price' => [$k[0] => 66, $k[1] => 5.1, $k[2] => 29, $k[3] => 86], 'delay_days' => 21, 'extra' => 450000,
            'payment_terms' => '50 % à la commande', 'validity' => now()->addDays(30)->toDateString(), 'compliant' => 1, 'comment' => 'Fret aérien compris jusqu\'à Abidjan, dédouanement à votre charge.']);
        $off->opened($tok($ca));
        \Carbon\Carbon::setTestNow(now()->setTime(11, 20));
        $off->supplierMessage($company, $tok($ca), $cmp->fresh(), 'Bonjour, pour les tableaux 4 rangées, acceptez-vous la marque Legrand à la place de Hager ?');
        \Carbon\Carbon::setTestNow();
        $cmp = $cmp->fresh();
        $c2 = $src->saveComparison($company, null, ['title' => 'Projecteurs LED pour le parking de l\'hôtel', 'lines' => [$L($prod['proj'], 24)]], $yao->id);
        $k2 = array_column($c2->lines, 'key');
        $c2 = $src->saveComparison($company, $c2, ['offers' => [
            ['partner_id' => $ep->id, 'currency' => 'XOF', 'delay_days' => 2, 'prices' => [$k2[0] => 17500]],
            ['partner_id' => $ca->id, 'currency' => 'XOF', 'delay_days' => 5, 'prices' => [$k2[0] => 16800]],
            ['partner_id' => $sz->id, 'currency' => 'USD', 'rate' => $usd, 'delay_days' => 45, 'extra' => 180000, 'prices' => [$k2[0] => 14.5]],
        ]], $yao->id);
        $src->award($company, $c2, collect($c2->offers)->firstWhere('partner_id', $ep->id)['key'], 'Délai de 2 jours pour l\'ouverture du parking, écart de prix faible.');

        // ---------------------------------------------------------------- locations et services externes
        $rent = app(\App\Modules\Appro\RentalService::class);
        $mk = function (array $d, int $createdAgo) use ($rent, $company, $yao) {
            \Carbon\Carbon::setTestNow(now()->subDays($createdAgo)->setTime(8, 30));
            $r = $rent->save($company, null, $d, $yao);
            \Carbon\Carbon::setTestNow();

            return $r;
        };
        $day = fn (int $n) => now()->addDays($n)->toDateString();
        $mk(['kind' => 'equipment', 'title' => 'Nacelle ciseaux 12 m', 'partner_id' => $loc->id, 'start_date' => $day(-12), 'end_date' => $day(2), 'rate' => 45000, 'rate_unit' => 'day', 'deposit' => 300000,
            'site' => 'Clinique Les Palmiers', 'reference' => 'CT-LOC-8812'], 12);
        $g = $mk(['kind' => 'equipment', 'title' => 'Groupe électrogène 60 kVA', 'partner_id' => $loc->id, 'start_date' => $day(-20), 'end_date' => $day(-10), 'rate' => 280000, 'rate_unit' => 'week', 'deposit' => 500000,
            'site' => 'Chantier Bingerville', 'reference' => 'CT-LOC-8790'], 20);
        $newEnd = $day(-3);
        \Carbon\Carbon::setTestNow(now()->subDays(14));
        $rent->extend($company, $g, $newEnd, 'Raccordement CIE retardé', $yao);
        \Carbon\Carbon::setTestNow();
        $mk(['kind' => 'service', 'title' => 'Électricien intérimaire (2 personnes)', 'partner_id' => $int->id, 'start_date' => $day(-5), 'end_date' => $day(9), 'rate' => 3500, 'rate_unit' => 'hour', 'quantity' => 160,
            'site' => 'Clinique Les Palmiers', 'notes' => '2 électriciens × 8 h × 10 jours'], 5);
        $mk(['kind' => 'vehicle', 'title' => 'Camion-grue 10 t', 'partner_id' => $loc->id, 'start_date' => $day(4), 'end_date' => $day(5), 'rate' => 150000, 'rate_unit' => 'day', 'site' => 'Clinique Les Palmiers',
            'notes' => 'Levage des tableaux généraux'], 1);
        $t = $mk(['kind' => 'subcontract', 'title' => 'Tranchée et pose de fourreaux (180 m)', 'partner_id' => $tg->id, 'start_date' => $day(-25), 'end_date' => $day(-15), 'rate' => 1450000, 'rate_unit' => 'fixed',
            'site' => 'Chantier Bingerville'], 26);
        \Carbon\Carbon::setTestNow(now()->subDays(14));
        $rent->returnBack($company, $t, now()->toDateString(), false, 'Travaux réceptionnés sans réserve', $yao);
        \Carbon\Carbon::setTestNow();
        $b = $mk(['kind' => 'equipment', 'title' => 'Bétonnière 350 L et compacteur', 'partner_id' => $loc->id, 'start_date' => $day(-45), 'end_date' => $day(-38), 'rate' => 18000, 'rate_unit' => 'day', 'quantity' => 2, 'deposit' => 100000,
            'site' => 'Chantier Bingerville'], 46);
        \Carbon\Carbon::setTestNow(now()->subDays(37));
        $b = $rent->returnBack($company, $b, now()->toDateString(), true, null, $yao);
        \Carbon\Carbon::setTestNow(now()->addDays(2));
        $rent->invoice($company, $b, $admin);
        \Carbon\Carbon::setTestNow();
        app()->forgetInstance('tenant');
    }

    /** Appels de fonds des services vers la direction, et dossiers de recouvrement en contentieux. */
    protected function appelsFondsRecouvrement(Company $company): void
    {
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $u = fn (string $e) => User::where('company_id', $company->id)->where('email', $e)->first();
        $admin = $u('admin@demo.ci');
        $yao = $u('yao@demo.ci') ?? $admin;
        $koffi = $u('koffi@demo.ci') ?? $admin;
        $mariam = $u('mariam@demo.ci') ?? $admin;
        $paul = $u('paul@demo.ci') ?? $admin;
        if ($company->hasModule('fonds')) {
            $f = app(\App\Modules\Fonds\FundService::class);
            $f->saveSettings($company, ['departments' => ['Agence Yopougon', 'Boutique Marcory', 'Logistique', 'Marketing', 'Chantier Clinique Les Palmiers', 'Direction générale'],
                'default_approver' => $admin->id, 'providers' => ['funds' => 'Direction financière', 'equipment' => 'Logistique']]);
            $acc = $company->hasModule('tresorerie') ? \Illuminate\Support\Facades\DB::table('tr_accounts')->where('company_id', $company->id)->whereNull('deleted_at')->orderBy('created_at')->get(['id', 'kind']) : collect();
            $bank = $acc->firstWhere('kind', 'bank')?->id;
            $cash = $acc->firstWhere('kind', 'cash')?->id;
            $make = function (\App\Models\User $who, array $d, int $daysAgo) use ($company, $f) {
                $r = $f->save($company, $who, null, $d);
                $r->forceFill(['created_at' => now()->subDays($daysAgo)->setTime(9, 10)])->save();

                return $r;
            };
            $L = fn (string $t, string $desc, float $q, float $p, ?string $note = null) => ['type' => $t, 'description' => $desc, 'quantity' => $q, 'unit_amount' => $p, 'note' => $note];
            $stamp = function ($r, int $daysAgo) {
                $at = now()->subDays($daysAgo);
                \App\Modules\Fonds\Models\FundEvent::where('request_id', $r->id)->where('created_at', '>=', now()->subMinutes(5))->update(['created_at' => $at, 'updated_at' => $at]);
            };
            // Reçu (cycle complet, virement banque → caisse de l'agence)
            $r = $make($yao, ['title' => 'Fonds de caisse de l\'agence Yopougon', 'department' => 'Agence Yopougon', 'to_department' => 'Direction générale', 'approver_id' => $admin->id,
                'purpose' => 'Ouverture de la caisse de l\'agence : rendu de monnaie et petites dépenses du premier mois.', 'lines' => [$L('funds', 'Fonds de caisse (monnaie)', 1, 200000), $L('funds', 'Petites dépenses du mois', 1, 100000)]], 95);
            $f->submit($company, $yao, $r);
            $f->approve($company, $admin, $r, [], $paul->id, 'Direction financière', 'Accordé.');
            $f->provide($company, $paul, $r, [['id' => $r->lines[0]->id], ['id' => $r->lines[1]->id]], ['method' => 'bank_transfer', 'reference' => 'VIR-2210', 'source_account_id' => $bank, 'dest_account_id' => $cash, 'date' => now()->subDays(92)->toDateString()]);
            $f->confirm($company, $yao, $r->fresh(), 'Reçu sur la caisse de l\'agence.');
            $r->forceFill(['submitted_at' => now()->subDays(95), 'decided_at' => now()->subDays(94), 'provided_at' => now()->subDays(92), 'closed_at' => now()->subDays(92)])->save();
            $stamp($r, 93);
            // Partiellement fourni
            $r = $make($koffi, ['title' => 'Équipement de la boutique Marcory', 'department' => 'Boutique Marcory', 'to_department' => 'Direction générale', 'approver_id' => $admin->id, 'needed_by' => now()->addDays(5)->toDateString(),
                'purpose' => 'Remplacement de la vitrine réfrigérée en panne et achat d\'un second terminal de caisse.', 'lines' => [
                    $L('equipment', 'Vitrine réfrigérée 2 portes', 1, 850000), $L('equipment', 'Terminal de caisse tactile', 1, 320000), $L('funds', 'Transport et installation', 1, 45000)]], 12);
            $f->submit($company, $koffi, $r);
            $f->approve($company, $admin, $r, [], $paul->id, 'Logistique', 'Vitrine prioritaire ; le terminal suivra le mois prochain.');
            $f->provide($company, $paul, $r, [['id' => $r->lines[0]->id], ['id' => $r->lines[2]->id]], ['method' => 'purchase', 'reference' => 'BC-FROID-118']);
            $r->forceFill(['submitted_at' => now()->subDays(12), 'decided_at' => now()->subDays(11)])->save();
            $stamp($r, 10);
            // Approuvé, à fournir
            $r = $make($mariam, ['title' => 'Campagne flyers et affiches de fin d\'année', 'department' => 'Marketing', 'to_department' => 'Direction générale', 'approver_id' => $admin->id, 'needed_by' => now()->addDays(10)->toDateString(),
                'lines' => [$L('funds', 'Impression flyers A5 (10 000 ex.)', 1, 320000), $L('funds', 'Affiches A2 (200 ex.)', 200, 900), $L('service', 'Distribution en ville (3 jours)', 3, 35000)]], 6);
            $f->submit($company, $mariam, $r);
            $f->approve($company, $admin, $r, [['id' => $r->lines[2]->id, 'approved_quantity' => 2]], $paul->id, 'Direction financière', 'Distribution limitée à 2 jours.');
            $r->forceFill(['submitted_at' => now()->subDays(6), 'decided_at' => now()->subDays(4)])->save();
            $stamp($r, 4);
            // Explication demandée
            $r = $make($yao, ['title' => 'Deuxième scooter de livraison', 'department' => 'Logistique', 'to_department' => 'Direction générale', 'approver_id' => $admin->id,
                'purpose' => 'Les commandes en ligne ont doublé : un seul livreur ne suffit plus.', 'lines' => [$L('equipment', 'Scooter 125 cm³ avec top-case', 1, 1150000), $L('funds', 'Assurance et carte grise', 1, 185000)]], 3);
            $f->submit($company, $yao, $r);
            $f->question($company, $admin, $r, 'Avez-vous comparé avec la location longue durée ? Envoyez-moi deux devis.');
            $stamp($r, 2);
            // Urgent, en attente de décision
            $r = $make($koffi, ['title' => 'Avance carburant pour les tournées de livraison', 'department' => 'Logistique', 'to_department' => 'Direction générale', 'approver_id' => $admin->id, 'priority' => 'urgent',
                'needed_by' => now()->addDays(1)->toDateString(), 'purpose' => 'Hausse des tournées pour les commandes de fin de mois.', 'lines' => [$L('funds', 'Carburant (litres)', 300, 775), $L('funds', 'Péages et stationnement', 1, 15000)]], 1);
            $f->submit($company, $koffi, $r);
            // Refusé et brouillon
            $r = $make($mariam, ['title' => 'Formation Excel avancé (4 personnes)', 'department' => 'Marketing', 'approver_id' => $admin->id, 'lines' => [$L('service', 'Formation 2 jours', 4, 90000)]], 40);
            $f->submit($company, $mariam, $r);
            $f->reject($company, $admin, $r, 'Non prévu au budget formation de cette année ; à reproposer en janvier.');
            $stamp($r, 38);
            $make($yao, ['title' => 'Tenues de travail des livreurs', 'department' => 'Logistique', 'lines' => [$L('equipment', 'Gilets et casquettes brodés', 6, 12000)]], 0);
        }

        // Recouvrement : dossiers sur les clients en retard
        if ($company->hasModule('relances')) {
            $rec = app(\App\Modules\Relances\RecoveryService::class);
            $rec->saveSettings($company, ['min_days' => 30]);
            auth()->setUser($admin);
            $cand = $rec->candidates($company);
            foreach (array_slice($cand, 0, 3) as $i => $c) {
                $case = $rec->open($company, $admin, $c['partner_id'], null, $i === 1 ? $paul->id : $admin->id, $i === 0 ? 'Gros client, retards répétés depuis l\'été.' : null);
                $case->forceFill(['opened_at' => now()->subDays(20 - $i * 4)->toDateString()])->save();
                $act = fn (string $type, array $d) => rescue(fn () => $rec->act($company, $admin, $case->fresh(), $type, $d), null, false);
                $act('call', ['body' => 'Appel au gérant : factures bien reçues, problème de trésorerie passager.', 'date' => now()->subDays(18 - $i * 4)->toDateString()]);
                if ($i === 0) {
                    $act('payment', ['amount' => round($c['amount'] * 0.3, -3), 'method' => 'mobile_money', 'reference' => 'OM-778451', 'date' => now()->subDays(9)->toDateString()]);
                    $act('plan', ['count' => 3, 'first_date' => now()->addDays(5)->toDateString(), 'every' => 'month', 'body' => 'Accord signé avec le gérant : 3 mensualités.', 'date' => now()->subDays(8)->toDateString()]);
                } elseif ($i === 1) {
                    $act('promise', ['amount' => round($c['amount'] / 2, -3), 'promise_date' => now()->addDays(3)->toDateString(), 'body' => 'Promesse par téléphone : virement en fin de semaine.', 'date' => now()->subDays(2)->toDateString()]);
                } else {
                    $act('visit', ['body' => 'Passage au magasin : le responsable était absent, message laissé.', 'date' => now()->subDays(6)->toDateString()]);
                    $act('notice', ['days' => 8, 'date' => now()->subDays(3)->toDateString(), 'body' => 'Lettre recommandée envoyée.']);
                }
            }
            auth()->forgetUser();
        }
        app()->forgetInstance('tenant');
    }

    /** Comptabilité « cabinet » : circuit de vérification des factures fournisseurs, natures d'achat mémorisées, proforma. */
    protected function comptaCabinet(Company $company): void
    {
        $company = $company->fresh();
        app()->instance('tenant', $company);
        if (! $company->hasModule('compta') || ! $company->hasModule('achats')) {
            return;
        }
        $admin = User::where('company_id', $company->id)->where('email', 'admin@demo.ci')->first();
        $buyer = User::where('company_id', $company->id)->where('email', 'paul@demo.ci')->first() ?? $admin;
        auth()->setUser($buyer);
        $w = app(\App\Services\EntityWriter::class);
        $put = function (string $class, array $data, ?string $id = null) use ($w) {
            $id ??= (string) \Illuminate\Support\Str::uuid();
            $r = $w->upsert($class, $id, $data);
            if ($r['status'] !== \App\Services\EntityWriter::APPLIED) {
                throw new \RuntimeException(class_basename($class).' : '.json_encode($r));
            }

            return $id;
        };
        $settings = $company->settings ?? [];
        data_set($settings, 'compta.purchase_review', true);
        $company->update(['settings' => $settings]);
        app()->instance('tenant', $company->fresh());
        $partner = fn (string $name, string $email) => \App\Modules\Core\Models\Partner::where('name', $name)->first()?->id
            ?? $put(\App\Modules\Core\Models\Partner::class, ['type' => 'supplier', 'is_company' => true, 'name' => $name, 'email' => $email, 'city' => 'Abidjan']);
        $printer = $partner('Imprimerie Graphica Plateau', 'devis@graphica.ci');
        $cie = $partner('CIE — Compagnie Ivoirienne d\'Électricité', 'entreprises@cie.ci');
        $sci = $partner('SCI Les Cocotiers', 'gestion@sci-cocotiers.ci');
        $orange = $partner('Orange Côte d\'Ivoire Entreprises', 'pro@orange.ci');
        $garage = $partner('Garage Central Treichville', 'atelier@garagecentral.ci');
        $D = \App\Modules\Achats\Models\PurchaseDocument::class;
        $d = fn (int $days) => now()->addDays($days)->toDateString();
        $line = fn (string $desc, float $q, float $price, float $tax = 18) => ['id' => (string) \Illuminate\Support\Str::uuid(), 'description' => $desc, 'quantity' => $q, 'unit_price' => $price, 'tax_rate' => $tax, 'discount_percent' => 0];
        $inv = fn (string $supplier, string $ref, int $days, array $lines) => $put($D, ['type' => 'supplier_invoice', 'partner_id' => $supplier, 'partner_name' => \App\Modules\Core\Models\Partner::find($supplier)?->name,
            'date' => $d($days), 'due_date' => $d($days + 30), 'status' => 'validated', 'reference' => $ref, 'lines' => $lines]);

        // Mois précédent : comptabilisées (les comptes sont mémorisés pour la suite)
        $review = app(\App\Modules\Compta\PurchaseReview::class);
        auth()->setUser($admin);
        foreach ([
            [$printer, 'GRA-2026-1187', -34, [$line('Flyers A5 quadri, 5 000 ex.', 1, 185000)], ['627']],
            [$cie, 'CIE-0925-55871', -30, [$line('Électricité septembre — compteur 4471', 1, 212500)], ['6052']],
            [$sci, 'LOY-2026-10', -28, [$line('Loyer d\'octobre — magasin Marcory', 1, 650000, 0)], ['622']],
            [$orange, 'OCI-F-778120', -27, [$line('Fibre pro 100 Mb et 6 lignes mobiles', 1, 145000)], ['628']],
        ] as [$sup, $ref, $days, $lines, $codes]) {
            $id = $inv($sup, $ref, $days, $lines);
            $doc = $D::find($id);
            $review->decide($company, $doc, 'post', null, [$doc->lines->first()->id => $codes[0]], true, $admin->id);
        }
        // À vérifier : comptes proposés grâce aux natures mémorisées
        auth()->setUser($buyer);
        $inv($printer, 'GRA-2026-1242', -4, [$line('Kakémonos roll-up 85 × 200', 3, 45000), $line('Cartes de visite (10 boîtes)', 1, 60000)]);
        $inv($cie, 'CIE-1025-60214', -3, [$line('Électricité octobre — compteur 4471', 1, 238900)]);
        $inv($orange, 'OCI-F-781455', -2, [$line('Fibre pro 100 Mb et 6 lignes mobiles', 1, 145000)]);
        $inv($garage, 'GC-3381', -1, [$line('Vidange et plaquettes — Toyota Hilux 4521 HH 01', 1, 98000), $line('Pneus 265/65 R17', 2, 85000)]);
        // Approuvée, en attente de comptabilisation ; refusée avec motif
        auth()->setUser($admin);
        $id = $inv($sci, 'LOY-2026-11', -1, [$line('Loyer de novembre — magasin Marcory', 1, 650000, 0)]);
        $review->decide($company, $D::find($id), 'approve', 'Conforme au bail.', [], true, $admin->id);
        $id = $inv($printer, 'GRA-2026-1250', -2, [$line('Flyers A5 quadri, 10 000 ex.', 1, 320000)]);
        $review->decide($company, $D::find($id), 'refuse', 'Pas de bon de commande ni de bon de livraison signé : merci de joindre le BL et de confirmer la quantité (10 000 ex. au lieu de 5 000 habituellement).', [], false, $admin->id);
        \App\Models\AppNotification::where('company_id', $company->id)->where('created_at', '>=', now()->subMinute())->where('title', 'like', 'Facture fournisseur%')
            ->update(['created_at' => now()->subHours(3)]);
        // Nature mémorisée par catégorie
        $cat = \App\Modules\Core\Models\ProductCategory::where('name', 'Hygiène')->first();
        if ($cat) {
            $review->saveRule($company, 'category', $cat->id, '604');
        }
        // Proforma pour un client (demande de financement bancaire)
        $client = \App\Modules\Core\Models\Partner::where('type', 'customer')->orderBy('name')->first();
        $prod = \App\Modules\Core\Models\Product::whereNull('parent_id')->where('is_template', false)->where('sale_price', '>', 0)->orderByDesc('sale_price')->first();
        if ($client && $prod) {
            $put(\App\Modules\Ventes\Models\SalesDocument::class, ['type' => 'proforma', 'partner_id' => $client->id, 'partner_name' => $client->name, 'date' => $d(-2), 'due_date' => $d(28), 'status' => 'validated',
                'notes' => 'Facture proforma valable 30 jours, établie pour votre demande de financement.', 'lines' => [
                    ['id' => (string) \Illuminate\Support\Str::uuid(), 'product_id' => $prod->id, 'description' => $prod->name, 'quantity' => 12, 'unit_price' => (float) $prod->sale_price, 'tax_rate' => (float) $prod->tax_rate, 'discount_percent' => 5],
                ]]);
        }
        auth()->forgetUser();
        app()->forgetInstance('tenant');
    }

    /** Fiches produits de la boutique : photos par couleur, descriptions, badges, prix barrés, produits liés, avis sur variantes. */
    protected function fichesProduits(Company $company, \App\Modules\Sites\Models\Site $site): void
    {
        app()->instance('tenant', $company);
        $shop = app(\App\Modules\Sites\ShopService::class);
        $tshirt = Product::where('name', 'T-shirt coton Yomara')->first();
        $pagne = Product::where('name', 'Pagne wax 6 yards')->first();
        if (! $tshirt || ! $pagne) {
            return;
        }
        $dir = 'sites/'.$company->id.'/'.$site->id.'/produits/';
        $png = function (string $name, callable $draw) use ($dir): ?string {
            if (! function_exists('imagecreatetruecolor')) {
                return null;
            }
            $im = imagecreatetruecolor(900, 900);
            imageantialias($im, true);
            $draw($im);
            ob_start();
            imagepng($im, null, 6);
            \Illuminate\Support\Facades\Storage::disk('public')->put($dir.$name.'.png', (string) ob_get_clean());
            imagedestroy($im);

            return $dir.$name.'.png';
        };
        $hex = fn ($im, string $h) => imagecolorallocate($im, hexdec(substr($h, 1, 2)), hexdec(substr($h, 3, 2)), hexdec(substr($h, 5, 2)));
        $shirt = function (string $color, string $print, string $bg, bool $back = false) use ($hex) {
            return function ($im) use ($color, $print, $bg, $back, $hex) {
                imagefilledrectangle($im, 0, 0, 900, 900, $hex($im, $bg));
                imagefilledellipse($im, 450, 800, 620, 70, $hex($im, '#D9D4CC'));
                $c = $hex($im, $color);
                imagefilledpolygon($im, [300, 170, 380, 140, 450, 175, 520, 140, 600, 170, 770, 290, 700, 400, 640, 360, 640, 770, 260, 770, 260, 360, 200, 400, 130, 290], $c);
                $edge = $hex($im, $color === '#FFFFFF' ? '#D8D8D8' : $color);
                imagesetthickness($im, 4);
                imagepolygon($im, [300, 170, 380, 140, 450, 175, 520, 140, 600, 170, 770, 290, 700, 400, 640, 360, 640, 770, 260, 770, 260, 360, 200, 400, 130, 290], $edge);
                imagefilledellipse($im, 450, 150, $back ? 150 : 170, $back ? 40 : 90, $hex($im, $bg));
                if ($color === '#FFFFFF') {
                    imagearc($im, 450, 150, $back ? 150 : 170, $back ? 40 : 90, 0, 180, $hex($im, '#CFCFCF'));
                }
                $p = $hex($im, $print);
                if ($back) {
                    imagefilledrectangle($im, 330, 300, 570, 330, $p);
                    imagefilledrectangle($im, 360, 350, 540, 372, $p);
                } else {
                    imagefilledellipse($im, 450, 360, 150, 150, $p);
                    imagefilledellipse($im, 450, 360, 90, 90, $hex($im, $color));
                    imagefilledrectangle($im, 360, 470, 540, 500, $p);
                }
            };
        };
        $ph = fn (?string $path, ?string $tag) => $path ? ['path' => $path, 'tag' => $tag] : null;
        $photos = array_values(array_filter([
            $ph($png('tshirt-blanc', $shirt('#FFFFFF', '#F28C28', '#EFEBE4')), 'Blanc'),
            $ph($png('tshirt-noir', $shirt('#1F2329', '#F28C28', '#E9EEF3')), 'Noir'),
            $ph($png('tshirt-orange', $shirt('#F28C28', '#FFFFFF', '#F6EFE6')), 'Orange'),
            $ph($png('tshirt-dos', $shirt('#1F2329', '#F28C28', '#EEF0F2', true)), null),
        ]));
        \App\Modules\Sites\Models\SiteProductPage::create(['company_id' => $company->id, 'site_id' => $site->id, 'product_id' => $tshirt->id, 'photos' => $photos ?: null, 'badge' => 'Nouveau',
            'description' => "Notre t-shirt maison, **100 % coton peigné 180 g/m²**, coupe droite unisexe. Le logo Yomara est sérigraphié à Abidjan avec des encres à l'eau.\n\n## Matière et coupe\n\n- Coton peigné, doux et épais\n- Col rond côtelé, coutures renforcées aux épaules\n- Coupe droite : prenez votre taille habituelle\n\n## Guide des tailles\n\n- **S** : tour de poitrine 88–94 cm\n- **M** : 96–102 cm\n- **L** : 104–110 cm\n- **XL** : 112–120 cm (supplément de 500 F)\n\n## Entretien\n\nLavage à 30 °C sur l'envers, pas de sèche-linge, repassage doux en évitant le motif.",
            'related' => [$pagne->id]]);
        $motifs = ['Fleurs de mariage' => ['#B0272F', '#F2C14E', '#1D6F42'], 'Œil de ma rivale' => ['#1B3A6B', '#F28C28', '#FFFFFF'], 'Hirondelles' => ['#0E7C7B', '#F6E7CB', '#D1495B']];
        $wax = [];
        foreach ($motifs as $m => [$a, $b, $c]) {
            $wax[] = $ph($png('pagne-'.\Illuminate\Support\Str::slug($m), function ($im) use ($a, $b, $c, $hex) {
                imagefilledrectangle($im, 0, 0, 900, 900, $hex($im, $a));
                for ($y = 0; $y < 10; $y++) {
                    for ($x = 0; $x < 10; $x++) {
                        $cx = $x * 100 + ($y % 2 ? 50 : 0);
                        imagefilledellipse($im, $cx, $y * 100, 74, 74, $hex($im, $b));
                        imagefilledellipse($im, $cx, $y * 100, 38, 38, $hex($im, $c));
                    }
                }
            }), $m);
        }
        \App\Modules\Sites\Models\SiteProductPage::create(['company_id' => $company->id, 'site_id' => $site->id, 'product_id' => $pagne->id, 'photos' => array_values(array_filter($wax)) ?: null,
            'compare_at' => 17000, 'description' => "Pagne wax **6 yards (5,48 m × 1,16 m)**, idéal pour une tenue complète ou un ensemble pagne-foulard.\n\n## Deux qualités\n\n- **Standard** : coton imprimé recto-verso, couleurs vives\n- **Super wax** : cire plus riche, tenue et brillance supérieures\n\nChaque motif porte un nom et un message : choisissez le vôtre !",
            'related' => [$tshirt->id]]);
        $grocery = Product::whereIn('id', (array) ($site->fresh()->content['product_ids'] ?? []))->first();
        if ($grocery) {
            \App\Modules\Sites\Models\SiteProductPage::create(['company_id' => $company->id, 'site_id' => $site->id, 'product_id' => $grocery->id, 'badge' => 'Best-seller']);
        }
        // Une commande livrée de t-shirts et l'avis du client sur ses variantes
        $vs = Product::where('parent_id', $tshirt->id)->get()->keyBy(fn ($v) => implode('/', (array) $v->variant_values));
        $cart = array_values(array_filter([isset($vs['M/Noir']) ? ['id' => $vs['M/Noir']->id, 'quantity' => 2] : null, isset($vs['L/Orange']) ? ['id' => $vs['L/Orange']->id, 'quantity' => 1] : null]));
        $o = $cart ? rescue(fn () => $shop->placeOrder($site, ['name' => 'Awa Coulibaly', 'phone' => '+225 07 58 69 70 81', 'delivery' => 'pickup', 'payment' => 'cod', 'items' => $cart]), null, false) : null;
        if ($o) {
            app()->instance('tenant', $company);
            $shop->setStatus($o, 'delivered', null, true);
            $o->forceFill(['created_at' => now()->subDays(8), 'review_requested_at' => now()->subDays(6)])->save();
            $rv = $shop->saveReview($site, $o, ['rating' => 5, 'comment' => 'Tissu épais et doux, la taille M tombe parfaitement. Le orange est encore plus beau en vrai !', 'products' => collect($cart)->mapWithKeys(fn ($c) => [$c['id'] => 5])->all()]);
            $rv->forceFill(['created_at' => now()->subDays(5)])->save();
        }
    }

    /** Sites vitrines : site publié de la démo (visites, messages), demandes de clients (modèle, sur mesure avec devis). */
    protected function sitesVitrines(Company $company): void
    {
        app()->instance('tenant', $company);
        $svc = app(\App\Modules\Sites\SiteService::class);
        $site = $svc->create($company, 'boutique-eclat', 'Démo SARL');
        $svc->update($site, [
            'theme' => ['primary' => '#1F6FEB', 'dark' => '#0B1B3A', 'accent' => '#FFC531', 'background' => '#F4F6FB'],
            'content' => [
                'hero_title' => 'Tout pour la maison, au meilleur prix', 'hero_subtitle' => 'Épicerie, boissons et produits du quotidien à Marcory. Commandez sur WhatsApp, nous livrons dans tout Abidjan.',
                'cta_label' => 'Commander sur WhatsApp', 'cta_target' => 'whatsapp', 'about_title' => 'Votre commerce de proximité',
                'about_text' => "Depuis 2015, Démo SARL approvisionne familles, restaurants et bureaux d'Abidjan.\nDes prix justes, des produits toujours disponibles et une livraison rapide.",
                'announcement' => 'Livraison offerte dès 25 000 F d\'achat ce mois-ci',
                'services' => [['icon' => 'bag', 'title' => 'Épicerie', 'text' => 'Riz, huile, sucre, conserves et produits frais.'], ['icon' => 'truck', 'title' => 'Livraison', 'text' => 'Dans la journée, partout à Abidjan.'],
                    ['icon' => 'briefcase', 'title' => 'Comptes professionnels', 'text' => 'Restaurants et entreprises : facture mensuelle.'], ['icon' => 'clock', 'title' => '7 jours sur 7', 'text' => 'De 7 h à 21 h, même le dimanche.']],
                'stats' => [['value' => '10 ans', 'label' => 'à votre service'], ['value' => '1 200+', 'label' => 'clients fidèles'], ['value' => '600', 'label' => 'références en rayon'], ['value' => '2 h', 'label' => 'délai de livraison moyen']],
                'testimonials' => [['name' => 'Mariam O.', 'role' => 'Cliente à Marcory', 'text' => 'Je commande sur WhatsApp le matin, je suis livrée avant midi.'],
                    ['name' => 'Restaurant Le Baobab', 'role' => 'Client professionnel', 'text' => 'Un fournisseur fiable pour nos boissons et notre épicerie.']],
                'faq' => [['q' => 'Livrez-vous à Yopougon et Abobo ?', 'a' => 'Oui, dans toutes les communes d\'Abidjan (frais selon la distance).'], ['q' => 'Quels moyens de paiement acceptez-vous ?', 'a' => 'Espèces, Orange Money, MTN, Moov, Wave et carte bancaire.']],
                'product_ids' => \App\Modules\Core\Models\Product::where('active', true)->where('sale_price', '>', 0)->where(fn ($q) => $q->where('name', 'like', 'Riz%')->orWhere('name', 'like', 'Huile%')->orWhere('name', 'like', 'Sucre%')->orWhere('name', 'like', 'Lait%')->orWhere('name', 'like', 'Spaghetti%')->orWhere('name', 'like', 'Coca%')->orWhere('name', 'like', 'Eau%')->orWhere('name', 'like', 'Bière%'))->limit(8)->pluck('id')->all(),
                'hours' => "Lundi – samedi : 7 h – 21 h\nDimanche : 8 h – 14 h", 'socials' => ['facebook' => 'facebook.com/demosarl', 'instagram' => 'instagram.com/demosarl'],
            ],
            'sections' => ['hero', 'services', 'products', 'about', 'stats', 'events', 'testimonials', 'booking', 'faq', 'contact'],
            'seo' => ['title' => 'Démo SARL — épicerie et livraison à Abidjan', 'description' => 'Épicerie, boissons et produits du quotidien livrés dans tout Abidjan.'],
        ]);
        $svc->publish($site, true);
        $site->forceFill(['published_at' => now()->subDays(40)])->save();
        // Boutique en ligne et blog
        $shop = app(\App\Modules\Sites\ShopService::class);
        $shop->saveSettings($site, ['enabled' => true, 'pickup' => true, 'pickup_address' => 'Boulevard VGE, Marcory (face pharmacie)', 'free_from' => 50000, 'min_order' => 2000,
            'zones' => [['name' => 'Marcory, Koumassi, Treichville', 'fee' => 1000], ['name' => 'Plateau, Cocody, Yopougon', 'fee' => 2000], ['name' => 'Abobo, Bingerville, Port-Bouët', 'fee' => 3000]],
            'payment_online' => true, 'payment_cod' => true, 'payment_momo' => true, 'momo_instructions' => 'Orange Money ou Wave au +225 07 00 00 00 00, au nom de Démo SARL.',
            'note' => 'Commandez avant 14 h : livraison le jour même dans tout Abidjan.',
            'categories' => \App\Modules\Core\Models\ProductCategory::whereIn('name', ['Boissons', 'Épicerie', 'Produits frais', 'Hygiène', 'Textile & goodies'])->pluck('id')->all()]);
        $pids = $site->fresh()->content['product_ids'] ?? [];
        foreach ([
            ['Mariam Ouattara', '+225 07 08 09 10 11', 'mariam.ouattara@exemple.ci', 'Marcory, Koumassi, Treichville', 'Résidence Les Lauriers, bât. B', 'cod', [0 => 2, 3 => 1, 5 => 6], 'delivered', true, 52],
            ['Hervé Konan', '+225 05 22 33 44 55', null, 'Plateau, Cocody, Yopougon', 'Cocody Angré 8e tranche, villa 214', 'momo', [1 => 3, 2 => 2], 'shipped', true, 20],
            ['Linda Kra', '+225 01 44 55 66 77', 'linda.kra@exemple.ci', 'pickup', null, 'cod', [4 => 4, 6 => 2], 'preparing', false, 6],
            ['Patrick Zadi', '+225 07 66 12 34 98', null, 'Abobo, Bingerville, Port-Bouët', 'Abobo Avocatier, près du marché', 'cod', [0 => 1, 7 => 12], 'new', false, 1],
        ] as [$nm, $ph, $em, $zone, $addr, $pay, $items, $status, $paid, $hoursAgo]) {
            $cart = [];
            foreach ($items as $i => $q) {
                if (isset($pids[$i])) {
                    $cart[] = ['id' => $pids[$i], 'quantity' => $q];
                }
            }
            if (! $cart) {
                continue;
            }
            $o = rescue(fn () => $shop->placeOrder($site, ['name' => $nm, 'phone' => $ph, 'email' => $em, 'delivery' => $zone, 'address' => $addr, 'payment' => $pay, 'items' => $cart]), null, false);
            if ($o) {
                app()->instance('tenant', $company);
                if ($status !== 'new') {
                    $shop->setStatus($o, $status, null, $paid);
                }
                $o->forceFill(['created_at' => now()->subHours($hoursAgo)])->save();
            }
        }
        // Codes promo, avis vérifiés, paniers abandonnés
        app()->instance('tenant', $company);
        $shop->saveCoupon($site, null, ['code' => 'BIENVENUE', 'type' => 'percent', 'value' => 10, 'min_order' => 10000, 'once_per_customer' => true, 'description' => 'Première commande (réseaux sociaux)']);
        $shop->saveCoupon($site, null, ['code' => 'LIVRAISON', 'type' => 'shipping', 'min_order' => 15000, 'ends_on' => now()->addDays(20)->toDateString(), 'description' => 'Campagne WhatsApp de novembre']);
        $shop->saveCoupon($site, null, ['code' => 'TABASKI', 'type' => 'amount', 'value' => 2000, 'min_order' => 20000, 'max_uses' => 100, 'active' => false, 'description' => 'Opération Tabaski']);
        foreach ([
            ['Fatou Diallo', '+225 07 41 52 63 74', [0 => 6, 2 => 6, 3 => 1], 5, 'Livraison rapide et produits bien emballés. Je recommande !', 'BIENVENUE', 'Merci Fatou, à très bientôt !'],
            ['Serge Kouassi', '+225 05 85 96 07 18', [1 => 4, 5 => 2], 4, 'Bon service, le livreur a appelé avant de venir.', null, null],
            ['Inès N\'Guessan', '+225 01 52 63 74 85', [3 => 1, 6 => 2], 5, 'Prix corrects et commande reçue le jour même.', null, null],
        ] as [$nm, $ph, $items, $note, $comment, $code, $reply]) {
            $cart = [];
            foreach ($items as $i => $q) {
                if (isset($pids[$i])) {
                    $cart[] = ['id' => $pids[$i], 'quantity' => $q];
                }
            }
            $o = $cart ? rescue(fn () => $shop->placeOrder($site, ['name' => $nm, 'phone' => $ph, 'delivery' => 'Marcory, Koumassi, Treichville', 'address' => 'Marcory Zone 4', 'payment' => 'cod', 'items' => $cart, 'coupon' => $code]), null, false) : null;
            if (! $o) {
                continue;
            }
            app()->instance('tenant', $company);
            $shop->setStatus($o, 'delivered', null, true);
            $o->forceFill(['created_at' => now()->subDays(6), 'review_requested_at' => now()->subDays(4)])->save();
            $rv = $shop->saveReview($site, $o, ['rating' => $note, 'comment' => $comment, 'products' => collect($cart)->mapWithKeys(fn ($c) => [$c['id'] => $note])->all()]);
            $rv->forceFill(['reply' => $reply, 'replied_at' => $reply ? now()->subDays(2) : null, 'created_at' => now()->subDays(4)])->save();
        }
        foreach ([
            ['Koffi Yao', '+225 07 12 21 34 43', 'koffi.yao@exemple.ci', [0 => 3, 4 => 1], 26, true, false],
            ['Aïcha Bamba', '+225 05 34 43 56 65', null, [2 => 6], 5, false, false],
            ['Jean-Marc Aka', '+225 01 98 87 76 65', 'jm.aka@exemple.ci', [1 => 2, 3 => 1], 30, true, true],
        ] as [$nm, $ph, $em, $items, $hoursAgo, $reminded, $recovered]) {
            $cart = [];
            foreach ($items as $i => $q) {
                if (isset($pids[$i])) {
                    $cart[] = ['id' => $pids[$i], 'quantity' => $q];
                }
            }
            $ct = $cart ? rescue(fn () => $shop->saveCart($site, ['name' => $nm, 'phone' => $ph, 'email' => $em, 'items' => $cart]), null, false) : null;
            if ($ct) {
                $ct->forceFill(['updated_at' => now()->subHours($hoursAgo), 'created_at' => now()->subHours($hoursAgo), 'reminded_at' => $reminded ? now()->subHours($hoursAgo - 3) : null,
                    'reminder_channel' => $reminded ? 'email' : null])->save();
                if ($recovered) {
                    $o = rescue(fn () => $shop->placeOrder($site, ['name' => $nm, 'phone' => $ph, 'email' => $em, 'delivery' => 'pickup', 'payment' => 'cod', 'items' => $cart, 'cart_token' => $ct->token]), null, false);
                    app()->instance('tenant', $company);
                    $o?->forceFill(['created_at' => now()->subHours($hoursAgo - 5)])->save();
                }
            }
        }
        $this->fichesProduits($company, $site);
        app()->instance('tenant', $company);
        foreach ([
            ['5 astuces pour faire des économies sur vos courses', 'Liste, achats groupés, marques locales : nos conseils pour réduire la facture sans se priver.',
                "## Faites une liste… et tenez-vous-y\n\nUne liste préparée à la maison évite les achats impulsifs. Classez-la par rayon pour aller plus vite.\n\n## Achetez en gros ce qui se conserve\n\nRiz, huile, sucre, conserves : le **format familial** revient moins cher au kilo.\n\n- Riz en sac de 25 kg\n- Huile en bidon de 5 L\n- Sucre en carton\n\n## Pensez aux produits locaux\n\nSouvent moins chers et tout aussi bons. Retrouvez-les dans [notre boutique en ligne](https://exemple.ci/boutique).", 9],
            ['Nouveau : la livraison le jour même dans tout Abidjan', 'Commandez avant 14 h sur le site ou sur WhatsApp, vous êtes livré le soir même.',
                "Bonne nouvelle : nous livrons désormais **le jour même** dans toutes les communes d'Abidjan pour toute commande passée avant 14 h.\n\nLa livraison est **offerte dès 50 000 F** d'achat. Vous payez en ligne, à la livraison ou par Mobile Money.", 3],
            ['Comptes professionnels : simplifiez vos achats', 'Restaurants, maquis, bureaux : une facture mensuelle et des tarifs dédiés.',
                "Vous gérez un restaurant, un maquis ou une entreprise ? Ouvrez un **compte professionnel** :\n\n- tarifs dégressifs selon les volumes ;\n- livraison planifiée deux fois par semaine ;\n- une seule facture en fin de mois.\n\nContactez-nous via le formulaire du site pour en profiter.", 1],
        ] as [$title, $excerpt, $body, $daysAgo]) {
            $post = $shop->savePost($site, null, ['title' => $title, 'excerpt' => $excerpt, 'body' => $body, 'published' => true]);
            $post->forceFill(['published_at' => now()->subDays($daysAgo)->setTime(9, 30), 'views' => 40 + $daysAgo * 23])->save();
        }
        $svc->update($site->fresh(), ['sections' => ['hero', 'services', 'products', 'about', 'stats', 'events', 'testimonials', 'blog', 'booking', 'faq', 'contact']]);
        $refs = ['direct', 'facebook.com', 'google.com', 'instagram.com', 'wa.me'];
        for ($i = 29; $i >= 0; $i--) {
            $n = 18 + ($i * 7 % 23) + (in_array(now()->subDays($i)->dayOfWeekIso, [6, 7], true) ? 14 : 0);
            $r = [];
            foreach ($refs as $k => $ref) {
                $r[$ref] = (int) round($n * [0.42, 0.27, 0.16, 0.1, 0.05][$k]);
            }
            \Illuminate\Support\Facades\DB::table('ws_visits')->insert(['site_id' => $site->id, 'date' => now()->subDays($i)->toDateString(), 'views' => array_sum($r), 'referrers' => json_encode($r)]);
        }
        foreach ([
            ['Aïcha Bamba', 'aicha.bamba@exemple.ci', '+225 07 58 12 34 56', 'Commande pour un mariage', 'Bonjour, nous organisons un mariage le 15 novembre : pouvez-vous livrer 20 casiers de boissons et 10 sacs de riz à Cocody ?', true, 2],
            ['Restaurant Chez Fatou', 'chezfatou@exemple.ci', '+225 05 44 21 09 87', 'Compte professionnel', 'Nous souhaitons ouvrir un compte professionnel avec livraison deux fois par semaine.', false, 26],
            ['Paul Tanoh', null, '+225 01 23 45 67 89', null, 'Avez-vous du riz parfumé en sac de 25 kg ? Quel prix ?', false, 50],
        ] as [$name, $email, $phone, $subject, $msg, $consent, $hoursAgo]) {
            $m = $svc->contact($site, ['name' => $name, 'email' => $email, 'phone' => $phone, 'subject' => $subject, 'message' => $msg, 'consent' => $consent]);
            $m->forceFill(['created_at' => now()->subHours($hoursAgo), 'read_at' => $hoursAgo > 24 ? now()->subHours($hoursAgo - 2) : null])->save();
        }

        // Clients de la plateforme : demande d'un modèle, site sur mesure avec devis, demande à chiffrer
        $setup = app(CompanySetup::class);
        $plan = Plan::where('code', 'entreprise')->first();
        $mk = fn (string $name, string $email, string $phone, string $city) => $setup->create(['name' => $name, 'email' => $email, 'phone' => $phone, 'city' => $city],
            ['name' => 'Gérant '.$name, 'email' => $email, 'password' => 'password'], $plan)['company'];
        $baobab = $mk('Restaurant Le Baobab', 'baobab@demo.ci', '+225 07 11 22 33 44', 'Cocody');
        $clinique = $mk('Clinique Les Palmiers', 'palmiers@demo.ci', '+225 27 22 00 11 22', 'Abidjan');
        $garage = $mk('Garage Express Yopougon', 'garage@demo.ci', '+225 05 66 77 88 99', 'Yopougon');
        $svc->submit($baobab, null, ['type' => 'template', 'template' => 'restaurant-elegance', 'site_name' => 'Le Baobab']);
        $r = $svc->submit($clinique, null, ['type' => 'custom', 'site_name' => 'Clinique Les Palmiers', 'sector' => 'sante', 'needs' => [
            'pages' => ['Accueil', 'La clinique', 'Spécialités', 'Médecins', 'Rendez-vous', 'Actualités', 'Contact'], 'features' => ['booking', 'multilingual', 'blog', 'seo', 'photos'],
            'description' => 'Site de la clinique avec la liste des spécialités, l\'équipe médicale et la prise de rendez-vous en ligne par spécialité. Version anglaise pour les expatriés.',
            'colors' => 'Bleu et vert, comme notre logo', 'deadline' => now()->addDays(45)->toDateString(), 'has_logo' => true]]);
        app()->instance('tenant', $company);
        $svc->quote($r, null, 'Réalisation en 4 semaines après validation de la maquette.', 5);
        $svc->submit($garage, null, ['type' => 'custom', 'site_name' => 'Garage Express', 'sector' => 'auto', 'needs' => [
            'pages' => ['Accueil', 'Services', 'Pièces détachées', 'Contact'], 'features' => ['ecommerce', 'payment', 'logo'], 'urgent' => true,
            'description' => 'Vendre nos pièces détachées en ligne avec paiement Mobile Money et prise de rendez-vous pour l\'entretien.', 'budget' => 500000]]);
        app()->instance('tenant', $company);
    }

    /** SMS Mobile Money reçus : rapprochés automatiquement, à traiter, sortants. */
    protected function encaissementsMobileMoney(Company $company): void
    {
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $acc = \App\Modules\Tresorerie\Models\TreasuryAccount::where('kind', 'mobile_money')->first();
        if (! $acc) {
            app()->forgetInstance('tenant');

            return;
        }
        $svc = app(\App\Modules\Tresorerie\Momo\MomoService::class);
        $svc->token($acc);
        $fmt = fn ($v) => number_format($v, 0, '', ' ');
        // Factures ouvertes de clients avec téléphone : deux règlements reçus par SMS, reconnus tout seuls
        $open = \App\Modules\Ventes\Models\SalesDocument::where('status', 'validated')->where('type', 'invoice')->where('payment_status', 'unpaid')->whereNotNull('partner_id')
            ->orderBy('due_date')->get()->filter(fn ($d) => $d->partner?->phone && $d->balance() < 600000)->unique('partner_id')->take(2)->values();
        foreach ($open as $i => $d) {
            $p = $d->partner;
            $amount = $d->amountDueNow() ?: $d->balance();
            $text = $i === 0
                ? 'Vous avez recu '.(int) $amount.' FCFA du '.preg_replace('/\D/', '', $p->phone).' ('.mb_strtoupper($p->name).'). Ref: CI'.now()->format('ymd').'.0'.($i + 7).'21.A'.random_int(10000, 99999).'. Nouveau solde: 2 '.random_int(100, 900).' 000 FCFA.'
                : 'Vous avez reçu '.$fmt($amount).' FCFA de '.mb_strtoupper($p->name).' (225'.preg_replace('/\D/', '', $p->phone).'). Transaction ID: '.random_int(1000000000, 9999999999).'. Message: '.$d->number.'.';
            $svc->receive($company, $acc, $text, $i === 0 ? 'OrangeMoney' : 'MTN MoMo', now()->subHours(5 - $i));
        }
        // À traiter : expéditeur inconnu ; sortie
        $svc->receive($company, $acc, 'Vous avez reçu 45.000F de Konan Serge (07 58 21 33 90). Nouveau solde : 3.612.500F. ID: T_DM'.random_int(100000, 999999), 'Wave', now()->subHours(2));
        // Boutique : un abonnement mensuel qui arrive à échéance, un annuel, un essai
        foreach ([['fidelite', 'monthly', 5], ['signature', 'yearly', 210], ['recrutement', 'trial', 9]] as [$key, $billing, $days]) {
            \App\Models\CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => $key], ['enabled' => true, 'source' => $billing === 'trial' ? 'trial' : 'purchase', 'billing' => $billing,
                'expires_at' => now()->addDays($days)->setTime(23, 0), 'trial_used' => $billing === 'trial']);
        }
        $svc->receive($company, $acc, 'Vous avez envoyé 12.500F à Transport Express (05 44 12 12 12). Frais : 125F. Nouveau solde : 3.600.000F', 'Wave', now()->subHour());
        app()->forgetInstance('tenant');
    }

    /** Conditions de paiement par client / fournisseur, facture à échéancier, historique de retards de paiement. */
    protected function conditionsPaiement(Company $company): void
    {
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $T = \App\Modules\Core\Models\PaymentTerm::class;
        $T::ensureDefaults((int) $company->setting('payment_terms_days', 30));
        $s = (array) $company->settings;
        $s['_setup']['core'] = 1;
        $company->forceFill(['settings' => $s])->saveQuietly();
        $t = fn (string $name) => $T::where('name', $name)->value('id');
        $set = function (string $partner, string $field, ?string $term) {
            Partner::where('name', $partner)->get()->each(fn ($p) => $p->forceFill([$field => $term])->save());
        };
        $set('Hôtel Lagune', 'payment_term_id', $t('Acompte 30 % à la commande, solde à 30 jours'));
        $set('Clinique Les Palmiers', 'payment_term_id', $t('45 jours fin de mois'));
        $set('Restaurant Le Baobab', 'payment_term_id', $t('Comptant (à réception)'));
        $set('Network Pro Africa', 'supplier_payment_term_id', $t('30 jours fin de mois'));
        $set('Tech Distribution CI', 'supplier_payment_term_id', $t('45 jours fin de mois'));
        // Facture à échéancier : acompte de 30 % échu et non réglé, solde à 30 jours
        $hotel = Partner::where('name', 'Hôtel Lagune')->first();
        if ($hotel) {
            $w = app(\App\Services\EntityWriter::class);
            $id = (string) \Illuminate\Support\Str::uuid();
            $r = $w->upsert(\App\Modules\Ventes\Models\SalesDocument::class, $id, ['type' => 'invoice', 'partner_id' => $hotel->id, 'date' => now()->subDays(12)->toDateString(), 'reference' => 'LAG-26 / phase 2',
                'notes' => 'Moteur de réservation et paiement Mobile Money', 'lines' => [['id' => (string) \Illuminate\Support\Str::uuid(), 'description' => 'Développement moteur de réservation (phase 2)', 'quantity' => 1, 'unit_price' => 2500000, 'tax_rate' => 18, 'discount_percent' => 0]]]);
            if ($r['status'] === \App\Services\EntityWriter::APPLIED) {
                \App\Modules\Ventes\Models\SalesDocument::find($id)->validateDocument();
            }
        }
        // Historique réaliste : quelques clients paient en retard (le retard moyen est mesuré par la prévision)
        foreach (['Hôtel Lagune' => 25, 'Clinique Les Palmiers' => 35, 'École Les Lauriers' => 18] as $name => $late) {
            $pid = Partner::where('name', $name)->value('id');
            foreach (\Illuminate\Support\Facades\DB::table('trade_documents')->where('company_id', $company->id)->where('partner_id', $pid)->where('type', 'invoice')->where('payment_status', 'paid')->whereNotNull('due_date')->get() as $d) {
                $date = \Carbon\Carbon::parse($d->due_date)->addDays($late);
                if ($date->lte(now()->subDays(45))) { // hors du relevé bancaire du mois rapproché dans la démo
                    \Illuminate\Support\Facades\DB::table('payments')->where('document_id', $d->id)->where('date', '<', now()->subDays(45)->toDateString())->update(['date' => $date->toDateString()]);
                }
            }
        }
        app()->forgetInstance('tenant');
    }

    /** Prévision de trésorerie en trois scénarios ; notes de frais rattachées aux projets. */
    protected function previsionTresorerie(Company $company): void
    {
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $F = \App\Modules\Tresorerie\Models\Forecast::class;
        // La paie est désormais prévue automatiquement depuis le module Paie
        if (\Illuminate\Support\Facades\DB::table('paie_runs')->where('company_id', $company->id)->exists()) {
            $F::where('label', 'Salaires')->get()->each->delete();
        }
        $F::where('label', 'like', 'Ventes comptoir%')->get()->each(fn ($f) => $f->forceFill(['scenarios' => ['optimistic', 'realistic']])->save());
        $mk = fn (array $d) => $F::create($d);
        $mk(['label' => 'Subvention FDFP formation attendue', 'amount' => 2000000, 'date' => now()->addWeeks(6)->toDateString(), 'repeat' => 'none', 'scenarios' => ['optimistic']]);
        $mk(['label' => 'Remplacement du groupe électrogène', 'amount' => -1800000, 'date' => now()->addWeeks(4)->toDateString(), 'repeat' => 'none', 'scenarios' => ['pessimistic']]);
        // Notes de frais approuvées imputées aux projets
        $P = \App\Modules\Projets\Models\Project::class;
        $X = \App\Modules\NotesFrais\Models\Expense::class;
        $pal = $P::where('code', 'PAL-26')->value('id');
        $lag = $P::where('code', 'LAG-26')->value('id');
        foreach ($X::whereIn('status', ['approved', 'paid'])->orderBy('date')->take(3)->get() as $i => $x) {
            $x->forceFill(['project_id' => $i === 1 ? $lag : $pal])->save();
        }
        $svc = app(\App\Modules\Tresorerie\CashForecastService::class);
        $f = $svc->compute();
        // Seuil : au-dessus du point bas pessimiste, sous le point bas réaliste → le scénario pessimiste alerte
        $th = round((($f['scenarios']['pessimistic']['lowest'] + $f['scenarios']['realistic']['lowest']) / 2) / 100000) * 100000;
        $svc->saveSettings($company->fresh(), ['threshold' => $th]);
        app()->instance('tenant', $company->fresh());
        $svc->checkAlert($company->fresh());
        app()->forgetInstance('tenant');
    }

    /** Factures fournisseurs lues automatiquement et rapprochées ; achats et coûts rattachés aux projets. */
    protected function rentabiliteProjets(Company $company): void
    {
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $w = app(\App\Services\EntityWriter::class);
        $put = function (string $class, array $data, ?string $id = null) use ($w) {
            $id ??= (string) \Illuminate\Support\Str::uuid();
            $r = $w->upsert($class, $id, $data);
            if ($r['status'] !== \App\Services\EntityWriter::APPLIED) {
                throw new \RuntimeException(class_basename($class).' : '.json_encode($r));
            }

            return $id;
        };
        $D = \App\Modules\Achats\Models\PurchaseDocument::class;
        $P = \App\Modules\Projets\Models\Project::class;
        $u = User::where('company_id', $company->id)->pluck('id', 'email');
        $clin = $P::where('code', 'PAL-26')->first();
        $lag = $P::where('code', 'LAG-26')->first();
        $net = Partner::where('name', 'Network Pro Africa')->first();
        $tech = Partner::where('name', 'Tech Distribution CI')->first();
        if (! $clin || ! $lag || ! $net || ! $tech) {
            app()->forgetInstance('tenant');

            return;
        }
        $net->forceFill(['tax_id' => '1834567 R', 'phone' => '+225 27 22 41 18 18'])->save();
        $tech->forceFill(['tax_id' => '0712345 B'])->save();
        // Coût horaire chargé : général et par personne
        app(\App\Modules\Projets\ProjectCostService::class)->saveSettings($company, ['hourly_cost' => 6000, 'user_costs' => [$u['koffi@demo.ci'] => 9000, $u['yao@demo.ci'] => 5000, $u['mariam@demo.ci'] => 10000]]);
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $lag->forceFill(['budget_amount' => 900000])->save();
        $clin->forceFill(['budget_amount' => 3000000])->save();
        \App\Modules\Projets\Models\Timesheet::create(['project_id' => $clin->id, 'user_id' => $u['yao@demo.ci'], 'date' => now()->subDay()->toDateString(), 'hours' => 3, 'description' => 'Commande et suivi du matériel']);

        $line = fn (string $desc, float $q, float $price, string $unit = 'pièce') => ['id' => (string) \Illuminate\Support\Str::uuid(), 'description' => $desc, 'quantity' => $q, 'unit' => $unit, 'unit_price' => $price, 'tax_rate' => 18, 'discount_percent' => 0];
        $order = function (Partner $p, array $lines, string $project, string $ref) use ($put, $D) {
            $id = $put($D, ['type' => 'purchase_order', 'partner_id' => $p->id, 'date' => now()->subDays(6)->toDateString(), 'reference' => $ref, 'project_id' => $project, 'lines' => $lines]);
            $D::find($id)->validateDocument();

            return $D::with('lines')->find($id);
        };
        $receive = function ($po, array $qty) use ($put) {
            $put(\App\Modules\Achats\Models\PurchaseReceipt::class, ['order_id' => $po->id, 'date' => now()->subDays(2)->toDateString(), 'supplier_reference' => 'BL-'.random_int(1000, 9999),
                'lines' => $po->lines->values()->map(fn ($l, $i) => ['order_line_id' => $l->id, 'quantity' => $qty[$i]])->filter(fn ($l) => $l['quantity'] > 0)->values()->all()]);
        };
        $invoicePdf = function (string $head, string $ncc, string $number, string $po, array $rows) {
            $f = fn ($v) => number_format($v, 0, ',', ' ');
            $ht = array_sum(array_map(fn ($r) => $r[1] * $r[2], $rows));
            $p = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
            $p->setPrintHeader(false);
            $p->setPrintFooter(false);
            $p->AddPage();
            $p->SetFont('dejavusans', '', 10);
            $tr = implode('', array_map(fn ($r) => '<tr><td>'.$r[0].'</td><td align="right">'.$r[1].'</td><td align="right">'.$f($r[2]).'</td><td align="right">'.$f($r[1] * $r[2]).'</td></tr>', $rows));
            $p->writeHTML('<h2>'.$head.'</h2><p>NCC : '.$ncc.' · Abidjan, Côte d\'Ivoire</p><h1>FACTURE N° '.$number.'</h1><p>Date de facture : '.now()->subDay()->format('d/m/Y').'<br>Votre commande : '.$po.'</p>'
                .'<table border="1" cellpadding="4"><tr><th width="50%">Désignation</th><th width="12%">Qté</th><th width="18%">P.U.</th><th width="20%">Montant</th></tr>'.$tr.'</table>'
                .'<p>Total HT : '.$f($ht).' F CFA<br>TVA 18 % : '.$f($ht * 0.18).' F CFA<br><b>Net à payer : '.$f($ht * 1.18).' F CFA</b></p><p>Paiement à 30 jours par virement.</p>');
            $tmp = tempnam(sys_get_temp_dir(), 'inv');
            file_put_contents($tmp, $p->Output('', 'S'));

            return new \Illuminate\Http\UploadedFile($tmp, $number.'.pdf', 'application/pdf', null, true);
        };
        $svc = app(\App\Modules\Fournisseurs\FournisseursService::class);

        // Clinique : switchs reçus et facturés (déposée sur le portail, conforme), bornes Wi-Fi reçues en partie
        $po1 = $order($tech, [$line('Switch 24 ports PoE manageable', 2, 340000), $line('Câble Cat6 (bobine 305 m)', 1, 69000, 'bobine')], $clin->id, 'PAL-26 / switchs');
        $receive($po1, [2, 1]);
        $i1 = $svc->depositInvoice($company, $tech, ['number' => 'TD-6040', 'date' => now()->subDay()->toDateString(), 'amount' => (float) $po1->total, 'order_id' => $po1->id],
            $invoicePdf('TECH DISTRIBUTION CI', '0712345 B', 'TD-6040', $po1->number, [['Switch 24 ports PoE manageable', 2, 340000], ['Câble Cat6 (bobine 305 m)', 1, 69000]]));
        $put(\App\Modules\Fournisseurs\Models\SupplierInvoice::class, ['status' => 'accepted'], $i1->id);
        $D::find(\App\Modules\Fournisseurs\Models\SupplierInvoice::find($i1->id)->supplier_invoice_id)->validateDocument();

        $po2 = $order($net, [$line('Borne Wi-Fi 6 plafonnier', 6, 105000)], $clin->id, 'PAL-26 / Wi-Fi');
        $receive($po2, [4]);
        // Facture reçue par e-mail, importée par l'acheteur : fournisseur, n°, montants et commande retrouvés dans le PDF
        auth()->setUser(User::find($u['admin@demo.ci']));
        $svc->depositInvoice($company, $net, [], $invoicePdf('NETWORK PRO AFRICA SARL', '1834567 R', 'NPA-2026-1187', $po2->number, [['Borne Wi-Fi 6 plafonnier', 4, 105000]]), 'upload', 'Démo Admin');
        // … et une facture qui dépasse ce qui a été reçu
        $po3 = $order($net, [$line('Onduleur 3 kVA rackable', 2, 210000)], $clin->id, 'PAL-26 / onduleurs');
        $receive($po3, [1]);
        $svc->depositInvoice($company, $net, ['number' => 'NPA-2026-1203', 'date' => now()->toDateString(), 'amount' => 495600, 'order_id' => $po3->id],
            $invoicePdf('NETWORK PRO AFRICA SARL', '1834567 R', 'NPA-2026-1203', $po3->number, [['Onduleur 3 kVA rackable', 2, 210000]]));

        // Facture client du jalon atteint (audit) → chiffre d'affaires du projet
        $ms = \App\Modules\Projets\Models\Milestone::where('project_id', $clin->id)->where('status', 'reached')->first();
        if ($ms && $clin->partner_id) {
            $sid = $put(\App\Modules\Ventes\Models\SalesDocument::class, ['type' => 'invoice', 'project_id' => $clin->id, 'partner_id' => $clin->partner_id, 'date' => now()->subDays(3)->toDateString(), 'reference' => 'PAL-26',
                'notes' => 'Jalon « '.$ms->name.' »', 'lines' => [array_merge($line($clin->name.' — '.$ms->name, 1, (float) $ms->amount, 'forfait'), ['tax_rate' => 18])]]);
            \App\Modules\Ventes\Models\SalesDocument::find($sid)->validateDocument();
            $ms->forceFill(['invoice_id' => $sid, 'status' => 'invoiced'])->save();
        }
        // Site de l'hôtel : hébergement et licences achetés pour le projet → budget presque consommé (alerte)
        $po4 = $order(Partner::where('name', 'Librairie de France — Pro')->first() ?? $tech, [$line('Hébergement cloud 12 mois', 1, 180000, 'an'), $line('Licence moteur de réservation', 1, 150000, 'licence')], $lag->id, 'LAG-26');
        foreach ([$clin, $lag] as $p) {
            app(\App\Modules\Projets\ProjectCostService::class)->checkAlert($p->fresh());
        }
        auth()->forgetGuards();
        app()->forgetInstance('tenant');
    }

    /** E-mails et WhatsApp reçus transformés en tickets ; portail fournisseurs (appels d'offres, confirmations, factures déposées). */
    protected function receptionFournisseurs(Company $company): void
    {
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $admin = User::where('email', 'admin@demo.ci')->first();
        auth()->setUser($admin);
        app(\App\Modules\Fournisseurs\FournisseursSetup::class)($company);
        $settings = $company->fresh()->settings ?? [];
        $settings['_setup']['portail_fournisseurs'] = 1;
        $company->forceFill(['settings' => $settings])->saveQuietly();
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $w = app(\App\Services\EntityWriter::class);
        $put = function (string $class, array $data, ?string $id = null) use ($w) {
            $id ??= (string) \Illuminate\Support\Str::uuid();
            $r = $w->upsert($class, $id, $data);
            if ($r['status'] !== \App\Services\EntityWriter::APPLIED) {
                throw new \RuntimeException(class_basename($class).' : '.json_encode($r));
            }

            return $id;
        };
        $pdf = function (string $title) {
            $p = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
            $p->setPrintHeader(false);
            $p->setPrintFooter(false);
            $p->AddPage();
            $p->SetFont('dejavusans', '', 12);
            $p->Write(0, $title);

            return $p->Output('', 'S');
        };

        // ---------------------------------------------------------------- réception e-mail / WhatsApp
        $in = app(\App\Modules\Helpdesk\Inbound\InboundService::class);
        $box = \App\Modules\Helpdesk\Inbound\Mailbox::for($company->id);
        $box->forceFill(['address' => 'support@demo.ci', 'imap_host' => 'mail.demo.ci', 'imap_username' => 'support@demo.ci', 'imap_enabled' => false])->save();
        \Carbon\Carbon::setTestNow(now()->subMinutes(70));
        $in->handleMail($company, new \App\Modules\Helpdesk\Inbound\InboundMail('compta@hotelivoire-plateau.ci', 'Service comptable — Hôtel Ivoire Plateau', 'Relevé de compte d\'octobre incorrect',
            "Bonjour,\n\nLe relevé d'octobre reprend deux fois la facture FAC-2026-00112. Pouvez-vous nous envoyer un relevé corrigé ?\n\nCordialement,\nService comptable", null, 'demo-mail-1@hotelivoire-plateau.ci', [],
            [['name' => 'releve-octobre.pdf', 'mime' => 'application/pdf', 'data' => $pdf('Relevé de compte — octobre 2026')]]), $box);
        \Carbon\Carbon::setTestNow(now()->addMinutes(45));
        $in->handleWhatsapp($company, ['id' => 'demo-wa-1', 'from' => '2250709112233', 'name' => 'Fatou Cissé', 'text' => 'Bonjour, ma commande de pagnes n\'est toujours pas arrivée. Voici le reçu.', 'media' => null], $box);
        $wt = \App\Modules\Helpdesk\Models\Ticket::where('external_id', 'wa:demo-wa-1')->first();
        if ($wt) {
            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
            $in->storeFiles($wt, [['name' => 'recu-commande.png', 'mime' => 'image/png', 'data' => $png]], null);
        }
        \Carbon\Carbon::setTestNow(now()->addMinutes(10));
        $baobab = \App\Modules\Helpdesk\Models\Ticket::where('subject', 'like', 'Erreur sur la facture%')->first();
        if ($baobab) {
            $in->handleMail($company, new \App\Modules\Helpdesk\Inbound\InboundMail($baobab->contact_email ?: 'baobab@exemple.ci', 'Restaurant Le Baobab', 'Re: Erreur sur la facture d\'octobre ['.$baobab->number.']',
                "Oui, déduisez-les du forfait de novembre, merci.\n\nLe sam. 24 oct. 2026 à 17:51, Démo SARL a écrit :\n> Bonjour,", null, 'demo-mail-2@baobab', ['ticket-'.$baobab->id.'@yomara.erp']), $box);
        }
        \Carbon\Carbon::setTestNow();

        // ---------------------------------------------------------------- portail fournisseurs
        $sup = fn (string $name) => Partner::where('name', $name)->first();
        $suppliers = array_values(array_filter([$sup('Librairie de France — Pro'), $sup('Tech Distribution CI'), $sup('Grossiste Adjamé SARL')]));
        foreach (['Bureau Vallée Abidjan' => 'commercial@bureauvallee.ci', 'Network Pro Africa' => 'ventes@networkpro.ci'] as $n => $email) {
            $suppliers[] = Partner::where('name', $n)->first() ?? Partner::create(['name' => $n, 'type' => 'supplier', 'email' => $email, 'city' => 'Abidjan']);
        }
        $svc = app(\App\Modules\Fournisseurs\FournisseursService::class);
        foreach ($suppliers as $p) {
            $svc->access($p);
        }
        $T = \App\Modules\Fournisseurs\Models\Tender::class;
        [$lib, $tech, $gros, $bv, $net] = array_pad($suppliers, 5, null);
        $bid = function (string $tid, $p, array $prices, ?int $days, ?string $comment = null) use ($svc, $company, $T) {
            $t = $T::find($tid);
            $map = [];
            foreach ($t->lines as $i => $l) {
                $map[$l['id']] = $prices[$i] ?? null;
            }
            $svc->submitBid($company, $t, $p, $map, ['delivery_days' => $days, 'comment' => $comment, 'valid_until' => now()->addDays(30)->toDateString()]);
        };
        // 1. Ouvert, deux offres reçues
        $t1 = $put($T, ['title' => 'Fournitures de bureau — 1er trimestre', 'description' => 'Livraison au siège (Marcory), en une fois, facture à 30 jours.', 'delivery_place' => 'Siège, Boulevard VGE, Marcory',
            'deadline' => now()->addDays(5)->setTime(17, 0)->toDateTimeString(), 'invited' => array_values(array_filter([$lib?->id, $bv?->id, $gros?->id])), 'lines' => [
                ['description' => 'Ramette papier A4 80 g (carton de 5)', 'quantity' => 40, 'unit' => 'carton'], ['description' => 'Cartouche toner HP 85A', 'quantity' => 12, 'unit' => 'pièce'],
                ['description' => 'Classeur à levier dos 8 cm', 'quantity' => 100, 'unit' => 'pièce'], ['description' => 'Stylo bille bleu (boîte de 50)', 'quantity' => 6, 'unit' => 'boîte'],
            ]]);
        $put($T, ['status' => 'open'], $t1);
        if ($lib) {
            $bid($t1, $lib, [14500, 38000, 1650, 6500], 3, 'Livraison gratuite au-delà de 500 000 F.');
        }
        if ($bv) {
            $bid($t1, $bv, [13900, 41000, 1500, null], 5);
        }
        // 2. Clos, trois offres : à attribuer
        $t2 = $put($T, ['title' => 'Matériel réseau — Clinique Les Palmiers', 'description' => 'Équipements pour le câblage des étages (projet PAL-26).', 'delivery_place' => 'Clinique Les Palmiers, Cocody',
            'deadline' => now()->addDays(2)->toDateTimeString(), 'invited' => array_values(array_filter([$tech?->id, $net?->id, $gros?->id])), 'lines' => [
                ['description' => 'Switch 24 ports PoE manageable', 'quantity' => 3, 'unit' => 'pièce'], ['description' => 'Borne Wi-Fi 6 plafonnier', 'quantity' => 8, 'unit' => 'pièce'],
                ['description' => 'Câble Cat6 (bobine 305 m)', 'quantity' => 4, 'unit' => 'bobine'],
            ]]);
        $put($T, ['status' => 'open'], $t2);
        if ($tech) {
            $bid($t2, $tech, [385000, 98000, 72000], 7, 'Matériel garanti 3 ans.');
        }
        if ($net) {
            $bid($t2, $net, [360000, 105000, 69000], 10);
        }
        if ($gros) {
            $bid($t2, $gros, [410000, null, 65000], 2);
        }
        $T::find($t2)->forceFill(['status' => 'closed', 'deadline' => now()->subHours(3)])->save();
        // 3. Attribué : bon de commande validé, confirmé par le fournisseur, factures déposées
        $t3 = $put($T, ['title' => 'Mobilier salle de réunion', 'delivery_place' => 'Siège, Marcory', 'deadline' => now()->addDay()->toDateTimeString(), 'invited' => array_values(array_filter([$bv?->id, $lib?->id])), 'lines' => [
            ['description' => 'Table de réunion 10 places', 'quantity' => 1, 'unit' => 'pièce'], ['description' => 'Fauteuil de réunion', 'quantity' => 10, 'unit' => 'pièce'],
        ]]);
        $put($T, ['status' => 'open'], $t3);
        if ($bv && $lib) {
            $bid($t3, $bv, [280000, 35000], 10);
            $bid($t3, $lib, [320000, 42000], 15);
            $po = $svc->award($company, $T::find($t3), $bv->id);
            $put(\App\Modules\Achats\Models\PurchaseDocument::class, ['status' => 'validated'], $po->id);
            $po = $po->fresh();
            $svc->acknowledge($company, $po, $bv, true, now()->addDays(8)->toDateString(), 'Livraison et montage inclus.', 'Aminata (Bureau Vallée)');
            $file = function (string $name, string $data) {
                $tmp = tempnam(sys_get_temp_dir(), 'inv');
                file_put_contents($tmp, $data);

                return new \Illuminate\Http\UploadedFile($tmp, $name, 'application/pdf', null, true);
            };
            $i1 = $svc->depositInvoice($company, $bv, ['number' => 'BV-2026-0412', 'date' => now()->toDateString(), 'amount' => (float) $po->total, 'order_id' => $po->id, 'comment' => 'Facture du mobilier'], $file('BV-2026-0412.pdf', $pdf('Facture BV-2026-0412')));
            $put(\App\Modules\Fournisseurs\Models\SupplierInvoice::class, ['status' => 'accepted'], $i1->id);
        }
        if ($lib) {
            $tmp = tempnam(sys_get_temp_dir(), 'inv');
            file_put_contents($tmp, $pdf('Facture LDF-88213'));
            $svc->depositInvoice($company, $lib, ['number' => 'LDF-88213', 'date' => now()->subDays(2)->toDateString(), 'amount' => 186500, 'comment' => 'Commande téléphonique de cartouches du 20/10'], new \Illuminate\Http\UploadedFile($tmp, 'LDF-88213.pdf', 'application/pdf', null, true));
        }
        if ($tech) {
            $tmp = tempnam(sys_get_temp_dir(), 'inv');
            file_put_contents($tmp, $pdf('Facture TD-5521'));
            $ri = $svc->depositInvoice($company, $tech, ['number' => 'TD-5521', 'date' => now()->subDays(5)->toDateString(), 'amount' => 945000], new \Illuminate\Http\UploadedFile($tmp, 'TD-5521.pdf', 'application/pdf', null, true));
            $put(\App\Modules\Fournisseurs\Models\SupplierInvoice::class, ['status' => 'rejected', 'reject_reason' => 'Aucun bon de commande ne correspond : merci de rattacher la facture au bon BCF concerné.'], $ri->id);
        }

        app()->forgetInstance('tenant');
    }

    /** Service client (tickets à tous les stades, délais, avis, centre d'aide) et feuilles de temps (validée, à valider, à corriger). */
    protected function serviceClient(Company $company): void
    {
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $admin = User::where('email', 'admin@demo.ci')->first();
        auth()->setUser($admin);
        foreach (['helpdesk' => \App\Modules\Helpdesk\HelpdeskSetup::class, 'feuilles_temps' => \App\Modules\Feuilles\FeuillesSetup::class] as $k => $cls) {
            app($cls)($company);
            $settings = $company->fresh()->settings ?? [];
            $settings['_setup'][$k] = 1;
            $company->forceFill(['settings' => $settings])->saveQuietly();
        }
        $settings = $company->fresh()->settings ?? [];
        $settings['feuilles_temps'] = ['require_approval' => true, 'remind_friday' => true];
        $company->forceFill(['settings' => $settings])->saveQuietly();
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $w = app(\App\Services\EntityWriter::class);
        $put = function (string $class, array $data, ?string $id = null) use ($w) {
            $id ??= (string) \Illuminate\Support\Str::uuid();
            $r = $w->upsert($class, $id, $data);
            if ($r['status'] !== \App\Services\EntityWriter::APPLIED) {
                throw new \RuntimeException(class_basename($class).' : '.json_encode($r));
            }

            return $id;
        };
        $u = fn (string $email) => User::where('email', $email)->first();
        $pid = fn (string $name) => Partner::where('name', $name)->value('id');
        $moussa = $u('moussa@demo.ci') ?? $admin;
        $seydou = $u('seydou@demo.ci') ?? $admin;
        $mariam = $u('mariam@demo.ci') ?? $admin;
        $jm = $u('jm@demo.ci') ?? $admin;
        $T = \App\Modules\Helpdesk\Models\Ticket::class;
        $M = \App\Modules\Helpdesk\Models\Message::class;
        $H = \App\Modules\Helpdesk\Models\TimeEntry::class;
        $svc = app(\App\Modules\Helpdesk\HelpdeskService::class);
        $cat = fn (string $n) => \App\Modules\Helpdesk\Models\Category::where('name', $n)->value('id');
        $put(\App\Modules\Helpdesk\Models\Category::class, ['name' => 'Panne ou incident', 'assignee_id' => $moussa->id], \App\Modules\Helpdesk\Models\Category::where('name', 'Panne ou incident')->value('id'));
        $put(\App\Modules\Helpdesk\Models\Category::class, ['name' => 'Facturation', 'assignee_id' => $mariam->id], \App\Modules\Helpdesk\Models\Category::where('name', 'Facturation')->value('id'));
        $put(\App\Modules\Helpdesk\Models\SlaPolicy::class, ['name' => 'Premium — contrat de support', 'business_hours' => false, 'partner_ids' => array_values(array_filter([$pid('Clinique Les Palmiers')])),
            'targets' => ['urgent' => ['first' => 15, 'resolve' => 120], 'high' => ['first' => 30, 'resolve' => 240], 'normal' => ['first' => 60, 'resolve' => 480], 'low' => ['first' => 240, 'resolve' => 1440]]]);
        if (! Partner::where('name', 'Pharmacie du Plateau')->exists()) {
            Partner::create(['name' => 'Pharmacie du Plateau', 'type' => 'customer', 'is_company' => true, 'email' => 'c.nguessan@pharmaplateau.ci', 'phone' => '+225 01 02 03 04 05']);
        }
        $at = function (string $when) {
            \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse($when));
        };
        $base = now()->copy();
        $ago = fn (int $minutes) => $base->copy()->subMinutes($minutes)->toDateTimeString();
        $ticket = function (array $d, int $createdAgo, ?User $as = null) use ($put, $T, $at, $ago, $admin) {
            $at($ago($createdAgo));
            auth()->setUser($as ?? $admin);
            if (in_array($d['channel'] ?? '', ['web', 'portal'], true)) {
                $T::$systemWrite = true;
            }
            try {
                return $put($T, $d);
            } finally {
                $T::$systemWrite = false;
            }
        };
        $reply = function (string $tid, User $who, string $body, int $minutesAgo, bool $internal = false) use ($put, $M, $at, $ago) {
            $at($ago($minutesAgo));
            auth()->setUser($who);
            $put($M, ['ticket_id' => $tid, 'body' => $body, 'internal' => $internal]);
        };
        $customer = function (string $tid, string $body, int $minutesAgo) use ($svc, $T, $at, $ago, $company) {
            $at($ago($minutesAgo));
            $svc->customerMessage($company, $T::find($tid), $body, null, 'web');
        };
        $status = function (string $tid, string $st, User $who, int $minutesAgo) use ($put, $T, $at, $ago) {
            $at($ago($minutesAgo));
            auth()->setUser($who);
            $put($T, ['status' => $st], $tid);
        };
        $time = function (string $tid, User $who, int $minutes, string $note, int $daysAgo = 0) use ($put, $H, $base) {
            auth()->setUser($who);
            $put($H, ['ticket_id' => $tid, 'date' => $base->copy()->subDays($daysAgo)->toDateString(), 'minutes' => $minutes, 'note' => $note]);
        };

        // 1. Clinique (contrat Premium) : panne urgente, en retard sur la résolution
        $t1 = $ticket(['partner_id' => $pid('Clinique Les Palmiers'), 'contact_name' => 'Dr Yao (direction)', 'subject' => 'Le serveur de fichiers ne répond plus', 'description' => "Depuis ce matin, plus personne n'accède aux dossiers patients partagés. Les postes affichent « chemin réseau introuvable ».", 'priority' => 'urgent', 'channel' => 'phone', 'category_id' => $cat('Panne ou incident')], 180);
        $reply($t1, $moussa, 'Le disque du serveur est plein (journaux de sauvegarde). Je libère de l\'espace à distance.', 175, true);
        $reply($t1, $moussa, "Bonjour Docteur,\n\nNous avons identifié la cause : le disque du serveur est saturé. Je libère de l'espace et vous confirme dans l'heure le retour à la normale.\n\nMoussa", 172);
        $time($t1, $moussa, 45, 'Diagnostic et nettoyage du disque');
        $customer($t1, 'Merci, l\'accès revient sur certains postes mais pas à l\'accueil.', 40);
        // 2. Baobab : question de facturation, en attente du client
        $t2 = $ticket(['partner_id' => $pid('Restaurant Le Baobab'), 'subject' => 'Erreur sur la facture d\'octobre', 'description' => 'La facture mentionne 22 h de community management alors que le forfait est de 20 h.', 'priority' => 'normal', 'channel' => 'web', 'category_id' => $cat('Facturation')], 1500);
        $reply($t2, $mariam, "Bonjour,\n\nLes 2 h supplémentaires correspondent au shooting du 12. Souhaitez-vous que nous les déduisions du forfait de novembre ?\n\nMariam", 1380);
        $status($t2, 'pending', $mariam, 1379);
        // 3. Boutique : demande récente sans réponse
        $ticket(['partner_id' => $pid('Boutique Mode Ivoire'), 'subject' => 'Ajouter le paiement Wave sur le site', 'description' => 'Nos clientes demandent à payer avec Wave. Est-ce possible avant la Tabaski ?', 'priority' => 'normal', 'channel' => 'portal', 'category_id' => $cat('Demande de modification')], 95);
        // 4. Hôtel Lagune : résolu, très satisfait
        $t4 = $ticket(['partner_id' => $pid('Hôtel Lagune'), 'subject' => 'Réinitialiser le mot de passe d\'un réceptionniste', 'description' => 'Un réceptionniste a oublié son mot de passe.', 'priority' => 'low', 'channel' => 'email', 'category_id' => $cat('Question')], 7200, $jm);
        $reply($t4, $jm, "Bonjour,\n\nC'est fait : un lien de réinitialisation lui a été envoyé. Le guide est aussi dans notre centre d'aide.\n\nJean-Marc", 7100);
        $status($t4, 'resolved', $jm, 7090);
        $svc->rate($T::find($t4), 5, 'Rapide et clair, merci !');
        // 5. Cabinet Kouadio : priorité haute, résolu avec temps sur le forfait
        $t5 = $ticket(['partner_id' => $pid('Cabinet Kouadio & Associés'), 'subject' => 'Impression des bulletins de paie en PDF', 'description' => 'Le bouton d\'export PDF des bulletins ne fait rien.', 'priority' => 'high', 'channel' => 'whatsapp', 'category_id' => $cat('Panne ou incident')], 2900);
        $reply($t5, $seydou, "Bonjour,\n\nLe navigateur bloquait les fenêtres. Je vous ai envoyé la procédure ; l'export fonctionne maintenant depuis l'application.\n\nSeydou", 2700);
        $time($t5, $seydou, 60, 'Prise en main à distance et correction', 2);
        $status($t5, 'resolved', $seydou, 1500);
        $svc->rate($T::find($t5), 4, 'Résolu, un peu d\'attente le matin.');
        // 6. Nouveau contact par le formulaire en ligne
        $put(\App\Modules\Core\Models\Partner::class, ['name' => 'Nadia Koné', 'type' => 'customer', 'email' => 'nadia.kone@exemple.ci', 'phone' => '+225 07 55 44 33 22']);
        $ticket(['partner_id' => $pid('Nadia Koné'), 'contact_name' => 'Nadia Koné', 'subject' => 'Devis pour un site vitrine', 'description' => 'Bonjour, je lance une boutique de pagnes et souhaite un site avec catalogue. Quel est votre tarif ?', 'priority' => 'normal', 'channel' => 'web', 'category_id' => $cat('Question')], 28);
        // 7. Pharmacie : caisse bloquée, en cours
        $t7 = $ticket(['partner_id' => $pid('Pharmacie du Plateau'), 'contact_name' => 'Christelle N\'Guessan', 'subject' => 'Caisse bloquée au démarrage', 'description' => 'Le logiciel de caisse reste sur l\'écran de chargement.', 'priority' => 'urgent', 'channel' => 'phone'], 50, $seydou);
        $put($T, ['assignee_id' => $seydou->id], $t7);
        $reply($t7, $seydou, "Bonjour Christelle,\n\nRedémarrez le poste en maintenant la touche Maj enfoncée, puis relancez la caisse. Je reste disponible.\n\nSeydou", 35);
        $time($t7, $seydou, 30, 'Assistance téléphonique');
        // 8. Ancien ticket fermé, client mécontent
        $t8 = $ticket(['partner_id' => $pid('Boutique Mode Ivoire'), 'subject' => 'Lenteur du site le samedi', 'description' => 'Les pages produits mettent 10 secondes à s\'afficher.', 'priority' => 'high', 'channel' => 'email', 'category_id' => $cat('Panne ou incident')], 14400);
        $reply($t8, $moussa, 'Bonjour, nous avons activé le cache et compressé les images. Le site doit être plus rapide.', 12000);
        $status($t8, 'resolved', $moussa, 11900);
        $svc->rate($T::find($t8), 2, 'Toujours lent les jours de promo.');
        $status($t8, 'closed', $moussa, 4000);
        \Carbon\Carbon::setTestNow();
        auth()->setUser($admin);
        $svc->runEvery($company);

        // Centre d'aide
        $A = \App\Modules\Helpdesk\Models\Article::class;
        foreach ([
            ['Réinitialiser le mot de passe d\'un employé', 'Compte et accès', "## Depuis l'application\n- Ouvrez **Paramètres → Utilisateurs**\n- Choisissez la personne puis **Réinitialiser le mot de passe**\n- Elle reçoit un lien par e-mail ou WhatsApp\n\nLe lien est valable 24 heures.", 128, 24, 1],
            ['Payer une facture en ligne (Mobile Money, carte)', 'Facturation', "Chaque facture envoyée contient un bouton **Payer en ligne**.\n\n## Moyens acceptés\n- Orange Money, MTN MoMo, Moov Money, Wave\n- Carte bancaire Visa et Mastercard\n\nLa facture passe en « payée » dès la confirmation du paiement.", 96, 18, 2],
            ['Comprendre votre forfait d\'heures', 'Facturation', "Votre forfait est décompté à chaque intervention (ticket, projet ou maintenance).\n\n## Où suivre ma consommation ?\nDans votre **espace client**, onglet « Forfaits & livrables », et dans le rapport d'activité envoyé chaque mois.\n\nÀ 80 % puis à 100 %, vous êtes prévenu par e-mail.", 61, 9, 0],
            ['La caisse ne démarre plus', 'Caisse', "1. Redémarrez le poste en maintenant la touche Maj enfoncée\n2. Vérifiez que l'imprimante ticket est allumée\n3. Relancez l'application\n\nSi la caisse reste bloquée, [ouvrez une demande](https://yomara.ci) en précisant le message affiché. **La vente hors connexion continue de fonctionner.**", 74, 11, 3],
            ['Délais de réponse du support', 'Support', "## Nos engagements\n- Urgente (activité bloquée) : réponse sous 30 minutes ouvrées\n- Haute : sous 1 heure\n- Normale : sous 4 heures\n- Basse : sous 1 jour ouvré\n\nHoraires : du lundi au vendredi, 8 h – 18 h, hors jours fériés.", 40, 6, 0],
            ['Modifier une commande en ligne', 'Site web', "Une commande peut être modifiée tant qu'elle n'est pas expédiée, depuis l'espace client ou en nous écrivant avec son numéro.", 12, 2, 1],
        ] as [$title, $section, $body, $views, $yes, $no]) {
            $aid = $put($A, ['title' => $title, 'section' => $section, 'body' => $body, 'published' => true]);
            $A::whereKey($aid)->update(['views' => $views, 'helpful_yes' => $yes, 'helpful_no' => $no]);
        }
        $put($A, ['title' => 'Procédure interne : escalade vers le fournisseur', 'section' => 'Interne', 'body' => 'Brouillon à compléter.', 'published' => false]);

        // Feuilles de temps de la semaine dernière : validée (Seydou), à valider (Moussa), à corriger (Mariam)
        $mon = now()->startOfWeek()->subWeek();
        $S = \App\Modules\Projets\Models\Timesheet::class;
        $clin = \App\Modules\Projets\Models\Project::where('name', 'like', 'Installation réseau%')->value('id');
        $lag = \App\Modules\Projets\Models\Project::where('name', 'like', 'Site web & réservation%')->value('id');
        foreach ([[$seydou, $clin, [1 => 7, 2 => 7, 3 => 6], 'Câblage des étages'], [$moussa, $clin, [0 => 8, 1 => 8, 2 => 6, 3 => 8, 4 => 7], 'Installation des bornes Wi-Fi'], [$mariam, $lag, [3 => 3], 'Recette avec la direction']] as [$who, $project, $days, $desc]) {
            foreach ($days as $d => $h) {
                if ($project) {
                    $S::create(['project_id' => $project, 'user_id' => $who->id, 'date' => $mon->copy()->addDays($d)->toDateString(), 'hours' => $h, 'description' => $desc, 'billable' => true]);
                }
            }
        }
        $W = \App\Modules\Feuilles\Models\Week::class;
        $week = function (User $who) use ($put, $W, $mon) {
            auth()->setUser($who);

            return $put($W, ['week_start' => $mon->toDateString(), 'status' => 'submitted']);
        };
        $ws = $week($seydou);
        $wm = $week($moussa);
        $wr = $week($mariam);
        auth()->setUser($admin);
        $put($W, ['status' => 'approved'], $ws);
        $put($W, ['status' => 'rejected', 'comment' => 'Indiquez la tâche du jeudi et ajoutez le point client du vendredi.'], $wr);

        app()->forgetInstance('tenant');
    }

    /** Planning des ressources (affectations, surcharge, option, congé), projet décompté d'un forfait, rapports d'activité. */
    protected function planningRapports(Company $company): void
    {
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $admin = User::where('email', 'admin@demo.ci')->first();
        auth()->setUser($admin);
        app(\App\Modules\Planning\PlanningSetup::class)($company);
        $settings = $company->fresh()->settings ?? [];
        $settings['_setup']['planning'] = 1;
        $settings['prestations'] = ['monthly_reports' => true, 'include_projects' => true];
        $company->forceFill(['settings' => $settings])->saveQuietly();
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $w = app(\App\Services\EntityWriter::class);
        $put = function (string $class, array $data, ?string $id = null) use ($w) {
            $id ??= (string) \Illuminate\Support\Str::uuid();
            $r = $w->upsert($class, $id, $data);
            if ($r['status'] !== \App\Services\EntityWriter::APPLIED) {
                throw new \RuntimeException(class_basename($class).' : '.json_encode($r));
            }

            return $id;
        };
        $u = fn (string $email) => User::where('email', $email)->value('id');
        $pid = fn (string $name) => Partner::where('name', $name)->value('id');
        $d = fn (int $days) => now()->addDays($days)->toDateString();
        $prj = fn (string $like) => \App\Modules\Projets\Models\Project::where('name', 'like', $like.'%')->value('id');

        // Projet de la boutique décompté de son forfait de maintenance
        $boutique = $put(\App\Modules\Projets\Models\Project::class, ['name' => 'Boutique en ligne — Mode Ivoire', 'partner_id' => $pid('Boutique Mode Ivoire'), 'billing_mode' => 'time', 'hourly_rate' => 30000,
            'start_date' => $d(-5), 'end_date' => $d(20), 'color' => '#E84393', 'manager_id' => $u('mariam@demo.ci'), 'member_ids' => array_values(array_filter([$u('koffi@demo.ci'), $u('awa@demo.ci')])),
            'pack_id' => \App\Modules\Prestations\Models\Pack::where('name', 'like', 'Maintenance site e-commerce%')->value('id'),
            'description' => 'Évolutions de la boutique WooCommerce, décomptées du forfait de maintenance.']);
        foreach ([[$u('koffi@demo.ci'), -2, 1, 'Paiement Mobile Money sur la boutique'], [$u('awa@demo.ci'), -1, 0.5, 'Bannières de la collection']] as [$who, $day, $h, $desc]) {
            $put(\App\Modules\Projets\Models\Timesheet::class, ['project_id' => $boutique, 'user_id' => $who, 'date' => $d($day), 'hours' => $h, 'description' => $desc, 'billable' => true]);
        }

        // Congé approuvé de Mariam Traoré la semaine prochaine (elle reste affectée : conflit signalé)
        $mon = now()->startOfWeek();
        $emp = \App\Modules\Rh\Models\Employee::where('matricule', 'EMP00003')->first();
        if ($emp) {
            \App\Modules\Rh\Models\Leave::create(['employee_id' => $emp->id, 'type' => 'annual', 'start_date' => $mon->copy()->addDays(9)->toDateString(), 'end_date' => $mon->copy()->addDays(10)->toDateString(),
                'days' => 2, 'status' => 'approved', 'reason' => 'Mariage']);
        }
        $A = \App\Modules\Planning\Models\Assignment::class;
        $w0 = fn (int $days) => $mon->copy()->addDays($days)->toDateString();
        $lagune = $prj('Site web & réservation');
        $clinique = $prj('Installation réseau');
        $plan = [
            ['koffi@demo.ci', $lagune, null, null, -7, 11, 6, 'confirmed'],
            ['koffi@demo.ci', $boutique, null, null, 2, 9, 3, 'confirmed'], // surcharge mercredi → vendredi
            ['awa@demo.ci', $lagune, null, 'Maquettes et traductions', -7, 4, 5, 'confirmed'],
            ['awa@demo.ci', null, 'Restaurant Le Baobab', 'Community management', 0, 25, 3, 'confirmed'],
            ['jm@demo.ci', $lagune, null, 'Paiement Mobile Money', 7, 18, 6, 'confirmed'],
            ['jm@demo.ci', null, 'Banque Atlantique', 'Application mobile (devis en attente)', 21, 34, 8, 'tentative'],
            ['mariam@demo.ci', $lagune, null, 'Pilotage et recette', -7, 25, 2, 'confirmed'],
            ['mariam@demo.ci', $boutique, null, null, 0, 18, 2, 'confirmed'],
            ['mariam.traore@demo.ci', null, 'Cabinet Kouadio & Associés', 'Formation Yomara ERP', 7, 11, 6, 'confirmed'],
            ['moussa@demo.ci', $clinique, null, null, -7, 4, 8, 'confirmed'],
            ['moussa@demo.ci', null, 'Clinique Les Palmiers', 'Support réseau', 7, 20, 4, 'confirmed'],
            ['seydou@demo.ci', $clinique, null, 'Câblage des étages', 0, 11, 7, 'confirmed'],
            ['fatou@demo.ci', null, null, 'Salon de l\'Agriculture — préparation', 14, 27, 4, 'tentative'],
        ];
        if (! Partner::where('name', 'Banque Atlantique')->exists()) {
            Partner::create(['name' => 'Banque Atlantique', 'type' => 'customer', 'is_company' => true, 'email' => 'digital@banqueatlantique.ci', 'city' => 'Abidjan']);
        }
        foreach ($plan as [$email, $project, $client, $title, $from, $to, $hpd, $status]) {
            if (! ($uid = $u($email))) {
                continue;
            }
            $put($A, array_filter(['user_id' => $uid, 'project_id' => $project, 'partner_id' => $client ? $pid($client) : null, 'title' => $title, 'start_date' => $w0($from), 'end_date' => $w0($to),
                'hours_per_day' => $hpd, 'status' => $status], fn ($v) => $v !== null));
        }

        // Rapports d'activité du mois dernier (envoyés) et du mois en cours (brouillon)
        $rs = app(\App\Modules\Prestations\ReportService::class);
        $last = now()->subMonthNoOverflow()->format('Y-m');
        foreach (['Clinique Les Palmiers', 'Restaurant Le Baobab', 'Boutique Mode Ivoire', 'Hôtel Lagune'] as $name) {
            $partner = Partner::where('name', $name)->first();
            if ($partner && $rs->hasActivity($rs->data($company, $partner->id, $last))) {
                $r = $rs->generate($company, $partner->id, $last);
                $r->forceFill(['sent_at' => now()->startOfMonth()->addHours(7)->addMinutes(30), 'sent_to' => $partner->email ?: $partner->phone])->save();
            }
        }
        $rs->generate($company, $pid('Restaurant Le Baobab'), now()->format('Y-m'), $admin);

        app()->forgetInstance('tenant');
    }

    /** Billetterie (salon et forum du jour avec entrées), acompte en ligne, forfaits d'heures et livrables. */
    protected function billetterieForfaits(Company $company): void
    {
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $admin = User::where('email', 'admin@demo.ci')->first();
        auth()->setUser($admin);
        foreach (['billetterie' => \App\Modules\Billetterie\BilletterieSetup::class, 'prestations' => \App\Modules\Prestations\PrestationsSetup::class] as $k => $cls) {
            app($cls)($company);
            $settings = $company->fresh()->settings ?? [];
            $settings['_setup'][$k] = 1;
            $company->forceFill(['settings' => $settings])->saveQuietly();
        }
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $w = app(\App\Services\EntityWriter::class);
        $put = function (string $class, array $data, ?string $id = null) use ($w) {
            $id ??= (string) \Illuminate\Support\Str::uuid();
            $r = $w->upsert($class, $id, $data);
            if ($r['status'] !== \App\Services\EntityWriter::APPLIED) {
                throw new \RuntimeException(class_basename($class).' : '.json_encode($r));
            }

            return $id;
        };
        $pay = function (?string $docId, string $method = 'mobile_money') {
            $d = $docId ? \App\Modules\Ventes\Models\SalesDocument::find($docId) : null;
            if (! $d) {
                return;
            }
            $p = new \App\Modules\Ventes\Models\SalesPayment(['document_id' => $d->id, 'date' => now()->toDateString(), 'amount' => (float) $d->total - (float) $d->amount_paid, 'method' => $method]);
            $p->partner_id = $d->partner_id;
            $p->save();
            $d->refreshPaymentStatus();
        };
        $E = \App\Modules\Evenements\Models\Event::class;
        $T = \App\Modules\Billetterie\Models\TicketType::class;
        $O = \App\Modules\Billetterie\Models\TicketOrder::class;
        $svc = app(\App\Modules\Billetterie\TicketService::class);
        $names = ['Koffi Yao', 'Awa Traoré', 'Mamadou Koné', 'Aïcha Bamba', 'Serge Kouassi', 'Fatou Diallo', 'Jean-Marc Aka', 'Mariam Ouattara', 'Didier Gbagbo', 'Inès N\'Guessan', 'Paul Tanoh', 'Nadia Coulibaly',
            'Hervé Konan', 'Salimata Cissé', 'Eric Brou', 'Grâce Assi', 'Olivier Dago', 'Rokia Sangaré', 'Patrick Zadi', 'Linda Kra'];

        // Salon : billetterie en ligne, jauge, acompte en ligne, badges des exposants
        $salon = $E::where('status', 'open')->first();
        $put($E, ['ticketing_enabled' => true, 'capacity' => 15000, 'online_deposit' => true], $salon->id);
        $types = [];
        foreach ([
            ['Pass 1 jour', 'visitor', 2000, null, '#1F6FEB', 'Accès aux halls et aux concours, un jour au choix', false],
            ['Pass 5 jours', 'visitor', 7500, null, '#0FA3B1', 'Accès illimité pendant tout le salon', true],
            ['Pass VIP', 'vip', 25000, 300, '#8E44AD', 'Salon VIP, déjeuner, parking réservé', true],
            ['Entrée étudiants (sur inscription)', 'visitor', 0, 500, '#12A150', 'Gratuit, carte d\'étudiant demandée', false],
            ['Badge exposant', 'exhibitor', 0, null, '#12A150', null, true],
            ['Badge organisation', 'staff', 0, null, '#0B1B3A', null, true],
            ['Badge presse', 'press', 0, null, '#E8A317', null, true],
        ] as $i => [$name, $kind, $price, $qty, $color, $desc, $reentry]) {
            $types[$name] = $put($T, ['event_id' => $salon->id, 'name' => $name, 'kind' => $kind, 'price' => $price, 'quantity' => $qty, 'color' => $color, 'description' => $desc, 'reentry' => $reentry, 'sort' => $i]);
        }
        $one = $T::find($types['Pass 1 jour']);
        $put($T, ['valid_from' => $salon->start_date->toDateString(), 'valid_to' => $salon->end_date->toDateString()], $one->id);
        foreach ([[['Pass 1 jour', 4], ['Pass VIP', 1]], [['Pass 5 jours', 2]], [['Pass 1 jour', 6]], [['Pass VIP', 2]], [['Entrée étudiants (sur inscription)', 3]], [['Pass 1 jour', 2], ['Pass 5 jours', 1]], [['Pass VIP', 4]], [['Pass 1 jour', 10]]] as $i => $items) {
            $online = $i % 3 !== 2;
            $buyer = $names[$i % count($names)];
            $id = (string) \Illuminate\Support\Str::uuid();
            $O::$systemWrite = $online;
            try {
                $put($O, array_filter(['event_id' => $salon->id, 'buyer_name' => $buyer, 'buyer_phone' => '+225 07 '.(40 + $i).' 11 22 33', 'buyer_email' => \Illuminate\Support\Str::slug($buyer).'@exemple.ci',
                    'items' => array_map(fn ($x) => ['type_id' => $types[$x[0]], 'quantity' => $x[1]], $items), 'status' => $online ? 'pending' : 'paid', 'payment_method' => $online ? null : 'cash']), $id);
            } finally {
                $O::$systemWrite = false;
            }
            $o = $O::find($id);
            if ($online && $o->status === 'pending' && $i !== 6) {
                $pay($o->invoice_id); // payé en ligne → billets émis
            }
        }
        foreach (\App\Modules\Evenements\Models\Booking::where('event_id', $salon->id)->where('status', 'confirmed')->take(5)->get() as $i => $b) {
            $svc->exhibitorBadges($b, array_slice(['M. Koné', 'Mme Yao', 'M. Bamba', 'Mme Touré', 'M. Kouassi', 'Mme Diallo'], $i % 3, 2 + $i % 2));
        }
        foreach (['Équipe accueil' => ['Christelle Amon', 'Junior Kacou', 'Brigitte Lath'], 'Presse' => ['Fraternité Matin', 'RTI 1']] as $grp => $list) {
            foreach ($list as $n) {
                $put(\App\Modules\Billetterie\Models\Ticket::class, ['event_id' => $salon->id, 'type_id' => $types[$grp === 'Presse' ? 'Badge presse' : 'Badge organisation'], 'holder_name' => $n, 'company_name' => $grp === 'Presse' ? $n : 'Organisation']);
            }
        }

        // Forum du jour : billets émis et entrées contrôlées depuis ce matin
        $forum = $put($E, ['name' => 'Forum du Numérique d\'Abidjan', 'venue' => 'Sofitel Hôtel Ivoire', 'city' => 'Abidjan', 'status' => 'open', 'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(), 'plan_cols' => 12, 'plan_rows' => 6, 'tax_rate' => 18, 'ticketing_enabled' => true, 'capacity' => 800, 'public_enabled' => false,
            'description' => 'Deux jours de conférences et d\'ateliers sur la transformation numérique des entreprises.']);
        $ft = [];
        foreach ([['Pass conférence', 'visitor', 15000, '#1F6FEB', false], ['Pass VIP + déjeuner', 'vip', 50000, '#8E44AD', true], ['Badge intervenant', 'staff', 0, '#0B1B3A', true]] as $i => [$n, $k, $p, $c, $re]) {
            $ft[$n] = $put($T, ['event_id' => $forum, 'name' => $n, 'kind' => $k, 'price' => $p, 'color' => $c, 'reentry' => $re, 'sort' => $i, 'online' => $k !== 'staff']);
        }
        $issued = [];
        foreach ($names as $i => $n) {
            $id = (string) \Illuminate\Support\Str::uuid();
            $put($O, ['event_id' => $forum, 'buyer_name' => $n, 'buyer_phone' => '+225 05 '.(10 + $i).' 44 55 66', 'items' => [['type_id' => $ft[$i % 5 === 0 ? 'Pass VIP + déjeuner' : 'Pass conférence'], 'quantity' => $i % 4 === 0 ? 2 : 1]],
                'payment_method' => ['cash', 'mobile_money', 'card'][$i % 3], 'status' => 'paid'], $id);
            foreach (\App\Modules\Billetterie\Models\Ticket::where('order_id', $id)->get() as $t) {
                $issued[] = $t;
            }
        }
        foreach (['Dr Kouamé (IA et santé)', 'Mme Fofana (Mobile Money)', 'M. Ehui (cybersécurité)'] as $n) {
            $issued[] = \App\Modules\Billetterie\Models\Ticket::find($put(\App\Modules\Billetterie\Models\Ticket::class, ['event_id' => $forum, 'type_id' => $ft['Badge intervenant'], 'holder_name' => $n, 'company_name' => 'Intervenant']));
        }
        $start = now()->startOfDay()->setTime(8, 5);
        foreach (array_slice($issued, 0, 24) as $i => $t) {
            $at = $start->copy()->addMinutes($i * 9 + ($i % 3) * 2);
            if ($at->gt(now())) {
                $at = now()->subMinutes(30 - $i);
            }
            $put(\App\Modules\Billetterie\Models\Checkin::class, ['event_id' => $forum, 'code' => $t->code, 'scanned_at' => $at->toDateTimeString(), 'gate' => $i % 2 ? 'Entrée hall' : 'Entrée VIP']);
        }
        $put(\App\Modules\Billetterie\Models\Checkin::class, ['event_id' => $forum, 'code' => $issued[2]->code, 'scanned_at' => now()->subMinutes(5)->toDateTimeString(), 'gate' => 'Entrée hall']); // tentative de double entrée
        $put(\App\Modules\Billetterie\Models\Checkin::class, ['event_id' => $forum, 'code' => 'FAUX-1234', 'scanned_at' => now()->subMinutes(3)->toDateTimeString(), 'gate' => 'Entrée hall']);

        // ---------------------------------------------------------------- v0.43 : stands mesurés, fiches exposants, inscriptions gratuites, avis
        $dims = ['Stand standard 9 m²' => [3, 3], 'Stand premium 12 m²' => [4, 3], 'Espace extérieur 54 m²' => [9, 6], 'Food court 4 m²' => [2, 2]];
        foreach (\App\Modules\Evenements\Models\Space::where('event_id', $salon->id)->get() as $s) {
            if ($d = $dims[$s->category] ?? null) {
                $s->forceFill(['width_m' => $d[0], 'depth_m' => $d[1], 'area' => $d[0] * $d[1]])->saveQuietly();
            }
        }
        $evs = app(\App\Modules\Evenements\EventService::class);
        foreach ([
            'Banque des Producteurs' => ['Le partenaire financier des agriculteurs', 'Banque', 'Crédits de campagne, financement de matériel agricole et comptes pour coopératives.', 'Ouverture de compte gratuite pendant le salon', 'www.banque-producteurs.ci'],
            'Laiterie Bon Lait' => ['Le bon lait de chez nous', 'Agroalimentaire', 'Lait frais, yaourts et fromages produits à partir du lait des éleveurs ivoiriens.', 'Dégustation gratuite chaque matin', 'www.bonlait.ci'],
            'Tracteurs Afrique Équipements' => ['La mécanisation à portée de main', 'Machinisme agricole', 'Tracteurs, motoculteurs et pièces détachées, avec service après-vente dans 12 villes.', '-10 % sur les motoculteurs commandés au salon', 'www.tae.ci'],
            'Ivoire Poussins SA' => ['Des poussins sains, une ferme rentable', 'Aviculture', 'Poussins d\'un jour, aliments et accompagnement technique des éleveurs.', null, 'www.ivoirepoussins.ci'],
            'SoluVet Pharma' => ['Santé animale', 'Pharmacie vétérinaire', 'Vaccins, antiparasitaires et conseils vétérinaires.', 'Consultation offerte sur le stand', null],
        ] as $name => [$tag, $sector, $desc, $offer, $web]) {
            $bk = \App\Modules\Evenements\Models\Booking::where('event_id', $salon->id)->where('status', 'confirmed')->where('exhibitor_name', $name)->first();
            if ($bk) {
                $evs->saveProfile($bk, ['tagline' => $tag, 'sector' => $sector, 'description' => $desc, 'offer' => $offer, 'website' => $web, 'phone' => $bk->contact_phone, 'email' => \Illuminate\Support\Str::slug($name).'@exemple.ci',
                    'facebook' => 'facebook.com/'.\Illuminate\Support\Str::slug($name)], null, true);
            }
        }

        // Journée portes ouvertes gratuite : formulaire d'inscription, inscrits versés dans Marketing
        $jpo = $put($E, ['name' => 'Journée Agritech & Innovation', 'venue' => 'Pôle Scientifique de Bingerville', 'city' => 'Bingerville', 'status' => 'open', 'start_date' => now()->addDays(12)->toDateString(),
            'end_date' => now()->addDays(12)->toDateString(), 'plan_cols' => 12, 'plan_rows' => 6, 'tax_rate' => 18, 'ticketing_enabled' => true, 'capacity' => 400, 'public_enabled' => false,
            'description' => 'Démonstrations de drones, irrigation solaire et applications pour les producteurs. Entrée gratuite sur inscription.',
            'registration' => ['ask_email' => 'required', 'ask_company' => true, 'ask_job' => true, 'questions' => [
                ['id' => 'secteur', 'label' => 'Votre activité', 'type' => 'select', 'required' => true, 'options' => ['Producteur', 'Coopérative', 'Étudiant', 'Entreprise', 'Administration']],
                ['id' => 'atelier', 'label' => 'Atelier choisi', 'type' => 'select', 'required' => true, 'options' => ['Drones agricoles', 'Irrigation solaire', 'Applications mobiles', 'Financement']],
                ['id' => 'attentes', 'label' => 'Qu\'attendez-vous de cette journée ?', 'type' => 'textarea'],
            ]]]);
        $jt = $put($T, ['event_id' => $jpo, 'name' => 'Entrée gratuite', 'kind' => 'visitor', 'price' => 0, 'quantity' => 400, 'color' => '#12A150', 'online' => true]);
        $secteurs = ['Producteur', 'Producteur', 'Coopérative', 'Étudiant', 'Entreprise', 'Producteur', 'Administration', 'Étudiant', 'Entreprise', 'Coopérative'];
        $ateliers = ['Drones agricoles', 'Irrigation solaire', 'Applications mobiles', 'Financement', 'Drones agricoles', 'Irrigation solaire'];
        $expect = ['Rencontrer des fournisseurs de matériel', 'Voir les drones en démonstration', 'Trouver un financement pour mon exploitation', 'Découvrir les applications de suivi des cultures', null, 'Échanger avec d\'autres coopératives'];
        $orgs = ['Coop Anouanzè', 'SCAB Bingerville', null, 'Université Nangui Abrogoua', 'AgroDrone CI', null, 'Ministère de l\'Agriculture', 'INP-HB', 'Ivoire Irrigation', 'Coop Agnibilékrou'];
        $srcs = ['facebook', 'whatsapp', 'whatsapp', null, 'facebook', 'instagram', null, 'whatsapp'];
        $O::$systemWrite = true;
        try {
            for ($i = 0; $i < 36; $i++) {
                $n = $names[$i % count($names)].($i >= count($names) ? ' '.chr(65 + $i % 26).'.' : '');
                $id = (string) \Illuminate\Support\Str::uuid();
                $put($O, ['event_id' => $jpo, 'buyer_name' => $n, 'buyer_phone' => '+225 07 '.(50 + $i).' 21 43 65', 'buyer_email' => \Illuminate\Support\Str::slug($n).'.'.$i.'@exemple.ci',
                    'buyer_company' => $orgs[$i % 10], 'buyer_job' => ['Gérant', 'Technicien', 'Étudiant', 'Directeur', 'Agronome'][$i % 5], 'consent' => $i % 3 !== 0, 'source' => $srcs[$i % 8],
                    'items' => [['type_id' => $jt, 'quantity' => $i % 7 === 0 ? 2 : 1]], 'status' => 'pending',
                    'answers' => array_values(array_filter([['id' => 'secteur', 'label' => 'Votre activité', 'value' => $secteurs[$i % 10]], ['id' => 'atelier', 'label' => 'Atelier choisi', 'value' => $ateliers[$i % 6]],
                        $expect[$i % 6] ? ['id' => 'attentes', 'label' => 'Qu\'attendez-vous de cette journée ?', 'value' => $expect[$i % 6]] : null]))], $id);
                $at = now()->subDays(11 - intdiv($i * 11, 36))->setTime(8 + $i % 12, ($i * 7) % 60);
                \Illuminate\Support\Facades\DB::table('ev_ticket_orders')->where('id', $id)->update(['created_at' => $at]);
            }
        } finally {
            $O::$systemWrite = false;
        }

        // Atelier passé : présence contrôlée et avis des participants
        $past = $put($E, ['name' => 'Atelier Export Cacao & Anacarde', 'venue' => 'CCI Côte d\'Ivoire', 'city' => 'Abidjan', 'status' => 'open', 'start_date' => now()->subDays(4)->toDateString(),
            'end_date' => now()->subDays(4)->toDateString(), 'plan_cols' => 12, 'plan_rows' => 6, 'tax_rate' => 18, 'ticketing_enabled' => true, 'capacity' => 120, 'public_enabled' => false]);
        $pt = $put($T, ['event_id' => $past, 'name' => 'Participation', 'kind' => 'visitor', 'price' => 10000, 'color' => '#E8A317']);
        $comments = ['Très instructif, les intervenants maîtrisent leur sujet.', 'Bonne organisation, mais la salle était trop petite.', 'J\'ai trouvé deux acheteurs, merci !', null, 'Il faudrait plus de temps pour les questions.', 'Excellent atelier, à refaire.', null, 'Les supports seront-ils partagés ?'];
        foreach (array_slice($names, 0, 16) as $i => $n) {
            $id = (string) \Illuminate\Support\Str::uuid();
            $put($O, ['event_id' => $past, 'buyer_name' => $n, 'buyer_phone' => '+225 01 '.(20 + $i).' 33 44 55', 'buyer_email' => \Illuminate\Support\Str::slug($n).'@exemple.ci', 'buyer_company' => $orgs[$i % 10],
                'items' => [['type_id' => $pt, 'quantity' => 1]], 'payment_method' => ['mobile_money', 'cash', 'bank_transfer'][$i % 3], 'status' => 'paid'], $id);
            $t = \App\Modules\Billetterie\Models\Ticket::where('order_id', $id)->first();
            if ($t && $i < 13) {
                $t->forceFill(['checked_in_at' => now()->subDays(4)->setTime(8, 40 + $i * 3)])->saveQuietly();
                if ($i < 11) {
                    \Illuminate\Support\Facades\DB::table('ev_feedback')->insert(['company_id' => $company->id, 'event_id' => $past, 'ticket_id' => $t->id, 'name' => $n, 'email' => $t->holder_email,
                        'rating' => [5, 4, 5, 3, 4, 5, 4, 5, 2, 5, 4][$i], 'nps' => [10, 8, 9, 6, 8, 10, 7, 9, 4, 10, 9][$i], 'comment' => $comments[$i % 8],
                        'created_at' => now()->subDays(3)->setTime(9 + $i % 8, 10), 'updated_at' => now()->subDays(3)]);
                }
            }
        }
        \App\Modules\Evenements\Models\Event::whereKey($past)->update(['status' => 'done', 'feedback_sent_at' => now()->subDays(3)->setTime(10, 5)]);

        // ---------------------------------------------------------------- forfaits d'heures et livrables
        $P = \App\Modules\Prestations\Models\Pack::class;
        $N = \App\Modules\Prestations\Models\Entry::class;
        $D = \App\Modules\Prestations\Models\Deliverable::class;
        $pid = fn (string $name) => Partner::where('name', $name)->value('id');
        $team = User::where('company_id', $company->id)->where('is_active', true)->take(4)->get();
        $packs = [
            ['Boutique Mode Ivoire', 'Maintenance site e-commerce et sauvegardes', 10, 250000, 30000, -40, 60, [[1.5, 'Mise à jour WooCommerce et extensions'], [2, 'Ajout de la collection Tabaski'], [1, 'Sauvegarde et test de restauration'], [3, 'Optimisation des images et du cache']]],
            ['Restaurant Le Baobab', 'Community management (20 h / mois)', 20, 400000, 25000, -25, 5, [[3, 'Calendrier éditorial et visuels de la semaine'], [4, 'Shooting photo des nouveaux plats'], [2.5, 'Réponses aux avis Google et Facebook'], [3, 'Campagne sponsorisée week-end'], [4, 'Vidéo Reels du chef'], [2.5, 'Rapport de performance']]],
            ['Clinique Les Palmiers', 'Support informatique et réseau (30 h)', 30, 750000, 35000, -80, 100, [[6, 'Installation du réseau Wi-Fi des étages'], [8, 'Migration des postes vers Windows 11'], [5, 'Dépannage imprimantes et scanner'], [7.5, 'Sauvegarde automatique du serveur'], [5, 'Intervention urgence : panne serveur']]],
            ['Cabinet Kouadio & Associés', 'Formation Yomara ERP (8 h)', 8, 320000, null, -10, 80, [[2, 'Formation comptabilité et factures']]],
        ];
        $packIds = [];
        foreach ($packs as [$client, $name, $hours, $price, $rate, $startAgo, $validDays, $entries]) {
            $id = $put($P, array_filter(['partner_id' => $pid($client), 'name' => $name, 'hours' => $hours, 'price' => $price, 'tax_rate' => 18, 'hourly_rate' => $rate,
                'start_date' => now()->addDays($startAgo)->toDateString(), 'expiry_date' => now()->addDays($validDays)->toDateString(), 'invoice' => true], fn ($v) => $v !== null));
            $packIds[$client] = $id;
            foreach ($entries as $j => [$h, $desc]) {
                auth()->setUser($team[$j % max(1, $team->count())] ?? $admin);
                $put($N, ['pack_id' => $id, 'date' => now()->addDays($startAgo + 2 + $j * (int) floor(abs($startAgo) / (count($entries) + 1)))->toDateString(), 'hours' => $h, 'description' => $desc]);
            }
            auth()->setUser($admin);
        }
        $pay(\App\Modules\Prestations\Models\Pack::find($packIds['Clinique Les Palmiers'])->invoice_id, 'bank_transfer');
        $pay(\App\Modules\Prestations\Models\Pack::find($packIds['Boutique Mode Ivoire'])->invoice_id);
        $ps = app(\App\Modules\Prestations\PrestationService::class);
        // Livrables : validé après une version corrigée, en attente, modifications demandées, brouillon
        $mk = fn (string $client, string $title, string $desc, ?string $url, ?string $pack = null, int $due = 5) => $put($D, array_filter(['partner_id' => $pid($client), 'pack_id' => $pack ? $packIds[$pack] : null,
            'title' => $title, 'description' => $desc, 'url' => $url, 'due_date' => now()->addDays($due)->toDateString()]));
        $d1 = $mk('Hôtel Lagune', 'Maquette du nouveau site de l\'hôtel', 'Voici la proposition de page d\'accueil et de page « Chambres ».', 'https://www.figma.com/file/demo-hotel-lagune');
        $put($D, ['status' => 'sent'], $d1);
        $ps->decide($D::find($d1), false, 'Mme Koffi (direction)', 'Mettre la piscine en grande photo d\'accueil et ajouter le bouton « Réserver » en haut.');
        $put($D, ['new_version' => true], $d1);
        $put($D, ['status' => 'sent', 'description' => 'Version 2 : photo de la piscine en accueil et bouton Réserver ajouté.'], $d1);
        $ps->decide($D::find($d1), true, 'Mme Koffi (direction)', 'Parfait, vous pouvez lancer le développement.');
        $d2 = $mk('Restaurant Le Baobab', 'Calendrier éditorial de décembre', '24 publications prévues pour les fêtes : visuels et textes.', 'https://docs.google.com/spreadsheets/d/demo-baobab', 'Restaurant Le Baobab', 3);
        $put($D, ['status' => 'sent'], $d2);
        $d3 = $mk('Boutique Mode Ivoire', 'Vidéo promotionnelle collection Tabaski', 'Montage de 45 secondes pour Instagram et TikTok.', 'https://vimeo.com/demo-mode-ivoire', 'Boutique Mode Ivoire');
        $put($D, ['status' => 'sent'], $d3);
        $ps->decide($D::find($d3), false, 'Aminata (gérante)', 'Musique plus dynamique et finir sur le logo avec l\'adresse de la boutique.');
        $mk('Clinique Les Palmiers', 'Rapport d\'intervention réseau — octobre', 'Synthèse des interventions et recommandations.', null, 'Clinique Les Palmiers', 7);
        $mk('Cabinet Kouadio & Associés', 'Support de formation Yomara ERP', 'Guide pas à pas : factures, relances, comptabilité.', 'https://yomara.ci/guides/erp-formation', 'Cabinet Kouadio & Associés', 10);

        app()->forgetInstance('tenant');
    }

    /** Salon avec plan des stands, exposants, sponsors, acomptes ; services clients d'une activité numérique. */
    protected function evenementsHebergement(Company $company): void
    {
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $admin = User::where('email', 'admin@demo.ci')->first();
        auth()->setUser($admin);
        foreach (['evenements' => \App\Modules\Evenements\EvenementsSetup::class, 'hebergement' => \App\Modules\Hebergement\HebergementSetup::class] as $k => $cls) {
            app($cls)($company);
            $settings = $company->fresh()->settings ?? [];
            $settings['_setup'][$k] = 1;
            $company->forceFill(['settings' => $settings])->saveQuietly();
        }
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $w = app(\App\Services\EntityWriter::class);
        $put = function (string $class, array $data, ?string $id = null) use ($w) {
            $id ??= (string) \Illuminate\Support\Str::uuid();
            $r = $w->upsert($class, $id, $data);
            if ($r['status'] !== \App\Services\EntityWriter::APPLIED) {
                throw new \RuntimeException(class_basename($class).' : '.json_encode($r));
            }

            return $id;
        };
        $pay = function (?string $docId, float $ratio = 1.0, string $method = 'bank_transfer', int $ago = 1) {
            $d = $docId ? \App\Modules\Ventes\Models\SalesDocument::find($docId) : null;
            if (! $d) {
                return;
            }
            $p = new \App\Modules\Ventes\Models\SalesPayment(['document_id' => $d->id, 'date' => now()->subDays($ago)->toDateString(), 'amount' => round((float) $d->total * $ratio), 'method' => $method]);
            $p->partner_id = $d->partner_id;
            $p->save();
            $d->refreshPaymentStatus();
        };
        $partner = fn (string $name, string $phone, ?string $email = null, string $type = 'customer') => Partner::firstOrCreate(['name' => $name], ['type' => $type, 'phone' => $phone, 'email' => $email, 'city' => 'Abidjan'])->id;

        // ---------------------------------------------------------------- salon
        $E = \App\Modules\Evenements\Models\Event::class;
        $S = \App\Modules\Evenements\Models\Space::class;
        $K = \App\Modules\Evenements\Models\Package::class;
        $B = \App\Modules\Evenements\Models\Booking::class;
        $ev = $put($E, ['name' => 'Salon de l\'Agriculture et de l\'Élevage 2027', 'venue' => 'Parc des expositions', 'city' => 'Abidjan', 'status' => 'open',
            'start_date' => now()->addDays(45)->toDateString(), 'end_date' => now()->addDays(49)->toDateString(), 'plan_cols' => 32, 'plan_rows' => 15,
            'tax_rate' => 18, 'deposit_percent' => 50, 'balance_days' => 21, 'option_days' => 7, 'public_enabled' => true,
            'description' => "Cinq jours pour rencontrer éleveurs, agriculteurs, coopératives et fournisseurs de toute la sous-région.\nConférences, démonstrations, concours d'animaux et espace restauration.",
            'terms' => 'Montage la veille de 8 h à 20 h. Annulation : acompte acquis à moins de 30 jours de l\'ouverture.',
            'categories' => [['name' => 'Stand standard 9 m²', 'color' => '#1F6FEB', 'price' => 350000, 'area' => 9], ['name' => 'Stand premium 12 m²', 'color' => '#8E44AD', 'price' => 650000, 'area' => 12],
                ['name' => 'Espace extérieur 54 m²', 'color' => '#12A150', 'price' => 900000, 'area' => 54], ['name' => 'Food court 4 m²', 'color' => '#FF7A00', 'price' => 150000, 'area' => 4]]]);
        $sp = [];
        $row = function (string $prefix, int $count, int $x, int $y, int $w, int $h, string $cat, string $zone) use ($put, $S, $ev, &$sp) {
            for ($i = 0; $i < $count; $i++) {
                $code = $prefix.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
                $sp[$code] = $put($S, ['event_id' => $ev, 'code' => $code, 'x' => $x + $i * $w, 'y' => $y, 'w' => $w, 'h' => $h, 'category' => $cat, 'zone' => $zone]);
            }
        };
        $row('A', 10, 1, 1, 3, 3, 'Stand standard 9 m²', 'Hall A');
        $row('B', 10, 1, 5, 3, 3, 'Stand standard 9 m²', 'Hall A');
        $row('P', 4, 1, 9, 4, 3, 'Stand premium 12 m²', 'Allée centrale');
        $row('F', 6, 18, 9, 2, 2, 'Food court 4 m²', 'Restauration');
        $row('E', 3, 1, 12, 6, 3, 'Espace extérieur 54 m²', 'Extérieur');
        $put($S, ['event_id' => $ev, 'code' => 'SCÈNE', 'x' => 20, 'y' => 12, 'w' => 10, 'h' => 3, 'blocked' => true, 'notes' => 'Conférences et concours']);
        $put($S, ['event_id' => $ev, 'code' => 'ACCUEIL', 'x' => 18, 'y' => 11, 'w' => 6, 'h' => 1, 'blocked' => true]);
        $put($S, ['event_id' => $ev, 'code' => 'P05', 'x' => 30, 'y' => 9, 'w' => 2, 'h' => 3, 'category' => 'Stand premium 12 m²', 'zone' => 'Allée centrale', 'features' => 'Angle, double façade', 'price' => 750000]);
        $sp['P05'] = $S::where('event_id', $ev)->where('code', 'P05')->value('id');
        $pk = [];
        foreach ([
            ['sponsor', 'Sponsor Platine', 5000000, 1, ['Espace extérieur de 54 m² offert', 'Logo en tête de toutes les communications', 'Prise de parole à l\'ouverture', '10 badges']],
            ['sponsor', 'Sponsor Or', 2500000, 3, ['Stand premium offert', 'Logo sur l\'affiche et le site', '6 badges']],
            ['sponsor', 'Sponsor Argent', 1000000, 6, ['Logo sur le site et le catalogue', '3 badges']],
            ['option', 'Électricité 3 kW', 75000, null, []],
            ['option', 'Mobilier : table et 2 chaises', 25000, null, []],
            ['option', 'Badge exposant supplémentaire', 10000, null, []],
            ['option', 'Page dans le catalogue officiel', 150000, 40, []],
        ] as $i => [$kind, $name, $price, $qty, $benefits]) {
            $pk[$name] = $put($K, ['event_id' => $ev, 'kind' => $kind, 'name' => $name, 'price' => $price, 'quantity' => $qty, 'benefits' => $benefits, 'sort' => $i]);
        }
        $ex = [
            ['Ivoire Poussins SA', '+225 07 11 20 30 40', ['A01', 'A02'], ['Électricité 3 kW' => 1], 'confirmed', 1.0, true],
            ['Laiterie Bon Lait', '+225 07 22 30 40 50', ['P01'], ['Sponsor Or' => 1, 'Électricité 3 kW' => 1], 'confirmed', 1.0, true],
            ['AgroVet Côte d\'Ivoire', '+225 05 33 40 50 60', ['A03'], ['Mobilier : table et 2 chaises' => 2], 'confirmed', 0.5, false],
            ['Coopérative des Éleveurs du Nord', '+225 07 44 50 60 70', ['A05', 'A06'], [], 'confirmed', 0.5, false],
            ['Tracteurs Afrique Équipements', '+225 27 21 55 66 77', ['E01'], ['Sponsor Argent' => 1, 'Électricité 3 kW' => 2], 'confirmed', 0.5, false],
            ['Provenderie du Sud', '+225 07 66 70 80 90', ['B01'], ['Page dans le catalogue officiel' => 1], 'confirmed', 0.0, false],
            ['Miel de Korhogo', '+225 05 77 80 90 10', ['F01'], [], 'confirmed', 0.5, false],
            ['Pisciculture de la Lagune', '+225 07 88 90 10 20', ['B02', 'B03'], ['Électricité 3 kW' => 1], 'confirmed', 0.0, false],
            ['SoluVet Pharma', '+225 27 22 99 00 11', ['P02'], ['Sponsor Argent' => 1], 'confirmed', 0.5, false],
            ['Ferme Moderne de Dabou', '+225 07 10 20 30 50', ['A07'], [], 'confirmed', 0.5, false],
            ['Banque des Producteurs', '+225 27 20 30 40 60', ['E02'], ['Sponsor Platine' => 1], 'confirmed', 0.5, false],
            ['Le Grill du Salon', '+225 05 12 34 56 78', ['F02', 'F03'], ['Électricité 3 kW' => 1], 'confirmed', 0.5, false],
            ['Semences d\'Afrique', '+225 07 13 24 35 46', ['B05'], [], 'option', 0, false],
            ['Engrais Plus', '+225 07 14 25 36 47', ['B06', 'B07'], ['Électricité 3 kW' => 1], 'option', 0, false],
            ['Abattoir Moderne d\'Anyama', '+225 27 23 45 67 89', ['P05'], ['Sponsor Or' => 1], 'option', 0, false],
            ['Œufs Frais du Bandama', '+225 05 16 27 38 49', ['A09'], [], 'option', 0, false],
        ];
        $ids = [];
        foreach ($ex as $i => [$name, $phone, $codes, $items, $status, $paid, $balance]) {
            $pid = $partner($name, $phone, \Illuminate\Support\Str::slug($name).'@exemple.ci');
            $b = $put($B, ['event_id' => $ev, 'partner_id' => $pid, 'contact_name' => ['M. Koné', 'Mme Yao', 'M. Bamba', 'Mme Touré', 'M. Kouassi', 'Mme Diallo'][$i % 6], 'contact_phone' => $phone,
                'space_ids' => array_map(fn ($c) => $sp[$c], $codes), 'items' => collect($items)->map(fn ($q, $n) => ['package_id' => $pk[$n], 'quantity' => $q])->values()->all(),
                'status' => 'option', 'option_until' => now()->addDays([2, 5, 6, 1][$i % 4])->toDateString(), 'discount_percent' => $name === 'Banque des Producteurs' ? 10 : 0]);
            if ($status === 'confirmed') {
                $put($B, ['status' => 'confirmed'], $b);
                $bk = $B::find($b);
                if ($paid > 0) {
                    $pay($bk->deposit_invoice_id ?? $bk->balance_invoice_id, 1.0, $i % 2 ? 'mobile_money' : 'bank_transfer', 10 - $i % 5);
                }
                if ($balance) {
                    $bal = app(\App\Modules\Evenements\EventService::class)->invoiceBalance($B::find($b));
                    $pay($bal->id, 1.0, 'bank_transfer', 1);
                }
            }
            $ids[$name] = $b;
        }
        // Une option arrivée à échéance et une demande reçue par la page publique
        $old = $put($B, ['event_id' => $ev, 'partner_id' => $partner('Poulets Bicyclette & Cie', '+225 07 17 28 39 40'), 'space_ids' => [$sp['A10']], 'status' => 'option', 'option_until' => now()->addDay()->toDateString()]);
        $B::find($old)->forceFill(['status' => 'expired', 'option_until' => now()->subDays(3)->toDateString()])->save();
        app(\App\Modules\Evenements\EventService::class)->publicRequest($E::find($ev), ['company' => 'Escargots d\'Agboville', 'name' => 'M. Aka', 'phone' => '+225 07 18 29 30 41',
            'email' => 'contact@escargots-agboville.ci', 'spaces' => [$sp['B09']], 'packages' => [$pk['Mobilier : table et 2 chaises'] => 1], 'message' => 'Premier salon pour nous, merci de nous rappeler.']);
        // Événement en préparation
        $fest = $put($E, ['name' => 'Festival des Saveurs 2027', 'venue' => 'Esplanade du Plateau', 'city' => 'Abidjan', 'status' => 'draft', 'start_date' => now()->addMonths(7)->toDateString(),
            'end_date' => now()->addMonths(7)->addDays(2)->toDateString(), 'plan_cols' => 20, 'plan_rows' => 10, 'deposit_percent' => 30,
            'categories' => [['name' => 'Kiosque 6 m²', 'color' => '#FF7A00', 'price' => 200000, 'area' => 6]]]);
        for ($i = 0; $i < 8; $i++) {
            $put($S, ['event_id' => $fest, 'code' => 'K'.($i + 1), 'x' => 1 + $i * 2, 'y' => 2, 'w' => 2, 'h' => 2, 'category' => 'Kiosque 6 m²']);
        }

        // ---------------------------------------------------------------- hébergement, domaines & licences
        $H = \App\Modules\Hebergement\Models\HostedService::class;
        $nic = $partner('NIC Côte d\'Ivoire (registre .ci)', '+225 27 20 34 43 00', 'contact@nic.ci', 'supplier');
        $clients = [
            ['Boutique Mode Ivoire', '+225 07 21 31 41 51', 'boutique-mode.ci', 'contact@boutique-mode.ci'],
            ['Clinique Les Palmiers', null, 'clinique-lespalmiers.ci', null],
            ['Hôtel Lagune', null, 'hotel-lagune.com', null],
            ['Cabinet Kouadio & Associés', null, 'kouadio-avocats.ci', null],
            ['École Les Lauriers', '+225 07 22 32 42 52', 'ecole-leslauriers.ci', 'direction@ecole-leslauriers.ci'],
            ['Restaurant Le Baobab', null, 'lebaobab.ci', null],
            ['ONG Eau pour Tous', '+225 05 23 33 43 53', 'eaupourtous.org', 'info@eaupourtous.org'],
            ['Transports Rapides CI', '+225 07 24 34 44 54', 'transports-rapides.ci', 'dg@transports-rapides.ci'],
        ];
        $d = fn (int $days) => now()->addDays($days)->toDateString();
        $plan = [
            // [domaine j, hébergement j, offre, SSL j, e-mails j / null, maintenance mensuelle]
            [12, 12, 'Pro 10 Go', null, 160, true],
            [25, 25, 'Business 30 Go', 25, 70, true],
            [-4, 200, 'Pro 10 Go', null, null, false],
            [48, 48, 'Starter 2 Go', null, 300, false],
            [7, 7, 'Pro 10 Go', null, 95, true],
            [140, 140, 'Starter 2 Go', null, null, false],
            [33, 33, 'Business 30 Go', 120, 33, true],
            [260, 18, 'VPS 4 Go', 18, 210, true],
        ];
        foreach ($clients as $i => [$name, $phone, $domain, $email]) {
            $pid = Partner::where('name', $name)->value('id') ?? $partner($name, $phone ?? '+225 07 00 00 0'.$i.' 0'.$i, $email);
            if ($email) {
                Partner::where('id', $pid)->whereNull('email')->update(['email' => $email]);
            }
            [$dj, $hj, $offer, $sslj, $mailj, $maint] = $plan[$i];
            $put($H, ['partner_id' => $pid, 'type' => 'domain', 'name' => $domain, 'provider' => str_ends_with($domain, '.ci') ? 'NIC CI' : 'OVHcloud', 'supplier_id' => str_ends_with($domain, '.ci') ? $nic : null,
                'cost_price' => str_ends_with($domain, '.ci') ? 15000 : 9000, 'sale_price' => 25000, 'tax_rate' => 18, 'period_months' => 12, 'start_date' => now()->subYears(2)->addDays($dj)->toDateString(), 'expiry_date' => $d($dj)]);
            $put($H, ['partner_id' => $pid, 'type' => $offer === 'VPS 4 Go' ? 'cloud' : 'hosting', 'name' => ($offer === 'VPS 4 Go' ? 'Serveur ' : 'Hébergement ').$domain, 'plan' => $offer,
                'provider' => $offer === 'VPS 4 Go' ? 'Contabo' : 'o2switch', 'cost_price' => ['Starter 2 Go' => 25000, 'Pro 10 Go' => 45000, 'Business 30 Go' => 70000, 'VPS 4 Go' => 90000][$offer],
                'sale_price' => ['Starter 2 Go' => 60000, 'Pro 10 Go' => 95000, 'Business 30 Go' => 150000, 'VPS 4 Go' => 240000][$offer], 'tax_rate' => 18, 'period_months' => 12,
                'expiry_date' => $d($hj), 'details' => 'DNS : ns1.yomara-host.ci / ns2.yomara-host.ci']);
            if ($sslj !== null) {
                $put($H, ['partner_id' => $pid, 'type' => 'ssl', 'name' => $domain, 'plan' => 'SSL OV', 'provider' => 'Sectigo', 'cost_price' => 35000, 'sale_price' => 60000, 'tax_rate' => 18, 'period_months' => 12, 'expiry_date' => $d($sslj)]);
            }
            if ($mailj !== null) {
                $put($H, ['partner_id' => $pid, 'type' => 'email', 'name' => 'Microsoft 365 — '.$domain, 'plan' => (3 + $i).' boîtes Business Basic', 'provider' => 'Microsoft', 'cost_price' => (3 + $i) * 30000,
                    'sale_price' => (3 + $i) * 42000, 'tax_rate' => 18, 'period_months' => 12, 'expiry_date' => $d($mailj)]);
            }
            if ($maint) {
                $put($H, ['partner_id' => $pid, 'type' => 'maintenance', 'name' => 'Maintenance et sauvegardes '.$domain, 'plan' => 'Mises à jour, sauvegardes, 2 h de modifications', 'cost_price' => 0,
                    'sale_price' => 35000, 'tax_rate' => 18, 'period_months' => 1, 'renew_days_before' => 7, 'expiry_date' => $d(4 + $i % 20)]);
            }
        }
        $put($H, ['partner_id' => Partner::where('name', 'Cabinet Kouadio & Associés')->value('id'), 'type' => 'license', 'name' => 'Licence Yomara ERP — 5 utilisateurs', 'provider' => 'Yomara Technologies',
            'cost_price' => 0, 'sale_price' => 600000, 'tax_rate' => 18, 'period_months' => 12, 'expiry_date' => $d(95)]);
        // Facturation des renouvellements arrivés, puis un client qui a déjà payé (à renouveler chez le fournisseur)
        $res = app(\App\Modules\Hebergement\HostingService::class)->runDaily($company);
        $docs = \App\Modules\Hebergement\Models\Renewal::where('status', 'invoiced')->get()->groupBy('document_id');
        foreach ($docs->keys()->take(2) as $docId) {
            $pay($docId, 1.0, 'mobile_money', 0);
        }
        // Un service déjà renouvelé chez le fournisseur l'an dernier (historique)
        $first = $H::where('type', 'domain')->where('name', 'ecole-leslauriers.ci')->first();
        \App\Modules\Hebergement\Models\Renewal::create(['service_id' => $first->id, 'period_start' => now()->subYear()->addDays(8)->toDateString(), 'period_end' => now()->addDays(7)->toDateString(),
            'amount' => 25000, 'cost' => 15000, 'status' => 'paid', 'paid_at' => now()->subYear()->addDays(2), 'provider_done_at' => now()->subYear()->addDays(3), 'provider_done_by' => $admin->id]);

        app()->forgetInstance('tenant');
    }

    /** Lots et péremptions (FEFO), numéros de série, conditionnements, listes de prix, variantes. */
    protected function articlesAvances(Company $company): void
    {
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $admin = User::where('email', 'admin@demo.ci')->first();
        auth()->setUser($admin);
        app(\App\Modules\Tarifs\TarifsSetup::class)($company);
        $settings = $company->fresh()->settings ?? [];
        $settings['_setup']['tarifs'] = 1;
        $settings['lots_alert_days'] = 15;
        $company->forceFill(['settings' => $settings])->saveQuietly();
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $w = app(\App\Services\EntityWriter::class);
        $put = function (string $class, array $data, ?string $id = null) use ($w) {
            $id ??= $data['id'] ?? (string) \Illuminate\Support\Str::uuid();
            unset($data['id']);
            $r = $w->upsert($class, $id, $data);
            if ($r['status'] !== \App\Services\EntityWriter::APPLIED) {
                throw new \RuntimeException(class_basename($class).' : '.json_encode($r));
            }

            return $id;
        };
        $P = Product::class;
        $wh = Warehouse::where('is_default', true)->value('id');
        $d = fn (int $days) => now()->addDays($days)->toDateString();
        $cat = fn (string $name) => ProductCategory::where('name', $name)->value('id') ?? ProductCategory::create(['name' => $name])->id;
        $products = Product::all()->keyBy('name');
        $supplier = Partner::where('name', 'Grossiste Adjamé SARL')->first();
        $laiterie = Partner::create(['name' => 'Laiterie Ivoirienne (Fermes du Sud)', 'type' => 'supplier', 'phone' => '+225 27 21 00 11 22', 'city' => 'Abidjan']);
        $tech = Partner::create(['name' => 'Tech Distribution CI', 'type' => 'supplier', 'phone' => '+225 27 22 33 44 55', 'city' => 'Abidjan']);
        $superette = Partner::create(['name' => 'Supérette Bon Prix Yopougon', 'type' => 'customer', 'phone' => '+225 07 11 22 33 44', 'city' => 'Abidjan']);
        $boutique = Partner::create(['name' => 'Boutique Awa (revendeuse)', 'type' => 'customer', 'phone' => '+225 05 44 33 22 11', 'city' => 'Bouaké']);
        $tantie = Partner::where('name', 'Maquis Chez Tantie')->first();

        // ---------------------------------------------------------------- lots et péremptions
        $frais = $cat('Produits frais');
        $yaourt = $put($P, ['name' => 'Yaourt sucré 125 g', 'type' => 'product', 'category_id' => $frais, 'sku' => 'YAO-125', 'barcode' => '6194000001001', 'unit' => 'unité',
            'sale_price' => 254.24, 'purchase_price' => 160, 'tax_rate' => 18, 'min_stock' => 24, 'track_stock' => true,
            'tracking' => 'lot', 'use_expiry' => true, 'shelf_life_days' => 21, 'expiry_alert_days' => 7,
            'packagings' => [['name' => 'Pack de 4', 'factor' => 4, 'barcode' => '6194000001004']]]);
        $lait = $put($P, ['name' => 'Lait frais pasteurisé 1 L', 'type' => 'product', 'category_id' => $frais, 'sku' => 'LAIT-1L', 'barcode' => '6194000001018', 'unit' => 'litre',
            'sale_price' => 847.46, 'purchase_price' => 600, 'tax_rate' => 18, 'min_stock' => 12, 'track_stock' => true,
            'tracking' => 'lot', 'use_expiry' => true, 'shelf_life_days' => 10]);
        $farine = $put($P, ['name' => 'Farine de blé 1 kg', 'type' => 'product', 'category_id' => $cat('Épicerie'), 'sku' => 'FAR-1K', 'unit' => 'kg',
            'sale_price' => 593.22, 'purchase_price' => 450, 'tax_rate' => 18, 'min_stock' => 20, 'track_stock' => true,
            'tracking' => 'lot', 'use_expiry' => true, 'shelf_life_days' => 240,
            'packagings' => [['name' => 'Sac de 25', 'factor' => 25, 'sale_price' => 13500, 'purchase_price' => 10500]]]);
        $phone = $put($P, ['name' => 'Smartphone Tecno Spark 20', 'type' => 'product', 'category_id' => $cat('Téléphonie'), 'sku' => 'TEC-SP20', 'unit' => 'unité',
            'sale_price' => 76271.19, 'purchase_price' => 58000, 'tax_rate' => 18, 'min_stock' => 2, 'track_stock' => true, 'tracking' => 'serial']);
        $line = fn (string $id, string $desc, float $q, float $price, array $extra = []) => ['product_id' => $id, 'description' => $desc, 'quantity' => $q, 'unit_price' => $price, 'tax_rate' => 18] + $extra;

        // Stock ancien saisi à la main (un lot déjà périmé, un autre qui périme bientôt)
        $put(StockMove::class, ['product_id' => $yaourt, 'warehouse_id' => $wh, 'quantity' => 18, 'type' => 'initial', 'date' => $d(-25), 'lot_number' => 'YA-2409-A', 'expiry_date' => $d(-3), 'notes' => 'Reprise du stock']);
        $put(StockMove::class, ['product_id' => $lait, 'warehouse_id' => $wh, 'quantity' => 10, 'type' => 'initial', 'date' => $d(-8), 'lot_number' => 'LT-0412', 'expiry_date' => $d(2)]);
        // Réceptions fournisseurs avec numéros de lot et dates
        $put(\App\Modules\Achats\Models\PurchaseDocument::class, ['type' => 'supplier_invoice', 'partner_id' => $laiterie->id, 'partner_name' => $laiterie->name, 'date' => $d(-6), 'status' => 'validated', 'reference' => 'BL-LIS-8841', 'lines' => [
            $line($yaourt, 'Yaourt sucré 125 g', 60, 640, ['unit' => 'Pack de 4', 'unit_factor' => 4, 'lots' => [['number' => 'YA-2410-B', 'expiry_date' => $d(5), 'quantity' => 96], ['number' => 'YA-2410-C', 'expiry_date' => $d(16), 'quantity' => 144]]]),
            $line($lait, 'Lait frais pasteurisé 1 L', 36, 600, ['lots' => [['number' => 'LT-0419', 'expiry_date' => $d(6), 'quantity' => 36]]]),
        ]]);
        $put(\App\Modules\Achats\Models\PurchaseDocument::class, ['type' => 'supplier_invoice', 'partner_id' => $supplier->id, 'partner_name' => $supplier->name, 'date' => $d(-20), 'status' => 'validated', 'lines' => [
            $line($farine, 'Farine de blé 1 kg — Sac de 25', 8, 10500, ['unit' => 'Sac de 25', 'unit_factor' => 25, 'lots' => [['number' => 'FB-240815', 'expiry_date' => $d(95), 'quantity' => 100], ['number' => 'FB-240920', 'expiry_date' => $d(190), 'quantity' => 100]]]),
        ]]);
        $put(\App\Modules\Achats\Models\PurchaseDocument::class, ['type' => 'supplier_invoice', 'partner_id' => $tech->id, 'partner_name' => $tech->name, 'date' => $d(-15), 'status' => 'validated', 'lines' => [
            $line($phone, 'Smartphone Tecno Spark 20', 6, 58000, ['lots' => array_map(fn ($n) => ['number' => sprintf('35896210%07d', 4471200 + $n)], range(1, 6))]),
        ]]);
        // Ventes : sortie FEFO automatique (le lot périmé est écarté), série précisée pour le téléphone
        $put(\App\Modules\Ventes\Models\SalesDocument::class, ['type' => 'invoice', 'partner_id' => $tantie->id, 'partner_name' => $tantie->name, 'date' => $d(-3), 'status' => 'validated', 'lines' => [
            $line($yaourt, 'Yaourt sucré 125 g', 48, 254.24), $line($lait, 'Lait frais pasteurisé 1 L', 12, 847.46),
        ]]);
        $put(\App\Modules\Ventes\Models\SalesDocument::class, ['type' => 'invoice', 'partner_id' => $boutique->id, 'partner_name' => $boutique->name, 'date' => $d(-2), 'status' => 'validated', 'lines' => [
            $line($phone, 'Smartphone Tecno Spark 20', 2, 76271.19, ['lots' => [['number' => '358962104471202'], ['number' => '358962104471205']]]),
            $line($farine, 'Farine de blé 1 kg — Sac de 25', 2, 13500, ['unit' => 'Sac de 25', 'unit_factor' => 25]),
        ]]);

        // ---------------------------------------------------------------- conditionnements
        $put($P, ['packagings' => [['name' => 'Pack de 6', 'factor' => 6, 'barcode' => '6194000000066'], ['name' => 'Casier de 24', 'factor' => 24, 'barcode' => '6194000000240', 'sale_price' => 9600, 'purchase_price' => 8000]]], $products['Coca-Cola 33cl']->id);
        $put($P, ['packagings' => [['name' => 'Pack de 6', 'factor' => 6, 'barcode' => '6194000000035', 'sale_price' => 1950]]], $products['Eau minérale 1,5L']->id);
        $put($P, ['packagings' => [['name' => 'Carton de 12', 'factor' => 12, 'barcode' => '6194000000127', 'sale_price' => 14500, 'purchase_price' => 13200]]], $products['Huile végétale 1L']->id);
        $put($P, ['packagings' => [['name' => 'Carton de 24', 'factor' => 24, 'sale_price' => 9000]]], $products['Spaghetti 500g']->id);

        // ---------------------------------------------------------------- listes de prix
        $boissons = $cat('Boissons');
        $gros = $put(\App\Modules\Tarifs\Models\PriceList::class, ['name' => 'Grossistes & revendeurs', 'sort' => 1, 'notes' => 'Clients qui achètent par casier ou par carton.', 'items' => [
            ['scope' => 'all', 'mode' => 'discount', 'discount' => 3],
            ['scope' => 'category', 'category_id' => $boissons, 'mode' => 'discount', 'discount' => 5],
            ['scope' => 'category', 'category_id' => $boissons, 'mode' => 'discount', 'discount' => 8, 'min_qty' => 120],
            ['scope' => 'product', 'product_id' => $products['Coca-Cola 33cl']->id, 'packaging' => 'Casier de 24', 'mode' => 'price', 'price' => 9000, 'min_qty' => 5],
            ['scope' => 'product', 'product_id' => $products['Huile végétale 1L']->id, 'packaging' => 'Carton de 12', 'mode' => 'price', 'price' => 13900],
            ['scope' => 'product', 'product_id' => $products['Riz parfumé 5kg']->id, 'mode' => 'price', 'price' => 3600, 'min_qty' => 20],
            ['scope' => 'product', 'product_id' => $farine, 'packaging' => 'Sac de 25', 'mode' => 'price', 'price' => 12800],
        ]]);
        $chr = $put(\App\Modules\Tarifs\Models\PriceList::class, ['name' => 'Hôtels, cafés, restaurants', 'sort' => 2, 'items' => [
            ['scope' => 'category', 'category_id' => $boissons, 'mode' => 'discount', 'discount' => 4],
            ['scope' => 'category', 'category_id' => $boissons, 'mode' => 'discount', 'discount' => 6, 'min_qty' => 48],
            ['scope' => 'category', 'category_id' => $frais, 'mode' => 'discount', 'discount' => 5, 'min_qty' => 24],
        ]]);
        $put(\App\Modules\Tarifs\Models\PriceList::class, ['name' => 'Promo rentrée', 'sort' => 3, 'is_default' => true, 'start_date' => $d(-5), 'end_date' => $d(10), 'notes' => 'Pour tous les clients sans liste attitrée, en caisse comme en facture.', 'items' => [
            ['scope' => 'product', 'product_id' => $products['Spaghetti 500g']->id, 'mode' => 'discount', 'discount' => 10],
            ['scope' => 'product', 'product_id' => $products['Lait concentré 397g']->id, 'mode' => 'discount', 'discount' => 15, 'min_qty' => 6],
        ]]);
        foreach ([[$superette, $gros], [$boutique, $gros], [$tantie, $chr]] as [$partner, $list]) {
            $put(Partner::class, ['price_list_id' => $list], $partner->id);
        }
        foreach (Partner::where('type', 'customer')->where(fn ($q) => $q->where('name', 'like', '%Hôtel%')->orWhere('name', 'like', '%Restaurant%')->orWhere('name', 'like', '%Maquis%'))->whereNull('price_list_id')->get() as $partner) {
            $put(Partner::class, ['price_list_id' => $chr], $partner->id);
        }
        // Achat par casiers et packs (coût de stock ramené à l'unité), puis vente en casiers à la supérette, au tarif grossiste
        $put(\App\Modules\Achats\Models\PurchaseDocument::class, ['type' => 'supplier_invoice', 'partner_id' => $supplier->id, 'partner_name' => $supplier->name, 'date' => $d(-7), 'status' => 'validated', 'lines' => [
            $line($products['Coca-Cola 33cl']->id, 'Coca-Cola 33cl — Casier de 24', 25, 8000, ['unit' => 'Casier de 24', 'unit_factor' => 24]),
            $line($products['Eau minérale 1,5L']->id, 'Eau minérale 1,5L — Pack de 6', 40, 1400, ['unit' => 'Pack de 6', 'unit_factor' => 6]),
            $line($products['Huile végétale 1L']->id, 'Huile végétale 1L — Carton de 12', 6, 13200, ['unit' => 'Carton de 12', 'unit_factor' => 12]),
        ]]);
        $put(\App\Modules\Ventes\Models\SalesDocument::class, ['type' => 'invoice', 'partner_id' => $superette->id, 'partner_name' => $superette->name, 'date' => $d(-1), 'status' => 'validated', 'lines' => [
            $line($products['Coca-Cola 33cl']->id, 'Coca-Cola 33cl — Casier de 24', 6, 9000, ['unit' => 'Casier de 24', 'unit_factor' => 24]),
            $line($products['Huile végétale 1L']->id, 'Huile végétale 1L — Carton de 12', 2, 13900, ['unit' => 'Carton de 12', 'unit_factor' => 12]),
            $line($products['Eau minérale 1,5L']->id, 'Eau minérale 1,5L — Pack de 6', 10, 1950, ['unit' => 'Pack de 6', 'unit_factor' => 6, 'discount_percent' => 5]),
        ]]);

        // ---------------------------------------------------------------- variantes
        $textile = $cat('Textile & goodies');
        $tshirt = $put($P, ['name' => 'T-shirt coton Yomara', 'sku' => 'TSY', 'type' => 'product', 'category_id' => $textile, 'unit' => 'unité', 'sale_price' => 4237.29, 'purchase_price' => 2200, 'tax_rate' => 18,
            'is_template' => true, 'variant_attributes' => [['name' => 'Taille', 'values' => ['S', 'M', 'L', 'XL']], ['name' => 'Couleur', 'values' => ['Blanc', 'Noir', 'Orange']]],
            'price_extras' => ['Taille' => ['XL' => 423.73]], 'description' => '100 % coton, sérigraphie du logo.']);
        $pagne = $put($P, ['name' => 'Pagne wax 6 yards', 'sku' => 'WAX', 'type' => 'product', 'category_id' => $textile, 'unit' => 'unité', 'sale_price' => 12711.86, 'purchase_price' => 8500, 'tax_rate' => 18,
            'is_template' => true, 'variant_attributes' => [['name' => 'Motif', 'values' => ['Fleurs de mariage', 'Œil de ma rivale', 'Hirondelles']], ['name' => 'Qualité', 'values' => ['Standard', 'Super wax']]],
            'price_extras' => ['Qualité' => ['Super wax' => 4237.29]]]);
        $svc = app(\App\Modules\Variantes\VariantService::class);
        $variants = array_merge($svc->generate(Product::find($tshirt)), $svc->generate(Product::find($pagne)));
        $qty = [9, 14, 3, 0, 12, 20, 18, 6, 4, 11, 7, 2, 5, 8, 3, 6, 2, 4];
        foreach ($variants as $i => $v) {
            if (($q = $qty[$i % count($qty)]) > 0) {
                StockMove::create(['product_id' => $v->id, 'warehouse_id' => $wh, 'quantity' => $q, 'type' => 'initial', 'date' => $d(-30)]);
            }
            $put($P, ['barcode' => sprintf('619400002%04d', $i + 1)], $v->id);
        }
        $vs = collect($variants)->keyBy('name');
        $put(\App\Modules\Ventes\Models\SalesDocument::class, ['type' => 'invoice', 'partner_id' => $boutique->id, 'partner_name' => $boutique->name, 'date' => $d(-4), 'status' => 'validated', 'lines' => [
            $line($vs['T-shirt coton Yomara — M / Noir']->id, 'T-shirt coton Yomara — M / Noir', 6, 4237.29),
            $line($vs['T-shirt coton Yomara — L / Blanc']->id, 'T-shirt coton Yomara — L / Blanc', 4, 4237.29),
            $line($vs['Pagne wax 6 yards — Hirondelles / Super wax']->id, 'Pagne wax 6 yards — Hirondelles / Super wax', 2, 16949.15),
        ]]);

        app()->forgetInstance('tenant');
    }

    /** Champs personnalisés (secteur des clients, garantie, n° de bon de commande…) et circuits d'approbation en cours. */
    protected function approbationsChamps(Company $company): void
    {
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $admin = User::where('email', 'admin@demo.ci')->first();
        auth()->setUser($admin);
        foreach (['approbations' => \App\Modules\Approbations\ApprobationsSetup::class, 'champs' => \App\Modules\Champs\ChampsSetup::class] as $k => $cls) {
            app($cls)($company);
            $settings = $company->fresh()->settings ?? [];
            $settings['_setup'][$k] = 1;
            $settings['purchase_approval_limit'] = 0; // les circuits d'approbation prennent le relais du seuil simple
            $company->forceFill(['settings' => $settings])->saveQuietly();
        }
        $company = $company->fresh();
        app()->instance('tenant', $company);
        \App\Modules\Champs\CustomFields::flush();
        $w = app(\App\Services\EntityWriter::class);
        $put = function (string $class, array $data, ?User $as = null) use ($w, $admin) {
            auth()->setUser($as ?? $admin);
            $id = $data['id'] ?? (string) \Illuminate\Support\Str::uuid();
            unset($data['id']);
            $r = $w->upsert($class, $id, $data);
            if ($r['status'] !== \App\Services\EntityWriter::APPLIED) {
                throw new \RuntimeException(class_basename($class).' : '.json_encode($r));
            }

            return $id;
        };

        // ---------------------------------------------------------------- champs personnalisés
        $F = \App\Modules\Champs\Models\Field::class;
        foreach ([
            ['entity' => 'partners', 'label' => 'Secteur d\'activité', 'type' => 'select', 'options' => ['Hôtellerie', 'Restauration', 'Commerce de détail', 'Santé', 'Administration', 'Export', 'Particulier'], 'default_value' => 'Commerce de détail', 'show_in_list' => true, 'sort' => 1],
            ['entity' => 'partners', 'label' => 'Commercial attitré', 'type' => 'text', 'sort' => 2],
            ['entity' => 'partners', 'label' => 'Contribuable vérifié (DGI)', 'type' => 'checkbox', 'help' => 'NCC contrôlé sur le site des impôts', 'sort' => 3],
            ['entity' => 'products', 'label' => 'Marque', 'type' => 'text', 'sort' => 1],
            ['entity' => 'products', 'label' => 'Pays d\'origine', 'type' => 'select', 'options' => ['Côte d\'Ivoire', 'Ghana', 'Nigeria', 'Sénégal', 'France', 'Chine', 'Autre'], 'sort' => 2],
            ['entity' => 'products', 'label' => 'Garantie (mois)', 'type' => 'number', 'sort' => 3],
            ['entity' => 'sales_documents', 'label' => 'N° de bon de commande client', 'type' => 'text', 'show_on_pdf' => true, 'sort' => 1],
            ['entity' => 'sales_documents', 'label' => 'Délai de livraison', 'type' => 'select', 'options' => ['Immédiat', '48 heures', '1 semaine', '2 semaines'], 'show_on_pdf' => true, 'sort' => 2],
            ['entity' => 'purchase_documents', 'label' => 'N° de proforma fournisseur', 'type' => 'text', 'show_on_pdf' => true, 'sort' => 1],
            ['entity' => 'rh_employees', 'label' => 'Taille de tenue', 'type' => 'select', 'options' => ['S', 'M', 'L', 'XL', 'XXL'], 'sort' => 1],
            ['entity' => 'rh_employees', 'label' => 'Permis de conduire', 'type' => 'checkbox', 'sort' => 2],
            ['entity' => 'crm_leads', 'label' => 'Budget annoncé', 'type' => 'money', 'sort' => 1],
            ['entity' => 'crm_leads', 'label' => 'Concurrent en place', 'type' => 'text', 'sort' => 2],
        ] as $f) {
            $put($F, $f);
        }
        \App\Modules\Champs\CustomFields::flush();
        $sectors = fn (string $n) => match (true) {
            (bool) preg_match('/h[ôo]tel/i', $n) => 'Hôtellerie',
            (bool) preg_match('/maquis|restaurant|bar\b|grill|traiteur/i', $n) => 'Restauration',
            (bool) preg_match('/clinique|pharmacie|santé/i', $n) => 'Santé',
            (bool) preg_match('/mairie|minist|ecole|école|lycée/i', $n) => 'Administration',
            (bool) preg_match('/cocoa|export|atlantic/i', $n) => 'Export',
            (bool) preg_match('/^(m|mme|mlle|m\.)\s/i', $n) => 'Particulier',
            default => 'Commerce de détail',
        };
        $reps = ['Mariam Touré', 'Jean-Marc Aka'];
        $i = 0;
        foreach (\App\Modules\Core\Models\Partner::whereIn('type', ['customer', 'both'])->get() as $p) {
            $p->custom = ['secteur_dactivite' => $sectors($p->name), 'commercial_attitre' => $reps[$i++ % 2], 'contribuable_verifie_dgi' => (bool) $p->tax_id];
            $p->save();
        }
        $brands = ['riz' => ['Uncle Sam', 'Autre'], 'huile' => ['Dinor', 'Côte d\'Ivoire'], 'sucre' => ['Sucrivoire', 'Côte d\'Ivoire'], 'lait' => ['Bonnet Rouge', 'France'], 'eau' => ['Awa', 'Côte d\'Ivoire'],
            'bière' => ['Flag', 'Côte d\'Ivoire'], 'coca' => ['Coca-Cola', 'Côte d\'Ivoire'], 'savon' => ['Saprolait', 'Côte d\'Ivoire'], 'congél' => ['Hisense', 'Chine'], 'ventil' => ['Binatone', 'Chine'], 'tomate' => ['Gino', 'Ghana']];
        foreach (\App\Modules\Core\Models\Product::all() as $pr) {
            foreach ($brands as $needle => [$brand, $country]) {
                if (stripos($pr->name, $needle) !== false) {
                    $pr->custom = ['marque' => $brand, 'pays_dorigine' => $country] + (in_array($needle, ['congél', 'ventil'], true) ? ['garantie_mois' => 12] : []);
                    $pr->save();
                    break;
                }
            }
        }
        foreach (\App\Modules\Ventes\Models\SalesDocument::where('type', 'invoice')->where('status', 'validated')->whereNotNull('partner_id')->orderByDesc('date')->limit(12)->get() as $k => $d) {
            $d->custom = ['n_de_bon_de_commande_client' => 'BC-'.now()->format('Y').'-'.str_pad((string) (140 + $k * 7), 4, '0', STR_PAD_LEFT), 'delai_de_livraison' => ['48 heures', '1 semaine', 'Immédiat'][$k % 3]];
            $d->save();
        }
        foreach (\App\Modules\Rh\Models\Employee::all() as $k => $e) {
            $e->custom = ['taille_de_tenue' => ['M', 'L', 'S', 'XL', 'M'][$k % 5], 'permis_de_conduire' => $k % 2 === 0];
            $e->save();
        }
        foreach (\App\Modules\Crm\Models\Lead::all() as $k => $l) {
            $l->custom = ['budget_annonce' => round((float) $l->expected_revenue * 1.15, -4), 'concurrent_en_place' => ['Prosuma', 'CDCI', null, 'Carrefour Market'][$k % 4]];
            $l->save();
        }
        $rid = $put(\App\Modules\Rapports\Models\Report::class, ['name' => 'CA par secteur d\'activité', 'description' => 'Champ personnalisé « Secteur d\'activité » des clients.', 'source' => 'ventes', 'folder' => 'Ventes',
            'config' => ['dimensions' => ['cf_p_secteur_dactivite'], 'measures' => ['subtotal'], 'period' => 'this_year', 'chart' => 'pie'], 'visibility' => 'company']);
        if ($board = \App\Modules\Rapports\Models\Board::where('name', 'Ventes & caisse')->first()) {
            $widgets = $board->widgets;
            array_splice($widgets, 1, 0, [['id' => 'cfsect', 'report_id' => $rid, 'title' => null, 'w' => 2, 'h' => 2]]);
            $board->widgets = $widgets;
            $board->save();
        }

        // ---------------------------------------------------------------- circuits d'approbation
        $roles = \App\Models\Role::pluck('id', 'name');
        $u = fn (string $email) => User::where('email', $email)->first();
        [$mariam, $jm, $fatou, $paul, $nadia] = [$u('mariam@demo.ci'), $u('jm@demo.ci'), $u('fatou@demo.ci'), $u('paul@demo.ci'), $u('nadia@demo.ci')];
        $R = \App\Modules\Approbations\Models\Rule::class;
        $put($R, ['name' => 'Remises commerciales', 'targets' => ['sale.quote', 'sale.order'], 'discount_over' => 15, 'sort' => 1, 'reminder_hours' => 24,
            'steps' => [['name' => 'Accord de la direction', 'roles' => [$roles['Direction']], 'users' => [$admin->id]]]]);
        $put($R, ['name' => 'Bons de commande importants', 'targets' => ['purchase.purchase_order'], 'min_amount' => 1000000, 'sort' => 2, 'reminder_hours' => 24,
            'steps' => [['name' => 'Contrôle budgétaire', 'roles' => [$roles['Comptable']], 'escalate_after_hours' => 48, 'escalate_roles' => [$roles['Direction']]],
                ['name' => 'Accord de la direction', 'roles' => [$roles['Direction']], 'users' => [$admin->id]]]]);
        $put($R, ['name' => 'Notes de frais élevées', 'targets' => ['expense'], 'min_amount' => 50000, 'sort' => 3, 'reminder_hours' => 48,
            'steps' => [['name' => 'Responsable', 'roles' => [$roles['Direction']], 'users' => [$admin->id]]]]);

        $client = fn (string $like) => \App\Modules\Core\Models\Partner::where('name', 'like', $like)->value('id');
        $supplier = \App\Modules\Core\Models\Partner::whereIn('type', ['supplier', 'both'])->orderBy('name')->value('id');
        $prod = fn (string $like) => \App\Modules\Core\Models\Product::where('name', 'like', $like)->first();
        $line = function (?\App\Modules\Core\Models\Product $p, string $desc, float $q, float $price, float $disc = 0) {
            return ['product_id' => $p?->id, 'description' => $p?->name ?? $desc, 'quantity' => $q, 'unit_price' => $price, 'discount_percent' => $disc, 'tax_rate' => 18];
        };
        $riz = $prod('%riz%');
        $huile = $prod('%huile%');
        $boisson = $prod('%coca%') ?? $prod('%bière%');
        $S = \App\Modules\Ventes\Models\SalesDocument::class;
        $P = \App\Modules\Achats\Models\PurchaseDocument::class;
        $AR = \App\Modules\Approbations\Models\ApprovalRequest::class;
        $svc = app(\App\Modules\Approbations\ApprovalService::class);
        $req = fn (string $id) => $AR::where('record_id', $id)->latest('created_at')->first();
        $age = function (string $id, int $hours) use ($req) {
            $r = $req($id);
            \Illuminate\Support\Facades\DB::table('wf_requests')->where('id', $r->id)->update(['created_at' => now()->subHours($hours), 'step_started_at' => now()->subHours(max(1, $hours - 2))]);
            \Illuminate\Support\Facades\DB::table('wf_decisions')->where('request_id', $r->id)->where('decision', 'submitted')->update(['created_at' => now()->subHours($hours)]);
        };
        $doc = fn (string $class, array $data, User $as) => $put($class, $data + ['date' => now()->toDateString(), 'status' => 'validated'], $as);

        // Devis remisés en attente
        $q1 = $doc($S, ['type' => 'quote', 'partner_id' => $client('Hôtel Lagune%'), 'custom' => ['delai_de_livraison' => '1 semaine'], 'notes' => 'Contrat annuel boissons — remise volume demandée par l\'hôtel.',
            'lines' => [$line($boisson, 'Boissons', 120, 9000, 20), $line($riz, 'Riz', 40, 18500, 12)]], $mariam);
        $age($q1, 26);
        $q2 = $doc($S, ['type' => 'quote', 'partner_id' => $client('Clinique%'), 'lines' => [$line($huile, 'Huile', 30, 21000, 18)]], $jm);
        $age($q2, 5);
        // Commande approuvée (validée automatiquement)
        $o1 = $doc($S, ['type' => 'order', 'partner_id' => $client('Restaurant Le Baobab%'), 'lines' => [$line($riz, 'Riz', 25, 18500, 17)]], $jm);
        $svc->decide($req($o1), $fatou, 'approved', 'Client fidèle depuis 3 ans : d\'accord.');
        // Devis refusé
        $q3 = $doc($S, ['type' => 'quote', 'partner_id' => $client('Supérette%'), 'lines' => [$line($huile, 'Huile', 50, 21000, 30)]], $mariam);
        $svc->decide($req($q3), $fatou, 'rejected', '30 % est hors politique : 20 % maximum pour ce volume.');
        // Bons de commande : l'un validé au contrôle budgétaire, l'autre en attente du comptable
        $po1 = $doc($P, ['type' => 'purchase_order', 'partner_id' => $supplier, 'custom' => ['n_de_proforma_fournisseur' => 'PF-2026-0457'], 'lines' => [$line($riz, 'Riz', 100, 15500)]], $nadia);
        $age($po1, 30);
        $svc->decide($req($po1), $paul, 'approved', 'Budget achats du trimestre disponible.');
        $po2 = $doc($P, ['type' => 'purchase_order', 'partner_id' => $supplier, 'lines' => [$line($huile, 'Huile', 70, 17000)]], $nadia);
        $age($po2, 3);
        // Note de frais élevée en attente
        $emp = \App\Modules\Rh\Models\Employee::where('matricule', 'EMP00003')->first();
        $cat = \App\Modules\NotesFrais\Models\ExpenseCategory::where('name', 'Transport, taxi, VTC')->value('id');
        if ($emp && $cat) {
            $x = $put(\App\Modules\NotesFrais\Models\Expense::class, ['employee_id' => $emp->id, 'category_id' => $cat, 'date' => now()->subDays(2)->toDateString(), 'amount' => 65000,
                'description' => 'Location de véhicule — livraison Grand-Bassam', 'merchant' => 'Ivoire Location Auto'], User::find($emp->user_id));
            $put(\App\Modules\NotesFrais\Models\Expense::class, ['id' => $x, 'status' => 'submitted'], User::find($emp->user_id));
            $age($x, 20);
        }
        // Délégation prévue pendant une mission
        \App\Modules\Approbations\Models\Delegation::create(['user_id' => $fatou->id, 'delegate_id' => $paul->id, 'starts_on' => now()->next('Monday')->toDateString(),
            'ends_on' => now()->next('Monday')->addDays(4)->toDateString(), 'note' => 'Mission à Paris']);
        // Les demandes touchées ensuite apparaissent dans la synchronisation
        foreach ($AR::all() as $r) {
            $r->logSyncChange('upsert');
        }
        auth()->logout();
        app()->forgetInstance('tenant');
    }

    /** Rapports : tableau « Pilotage » installé, tableaux Ventes & caisse et RH, rapports partagés, envois programmés. */
    protected function rapports(Company $company): void
    {
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $admin = User::where('email', 'admin@demo.ci')->first();
        auth()->setUser($admin);
        app(\App\Modules\Rapports\RapportsSetup::class)($company);
        $settings = $company->fresh()->settings ?? [];
        $settings['_setup']['rapports'] = 1;
        $company->forceFill(['settings' => $settings])->saveQuietly();
        app()->instance('tenant', $company->fresh());

        $writer = app(\App\Services\EntityWriter::class);
        $templates = \App\Modules\Rapports\Templates::all();
        $roles = fn (array $names) => \App\Models\Role::whereIn('name', $names)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $ids = [];
        $mk = function (string $key, array $extra = []) use ($writer, $templates, &$ids) {
            $t = $templates[$key];
            $id = (string) \Illuminate\Support\Str::uuid();
            $r = $writer->upsert(\App\Modules\Rapports\Models\Report::class, $id, array_merge([
                'name' => $t['name'], 'description' => $t['description'], 'source' => $t['source'], 'config' => $t['config'], 'folder' => $t['folder'], 'visibility' => 'company',
            ], $extra));
            if ($r['status'] !== \App\Services\EntityWriter::APPLIED) {
                throw new \RuntimeException('Rapport '.$key.' : '.json_encode($r));
            }
            \App\Modules\Rapports\Models\Report::whereKey($id)->update(['template' => $key]);

            return $ids[$key] = $id;
        };
        foreach (['ca_cumule', 'jours_semaine', 'moyens_paiement', 'marge_mensuelle', 'ventes_vendeurs', 'transformation_devis', 'reussite_commerciaux',
            'achats_fournisseurs', 'achats_articles', 'principales_charges', 'notes_frais_categories', 'valeur_stock', 'temps_projets'] as $k) {
            $mk($k);
        }
        $rh = $roles(['Direction', 'RH / Paie', 'Administrateur']);
        foreach (['masse_salariale', 'masse_services', 'effectifs'] as $k) {
            $mk($k, ['visibility' => 'roles', 'role_ids' => $rh]);
        }
        $mk('top_clients', ['name' => 'Mes clients professionnels à Abidjan', 'visibility' => 'private', 'folder' => 'Mes rapports', 'description' => 'Clients facturés depuis janvier, ville Abidjan, plus de 100 000 F CFA.',
            'config' => ['dimensions' => ['partner'], 'measures' => ['subtotal', 'count', 'balance'], 'period' => 'this_year', 'chart' => 'table',
                'filters' => [['field' => 'city', 'op' => 'contains', 'value' => 'Abidjan'], ['field' => 'type', 'op' => 'eq', 'value' => 'invoice']],
                'having' => [['measure' => 'subtotal', 'op' => 'gt', 'value' => 100000]]]]);

        $board = function (string $name, string $desc, array $widgets, array $extra = []) use ($writer, &$ids) {
            $w = [];
            foreach ($widgets as $i => [$key, $width, $height]) {
                $w[] = ['id' => 'w'.($i + 1), 'report_id' => $ids[$key], 'w' => $width, 'h' => $height];
            }
            $r = $writer->upsert(\App\Modules\Rapports\Models\Board::class, (string) \Illuminate\Support\Str::uuid(), array_merge(['name' => $name, 'description' => $desc, 'widgets' => $w, 'visibility' => 'company'], $extra));
            if ($r['status'] !== \App\Services\EntityWriter::APPLIED) {
                throw new \RuntimeException('Tableau '.$name.' : '.json_encode($r));
            }
        };
        $mk('ventes_categories');
        $mk('ventes_categories', ['name' => 'CA par ville', 'source' => 'ventes', 'description' => 'Répartition du CA HT de l\'année selon la ville du client.', 'config' => ['dimensions' => ['city'], 'measures' => ['subtotal'], 'period' => 'this_year', 'limit' => 6, 'chart' => 'pie']]);
        $ids['ca_villes'] = $ids['ventes_categories'];
        $board('Ventes & caisse', 'Progression du CA, villes, affluence, moyens de paiement et marge.', [
            ['ca_cumule', 2, 2], ['ca_villes', 2, 2], ['jours_semaine', 2, 2], ['moyens_paiement', 2, 2], ['marge_mensuelle', 2, 2], ['ventes_vendeurs', 2, 2],
        ], ['sort' => 1]);
        $board('Ressources humaines', 'Masse salariale, coût par service et effectifs.', [
            ['masse_salariale', 2, 2], ['effectifs', 2, 2], ['masse_services', 4, 2],
        ], ['visibility' => 'roles', 'role_ids' => $rh, 'sort' => 2]);

        $pilotage = \App\Modules\Rapports\Models\Board::where('name', 'Pilotage de la direction')->first();
        $svc = app(\App\Modules\Rapports\RapportsService::class);
        foreach ([
            ['dashboard_id' => $pilotage?->id, 'frequency' => 'weekly', 'weekday' => 1, 'hour' => 7, 'recipients' => ['admin@demo.ci', 'direction@demo.ci'], 'format' => 'pdf', 'period' => 'last_week',
                'message' => "Bonjour,\n\nVoici le tableau de bord de la semaine écoulée.", 'last_sent_at' => now()->startOfWeek()->setTime(7, 0), 'last_status' => 'sent'],
            ['report_id' => $ids['ca_cumule'], 'frequency' => 'monthly', 'monthday' => 1, 'hour' => 8, 'recipients' => ['comptabilite@demo.ci'], 'format' => 'xlsx',
                'last_sent_at' => now()->startOfMonth()->setTime(8, 0), 'last_status' => 'sent'],
            ['report_id' => $ids['moyens_paiement'], 'frequency' => 'daily', 'hour' => 20, 'recipients' => ['caisse@demo.ci'], 'format' => 'pdf', 'period' => 'today'],
        ] as $data) {
            if (empty($data['dashboard_id']) && empty($data['report_id'])) {
                continue;
            }
            $sch = new \App\Modules\Rapports\Models\Schedule($data + ['owner_id' => $admin->id, 'active' => true]);
            $sch->next_run_at = $svc->nextRun($sch);
            $sch->save();
        }
        auth()->logout();
        app()->forgetInstance('tenant');
    }

    /** Devises (cours du dollar, client américain, facture en euros), relances (historique), abonnements (contrats en cours). */
    protected function devisesRelancesAbonnements(Company $company): void
    {
        app()->instance('tenant', $company);
        $admin = User::where('email', 'admin@demo.ci')->first();
        auth()->setUser($admin);
        foreach (['devises' => \App\Modules\Devises\DevisesSetup::class, 'relances' => \App\Modules\Relances\RelancesSetup::class, 'abonnements' => \App\Modules\Abonnements\AbonnementsSetup::class] as $k => $cls) {
            app($cls)($company);
            $settings = $company->fresh()->settings ?? [];
            $settings['_setup'][$k] = 1;
            $company->forceFill(['settings' => $settings])->saveQuietly();
        }
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $writer = app(\App\Services\EntityWriter::class);
        $put = function (string $class, array $data) use ($writer) {
            $id = (string) \Illuminate\Support\Str::uuid();
            $r = $writer->upsert($class, $id, $data);
            if ($r['status'] !== \App\Services\EntityWriter::APPLIED) {
                throw new \RuntimeException(class_basename($class).' : '.($r['message'] ?? '').' '.json_encode($r['errors'] ?? []));
            }

            return $class::find($id);
        };

        // Cours du dollar (mensuels)
        $FX = \App\Modules\Devises\Models\FxRate::class;
        foreach ([[6, 612.40], [5, 605.85], [4, 598.10], [3, 603.70], [2, 596.25], [1, 590.80], [0, 586.15]] as [$m, $rate]) {
            $put($FX, ['currency' => 'USD', 'date' => now()->subMonthsNoOverflow($m)->startOfMonth()->toDateString(), 'rate' => $rate, 'note' => 'Cours BCEAO']);
        }
        $usa = $put(\App\Modules\Core\Models\Partner::class, ['type' => 'customer', 'is_company' => true, 'name' => 'Atlantic Cocoa Trading LLC', 'email' => 'ap@atlanticcocoa.com', 'country' => 'US', 'currency' => 'USD']);
        $paris = $put(\App\Modules\Core\Models\Partner::class, ['type' => 'customer', 'is_company' => true, 'name' => 'Épicerie Fine Saint-Germain', 'email' => 'compta@efsg.fr', 'country' => 'FR', 'currency' => 'EUR']);
        $S = \App\Modules\Ventes\Models\SalesDocument::class;
        $usInv = $put($S, ['type' => 'invoice', 'partner_id' => $usa->id, 'date' => now()->subMonthsNoOverflow(2)->startOfMonth()->addDays(9)->toDateString(), 'status' => 'validated',
            'lines' => [['description' => 'Noix de cajou W320 — conteneur 20 pieds', 'quantity' => 1, 'unit_price' => 28500, 'tax_rate' => 0], ['description' => 'Fret et assurance (CIF)', 'quantity' => 1, 'unit_price' => 1850, 'tax_rate' => 0]]]);
        $put(\App\Modules\Ventes\Models\SalesPayment::class, ['document_id' => $usInv->id, 'date' => now()->subMonthsNoOverflow(1)->startOfMonth()->addDays(4)->toDateString(), 'amount' => 30350, 'method' => 'bank_transfer', 'reference' => 'SWIFT 2026-0412']);
        $put($S, ['type' => 'invoice', 'partner_id' => $paris->id, 'date' => now()->subDays(20)->toDateString(), 'status' => 'validated',
            'lines' => [['description' => 'Attiéké déshydraté 500 g (carton de 24)', 'quantity' => 40, 'unit_price' => 52.80, 'tax_rate' => 0], ['description' => 'Piment séché en poudre 100 g (carton de 48)', 'quantity' => 15, 'unit_price' => 61.20, 'tax_rate' => 0]]]);
        $put($S, ['type' => 'quote', 'partner_id' => $usa->id, 'date' => now()->toDateString(), 'status' => 'validated',
            'lines' => [['description' => 'Noix de cajou W240 — conteneur 20 pieds', 'quantity' => 2, 'unit_price' => 31200, 'tax_rate' => 0]]]);

        // Relances : historique des envois sur les factures échues
        $svc = app(\App\Modules\Relances\DunningService::class);
        $levels = $svc->settings($company)['levels'];
        foreach ($svc->overdue($company)->groupBy('partner_id') as $pid => $docs) {
            $partner = \App\Modules\Core\Models\Partner::find($pid);
            $sent = 0;
            foreach ($docs as $d) {
                $lvl = $svc->levelFor($levels, (int) $d->due_date->diffInDays(now()->startOfDay()));
                $d->forceFill(['dunning_level' => $lvl, 'last_dunning_at' => now()->subDays(2)])->saveQuietly();
                $sent = max($sent, $lvl);
            }
            if ($sent > 0) {
                \App\Modules\Relances\Models\Reminder::create(['partner_id' => $pid, 'level' => $sent, 'level_name' => $levels[$sent - 1]['name'],
                    'documents' => $docs->map(fn ($d) => ['id' => $d->id, 'number' => $d->number, 'balance' => $d->balance(), 'currency' => $d->currency, 'days' => (int) $d->due_date->diffInDays(now()->startOfDay())])->values()->all(),
                    'channels' => array_filter(['email' => $partner?->email ? 'ok' : null, 'whatsapp' => $partner?->phone ? 'ok' : null]) ?: ['email' => 'ok'], 'status' => 'sent']);
            }
        }

        // Abonnements : maintenance mensuelle, location trimestrielle, licence annuelle en euros
        $customers = \App\Modules\Core\Models\Partner::whereIn('type', ['customer', 'both'])->whereNull('currency')->orderByRaw('email IS NULL')->pluck('id')->all();
        $SUB = \App\Modules\Abonnements\Models\Subscription::class;
        $sec = fn (string $c) => class_exists(\App\Modules\Analytique\Models\Section::class) ? \App\Modules\Analytique\Models\Section::where('code', $c)->value('id') : null;
        $an = fn (array $codes) => array_filter(collect($codes)->mapWithKeys(fn ($p, $c) => [$sec($c) => $p])->filter(fn ($p, $k) => $k)->all()) ?: null;
        $put($SUB, ['partner_id' => $customers[0] ?? $usa->id, 'name' => 'Contrat de maintenance climatisation (12 appareils)', 'start_date' => now()->subMonthsNoOverflow(5)->startOfMonth()->toDateString(),
            'interval_unit' => 'month', 'interval_count' => 1, 'auto_validate' => true, 'analytic' => $an(['SAV' => 100, 'MAR' => 100]),
            'lines' => [['description' => 'Maintenance préventive mensuelle', 'quantity' => 12, 'unit_price' => 15000, 'tax_rate' => 18], ['description' => 'Astreinte dépannage 24 h/24', 'quantity' => 1, 'unit_price' => 45000, 'tax_rate' => 18]]]);
        $put($SUB, ['partner_id' => $customers[1] ?? $usa->id, 'name' => 'Location chambre froide 6 m³', 'start_date' => now()->subMonthsNoOverflow(7)->startOfMonth()->toDateString(),
            'interval_unit' => 'month', 'interval_count' => 3, 'auto_validate' => true, 'analytic' => $an(['RST' => 100, 'PLA' => 100]),
            'lines' => [['description' => 'Location trimestrielle', 'quantity' => 1, 'unit_price' => 360000, 'tax_rate' => 18]]]);
        $put($SUB, ['partner_id' => $paris->id, 'name' => 'Abonnement approvisionnement mensuel', 'start_date' => now()->subMonthsNoOverflow(3)->startOfMonth()->toDateString(),
            'interval_unit' => 'month', 'interval_count' => 1, 'currency' => 'EUR', 'auto_validate' => true, 'max_invoices' => 12,
            'lines' => [['description' => 'Colis épicerie ivoirienne (assortiment 20 kg)', 'quantity' => 3, 'unit_price' => 185, 'tax_rate' => 0]]]);
        $put($SUB, ['partner_id' => $customers[2] ?? $usa->id, 'name' => 'Hébergement site e-commerce et sauvegardes', 'start_date' => now()->subMonthsNoOverflow(11)->startOfMonth()->toDateString(),
            'interval_unit' => 'year', 'interval_count' => 1, 'auto_validate' => true,
            'lines' => [['description' => 'Hébergement annuel + nom de domaine', 'quantity' => 1, 'unit_price' => 240000, 'tax_rate' => 18]]]);
        $paused = $put($SUB, ['partner_id' => $customers[3] ?? $usa->id, 'name' => 'Livraison hebdomadaire de boissons', 'start_date' => now()->subWeeks(6)->startOfWeek()->toDateString(),
            'interval_unit' => 'week', 'interval_count' => 1, 'auto_validate' => true,
            'lines' => [['description' => 'Casiers de boissons assorties', 'quantity' => 10, 'unit_price' => 7500, 'tax_rate' => 18]]]);
        app(\App\Modules\Abonnements\SubscriptionService::class)->runDue($company);
        $paused->refresh()->forceFill(['status' => 'paused'])->save();
        app()->forgetInstance('tenant');
    }

    /** Immobilisations : véhicules, informatique, climatisation, mobilier, logiciel, groupe repris d'un ancien registre, moto mise au rebut. */
    protected function immobilisations(Company $company): void
    {
        app()->instance('tenant', $company);
        $admin = User::where('email', 'admin@demo.ci')->first();
        auth()->setUser($admin);
        app(\App\Modules\Immobilisations\ImmobilisationsSetup::class)($company);
        $settings = $company->fresh()->settings ?? [];
        $settings['_setup']['immobilisations'] = 1;
        $company->forceFill(['settings' => $settings])->saveQuietly();
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $svc = app(\App\Modules\Immobilisations\AssetService::class);
        $cat = fn (string $n) => \App\Modules\Immobilisations\Models\AssetCategory::where('name', $n)->value('id');
        $sec = fn (string $code) => class_exists(\App\Modules\Analytique\Models\Section::class) ? \App\Modules\Analytique\Models\Section::where('code', $code)->value('id') : null;
        $emp = fn (string $first) => \App\Modules\Rh\Models\Employee::where('first_name', $first)->value('id');
        $partner = fn (string $n) => \App\Modules\Core\Models\Partner::where('name', $n)->value('id');
        $y = now()->year;
        $mk = function (array $a) use ($svc) {
            $asset = new \App\Modules\Immobilisations\Models\Asset(collect($a)->except(['analytic_codes'])->all());
            $asset->created_by = auth()->id();
            $asset->save();

            return $svc->activate($asset);
        };
        $an = fn (array $codes) => array_filter(collect($codes)->mapWithKeys(fn ($p, $c) => [$sec($c) => $p])->filter(fn ($p, $k) => $k)->all()) ?: null;

        $mk(['name' => 'Toyota Hilux double cabine AB-4521-CI', 'category_id' => $cat('Matériel de transport'), 'value' => 24500000, 'acquisition_date' => ($y - 2).'-02-10', 'in_service_date' => ($y - 2).'-02-15',
            'method' => 'linear', 'years' => 4, 'serial_number' => 'AHTFR22G507123456', 'location' => 'Entrepôt Yopougon', 'post_acquisition' => true, 'analytic' => $an(['NEG' => 100, 'YOP' => 100])]);
        $mk(['name' => 'Camion Isuzu NPR 3,5 t — livraisons', 'category_id' => $cat('Matériel de transport'), 'value' => 31000000, 'acquisition_date' => ($y - 1).'-06-01', 'in_service_date' => ($y - 1).'-06-01',
            'method' => 'degressive', 'years' => 5, 'serial_number' => 'JAANPR75H0712345', 'location' => 'Entrepôt Yopougon', 'post_acquisition' => true, 'analytic' => $an(['NEG' => 100, 'YOP' => 100])]);
        $mk(['name' => 'Ordinateurs portables HP ProBook (x6)', 'category_id' => $cat('Matériel informatique'), 'value' => 3900000, 'acquisition_date' => $y.'-01-12', 'in_service_date' => $y.'-01-15',
            'method' => 'linear', 'years' => 3, 'location' => 'Siège Marcory', 'employee_id' => $emp('Awa'), 'post_acquisition' => true, 'partner_id' => $partner('Librairie de France — Pro'), 'analytic' => $an(['MAR' => 100])]);
        $mk(['name' => 'Climatiseurs split 2 CV (x4) — boutique', 'category_id' => $cat('Matériel de bureau'), 'value' => 2200000, 'acquisition_date' => ($y - 1).'-03-20', 'in_service_date' => ($y - 1).'-04-01',
            'method' => 'linear', 'years' => 5, 'location' => 'Boutique Plateau', 'post_acquisition' => true, 'analytic' => $an(['BTQ' => 100, 'PLA' => 100])]);
        $mk(['name' => 'Mobilier de bureau — open space', 'category_id' => $cat('Mobilier de bureau'), 'value' => 4800000, 'acquisition_date' => ($y - 2).'-09-01', 'in_service_date' => ($y - 2).'-09-01',
            'method' => 'linear', 'years' => 10, 'location' => 'Siège Marcory', 'post_acquisition' => true, 'analytic' => $an(['MAR' => 100])]);
        $mk(['name' => 'Licence logiciel de caisse et site e-commerce', 'category_id' => $cat('Logiciels et sites internet'), 'value' => 2500000, 'acquisition_date' => ($y - 1).'-01-05', 'in_service_date' => ($y - 1).'-01-05',
            'method' => 'linear', 'years' => 3, 'post_acquisition' => true, 'analytic' => $an(['BTQ' => 100, 'PLA' => 100])]);
        $mk(['name' => 'Chambre froide 12 m³ — restaurant', 'category_id' => $cat('Matériel et outillage'), 'value' => 7800000, 'acquisition_date' => ($y - 1).'-11-15', 'in_service_date' => ($y - 1).'-12-01',
            'method' => 'linear', 'years' => 8, 'location' => 'Boutique Plateau', 'post_acquisition' => true, 'analytic' => $an(['RST' => 100, 'PLA' => 100])]);
        // Repris de l'ancien registre (amortissements antérieurs sans écriture)
        $mk(['name' => 'Groupe électrogène SDMO 60 kVA', 'category_id' => $cat('Matériel et outillage'), 'value' => 14500000, 'acquisition_date' => ($y - 4).'-05-01', 'in_service_date' => ($y - 4).'-05-01',
            'method' => 'linear', 'years' => 8, 'location' => 'Siège Marcory', 'opening_date' => ($y - 1).'-12-31', 'analytic' => $an(['MAR' => 100])]);
        // Dotations des exercices passés comptabilisées ; l'exercice en cours le sera à la clôture
        $svc->postDue($company, ($y - 1).'-12-31');
        // Moto de livraison mise au rebut après accident
        $moto = $mk(['name' => 'Moto Yamaha Crux — livraisons', 'category_id' => $cat('Matériel de transport'), 'value' => 950000, 'acquisition_date' => ($y - 2).'-04-01', 'in_service_date' => ($y - 2).'-04-01',
            'method' => 'linear', 'years' => 4, 'post_acquisition' => true]);
        $svc->postDue($company, ($y - 1).'-12-31', $moto->id);
        $svc->dispose($moto, ['date' => $y.'-03-18', 'type' => 'scrap', 'note' => 'Accident — épave non réparable']);
        // Brouillon à compléter
        $draft = new \App\Modules\Immobilisations\Models\Asset(['name' => 'Vidéoprojecteur Epson salle de réunion', 'category_id' => $cat('Matériel de bureau'), 'value' => 650000, 'acquisition_date' => now()->subDays(6)->toDateString(), 'method' => 'linear', 'years' => 5]);
        $draft->save();
        app()->forgetInstance('tenant');
    }

    /** Analytique : activités et sites, règles, historique de l'année, réaffectation du loyer, budget approuvé avec alertes. */
    protected function analytique(Company $company): void
    {
        app()->instance('tenant', $company);
        $admin = User::where('email', 'admin@demo.ci')->first();
        auth()->setUser($admin);
        app(\App\Modules\Analytique\AnalytiqueSetup::class)($company);
        $settings = $company->fresh()->settings ?? [];
        $settings['_setup']['analytique'] = 1;
        $company->forceFill(['settings' => $settings])->saveQuietly();
        $company = $company->fresh();
        app()->instance('tenant', $company);

        $axis = function (string $name, string $code, int $sort) {
            $a = new \App\Modules\Analytique\Models\Axis(['name' => $name, 'code' => $code, 'sort' => $sort]);
            $a->save();

            return $a;
        };
        $section = function ($axis, string $code, string $name, ?int $manager = null) {
            $s = new \App\Modules\Analytique\Models\Section(['axis_id' => $axis->id, 'code' => $code, 'name' => $name, 'manager_id' => $manager]);
            $s->save();

            return $s->id;
        };
        $act = $axis('Activité', 'ACT', 1);
        $site = $axis('Site', 'SITE', 2);
        $neg = $section($act, 'NEG', 'Négoce (vente en gros)', $admin->id);
        $btq = $section($act, 'BTQ', 'Boutique');
        $rst = $section($act, 'RST', 'Restaurant');
        $sav = $section($act, 'SAV', 'Services techniques');
        $siege = $section($site, 'MAR', 'Siège Marcory');
        $plateau = $section($site, 'PLA', 'Boutique Plateau');
        $yop = $section($site, 'YOP', 'Entrepôt Yopougon');

        $rule = function (array $attrs, array $dist) {
            $r = new \App\Modules\Analytique\Models\Rule($attrs + ['distribution' => $dist]);
            $r->save();
        };
        $cat = fn (string $n) => \App\Modules\Core\Models\ProductCategory::where('name', $n)->value('id');
        foreach (['Plats', 'Grillades', 'Desserts'] as $c) {
            if ($id = $cat($c)) {
                $rule(['applies_to' => 'sale', 'category_id' => $id], [$rst => 100, $plateau => 100]);
            }
        }
        foreach (['Boissons', 'Épicerie', 'Hygiène'] as $c) {
            if ($id = $cat($c)) {
                $rule(['applies_to' => 'sale', 'category_id' => $id], [$btq => 100, $plateau => 100]);
            }
        }
        if ($id = $cat('Matières premières')) {
            $rule(['applies_to' => 'purchase', 'category_id' => $id], [$rst => 100, $plateau => 100]);
        }
        $partner = fn (string $n) => \App\Modules\Core\Models\Partner::where('name', $n)->value('id');
        if ($id = $partner('Grossiste Adjamé SARL')) {
            $rule(['applies_to' => 'purchase', 'partner_id' => $id], [$neg => 100, $yop => 100]);
        }
        if ($id = $partner('Solibra Distribution')) {
            $rule(['applies_to' => 'purchase', 'partner_id' => $id], [$btq => 100, $plateau => 100]);
        }
        foreach (\App\Modules\Core\Models\Product::whereNull('category_id')->get() as $p) {
            $rule(['applies_to' => 'any', 'product_id' => $p->id], [$sav => 100, $siege => 100]);
        }
        $rule(['applies_to' => 'payroll', 'department' => 'Boutique'], [$btq => 100, $plateau => 100]);
        $rule(['applies_to' => 'payroll', 'department' => 'Logistique'], [$neg => 100, $yop => 100]);
        $rule(['applies_to' => 'payroll', 'department' => 'Direction'], [$neg => 40, $btq => 30, $rst => 20, $sav => 10, $siege => 100]);

        // Historique de l'année : ventes et achats mensuels par activité
        mt_srand(2026);
        $customers = \App\Modules\Core\Models\Partner::whereIn('type', ['customer', 'both'])->pluck('id')->all();
        $suppliers = \App\Modules\Core\Models\Partner::whereIn('type', ['supplier', 'both'])->pluck('id', 'name')->all();
        $mkDoc = function (string $class, string $type, ?string $partner, string $date, array $lines, array $dist) {
            $d = new $class;
            $d->fill(['type' => $type, 'partner_id' => $partner, 'partner_name' => $partner ? \App\Modules\Core\Models\Partner::find($partner)?->name : null, 'date' => $date, 'analytic' => $dist ?: null]);
            $d->save();
            $d->replaceLines($lines);
            $d->refresh()->validateDocument();
            // Réglé un mois plus tard (sauf le mois dernier, encore ouvert)
            $paid = \Illuminate\Support\Carbon::parse($date)->addDays(25);
            if ($paid->lt(now()->startOfMonth()->subDays(5))) {
                $payClass = $class === \App\Modules\Ventes\Models\SalesDocument::class ? \App\Modules\Ventes\Models\SalesPayment::class : \App\Modules\Achats\Models\PurchasePayment::class;
                $p = new $payClass(['document_id' => $d->id, 'date' => $paid->toDateString(), 'amount' => $d->fresh()->total, 'method' => $partner ? 'bank_transfer' : 'cash']);
                $p->id = (string) \Illuminate\Support\Str::uuid();
                $p->partner_id = $d->partner_id;
                $p->save();
                $d->refresh()->refreshPaymentStatus();
            }
        };
        $S = \App\Modules\Ventes\Models\SalesDocument::class;
        $P = \App\Modules\Achats\Models\PurchaseDocument::class;
        $season = [1 => 0.8, 2 => 0.85, 3 => 0.95, 4 => 1.0, 5 => 1.05, 6 => 0.9, 7 => 0.85, 8 => 0.8, 9 => 1.0, 10 => 1.1, 11 => 1.15, 12 => 1.4];
        for ($m = 1; $m < (int) now()->month; $m++) {
            $f = $season[$m];
            $day = fn (int $d) => now()->setDate(now()->year, $m, min($d, 28))->toDateString();
            $mkDoc($S, 'invoice', $customers[array_rand($customers)], $day(6), [['description' => 'Riz brisé (vrac) — lot de 90 sacs', 'quantity' => 90, 'unit_price' => round(mt_rand(17000, 19000) * $f, -2), 'tax_rate' => 18]], [$neg => 100, $yop => 100]);
            $mkDoc($S, 'invoice', $customers[array_rand($customers)], $day(18), [['description' => 'Huile et sucre — livraison grossiste', 'quantity' => 1, 'unit_price' => round(mt_rand(350000, 520000) * $f, -3), 'tax_rate' => 18]], [$neg => 100, $yop => 100]);
            $mkDoc($S, 'invoice', $customers[array_rand($customers)], $day(12), [['description' => 'Contrat de maintenance climatisation', 'quantity' => 1, 'unit_price' => round(mt_rand(250000, 420000) * $f, -3), 'tax_rate' => 18]], [$sav => 100, $siege => 100]);
            $mkDoc($S, 'invoice', $customers[array_rand($customers)], $day(22), [['description' => 'Repas d\'entreprise et traiteur', 'quantity' => 1, 'unit_price' => round(mt_rand(180000, 300000) * $f, -3), 'tax_rate' => 18]], [$rst => 100, $plateau => 100]);
            $mkDoc($S, 'invoice', null, $day(27), [['description' => 'Ventes boutique du mois (récapitulatif)', 'quantity' => 1, 'unit_price' => round(mt_rand(600000, 820000) * $f, -3), 'tax_rate' => 18]], [$btq => 100, $plateau => 100]);
            if ($sup = $suppliers['Grossiste Adjamé SARL'] ?? null) {
                $mkDoc($P, 'supplier_invoice', $sup, $day(4), [['description' => 'Riz brisé et huile en gros', 'quantity' => 1, 'unit_price' => round(mt_rand(950000, 1150000) * $f, -3), 'tax_rate' => 18]], []);
            }
            if ($sup = $suppliers['Solibra Distribution'] ?? null) {
                $mkDoc($P, 'supplier_invoice', $sup, $day(9), [['description' => 'Boissons boutique', 'quantity' => 1, 'unit_price' => round(mt_rand(380000, 520000) * $f, -3), 'tax_rate' => 18]], []);
            }
            $mkDoc($P, 'supplier_invoice', $suppliers['Librairie de France — Pro'] ?? null, $day(14), [['description' => 'Denrées restaurant (marché)', 'quantity' => 1, 'unit_price' => round(mt_rand(110000, 170000) * $f, -3), 'tax_rate' => 0]], [$rst => 100, $plateau => 100]);
            $mkDoc($P, 'supplier_invoice', $suppliers['Librairie de France — Pro'] ?? null, $day(16), [['description' => 'Pièces et gaz réfrigérant', 'quantity' => 1, 'unit_price' => round(mt_rand(90000, 150000) * $f, -3), 'tax_rate' => 18]], [$sav => 100, $siege => 100]);
        }

        $an = app(\App\Modules\Analytique\AnalyticService::class);
        $an->rebuild($company);
        // Loyer du siège réparti chaque mois entre les activités
        for ($m = 1; $m <= (int) now()->month; $m++) {
            $an->manual(now()->setDate(now()->year, $m, 1)->toDateString(), 'Loyer du siège réparti', -450000, '622', [$neg => 40, $btq => 25, $rst => 20, $sav => 15, $siege => 100]);
        }

        // Budget de l'année, approuvé
        $year = now()->year;
        $b = new \App\Modules\Analytique\Models\Budget(['name' => "Budget $year", 'start_date' => "$year-01-01", 'end_date' => "$year-12-31", 'warn_percent' => 80, 'notes' => 'Validé en conseil de gérance de janvier.']);
        $b->company_id = $company->id;
        $b->created_by = $admin->id;
        $b->save();
        $svc = app(\App\Modules\Analytique\BudgetService::class);
        $svc->saveLines($company, $b, [
            ['kind' => 'revenue', 'section_id' => $neg, 'label' => 'Chiffre d\'affaires négoce', 'total' => 18000000],
            ['kind' => 'revenue', 'section_id' => $sav, 'label' => 'Contrats de maintenance', 'total' => 4200000],
            ['kind' => 'revenue', 'section_id' => $btq, 'label' => 'Ventes boutique', 'total' => 9000000],
            ['kind' => 'expense', 'section_id' => $neg, 'account_prefix' => '60', 'label' => 'Achats de marchandises négoce', 'total' => 12500000, 'owner_id' => $admin->id],
            ['kind' => 'expense', 'section_id' => $rst, 'label' => 'Charges restaurant', 'total' => 2600000],
            ['kind' => 'expense', 'section_id' => $sav, 'label' => 'Pièces et sous-traitance SAV', 'total' => 1200000],
            ['kind' => 'expense', 'section_id' => $plateau, 'label' => 'Charges boutique Plateau', 'total' => 9500000],
        ]);
        $b->forceFill(['status' => 'approved', 'approved_by' => $admin->id, 'approved_at' => now()->setDate($year, 1, 20)])->save();
        $svc->checkAlerts($company);
        app()->forgetInstance('tenant');
    }

    /** FNE : connexion DGI en environnement de test, factures déjà certifiées, une refusée, une en attente. */
    protected function fne(Company $company): void
    {
        app()->instance('tenant', $company);
        app(\App\Modules\Fne\FneSetup::class)($company);
        $settings = $company->fresh()->settings ?? [];
        $settings['_setup']['fne'] = 1;
        $company->forceFill(['settings' => $settings])->saveQuietly();
        $company = $company->fresh();
        app()->instance('tenant', $company);

        $cfg = new \App\Modules\Fne\Models\FneConfig;
        $cfg->company_id = $company->id;
        // Pas de clé API en démonstration : les nouvelles factures restent « en attente » au lieu d'appeler la DGI.
        $cfg->fill(['enabled' => true, 'environment' => 'test', 'point_of_sale' => 'Caisse principale', 'establishment' => 'Siège Marcory',
            'commercial_message' => 'Merci pour votre confiance', 'sticker_alert' => 200]);
        $cfg->sticker_balance = 1240;
        $cfg->sticker_balance_at = now()->subHours(3);
        $cfg->save();

        $C = \App\Modules\Fne\Models\FneCertification::class;
        $docs = \App\Modules\Ventes\Models\SalesDocument::with('lines')->where('status', 'validated')->whereIn('type', ['invoice', 'receipt'])
            ->whereNotNull('number')->orderBy('validated_at')->get();
        $n = 0;
        $balance = 1240 + $docs->count();
        $failedId = $docs->where('type', 'invoice')->whereNotNull('partner_id')->last()?->id;
        $pendingId = $docs->where('type', 'receipt')->last()?->id;
        foreach ($docs as $i => $d) {
            $c = new $C;
            $c->document_id = $d->id;
            $c->document_number = $d->number;
            $c->document_type = $d->type;
            $c->invoice_type = 'sale';
            $c->environment = 'test';
            $partner = $d->partner_id ? \App\Modules\Core\Models\Partner::find($d->partner_id) : null;
            $c->template = $partner && $partner->country && $partner->country !== 'CI' ? 'B2F' : ($partner && $partner->tax_id ? 'B2B' : 'B2C');
            $c->payment_method = $d->payment_status === 'paid' ? 'cash' : 'deferred';
            if ($d->id === $failedId) {
                // La plus récente : refusée (NCC du client manquant) pour montrer la correction
                $c->status = 'failed';
                $c->template = 'B2B';
                $c->attempts = 1;
                $c->last_status = 400;
                $c->last_error = 'Données refusées par la FNE (400) : clientNcc est obligatoire pour une facture B2B. Renseignez le NCC du client ou certifiez en B2C.';
            } elseif ($d->id === $pendingId) {
                $c->status = 'pending';
                $c->attempts = 2;
                $c->template = 'B2C';
                $c->last_error = 'Serveur FNE injoignable : nouvel essai automatique.';
                $c->next_attempt_at = now()->addMinutes(4);
            } else {
                $n++;
                $ref = '1904512K'.now()->format('y').str_pad((string) (1000 + $n), 7, '0', STR_PAD_LEFT);
                $token = (string) \Illuminate\Support\Str::uuid();
                $c->status = 'certified';
                $c->attempts = 1;
                $c->last_status = 200;
                $c->reference = $ref;
                $c->ncc = '1904512K';
                $c->fne_invoice_id = (string) \Illuminate\Support\Str::uuid();
                $c->token = $token;
                $c->verify_url = 'http://54.247.95.108/fr/verification/'.$token;
                $c->fiscal_stamp = 'ST-'.strtoupper(substr(md5($ref), 0, 10));
                $c->amount = $d->subtotal;
                $c->vat_amount = $d->tax_total;
                $c->sticker_balance = --$balance;
                $c->items = $d->lines->values()->map(fn ($l, $k) => ['line_id' => $l->id, 'fne_id' => (string) \Illuminate\Support\Str::uuid(), 'quantity' => (float) $l->quantity, 'product_id' => $l->product_id, 'description' => $l->description])->all();
                $c->certified_at = ($d->validated_at ?? now())->copy()->addSeconds(4);
            }
            $c->save();
        }
        app()->forgetInstance('tenant');
    }

    /** Notes de frais : taxis remboursés par la caisse, carburant à rembourser, repas à valider, une dépense refusée. */
    protected function notesFrais(Company $company): void
    {
        app()->instance('tenant', $company);
        app(\App\Modules\NotesFrais\NotesFraisSetup::class)($company);
        $settings = $company->fresh()->settings ?? [];
        $settings['_setup']['notes_frais'] = 1;
        $company->forceFill(['settings' => $settings])->saveQuietly();
        $company = $company->fresh();
        app()->instance('tenant', $company);

        $w = app(\App\Services\EntityWriter::class);
        $X = \App\Modules\NotesFrais\Models\Expense::class;
        $cat = \App\Modules\NotesFrais\Models\ExpenseCategory::pluck('id', 'name');
        $emp = fn (string $m) => \App\Modules\Rh\Models\Employee::where('matricule', $m)->first();
        $paul = User::where('email', 'paul@demo.ci')->first() ?? User::where('email', 'admin@demo.ci')->first();
        $receipt = function (string $id, string $title, int $amount) use ($company, $X) {
            if (! function_exists('imagecreatetruecolor')) {
                return;
            }
            $im = imagecreatetruecolor(360, 520);
            imagefill($im, 0, 0, imagecolorallocate($im, 250, 248, 240));
            $ink = imagecolorallocate($im, 40, 40, 40);
            $y = 30;
            foreach ([strtoupper($title), 'Abidjan - '.now()->format('d/m/Y'), str_repeat('-', 40), 'Montant TTC', number_format($amount, 0, ',', ' ').' F CFA', str_repeat('-', 40), 'Merci de votre visite'] as $line) {
                imagestring($im, $y === 30 ? 5 : 4, 24, $y, $line, $ink);
                $y += 48;
            }
            ob_start();
            imagejpeg($im, null, 80);
            $path = 'receipts/'.$company->id.'/'.$id.'-demo.jpg';
            \Illuminate\Support\Facades\Storage::disk('local')->put($path, ob_get_clean());
            $X::find($id)->forceFill(['receipt_path' => $path, 'receipt_name' => 'ticket.jpg'])->save();
        };
        $mk = function (\App\Modules\Rh\Models\Employee $e, string $category, int $daysAgo, int $amount, string $desc, ?string $merchant = null, bool $withReceipt = true) use ($w, $X, $cat, $receipt) {
            $id = (string) \Illuminate\Support\Str::uuid();
            auth()->setUser(User::find($e->user_id));
            $w->upsert($X, $id, ['employee_id' => $e->id, 'category_id' => $cat[$category], 'date' => now()->subDays($daysAgo)->toDateString(), 'amount' => $amount, 'description' => $desc, 'merchant' => $merchant]);
            if ($withReceipt) {
                $receipt($id, $merchant ?? $desc, $amount);
            }

            return $id;
        };
        $as = function (?User $u, string $id, array $data) use ($w, $X) {
            auth()->setUser($u);
            $r = $w->upsert($X, $id, $data);
            if ($r['status'] !== 'applied') {
                throw new \RuntimeException('Note de frais : '.$r['message']);
            }
        };
        $yao = $emp('EMP00002');
        $mariam = $emp('EMP00003');
        $yaoU = User::find($yao->user_id);
        $mariamU = User::find($mariam->user_id);

        // Taxis du mois dernier : validés et remboursés par la caisse
        $t1 = $mk($yao, 'Transport, taxi, VTC', 34, 4500, 'Taxi dépôt de la recette à la banque', null, false);
        $t2 = $mk($yao, 'Transport, taxi, VTC', 30, 3000, 'Taxi livraison urgente Cocody', null, false);
        foreach ([$t1, $t2] as $id) {
            $as($yaoU, $id, ['status' => 'submitted']);
            $as($paul, $id, ['status' => 'approved']);
        }
        auth()->setUser($paul);
        $cash = \App\Modules\Tresorerie\Models\TreasuryAccount::where('kind', 'cash')->value('id');
        if ($cash) {
            app(\App\Modules\NotesFrais\NotesFraisService::class)->pay($company, $yao->id, [$t1, $t2], ['via' => 'treasury', 'account_id' => $cash, 'date' => now()->subDays(28)->toDateString()]);
        }
        // Carburant approuvé, à rembourser
        $f = $mk($yao, 'Carburant', 9, 20000, 'Carburant groupe électrogène (coupure)', 'Station Total Marcory');
        $as($yaoU, $f, ['status' => 'submitted']);
        $as($paul, $f, ['status' => 'approved']);
        // Repas au-delà du plafond, à valider
        $r = $mk($yao, 'Repas et réceptions', 3, 22500, 'Déjeuner fournisseur Coca-Cola (négociation tarifs)', 'Restaurant Le Baobab');
        $as($yaoU, $r, ['status' => 'submitted', 'project' => 'Négociation fournisseurs']);
        // Refusée : justificatif illisible
        $x = $mk($yao, 'Fournitures et petit matériel', 6, 7500, 'Rallonge électrique caisse 2', 'Quincaillerie Adjamé');
        $as($yaoU, $x, ['status' => 'submitted']);
        $as($paul, $x, ['status' => 'rejected', 'decision_note' => 'Ticket illisible : merci de reprendre la photo']);
        // Mariam : taxi à valider, brouillon de téléphone
        $m1 = $mk($mariam, 'Transport, taxi, VTC', 2, 2500, 'Taxi retour inventaire tardif', null, false);
        $as($mariamU, $m1, ['status' => 'submitted']);
        $mk($mariam, 'Téléphone et internet', 1, 5000, 'Recharge téléphone (commandes clients WhatsApp)', 'Orange', false);
        auth()->logout();
    }

    /** Recrutement : trois offres (dont une pourvue), page Emplois, candidatures avec CV, entretiens et avis, une embauche. */
    protected function recrutement(Company $company): void
    {
        app()->instance('tenant', $company);
        app(\App\Modules\Recrutement\RecrutementSetup::class)($company);
        $settings = $company->fresh()->settings ?? [];
        $settings['_setup']['recrutement'] = 1;
        $settings['recrutement'] = ['careers_enabled' => true, 'retention_months' => 24,
            'careers_intro' => "Démo SARL exploite une supérette et un restaurant à Abidjan. Nous recrutons des personnes motivées, ponctuelles et souriantes.\nFormation assurée à l'arrivée."];
        $company->forceFill(['settings' => $settings])->saveQuietly();
        $company = $company->fresh();
        app()->instance('tenant', $company);

        $w = app(\App\Services\EntityWriter::class);
        $admin = User::where('email', 'admin@demo.ci')->first();
        $fatou = User::where('email', 'fatou@demo.ci')->first() ?? $admin;
        auth()->setUser($admin);
        $J = \App\Modules\Recrutement\Models\Job::class;
        $A = \App\Modules\Recrutement\Models\Application::class;
        $I = \App\Modules\Recrutement\Models\Interview::class;
        $put = function (string $class, array $data, ?string $id = null, ?User $as = null) use ($w, $admin) {
            auth()->setUser($as ?? $admin);
            $id ??= (string) \Illuminate\Support\Str::uuid();
            $r = $w->upsert($class, $id, $data);
            if ($r['status'] !== 'applied') {
                throw new \RuntimeException('Recrutement : '.$r['message'].' '.json_encode($r['errors'] ?? []));
            }

            return $id;
        };
        $cv = function (string $id, string $name, array $lines) use ($company, $A) {
            $esc = fn ($t) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], iconv('UTF-8', 'Windows-1252//TRANSLIT', $t));
            $ops = "BT /F1 20 Tf 60 780 Td (".$esc($name).") Tj ET\n";
            $y = 740;
            foreach ($lines as $l) {
                $bold = str_starts_with($l, '#');
                $ops .= 'BT /F1 '.($bold ? 13 : 11)." Tf 60 $y Td (".$esc(ltrim($l, '# ')).") Tj ET\n";
                $y -= $bold ? 26 : 18;
            }
            $objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
                '<< /Length '.strlen($ops)." >>\nstream\n".$ops."endstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'];
            $pdf = "%PDF-1.4\n";
            $off = [];
            foreach ($objs as $i => $o) {
                $off[] = strlen($pdf);
                $pdf .= ($i + 1)." 0 obj\n$o\nendobj\n";
            }
            $x = strlen($pdf);
            $pdf .= "xref\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
            foreach ($off as $o) {
                $pdf .= sprintf("%010d 00000 n \n", $o);
            }
            $pdf .= "trailer\n<< /Size ".(count($objs) + 1)." /Root 1 0 R >>\nstartxref\n$x\n%%EOF";
            $path = 'cvs/'.$company->id.'/'.$id.'-demo.pdf';
            \Illuminate\Support\Facades\Storage::disk('local')->put($path, $pdf);
            $A::find($id)->forceFill(['cv_path' => $path, 'cv_name' => 'CV '.$name.'.pdf'])->saveQuietly();
        };
        $ago = function (string $id, int $days, string $class) {
            $class::withoutGlobalScopes()->where('id', $id)->update(['created_at' => now()->subDays($days), 'stage_at' => now()->subDays(max(0, $days - 3))]);
        };

        $caissier = $put($J, ['title' => 'Caissier(ère) supérette', 'department' => 'Boutique', 'location' => 'Abidjan, Marcory', 'contract_type' => 'CDI', 'positions' => 2, 'status' => 'open', 'salary' => '90 000 – 110 000 F CFA',
            'description' => "Encaissement des clients (espèces, Mobile Money, carte), tenue de la caisse et clôture journalière.\nMise en rayon et accueil.\nHoraires en rotation 7 h – 15 h / 14 h – 22 h, un dimanche sur deux.",
            'requirements' => "BEPC minimum, BAC apprécié.\nUne première expérience en caisse est un plus.\nRigueur, honnêteté, bon contact.", 'closes_at' => now()->addDays(21)->toDateString()]);
        $cuisinier = $put($J, ['title' => 'Cuisinier(ère) — restaurant', 'department' => 'Restaurant', 'location' => 'Abidjan, Marcory', 'contract_type' => 'CDD', 'positions' => 1, 'status' => 'open',
            'description' => "Préparation des plats ivoiriens et grillades, mise en place, respect des règles d'hygiène.\nService du midi et du soir, 6 jours sur 7.",
            'requirements' => "2 ans d'expérience en restauration.\nMaîtrise des plats locaux (garba, alloco, kedjenou, poisson braisé)."]);
        $livreur = $put($J, ['title' => 'Livreur à moto', 'department' => 'Logistique', 'location' => 'Abidjan', 'contract_type' => 'CDI', 'positions' => 1, 'status' => 'open',
            'description' => "Livraison des commandes clients dans le grand Abidjan, encaissement à la livraison.\nMoto fournie.", 'requirements' => 'Permis A, bonne connaissance d\'Abidjan.']);
        $put($J, ['title' => 'Comptable confirmé(e)', 'department' => 'Finance', 'location' => 'Abidjan, Plateau', 'contract_type' => 'CDI', 'positions' => 1, 'status' => 'draft', 'published' => false,
            'description' => "Tenue de la comptabilité SYSCOHADA, déclarations fiscales et sociales, rapprochements bancaires.", 'requirements' => 'BTS / Licence en comptabilité, 5 ans d\'expérience.']);

        $people = [
            // [job, prénom, nom, téléphone, e-mail, ville, source, étape, note, jours, message, prétentions, cv lines]
            [$caissier, 'Aïcha', 'Konaté', '+225 07 11 22 33 44', 'aicha.konate@exemple.ci', 'Koumassi', 'website', 'new', null, 1, 'Je suis disponible immédiatement et j\'ai travaillé 2 ans dans une pharmacie à la caisse.', '100 000', ['# Expérience', 'Caissière, Pharmacie du Port, Koumassi (2023 - 2025)', 'Vendeuse, boutique de téléphonie, Treichville (2021 - 2023)', '# Formation', 'BAC série G2 (2021)', '# Compétences', 'Caisse, Mobile Money, accueil clientèle']],
            [$caissier, 'Brice', 'N\'Guessan', '+225 05 22 33 44 55', null, 'Yopougon', 'whatsapp', 'new', null, 2, null, null, null],
            [$caissier, 'Mariette', 'Gbagbo', '+225 01 33 44 55 66', 'mariette.g@exemple.ci', 'Marcory', 'website', 'screening', 4, 6, 'Habitant Marcory, je peux venir à pied. 3 ans de caisse en supermarché.', '110 000', ['# Expérience', 'Hôtesse de caisse, Supermarché Cap Sud (2022 - 2025)', '# Formation', 'BEPC, formation caisse et accueil', '# Langues', 'Français, dioula']],
            [$caissier, 'Ismaël', 'Ouattara', '+225 07 44 55 66 77', 'ismael.ouattara@exemple.ci', 'Port-Bouët', 'facebook', 'interview', 4, 9, null, '95 000', ['# Expérience', 'Caissier, station-service Total Port-Bouët (2024 - 2025)', '# Formation', 'BAC série D']],
            [$caissier, 'Grâce', 'Yapi', '+225 05 55 66 77 88', 'grace.yapi@exemple.ci', 'Treichville', 'referral', 'offer', 5, 14, 'Recommandée par Yao Kouassi.', '100 000', ['# Expérience', 'Caissière principale, mini-market (2020 - 2025)', 'Responsable de clôture de caisse', '# Formation', 'BAC G2, BTS gestion commerciale (en cours)']],
            [$caissier, 'Didier', 'Kouamé', '+225 01 66 77 88 99', null, 'Abobo', 'website', 'rejected', 2, 18, null, '150 000', null, 'Prétentions trop élevées'],
            [$caissier, 'Sandrine', 'Bédié', '+225 07 77 88 99 00', 'sandrine.b@exemple.ci', 'Adjamé', 'website', 'rejected', 1, 20, null, null, null, 'Profil ne correspondant pas'],
            [$cuisinier, 'Moussa', 'Sanogo', '+225 05 88 99 00 11', 'moussa.sanogo@exemple.ci', 'Marcory', 'website', 'interview', 5, 8, 'Chef de partie pendant 4 ans dans un maquis réputé de Zone 4.', '150 000', ['# Expérience', 'Chef de partie, Maquis Le Bélier, Zone 4 (2021 - 2025)', 'Commis de cuisine, hôtel Ivoire (2018 - 2021)', '# Formation', 'CAP cuisine, INFS Abidjan', '# Spécialités', 'Kedjenou, poisson braisé, sauce graine']],
            [$cuisinier, 'Adjoa', 'Brou', '+225 01 99 00 11 22', null, 'Bingerville', 'manual', 'screening', 3, 5, 'Déposé son CV à l\'accueil du restaurant.', null, null],
            [$livreur, 'Seydou', 'Coulibaly', '+225 07 00 11 22 33', null, 'Abobo', 'whatsapp', 'new', null, 0, null, null, null],
            [null, 'Estelle', 'Aka', '+225 05 10 20 30 40', 'estelle.aka@exemple.ci', 'Cocody', 'website', 'new', null, 3, 'Diplômée en marketing, je souhaite rejoindre votre équipe (communication, réseaux sociaux).', null, ['# Formation', 'Licence marketing digital, Université FHB (2025)', '# Stage', 'Community manager, agence Pixel (6 mois)', '# Outils', 'Canva, Meta Business Suite']],
        ];
        $ids = [];
        foreach ($people as $p) {
            [$job, $fn, $ln, $tel, $mail, $city, $src, $stage, $rating, $days, $msg, $sal, $lines] = $p;
            $id = $put($A, array_filter(['job_id' => $job, 'first_name' => $fn, 'last_name' => $ln, 'phone' => $tel, 'email' => $mail, 'city' => $city, 'source' => $src, 'stage' => $stage === 'interview' || $stage === 'offer' ? 'screening' : $stage,
                'rating' => $rating, 'cover_letter' => $msg, 'expected_salary' => $sal, 'reject_reason' => $p[13] ?? null], fn ($v) => $v !== null));
            if ($src === 'website') {
                $A::find($id)->forceFill(['consent_at' => now()->subDays($days)])->saveQuietly();
            }
            if ($lines) {
                $cv($id, "$fn $ln", $lines);
            }
            $ids["$fn"] = [$id, $days, $stage];
        }
        $slot = fn (int $d, int $h, int $m = 0) => now()->addDays($d)->setTime($h, $m)->format('Y-m-d H:i:s');
        // Ismaël : entretien demain avec la direction
        $put($I, ['application_id' => $ids['Ismaël'][0], 'starts_at' => $slot(1, 10), 'duration' => 30, 'location' => 'Bureau Marcory', 'interviewer_ids' => [$admin->id, $fatou->id]]);
        // Moussa : premier entretien fait (avis de la direction), essai en cuisine à venir
        $m1 = $put($I, ['application_id' => $ids['Moussa'][0], 'starts_at' => $slot(-3, 15), 'duration' => 45, 'location' => 'Restaurant', 'interviewer_ids' => [$fatou->id]]);
        $put($I, ['status' => 'done', 'score' => 5, 'recommendation' => 'yes', 'feedback' => 'Très bonne maîtrise des plats locaux, calme, disponible tout de suite. Prévoir un essai en cuisine.'], $m1, $fatou);
        $put($I, ['application_id' => $ids['Moussa'][0], 'starts_at' => $slot(2, 11), 'duration' => 120, 'location' => 'Cuisine du restaurant (essai)', 'interviewer_ids' => [$admin->id]]);
        // Grâce : entretien fait, proposition en cours
        $g = $put($I, ['application_id' => $ids['Grâce'][0], 'starts_at' => $slot(-6, 9, 30), 'duration' => 30, 'location' => 'Bureau Marcory', 'interviewer_ids' => [$admin->id]]);
        $put($I, ['status' => 'done', 'score' => 5, 'recommendation' => 'yes', 'feedback' => 'Expérience solide de clôture de caisse, très bonne présentation.'], $g, $admin);
        $put($A, ['stage' => 'offer'], $ids['Grâce'][0]);
        // Avis attendu : entretien d'hier non renseigné
        $put($I, ['application_id' => $ids['Mariette'][0], 'starts_at' => $slot(-1, 14), 'duration' => 30, 'location' => 'Par téléphone', 'interviewer_ids' => [$admin->id]]);

        // Une embauche déjà réalisée : poste de caissier (1 sur 2 pourvu)
        $h = $put($A, ['job_id' => $caissier, 'first_name' => 'Rokia', 'last_name' => 'Diabaté', 'phone' => '+225 07 12 34 56 78', 'email' => 'rokia.diabate@exemple.ci', 'city' => 'Marcory', 'source' => 'website', 'stage' => 'offer', 'rating' => 5, 'expected_salary' => '100 000']);
        $cv($h, 'Rokia Diabaté', ['# Expérience', 'Caissière, supérette Prosuma (2022 - 2025)', '# Formation', 'BAC G2']);
        $hi = $put($I, ['application_id' => $h, 'starts_at' => $slot(-12, 10), 'duration' => 30, 'location' => 'Bureau Marcory', 'interviewer_ids' => [$admin->id, $fatou->id]]);
        $put($I, ['status' => 'done', 'score' => 4, 'recommendation' => 'yes', 'feedback' => 'Rigoureuse, connaît déjà notre logiciel de caisse.'], $hi, $fatou);
        app(\App\Modules\Recrutement\RecrutementService::class)->hire($company, $A::find($h), ['hire_date' => now()->subDays(2)->toDateString(), 'base_salary' => 100000]);
        $ids['Rokia'] = [$h, 25, 'hired'];

        foreach ($ids as [$id, $days]) {
            $ago($id, $days, $A);
        }
        $A::withoutGlobalScopes()->where('id', $h)->update(['stage_at' => now()->subDays(4)]);
        auth()->logout();
    }

    /** Agenda : salles et véhicule, point d'équipe hebdomadaire, rendez-vous avec invités, formation, livraison, événement privé. */
    protected function agenda(Company $company): void
    {
        app()->instance('tenant', $company);
        app(\App\Modules\Agenda\AgendaSetup::class)($company);
        $settings = $company->fresh()->settings ?? [];
        $settings['_setup']['agenda'] = 1;
        $company->forceFill(['settings' => $settings])->saveQuietly();
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $w = app(\App\Services\EntityWriter::class);
        $admin = User::where('email', 'admin@demo.ci')->first();
        $u = User::where('company_id', $company->id)->pluck('id', 'email');
        $id = fn (string $email) => $u[$email] ?? null;
        $put = function (string $class, array $data, ?User $as = null) use ($w, $admin) {
            auth()->setUser($as ?? $admin);
            $rid = (string) \Illuminate\Support\Str::uuid();
            $r = $w->upsert($class, $rid, $data);
            if ($r['status'] !== 'applied') {
                throw new \RuntimeException('Agenda : '.$r['message'].' '.json_encode($r['errors'] ?? []));
            }

            return $rid;
        };
        $R = \App\Modules\Agenda\Models\Resource::class;
        $E = \App\Modules\Agenda\Models\Event::class;
        $salle = $put($R, ['name' => 'Salle de réunion', 'kind' => 'room', 'capacity' => 8, 'color' => '#1F6FEB', 'notes' => '1er étage, écran et climatisation']);
        $hilux = $put($R, ['name' => 'Toyota Hilux', 'kind' => 'vehicle', 'color' => '#E67E22', 'notes' => '2154 HJ 01']);
        $put($R, ['name' => 'Vidéoprojecteur', 'kind' => 'equipment', 'color' => '#8E44AD']);
        $mon = today()->dayOfWeekIso >= 5 ? today()->next(\Carbon\Carbon::MONDAY) : today()->startOfWeek();
        $at = fn (int $day, string $time) => $mon->copy()->addDays($day)->format('Y-m-d').' '.$time.':00';
        $team = array_values(array_filter([$id('paul@demo.ci'), $id('fatou@demo.ci'), $id('jm@demo.ci'), $id('mariam@demo.ci')]));
        $point = $put($E, ['title' => 'Point d\'équipe hebdomadaire', 'starts_at' => $mon->copy()->subWeeks(3)->format('Y-m-d').' 08:30:00', 'ends_at' => $mon->copy()->subWeeks(3)->format('Y-m-d').' 09:15:00',
            'category' => 'meeting', 'resource_id' => $salle, 'attendee_ids' => $team, 'recurrence' => ['freq' => 'weekly', 'byday' => [1]], 'reminder_minutes' => 15,
            'description' => "Chiffre de la semaine, ruptures de stock, livraisons, points RH."]);
        $put($E, ['title' => 'Réunion Solibra — négociation remise 2027', 'starts_at' => $at(2, '10:00'), 'ends_at' => $at(2, '11:30'), 'category' => 'appointment', 'resource_id' => $salle,
            'attendee_ids' => array_values(array_filter([$id('fatou@demo.ci'), $id('paul@demo.ci')])), 'guests' => [['name' => 'Ibrahim Touré (Solibra)', 'email' => 'i.toure@solibra.ci']], 'reminder_minutes' => 30,
            'description' => "Objectif : remise de 4 % au-delà de 2 000 casiers.\nApporter les volumes 2026."]);
        $put($E, ['title' => 'Livraison Hôtel Lagune', 'starts_at' => $at(3, '07:30'), 'ends_at' => $at(3, '10:00'), 'category' => 'travel', 'resource_id' => $hilux, 'location' => 'Biétry, Boulevard de Marseille', 'attendee_ids' => array_values(array_filter([$id('yao@demo.ci')]))]);
        $put($E, ['title' => 'Formation nouvelle caisse', 'starts_at' => $at(4, '14:00'), 'ends_at' => $at(4, '16:00'), 'category' => 'training', 'resource_id' => $salle,
            'attendee_ids' => array_values(array_filter([$id('caisse@demo.ci'), $id('mariam.traore@demo.ci')])), 'online_link' => 'https://meet.google.com/abc-defg-hij']);
        $put($E, ['title' => 'Inventaire de fin de mois', 'starts_at' => today()->endOfMonth()->format('Y-m-d').' 00:00:00', 'ends_at' => today()->endOfMonth()->format('Y-m-d').' 23:59:59', 'all_day' => true, 'category' => 'other',
            'attendee_ids' => array_values(array_filter([$id('ousmane@demo.ci'), $id('paul@demo.ci')]))]);
        $put($E, ['title' => 'Rendez-vous banque SGBCI', 'starts_at' => $at(1, '15:00'), 'ends_at' => $at(1, '16:00'), 'category' => 'appointment', 'location' => 'SGBCI Plateau', 'visibility' => 'private']);
        $put($E, ['title' => 'Déjeuner avec le comptable', 'starts_at' => $at(4, '12:30'), 'ends_at' => $at(4, '14:00'), 'category' => 'appointment', 'location' => 'Restaurant Le Baobab', 'attendee_ids' => array_values(array_filter([$id('paul@demo.ci')]))]);
        // Réponses aux invitations
        foreach (['paul@demo.ci' => 'accepted', 'fatou@demo.ci' => 'accepted', 'jm@demo.ci' => 'tentative', 'mariam@demo.ci' => 'declined'] as $email => $st) {
            if ($uid = $id($email)) {
                auth()->setUser(User::find($uid));
                $w->upsert($E, $point, ['responses' => [(string) $uid => $st]]);
            }
        }
        auth()->logout();
    }

    /** Documents (GED) : dossiers avec droits, assurances et agréments à échéance, factures fournisseurs liées, versions, lien de partage. */
    protected function documents(Company $company): void
    {
        app()->instance('tenant', $company);
        app(\App\Modules\Documents\DocumentsSetup::class)($company);
        $settings = $company->fresh()->settings ?? [];
        $settings['_setup']['documents'] = 1;
        $company->forceFill(['settings' => $settings])->saveQuietly();
        $company = $company->fresh();
        app()->instance('tenant', $company);
        \App\Modules\Documents\DocumentAccess::flush();
        $svc = app(\App\Modules\Documents\DocumentService::class);
        $admin = User::where('email', 'admin@demo.ci')->first();
        $paul = User::where('email', 'paul@demo.ci')->first() ?? $admin;
        auth()->setUser($admin);
        $roles = \App\Models\Role::where('company_id', $company->id)->pluck('id', 'name');
        $F = \App\Modules\Documents\Models\Folder::class;
        $folder = function (string $name, ?string $parent = null, string $color = '#FFC531', string $access = 'inherit', array $roleNames = []) use ($F, $roles, $admin) {
            $f = new $F(['name' => $name, 'parent_id' => $parent, 'color' => $color, 'access' => $access, 'role_ids' => $roleNames ? array_values(array_filter(array_map(fn ($n) => $roles[$n] ?? null, $roleNames))) : null]);
            $f->id = (string) \Illuminate\Support\Str::uuid();
            $f->owner_id = $admin->id;
            $f->save();

            return $f->id;
        };
        $pdf = function (string $title, array $lines) use ($company) {
            $p = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
            $p->setPrintHeader(false);
            $p->setPrintFooter(false);
            $p->SetMargins(20, 20, 20);
            $p->AddPage();
            $p->SetFont('dejavusans', '', 10.5);
            $html = '<p style="font-size:15pt;font-weight:bold;color:#0B1B3A">'.e($title).'</p><br>';
            foreach ($lines as $l) {
                $html .= '<p>'.e($l).'</p>';
            }
            $p->writeHTML($html);

            return $p->Output('', 'S');
        };
        $docx = function (array $paras) {
            $path = tempnam(sys_get_temp_dir(), 'dx');
            $z = new \ZipArchive;
            $z->open($path, \ZipArchive::OVERWRITE);
            $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
            $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
            $body = '';
            foreach ($paras as $p) {
                $body .= '<w:p><w:r>'.(str_starts_with($p, '#') ? '<w:rPr><w:b/></w:rPr>' : '').'<w:t xml:space="preserve">'.htmlspecialchars(ltrim($p, '# ')).'</w:t></w:r></w:p>';
            }
            $z->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>');
            $z->close();
            $b = file_get_contents($path);
            @unlink($path);

            return $b;
        };
        $put = function (string $name, string $bytes, ?string $folder, array $meta = [], ?User $as = null) use ($svc, $company, $admin) {
            return $svc->store($company, $as ?? $admin, $bytes, $name, $folder, $meta, null, null, false);
        };

        $adm = $folder('Administratif', null, '#1F6FEB');
        $assur = $folder('Assurances', $adm, '#1F6FEB');
        $jur = $folder('Juridique — statuts, RCCM', $adm, '#1F6FEB');
        $cf = $folder('Contrats fournisseurs', $adm, '#0E9AA7');
        $cpt = $folder('Comptabilité', null, '#12A150', 'roles', ['Comptable', 'Direction']);
        $ff = $folder('Factures fournisseurs 2026', $cpt, '#12A150');
        $rb = $folder('Relevés bancaires', $cpt, '#12A150');
        $rh = $folder('Ressources humaines', null, '#8E44AD', 'roles', ['RH / Paie', 'Direction']);
        $com = $folder('Commercial', null, '#E67E22');
        $priv = $folder('Notes de la gérance', null, '#5B6B85', 'private');

        $put('Attestation assurance multirisque boutique.pdf', $pdf('Attestation d\'assurance multirisque professionnelle', ['Assureur : NSIA Assurances — police n° MRP-2025-00871.', 'Assuré : '.$company->name.', boutique de Marcory.', 'Garanties : incendie, dégâts des eaux, vol, responsabilité civile.', 'Période de garantie : du 15/11/2025 au '.today()->addDays(18)->format('d/m/Y').'.']),
            $assur, ['tags' => ['assurance', 'boutique'], 'expires_at' => today()->addDays(18)->toDateString(), 'description' => 'À renouveler avant l\'échéance (franchise vol 50 000 F).']);
        $put('Carte grise et assurance moto livraison.pdf', $pdf('Assurance véhicule — moto de livraison', ['Yamaha Crypton 115 — immatriculation 8812 GH 01.', 'Assurance tous risques, échéance '.today()->addMonths(7)->format('d/m/Y').'.']),
            $assur, ['tags' => ['assurance', 'véhicule'], 'expires_at' => today()->addMonths(7)->toDateString()]);
        $put('Statuts Démo SARL.pdf', $pdf('Statuts de la société '.$company->name, ['Société à responsabilité limitée au capital de 6 920 000 F CFA.', 'Siège social : Boulevard VGE, Marcory, Abidjan.', 'Objet : commerce de détail, restauration, services.', 'Gérance : Madame Awa Koné.']), $jur, ['tags' => ['statuts', 'juridique']]);
        $put('Extrait RCCM.pdf', $pdf('Registre du Commerce et du Crédit Mobilier', ['Numéro : CI-ABJ-2019-B-04512.', 'Dénomination : '.$company->name.'.', 'Date d\'immatriculation : 14/03/2019.']), $jur, ['tags' => ['RCCM', 'juridique']]);
        $put('Agrément sanitaire restaurant.pdf', $pdf('Certificat de conformité sanitaire', ['Établissement : restaurant '.$company->name.'.', 'Délivré par la Direction de l\'Hygiène Publique.', 'Valable jusqu\'au '.today()->subDays(3)->format('d/m/Y').'.']), $adm, ['tags' => ['agrément', 'restaurant'], 'expires_at' => today()->subDays(3)->toDateString(), 'description' => 'Renouvellement demandé, visite d\'inspection à programmer.']);

        $solibra = Partner::where('name', 'Solibra Distribution')->first();
        $bail = $put('Bail commercial local Marcory.pdf', $pdf('Bail commercial', ['Bailleur : SCI Les Cocotiers.', 'Locataire : '.$company->name.'.', 'Loyer mensuel : 420 000 F CFA.', 'Durée : 3 ans, du 01/01/2025 au 31/12/2027.']), $cf, ['tags' => ['bail', 'contrat'], 'expires_at' => '2027-12-31']);
        if ($solibra) {
            $put('Contrat de distribution Solibra.pdf', $pdf('Contrat de distribution', ['Fournisseur : Solibra Distribution, Treichville.', 'Remise annuelle : 3 % au-delà de 2 000 casiers.', 'Paiement à 30 jours fin de mois.']), $cf, ['tags' => ['contrat', 'boissons'], 'partner_id' => $solibra->id, 'expires_at' => today()->addMonths(5)->toDateString()]);
            foreach ([['FS-2026-1182', 1250000, 40], ['FS-2026-1307', 980000, 12]] as [$num, $amt, $ago]) {
                $put('Facture Solibra '.$num.'.pdf', $pdf('Facture '.$num, ['Solibra Distribution — Treichville, Abidjan.', 'Client : '.$company->name.'.', 'Bière locale 65cl, Coca-Cola 33cl, eau minérale.', 'Montant TTC : '.number_format($amt, 0, ',', ' ').' F CFA.', 'Date : '.today()->subDays($ago)->format('d/m/Y').'.']),
                    $ff, ['tags' => ['facture', 'boissons'], 'partner_id' => $solibra->id], $paul);
            }
        }
        $put('Relevé SGBCI septembre 2026.pdf', $pdf('Relevé de compte — SGBCI', ['Compte courant n° CI008 01111 000123456789 45.', 'Période : septembre 2026.', 'Solde au 30/09 : 5 812 400 F CFA.']), $rb, ['tags' => ['banque']], $paul);
        $rib = $put('Règlement intérieur.docx', $docx(['# Règlement intérieur de '.$company->name, 'Horaires : la boutique ouvre de 7 h à 22 h ; chaque salarié respecte l\'horaire de son équipe.', 'Pointage : arrivée et départ sur la borne, avec votre code personnel.', 'Tenue : tenue de travail fournie, propre et complète.', 'Caisse : tout écart de caisse est signalé au responsable le jour même.']), $rh, ['tags' => ['RH', 'règlement']]);
        $yao = \App\Modules\Rh\Models\Employee::where('matricule', 'EMP00002')->first();
        if ($yao) {
            $put('Pièce d\'identité Yao Kouassi.pdf', $pdf('Copie de pièce d\'identité', ['Carte nationale d\'identité — Yao Kouassi.', 'Valable jusqu\'au '.today()->addYears(3)->format('d/m/Y').'.']), $rh, ['tags' => ['identité'], 'employee_id' => $yao->id, 'expires_at' => today()->addYears(3)->toDateString()]);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'xl').'.xlsx';
        \App\Modules\ImportExport\Sheet::write($tmp, ['Produit', 'Prix de vente', 'Prix grossiste'], Product::orderBy('name')->take(25)->get()->map(fn ($p) => [$p->name, (float) $p->sale_price, round((float) $p->sale_price * 0.9)])->all());
        $tarifs = $put('Grille tarifaire 2026.xlsx', file_get_contents($tmp), $com, ['tags' => ['tarifs', 'grossistes'], 'description' => 'Prix grossistes : -10 % à partir de 10 cartons.']);
        @unlink($tmp);
        // Deuxième version de la grille (nouveaux prix)
        $tmp = tempnam(sys_get_temp_dir(), 'xl').'.xlsx';
        \App\Modules\ImportExport\Sheet::write($tmp, ['Produit', 'Prix de vente', 'Prix grossiste'], Product::orderBy('name')->take(25)->get()->map(fn ($p) => [$p->name, (float) $p->sale_price, round((float) $p->sale_price * 0.88)])->all());
        $svc->store($company, $admin, file_get_contents($tmp), 'Grille tarifaire 2026.xlsx', $com, [], $tarifs, 'Remise grossistes portée à 12 %', false);
        @unlink($tmp);
        $put('Présentation Démo SARL.pdf', $pdf('Présentation de '.$company->name, ['Supérette de proximité et restaurant à Marcory.', 'Livraison dans le grand Abidjan, commandes par WhatsApp.', 'Clients professionnels : hôtels, maquis, entreprises.']), $com, ['tags' => ['plaquette']]);
        $put('Idées pour 2027.txt', "Ouvrir une deuxième boutique à Cocody (Angré).\nNégocier la remise Solibra à 4 %.\nLancer la carte de fidélité restaurant.", $priv, ['tags' => ['stratégie']]);
        $svc->share($admin, \App\Modules\Documents\Models\DocFile::find($tarifs->id), null, ['expires_days' => 30]);
        auth()->logout();
    }

    /** Signature électronique : contrat signé et scellé, devis en attente du client, contrat de prestation à signer par la direction, refus, brouillon. */
    protected function signature(Company $company): void
    {
        app()->instance('tenant', $company);
        app(\App\Modules\Signature\SignatureSetup::class)($company);
        $settings = $company->fresh()->settings ?? [];
        $settings['_setup']['signature'] = 1;
        $company->forceFill(['settings' => $settings])->saveQuietly();
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $svc = app(\App\Modules\Signature\SignatureService::class);
        $admin = User::where('email', 'admin@demo.ci')->first();
        auth()->setUser($admin);
        $S = \App\Modules\Signature\Models\Signer::class;
        // Le serveur e-mail de démonstration est fictif : pas d'envoi pendant la préparation des données
        $mail = \App\Models\MessagingChannel::where('channel', 'email')->where('enabled', true)->first();
        $mail?->forceFill(['enabled' => false])->save();

        $pdf = function (string $title, array $paras) use ($company) {
            $p = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
            $p->setPrintHeader(false);
            $p->setPrintFooter(false);
            $p->SetMargins(20, 20, 20);
            $p->AddPage();
            $p->SetFont('dejavusans', '', 10.5);
            $html = '<table><tr><td width="2%" style="background-color:#FFC531"></td><td width="3%"></td><td width="95%"><b style="font-size:14pt;color:#0B1B3A">'.e($company->name).'</b><br><span style="font-size:8.5pt">'.e(trim($company->address.' — '.$company->city, ' —')).'</span></td></tr></table><br><br>'.
                '<p style="text-align:center;font-size:14pt;font-weight:bold;color:#0B1B3A">'.e(mb_strtoupper($title)).'</p><br>';
            foreach ($paras as $para) {
                $html .= str_starts_with($para, '#') ? '<p><b>'.e(ltrim($para, '# ')).'</b></p>' : '<p style="text-align:justify">'.e($para).'</p>';
            }
            $p->writeHTML($html);

            return $p->Output('', 'S');
        };
        $sig = function (int $seed) {
            $w = 480;
            $h = 180;
            $im = imagecreatetruecolor($w, $h);
            imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
            $ink = imagecolorallocate($im, 11, 27, 58);
            imagesetthickness($im, 3);
            mt_srand($seed);
            $x0 = 40;
            $py = 100;
            $px = $x0;
            for ($t = 0; $t <= 380; $t += 2) {
                $x = $x0 + $t;
                $y = 100 + (int) (34 * sin($t / (14 + $seed % 5)) * cos($t / (47 + $seed % 7))) - (int) ($t / 9);
                imageline($im, $px, $py, $x, $y, $ink);
                [$px, $py] = [$x, $y];
            }
            imageline($im, 70, 140, 380, 128, $ink);
            ob_start();
            imagepng($im);

            return 'data:image/png;base64,'.base64_encode(ob_get_clean());
        };
        $ip = fn () => '196.47.'.mt_rand(10, 250).'.'.mt_rand(2, 250);
        $ua = 'Mozilla/5.0 (Linux; Android 14; SM-A155F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36';
        $signer = fn ($r, int $i) => $S::where('request_id', $r->id)->orderBy('position')->orderBy('id')->skip($i)->first();

        // 1. Contrat de Rokia : signé par la salariée (lien) puis par la gérante (application), document scellé
        $r1 = $svc->create($company, $admin, $pdf('Contrat de travail à durée indéterminée', [
            'Entre la société '.$company->name.', représentée par sa Gérante, ci-après « l\'Employeur », et Madame Rokia Diabaté, demeurant à Marcory, ci-après « la Salariée », il a été convenu ce qui suit.',
            '# Article 1 — Engagement', 'La Salariée est engagée en qualité de Caissière, catégorie employé, à compter du '.now()->subDays(2)->format('d/m/Y').', pour une durée indéterminée.',
            '# Article 2 — Période d\'essai', 'Le présent contrat ne deviendra définitif qu\'à l\'issue d\'une période d\'essai de deux mois.',
            '# Article 3 — Rémunération', 'La Salariée percevra un salaire de base mensuel brut de 100 000 F CFA, auquel s\'ajoutent la prime de transport et les accessoires prévus par la convention collective.',
            '# Article 4 — Durée du travail', 'La durée hebdomadaire du travail est fixée à 40 heures, selon les horaires de la boutique.',
            'Fait à Abidjan, en deux exemplaires.',
        ]), 'Contrat CDI - Rokia Diabate.pdf', ['title' => 'Contrat de travail CDI — Rokia Diabaté', 'message' => 'Bienvenue dans l\'équipe ! Merci de signer votre contrat.', 'sequential' => true],
            [['name' => 'Rokia Diabaté', 'label' => 'La Salariée', 'phone' => '+225 07 12 34 56 78', 'email' => 'rokia.diabate@exemple.ci'], ['user_id' => $admin->id, 'name' => 'Awa Koné', 'label' => 'L\'Employeur — la Gérante']]);
        $svc->send($company, $r1);
        $a = $signer($r1, 0);
        $svc->viewed($r1, $a, $ip1 = $ip(), $ua);
        $svc->sign($company, $r1->fresh(), $a->fresh(), $sig(3), $ip1, $ua);
        $svc->sign($company, $r1->fresh(), $signer($r1, 1), $sig(8), '196.47.128.14', 'Yomara ERP (Windows)', true);

        // 2. Devis envoyé au client : ouvert, pas encore signé
        $client = Partner::where('type', 'customer')->whereNotIn('name', ['Hôtel Lagune', 'Maquis Chez Tantie'])->orderBy('created_at')->first();
        if ($client && class_exists(\App\Modules\Ventes\Models\SalesDocument::class)) {
            $products = Product::orderBy('name')->take(3)->get();
            $q = new \App\Modules\Ventes\Models\SalesDocument;
            $q->fill(['type' => 'quote', 'partner_id' => $client->id, 'partner_name' => $client->name, 'date' => now()->subDay()->toDateString(), 'due_date' => now()->addDays(29)->toDateString()]);
            $q->save();
            $q->replaceLines($products->map(fn ($p) => ['product_id' => $p->id, 'description' => $p->name, 'quantity' => 24, 'unit_price' => $p->sale_price ?: 5000, 'tax_rate' => 18])->all());
            $q->validateDocument();
            $q = $q->fresh();
            $paras = ['Devis n° '.$q->number.' du '.$q->date->format('d/m/Y').' — client : '.$client->name.'.'];
            foreach ($q->lines as $l) {
                $paras[] = '• '.$l->description.' — '.rtrim(rtrim(number_format($l->quantity, 2, ',', ' '), '0'), ',').' × '.number_format($l->unit_price, 0, ',', ' ').' F CFA';
            }
            $paras[] = '# Total TTC : '.number_format($q->total, 0, ',', ' ').' F CFA';
            $paras[] = 'Validité : 30 jours. Livraison sous 48 heures après accord. Bon pour accord du client ci-après.';
            $r2 = $svc->create($company, $admin, $pdf('Devis '.$q->number, $paras), 'Devis '.$q->number.'.pdf',
                ['title' => 'Devis '.$q->number.' — '.$client->name, 'message' => 'Votre devis est prêt : signez-le en ligne pour lancer la livraison.', 'source_type' => 'sales_document', 'source_id' => $q->id],
                [['name' => $client->name, 'label' => 'Bon pour accord — le client', 'phone' => $client->phone ?: '+225 05 06 07 08 09', 'email' => $client->email]]);
            $svc->send($company, $r2);
            $svc->viewed($r2, $signer($r2, 0), $ip(), $ua);
        }

        // 3. Contrat de prestation signé par le prestataire, en attente de la direction (onglet « À signer »)
        $r3 = $svc->create($company, $admin, $pdf('Contrat de maintenance informatique', [
            'Entre '.$company->name.' (le Client) et Ivoire Tech Services SARL (le Prestataire).',
            '# Objet', 'Maintenance préventive trimestrielle des caisses et ordinateurs, dépannage sous 24 heures ouvrées, assistance téléphonique.',
            '# Prix', 'Forfait annuel de 600 000 F CFA HT, payable par trimestre.',
            '# Durée', 'Un an à compter de la signature, renouvelable par tacite reconduction.',
        ]), 'Contrat maintenance Ivoire Tech.pdf', ['title' => 'Contrat de maintenance — Ivoire Tech Services', 'sequential' => true],
            [['name' => 'Ibrahim Koné', 'label' => 'Pour Ivoire Tech Services', 'email' => 'i.kone@ivoiretech.ci', 'phone' => '+225 07 77 66 55 44'], ['user_id' => $admin->id, 'label' => 'Pour '.$company->name]]);
        $svc->send($company, $r3);
        $b = $signer($r3, 0);
        $svc->viewed($r3, $b, $ip3 = $ip(), 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/129.0');
        $svc->sign($company, $r3->fresh(), $b->fresh(), $sig(5), $ip3, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/129.0');

        // 4. Refusé par le signataire
        $r4 = $svc->create($company, $admin, $pdf('Avenant n° 2 au bail commercial', ['Révision du loyer mensuel du local de Marcory à 450 000 F CFA à compter du 1er janvier.']),
            'Avenant bail.pdf', ['title' => 'Avenant n° 2 au bail — local de Marcory'], [['name' => 'SCI Les Cocotiers', 'label' => 'Le Bailleur', 'email' => 'gestion@cocotiers.ci']]);
        $svc->send($company, $r4);
        $c = $signer($r4, 0);
        $svc->viewed($r4, $c, $ip4 = $ip(), $ua);
        $svc->decline($company, $r4->fresh(), $c->fresh(), 'Le montant convenu était de 420 000 F CFA, merci de corriger.', $ip4, $ua);

        // 5. Brouillon
        $svc->create($company, $admin, $pdf('Bon de livraison', ['Livraison de 40 packs d\'eau minérale à l\'Hôtel Lagune.']), 'Bon de livraison.pdf',
            ['title' => 'Bon de livraison — Hôtel Lagune'], [['name' => 'Réception Hôtel Lagune', 'label' => 'Le destinataire', 'phone' => '+225 27 21 00 00 00']]);
        $mail?->forceFill(['enabled' => true])->save();
        auth()->logout();
    }

    /** Pointage : borne, codes PIN, horaires, trois semaines de pointages (retards, absence, oubli de badge). */
    protected function pointage(Company $company): void
    {
        app()->instance('tenant', $company);
        app(\App\Modules\Pointage\PointageSetup::class)($company);
        $settings = $company->fresh()->settings ?? [];
        $settings['_setup']['pointage'] = 1;
        $company->forceFill(['settings' => $settings])->saveQuietly();
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $roles = \App\Models\Role::where('company_id', $company->id)->pluck('id', 'name');
        User::create(['company_id' => $company->id, 'role_id' => $roles['Borne de pointage'], 'name' => 'Borne entrée boutique', 'email' => 'borne@demo.ci', 'password' => 'password']);

        $S = \App\Modules\Pointage\Models\Schedule::class;
        $B = \App\Modules\Pointage\Models\Badge::class;
        $P = \App\Modules\Pointage\Models\Punch::class;
        $day = ['start' => '08:00', 'end' => '18:00', 'break' => 120];
        $shop = new $S(['name' => 'Boutique 8 h – 18 h (lundi au samedi)', 'days' => ['1' => $day, '2' => $day, '3' => $day, '4' => $day, '5' => $day, '6' => $day, '7' => null], 'tolerance' => 10]);
        $shop->id = (string) \Illuminate\Support\Str::uuid();
        $shop->save();
        $emp = fn (string $m) => \App\Modules\Rh\Models\Employee::where('matricule', $m)->first();
        foreach (['EMP00001' => '1111', 'EMP00002' => '2222', 'EMP00003' => '3333', 'EMP00004' => '4444'] as $m => $pin) {
            $b = $B::find($emp($m)->id);
            $b->forceFill(['pin_hash' => $B::hashPin($b->id, $pin), 'schedule_id' => in_array($m, ['EMP00002', 'EMP00003'], true) ? $shop->id : $b->schedule_id, 'self_allowed' => $m !== 'EMP00004'])->save();
        }

        mt_srand(2026);
        $tz = $company->settings['timezone'] ?? config('app.timezone');
        $now = now($tz);
        $leaves = \App\Modules\Rh\Models\Leave::where('status', 'approved')->get();
        $absent = collect(range(4, 9))->map(fn ($i) => $now->copy()->subDays($i))->first(fn ($d) => $d->isWeekday())->toDateString();
        $forgot = collect(range(2, 6))->map(fn ($i) => $now->copy()->subDays($i))->first(fn ($d) => $d->isWeekday() && ! $d->isSaturday())->toDateString();
        $put = function (string $empId, string $at, string $kind, string $source, ?string $note = null) use ($P) {
            $p = new $P;
            $p->id = (string) \Illuminate\Support\Str::uuid();
            $p->forceFill(['employee_id' => $empId, 'at' => $at, 'date' => substr($at, 0, 10), 'kind' => $kind, 'source' => $source, 'note' => $note, 'device' => $source === 'kiosk' ? 'Borne' : null]);
            $p->save();
        };
        foreach ($B::where('active', true)->get() as $b) {
            $sched = $S::find($b->schedule_id) ?? $S::where('is_default', true)->first();
            for ($i = 55; $i >= 0; $i--) {
                $d = $now->copy()->subDays($i)->startOfDay();
                $plan = $sched->forDay($d->isoWeekday());
                if (! $plan || $leaves->contains(fn ($l) => $l->employee_id === $b->id && $l->start_date->lte($d) && $l->end_date->gte($d))) {
                    continue;
                }
                if ($b->matricule === 'EMP00004' && $d->toDateString() === $absent) {
                    continue; // absence non justifiée
                }
                $late = mt_rand(1, 100) <= 12 ? mt_rand(15, 40) : mt_rand(-12, 8);
                $in = \Carbon\Carbon::parse($d->toDateString().' '.$plan['start'], $tz)->addMinutes($late);
                $out = \Carbon\Carbon::parse($d->toDateString().' '.$plan['end'], $tz)->addMinutes(mt_rand(-5, 45) + ($b->matricule === 'EMP00002' && $d->isFriday() ? 90 : 0));
                $source = $b->matricule === 'EMP00002' && mt_rand(0, 1) ? 'self' : 'kiosk';
                if ($in->gt($now)) {
                    continue;
                }
                $put($b->id, $in->format('Y-m-d H:i:s'), 'in', $source);
                if ($b->matricule === 'EMP00003' && $d->toDateString() === $forgot) {
                    continue; // départ oublié
                }
                if ($out->lt($now)) {
                    $put($b->id, $out->format('Y-m-d H:i:s'), 'out', $source);
                }
            }
        }
        // Inventaire un dimanche matin, saisi par la gérante
        $sunday = $now->copy()->subWeek()->startOfWeek()->addDays(6)->toDateString();
        $put($emp('EMP00004')->id, "$sunday 08:00:00", 'in', 'manual', 'Inventaire du dimanche');
        $put($emp('EMP00004')->id, "$sunday 12:30:00", 'out', 'manual', 'Inventaire du dimanche');
    }

    /** Espace salarié : Yao (caissier) relié à sa fiche, bulletins des deux mois passés, congés de l'équipe. */
    protected function espaceSalarie(Company $company): void
    {
        app()->instance('tenant', $company);
        app(\App\Modules\Rh\RhSetup::class)($company);
        $settings = $company->fresh()->settings ?? [];
        $settings['_setup']['rh'] = 2;
        $settings['rh_signatory'] = 'Awa Koné';
        $settings['rh_signatory_title'] = 'La Gérante';
        $company->forceFill(['settings' => $settings])->saveQuietly();
        $company = $company->fresh();
        app()->instance('tenant', $company);

        $E = \App\Modules\Rh\Models\Employee::class;
        $L = \App\Modules\Rh\Models\Leave::class;
        $roles = \App\Models\Role::where('company_id', $company->id)->pluck('id', 'name');
        $yao = User::create(['company_id' => $company->id, 'role_id' => $roles['Caissier'], 'name' => 'Yao Kouassi (caisse)', 'email' => 'caisse@demo.ci', 'password' => 'password']);
        $mariam = User::create(['company_id' => $company->id, 'role_id' => $roles['Salarié'], 'name' => 'Mariam Traoré', 'email' => 'mariam.traore@demo.ci', 'password' => 'password']);
        $e = fn (string $m) => $E::where('matricule', $m)->first();
        $company->forceFill(['trade_register' => $company->trade_register ?: 'CI-ABJ-2019-B-04512', 'tax_id' => $company->tax_id ?: '1904512 K', 'address' => $company->address ?: 'Boulevard VGE, Marcory'])->save();
        $e('EMP00002')->update(['user_id' => $yao->id, 'birth_date' => '1996-11-03', 'phone' => '+225 07 07 00 00 01', 'emergency_contact' => 'Adjoua Kouassi (sœur) 05 05 00 00 01']);
        $e('EMP00003')->update(['user_id' => $mariam->id]);

        // Paies des deux mois précédents, validées et payées
        $admin = User::where('email', 'admin@demo.ci')->first();
        $svc = app(\App\Modules\Paie\PayrollService::class);
        foreach ([2, 1] as $ago) {
            $period = now()->startOfMonth()->subMonthsNoOverflow($ago);
            $run = $svc->createRun($company, $period->format('Y-m'));
            $svc->validate($company, $run, $admin->id);
            $svc->pay($company, $run->fresh(), 'bank_transfer', $period->copy()->endOfMonth()->toDateString());
        }

        // Congés : passés, à venir, refusé, en attente
        $leave = function (string $m, string $type, int $from, int $to, string $status, ?string $reason = null, ?string $note = null) use ($L, $e, $admin) {
            // Pas de congé qui commence ou finit un dimanche
            $from += now()->addDays($from)->isSunday() ? 1 : 0;
            $to += now()->addDays($to)->isSunday() ? 1 : 0;
            $to = max($to, $from);

            return $L::create([
            'employee_id' => $e($m)->id, 'type' => $type, 'start_date' => now()->addDays($from)->toDateString(), 'end_date' => now()->addDays($to)->toDateString(),
            'days' => $L::workingDays(now()->addDays($from)->toDateString(), now()->addDays($to)->toDateString()), 'status' => $status, 'reason' => $reason, 'decision_note' => $note,
            'requested_by' => $status === 'requested' ? null : $admin->id, 'decided_by' => in_array($status, ['approved', 'rejected'], true) ? $admin->id : null, 'decided_at' => in_array($status, ['approved', 'rejected'], true) ? now()->subDays(2) : null,
            ]);
        };
        $leave('EMP00002', 'annual', -75, -71, 'approved', 'Congé annuel');
        $leave('EMP00002', 'exceptional', -20, -20, 'approved', 'Baptême de ma fille');
        $leave('EMP00002', 'annual', 40, 45, 'rejected', 'Voyage', 'Période d\'inventaire, merci de choisir une autre date');
        $leave('EMP00003', 'sick', -9, -7, 'approved', 'Certificat médical remis');
        $leave('EMP00003', 'annual', 25, 36, 'requested', 'Congé annuel');
        $leave('EMP00004', 'annual', 3, 8, 'approved', 'Congé annuel');
        $leave('EMP00001', 'exceptional', 18, 18, 'requested', 'Mariage d\'un proche');

        // Documents RH : attestation (libre-service), contrat de Mariam, avertissement en brouillon, demande en attente
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $T = \App\Modules\Rh\Models\DocTemplate::all()->keyBy('name');
        $D = \App\Modules\Rh\Models\HrDocument::class;
        $w = app(\App\Services\EntityWriter::class);
        $doc = function (?User $as, string $m, string $tpl, array $extra = [], string $status = 'issued', array $more = []) use ($T, $D, $w, $e, $company) {
            auth()->setUser($as);
            $t = $T[$tpl];
            $r = $w->upsert($D, (string) \Illuminate\Support\Str::uuid(), $more + ['employee_id' => $e($m)->id, 'template_id' => $t->id, 'type' => $t->type, 'title' => $t->name,
                'date' => now()->toDateString(), 'body' => \App\Modules\Rh\DocRenderer::render($t->body, \App\Modules\Rh\DocRenderer::fields($company, $e($m)->fresh(), $extra)), 'status' => $status]);
            if ($r['status'] !== 'applied') {
                throw new \RuntimeException('Document RH : '.$r['message']);
            }
        };
        $doc($yao, 'EMP00002', 'Attestation de travail');
        $e('EMP00003')->update(['birth_date' => '1998-05-12', 'address' => 'Yopougon Niangon', 'id_number' => 'CI004512398', 'category' => 'Employée 2']);
        $doc($admin, 'EMP00003', 'Contrat de travail à durée indéterminée', ['lieu_travail' => 'Abidjan, boutique de Marcory', 'duree_essai' => 'un (1) mois renouvelable une fois']);
        $doc($admin, 'EMP00004', 'Avertissement', ['faits' => 'trois retards de plus de trente minutes au cours du mois, constatés par le système de pointage, sans justification'], 'draft');
        auth()->setUser($yao);
        $w->upsert($D, (string) \Illuminate\Support\Str::uuid(), ['employee_id' => $e('EMP00002')->id, 'type' => 'other', 'title' => 'Autre document', 'date' => now()->toDateString(), 'note' => 'Attestation de salaire pour un prêt à la banque', 'status' => 'requested']);
        auth()->logout();
    }

    /** Trésorerie : banque SGCI, caisse, Orange Money, dépenses courantes, virement, prévisions, relevé importé. */
    protected function tresorerie(Company $company): void
    {
        app()->instance('tenant', $company);
        $svc = app(\App\Modules\Tresorerie\TreasuryService::class);
        $svc->ensureDefaults();
        $w = app(\App\Services\EntityWriter::class);
        $put = function (string $class, array $data, ?string $id = null) use ($w) {
            $r = $w->upsert($class, $id ?? (string) \Illuminate\Support\Str::uuid(), $data);
            if ($r['status'] !== 'applied') {
                throw new \RuntimeException($class.' : '.$r['message'].' '.json_encode($r['errors']));
            }

            return $r['record'];
        };
        $A = \App\Modules\Tresorerie\Models\TreasuryAccount::class;
        $acc = $A::all()->keyBy('kind');
        $start = now()->startOfYear()->toDateString();
        $bank = $put($A, ['name' => 'SGCI Plateau', 'bank_name' => 'Société Générale Côte d\'Ivoire', 'number' => 'CI008 01234 012345678901 25', 'opening_balance' => 6000000, 'opening_date' => $start, 'low_balance' => 1000000], $acc['bank']->id);
        $cash = $put($A, ['name' => 'Caisse boutique', 'opening_balance' => 600000, 'opening_date' => $start, 'low_balance' => 100000], $acc['cash']->id);
        $mm = $put($A, ['name' => 'Orange Money marchand', 'bank_name' => 'Orange', 'number' => '07 07 00 00 00', 'opening_balance' => 320000, 'opening_date' => $start], $acc['mobile_money']->id);

        $O = \App\Modules\Tresorerie\Models\Operation::class;
        $op = fn ($a, int $ago, float $amount, string $cat, string $label, ?string $ref = null) => $put($O, ['account_id' => $a->id, 'date' => now()->subDays($ago)->toDateString(), 'amount' => $amount, 'category' => $cat, 'label' => $label, 'reference' => $ref]);
        $op($bank, 40, -450000, 'rent', 'Loyer du local — mois précédent', 'VIR LOYER');
        $op($bank, 31, -18500, 'bank_fees', 'Frais de tenue de compte et SMS banking');
        $op($bank, 22, -187000, 'social', 'Cotisations CNPS', 'CNPS');
        $op($bank, 10, -450000, 'rent', 'Loyer du local', 'VIR LOYER');
        $op($cash, 20, -35000, 'utilities', 'Carburant groupe électrogène');
        $op($cash, 14, -12500, 'supplies', 'Fournitures de bureau');
        $op($cash, 6, -25000, 'transport', 'Livraisons taxi-moto');
        $op($mm, 18, -42000, 'telecom', 'Forfait internet boutique');
        $op($mm, 3, -8000, 'bank_fees', 'Frais de retrait Orange Money');
        // Versement des espèces en banque
        $tid = (string) \Illuminate\Support\Str::uuid();
        $op2 = fn ($a, float $amount) => $put($O, ['account_id' => $a->id, 'date' => now()->subDays(12)->toDateString(), 'amount' => $amount, 'category' => 'transfer', 'label' => 'Versement espèces en banque', 'transfer_id' => $tid]);
        $op2($cash, -200000);
        $op2($bank, 200000);

        $F = \App\Modules\Tresorerie\Models\Forecast::class;
        $put($F, ['label' => 'Loyer du local', 'amount' => -450000, 'date' => now()->addDays(20)->toDateString(), 'repeat' => 'monthly']);
        $put($F, ['label' => 'Salaires', 'amount' => -1250000, 'date' => now()->addDays(2)->toDateString(), 'repeat' => 'monthly']);
        $put($F, ['label' => 'Échéance prêt BICICI', 'amount' => -380000, 'date' => now()->addDays(9)->toDateString(), 'repeat' => 'monthly']);
        $put($F, ['label' => 'Ventes comptoir (estimation)', 'amount' => 600000, 'date' => now()->addDays(4)->toDateString(), 'repeat' => 'weekly']);
        $put($F, ['label' => 'Acompte commande Hôtel Lagune', 'amount' => 1500000, 'date' => now()->addDays(25)->toDateString(), 'repeat' => 'none']);

        // Relevé bancaire du mois : la plupart des lignes se rapprochent seules, d'autres restent à traiter
        $rows = [['Date opération', 'Date valeur', 'Libellé', 'Débit', 'Crédit', 'Solde']];
        $bal = 6000000;
        $book = $svc->bookMovements($bank->fresh())->sortBy('date')->values();
        $lines = [];
        foreach ($book as $i => $m) {
            if ($i % 5 === 4) {
                continue; // pas encore passé en banque
            }
            $lines[] = [$m['date'], $m['amount'], strtoupper($m['amount'] < 0 ? 'PRLV ' : 'VIR RECU ').mb_strtoupper(\Illuminate\Support\Str::ascii($m['label']))];
        }
        $lines[] = [now()->subDays(5)->toDateString(), -6500, 'COMMISSION TENUE COMPTE'];
        $lines[] = [now()->subDays(4)->toDateString(), -2950, 'TAXE TOB SUR FRAIS'];
        $open = \App\Modules\Ventes\Models\SalesDocument::where('type', 'invoice')->where('status', 'validated')->whereIn('payment_status', ['unpaid', 'partial'])->first();
        if ($open) {
            $lines[] = [now()->subDays(2)->toDateString(), round($open->balance()), 'VIR RECU '.mb_strtoupper(\Illuminate\Support\Str::ascii((string) $open->partner_name)).' REF '.$open->number];
        }
        usort($lines, fn ($a, $b) => strcmp($a[0], $b[0]));
        foreach ($lines as [$d, $amt, $label]) {
            $bal += $amt;
            $rows[] = [\Carbon\Carbon::parse($d)->format('d/m/Y'), \Carbon\Carbon::parse($d)->format('d/m/Y'), $label, $amt < 0 ? number_format(-$amt, 0, ',', ' ') : '', $amt > 0 ? number_format($amt, 0, ',', ' ') : '', number_format($bal, 0, ',', ' ')];
        }
        $csv = "SOCIETE GENERALE COTE D'IVOIRE\nRelevé de compte CI008 01234 012345678901 25\n\n";
        foreach ($rows as $r) {
            $csv .= implode(';', array_map(fn ($c) => str_contains((string) $c, ';') ? '"'.$c.'"' : $c, $r))."\n";
        }
        $path = tempnam(sys_get_temp_dir(), 'rel').'.csv';
        file_put_contents($path, $csv);
        $a = $svc->analyzeStatement($path);
        $svc->importStatement($bank->fresh(), $path, 'releve-sgci.csv', $a['mapping'], false, 'Paul Ehui');
        @unlink($path);
    }

    /** Étiquettes : codes internes, vrac pesé, historique d'impression (dont un prix changé depuis). */
    protected function etiquettes(Company $company): void
    {
        app()->instance('tenant', $company);
        $wh = Warehouse::defaultFor($company->id);
        $epi = ProductCategory::where('name', 'Épicerie')->value('id');
        foreach ([['Riz brisé (vrac)', 650], ['Arachides grillées (vrac)', 1800], ['Poisson fumé (vrac)', 4500]] as [$n, $ttc]) {
            $p = Product::create(['category_id' => $epi, 'name' => $n, 'unit' => 'kg', 'sale_price' => round($ttc / 1.18, 2), 'purchase_price' => round($ttc * 0.7), 'tax_rate' => 18, 'track_stock' => true]);
            StockMove::create(['product_id' => $p->id, 'warehouse_id' => $wh->id, 'quantity' => 50, 'type' => 'initial', 'date' => now()->toDateString()]);
        }
        app(\App\Modules\Etiquettes\EtiquettesController::class)->barcodes(new \Illuminate\Http\Request());
        $put = fn (array $d) => app(\App\Services\EntityWriter::class)->upsert(\App\Modules\Etiquettes\Models\LabelPrint::class, $d['product_id'], $d);
        foreach (Product::whereIn('name', ['Coca-Cola 33cl', 'Eau minérale 1,5L', 'Riz parfumé 5kg', 'Huile végétale 1L', 'Savon de Marseille', 'Dentifrice 75ml'])->get() as $p) {
            $ttc = round($p->sale_price * (1 + $p->tax_rate / 100));
            // Le riz et l'huile ont augmenté depuis la dernière impression
            $put(['product_id' => $p->id, 'price' => in_array($p->name, ['Riz parfumé 5kg', 'Huile végétale 1L'], true) ? $ttc - 250 : $ttc, 'count' => 24, 'printed_at' => now()->subDays(12)->toIso8601String()]);
        }
        app(\App\Services\EntityWriter::class)->upsert(\App\Modules\Etiquettes\Models\LabelTemplate::class, (string) \Illuminate\Support\Str::uuid(), ['name' => 'Planche fournisseur 4 × 12 (48 × 24 mm)', 'kind' => 'sheet', 'page_w' => 210, 'page_h' => 297, 'cols' => 4, 'rows' => 12, 'label_w' => 48, 'label_h' => 24, 'margin_top' => 4.5, 'margin_left' => 5, 'gap_x' => 2, 'gap_y' => 0, 'style' => 'product', 'fields' => ['name', 'price', 'barcode']]);
    }

    /** Carte du restaurant (plats sans suivi de stock : leurs ingrédients sortent via les recettes). */
    protected function restaurantMenu(Company $company): void
    {
        app()->instance('tenant', $company);
        $plats = ProductCategory::create(['name' => 'Plats'])->id;
        $grill = ProductCategory::create(['name' => 'Grillades'])->id;
        $desserts = ProductCategory::create(['name' => 'Desserts'])->id;
        foreach ([[$plats, 'Garba (attiéké thon)', 1500], [$plats, 'Kedjenou de poulet', 6000], [$plats, 'Sauce graine & riz', 3500], [$plats, 'Foutou sauce claire', 4000],
            [$grill, 'Poulet braisé entier', 7000], [$grill, 'Poisson braisé & attiéké', 5000], [$grill, 'Brochettes de bœuf (x4)', 3000], [$grill, 'Alloco', 1000],
            [$desserts, 'Salade de fruits', 1500], [$desserts, 'Dégué', 1000]] as [$cat, $name, $ttc]) {
            Product::create(['category_id' => $cat, 'name' => $name, 'type' => 'service', 'sale_price' => round($ttc / 1.18, 2), 'tax_rate' => 18]);
        }
    }

    /** Fabrication : ingrédients, recettes des plats du restaurant, jus de bissap fabriqué par lots. */
    protected function production(Company $company): void
    {
        $settings = $company->fresh()->settings ?? [];
        $settings['mrp_labour_rate'] = 1000;
        $company->forceFill(['settings' => $settings])->saveQuietly();
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $w = app(\App\Services\EntityWriter::class);
        $put = function (string $class, array $data, ?string $id = null) use ($w) {
            $r = $w->upsert($class, $id ?? (string) \Illuminate\Support\Str::uuid(), $data);
            if ($r['status'] !== 'applied') {
                throw new \RuntimeException($class.' : '.$r['message'].' '.json_encode($r['errors']));
            }

            return $r['record'];
        };
        $roles = \App\Models\Role::where('company_id', $company->id)->pluck('id', 'name');
        User::create(['company_id' => $company->id, 'role_id' => $roles['Opérateur de production'] ?? null, 'name' => 'Lassina Atelier', 'email' => 'lassina@demo.ci', 'password' => 'password']);
        $wh = Warehouse::defaultFor($company->id);
        $ing = ProductCategory::create(['name' => 'Matières premières'])->id;
        $I = [];
        foreach ([['Attiéké (kg)', 'kg', 800, 40], ['Thon frit (kg)', 'kg', 2600, 12], ['Poulet (kg)', 'kg', 2200, 30], ['Tilapia (kg)', 'kg', 2600, 20],
            ['Banane plantain (kg)', 'kg', 500, 25], ['Oignons (kg)', 'kg', 700, 10], ['Tomates (kg)', 'kg', 900, 8], ['Piment (kg)', 'kg', 1500, 2],
            ['Fleurs de bissap (kg)', 'kg', 2500, 3], ['Bouteilles 50 cl vides', 'u', 60, 150]] as [$name, $unit, $cost, $qty]) {
            $I[$name] = Product::create(['category_id' => $ing, 'name' => $name, 'unit' => $unit, 'type' => 'product', 'track_stock' => true, 'sale_price' => 0, 'purchase_price' => $cost, 'tax_rate' => 0, 'min_stock' => round($qty / 4)])->id;
            StockMove::create(['product_id' => $I[$name], 'warehouse_id' => $wh->id, 'quantity' => $qty, 'type' => 'initial', 'date' => now()->subDays(3)->toDateString()]);
        }
        $P = Product::all()->keyBy('name');
        $B = \App\Modules\Production\Models\Bom::class;
        $kit = fn (string $dish, array $lines, int $minutes) => $put($B, ['product_id' => $P[$dish]->id, 'kind' => 'kit', 'quantity' => 1, 'labour_minutes' => $minutes,
            'lines' => array_map(fn ($l) => ['product_id' => $I[$l[0]] ?? $P[$l[0]]->id, 'quantity' => $l[1], 'loss_percent' => $l[2] ?? 0], $lines)]);
        $kit('Garba (attiéké thon)', [['Attiéké (kg)', 0.25], ['Thon frit (kg)', 0.1], ['Huile végétale 1L', 0.03], ['Oignons (kg)', 0.03], ['Piment (kg)', 0.01]], 4);
        $kit('Poulet braisé entier', [['Poulet (kg)', 1.3, 10], ['Oignons (kg)', 0.1], ['Piment (kg)', 0.02], ['Attiéké (kg)', 0.2]], 15);
        $kit('Kedjenou de poulet', [['Poulet (kg)', 0.45, 10], ['Tomates (kg)', 0.15], ['Oignons (kg)', 0.08], ['Piment (kg)', 0.01]], 12);
        $kit('Poisson braisé & attiéké', [['Tilapia (kg)', 0.5, 15], ['Attiéké (kg)', 0.25], ['Oignons (kg)', 0.05], ['Tomates (kg)', 0.05]], 10);
        $kit('Alloco', [['Banane plantain (kg)', 0.35, 5], ['Huile végétale 1L', 0.05]], 5);

        // Jus de bissap fabriqué par lots de 40 bouteilles
        $bissap = $put($B, ['product_id' => $P['Jus de bissap 50cl']->id, 'kind' => 'manufacture', 'quantity' => 40, 'labour_minutes' => 120, 'extra_cost' => 1500,
            'notes' => "1. Rincer les fleurs, infuser 20 min dans 20 L d'eau frémissante.\n2. Filtrer, sucrer, aromatiser (menthe, gingembre).\n3. Mettre en bouteilles, réfrigérer.",
            'lines' => [['product_id' => $I['Fleurs de bissap (kg)'], 'quantity' => 0.8, 'loss_percent' => 5], ['product_id' => $P['Sucre en morceaux 1kg']->id, 'quantity' => 3],
                ['product_id' => $I['Bouteilles 50 cl vides'], 'quantity' => 40, 'loss_percent' => 2]]]);
        $O = \App\Modules\Production\Models\ProductionOrder::class;
        $done = $put($O, ['product_id' => $P['Jus de bissap 50cl']->id, 'quantity' => 40, 'planned_date' => now()->subDay()->toDateString()]);
        $put($O, ['status' => 'confirmed'], $done->id);
        $put($O, ['status' => 'in_progress'], $done->id);
        $put($O, ['status' => 'done', 'produced_qty' => 39, 'labour_minutes' => 130], $done->id);
        $o2 = $put($O, ['product_id' => $P['Jus de bissap 50cl']->id, 'quantity' => 80, 'planned_date' => now()->toDateString(), 'notes' => 'Commande Hôtel Lagune']);
        $put($O, ['status' => 'confirmed'], $o2->id);
        $put($O, ['status' => 'in_progress'], $o2->id);
        $put($O, ['product_id' => $P['Jus de bissap 50cl']->id, 'quantity' => 120, 'planned_date' => now()->addDays(2)->toDateString()]);
    }

    /** Restaurant : deux salles, cuisine et bar, service du midi en cours, additions déjà réglées. */
    protected function restaurant(Company $company): void
    {
        app()->instance('tenant', $company);
        $w = app(\App\Services\EntityWriter::class);
        $put = function (string $class, array $data) use ($w) {
            $r = $w->upsert($class, (string) \Illuminate\Support\Str::uuid(), $data);
            if ($r['status'] !== 'applied') {
                throw new \RuntimeException($class.' : '.$r['message'].' '.json_encode($r['errors']));
            }

            return $r['record'];
        };
        $roles = \App\Models\Role::where('company_id', $company->id)->pluck('id', 'name');
        $adjoua = User::create(['company_id' => $company->id, 'role_id' => $roles['Serveur'] ?? null, 'name' => 'Adjoua Serveuse', 'email' => 'adjoua@demo.ci', 'password' => 'password']);
        $kader = User::create(['company_id' => $company->id, 'role_id' => $roles['Serveur'] ?? null, 'name' => 'Kader Serveur', 'email' => 'kader@demo.ci', 'password' => 'password']);
        User::create(['company_id' => $company->id, 'role_id' => $roles['Cuisine / bar'] ?? null, 'name' => 'Bakary Cuisinier', 'email' => 'bakary@demo.ci', 'password' => 'password']);

        // Carte (créée par restaurantMenu)
        $boissons = ProductCategory::where('name', 'Boissons')->value('id');
        [$plats, $grill, $desserts] = [ProductCategory::where('name', 'Plats')->value('id'), ProductCategory::where('name', 'Grillades')->value('id'), ProductCategory::where('name', 'Desserts')->value('id')];
        $menu = Product::whereIn('category_id', [$plats, $grill, $desserts])->pluck('id', 'name')->all();
        foreach (['Bière locale 65cl', 'Coca-Cola 33cl', 'Jus de bissap 50cl', 'Eau minérale 1,5L'] as $n) {
            $menu[$n] = Product::where('name', $n)->value('id');
        }
        $put(\App\Modules\Restaurant\Models\Station::class, ['name' => 'Cuisine', 'kind' => 'kitchen', 'category_ids' => [$plats, $desserts], 'sort' => 0]);
        $put(\App\Modules\Restaurant\Models\Station::class, ['name' => 'Grill', 'kind' => 'kitchen', 'category_ids' => [$grill], 'sort' => 1]);
        $put(\App\Modules\Restaurant\Models\Station::class, ['name' => 'Bar', 'kind' => 'bar', 'category_ids' => [$boissons], 'sort' => 2]);

        // Plan de salle
        $salle = $put(\App\Modules\Restaurant\Models\Floor::class, ['name' => 'Salle', 'sort' => 0]);
        $terrasse = $put(\App\Modules\Restaurant\Models\Floor::class, ['name' => 'Terrasse', 'sort' => 1]);
        $T = [];
        foreach ([['1', 1, 1, 2, 2, 'square', 2], ['2', 4, 1, 2, 2, 'square', 2], ['3', 7, 1, 2, 2, 'square', 4], ['4', 10, 1, 3, 3, 'round', 6], ['5', 1, 5, 2, 2, 'square', 4],
            ['6', 4, 5, 2, 2, 'square', 4], ['7', 7, 5, 4, 2, 'rect', 8], ['8', 14, 1, 2, 2, 'round', 2], ['9', 14, 5, 2, 2, 'round', 2], ['10', 1, 10, 6, 2, 'rect', 12],
            ['11', 9, 10, 3, 3, 'round', 6], ['12', 14, 10, 2, 2, 'square', 4]] as [$n, $x, $y, $ww, $h, $shape, $seats]) {
            $T[$n] = $put(\App\Modules\Restaurant\Models\Table::class, ['floor_id' => $salle->id, 'name' => $n, 'x' => $x, 'y' => $y, 'w' => $ww, 'h' => $h, 'shape' => $shape, 'seats' => $seats]);
        }
        foreach ([['T1', 1, 1], ['T2', 4, 1], ['T3', 7, 1], ['T4', 10, 1], ['T5', 1, 4], ['T6', 4, 4]] as [$n, $x, $y]) {
            $put(\App\Modules\Restaurant\Models\Table::class, ['floor_id' => $terrasse->id, 'name' => $n, 'x' => $x, 'y' => $y, 'w' => 2, 'h' => 2, 'shape' => 'round', 'seats' => 4]);
        }

        $O = \App\Modules\Restaurant\Models\Order::class;
        $L = \App\Modules\Restaurant\Models\OrderLine::class;
        $order = function (?string $table, int $guests, User $waiter, int $ago, array $lines, ?string $customer = null) use ($put, $O, $L, $T, $menu) {
            $o = $put($O, ['table_id' => $table ? $T[$table]->id : null, 'guests' => $guests, 'waiter_id' => $waiter->id, 'customer_name' => $customer, 'opened_at' => now()->subMinutes($ago)->toIso8601String()]);
            $o->forceFill(['waiter_name' => $waiter->name])->saveQuietly();
            $made = [];
            foreach ($lines as [$name, $q, $course, $status, $sentAgo, $note]) {
                $l = $put($L, ['order_id' => $o->id, 'product_id' => $menu[$name], 'quantity' => $q, 'course' => $course, 'note' => $note, 'status' => in_array($status, ['pending'], true) ? 'pending' : 'sent']);
                if ($status !== 'pending') {
                    $sent = now()->subMinutes($sentAgo);
                    $l->forceFill([
                        'status' => $status, 'sent_at' => $sent,
                        'ready_at' => in_array($status, ['ready', 'served'], true) ? $sent->copy()->addMinutes($prep = min(max(3, intdiv($sentAgo, 3)), $course === 0 ? 4 : 17)) : null,
                        'served_at' => $status === 'served' ? $sent->copy()->addMinutes(($prep ?? 4) + 2) : null,
                        'created_at' => $sent,
                    ])->save();
                }
                $made[] = $l;
            }

            return [$o, $made];
        };

        // Additions déjà réglées ce midi (tickets de caisse reliés)
        $register = \App\Modules\Pos\Models\PosRegister::first();
        $session = $put(\App\Modules\Pos\Models\PosSession::class, ['register_id' => $register->id, 'opening_cash' => 20000, 'status' => 'open', 'opened_at' => now()->subHours(3)->toIso8601String()]);
        $session->forceFill(['user_name' => 'Adjoua Serveuse', 'user_id' => $adjoua->id])->saveQuietly();
        $pay = function ($o, array $lines, string $method) use ($put, $session, $register) {
            $doc = $put(\App\Modules\Ventes\Models\SalesDocument::class, ['type' => 'receipt', 'status' => 'validated', 'date' => now()->toDateString(), 'pos_session_id' => $session->id,
                'warehouse_id' => $register->warehouse_id, 'rs_order_id' => $o->id, 'rs_bill' => collect($lines)->mapWithKeys(fn ($l) => [$l->id => $l->quantity])->all(),
                'notes' => 'Table · '.$o->number, 'lines' => collect($lines)->map(fn ($l) => ['product_id' => $l->product_id, 'description' => $l->description, 'quantity' => $l->quantity, 'unit_price' => $l->unit_price, 'tax_rate' => $l->tax_rate])->all()]);
            $put(\App\Modules\Ventes\Models\SalesPayment::class, ['document_id' => $doc->id, 'date' => now()->toDateString(), 'amount' => $doc->fresh()->total, 'method' => $method]);
        };
        [$o, $ls] = $order('3', 4, $adjoua, 150, [['Bière locale 65cl', 4, 0, 'served', 145, null], ['Garba (attiéké thon)', 2, 2, 'served', 140, null], ['Poisson braisé & attiéké', 2, 2, 'served', 140, 'Piment à part']]);
        $pay($o, $ls, 'cash');
        [$o, $ls] = $order('8', 2, $kader, 120, [['Coca-Cola 33cl', 2, 0, 'served', 118, null], ['Kedjenou de poulet', 2, 2, 'served', 115, null], ['Dégué', 2, 3, 'served', 80, null]]);
        $pay($o, array_slice($ls, 0, 2), 'mobile_money');
        $pay($o, array_slice($ls, 2), 'cash');
        [$o, $ls] = $order(null, 1, $kader, 100, [['Poulet braisé entier', 1, 2, 'served', 98, null], ['Alloco', 2, 2, 'served', 98, null]], 'M. Yao');
        $pay($o, $ls, 'mobile_money');

        // Service en cours
        $order('1', 2, $adjoua, 38, [['Jus de bissap 50cl', 2, 0, 'served', 36, null], ['Sauce graine & riz', 2, 2, 'preparing', 22, null], ['Salade de fruits', 2, 3, 'pending', 0, null]]);
        $order('4', 6, $adjoua, 26, [['Bière locale 65cl', 6, 0, 'served', 25, 'Bien fraîches'], ['Brochettes de bœuf (x4)', 2, 1, 'ready', 18, null],
            ['Poulet braisé entier', 1, 2, 'preparing', 18, null], ['Foutou sauce claire', 3, 2, 'sent', 9, null], ['Kedjenou de poulet', 2, 2, 'sent', 9, 'Sans piment']]);
        $order('6', 3, $kader, 15, [['Eau minérale 1,5L', 1, 0, 'ready', 6, null], ['Garba (attiéké thon)', 3, 2, 'sent', 6, 'Un sans oignon']]);
        [$o] = $order('7', 8, $kader, 70, [['Bière locale 65cl', 8, 0, 'served', 68, null], ['Poisson braisé & attiéké', 4, 2, 'served', 60, null], ['Kedjenou de poulet', 4, 2, 'served', 60, null]]);
        $o->forceFill(['bill_requested_at' => now()->subMinutes(3)])->saveQuietly();
        $order('10', 9, $adjoua, 4, [['Coca-Cola 33cl', 4, 0, 'pending', 0, null], ['Jus de bissap 50cl', 5, 0, 'pending', 0, null]]);
        $order(null, 1, $kader, 12, [['Poisson braisé & attiéké', 2, 2, 'preparing', 11, null], ['Alloco', 2, 2, 'ready', 10, null]], 'Mme Koné');
    }

    /** Fidélité : programme, clients fidèles avec historique, récompenses, cartes cadeaux. */
    protected function fidelite(Company $company): void
    {
        $settings = $company->fresh()->settings ?? [];
        $settings += ['fid_enabled' => true, 'fid_per_amount' => 1000, 'fid_point_value' => 10, 'fid_min_redeem' => 100, 'fid_expiry_months' => 12, 'fid_welcome_points' => 50, 'fid_auto_enroll' => true,
            'fid_tiers' => [['name' => 'Argent', 'min' => 400, 'multiplier' => 1.25], ['name' => 'Or', 'min' => 1000, 'multiplier' => 1.5]], 'gift_card_months' => 12];
        $company->forceFill(['settings' => $settings])->saveQuietly();
        $company = $company->fresh();
        app()->instance('tenant', $company);
        $svc = app(\App\Modules\Fidelite\LoyaltyService::class);
        $people = [
            ['Mme Bamba Awa', '0709876543', 'Cocody', [[60, 42000], [35, 18500], [12, 64000], [3, 27500]], 100, 'Cadeau de mariage'],
            ['M. Kouakou Serge', '0102030405', 'Yopougon', [[80, 125000], [50, 98000], [20, 87500], [6, 112000], [1, 76000]], 200, null],
            ['Mme Yao Christelle', '0707112233', 'Marcory', [[40, 23500], [15, 31000]], 0, null],
            ['M. Coulibaly Ibrahim', '0566778899', 'Abobo', [[25, 15500]], 0, null],
            ['Mme Diallo Fatim', '0544556677', 'Plateau', [[90, 210000], [70, 185000], [45, 160000], [30, 240000], [10, 195000], [2, 150000], [1, 98000]], 500, 'Anniversaire client'],
            ['Mme Konan Estelle', '0707223344', 'Riviera', [[5, 8500]], 0, null],
        ];
        foreach ($people as [$name, $phone, $city, $buys, $redeem, $bonus]) {
            $p = Partner::where('company_id', $company->id)->where('name', $name)->first()
                ?? Partner::create(['name' => $name, 'type' => 'customer', 'phone' => $phone, 'city' => $city]);
            $card = $svc->cardFor($company, $p->id, true);
            $card->forceFill(['created_at' => now()->subDays($buys[0][0] + 1)])->saveQuietly();
            foreach ($buys as [$ago, $amount]) {
                $t = $svc->move($card, 'earn', $svc->pointsFor($company, $amount, $card->fresh()), ['amount' => $amount, 'note' => 'Ticket de caisse']);
                $t->forceFill(['created_at' => now()->subDays($ago), 'expires_at' => now()->subDays($ago)->addMonths(12)->toDateString()])->saveQuietly();
            }
            if ($bonus) {
                $svc->move($card, 'bonus', 200, ['note' => $bonus])->forceFill(['created_at' => now()->subDays(8), 'expires_at' => now()->subDays(8)->addMonths(12)->toDateString()])->saveQuietly();
            }
            if ($redeem) {
                $svc->move($card, 'redeem', -$redeem, ['amount' => $redeem * 10, 'note' => 'Paiement en points'])->forceFill(['created_at' => now()->subDays(4)])->saveQuietly();
            }
            $svc->refresh($card->fresh());
        }
        $R = \App\Modules\Fidelite\Models\Reward::class;
        $products = Product::all()->keyBy('name');
        $R::create(['name' => 'Bon de 1 000 F', 'points_cost' => 100, 'kind' => 'amount', 'amount' => 1000]);
        $R::create(['name' => 'Bon de 5 000 F', 'points_cost' => 500, 'kind' => 'amount', 'amount' => 5000]);
        if (isset($products['Coca-Cola 33cl'])) {
            $R::create(['name' => 'Pack de 6 Coca-Cola offert', 'points_cost' => 250, 'kind' => 'product', 'product_id' => $products['Coca-Cola 33cl']->id]);
        }
        $R::create(['name' => 'Bon de 15 000 F (clients Or)', 'points_cost' => 1500, 'kind' => 'amount', 'amount' => 15000]);
        $G = \App\Modules\Fidelite\Models\GiftCard::class;
        $buyer = Partner::where('company_id', $company->id)->where('name', 'Mme Diallo Fatim')->first();
        $G::create(['initial_amount' => 50000, 'balance' => 50000, 'kind' => 'gift', 'partner_id' => $buyer?->id, 'recipient_name' => 'Aïcha Diallo', 'message' => 'Joyeux anniversaire ma sœur !']);
        $g2 = $G::create(['initial_amount' => 20000, 'balance' => 20000, 'kind' => 'gift', 'recipient_name' => 'Équipe comptabilité', 'message' => 'Merci pour cette belle année']);
        \App\Modules\Fidelite\Models\GiftCardMove::create(['company_id' => $company->id, 'gift_card_id' => $g2->id, 'amount' => -12500, 'note' => 'Ticket de caisse']);
        $svc->refreshGift($g2->fresh());
        $G::create(['initial_amount' => 5000, 'balance' => 5000, 'kind' => 'voucher', 'recipient_name' => 'M. Coulibaly Ibrahim', 'message' => 'Geste commercial : livraison en retard']);
    }

    /** Rendez-vous : trois membres, quatre prestations, une semaine d'agenda dont des réservations en ligne. */
    protected function rendezVous(Company $company): void
    {
        app()->instance('tenant', $company);
        $St = \App\Modules\RendezVous\Models\Staff::class;
        $Sv = \App\Modules\RendezVous\Models\Service::class;
        $A = \App\Modules\RendezVous\Models\Appointment::class;
        $tz = $company->settings['timezone'] ?? 'Africa/Abidjan';
        $u = User::where('company_id', $company->id)->pluck('id', 'email');
        $mariam = $St::create(['name' => 'Mariam Touré', 'title' => 'Conseillère', 'user_id' => $u['mariam@demo.ci'] ?? null, 'color' => '#1F6FEB', 'sort' => 1]);
        $jm = $St::create(['name' => 'Jean-Marc Aka', 'title' => 'Commercial', 'user_id' => $u['jm@demo.ci'] ?? null, 'color' => '#12A150', 'sort' => 2,
            'schedule' => ['1' => [['09:00', '13:00'], ['15:00', '18:30']], '2' => [['09:00', '13:00'], ['15:00', '18:30']], '3' => [['09:00', '13:00']], '4' => [['09:00', '13:00'], ['15:00', '18:30']], '5' => [['09:00', '13:00'], ['15:00', '18:30']], '6' => [], '7' => []]]);
        $room = $St::create(['name' => 'Salle de dégustation', 'title' => 'Ressource', 'color' => '#E8A317', 'sort' => 3, 'online' => false]);
        $advice = $Sv::create(['name' => 'Conseil personnalisé', 'category' => 'Boutique', 'duration_minutes' => 30, 'price' => 0, 'color' => '#1F6FEB', 'description' => 'Un conseiller vous aide à choisir pour votre événement ou votre commerce.', 'sort' => 1]);
        $quote = $Sv::create(['name' => 'Rendez-vous devis professionnel', 'category' => 'Professionnels', 'duration_minutes' => 45, 'buffer_minutes' => 15, 'price' => 0, 'color' => '#12A150', 'staff_ids' => [$jm->id], 'description' => 'Restaurants, maquis, hôtels : établissons ensemble votre tarif et vos livraisons.', 'sort' => 2]);
        $pickup = $Sv::create(['name' => 'Retrait de commande', 'category' => 'Boutique', 'duration_minutes' => 15, 'price' => 0, 'color' => '#7A4FD6', 'sort' => 3]);
        $tasting = $Sv::create(['name' => 'Dégustation privée (6 pers. max.)', 'category' => 'Événements', 'duration_minutes' => 90, 'price' => 25000, 'color' => '#E8A317', 'staff_ids' => [$room->id, $mariam->id], 'requires_approval' => true, 'sort' => 4]);
        $day = function (int $offset) use ($tz) {
            $d = now($tz)->startOfDay()->addDays($offset);
            while ($d->isWeekend()) {
                $d->addDay();
            }

            return $d;
        };
        $mk = function ($svc, $staff, int $offset, string $time, string $name, string $phone, string $status = 'confirmed', string $source = 'manual', ?string $notes = null) use ($A, $day, $tz) {
            [$h, $m] = explode(':', $time);
            $start = $day($offset)->setTime((int) $h, (int) $m)->utc();
            $a = new $A(['service_id' => $svc->id, 'staff_id' => $staff->id, 'customer_name' => $name, 'customer_phone' => $phone, 'starts_at' => $start->toDateTimeString(),
                'ends_at' => $start->copy()->addMinutes($svc->duration_minutes)->toDateTimeString(), 'source' => $source, 'notes' => $notes, 'price' => $svc->price, 'notify' => false]);
            $a->status = $status;
            $a->save();

            return $a;
        };
        // Semaine passée
        $mk($advice, $mariam, -3, '10:00', 'Mme Yao Christelle', '0707112233', 'done');
        $mk($quote, $jm, -3, '15:30', 'Maquis Le Jardin', '0505060708', 'done', 'online');
        $mk($pickup, $mariam, -2, '11:00', 'M. Kouakou', '0102030405', 'no_show', 'online');
        $mk($tasting, $room, -1, '17:00', 'Cabinet Kouadio & Associés', '0708091011', 'done', 'manual', 'Séminaire de fin d\'année');
        // Aujourd'hui et à venir
        $mk($advice, $mariam, 0, '09:30', 'Mme Bamba Awa', '0709876543', 'confirmed', 'online', 'Mariage de 200 invités');
        $mk($pickup, $mariam, 0, '11:15', 'Restaurant Le Baobab', '0707080910', 'confirmed', 'online');
        $mk($quote, $jm, 0, '10:00', 'Hôtel Résidence Treichville', '0778787878', 'confirmed', 'manual');
        $mk($quote, $jm, 0, '16:00', 'Supérette Bonheur', '0102030405', 'confirmed', 'online');
        $mk($tasting, $room, 1, '17:00', 'Mme Diallo Fatim', '0544556677', 'pending', 'online', 'Anniversaire, 6 personnes');
        $mk($advice, $mariam, 1, '15:00', 'M. Coulibaly', '0566778899', 'confirmed', 'online');
        $mk($quote, $jm, 2, '09:30', 'Pharmacie du Plateau', '0202020202', 'confirmed', 'online');
        $mk($pickup, $mariam, 2, '12:00', 'Épicerie Yopougon Selmer', '0566778899');
        $mk($advice, $mariam, 3, '10:30', 'Mme Konan', '0707223344', 'cancelled', 'online')->forceFill(['cancel_reason' => 'Annulé par le client. Empêchement'])->saveQuietly();
        // Fermeture : jour férié de démonstration
        \App\Modules\RendezVous\Models\Closure::create(['starts_at' => $day(12)->utc()->toDateTimeString(), 'ends_at' => $day(12)->addDay()->utc()->toDateTimeString(), 'reason' => 'Jour férié']);
    }

    /** Achats : seuil d'approbation, acheteuse, magasinier, demandes d'achat, commande reçue en partie, tarifs fournisseurs. */
    protected function achats(Company $company): void
    {
        app()->instance('tenant', $company);
        $settings = $company->fresh()->settings ?? [];
        $settings['purchase_approval_limit'] = 1000000;
        $company->forceFill(['settings' => $settings])->saveQuietly();
        app()->instance('tenant', $company->fresh());
        $roles = \App\Models\Role::where('company_id', $company->id)->pluck('id', 'name');
        $nadia = User::create(['company_id' => $company->id, 'role_id' => $roles['Acheteur'] ?? null, 'name' => 'Nadia Acheteuse', 'email' => 'nadia@demo.ci', 'password' => 'password']);
        $ousmane = User::create(['company_id' => $company->id, 'role_id' => $roles['Magasinier'] ?? null, 'name' => 'Ousmane Diarra', 'email' => 'ousmane@demo.ci', 'password' => 'password']);
        $mariam = User::where('email', 'mariam@demo.ci')->first();
        $fatou = User::where('email', 'fatou@demo.ci')->first();
        $grossiste = Partner::where('name', 'Grossiste Adjamé SARL')->first();
        $solibra = Partner::create(['name' => 'Solibra Distribution', 'type' => 'supplier', 'phone' => '+225 27 21 75 00 00', 'city' => 'Treichville']);
        $bureau = Partner::create(['name' => 'Librairie de France — Pro', 'type' => 'supplier', 'city' => 'Plateau']);
        $products = Product::all()->keyBy('name');
        $SP = \App\Modules\Achats\Models\SupplierPrice::class;
        foreach ([['Bière locale 65cl', $solibra, 520, 3, true], ['Bière locale 65cl', $grossiste, 560, 1, false], ['Coca-Cola 33cl', $solibra, 300, 3, true], ['Eau minérale 1,5L', $grossiste, 260, 2, true],
            ['Riz parfumé 5kg', $grossiste, 3600, 2, true], ['Huile végétale 1L', $grossiste, 1150, 2, true]] as [$n, $sup, $price, $lead, $pref]) {
            if (isset($products[$n])) {
                $SP::create(['partner_id' => $sup->id, 'product_id' => $products[$n]->id, 'price' => $price, 'min_qty' => 24, 'lead_days' => $lead, 'preferred' => $pref]);
            }
        }
        // Inventaire de la semaine dernière (les ventes de démonstration ont entamé le stock)
        $wh = Warehouse::defaultFor($company->id);
        foreach (['Bière locale 65cl', 'Coca-Cola 33cl', 'Eau minérale 1,5L'] as $n) {
            StockMove::create(['product_id' => $products[$n]->id, 'warehouse_id' => $wh->id, 'quantity' => 75, 'type' => 'adjustment', 'reference' => 'INV-S42', 'date' => now()->subWeek()->toDateString(), 'notes' => 'Inventaire']);
        }
        $R = \App\Modules\Achats\Models\PurchaseRequest::class;
        $mk = function (array $a, string $status, ?User $who, ?User $approver = null) use ($R) {
            $r = new $R($a);
            $r->requester_id = $who?->id;
            $r->status = $status;
            $r->save();
            if ($approver) {
                $r->forceFill(['approver_id' => $approver->id, 'approved_at' => now()->subDay()])->saveQuietly();
            }

            return $r;
        };
        $mk(['title' => 'Fournitures de bureau du trimestre', 'department' => 'Marketing', 'needed_by' => now()->addDays(10)->toDateString(), 'reason' => 'Réassort habituel.',
            'lines' => [['description' => 'Ramettes papier A4 (carton de 5)', 'quantity' => 6, 'estimated_price' => 17500, 'supplier_id' => $bureau->id], ['description' => 'Cartouches d\'encre HP 305', 'quantity' => 4, 'estimated_price' => 14000, 'supplier_id' => $bureau->id]]],
            'submitted', $mariam);
        $mk(['title' => 'Deux ordinateurs portables pour les commerciaux', 'department' => 'Commercial', 'priority' => 2, 'needed_by' => now()->addDays(5)->toDateString(),
            'reason' => 'Arrivée de deux commerciaux le mois prochain.', 'lines' => [['description' => 'Ordinateur portable 15" Core i5, 16 Go', 'quantity' => 2, 'estimated_price' => 525000]]],
            'submitted', $mariam);
        $mk(['title' => 'Réassort boissons pour la fête de fin d\'année', 'department' => 'Boutique', 'priority' => 1,
            'lines' => [['product_id' => $products['Bière locale 65cl']->id ?? null, 'description' => 'Bière locale 65cl', 'quantity' => 480, 'estimated_price' => 520], ['product_id' => $products['Coca-Cola 33cl']->id ?? null, 'description' => 'Coca-Cola 33cl', 'quantity' => 240, 'estimated_price' => 300]]],
            'approved', $ousmane, $fatou);

        // Bon de commande validé, reçu en partie
        $svc = app(\App\Modules\Achats\AchatsService::class);
        [$po] = $svc->createOrders([
            ['product_id' => $products['Riz parfumé 5kg']->id, 'partner_id' => $grossiste->id, 'quantity' => 60, 'unit_price' => 3600],
            ['product_id' => $products['Huile végétale 1L']->id, 'partner_id' => $grossiste->id, 'quantity' => 96, 'unit_price' => 1150],
        ], 'Réassort épicerie');
        $po = \App\Modules\Achats\Models\PurchaseDocument::find($po->id);
        $po->due_date = now()->addDays(2)->toDateString();
        $po->save();
        $po->validateDocument();
        $lines = $po->fresh()->lines;
        $rec = new \App\Modules\Achats\Models\PurchaseReceipt(['order_id' => $po->id, 'date' => now()->toDateString(), 'supplier_reference' => 'BL-2026-1187',
            'lines' => [['order_line_id' => $lines[0]->id, 'product_id' => $lines[0]->product_id, 'description' => $lines[0]->description, 'quantity' => 40, 'unit' => $lines[0]->unit],
                ['order_line_id' => $lines[1]->id, 'product_id' => $lines[1]->product_id, 'description' => $lines[1]->description, 'quantity' => 96, 'unit' => $lines[1]->unit]]]);
        $rec->partner_id = $po->partner_id;
        $rec->user_id = $ousmane->id;
        $rec->save();
        // Bon de commande au-delà du seuil, approbation demandée
        [$big] = $svc->createOrders([['product_id' => $products['Bière locale 65cl']->id, 'partner_id' => $solibra->id, 'quantity' => 2400, 'unit_price' => 520]], 'Commande annuelle Solibra');
        $big = \App\Modules\Achats\Models\PurchaseDocument::find($big->id);
        $big->approval_requested_at = now()->subHours(3);
        $big->save();
        \App\Models\AppNotification::send($company->id, [$fatou->id, User::where('email', 'admin@demo.ci')->value('id')], 'purchase', 'Bon de commande à approuver', 'Solibra Distribution — '.number_format($big->total, 0, ',', ' ').' XOF', 'achats', $big->id);
    }

    /** Interventions : deux techniciens, contrats, parc d'équipements, semaine de dépannages et visites. */
    protected function interventions(Company $company): void
    {
        app()->instance('tenant', $company);
        $settings = $company->fresh()->settings ?? [];
        $settings['intervention_hourly_rate'] = 12000;
        $settings['intervention_travel_fee'] = 5000;
        $company->forceFill(['settings' => $settings])->saveQuietly();
        app()->instance('tenant', $company->fresh());
        $roles = \App\Models\Role::where('company_id', $company->id)->pluck('id', 'name');
        $moussa = User::create(['company_id' => $company->id, 'role_id' => $roles['Technicien'] ?? null, 'name' => 'Moussa Traoré', 'email' => 'moussa@demo.ci', 'password' => 'password', 'phone' => '+225 05 44 33 22 11']);
        $seydou = User::create(['company_id' => $company->id, 'role_id' => $roles['Technicien'] ?? null, 'name' => 'Seydou Konaté', 'email' => 'seydou@demo.ci', 'password' => 'password']);
        $wh = Warehouse::defaultFor($company->id);
        $parts = [];
        foreach ([['Filtre split universel', 6500, 3500], ['Condensateur 35 µF', 9000, 4500], ['Gaz R32 (kg)', 18000, 11000], ['Disjoncteur 32 A', 12500, 7000]] as [$n, $sp, $pp]) {
            $p = Product::create(['name' => $n, 'type' => 'product', 'sale_price' => $sp, 'purchase_price' => $pp, 'tax_rate' => 18, 'unit' => 'u', 'track_stock' => true, 'min_stock' => 3]);
            StockMove::create(['product_id' => $p->id, 'warehouse_id' => $wh->id, 'quantity' => 20, 'type' => 'initial', 'unit_cost' => $pp, 'date' => now()->subMonth()->toDateString()]);
            $parts[$n] = $p;
        }
        $C = \App\Modules\Interventions\Models\Contract::class;
        $E = \App\Modules\Interventions\Models\Equipment::class;
        $I = \App\Modules\Interventions\Models\Intervention::class;
        $hotel = Partner::where('name', 'Hôtel Lagune')->first();
        $clinic = Partner::where('name', 'Clinique Les Palmiers')->first();
        $bank = Partner::create(['name' => 'Banque Atlantique — Agence Plateau', 'type' => 'customer', 'is_company' => true, 'phone' => '+225 27 20 31 59 00', 'address' => 'Avenue Noguès', 'city' => 'Plateau']);
        $clinic?->update(['address' => 'Rue des Jardins, II Plateaux', 'phone' => '+225 27 22 41 00 00']);
        $hotel?->update(['phone' => '+225 27 22 33 44 55']);

        // Jours ouvrés uniquement (une date tombant un week-end passe au lundi)
        $wd = function (int $days) {
            $d = now()->startOfDay()->addDays($days);

            return $d->isWeekend() ? $d->next(\Carbon\Carbon::MONDAY) : $d;
        };
        $ctrHotel = $C::create(['partner_id' => $hotel->id, 'name' => 'Climatisation hôtel (32 chambres)', 'start_date' => now()->subMonths(5)->startOfMonth()->toDateString(),
            'frequency' => 'quarterly', 'next_visit' => $wd(3)->toDateString(), 'visit_minutes' => 240, 'response_hours' => 4, 'price' => 450000, 'billing' => 'quarterly',
            'labor_included' => true, 'parts_included' => false, 'technician_id' => $moussa->id]);
        $ctrClinic = $C::create(['partner_id' => $clinic->id, 'name' => 'Groupe électrogène et onduleurs', 'start_date' => now()->subMonths(2)->toDateString(),
            'frequency' => 'monthly', 'next_visit' => $wd(5)->toDateString(), 'visit_minutes' => 120, 'response_hours' => 2, 'price' => 150000, 'billing' => 'monthly',
            'labor_included' => true, 'parts_included' => true, 'technician_id' => $seydou->id]);
        $ctrClinic->forceFill(['invoiced_until' => now()->subDays(3)->toDateString()])->save();

        $eq = [];
        foreach ([[$hotel, $ctrHotel, 'Split chambre 112', 'Climatisation', 'Daikin', 'FTXM35R', 'DK35-221873', '1er étage'],
            [$hotel, $ctrHotel, 'Cassette restaurant', 'Climatisation', 'LG', 'CT24', 'LG24-55102', 'Restaurant'],
            [$hotel, $ctrHotel, 'Centrale hall d\'accueil', 'Climatisation', 'Carrier', '42QHC', 'CR-998201', 'Hall'],
            [$clinic, $ctrClinic, 'Groupe électrogène 100 kVA', 'Énergie', 'SDMO', 'J110K', 'SD-110-4471', 'Local technique'],
            [$clinic, $ctrClinic, 'Onduleur bloc opératoire', 'Énergie', 'APC', 'Smart-UPS 10 kVA', 'APC-10K-0082', 'Bloc opératoire'],
            [$bank, null, 'Split salle des coffres', 'Climatisation', 'Samsung', 'AR12', 'SM12-77810', 'RDC']] as [$p, $ctr, $n, $cat, $b, $m, $sn, $loc]) {
            $eq[$n] = $E::create(['partner_id' => $p->id, 'contract_id' => $ctr?->id, 'name' => $n, 'category' => $cat, 'brand' => $b, 'model' => $m, 'serial_number' => $sn,
                'location' => $loc, 'installed_at' => now()->subYears(2)->toDateString(), 'warranty_until' => now()->addMonths($ctr ? 6 : -3)->toDateString()]);
        }
        $at = fn (int $days, int $h, int $m = 0) => $days === 0 ? now()->startOfDay()->setTime($h, $m) : $wd($days)->setTime($h, $m);
        $mk = function (array $a) use ($I) {
            $i = new $I($a + ['notify' => false]);
            $i->status = $a['status'] ?? 'planned';
            $i->save();
            // Demande reçue avant le rendez-vous (pour des délais de prise en charge réalistes)
            if ($i->scheduled_at && $i->scheduled_at->lt(now())) {
                $i->forceFill(['created_at' => $i->scheduled_at->copy()->subMinutes(($i->priority >= 3 ? 1 : 2) * 75)])->saveQuietly();
            }

            return $i;
        };
        $sig = 'data:image/png;base64,'.base64_encode($this->signaturePng());
        // Semaine passée : terminées (dont une facturée)
        $done = function ($i, string $report, int $minutes, array $usedParts = [], ?string $who = null, int $stars = 5, int $startH = 9) use ($sig, $parts) {
            $i->update(['status' => 'in_progress', 'started_at' => $i->scheduled_at->copy()->setTime($startH, 10)]);
            $i->update(['status' => 'done', 'report' => $report, 'worked_minutes' => $minutes, 'finished_at' => $i->scheduled_at->copy()->setTime($startH, 10)->addMinutes($minutes),
                'parts' => array_map(fn ($p) => ['product_id' => $parts[$p[0]]->id, 'description' => $p[0], 'quantity' => $p[1], 'unit_price' => $parts[$p[0]]->sale_price], $usedParts),
                'signature' => $sig, 'signed_by' => $who, 'signed_at' => $i->scheduled_at->copy()->setTime($startH, 10)->addMinutes($minutes), 'satisfaction' => $stars, 'checklist' => collect($i->checklist ?? [])->map(fn ($c) => ['text' => $c['text'], 'done' => true])->all()]);

            return $i->fresh();
        };
        $i1 = $mk(['partner_id' => $bank->id, 'equipment_id' => $eq['Split salle des coffres']->id, 'type' => 'corrective', 'priority' => 3, 'title' => 'Climatisation salle des coffres en panne',
            'description' => 'Plus de froid depuis ce matin, la salle dépasse 30 °C.', 'address' => $bank->address, 'city' => $bank->city, 'contact_name' => 'M. Kouamé (sécurité)', 'contact_phone' => '0707000001',
            'scheduled_at' => $at(-4, 9), 'duration_minutes' => 120, 'technician_id' => $moussa->id]);
        $i1 = $done($i1, "Condensateur de démarrage HS remplacé, recharge de gaz R32 (0,8 kg), contrôle des pressions.\nFonctionnement rétabli : 18 °C en sortie.", 105, [['Condensateur 35 µF', 1], ['Gaz R32 (kg)', 0.8]], 'M. Kouamé', 5);
        app(\App\Modules\Interventions\InterventionService::class)->invoice($company, $i1);
        $i2 = $mk(['partner_id' => $hotel->id, 'contract_id' => $ctrHotel->id, 'equipment_id' => $eq['Cassette restaurant']->id, 'type' => 'corrective', 'priority' => 2, 'billable' => false,
            'title' => 'Cassette restaurant : fuite d\'eau', 'address' => 'Boulevard de Marseille', 'city' => 'Biétry', 'contact_name' => 'Mme Aka (gouvernante)',
            'scheduled_at' => $at(-2, 14), 'duration_minutes' => 60, 'technician_id' => $moussa->id]);
        $done($i2, 'Bac à condensats bouché : nettoyage et débouchage de l\'évacuation, test d\'écoulement OK.', 50, [['Filtre split universel', 1]], 'Mme Aka', 4, 14);
        $i3 = $mk(['partner_id' => $clinic->id, 'contract_id' => $ctrClinic->id, 'equipment_id' => $eq['Onduleur bloc opératoire']->id, 'type' => 'corrective', 'priority' => 3, 'billable' => false,
            'title' => 'Alarme onduleur bloc opératoire', 'address' => 'Rue des Jardins', 'city' => 'II Plateaux', 'scheduled_at' => $at(-1, 10), 'duration_minutes' => 90, 'technician_id' => $seydou->id]);
        $done($i3, 'Batteries en fin de vie (autonomie 4 min) : remplacement à prévoir. Réglage du seuil d\'alarme, test de bascule OK.', 80, [], 'Dr Diallo', 5, 10);
        $i3->update(['recommendations' => 'Remplacer les 20 batteries sous un mois (devis à suivre).']);
        // Aujourd'hui et cette semaine
        $mk(['partner_id' => $bank->id, 'type' => 'installation', 'priority' => 1, 'title' => 'Pose d\'un disjoncteur dédié pour la climatisation', 'address' => $bank->address, 'city' => $bank->city,
            'scheduled_at' => $at(0, 15), 'duration_minutes' => 120, 'technician_id' => $moussa->id, 'checklist' => [['text' => 'Couper l\'alimentation', 'done' => false], ['text' => 'Poser et raccorder le disjoncteur', 'done' => false], ['text' => 'Tester et étiqueter', 'done' => false]]]);
        $cur = $mk(['partner_id' => $hotel->id, 'contract_id' => $ctrHotel->id, 'equipment_id' => $eq['Split chambre 112']->id, 'type' => 'corrective', 'priority' => 2, 'billable' => false,
            'title' => 'Chambre 112 : climatiseur bruyant', 'address' => 'Boulevard de Marseille', 'city' => 'Biétry', 'scheduled_at' => $at(0, 9, 30), 'duration_minutes' => 60, 'technician_id' => $moussa->id]);
        $cur->update(['status' => 'in_progress', 'started_at' => $at(0, 9, 40)]);
        $mk(['partner_id' => $clinic->id, 'type' => 'other', 'priority' => 1, 'title' => 'Audit électrique annuel', 'address' => 'Rue des Jardins', 'city' => 'II Plateaux',
            'scheduled_at' => $at(1, 8, 30), 'duration_minutes' => 240, 'technician_id' => $seydou->id]);
        // Visites des contrats (générées comme chaque matin)
        app(\App\Modules\Interventions\InterventionService::class)->generateVisits($company, 7);
        // Demandes à planifier
        $mk(['partner_id' => $bank->id, 'type' => 'corrective', 'priority' => 1, 'title' => 'Éclairage du parking à vérifier', 'source' => 'manual', 'status' => 'requested', 'address' => $bank->address, 'city' => $bank->city]);
        if ($hotel) {
            app(\App\Modules\Interventions\InterventionService::class)->request($company, $hotel, ['title' => 'Chambre 205 : la télécommande ne répond plus', 'description' => 'Client en chambre jusqu\'à vendredi.', 'priority' => 2, 'equipment_id' => $eq['Split chambre 112']->id]);
        }
    }

    /** Portail client : deux clients invités, un devis à accepter, un devis accepté en ligne, un message. */
    protected function portail(Company $company): void
    {
        app()->instance('tenant', $company);
        $svc = app(\App\Modules\Portail\PortalService::class);
        $products = Product::all()->keyBy('name');
        $quote = function (Partner $p, array $lines, int $ago, int $valid = 30) use ($products) {
            $d = new \App\Modules\Ventes\Models\SalesDocument;
            $d->fill(['type' => 'quote', 'partner_id' => $p->id, 'partner_name' => $p->name, 'date' => now()->subDays($ago)->toDateString(), 'due_date' => now()->addDays($valid - $ago)->toDateString()]);
            $d->save();
            $d->replaceLines(array_map(fn ($l) => ['product_id' => $products[$l[0]]->id ?? null, 'description' => $l[0], 'quantity' => $l[1], 'unit_price' => $l[2] ?? ($products[$l[0]]->sale_price ?? 5000), 'tax_rate' => 18], $lines));
            $d->validateDocument();

            return $d->fresh();
        };
        $hotel = Partner::where('name', 'Hôtel Lagune')->first();
        $tantie = Partner::where('name', 'Maquis Chez Tantie')->orderBy('created_at')->first();
        if (! $hotel || ! $tantie) {
            return;
        }
        $hotel->update(['address' => 'Boulevard de Marseille, Biétry', 'tax_id' => 'CI-ABJ-2019-B-12345']);
        $q1 = $quote($hotel, [['Eau minérale 1,5L', 240], ['Coca-Cola 33cl', 192], ['Bière locale 65cl', 120]], 2);
        $q2 = $quote($tantie, [['Bière locale 65cl', 96], ['Coca-Cola 33cl', 48]], 5);
        foreach ([$hotel, $tantie] as $p) {
            $a = $svc->access($p);
            $a->forceFill(['invited_at' => now()->subDays(6), 'invited_via' => $p->email ? 'email' : 'whatsapp', 'last_login_at' => now()->subHours(3), 'login_count' => 4])->save();
            (new \App\Modules\Portail\Models\PortalEvent)->forceFill(['partner_id' => $p->id, 'type' => 'invite', 'data' => ['via' => $a->invited_via], 'read_at' => now(), 'created_at' => now()->subDays(6)])->save();
            (new \App\Modules\Portail\Models\PortalEvent)->forceFill(['partner_id' => $p->id, 'type' => 'login', 'read_at' => now(), 'created_at' => now()->subHours(3)])->save();
        }
        $svc->acceptQuote($company, $tantie, $q2, 'Tantie Rose, gérante');
        $svc->message($company, $hotel, 'Devis boissons séminaire', "Bonjour, pouvez-vous livrer le devis {$q1->number} jeudi avant 10 h ? Nous accueillons un séminaire de 120 personnes.", $q1->id);
    }

    /** Logistique : zones, véhicules, deux livreurs, tournées du jour, livraisons dans tous les états. */
    protected function logistique(Company $company): void
    {
        app()->instance('tenant', $company);
        $roles = \App\Models\Role::where('company_id', $company->id)->pluck('id', 'name');
        $ib = User::create(['company_id' => $company->id, 'role_id' => $roles['Livreur'] ?? null, 'name' => 'Ibrahim Coulibaly', 'email' => 'ibrahim@demo.ci', 'password' => 'password', 'phone' => '+225 07 11 22 33 44']);
        $ser = User::create(['company_id' => $company->id, 'role_id' => $roles['Livreur'] ?? null, 'name' => 'Serge N\'Guessan', 'email' => 'serge@demo.ci', 'password' => 'password']);
        $Z = \App\Modules\Logistique\Models\Zone::class;
        $V = \App\Modules\Logistique\Models\Vehicle::class;
        $T = \App\Modules\Logistique\Models\Tour::class;
        $D = \App\Modules\Logistique\Models\Delivery::class;
        $z = [
            'coc' => $Z::create(['name' => 'Cocody', 'cities' => 'Cocody, Angré, Riviera, II Plateaux', 'fee' => 1500, 'delay_days' => 0, 'color' => '#1F6FEB']),
            'pla' => $Z::create(['name' => 'Plateau', 'cities' => 'Plateau, Adjamé', 'fee' => 1000, 'delay_days' => 0, 'color' => '#12A150']),
            'yop' => $Z::create(['name' => 'Yopougon', 'cities' => 'Yopougon, Songon', 'fee' => 2000, 'delay_days' => 1, 'color' => '#E8A317']),
            'mar' => $Z::create(['name' => 'Marcory / Koumassi', 'cities' => 'Marcory, Koumassi, Treichville, Zone 4', 'fee' => 1500, 'delay_days' => 1, 'color' => '#D93F3F']),
            'bas' => $Z::create(['name' => 'Grand-Bassam', 'cities' => 'Grand-Bassam, Bingerville', 'fee' => 5000, 'delay_days' => 2, 'color' => '#7A4FD6']),
        ];
        $moto = $V::create(['name' => 'Moto 1', 'type' => 'moto', 'plate' => '1234 GH 01', 'capacity_kg' => 80]);
        $van = $V::create(['name' => 'Camionnette', 'type' => 'camionnette', 'plate' => '5678 FK 01', 'capacity_kg' => 1200]);
        $V::create(['name' => 'Tricycle', 'type' => 'tricycle', 'capacity_kg' => 400]);
        $today = now()->toDateString();
        $t1 = $T::create(['name' => 'Cocody matin', 'date' => $today, 'driver_id' => $ib->id, 'vehicle_id' => $moto->id]);
        $t2 = $T::create(['name' => 'Sud — camionnette', 'date' => $today, 'driver_id' => $ser->id, 'vehicle_id' => $van->id]);

        $products = Product::all()->keyBy('name');
        $invoice = function (string $name, string $phone, string $city, array $lines) use ($products) {
            $p = Partner::create(['name' => $name, 'type' => 'customer', 'phone' => $phone, 'city' => $city]);
            $d = new \App\Modules\Ventes\Models\SalesDocument;
            $d->fill(['type' => 'invoice', 'partner_id' => $p->id, 'partner_name' => $p->name, 'date' => now()->toDateString()]);
            $d->save();
            $d->replaceLines(array_map(fn ($l) => ['product_id' => $products[$l[0]]->id ?? null, 'description' => $l[0], 'quantity' => $l[1], 'unit_price' => $products[$l[0]]->sale_price ?? 5000, 'tax_rate' => 18], $lines));
            $d->validateDocument();

            return [$p, $d->fresh()];
        };
        $sig = 'data:image/png;base64,'.base64_encode($this->signaturePng());
        $rows = [
            // [client, tél, commune, adresse, zone, lignes, tournée, rang, statut, extra]
            ['Restaurant Le Baobab', '0707080910', 'Cocody', 'Angré 8e tranche, face pharmacie Sainte-Cécile', 'coc', [['Bière locale 65cl', 24], ['Coca-Cola 33cl', 24]], $t1, 0, 'delivered', ['signed_by' => 'Aminata (caissière)', 'cod_method' => 'mobile_money']],
            ['Maquis Le Jardin', '0505060708', 'Cocody', 'Riviera 2, carrefour Mel Théodore', 'coc', [['Eau minérale 1,5L', 12], ['Riz parfumé 5kg', 2]], $t1, 1, 'delivered', ['signed_by' => 'Mme Yao', 'cod_method' => 'cash']],
            ['Supérette Bonheur', '0102030405', 'Cocody', 'II Plateaux Vallon, rue des Jardins', 'coc', [['Huile végétale 1L', 12], ['Riz parfumé 5kg', 5]], $t1, 2, 'in_transit', []],
            ['Cabinet Kouadio & Associés', '0708091011', 'Cocody', 'Riviera Palmeraie, immeuble Les Cocotiers, 3e étage', 'coc', [['Eau minérale 1,5L', 24]], $t1, 3, 'planned', ['time_slot' => 'Après-midi (14 h – 18 h)']],
            ['Boutique Marcory Centre', '0101010203', 'Marcory', 'Marcory Zone 4, rue Paul Langevin', 'mar', [['Bière locale 65cl', 48], ['Coca-Cola 33cl', 48]], $t2, 0, 'delivered', ['signed_by' => 'M. Diallo', 'cod_method' => 'cash']],
            ['Alimentation Koumassi Remblais', '0545454545', 'Koumassi', 'Koumassi Remblais, près du marché', 'mar', [['Riz parfumé 5kg', 20], ['Huile végétale 1L', 24]], $t2, 1, 'failed', ['failed_reason' => 'Client absent — magasin fermé']],
            ['Hôtel Résidence Treichville', '0778787878', 'Treichville', 'Avenue 16, rue 12', 'mar', [['Eau minérale 1,5L', 48]], $t2, 2, 'planned', []],
            ['Épicerie Yopougon Selmer', '0566778899', 'Yopougon', 'Selmer, derrière l\'église Saint-André', 'yop', [['Riz parfumé 5kg', 10]], null, 0, 'planned', []],
            ['Mme Bamba', '0709876543', 'Grand-Bassam', 'Quartier France, villa bleue après le pont', 'bas', [['Coca-Cola 33cl', 12], ['Eau minérale 1,5L', 6]], null, 0, 'planned', ['notes' => 'Appeler 30 min avant']],
            ['Pharmacie du Plateau', '0202020202', 'Plateau', 'Avenue Chardy, immeuble Alpha 2000', 'pla', [['Eau minérale 1,5L', 12]], null, 0, 'planned', ['time_slot' => 'Matin (8 h – 12 h)']],
        ];
        foreach ($rows as [$name, $phone, $city, $addr, $zk, $lines, $tour, $seq, $status, $extra]) {
            [$p, $inv] = $invoice($name, $phone, $city, $lines);
            $d = $D::create([
                'source_document_id' => $inv->id, 'partner_id' => $p->id, 'recipient_name' => $name, 'recipient_phone' => $phone, 'address' => $addr, 'city' => $city,
                'zone_id' => $z[$zk]->id, 'scheduled_date' => $tour ? $today : now()->addDays($z[$zk]->delay_days)->toDateString(), 'status' => 'planned',
                'driver_id' => $tour?->driver_id, 'tour_id' => $tour?->id, 'tour_seq' => $seq, 'fee' => $z[$zk]->fee, 'cod_amount' => $inv->total,
                'items' => array_map(fn ($l) => ['description' => $l[0], 'quantity' => $l[1]], $lines), 'packages' => count($lines), 'notify' => false,
            ] + array_intersect_key($extra, array_flip(['time_slot', 'notes'])));
            if ($status === 'planned') {
                continue;
            }
            $d->update(['status' => 'in_transit']);
            if ($status === 'delivered') {
                $d->update(['status' => 'delivered', 'signed_by' => $extra['signed_by'], 'signature' => $sig, 'cod_collected' => $inv->total, 'cod_method' => $extra['cod_method'],
                    'delivered_at' => now()->setTime(9 + $seq, 15 + 10 * $seq)]);
            } elseif ($status === 'failed') {
                $d->update(['status' => 'failed', 'failed_reason' => $extra['failed_reason']]);
            }
        }
        // Historique : une semaine de livraisons passées pour les statistiques
        foreach (range(1, 6) as $ago) {
            foreach (range(1, 3 + $ago % 3) as $i) {
                $ok = ($ago + $i) % 7 !== 0;
                $D::create(['recipient_name' => 'Client '.chr(64 + $i).$ago, 'city' => ['Cocody', 'Plateau', 'Marcory', 'Yopougon'][$i % 4], 'zone_id' => array_values($z)[$i % 4]->id,
                    'scheduled_date' => now()->subDays($ago)->toDateString(), 'status' => $ok ? 'delivered' : 'returned', 'driver_id' => $i % 2 ? $ib->id : $ser->id,
                    'fee' => 1500, 'cod_amount' => 0, 'delivered_at' => $ok ? now()->subDays($ago - ($i === 3 ? 1 : 0))->setTime(10 + $i, 0) : null,
                    'failed_reason' => $ok ? null : 'Adresse introuvable', 'signed_by' => $ok ? 'Client' : null, 'notify' => false]);
            }
        }
        // Encaissements d'hier déjà remis à la caisse
        $D::where('status', 'delivered')->whereDate('scheduled_date', '<', $today)->update(['cod_remitted_at' => now()->subDay()]);
    }

    /** Petite signature manuscrite (PNG) pour les preuves de livraison de démonstration. */
    protected function signaturePng(): string
    {
        $w = 360;
        $h = 120;
        if (! function_exists('imagecreatetruecolor')) {
            return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        }
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        $ink = imagecolorallocate($im, 11, 27, 58);
        imagesetthickness($im, 3);
        $prev = null;
        for ($x = 30; $x <= 320; $x += 3) {
            $y = (int) (60 + 28 * sin($x / 18) * cos($x / 47) + 10 * sin($x / 7));
            if ($prev) {
                imageline($im, $prev[0], $prev[1], $x, $y, $ink);
            }
            $prev = [$x, $y];
        }
        imageline($im, 60, 98, 300, 92, $ink);
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    /** Projets : un site web client au temps passé, une installation au forfait, un projet interne. */
    protected function projets(Company $company): void
    {
        app()->instance('tenant', $company);
        $u = User::where('company_id', $company->id)->pluck('id', 'email');
        $admin = $u['admin@demo.ci'];
        [$koffi, $awa, $yao, $mariam, $jm] = [$u['koffi@demo.ci'], $u['awa@demo.ci'], $u['yao@demo.ci'], $u['mariam@demo.ci'], $u['jm@demo.ci']];
        $P = \App\Modules\Projets\Models\Project::class;
        $T = \App\Modules\Projets\Models\Task::class;
        $M = \App\Modules\Projets\Models\Milestone::class;
        $S = \App\Modules\Projets\Models\Timesheet::class;
        $d = fn (int $days) => now()->addDays($days)->toDateString();
        $hotel = Partner::create(['name' => 'Hôtel Lagune', 'type' => 'customer', 'email' => 'direction@hotellagune.ci', 'phone' => '+225 27 22 33 44 55', 'city' => 'Abidjan', 'is_company' => true]);
        $clinique = Partner::create(['name' => 'Clinique Les Palmiers', 'type' => 'customer', 'email' => 'contact@palmiers.ci', 'city' => 'Abidjan', 'is_company' => true]);

        $site = $P::create(['name' => 'Site web & réservation — Hôtel Lagune', 'code' => 'LAG-26', 'partner_id' => $hotel->id, 'manager_id' => $mariam, 'status' => 'active',
            'start_date' => $d(-21), 'end_date' => $d(18), 'billing_mode' => 'time', 'hourly_rate' => 15000, 'budget_hours' => 80, 'color' => '#1F6FEB',
            'member_ids' => [$koffi, $awa, $jm], 'description' => 'Refonte du site, moteur de réservation et paiement Mobile Money.']);
        $m1 = $M::create(['project_id' => $site->id, 'name' => 'Maquettes validées', 'due_date' => $d(-7), 'amount' => 0, 'status' => 'reached']);
        $m2 = $M::create(['project_id' => $site->id, 'name' => 'Mise en ligne', 'due_date' => $d(18), 'amount' => 0, 'status' => 'open']);
        $tasks = [
            ['Ateliers de cadrage avec la direction', 'done', $mariam, -21, -18, 6, $m1, 2],
            ['Maquettes accueil et chambres', 'done', $awa, -17, -8, 14, $m1, 3],
            ['Charte graphique et photos', 'done', $awa, -12, -9, 6, $m1, 1],
            ['Intégration des pages', 'doing', $koffi, -6, 4, 20, $m2, 2],
            ['Moteur de réservation', 'doing', $koffi, -3, 9, 24, $m2, 3],
            ['Paiement Mobile Money (CinetPay)', 'todo', $jm, 3, 12, 8, $m2, 2],
            ['Traductions anglais', 'todo', $awa, 8, 14, 6, $m2, 0],
            ['Recette avec le client', 'todo', $mariam, 13, 16, 4, $m2, 1],
            ['Formation du personnel', 'todo', $mariam, 16, 18, 3, $m2, 0],
            ['Textes des chambres', 'review', $jm, -5, -1, 4, $m2, 1],
        ];
        $ids = [];
        foreach ($tasks as $i => [$title, $st, $who, $from, $to, $est, $ms, $prio]) {
            $t = $T::create(['project_id' => $site->id, 'title' => $title, 'status' => $st, 'assignee_id' => $who, 'start_date' => $d($from), 'due_date' => $d($to),
                'estimate_hours' => $est, 'milestone_id' => $ms->id, 'priority' => $prio, 'sort' => $i,
                'checklist' => $i === 4 ? [['text' => 'Disponibilités par type de chambre', 'done' => true], ['text' => 'Tarifs saisonniers', 'done' => true], ['text' => 'Confirmation par e-mail', 'done' => false], ['text' => 'Annulation', 'done' => false]] : null,
                'blocked_reason' => $i === 9 ? 'En attente des descriptifs du client' : null]);
            $ids[$i] = $t;
        }
        // Temps passés sur les 3 dernières semaines
        $log = function ($task, int $user, int $day, float $h, string $desc, bool $billable = true) use ($S, $site, $d) {
            $S::create(['project_id' => $site->id, 'task_id' => $task?->id, 'user_id' => $user, 'date' => $d($day), 'hours' => $h, 'description' => $desc, 'billable' => $billable]);
        };
        $log($ids[0], $mariam, -21, 3, 'Atelier direction');
        $log($ids[0], $mariam, -19, 2.5, 'Compte rendu et arborescence');
        foreach ([-17, -16, -15, -12, -10, -9] as $k => $day) {
            $log($ids[1], $awa, $day, [2.5, 3, 2, 3, 2.5, 1.5][$k], 'Maquettes');
        }
        $log($ids[2], $awa, -11, 3, 'Charte graphique');
        $log($ids[2], $awa, -9, 2, 'Sélection photos');
        foreach ([-6, -5, -4, -3, -2, -1, 0] as $k => $day) {
            $log($ids[3], $koffi, $day, [4, 3.5, 4, 2, 3, 2.5, 1.5][$k], 'Intégration');
        }
        $log($ids[4], $koffi, -2, 2, 'Calendrier des disponibilités');
        $log($ids[4], $koffi, -1, 3, 'Tarifs');
        $log($ids[9], $jm, -3, 2, 'Rédaction');
        $log(null, $mariam, -7, 1, 'Point d\'avancement interne', false);
        $log(null, $mariam, 0, 1.5, 'Point hebdomadaire avec le client');

        $clin = $P::create(['name' => 'Installation réseau — Clinique Les Palmiers', 'code' => 'PAL-26', 'partner_id' => $clinique->id, 'manager_id' => $koffi, 'status' => 'active',
            'start_date' => $d(-10), 'end_date' => $d(25), 'billing_mode' => 'fixed', 'budget_amount' => 2400000, 'budget_hours' => 60, 'color' => '#12A150', 'member_ids' => [$yao]]);
        $c1 = $M::create(['project_id' => $clin->id, 'name' => 'Audit et devis matériel', 'due_date' => $d(-3), 'amount' => 600000, 'status' => 'reached']);
        $c2 = $M::create(['project_id' => $clin->id, 'name' => 'Câblage et baies', 'due_date' => $d(12), 'amount' => 1200000, 'status' => 'open']);
        $c3 = $M::create(['project_id' => $clin->id, 'name' => 'Recette et formation', 'due_date' => $d(25), 'amount' => 600000, 'status' => 'open']);
        foreach ([['Audit des locaux', 'done', $koffi, -10, -8, 8, $c1], ['Devis matériel', 'done', $koffi, -8, -4, 4, $c1], ['Commande du matériel', 'doing', $yao, -3, 2, 2, $c2],
            ['Câblage étage 1', 'todo', $yao, 3, 8, 16, $c2], ['Câblage étage 2', 'todo', $yao, 8, 12, 16, $c2], ['Configuration Wi-Fi', 'todo', $koffi, 13, 18, 8, $c3], ['Formation du personnel', 'todo', $koffi, 22, 25, 4, $c3]] as $i => [$title, $st, $who, $from, $to, $est, $ms]) {
            $t = $T::create(['project_id' => $clin->id, 'title' => $title, 'status' => $st, 'assignee_id' => $who, 'start_date' => $d($from), 'due_date' => $d($to), 'estimate_hours' => $est, 'milestone_id' => $ms->id, 'sort' => $i]);
            if ($st === 'done') {
                $S::create(['project_id' => $clin->id, 'task_id' => $t->id, 'user_id' => $who, 'date' => $d($to), 'hours' => $est + 1, 'description' => $title]);
            }
        }

        $int = $P::create(['name' => 'Inventaire annuel des magasins', 'manager_id' => $admin, 'status' => 'planned', 'start_date' => $d(30), 'end_date' => $d(34), 'billing_mode' => 'none', 'color' => '#E8A317', 'member_ids' => [$yao, $jm]]);
        foreach (['Préparer les feuilles de comptage', 'Comptage Boutique Cocody', 'Rapprochement des écarts'] as $i => $title) {
            $T::create(['project_id' => $int->id, 'title' => $title, 'status' => 'todo', 'assignee_id' => $i === 0 ? $admin : $yao, 'due_date' => $d(30 + $i * 2), 'sort' => $i]);
        }
    }

    /** Marketing : contacts, listes, une campagne envoyée avec statistiques, un brouillon. */
    protected function marketing(Company $company): void
    {
        app()->instance('tenant', $company);
        app(\App\Modules\Marketing\MarketingSetup::class)($company);
        $settings = $company->fresh()->settings ?? [];
        $settings['_setup']['marketing'] = 1;
        $company->forceFill(['settings' => $settings])->saveQuietly();

        $C = \App\Modules\Marketing\Models\Contact::class;
        $L = \App\Modules\Marketing\Models\MkList::class;
        $clients = $L::where('name', 'Clients')->first();
        $vip = $L::create(['name' => 'Clients VIP', 'description' => 'Meilleurs clients (programme fidélité)']);
        $pros = $L::create(['name' => 'Prospects salon PME', 'description' => 'Cartes de visite du salon des PME']);
        $people = [
            ['Awa', 'Koné', 'Boutique Awa', 'awa.kone@exemple.ci', '+225 07 07 11 22 33'], ['Ibrahim', 'Traoré', 'Traoré & Fils', 'ibrahim@traore-fils.ci', '+225 05 44 55 66 77'],
            ['Christelle', 'N\'Guessan', 'Pharmacie du Plateau', 'c.nguessan@pharmaplateau.ci', '+225 01 02 03 04 05'], ['Moussa', 'Diallo', null, 'moussa.diallo@exemple.ci', '+225 07 98 76 54 32'],
            ['Aïcha', 'Ouattara', 'Hôtel Lagune', 'aicha@hotellagune.ci', '+225 27 22 33 44 55'], ['Serge', 'Kouadio', 'BTP Kouadio', 'serge@btpkouadio.ci', '+225 05 11 22 33 44'],
            ['Nadia', 'Yao', 'Salon Nadia Beauté', 'nadia@nadiabeaute.ci', '+225 07 66 77 88 99'], ['Eric', 'Gbagbo', 'Imprimerie Eric', 'contact@imprimerie-eric.ci', '+225 01 23 45 67 89'],
            ['Fanta', 'Camara', 'Restaurant Le Baobab', 'fanta@lebaobab.ci', '+225 05 98 87 76 65'], ['Hervé', 'Assi', 'Garage Assi Auto', 'herve@assiauto.ci', '+225 07 12 34 56 78'],
            ['Rokia', 'Sangaré', 'Clinique Les Palmiers', 'r.sangare@palmiers.ci', '+225 27 21 00 11 22'], ['Didier', 'Brou', 'Quincaillerie Brou', 'didier@quincabrou.ci', '+225 05 55 44 33 22'],
            ['Mariette', 'Aké', 'École Les Lauréats', 'direction@laureats.ci', '+225 07 45 45 45 45'], ['Lassina', 'Coulibaly', 'Transport Coulibaly', 'lassina@coultrans.ci', '+225 01 77 66 55 44'],
        ];
        $contacts = [];
        foreach ($people as $i => [$f, $l, $co, $e, $ph]) {
            $c = $C::create(['first_name' => $f, 'last_name' => $l, 'company_name' => $co, 'email' => $e, 'phone' => $ph, 'source' => $i < 10 ? 'partner' : 'import', 'tags' => $i % 4 === 0 ? 'vip, abidjan' : ($i % 3 ? 'abidjan' : 'bouaké'),
                'city' => $i % 3 ? 'Abidjan' : 'Bouaké', 'birthday' => now()->subYears(25 + $i)->addDays($i * 23 - 40)->format('Y-m-d'), 'opt_in' => 'confirmed', 'consent_at' => now()->subMonths(2)]);
            $c->lists()->attach($i < 10 ? [$clients->id] : [$pros->id]);
            if ($i % 4 === 0) {
                $c->lists()->attach($vip->id);
            }
            $contacts[] = $c;
        }
        $contacts[7]->update(['unsubscribed_email_at' => now()->subDays(3)]);

        $promo = \App\Modules\Marketing\EmailLayouts::find('promo');
        $sent = \App\Modules\Marketing\Models\Campaign::create([
            'name' => 'Promotion de rentrée', 'kind' => 'promotion', 'channel' => 'email', 'subject' => '{{prenom}}, -30 % ce week-end seulement',
            'preheader' => 'Code promo à l\'intérieur', 'blocks' => $promo['blocks'], 'style' => $promo['style'], 'list_ids' => [$clients->id],
            'ab' => ['enabled' => true, 'subject_b' => 'Dernière chance : -30 % jusqu\'à dimanche', 'percent' => 20, 'wait_hours' => 4, 'metric' => 'open', 'status' => 'done', 'winner' => 'B', 'rate_a' => 0, 'rate_b' => 100],
            'status' => 'sent', 'started_at' => now()->subDays(6)->setTime(9, 0), 'sent_at' => now()->subDays(6)->setTime(9, 4), 'user_id' => User::where('email', 'admin@demo.ci')->value('id'),
        ]);
        foreach (array_slice($contacts, 0, 10) as $i => $c) {
            $base = now()->subDays(6)->setTime(9, 1);
            $m = \App\Modules\Marketing\Models\CampaignMessage::create([
                'campaign_id' => $sent->id, 'contact_id' => $c->id, 'channel' => 'email', 'to' => $c->email, 'token' => \Illuminate\Support\Str::random(32), 'variant' => in_array($i, [3, 7], true) ? 'A' : 'B',
                'status' => $i === 9 ? 'failed' : 'sent', 'error' => $i === 9 ? '550 Mailbox unavailable' : null, 'sent_at' => $i === 9 ? null : $base,
                'opened_at' => in_array($i, [0, 1, 2, 4, 5, 6], true) ? $base->copy()->addMinutes(20 + $i * 13) : null, 'opens' => in_array($i, [0, 1, 2, 4, 5, 6], true) ? 1 + $i % 3 : 0,
                'clicked_at' => in_array($i, [0, 2, 5], true) ? $base->copy()->addMinutes(40 + $i * 9) : null, 'clicks' => in_array($i, [0, 2, 5], true) ? 1 : 0,
                'unsubscribed_at' => $i === 7 ? now()->subDays(3) : null,
            ]);
            foreach (in_array($i, [0, 2, 5], true) ? ['https://demo.ci/promo', 'https://demo.ci/promo', 'https://demo.ci/baskets'] : (in_array($i, [1, 4], true) ? ['https://demo.ci/sac'] : []) as $k => $url) {
                if ($k < 1 + $i % 3) {
                    \App\Modules\Marketing\Models\Click::create(['company_id' => $company->id, 'message_id' => $m->id, 'campaign_id' => $sent->id, 'url' => $url]);
                }
            }
            $c->forceFill(['score' => ($m->opened_at ? 1 : 0) + ($m->clicked_at ? 3 : 0) + ($i % 4 === 0 ? 5 : 0), 'last_activity_at' => $m->opened_at])->saveQuietly();
        }
        $sent->refreshStats();

        $news = \App\Modules\Marketing\EmailLayouts::find('newsletter');
        \App\Modules\Marketing\Models\Campaign::create([
            'name' => 'Newsletter de novembre', 'kind' => 'newsletter', 'channel' => 'email', 'subject' => $news['subject'], 'preheader' => $news['preheader'],
            'blocks' => $news['blocks'], 'style' => $news['style'], 'list_ids' => [$clients->id, $pros->id], 'status' => 'draft',
        ]);
        $prop = \App\Modules\Marketing\EmailLayouts::find('proposal');
        \App\Modules\Marketing\Models\Campaign::create([
            'name' => 'Proposition — contrat de maintenance', 'kind' => 'proposal', 'channel' => 'email', 'subject' => $prop['subject'], 'preheader' => $prop['preheader'],
            'blocks' => $prop['blocks'], 'style' => $prop['style'], 'list_ids' => [$pros->id], 'status' => 'draft',
        ]);
        \App\Modules\Marketing\Models\Campaign::create([
            'name' => 'SMS — soldes de fin d\'année', 'kind' => 'promotion', 'channel' => 'sms', 'text' => '{{entreprise}} : soldes de fin d\'annee, jusqu\'a -30% du 20 au 31 decembre. A tres vite !',
            'list_ids' => [$clients->id, $vip->id], 'status' => 'scheduled', 'scheduled_at' => now()->addDays(20)->setTime(10, 0),
        ]);

        \App\Modules\Marketing\Models\Campaign::create([
            'name' => 'WhatsApp — arrivage de la semaine', 'kind' => 'newsletter', 'channel' => 'whatsapp',
            'text' => "Bonjour {{prenom}}\nNouvel arrivage chez {{entreprise}} : riz parfumé, huile végétale et boissons à prix promo jusqu'à samedi.\nRépondez *COMMANDE* pour être livré(e) à Cocody ou au Plateau.",
            'list_ids' => [$vip->id], 'status' => 'draft',
        ]);

        // Segments, page d'inscription, automatisations
        \App\Modules\Marketing\Models\Segment::create(['name' => 'VIP d\'Abidjan', 'match' => 'all', 'conditions' => [['field' => 'tag', 'value' => 'vip'], ['field' => 'city', 'value' => 'Abidjan']]]);
        \App\Modules\Marketing\Models\Segment::create(['name' => 'Engagés (score ≥ 4)', 'match' => 'all', 'conditions' => [['field' => 'score_min', 'value' => 4]]]);
        \App\Modules\Marketing\Models\Segment::create(['name' => 'Anniversaire ce mois-ci', 'match' => 'all', 'conditions' => [['field' => 'birthday_month', 'value' => now()->month]]]);
        $club = $L::create(['name' => 'Club fidélité', 'description' => 'Inscrits par la page « Club fidélité »']);
        $welcome = \App\Modules\Marketing\EmailLayouts::find('welcome');
        $form = \App\Modules\Marketing\Models\Form::create([
            'kind' => 'page', 'name' => 'Club fidélité', 'slug' => 'club-fidelite', 'list_id' => $club->id, 'title' => 'Rejoignez le club', 'description' => 'Offres exclusives, cadeau d\'anniversaire et -10 % sur votre prochain achat.',
            'fields' => [['key' => 'first_name', 'required' => true], ['key' => 'email', 'required' => true], ['key' => 'phone'], ['key' => 'birthday']],
            'button_label' => 'Je rejoins le club', 'double_optin' => true, 'consent_text' => 'J\'accepte de recevoir les offres de Démo SARL et je peux me désinscrire à tout moment.',
            'success_message' => 'Bienvenue au club ! Votre code -10 % arrive par e-mail.', 'blocks' => [
                ['type' => 'hero', 'image' => $welcome['blocks'][0]['image'], 'title' => 'Le Club fidélité Démo SARL', 'text' => 'Gratuit, sans engagement, et plein d\'avantages.', 'bg_color' => '#12A150'],
                ['type' => 'columns3', 'cols' => [['title' => '-10 %', 'text' => 'sur votre prochain achat'], ['title' => 'Cadeau', 'text' => 'le jour de votre anniversaire'], ['title' => 'En avant-première', 'text' => 'nos arrivages et promotions']]],
            ], 'style' => ['primary' => '#12A150'],
        ]);
        $form->forceFill(['views' => 248, 'submissions' => 37])->saveQuietly();
        $tplWelcome = \App\Modules\Marketing\Models\Template::create(['name' => 'Bienvenue au club', 'channel' => 'email', 'subject' => 'Bienvenue au club, {{prenom}} !', 'preheader' => $welcome['preheader'], 'blocks' => $welcome['blocks'], 'style' => $welcome['style']]);
        $birthday = \App\Modules\Marketing\EmailLayouts::find('birthday');
        $tplBirthday = \App\Modules\Marketing\Models\Template::create(['name' => 'Joyeux anniversaire', 'channel' => 'email', 'subject' => $birthday['subject'], 'preheader' => $birthday['preheader'], 'blocks' => $birthday['blocks'], 'style' => $birthday['style']]);
        $auto = \App\Modules\Marketing\Models\Automation::create(['name' => 'Bienvenue au club', 'trigger' => 'form', 'trigger_config' => ['form_id' => $form->id], 'status' => 'active', 'steps' => [
            ['id' => 'w1', 'type' => 'email', 'config' => ['template_id' => $tplWelcome->id]],
            ['id' => 'w2', 'type' => 'wait', 'config' => ['amount' => 3, 'unit' => 'days']],
            ['id' => 'w3', 'type' => 'condition', 'config' => ['check' => 'opened'], 'yes' => [['id' => 'w4', 'type' => 'add_tag', 'config' => ['tag' => 'engagé']]], 'no' => [
                ['id' => 'w5', 'type' => 'whatsapp', 'config' => ['text' => "Bonjour {{prenom}} votre code -10 % du club : CLUB10. À très vite chez {{entreprise}} !"]],
            ]],
            ['id' => 'w6', 'type' => 'notify', 'config' => ['message' => '{{nom_complet}} a terminé la série de bienvenue.']],
        ]]);
        \App\Modules\Marketing\Models\Automation::create(['name' => 'Anniversaires', 'trigger' => 'birthday', 'trigger_config' => ['days_before' => 0], 'status' => 'active', 'steps' => [
            ['id' => 'b1', 'type' => 'email', 'config' => ['template_id' => $tplBirthday->id]],
            ['id' => 'b2', 'type' => 'condition', 'config' => ['check' => 'has_phone'], 'yes' => [['id' => 'b3', 'type' => 'whatsapp', 'config' => ['text' => "Joyeux anniversaire {{prenom}} 🎉 -20 % pendant 7 jours avec le code ANNIV20."]]], 'no' => []],
        ]]);
        foreach (array_slice($contacts, 10, 4) as $k => $c) {
            $c->lists()->attach($club->id);
            $c->forceFill(['source' => 'form'])->saveQuietly();
            $run = \App\Modules\Marketing\Models\AutomationRun::create(['automation_id' => $auto->id, 'contact_id' => $c->id, 'cursor' => $k < 2 ? '2' : '4', 'status' => $k < 2 ? 'active' : 'done',
                'next_run_at' => $k < 2 ? now()->addDays(2) : null, 'history' => [['at' => now()->subDays(1 + $k)->toIso8601String(), 'step' => 'email', 'result' => 'envoyé'], ['at' => now()->subDays(1 + $k)->toIso8601String(), 'step' => 'wait', 'result' => 'attente']]]);
            \App\Modules\Marketing\Models\CampaignMessage::create(['automation_id' => $auto->id, 'step_id' => 'w1', 'contact_id' => $c->id, 'channel' => 'email', 'to' => $c->email, 'token' => \Illuminate\Support\Str::random(32),
                'status' => 'sent', 'sent_at' => now()->subDays(1 + $k), 'opened_at' => $k % 2 ? null : now()->subDays($k), 'opens' => $k % 2 ? 0 : 1]);
        }
        $auto->refreshStats();

        // Canal e-mail de démonstration (serveur fictif : les envois échoueront tant qu'il n'est pas remplacé)
        \App\Models\MessagingChannel::create(['channel' => 'email', 'provider' => 'smtp', 'enabled' => true, 'settings' => [
            'host' => 'mail.demo.ci', 'port' => '465', 'encryption' => 'ssl', 'username' => 'contact@demo.ci', 'password' => 'demo', 'from_email' => 'contact@demo.ci', 'from_name' => 'Démo SARL',
        ]]);
    }

    /** Organisation et demandes internes de démonstration. */
    protected function tickets(Company $company): void
    {
        app()->instance('tenant', $company);
        $roles = \App\Models\Role::where('company_id', $company->id)->pluck('id', 'name');
        $people = [
            'koffi' => ['Koffi Kouamé', 'Lecture seule'], 'awa' => ['Awa Diabaté', 'Lecture seule'], 'yao' => ['Yao Konan', 'Lecture seule'],
            'mariam' => ['Mariam Touré', 'Commercial'], 'jm' => ['Jean-Marc Aka', 'Commercial'], 'fatou' => ['Fatou Bamba', 'Direction'], 'paul' => ['Paul Ehui', 'Comptable'],
        ];
        $u = [];
        foreach ($people as $k => [$name, $role]) {
            $user = User::create(['company_id' => $company->id, 'role_id' => $roles[$role] ?? null, 'name' => $name, 'email' => $k.'@demo.ci', 'password' => 'password']);
            $u[$k] = $user;
        }
        app(\App\Modules\Tickets\TicketSetup::class)($company);
        $settings = $company->settings ?? [];
        $settings['_setup']['tickets'] = 1;
        $company->forceFill(['settings' => $settings])->saveQuietly();

        $D = \App\Modules\Tickets\Models\Department::class;
        $M = \App\Modules\Tickets\Models\Member::class;
        $dep = $D::pluck('id', 'code');
        $dga = $D::create(['name' => 'Direction générale adjointe', 'code' => 'DGA', 'parent_id' => $dep['DG'], 'head_user_id' => $u['fatou']->id, 'accepts_tickets' => false, 'color' => '#13274F']);
        $M::create(['department_id' => $dga->id, 'user_id' => $u['fatou']->id, 'role' => 'head']);
        $D::whereIn('code', ['IT', 'MKT', 'FIN'])->update(['parent_id' => $dga->id]);
        foreach ([['IT', 'koffi', 'head'], ['IT', 'awa', 'member'], ['IT', 'yao', 'intern'], ['MKT', 'mariam', 'head'], ['MKT', 'jm', 'member'], ['FIN', 'paul', 'head']] as [$code, $who, $role]) {
            $M::create(['department_id' => $dep[$code], 'user_id' => $u[$who]->id, 'role' => $role]);
            if ($role === 'head') {
                $D::where('id', $dep[$code])->update(['head_user_id' => $u[$who]->id]);
            }
        }

        $svc = app(\App\Modules\Tickets\TicketService::class);
        $access = fn (User $x) => new \App\Modules\Tickets\TicketAccess($x);
        $at = fn (string $when) => \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse($when));
        $base = now()->startOfDay();
        $story = function (array $steps) use ($svc, $access, $at) {
            $t = null;
            foreach ($steps as [$when, $who, $action, $data]) {
                $at($when);
                $t = $action === 'create' ? $svc->create($who, $data) : $svc->act($t->fresh(), $who, $access($who), $action, $data);
            }

            return $t;
        };
        $d = fn (int $days, string $time) => $base->copy()->subDays($days)->format('Y-m-d').' '.$time;

        $story([
            [$d(9, '08:40'), $u['jm'], 'create', ['department_id' => $dep['IT'], 'category' => 'Imprimante', 'urgency' => 'high', 'subject' => 'Mon imprimante ne démarre plus', 'description' => 'Bonjour, mon imprimante ne démarre plus. J\'ai besoin que vous passiez la récupérer ou regarder la panne.']],
            [$d(9, '08:55'), $u['koffi'], 'assign', ['assignee_id' => $u['awa']->id]],
            [$d(9, '09:05'), $u['awa'], 'accept', []],
            [$d(9, '09:30'), $u['awa'], 'start', []],
            [$d(9, '10:10'), $u['awa'], 'hold', ['reason' => 'Soumission faite pour l\'achat d\'un bloc d\'alimentation : réglé dès réception.', 'purchase' => true]],
            [$d(7, '14:00'), $u['awa'], 'resume', []],
            [$d(7, '14:45'), $u['awa'], 'done', ['resolution' => 'Bloc d\'alimentation remplacé, impression testée.']],
            [$d(7, '16:00'), $u['jm'], 'close', ['rating' => 5]],
        ]);
        $story([
            [$d(6, '10:00'), $u['mariam'], 'create', ['department_id' => $dep['IT'], 'category' => 'Réseau / Internet', 'urgency' => 'critical', 'subject' => 'Plus d\'Internet au service marketing']],
            [$d(6, '10:08'), $u['koffi'], 'start', []],
            [$d(6, '11:20'), $u['koffi'], 'done', ['resolution' => 'Routeur redémarré, câble remplacé.']],
            [$d(6, '11:40'), $u['mariam'], 'close', ['rating' => 4]],
        ]);
        $story([
            [$d(4, '09:15'), $u['paul'], 'create', ['department_id' => $dep['IT'], 'category' => 'Logiciel', 'urgency' => 'normal', 'subject' => 'Installer le logiciel de paie sur le poste comptable']],
            [$d(4, '11:00'), $u['koffi'], 'assign', ['assignee_id' => $u['yao']->id]],
            [$d(4, '11:30'), $u['yao'], 'accept', []],
            [$d(3, '09:00'), $u['yao'], 'start', []],
            [$d(3, '15:30'), $u['yao'], 'done', ['resolution' => 'Installé et paramétré.']],
            [$d(3, '17:00'), $u['paul'], 'close', ['rating' => 4]],
        ]);
        $story([
            [$d(3, '08:30'), $u['koffi'], 'create', ['department_id' => $dep['MKT'], 'category' => 'Visuel / affiche', 'urgency' => 'normal', 'subject' => 'Affiche pour la journée portes ouvertes IT']],
            [$d(3, '09:00'), $u['mariam'], 'assign', ['assignee_id' => $u['jm']->id]],
            [$d(2, '10:00'), $u['jm'], 'start', []],
        ]);
        $story([
            [$d(2, '15:10'), $u['jm'], 'create', ['department_id' => $dep['IT'], 'category' => 'Compte et mot de passe', 'urgency' => 'high', 'subject' => 'Mot de passe de messagerie bloqué']],
            [$d(2, '15:20'), $u['awa'], 'accept', []],
            [$d(2, '15:50'), $u['awa'], 'done', ['resolution' => 'Mot de passe réinitialisé.']],
        ]);
        $story([[$d(1, '16:45'), $u['paul'], 'create', ['department_id' => $dep['IT'], 'category' => 'Panne matériel', 'urgency' => 'normal', 'subject' => 'Écran du poste caisse qui scintille']]]);
        $story([
            [$d(0, '08:20'), $u['fatou'], 'create', ['department_id' => $dep['MG'], 'category' => 'Entretien / réparation', 'urgency' => 'low', 'subject' => 'Climatisation de la salle de réunion bruyante']],
        ]);
        \Carbon\Carbon::setTestNow();
        app(\App\Modules\Tickets\TicketReports::class)->generate($company, 'week');
        app()->instance('tenant', $company);
    }

    /** Boutique : contacts, moyens de paiement hors ligne de démonstration. */
    protected function store(): void
    {
        \App\Models\PlatformSetting::put('store.contact', [
            'name' => 'Yomara Technologies', 'message' => 'Besoin d\'aide pour choisir vos modules ou d\'un développement sur mesure ? Parlons-en.',
            'hours' => 'Du lundi au vendredi, 8 h – 18 h',
        ]);
        \App\Models\PlatformSetting::put('store.bank_accounts', [['bank' => 'Banque de démonstration', 'holder' => 'Yomara Technologies', 'account' => 'CI000 00000 000000000000 00']]);
        \App\Models\PaymentGateway::whereNull('company_id')->delete(); // remplace les moyens par défaut de l'installation
        \App\Models\PaymentGateway::create(['driver' => 'bank_transfer', 'label' => 'Virement bancaire', 'instructions' => "Virement sur le compte ci-dessous en indiquant le numéro de commande.\nActivation dès réception.", 'sort' => 2]);
        \App\Models\PaymentGateway::create(['driver' => 'mobile_money', 'label' => 'Dépôt Mobile Money', 'instructions' => "Orange Money / Wave : envoyez le montant au numéro communiqué par notre service commercial,\npuis saisissez l'ID de transaction.", 'sort' => 1]);
        \App\Models\PaymentGateway::create(['driver' => 'cinetpay', 'label' => 'Mobile Money & carte (CinetPay)', 'mode' => 'test', 'enabled' => false, 'sort' => 0]);
    }

    /** Catalogue, stock et caisse de démonstration (supérette). */
    protected function catalog(Company $company): void
    {
        app()->instance('tenant', $company);

        $cats = [];
        foreach (['Boissons', 'Épicerie', 'Hygiène'] as $name) {
            $cats[$name] = ProductCategory::create(['name' => $name])->id;
        }
        $wh = Warehouse::create(['name' => 'Boutique Cocody', 'code' => 'COC', 'is_default' => true]);

        // [catégorie, nom, prix TTC, prix d'achat, code-barres]
        $items = [
            ['Boissons', 'Coca-Cola 33cl', 500, 350, '6194000000011'], ['Boissons', 'Eau minérale 1,5L', 400, 250, '6194000000028'],
            ['Boissons', 'Jus de bissap 50cl', 600, 300, null], ['Boissons', 'Bière locale 65cl', 800, 550, null],
            ['Épicerie', 'Riz parfumé 5kg', 4500, 3600, null], ['Épicerie', 'Huile végétale 1L', 1500, 1150, null],
            ['Épicerie', 'Sucre en morceaux 1kg', 900, 700, null], ['Épicerie', 'Lait concentré 397g', 750, 560, null],
            ['Épicerie', 'Spaghetti 500g', 450, 320, null], ['Hygiène', 'Savon de Marseille', 350, 220, null],
            ['Hygiène', 'Dentifrice 75ml', 1000, 700, null], ['Hygiène', 'Papier toilette x4', 1200, 850, null],
        ];
        foreach ($items as [$cat, $name, $ttc, $cost, $barcode]) {
            $p = Product::create([
                'category_id' => $cats[$cat], 'name' => $name, 'barcode' => $barcode,
                'sale_price' => round($ttc / 1.18, 2), 'purchase_price' => $cost, 'tax_rate' => 18, 'min_stock' => 10,
            ]);
            StockMove::create([
                'product_id' => $p->id, 'warehouse_id' => $wh->id, 'quantity' => 60, 'type' => 'initial', 'date' => now()->toDateString(),
            ]);
        }

        $client = Partner::create(['name' => 'Maquis Chez Tantie', 'type' => 'customer', 'phone' => '+225 07 00 00 00 01', 'city' => 'Abidjan']);
        $fournisseur = Partner::create(['name' => 'Grossiste Adjamé SARL', 'type' => 'supplier', 'phone' => '+225 27 20 00 00 00', 'city' => 'Abidjan']);
        PosRegister::create(['name' => 'Caisse 1', 'code' => 'C1', 'warehouse_id' => $wh->id]);

        // Ouverture : capital apporté (les soldes d'ouverture des comptes de trésorerie passent en 471, reclassé ici en capital)
        $posting = app(\App\Modules\Compta\Posting::class);
        \App\Modules\Compta\Chart::ensure($company);
        $acc = \App\Modules\Compta\Models\Account::withoutGlobalScopes()->where('company_id', $company->id)->pluck('id', 'code');
        $an = \App\Modules\Compta\Models\Journal::withoutGlobalScopes()->where(['company_id' => $company->id, 'code' => 'AN'])->value('id');
        $acc471 = $acc['471'] ?? \App\Modules\Compta\Models\Account::withoutGlobalScopes()->firstOrCreate(['company_id' => $company->id, 'code' => '471'], ['name' => 'Comptes d\'attente', 'is_system' => true, 'active' => true])->id;
        $posting->manual($company, $an, now()->startOfYear()->toDateString(), 'Apport en capital (banque, caisse, Mobile Money)', null, [
            ['account_id' => $acc471, 'debit' => 6920000], ['account_id' => $acc['101'], 'credit' => 6920000],
        ]);

        // Un peu d'activité : achat fournisseur réglé par virement, deux factures clients
        $products = Product::all()->keyBy('name');
        $line = fn (string $name, float $q, ?float $price = null) => [
            'product_id' => $products[$name]->id, 'description' => $name, 'quantity' => $q,
            'unit_price' => $price ?? $products[$name]->sale_price, 'tax_rate' => 18,
        ];
        $doc = function (string $class, string $type, Partner $p, array $lines, int $daysAgo) {
            $d = new $class;
            $d->fill(['type' => $type, 'partner_id' => $p->id, 'partner_name' => $p->name, 'date' => now()->subDays($daysAgo)->toDateString()]);
            $d->save();
            $d->replaceLines($lines);
            $d->validateDocument();

            return $d->fresh();
        };
        $pay = function (string $class, $d, float $amount, string $method, int $daysAgo) {
            $p = new $class(['document_id' => $d->id, 'date' => now()->subDays($daysAgo)->toDateString(), 'amount' => $amount, 'method' => $method]);
            $p->partner_id = $d->partner_id;
            $p->save();
            $d->refreshPaymentStatus();
        };

        $achat = $doc(\App\Modules\Achats\Models\PurchaseDocument::class, 'supplier_invoice', $fournisseur, [
            $line('Riz parfumé 5kg', 40, 3600), $line('Huile végétale 1L', 60, 1150),
        ], 12);
        $pay(\App\Modules\Achats\Models\PurchasePayment::class, $achat, $achat->total, 'bank_transfer', 10);

        $f1 = $doc(\App\Modules\Ventes\Models\SalesDocument::class, 'invoice', $client, [
            $line('Bière locale 65cl', 48), $line('Coca-Cola 33cl', 48), $line('Eau minérale 1,5L', 24),
        ], 8);
        $pay(\App\Modules\Ventes\Models\SalesPayment::class, $f1, $f1->total, 'mobile_money', 6);
        $doc(\App\Modules\Ventes\Models\SalesDocument::class, 'invoice', $client, [
            $line('Riz parfumé 5kg', 10), $line('Huile végétale 1L', 12),
        ], 2);

        // RH : quatre salariés et une paie du mois en brouillon
        $emp = fn (array $a) => \App\Modules\Rh\Models\Employee::create($a + ['contract_type' => 'CDI', 'payment_method' => 'bank_transfer']);
        $emp(['matricule' => 'EMP00001', 'first_name' => 'Awa', 'last_name' => 'Koné', 'gender' => 'F', 'job_title' => 'Gérante', 'department' => 'Direction',
            'hire_date' => now()->subYears(4)->startOfYear()->toDateString(), 'base_salary' => 450000, 'transport_allowance' => 30000,
            'marital_status' => 'married', 'children_count' => 2, 'cnps_number' => '1002345678',
            'fixed_items' => [['label' => 'Prime de responsabilité', 'amount' => 50000, 'taxable' => true, 'social' => true]]]);
        $emp(['matricule' => 'EMP00002', 'first_name' => 'Yao', 'last_name' => 'Kouassi', 'gender' => 'M', 'job_title' => 'Caissier', 'department' => 'Boutique',
            'hire_date' => now()->subYears(2)->subMonths(3)->toDateString(), 'base_salary' => 120000, 'transport_allowance' => 25000,
            'marital_status' => 'single', 'children_count' => 1, 'cnps_number' => '1002345679', 'payment_method' => 'mobile_money', 'payment_account' => '07 07 00 00 01']);
        $emp(['matricule' => 'EMP00003', 'first_name' => 'Mariam', 'last_name' => 'Traoré', 'gender' => 'F', 'job_title' => 'Caissière', 'department' => 'Boutique',
            'hire_date' => now()->subMonths(8)->toDateString(), 'base_salary' => 100000, 'transport_allowance' => 25000,
            'marital_status' => 'married', 'children_count' => 3, 'spouse_dependent' => false, 'cnps_number' => '1002345680']);
        $emp(['matricule' => 'EMP00004', 'first_name' => 'Ibrahim', 'last_name' => 'Ouattara', 'gender' => 'M', 'job_title' => 'Magasinier', 'department' => 'Logistique',
            'hire_date' => now()->subYears(6)->toDateString(), 'base_salary' => 150000, 'transport_allowance' => 25000,
            'marital_status' => 'married', 'children_count' => 4, 'spouse_dependent' => true, 'cnps_number' => '1002345681']);
        \App\Models\Sequence::create(['company_id' => $company->id, 'key' => 'rh.matricule', 'year' => 0, 'last_number' => 4]);
        \App\Modules\Rh\Models\Leave::create(['employee_id' => \App\Modules\Rh\Models\Employee::where('matricule', 'EMP00002')->value('id'), 'type' => 'annual', 'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(15)->toDateString(), 'days' => 5, 'status' => 'requested', 'reason' => 'Congé familial']);
        app(\App\Modules\Paie\PayrollService::class)->createRun($company, now()->format('Y-m'));

        // CRM : pipeline de démonstration
        app(\App\Modules\Crm\CrmSetup::class)($company);
        $settings = $company->settings ?? [];
        $settings['_setup']['crm'] = 1;
        $company->forceFill(['settings' => $settings])->saveQuietly();
        $owner = User::where('email', 'admin@demo.ci')->value('id');
        $stage = \App\Modules\Crm\Models\Stage::pluck('id', 'name');
        $leads = [
            ['Approvisionnement mensuel boissons', 'Maquis Chez Tantie', 'Tantie Rose', 'Qualifié', 450000, 'referral', 12, 2, $client->id],
            ['Fourniture cantine scolaire', 'Groupe Scolaire Les Lauriers', 'M. Bamba', 'Proposition envoyée', 1800000, 'prospecting', 20, 3, null],
            ['Livraison hebdomadaire hôtel', 'Hôtel Ivoire Lagune', 'Mme Koffi', 'Négociation', 2500000, 'event', 8, 3, null],
            ['Colis de fin d\'année salariés', 'Banque Atlantique Cocody', 'Service RH', 'Nouveau', 3200000, 'website', 45, 2, null],
            ['Pause-café séminaires', 'Cabinet Konan & Associés', 'Mme Konan', 'Nouveau', 350000, 'whatsapp', 30, 1, null],
            ['Fourniture boutique station', 'Station Petro Ivoire Riviera', 'M. Diallo', 'Gagné', 900000, 'phone', -5, 2, null],
            ['Buffet anniversaire entreprise', 'Agence Com\'Impact', 'M. Yao', 'Perdu', 600000, 'social', -10, 1, null],
        ];
        $ids = [];
        foreach ($leads as [$title, $comp, $contact, $st, $amount, $source, $days, $prio, $partner]) {
            $l = new \App\Modules\Crm\Models\Lead([
                'title' => $title, 'company_name' => $comp, 'contact_name' => $contact, 'stage_id' => $stage[$st],
                'expected_revenue' => $amount, 'source' => $source, 'expected_close_date' => now()->addDays($days)->toDateString(),
                'owner_id' => $owner, 'priority' => $prio, 'partner_id' => $partner, 'city' => 'Abidjan',
                'phone' => '+225 07 '.random_int(10, 99).' '.random_int(10, 99).' '.random_int(10, 99).' '.random_int(10, 99),
                'lost_reason' => $st === 'Perdu' ? 'Prix trop élevé' : null,
            ]);
            $l->save();
            $ids[$title] = $l->id;
        }
        $act = fn (string $lead, string $type, string $subject, $due, bool $done = false) => \App\Modules\Crm\Models\Activity::create([
            'lead_id' => $ids[$lead], 'type' => $type, 'subject' => $subject, 'due_at' => $due, 'done' => $done, 'owner_id' => $owner,
        ]);
        $act('Fourniture cantine scolaire', 'call', 'Relancer M. Bamba sur le devis', now()->subDay()->setTime(10, 0));
        $act('Livraison hebdomadaire hôtel', 'meeting', 'Négociation des tarifs avec Mme Koffi', now()->setTime(15, 30));
        $act('Colis de fin d\'année salariés', 'visit', 'Présenter les échantillons au service RH', now()->addDays(2)->setTime(9, 0));
        $act('Approvisionnement mensuel boissons', 'whatsapp', 'Envoyer la grille de prix', now()->addDays(1)->setTime(11, 0));
        $act('Fourniture boutique station', 'call', 'Appel de qualification', now()->subDays(12), true);

        app()->forgetInstance('tenant');
    }
}
