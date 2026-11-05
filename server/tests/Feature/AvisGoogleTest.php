<?php

namespace Tests\Feature;

use App\Models\MessagingChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Avis Google : fiche connectée (Business Profile), mode lecture seule (Places), réponses, alertes, demandes d'avis, publications, pages publiques. */
class AvisGoogleTest extends TestCase
{
    public function test_business_profile_flow(): void
    {
        [$company, $user, $token] = $this->makeCompany('Avis SARL');
        config(['erp.mailbox.google_client_id' => 'gcid', 'erp.mailbox.google_client_secret' => 'gsec']);
        $api = fn () => $this->api($token);
        MessagingChannel::withoutGlobalScopes()->create(['company_id' => $company->id, 'channel' => 'whatsapp', 'provider' => 'ultramsg', 'enabled' => true, 'settings' => ['instance_id' => 'i1', 'token' => 't']]);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'GAT', 'refresh_token' => 'GRT', 'expires_in' => 3600]),
            'openidconnect.googleapis.com/*' => Http::response(['email' => 'gerant@gmail.com']),
            'mybusinessaccountmanagement.googleapis.com/v1/accounts' => Http::response(['accounts' => [['name' => 'accounts/111']]]),
            'mybusinessbusinessinformation.googleapis.com/v1/accounts/111/locations*' => Http::response(['locations' => [['name' => 'locations/222', 'title' => 'Avis SARL Plateau',
                'storefrontAddress' => ['addressLines' => ['Rue du Commerce'], 'locality' => 'Abidjan'], 'phoneNumbers' => ['primaryPhone' => '+225 27 20 00 00 00'],
                'metadata' => ['placeId' => 'PL1', 'mapsUri' => 'https://maps.google.com/?cid=1', 'newReviewUri' => 'https://g.page/r/abc/review']]]]),
            'mybusiness.googleapis.com/v4/accounts/111/locations/222/reviews?*' => Http::response(['averageRating' => 4.3, 'totalReviewCount' => 57, 'reviews' => [
                ['reviewId' => 'R1', 'reviewer' => ['displayName' => 'Awa K.'], 'starRating' => 'FIVE', 'comment' => 'Service rapide et accueil top !', 'createTime' => now()->subDay()->toIso8601String()],
                ['reviewId' => 'R2', 'reviewer' => ['displayName' => 'Paul Z.'], 'starRating' => 'ONE', 'comment' => 'Commande livrée en retard, personne ne répond au téléphone.', 'createTime' => now()->subHours(3)->toIso8601String()],
                ['reviewId' => 'R3', 'reviewer' => ['displayName' => 'Inès'], 'starRating' => 'FOUR', 'createTime' => now()->subDays(5)->toIso8601String(), 'reviewReply' => ['comment' => 'Merci Inès !', 'updateTime' => now()->subDays(4)->toIso8601String()]],
            ]]),
            'mybusiness.googleapis.com/v4/accounts/111/locations/222/reviews/R2/reply' => Http::response(['comment' => 'ok']),
            'mybusiness.googleapis.com/v4/accounts/111/locations/222/localPosts' => Http::response(['name' => 'accounts/111/locations/222/localPosts/P9']),
            'mybusinessbusinessinformation.googleapis.com/v1/locations/222*' => Http::response(['name' => 'locations/222']),
            'businessprofileperformance.googleapis.com/*' => Http::response(['multiDailyMetricTimeSeries' => [['dailyMetricTimeSeries' => [
                ['dailyMetric' => 'BUSINESS_IMPRESSIONS_MOBILE_MAPS', 'timeSeries' => ['datedValues' => [['date' => ['year' => 2026, 'month' => 10, 'day' => 30], 'value' => '120']]]],
                ['dailyMetric' => 'CALL_CLICKS', 'timeSeries' => ['datedValues' => [['date' => ['year' => 2026, 'month' => 10, 'day' => 30], 'value' => '7']]]],
            ]]]]),
            'api.ultramsg.com/*' => Http::response(['sent' => 'true', 'id' => 5]),
        ]);

        $url = $api()->postJson('/api/avis_google/connect')->assertOk()->json('url');
        $this->assertStringContainsString('business.manage', urldecode($url));
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->flushHeaders();
        $this->get('/oauth/google-business/retour?state='.$q['state'].'&code=C')->assertOk()->assertSee('Fiche Google connectée');
        $idx = $api()->getJson('/api/avis_google')->assertOk()->json('data');
        $this->assertEquals(['api', 'gerant@gmail.com', 4.3, 57, 3, 2], [$idx['mode'], $idx['connected_email'], $idx['stats']['rating'], $idx['stats']['count'], $idx['stats']['stored'], $idx['stats']['unanswered']]);
        $loc = $idx['locations'][0];
        $this->assertEquals(['Avis SARL Plateau', 'https://g.page/r/abc/review'], [$loc['title'], $loc['review_uri']]);
        // alerte sur l'avis 1 étoile
        $this->assertTrue(DB::table('app_notifications')->where('company_id', $company->id)->where('title', 'like', 'Avis Google à traiter (1/5)%')->exists());

        $neg = collect($api()->getJson('/api/avis_google/reviews?unanswered=1&rating=1,2')->assertOk()->json('data'))->first();
        $this->assertSame('Paul Z.', $neg['author']);
        $api()->postJson('/api/avis_google/reviews/'.$neg['id'].'/reply', ['text' => 'Bonjour Paul, toutes nos excuses pour ce retard. Contactez-nous au 27 20 00 00 00.'])->assertOk()->assertJsonPath('data.reply', 'Bonjour Paul, toutes nos excuses pour ce retard. Contactez-nous au 27 20 00 00 00.');
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/reviews/R2/reply') && str_starts_with($r['comment'], 'Bonjour Paul'));

        // Publication d'une offre, mise à jour de la fiche, statistiques
        $api()->postJson('/api/avis_google/posts', ['location_id' => $loc['id'], 'type' => 'OFFER', 'title' => 'Promo de novembre', 'summary' => '-20 % sur toute la gamme jusqu\'au 30 novembre.',
            'cta_type' => 'LEARN_MORE', 'cta_url' => 'https://avis-sarl.ci/promo', 'coupon' => 'NOV20', 'ends_on' => now()->addDays(10)->toDateString()])->assertCreated()->assertJsonPath('data.status', 'published');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/localPosts') && $r['topicType'] === 'OFFER' && $r['offer']['couponCode'] === 'NOV20' && $r['callToAction']['actionType'] === 'LEARN_MORE');
        $api()->putJson('/api/avis_google/locations/'.$loc['id'], ['description' => 'Distributeur de matériel à Abidjan depuis 2010.', 'website' => 'https://avis-sarl.ci'])->assertOk();
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), 'locations/222') && str_contains(urldecode($r->url()), 'updateMask=profile.description,websiteUri'));
        $m = $api()->getJson('/api/avis_google/locations/'.$loc['id'].'/metrics')->assertOk()->json('data');
        $this->assertEquals([120, 7], [$m['views'], $m['calls']]);

        // Demandes d'avis automatiques après une facture payée (délai 0), une seule fois par période
        $api()->putJson('/api/avis_google/settings', ['requests' => ['enabled' => true, 'delay_hours' => 0, 'triggers' => ['invoice.paid']]])->assertOk();
        DB::table('partners')->insert(['id' => $pid = (string) Str::uuid(), 'company_id' => $company->id, 'type' => 'customer', 'name' => 'Koné Mamadou', 'phone' => '0707080910', 'created_at' => now(), 'updated_at' => now(), 'version' => 1]);
        $push = fn (string $entity, array $data, ?string $id = null) => $this->api($token)->postJson('/api/sync/push', ['mutations' => [[
            'id' => (string) Str::uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? (string) Str::uuid(), 'data' => $data]]])->assertOk()->json('results.0');
        foreach ([1, 2] as $k) {
            $inv = (string) Str::uuid();
            $push('sales_documents', ['type' => 'invoice', 'partner_id' => $pid, 'date' => now()->toDateString(), 'lines' => [['id' => (string) Str::uuid(), 'description' => 'Article', 'quantity' => 1, 'unit_price' => 10000, 'tax_rate' => 0]]], $inv);
            $this->api($token)->postJson("/api/ventes/documents/$inv/validate")->assertOk();
            $push('sales_payments', ['document_id' => $inv, 'date' => now()->toDateString(), 'amount' => 10000, 'method' => 'cash']);
        }
        $reqs = DB::table('gb_requests')->where('company_id', $company->id)->get();
        $this->assertCount(1, $reqs);
        $this->assertEquals(['whatsapp', '+2250707080910', 'sent', 'Koné Mamadou'], [$reqs[0]->channel, $reqs[0]->to, $reqs[0]->status, $reqs[0]->name]);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'ultramsg') && str_contains((string) $r['body'], 'Bonjour Koné, merci pour votre confiance') && str_contains((string) $r['body'], '/note/'.$reqs[0]->token));

        // Page reçue par le client : bouton Google pour tous + message privé à l'équipe
        $this->flushHeaders();
        $this->get('/note/'.$reqs[0]->token)->assertOk()->assertSee('Donner mon avis sur Google')->assertSee('https://g.page/r/abc/review', false)->assertSee('Un souci ?');
        $this->post('/note/'.$reqs[0]->token, ['rating' => 2, 'feedback' => 'Le livreur était impoli.'])->assertOk()->assertSee('Merci, c&#039;est transmis', false);
        $this->assertEquals(['feedback', 2], [DB::table('gb_requests')->value('status'), DB::table('gb_requests')->value('feedback_rating')]);
        // QR code et affiche
        $l = DB::table('gb_locations')->first();
        $this->get('/note/l/'.$l->token)->assertRedirect('https://g.page/r/abc/review');
        $this->get('/note/l/'.$l->token.'/affiche')->assertOk()->assertSee('Notez-nous sur')->assertSee('<svg', false);
        $this->assertSame(1, json_decode(DB::table('gb_locations')->value('metrics'), true)['qr_scans']);
        // Demande manuelle forcée
        $this->api($token)->postJson('/api/avis_google/requests', ['partner_id' => $pid])->assertCreated();
        $this->assertSame(2, DB::table('gb_requests')->count());
    }

    public function test_places_read_only_mode(): void
    {
        [$company, , $token] = $this->makeCompany('Places SARL');
        Http::fake([
            'places.googleapis.com/v1/places:searchText' => Http::response(['places' => [['id' => 'ChIJ123', 'displayName' => ['text' => 'Maquis Le Bon Goût'], 'formattedAddress' => 'Cocody, Abidjan', 'rating' => 4.6, 'userRatingCount' => 210]]]),
            'places.googleapis.com/v1/places/ChIJ123*' => Http::response(['id' => 'ChIJ123', 'displayName' => ['text' => 'Maquis Le Bon Goût'], 'rating' => 4.6, 'userRatingCount' => 210,
                'googleMapsUri' => 'https://maps.google.com/?cid=9', 'reviews' => [['name' => 'places/ChIJ123/reviews/A', 'rating' => 5, 'text' => ['text' => 'Le meilleur garba de Cocody'],
                    'authorAttribution' => ['displayName' => 'Yao'], 'publishTime' => now()->subDays(2)->toIso8601String()]]]),
        ]);
        $this->api($token)->postJson('/api/avis_google/places/search', ['query' => 'Maquis Le Bon Goût'])->assertStatus(422);
        $this->api($token)->putJson('/api/avis_google/settings', ['places_api_key' => 'AIzaTEST'])->assertOk()->assertJsonPath('data.places_key', true);
        $found = $this->api($token)->postJson('/api/avis_google/places/search', ['query' => 'Maquis Le Bon Goût'])->assertOk()->json('data');
        $this->assertSame('ChIJ123', $found[0]['place_id']);
        $loc = $this->api($token)->postJson('/api/avis_google/places', ['place_id' => 'ChIJ123'])->assertCreated()->json('data');
        $this->assertEquals([4.6, 210, 'https://search.google.com/local/writereview?placeid=ChIJ123'], [$loc['rating'], $loc['review_count'], $loc['review_uri']]);
        $rv = $this->api($token)->getJson('/api/avis_google/reviews')->assertOk()->json('data.0');
        $this->assertEquals(['Yao', 5, 'places'], [$rv['author'], $rv['rating'], $rv['source']]);
        $this->api($token)->postJson('/api/avis_google/reviews/'.$rv['id'].'/reply', ['text' => 'Merci Yao !'])->assertStatus(422);
        $this->assertSame('places', $this->api($token)->getJson('/api/avis_google')->json('data.mode'));
    }
}
