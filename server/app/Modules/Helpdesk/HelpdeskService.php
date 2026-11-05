<?php

namespace App\Modules\Helpdesk;

use App\Messaging\Message as Mail;
use App\Messaging\MessagingService;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Helpdesk\Models\Article;
use App\Modules\Helpdesk\Models\Category;
use App\Modules\Helpdesk\Models\Message;
use App\Modules\Helpdesk\Models\SlaPolicy;
use App\Modules\Helpdesk\Models\Ticket;
use App\Modules\Helpdesk\Models\TimeEntry;
use Carbon\Carbon;
use Illuminate\Support\Str;

class HelpdeskService
{
    /** Pas d'accusé de réception automatique (le module appelant envoie déjà son propre message, ex. commande reçue). */
    public static bool $silentAck = false;

    public const DEFAULTS = ['hours' => ['start' => '08:00', 'end' => '18:00', 'days' => [1, 2, 3, 4, 5]], 'auto_close_days' => 5, 'auto_assign' => true,
        'ack' => true, 'csat' => true, 'kb_public' => true, 'contact_form' => true];

    public function settings(Company $c): array
    {
        $s = array_replace_recursive(self::DEFAULTS, (array) data_get($c->settings, 'helpdesk', []));
        $s['hours']['days'] = array_values(array_map('intval', (array) $s['hours']['days']));

        return $s;
    }

    public function saveSettings(Company $c, array $v): array
    {
        $s = (array) ($c->settings ?? []);
        $cur = $this->settings($c);
        if (isset($v['hours'])) {
            $cur['hours'] = array_merge($cur['hours'], $v['hours']);
            unset($v['hours']);
        }
        $s['helpdesk'] = array_merge($cur, $v);
        $c->settings = $s;
        $c->save();

        return $this->settings($c);
    }

    // ------------------------------------------------------------------ délais (SLA)

    protected array $holidayCache = [];

    protected function isHoliday(Company $c, Carbon $d): bool
    {
        $y = $d->year;
        if (! isset($this->holidayCache[$c->id][$y])) {
            $this->holidayCache[$c->id][$y] = class_exists(\App\Modules\Pointage\PointageService::class) ? app(\App\Modules\Pointage\PointageService::class)->holidays($c, $y) : [];
        }

        return isset($this->holidayCache[$c->id][$y][$d->toDateString()]);
    }

    /** Ajoute des minutes ouvrées (horaires du service client, sans week-end ni jours fériés). */
    public function addMinutes(Company $c, Carbon $from, int $minutes, bool $business): Carbon
    {
        if (! $business) {
            return $from->copy()->addMinutes($minutes);
        }
        $h = $this->settings($c)['hours'];
        [$sh, $sm] = array_map('intval', explode(':', $h['start']));
        [$eh, $em] = array_map('intval', explode(':', $h['end']));
        $cur = $from->copy()->second(0);
        $left = $minutes;
        for ($guard = 0; $left > 0 && $guard < 800; $guard++) {
            $start = $cur->copy()->setTime($sh, $sm);
            $end = $cur->copy()->setTime($eh, $em);
            if (! in_array($cur->isoWeekday(), $h['days'], true) || $this->isHoliday($c, $cur) || $cur->gte($end)) {
                $cur = $cur->copy()->addDay()->setTime($sh, $sm);

                continue;
            }
            if ($cur->lt($start)) {
                $cur = $start;
            }
            $avail = (int) $cur->diffInMinutes($end);
            $take = min($avail, $left);
            $cur = $cur->copy()->addMinutes($take);
            $left -= $take;
        }

        return $cur;
    }

    public function policyFor(Ticket $t): ?SlaPolicy
    {
        if ($t->sla_policy_id && ($p = SlaPolicy::find($t->sla_policy_id))) {
            return $p;
        }
        if ($t->partner_id) {
            $p = SlaPolicy::all()->first(fn ($p) => in_array($t->partner_id, $p->partner_ids ?? [], true));
            if ($p) {
                return $p;
            }
        }

        return SlaPolicy::where('is_default', true)->first();
    }

    protected function computeDue(Company $c, Ticket $t): void
    {
        $p = $this->policyFor($t);
        $t->sla_policy_id = $p?->id;
        if (! $p) {
            $t->first_response_due = $t->resolution_due = null;

            return;
        }
        $target = $p->target($t->priority);
        $created = $t->created_at ?? now();
        $t->first_response_due = $this->addMinutes($c, $created, (int) $target['first'], $p->business_hours);
        $t->resolution_due = $this->addMinutes($c, $created, (int) $target['resolve'], $p->business_hours)->addMinutes((int) $t->paused_minutes);
    }

