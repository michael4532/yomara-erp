<?php

use App\Models\Company;
use Illuminate\Database\Migrations\Migration;

/** Les sociétés existantes restent sur le SYSCOHADA révisé (système normal), le référentiel de leurs écritures. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Company::query()->get() as $c) {
            if (! data_get($c->settings, 'compta.referential')) {
                $settings = $c->settings ?? [];
                data_set($settings, 'compta.referential', 'syscohada');
                $c->forceFill(['settings' => $settings])->saveQuietly();
            }
        }
    }

    public function down(): void {}
};
