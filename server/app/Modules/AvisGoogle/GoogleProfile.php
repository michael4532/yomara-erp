<?php

namespace App\Modules\AvisGoogle;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Mailbox\OAuth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Fiche Google de l'entreprise : API Google Business Profile (avis, réponses, publications, informations, statistiques)
 * ou, en attendant l'accès à cette API, lecture de la note et des derniers avis par l'API Places.
 */
class GoogleProfile
{
    public const SCOPE = 'https://www.googleapis.com/auth/business.manage openid email';

    public const STARS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    public const REQUEST_DEFAULTS = ['enabled' => false, 'triggers' => ['invoice.paid', 'receipt.created'], 'channels' => ['whatsapp', 'sms', 'email'], 'delay_hours' => 2, 'cooldown_days' => 90,
        'message' => "Bonjour {nom}, merci pour votre confiance ! Votre avis compte beaucoup pour {societe} et ne prend qu'une minute : {lien}"];

    public const ALERT_DEFAULTS = ['max_rating' => 2, 'whatsapp' => null, 'email' => null];

    public function settings(Company $c): object
    {
        $row = DB::table('gb_settings')->where('company_id', $c->id)->first();
        if (! $row) {
            DB::table('gb_settings')->insert(['company_id' => $c->id, 'requests' => json_encode(self::REQUEST_DEFAULTS), 'alerts' => json_encode(self::ALERT_DEFAULTS), 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('gb_settings')->where('company_id', $c->id)->first();
        }

        return $row;
    }

    public function requestSettings(Company $c): array
    {
        return array_merge(self::REQUEST_DEFAULTS, json_decode((string) $this->settings($c)->requests, true) ?: []);
    }

    public function alertSettings(Company $c): array
    {
        return array_merge(self::ALERT_DEFAULTS, json_decode((string) $this->settings($c)->alerts, true) ?: []);
    }

    protected function dec(?string $v): ?string
    {
        return $v ? rescue(fn () => Crypt::decryptString($v), null, false) : null;
    }

    public function placesKey(Company $c): ?string
    {
        return $this->dec($this->settings($c)->places_api_key) ?: config('erp.google.places_api_key');
    }

    public static function newToken(int $len = 32): string
    {
        return Str::lower(Str::random($len));
    }

    // ------------------------------------------------------------------ connexion Google (OAuth)

    public static function redirectUri(): string
    {
        return url('/oauth/google-business/retour');
    }

    public function connectUrl(Company $c, int $userId): string
    {
        $app = OAuth::app($c, 'google');
        if (empty($app['client_id'])) {
            throw new \DomainException('L\'application Google n\'est pas paramétrée : renseignez son Client ID et son secret dans Configuration du serveur › Boîtes mail Microsoft et Google (éditeur de la plateforme) ou dans YoMail › Paramètres (société), et déclarez chez Google l\'adresse de retour '.self::redirectUri().'.');
        }
        $state = Str::random(48);
        DB::table('mb_oauth_states')->insert(['state' => $state, 'company_id' => $c->id, 'user_id' => $userId, 'provider' => 'gbp', 'meta' => '{}', 'created_at' => now()]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id' => $app['client_id'], 'response_type' => 'code', 'redirect_uri' => self::redirectUri(),
            'scope' => self::SCOPE, 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state]);
    }

    public function complete(string $state, string $code): Company
    {
        $st = DB::table('mb_oauth_states')->where('state', $state)->where('provider', 'gbp')->first();
        if (! $st || now()->diffInMinutes($st->created_at, true) > 60) {
            throw new \DomainException('Le lien de connexion a expiré : recommencez depuis Avis Google.');
        }
        DB::table('mb_oauth_states')->where('state', $state)->delete();
        $c = Company::findOrFail($st->company_id);
        app()->instance('tenant', $c);
        $app = OAuth::app($c, 'google');
        $r = Http::asForm()->acceptJson()->timeout(20)->post('https://oauth2.googleapis.com/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirectUri(),
            'client_id' => $app['client_id'], 'client_secret' => $app['client_secret']]);
        if (! $r->successful()) {
            throw new \DomainException('Google a refusé la connexion : '.($r->json('error_description') ?: $r->json('error') ?: $r->status()));
        }
        $tok = $r->json();
        $email = Http::withToken($tok['access_token'])->acceptJson()->get('https://openidconnect.googleapis.com/v1/userinfo')->json('email');
        $this->settings($c);
        DB::table('gb_settings')->where('company_id', $c->id)->update(['mode' => 'api', 'connected_email' => $email, 'error' => null, 'updated_at' => now(),
            'oauth' => Crypt::encryptString(json_encode(['access_token' => $tok['access_token'], 'refresh_token' => $tok['refresh_token'] ?? null, 'expires_at' => time() + (int) ($tok['expires_in'] ?? 3600) - 60]))]);
        try {
            $this->syncLocations($c);
            $this->syncAll($c);
        } catch (\Throwable $e) {
            DB::table('gb_settings')->where('company_id', $c->id)->update(['error' => mb_substr($e->getMessage(), 0, 500)]);
        }

        return $c;
    }

