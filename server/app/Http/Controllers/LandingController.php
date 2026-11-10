<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Module;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Services\CompanySetup;
use App\Services\SiteTraffic;
use App\Store\ModuleStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Site de présentation de la plateforme (mode SaaS) : vitrine, inscription en ligne
 * et téléchargement des applications. En installation unique, « / » ouvre l'application.
 */
class LandingController extends Controller
{
    public const PLATFORMS = [
        'windows' => 'Windows',
        'mac' => 'macOS',
        'linux' => 'Linux',
        'android' => 'Android',
        'ios' => 'iPhone & iPad',
    ];

    /**
     * Liens de boutique par plateforme (clé enregistrée dans landing.downloads). L'App Store garde la clé « ios »
     * (pas de fichier à installer sur iPhone) ; les autres plateformes ont en plus le fichier direct (clé = plateforme).
     */
    public const STORE_KEYS = ['windows' => 'windows_store', 'mac' => 'mac_store', 'android' => 'android_store', 'ios' => 'ios'];

    public const STORE_NAMES = ['windows' => 'Microsoft Store', 'mac' => 'Mac App Store', 'android' => 'Google Play', 'ios' => 'App Store'];

    public const FILE_NAMES = ['windows' => 'fichier .exe', 'mac' => 'fichier .dmg', 'linux' => 'AppImage', 'android' => 'fichier APK'];

    /** Toutes les clés modifiables depuis l'administration (Boutique › Liens de téléchargement). */
    public const LINK_KEYS = ['windows', 'windows_store', 'mac', 'mac_store', 'linux', 'android', 'android_store', 'ios', 'server', 'github'];

    /** Adresses officielles des fiches, proposées dans l'administration (bouton « Liens officiels »). */
    public static function officialStoreLinks(): array
    {
        return array_filter((array) config('erp.store_links', []));
    }

    protected static function saved(): array
    {
        return array_map(fn ($v) => trim((string) $v), (array) PlatformSetting::get('landing.downloads', []));
    }

    /** Fichier direct par plateforme : lien enregistré, sinon fichier déposé dans public/downloads. */
    public static function files(): array
    {
        $saved = self::saved();
        $out = [];
        foreach (array_keys(self::PLATFORMS) as $k) {
            if ($k === 'ios') {
                continue;
            }
            $url = $saved[$k] ?? '';
            $out[$k] = $url !== '' ? $url : self::localFile($k);
        }

        return $out;
    }

    /** Fiche de boutique par plateforme (null si non renseignée). */
    public static function stores(): array
    {
        $saved = self::saved();

        return array_map(fn ($key) => ($saved[$key] ?? '') !== '' ? $saved[$key] : null, self::STORE_KEYS);
    }

    /** Lien principal par plateforme : la boutique quand elle existe (installation et mises à jour automatiques), sinon le fichier. */
    public static function downloads(): array
    {
        $files = self::files();
        $stores = self::stores();
        $out = [];
        foreach (array_keys(self::PLATFORMS) as $k) {
            $out[$k] = ($stores[$k] ?? null) ?: ($files[$k] ?? null);
        }

        return $out;
    }

    /** Dépôt public du code source (GitHub, GitLab…). */
    public static function github(): ?string
    {
        $u = self::saved()['github'] ?? '';

        return $u !== '' ? $u : (trim((string) config('erp.source_repository')) ?: null);
    }

    /** Version des logiciels affichée sur la page Télécharger (facultative). */
    public static function appsVersion(): ?string
    {
        $v = trim((string) PlatformSetting::get('landing.apps_version', ''));

        return $v !== '' ? $v : null;
    }

    /** Fichiers déposés dans public/downloads : reconnus tout seuls (le plus récent par plateforme). */
    public const LOCAL_FILES = [
        'windows' => '/(\.(exe|msi)|windows[^\/]*\.zip)$/i',
        'mac' => '/(\.dmg|mac(os)?[^\/]*\.zip)$/i',
        'linux' => '/(\.appimage|\.deb|linux[^\/]*\.tar\.gz)$/i',
        'android' => '/\.apk$/i',
        'server' => '/(open-source|serveur|server)[^\/]*\.zip$/i',
    ];

