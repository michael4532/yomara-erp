<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\ModuleRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Mise à jour sans SSH : après avoir remplacé les fichiers (FTP / gestionnaire
 * de fichiers cPanel), le super admin clique sur « Appliquer la mise à jour ».
 */
class SystemController extends Controller
{
    public function info()
    {
        Artisan::call('migrate:status', ['--pending' => true]);
        $pending = collect(explode("\n", Artisan::output()))->filter(fn ($l) => str_contains($l, 'Pending'))->count();

        return [
            'version' => config('erp.version'),
            'php' => PHP_VERSION,
            'database' => DB::connection()->getDriverName(),
            'mode' => config('erp.mode'),
            'pending_migrations' => $pending,
            'server_time' => now()->toIso8601String(),
        ];
    }

    /** Contrôle de production affiché au super admin. */
    public function checks(\App\Services\ProductionCheck $check)
    {
        return ['data' => $check->run()];
    }

    /** Configuration du serveur (réglages du .env modifiables depuis l'application). */
    public function env()
    {
        return ['data' => \App\Support\EnvSettings::view()];
    }

    public function saveEnv(\Illuminate\Http\Request $r)
    {
        $d = $r->validate(['values' => 'required|array|max:200']);
        $errors = \App\Support\EnvSettings::save($d['values']);
        ActivityLog::record('admin.system.env', null, ['keys' => array_keys($d['values'])]);
        if ($errors) {
            return response()->json(['message' => reset($errors), 'errors' => $errors, 'data' => \App\Support\EnvSettings::view()], 422);
        }

        return ['data' => \App\Support\EnvSettings::view()];
    }

    /** E-mail de test avec la configuration d'envoi enregistrée. */
    public function testMail(\Illuminate\Http\Request $r)
    {
        $d = $r->validate(['to' => 'required|email']);
        try {
            \Illuminate\Support\Facades\Mail::raw('Ceci est un e-mail de test envoyé par '.config('app.name').' ('.config('app.url').'). La configuration d\'envoi fonctionne.', function ($m) use ($d) {
                $m->to($d['to'])->subject('Test d\'envoi — '.config('app.name'));
            });
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Envoi impossible : '.\Illuminate\Support\Str::limit($e->getMessage(), 240)], 422);
        }

        return ['ok' => true, 'message' => config('mail.default') === 'log' ? 'Méthode « journal » : l\'e-mail a été écrit dans le journal, pas envoyé.' : 'E-mail envoyé à '.$d['to'].' : vérifiez la boîte de réception (et les indésirables).'];
    }

    public function update(ModuleRegistry $registry)
    {
        @set_time_limit(300);
        Artisan::call('migrate', ['--force' => true]);
        $out = Artisan::output();
        $registry->syncToDatabase();
        Artisan::call('optimize:clear');
        ActivityLog::record('admin.system.update', null, ['version' => config('erp.version')]);

        return ['ok' => true, 'output' => $out, 'version' => config('erp.version')];
    }
}
