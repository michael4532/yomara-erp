<?php

namespace App\Modules\Acquisition;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Sequences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Arrivée d'un prospect (publicité, page de capture, formulaire externe) : opportunité dans le CRM, commercial désigné,
 * contact marketing et scénario, réponse automatique, relances, notification. Un prospect déjà connu n'est pas recréé.
 */
class LeadIntake
{
    public const LABELS = ['meta' => 'Facebook / Instagram', 'google' => 'Google Ads', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn', 'landing' => 'Page', 'website' => 'Site web', 'zapier' => 'Formulaire', 'other' => 'Formulaire'];

    /** Noms de champs reconnus (Meta, Google, TikTok, LinkedIn, formulaires courants) => champ du CRM. */
    public const ALIASES = [
        'full_name' => 'name', 'name' => 'name', 'nom_complet' => 'name', 'nom_et_prenom' => 'name', 'nom_prenom' => 'name', 'fullname' => 'name', 'your_name' => 'name', 'contact_name' => 'name',
        'first_name' => 'first_name', 'firstname' => 'first_name', 'prenom' => 'first_name', 'given_name' => 'first_name',
        'last_name' => 'last_name', 'lastname' => 'last_name', 'nom' => 'last_name', 'family_name' => 'last_name', 'surname' => 'last_name',
        'email' => 'email', 'e_mail' => 'email', 'mail' => 'email', 'email_address' => 'email', 'courriel' => 'email', 'work_email' => 'email', 'adresse_e_mail' => 'email',
        'phone' => 'phone', 'phone_number' => 'phone', 'telephone' => 'phone', 'tel' => 'phone', 'mobile' => 'phone', 'whatsapp' => 'phone', 'numero' => 'phone', 'numero_de_telephone' => 'phone', 'work_phone_number' => 'phone', 'contact' => 'phone',
        'company' => 'company', 'company_name' => 'company', 'entreprise' => 'company', 'societe' => 'company', 'organisation' => 'company', 'structure' => 'company',
        'city' => 'city', 'ville' => 'city', 'commune' => 'city', 'localite' => 'city',
        'message' => 'message', 'comments' => 'message', 'commentaire' => 'message', 'besoin' => 'message', 'demande' => 'message', 'votre_message' => 'message',
    ];

    public function __construct(protected MessagingService $messaging) {}

    public static function key(string $k): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii($k))), '_');
    }

    /** Champs reconnus + toutes les réponses telles que saisies. */
    public static function normalize(array $answers): array
    {
        $n = [];
        foreach ($answers as $k => $v) {
            $v = is_array($v) ? implode(', ', array_filter(array_map('strval', $v))) : trim((string) $v);
            if ($v === '') {
                continue;
            }
            $f = self::ALIASES[self::key((string) $k)] ?? null;
            if ($f && ! isset($n[$f])) {
                $n[$f] = Str::limit($v, 250, '');
            }
        }
        if (empty($n['name'])) {
            $n['name'] = trim(($n['first_name'] ?? '').' '.($n['last_name'] ?? '')) ?: null;
        }
        if (! empty($n['email']) && ! filter_var($n['email'], FILTER_VALIDATE_EMAIL)) {
            unset($n['email']);
        }
        if (! empty($n['phone'])) {
            $n['phone'] = Str::limit(preg_replace('/[^\d+]/', '', $n['phone']), 30, '');
        }

        return $n;
    }

    protected static function digits(?string $phone): ?string
    {
        $d = preg_replace('/\D/', '', (string) $phone);

        return strlen($d) >= 8 ? substr($d, -8) : null;
    }

    protected function assignee(Company $c, ?object $source): ?int
    {
        if ($source && $source->assign === 'round_robin') {
            $team = User::where('company_id', $c->id)->where('is_active', true)->orderBy('id')->get()->filter(fn ($u) => $u->hasPermission('crm.manage'))->pluck('id')->values();
            if ($team->isNotEmpty()) {
                $i = (int) $source->rr_index % $team->count();
                DB::table('ac_sources')->where('id', $source->id)->increment('rr_index');

                return $team[$i];
            }
        }
        if ($source?->owner_id) {
            return (int) $source->owner_id;
        }

        return User::where('company_id', $c->id)->where('is_active', true)->orderBy('id')->get()->first(fn ($u) => $u->hasPermission('crm.manage'))?->id;
    }

    /** Campagne interne correspondant à la campagne de la plateforme (créée au besoin) ou au code utm_campaign. */
    public function campaign(Company $c, string $platform, ?string $externalId, ?string $name, ?string $utm): ?int
    {
        if ($externalId) {
            $id = DB::table('ac_campaigns')->where('company_id', $c->id)->where('platform', $platform)->where('external_id', $externalId)->value('id');
            if ($id) {
                return $id;
            }
            if ($name || $platform !== 'other') {
                $title = $name ?: (self::LABELS[$platform] ?? 'Campagne').' '.$externalId;

                return DB::table('ac_campaigns')->insertGetId(['company_id' => $c->id, 'platform' => $platform, 'external_id' => $externalId, 'title' => Str::limit($title, 200, ''),
                    'utm_campaign' => Str::limit(Str::slug($title, '_'), 150, ''), 'status' => 'active', 'objective' => 'leads', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        if ($utm) {
            return DB::table('ac_campaigns')->where('company_id', $c->id)->where('utm_campaign', Str::slug($utm, '_'))->value('id');
        }

        return null;
    }

    /**
     * @param  array  $meta  external_id, campaign_id, campaign_name, ad_name, form_name, utm_source, utm_medium, utm_campaign, utm_content
     */
    public function receive(Company $c, ?object $source, string $platform, array $answers, array $meta = []): array
    {
        app()->instance('tenant', $c);
        $n = self::normalize($answers);
        $ext = isset($meta['external_id']) ? Str::limit((string) $meta['external_id'], 120, '') : null;
        if ($ext && ($prev = DB::table('ac_leads')->where('company_id', $c->id)->where('platform', $platform)->where('external_id', $ext)->first())) {
            return ['status' => 'already', 'ac_lead_id' => $prev->id, 'lead_id' => $prev->lead_id];
        }
        if (empty($n['phone']) && empty($n['email'])) {
            throw new \DomainException('Ni téléphone ni e-mail dans le formulaire reçu.');
        }
        $name = $n['name'] ?: ($n['email'] ?? $n['phone']);
        $campaignId = $this->campaign($c, $platform, $meta['campaign_id'] ?? null, $meta['campaign_name'] ?? null, $meta['utm_campaign'] ?? null);
        $campaign = $campaignId ? DB::table('ac_campaigns')->find($campaignId) : null;
        $log = ['company_id' => $c->id, 'source_id' => $source?->id, 'platform' => $platform, 'external_id' => $ext, 'title' => Str::limit($name, 200, ''), 'email' => $n['email'] ?? null, 'phone' => $n['phone'] ?? null,
            'campaign_id' => $campaignId, 'ad_name' => isset($meta['ad_name']) ? Str::limit((string) $meta['ad_name'], 200, '') : null, 'form_name' => isset($meta['form_name']) ? Str::limit((string) $meta['form_name'], 200, '') : null,
            'fields' => json_encode($answers, JSON_UNESCAPED_UNICODE), 'received_at' => now(), 'created_at' => now(), 'updated_at' => now()];

        // Déjà dans le CRM (même e-mail ou mêmes 8 derniers chiffres) avec une affaire en cours : on la complète au lieu d'en créer une autre.
        $digits = self::digits($n['phone'] ?? null);
        $existing = Lead::where('status', 'open')->where('created_at', '>=', now()->subDays(90))
            ->where(function ($q) use ($n, $digits) {
                if (! empty($n['email'])) {
                    $q->orWhere('email', $n['email']);
                }
                if ($digits) {
                    $q->orWhere('phone', 'like', '%'.$digits);
                }
            })->orderByDesc('created_at')->first();
        $label = self::LABELS[$platform] ?? 'Formulaire';
        $summary = collect($answers)->map(fn ($v, $k) => $k.' : '.(is_array($v) ? implode(', ', $v) : $v))->implode("\n");
        if ($existing) {
            Activity::create(['lead_id' => $existing->id, 'type' => 'task', 'subject' => 'Nouvelle demande via '.$label.($campaign ? ' (« '.$campaign->title.' »)' : ''), 'due_at' => now(),
                'owner_id' => $existing->owner_id, 'notes' => Str::limit($summary, 5000, ''), 'done' => false]);
            $existing->save(); // note du prospect
            $id = DB::table('ac_leads')->insertGetId($log + ['lead_id' => $existing->id, 'status' => 'duplicate', 'owner_id' => $existing->owner_id, 'note' => 'Ajouté à l\'opportunité en cours « '.Str::limit($existing->title, 120).' »']);
            if ($existing->owner_id) {
                AppNotification::send($c->id, [$existing->owner_id], 'crm', 'Le prospect revient : '.Str::limit($name, 60), 'Nouvelle demande via '.$label.' pour une affaire en cours.', 'crm', (string) $existing->id);
            }

            return ['status' => 'duplicate', 'ac_lead_id' => $id, 'lead_id' => $existing->id];
        }

        $owner = $this->assignee($c, $source);
        $lead = new Lead([
            'title' => Str::limit($label.' — '.$name, 250, ''), 'contact_name' => $n['name'] ?? null, 'company_name' => $n['company'] ?? null, 'email' => $n['email'] ?? null, 'phone' => $n['phone'] ?? null,
            'city' => $n['city'] ?? null, 'source' => $platform === 'landing' ? 'landing' : (in_array($platform, ['website', 'zapier', 'other'], true) ? 'website' : 'ads'),
            'owner_id' => $owner, 'tags' => $source?->tags ? Str::limit($source->tags, 255, '') : null,
            'notes' => Str::limit(trim(($n['message'] ?? '')."\n\n".$summary.($meta['ad_name'] ?? null ? "\nPublicité : ".$meta['ad_name'] : '').($meta['form_name'] ?? null ? "\nFormulaire : ".$meta['form_name'] : '')), 10000, ''),
            'utm_source' => $meta['utm_source'] ?? ($platform !== 'other' && $platform !== 'landing' ? $platform : null), 'utm_medium' => $meta['utm_medium'] ?? (in_array($platform, ['meta', 'google', 'tiktok', 'linkedin'], true) ? 'paid' : null),
            'utm_campaign' => ! empty($meta['utm_campaign']) ? Str::limit(Str::slug((string) $meta['utm_campaign'], '_'), 150, '') : $campaign?->utm_campaign, 'utm_content' => $meta['utm_content'] ?? null,
        ]);
        $lead->forceFill(['ad_platform' => in_array($platform, ['meta', 'google', 'tiktok', 'linkedin'], true) ? $platform : ($campaign?->platform), 'ad_campaign_id' => $campaignId, 'ad_ref' => $ext]);
        $lead->save();
        $id = DB::table('ac_leads')->insertGetId($log + ['lead_id' => $lead->id, 'status' => 'created', 'owner_id' => $owner]);

        $this->marketing($c, $lead, $n, $source, $platform);
        $done = [];
        if ($source?->reply_channel && $source->reply_text) {
            $done[] = $this->autoReply($c, $source, $lead, $n);
        }
        if ($source?->sequence_id) {
            rescue(fn () => app(Sequences::class)->enroll($c, $lead, (int) $source->sequence_id), null, false);
        }
        if ($owner && (! $source || $source->notify)) {
            AppNotification::send($c->id, [$owner], 'crm', 'Nouveau prospect '.$label.' : '.Str::limit($name, 60),
                trim(($n['phone'] ?? '').' '.($n['email'] ?? '')).($campaign ? ' — campagne « '.Str::limit($campaign->title, 60).' »' : '').'. Rappelez-le vite : les chances baissent après 5 minutes.', 'crm', (string) $lead->id);
        }

        return ['status' => 'created', 'ac_lead_id' => $id, 'lead_id' => $lead->id, 'actions' => $done];
    }

    protected function marketing(Company $c, Lead $lead, array $n, ?object $source, string $platform): void
    {
        if (! $c->hasModule('marketing')) {
            return;
        }
        rescue(function () use ($lead, $n, $source, $platform) {
            $C = \App\Modules\Marketing\Models\Contact::class;
            $contact = ! empty($n['email']) ? $C::where('email', mb_strtolower($n['email']))->first() : null;
            $contact ??= ! empty($n['phone']) ? $C::where('phone', $n['phone'])->first() : null;
            $contact ??= new $C(['source' => 'lead']);
            [$first, $last] = str_contains((string) $n['name'], ' ') ? explode(' ', (string) $n['name'], 2) : [$n['first_name'] ?? $n['name'], $n['last_name'] ?? null];
            $contact->first_name ??= $first;
            $contact->last_name ??= $last;
            $contact->email ??= ! empty($n['email']) ? mb_strtolower($n['email']) : null;
            $contact->phone ??= $n['phone'] ?? null;
            $contact->company_name ??= $n['company'] ?? null;
            $contact->city ??= $n['city'] ?? null;
            $contact->lead_id ??= $lead->id;
            $newTags = array_filter(array_map('trim', explode(',', (string) $source?->tags)));
            if ($newTags) {
                $contact->tags = implode(', ', array_values(array_unique(array_merge($contact->tagList(), array_map('mb_strtolower', $newTags)))));
            }
            $contact->last_activity_at = now();
            $contact->save();
            $lists = [];
            if ($source?->list_id) {
                $contact->lists()->syncWithoutDetaching([(int) $source->list_id]);
                $lists[] = (int) $source->list_id;
            }
            $engine = app(\App\Modules\Marketing\AutomationEngine::class);
            if ($lists) {
                $engine->listJoined($contact, $lists);
            }
            if ($newTags) {
                $engine->tagsAdded($contact, $newTags);
            }
            $engine->leadReceived($contact, $platform);
        }, null, false);
    }

    /** Nom saisi par le prospect, nettoyé avant d'être repris dans un message (pas de lien ni de texte piégé). */
    public static function safeName(?string $v): string
    {
        $v = preg_replace('#(https?://|www\.)\S*#iu', '', (string) $v);
        $v = preg_replace("/[^\\p{L}\\p{M}\\s'’-]/u", '', (string) $v);

        return Str::limit(trim(preg_replace('/\s+/u', ' ', (string) $v)), 30, '');
    }

    protected function autoReply(Company $c, object $source, Lead $lead, array $n): string
    {
        // Garde-fous contre l'envoi en masse depuis un formulaire public : 2 réponses par numéro et par jour, 300 par jour pour la société.
        $to0 = $source->reply_channel === 'email' ? mb_strtolower((string) ($n['email'] ?? '')) : preg_replace('/\D/', '', (string) ($n['phone'] ?? ''));
        $dayKey = 'ac-reply:'.$c->id.':'.now()->toDateString();
        if (\Illuminate\Support\Facades\Cache::get($dayKey.':'.sha1($to0), 0) >= 2 || \Illuminate\Support\Facades\Cache::get($dayKey, 0) >= 300) {
            return 'réponse automatique non envoyée (limite atteinte)';
        }
        \Illuminate\Support\Facades\Cache::put($dayKey.':'.sha1($to0), \Illuminate\Support\Facades\Cache::get($dayKey.':'.sha1($to0), 0) + 1, now()->endOfDay());
        \Illuminate\Support\Facades\Cache::put($dayKey, \Illuminate\Support\Facades\Cache::get($dayKey, 0) + 1, now()->endOfDay());
        $owner = $lead->owner_id ? User::find($lead->owner_id) : null;
        $first = self::safeName($n['first_name'] ?? explode(' ', (string) $n['name'])[0]);
        $n['name'] = self::safeName($n['name'] ?? '');
        $n['company'] = self::safeName($n['company'] ?? '');
        $text = Sequences::fill((string) $source->reply_text, ['prenom' => $first, 'nom' => (string) $n['name'], 'entreprise' => $c->name, 'commercial' => $owner?->name ?? $c->name, 'societe' => (string) ($n['company'] ?? '')]);
        $ch = $source->reply_channel;
        $to = $ch === 'email' ? ($n['email'] ?? null) : ($n['phone'] ?? null);
        if (! $to) {
            return 'réponse non envoyée (coordonnée manquante)';
        }
        try {
            $m = $ch === 'email' ? new Message(to: $to, toName: $n['name'] ?? null, subject: 'Merci pour votre demande — '.$c->name, html: nl2br(e($text)), text: $text, replyTo: $owner?->email ?? $c->email)
                : new Message(to: $to, toName: $n['name'] ?? null, text: $text, templateParams: [$first ?: (string) $n['name']]);
            $r = $this->messaging->send($c, $ch, $m, 'acquisition:'.$source->id, $owner?->id);
            if ($r->ok) {
                Activity::create(['lead_id' => $lead->id, 'type' => $ch === 'email' ? 'email' : 'whatsapp', 'subject' => 'Réponse automatique envoyée ('.AcquisitionHooks::REPLY_CHANNELS[$ch].')', 'notes' => $text,
                    'due_at' => now(), 'done' => true, 'done_at' => now(), 'owner_id' => $lead->owner_id]);
            }

            return $r->ok ? 'réponse '.$ch.' envoyée' : 'réponse '.$ch.' non envoyée : '.Str::limit((string) $r->error, 80);
        } catch (\Throwable $e) {
            return 'réponse non envoyée : '.Str::limit($e->getMessage(), 80);
        }
    }
}
