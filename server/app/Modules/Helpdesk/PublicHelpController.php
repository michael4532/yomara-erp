<?php

namespace App\Modules\Helpdesk;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Core\Models\Partner;
use App\Modules\Helpdesk\Models\Article;
use App\Modules\Helpdesk\Models\Category;
use App\Modules\Helpdesk\Models\Message;
use App\Modules\Helpdesk\Models\Ticket;
use App\Modules\Portail\Models\PortalAccess;
use App\Services\EntityWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Centre d'aide public : base de connaissances, formulaire de contact, suivi d'un ticket par son lien. */
class PublicHelpController extends Controller
{
    public function __construct(private HelpdeskService $svc) {}

    protected function company(string $slug): Company
    {
        $c = Company::where('slug', $slug)->first();
        abort_unless($c && $c->status !== 'suspended' && $c->hasModule('helpdesk'), 404);
        app()->instance('tenant', $c);

        return $c;
    }

    protected function portalPartner(Request $r, Company $c): ?Partner
    {
        $t = (string) $r->query('portal', $r->input('portal', ''));
        if (strlen($t) < 32 || ! $c->hasModule('portail')) {
            return null;
        }
        $a = PortalAccess::where('token', $t)->where('active', true)->first();

        return $a ? Partner::find($a->partner_id) : null;
    }

    protected function view(string $v, array $data, bool $index = false)
    {
        return response()->view('helpdesk.'.$v, $data + ['ok' => session('ok'), 'error' => session('error')])
            ->header('X-Robots-Tag', $index ? 'index, follow' : 'noindex')->header('Referrer-Policy', 'no-referrer');
    }

    public function home(Request $r, string $slug)
    {
        $c = $this->company($slug);
        $s = $this->svc->settings($c);
        abort_unless($s['kb_public'] || $s['contact_form'], 404);
        $q = trim((string) $r->query('q', ''));
        $articles = $s['kb_public'] ? ($q !== '' ? $this->svc->search($q, 20) : Article::where('published', true)->orderBy('section')->orderBy('title')->get()) : collect();

        return $this->view('home', ['company' => $c, 'q' => $q, 'articles' => $articles, 'sections' => $q === '' ? $articles->groupBy(fn ($a) => $a->section ?: 'Général') : collect(),
            'popular' => $q === '' && $s['kb_public'] ? Article::where('published', true)->orderByDesc('views')->take(5)->get() : collect(), 'settings' => $s, 'portal' => $r->query('portal')], $q === '');
    }

    public function article(string $slug, string $article)
    {
        $c = $this->company($slug);
        abort_unless($this->svc->settings($c)['kb_public'], 404);
        $a = Article::where('slug', $article)->where('published', true)->firstOrFail();
        Article::whereKey($a->id)->increment('views');
        $related = Article::where('published', true)->where('id', '!=', $a->id)->when($a->section, fn ($q) => $q->where('section', $a->section))->take(5)->get();

        return $this->view('article', ['company' => $c, 'a' => $a, 'related' => $related, 'settings' => $this->svc->settings($c), 'voted' => session('voted_'.$a->id)], true);
    }

    public function vote(Request $r, string $slug, string $article)
    {
        $this->company($slug);
        $a = Article::where('slug', $article)->where('published', true)->firstOrFail();
        if (! session('voted_'.$a->id)) {
            Article::whereKey($a->id)->increment($r->input('helpful') === 'yes' ? 'helpful_yes' : 'helpful_no');
            session(['voted_'.$a->id => true]);
        }

        return redirect('/aide/'.$slug.'/a/'.$a->slug)->with('ok', 'Merci pour votre avis.');
    }

    public function contact(Request $r, string $slug)
    {
        $c = $this->company($slug);
        abort_unless($this->svc->settings($c)['contact_form'], 404);
        $partner = $this->portalPartner($r, $c);
        $q = trim((string) $r->query('q', old('subject', '')));

        return $this->view('contact', ['company' => $c, 'partner' => $partner, 'portal' => $partner ? $r->query('portal') : null,
            'categories' => Category::where('public', true)->orderBy('sort')->orderBy('name')->get(), 'suggest' => $q !== '' && $this->svc->settings($c)['kb_public'] ? $this->svc->search($q, 4) : collect(), 'q' => $q]);
    }

