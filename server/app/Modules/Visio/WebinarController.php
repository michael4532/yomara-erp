<?php

namespace App\Modules\Visio;

use App\Http\Controllers\Controller;
use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\Company;
use App\Modules\Visio\Models\Meeting;
use App\Modules\Visio\Models\Participant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/** Pages publiques des webinaires : inscription, confirmation, rediffusion. */
class WebinarController extends Controller
{
    public function __construct(protected VisioService $svc) {}

    protected function webinar(string $slug): array
    {
        $m = Meeting::withoutGlobalScopes()->where('slug', $slug)->where('type', 'webinar')->first();
        abort_unless($m && $m->status !== 'cancelled', 404, 'Webinaire introuvable.');
        $c = Company::find($m->company_id);
        abort_unless($c && $c->hasModule('visio') && ! $c->isSuspended(), 404);

        return [$c, $m];
    }

    protected function replayFile(Meeting $m): ?object
    {
        return DB::table('vis_recordings')->where('meeting_id', $m->id)->where('status', 'ready')->where('size', '>', 0)->orderByDesc('size')->first();
    }

    protected function view(Company $c, Meeting $m, array $extra = [])
    {
        $registered = Participant::where('meeting_id', $m->id)->where('role', 'attendee')->count();

        return view('visio.webinar', $extra + [
            'c' => $c, 'm' => $m, 'host' => $m->host_id ? DB::table('users')->where('id', $m->host_id)->value('name') : null,
            'speakers' => Participant::where('meeting_id', $m->id)->whereIn('role', ['host', 'cohost', 'panelist'])->orderByRaw("case role when 'host' then 0 else 1 end")->get(['name', 'organization']),
            'registered' => $registered, 'full' => $m->capacity > 0 && $registered >= $m->capacity,
            'replay' => $m->status === 'ended' && $m->replay_public && $this->replayFile($m),
        ]);
    }

    public function show(Request $r, string $slug)
    {
        [$c, $m] = $this->webinar($slug);

        return $this->view($c, $m, ['source' => mb_substr((string) ($r->query('utm_source') ?: $r->query('source')), 0, 60)]);
    }

