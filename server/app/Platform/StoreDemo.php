<?php

namespace App\Platform;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Modules\Core\Models\Partner;
use App\Modules\Core\Models\Product;
use App\Modules\Core\Models\ProductCategory;
use App\Modules\Pos\Models\PosRegister;
use App\Modules\Stock\Models\StockMove;
use App\Modules\Stock\Models\Warehouse;
use App\Services\CompanySetup;
use App\Support\ModuleRegistry;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Compte de démonstration pour la relecture des stores (App Store, Google Play) : une société à part,
 * tous les modules de la formule la plus complète, quelques données d'exemple et un utilisateur
 * administrateur de cette société seulement (jamais super admin). Le mot de passe est gardé chiffré
 * pour être recopié dans App Store Connect / Play Console ; il ne peut être changé que depuis l'administration.
 */
class StoreDemo
{
    public const KEY = 'store_demo';

    /** @return array{company_id:int, user_id:int, password:string}|null */
    protected function ref(): ?array
    {
        $r = PlatformSetting::get(self::KEY);

        return is_array($r) && ! empty($r['company_id']) ? $r : null;
    }

    public static function isDemoUser(?User $u): bool
    {
        if (! $u) {
            return false;
        }
        $r = PlatformSetting::get(self::KEY);

        return is_array($r) && (int) ($r['user_id'] ?? 0) === (int) $u->id;
    }

    public static function companyId(): ?int
    {
        $r = PlatformSetting::get(self::KEY);

        return is_array($r) && ! empty($r['company_id']) ? (int) $r['company_id'] : null;
    }

    public function status(): array
    {
        $r = $this->ref();
        $company = $r ? Company::find($r['company_id']) : null;
        $user = $r ? User::find($r['user_id']) : null;
        if (! $company || ! $user) {
            return ['exists' => false, 'suggested_email' => $this->suggestedEmail()];
        }

        return [
            'exists' => true,
            'company' => ['id' => $company->id, 'name' => $company->name, 'status' => $company->status, 'plan' => $company->plan?->name],
            'email' => $user->email,
            'password' => rescue(fn () => Crypt::decryptString($r['password']), null, false),
            'active' => (bool) $user->is_active && $company->status !== 'suspended',
            'last_login' => $user->last_seen_at?->toIso8601String(),
            'last_platform' => $user->last_platform,
            'created_at' => $company->created_at?->toIso8601String(),
            'login_url' => rtrim((string) config('app.url'), '/').'/app/',
            'review_notes' => $this->reviewNotes($user->email),
        ];
    }

    public function suggestedEmail(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'example.com';
        $host = preg_replace('/^(www|erp|app)\./', '', strtolower($host));

        return 'demo.stores@'.(str_contains($host, '.') && ! filter_var($host, FILTER_VALIDATE_IP) ? $host : 'example.com');
    }

    public function create(string $email, string $companyName = 'Yomara Démo'): array
    {
        abort_if($this->status()['exists'], 422, 'Le compte de démonstration existe déjà : utilisez « Nouveau mot de passe » ou supprimez-le d\'abord.');
        $email = strtolower(trim($email));
        abort_if(User::where('email', $email)->exists(), 422, 'Cette adresse e-mail est déjà utilisée par un autre compte.');

        $previous = app()->bound('tenant') ? app('tenant') : null; // tenant et utilisateur de la requête, rétablis à la fin
        $previousUser = auth()->user();
        $plan = Plan::where('code', 'entreprise')->first()
            ?? Plan::where('is_active', true)->get()->sortByDesc(fn ($p) => count($p->modules ?? []))->first();
        $password = $this->newPassword();

        ['company' => $company, 'user' => $user] = app(CompanySetup::class)->create(
            ['name' => $companyName, 'email' => $email, 'phone' => '+225 01 00 00 00 00', 'city' => 'Abidjan'],
            ['name' => 'Compte démo', 'email' => $email, 'password' => $password],
            $plan,
            'active',
        );
        $s = $company->settings ?? [];
        $s['store_demo'] = true;
        $company->forceFill(['settings' => $s, 'subscription_ends_at' => null, 'trial_ends_at' => null])->saveQuietly();
        $user->forceFill(['is_active' => true, 'email_verified_at' => now()])->saveQuietly();

        PlatformSetting::put(self::KEY, ['company_id' => $company->id, 'user_id' => $user->id, 'password' => Crypt::encryptString($password)]);

        // Données d'exemple : un échec ne bloque pas le compte (les écrans restent utilisables, vides)
        try {
            $this->seed($company->fresh(), $user);
        } catch (\Throwable $e) {
            Log::warning('Compte démo stores : données d\'exemple incomplètes — '.$e->getMessage());
        } finally {
            app()->forgetInstance('tenant');
        }
        try {
            $company = $company->fresh();
            app()->instance('tenant', $company);
            $registry = app(ModuleRegistry::class);
            $registry->flush();
            $registry->ensureSetup($company);
        } catch (\Throwable $e) {
            Log::warning('Compte démo stores : préparation des modules — '.$e->getMessage());
        } finally {
            $previous ? app()->instance('tenant', $previous) : app()->forgetInstance('tenant');
            if ($previousUser) {
                auth()->setUser($previousUser);
            }
        }
        ActivityLog::record('admin.store_demo.created', $company);

        return $this->status();
    }