    public static function localFile(string $os, bool $path = false): ?string
    {
        $re = self::LOCAL_FILES[$os] ?? null;
        if (! $re) {
            return null;
        }
        $files = array_filter(glob(public_path('downloads/*')) ?: [], fn ($f) => is_file($f) && preg_match($re, basename($f)));
        if ($os === 'android' && ($arm = array_filter($files, fn ($f) => str_contains($f, 'arm64')))) {
            $files = $arm;
        }
        if ($os === 'linux' && ($img = array_filter($files, fn ($f) => preg_match('/\.appimage$/i', $f)))) {
            $files = $img; // AppImage : se lance sur toutes les distributions
        }
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $f = $files[0] ?? null;

        return $f ? ($path ? $f : url('/downloads/'.rawurlencode(basename($f)))) : null;
    }

    /** Zip du serveur (édition libre, AGPL) pour l'installer sur son propre serveur ou ordinateur. */
    public static function serverZip(): ?string
    {
        $u = trim((string) (((array) PlatformSetting::get('landing.downloads', []))['server'] ?? ''));

        return $u !== '' ? $u : self::localFile('server');
    }

    /** Valeurs saisies dans l'administration, clé par clé (vide = non renseigné). */
    public static function linkSettings(): array
    {
        $saved = self::saved();

        return array_combine(self::LINK_KEYS, array_map(fn ($k) => ($saved[$k] ?? '') !== '' ? $saved[$k] : null, self::LINK_KEYS));
    }

    /** Fichiers déposés dans public/downloads, utilisés quand le champ correspondant est vide. */
    public static function detectedFiles(): array
    {
        $out = [];
        foreach (['windows', 'mac', 'linux', 'android', 'server'] as $k) {
            $out[$k] = self::localFile($k);
        }

        return $out;
    }

    public static function downloadCounts(): array
    {
        return (array) PlatformSetting::get('landing.download_counts', []);
    }

    protected function saas(): bool
    {
        return config('erp.mode') === 'saas';
    }


