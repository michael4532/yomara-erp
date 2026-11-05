<?php

namespace App\Modules\Marketing;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Marketing\Models\Automation;
use App\Modules\Marketing\Models\AutomationRun;
use App\Modules\Marketing\Models\CampaignMessage;
use App\Modules\Marketing\Models\Contact;
use App\Modules\Marketing\Models\MkList;
use App\Modules\Marketing\Models\Template;
use Illuminate\Support\Str;

/**
 * Exécution des scénarios automatiques.
 *
 * Les étapes forment un arbre : une condition a deux branches (« yes » et « no »), et le scénario
 * reprend après la condition quand la branche est terminée. La position d'un contact est un
 * curseur du type « 2.yes.0 » (3e étape, branche oui, 1re étape).
 */
class AutomationEngine
{
    public const MAX_STEPS_PER_TICK = 30;

    public function __construct(protected MessagingService $messaging) {}

    // ---------- Déclencheurs ----------

    /** Un contact vient d'entrer dans des listes (ajout manuel, import, formulaire confirmé). */
    public function listJoined(Contact $c, array $listIds, ?int $formId = null): void
    {
        foreach ($this->active() as $a) {
            $cfg = (array) $a->trigger_config;
            $ok = match ($a->trigger) {
                'list_join' => in_array((int) ($cfg['list_id'] ?? 0), array_map('intval', $listIds), true),
                'form' => $formId !== null && (empty($cfg['form_id']) || (int) $cfg['form_id'] === $formId),
                default => false,
            };
            if ($ok) {
                $this->enroll($a, $c);
            }
        }
    }

    public function tagsAdded(Contact $c, array $tags): void
    {
        $tags = array_map(fn ($t) => mb_strtolower(trim($t)), $tags);
        foreach ($this->active()->where('trigger', 'tag') as $a) {
            if (in_array(mb_strtolower(trim((string) ($a->trigger_config['tag'] ?? ''))), $tags, true)) {
                $this->enroll($a, $c);
            }
        }
    }

    /** Prospect arrivé d'une publicité, d'une page de capture ou d'un formulaire externe (option : seulement une plateforme). */
    public function leadReceived(Contact $c, string $platform): void
    {
        foreach ($this->active()->where('trigger', 'lead_ads') as $a) {
            $want = (string) ($a->trigger_config['platform'] ?? '');
            if ($want === '' || $want === $platform) {
                $this->enroll($a, $c, (bool) $a->allow_reentry);
            }
        }
    }

    /** Contact relié au client d'une vente validée (le contact est créé s'il manque). */
    public static function contactForPartner(object $partner): ?Contact
    {
        if (empty($partner->email) && empty($partner->phone)) {
            return null;
        }
        $c = Contact::where('partner_id', $partner->id)->first()
            ?? (! empty($partner->email) ? Contact::where('email', mb_strtolower($partner->email))->first() : null)
            ?? (! empty($partner->phone) ? Contact::where('phone', $partner->phone)->first() : null);
        if (! $c) {
            [$first, $last] = str_contains((string) $partner->name, ' ') ? explode(' ', (string) $partner->name, 2) : [$partner->name, null];
            $c = Contact::create(['first_name' => $first, 'last_name' => $last, 'email' => ! empty($partner->email) ? mb_strtolower($partner->email) : null, 'phone' => $partner->phone ?: null, 'source' => 'partner', 'partner_id' => $partner->id]);
        } elseif (! $c->partner_id) {
            $c->forceFill(['partner_id' => $partner->id])->saveQuietly();
        }

        return $c;
    }

    /** Achat validé : scénarios « Achat » (option : montant minimum, premier achat seulement). */
    public function purchased(object $partner, float $amount, bool $first): void
    {
        $list = $this->active()->where('trigger', 'purchase');
        if ($list->isEmpty()) {
            return;
        }
        $c = self::contactForPartner($partner);
        if (! $c) {
            return;
        }
        foreach ($list as $a) {
            $cfg = (array) $a->trigger_config;
            if ((float) ($cfg['min_amount'] ?? 0) > $amount || (! empty($cfg['first_only']) && ! $first)) {
                continue;
            }
            $this->enroll($a, $c, (bool) $a->allow_reentry);
        }
    }

