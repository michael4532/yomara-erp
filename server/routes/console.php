<?php

use App\Models\SyncChange;
use App\Models\SyncMutation;
use App\Support\ModuleRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('erp:modules-sync', function (ModuleRegistry $registry) {
    $registry->syncToDatabase();
    $this->info('Catalogue de modules mis à jour.');
})->purpose('Inscrit les modules du code dans le catalogue');

Artisan::command('erp:modules-all {company? : id ou nom de la société (toutes par défaut)}', function (ModuleRegistry $registry, ?string $company = null) {
    // Démonstration / essai : met le catalogue à jour et active tous les modules, sans date d'expiration.
    $registry->syncToDatabase();
    \App\Models\Module::query()->update(['is_active' => true, 'is_available' => true]);
    $companies = \App\Models\Company::query()
        ->when($company, fn ($q) => is_numeric($company) ? $q->whereKey($company) : $q->where('name', 'like', "%{$company}%"))
        ->get();
    $keys = \App\Models\Module::where('is_core', false)->pluck('key');
    foreach ($companies as $c) {
        foreach ($keys as $key) {
            \App\Models\CompanyModule::updateOrCreate(
                ['company_id' => $c->id, 'module_key' => $key],
                ['enabled' => true, 'expires_at' => null],
            );
        }
        $this->info("{$c->name} : ".count($keys).' modules activés.');
    }
    $registry->flush();
    \Illuminate\Support\Facades\Cache::flush();
})->purpose('Active tous les modules (démonstration)');

Artisan::command('erp:sync-compact', function () {
    // Ne garde que le dernier changement de chaque enregistrement (le pull renvoie l'état courant).
    $latest = SyncChange::query()->selectRaw('MAX(id) as id')->groupBy('company_id', 'entity', 'record_id');
    $deleted = SyncChange::whereNotIn('id', DB::query()->fromSub($latest, 'l')->select('id'))->delete();
    $old = SyncMutation::where('created_at', '<', now()->subDays(30))->delete();
    $this->info("Journal compacté : $deleted changements, $old mutations anciennes supprimés.");
})->purpose('Compacte le journal de synchronisation');

Artisan::command('erp:tickets-report {period=week}', function (string $period) {
    $count = 0;
    foreach (\App\Models\Company::where('status', '!=', 'suspended')->get() as $company) {
        if (! $company->hasModule('tickets')) {
            continue;
        }
        $count += count(app(\App\Modules\Tickets\TicketReports::class)->generate($company, $period === 'month' ? 'month' : 'week'));
        app()->forgetInstance('tenant');
    }
    $this->info("$count rapport(s) de tickets générés.");
})->purpose('Rapports automatiques des demandes internes (week|month)');

Artisan::command('erp:marketing-dispatch', function () {
    $n = 0;
    foreach (\App\Modules\Marketing\Models\Campaign::withoutGlobalScopes()->where('status', 'scheduled')->where('scheduled_at', '<=', now())->get() as $c) {
        $company = \App\Models\Company::find($c->company_id);
        if (! $company?->hasModule('marketing')) {
            continue;
        }
        app()->instance('tenant', $company);
        try {
            app(\App\Modules\Marketing\CampaignService::class)->launch($c);
            $n++;
        } catch (\DomainException $e) {
            $c->update(['status' => 'draft']);
            \App\Models\AppNotification::send($c->company_id, [$c->user_id], 'campaign', 'Campagne « '.$c->name.' » non envoyée', $e->getMessage(), 'marketing', (string) $c->id);
        }
        app()->forgetInstance('tenant');
    }
    // Tests A/B arrivés à échéance : envoi de l'objet gagnant au reste de la liste
    foreach (\App\Modules\Marketing\Models\Campaign::withoutGlobalScopes()->where('status', 'sending')->whereNotNull('ab')->get() as $c) {
        if (($c->ab['status'] ?? null) === 'testing' && now()->gte(\Carbon\Carbon::parse($c->ab['decide_at']))) {
            app()->instance('tenant', \App\Models\Company::find($c->company_id));
            app(\App\Modules\Marketing\CampaignService::class)->decideAb($c);
            app()->forgetInstance('tenant');
        }
    }
    // Automatisations : faire avancer les contacts dont l'étape est due
    $companies = \App\Modules\Marketing\Models\AutomationRun::withoutGlobalScopes()->where('status', 'active')->where('next_run_at', '<=', now())->distinct()->pluck('company_id');
    foreach ($companies as $cid) {
        $company = \App\Models\Company::find($cid);
        if ($company?->hasModule('marketing')) {
            app()->instance('tenant', $company);
            app(\App\Modules\Marketing\AutomationEngine::class)->tick($company);
            app()->forgetInstance('tenant');
        }
    }
    // Les liens partagés par WhatsApp / SMS expirent après 30 jours
    foreach (glob(public_path('uploads/shared/*/*')) ?: [] as $f) {
        if (filemtime($f) < time() - 30 * 86400) {
            @unlink($f);
        }
    }
    $this->info("$n campagne(s) lancée(s).");
})->purpose('Lance les campagnes programmées');

Artisan::command('erp:marketing-birthdays', function () {
    $n = 0;
    foreach (\App\Models\Company::where('status', '!=', 'suspended')->get() as $company) {
        if ($company->hasModule('marketing')) {
            app()->instance('tenant', $company);
            $n += app(\App\Modules\Marketing\AutomationEngine::class)->birthdays($company);
            $n += app(\App\Modules\Marketing\AutomationEngine::class)->inactiveCustomers($company);
            app()->forgetInstance('tenant');
        }
    }
    $this->info("$n anniversaire(s) programmé(s).");
})->purpose('Lance les scénarios « Anniversaire » du jour');

Artisan::command('erp:interventions-contracts', function () {
    $n = 0;
    foreach (\App\Models\Company::where('status', '!=', 'suspended')->get() as $company) {
        if ($company->hasModule('interventions')) {
            app()->instance('tenant', $company);
            $n += count(app(\App\Modules\Interventions\InterventionService::class)->generateVisits($company, (int) ($company->settings['intervention_lead_days'] ?? 7)));
            app()->forgetInstance('tenant');
        }
    }
    $this->info("$n visite(s) préventive(s) créée(s).");
})->purpose('Crée les visites préventives des contrats de maintenance');