    /** Nouveau mot de passe, compte réactivé (après un test de suppression de compte par la relecture), appareils déconnectés. */
    public function resetPassword(): array
    {
        $r = $this->ref();
        $user = $r ? User::find($r['user_id']) : null;
        abort_unless($user, 404, 'Aucun compte de démonstration.');
        $password = $this->newPassword();
        $user->forceFill(['password' => $password, 'is_active' => true])->save();
        $user->tokens()->delete();
        Company::where('id', $r['company_id'])->where('status', 'suspended')->update(['status' => 'active']);
        PlatformSetting::put(self::KEY, ['company_id' => $r['company_id'], 'user_id' => $user->id, 'password' => Crypt::encryptString($password)]);
        ActivityLog::record('admin.store_demo.password', $user);

        return $this->status();
    }

    /** Désactive le compte (société suspendue, utilisateur déconnecté) ; il peut être réactivé par « Nouveau mot de passe ». */
    public function disable(): array
    {
        $r = $this->ref();
        abort_unless($r, 404, 'Aucun compte de démonstration.');
        Company::where('id', $r['company_id'])->update(['status' => 'suspended']);
        if ($u = User::find($r['user_id'])) {
            $u->forceFill(['is_active' => false])->save();
            $u->tokens()->delete();
        }
        ActivityLog::record('admin.store_demo.disabled', Company::find($r['company_id']));

        return $this->status();
    }

    /** Oublie le compte (la société reste suspendue dans la liste des sociétés) pour en créer un nouveau. */
    public function forget(): array
    {
        if ($this->ref()) {
            $this->disable();
        }
        PlatformSetting::put(self::KEY, null);

        return $this->status();
    }