    /** Clients sans achat depuis N jours (scénario « Client sans achat »), une fois par jour. */
    public function inactiveCustomers(Company $company): int
    {
        $n = 0;
        foreach ($this->active()->where('trigger', 'inactive_customer') as $a) {
            $days = max(7, (int) ($a->trigger_config['days'] ?? 90));
            $partners = \Illuminate\Support\Facades\DB::table('trade_documents')->where('company_id', $company->id)->where('direction', 'sale')->whereIn('type', ['invoice', 'order', 'receipt'])
                ->whereNull('deleted_at')->whereNotNull('partner_id')->groupBy('partner_id')->havingRaw('MAX(date) = ?', [now()->subDays($days)->toDateString()])->pluck('partner_id');
            foreach (\Illuminate\Support\Facades\DB::table('partners')->whereIn('id', $partners)->get() as $p) {
                $c = self::contactForPartner($p);
                if ($c && $this->enroll($a, $c, true)) {
                    $n++;
                }
            }
        }

        return $n;
    }

    /** Anniversaires du jour (ou dans N jours), à lancer une fois par jour. */
    public function birthdays(Company $company): int
    {
        $n = 0;
        foreach ($this->active()->where('trigger', 'birthday') as $a) {
            $day = now()->addDays(max(0, (int) ($a->trigger_config['days_before'] ?? 0)));
            foreach (Contact::whereNotNull('birthday')->whereMonth('birthday', $day->month)->whereDay('birthday', $day->day)->get() as $c) {
                // Un seul passage par an et par contact
                $already = AutomationRun::where('automation_id', $a->id)->where('contact_id', $c->id)->where('created_at', '>=', now()->startOfYear())->exists();
                if (! $already && $this->enroll($a, $c, true)) {
                    $n++;
                }
            }
        }

        return $n;
    }

    protected function active()
    {
        return Automation::where('status', 'active')->get();
    }

    public function enroll(Automation $a, Contact $c, bool $force = false): ?AutomationRun
    {
        if ($a->status !== 'active' || empty($a->steps)) {
            return null;
        }
        $q = AutomationRun::where('automation_id', $a->id)->where('contact_id', $c->id);
        if ((clone $q)->where('status', 'active')->exists()) {
            return null;
        }
        if (! $force && ! $a->allow_reentry && $q->exists()) {
            return null;
        }
        $run = AutomationRun::create(['automation_id' => $a->id, 'contact_id' => $c->id, 'cursor' => '0', 'status' => 'active', 'next_run_at' => now(), 'history' => []]);

        return $run;
    }

    // ---------- Navigation dans l'arbre des étapes ----------

    /** @return array{0: ?array, 1: array} étape au curseur, et la liste qui la contient */
    public static function stepAt(array $steps, string $cursor): ?array
    {
        $parts = explode('.', $cursor);
        $list = $steps;
        $step = null;
        for ($i = 0; $i < count($parts); $i++) {
            $p = $parts[$i];
            if ($p === 'yes' || $p === 'no') {
                $list = (array) ($step[$p] ?? []);

                continue;
            }
            $step = $list[(int) $p] ?? null;
            if ($step === null) {
                return null;
            }
        }

        return $step;
    }

    /** Curseur de l'étape suivante (après la branche, on reprend derrière la condition). */
    public static function next(array $steps, string $cursor): ?string
    {
        $parts = explode('.', $cursor);
        while ($parts) {
            $last = (int) array_pop($parts);
            $candidate = implode('.', array_merge($parts, [(string) ($last + 1)]));
            if (self::stepAt($steps, $candidate) !== null) {
                return $candidate;
            }
            if (! $parts) {
                return null;
            }
            array_pop($parts); // retire « yes » / « no » : on remonte à la condition
        }

        return null;
    }

    // ---------- Exécution ----------

    /** Fait avancer les contacts dont l'étape est due. */
    public function tick(Company $company, int $limit = 200): int
    {
        $n = 0;
        $runs = AutomationRun::where('status', 'active')->where('next_run_at', '<=', now())->orderBy('next_run_at')->limit($limit)->get();
        $touched = [];
        foreach ($runs as $run) {
            $this->advance($company, $run);
            $touched[$run->automation_id] = true;
            $n++;
        }
        foreach (array_keys($touched) as $id) {
            Automation::find($id)?->refreshStats();
        }

        return $n;
    }