Artisan::command('erp:rdv-reminders', function () {
    $n = 0;
    foreach (\App\Models\Company::where('status', '!=', 'suspended')->get() as $company) {
        if ($company->hasModule('rendez_vous')) {
            app()->instance('tenant', $company);
            $n += app(\App\Modules\RendezVous\BookingService::class)->reminders($company);
            app()->forgetInstance('tenant');
        }
    }
    $this->info("$n rappel(s) envoyé(s).");
})->purpose('Envoie les rappels de rendez-vous');

Artisan::command('erp:fidelite-expire', function () {
    $n = 0;
    foreach (\App\Models\Company::where('status', '!=', 'suspended')->get() as $company) {
        if ($company->hasModule('fidelite')) {
            app()->instance('tenant', $company);
            $n += app(\App\Modules\Fidelite\LoyaltyService::class)->expire($company);
            app()->forgetInstance('tenant');
        }
    }
    $this->info("$n point(s) expiré(s).");
})->purpose('Fait expirer les points de fidélité et les cartes cadeaux échues');

Artisan::command('erp:recrutement-purge', function () {
    $n = 0;
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('recrutement')) {
            app()->instance('tenant', $company);
            $n += app(\App\Modules\Recrutement\RecrutementService::class)->purge($company);
            app()->forgetInstance('tenant');
        }
    }
    $this->info("$n candidature(s) non retenue(s) effacée(s).");
})->purpose('Efface les candidatures non retenues après la durée de conservation');

// Cron unique (cPanel) : * * * * * php artisan schedule:run
Schedule::command('erp:sync-compact')->dailyAt('03:00');
// Installation sous licence : vérification auprès de la plateforme (modules achetés, appareil)
Artisan::command('erp:license-check', function () {
    if (config('erp.mode') !== 'single') {
        return;
    }
    $i = \App\Licensing\LicenseClient::check();
    $this->info('Licence : '.$i['status'].($i['error'] ? ' — '.$i['error'] : ''));
})->purpose('Vérifie la licence de cette installation');
Artisan::command('erp:plans-downgrade', function () {
    $n = 0;
    foreach (\App\Models\Company::whereIn('status', ['trial', 'active'])->get() as $c) {
        $n += \App\Store\ModuleStore::downgradeIfExpired($c) ? 1 : 0;
    }
    $this->info($n.' société(s) repassée(s) en formule gratuite');
})->purpose('Essais et abonnements échus : formule gratuite');
Schedule::command('erp:plans-downgrade')->dailyAt('00:20');
Artisan::command('erp:subscription-reminders', function () {
    $this->info(\App\Platform\SubscriptionLifecycle::remind().' rappel(s) de fin d\'essai ou d\'abonnement envoyé(s).');
})->purpose('Rappels 7, 3, 1 jour(s) avant la fin de l\'essai ou de l\'abonnement : e-mail, notification, bandeau');
Schedule::command('erp:subscription-reminders')->dailyAt('08:40');
Schedule::command('erp:license-check')->everySixHours()->withoutOverlapping();
Schedule::command('erp:marketing-dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('erp:marketing-birthdays')->dailyAt('08:00');
Schedule::command('erp:interventions-contracts')->dailyAt('05:30');
Schedule::command('erp:fidelite-expire')->dailyAt('02:30');
Artisan::command('erp:signature-maintenance', function () {
    $t = ['expired' => 0, 'reminded' => 0];
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('signature')) {
            app()->instance('tenant', $company);
            foreach (app(\App\Modules\Signature\SignatureService::class)->maintenance($company) as $k => $v) {
                $t[$k] += $v;
            }
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Signatures : {$t['expired']} demande(s) expirée(s), {$t['reminded']} relance(s).");
})->purpose('Signature électronique : expiration des demandes et relances automatiques');

Schedule::command('erp:recrutement-purge')->dailyAt('04:15');
Schedule::command('erp:signature-maintenance')->dailyAt('09:05');
Artisan::command('erp:documents-maintenance', function () {
    $t = ['notified' => 0, 'purged' => 0];
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('documents')) {
            app()->instance('tenant', $company);
            foreach (app(\App\Modules\Documents\DocumentService::class)->maintenance($company) as $k => $v) {
                $t[$k] += $v;
            }
            app()->forgetInstance('tenant');
            \App\Modules\Documents\DocumentAccess::flush();
        }
    }
    $this->info("Documents : {$t['notified']} rappel(s) d'échéance, {$t['purged']} fichier(s) supprimé(s) de la corbeille.");
})->purpose('Documents : rappels d\'échéance et corbeille vidée après 30 jours');
Schedule::command('erp:documents-maintenance')->dailyAt('07:10');
Artisan::command('erp:agenda-reminders', function () {
    $n = 0;
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('agenda')) {
            app()->instance('tenant', $company);
            $n += app(\App\Modules\Agenda\AgendaService::class)->reminders($company);
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Agenda : $n rappel(s) envoyé(s).");
})->purpose('Agenda : rappels avant les réunions et rendez-vous');
Schedule::command('erp:agenda-reminders')->everyFiveMinutes()->withoutOverlapping();

