<?php

namespace App\Modules\Marketing;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\Company;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignMessage;
use App\Modules\Marketing\Models\Contact;
use App\Modules\Marketing\Models\Segment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Préparation et envoi des campagnes (par lots, compatible hébergement mutualisé). */
class CampaignService
{
    public const BATCH = 50;

    public function __construct(protected MessagingService $messaging) {}

    /** Destinataires : contacts des listes et segments choisis, joignables sur le canal, confirmés et non désinscrits. */
    public function recipients(Campaign $c)
    {
        $field = $c->channel === 'email' ? 'email' : 'phone';
        $segments = Segment::whereIn('id', $c->segment_ids ?: [0])->get();

        return Contact::where(function ($w) use ($c, $segments) {
            $w->whereHas('lists', fn ($q) => $q->whereIn('mk_lists.id', $c->list_ids ?: [0]));
            foreach ($segments as $seg) {
                $w->orWhere(fn ($x) => Segment::apply($x, $seg->match, (array) $seg->conditions));
            }
        })
            ->where(fn ($q) => $q->whereNull('opt_in')->orWhere('opt_in', '!=', 'pending'))
            ->whereNotNull($field)->where($field, '!=', '')
            ->whereNull('unsubscribed_'.$c->channel.'_at')
            ->get()->unique(fn ($ct) => $c->channel === 'email' ? $ct->email : preg_replace('/\D/', '', (string) $ct->phone))->values();
    }

    public function launch(Campaign $c): Campaign
    {
        if (! in_array($c->status, ['draft', 'scheduled'], true)) {
            throw new \DomainException('Cette campagne a déjà été envoyée.');
        }
        if ($c->channel === 'email' && trim((string) $c->subject) === '') {
            throw new \DomainException('Indiquez l\'objet de l\'e-mail.');
        }
        if ($c->channel !== 'email' && trim((string) $c->text) === '') {
            throw new \DomainException('Le message est vide.');
        }
        $company = app('tenant');
        if (! ($this->messaging->available($company)[$c->channel] ?? null)) {
            throw new \DomainException('Le canal « '.$c->channel.' » n\'est pas configuré (Paramètres → Canaux d\'envoi).');
        }
        $recipients = $this->recipients($c);
        if ($recipients->isEmpty()) {
            throw new \DomainException('Aucun destinataire joignable dans les listes et segments choisis.');
        }
        // Test A/B de l'objet : deux groupes tests, le reste attend le gagnant
        $ab = (array) $c->ab;
        $abOn = $c->channel === 'email' && ! empty($ab['enabled']) && trim((string) ($ab['subject_b'] ?? '')) !== '' && $recipients->count() >= 10;
        $recipients = $abOn ? $recipients->shuffle()->values() : $recipients;
        $size = $abOn ? max(1, (int) floor($recipients->count() * min(50, max(5, (int) ($ab['percent'] ?? 20))) / 100)) : 0;
        DB::transaction(function () use ($c, $recipients, $abOn, $size, $ab) {
            $now = now();
            foreach ($recipients->chunk(500) as $k => $chunk) {
                CampaignMessage::insert($chunk->map(function ($ct, $i) use ($c, $now, $abOn, $size) {
                    [$status, $variant] = ! $abOn ? ['queued', null] : ($i < $size ? ['queued', 'A'] : ($i < 2 * $size ? ['queued', 'B'] : ['held', null]));

                    return [
                        'company_id' => $c->company_id, 'campaign_id' => $c->id, 'contact_id' => $ct->id, 'channel' => $c->channel,
                        'to' => $ct->address($c->channel), 'token' => Str::random(32), 'status' => $status, 'variant' => $variant, 'created_at' => $now, 'updated_at' => $now,
                    ];
                })->all());
            }
            $update = ['status' => 'sending', 'started_at' => $now];
            if ($abOn) {
                $update['ab'] = $ab + ['status' => 'testing', 'decide_at' => $now->copy()->addHours(max(1, min(72, (int) ($ab['wait_hours'] ?? 4))))->toIso8601String(), 'test_size' => $size];
            }
            $c->update($update);
            $c->refreshStats();
        });
        SendCampaignBatch::dispatch($c->id);

        return $c->fresh();
    }

