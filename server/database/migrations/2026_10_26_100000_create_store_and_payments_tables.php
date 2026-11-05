<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Réglages de la plateforme (boutique, contacts, coordonnées bancaires, moyens de paiement autorisés…)
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        // Vente des modules : prix fixe et / ou abonnement, essai, fiche boutique
        Schema::table('modules', function (Blueprint $table) {
            $table->string('billing_mode', 20)->default('subscription')->after('is_active'); // free, one_time, subscription, both
            $table->decimal('price_one_time', 15, 2)->default(0)->after('price_yearly');
            $table->unsignedSmallInteger('trial_days')->default(0)->after('price_one_time');
            $table->boolean('in_store')->default(true)->after('trial_days');
            $table->string('tagline')->nullable()->after('description');
            $table->text('long_description')->nullable()->after('tagline');
            $table->json('features')->nullable()->after('long_description');
            $table->json('screenshots')->nullable()->after('features');
            $table->string('demo_url')->nullable()->after('screenshots');
        });

        Schema::table('company_modules', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('price_override'); // admin, purchase, trial
            $table->string('billing', 20)->nullable()->after('source');        // one_time, monthly, yearly, trial
            $table->boolean('trial_used')->default(false)->after('billing');
        });

        // Moyens de paiement : company_id NULL = ceux de la plateforme (vente des modules)
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->index();
            $table->string('driver', 30);
            $table->string('label');
            $table->string('mode', 10)->default('live'); // test, live
            $table->text('credentials')->nullable();      // chiffré
            $table->text('instructions')->nullable();     // moyens hors ligne : RIB, numéro Mobile Money…
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('module_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('user_id')->nullable();
            $table->string('number', 30)->unique();
            $table->string('module_key', 50);
            $table->string('billing', 20); // one_time, monthly, yearly
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('XOF');
            $table->string('status', 30)->default('pending'); // pending, awaiting_validation, paid, failed, cancelled
            $table->foreignId('gateway_id')->nullable();
            $table->string('payer_reference')->nullable(); // référence du virement / transaction saisie par le client
            $table->text('notes')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('validated_by')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamps();
        });

        // Transactions de paiement en ligne (commande de module ou facture d'une société)
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->nullable()->index(); // société concernée
            $table->foreignId('gateway_id')->index();
            $table->string('purpose', 20);   // module_order, invoice
            $table->string('purpose_id', 64);
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('XOF');
            $table->string('status', 20)->default('pending'); // pending, paid, failed, cancelled
            $table->string('reference')->nullable();          // identifiant chez le prestataire
            $table->text('payment_url')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['purpose', 'purpose_id']);
        });

        // Liens publics de paiement des factures
        Schema::create('invoice_pay_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->uuid('document_id')->unique();
            $table->string('token', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_pay_links');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('module_orders');
        Schema::dropIfExists('payment_gateways');
        Schema::table('company_modules', fn (Blueprint $t) => $t->dropColumn(['source', 'billing', 'trial_used']));
        Schema::table('modules', fn (Blueprint $t) => $t->dropColumn(['billing_mode', 'price_one_time', 'trial_days', 'in_store', 'tagline', 'long_description', 'features', 'screenshots', 'demo_url']));
        Schema::dropIfExists('platform_settings');
    }
};