    protected function newPassword(): string
    {
        // Lisible et facile à recopier : pas de caractères ambigus (0/O, 1/l/I)
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $p = '';
        for ($i = 0; $i < 12; $i++) {
            $p .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return 'Demo-'.$p;
    }

    protected function reviewNotes(string $email): string
    {
        return "Compte de démonstration / Demo account\n"
            ."E-mail : {$email}\n"
            ."Ouvrez l'application, choisissez « Se connecter » et saisissez l'adresse et le mot de passe ci-dessus.\n"
            ."Open the app, tap \"Se connecter\" (Sign in) and enter the e-mail and password above. The account belongs to a sample company with every module enabled and sample data (customers, products, invoices, stock, CRM, HR).\n"
            ."La suppression de compte se trouve dans Paramètres › Mon compte › Supprimer mon compte. / Account deletion: Settings (Paramètres) › My account › \"Supprimer mon compte\".";
    }

    /** Données d'exemple : catalogue, stock, clients, factures, salariés, opportunités. */
    protected function seed(Company $company, User $owner): void
    {
        app()->instance('tenant', $company);
        auth()->setUser($owner);

        $cats = [];
        foreach (['Boissons', 'Épicerie', 'Hygiène'] as $name) {
            $cats[$name] = ProductCategory::create(['name' => $name])->id;
        }
        $wh = Warehouse::where('is_default', true)->first() ?? Warehouse::create(['name' => 'Boutique principale', 'code' => 'BTQ', 'is_default' => true]);
        $items = [
            ['Boissons', 'Coca-Cola 33cl', 500, 350], ['Boissons', 'Eau minérale 1,5L', 400, 250], ['Boissons', 'Jus de bissap 50cl', 600, 300],
            ['Épicerie', 'Riz parfumé 5kg', 4500, 3600], ['Épicerie', 'Huile végétale 1L', 1500, 1150], ['Épicerie', 'Sucre en morceaux 1kg', 900, 700],
            ['Épicerie', 'Lait concentré 397g', 750, 560], ['Hygiène', 'Savon de Marseille', 350, 220], ['Hygiène', 'Dentifrice 75ml', 1000, 700],
        ];
        foreach ($items as [$cat, $name, $ttc, $cost]) {
            $p = Product::create(['category_id' => $cats[$cat], 'name' => $name, 'sale_price' => round($ttc / 1.18, 2), 'purchase_price' => $cost, 'tax_rate' => 18, 'min_stock' => 10]);
            StockMove::create(['product_id' => $p->id, 'warehouse_id' => $wh->id, 'quantity' => 60, 'type' => 'initial', 'date' => now()->toDateString()]);
        }
        $clients = [];
        foreach ([['Maquis Chez Tantie', 'Cocody'], ['Hôtel Lagune', 'Marcory'], ['Supérette Bonheur', 'Yopougon'], ['Clinique Les Palmiers', 'Plateau']] as $i => [$n, $city]) {
            $clients[] = Partner::create(['name' => $n, 'type' => 'customer', 'phone' => '+225 07 00 00 00 0'.($i + 1), 'city' => $city]);
        }
        $supplier = Partner::create(['name' => 'Grossiste Adjamé SARL', 'type' => 'supplier', 'phone' => '+225 27 20 00 00 00', 'city' => 'Abidjan']);
        if (class_exists(PosRegister::class)) {
            rescue(fn () => PosRegister::create(['name' => 'Caisse 1', 'code' => 'C1', 'warehouse_id' => $wh->id]), null, false);
        }

        $products = Product::all()->keyBy('name');
        $line = fn (string $name, float $q, ?float $price = null) => ['product_id' => $products[$name]->id, 'description' => $name, 'quantity' => $q, 'unit_price' => $price ?? $products[$name]->sale_price, 'tax_rate' => 18];
        $doc = function (string $class, string $type, Partner $p, array $lines, int $daysAgo, bool $validate = true) {
            $d = new $class;
            $d->fill(['type' => $type, 'partner_id' => $p->id, 'partner_name' => $p->name, 'date' => now()->subDays($daysAgo)->toDateString()]);
            $d->save();
            $d->replaceLines($lines);
            if ($validate) {
                $d->validateDocument();
            }

            return $d->fresh();
        };
        $pay = function (string $class, $d, float $amount, string $method, int $daysAgo) {
            $p = new $class(['document_id' => $d->id, 'date' => now()->subDays($daysAgo)->toDateString(), 'amount' => $amount, 'method' => $method]);
            $p->partner_id = $d->partner_id;
            $p->save();
            $d->refreshPaymentStatus();
        };
        $sales = \App\Modules\Ventes\Models\SalesDocument::class;
        $achat = $doc(\App\Modules\Achats\Models\PurchaseDocument::class, 'supplier_invoice', $supplier, [$line('Riz parfumé 5kg', 40, 3600), $line('Huile végétale 1L', 60, 1150)], 12);
        $pay(\App\Modules\Achats\Models\PurchasePayment::class, $achat, $achat->total, 'bank_transfer', 10);
        $f1 = $doc($sales, 'invoice', $clients[0], [$line('Coca-Cola 33cl', 48), $line('Eau minérale 1,5L', 24)], 8);
        $pay(\App\Modules\Ventes\Models\SalesPayment::class, $f1, $f1->total, 'mobile_money', 6);
        $doc($sales, 'invoice', $clients[1], [$line('Riz parfumé 5kg', 10), $line('Huile végétale 1L', 12)], 3);
        $doc($sales, 'quote', $clients[2], [$line('Sucre en morceaux 1kg', 30), $line('Lait concentré 397g', 48)], 1, false);

        $emp = fn (array $a) => \App\Modules\Rh\Models\Employee::create($a + ['contract_type' => 'CDI', 'payment_method' => 'bank_transfer', 'transport_allowance' => 25000, 'marital_status' => 'single', 'children_count' => 0]);
        $emp(['matricule' => 'EMP00001', 'first_name' => 'Awa', 'last_name' => 'Koné', 'gender' => 'F', 'job_title' => 'Gérante', 'department' => 'Direction', 'hire_date' => now()->subYears(3)->toDateString(), 'base_salary' => 450000]);
        $emp(['matricule' => 'EMP00002', 'first_name' => 'Yao', 'last_name' => 'Kouassi', 'gender' => 'M', 'job_title' => 'Caissier', 'department' => 'Boutique', 'hire_date' => now()->subYears(1)->toDateString(), 'base_salary' => 120000]);
        $emp(['matricule' => 'EMP00003', 'first_name' => 'Mariam', 'last_name' => 'Traoré', 'gender' => 'F', 'job_title' => 'Commerciale', 'department' => 'Ventes', 'hire_date' => now()->subMonths(8)->toDateString(), 'base_salary' => 180000]);
        \App\Models\Sequence::updateOrCreate(['company_id' => $company->id, 'key' => 'rh.matricule', 'year' => 0], ['last_number' => 3]);

        app(\App\Modules\Crm\CrmSetup::class)($company);
        $s = $company->fresh()->settings ?? [];
        $s['_setup']['crm'] = 1;
        $company->forceFill(['settings' => $s])->saveQuietly();
        $stage = \App\Modules\Crm\Models\Stage::pluck('id', 'name');
        foreach ([
            ['Approvisionnement mensuel boissons', 'Maquis Chez Tantie', 'Qualifié', 450000, $clients[0]->id],
            ['Fourniture cantine scolaire', 'Groupe Scolaire Les Lauriers', 'Proposition envoyée', 1800000, null],
            ['Livraison hebdomadaire hôtel', 'Hôtel Lagune', 'Négociation', 2500000, $clients[1]->id],
            ['Colis de fin d\'année', 'Banque Atlantique Cocody', 'Nouveau', 3200000, null],
        ] as $i => [$title, $comp, $st, $amount, $partner]) {
            if (! isset($stage[$st])) {
                continue;
            }
            (new \App\Modules\Crm\Models\Lead(['title' => $title, 'company_name' => $comp, 'stage_id' => $stage[$st], 'expected_revenue' => $amount, 'source' => 'website',
                'expected_close_date' => now()->addDays(10 + $i * 7)->toDateString(), 'owner_id' => $owner->id, 'priority' => 2, 'partner_id' => $partner, 'city' => 'Abidjan']))->save();
        }
    }
}
