<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Offres commerciales : formule gratuite, remise annuelle, remise de lancement au mois,
 * codes promo (une fois par utilisateur), et live chat des visiteurs du site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $t) {
            $t->boolean('is_free')->default(false);
            $t->decimal('yearly_discount', 5, 2)->default(0);   // % de remise sur 12 mois
            $t->decimal('intro_discount', 5, 2)->default(0);    // % de remise au mois…
            $t->unsignedSmallInteger('intro_months')->default(0); // …pendant N premiers mois payés
            $t->string('badge', 40)->nullable();
        });
        Schema::table('module_orders', function (Blueprint $t) {
            $t->unsignedBigInteger('promo_code_id')->nullable();
            $t->decimal('discount', 15, 2)->default(0);
            $t->decimal('base_amount', 15, 2)->nullable();
            $t->string('kind', 10)->nullable(); // new, renew
        });
        Schema::create('promo_codes', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('description')->nullable();
            $t->string('type', 10)->default('percent'); // percent, amount
            $t->decimal('value', 15, 2);
            $t->string('applies_to', 10)->default('all'); // all, plan, module
            $t->string('target', 60)->nullable();          // code de formule ou clé de module (vide = toutes / tous)
            $t->string('usage', 10)->default('any');       // any, new, renew
            $t->timestamp('valid_from')->nullable();
            $t->timestamp('valid_until')->nullable();
            $t->unsignedInteger('max_uses')->nullable();
            $t->unsignedInteger('uses')->default(0);
            $t->boolean('once_per_user')->default(true);
            $t->boolean('is_active')->default(true);
            $t->string('batch', 40)->nullable()->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('promo_redemptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('promo_code_id')->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('order_id')->nullable();
            $t->decimal('discount', 15, 2)->default(0);
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('live_chats', function (Blueprint $t) {
            $t->id();
            $t->string('token', 64)->unique();
            $t->string('name', 120)->nullable();
            $t->string('email', 160)->nullable();
            $t->string('phone', 60)->nullable();
            $t->string('page', 190)->nullable();
            $t->string('status', 10)->default('open'); // open, closed
            $t->unsignedInteger('unread_admin')->default(0);
            $t->unsignedInteger('unread_visitor')->default(0);
            $t->timestamp('last_message_at')->nullable()->index();
            $t->timestamp('visitor_seen_at')->nullable();
            $t->string('ip', 64)->nullable();
            $t->string('device', 30)->nullable();
            $t->timestamps();
        });
        Schema::create('live_chat_messages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('live_chat_id')->index();
            $t->string('sender', 10); // visitor, admin
            $t->unsignedBigInteger('user_id')->nullable();
            $t->text('body');
            $t->timestamp('created_at')->nullable();
        });

        // Offres de départ (modifiables dans Super admin › Formules)
        if (! DB::table('plans')->where('code', 'gratuit')->exists()) {
            DB::table('plans')->insert([
                'code' => 'gratuit', 'name' => 'Gratuit', 'is_free' => true, 'price_monthly' => 0, 'price_yearly' => 0,
                'currency' => config('erp.defaults.currency', 'XOF'), 'max_users' => null, 'sort' => 0, 'is_active' => true,
                'description' => 'Les fonctions de base d\'un ERP : ventes et factures, achats, stock et clients, sans limite de durée.',
                'modules' => json_encode(\App\Support\PricingGrid::FREE), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('plans')->where('code', 'gratuit')->update(['is_free' => true, 'price_monthly' => 0, 'price_yearly' => 0]);
        // Paliers clarifiés : chaque formule ajoute un ensemble de modules à la précédente
        foreach (\App\Support\PricingGrid::plans() as $p) {
            if (in_array($p['code'], ['gratuit', 'starter', 'business'], true)) {
                DB::table('plans')->where('code', $p['code'])->update(['modules' => json_encode($p['modules']), 'description' => $p['description']]);
            }
        }
        foreach (['starter' => [15, 3], 'business' => [15, 3], 'entreprise' => [30, 3]] as $code => [$pct, $months]) {
            DB::table('plans')->where('code', $code)->update(['intro_discount' => $pct, 'intro_months' => $months]);
        }
        foreach (DB::table('plans')->where('price_monthly', '>', 0)->get() as $p) {
            DB::table('plans')->where('id', $p->id)->update(['yearly_discount' => 25, 'price_yearly' => round($p->price_monthly * 12 * 0.75 / 100) * 100]);
        }
        DB::table('plans')->where('code', 'business')->update(['badge' => 'Le plus choisi']);
        // Tous les employés de l'entreprise peuvent travailler : seuls les modules sont payants
        DB::table('plans')->update(['max_users' => null]);
    }

    public function down(): void
    {
        Schema::dropIfExists('live_chat_messages');
        Schema::dropIfExists('live_chats');
        Schema::dropIfExists('promo_redemptions');
        Schema::dropIfExists('promo_codes');
        Schema::table('module_orders', fn (Blueprint $t) => $t->dropColumn(['promo_code_id', 'discount', 'base_amount', 'kind']));
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn(['is_free', 'yearly_discount', 'intro_discount', 'intro_months', 'badge']));
    }
};
