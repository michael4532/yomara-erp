<?php

namespace App\Platform;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Messaging\Providers\Smtp;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\ModuleOrder;
use App\Models\PlatformAutomation;
use App\Models\PlatformCampaign;
use App\Models\PlatformDelivery;
use App\Models\User;
use App\Modules\Marketing\EmailRenderer;
use App\Services\SiteTraffic;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Communication de l'éditeur (super admin) vers ses sociétés clientes :
 * - campagnes : notification dans l'application et / ou e-mail, envoi immédiat, programmé ou récurrent ;
 * - relances automatiques : fin d'essai, échéance d'abonnement, inactivité, commande non payée…
 * Les e-mails partent par lots depuis la tâche planifiée (compatible hébergement mutualisé, sans file d'attente).
 */
class ClientComms
{
    public const SEGMENTS = [
        'all' => 'Toutes les sociétés clientes',
        'free' => 'Formule gratuite',
        'paid' => 'Abonnés payants',
        'trial' => 'En période d\'essai',
        'plans' => 'Formules choisies',
        'expiring' => 'Abonnement ou essai qui se termine bientôt',
        'inactive' => 'Inactives depuis un moment',
        'new' => 'Inscrites récemment',
        'referrers' => 'Parrains (au moins un filleul)',
        'companies' => 'Sociétés choisies',
    ];

    public const TRIGGERS = [
        'welcome' => ['Bienvenue et prise en main', 'jours après l\'inscription'],
        'trial_ending' => ['Fin d\'essai proche', 'jours avant la fin de l\'essai'],
        'trial_ended' => ['Essai terminé (passage en Gratuit)', 'jours après la fin de l\'essai'],
        'subscription_ending' => ['Abonnement bientôt échu', 'jours avant l\'échéance'],
        'subscription_ended' => ['Abonnement échu', 'jours après l\'échéance'],
        'pending_order' => ['Commande non payée', 'jours après la commande'],
        'inactive' => ['Société inactive', 'jours sans connexion'],
        'free_upsell' => ['Sur la formule gratuite', 'jours sur la formule gratuite'],
    ];

    /** Variables utilisables dans les textes : {{nom}}, {{entreprise}}… */
    public const VARIABLES = [
        'prenom' => 'Prénom', 'nom' => 'Nom complet', 'entreprise' => 'Société cliente', 'formule' => 'Formule actuelle',
        'fin_essai' => 'Date de fin d\'essai', 'fin_abonnement' => 'Date d\'échéance', 'jours_restants' => 'Jours restants',
        'lien_app' => 'Lien vers l\'application', 'lien_boutique' => 'Lien vers la boutique', 'code_parrain' => 'Code parrain',
        'lien_parrainage' => 'Lien de parrainage', 'lien_desinscription' => 'Lien de désinscription',
    ];

    public const BATCH = 60;

    // ------------------------------------------------------------------ audiences

    /** Sociétés clientes (hors société de l'éditeur, hors sociétés techniques d'installation). */
    public static function clients()
    {
        $editor = SiteTraffic::editorCompany()?->id;

        return Company::query()->where('status', '!=', 'suspended')->when($editor, fn ($q) => $q->where('companies.id', '!=', $editor));
    }

    public static function audienceQuery(array $a)
    {
        $q = self::clients()->with('plan');
        $days = max(1, min(365, (int) ($a['days'] ?? 7)));

        return match ($a['segment'] ?? 'all') {
            'free' => $q->where('status', 'active')->whereHas('plan', fn ($p) => $p->where('is_free', true)),
            'paid' => $q->where('status', 'active')->whereHas('plan', fn ($p) => $p->where('is_free', false))->where('subscription_ends_at', '>', now()),
            'trial' => $q->where('status', 'trial'),
            'plans' => $q->whereHas('plan', fn ($p) => $p->whereIn('code', (array) ($a['plans'] ?? []))),
            'expiring' => $q->where(fn ($w) => $w->where(fn ($t) => $t->where('status', 'trial')->whereBetween('trial_ends_at', [now(), now()->addDays($days)]))
                ->orWhereBetween('subscription_ends_at', [now(), now()->addDays($days)])),
            'inactive' => $q->whereNotIn('companies.id', User::where('last_seen_at', '>=', now()->subDays($days))->whereNotNull('company_id')->select('company_id')),
            'new' => $q->where('created_at', '>=', now()->subDays($days)),
            'referrers' => $q->whereIn('companies.id', Company::query()->whereNotNull('referred_by')->select('referred_by')),
            'companies' => $q->whereIn('companies.id', array_map('intval', (array) ($a['company_ids'] ?? []))),
            default => $q,
        };
    }

    /** Utilisateurs destinataires d'une société : administrateurs (gestion de la société) ou tous les actifs. */
    public static function recipients(Company $c, string $who = 'admins'): Collection
    {
        $users = User::where('company_id', $c->id)->where('is_active', true)->where('is_super_admin', false)->get();

        return $who === 'all' ? $users : $users->filter(fn (User $u) => $u->hasPermission('core.settings.manage'))->values();
    }

    public function count(array $audience): array
    {
        $companies = self::audienceQuery($audience)->get();
        $users = 0;
        $emails = 0;
        $unsub = DB::table('platform_unsubscribes')->pluck('email')->flip();
        foreach ($companies as $c) {
            $r = self::recipients($c, $audience['recipients'] ?? 'admins');
            $users += $r->count();
            $emails += $r->filter(fn ($u) => $u->email && ! isset($unsub[mb_strtolower($u->email)]))->count();
        }

        return ['companies' => $companies->count(), 'users' => $users, 'emails' => $emails,
            'sample' => $companies->take(8)->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'plan' => $c->plan?->name])->values()];
    }

    // ------------------------------------------------------------------ contenu

    public static function vars(Company $c, ?User $u, ?string $unsubUrl = null): array
    {
        $end = $c->status === 'trial' ? $c->trial_ends_at : $c->subscription_ends_at;
        $base = rtrim(config('app.url'), '/');
        $code = rescue(fn () => \App\Store\Referrals::code($c), '', false);
        $name = (string) ($u?->name ?: $c->name);

        return [
            'prenom' => Str::before(trim($name), ' ') ?: $name, 'nom' => $name, 'entreprise' => $c->name,
            'formule' => (string) ($c->plan?->name ?? ''),
            'fin_essai' => $c->trial_ends_at?->format('d/m/Y') ?? '', 'fin_abonnement' => $c->subscription_ends_at?->format('d/m/Y') ?? '',
            'jours_restants' => $end ? (string) max(0, (int) now()->startOfDay()->diffInDays($end->copy()->startOfDay(), false)) : '',
            'lien_app' => $base.'/app/', 'lien_boutique' => $base.'/app/', 'code_parrain' => $code,
            'lien_parrainage' => $code ? $base.'/inscription?parrain='.$code : '', 'lien_desinscription' => $unsubUrl ?? '#',
        ];
    }

    /** Texte brut (notification) à partir du contenu. */
    public static function plain(PlatformCampaign|PlatformAutomation $m, array $vars): string
    {
        $t = trim((string) $m->body_text);
        if ($t === '' && $m->format === 'html') {
            $t = trim(html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div|/h\d|/tr)\b[^>]*>#i', "\n", (string) $m->html))));
        }
        if ($t === '' && $m->format === 'design') {
            $t = collect((array) $m->blocks)->map(fn ($b) => $b['text'] ?? ($b['title'] ?? null))->filter()->implode("\n\n");
        }
        $t = preg_replace('/\*\*(.+?)\*\*|\[([^\]]+)\]\([^)]+\)/', '$1$2', $t);

        return mb_substr(trim(preg_replace("/\n{3,}/", "\n\n", EmailRenderer::vars($t, $vars))), 0, 2000);
    }

    /** E-mail complet (HTML) personnalisé. */
    public function emailHtml(PlatformCampaign|PlatformAutomation $m, array $vars): string
    {
        $editor = SiteTraffic::editorCompany() ?? new Company(['name' => config('erp.name')]);
        $r = new EmailRenderer($editor);
        $cta = $m->cta_label && $m->cta_url ? ['type' => 'button', 'label' => $m->cta_label, 'url' => EmailRenderer::vars($m->cta_url, $vars), 'align' => 'center'] : null;
        $blocks = match ($m->format) {
            'design' => (array) $m->blocks,
            'html' => null,
            default => array_values(array_filter([
                $m->title ? ['type' => 'heading', 'text' => EmailRenderer::vars((string) $m->title, $vars), 'size' => 'h2'] : null,
                ['type' => 'text', 'text' => (string) $m->body_text],
                $cta,
            ])),
        };
        if ($m->format === 'design' && $cta && ! collect($blocks)->contains(fn ($b) => ($b['type'] ?? '') === 'button')) {
            $blocks[] = $cta;
        }
        $html = $r->render($blocks, $m->format === 'html' ? (string) $m->html : null, (array) $m->style, $vars + ['subject' => (string) $m->subject], $m->preheader);
        // Les variables des blocs (liens compris) sont résolues après le rendu
        $html = EmailRenderer::vars($html, array_map(fn ($v) => e((string) $v), $vars));

        return $html;
    }

    /** Liens suivis (clics signés, pas de redirection ouverte) et pixel d'ouverture. */
    public static function track(string $html, string $token): string
    {
        $html = preg_replace_callback('/href="(https?:\/\/[^"]+)"/i', function ($m) use ($token) {
            $u = html_entity_decode($m[1]);
            if (str_contains($u, '/c/u/')) {
                return $m[0];
            }
            $enc = rtrim(strtr(base64_encode($u), '+/', '-_'), '=');

            return 'href="'.url('/c/c/'.$token).'?u='.$enc.'&amp;s='.substr(hash_hmac('sha256', $token.'|'.$u, (string) config('app.key')), 0, 24).'"';
        }, $html);
        $pixel = '<img src="'.url('/c/o/'.$token.'.gif').'" width="1" height="1" alt="" style="display:block;border:0;">';

        return str_contains($html, '</body>') ? str_replace('</body>', $pixel.'</body>', $html) : $html.$pixel;
    }

    public static function validClick(string $token, string $url, string $sig): bool
    {
        return hash_equals(substr(hash_hmac('sha256', $token.'|'.$url, (string) config('app.key')), 0, 24), $sig);
    }

    /** Envoi par le serveur d'e-mails de la plateforme (super admin › Serveur e-mail), sinon par celui du fichier .env. */
    /** @param array<int, array{name: string, data: string, mime?: string}> $attachments */
    public static function mail(string $to, ?string $name, string $subject, string $html, string $text, array $headers = [], array $attachments = []): void
    {
        if (MessagingService::platformMailReady()) {
            $r = (new Smtp)->send(new Message(to: $to, toName: $name, subject: $subject, html: $html, text: $text, headers: $headers, attachments: $attachments), MessagingService::platformMail());
            if (! $r->ok) {
                throw new \RuntimeException((string) ($r->error ?: 'Envoi refusé par le serveur d\'e-mails.'));
            }

            return;
        }
        Mail::html($html, function ($msg) use ($to, $name, $subject, $headers, $attachments) {
            $msg->to($to, $name)->subject($subject);
            foreach ($attachments as $a) {
                $msg->attachData($a['data'], $a['name'], ['mime' => $a['mime'] ?? 'application/octet-stream']);
            }
            foreach ($headers as $k => $v) {
                $msg->getHeaders()->addTextHeader($k, $v);
            }
        });
    }

    // ------------------------------------------------------------------ envoi

    /**
     * Prépare un envoi : notifications posées tout de suite, e-mails mis en attente (partis par lots).
     *
     * @return array{companies:int, notified:int, emails:int}
     */
    public function dispatch(PlatformCampaign|PlatformAutomation $m, Collection $companies, string $who, int $run = 1, ?string $occurrence = null): array
    {
        $channels = (array) $m->channels;
        $isCampaign = $m instanceof PlatformCampaign;
        $unsub = DB::table('platform_unsubscribes')->pluck('email')->flip();
        $out = ['companies' => 0, 'notified' => 0, 'emails' => 0];
        foreach ($companies as $c) {
            $users = self::recipients($c, $who);
            if ($users->isEmpty()) {
                continue;
            }
            $out['companies']++;
            if (in_array('notification', $channels, true)) {
                $ref = ($isCampaign ? 'c' : 'a').$m->id;
                foreach ($users as $u) {
                    $vars = self::vars($c, $u); // personnalisée pour chacun : « Bonjour Awa, »
                    $title = mb_substr(EmailRenderer::vars((string) ($m->title ?: $m->subject ?: $m->name), $vars), 0, 255);
                    AppNotification::send($c->id, [$u->id], 'announcement', $title, self::plain($m, $vars), 'announcement', $ref);
                    PlatformDelivery::create(['campaign_id' => $isCampaign ? $m->id : null, 'automation_id' => $isCampaign ? null : $m->id, 'run' => $run,
                        'company_id' => $c->id, 'user_id' => $u->id, 'channel' => 'notification', 'token' => Str::random(40), 'status' => 'sent', 'sent_at' => now()]);
                    $out['notified']++;
                }
            }
            if (in_array('email', $channels, true)) {
                $seen = [];
                foreach ($users as $u) {
                    $email = mb_strtolower(trim((string) $u->email));
                    if ($email === '' || isset($seen[$email]) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        continue;
                    }
                    $seen[$email] = true;
                    PlatformDelivery::create(['campaign_id' => $isCampaign ? $m->id : null, 'automation_id' => $isCampaign ? null : $m->id, 'run' => $run,
                        'company_id' => $c->id, 'user_id' => $u->id, 'channel' => 'email', 'email' => $email, 'token' => Str::random(40),
                        'status' => isset($unsub[$email]) ? 'skipped' : 'pending', 'error' => isset($unsub[$email]) ? 'Désinscrit' : null]);
                    $out['emails'] += isset($unsub[$email]) ? 0 : 1;
                }
            }
            if ($occurrence !== null) {
                DB::table('platform_automation_logs')->insertOrIgnore(['automation_id' => $m->id, 'company_id' => $c->id, 'occurrence' => $occurrence, 'created_at' => now()]);
            }
        }

        return $out;
    }

    /** Lance une campagne maintenant (nouvelle exécution pour une campagne récurrente). */
    public function launch(PlatformCampaign $c): array
    {
        $run = $c->runs + 1;
        $out = $this->dispatch($c, self::audienceQuery((array) $c->audience)->get(), $c->audience['recipients'] ?? 'admins', $run);
        $next = $c->schedule === 'recurring' ? $c->nextOccurrence(now()->addMinute()) : null;
        $c->forceFill([
            'runs' => $run, 'last_run_at' => now(), 'next_run_at' => $next,
            'status' => $c->schedule === 'recurring' && $next ? 'scheduled' : ($out['emails'] > 0 ? 'sending' : 'sent'),
        ])->save();
        $this->refreshStats($c);

        return $out;
    }

    /** Envoie un lot d'e-mails en attente ; renvoie le nombre envoyé. */
    public function sendPending(int $limit = self::BATCH): int
    {
        $n = 0;
        $cache = [];
        foreach (PlatformDelivery::where('channel', 'email')->where('status', 'pending')->orderBy('id')->limit($limit)->get() as $d) {
            $m = $d->campaign_id ? ($cache['c'.$d->campaign_id] ??= PlatformCampaign::find($d->campaign_id)) : ($cache['a'.$d->automation_id] ??= PlatformAutomation::find($d->automation_id));
            $company = Company::find($d->company_id);
            if (! $m || ! $company || ($m instanceof PlatformCampaign && $m->status === 'paused')) {
                if (! $m || ! $company) {
                    $d->forceFill(['status' => 'skipped', 'error' => 'Supprimé'])->save();
                }

                continue;
            }
            $unsubUrl = url('/c/u/'.$d->token);
            $vars = self::vars($company, $d->user_id ? User::find($d->user_id) : null, $unsubUrl);
            try {
                $html = self::track($this->emailHtml($m, $vars), $d->token);
                $subject = EmailRenderer::vars((string) ($m->subject ?: $m->title ?: $m->name), $vars);
                self::mail($d->email, $vars['nom'], $subject, $html, self::plain($m, $vars)."\n\n".$vars['lien_app']."\n\nSe désinscrire : ".$unsubUrl, ['List-Unsubscribe' => '<'.$unsubUrl.'>']);
                $d->forceFill(['status' => 'sent', 'sent_at' => now(), 'error' => null])->save();
                $n++;
            } catch (\Throwable $e) {
                $d->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250)])->save();
            }
        }
        foreach ($cache as $m) {
            if ($m instanceof PlatformCampaign) {
                if ($m->status === 'sending' && ! PlatformDelivery::where('campaign_id', $m->id)->where('status', 'pending')->exists()) {
                    $m->forceFill(['status' => 'sent'])->save();
                }
                $this->refreshStats($m);
            }
        }

        return $n;
    }

    public function refreshStats(PlatformCampaign $c): void
    {
        $q = fn () => PlatformDelivery::where('campaign_id', $c->id);
        $c->forceFill(['stats' => [
            'companies' => $q()->distinct()->count('company_id'),
            'notified' => $q()->where('channel', 'notification')->count(),
            'emails' => $q()->where('channel', 'email')->whereIn('status', ['sent', 'pending', 'failed'])->count(),
            'sent' => $q()->where('channel', 'email')->where('status', 'sent')->count(),
            'pending' => $q()->where('channel', 'email')->where('status', 'pending')->count(),
            'failed' => $q()->where('channel', 'email')->where('status', 'failed')->count(),
            'opened' => $q()->where('channel', 'email')->whereNotNull('opened_at')->count(),
            'clicked' => $q()->where('channel', 'email')->whereNotNull('clicked_at')->count(),
            'unsubscribed' => $q()->where('status', 'skipped')->count(),
        ]])->saveQuietly();
    }

    /** Tâche planifiée : campagnes dues, lot d'e-mails, relances (une fois par heure). */
    public function tick(): array
    {
        $launched = 0;
        foreach (PlatformCampaign::where('status', 'scheduled')->where(fn ($q) => $q->where(fn ($w) => $w->where('schedule', 'once')->where('send_at', '<=', now()))
            ->orWhere(fn ($w) => $w->where('schedule', 'recurring')->where('next_run_at', '<=', now())))->get() as $c) {
            $this->launch($c);
            $launched++;
        }
        $relances = 0;
        $last = (string) \App\Models\PlatformSetting::get('comms.automations_at', '');
        if ($last === '' || \Carbon\Carbon::parse($last)->lt(now()->subHour())) {
            \App\Models\PlatformSetting::put('comms.automations_at', now()->toIso8601String());
            $relances = $this->runAutomations();
        }

        return ['launched' => $launched, 'automations' => $relances, 'emails' => $this->sendPending()];
    }

    // ------------------------------------------------------------------ relances automatiques

    /** Sociétés concernées par une relance, avec l'occurrence (pour n'envoyer qu'une fois). */
    public function automationTargets(PlatformAutomation $a): Collection
    {
        $d = max(0, (int) $a->days);
        $day = fn (int $offset) => [now()->addDays($offset)->startOfDay(), now()->addDays($offset)->endOfDay()];
        $base = self::clients()->with('plan');
        $rows = match ($a->trigger) {
            'welcome' => $base->whereBetween('created_at', [now()->subDays($d + 2)->startOfDay(), now()->subDays($d)->endOfDay()])->get()->map(fn ($c) => [$c, 'signup']),
            // Fenêtre de 2 jours : rattrape un passage de la tâche planifiée manqué
            'trial_ending' => $base->where('status', 'trial')->whereBetween('trial_ends_at', [now(), now()->addDays($d)->endOfDay()])->get()->map(fn ($c) => [$c, 'trial:'.$c->trial_ends_at->toDateString()]),
            // Passage automatique en Gratuit à la fin de l'essai (journal de la boutique)
            'trial_ended' => \App\Models\ActivityLog::where('action', 'store.plan.downgraded')->where('subject_type', 'Company')
                ->whereBetween('created_at', [now()->subDays($d + 2)->startOfDay(), now()->subDays($d)->endOfDay()])->get()
                ->filter(fn ($l) => ($l->properties['reason'] ?? '') === 'trial')
                ->map(fn ($l) => [(clone $base)->find($l->subject_id), 'trialend:'.$l->created_at->toDateString()])->filter(fn ($r) => $r[0] && $r[0]->plan?->is_free),
            'subscription_ending' => $base->where('status', 'active')->whereHas('plan', fn ($p) => $p->where('is_free', false))->whereBetween('subscription_ends_at', [now(), now()->addDays($d)->endOfDay()])->get()->map(fn ($c) => [$c, 'sub:'.$c->subscription_ends_at->toDateString()]),
            'subscription_ended' => $base->whereBetween('subscription_ends_at', [now()->subDays($d + 2)->startOfDay(), now()->subDays($d)->endOfDay()])->get()->map(fn ($c) => [$c, 'subend:'.$c->subscription_ends_at->toDateString()]),
            'pending_order' => $base->whereIn('companies.id', ModuleOrder::whereIn('status', ['pending'])->where('created_at', '<=', now()->subDays($d))->where('created_at', '>=', now()->subDays($d + 5))->select('company_id'))->get()
                ->map(fn ($c) => [$c, 'order:'.ModuleOrder::where('company_id', $c->id)->where('status', 'pending')->max('id')]),
            'inactive' => $base->where('created_at', '<=', now()->subDays($d))->whereNotIn('companies.id', User::where('last_seen_at', '>=', now()->subDays(max(1, $d)))->whereNotNull('company_id')->select('company_id'))->get()
                ->map(fn ($c) => [$c, 'inactive:'.(User::where('company_id', $c->id)->max('last_seen_at') ?? 'jamais')]),
            'free_upsell' => $base->where('status', 'active')->whereHas('plan', fn ($p) => $p->where('is_free', true))->where('created_at', '<=', now()->subDays($d))->get()
                ->map(fn ($c) => [$c, 'free:'.now()->format('Y').'-'.ceil(now()->month / 3)]), // au plus une fois par trimestre
            default => collect(),
        };
        $done = DB::table('platform_automation_logs')->where('automation_id', $a->id)->get(['company_id', 'occurrence'])->map(fn ($r) => $r->company_id.'|'.$r->occurrence)->flip();

        return collect($rows)->reject(fn ($r) => isset($done[$r[0]->id.'|'.$r[1]]))->values();
    }

    public function runAutomations(): int
    {
        $n = 0;
        foreach (PlatformAutomation::where('is_active', true)->get() as $a) {
            $sent = 0;
            foreach ($this->automationTargets($a)->groupBy(fn ($r) => $r[1]) as $occ => $rows) {
                $out = $this->dispatch($a, collect($rows)->map(fn ($r) => $r[0]), $a->recipients ?: 'admins', 1, (string) $occ);
                $sent += $out['companies'];
            }
            $a->forceFill(['sent' => $a->sent + $sent, 'last_run_at' => now()])->save();
            $n += $sent;
        }

        return $n;
    }

    /** Relances proposées par défaut (désactivées : le super admin les relit puis les active). */
    public static function ensureDefaults(): void
    {
        $defaults = [
            'welcome' => [1, 'Bien démarrer avec {{entreprise}}', "Bonjour {{prenom}},\n\nVotre espace **{{entreprise}}** est prêt. Trois gestes pour bien démarrer :\n\n1. Ajoutez vos articles et vos clients (ou importez-les depuis Excel).\n2. Invitez vos collaborateurs : ils sont inclus, sans limite.\n3. Faites votre première vente ou facture.\n\nLe guide de démarrage s'affiche dans l'application, et notre équipe répond à vos questions.", 'Ouvrir Yomara ERP', '{{lien_app}}', ['notification', 'email']],
            'trial_ending' => [3, 'Votre essai se termine dans {{jours_restants}} jour(s)', "Bonjour {{prenom}},\n\nL'essai de la formule **{{formule}}** pour {{entreprise}} se termine le {{fin_essai}}. Pour garder toutes vos fonctions, choisissez votre formule dans « Boutique de modules › Mon abonnement ».\n\nSans action de votre part, vous passez sur la formule gratuite : vos données restent intactes.", 'Choisir ma formule', '{{lien_boutique}}', ['notification', 'email']],
            'trial_ended' => [1, 'Votre essai est terminé : vos données vous attendent', "Bonjour {{prenom}},\n\n{{entreprise}} est maintenant sur la formule gratuite : ventes, achats, stock et clients restent disponibles pour toute votre équipe.\n\nVous aviez aimé les autres fonctions ? Réactivez-les en un clic depuis la boutique.", 'Voir les formules', '{{lien_boutique}}', ['notification', 'email']],
            'subscription_ending' => [7, 'Votre abonnement arrive à échéance le {{fin_abonnement}}', "Bonjour {{prenom}},\n\nL'abonnement **{{formule}}** de {{entreprise}} arrive à échéance le {{fin_abonnement}}. Renouvelez-le depuis « Boutique de modules › Mon abonnement » pour continuer sans interruption (−25 % en payant à l'année).", 'Renouveler', '{{lien_boutique}}', ['notification', 'email']],
            'subscription_ended' => [1, 'Votre abonnement est échu', "Bonjour {{prenom}},\n\nL'abonnement de {{entreprise}} est arrivé à échéance. Renouvelez-le pour retrouver toutes vos fonctions : vos données sont conservées.", 'Renouveler', '{{lien_boutique}}', ['notification', 'email']],
            'pending_order' => [2, 'Votre commande attend son paiement', "Bonjour {{prenom}},\n\nUne commande de {{entreprise}} attend toujours son paiement. Finalisez-la depuis « Boutique de modules › Mes commandes », ou répondez à ce message si vous avez besoin d'aide.", 'Voir ma commande', '{{lien_boutique}}', ['notification', 'email']],
            'inactive' => [14, '{{entreprise}} vous attend', "Bonjour {{prenom}},\n\nNous ne vous avons pas vu depuis quelque temps. Besoin d'un coup de main pour reprendre ? Notre équipe peut vous accompagner gratuitement.", 'Reprendre', '{{lien_app}}', ['email']],
            'free_upsell' => [30, 'Allez plus loin avec Yomara ERP', "Bonjour {{prenom}},\n\nDepuis un mois, {{entreprise}} utilise la formule gratuite. Caisse, trésorerie, facture normalisée FNE, relances des impayés : ajoutez seulement ce dont vous avez besoin, module par module.\n\nEt parrainez vos partenaires avec votre code **{{code_parrain}}** pour gagner du crédit.", 'Découvrir les modules', '{{lien_boutique}}', ['notification', 'email']],
        ];
        foreach ($defaults as $trigger => [$days, $subject, $text, $cta, $url, $channels]) {
            PlatformAutomation::firstOrCreate(['trigger' => $trigger], [
                'name' => self::TRIGGERS[$trigger][0], 'days' => $days, 'channels' => $channels, 'subject' => $subject, 'title' => $subject,
                'body_text' => $text, 'format' => 'text', 'cta_label' => $cta, 'cta_url' => $url, 'recipients' => 'admins', 'is_active' => false,
            ]);
        }
    }
}