// Paiements en ligne restés en attente (client qui ferme la page avant le retour) : vérification régulière
// chez le prestataire, puis activation automatique du module ou encaissement de la facture.
Artisan::command('erp:payments-check {--hours=48}', function (\App\Payments\PaymentService $payments) {
    $n = 0;
    \App\Models\PaymentTransaction::with('gateway')->where('status', 'pending')->whereNotNull('reference')
        ->where('created_at', '>=', now()->subHours((int) $this->option('hours')))->orderBy('created_at')->limit(200)->get()
        ->each(function ($tx) use ($payments, &$n) {
            if ($payments->check($tx)->status !== 'pending') {
                $n++;
            }
        });
    $this->info("$n paiement(s) mis à jour");
})->purpose('Vérifie les paiements en ligne en attente et active ce qui a été payé');
Schedule::command('erp:payments-check')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('erp:rdv-reminders')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('erp:tickets-report week')->weeklyOn(1, '06:00');
Schedule::command('erp:tickets-report month')->monthlyOn(1, '06:30');
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping();
Artisan::command('erp:fne-process', function () {
    $t = ['certified' => 0, 'failed' => 0, 'pending' => 0];
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('fne')) {
            app()->instance('tenant', $company);
            foreach (app(\App\Modules\Fne\FneService::class)->processDue($company) as $k => $v) {
                $t[$k] += $v;
            }
            app()->forgetInstance('tenant');
        }
    }
    $this->info("FNE : {$t['certified']} certifiée(s), {$t['failed']} refusée(s), {$t['pending']} toujours en attente.");
})->purpose('FNE : envoi à la DGI des factures en attente de certification');
Schedule::command('erp:fne-process')->everyFiveMinutes()->withoutOverlapping();
Artisan::command('erp:assets-depreciate', function () {
    $n = 0;
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('immobilisations') && data_get($company->settings, 'immo.auto_post')) {
            app()->instance('tenant', $company);
            // Périodes terminées : dotations comptabilisées automatiquement
            $n += app(\App\Modules\Immobilisations\AssetService::class)->postDue($company, now()->subDay()->toDateString())['count'];
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Immobilisations : $n dotation(s) comptabilisée(s).");
})->purpose('Immobilisations : comptabilisation automatique des dotations échues');
Schedule::command('erp:assets-depreciate')->dailyAt('03:30');
Artisan::command('erp:dunning', function () {
    $t = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('relances')) {
            app()->instance('tenant', $company);
            foreach (app(\App\Modules\Relances\DunningService::class)->run($company) as $k => $v) {
                $t[$k] += $v;
            }
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Relances : {$t['sent']} envoyée(s), {$t['failed']} en échec, {$t['skipped']} client(s) ignoré(s).");
})->purpose('Relances clients : factures échues, par paliers');
Schedule::command('erp:dunning')->dailyAt('09:15');
Artisan::command('erp:subscriptions', function () {
    $t = ['invoices' => 0, 'errors' => 0];
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('abonnements')) {
            app()->instance('tenant', $company);
            foreach (app(\App\Modules\Abonnements\SubscriptionService::class)->runDue($company) as $k => $v) {
                $t[$k] += $v;
            }
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Abonnements : {$t['invoices']} facture(s) émise(s), {$t['errors']} contrat(s) en erreur.");
})->purpose('Abonnements : factures des échéances arrivées');
Schedule::command('erp:subscriptions')->dailyAt('06:20');
Artisan::command('erp:rents', function () {
    $t = ['rents' => 0, 'errors' => 0];
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('immobilier')) {
            app()->instance('tenant', $company);
            foreach (app(\App\Modules\Immobilier\ImmobilierService::class)->runDue($company) as $k => $v) {
                $t[$k] += $v;
            }
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Gestion locative : {$t['rents']} appel(s) de loyer émis, {$t['errors']} bail(aux) en erreur.");
})->purpose('Gestion locative : appels de loyer des périodes qui commencent');
Schedule::command('erp:rents')->dailyAt('06:35');
Artisan::command('erp:reports-send', function () {
    $t = ['sent' => 0, 'failed' => 0];
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('rapports')) {
            app()->instance('tenant', $company);
            foreach (app(\App\Modules\Rapports\RapportsService::class)->runDue($company) as $k => $v) {
                $t[$k] += $v;
            }
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Rapports : {$t['sent']} envoi(s) réussi(s), {$t['failed']} en échec.");
})->purpose('Rapports : envois programmés par e-mail');
Schedule::command('erp:reports-send')->everyTenMinutes();
Artisan::command('erp:excel-dashboards', function () {
    $t = ['sent' => 0, 'failed' => 0];
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('tableaux_excel')) {
            app()->instance('tenant', $company);
            foreach (app(\App\Modules\TableauxExcel\TableauxExcelService::class)->runDue($company) as $k => $v) {
                $t[$k] += $v;
            }
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Tableaux de bord Excel : {$t['sent']} envoi(s) réussi(s), {$t['failed']} en échec.");
})->purpose('Tableaux de bord Excel : envois programmés par e-mail (lundi / 1er du mois)');
Schedule::command('erp:excel-dashboards')->dailyAt('07:10');
Artisan::command('erp:visio', function () {
    $r = app(\App\Modules\Visio\Housekeeping::class)->run();
    $this->info("Réunions : {$r['reminders']} rappel(s), {$r['closed']} salle(s) clôturée(s), {$r['reports']} compte(s) rendu(s).");
})->purpose('Réunions & webinaires : rappels, clôture des salles abandonnées, comptes rendus');
Schedule::command('erp:visio')->everyFiveMinutes()->withoutOverlapping();
Artisan::command('erp:bank-sync', function () {
    $r = app(\App\Modules\Tresorerie\Bank\BankSync::class)->runDue();
    $this->info("Banques : {$r['synced']} connexion(s) synchronisée(s), {$r['imported']} opération(s), {$r['failed']} échec(s).");
})->purpose('Synchronisation bancaire : relevés Wave Business et banques connectées');
Schedule::command('erp:bank-sync')->hourly()->withoutOverlapping();
Artisan::command('erp:webhooks', function () {
    $r = app(\App\Integrations\Webhooks::class)->retryDue();
    $this->info("Webhooks : {$r['sent']} envoi(s), {$r['success']} réussi(s).");
})->purpose('Webhooks sortants : relance des livraisons en échec');
Schedule::command('erp:webhooks')->everyMinute()->withoutOverlapping();
Artisan::command('erp:mailbox', function () {
    $r = app(\App\Modules\Mailbox\MailboxService::class)->runDue();
    $this->info("Boîte mail : {$r['synced']} compte(s) synchronisé(s), {$r['errors']} en erreur, {$r['sent']} envoi(s).");
})->purpose('Boîte mail : synchronisation IMAP / POP3 / Microsoft 365 / Gmail, calendriers et envois programmés');
Schedule::command('erp:mailbox')->everyMinute()->withoutOverlapping(10);
Artisan::command('erp:avis-google', function () {
    $r = app(\App\Modules\AvisGoogle\GoogleProfile::class)->runDue();
    $this->info("Avis Google : {$r['synced']} fiche(s) synchronisée(s), {$r['requests']} demande(s) d'avis envoyée(s).");
})->purpose('Avis Google : synchronisation des avis et statistiques, alertes, demandes d\'avis programmées');
Schedule::command('erp:avis-google')->everyFiveMinutes()->withoutOverlapping(15);
Artisan::command('erp:approvals', function () {
    $t = ['reminded' => 0, 'escalated' => 0];
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('approbations')) {
            app()->instance('tenant', $company);
            foreach (app(\App\Modules\Approbations\ApprovalService::class)->remind($company) as $k => $v) {
                $t[$k] += $v;
            }
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Approbations : {$t['reminded']} rappel(s), {$t['escalated']} escalade(s).");
})->purpose('Circuits d\'approbation : rappels et escalades');
Schedule::command('erp:approvals')->hourly();
Artisan::command('erp:lots-expiry', function () {
    $n = 0;
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('lots')) {
            app()->instance('tenant', $company);
            $n += app(\App\Modules\Lots\LotService::class)->alert($company);
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Lots : $n alerte(s) de péremption.");
})->purpose('Lots : alertes de péremption');
Schedule::command('erp:lots-expiry')->dailyAt('07:00');
Artisan::command('erp:events', function () {
    $t = ['expired' => 0, 'balances' => 0, 'errors' => 0];
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('evenements')) {
            app()->instance('tenant', $company);
            foreach (app(\App\Modules\Evenements\EventService::class)->runDaily($company) as $k => $v) {
                $t[$k] += $v;
            }
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Événements : {$t['expired']} option(s) expirée(s), {$t['balances']} solde(s) facturé(s), {$t['errors']} erreur(s).");
})->purpose('Événements : options expirées et factures de solde');
Schedule::command('erp:events')->dailyAt('06:30');
Artisan::command('erp:hosting', function () {
    $t = ['invoices' => 0, 'reminders' => 0, 'errors' => 0];
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('hebergement')) {
            app()->instance('tenant', $company);
            foreach (app(\App\Modules\Hebergement\HostingService::class)->runDaily($company) as $k => $v) {
                $t[$k] = ($t[$k] ?? 0) + $v;
            }
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Hébergement : {$t['invoices']} facture(s) de renouvellement, {$t['reminders']} rappel(s), {$t['errors']} erreur(s).");
})->purpose('Hébergement & domaines : renouvellements et rappels d\'échéance');
Schedule::command('erp:hosting')->dailyAt('06:40');
Artisan::command('erp:prestations', function () {
    $t = ['expired' => 0, 'reminders' => 0, 'reports' => 0];
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('prestations')) {
            app()->instance('tenant', $company);
            foreach (app(\App\Modules\Prestations\PrestationService::class)->runDaily($company) as $k => $v) {
                $t[$k] += $v;
            }
            if (now()->day <= 3) {
                $t['reports'] += app(\App\Modules\Prestations\ReportService::class)->runMonthly($company);
            }
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Forfaits & livrables : {$t['expired']} forfait(s) expiré(s), {$t['reminders']} relance(s) de livrable, {$t['reports']} rapport(s) d'activité.");
})->purpose('Forfaits expirés, relance des livrables sans réponse, rapports d\'activité mensuels (du 1er au 3)');
Schedule::command('erp:prestations')->dailyAt('07:20');

