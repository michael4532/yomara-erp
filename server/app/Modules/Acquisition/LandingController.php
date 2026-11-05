<?php

namespace App\Modules\Acquisition;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/** Pages de capture publiques (/lp/…) : affichage, compte des visites, formulaire versé dans le CRM. */
class LandingController extends Controller
{
    protected function page(string $slug): array
    {
        $p = DB::table('ac_pages')->where('slug', $slug)->first();
        abort_unless($p, 404);
        $c = Company::find($p->company_id);
        abort_unless($c && $c->hasModule('acquisition') && $c->status !== 'suspended', 404);

        return [$p, $c];
    }

    public static function tracking(Company $c): array
    {
        return array_filter((array) $c->setting('tracking', []), fn ($v) => is_string($v) && $v !== '');
    }

    protected function render(object $p, Company $c, bool $sent = false, ?string $error = null, array $events = [])
    {
        $logo = $c->logo && (str_starts_with($c->logo, 'http') || str_starts_with($c->logo, 'data:image')) ? $c->logo : null;

        return response()->view('landing-pages.page', ['page' => $p, 'company' => $c, 'logo' => $logo, 'sent' => $sent, 'error' => $error, 'tracking' => self::tracking($c), 'events' => $events])
            ->header('X-Robots-Tag', $p->status === 'published' ? 'all' : 'noindex');
    }

    public function show(Request $r, string $slug)
    {
        [$p, $c] = $this->page($slug);
        if ($p->status === 'published' && ! $r->cookies->has('lpv_'.$p->id) && ! preg_match('/bot|crawl|spider|preview|facebookexternalhit/i', (string) $r->userAgent())) {
            DB::table('ac_pages')->where('id', $p->id)->increment('views');
            cookie()->queue('lpv_'.$p->id, '1', 60 * 24);
        }
        $sent = $r->query('merci') === '1';
        // Les conversions ne sont signalées aux pixels qu'après un vrai envoi (pas en rechargeant la page de remerciement)
        $event = session('lp_event');

        return $this->render($p, $c, $sent, session('lp_error'), $sent && $event ? ['lead', 'event_id' => $event] : []);
    }

    public function submit(Request $r, string $slug)
    {
        [$p, $c] = $this->page($slug);
        $back = fn (string $m) => redirect('/lp/'.$p->slug.'#form')->withInput()->with('lp_error', $m);
        if ($p->status !== 'published') {
            return $back($p->status === 'closed' ? 'Cette offre est terminée.' : 'Page en aperçu : publiez-la pour recevoir des demandes.');
        }
        if ($r->filled('website_hp')) {
            return redirect('/lp/'.$p->slug.'?merci=1');
        }
        $key = 'lp:'.$p->id.':'.$r->ip();
        if (RateLimiter::tooManyAttempts($key, 6)) {
            return $back('Trop d\'envois : réessayez dans quelques minutes.');
        }
        $fields = (array) (json_decode((string) $p->fields, true) ?: ['name', 'phone']);
        $rules = [];
        foreach ($fields as $f) {
            $rules[$f] = match ($f) {
                'name' => 'required|string|min:2|max:160',
                'phone' => 'required|string|min:8|max:30',
                'email' => (in_array('phone', $fields, true) ? 'nullable' : 'required').'|email|max:190',
                'message' => 'nullable|string|max:3000',
                default => 'nullable|string|max:160',
            };
        }
        $v = validator($r->all(), $rules, ['name.required' => 'Indiquez votre nom.', 'phone.required' => 'Indiquez votre numéro de téléphone.', 'phone.min' => 'Numéro de téléphone incomplet.', 'email.email' => 'Adresse e-mail invalide.']);
        if ($v->fails()) {
            return $back($v->errors()->first());
        }
        RateLimiter::hit($key, 600);
        $source = $p->source_id ? DB::table('ac_sources')->where('id', $p->source_id)->first() : null;
        $source ??= DB::table('ac_sources')->where('company_id', $c->id)->where('platform', 'landing')->where('active', true)->orderBy('id')->first();
        $answers = array_intersect_key($v->validated(), array_flip($fields));
        $utm = array_filter($r->only(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content']), fn ($x) => is_string($x) && $x !== '');
        $eventId = 'lp-'.$p->id.'-'.Str::random(10);
        try {
            app(LeadIntake::class)->receive($c, $source, 'landing', $answers + ['Page' => $p->title], ['external_id' => 'page'.$p->id.':'.Str::random(12), 'form_name' => $p->title,
                'utm_source' => $utm['utm_source'] ?? ($r->filled('fbclid') ? 'facebook' : ($r->filled('gclid') ? 'google' : null)), 'utm_medium' => $utm['utm_medium'] ?? null,
                'utm_campaign' => $utm['utm_campaign'] ?? null, 'utm_content' => $utm['utm_content'] ?? null]);
        } catch (\DomainException $e) {
            return $back($e->getMessage());
        }
        rescue(fn () => app(AdPlatforms::class)->metaConversion($c, 'Lead', ['email' => $answers['email'] ?? null, 'phone' => $answers['phone'] ?? null, 'fbc' => $r->input('fbc') ?: $r->cookie('_fbc'),
            'fbp' => $r->cookie('_fbp'), 'ip' => $r->ip(), 'ua' => $r->userAgent()], null, $eventId, 'website', url('/lp/'.$p->slug)), null, false);
        session()->flash('lp_event', $eventId);

        return $p->redirect_url ? redirect()->away($p->redirect_url) : redirect('/lp/'.$p->slug.'?merci=1#form');
    }
}
