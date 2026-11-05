<?php

namespace App\Http\Controllers;

use App\Integrations\Feeds;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Flux de données publics (lien secret) : CSV pour tableurs, JSON pour outils décisionnels. */
class FeedController extends Controller
{
    public function show(string $token, string $format)
    {
        $f = DB::table('data_feeds')->where('token', $token)->first();
        abort_unless($f, 404);
        $c = Company::find($f->company_id);
        $u = User::find($f->user_id);
        abort_unless($c && ! $c->isSuspended() && $u && $u->is_active && $u->company_id === $c->id, 404);
        app()->instance('tenant', $c);
        abort_unless(Feeds::allowed($u, $f->dataset), 403, 'Le propriétaire de ce flux n\'a plus accès à ces données.');
        [$head, $rows] = Feeds::build($c, $u, $f->dataset, (int) $f->days);
        DB::table('data_feeds')->where('id', $f->id)->update(['hits' => $f->hits + 1, 'last_used_at' => now()]);
        if ($format === 'json') {
            $keys = array_map(fn ($h) => \Illuminate\Support\Str::slug($h, '_'), $head);

            return response()->json(['dataset' => $f->dataset, 'generated_at' => now()->toIso8601String(), 'columns' => $head,
                'rows' => collect($rows)->map(fn ($r) => array_combine($keys, array_map(fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2} 00:00:00$/', $v) ? substr($v, 0, 10) : $v, $r)))->values()], 200, ['Cache-Control' => 'private, max-age=300'], JSON_UNESCAPED_UNICODE);
        }
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $head);
        foreach ($rows as $r) {
            fputcsv($out, array_map(fn ($v) => is_string($v) && ! preg_match('/^\d{4}-\d{2}-\d{2} 00:00:00$/', $v) ? \App\Support\SafeCell::csv($v) : (is_float($v) ? rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') : (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2} 00:00:00$/', $v) ? substr($v, 0, 10) : $v)), $r));
        }
        rewind($out);

        return response(stream_get_contents($out), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'private, max-age=300',
            'Content-Disposition' => 'inline; filename="'.$f->dataset.'.csv"']);
    }
}