    public function advance(Company $company, AutomationRun $run): void
    {
        $a = $run->automation;
        $contact = $run->contact;
        if (! $a || ! $contact || $a->status !== 'active') {
            if ($a && $a->status === 'paused') {
                return; // reprend quand le scénario est réactivé
            }
            $run->update(['status' => 'exited', 'next_run_at' => null]);

            return;
        }
        $steps = (array) $a->steps;
        $history = (array) $run->history;
        for ($i = 0; $i < self::MAX_STEPS_PER_TICK; $i++) {
            $step = self::stepAt($steps, $run->cursor);
            if ($step === null) {
                $run->forceFill(['status' => 'done', 'next_run_at' => null, 'history' => $history])->save();

                return;
            }
            $type = $step['type'] ?? '';
            $cfg = (array) ($step['config'] ?? []);
            try {
                $res = $this->execute($company, $a, $run, $contact, $type, $cfg, (string) ($step['id'] ?? $run->cursor));
            } catch (\Throwable $e) {
                $history[] = ['at' => now()->toIso8601String(), 'step' => $type, 'result' => 'erreur : '.mb_substr($e->getMessage(), 0, 200)];
                $run->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'next_run_at' => null, 'history' => $history])->save();

                return;
            }
            $history[] = ['at' => now()->toIso8601String(), 'step' => $type, 'result' => $res['label'] ?? 'ok'];
            if (($res['action'] ?? null) === 'exit') {
                $run->forceFill(['status' => 'exited', 'next_run_at' => null, 'history' => $history])->save();

                return;
            }
            if (($res['action'] ?? null) === 'branch') {
                $branch = $res['branch'];
                $first = $run->cursor.'.'.$branch.'.0';
                $next = self::stepAt($steps, $first) !== null ? $first : self::next($steps, $run->cursor);
            } else {
                $next = self::next($steps, $run->cursor);
            }
            if ($next === null) {
                $run->forceFill(['status' => 'done', 'next_run_at' => null, 'history' => $history, 'cursor' => $run->cursor])->save();

                return;
            }
            $run->cursor = $next;
            if (($res['action'] ?? null) === 'wait') {
                $run->forceFill(['next_run_at' => $res['until'], 'history' => $history])->save();

                return;
            }
        }
        $run->forceFill(['next_run_at' => now(), 'history' => $history])->save();
    }

    /** @return array{action?: string, label?: string, until?: \Carbon\Carbon, branch?: string} */
    protected function execute(Company $company, Automation $a, AutomationRun $run, Contact $c, string $type, array $cfg, string $stepId): array
    {
        switch ($type) {
            case 'wait':
                $amount = max(1, (int) ($cfg['amount'] ?? 1));
                $until = match ($cfg['unit'] ?? 'days') {
                    'minutes' => now()->addMinutes($amount),
                    'hours' => now()->addHours($amount),
                    'weeks' => now()->addWeeks($amount),
                    default => now()->addDays($amount),
                };

                return ['action' => 'wait', 'until' => $until, 'label' => 'attente jusqu\'au '.$until->format('d/m H:i')];

            case 'email':
            case 'sms':
            case 'whatsapp':
                return $this->send($company, $a, $run, $c, $type, $cfg, $stepId);

            case 'condition':
                $last = $run->last_message_id ? CampaignMessage::withoutGlobalScopes()->find($run->last_message_id) : null;
                $v = $cfg['value'] ?? null;
                $ok = match ($cfg['check'] ?? '') {
                    'opened' => (bool) $last?->opened_at,
                    'clicked' => (bool) $last?->clicked_at,
                    'has_tag' => in_array(mb_strtolower(trim((string) $v)), $c->tagList(), true),
                    'in_list' => $c->lists()->where('mk_lists.id', (int) $v)->exists(),
                    'has_email' => (bool) $c->email,
                    'has_phone' => (bool) $c->phone,
                    'score_min' => $c->score >= (int) $v,
                    'purchased' => $c->partner_id && \Illuminate\Support\Facades\DB::table('trade_documents')->where('partner_id', $c->partner_id)->where('direction', 'sale')->whereIn('type', ['invoice', 'order', 'receipt'])
                        ->whereNull('deleted_at')->where('created_at', '>=', $run->created_at)->exists(),
                    'spent_min' => $c->partner_id && (float) \Illuminate\Support\Facades\DB::table('trade_documents')->where('partner_id', $c->partner_id)->where('direction', 'sale')->where('type', 'invoice')->whereNull('deleted_at')->sum('total') >= (float) $v,
                    default => false,
                };

                return ['action' => 'branch', 'branch' => $ok ? 'yes' : 'no', 'label' => $ok ? 'oui' : 'non'];

            case 'add_list':
            case 'remove_list':
                $list = MkList::find((int) ($cfg['list_id'] ?? 0));
                if ($list) {
                    $type === 'add_list' ? $c->lists()->syncWithoutDetaching([$list->id]) : $c->lists()->detach($list->id);
                }

                return ['label' => $list?->name ?? 'liste introuvable'];

            case 'add_tag':
            case 'remove_tag':
                $tag = mb_strtolower(trim((string) ($cfg['tag'] ?? '')));
                $tags = $c->tagList();
                $tags = $type === 'add_tag' ? array_values(array_unique([...$tags, $tag])) : array_values(array_diff($tags, [$tag]));
                $c->forceFill(['tags' => implode(', ', array_filter($tags))])->saveQuietly();

                return ['label' => $tag];

            case 'notify':
                $users = User::where('company_id', $company->id)->where('is_active', true)->get()->filter(fn ($u) => $u->is_super_admin || $u->hasPermission('marketing.manage'))->pluck('id')->all();
                $text = EmailRenderer::vars((string) ($cfg['message'] ?? '{{nom_complet}} est arrivé à cette étape.'), $c->vars() + ['entreprise' => $company->name]);
                AppNotification::send($company->id, $users, 'automation', $a->name, $text.' ('.($c->email ?: $c->phone).')', 'marketing', (string) $a->id);

                return ['label' => 'équipe prévenue'];

            case 'crm_lead':
                if (! $company->hasModule('crm') || $c->lead_id) {
                    return ['label' => 'ignoré'];
                }
                $lead = \App\Modules\Crm\Models\Lead::create([
                    'title' => EmailRenderer::vars((string) ($cfg['title'] ?? 'Opportunité — {{nom_complet}}'), $c->vars()),
                    'contact_name' => $c->full_name, 'company_name' => $c->company_name, 'email' => $c->email, 'phone' => $c->phone,
                    'source' => 'campaign', 'expected_revenue' => (float) ($cfg['amount'] ?? 0),
                ]);
                $c->forceFill(['lead_id' => $lead->id])->saveQuietly();

                return ['label' => 'opportunité créée'];

            case 'exit':
                return ['action' => 'exit', 'label' => 'fin'];
        }

        return ['label' => 'étape inconnue ignorée'];
    }

    protected function send(Company $company, Automation $a, AutomationRun $run, Contact $c, string $channel, array $cfg, string $stepId): array
    {
        $address = $c->address($channel);
        if (! $address || $c->unsubscribed($channel) || $c->opt_in === 'pending') {
            return ['label' => 'non envoyé (contact non joignable ou désinscrit)'];
        }
        $token = Str::random(32);
        $vars = $c->vars() + ['entreprise' => $company->name, 'lien_desinscription' => url('/m/u/'.$token)];
        if ($channel === 'email') {
            $tpl = ! empty($cfg['template_id']) ? Template::find((int) $cfg['template_id']) : null;
            $subject = (string) ($cfg['subject'] ?? '') ?: (string) $tpl?->subject;
            $html = (new EmailRenderer($company))->render($cfg['blocks'] ?? $tpl?->blocks, $cfg['html'] ?? $tpl?->html, $cfg['style'] ?? $tpl?->style, $vars + ['subject' => $subject], $tpl?->preheader);
            $m = new Message(to: $c->email, toName: $c->full_name, subject: EmailRenderer::vars($subject ?: $company->name, $vars), html: EmailRenderer::track($html, $token),
                headers: ['List-Unsubscribe' => '<'.url('/m/u/'.$token).'>'], replyTo: $company->email);
        } else {
            $tpl = ! empty($cfg['template_id']) ? Template::find((int) $cfg['template_id']) : null;
            $text = EmailRenderer::vars((string) (($cfg['text'] ?? '') ?: $tpl?->text), $vars);
            if ($channel === 'sms') {
                $text .= "\nSTOP : ".url('/m/u/'.$token);
            }
            $m = new Message(to: (string) $c->phone, toName: $c->full_name, text: $text, templateParams: [$c->vars()['prenom'] ?: $c->full_name]);
        }
        $msg = CampaignMessage::create(['automation_id' => $a->id, 'step_id' => $stepId, 'contact_id' => $c->id, 'channel' => $channel, 'to' => $address, 'token' => $token, 'status' => 'queued']);
        $res = $this->messaging->send($company, $channel, $m, 'automation:'.$a->id, $a->user_id);
        $msg->update($res->ok ? ['status' => 'sent', 'sent_at' => now()] : ['status' => 'failed', 'error' => $res->error]);
        $run->last_message_id = $msg->id;

        return ['label' => $res->ok ? 'envoyé' : 'échec : '.mb_substr((string) $res->error, 0, 120)];
    }
}