    /** Contenu personnalisé pour un destinataire. */
    public function build(Company $company, Campaign $c, Contact $ct, ?string $token, ?string $variant = null): Message
    {
        $unsub = $token ? url('/m/u/'.$token) : '#';
        $vars = $ct->vars() + ['entreprise' => $company->name, 'lien_desinscription' => $unsub];
        if ($c->channel === 'email') {
            $r = new EmailRenderer($company);
            $html = $r->render($c->blocks, $c->html, $c->style, $vars + ['subject' => $c->subject], $c->preheader);
            if ($token) {
                $html = EmailRenderer::track($html, $token);
            }
            $attachments = [];
            foreach ((array) $c->attachments as $a) {
                if (Storage::disk('local')->exists($a['path'])) {
                    $attachments[] = ['name' => $a['name'], 'data' => Storage::disk('local')->get($a['path']), 'mime' => $a['mime'] ?? null];
                }
            }

            $subject = $variant === 'B' && ! empty($c->ab['subject_b']) ? (string) $c->ab['subject_b'] : (string) $c->subject;

            return new Message(to: $ct->email, toName: $ct->full_name, subject: EmailRenderer::vars($subject, $vars), html: $html, attachments: $attachments,
                headers: $token ? ['List-Unsubscribe' => '<'.$unsub.'>'] : [], replyTo: $company->email);
        }
        $text = EmailRenderer::vars((string) $c->text, $vars);
        if ($c->channel === 'sms' && $token && $c->kind !== 'reminder') {
            $text .= "\nSTOP : ".$unsub;
        }

        return new Message(to: (string) $ct->phone, toName: $ct->full_name, text: $text, templateParams: [$ct->vars()['prenom'] ?: $ct->full_name]);
    }

    /** Envoie un lot ; renvoie le nombre restant. */
    public function sendBatch(Campaign $c): int
    {
        $company = Company::find($c->company_id);
        app()->instance('tenant', $company);
        $batch = CampaignMessage::where('campaign_id', $c->id)->where('status', 'queued')->with('contact')->limit(self::BATCH)->get();
        foreach ($batch as $m) {
            try {
                $res = $this->messaging->send($company, $c->channel, $this->build($company, $c, $m->contact, $m->token, $m->variant), 'campaign:'.$c->id, $c->user_id);
                $m->update($res->ok ? ['status' => 'sent', 'sent_at' => now(), 'error' => null] : ['status' => 'failed', 'error' => $res->error]);
            } catch (\Throwable $e) {
                $m->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            }
        }
        $left = CampaignMessage::where('campaign_id', $c->id)->where('status', 'queued')->count();
        $held = CampaignMessage::where('campaign_id', $c->id)->where('status', 'held')->count();
        if ($left === 0 && $held === 0 && $c->status === 'sending') {
            $c->update(['status' => 'sent', 'sent_at' => now()]);
        }
        $c->refreshStats();

        return $left;
    }

    /** Test A/B : choisit l'objet gagnant (ouvertures ou clics) et envoie au reste de la liste. */
    public function decideAb(Campaign $c): ?string
    {
        $ab = (array) $c->ab;
        if (($ab['status'] ?? null) !== 'testing') {
            return null;
        }
        $metric = ($ab['metric'] ?? 'open') === 'click' ? 'clicked_at' : 'opened_at';
        $rate = function (string $v) use ($c, $metric) {
            $q = fn () => CampaignMessage::where('campaign_id', $c->id)->where('variant', $v)->where('status', 'sent');
            $sent = $q()->count();

            return $sent ? $q()->whereNotNull($metric)->count() / $sent : 0;
        };
        $a = $rate('A');
        $b = $rate('B');
        $winner = $b > $a ? 'B' : 'A';
        CampaignMessage::where('campaign_id', $c->id)->where('status', 'held')->update(['status' => 'queued', 'variant' => $winner]);
        $c->update(['ab' => $ab + ['status' => 'done', 'winner' => $winner, 'rate_a' => round($a * 100, 1), 'rate_b' => round($b * 100, 1), 'decided_at' => now()->toIso8601String()]]);
        if ($c->status === 'sending') {
            SendCampaignBatch::dispatch($c->id);
        }

        return $winner;
    }

    /** Envoi d'essai à une adresse. */
    public function test(Campaign $c, string $to): \App\Messaging\SendResult
    {
        $company = app('tenant');
        $sample = new Contact(['first_name' => 'Awa', 'last_name' => 'Koné', 'company_name' => 'Société exemple', 'email' => $to, 'phone' => $to]);
        $m = $this->build($company, $c, $sample, null);
        $m->to = $to;
        if ($c->channel === 'email') {
            $m->subject = '[TEST] '.$m->subject;
        }

        return $this->messaging->send($company, $c->channel, $m, 'campaign-test:'.$c->id, auth()->id());
    }
}
