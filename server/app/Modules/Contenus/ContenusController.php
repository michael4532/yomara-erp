<?php

namespace App\Modules\Contenus;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContenusController extends Controller
{
    public function dashboard(ContenusHooks $h)
    {
        return ['data' => $h->dashboard(app('tenant'))];
    }

    protected function run(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function accounts(SocialPublisher $s)
    {
        return ['data' => $s->accounts(app('tenant'))];
    }

    public function connect(Request $r, SocialPublisher $s, string $platform)
    {
        $d = $r->validate(['token' => 'required|string|max:2000', 'account_id' => 'required|string|max:100']);

        return $this->run(fn () => ['data' => $platform === 'meta'
            ? $s->connectMeta(app('tenant'), $r->user(), $d['token'], $d['account_id'])
            : $s->connectLinkedIn(app('tenant'), $r->user(), $d['token'], $d['account_id'])]);
    }

    public function disconnect(int $id)
    {
        DB::table('mc_social_accounts')->where('company_id', app('tenant')->id)->where('id', $id)->delete();

        return response()->noContent();
    }

    /** « Publier maintenant » : la publication doit être validée (ou validée par la personne qui publie, si elle en a le droit). */
    public function publish(Request $r, SocialPublisher $s, int $id)
    {
        $c = app('tenant');
        $p = DB::table('mc_posts')->where('company_id', $c->id)->where('id', $id)->first();
        abort_unless($p, 404);
        if (! in_array($p->status, ['approved', 'published'], true)) {
            if (! $r->user()->hasPermission('contenus.validate')) {
                return response()->json(['message' => 'La publication doit d\'abord être validée par un responsable.'], 422);
            }
            DB::table('mc_posts')->where('id', $id)->update(['status' => 'approved', 'validator_id' => $r->user()->id, 'validated_at' => now()]);
        }

        return $this->run(fn () => ['data' => $s->publish($c, $id, $r->user())]);
    }

    public function stats(Request $r, SocialPublisher $s)
    {
        return ['data' => ['updated' => $s->syncStats(app('tenant'), $r->integer('post_id') ?: null)]];
    }
}
