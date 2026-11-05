<?php

namespace App\Modules\Ia;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Assistant IA : réglages (clé de la société ou de la plateforme), quota mensuel, conversations, rédaction. */
class AiService
{
    public const DRAFTS = [
        'relance' => 'Relance d\'une facture impayée',
        'envoi_devis' => 'Message d\'envoi d\'un devis',
        'envoi_facture' => 'Message d\'envoi d\'une facture',
        'fiche_article' => 'Description d\'un article (boutique, catalogue)',
        'reponse_client' => 'Réponse à un client',
        'publication' => 'Publication réseaux sociaux',
        'libre' => 'Texte libre',
    ];

    public function settings(Company $c): array
    {
        $row = DB::table('ia_settings')->where('company_id', $c->id)->first();
        $own = $row && $row->api_key;
        $provider = $own ? ($row->provider ?: 'anthropic') : (string) config('erp.ai.provider', 'anthropic');
        $key = $own ? rescue(fn () => Crypt::decryptString($row->api_key), null, false) : config('erp.ai.api_key');
        $model = ($own ? $row->model : null) ?: ($own ? null : config('erp.ai.model')) ?: AiClient::defaultModel($provider);

        return [
            'own_key' => (bool) $own, 'provider' => $provider, 'model' => $model, 'base_url' => $own ? $row->base_url : config('erp.ai.base_url'),
            'key' => $key, 'configured' => (bool) $key, 'enabled' => $row ? (bool) $row->enabled : true,
            // Avec la clé de la plateforme : quota mensuel ; avec sa propre clé : limite choisie par la société (0 = sans limite)
            'monthly_limit' => $own ? (int) ($row->monthly_limit ?? 0) : (int) ($row->monthly_limit ?? config('erp.ai.monthly_limit', 300)),
            'used' => $this->usedThisMonth($c),
        ];
    }

    public function publicSettings(Company $c): array
    {
        $s = $this->settings($c);
        unset($s['key']);

        return $s + ['key_hint' => $s['own_key'] ? '••••'.mb_substr((string) $this->settings($c)['key'], -4) : null, 'drafts' => self::DRAFTS];
    }