Artisan::command('erp:helpdesk', function () {
    $t = ['breached' => 0, 'closed' => 0];
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('helpdesk')) {
            app()->instance('tenant', $company);
            foreach (app(\App\Modules\Helpdesk\HelpdeskService::class)->runEvery($company) as $k => $v) {
                $t[$k] += $v;
            }
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Service client : {$t['breached']} délai(s) dépassé(s), {$t['closed']} ticket(s) fermé(s).");
})->purpose('Service client : délais (SLA) dépassés et fermeture des tickets résolus');
Schedule::command('erp:helpdesk')->everyFiveMinutes();

Artisan::command('erp:helpdesk-mail', function () {
    $t = ['created' => 0, 'appended' => 0, 'ignored' => 0, 'errors' => 0];
    foreach (\App\Modules\Helpdesk\Inbound\Mailbox::where('imap_enabled', true)->get() as $box) {
        $company = \App\Models\Company::find($box->company_id);
        if (! $company || $company->status === 'suspended' || ! $company->hasModule('helpdesk')) {
            continue;
        }
        app()->instance('tenant', $company);
        $r = app(\App\Modules\Helpdesk\Inbound\InboundService::class)->fetchMailbox($company, $box);
        foreach (['created', 'appended', 'ignored'] as $k) {
            $t[$k] += $r[$k] ?? 0;
        }
        $t['errors'] += isset($r['error']) ? 1 : 0;
        app()->forgetInstance('tenant');
    }
    $this->info("E-mails reçus : {$t['created']} ticket(s) créé(s), {$t['appended']} réponse(s), {$t['ignored']} ignoré(s), {$t['errors']} boîte(s) en erreur.");
})->purpose('Service client : relève des boîtes e-mail (IMAP) et création des tickets');
Schedule::command('erp:helpdesk-mail')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('erp:feuilles', function () {
    $t = ['reminded' => 0, 'pending' => 0];
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('feuilles_temps')) {
            app()->instance('tenant', $company);
            foreach (app(\App\Modules\Feuilles\FeuillesService::class)->runDaily($company) as $k => $v) {
                $t[$k] += $v;
            }
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Feuilles de temps : {$t['reminded']} rappel(s), {$t['pending']} feuille(s) à valider.");
})->purpose('Feuilles de temps : rappel du vendredi et feuilles à valider');
Schedule::command('erp:feuilles')->dailyAt('16:30');

