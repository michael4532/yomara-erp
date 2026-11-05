<?php

namespace App\Modules\Dev;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DevController extends Controller
{
    public function __construct(private DevHooks $h) {}

    public function dashboard(Request $r)
    {
        return ['data' => $this->h->dashboard(app('tenant'), $r->user())];
    }

    /** Webhook GitHub / GitLab d'une application (adresse secrète + signature). */
    public function git(Request $r, string $token, GitWebhook $git)
    {
        $app = \Illuminate\Support\Facades\DB::table('dev_apps')->where('git_token', $token)->first();
        if (! $app) {
            return response()->json(['ok' => false], 404);
        }
        $headers = collect($r->headers->all())->map(fn ($v) => $v[0] ?? null)->all();
        if (! $git->verify($app, $r->getContent(), $headers)) {
            return response()->json(['ok' => false, 'message' => 'Signature invalide.'], 401);
        }
        if (($headers['x-github-event'] ?? null) === 'ping') {
            return ['ok' => true];
        }
        app()->instance('tenant', GitWebhook::company($app));

        return ['ok' => true, 'items' => $git->handle($app, $headers, $r->json()->all())];
    }

    public function regenerate(int $id)
    {
        \Illuminate\Support\Facades\DB::table('dev_apps')->where('company_id', app('tenant')->id)->where('id', $id)
            ->update(['git_token' => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(40)), 'git_secret' => \Illuminate\Support\Str::random(40)]);

        return ['ok' => true];
    }

    public function changelog(int $id)
    {
        try {
            return ['data' => ['notes' => $this->h->changelog(app('tenant'), $id)]];
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