    public function saveSettings(Company $c, array $d): array
    {
        if (! empty($d['base_url']) && ! \App\Support\UrlGuard::isPublic((string) $d['base_url'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['base_url' => 'Adresse refusée : seuls les serveurs publics (http/https) sont autorisés.']);
        }
        $row = ['provider' => $d['provider'] ?? null, 'model' => $d['model'] ?? null, 'base_url' => $d['base_url'] ?? null,
            'monthly_limit' => $d['monthly_limit'] ?? null, 'enabled' => $d['enabled'] ?? true, 'updated_at' => now()];
        if (array_key_exists('api_key', $d)) {
            $row['api_key'] = $d['api_key'] ? Crypt::encryptString($d['api_key']) : null; // vide = revenir à la clé de la plateforme
        }
        DB::table('ia_settings')->updateOrInsert(['company_id' => $c->id], $row + ['created_at' => now()]);

        return $this->publicSettings($c);
    }

    public function usedThisMonth(Company $c): int
    {
        return DB::table('ia_messages')->where('company_id', $c->id)->whereIn('role', ['assistant', 'draft'])->where('created_at', '>=', now()->startOfMonth())->count();
    }

    protected function client(Company $c): AiClient
    {
        $s = $this->settings($c);
        if (! $s['enabled']) {
            throw new \DomainException('L\'assistant IA est désactivé pour votre société.');
        }
        if (! $s['configured']) {
            throw new \DomainException('L\'assistant IA n\'est pas encore configuré : saisissez la clé de votre fournisseur d\'IA dans ses paramètres.');
        }
        if ($s['monthly_limit'] > 0 && $s['used'] >= $s['monthly_limit']) {
            throw new \DomainException('Quota mensuel de l\'assistant atteint ('.$s['monthly_limit'].' demandes) : il se renouvelle le 1er du mois.');
        }

        return new AiClient($s['provider'], (string) $s['key'], $s['model'], $s['base_url']);
    }

    protected function system(Company $c, User $u): string
    {
        $branch = \App\Support\BranchScope::current();

        return implode("\n", [
            'Tu es l\'assistant de gestion de l\'ERP Yomara pour la société « '.$c->name.' » ('.($c->city ?: $c->country ?: 'Côte d\'Ivoire').'). Monnaie : '.$c->currency.'.',
            'Nous sommes le '.now()->locale('fr')->isoFormat('dddd D MMMM YYYY').'. Tu parles à '.$u->name.' ('.($u->role?->name ?? 'utilisateur').').',
            $branch ? 'Agence de travail : '.(DB::table('branches')->where('id', $branch)->value('name')).'.' : '',
            'Réponds en français, de façon courte et concrète, avec les montants formatés (ex. 1 250 000 F CFA). Utilise des listes ou un petit tableau quand il y a plusieurs lignes.',
            'Pour tout chiffre sur l\'activité, appelle les outils : n\'invente jamais de données. Si un outil n\'est pas disponible, dis que l\'utilisateur n\'a pas accès à cette information.',
            'Tu ne peux rien modifier dans l\'ERP : propose à l\'utilisateur l\'écran où faire l\'action (Ventes, Achats, Stock, Banque & trésorerie, Relances…).',
            'Ne donne pas de conseil fiscal ou juridique définitif : recommande de vérifier avec un expert-comptable quand c\'est engageant.',
        ]);
    }

    /** Question dans une conversation (créée au besoin). */
    public function ask(Company $c, User $u, string $question, ?int $conversationId = null): array
    {
        $client = $this->client($c);
        $conv = $conversationId ? DB::table('ia_conversations')->where('company_id', $c->id)->where('user_id', $u->id)->where('id', $conversationId)->first() : null;
        if ($conversationId && ! $conv) {
            throw new \DomainException('Conversation introuvable.');
        }
        $history = $conv ? DB::table('ia_messages')->where('conversation_id', $conv->id)->whereIn('role', ['user', 'assistant'])->orderByDesc('id')->limit(12)->get(['role', 'content'])->reverse()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])->values()->all() : [];
        $tools = new AiTools($c, $u);
        $res = $client->chat($this->system($c, $u), array_merge($history, [['role' => 'user', 'content' => $question]]), $tools->definitions(), fn ($name, $args) => $tools->run($name, $args));
        $text = $res['text'] ?: 'Je n\'ai pas de réponse à cette question.';
        $convId = $conv?->id ?? DB::table('ia_conversations')->insertGetId(['company_id' => $c->id, 'user_id' => $u->id, 'title' => mb_substr($question, 0, 120), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ia_conversations')->where('id', $convId)->update(['updated_at' => now()]);
        DB::table('ia_messages')->insert([
            ['conversation_id' => $convId, 'company_id' => $c->id, 'user_id' => $u->id, 'role' => 'user', 'content' => $question, 'tools' => null, 'tokens_in' => 0, 'tokens_out' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['conversation_id' => $convId, 'company_id' => $c->id, 'user_id' => $u->id, 'role' => 'assistant', 'content' => $text, 'tools' => json_encode($res['calls'], JSON_UNESCAPED_UNICODE),
                'tokens_in' => $res['tokens_in'], 'tokens_out' => $res['tokens_out'], 'created_at' => now(), 'updated_at' => now()],
        ]);

        return ['conversation_id' => $convId, 'answer' => $text, 'tools' => array_column($res['calls'], 'name'), 'used' => $this->usedThisMonth($c)];
    }

    /** Texte libre demandé par un module (analyse, synthèse, fiche…), compté dans le quota du mois. */
    public function complete(Company $c, User $u, string $system, string $prompt, string $kind, int $maxTokens = 1200): string
    {
        $res = $this->client($c)->chat($system, [['role' => 'user', 'content' => $prompt]], [], null, $maxTokens);
        DB::table('ia_messages')->insert(['conversation_id' => null, 'company_id' => $c->id, 'user_id' => $u->id, 'role' => 'draft', 'content' => $res['text'], 'tools' => json_encode(['kind' => $kind]),
            'tokens_in' => $res['tokens_in'], 'tokens_out' => $res['tokens_out'], 'created_at' => now(), 'updated_at' => now()]);

        return trim((string) $res['text']);
    }

    /** Proposition de réponse à un avis Google (remerciement, ou excuse et solution pour un avis négatif). */
    public function reviewReply(Company $c, User $u, array $review, string $tone = 'chaleureux'): array
    {
        $client = $this->client($c);
        $prompt = "Avis Google laissé par « {$review['author']} » : {$review['rating']}/5.\nTexte : ".($review['comment'] ?: '(aucun texte, seulement la note)')."\n\n"
            ."Rédige la réponse publique de l'établissement « {$c->name} », ton {$tone}, 2 à 4 phrases, en français, signée « L'équipe {$c->name} ». "
            .'Remercie toujours. Si l\'avis est négatif : excuse sincère, reprise précise du problème évoqué, solution ou invitation à nous contacter'.($c->phone ? ' au '.$c->phone : '').'. '
            .'N\'invente aucun fait, aucune promesse chiffrée, aucun prénom d\'employé. Rends uniquement le texte de la réponse.';
        $res = $client->chat('Tu réponds aux avis clients d\'entreprises d\'Afrique de l\'Ouest, avec professionnalisme et chaleur.', [['role' => 'user', 'content' => $prompt]], [], null, 500);
        DB::table('ia_messages')->insert(['conversation_id' => null, 'company_id' => $c->id, 'user_id' => $u->id, 'role' => 'draft', 'content' => $res['text'], 'tools' => json_encode(['kind' => 'avis_google']),
            'tokens_in' => $res['tokens_in'], 'tokens_out' => $res['tokens_out'], 'created_at' => now(), 'updated_at' => now()]);

        return ['text' => trim($res['text']), 'used' => $this->usedThisMonth($c)];
    }

    /** Rédaction d'un texte (relance, message d'envoi, fiche article…) à partir d'un document ou d'instructions. */
    public function draft(Company $c, User $u, string $kind, string $instructions, ?string $documentNumber = null, string $tone = 'cordial', string $channel = 'email'): array
    {
        $client = $this->client($c);
        $context = '';
        if ($documentNumber) {
            $doc = (new AiTools($c, $u))->run('document', ['numero' => $documentNumber]);
            if (! ($doc['trouve'] ?? false)) {
                throw new \DomainException('Document '.$documentNumber.' introuvable.');
            }
            $context = "Document concerné (données de l'ERP) :\n".json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        $sender = trim($u->name.', '.$c->name.($c->phone ? ' — '.$c->phone : ''));
        $prompt = implode("\n\n", array_filter([
            'Rédige : '.(self::DRAFTS[$kind] ?? 'un texte').'. Canal : '.(['email' => 'e-mail (avec une ligne « Objet : … » en premier)', 'whatsapp' => 'message WhatsApp court, sans objet', 'sms' => 'SMS de moins de 300 caractères, sans objet'][$channel] ?? 'e-mail').'.',
            'Ton : '.$tone.'. Signataire : '.$sender.'.',
            $context,
            $instructions ? 'Consignes : '.$instructions : null,
            'Rends uniquement le texte prêt à envoyer, en français, sans commentaire. N\'invente ni montant, ni date, ni numéro qui ne figure pas dans les données.',
        ]));
        $res = $client->chat('Tu rédiges des messages professionnels pour une entreprise de Côte d\'Ivoire ou d\'Afrique de l\'Ouest, au nom de l\'utilisateur.', [['role' => 'user', 'content' => $prompt]], [], null, 900);
        DB::table('ia_messages')->insert(['conversation_id' => null, 'company_id' => $c->id, 'user_id' => $u->id, 'role' => 'draft', 'content' => $res['text'], 'tools' => json_encode(['kind' => $kind, 'document' => $documentNumber]),
            'tokens_in' => $res['tokens_in'], 'tokens_out' => $res['tokens_out'], 'created_at' => now(), 'updated_at' => now()]);

        return ['text' => $res['text'], 'used' => $this->usedThisMonth($c)];
    }
}
