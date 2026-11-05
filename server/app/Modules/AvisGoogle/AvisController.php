<?php

namespace App\Modules\AvisGoogle;

use App\Http\Controllers\Controller;
use App\Messaging\Phone;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AvisController extends Controller
{
    public function __construct(protected GoogleProfile $gp, protected ReviewRequests $req) {}

    protected function fail(\Throwable $e)
    {
        return response()->json(['message' => $e->getMessage()], 422);
    }

    protected function loc(int $id): object
    {
        $l = DB::table('gb_locations')->where('company_id', app('tenant')->id)->where('id', $id)->first();
        abort_unless($l, 404);

        return $l;
    }

    public static function locRow(object $l): array
    {
        return ['id' => $l->id, 'title' => $l->title, 'address' => $l->address, 'phone' => $l->phone, 'website' => $l->website, 'description' => $l->description,
            'hours' => json_decode((string) $l->hours, true) ?: [], 'maps_uri' => $l->maps_uri, 'review_uri' => $l->review_uri, 'rating' => $l->rating !== null ? (float) $l->rating : null,
            'review_count' => (int) $l->review_count, 'place_id' => $l->place_id, 'connected' => (bool) $l->location_name, 'branch_id' => $l->branch_id,
            'qr_url' => url('/note/l/'.$l->token), 'poster_url' => url('/note/l/'.$l->token.'/affiche'), 'metrics' => json_decode((string) $l->metrics, true),
            'history' => json_decode((string) $l->history, true) ?: [], 'last_sync_at' => $l->last_sync_at];
    }

    public static function reviewRow(object $r, array $users = []): array
    {
        return ['id' => $r->id, 'location_id' => $r->location_id, 'author' => $r->author, 'author_photo' => $r->author_photo, 'rating' => (int) $r->rating, 'comment' => $r->comment,
            'reviewed_at' => $r->reviewed_at, 'reply' => $r->reply, 'replied_at' => $r->replied_at, 'replied_by' => $r->replied_by ? ($users[$r->replied_by] ?? null) : null,
            'source' => $r->source, 'partner_id' => $r->partner_id];
    }

    public function index(Request $r)
    {
        $c = app('tenant');
        $s = $this->gp->settings($c);
        $locs = DB::table('gb_locations')->where('company_id', $c->id)->orderBy('id')->get();
        $q = DB::table('gb_reviews')->where('company_id', $c->id);
        $total = (clone $q)->count();
        $answered = (clone $q)->whereNotNull('reply')->count();
        $dist = [];
        foreach (range(1, 5) as $i) {
            $dist[$i] = (clone $q)->where('rating', $i)->count();
        }
        $req = DB::table('gb_requests')->where('company_id', $c->id)->where('created_at', '>=', now()->subDays(30));
        $users = DB::table('users')->where('company_id', $c->id)->pluck('name', 'id')->all();
        $avgResp = DB::table('gb_reviews')->where('company_id', $c->id)->whereNotNull('replied_at')->whereNotNull('reviewed_at')->where('reviewed_at', '>=', now()->subDays(90))
            ->get(['reviewed_at', 'replied_at'])->avg(fn ($x) => max(0, strtotime($x->replied_at) - strtotime($x->reviewed_at)) / 3600);
        $googleApp = \App\Modules\Mailbox\OAuth::configured($c, 'google');

        return ['data' => [
            'mode' => $s->mode, 'connected_email' => $s->connected_email, 'error' => $s->error, 'last_sync_at' => $s->last_sync_at,
            'google_app' => $googleApp, 'redirect_uri' => GoogleProfile::redirectUri(), 'places_key' => (bool) $this->gp->placesKey($c),
            'can_manage' => $r->user()->hasPermission('avis.manage'), 'can_reply' => $r->user()->hasPermission('avis.reply') || $r->user()->hasPermission('avis.manage'),
            'ai' => $c->hasModule('ia'),
            'locations' => $locs->map(fn ($l) => self::locRow($l))->values(),
            'stats' => [
                'rating' => $locs->count() ? round((float) $locs->avg('rating'), 2) : null, 'count' => (int) $locs->sum('review_count'), 'stored' => $total,
                'unanswered' => (clone $q)->whereNull('reply')->count(), 'response_rate' => $total ? round(100 * $answered / $total) : null,
                'avg_response_hours' => $avgResp !== null ? round($avgResp, 1) : null, 'distribution' => $dist,
                'last_30' => (clone $q)->where('reviewed_at', '>=', now()->subDays(30))->count(),
                'requests_30' => (clone $req)->count(), 'clicks_30' => (clone $req)->whereNotNull('clicked_at')->count(),
            ],
            'requests_settings' => $this->gp->requestSettings($c), 'alerts' => $this->gp->alertSettings($c), 'triggers' => ReviewRequests::TRIGGERS,
            'recent' => DB::table('gb_reviews')->where('company_id', $c->id)->orderByDesc('reviewed_at')->limit(5)->get()->map(fn ($x) => self::reviewRow($x, $users))->values(),
        ]];
    }

    public function reviews(Request $r)
    {
        $c = app('tenant');
        $q = DB::table('gb_reviews')->where('company_id', $c->id);
        if ($r->filled('location_id')) {
            $q->where('location_id', (int) $r->query('location_id'));
        }
        if ($r->filled('rating')) {
            $q->whereIn('rating', array_map('intval', explode(',', (string) $r->query('rating'))));
        }
        if ($r->boolean('unanswered')) {
            $q->whereNull('reply');
        }
        if ($s = trim((string) $r->query('q'))) {
            $q->where(fn ($w) => $w->where('author', 'like', '%'.$s.'%')->orWhere('comment', 'like', '%'.$s.'%'));
        }
        $users = DB::table('users')->where('company_id', $c->id)->pluck('name', 'id')->all();
        $page = $q->orderByDesc('reviewed_at')->paginate(30);

        return ['data' => collect($page->items())->map(fn ($x) => self::reviewRow($x, $users))->values(), 'total' => $page->total(), 'last_page' => $page->lastPage()];
    }

    protected function review(int $id): object
    {
        $rv = DB::table('gb_reviews')->where('company_id', app('tenant')->id)->where('id', $id)->first();
        abort_unless($rv, 404);

        return $rv;
    }

    public function reply(Request $r, int $id)
    {
        $d = $r->validate(['text' => 'required|string|max:4000']);
        try {
            $this->gp->reply(app('tenant'), $this->review($id), trim($d['text']), $r->user()->id);
        } catch (\DomainException $e) {
            return $this->fail($e);
        }

        return ['data' => self::reviewRow(DB::table('gb_reviews')->find($id), DB::table('users')->where('company_id', app('tenant')->id)->pluck('name', 'id')->all())];
    }

    public function deleteReply(int $id)
    {
        try {
            $this->gp->deleteReply(app('tenant'), $this->review($id));
        } catch (\DomainException $e) {
            return $this->fail($e);
        }

        return response()->noContent();
    }

    public function suggest(Request $r, int $id)
    {
        $rv = $this->review($id);
        $c = app('tenant');
        abort_unless($c->hasModule('ia'), 422, 'L\'assistant IA n\'est pas activé.');
        try {
            return app(\App\Modules\Ia\AiService::class)->reviewReply($c, $r->user(), ['author' => $rv->author, 'rating' => $rv->rating, 'comment' => $rv->comment], (string) $r->input('tone', 'chaleureux'));
        } catch (\DomainException $e) {
            return $this->fail($e);
        }
    }

    // ------------------------------------------------------------------ connexion et réglages (gestionnaire)

    public function connect(Request $r)
    {
        try {
            return ['url' => $this->gp->connectUrl(app('tenant'), $r->user()->id)];
        } catch (\DomainException $e) {
            return $this->fail($e);
        }
    }

    public function disconnect()
    {
        $c = app('tenant');
        DB::table('gb_settings')->where('company_id', $c->id)->update(['mode' => DB::table('gb_locations')->where('company_id', $c->id)->whereNotNull('place_id')->exists() && $this->gp->placesKey($c) ? 'places' : 'none',
            'oauth' => null, 'connected_email' => null, 'account_name' => null, 'updated_at' => now()]);
        DB::table('gb_locations')->where('company_id', $c->id)->update(['location_name' => null]);

        return response()->noContent();
    }

    public function searchPlaces(Request $r)
    {
        $d = $r->validate(['query' => 'required|string|max:200']);
        try {
            return ['data' => $this->gp->searchPlaces(app('tenant'), $d['query'])];
        } catch (\DomainException $e) {
            return $this->fail($e);
        }
    }

    public function addPlace(Request $r)
    {
        $d = $r->validate(['place_id' => 'required|string|max:120', 'title' => 'nullable|string|max:190']);
        try {
            $id = $this->gp->addPlace(app('tenant'), $d['place_id'], $d['title'] ?? null);
        } catch (\DomainException $e) {
            return $this->fail($e);
        }

        return response()->json(['data' => self::locRow($this->loc($id))], 201);
    }

    public function removeLocation(int $id)
    {
        $l = $this->loc($id);
        DB::table('gb_reviews')->where('location_id', $l->id)->delete();
        DB::table('gb_locations')->where('id', $l->id)->delete();

        return response()->noContent();
    }

    public function saveSettings(Request $r)
    {
        $d = $r->validate(['places_api_key' => 'nullable|string|max:120', 'requests' => 'nullable|array', 'requests.enabled' => 'nullable|boolean', 'requests.triggers' => 'nullable|array',
            'requests.channels' => 'nullable|array', 'requests.delay_hours' => 'nullable|integer|min:0|max:720', 'requests.cooldown_days' => 'nullable|integer|min:1|max:730',
            'requests.message' => 'nullable|string|max:600', 'alerts' => 'nullable|array', 'alerts.max_rating' => 'nullable|integer|min:1|max:5', 'alerts.whatsapp' => 'nullable|string|max:30',
            'alerts.email' => 'nullable|email']);
        $c = app('tenant');
        $s = $this->gp->settings($c);
        $u = ['updated_at' => now()];
        if (array_key_exists('places_api_key', $d) && ! str_starts_with((string) $d['places_api_key'], '••')) {
            $u['places_api_key'] = $d['places_api_key'] ? Crypt::encryptString(trim($d['places_api_key'])) : null;
        }
        if (isset($d['requests'])) {
            $req = array_merge($this->gp->requestSettings($c), array_intersect_key($d['requests'], GoogleProfile::REQUEST_DEFAULTS));
            if (isset($d['requests']['message']) && ! str_contains($d['requests']['message'], '{lien}')) {
                return $this->fail(new \DomainException('Le message doit contenir {lien} (le lien vers la page d\'avis).'));
            }
            $req['triggers'] = array_values(array_intersect((array) $req['triggers'], array_keys(ReviewRequests::TRIGGERS)));
            $req['channels'] = array_values(array_intersect((array) $req['channels'], ['whatsapp', 'sms', 'email']));
            $u['requests'] = json_encode($req);
        }
        if (isset($d['alerts'])) {
            $u['alerts'] = json_encode(array_merge($this->gp->alertSettings($c), array_intersect_key($d['alerts'], GoogleProfile::ALERT_DEFAULTS)));
        }
        DB::table('gb_settings')->where('id', $s->id)->update($u);

        return $this->index($r);
    }

    public function sync()
    {
        $c = app('tenant');
        try {
            if ($this->gp->settings($c)->mode === 'api') {
                $this->gp->syncLocations($c);
            }
            $res = $this->gp->syncAll($c);
        } catch (\DomainException $e) {
            DB::table('gb_settings')->where('company_id', $c->id)->update(['error' => mb_substr($e->getMessage(), 0, 500)]);

            return $this->fail($e);
        }

        return $res;
    }

    public function updateLocation(Request $r, int $id)
    {
        $l = $this->loc($id);
        $d = $r->validate(['description' => 'nullable|string|max:750', 'phone' => 'nullable|string|max:40', 'website' => 'nullable|url|max:300', 'hours' => 'nullable|array', 'branch_id' => 'nullable|uuid']);
        try {
            $this->gp->updateInfo(app('tenant'), $l, array_intersect_key($d, array_flip(['description', 'phone', 'website', 'hours'])));
        } catch (\DomainException $e) {
            return $this->fail($e);
        }
        if (array_key_exists('branch_id', $d)) {
            DB::table('gb_locations')->where('id', $l->id)->update(['branch_id' => $d['branch_id']]);
        }

        return ['data' => self::locRow($this->loc($id))];
    }

    public function metrics(int $id)
    {
        try {
            return ['data' => $this->gp->metrics(app('tenant'), $this->loc($id))];
        } catch (\DomainException $e) {
            return $this->fail($e);
        }
    }

    // ------------------------------------------------------------------ publications

    public function posts()
    {
        return ['data' => DB::table('gb_posts')->where('company_id', app('tenant')->id)->orderByDesc('id')->limit(50)->get()];
    }

    public function storePost(Request $r)
    {
        $d = $r->validate(['location_id' => 'required|integer', 'type' => 'required|in:STANDARD,OFFER,EVENT', 'title' => 'nullable|string|max:58', 'summary' => 'required|string|max:1500',
            'cta_type' => 'nullable|in:LEARN_MORE,ORDER,BOOK,CALL,SHOP,SIGN_UP', 'cta_url' => 'nullable|url|max:300', 'photo_url' => 'nullable|url|max:300', 'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date', 'coupon' => 'nullable|string|max:60']);
        $this->loc($d['location_id']);
        $c = app('tenant');
        $id = DB::table('gb_posts')->insertGetId($d + ['company_id' => $c->id, 'status' => 'draft', 'user_id' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        try {
            $this->gp->publish($c, DB::table('gb_posts')->find($id));
        } catch (\DomainException $e) {
            DB::table('gb_posts')->where('id', $id)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);

            return response()->json(['message' => $e->getMessage(), 'data' => DB::table('gb_posts')->find($id)], 422);
        }

        return response()->json(['data' => DB::table('gb_posts')->find($id)], 201);
    }

    public function media(Request $r)
    {
        $r->validate(['file' => 'required|file|max:5120']);
        try {
            return response()->json(['url' => \App\Modules\Mailbox\Media::store($r->file('file'))], 201);
        } catch (\DomainException $e) {
            return $this->fail($e);
        }
    }

    public function destroyPost(int $id)
    {
        DB::table('gb_posts')->where('company_id', app('tenant')->id)->where('id', $id)->delete();

        return response()->noContent();
    }

    // ------------------------------------------------------------------ demandes d'avis

    public function requests(Request $r)
    {
        $c = app('tenant');

        return ['data' => DB::table('gb_requests')->where('company_id', $c->id)->orderByDesc('id')->limit(200)->get(['id', 'partner_id', 'name', 'channel', 'to', 'status', 'error', 'send_at', 'sent_at', 'clicked_at', 'feedback_rating', 'feedback', 'context', 'created_at'])];
    }

    public function storeRequest(Request $r)
    {
        $d = $r->validate(['partner_id' => 'nullable|uuid', 'name' => 'nullable|string|max:190', 'phone' => 'nullable|string|max:30', 'email' => 'nullable|email', 'channel' => 'nullable|in:whatsapp,sms,email',
            'location_id' => 'nullable|integer']);
        $c = app('tenant');
        if (! empty($d['partner_id']) && ($p = DB::table('partners')->where('company_id', $c->id)->where('id', $d['partner_id'])->first(['name', 'phone', 'email']))) {
            $d += ['name' => $p->name, 'phone' => $p->phone, 'email' => $p->email];
            $d['phone'] ??= $p->phone;
            $d['email'] ??= $p->email;
        }
        abort_if(empty($d['phone']) && empty($d['email']), 422, 'Indiquez un numéro ou une adresse e-mail.');
        try {
            $row = $this->req->create($c, $d + ['context' => 'manuel', 'send_at' => now()], true);
        } catch (\DomainException $e) {
            return $this->fail($e);
        }

        return response()->json(['data' => $row], $row->status === 'failed' ? 422 : 201);
    }

    public function cancelRequest(int $id)
    {
        DB::table('gb_requests')->where('company_id', app('tenant')->id)->where('id', $id)->where('status', 'scheduled')->update(['status' => 'cancelled', 'updated_at' => now()]);

        return response()->noContent();
    }

    // ------------------------------------------------------------------ pages publiques

    public function oauthCallback(Request $r)
    {
        $ok = false;
        try {
            if ($r->filled('error')) {
                throw new \DomainException($r->query('error') === 'access_denied' ? 'Connexion annulée.' : 'Google a refusé : '.$r->query('error'));
            }
            $c = $this->gp->complete((string) $r->query('state'), (string) $r->query('code'));
            $s = DB::table('gb_settings')->where('company_id', $c->id)->first();
            \Illuminate\Support\Facades\Log::info('Avis Google connecté', ['company' => $c->id, 'mode' => $s?->mode, 'email' => $s?->connected_email, 'error' => $s?->error]);
            $ok = $s?->mode === 'api';
            $msg = $ok
                ? 'Fiche Google connectée pour « '.$c->name.' »'.($s->connected_email ? ' avec le compte '.$s->connected_email : '').'.'
                    .($s->error ? ' Attention : '.$s->error : ' Les avis arrivent dans Yomara.').' Vous pouvez fermer cette page et revenir dans l\'application.'
                : 'La connexion n\'a pas pu être enregistrée pour « '.$c->name.' ». Recommencez depuis Avis Google.';
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
        }

        return $this->page($ok ? 'Fiche Google connectée' : 'Connexion impossible', '<p style="color:#5B6B85;line-height:1.5">'.e($msg).'</p>', $ok ? '#1A73E8' : '#D93025');
    }

    protected function page(string $title, string $body, string $color = '#1A73E8', string $head = '')
    {
        return response('<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).'</title>'.$head.'</head>'
            .'<body style="font-family:system-ui,Segoe UI,Roboto,sans-serif;background:#F4F6FB;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center">'
            .'<div style="background:#fff;border-radius:22px;padding:30px 26px;max-width:440px;width:88%;text-align:center;overflow-wrap:anywhere;box-shadow:0 14px 50px rgba(0,0,0,.08)">'
            .'<h2 style="margin:0 0 10px;color:#202124">'.e($title).'</h2>'.$body.'</div></body></html>')->header('Content-Type', 'text/html; charset=utf-8');
    }

    /** Page d'avis reçue par le client : bouton Google pour tous, et possibilité d'écrire à l'équipe (sans filtrage). */
    public function landing(string $token)
    {
        $req = DB::table('gb_requests')->where('token', $token)->first();
        abort_unless($req, 404);
        $loc = DB::table('gb_locations')->find($req->location_id);
        $c = Company::find($req->company_id);
        if (! $req->clicked_at) {
            DB::table('gb_requests')->where('id', $req->id)->update(['clicked_at' => now(), 'status' => in_array($req->status, ['sent', 'scheduled'], true) ? 'clicked' : $req->status]);
        }
        $first = $req->name ? e(explode(' ', trim($req->name))[0]) : '';
        $stars = '<div style="font-size:34px;color:#FBBC04;letter-spacing:4px;margin:6px 0 14px">★★★★★</div>';
        $body = '<div style="width:60px;height:60px;border-radius:16px;background:#1A73E8;color:#fff;font-weight:800;font-size:28px;line-height:60px;margin:0 auto 12px">'.e(mb_substr($c->name, 0, 1)).'</div>'
            .'<p style="color:#5B6B85;margin:0">'.($first ? 'Bonjour '.$first.', merci' : 'Merci').' pour votre confiance !</p>'.$stars
            .'<p style="color:#202124;margin:0 0 18px">Votre avis aide d\'autres clients à nous découvrir. Il ne prend qu\'une minute.</p>'
            .'<a href="'.e($loc->review_uri).'" style="display:block;background:#1A73E8;color:#fff;padding:15px;border-radius:12px;text-decoration:none;font-weight:700;font-size:16px">Donner mon avis sur Google</a>'
            .'<details style="margin-top:20px;text-align:left"><summary style="cursor:pointer;color:#5B6B85;text-align:center">Un souci ? Écrivez directement à l\'équipe</summary>'
            .'<form method="post" action="'.e(url('/note/'.$token)).'" style="margin-top:12px"><input type="hidden" name="_token" value="'.csrf_token().'">'
            .'<label style="font-size:14px;color:#5B6B85">Votre note</label><select name="rating" style="width:100%;padding:10px;border-radius:10px;border:1px solid #DADCE0;margin:6px 0 10px">'
            .'<option value="5">★★★★★ Excellent</option><option value="4">★★★★ Bien</option><option value="3">★★★ Moyen</option><option value="2">★★ Décevant</option><option value="1">★ Mauvais</option></select>'
            .'<textarea name="feedback" rows="4" required placeholder="Dites-nous ce qui s\'est passé…" style="width:100%;box-sizing:border-box;padding:10px;border-radius:10px;border:1px solid #DADCE0"></textarea>'
            .'<button style="margin-top:10px;width:100%;padding:12px;border:0;border-radius:10px;background:#202124;color:#fff;font-weight:700">Envoyer à l\'équipe</button></form></details>';

        return $this->page($c->name, $body);
    }

    public function feedback(Request $r, string $token)
    {
        $req = DB::table('gb_requests')->where('token', $token)->first();
        abort_unless($req, 404);
        $d = $r->validate(['rating' => 'required|integer|min:1|max:5', 'feedback' => 'required|string|max:3000']);
        DB::table('gb_requests')->where('id', $req->id)->update(['feedback_rating' => $d['rating'], 'feedback' => $d['feedback'], 'status' => 'feedback', 'updated_at' => now()]);
        $c = Company::find($req->company_id);
        $users = User::where('company_id', $c->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('avis.manage') || $u->hasPermission('avis.reply'))->pluck('id')->all();
        try {
            AppNotification::send($c->id, $users, 'avis', 'Retour client ('.$d['rating'].'/5) · '.($req->name ?: $req->to), mb_substr($d['feedback'], 0, 200), 'avis_google', 'request:'.$req->id);
        } catch (\Throwable) {
        }
        $loc = DB::table('gb_locations')->find($req->location_id);

        return $this->page('Merci, c\'est transmis', '<p style="color:#5B6B85;line-height:1.5">L\'équipe de '.e($c->name).' a bien reçu votre message et revient vers vous rapidement.</p>'
            .'<p style="margin-top:18px"><a href="'.e($loc->review_uri).'" style="color:#1A73E8">Vous pouvez aussi laisser un avis public sur Google</a></p>');
    }

    /** QR code du comptoir : redirige vers la page d'avis Google (scans comptés). */
    public function qr(string $token)
    {
        $loc = DB::table('gb_locations')->where('token', $token)->first();
        abort_unless($loc && $loc->review_uri, 404);
        $m = json_decode((string) $loc->metrics, true) ?: [];
        $m['qr_scans'] = ($m['qr_scans'] ?? 0) + 1;
        DB::table('gb_locations')->where('id', $loc->id)->update(['metrics' => json_encode($m)]);

        return redirect()->away($loc->review_uri);
    }

    /** Affiche à imprimer « Notez-nous sur Google » avec QR code. */
    public function poster(string $token)
    {
        $loc = DB::table('gb_locations')->where('token', $token)->first();
        abort_unless($loc, 404);
        $c = Company::find($loc->company_id);
        $qr = (new \TCPDF2DBarcode(url('/note/l/'.$loc->token), 'QRCODE,M'))->getBarcodeSVGcode(8, 8, '#202124');
        $qr = preg_replace('/<\?xml[^>]*>|<!DOCTYPE[^>]*>/', '', $qr);
        if (! str_contains($qr, 'viewBox') && preg_match('/<svg[^>]*\swidth="([\d.]+)"[^>]*\sheight="([\d.]+)"/', $qr, $m)) {
            $qr = preg_replace('/<svg\s/', '<svg shape-rendering="crispEdges" viewBox="0 0 '.$m[1].' '.$m[2].'" ', $qr, 1);
        }

        return response('<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Notez-nous sur Google</title><style>@page{size:A4;margin:0}body{margin:0;font-family:Segoe UI,Roboto,Arial,sans-serif}'
            .'.p{width:210mm;height:297mm;box-sizing:border-box;padding:24mm 18mm;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;background:linear-gradient(160deg,#fff 60%,#E8F0FE)}'
            .'.g{font-size:64px;font-weight:800;letter-spacing:-1px}.b{color:#4285F4}.r{color:#EA4335}.y{color:#FBBC05}.gr{color:#34A853}'
            .'.q{margin:16mm 0 10mm;padding:10mm;border-radius:10mm;background:#fff;box-shadow:0 6mm 18mm rgba(0,0,0,.08)}.q svg{width:95mm;height:95mm}'
            .'@media print{.np{display:none}}</style></head><body><div class="p">'
            .'<div style="font-size:30px;color:#5B6B85">Vous avez aimé ?</div><div style="font-size:46px;font-weight:800;color:#202124;margin:4mm 0">Notez-nous sur</div>'
            .'<div class="g"><span class="b">G</span><span class="r">o</span><span class="y">o</span><span class="b">g</span><span class="gr">l</span><span class="r">e</span></div>'
            .'<div style="font-size:42px;color:#FBBC04;letter-spacing:6px;margin-top:4mm">★★★★★</div>'
            .'<div class="q">'.$qr.'</div><div style="font-size:24px;color:#202124">Scannez avec l\'appareil photo de votre téléphone</div>'
            .'<div style="font-size:30px;font-weight:800;color:#202124;margin-top:10mm">'.e($loc->title ?: $c->name).'</div>'
            .($loc->rating ? '<div style="font-size:20px;color:#5B6B85;margin-top:2mm">'.number_format((float) $loc->rating, 1, ',', ' ').' / 5 · '.$loc->review_count.' avis</div>' : '')
            .'<button class="np" onclick="window.print()" style="margin-top:12mm;padding:12px 24px;border:0;border-radius:24px;background:#1A73E8;color:#fff;font-size:16px;font-weight:700;cursor:pointer">Imprimer l\'affiche</button>'
            .'</div></body></html>')->header('Content-Type', 'text/html; charset=utf-8');
    }
}