    // ------------------------------------------------------------------ cycle de vie

    protected function agents(Company $c)
    {
        return User::where('company_id', $c->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('helpdesk.agent'))->values();
    }

    protected function managers(Company $c)
    {
        return User::where('company_id', $c->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('helpdesk.manage'))->values();
    }

    /** Catégorie avec responsable, sinon l'agent qui a le moins de tickets ouverts. */
    public function autoAssign(Company $c, Ticket $t): ?int
    {
        if ($t->category_id && ($cat = Category::find($t->category_id)) && $cat->assignee_id) {
            return $cat->assignee_id;
        }
        $agents = $this->agents($c)->reject(fn ($u) => $u->is_super_admin && ! $u->role_id);
        if ($agents->isEmpty()) {
            return null;
        }
        $load = Ticket::whereIn('status', ['new', 'open', 'pending'])->whereIn('assignee_id', $agents->pluck('id'))->selectRaw('assignee_id, count(*) as n')->groupBy('assignee_id')->pluck('n', 'assignee_id');

        return $agents->sortBy(fn ($u) => sprintf('%06d-%09d', $load[$u->id] ?? 0, $u->id))->first()->id;
    }

    public function afterTicketWrite(Ticket $t, bool $creating): void
    {
        $c = app('tenant');
        $b = $t->before;
        $s = $this->settings($c);
        if ($creating) {
            if (! $t->pack_id && $t->partner_id && $c->hasModule('prestations')) {
                $t->pack_id = \App\Modules\Prestations\Models\Pack::where('partner_id', $t->partner_id)->whereIn('status', ['active', 'exhausted'])->orderByDesc('start_date')->value('id');
            }
            if (! $t->assignee_id && $s['auto_assign']) {
                $t->assignee_id = $this->autoAssign($c, $t);
            }
            $this->computeDue($c, $t);
            $t->last_customer_at = in_array($t->channel, ['web', 'portal', 'email', 'whatsapp', 'phone'], true) ? now() : null;
            $t->save();
            $this->system($t, 'Ticket créé ('.(Ticket::CHANNELS[$t->channel] ?? $t->channel).')'.($t->assignee_id ? ', attribué à '.User::find($t->assignee_id)?->name : '').'.');
            if ($t->assignee_id && $t->assignee_id !== auth()->id()) {
                AppNotification::send($c->id, [$t->assignee_id], 'helpdesk', 'Ticket '.$t->number.' : '.$t->subject, ($t->partner_name ?? $t->contact_name ?? '').' · priorité '.mb_strtolower(Ticket::PRIORITIES[$t->priority]), 'helpdesk', $t->id);
            } elseif (! $t->assignee_id) {
                AppNotification::send($c->id, $this->agents($c)->pluck('id'), 'helpdesk', 'Nouveau ticket non attribué : '.$t->subject, $t->partner_name ?? $t->contact_name ?? '', 'helpdesk', $t->id);
            }
            if ($s['ack'] && ! self::$silentAck) {
                $this->send($c, $t, 'Demande enregistrée : '.$t->subject.' ['.$t->number.']',
                    "Bonjour ".($t->contact_name ?? '').",\n\nNous avons bien reçu votre demande « {$t->subject} » (n° {$t->number})."
                    .($t->first_response_due ? "\nNous vous répondons au plus tard le ".$t->first_response_due->format('d/m/Y à H:i').'.' : '')
                    ."\n\nSuivre votre demande et nous répondre : ".$t->publicUrl()."\n\n{$c->name}");
            }

            return;
        }
        $dirty = false;
        if ($b['status'] !== $t->status) {
            $this->system($t, 'Statut : '.(Ticket::STATUSES[$b['status']] ?? $b['status']).' → '.(Ticket::STATUSES[$t->status] ?? $t->status).'.');
            if ($b['status'] === 'pending' && $t->pending_since) {
                $t->paused_minutes += (int) $t->pending_since->diffInMinutes(now());
                $t->pending_since = null;
                $this->computeDue($c, $t);
            }
            if ($t->status === 'pending') {
                $t->pending_since = now();
            }
            if (in_array($b['status'], ['resolved', 'closed'], true) && in_array($t->status, ['new', 'open', 'pending'], true)) {
                $t->reopened++;
                $t->resolved_at = $t->closed_at = null;
                $t->rating = null;
            }
            if ($t->status === 'resolved' || ($t->status === 'closed' && ! $t->resolved_at)) {
                $t->resolved_at ??= now();
                if ($t->resolution_due && $t->resolved_at->gt($t->resolution_due)) {
                    $t->sla_resolve_breached = true;
                }
            }
            if ($t->status === 'closed') {
                $t->closed_at = now();
            }
            if ($t->status === 'resolved') {
                $this->send($c, $t, 'Demande résolue : '.$t->subject.' ['.$t->number.']',
                    "Bonjour ".($t->contact_name ?? '').",\n\nVotre demande « {$t->subject} » est résolue."
                    .($s['csat'] ? "\nDonnez-nous votre avis en un clic : ".$t->publicUrl() : '')
                    ."\nSi le problème persiste, répondez simplement depuis ce lien : la demande sera rouverte.\n\n{$c->name}");
            }
            $dirty = true;
        }
        if ($b['priority'] !== $t->priority || $b['sla_policy_id'] !== $t->sla_policy_id) {
            if ($b['priority'] !== $t->priority) {
                $this->system($t, 'Priorité : '.(Ticket::PRIORITIES[$b['priority']] ?? '').' → '.(Ticket::PRIORITIES[$t->priority] ?? '').'.');
            }
            $this->computeDue($c, $t);
            $dirty = true;
        }
        if ($b['assignee_id'] !== $t->assignee_id && $t->assignee_id) {
            $this->system($t, 'Attribué à '.User::find($t->assignee_id)?->name.'.');
            if ($t->assignee_id !== auth()->id()) {
                AppNotification::send($c->id, [$t->assignee_id], 'helpdesk', 'Ticket '.$t->number.' attribué : '.$t->subject, $t->partner_name ?? $t->contact_name ?? '', 'helpdesk', $t->id);
            }
            if ($t->status === 'new') {
                $t->status = 'open';
            }
            $dirty = true;
        }
        if ($dirty) {
            $t->save();
        }
    }