    /** Pictogrammes des plateformes (SVG en ligne) pour le site. */
    public static function osIcons(): array
    {
        return [
        'web' => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm6.9 6h-2.9a15.7 15.7 0 0 0-1.4-3.6A8 8 0 0 1 18.9 8ZM12 4.1c.8 1.2 1.5 2.5 1.9 3.9h-3.8c.4-1.4 1.1-2.7 1.9-3.9ZM4.3 14a8.2 8.2 0 0 1 0-4h3.3a16.5 16.5 0 0 0 0 4H4.3Zm.8 2h2.9c.3 1.3.8 2.5 1.4 3.6A8 8 0 0 1 5.1 16ZM8 8H5.1a8 8 0 0 1 4.3-3.6C8.8 5.5 8.3 6.7 8 8Zm4 11.9c-.8-1.2-1.5-2.5-1.9-3.9h3.8c-.4 1.4-1.1 2.7-1.9 3.9ZM14.3 14H9.7a14.7 14.7 0 0 1 0-4h4.6a14.7 14.7 0 0 1 0 4Zm.3 5.6c.6-1.1 1.1-2.3 1.4-3.6h2.9a8 8 0 0 1-4.3 3.6Zm1.8-5.6a16.5 16.5 0 0 0 0-4h3.3a8.2 8.2 0 0 1 0 4h-3.3Z"/></svg>',
        'windows' => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M3 5.5 10.5 4.4v7.1H3V5.5Zm0 13 7.5 1.1v-7H3v5.9Zm8.4 1.2L21 21V12.6h-9.6v7.1Zm0-15.4v7.2H21V3l-9.6 1.3Z"/></svg>',
        'mac' => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M16.4 12.6c0-2.4 2-3.6 2.1-3.7-1.1-1.7-2.9-1.9-3.5-1.9-1.5-.2-2.9.9-3.7.9-.8 0-1.9-.9-3.2-.8-1.6 0-3.1 1-4 2.4-1.7 3-.4 7.4 1.2 9.8.8 1.2 1.8 2.5 3 2.4 1.2 0 1.7-.8 3.1-.8 1.5 0 1.9.8 3.2.8 1.3 0 2.2-1.2 3-2.4.9-1.4 1.3-2.7 1.3-2.8 0 0-2.5-1-2.5-3.9ZM14 5.4c.7-.8 1.1-1.9 1-3-1 0-2.1.7-2.8 1.5-.6.7-1.2 1.8-1 2.9 1.1.1 2.1-.6 2.8-1.4Z"/></svg>',
        'linux' => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M12 2c-2.2 0-3.6 1.9-3.6 4.6 0 1.4.3 2.3-.6 3.6-1 1.4-2.9 3.7-2.9 6.3 0 .8.2 1.5.5 2.1-.6.4-1.6.8-1.4 1.6.2.9 2.3.6 3.7 1.4 1.2.6 2.6.6 3.2-.3h2.2c.6.9 2 .9 3.2.3 1.4-.8 3.5-.5 3.7-1.4.2-.8-.8-1.2-1.4-1.6.3-.6.5-1.3.5-2.1 0-2.6-1.9-4.9-2.9-6.3-.9-1.3-.6-2.2-.6-3.6C15.6 3.9 14.2 2 12 2Zm0 6.6c.8 0 2.1.5 2.1.9 0 .3-1.3 1.2-2.1 1.2s-2.1-.9-2.1-1.2c0-.4 1.3-.9 2.1-.9Z"/></svg>',
        'android' => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M17.6 9.5 19.4 6.4a.4.4 0 0 0-.7-.4l-1.8 3.1A11 11 0 0 0 12 8c-1.8 0-3.4.4-4.9 1.1L5.3 6a.4.4 0 0 0-.7.4l1.8 3.1A9.6 9.6 0 0 0 1.5 17h21a9.6 9.6 0 0 0-4.9-7.5ZM7 14.3a1 1 0 1 1 0-2 1 1 0 0 1 0 2Zm10 0a1 1 0 1 1 0-2 1 1 0 0 1 0 2Z"/></svg>',
        'ios' => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M7 1h10a2 2 0 0 1 2 2v18a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2Zm0 3v15h10V4H7Zm5 16.2a.9.9 0 1 0 0 1.8.9.9 0 0 0 0-1.8Z"/></svg>',
        ];
    }

    public const OS_HINT = ['windows' => 'Windows 10 et 11', 'mac' => 'macOS 11 et plus', 'linux' => 'Ubuntu, Debian, Fedora', 'android' => 'Téléphones et tablettes', 'ios' => 'iPhone et iPad'];

    protected function common(): array
    {
        $currency = config('erp.defaults.currency', 'XOF');

        return [
            'osIcons' => self::osIcons(),
            'osHint' => self::OS_HINT,
            'fmt' => fn ($n) => number_format((float) $n, 0, ',', ' '),
            'cur' => $currency === 'XOF' ? 'FCFA' : $currency,
            'appName' => config('erp.name', 'Yomara ERP'),
            'contact' => ModuleStore::contact(),
            'downloads' => self::downloads(),
            'dlFiles' => self::files(),
            'dlStores' => self::stores(),
            'storeNames' => self::STORE_NAMES,
            'fileNames' => self::FILE_NAMES,
            'appsVersion' => self::appsVersion(),
            'sourceRepo' => self::github(),
            'serverZip' => self::serverZip(),
            'platforms' => self::PLATFORMS,
            'trialDays' => \App\Platform\SubscriptionLifecycle::trialDays(),
            'trialOffer' => \App\Platform\SubscriptionLifecycle::offer(),
            'currency' => config('erp.defaults.currency', 'XOF'),
            'chatEnabled' => (bool) PlatformSetting::get('livechat.enabled', true),
            'chatWelcome' => \App\Http\Controllers\Api\LiveChatController::welcome(),
        ];
    }

