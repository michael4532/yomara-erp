<?php

namespace App\Modules\Visio;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\Company;
use App\Models\User;
use App\Modules\Visio\Models\Meeting;
use App\Modules\Visio\Models\Participant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Visioconférence : réglages des fournisseurs, choix et bascule automatique entre ZEGOCLOUD, Agora et le mode
 * pair-à-pair de secours, présence et consommation, invitations.
 *
 * Bascule : chaque salle a un fournisseur actif et une « époque ». Quand un fournisseur échoue (connexion refusée,
 * crédit épuisé, quota mensuel atteint) ou devient instable pour plusieurs participants, le serveur choisit le suivant
 * dans l'ordre de priorité et incrémente l'époque ; chaque navigateur le voit au prochain battement (2 s) et se
 * reconnecte sur le nouveau fournisseur en gardant caméra, micro, conversation et enregistrement.
 */
class VisioService
{
    public const PROVIDERS = ['zego' => 'ZEGOCLOUD', 'agora' => 'Agora', 'p2p' => 'Pair-à-pair (secours)'];

    public const DEFAULTS = ['lobby' => false, 'guests' => true, 'mute_on_entry' => false, 'chat' => true, 'screen' => true, 'transcribe' => true,
        'record_auto' => false, 'ai_report' => true, 'send_report' => true, 'reactions' => true];

    /** Au-delà, le maillage pair-à-pair n'est plus raisonnable (chaque navigateur envoie sa vidéo à chacun). */
    public const P2P_MAX = 6;

    /** Identifiant Agora de la connexion « partage d'écran » d'un participant : SCREEN_UID + id. */
    public const SCREEN_UID = 100000000;

    // ---------------------------------------------------------------- Réglages

    public function settings(Company $c): array
    {
        $row = DB::table('vis_settings')->where('company_id', $c->id)->first();
        $dec = fn ($v) => $v ? rescue(fn () => Crypt::decryptString($v), null, false) : null;
        $ownZego = $row && $row->zego_app_id && $row->zego_secret;
        $ownAgora = $row && $row->agora_app_id && $row->agora_certificate;
        $cfg = config('erp.visio', []);

        return [
            'zego' => [
                'own' => (bool) $ownZego,
                'app_id' => $ownZego ? $row->zego_app_id : ($cfg['zego_app_id'] ?? null),
                'secret' => $ownZego ? $dec($row->zego_secret) : ($cfg['zego_secret'] ?? null),
                'server' => ($ownZego ? $row->zego_server : null) ?: ($cfg['zego_server'] ?? null),
                'budget' => (int) ($row->zego_budget_minutes ?? 0),
            ],
            'agora' => [
                'own' => (bool) $ownAgora,
                'app_id' => $ownAgora ? $row->agora_app_id : ($cfg['agora_app_id'] ?? null),
                'certificate' => $ownAgora ? $dec($row->agora_certificate) : ($cfg['agora_certificate'] ?? null),
                'budget' => (int) ($row->agora_budget_minutes ?? 0),
            ],
            'priority' => $this->priority($row->priority ?? null),
            'p2p_fallback' => $row ? (bool) $row->p2p_fallback : true,
            'turn' => $row && $row->turn_url ? ['url' => $row->turn_url, 'username' => $row->turn_username, 'credential' => $dec($row->turn_credential)] : null,
            'defaults' => array_merge(self::DEFAULTS, (array) json_decode((string) ($row->defaults ?? ''), true)),
        ];
    }

    protected function priority(?string $p): array
    {
        $list = array_values(array_intersect(array_map('trim', explode(',', (string) ($p ?: 'zego,agora,p2p'))), array_keys(self::PROVIDERS)));
        foreach (array_keys(self::PROVIDERS) as $k) {
            if (! in_array($k, $list, true)) {
                $list[] = $k;
            }
        }

        return $list;
    }

    public function configured(array $s, string $provider): bool
    {
        return match ($provider) {
            'zego' => (bool) ($s['zego']['app_id'] && $s['zego']['secret']),
            'agora' => (bool) ($s['agora']['app_id'] && $s['agora']['certificate']),
            'p2p' => (bool) $s['p2p_fallback'],
            default => false,
        };
    }

