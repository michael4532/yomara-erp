<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gb_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->unique();
            $t->string('mode', 10)->default('none'); // none, api (Google Business Profile), places (lecture seule)
            $t->text('places_api_key')->nullable(); // chiffré
            $t->text('oauth')->nullable(); // chiffré : jetons Google
            $t->string('connected_email', 190)->nullable();
            $t->string('account_name', 120)->nullable(); // accounts/123
            $t->json('requests')->nullable(); // réglages des demandes d'avis
            $t->json('alerts')->nullable(); // seuil, destinataires
            $t->string('error', 500)->nullable();
            $t->timestamp('last_sync_at')->nullable();
            $t->timestamps();
        });

        Schema::create('gb_locations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('location_name', 120)->nullable(); // locations/456 (API)
            $t->string('place_id', 120)->nullable();
            $t->string('title', 190);
            $t->string('address', 300)->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('website', 300)->nullable();
            $t->text('description')->nullable();
            $t->json('hours')->nullable();
            $t->string('maps_uri', 300)->nullable();
            $t->string('review_uri', 300)->nullable();
            $t->decimal('rating', 3, 2)->nullable();
            $t->unsignedInteger('review_count')->default(0);
            $t->uuid('branch_id')->nullable();
            $t->string('token', 32)->unique(); // page publique « laissez un avis » et affiche
            $t->json('metrics')->nullable();
            $t->json('history')->nullable(); // note et nombre d'avis par jour
            $t->timestamp('last_sync_at')->nullable();
            $t->timestamps();
        });

        Schema::create('gb_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->foreignId('location_id')->index();
            $t->string('review_id', 255);
            $t->string('author', 190)->nullable();
            $t->string('author_photo', 500)->nullable();
            $t->unsignedTinyInteger('rating');
            $t->text('comment')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('reply')->nullable();
            $t->timestamp('replied_at')->nullable();
            $t->foreignId('replied_by')->nullable();
            $t->string('source', 10)->default('api');
            $t->uuid('partner_id')->nullable();
            $t->timestamp('alerted_at')->nullable();
            $t->timestamps();
            $t->unique(['location_id', 'review_id']);
        });

        Schema::create('gb_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->foreignId('location_id');
            $t->uuid('partner_id')->nullable()->index();
            $t->string('name', 190)->nullable();
            $t->string('channel', 10); // whatsapp, sms, email
            $t->string('to', 190);
            $t->string('token', 40)->unique();
            $t->string('context', 60)->nullable(); // invoice.paid:uuid …
            $t->string('status', 10)->default('scheduled'); // scheduled, sent, failed, clicked, feedback, cancelled
            $t->string('error', 300)->nullable();
            $t->timestamp('send_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('clicked_at')->nullable();
            $t->unsignedTinyInteger('feedback_rating')->nullable();
            $t->text('feedback')->nullable();
            $t->timestamps();
            $t->index(['status', 'send_at']);
        });

        Schema::create('gb_posts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->foreignId('location_id');
            $t->string('type', 10)->default('STANDARD'); // STANDARD, OFFER, EVENT
            $t->string('title', 120)->nullable();
            $t->text('summary');
            $t->string('cta_type', 20)->nullable(); // LEARN_MORE, ORDER, BOOK, CALL, SHOP, SIGN_UP
            $t->string('cta_url', 300)->nullable();
            $t->string('photo_url', 300)->nullable();
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->string('coupon', 60)->nullable();
            $t->string('google_name', 255)->nullable();
            $t->string('status', 10)->default('draft'); // draft, published, failed
            $t->string('error', 500)->nullable();
            $t->foreignId('user_id')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });

        if (Schema::hasTable('roles')) {
            foreach (DB::table('roles')->whereNotNull('permissions')->get(['id', 'permissions']) as $r) {
                $p = (array) json_decode((string) $r->permissions, true);
                if (array_intersect($p, ['crm.*', 'marketing.*', 'helpdesk.*']) && ! in_array('avis.reply', $p, true)) {
                    $p = array_merge($p, ['avis.view', 'avis.reply']);
                    DB::table('roles')->where('id', $r->id)->update(['permissions' => json_encode(array_values(array_unique($p)))]);
                }
            }
        }

        foreach ((Schema::hasTable('plans') ? DB::table('plans')->whereIn('code', ['professional', 'entreprise', 'sur_mesure'])->get() : []) as $p) {
            $m = (array) json_decode((string) $p->modules, true);
            if (! in_array('avis_google', $m, true)) {
                $m[] = 'avis_google';
                DB::table('plans')->where('id', $p->id)->update(['modules' => json_encode(array_values($m))]);
            }
        }
    }

    public function down(): void
    {
        foreach (['gb_posts', 'gb_requests', 'gb_reviews', 'gb_locations', 'gb_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