    protected function plans()
    {
        return Plan::where('is_active', true)->orderBy('sort')->get();
    }

    /** Formules, ce que chacune ajoute à la précédente et leur valeur prise module par module. */
    protected function pricingData(): array
    {
        $byKey = Module::where('is_active', true)->where('is_core', false)->get(['key', 'name', 'price_monthly'])->keyBy('key');
        $plans = $this->plans();
        $prev = [];
        $prevName = null;
        $planInfo = [];
        foreach ($plans as $p) {
            $keys = array_values(array_filter((array) ($p->modules ?? []), fn ($k) => isset($byKey[$k])));
            $added = array_values(array_diff($keys, $prev));
            $value = collect($keys)->sum(fn ($k) => (float) $byKey[$k]->price_monthly);
            $planInfo[$p->code] = [
                'count' => count($keys),
                'base' => $prevName,
                'added' => collect($added)->map(fn ($k) => $byKey[$k]->name)->all(),
                'value' => $value,
                'saving' => $value > 0 && $p->price_monthly > 0 ? max(0, (int) round(100 - $p->price_monthly * 100 / $value)) : 0,
            ];
            $prev = $keys;
            $prevName = $p->name;
        }

        return [
            'planInfo' => $planInfo,
            'plans' => $plans,
            'minModulePrice' => $byKey->where('price_monthly', '>', 0)->min('price_monthly'),
            'maxYearly' => (float) $plans->max('yearly_discount'),
        ];
    }

    protected function modules()
    {
        return Module::where('is_available', true)->where('is_active', true)->orderBy('sort')
            ->get(['key', 'name', 'description', 'category', 'icon', 'price_monthly', 'is_core', 'tagline', 'long_description', 'features', 'billing_mode', 'trial_days']);
    }

    public function home()
    {
        if (! $this->saas()) {
            return redirect('/app/');
        }
        SiteTraffic::record(request());
        $modules = $this->modules()->where('is_core', false);
        $categories = $modules->groupBy(fn ($m) => $m->category ?: 'Autres');
        $pricing = $this->pricingData();
        $paid = $pricing['plans']->filter(fn ($p) => (float) $p->price_monthly > 0);

        return view('landing.home', $this->common() + [
            'categories' => $categories,
            'moduleCount' => $modules->count(),
            'marquee' => $modules->pluck('name')->values()->all(),
            'fromPrice' => $paid->min('price_monthly'),
            'hasFree' => $pricing['plans']->contains(fn ($p) => (float) $p->price_monthly <= 0),
            'planCount' => $pricing['plans']->count(),
        ]);
    }

    /** Page Modules : chaque module expliqué, fiche ouverte au clic. */
    public function modulesPage()
    {
        abort_unless($this->saas(), 404);
        SiteTraffic::record(request());
        $plans = $this->plans();
        $all = $this->modules();
        $list = $all->where('is_core', false)->values()->map(function ($m) use ($plans) {
            $in = $plans->filter(fn ($p) => in_array($m->key, (array) ($p->modules ?? []), true))->pluck('name')->values()->all();

            return [
                'key' => $m->key, 'name' => $m->name, 'category' => $m->category ?: 'Autres', 'description' => (string) $m->description,
                'tagline' => (string) ($m->tagline ?: $m->description), 'long' => (string) $m->long_description,
                'features' => array_values(array_filter((array) ($m->features ?? []), 'is_string')),
                'price' => (float) $m->price_monthly, 'free' => $m->billing_mode === 'free' || (float) $m->price_monthly <= 0,
                'trial' => (int) $m->trial_days, 'plans' => $in, 'icon' => $m->icon,
            ];
        });
        $cats = $list->groupBy('category')->map->count();

        return view('landing.modules', $this->common() + [
            'mods' => $list->all(), 'cats' => $cats->all(), 'core' => $all->where('is_core', true)->pluck('name')->values()->all(),
            'moduleCount' => $list->count(),
        ]);
    }

    public function pricingPage()
    {
        abort_unless($this->saas(), 404);
        SiteTraffic::record(request());

        return view('landing.tarifs', $this->common() + $this->pricingData());
    }