    protected function system(Ticket $t, string $text): void
    {
        $m = new Message;
        $m->forceFill(['ticket_id' => $t->id, 'author_type' => 'system', 'body' => $text, 'internal' => true, 'author_name' => auth()->user()?->name]);
        $m->save();
    }

    public function afterAgentMessage(Message $m): void
    {
        $c = app('tenant');
        $t = Ticket::find($m->ticket_id);
        if (! $t->assignee_id) {
            $t->assignee_id = $m->user_id;
        }
        if (! $m->internal) {
            if (! $t->first_response_at) {
                $t->first_response_at = now();
                $t->sla_first_breached = $t->sla_first_breached || ($t->first_response_due && now()->gt($t->first_response_due));
            }
            $t->last_agent_at = now();
            if ($t->status === 'new') {
                $t->status = 'open';
            }
            $files = \App\Modules\Helpdesk\Models\Attachment::where('ticket_id', $t->id)->whereNull('message_id')->where('public', true)->where('uploaded_by', $m->author_name)
                ->where('created_at', '>=', now()->subHour())->get();
            foreach ($files as $f) {
                $f->forceFill(['message_id' => $m->id])->save();
            }
            $via = $this->send($c, $t, 'Re : '.$t->subject.' ['.$t->number.']', $m->body.($files->isNotEmpty() ? "\n\nPièce(s) jointe(s) : ".$files->pluck('name')->implode(', ') : '')
                ."\n\n— ".$m->author_name.", {$c->name}\n\nRépondre ou suivre la demande : ".$t->publicUrl(), $files->all());
            $m->forceFill(['sent_via' => $via ? implode(', ', $via) : null])->save();
        } elseif ($t->status === 'new') {
            $t->status = 'open';
        }
        $t->save();
    }

