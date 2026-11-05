<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Notifications de commande envoyées (une par commande et par étape) et ticket du service client associé
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_notices')) {
            Schema::create('order_notices', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->index();
                $t->uuid('document_id');
                $t->string('event', 20);
                $t->string('channels', 60)->nullable();
                $t->uuid('ticket_id')->nullable();
                $t->timestamps();
                $t->unique(['document_id', 'event']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notices');
    }
};