Artisan::command('erp:fournisseurs', function () {
    $n = 0;
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('portail_fournisseurs')) {
            app()->instance('tenant', $company);
            $n += app(\App\Modules\Fournisseurs\FournisseursService::class)->closeExpired($company);
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Portail fournisseurs : {$n} appel(s) d'offres clos.");
})->purpose('Portail fournisseurs : clôture des appels d\'offres échus');
Schedule::command('erp:fournisseurs')->everyFifteenMinutes();

Artisan::command('erp:projets-budget', function () {
    $n = 0;
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('projets')) {
            app()->instance('tenant', $company);
            $n += app(\App\Modules\Projets\ProjectCostService::class)->runDaily($company);
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Projets : {$n} alerte(s) de budget.");
})->purpose('Projets : alerte quand les coûts engagés atteignent 80 % puis 100 % du budget');
Schedule::command('erp:projets-budget')->dailyAt('07:10');

Artisan::command('erp:tresorerie-prevision', function () {
    $n = 0;
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('tresorerie')) {
            app()->instance('tenant', $company);
            $n += app(\App\Modules\Tresorerie\CashForecastService::class)->checkAlert($company) ? 1 : 0;
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Trésorerie : {$n} alerte(s) de seuil.");
})->purpose('Prévision de trésorerie : alerte quand le solde prévu passe sous le seuil');
Schedule::command('erp:tresorerie-prevision')->dailyAt('07:20');

Artisan::command('erp:store-expiry', function () {
    $n = app(\App\Store\ModuleStore::class)->notifyExpiring();
    $this->info("Boutique : {$n} rappel(s) d'expiration envoyé(s).");
})->purpose('Boutique des modules : rappels avant l\'expiration des abonnements et essais');
Schedule::command('erp:store-expiry')->dailyAt('08:40');

Artisan::command('erp:event-feedback', function () {
    $n = 0;
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('billetterie')) {
            app()->instance('tenant', $company);
            $n += app(\App\Modules\Billetterie\RegistrationService::class)->requestFeedback($company);
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Événements : {$n} demande(s) d'avis envoyée(s).");
})->purpose('Événements : demande d\'avis aux participants le lendemain de la fin');
Schedule::command('erp:event-feedback')->dailyAt('10:05');

Artisan::command('erp:sites-dns', function () {
    $svc = app(\App\Modules\Sites\SiteService::class);
    $n = 0;
    foreach (\App\Modules\Sites\Models\Site::withoutGlobalScopes()->whereNotNull('custom_domain')->whereIn('domain_status', ['pending', 'verified', 'active', 'error'])->get() as $s) {
        // Domaines en attente : à chaque passage ; domaines actifs : contrôle quotidien
        if ($s->domain_status === 'active' && $s->domain_checked_at && $s->domain_checked_at->gt(now()->subDay())) {
            continue;
        }
        $svc->checkDomain($s);
        $n++;
    }
    $this->info("Sites : {$n} domaine(s) vérifié(s).");
})->purpose('Sites vitrines : vérification DNS des domaines personnels');
Schedule::command('erp:sites-dns')->hourlyAt(25);

Artisan::command('erp:shop-followups', function () {
    $shop = app(\App\Modules\Sites\ShopService::class);
    [$carts, $reviews] = [0, 0];
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('sites')) {
            app()->instance('tenant', $company);
            $carts += $shop->remindCarts($company);
            $reviews += $shop->requestReviews($company);
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Boutiques : {$carts} panier(s) relancé(s), {$reviews} demande(s) d'avis.");
})->purpose('Boutiques en ligne : relance des paniers abandonnés et demandes d\'avis après livraison');
Schedule::command('erp:shop-followups')->hourlyAt(35);

Artisan::command('erp:fonds-remind', function () {
    $n = 0;
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('fonds')) {
            app()->instance('tenant', $company);
            $n += app(\App\Modules\Fonds\FundService::class)->remind($company);
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Appels de fonds : $n relance(s).");
})->purpose('Appels de fonds : relance des décisions et mises à disposition en attente');
Schedule::command('erp:fonds-remind')->dailyAt('08:40');
Artisan::command('erp:recovery-check', function () {
    $t = ['broken' => 0, 'installments' => 0, 'notices' => 0];
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('relances')) {
            app()->instance('tenant', $company);
            foreach (app(\App\Modules\Relances\RecoveryService::class)->check($company) as $k => $v) {
                $t[$k] += $v;
            }
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Recouvrement : {$t['broken']} promesse(s) non tenue(s), {$t['installments']} échéance(s) manquée(s), {$t['notices']} mise(s) en demeure expirée(s).");
})->purpose('Recouvrement : promesses non tenues, échéances manquées, mises en demeure expirées');
Schedule::command('erp:recovery-check')->dailyAt('07:50');
Artisan::command('erp:rentals', function () {
    $t = ['started' => 0, 'reminders' => 0, 'overdue' => 0];
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('appro')) {
            app()->instance('tenant', $company);
            foreach (app(\App\Modules\Appro\RentalService::class)->daily($company) as $k => $v) {
                $t[$k] += $v;
            }
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Locations : {$t['started']} démarrée(s), {$t['reminders']} rappel(s) de fin, {$t['overdue']} dépassement(s).");
})->purpose('Locations et services externes : démarrage, rappel avant la fin, dépassement');
Schedule::command('erp:rentals')->dailyAt('07:35');
Artisan::command('erp:offers-remind', function () {
    $n = 0;
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('appro')) {
            app()->instance('tenant', $company);
            $n += app(\App\Modules\Appro\OfferService::class)->remind($company);
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Demandes de prix : {$n} relance(s) envoyée(s) aux fournisseurs sans réponse.");
})->purpose('Comparatifs : relance des fournisseurs consultés 2 jours avant la date limite');
Schedule::command('erp:offers-remind')->dailyAt('08:50');