    public function token(Company $c): string
    {
        $s = json_decode((string) $this->dec($this->settings($c)->oauth), true) ?: [];
        if (! empty($s['access_token']) && ($s['expires_at'] ?? 0) > time() + 30) {
            return $s['access_token'];
        }
        if (empty($s['refresh_token'])) {
            throw new \DomainException('Connexion Google expirée : reconnectez la fiche.');
        }
        $app = OAuth::app($c, 'google');
        $r = Http::asForm()->acceptJson()->timeout(20)->post('https://oauth2.googleapis.com/token', ['grant_type' => 'refresh_token', 'refresh_token' => $s['refresh_token'],
            'client_id' => $app['client_id'], 'client_secret' => $app['client_secret']]);
        if (! $r->successful()) {
            throw new \DomainException('Connexion Google expirée ou révoquée : reconnectez la fiche. ('.($r->json('error') ?: $r->status()).')');
        }
        $s['access_token'] = $r->json('access_token');
        $s['expires_at'] = time() + (int) $r->json('expires_in', 3600) - 60;
        DB::table('gb_settings')->where('company_id', $c->id)->update(['oauth' => Crypt::encryptString(json_encode($s))]);

        return $s['access_token'];
    }

    protected function api(Company $c, string $method, string $url, array $body = [], array $query = [])
    {
        $r = Http::withToken($this->token($c))->acceptJson()->timeout(30)->send($method, $url.($query ? '?'.http_build_query($query) : ''), $body ? ['json' => $body] : []);
        if ($r->failed()) {
            $msg = $r->json('error.message') ?: 'erreur '.$r->status();
            if ($r->status() === 403 && str_contains(strtolower($msg), 'not been used') || str_contains(strtolower($msg), 'disabled')) {
                $msg .= ' — activez les API « My Business » dans Google Cloud (accès à demander à Google pour l\'API Business Profile).';
            }
            throw new \DomainException('Google : '.$msg);
        }

        return $r;
    }

    // ------------------------------------------------------------------ établissements

