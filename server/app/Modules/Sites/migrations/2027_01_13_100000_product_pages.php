<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ws_product_pages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('site_id');
            $t->uuid('product_id');
            $t->longText('description')->nullable();
            $t->json('photos')->nullable();
            $t->json('related')->nullable();
            $t->string('badge', 30)->nullable();
            $t->decimal('compare_at', 14, 2)->nullable();
            $t->timestamps();
            $t->unique(['site_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ws_product_pages');
    }
};