Artisan::command('erp:update {--check : Vérifier seulement} {--rollback : Revenir à la version précédente}', function () {
    $u = app(\App\Services\Updates\Updater::class);
    if ($this->option('rollback')) {
        $r = $u->rollback();
        $this->{$r['ok'] ? 'info' : 'error'}(implode("\n", $r['log']));

        return $r['ok'] ? 0 : 1;
    }
    $s = $u->status(true);
    if (! $s['available']) {
        $this->info('Yomara ERP '.$s['current'].' est à jour.'.($s['error'] ? ' ('.$s['error'].')' : ''));

        return 0;
    }
    $this->info('Version '.$s['latest']['version'].' disponible (installée : '.$s['current'].').');
    if ($this->option('check')) {
        return 0;
    }
    $r = $u->install();
    $this->info(implode("\n", $r['log']));
    // Finalisation dans un nouveau processus : le nouveau code est chargé
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('artisan')).' erp:update-finish', $code);

    return $code;
})->purpose('Mises à jour à distance : vérifier, installer (ou --rollback)');
Artisan::command('erp:update-finish', function () {
    $r = app(\App\Services\Updates\Updater::class)->finish();
    $this->{$r['ok'] ? 'info' : 'error'}(implode("\n", $r['log']));

    return $r['ok'] ? 0 : 1;
})->purpose('Mises à jour : finalisation (migrations, contrôle de santé, retour arrière si échec)');
Schedule::command('erp:update --check')->everyThreeHours();
// Édition libre installée chez un client : la version stable diffusée par Yomara s'installe toute seule la nuit
Schedule::command('erp:update')->dailyAt((string) config('erp.updates.auto_install_at', '02:40'))->withoutOverlapping(120)
    ->when(fn () => config('erp.updates.auto_install') && ! config('erp.updates.publisher') && config('erp.mode') === 'single');

Artisan::command('erp:auto-publish', function () {
    if (! config('erp.updates.publisher') || ! config('erp.updates.auto_publish')) {
        return 0;
    }
    $v = (string) config('erp.version');
    if (\Illuminate\Support\Facades\DB::table('upd_releases')->where('version', $v)->where('channel', 'stable')->where('published', true)->exists()) {
        return 0;
    }
    // notes de version : section « ## <version> » de RELEASE-NOTES.md
    $notes = null;
    if (is_file(base_path('RELEASE-NOTES.md')) && preg_match('/^## '.preg_quote($v, '/').'\s*\n(.*?)(?=^## |\z)/ms', (string) file_get_contents(base_path('RELEASE-NOTES.md')), $m)) {
        $notes = trim($m[1]);
    }
    $rel = app(\App\Services\Updates\Publisher::class)->packageSelf('stable', $notes, false);
    if (empty($rel['signature'])) {
        $this->warn('Version '.$v.' empaquetée mais non publiée : activez la clé de signature (Installations › Activer).');

        return 0;
    }
    \Illuminate\Support\Facades\DB::table('upd_releases')->where('id', $rel['id'])->update(['published' => true, 'published_at' => now()]);
    $this->info('Version '.$v.' publiée pour les installations.');

    return 0;
})->purpose('Plateforme : publie automatiquement la version installée pour les installations clientes (édition libre)');
Schedule::command('erp:auto-publish')->hourly()->withoutOverlapping(60)->when(fn () => config('erp.updates.publisher') && config('erp.updates.auto_publish'));

Artisan::command('erp:woocommerce', function () {
    $r = app(\App\Modules\Woocommerce\WooSync::class)->runDue();
    $sh = app(\App\Modules\Shopify\ShopifySync::class)->runDue();
    foreach ($sh as $k => $v) {
        $r[$k] += $v;
    }
    $this->info("Boutiques WooCommerce / Shopify : {$r['stores']} boutique(s), {$r['orders']} commande(s) importée(s) ou mise(s) à jour, {$r['products']} produit(s) envoyé(s).");
})->purpose('WooCommerce : relevé des commandes et publication des produits, prix et stock modifiés');
Schedule::command('erp:woocommerce')->everyFiveMinutes()->withoutOverlapping(15);

Artisan::command('erp:backup {--now : sauvegarder tout de suite, sans attendre l\'heure réglée}', function () {
    $svc = app(\App\Backups\BackupService::class);
    $b = $this->option('now') ? $svc->run('ligne de commande') : $svc->runDue();
    $this->info($b ? "Sauvegarde {$b['name']} (".round($b['size'] / 1048576, 1).' Mo).' : 'Pas de sauvegarde prévue maintenant.');
})->purpose('Sauvegarde de la base et des fichiers (chiffrée), copie vers Google Drive / Dropbox');
Schedule::command('erp:backup')->everyFifteenMinutes()->withoutOverlapping(120);

Artisan::command('erp:restore {fichier} {--key= : clé de sauvegarde} {--sans-fichiers}', function () {
    $f = $this->argument('fichier');
    $path = is_file($f) ? $f : \App\Backups\BackupService::dir().'/'.$f;
    if (! $this->confirm('Remplacer toutes les données par celles de '.basename($path).' ?')) {
        return;
    }
    $r = app(\App\Backups\BackupService::class)->restore($path, $this->option('key'), ! $this->option('sans-fichiers'));
    $this->info("Restauré : {$r['rows']} ligne(s), {$r['files']} fichier(s). Sauvegarde de sécurité : {$r['safety']}.");
})->purpose('Restauration d\'une sauvegarde');