    /** Message du client (centre d'aide, lien du ticket, espace client) : rouvre si besoin et prévient l'équipe. */
    public function customerMessage(Company $c, Ticket $t, string $body, ?string $name, string $via): Message
    {
        $body = trim($body);
        if ($body === '') {
            throw new \DomainException('Écrivez votre message.');
        }
        $m = new Message;
        $m->forceFill(['ticket_id' => $t->id, 'author_type' => 'customer', 'author_name' => mb_substr($name ?: ($t->contact_name ?? 'Client'), 0, 120), 'body' => mb_substr($body, 0, 20000), 'internal' => false, 'sent_via' => $via]);
        $m->save();
        $t->last_customer_at = now();
        if (in_array($t->status, ['resolved', 'closed'], true)) {
            $t->reopened++;
            $t->resolved_at = $t->closed_at = null;
            $t->status = 'open';
            $t->rating = null;
        } elseif ($t->status === 'pending') {
            if ($t->pending_since) {
                $t->paused_minutes += (int) $t->pending_since->diffInMinutes(now());
            }
            $t->pending_since = null;
            $t->status = 'open';
            $this->computeDue($c, $t);
        }
        $t->save();
        $to = $t->assignee_id ? [$t->assignee_id] : $this->agents($c)->pluck('id')->all();
        AppNotification::send($c->id, $to, 'helpdesk', 'Réponse du client : '.$t->number, Str::limit($body, 120), 'helpdesk', $t->id);

        return $m;
    }

    public function rate(Ticket $t, int $rating, ?string $comment): void
    {
        if (! in_array($t->status, ['resolved', 'closed'], true)) {
            throw new \DomainException('Vous pourrez donner votre avis une fois la demande résolue.');
        }
        $t->rating = max(1, min(5, $rating));
        $t->rating_comment = $comment ? mb_substr(trim($comment), 0, 2000) : null;
        $t->save();
        if ($t->rating <= 2) {
            $c = app('tenant');
            AppNotification::send($c->id, $this->managers($c)->pluck('id')->merge(array_filter([$t->assignee_id]))->unique()->values(), 'helpdesk',
                'Client mécontent ('.$t->rating.'/5) : '.$t->number, $t->rating_comment ?? $t->subject, 'helpdesk', $t->id);
        }
    }

    /** E-mail et WhatsApp au contact du ticket ; renvoie les canaux utilisés. */
    public const REPLY_MARKER = '##- Répondez au-dessus de cette ligne -##';

    public function send(Company $c, Ticket $t, string $subject, string $text, array $files = []): array
    {
        $headers = ['References' => '<ticket-'.$t->id.'@yomara.erp>', 'X-Yomara-Ticket' => (string) $t->number];
        $messaging = app(MessagingService::class);
        $avail = $messaging->available($c);
        $used = [];
        if ($t->contact_email && ($avail['email'] ?? null)) {
            $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;line-height:1.5"><p style="color:#999;font-size:12px">'.e(self::REPLY_MARKER).'</p>'.nl2br(e($text)).'<p><a href="'.e($t->publicUrl()).'" style="display:inline-block;background:#1f6feb;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">Voir la demande</a></p></div>';
            $atts = [];
            $total = 0;
            foreach ($files as $a) {
                if ($total + $a->size <= 10 * 1048576 && \Illuminate\Support\Facades\Storage::disk('local')->exists($a->path)) {
                    $atts[] = ['name' => $a->name, 'data' => \Illuminate\Support\Facades\Storage::disk('local')->get($a->path), 'mime' => $a->mime];
                    $total += $a->size;
                }
            }
            try {
                if ($messaging->send($c, 'email', new Mail(to: $t->contact_email, subject: $subject, html: $html, text: self::REPLY_MARKER."\n\n".$text, attachments: $atts, toName: $t->contact_name, headers: $headers), 'helpdesk')->ok) {
                    $used[] = 'e-mail';
                }
            } catch (\Throwable) {
            }
        }
        if ($t->contact_phone && ($avail['whatsapp'] ?? null)) {
            try {
                if ($messaging->send($c, 'whatsapp', new Mail(to: $t->contact_phone, text: $text, toName: $t->contact_name), 'helpdesk')->ok) {
                    $used[] = 'WhatsApp';
                }
            } catch (\Throwable) {
            }
        }

        return $used;
    }

    // ------------------------------------------------------------------ temps passé et forfaits

