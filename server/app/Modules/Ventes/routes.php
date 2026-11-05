<?php

use App\Http\Controllers\Api\DocumentActionController;
use App\Http\Controllers\Api\EntityController;
use App\Modules\Ventes\Models\SalesDocument;
use App\Modules\Ventes\Models\SalesPayment;

EntityController::routes('documents', SalesDocument::class);
DocumentActionController::routes('documents', SalesDocument::class);
EntityController::routes('payments', SalesPayment::class);

// Notifications de commande automatiques (client et équipe)
Illuminate\Support\Facades\Route::middleware('perm:ventes.manage')->group(function () {
    Illuminate\Support\Facades\Route::get('order-notifications', function () {
        $n = app(\App\Modules\Ventes\OrderNotifier::class);
        $c = app('tenant');

        return ['data' => $n->settings($c), 'events' => \App\Modules\Ventes\OrderNotifier::EVENTS, 'defaults' => \App\Modules\Ventes\OrderNotifier::DEFAULT_TEXTS,
            'helpdesk' => $c->hasModule('helpdesk'), 'channels' => app(\App\Messaging\MessagingService::class)->available($c),
            'recent' => Illuminate\Support\Facades\DB::table('order_notices')->where('company_id', $c->id)->latest('id')->limit(20)->get()];
    });
    Illuminate\Support\Facades\Route::put('order-notifications', fn (Illuminate\Http\Request $r) => ['data' => app(\App\Modules\Ventes\OrderNotifier::class)->saveSettings(app('tenant'), $r->validate([
        'enabled' => 'sometimes|boolean', 'scope' => 'sometimes|in:online,all', 'events' => 'sometimes|array', 'events.*' => 'boolean',
        'email' => 'sometimes|boolean', 'whatsapp' => 'sometimes|boolean', 'sms' => 'sometimes|boolean', 'ticket' => 'sometimes|boolean', 'team' => 'sometimes|boolean',
        'team_emails' => 'sometimes|array|max:10', 'team_emails.*' => 'email', 'texts' => 'sometimes|array', 'texts.*' => 'nullable|string|max:1500', 'signature' => 'nullable|string|max:500',
    ]))]);
    // Aperçu de l'e-mail sur la dernière commande (ou une commande donnée)
    Illuminate\Support\Facades\Route::get('order-notifications/preview', function (Illuminate\Http\Request $r) {
        $n = app(\App\Modules\Ventes\OrderNotifier::class);
        $c = app('tenant');
        $kind = in_array($r->query('event'), array_keys(\App\Modules\Ventes\OrderNotifier::EVENTS), true) ? $r->query('event') : 'received';
        $doc = \App\Modules\Ventes\Models\SalesDocument::where('type', 'order')->when($r->query('id'), fn ($q, $id) => $q->where('id', $id))->latest('date')->first();
        abort_unless($doc, 404, 'Aucune commande pour l\'aperçu.');
        $set = $n->settings($c);
        $name = (string) (\App\Modules\Core\Models\Partner::find($doc->partner_id)?->name ?: $doc->partner_name);
        $vars = $n->vars($c, $doc, $name);

        return response($n->html($c, $doc, $kind, strtr($set['texts'][$kind], $vars), $vars, null, $set))->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Content-Security-Policy', "default-src 'none'; img-src * data:; style-src 'unsafe-inline'");
    });
    // Renvoyer une notification pour une commande (ex. après avoir corrigé l'adresse du client)
    Illuminate\Support\Facades\Route::post('order-notifications/send/{id}', function (Illuminate\Http\Request $r, string $id) {
        $doc = \App\Modules\Ventes\Models\SalesDocument::where('type', 'order')->findOrFail($id);
        $kind = in_array($r->input('event'), array_keys(\App\Modules\Ventes\OrderNotifier::EVENTS), true) ? $r->input('event') : 'received';

        return app(\App\Modules\Ventes\OrderNotifier::class)->notify(app('tenant'), $doc, $kind, null, true);
    })->whereUuid('id')->middleware('throttle:20,1');
});