Artisan::command('erp:services-alerts', function () {
    $t = ['contenus' => 0, 'juridique' => 0, 'flotte' => 0, 'marches' => 0, 'location' => 0, 'maintenance' => 0, 'qualite' => 0];
    foreach (\App\Models\Company::all() as $company) {
        app()->instance('tenant', $company);
        if ($company->hasModule('contenus')) {
            $t['contenus'] += app(\App\Modules\Contenus\ContenusHooks::class)->reminders($company);
        }
        if ($company->hasModule('juridique')) {
            $t['juridique'] += app(\App\Modules\Juridique\JuridiqueHooks::class)->alerts($company);
        }
        if ($company->hasModule('flotte')) {
            $t['flotte'] += app(\App\Modules\Flotte\FlotteHooks::class)->alerts($company);
        }
        if ($company->hasModule('marches_publics')) {
            $t['marches'] += app(\App\Modules\MarchesPublics\MarchesHooks::class)->alerts($company);
        }
        if ($company->hasModule('location')) {
            $t['location'] += app(\App\Modules\Location\LocationHooks::class)->alerts($company);
        }
        if ($company->hasModule('maintenance')) {
            $t['maintenance'] += app(\App\Modules\Maintenance\MaintenanceHooks::class)->alerts($company);
        }
        if ($company->hasModule('qualite')) {
            $t['qualite'] += app(\App\Modules\Qualite\QualiteHooks::class)->alerts($company);
        }
        if ($company->hasModule('questionnaires')) {
            app(\App\Modules\Questionnaires\QuestionnaireService::class)->closeExpired($company);
        }
        app()->forgetInstance('tenant');
    }
    $this->info("Rappels : {$t['contenus']} publication(s), {$t['juridique']} contrat(s)/échéance(s) juridique(s), {$t['flotte']} alerte(s) parc auto, {$t['marches']} rappel(s) marchés, {$t['location']} rappel(s) location, {$t['maintenance']} maintenance, {$t['qualite']} qualité.");
})->purpose('Services : calendrier éditorial, échéances juridiques, parc automobile, marchés, location et maintenance préventive');
Schedule::command('erp:services-alerts')->hourly();

Artisan::command('erp:veille', function () {
    $t = ['watches' => 0, 'changes' => 0, 'alerts' => 0, 'mentions' => 0, 'audits' => 0, 'digest' => 0];
    foreach (\App\Models\Company::all() as $company) {
        if (! $company->hasModule('veille')) {
            continue;
        }
        app()->instance('tenant', $company);
        try {
            foreach (app(\App\Modules\Veille\VeilleService::class)->run($company) as $k => $v) {
                $t[$k] += $v;
            }
        } catch (\Throwable $e) {
            report($e);
        }
        app()->forgetInstance('tenant');
    }
    $this->info("Veille : {$t['watches']} page(s) visitée(s), {$t['changes']} changement(s), {$t['alerts']} mot(s)-clé(s), {$t['mentions']} mention(s), {$t['audits']} analyse(s) de site, {$t['digest']} synthèse(s).");
})->purpose('Veille concurrentielle : sites surveillés, mentions dans la presse, analyse des sites et synthèse du lundi');
Schedule::command('erp:veille')->hourly()->withoutOverlapping(50);

Artisan::command('erp:acquisition', function () {
    $t = ['sync' => 0, 'inbox' => 0];
    foreach (\App\Models\Company::where('status', '!=', 'suspended')->get() as $company) {
        app()->instance('tenant', $company);
        if ($company->hasModule('acquisition')) {
            foreach (\Illuminate\Support\Facades\DB::table('ac_accounts')->where('company_id', $company->id)->where(fn ($q) => $q->whereNull('synced_at')->orWhere('synced_at', '<=', now()->subHours(6)))->get() as $acc) {
                try {
                    $t['sync'] += app(\App\Modules\Acquisition\AdPlatforms::class)->sync($company, $acc, 7);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\DB::table('ac_accounts')->where('id', $acc->id)->update(['last_error' => mb_substr($e->getMessage(), 0, 300), 'status' => 'error', 'synced_at' => now()]);
                }
            }
        }
        if ($company->hasModule('social')) {
            $t['inbox'] += rescue(fn () => app(\App\Modules\Social\Inbox::class)->poll($company), 0);
        }
        app()->forgetInstance('tenant');
    }
    $this->info("Publicité : {$t['sync']} ligne(s) de dépenses ; boîte des réseaux : {$t['inbox']} message(s).");
})->purpose('Dépenses des comptes publicitaires (toutes les 6 h) et relevé des commentaires et messages des réseaux');
Schedule::command('erp:acquisition')->everyTenMinutes()->withoutOverlapping(30);

Artisan::command('erp:crm-sequences {--scores}', function () {
    $n = 0;
    $sc = 0;
    foreach (\App\Models\Company::all() as $company) {
        if (! $company->hasModule('crm')) {
            continue;
        }
        app()->instance('tenant', $company);
        try {
            $n += app(\App\Modules\Crm\Sequences::class)->tick($company);
            if ($this->option('scores')) {
                $sc += \App\Modules\Crm\LeadScoring::refresh($company->id);
            }
        } catch (\Throwable $e) {
            report($e);
        }
        app()->forgetInstance('tenant');
    }
    $this->info("CRM : {$n} étape(s) de relance, {$sc} note(s) de prospect recalculée(s).");
})->purpose('CRM : relances automatiques des prospects (et recalcul des notes avec --scores)');
Schedule::command('erp:crm-sequences')->everyFifteenMinutes()->withoutOverlapping(20);
Schedule::command('erp:crm-sequences --scores')->dailyAt('05:50')->withoutOverlapping(60);

Artisan::command('erp:activity-reports {--remind}', function () {
    $n = 0;
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('activite')) {
            app()->instance('tenant', $company);
            $n += app(\App\Modules\Activite\ReportService::class)->weekly($company, (bool) $this->option('remind'));
            app()->forgetInstance('tenant');
        }
    }
    $this->info("Rapports d'activité : $n ".($this->option('remind') ? 'rappel(s)' : 'brouillon(s) préparé(s)').'.');
})->purpose('Rapports d\'activité : brouillon de la semaine prêt le vendredi, rappel le lundi');
Schedule::command('erp:activity-reports')->weeklyOn(5, '16:00');
Schedule::command('erp:activity-reports --remind')->weeklyOn(1, '09:00');