    public function downloadPage()
    {
        abort_unless($this->saas(), 404);
        SiteTraffic::record(request());

        return view('landing.telecharger', $this->common());
    }

    public function contactPage()
    {
        abort_unless($this->saas(), 404);
        SiteTraffic::record(request());

        return view('landing.contact', $this->common() + ['requestTypes' => array_diff_key(self::REQUEST_TYPES, ['suppression' => 1])]);
    }

    public function signupForm(Request $request)
    {
        abort_unless($this->saas(), 404);
        SiteTraffic::record($request, 'signup_form');

        $code = strtoupper(trim((string) $request->query('parrain', '')));
        $referrer = $code !== '' ? \App\Models\Company::where('referral_code', $code)->first() : null;

        return view('landing.signup', $this->common() + [
            'plans' => $this->plans(),
            'selected' => $request->query('formule'),
            'parrain' => $referrer ? $code : null,
            'referrerName' => $referrer?->name,
            'welcomeDiscount' => \App\Store\Referrals::settings()['referee_discount'],
        ]);
    }

    public function signup(Request $request, CompanySetup $setup)
    {
        abort_unless($this->saas(), 404);
        $data = $request->validate([
            'company_name' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'phone' => 'nullable|string|max:50',
            'password' => 'required|string|min:8|confirmed',
            'plan' => ['nullable', 'string', Rule::exists('plans', 'code')->where('is_active', true)],
            'terms' => 'accepted',
            'parrain' => 'nullable|string|max:20',
        ], [
            'email.unique' => 'Cette adresse e-mail a déjà un compte. Connectez-vous ou utilisez « Mot de passe oublié ».',
            'password.confirmed' => 'Les deux mots de passe ne correspondent pas.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'terms.accepted' => 'Veuillez accepter les conditions d\'utilisation.',
        ]);

        $plan = Plan::where('code', $data['plan'] ?? null)->where('is_active', true)->first()
            ?? Plan::where('is_active', true)->orderBy('sort')->first();
        $email = strtolower($data['email']);

        ['user' => $user, 'company' => $company] = $setup->create(
            ['name' => $data['company_name'], 'email' => $email, 'phone' => $data['phone'] ?? null],
            ['name' => $data['name'], 'email' => $email, 'phone' => $data['phone'] ?? null, 'password' => $data['password']],
            $plan,
            $plan?->is_free || ($plan && $plan->price_monthly <= 0) ? 'active' : 'trial',
        );
        ActivityLog::record('landing.signup', $company ?? null);
        \App\Store\Referrals::attach($company, $data['parrain'] ?? null);

        SiteTraffic::record($request, 'signup', $plan?->code);
        SiteTraffic::capture('signup', ['name' => $data['name'], 'email' => $email, 'phone' => $data['phone'] ?? null, 'company' => $data['company_name'], 'tags' => array_filter([$plan?->code])]);
        $mailed = $this->sendWelcome($user->name, $email, '', $data['company_name'], $plan);
        rescue(fn () => \App\Platform\SubscriptionLifecycle::welcome($company->fresh()), null, false);

        // Résumé affiché une seule fois (jamais le mot de passe)
        return redirect('/inscription/bienvenue')->with('signup', [
            'name' => $user->name,
            'email' => $email,
            'company' => $data['company_name'],
            'plan' => $plan?->name,
            'mailed' => $mailed,
        ]);
    }

    public const REQUEST_TYPES = [
        'custom_module' => 'Module sur mesure',
        'demo' => 'Démonstration',
        'quote' => 'Devis / grande entreprise',
        'install' => 'Installation sur mon serveur',
        'contact' => 'Autre question',
        'suppression' => 'Suppression de compte',
    ];

