<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('woo_stores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('name', 120);
            $t->string('url', 255);
            $t->text('credentials'); // chiffré : consumer_key, consumer_secret
            $t->boolean('query_auth')->default(false); // hébergeurs qui suppriment l'en-tête Authorization
            $t->string('token', 40)->unique(); // adresse du webhook
            $t->string('webhook_secret', 64);
            $t->string('status', 12)->default('active'); // active, paused, error
            $t->string('error', 500)->nullable();
            $t->json('settings')->nullable();
            $t->json('info')->nullable(); // devise, prix TTC, version WooCommerce, ids des webhooks, catégories
            $t->timestamp('last_products_sync_at')->nullable();
            $t->timestamp('last_orders_sync_at')->nullable();
            $t->timestamp('last_webhook_at')->nullable();
            $t->timestamps();
        });

        Schema::create('woo_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->foreignId('store_id');
            $t->string('entity', 10); // product, variation, order
            $t->string('local_id', 36)->nullable(); // produit ou document de vente
            $t->unsignedBigInteger('remote_id')->nullable(); // null : produit choisi, pas encore créé sur le site
            $t->unsignedBigInteger('remote_parent_id')->nullable(); // produit variable d'une variation
            $t->string('hash', 32)->nullable(); // dernier état envoyé
            $t->json('meta')->nullable(); // commande : numéro, statut, paiement, total
            $t->string('error', 300)->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
            $t->index(['store_id', 'entity', 'local_id']);
            $t->index(['store_id', 'entity', 'remote_id']);
        });

        Schema::create('woo_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->foreignId('store_id')->index();
            $t->string('level', 8)->default('info'); // info, warning, error
            $t->string('message', 500);
            $t->timestamp('created_at')->nullable();
        });

        foreach ((Schema::hasTable('plans') ? DB::table('plans')->whereIn('code', ['professional', 'entreprise', 'sur_mesure'])->get() : []) as $p) {
            $m = (array) json_decode((string) $p->modules, true);
            if (! in_array('woocommerce', $m, true)) {
                $m[] = 'woocommerce';
                DB::table('plans')->where('id', $p->id)->update(['modules' => json_encode(array_values($m))]);
            }
        }
        if (Schema::hasTable('roles')) {
            foreach (DB::table('roles')->whereNotNull('permissions')->get(['id', 'permissions']) as $r) {
                $p = (array) json_decode((string) $r->permissions, true);
                if (array_intersect($p, ['ecommerce.manage', 'ecommerce.view']) && ! in_array('woo.view', $p, true)) {
                    $p[] = 'woo.view';
                    DB::table('roles')->where('id', $r->id)->update(['permissions' => json_encode(array_values(array_unique($p)))]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['woo_logs', 'woo_links', 'woo_stores'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
