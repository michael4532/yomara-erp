<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Signature choisie par boîte et par personne (comme Outlook) : nouveaux messages / réponses et transferts. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mb_signature_defaults', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('account_id');
            $t->unsignedBigInteger('new_id')->nullable();   // null = sans signature
            $t->unsignedBigInteger('reply_id')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mb_signature_defaults');
    }
};