    public function store(Request $r, string $slug)
    {
        $c = $this->company($slug);
        abort_unless($this->svc->settings($c)['contact_form'], 404);
        $partner = $this->portalPartner($r, $c);
        $d = $r->validate([
            'name' => $partner ? 'nullable|string|max:120' : 'required|string|max:120',
            'email' => $partner ? 'nullable|email|max:255' : 'nullable|email|max:255|required_without:phone',
            'phone' => 'nullable|string|max:40',
            'subject' => 'required|string|max:200', 'description' => 'required|string|max:10000',
            'category_id' => 'nullable|uuid', 'priority' => 'nullable|in:normal,high,urgent', 'website' => 'max:0',
        ], ['name.required' => 'Indiquez votre nom.', 'email.required_without' => 'Indiquez un e-mail ou un téléphone.', 'subject.required' => 'Indiquez l\'objet de votre demande.', 'description.required' => 'Décrivez votre demande.']);
        $partner ??= $this->svc->partnerFor($d['name'], $d['email'] ?? null, $d['phone'] ?? null);
        $cat = ! empty($d['category_id']) ? Category::where('public', true)->find($d['category_id']) : null;
        $id = (string) Str::uuid();
        Ticket::$systemWrite = true;
        try {
            $res = app(EntityWriter::class)->upsert(Ticket::class, $id, array_filter([
                'partner_id' => $partner->id, 'contact_name' => $d['name'] ?? $partner->name, 'contact_email' => $d['email'] ?? $partner->email, 'contact_phone' => $d['phone'] ?? $partner->phone,
                'subject' => $d['subject'], 'description' => $d['description'], 'channel' => $r->input('portal') && $partner ? 'portal' : 'web', 'category_id' => $cat?->id,
                'priority' => in_array($d['priority'] ?? 'normal', ['high', 'urgent'], true) ? 'high' : 'normal',
            ], fn ($v) => $v !== null && $v !== ''));
        } finally {
            Ticket::$systemWrite = false;
        }
        if ($res['status'] !== EntityWriter::APPLIED) {
            return back()->withInput()->with('error', $res['message'] ?? 'Demande refusée.');
        }

        return redirect('/support/'.Ticket::find($id)->token)->with('ok', 'Demande envoyée. Gardez ce lien pour suivre la réponse.');
    }

    protected function ticket(string $token): array
    {
        abort_unless(strlen($token) >= 32, 404);
        $raw = Ticket::withoutGlobalScopes()->where('token', $token)->whereNull('deleted_at')->first();
        abort_unless($raw, 404);
        $c = Company::find($raw->company_id);
        abort_unless($c && $c->status !== 'suspended' && $c->hasModule('helpdesk'), 404);
        app()->instance('tenant', $c);

        return [$c, Ticket::find($raw->id)];
    }

    public function show(string $token)
    {
        [$c, $t] = $this->ticket($token);
        $messages = Message::where('ticket_id', $t->id)->where('internal', false)->orderBy('created_at')->get();
        $files = \App\Modules\Helpdesk\Models\Attachment::where('ticket_id', $t->id)->where('public', true)->orderBy('created_at')->get();

        return $this->view('ticket', ['company' => $c, 't' => $t, 'messages' => $messages, 'files' => $files, 'settings' => $this->svc->settings($c)]);
    }

    public function file(string $token, string $id)
    {
        [, $t] = $this->ticket($token);
        $a = \App\Modules\Helpdesk\Models\Attachment::where('ticket_id', $t->id)->where('public', true)->findOrFail($id);
        abort_unless(\Illuminate\Support\Facades\Storage::disk('local')->exists($a->path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->download($a->path, $a->name);
    }

    public function reply(Request $r, string $token)
    {
        [$c, $t] = $this->ticket($token);
        $d = $r->validate(['body' => 'required|string|max:10000', 'website' => 'max:0', 'file' => 'nullable|file|max:15360'], ['body.required' => 'Écrivez votre message.', 'file.max' => 'Fichier trop lourd (15 Mo au plus).']);
        try {
            $m = $this->svc->customerMessage($c, $t, $d['body'], $t->contact_name, 'web');
            if ($f = $r->file('file')) {
                app(\App\Modules\Helpdesk\Inbound\InboundService::class)->storeFiles($t, [['name' => $f->getClientOriginalName(), 'mime' => $f->getClientMimeType(), 'data' => (string) file_get_contents($f->getRealPath())]], $m->id, true, $t->contact_name);
            }
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect('/support/'.$token)->with('ok', 'Message envoyé à l\'équipe.');
    }

    public function rate(Request $r, string $token)
    {
        [$c, $t] = $this->ticket($token);
        $d = $r->validate(['rating' => 'required|integer|between:1,5', 'comment' => 'nullable|string|max:2000']);
        try {
            $this->svc->rate($t, (int) $d['rating'], $d['comment'] ?? null);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect('/support/'.$token)->with('ok', 'Merci pour votre avis !');
    }
}