    public function register(Request $r, string $slug)
    {
        [$c, $m] = $this->webinar($slug);
        $d = $r->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:190', 'phone' => 'nullable|string|max:40',
            'organization' => 'nullable|string|max:120', 'source' => 'nullable|string|max:60', 'website' => 'nullable|max:0']);
        abort_if($m->status === 'ended', 410, 'Ce webinaire est terminé.');
        $email = mb_strtolower(trim($d['email']));
        $p = Participant::where('meeting_id', $m->id)->where('email', $email)->first();
        if (! $p) {
            if (! $m->registration_open) {
                return back()->withErrors(['email' => 'Les inscriptions sont closes.'])->withInput();
            }
            $count = Participant::where('meeting_id', $m->id)->where('role', 'attendee')->count();
            if ($m->capacity > 0 && $count >= $m->capacity) {
                return back()->withErrors(['email' => 'Le webinaire est complet.'])->withInput();
            }
            $p = Participant::create(['meeting_id' => $m->id, 'company_id' => $m->company_id, 'name' => trim($d['name']), 'email' => $email, 'phone' => $d['phone'] ?? null,
                'organization' => $d['organization'] ?? null, 'role' => 'attendee', 'token' => VisioService::token(), 'status' => 'registered', 'source' => $d['source'] ?? null]);
            $this->lead($c, $m, $p);
            $this->confirm($c, $m, $p);
            \App\Integrations\Webhooks::emit($c->id, 'webinar.registration', ['webinar_id' => $m->id, 'webinar' => $m->title, 'name' => $p->name, 'email' => $p->email,
                'phone' => $p->phone, 'organization' => $p->organization, 'source' => $p->source, 'join_url' => $m->roomUrl($p->token)]);
        }

        if (! $p->wasRecentlyCreated) {
            // Adresse déjà inscrite (ou animateur) : jamais de lien dans la réponse, seulement un renvoi par e-mail à cette adresse
            if ($p->role === 'attendee' && ! $p->user_id && \Illuminate\Support\Facades\Cache::add('webinar-resend:'.$p->id, 1, now()->addMinutes(10))) {
                $this->confirm($c, $m, $p);
            }

            return redirect('/webinaire/'.$m->slug.'?deja=1');
        }

        return redirect('/webinaire/'.$m->slug.'?inscrit='.$p->token);
    }

    /** Prospect CRM pour chaque inscrit (option du webinaire). */
    protected function lead(Company $c, Meeting $m, Participant $p): void
    {
        if (! $m->create_leads || ! $c->hasModule('crm') || ! class_exists(\App\Modules\Crm\Models\Lead::class)) {
            return;
        }
        rescue(function () use ($c, $m, $p) {
            app()->instance('tenant', $c);
            $exists = \App\Modules\Crm\Models\Lead::withoutGlobalScopes()->where('company_id', $c->id)->where('email', $p->email)->where('status', 'open')->first();
            if ($exists) {
                $p->forceFill(['lead_id' => $exists->id])->save();

                return;
            }
            $lead = new \App\Modules\Crm\Models\Lead;
            $lead->forceFill(['company_id' => $c->id, 'title' => 'Webinaire : '.$m->title.' — '.$p->name, 'contact_name' => $p->name, 'company_name' => $p->organization,
                'email' => $p->email, 'phone' => $p->phone, 'source' => 'webinaire', 'status' => 'open', 'owner_id' => $m->host_id,
                'stage_id' => DB::table('crm_stages')->where('company_id', $c->id)->orderBy('sequence')->value('id'),
                'notes' => 'Inscrit au webinaire « '.$m->title.' » le '.now()->format('d/m/Y H:i').($p->source ? ' (provenance : '.$p->source.')' : '').'.']);
            $lead->save();
            $p->forceFill(['lead_id' => $lead->id])->save();
        }, null, false);
    }

    protected function confirm(Company $c, Meeting $m, Participant $p): void
    {
        $messaging = app(MessagingService::class);
        if (! ($messaging->available($c)['email'] ?? null) || ! $p->email) {
            return;
        }
        $when = $m->starts_at?->locale('fr')->isoFormat('dddd D MMMM YYYY [à] HH:mm') ?? '';
        $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;line-height:1.55;color:#1d2433">'
            .'<p>Bonjour '.e($p->name).',</p><p>Votre inscription au webinaire <b>« '.e($m->title).' »</b> est confirmée.</p>'
            .'<p style="font-size:17px"><b>'.e(ucfirst($when)).'</b> · '.$m->duration_minutes.' min</p>'
            .'<p style="margin:24px 0"><a href="'.e($m->roomUrl($p->token)).'" style="background:#2563EB;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold">Mon lien d\'accès</a></p>'
            .'<p style="color:#64748B;font-size:12px">Ce lien est personnel. Vous recevrez un rappel la veille et une heure avant. Le fichier joint ajoute le webinaire à votre calendrier.</p>'
            .'<p style="color:#94A3B8;font-size:11px">'.e($c->name).'</p></div>';
        try {
            $messaging->send($c, 'email', new Message(to: $p->email, toName: $p->name, subject: 'Inscription confirmée : '.$m->title, html: $html,
                attachments: [['name' => 'webinaire.ics', 'data' => $this->svc->ics($m, $c, $p), 'mime' => 'text/calendar; charset=utf-8']]), 'visio');
        } catch (\Throwable $e) {
            Log::warning('Webinaire : confirmation non envoyée : '.$e->getMessage());
        }
    }

    public function ics(Request $r, string $slug)
    {
        [$c, $m] = $this->webinar($slug);
        $p = $r->query('t') ? Participant::where('meeting_id', $m->id)->where('token', $r->query('t'))->first() : null;

        return response($this->svc->ics($m, $c, $p, 'PUBLISH'), 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'attachment; filename="webinaire.ics"']);
    }

    public function replay(string $slug)
    {
        [$c, $m] = $this->webinar($slug);
        abort_unless($m->status === 'ended' && $m->replay_public && $this->replayFile($m), 404, 'Rediffusion indisponible.');

        return $this->view($c, $m, ['play' => true]);
    }

    public function video(string $slug)
    {
        [, $m] = $this->webinar($slug);
        $rec = $this->replayFile($m);
        abort_unless($m->status === 'ended' && $m->replay_public && $rec && Storage::disk('local')->exists($rec->path), 404);

        return response()->file(Storage::disk('local')->path($rec->path), ['Content-Type' => $rec->mime, 'Cache-Control' => 'private, max-age=3600']);
    }
}
