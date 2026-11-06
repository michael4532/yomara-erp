<?php

namespace App\Modules\Contenus;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Règles du service marketing : circuit de validation des publications, budgets et retour sur investissement. */
class ContenusHooks
{
    public const PLAN_STATUSES = ['draft' => ['Brouillon', '#64748B'], 'active' => ['En cours', '#16A34A'], 'done' => ['Terminée', '#1F6FEB']];

    public const POST_STATUSES = [
        'idea' => ['Idée', '#64748B'], 'writing' => ['En rédaction', '#D97706'], 'review' => ['À valider', '#9333EA'],
        'approved' => ['Validée', '#1F6FEB'], 'published' => ['Publiée', '#16A34A'], 'cancelled' => ['Annulée', '#94A3B8'],
    ];

    public const NETWORKS = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn', 'x' => 'X (Twitter)',
        'youtube' => 'YouTube', 'whatsapp' => 'Statut WhatsApp', 'site' => 'Site / blog', 'newsletter' => 'Newsletter'];

    public const CHANNELS = self::NETWORKS + ['google_ads' => 'Google Ads', 'meta_ads' => 'Publicité Facebook / Instagram', 'radio' => 'Radio', 'tv' => 'Télévision',
        'print' => 'Presse écrite', 'billboard' => 'Affichage', 'event' => 'Salon / événement', 'sms' => 'SMS', 'email' => 'E-mailing', 'influence' => 'Influenceurs', 'field' => 'Terrain / street marketing'];

    public const FORMATS = ['post' => 'Post image', 'carousel' => 'Carrousel', 'video' => 'Vidéo / reel', 'story' => 'Story', 'live' => 'Direct', 'article' => 'Article', 'newsletter' => 'Newsletter'];

    public const ACTION_STATUSES = ['planned' => ['Prévue', '#64748B'], 'running' => ['En cours', '#D97706'], 'done' => ['Terminée', '#16A34A'], 'cancelled' => ['Annulée', '#94A3B8']];

    public const LEVELS = ['low' => ['Faible', '#16A34A'], 'medium' => ['Moyen', '#D97706'], 'high' => ['Fort', '#DC2626']];

    public const POSITIONING = ['low' => 'Entrée de gamme', 'mid' => 'Milieu de gamme', 'high' => 'Haut de gamme'];

    public const OBS_KINDS = ['price' => 'Prix / promotion', 'product' => 'Nouveau produit', 'campaign' => 'Campagne publicitaire', 'post' => 'Publication', 'event' => 'Événement',
        'hiring' => 'Recrutement', 'partnership' => 'Partenariat', 'review' => 'Avis clients', 'other' => 'Autre'];

    public const PRESS_KINDS = ['release' => 'Communiqué de presse', 'interview' => 'Interview', 'article' => 'Article / reportage', 'partnership' => 'Partenariat média',
        'internal' => 'Communication interne', 'event' => 'Événement / conférence'];

    public const PRESS_STATUSES = ['todo' => ['À préparer', '#64748B'], 'sent' => ['Envoyé', '#D97706'], 'published' => ['Paru', '#16A34A'], 'cancelled' => ['Annulé', '#94A3B8']];

    public function saving(string $res, array $data, ?array $old, User $u): array
    {
        if ($res === 'posts') {
            $to = $data['status'] ?? null;
            if ($to && $to !== ($old['status'] ?? null) && in_array($to, ['approved', 'published'], true) && ($old['status'] ?? null) !== 'approved') {
                if (! $u->hasPermission('contenus.validate')) {
                    throw new \DomainException('Vous n\'avez pas le droit de valider les publications : passez-la « À valider », un responsable la validera.');
                }
                $data['validator_id'] = $u->id;
                $data['validated_at'] = now()->format('Y-m-d H:i:s');
            }
            if (! $u->hasPermission('contenus.validate')) {
                unset($data['validator_id'], $data['validated_at']); // la validation ne se déclare pas à la main
                // contenu modifié après validation : la publication repasse « À valider » (sinon publiée sans relecture)
                $before = fn ($k) => $k === 'networks' ? array_values((array) json_decode((string) ($old[$k] ?? '[]'), true)) : ($old[$k] ?? null);
                $now = fn ($k) => $k === 'networks' ? array_values((array) $data[$k]) : $data[$k];
                $changed = $old && ($old['status'] ?? null) === 'approved' && in_array($data['status'] ?? 'approved', ['approved', 'published'], true)
                    && collect(['caption', 'hashtags', 'visual_url', 'link_url', 'networks', 'title'])->contains(fn ($k) => array_key_exists($k, $data) && $now($k) != $before($k));
                if ($changed) {
                    $data['status'] = 'review';
                    $data['validator_id'] = null;
                    $data['validated_at'] = null;
                }
            }
        }

        return $data;
    }

    public function saved(string $res, array $row, ?array $old, User $u): void
    {
        if ($res !== 'posts' || $row['status'] === ($old['status'] ?? null)) {
            return;
        }
        $label = '« '.$row['title'].' »'.($row['scheduled_at'] ? ' du '.date('d/m H:i', strtotime($row['scheduled_at'])) : '');
        if ($row['status'] === 'review') {
            $to = $row['validator_id'] ? [$row['validator_id']] : $this->validators($row['company_id']);
            AppNotification::send($row['company_id'], $to, 'contenus', 'Publication à valider', $label.' — proposée par '.$u->name.'.', 'contenus', (string) $row['id'], $u->id);
        } elseif (in_array($row['status'], ['approved', 'writing'], true) && ($old['status'] ?? null) === 'review') {
            AppNotification::send($row['company_id'], [$row['owner_id']], 'contenus', $row['status'] === 'approved' ? 'Publication validée' : 'Publication à reprendre',
                $label.' — '.$u->name.'.', 'contenus', (string) $row['id'], $u->id);
        }
    }

    protected function validators(int $companyId): array
    {
        return User::where('company_id', $companyId)->where('is_active', true)->get()->filter(fn ($x) => $x->hasPermission('contenus.validate'))->pluck('id')->all();
    }

    public function present(string $res, array $row): array
    {
        if ($res === 'posts') {
            $soc = json_decode($row['social'] ?? '[]', true) ?: [];
            $row['social_status'] = $soc ? collect($soc)->map(fn ($r, $k) => (SocialPublisher::PLATFORMS[$k] ?? $k).(! empty($r['id']) ? ' ✓' : ' ✗ '.($r['error'] ?? '')))->implode(' · ') : null;
            unset($row['social']);
        } elseif ($res === 'plans') {
            $row['spent'] = (float) DB::table('mc_actions')->where('company_id', $row['company_id'])->where('plan_id', $row['id'])->sum('spent');
        } elseif ($res === 'actions') {
            $spent = (float) ($row['spent'] ?? 0);
            $row['roi'] = $spent > 0 && $row['sales'] !== null ? round(($row['sales'] - $spent) / $spent * 100).' %' : null;
        } elseif ($res === 'competitors') {
            $row['observations'] = DB::table('mc_observations')->where('company_id', $row['company_id'])->where('competitor_id', $row['id'])->count();
        }

        return $row;
    }

    /** Rappels quotidiens : publications du jour ou du lendemain pas encore validées. */
    public function reminders(Company $c): int
    {
        $n = 0;
        $rows = DB::table('mc_posts')->where('company_id', $c->id)->whereIn('status', ['idea', 'writing', 'review'])
            ->whereBetween('scheduled_at', [now(), now()->addHours(36)])->whereNull('reminded_at')->get();
        foreach ($rows as $p) {
            $to = $p->status === 'review' ? ($p->validator_id ? [$p->validator_id] : $this->validators($c->id)) : [$p->owner_id];
            AppNotification::send($c->id, $to, 'contenus', $p->status === 'review' ? 'Publication à valider avant sa date' : 'Publication bientôt prévue et pas prête',
                '« '.$p->title.' » prévue le '.date('d/m à H:i', strtotime($p->scheduled_at)).'.', 'contenus', (string) $p->id);
            DB::table('mc_posts')->where('id', $p->id)->update(['reminded_at' => now()]);
            $n++;
        }

        return $n;
    }

    public function dashboard(Company $c): array
    {
        $posts = DB::table('mc_posts')->where('company_id', $c->id);
        $week = [now()->startOfWeek(), now()->endOfWeek()];

        return [
            'week' => (clone $posts)->whereBetween('scheduled_at', $week)->where('status', '!=', 'cancelled')->count(),
            'published_month' => (clone $posts)->where('status', 'published')->whereBetween('scheduled_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
            'review' => (clone $posts)->where('status', 'review')->count(),
            'late' => (clone $posts)->whereIn('status', ['idea', 'writing', 'review', 'approved'])->where('scheduled_at', '<', now())->count(),
            'observations_month' => DB::table('mc_observations')->where('company_id', $c->id)->where('observed_on', '>=', now()->startOfMonth()->toDateString())->count(),
            'threats' => DB::table('mc_competitors')->where('company_id', $c->id)->where('threat', 'high')->count(),
            'budget' => (float) DB::table('mc_plans')->where('company_id', $c->id)->where('status', 'active')->sum('budget'),
            'spent' => (float) DB::table('mc_actions')->where('company_id', $c->id)->whereIn('plan_id', DB::table('mc_plans')->where('company_id', $c->id)->where('status', 'active')->select('id'))->sum('spent'),
            'by_network' => collect((clone $posts)->whereBetween('scheduled_at', [now()->startOfMonth(), now()->endOfMonth()])->pluck('networks'))
                ->flatMap(fn ($n) => json_decode($n ?? '[]', true) ?: [])->countBy()->sortDesc()->all(),
        ];
    }
}
