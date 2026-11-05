<?php

namespace App\Modules\Acquisition;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Publicité & acquisition : listes de valeurs, champs calculés (coût par prospect, ventes, retour) et contrôles. */
class AcquisitionHooks
{
    public const PLATFORMS = ['meta' => 'Facebook / Instagram', 'google' => 'Google Ads / YouTube', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn', 'snapchat' => 'Snapchat',
        'microsoft' => 'Microsoft (Bing)', 'x' => 'X (Twitter)', 'pinterest' => 'Pinterest', 'influencer' => 'Influenceur', 'billboard' => 'Affichage', 'radio' => 'Radio / TV', 'sms' => 'SMS / WhatsApp', 'other' => 'Autre'];

    public const SOURCE_PLATFORMS = ['meta' => 'Publicité Facebook / Instagram', 'google' => 'Formulaire Google Ads', 'tiktok' => 'Publicité TikTok', 'linkedin' => 'Publicité LinkedIn',
        'landing' => 'Page de capture', 'website' => 'Site externe (WordPress…)', 'zapier' => 'Zapier / Make', 'other' => 'Autre'];

    public const CAMPAIGN_STATUSES = ['active' => ['En cours', '#16A34A'], 'paused' => ['En pause', '#D97706'], 'ended' => ['Terminée', '#64748B']];

    public const OBJECTIVES = ['leads' => 'Prospects', 'sales' => 'Ventes', 'traffic' => 'Trafic', 'awareness' => 'Notoriété', 'messages' => 'Messages WhatsApp / Messenger', 'app' => 'Installations'];

    public const ASSIGN = ['owner' => 'Le responsable de la source', 'round_robin' => 'Chaque commercial à tour de rôle'];

    public const REPLY_CHANNELS = ['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'E-mail'];

    public const LEAD_STATUSES = ['created' => ['Opportunité créée', '#16A34A'], 'duplicate' => ['Déjà connu', '#D97706'], 'error' => ['Erreur', '#DC2626']];

    public const PAGE_STATUSES = ['draft' => ['Brouillon', '#64748B'], 'published' => ['En ligne', '#16A34A'], 'closed' => ['Fermée', '#94A3B8']];

    public const FORM_FIELDS = ['name' => 'Nom', 'phone' => 'Téléphone', 'email' => 'E-mail', 'company' => 'Entreprise', 'city' => 'Ville', 'message' => 'Message'];

    protected function cid(): int
    {
        return app('tenant')->id;
    }

    public function present(string $res, array $row): array
    {
        $cid = $this->cid();
        switch ($res) {
            case 'campaigns':
                $row += app(Roi::class)->forCampaign($cid, $row);
                break;
            case 'sources':
                if (! (request()->user()?->hasPermission('acquisition.manage') ?? false)) {
                    unset($row['token'], $row['secret']); // seuls les gestionnaires voient l'adresse secrète et la clé
                    $row['webhook_url'] = null;
                    $row['leads'] = DB::table('ac_leads')->where('company_id', $cid)->where('source_id', $row['id'])->where('received_at', '>=', now()->subDays(30))->count();
                    unset($row['rr_index']);
                    break;
                }
                $row['webhook_url'] = $row['platform'] === 'meta' ? 'Automatique : reliez la page Facebook dans Réseaux sociaux (Messages et prospects activés).' : url('/api/leads-in/'.$row['token']);
                $row['leads'] = DB::table('ac_leads')->where('company_id', $cid)->where('source_id', $row['id'])->where('received_at', '>=', now()->subDays(30))->count();
                unset($row['rr_index']);
                break;
            case 'leads':
                $f = json_decode((string) ($row['fields'] ?? ''), true) ?: [];
                $row['answers'] = $f ? collect($f)->map(fn ($v, $k) => $k.' : '.(is_array($v) ? implode(', ', $v) : $v))->implode("\n") : null;
                $l = $row['lead_id'] ? DB::table('crm_leads')->where('id', $row['lead_id'])->first(['status', 'title']) : null;
                $row['lead_status'] = $l ? (['open' => 'En cours', 'won' => 'Gagnée', 'lost' => 'Perdue'][$l->status] ?? $l->status) : null;
                break;
            case 'pages':
                $row['url'] = url('/lp/'.$row['slug']);
                $row['submissions'] = DB::table('ac_leads')->where('company_id', $cid)->where('platform', 'landing')->where('external_id', 'like', 'page'.$row['id'].':%')->count();
                $row['conversion'] = $row['views'] > 0 ? str_replace('.', ',', (string) round($row['submissions'] / $row['views'] * 100, 1)).' %' : '—';
                break;
        }

        return $row;
    }

    public function saving(string $res, array $data, ?array $old, User $u): array
    {
        switch ($res) {
            case 'campaigns':
                if (empty($data['utm_campaign']) && empty($old['utm_campaign']) && ! empty($data['title'])) {
                    $data['utm_campaign'] = Str::limit(Str::slug($data['title'], '_'), 150, '');
                } elseif (! empty($data['utm_campaign'])) {
                    $data['utm_campaign'] = Str::limit(Str::slug($data['utm_campaign'], '_'), 150, '');
                }
                break;
            case 'sources':
                if (! $old) {
                    $data['token'] = Str::random(40);
                }
                if (($data['reply_channel'] ?? null) && trim((string) ($data['reply_text'] ?? $old['reply_text'] ?? '')) === '') {
                    throw new \DomainException('Écrivez le texte de la réponse automatique.');
                }
                break;
            case 'pages':
                $slug = Str::slug((string) ($data['slug'] ?? '') ?: ($old['slug'] ?? '') ?: ($data['title'] ?? 'offre'));
                $slug = Str::limit($slug ?: 'offre', 70, '');
                $base = $slug;
                $i = 2;
                while (DB::table('ac_pages')->where('slug', $slug)->when($old, fn ($q) => $q->where('id', '!=', $old['id']))->exists()) {
                    $slug = $base.'-'.$i++;
                }
                $data['slug'] = $slug;
                if (! empty($data['color']) && ! preg_match('/^#[0-9A-Fa-f]{6}$/', $data['color'])) {
                    throw new \DomainException('Couleur attendue au format #RRGGBB, par exemple #1F6FEB.');
                }
                if (! empty($data['redirect_url']) && ! preg_match('#^https?://#i', $data['redirect_url'])) {
                    throw new \DomainException('L\'adresse après l\'envoi doit commencer par https://');
                }
                if (array_key_exists('fields', $data) && ! array_intersect((array) $data['fields'], ['phone', 'email'])) {
                    throw new \DomainException('Le formulaire doit demander au moins le téléphone ou l\'e-mail.');
                }
                break;
        }

        return $data;
    }

    public function deleting(string $res, array $row, User $u): void
    {
        $cid = $this->cid();
        if ($res === 'campaigns') {
            DB::table('ac_spend')->where('company_id', $cid)->where('campaign_id', $row['id'])->delete();
            DB::table('ac_leads')->where('company_id', $cid)->where('campaign_id', $row['id'])->update(['campaign_id' => null]);
            DB::table('crm_leads')->where('company_id', $cid)->where('ad_campaign_id', $row['id'])->update(['ad_campaign_id' => null]);
        }
        if ($res === 'sources') {
            DB::table('ac_pages')->where('company_id', $cid)->where('source_id', $row['id'])->update(['source_id' => null]);
        }
    }
}