    public function syncTime(TimeEntry $te): void
    {
        $c = app('tenant');
        if (! $c->hasModule('prestations')) {
            return;
        }
        $t = Ticket::find($te->ticket_id);
        $entry = \App\Modules\Prestations\Models\Entry::where('ticket_time_id', $te->id)->first();
        $pack = $t?->pack_id && $te->billable && ! $te->trashed() ? \App\Modules\Prestations\Models\Pack::find($t->pack_id) : null;
        if ($pack && ! in_array($pack->status, ['active', 'exhausted'], true) && $entry?->pack_id !== $pack->id) {
            $pack = null;
        }
        $old = $entry?->pack_id;
        $svc = app(\App\Modules\Prestations\PrestationService::class);
        if (! $pack) {
            $entry?->delete();

            return;
        }
        $entry ??= new \App\Modules\Prestations\Models\Entry;
        $entry->forceFill(['pack_id' => $pack->id, 'date' => $te->date, 'hours' => round($te->minutes / 60, 2), 'ticket_id' => $t->id, 'ticket_time_id' => $te->id,
            'description' => Str::limit('Ticket '.$t->number.' — '.($te->note ?: $t->subject), 255, ''), 'user_id' => $te->user_id, 'user_name' => $te->user_name]);
        if ($entry->isDirty()) {
            $entry->save();
        }
        $svc->refreshPack($pack->fresh());
        if ($old && $old !== $pack->id) {
            $svc->refreshPack(\App\Modules\Prestations\Models\Pack::find($old));
        }
    }

    public function unlinkTime(TimeEntry $te): void
    {
        \App\Modules\Prestations\Models\Entry::where('ticket_time_id', $te->id)->get()->each->delete();
    }

    /** Semaine envoyée pour validation : le temps ne bouge plus (module Feuilles de temps). */
    public static function weekLock(?int $userId, ?string $date, ?string $oldDate = null): void
    {
        \App\Modules\Feuilles\FeuillesService::check($userId, $date, $oldDate);
    }

    // ------------------------------------------------------------------ tâches planifiées et indicateurs

    public function runEvery(Company $c): array
    {
        $out = ['breached' => 0, 'closed' => 0];
        $s = $this->settings($c);
        $notify = function (Ticket $t, string $what) use ($c) {
            $to = $this->managers($c)->pluck('id')->merge(array_filter([$t->assignee_id]))->unique()->values();
            AppNotification::send($c->id, $to, 'helpdesk', 'Délai dépassé ('.$what.') : '.$t->number, $t->subject.' · '.($t->partner_name ?? $t->contact_name ?? ''), 'helpdesk', $t->id);
        };
        foreach (Ticket::whereIn('status', ['new', 'open', 'pending'])->whereNull('first_response_at')->where('sla_first_breached', false)->whereNotNull('first_response_due')->where('first_response_due', '<', now())->get() as $t) {
            $t->sla_first_breached = true;
            $t->save();
            $notify($t, 'première réponse');
            $out['breached']++;
        }
        foreach (Ticket::whereIn('status', ['new', 'open'])->where('sla_resolve_breached', false)->whereNotNull('resolution_due')->where('resolution_due', '<', now())->get() as $t) {
            $t->sla_resolve_breached = true;
            $t->save();
            $notify($t, 'résolution');
            $out['breached']++;
        }
        foreach (Ticket::where('status', 'resolved')->where('resolved_at', '<', now()->subDays((int) $s['auto_close_days']))->get() as $t) {
            $t->status = 'closed';
            $t->closed_at = now();
            $t->save();
            $this->system($t, 'Fermé automatiquement après '.$s['auto_close_days'].' jours sans réponse du client.');
            $out['closed']++;
        }

        return $out;
    }