    public function syncLocations(Company $c): int
    {
        $accounts = $this->api($c, 'GET', 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts')->json('accounts') ?? [];
        if (! $accounts) {
            throw new \DomainException('Aucune fiche d\'établissement sur ce compte Google.');
        }
        $n = 0;
        foreach ($accounts as $acc) {
            $page = null;
            do {
                $r = $this->api($c, 'GET', 'https://mybusinessbusinessinformation.googleapis.com/v1/'.$acc['name'].'/locations', [], array_filter([
                    'readMask' => 'name,title,storefrontAddress,phoneNumbers,websiteUri,regularHours,profile,metadata', 'pageSize' => 100, 'pageToken' => $page]))->json();
                foreach ($r['locations'] ?? [] as $l) {
                    $addr = $l['storefrontAddress'] ?? [];
                    $vals = ['title' => mb_substr($l['title'] ?? 'Établissement', 0, 190),
                        'address' => mb_substr(trim(implode(', ', array_filter([implode(' ', $addr['addressLines'] ?? []), $addr['locality'] ?? null, $addr['regionCode'] ?? null]))), 0, 300) ?: null,
                        'phone' => $l['phoneNumbers']['primaryPhone'] ?? null, 'website' => $l['websiteUri'] ?? null, 'description' => $l['profile']['description'] ?? null,
                        'hours' => json_encode($l['regularHours']['periods'] ?? []), 'place_id' => $l['metadata']['placeId'] ?? null, 'maps_uri' => $l['metadata']['mapsUri'] ?? null,
                        'review_uri' => $l['metadata']['newReviewUri'] ?? (isset($l['metadata']['placeId']) ? 'https://search.google.com/local/writereview?placeid='.$l['metadata']['placeId'] : null),
                        'updated_at' => now()];
                    $ex = DB::table('gb_locations')->where('company_id', $c->id)->where('location_name', $l['name'])->first();
                    $ex ? DB::table('gb_locations')->where('id', $ex->id)->update($vals)
                        : DB::table('gb_locations')->insert($vals + ['company_id' => $c->id, 'location_name' => $l['name'], 'token' => self::newToken(), 'created_at' => now()]);
                    $n++;
                }
                $page = $r['nextPageToken'] ?? null;
            } while ($page);
            DB::table('gb_settings')->where('company_id', $c->id)->update(['account_name' => $acc['name']]);
        }

        return $n;
    }

    /** Recherche d'un établissement par son nom (API Places), pour le mode lecture seule. */
    public function searchPlaces(Company $c, string $query): array
    {
        $key = $this->placesKey($c);
        if (! $key) {
            throw new \DomainException('Renseignez une clé API Google Maps (API Places) pour rechercher votre établissement.');
        }
        $r = Http::withHeaders(['X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress,places.rating,places.userRatingCount'])
            ->acceptJson()->timeout(20)->post('https://places.googleapis.com/v1/places:searchText', ['textQuery' => $query, 'languageCode' => 'fr']);
        if ($r->failed()) {
            throw new \DomainException('Google Places : '.($r->json('error.message') ?: 'erreur '.$r->status()));
        }

        return array_map(fn ($p) => ['place_id' => $p['id'], 'name' => $p['displayName']['text'] ?? '', 'address' => $p['formattedAddress'] ?? '', 'rating' => $p['rating'] ?? null,
            'count' => $p['userRatingCount'] ?? 0], $r->json('places') ?? []);
    }

    public function addPlace(Company $c, string $placeId, ?string $title = null): int
    {
        $s = $this->settings($c);
        if ($s->mode === 'none') {
            DB::table('gb_settings')->where('id', $s->id)->update(['mode' => 'places']);
        }
        $ex = DB::table('gb_locations')->where('company_id', $c->id)->where('place_id', $placeId)->first();
        $id = $ex?->id ?? DB::table('gb_locations')->insertGetId(['company_id' => $c->id, 'place_id' => $placeId, 'title' => $title ?: 'Mon établissement', 'token' => self::newToken(),
            'review_uri' => 'https://search.google.com/local/writereview?placeid='.$placeId, 'created_at' => now(), 'updated_at' => now()]);
        $this->syncPlace($c, DB::table('gb_locations')->find($id));

        return $id;
    }

    // ------------------------------------------------------------------ avis

    public function syncAll(Company $c): array
    {
        $s = $this->settings($c);
        $new = 0;
        foreach (DB::table('gb_locations')->where('company_id', $c->id)->get() as $loc) {
            $new += $s->mode === 'api' && $loc->location_name ? $this->syncReviews($c, $loc) : ($loc->place_id ? $this->syncPlace($c, $loc) : 0);
        }
        DB::table('gb_settings')->where('id', $s->id)->update(['last_sync_at' => now(), 'error' => null]);
        $this->alerts($c);

        return ['new' => $new];
    }

    public function syncReviews(Company $c, object $loc): int
    {
        $acc = $this->settings($c)->account_name;
        $path = 'https://mybusiness.googleapis.com/v4/'.$acc.'/'.$loc->location_name.'/reviews';
        $page = null;
        $new = 0;
        $avg = null;
        $total = 0;
        for ($i = 0; $i < 10; $i++) {
            $r = $this->api($c, 'GET', $path, [], array_filter(['pageSize' => 50, 'pageToken' => $page, 'orderBy' => 'updateTime desc']))->json();
            $avg = $r['averageRating'] ?? $avg;
            $total = $r['totalReviewCount'] ?? $total;
            $known = 0;
            foreach ($r['reviews'] ?? [] as $rv) {
                $is = $this->upsertReview($c, $loc, [
                    'review_id' => $rv['reviewId'] ?? basename($rv['name']), 'author' => $rv['reviewer']['displayName'] ?? 'Client Google', 'author_photo' => $rv['reviewer']['profilePhotoUrl'] ?? null,
                    'rating' => self::STARS[$rv['starRating'] ?? 'FIVE'] ?? 5, 'comment' => $rv['comment'] ?? null, 'reviewed_at' => isset($rv['createTime']) ? Carbon::parse($rv['createTime']) : now(),
                    'reply' => $rv['reviewReply']['comment'] ?? null, 'replied_at' => isset($rv['reviewReply']['updateTime']) ? Carbon::parse($rv['reviewReply']['updateTime']) : null, 'source' => 'api']);
                $is ? $new++ : $known++;
            }
            $page = $r['nextPageToken'] ?? null;
            if (! $page || $known > 10) {
                break; // le reste est déjà connu
            }
        }
        $this->stats($loc, $avg, $total);

        return $new;
    }

    public function syncPlace(Company $c, object $loc): int
    {
        $key = $this->placesKey($c);
        if (! $key) {
            return 0;
        }
        $r = Http::withHeaders(['X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => 'id,displayName,formattedAddress,rating,userRatingCount,reviews,googleMapsUri,websiteUri,nationalPhoneNumber'])
            ->acceptJson()->timeout(20)->get('https://places.googleapis.com/v1/places/'.$loc->place_id, ['languageCode' => 'fr']);
        if ($r->failed()) {
            throw new \DomainException('Google Places : '.($r->json('error.message') ?: 'erreur '.$r->status()));
        }
        $p = $r->json();
        DB::table('gb_locations')->where('id', $loc->id)->update(array_filter(['title' => $p['displayName']['text'] ?? null, 'address' => $p['formattedAddress'] ?? null,
            'maps_uri' => $p['googleMapsUri'] ?? null, 'website' => $p['websiteUri'] ?? null, 'phone' => $p['nationalPhoneNumber'] ?? null]) + ['updated_at' => now()]);
        $new = 0;
        foreach ($p['reviews'] ?? [] as $rv) {
            $new += $this->upsertReview($c, $loc, ['review_id' => basename((string) ($rv['name'] ?? md5(json_encode($rv)))), 'author' => $rv['authorAttribution']['displayName'] ?? 'Client Google',
                'author_photo' => $rv['authorAttribution']['photoUri'] ?? null, 'rating' => (int) ($rv['rating'] ?? 5), 'comment' => $rv['originalText']['text'] ?? ($rv['text']['text'] ?? null),
                'reviewed_at' => isset($rv['publishTime']) ? Carbon::parse($rv['publishTime']) : now(), 'source' => 'places']) ? 1 : 0;
        }
        $this->stats($loc, $p['rating'] ?? null, (int) ($p['userRatingCount'] ?? 0));

        return $new;
    }

    protected function upsertReview(Company $c, object $loc, array $d): bool
    {
        $ex = DB::table('gb_reviews')->where('location_id', $loc->id)->where('review_id', $d['review_id'])->first();
        $vals = ['author' => mb_substr((string) $d['author'], 0, 190), 'author_photo' => $d['author_photo'] ? mb_substr($d['author_photo'], 0, 500) : null, 'rating' => max(1, min(5, (int) $d['rating'])),
            'comment' => $d['comment'], 'reviewed_at' => $d['reviewed_at'], 'source' => $d['source'], 'updated_at' => now()];
        if (array_key_exists('reply', $d)) {
            $vals['reply'] = $d['reply'];
            $vals['replied_at'] = $d['replied_at'];
        }
        if ($ex) {
            DB::table('gb_reviews')->where('id', $ex->id)->update($vals);

            return false;
        }
        DB::table('gb_reviews')->insert($vals + ['company_id' => $c->id, 'location_id' => $loc->id, 'review_id' => $d['review_id'], 'partner_id' => $this->partnerByName($c, (string) $d['author']), 'created_at' => now()]);

        return true;
    }

    protected function partnerByName(Company $c, string $name): ?string
    {
        $n = trim($name);

        return mb_strlen($n) >= 5 ? DB::table('partners')->where('company_id', $c->id)->whereNull('deleted_at')->whereRaw('LOWER(name) = ?', [mb_strtolower($n)])->value('id') : null;
    }

    protected function stats(object $loc, $avg, int $total): void
    {
        $hist = json_decode((string) $loc->history, true) ?: [];
        $hist[now()->toDateString()] = ['r' => $avg !== null ? round((float) $avg, 2) : null, 'n' => $total];
        $hist = array_slice($hist, -400, null, true);
        DB::table('gb_locations')->where('id', $loc->id)->update(['rating' => $avg, 'review_count' => $total, 'history' => json_encode($hist), 'last_sync_at' => now()]);
    }

    /** Avis négatifs non signalés : notification aux responsables, et WhatsApp / e-mail d'alerte si réglés. */
    public function alerts(Company $c): int
    {
        $a = $this->alertSettings($c);
        $rows = DB::table('gb_reviews')->where('company_id', $c->id)->whereNull('alerted_at')->where('rating', '<=', (int) $a['max_rating'])->whereNull('reply')
            ->where('reviewed_at', '>=', now()->subDays(14))->get();
        if ($rows->isEmpty()) {
            DB::table('gb_reviews')->where('company_id', $c->id)->whereNull('alerted_at')->update(['alerted_at' => now()]);

            return 0;
        }
        $users = User::where('company_id', $c->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('avis.manage') || $u->hasPermission('avis.reply'))->pluck('id')->all();
        foreach ($rows as $r) {
            $text = str_repeat('★', $r->rating).str_repeat('☆', 5 - $r->rating).' '.$r->author.' : '.mb_substr((string) $r->comment, 0, 160);
            try {
                AppNotification::send($c->id, $users, 'avis', 'Avis Google à traiter ('.$r->rating.'/5)', $text, 'avis_google', (string) $r->id);
            } catch (\Throwable) {
            }
            foreach (['whatsapp' => $a['whatsapp'], 'email' => $a['email']] as $ch => $to) {
                if ($to) {
                    try {
                        app(MessagingService::class)->send($c, $ch, new Message(to: $to, subject: 'Nouvel avis Google '.$r->rating.'/5', text: "Nouvel avis Google ($r->rating/5) pour {$c->name}\n$text\n\nRépondez depuis Yomara › Avis Google."), 'avis.alerte');
                    } catch (\Throwable) {
                    }
                }
            }
        }
        DB::table('gb_reviews')->where('company_id', $c->id)->whereNull('alerted_at')->update(['alerted_at' => now()]);

        return $rows->count();
    }

    public function reply(Company $c, object $review, string $text, ?int $userId): void
    {
        $loc = DB::table('gb_locations')->find($review->location_id);
        if ($review->source !== 'api' || ! $loc?->location_name) {
            throw new \DomainException('Pour répondre depuis le logiciel, connectez votre fiche Google Business (le mode lecture seule ne permet que de consulter les avis).');
        }
        $acc = $this->settings($c)->account_name;
        $this->api($c, 'PUT', 'https://mybusiness.googleapis.com/v4/'.$acc.'/'.$loc->location_name.'/reviews/'.$review->review_id.'/reply', ['comment' => $text]);
        DB::table('gb_reviews')->where('id', $review->id)->update(['reply' => $text, 'replied_at' => now(), 'replied_by' => $userId, 'updated_at' => now()]);
    }

    public function deleteReply(Company $c, object $review): void
    {
        $loc = DB::table('gb_locations')->find($review->location_id);
        if ($review->source === 'api' && $loc?->location_name) {
            $this->api($c, 'DELETE', 'https://mybusiness.googleapis.com/v4/'.$this->settings($c)->account_name.'/'.$loc->location_name.'/reviews/'.$review->review_id.'/reply');
        }
        DB::table('gb_reviews')->where('id', $review->id)->update(['reply' => null, 'replied_at' => null, 'replied_by' => null, 'updated_at' => now()]);
    }

    // ------------------------------------------------------------------ fiche, publications, statistiques

    public function updateInfo(Company $c, object $loc, array $d): void
    {
        $body = [];
        $mask = [];
        if (array_key_exists('description', $d)) {
            $body['profile'] = ['description' => (string) $d['description']];
            $mask[] = 'profile.description';
        }
        if (array_key_exists('phone', $d)) {
            $body['phoneNumbers'] = ['primaryPhone' => (string) $d['phone']];
            $mask[] = 'phoneNumbers';
        }
        if (array_key_exists('website', $d)) {
            $body['websiteUri'] = (string) $d['website'];
            $mask[] = 'websiteUri';
        }
        if (array_key_exists('hours', $d)) {
            $body['regularHours'] = ['periods' => $d['hours']];
            $mask[] = 'regularHours';
        }
        if ($mask && $this->settings($c)->mode === 'api' && $loc->location_name) {
            $this->api($c, 'PATCH', 'https://mybusinessbusinessinformation.googleapis.com/v1/'.$loc->location_name, $body, ['updateMask' => implode(',', $mask)]);
        }
        $u = ['updated_at' => now()];
        foreach (['description', 'phone', 'website'] as $k) {
            if (array_key_exists($k, $d)) {
                $u[$k] = $d[$k];
            }
        }
        if (array_key_exists('hours', $d)) {
            $u['hours'] = json_encode($d['hours']);
        }
        DB::table('gb_locations')->where('id', $loc->id)->update($u);
    }

    public function publish(Company $c, object $post): void
    {
        $loc = DB::table('gb_locations')->find($post->location_id);
        if ($this->settings($c)->mode !== 'api' || ! $loc?->location_name) {
            throw new \DomainException('Les publications passent par la connexion Google Business de la fiche.');
        }
        $body = ['languageCode' => 'fr', 'summary' => $post->summary, 'topicType' => $post->type];
        if ($post->cta_type && ($post->cta_url || $post->cta_type === 'CALL')) {
            $body['callToAction'] = array_filter(['actionType' => $post->cta_type, 'url' => $post->cta_type === 'CALL' ? null : $post->cta_url]);
        }
        if ($post->photo_url) {
            $body['media'] = [['mediaFormat' => 'PHOTO', 'sourceUrl' => $post->photo_url]];
        }
        if (in_array($post->type, ['OFFER', 'EVENT'], true)) {
            $d = fn ($v) => ['year' => (int) substr($v, 0, 4), 'month' => (int) substr($v, 5, 2), 'day' => (int) substr($v, 8, 2)];
            $body['event'] = ['title' => $post->title ?: mb_substr($post->summary, 0, 58), 'schedule' => ['startDate' => $d($post->starts_on ?: now()->toDateString()), 'endDate' => $d($post->ends_on ?: now()->addDays(7)->toDateString())]];
            if ($post->type === 'OFFER' && $post->coupon) {
                $body['offer'] = ['couponCode' => $post->coupon];
            }
        }
        $r = $this->api($c, 'POST', 'https://mybusiness.googleapis.com/v4/'.$this->settings($c)->account_name.'/'.$loc->location_name.'/localPosts', $body);
        DB::table('gb_posts')->where('id', $post->id)->update(['google_name' => $r->json('name'), 'status' => 'published', 'published_at' => now(), 'error' => null, 'updated_at' => now()]);
    }

    public function metrics(Company $c, object $loc, int $days = 30): array
    {
        if ($this->settings($c)->mode !== 'api' || ! $loc->location_name) {
            return [];
        }
        $from = now()->subDays($days);
        $to = now()->subDay();
        $metrics = ['BUSINESS_IMPRESSIONS_DESKTOP_MAPS', 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH', 'BUSINESS_IMPRESSIONS_MOBILE_MAPS', 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH', 'CALL_CLICKS', 'WEBSITE_CLICKS', 'BUSINESS_DIRECTION_REQUESTS'];
        $q = http_build_query(['dailyRange.startDate.year' => $from->year, 'dailyRange.startDate.month' => $from->month, 'dailyRange.startDate.day' => $from->day,
            'dailyRange.endDate.year' => $to->year, 'dailyRange.endDate.month' => $to->month, 'dailyRange.endDate.day' => $to->day]);
        $q .= '&'.implode('&', array_map(fn ($m) => 'dailyMetrics='.$m, $metrics));
        $r = $this->api($c, 'GET', 'https://businessprofileperformance.googleapis.com/v1/'.$loc->location_name.':fetchMultiDailyMetricsTimeSeries?'.$q)->json();
        $out = ['views' => 0, 'calls' => 0, 'website' => 0, 'directions' => 0, 'days' => []];
        foreach ($r['multiDailyMetricTimeSeries'] ?? [] as $grp) {
            foreach ($grp['dailyMetricTimeSeries'] ?? [] as $ts) {
                $key = match (true) { str_starts_with($ts['dailyMetric'], 'BUSINESS_IMPRESSIONS') => 'views', $ts['dailyMetric'] === 'CALL_CLICKS' => 'calls', $ts['dailyMetric'] === 'WEBSITE_CLICKS' => 'website', default => 'directions' };
                foreach ($ts['timeSeries']['datedValues'] ?? [] as $dv) {
                    $v = (int) ($dv['value'] ?? 0);
                    $date = sprintf('%04d-%02d-%02d', $dv['date']['year'], $dv['date']['month'], $dv['date']['day']);
                    $out[$key] += $v;
                    $out['days'][$date][$key] = ($out['days'][$date][$key] ?? 0) + $v;
                }
            }
        }
        ksort($out['days']);
        DB::table('gb_locations')->where('id', $loc->id)->update(['metrics' => json_encode($out + ['at' => now()->toIso8601String()])]);

        return $out;
    }

    // ------------------------------------------------------------------ tâche planifiée

    public function runDue(): array
    {
        $synced = 0;
        foreach (DB::table('gb_settings')->where('mode', '!=', 'none')->where(fn ($q) => $q->whereNull('last_sync_at')->orWhere('last_sync_at', '<=', now()->subMinutes(30)))->get() as $s) {
            $c = Company::find($s->company_id);
            if (! $c || ! $c->hasModule('avis_google')) {
                continue;
            }
            app()->instance('tenant', $c);
            try {
                $this->syncAll($c);
                if ($s->mode === 'api' && (! $s->last_sync_at || Carbon::parse($s->last_sync_at)->toDateString() !== now()->toDateString())) {
                    foreach (DB::table('gb_locations')->where('company_id', $c->id)->get() as $loc) {
                        $this->metrics($c, $loc);
                    }
                }
                $synced++;
            } catch (\Throwable $e) {
                DB::table('gb_settings')->where('id', $s->id)->update(['error' => mb_substr($e->getMessage(), 0, 500), 'last_sync_at' => now()]);
            }
        }
        $sent = app(ReviewRequests::class)->sendDue();

        return ['synced' => $synced, 'requests' => $sent];
    }
}