    public function publicSettings(Company $c): array
    {
        $s = $this->settings($c);
        $month = now()->format('Y-m');
        $usage = DB::table('vis_usage')->where('company_id', $c->id)->where('month', $month)->pluck('seconds', 'provider');
        $health = DB::table('vis_provider_health')->where('company_id', $c->id)->get()->keyBy('provider');
        $mask = fn ($v) => $v ? '••••'.mb_substr((string) $v, -4) : null;
        $providers = [];
        foreach (self::PROVIDERS as $k => $label) {
            $h = $health[$k] ?? null;
            $providers[] = [
                'key' => $k, 'name' => $label, 'configured' => $this->configured($s, $k), 'own' => $s[$k]['own'] ?? false,
                'minutes' => (int) round(($usage[$k] ?? 0) / 60), 'budget' => $s[$k]['budget'] ?? 0,
                'down' => $h && $h->down_until && Carbon::parse($h->down_until)->isFuture(), 'down_until' => $h?->down_until,
                'last_reason' => $h?->last_reason, 'failures' => (int) ($h?->failures ?? 0),
            ];
        }

        return [
            'priority' => $s['priority'], 'providers' => $providers, 'p2p_fallback' => $s['p2p_fallback'],
            'zego_app_id' => $s['zego']['own'] ? $s['zego']['app_id'] : null, 'zego_secret_hint' => $s['zego']['own'] ? $mask($s['zego']['secret']) : null,
            'zego_server' => $s['zego']['own'] ? $s['zego']['server'] : null,
            'agora_app_id' => $s['agora']['own'] ? $s['agora']['app_id'] : null, 'agora_certificate_hint' => $s['agora']['own'] ? $mask($s['agora']['certificate']) : null,
            'zego_budget_minutes' => $s['zego']['budget'], 'agora_budget_minutes' => $s['agora']['budget'],
            'platform' => ['zego' => (bool) (config('erp.visio.zego_app_id') && config('erp.visio.zego_secret')), 'agora' => (bool) (config('erp.visio.agora_app_id') && config('erp.visio.agora_certificate'))],
            'turn_url' => $s['turn']['url'] ?? null, 'turn_username' => $s['turn']['username'] ?? null,
            'defaults' => $s['defaults'],
            'switches_month' => (int) DB::table('vis_usage')->where('company_id', $c->id)->where('month', $month)->sum('switches'),
        ];
    }

    public function saveSettings(Company $c, array $d): array
    {
        $row = ['updated_at' => now()];
        foreach (['zego_app_id', 'zego_server', 'agora_app_id', 'turn_url', 'turn_username'] as $k) {
            if (array_key_exists($k, $d)) {
                $row[$k] = $d[$k] ?: null;
            }
        }
        foreach (['zego_secret', 'agora_certificate', 'turn_credential'] as $k) {
            if (array_key_exists($k, $d) && $d[$k] !== null && ! str_starts_with((string) $d[$k], '••••')) {
                $row[$k] = $d[$k] === '' ? null : Crypt::encryptString(trim($d[$k]));
            }
        }
        foreach (['zego_budget_minutes', 'agora_budget_minutes'] as $k) {
            if (array_key_exists($k, $d)) {
                $row[$k] = max(0, (int) $d[$k]);
            }
        }
        if (array_key_exists('p2p_fallback', $d)) {
            $row['p2p_fallback'] = (bool) $d['p2p_fallback'];
        }
        if (! empty($d['priority'])) {
            $row['priority'] = implode(',', $this->priority(is_array($d['priority']) ? implode(',', $d['priority']) : $d['priority']));
        }
        if (isset($d['defaults']) && is_array($d['defaults'])) {
            $row['defaults'] = json_encode(array_intersect_key(array_map(fn ($v) => (bool) $v, $d['defaults']), self::DEFAULTS));
        }
        if (! empty($row['agora_app_id']) && ! preg_match('/^[0-9a-f]{32}$/i', $row['agora_app_id'])) {
            throw new \DomainException('L\'App ID Agora compte 32 caractères hexadécimaux.');
        }
        if (! empty($row['zego_app_id']) && ! ctype_digit((string) $row['zego_app_id'])) {
            throw new \DomainException('L\'AppID ZEGOCLOUD est un nombre.');
        }
        DB::table('vis_settings')->updateOrInsert(['company_id' => $c->id], $row + ['created_at' => now()]);
        // Nouveaux identifiants : on redonne sa chance au fournisseur
        foreach (['zego', 'agora'] as $p) {
            if (array_key_exists($p.'_app_id', $d) || array_key_exists($p === 'zego' ? 'zego_secret' : 'agora_certificate', $d)) {
                DB::table('vis_provider_health')->where('company_id', $c->id)->where('provider', $p)->update(['down_until' => null, 'failures' => 0]);
            }
        }

        return $this->publicSettings($c);
    }