    public function stats(int $days = 30): array
    {
        $from = now()->subDays($days);
        $open = Ticket::whereIn('status', ['new', 'open', 'pending'])->get();
        $resolved = Ticket::whereNotNull('resolved_at')->where('resolved_at', '>=', $from)->get();
        $responded = Ticket::whereNotNull('first_response_at')->where('created_at', '>=', $from)->get();
        $rated = Ticket::whereNotNull('rating')->where('updated_at', '>=', $from)->get();
        $users = User::where('company_id', app('tenant')->id)->pluck('name', 'id');
        $cats = Category::pluck('name', 'id');
        $avg = fn ($rows, $a, $b) => $rows->count() ? (int) round($rows->avg(fn ($t) => $t->{$a}->diffInMinutes($t->{$b}))) : null;

        return [
            'open' => $open->count(),
            'unassigned' => $open->whereNull('assignee_id')->count(),
            'pending' => $open->where('status', 'pending')->count(),
            'overdue' => $open->filter(fn ($t) => ($t->status !== 'pending' && $t->resolution_due?->isPast()) || (! $t->first_response_at && $t->first_response_due?->isPast()))->count(),
            'new_today' => Ticket::where('created_at', '>=', now()->startOfDay())->count(),
            'resolved' => $resolved->count(),
            'sla_compliance' => $resolved->count() ? (int) round(100 * $resolved->filter(fn ($t) => ! $t->sla_first_breached && ! $t->sla_resolve_breached)->count() / $resolved->count()) : null,
            'avg_first_response' => $avg($responded, 'created_at', 'first_response_at'),
            'avg_resolution' => $avg($resolved, 'created_at', 'resolved_at'),
            'csat' => $rated->count() ? round($rated->avg('rating'), 1) : null,
            'csat_count' => $rated->count(),
            'by_agent' => $open->groupBy('assignee_id')->map(fn ($g, $id) => ['user_id' => $id ? (int) $id : null, 'name' => $id ? ($users[$id] ?? '—') : 'Non attribué', 'open' => $g->count(),
                'resolved' => $resolved->where('assignee_id', $id ?: null)->count()])->values(),
            'by_category' => Ticket::where('created_at', '>=', $from)->get()->groupBy('category_id')->map(fn ($g, $id) => ['name' => $id ? ($cats[$id] ?? '—') : 'Sans catégorie', 'count' => $g->count()])->sortByDesc('count')->values(),
            'by_channel' => Ticket::where('created_at', '>=', $from)->get()->groupBy('channel')->map(fn ($g, $ch) => ['channel' => $ch, 'label' => Ticket::CHANNELS[$ch] ?? $ch, 'count' => $g->count()])->values(),
        ];
    }

    // ------------------------------------------------------------------ base de connaissances

    public function search(string $q, int $limit = 8)
    {
        $words = array_filter(preg_split('/\s+/', mb_strtolower(trim($q))), fn ($w) => mb_strlen($w) >= 3);
        $rows = Article::where('published', true)->get();
        if (! $words) {
            return $rows->sortByDesc('views')->take($limit)->values();
        }

        return $rows->map(function ($a) use ($words) {
            $title = mb_strtolower($a->title.' '.$a->tags);
            $body = mb_strtolower($a->body);
            $score = 0;
            foreach ($words as $w) {
                $score += (str_contains($title, $w) ? 5 : 0) + min(3, substr_count($body, $w));
            }
            $a->score = $score;

            return $a;
        })->filter(fn ($a) => $a->score > 0)->sortByDesc('score')->take($limit)->values();
    }

    /** Mise en forme simple des articles : ## titres, - listes, **gras**, [lien](https://…), paragraphes. */
    public static function render(string $text): string
    {
        $out = [];
        $list = false;
        foreach (preg_split('/\R/', $text) as $line) {
            $l = e(rtrim($line));
            $l = preg_replace('/\*\*(.+?)\*\*/', '<b>$1</b>', $l);
            $l = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $l);
            if (preg_match('/^\s*[-•]\s+(.*)$/u', $l, $m)) {
                if (! $list) {
                    $out[] = '<ul>';
                    $list = true;
                }
                $out[] = '<li>'.$m[1].'</li>';

                continue;
            }
            if ($list) {
                $out[] = '</ul>';
                $list = false;
            }
            if (preg_match('/^#{2,3}\s+(.*)$/', $l, $m)) {
                $out[] = '<h3>'.$m[1].'</h3>';
            } elseif (preg_match('/^\d+\.\s+(.*)$/', $l, $m)) {
                $out[] = '<p class="step">'.$l.'</p>';
            } elseif (trim($l) !== '') {
                $out[] = '<p>'.$l.'</p>';
            }
        }
        if ($list) {
            $out[] = '</ul>';
        }

        return implode("\n", $out);
    }

    /** Client retrouvé par e-mail ou téléphone, sinon créé. */
    public function partnerFor(string $name, ?string $email, ?string $phone): \App\Modules\Core\Models\Partner
    {
        $P = \App\Modules\Core\Models\Partner::class;
        $digits = $phone ? preg_replace('/\D/', '', $phone) : null;
        $p = ($email ? $P::where('email', $email)->first() : null)
            ?? ($digits && strlen($digits) >= 8 ? $P::whereNotNull('phone')->get()->first(fn ($x) => str_ends_with(preg_replace('/\D/', '', $x->phone), substr($digits, -8))) : null);

        return $p ?? $P::create(['name' => $name, 'type' => 'customer', 'email' => $email, 'phone' => $phone]);
    }
}