Artisan::command('erp:social-publish {--stats}', function () {
    $n = 0;
    foreach (\App\Models\Company::all() as $company) {
        if ($company->hasModule('contenus') && \Illuminate\Support\Facades\Schema::hasTable('mc_social_accounts')
            && \Illuminate\Support\Facades\DB::table('mc_social_accounts')->where('company_id', $company->id)->exists()) {
            app()->instance('tenant', $company);
            $s = app(\App\Modules\Contenus\SocialPublisher::class);
            $n += $this->option('stats') ? $s->syncStats($company) : $s->due($company);
            app()->forgetInstance('tenant');
        }
    }
    $this->info($this->option('stats') ? "Réseaux sociaux : statistiques de $n publication(s) mises à jour." : "Réseaux sociaux : $n publication(s) envoyée(s).");
})->purpose('Calendrier éditorial : publication automatique sur Facebook, Instagram, LinkedIn et relevé des statistiques');
Schedule::command('erp:social-publish')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('erp:social-publish --stats')->everyThreeHours();

Artisan::command('erp:social-hub {--stats}', function () {
    $DB = \Illuminate\Support\Facades\DB::class;
    if (! \Illuminate\Support\Facades\Schema::hasTable('so_posts')) {
        return;
    }
    // Seules les sociétés qui ont quelque chose à publier ou à relever sont chargées
    $ids = $this->option('stats')
        ? $DB::table('so_targets')->where('status', 'published')->where('published_at', '>=', now()->subDays(30))->distinct()->pluck('company_id')
        : $DB::table('so_posts')->whereIn('status', ['scheduled', 'publishing'])->where('scheduled_at', '<=', now())->distinct()->pluck('company_id')
            ->merge($DB::table('so_targets')->where('status', 'failed')->whereNotNull('retry_at')->where('retry_at', '<=', now())->distinct()->pluck('company_id'))
            ->merge($DB::table('so_targets')->where('status', 'publishing')->where('updated_at', '<', now()->subMinutes(20))->distinct()->pluck('company_id'))->unique();
    $n = 0;
    foreach (\App\Models\Company::whereIn('id', $ids)->get() as $company) {
        if (! $company->hasModule('social')) {
            continue;
        }
        app()->instance('tenant', $company);
        $s = app(\App\Modules\Social\SocialService::class);
        $n += $this->option('stats') ? $s->syncStats($company) : $s->tick($company);
        app()->forgetInstance('tenant');
    }
    if ($n) {
        $this->info($this->option('stats') ? "Réseaux sociaux : statistiques de $n publication(s) relevées." : "Réseaux sociaux : $n publication(s) traitée(s).");
    }
})->purpose('Réseaux sociaux : publication des posts planifiés sur tous les réseaux, relances automatiques, relevé des statistiques');
Schedule::command('erp:social-hub')->everyMinute()->withoutOverlapping(15);
Schedule::command('erp:social-hub --stats')->hourly()->withoutOverlapping(30);

// Témoin de la tâche cron (contrôle de production)
Schedule::call(fn () => \App\Services\ProductionCheck::beat())->everyMinute()->name('erp-heartbeat');

Artisan::command('erp:doctor', function () {
    $r = app(\App\Services\ProductionCheck::class)->run();
    $icon = ['ok' => '<fg=green>OK</>', 'warn' => '<fg=yellow>À VOIR</>', 'fail' => '<fg=red>BLOQUANT</>'];
    $group = null;
    foreach ($r['checks'] as $c) {
        if ($c['group'] !== $group) {
            $this->newLine();
            $this->line('<options=bold>'.$c['group'].'</>');
            $group = $c['group'];
        }
        $this->line('  '.$icon[$c['state']].'  '.$c['label'].($c['detail'] ? ' — '.$c['detail'] : ''));
        if ($c['state'] !== 'ok' && $c['fix']) {
            $this->line('        → '.$c['fix']);
        }
    }
    $this->newLine();
    $this->line(sprintf('%d OK, %d à voir, %d bloquant(s) : %s', $r['summary']['ok'], $r['summary']['warn'], $r['summary']['fail'], $r['ready'] ? 'prêt pour la production.' : 'à corriger avant d\'ouvrir.'));

    return $r['ready'] ? 0 : 1;
})->purpose('Contrôle de production : serveur, configuration, base, tâche cron, e-mail, sécurité, sauvegardes');

Schedule::call(fn () => \App\Services\PlatformActivity::sample())->everyFiveMinutes()->name('erp-online-sample');

Artisan::command('erp:client-comms', function () {
    $r = app(\App\Platform\ClientComms::class)->tick();
    if (array_sum($r) > 0) {
        $this->info("Communication clients : {$r['launched']} campagne(s) lancée(s), {$r['automations']} relance(s), {$r['emails']} e-mail(s) envoyé(s).");
    }
})->purpose('Super admin : campagnes programmées ou récurrentes, relances automatiques et envoi des e-mails aux clients');
Schedule::command('erp:client-comms')->everyMinute()->withoutOverlapping(10);

Artisan::command('erp:tokens-prune', function () {
    // Sessions des applications inutilisées depuis 90 jours : supprimées (téléphone perdu, ancien poste…)
    $n = \Illuminate\Support\Facades\DB::table('personal_access_tokens')
        ->where(fn ($q) => $q->where('last_used_at', '<', now()->subDays(90))->orWhere(fn ($w) => $w->whereNull('last_used_at')->where('created_at', '<', now()->subDays(90))))
        ->delete();
    $this->info("Sessions inactives supprimées : {$n}.");
})->purpose('Sécurité : supprime les jetons de connexion inutilisés depuis 90 jours');
Schedule::command('erp:tokens-prune')->dailyAt('03:40');

// Dernier recours (accès au serveur) : téléphone et codes de secours perdus, notamment pour le super administrateur
Artisan::command('erp:2fa-reset {email : adresse du compte}', function (string $email) {
    $u = \App\Models\User::where('email', strtolower(trim($email)))->first();
    if (! $u) {
        $this->error('Aucun compte avec cette adresse.');

        return 1;
    }
    \App\Support\TwoFactor::disable($u, 'admin');
    $u->tokens()->delete();
    $this->info("Double authentification retirée pour {$u->email}. Reconnectez-vous avec le mot de passe".(\App\Support\TwoFactor::required($u) ? ' : elle sera demandée à nouveau.' : '.'));

    return 0;
})->purpose('Retire la double authentification d\'un compte (téléphone et codes de secours perdus)');