    /** Formulaire « Nous contacter / module sur mesure » : enregistré et envoyé par e-mail à l'éditeur. */
    public function contactRequest(Request $request)
    {
        abort_unless($this->saas(), 404);
        if ($request->filled('website')) { // piège à robots
            return redirect('/contact#formulaire')->with('contact_ok', true);
        }
        $d = $request->validate([
            'type' => ['required', Rule::in(array_keys(array_diff_key(self::REQUEST_TYPES, ['suppression' => 1])))],
            'name' => 'required|string|max:120',
            'company' => 'nullable|string|max:160',
            'email' => 'required|email|max:160',
            'phone' => 'nullable|string|max:60',
            'budget' => 'nullable|string|max:60',
            'message' => 'required|string|min:10|max:5000',
        ], ['message.min' => 'Décrivez votre besoin en quelques mots (10 caractères minimum).']);
        $id = \Illuminate\Support\Facades\DB::table('landing_requests')->insertGetId($d + ['status' => 'new', 'ip' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);

        SiteTraffic::record($request, 'contact', $d['type']);
        SiteTraffic::capture('contact', $d + ['label' => self::REQUEST_TYPES[$d['type']], 'tags' => [$d['type']]]);
        $to = ModuleStore::contact()['email'] ?: config('mail.from.address');
        if ($to) {
            try {
                $label = self::REQUEST_TYPES[$d['type']];
                $rows = collect(['Type' => $label, 'Nom' => $d['name'], 'Entreprise' => $d['company'] ?? '—', 'E-mail' => $d['email'], 'Téléphone' => $d['phone'] ?? '—', 'Budget' => $d['budget'] ?? '—'])
                    ->map(fn ($v, $k) => '<tr><td style="padding:6px 12px;color:#64748B">'.e($k).'</td><td style="padding:6px 12px;font-weight:600">'.e($v).'</td></tr>')->implode('');
                $html = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#0B1530"><h2 style="margin:0 0 12px">Nouvelle demande : '.e($label).'</h2><table style="border-collapse:collapse;background:#F7F9FD;border-radius:10px">'.$rows.'</table>'
                    .'<p style="white-space:pre-wrap;background:#fff;border:1px solid #E5E9F2;border-radius:10px;padding:14px;margin-top:14px">'.e($d['message']).'</p>'
                    .'<p style="color:#64748B;font-size:13px">Demande n° '.$id.' reçue depuis '.e(url('/')).' — répondez directement à cet e-mail pour écrire au client.</p></div>';
                Mail::html($html, function ($m) use ($to, $d, $label) {
                    $m->to($to)->replyTo($d['email'], $d['name'])->subject('['.config('erp.name', 'Yomara ERP').'] '.$label.' — '.$d['name'].($d['company'] ? ' ('.$d['company'].')' : ''));
                });
                // Accusé de réception au visiteur
                Mail::html('<div style="font-family:Segoe UI,Arial,sans-serif;color:#0B1530"><p>Bonjour '.e($d['name']).',</p><p>Nous avons bien reçu votre demande (« '.e($label).' »). Notre équipe vous répond très vite, en général sous 24 h ouvrées.</p><p>— '.e(ModuleStore::contact()['name'] ?: config('erp.name')).'</p></div>',
                    fn ($m) => $m->to($d['email'], $d['name'])->subject('Votre demande a bien été reçue'));
            } catch (\Throwable $e) {
                Log::warning('Demande du site non envoyée par e-mail : '.$e->getMessage());
            }
        }

        return redirect('/contact#formulaire')->with('contact_ok', true);
    }

    public function welcome(Request $request)
    {
        abort_unless($this->saas(), 404);
        $s = $request->session()->get('signup');
        if (! $s) {
            return redirect('/inscription');
        }

        return view('landing.welcome', $this->common() + ['s' => $s]);
    }

    public function download(string $os)
    {
        // Code source (AGPL) : page avec le serveur (dernière version publiée ou fichier déposé) et les applications
        if ($os === 'source') {
            $rel = \Illuminate\Support\Facades\Schema::hasTable('upd_releases')
                ? \Illuminate\Support\Facades\DB::table('upd_releases')->where('published', true)->where('channel', 'stable')->orderByDesc('id')->first() : null;
            if (request()->query('fichier') === 'serveur' && $rel && $rel->path && is_file($rel->path)) {
                return response()->download($rel->path, 'yomara-erp-'.$rel->version.'-source-agpl.zip', ['Content-Type' => 'application/zip']);
            }
            $appSrc = collect(glob(public_path('downloads/*app-source*.zip')) ?: [])->sortByDesc(fn ($f) => filemtime($f))->first();

            return view('landing.source', $this->common() + [
                'server' => ($rel && $rel->path && is_file($rel->path)) ? url('/telecharger/source?fichier=serveur') : self::serverZip(),
                'version' => $rel->version ?? config('erp.version'),
                'app' => $appSrc ? url('/downloads/'.rawurlencode(basename($appSrc))) : null,
            ]);
        }
        abort_unless(array_key_exists($os, self::PLATFORMS) || in_array($os, ['serveur', 'github'], true), 404);
        $url = match ($os) {
            'serveur' => self::serverZip(),
            'github' => self::github(),
            default => self::downloads()[$os],
        };

        return $this->sendTo($url, $os === 'serveur' ? 'server' : $os, $os);
    }

    /** Fichier direct (APK, DMG, EXE) quand la plateforme a aussi une fiche de boutique. */
    public function downloadFile(string $os)
    {
        return $this->sendTo(self::files()[$os] ?? null, $os.'_file', $os);
    }

    protected function sendTo(?string $url, string $countKey, string $trafficKey)
    {
        if (! $url) {
            return redirect('/telecharger');
        }
        SiteTraffic::record(request(), 'download', $trafficKey);
        try {
            $counts = self::downloadCounts();
            $k = $countKey;
            $counts[$k] = (int) ($counts[$k] ?? 0) + 1;
            PlatformSetting::put('landing.download_counts', $counts);
        } catch (\Throwable) {
        }

        return redirect()->away($url);
    }

    /**
     * Toutes les applications proposées dans l'e-mail de bienvenue : boutique (liens enregistrés dans l'administration,
     * sinon fiches officielles) et fichier à installer quand il est disponible.
     *
     * @return list<array{platform: string, label: string, url: string}>
     */
    public static function welcomeApps(string $url): array
    {
        $saved = self::stores();
        $official = self::officialStoreLinks();
        $files = self::files();
        $out = [];
        foreach (self::PLATFORMS as $k => $name) {
            $storeKey = self::STORE_KEYS[$k] ?? null;
            $store = $storeKey ? (($saved[$k] ?? null) ?: ($official[$storeKey] ?? null)) : null;
            if ($store) {
                $out[] = ['platform' => $name, 'label' => self::STORE_NAMES[$k], 'url' => $store];
            }
            if ($files[$k] ?? null) {
                $out[] = ['platform' => $name, 'label' => 'Télécharger ('.(self::FILE_NAMES[$k] ?? 'fichier').')', 'url' => $url.'/telecharger/'.$k];
            }
        }

        return $out;
    }

    public function sendWelcome(string $name, string $email, string $password, string $company, ?Plan $plan): bool
    {
        try {
            $html = view('landing.mail-welcome', $this->common() + [
                'name' => $name, 'email' => $email, 'company' => $company,
                'plan' => $plan, 'url' => rtrim(config('app.url'), '/'),
                'modules' => \App\Platform\SubscriptionLifecycle::planModules($plan),
                'trialEnds' => \App\Models\User::where('email', $email)->first()?->company?->trial_ends_at,
                'apps' => self::welcomeApps(rtrim(config('app.url'), '/')),
            ])->render();
            $loc = app()->getLocale();
            $subject = \App\Support\Tr::f('Bienvenue sur {} : votre espace est prêt', [config('erp.name', 'Yomara ERP')], $loc);
            if ($loc !== 'fr') {
                $html = \App\Support\HtmlTranslator::translate($html, $loc);
            }
            Mail::html($html, function ($m) use ($email, $name, $subject) {
                $m->to($email, $name)->subject($subject);
            });

            return true;
        } catch (\Throwable $e) {
            Log::warning('E-mail de bienvenue non envoyé : '.$e->getMessage());

            return false;
        }
    }
}