    /** Teste les identifiants (format et génération de jeton) sans ouvrir de salle. */
    public function test(Company $c, string $provider): array
    {
        $s = $this->settings($c);
        if (! $this->configured($s, $provider)) {
            return ['ok' => false, 'message' => 'Fournisseur non configuré.'];
        }
        try {
            match ($provider) {
                'zego' => Tokens::zego((int) $s['zego']['app_id'], (string) $s['zego']['secret'], 'test', 60),
                'agora' => Tokens::agora((string) $s['agora']['app_id'], (string) $s['agora']['certificate'], 'test', 1, 60),
                default => null,
            };
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return ['ok' => true, 'message' => 'Identifiants valides : les jetons de connexion sont générés correctement.'];
    }

    // ---------------------------------------------------------------- Minutes Yomeet (forfaits)

    /** Minutes offertes chaque mois avec le module et forfaits vendus par la plateforme (réglables par le super admin). */
    public const DEFAULT_PACKS = [
        ['code' => 'm1000', 'minutes' => 1000, 'price' => 5000, 'label' => 'Forfait 1 000 minutes'],
        ['code' => 'm5000', 'minutes' => 5000, 'price' => 20000, 'label' => 'Forfait 5 000 minutes'],
        ['code' => 'm15000', 'minutes' => 15000, 'price' => 50000, 'label' => 'Forfait 15 000 minutes'],
        ['code' => 'm50000', 'minutes' => 50000, 'price' => 150000, 'label' => 'Forfait 50 000 minutes'],
    ];

    public static function includedMinutes(): int
    {
        return (int) \App\Models\PlatformSetting::get('visio.included_minutes', 300);
    }

    public static function packs(): array
    {
        $p = (array) \App\Models\PlatformSetting::get('visio.packs', self::DEFAULT_PACKS);

        return array_values(array_filter($p, fn ($x) => ! empty($x['code']) && (int) ($x['minutes'] ?? 0) > 0));
    }

    /** Minutes consommées ce mois sur l'infrastructure de la plateforme (hors comptes propres et pair-à-pair). */
    public function managedMinutes(Company $c): int
    {
        $s = $this->settings($c);
        $providers = array_values(array_filter(['zego', 'agora'], fn ($p) => ! ($s[$p]['own'] ?? false)));

        return (int) floor(DB::table('vis_usage')->where('company_id', $c->id)->where('month', now()->format('Y-m'))->whereIn('provider', $providers ?: ['-'])->sum('seconds') / 60);
    }

    /** Solde de minutes du mois : incluses + forfaits en cours − consommées. */
    public function quota(Company $c): array
    {
        $credits = Schema::hasTable('vis_credits') ? DB::table('vis_credits')->where('company_id', $c->id)->where('starts_at', '<=', now())->where('ends_at', '>', now())->get() : collect();
        $included = self::includedMinutes();
        $purchased = (int) $credits->sum('minutes');
        $used = $this->managedMinutes($c);
        $allowance = $included + $purchased;

        return [
            'included' => $included, 'purchased' => $purchased, 'allowance' => $allowance, 'used' => $used, 'remaining' => max(0, $allowance - $used),
            'percent' => $allowance > 0 ? min(100, (int) round($used * 100 / $allowance)) : 100, 'exhausted' => $used >= $allowance,
            'credits' => $credits->map(fn ($x) => ['minutes' => (int) $x->minutes, 'until' => (string) $x->ends_at, 'label' => $x->label])->values()->all(),
            'packs' => self::packs(), 'p2p_max' => self::P2P_MAX,
        ];
    }

    /** Forfait payé : minutes valables un mois à partir du paiement (renouvelable). */
    public function addCredit(int $companyId, int $minutes, ?int $orderId = null, ?string $label = null, string $source = 'purchase'): void
    {
        DB::table('vis_credits')->insert(['company_id' => $companyId, 'minutes' => $minutes, 'starts_at' => now(), 'ends_at' => now()->addMonthNoOverflow(),
            'source' => $source, 'order_id' => $orderId, 'label' => $label, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ---------------------------------------------------------------- Choix du fournisseur et bascule

    public function minutesUsed(Company $c, string $provider): int
    {
        return (int) floor(DB::table('vis_usage')->where('company_id', $c->id)->where('provider', $provider)->where('month', now()->format('Y-m'))->value('seconds') / 60);
    }

    public function available(Company $c, array $s, string $provider, int $people = 1): bool
    {
        if (! $this->configured($s, $provider)) {
            return false;
        }
        if ($provider === 'p2p') {
            return $people <= self::P2P_MAX;
        }
        $h = DB::table('vis_provider_health')->where('company_id', $c->id)->where('provider', $provider)->first();
        if ($h && $h->down_until && Carbon::parse($h->down_until)->isFuture()) {
            return false;
        }
        $budget = $s[$provider]['budget'] ?? 0;
        if ($budget > 0 && $this->minutesUsed($c, $provider) >= $budget) {
            return false;
        }
        // Infrastructure de la plateforme : dans la limite des minutes du mois (sinon pair-à-pair jusqu'à 6 personnes)
        if (! ($s[$provider]['own'] ?? false) && $this->quota($c)['exhausted']) {
            return false;
        }

        return true;
    }

    public function choose(Company $c, Meeting $m, array $exclude = []): ?string
    {
        $s = $this->settings($c);
        $people = $this->expectedPeople($m);
        foreach ($s['priority'] as $p) {
            if (! in_array($p, $exclude, true) && $this->available($c, $s, $p, $people)) {
                return $p;
            }
        }
        // Tout est en panne : on retente le moins récemment tombé plutôt que de laisser la salle vide
        foreach ($s['priority'] as $p) {
            if ($p !== 'p2p' && ! in_array($p, $exclude, true) && $this->configured($s, $p)) {
                return $p;
            }
        }
        if ($this->configured($s, 'p2p') && $people <= self::P2P_MAX) {
            return 'p2p';
        }

        return null;
    }

    protected function expectedPeople(Meeting $m): int
    {
        $online = Participant::where('meeting_id', $m->id)->where('status', 'admitted')->where('last_seen_at', '>', now()->subSeconds(25))->count();

        return max(1, $online + 1);
    }

    public function ensureProvider(Company $c, Meeting $m): Meeting
    {
        if (! $m->provider) {
            $p = $this->choose($c, $m);
            if (! $p) {
                throw new \DomainException('La visioconférence n\'est pas configurée : renseignez ZEGOCLOUD ou Agora dans les paramètres du module.');
            }
            $m->forceFill(['provider' => $p])->save();
        }

        return $m;
    }

    /** Bascule de la salle vers le fournisseur suivant (idempotent : ignorée si l'époque a déjà changé). */
    public function switchProvider(Company $c, Meeting $m, string $reason, ?int $fromEpoch = null, bool $markDown = true, int $downMinutes = 15): Meeting
    {
        return DB::transaction(function () use ($c, $m, $reason, $fromEpoch, $markDown, $downMinutes) {
            $m = Meeting::withoutGlobalScopes()->lockForUpdate()->find($m->id);
            if ($fromEpoch !== null && $fromEpoch !== $m->epoch) {
                return $m;
            }
            $from = $m->provider;
            if ($markDown && $from && $from !== 'p2p') {
                $this->markDown($c, $from, $reason, $downMinutes);
            }
            $next = $this->choose($c, $m, array_filter([$from]));
            if (! $next || $next === $from) {
                return $m;
            }
            $m->forceFill(['provider' => $next, 'epoch' => $m->epoch + 1, 'switches' => $m->switches + 1])->save();
            DB::table('vis_feed')->insert(['meeting_id' => $m->id, 'kind' => 'system', 'body' => 'switch', 'data' => json_encode(['from' => $from, 'to' => $next, 'reason' => $reason, 'epoch' => $m->epoch]),
                'created_at' => now()]);
            $this->addUsage($c->id, $next, 0, 1);
            Log::info("Visio : bascule de la salle {$m->code} de {$from} vers {$next} ({$reason})");

            return $m;
        });
    }

    public function markDown(Company $c, string $provider, string $reason, int $minutes = 15): void
    {
        $h = DB::table('vis_provider_health')->where('company_id', $c->id)->where('provider', $provider)->first();
        $failures = (int) ($h->failures ?? 0) + 1;
        // Pannes répétées : mise à l'écart plus longue (15 min, 30 min, 1 h…)
        $until = now()->addMinutes(min(240, $minutes * (2 ** min(4, max(0, $failures - 1)))));
        DB::table('vis_provider_health')->updateOrInsert(['company_id' => $c->id, 'provider' => $provider],
            ['failures' => $failures, 'down_until' => $until, 'last_reason' => mb_substr($reason, 0, 190), 'last_failure_at' => now(), 'updated_at' => now(), 'created_at' => $h->created_at ?? now()]);
    }

    public function markHealthy(Company $c, string $provider): void
    {
        DB::table('vis_provider_health')->where('company_id', $c->id)->where('provider', $provider)->where('failures', '>', 0)
            ->where(fn ($q) => $q->whereNull('down_until')->orWhere('down_until', '<', now()))->update(['failures' => 0, 'updated_at' => now()]);
    }

    /**
     * Signalement d'un navigateur. Échec de connexion (jeton refusé, crédit épuisé, service injoignable) : bascule
     * immédiate. Instabilité : bascule quand plusieurs participants la constatent (un seul suffit à deux), pour ne pas
     * déplacer toute la salle à cause de la connexion d'une seule personne.
     */
    public function reportFailure(Company $c, Meeting $m, Participant $p, string $provider, int $epoch, string $kind, ?string $detail = null): Meeting
    {
        if ($epoch !== $m->epoch || $provider !== $m->provider) {
            return $m;
        }
        $reason = ['join' => 'connexion refusée', 'unstable' => 'connexion instable', 'disconnected' => 'déconnexions répétées', 'media' => 'médias bloqués'][$kind] ?? $kind;
        if ($detail) {
            $reason .= ' : '.mb_substr($detail, 0, 120);
        }
        DB::table('vis_feed')->insert(['meeting_id' => $m->id, 'participant_id' => $p->id, 'kind' => 'health', 'body' => $kind,
            'data' => json_encode(['provider' => $provider, 'epoch' => $epoch, 'detail' => $detail]), 'created_at' => now()]);
        if ($kind === 'join') {
            return $this->switchProvider($c, $m, $reason, $epoch);
        }
        $reporters = DB::table('vis_feed')->where('meeting_id', $m->id)->where('kind', 'health')->where('created_at', '>=', now()->subSeconds(60))
            ->get(['participant_id', 'data'])->filter(fn ($r) => (json_decode($r->data, true)['epoch'] ?? -1) === $epoch)->pluck('participant_id')->unique()->count();
        $online = Participant::where('meeting_id', $m->id)->where('status', 'admitted')->where('last_seen_at', '>', now()->subSeconds(25))->count();
        $threshold = $online <= 2 ? 1 : 2;
        if ($reporters >= $threshold) {
            return $this->switchProvider($c, $m, $reason, $epoch, true, 10);
        }

        return $m;
    }

    /** Paramètres de connexion du navigateur pour le fournisseur actif (jetons à durée limitée). */
    public function credentials(Company $c, Meeting $m, Participant $p): array
    {
        $s = $this->settings($c);
        $room = 'yv'.$m->id;
        $base = ['provider' => $m->provider, 'epoch' => $m->epoch, 'publish' => $p->canPublish()];
        $ice = [['urls' => ['stun:stun.l.google.com:19302', 'stun:global.stun.twilio.com:3478']]];
        if ($s['turn']) {
            $ice[] = ['urls' => $s['turn']['url'], 'username' => $s['turn']['username'], 'credential' => $s['turn']['credential']];
        }

        return match ($m->provider) {
            'zego' => $base + [
                'app_id' => (int) $s['zego']['app_id'], 'room' => $room, 'user_id' => 'p'.$p->id, 'user_name' => $p->name,
                'token' => Tokens::zego((int) $s['zego']['app_id'], (string) $s['zego']['secret'], 'p'.$p->id, 6 * 3600),
                'server' => $s['zego']['server'] ?: 'wss://webliveroom'.$s['zego']['app_id'].'-api.zegocloud.com/ws',
                'stream_prefix' => $room.'e'.$m->epoch,
            ],
            'agora' => $base + [
                'app_id' => $s['agora']['app_id'], 'channel' => $room, 'uid' => $p->id, 'mode' => $m->isWebinar() ? 'live' : 'rtc',
                'token' => Tokens::agora((string) $s['agora']['app_id'], (string) $s['agora']['certificate'], $room, $p->id, 6 * 3600, $p->canPublish() || $m->isWebinar()),
                // Le partage d'écran passe par une seconde connexion (Agora : une seule vidéo publiée par connexion)
                'screen_uid' => self::SCREEN_UID + $p->id,
                'screen_token' => Tokens::agora((string) $s['agora']['app_id'], (string) $s['agora']['certificate'], $room, self::SCREEN_UID + $p->id, 6 * 3600, true),
            ],
            'p2p' => $base + ['ice' => $ice],
            default => $base,
        };
    }

    // ---------------------------------------------------------------- Présence et consommation

    public function addUsage(int $companyId, string $provider, int $seconds, int $switches = 0): void
    {
        $month = now()->format('Y-m');
        $updated = DB::table('vis_usage')->where('company_id', $companyId)->where('provider', $provider)->where('month', $month)
            ->update(['seconds' => DB::raw('seconds + '.(int) $seconds), 'switches' => DB::raw('switches + '.(int) $switches), 'updated_at' => now()]);
        if (! $updated) {
            DB::table('vis_usage')->insertOrIgnore(['company_id' => $companyId, 'provider' => $provider, 'month' => $month, 'seconds' => $seconds, 'switches' => $switches, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Battement de présence : temps de présence du participant et minutes consommées chez le fournisseur actif. */
    public function heartbeat(Company $c, Meeting $m, Participant $p): void
    {
        if ($p->status !== 'admitted') {
            $p->forceFill(['last_seen_at' => now()])->save();

            return;
        }
        $delta = $p->last_seen_at && $p->left_at === null ? min(30, max(0, (int) $p->last_seen_at->diffInSeconds(now(), true))) : 0;
        $p->forceFill(['last_seen_at' => now(), 'seconds_total' => $p->seconds_total + $delta])->save();
        if ($delta && $m->provider && $m->provider !== 'p2p') {
            $this->addUsage($c->id, $m->provider, $delta);
            // Quota mensuel atteint pendant la réunion : on change de fournisseur avant la coupure
            $s = $this->settings($c);
            $budget = $s[$m->provider]['budget'] ?? 0;
            if ($budget > 0 && $this->minutesUsed($c, $m->provider) >= $budget) {
                $this->switchProvider($c, $m, 'quota mensuel atteint', $m->epoch, false);
            }
        }
    }

    public function admit(Meeting $m, Participant $p): void
    {
        $p->forceFill(['status' => 'admitted', 'joined_at' => now(), 'first_joined_at' => $p->first_joined_at ?? now(), 'left_at' => null, 'last_seen_at' => now(),
            'mic' => ! $m->opt('mute_on_entry') && $p->canPublish(), 'cam' => $p->canPublish() && ! $m->isWebinar() || in_array($p->role, ['host', 'cohost', 'panelist'], true)])->save();
        if ($m->status === 'scheduled') {
            $m->forceFill(['status' => 'live', 'started_at' => $m->started_at ?? now()])->save();
        }
        $this->system($m, 'joined', $p);
    }

    public function system(Meeting $m, string $what, ?Participant $p = null, array $data = []): void
    {
        DB::table('vis_feed')->insert(['meeting_id' => $m->id, 'participant_id' => $p?->id, 'kind' => 'system', 'body' => $what,
            'data' => json_encode($data + ['name' => $p?->name]), 'created_at' => now()]);
    }

    public function leave(Meeting $m, Participant $p): void
    {
        if ($p->status === 'admitted') {
            $p->forceFill(['status' => 'left', 'left_at' => now(), 'screen' => false, 'hand_at' => null])->save();
            $this->system($m, 'left', $p);
        }
    }

    // ---------------------------------------------------------------- Réunions

    public static function code(): string
    {
        $a = 'abcdefghijkmnopqrstuvwxyz';
        do {
            $r = fn ($n) => implode('', array_map(fn () => $a[random_int(0, strlen($a) - 1)], range(1, $n)));
            $code = $r(3).'-'.$r(4).'-'.$r(3);
        } while (DB::table('vis_meetings')->where('code', $code)->exists());

        return $code;
    }

    public static function token(): string
    {
        return Str::random(40);
    }

    public function participantFor(Meeting $m, User $u, string $role = 'member'): Participant
    {
        $p = Participant::where('meeting_id', $m->id)->where('user_id', $u->id)->first();
        if (! $p) {
            $p = Participant::create(['meeting_id' => $m->id, 'company_id' => $m->company_id, 'user_id' => $u->id, 'name' => $u->name, 'email' => $u->email,
                'role' => $m->host_id === $u->id ? 'host' : $role, 'token' => self::token(), 'status' => 'invited']);
        } elseif ($m->host_id === $u->id && $p->role !== 'host') {
            $p->forceFill(['role' => 'host'])->save();
        }

        return $p;
    }

    /** Synchronise la liste des invités (utilisateurs internes et personnes externes). */
    public function syncInvitees(Meeting $m, array $userIds, array $guests, ?string $guestRole = null): array
    {
        $new = [];
        $host = $m->host_id ? User::find($m->host_id) : null;
        if ($host) {
            $this->participantFor($m, $host, 'host');
        }
        foreach (User::where('company_id', $m->company_id)->whereIn('id', $userIds)->where('is_active', true)->get() as $u) {
            $exists = Participant::where('meeting_id', $m->id)->where('user_id', $u->id)->exists();
            $p = $this->participantFor($m, $u, $m->isWebinar() ? 'panelist' : 'member');
            if (! $exists) {
                $new[] = $p;
            }
        }
        foreach ($guests as $g) {
            $email = mb_strtolower(trim((string) ($g['email'] ?? '')));
            $name = trim((string) ($g['name'] ?? '')) ?: ($email ? Str::before($email, '@') : null);
            if (! $name) {
                continue;
            }
            $p = $email ? Participant::where('meeting_id', $m->id)->where('email', $email)->first() : null;
            if (! $p) {
                $new[] = Participant::create(['meeting_id' => $m->id, 'company_id' => $m->company_id, 'name' => mb_substr($name, 0, 120), 'email' => $email ?: null,
                    'phone' => $g['phone'] ?? null, 'organization' => $g['organization'] ?? null,
                    'role' => $g['role'] ?? $guestRole ?? ($m->isWebinar() ? 'panelist' : 'guest'), 'token' => self::token(), 'status' => 'invited']);
            }
        }

        return $new;
    }

    public function ics(Meeting $m, Company $c, ?Participant $p = null, string $method = 'REQUEST'): string
    {
        $esc = fn ($s) => str_replace(['\\', ',', ';', "\r\n", "\n"], ['\\\\', '\\,', '\;', '\\n', '\\n'], (string) $s);
        $start = $m->starts_at ?? now();
        $end = $m->endsAt() ?? $start->copy()->addHour();
        $url = $m->roomUrl($p?->token);
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Yomara ERP//Reunions//FR', 'METHOD:'.$method, 'BEGIN:VEVENT',
            'UID:visio-'.$m->id.'@'.parse_url(url('/'), PHP_URL_HOST), 'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$start->copy()->utc()->format('Ymd\THis\Z'), 'DTEND:'.$end->copy()->utc()->format('Ymd\THis\Z'),
            'SUMMARY:'.$esc($m->title), 'LOCATION:'.$esc($url), 'URL:'.$url,
            'DESCRIPTION:'.$esc(trim(($m->description ? $m->description."\n\n" : '').'Rejoindre : '.$url.($m->password ? "\nCode d'accès : ".$m->password : ''))),
            'ORGANIZER;CN='.$esc($c->name).':mailto:'.($c->email ?: 'no-reply@'.parse_url(url('/'), PHP_URL_HOST)),
            'STATUS:'.($m->status === 'cancelled' ? 'CANCELLED' : 'CONFIRMED'),
            'BEGIN:VALARM', 'TRIGGER:-PT10M', 'ACTION:DISPLAY', 'DESCRIPTION:'.$esc($m->title), 'END:VALARM',
            'END:VEVENT', 'END:VCALENDAR', ''];

        return implode("\r\n", $lines);
    }

    /** Invitations par e-mail avec lien personnel et fichier calendrier. Renvoie le nombre d'envois réussis. */
    public function sendInvitations(Company $c, Meeting $m, iterable $participants, ?int $userId = null): int
    {
        $messaging = app(MessagingService::class);
        if (! ($messaging->available($c)['email'] ?? null)) {
            return 0;
        }
        $sent = 0;
        $host = $m->host_id ? User::find($m->host_id) : null;
        foreach ($participants as $p) {
            if (! $p->email || $p->role === 'host') {
                continue;
            }
            $when = $m->starts_at ? $m->starts_at->locale('fr')->isoFormat('dddd D MMMM YYYY [à] HH:mm') : 'dès maintenant';
            $btn = 'background:#2563EB;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block';
            $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;line-height:1.55;color:#1d2433">'
                .'<p>Bonjour '.e($p->name).',</p>'
                .'<p>'.e($host?->name ?? $c->name).' vous invite '.($m->isWebinar() ? 'à intervenir au webinaire' : 'à la réunion').' <b>« '.e($m->title).' »</b>, '.e($when).' ('.$m->duration_minutes.' min).</p>'
                .($m->description ? '<p style="color:#475569">'.nl2br(e($m->description)).'</p>' : '')
                .'<p style="margin:26px 0"><a href="'.e($m->roomUrl($p->token)).'" style="'.$btn.'">Rejoindre la réunion</a></p>'
                .($m->password ? '<p>Code d\'accès : <b>'.e($m->password).'</b></p>' : '')
                .'<p style="color:#64748B;font-size:12px">Aucune installation nécessaire : la réunion s\'ouvre dans votre navigateur (Chrome, Edge, Safari, Firefox), sur ordinateur ou téléphone. Le fichier joint ajoute la réunion à votre calendrier.</p>'
                .'<p style="color:#94A3B8;font-size:11px;margin-top:22px">'.e($c->name).' — Yomara ERP</p></div>';
            try {
                $res = $messaging->send($c, 'email', new Message(to: $p->email, toName: $p->name, subject: ($m->isWebinar() ? 'Intervenant : ' : 'Invitation : ').$m->title.' — '.($m->starts_at?->format('d/m H:i') ?? 'maintenant'),
                    html: $html, attachments: [['name' => 'invitation.ics', 'data' => $this->ics($m, $c, $p), 'mime' => 'text/calendar; method=REQUEST; charset=utf-8']]), 'visio', $userId);
                if ($res->ok) {
                    $sent++;
                    $p->forceFill(['invited_at' => now()])->save();
                }
            } catch (\Throwable $e) {
                Log::warning('Visio : invitation non envoyée à '.$p->email.' : '.$e->getMessage());
            }
        }

        return $sent;
    }

    /** Événement d'agenda lié (module Agenda). */
    public function syncAgenda(Company $c, Meeting $m): void
    {
        if (! $c->hasModule('agenda') || ! $m->starts_at || ! class_exists(\App\Modules\Agenda\Models\Event::class)) {
            return;
        }
        rescue(function () use ($c, $m) {
            $e = $m->agenda_event_id ? \App\Modules\Agenda\Models\Event::withoutGlobalScopes()->find($m->agenda_event_id) : null;
            $e ??= new \App\Modules\Agenda\Models\Event;
            $users = Participant::where('meeting_id', $m->id)->whereNotNull('user_id')->pluck('user_id')->map(fn ($v) => (int) $v)->values()->all();
            $guests = Participant::where('meeting_id', $m->id)->whereNull('user_id')->whereIn('role', ['guest', 'panelist', 'cohost'])->get()
                ->map(fn ($p) => ['name' => $p->name, 'email' => $p->email, 'phone' => $p->phone])->values()->all();
            $e->fill(['title' => ($m->isWebinar() ? 'Webinaire : ' : '').$m->title, 'description' => $m->description, 'location' => 'Visioconférence', 'online_link' => $m->roomUrl(),
                'starts_at' => $m->starts_at, 'ends_at' => $m->endsAt(), 'category' => $m->isWebinar() ? 'training' : 'meeting', 'attendee_ids' => $users, 'guests' => $guests,
                'reminder_minutes' => 10, 'status' => $m->status === 'cancelled' ? 'cancelled' : 'confirmed']);
            $e->forceFill(['company_id' => $c->id, 'owner_id' => $m->host_id]);
            $e->save();
            if ($m->agenda_event_id !== $e->id) {
                $m->forceFill(['agenda_event_id' => $e->id])->saveQuietly();
            }
        }, null, false);
    }

    public function end(Company $c, Meeting $m, ?Participant $by = null): Meeting
    {
        if ($m->status === 'ended') {
            return $m;
        }
        foreach (Participant::where('meeting_id', $m->id)->whereIn('status', ['admitted', 'lobby'])->get() as $p) {
            $p->forceFill(['status' => $p->status === 'lobby' ? 'invited' : 'left', 'left_at' => now(), 'screen' => false, 'hand_at' => null])->save();
        }
        DB::table('vis_recordings')->where('meeting_id', $m->id)->where('status', 'recording')->update(['status' => 'ready', 'updated_at' => now()]);
        $m->forceFill(['status' => 'ended', 'ended_at' => now(), 'recording_state' => null, 'started_at' => $m->started_at ?? now()])->save();
        $this->system($m, 'ended', $by);

        return $m;
    }

    /** Réunion instantanée ou planifiée de nouveau : remet la salle en service. */
    public function reopen(Meeting $m): Meeting
    {
        if (in_array($m->status, ['ended', 'cancelled'], true)) {
            $m->forceFill(['status' => 'scheduled', 'ended_at' => null, 'locked' => false])->save();
        }

        return $m;
    }
}
