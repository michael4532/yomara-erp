<?php

namespace App\Modules\Visio;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\Company;
use App\Models\User;
use App\Modules\Visio\Models\Meeting;
use App\Modules\Visio\Models\Participant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Compte rendu après réunion : présence (arrivée, départ, durée), statistiques, conversation, transcription,
 * sondages et questions ; résumé, décisions et actions rédigés par l'assistant IA (ou extraits automatiquement
 * sans IA) ; actions transformées en tâches « Ma journée » ; envoi aux participants.
 */
class ReportService
{
    public function transcript(Meeting $m): array
    {
        return DB::table('vis_feed')->leftJoin('vis_participants as p', 'p.id', '=', 'vis_feed.participant_id')
            ->where('vis_feed.meeting_id', $m->id)->where('vis_feed.kind', 'caption')->orderBy('vis_feed.id')
            ->get(['vis_feed.body', 'vis_feed.created_at', 'p.name'])
            ->map(fn ($r) => ['at' => (string) $r->created_at, 'name' => $r->name ?? 'Participant', 'text' => (string) $r->body])->all();
    }

    public function transcriptText(Meeting $m, array $lines = null): string
    {
        $lines ??= $this->transcript($m);
        $out = [];
        $last = null;
        foreach ($lines as $l) {
            $t = substr($l['at'], 11, 5);
            if ($last === $l['name'] && $out) {
                $out[count($out) - 1] .= ' '.$l['text'];
            } else {
                $out[] = '['.$t.'] '.$l['name'].' : '.$l['text'];
            }
            $last = $l['name'];
        }

        return implode("\n", $out);
    }

    public function attendance(Meeting $m): array
    {
        return Participant::where('meeting_id', $m->id)->orderByDesc('seconds_total')->get()->map(fn ($p) => [
            'name' => $p->name, 'email' => $p->email, 'role' => $p->role, 'internal' => (bool) $p->user_id, 'organization' => $p->organization,
            'joined' => $p->first_joined_at?->format('H:i'), 'left' => $p->left_at?->format('H:i'), 'minutes' => (int) round($p->seconds_total / 60),
            'present' => (bool) $p->first_joined_at, 'status' => $p->status,
        ])->all();
    }

    public function stats(Meeting $m): array
    {
        $feed = DB::table('vis_feed')->where('meeting_id', $m->id)->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind');
        $parts = Participant::where('meeting_id', $m->id)->get();
        $present = $parts->whereNotNull('first_joined_at');
        $duration = $m->started_at && $m->ended_at ? (int) round($m->started_at->diffInSeconds($m->ended_at, true) / 60) : 0;

        return [
            'duration_minutes' => $duration, 'invited' => $parts->count(), 'present' => $present->count(),
            'attendance_rate' => $parts->count() ? (int) round($present->count() * 100 / $parts->count()) : 0,
            'avg_minutes' => $present->count() ? (int) round($present->avg('seconds_total') / 60) : 0,
            'peak' => $present->count(), 'messages' => DB::table('vis_feed')->where('meeting_id', $m->id)->where('kind', 'chat')->whereNull('to_participant_id')->count(), 'reactions' => (int) ($feed['reaction'] ?? 0),
            'captions' => (int) ($feed['caption'] ?? 0), 'questions' => DB::table('vis_questions')->where('meeting_id', $m->id)->count(),
            'polls' => DB::table('vis_polls')->where('meeting_id', $m->id)->count(), 'switches' => (int) $m->switches,
            'recordings' => DB::table('vis_recordings')->where('meeting_id', $m->id)->where('size', '>', 0)->count(),
            'providers' => DB::table('vis_feed')->where('meeting_id', $m->id)->where('kind', 'system')->where('body', 'switch')->pluck('data')
                ->map(fn ($d) => json_decode($d, true)['to'] ?? null)->prepend(null)->filter()->values()->all(),
        ];
    }

    public function chat(Meeting $m): array
    {
        return DB::table('vis_feed')->leftJoin('vis_participants as p', 'p.id', '=', 'vis_feed.participant_id')
            ->where('vis_feed.meeting_id', $m->id)->where('vis_feed.kind', 'chat')->whereNull('vis_feed.to_participant_id')->orderBy('vis_feed.id')
            ->get(['vis_feed.body', 'vis_feed.created_at', 'p.name'])->map(fn ($r) => ['at' => substr((string) $r->created_at, 11, 5), 'name' => $r->name, 'text' => $r->body])->all();
    }

    public function polls(Meeting $m): array
    {
        return DB::table('vis_polls')->where('meeting_id', $m->id)->orderBy('id')->get()->map(function ($p) {
            $opts = json_decode($p->options, true) ?: [];
            $counts = array_fill(0, count($opts), 0);
            foreach ((array) json_decode((string) $p->votes, true) as $choices) {
                foreach ((array) $choices as $i) {
                    if (isset($counts[$i])) {
                        $counts[$i]++;
                    }
                }
            }

            return ['question' => $p->question, 'options' => array_map(fn ($o, $n) => ['label' => $o, 'votes' => $n], $opts, $counts), 'voters' => count((array) json_decode((string) $p->votes, true))];
        })->all();
    }

    public function questions(Meeting $m): array
    {
        return DB::table('vis_questions')->leftJoin('vis_participants as p', 'p.id', '=', 'vis_questions.participant_id')->where('vis_questions.meeting_id', $m->id)
            ->where('vis_questions.status', '!=', 'hidden')->orderByDesc('vis_questions.votes')->get(['vis_questions.*', 'p.name'])
            ->map(fn ($q) => ['question' => $q->body, 'by' => $q->anonymous ? 'Anonyme' : $q->name, 'votes' => (int) $q->votes, 'answer' => $q->answer, 'answered' => $q->status === 'answered'])->all();
    }

    /** Génère (ou régénère) le compte rendu. */
    public function generate(Company $c, Meeting $m, bool $useAi = true): object
    {
        $lines = $this->transcript($m);
        $transcript = $this->transcriptText($m, $lines);
        if (! $transcript) {
            $transcript = $this->whisper($c, $m) ?? '';
        }
        $attendance = $this->attendance($m);
        $stats = $this->stats($m);
        $chat = $this->chat($m);
        $summary = null;
        $decisions = [];
        $actions = [];
        $source = 'auto';
        if ($useAi && $m->opt('ai_report', true) && ($transcript || $chat)) {
            $ai = $this->aiSummary($c, $m, $transcript, $chat, $attendance);
            if ($ai) {
                [$summary, $decisions, $actions] = [$ai['summary'], $ai['decisions'], $ai['actions']];
                $source = 'ai';
            }
        }
        if (! $summary) {
            [$summary, $decisions, $actions] = $this->extractive($m, $lines, $chat, $stats);
        }
        $existing = DB::table('vis_reports')->where('meeting_id', $m->id)->first();
        $row = ['company_id' => $c->id, 'summary' => $summary, 'decisions' => json_encode($decisions, JSON_UNESCAPED_UNICODE), 'actions' => json_encode($actions, JSON_UNESCAPED_UNICODE),
            'attendance' => json_encode($attendance, JSON_UNESCAPED_UNICODE), 'stats' => json_encode($stats + ['polls_detail' => $this->polls($m), 'questions_detail' => $this->questions($m), 'chat' => $chat], JSON_UNESCAPED_UNICODE),
            'transcript' => $transcript, 'source' => $source, 'updated_at' => now()];
        if ($existing) {
            DB::table('vis_reports')->where('id', $existing->id)->update($row);
        } else {
            DB::table('vis_reports')->insert($row + ['meeting_id' => $m->id, 'token' => VisioService::token(), 'created_at' => now()]);
        }
        $report = DB::table('vis_reports')->where('meeting_id', $m->id)->first();
        if (! $existing) {
            $this->createTodos($c, $m, $report, $actions);
            \App\Integrations\Webhooks::emit($c->id, 'meeting.report_ready', ['meeting_id' => $m->id, 'title' => $m->title, 'type' => $m->type, 'summary' => $summary,
                'decisions' => $decisions, 'actions' => $actions, 'attendance' => $attendance, 'url' => url('/visio/rapport/'.$report->token)]);
        }

        return DB::table('vis_reports')->where('meeting_id', $m->id)->first();
    }

    protected function aiSummary(Company $c, Meeting $m, string $transcript, array $chat, array $attendance): ?array
    {
        if (! $c->hasModule('ia') || ! class_exists(\App\Modules\Ia\AiService::class)) {
            return null;
        }
        $s = app(\App\Modules\Ia\AiService::class)->settings($c);
        if (! $s['configured'] || ! $s['enabled'] || ($s['monthly_limit'] > 0 && $s['used'] >= $s['monthly_limit'])) {
            return null;
        }
        $names = implode(', ', array_column(array_filter($attendance, fn ($a) => $a['present']), 'name'));
        $chatText = implode("\n", array_map(fn ($l) => '['.$l['at'].'] '.$l['name'].' : '.$l['text'], $chat));
        $prompt = implode("\n\n", array_filter([
            'Réunion « '.$m->title.' » du '.($m->started_at ?? now())->locale('fr')->isoFormat('D MMMM YYYY').'. Participants présents : '.$names.'.',
            $m->description ? 'Ordre du jour : '.$m->description : null,
            $transcript ? "Transcription :\n".mb_substr($transcript, 0, 60000) : null,
            $chatText ? "Conversation écrite :\n".mb_substr($chatText, 0, 8000) : null,
            'Rédige le compte rendu en français. Réponds uniquement avec un objet JSON : {"resume": "4 à 10 phrases claires", "decisions": ["…"], '
            .'"actions": [{"titre": "verbe à l\'infinitif + objet", "responsable": "nom d\'un participant ou null", "echeance": "AAAA-MM-JJ ou null"}]}. '
            .'N\'invente rien qui ne figure pas dans la transcription ou la conversation.',
        ]));
        try {
            $client = new \App\Modules\Ia\AiClient($s['provider'], (string) $s['key'], $s['model'], $s['base_url']);
            $res = $client->chat('Tu es le secrétaire de séance d\'une entreprise d\'Afrique de l\'Ouest : comptes rendus précis, factuels et concis.', [['role' => 'user', 'content' => $prompt]], [], null, 1800);
            DB::table('ia_messages')->insert(['conversation_id' => null, 'company_id' => $c->id, 'user_id' => $m->host_id, 'role' => 'draft', 'content' => 'Compte rendu : '.$m->title,
                'tools' => null, 'tokens_in' => $res['tokens_in'] ?? 0, 'tokens_out' => $res['tokens_out'] ?? 0, 'created_at' => now(), 'updated_at' => now()]);
            $text = (string) ($res['text'] ?? '');
            if (! preg_match('/\{.*\}/s', $text, $mm)) {
                return null;
            }
            $j = json_decode($mm[0], true);
            if (! is_array($j) || empty($j['resume'])) {
                return null;
            }
            $actions = array_values(array_filter(array_map(fn ($a) => is_array($a) ? ['title' => mb_substr((string) ($a['titre'] ?? ''), 0, 250), 'owner' => $a['responsable'] ?? null,
                'due' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($a['echeance'] ?? '')) ? $a['echeance'] : null] : ['title' => mb_substr((string) $a, 0, 250), 'owner' => null, 'due' => null],
                (array) ($j['actions'] ?? [])), fn ($a) => $a['title'] !== ''));

            return ['summary' => (string) $j['resume'], 'decisions' => array_values(array_filter(array_map('strval', (array) ($j['decisions'] ?? [])))), 'actions' => $actions];
        } catch (\Throwable $e) {
            Log::info('Visio : résumé IA indisponible ('.$e->getMessage().')');

            return null;
        }
    }

    /** Sans IA : résumé factuel et repérage des décisions / actions par mots-clés. */
    protected function extractive(Meeting $m, array $lines, array $chat, array $stats): array
    {
        $present = $stats['present'];
        $summary = 'Réunion « '.$m->title.' » tenue le '.($m->started_at ?? now())->locale('fr')->isoFormat('dddd D MMMM YYYY [à] HH:mm').', durée '.$stats['duration_minutes']
            .' min, '.$present.' participant'.($present > 1 ? 's' : '').' présent'.($present > 1 ? 's' : '').'.';
        if ($stats['messages']) {
            $summary .= ' '.$stats['messages'].' message'.($stats['messages'] > 1 ? 's' : '').' échangé'.($stats['messages'] > 1 ? 's' : '').' dans la conversation.';
        }
        if ($stats['polls']) {
            $summary .= ' '.$stats['polls'].' sondage'.($stats['polls'] > 1 ? 's' : '').' réalisé'.($stats['polls'] > 1 ? 's' : '').'.';
        }
        if ($m->description) {
            $summary .= ' Ordre du jour : '.mb_substr($m->description, 0, 400);
        }
        $all = array_merge(array_map(fn ($l) => ['name' => $l['name'], 'text' => $l['text']], $lines), array_map(fn ($l) => ['name' => $l['name'], 'text' => $l['text']], $chat));
        $decisions = [];
        $actions = [];
        foreach ($all as $l) {
            $t = trim((string) $l['text']);
            if (preg_match('/\b(on a décidé|nous avons décidé|décision|c\'est validé|validé|on valide|adopté|d\'accord pour)\b/iu', $t)) {
                $decisions[] = mb_substr($t, 0, 250);
            }
            if (preg_match('/\b(action|à faire|todo|je m\'en occupe|je vais|tu vas|il faut|doit|devra|avant le|d\'ici)\b/iu', $t) && mb_strlen($t) > 12) {
                $actions[] = ['title' => mb_substr($t, 0, 250), 'owner' => preg_match('/\b(je m\'en occupe|je vais)\b/iu', $t) ? $l['name'] : null, 'due' => null];
            }
        }

        return [$summary, array_slice(array_values(array_unique($decisions)), 0, 12), array_slice($actions, 0, 15)];
    }

    /** Actions du compte rendu : tâche pour le responsable s'il est utilisateur, sinon pour l'organisateur. */
    protected function createTodos(Company $c, Meeting $m, object $report, array $actions): void
    {
        if (! $actions || ! \Illuminate\Support\Facades\Schema::hasTable('user_todos')) {
            return;
        }
        $users = User::where('company_id', $c->id)->where('is_active', true)->get(['id', 'name']);
        $n = 0;
        foreach ($actions as $a) {
            $owner = null;
            if (! empty($a['owner'])) {
                $needle = mb_strtolower((string) $a['owner']);
                $owner = $users->first(fn ($u) => mb_strtolower($u->name) === $needle) ?? $users->first(fn ($u) => str_contains(mb_strtolower($u->name), $needle) || str_contains($needle, mb_strtolower($u->name)));
            }
            $uid = $owner?->id ?? $m->host_id;
            if (! $uid) {
                continue;
            }
            DB::table('user_todos')->insert(['company_id' => $c->id, 'user_id' => $uid, 'title' => mb_substr($a['title'].' (réunion « '.$m->title.' »)', 0, 255),
                'due_date' => $a['due'] ?? null, 'important' => false, 'my_day' => true, 'sort' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $n++;
        }
        DB::table('vis_reports')->where('id', $report->id)->update(['todos_created' => $n]);
    }

    /** Transcription après coup de l'enregistrement (Whisper) quand l'IA de la société passe par OpenAI. */
    protected function whisper(Company $c, Meeting $m): ?string
    {
        if (! $c->hasModule('ia') || ! class_exists(\App\Modules\Ia\AiService::class)) {
            return null;
        }
        $rec = DB::table('vis_recordings')->where('meeting_id', $m->id)->where('status', 'ready')->where('size', '>', 0)->where('size', '<', 25 * 1024 * 1024)->orderByDesc('size')->first();
        $s = app(\App\Modules\Ia\AiService::class)->settings($c);
        if (! $rec || $s['provider'] !== 'openai' || ! $s['configured']) {
            return null;
        }
        try {
            $base = rtrim($s['base_url'] ?: 'https://api.openai.com/v1', '/');
            $r = Http::withOptions($s['base_url'] ? \App\Support\UrlGuard::pinned($base.'/audio/transcriptions') : [])->withToken((string) $s['key'])->timeout(300)->attach('file', Storage::disk('local')->get($rec->path), 'reunion.webm')
                ->post($base.'/audio/transcriptions', ['model' => 'whisper-1', 'language' => 'fr', 'response_format' => 'text']);

            return $r->successful() ? trim($r->body()) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function html(Company $c, Meeting $m, object $r, bool $email = false): string
    {
        $stats = json_decode((string) $r->stats, true) ?: [];
        $att = json_decode((string) $r->attendance, true) ?: [];
        $dec = json_decode((string) $r->decisions, true) ?: [];
        $act = json_decode((string) $r->actions, true) ?: [];
        $h = fn ($s) => e((string) $s);
        $card = 'border:1px solid #E2E8F0;border-radius:10px;padding:14px 18px;margin:14px 0;background:#fff';
        $out = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:720px;margin:auto;color:#0F172A;line-height:1.55">';
        $out .= '<div style="background:linear-gradient(135deg,#1E3A8A,#2563EB);color:#fff;border-radius:12px;padding:20px 22px">'
            .'<div style="font-size:12px;opacity:.85;text-transform:uppercase;letter-spacing:.06em">Compte rendu'.($m->isWebinar() ? ' de webinaire' : ' de réunion').'</div>'
            .'<div style="font-size:22px;font-weight:bold;margin-top:4px">'.$h($m->title).'</div>'
            .'<div style="opacity:.9;margin-top:4px">'.$h(($m->started_at ?? $m->starts_at ?? now())->locale('fr')->isoFormat('dddd D MMMM YYYY, HH:mm')).' · '.(int) ($stats['duration_minutes'] ?? 0).' min · '
            .(int) ($stats['present'] ?? 0).' présent(s)</div></div>';
        $out .= '<div style="'.$card.'"><div style="font-weight:bold;margin-bottom:6px">Résumé'.($r->source === 'ai' ? ' <span style="font-size:11px;color:#7C3AED;font-weight:normal">rédigé par l\'assistant IA</span>' : '').'</div>'
            .'<div>'.nl2br($h($r->summary)).'</div></div>';
        if ($dec) {
            $out .= '<div style="'.$card.'"><div style="font-weight:bold;margin-bottom:6px">Décisions</div><ul style="margin:0;padding-left:20px">'
                .implode('', array_map(fn ($d) => '<li>'.$h($d).'</li>', $dec)).'</ul></div>';
        }
        if ($act) {
            $out .= '<div style="'.$card.'"><div style="font-weight:bold;margin-bottom:6px">Actions à mener</div><table style="width:100%;border-collapse:collapse;font-size:14px">'
                .implode('', array_map(fn ($a) => '<tr><td style="padding:5px 0;border-bottom:1px solid #F1F5F9">☐ '.$h($a['title']).'</td><td style="padding:5px 8px;border-bottom:1px solid #F1F5F9;color:#475569;white-space:nowrap">'
                    .$h($a['owner'] ?? '').'</td><td style="padding:5px 0;border-bottom:1px solid #F1F5F9;color:#475569;white-space:nowrap">'.($a['due'] ? $h(date('d/m/Y', strtotime($a['due']))) : '').'</td></tr>', $act)).'</table></div>';
        }
        $out .= '<div style="'.$card.'"><div style="font-weight:bold;margin-bottom:6px">Présence</div><table style="width:100%;border-collapse:collapse;font-size:13px">'
            .'<tr style="color:#64748B;text-align:left"><th style="padding:4px 0">Participant</th><th>Arrivée</th><th>Départ</th><th style="text-align:right">Durée</th></tr>'
            .implode('', array_map(fn ($a) => '<tr><td style="padding:4px 0;border-top:1px solid #F1F5F9">'.$h($a['name']).($a['organization'] ? ' <span style="color:#94A3B8">('.$h($a['organization']).')</span>' : '').'</td>'
                .'<td style="border-top:1px solid #F1F5F9">'.($a['present'] ? $h($a['joined']) : '<span style="color:#DC2626">absent</span>').'</td><td style="border-top:1px solid #F1F5F9">'.$h($a['left']).'</td>'
                .'<td style="border-top:1px solid #F1F5F9;text-align:right">'.($a['present'] ? $a['minutes'].' min' : '').'</td></tr>', $att)).'</table></div>';
        foreach ($stats['polls_detail'] ?? [] as $p) {
            $tot = max(1, array_sum(array_column($p['options'], 'votes')));
            $out .= '<div style="'.$card.'"><div style="font-weight:bold;margin-bottom:6px">Sondage : '.$h($p['question']).'</div>'
                .implode('', array_map(fn ($o) => '<div style="margin:4px 0">'.$h($o['label']).' — <b>'.$o['votes'].'</b> ('.round($o['votes'] * 100 / $tot).' %)</div>', $p['options'])).'</div>';
        }
        if (! empty($stats['questions_detail'])) {
            $out .= '<div style="'.$card.'"><div style="font-weight:bold;margin-bottom:6px">Questions du public</div>'
                .implode('', array_map(fn ($q) => '<div style="margin:6px 0"><b>'.$h($q['question']).'</b> <span style="color:#94A3B8">— '.$h($q['by']).', '.$q['votes'].' vote(s)</span>'
                    .($q['answer'] ? '<div style="color:#475569">↳ '.$h($q['answer']).'</div>' : '').'</div>', array_slice($stats['questions_detail'], 0, 40))).'</div>';
        }
        if (! $email && $r->transcript) {
            $out .= '<div style="'.$card.'"><div style="font-weight:bold;margin-bottom:6px">Transcription</div><div style="white-space:pre-wrap;font-size:13px;color:#334155">'.$h($r->transcript).'</div></div>';
        }
        if ($email) {
            $out .= '<p style="margin:22px 0"><a href="'.e(url('/visio/rapport/'.$r->token)).'" style="background:#2563EB;color:#fff;padding:11px 20px;border-radius:8px;text-decoration:none;font-weight:bold">Voir le compte rendu complet</a></p>';
        }
        $out .= '<p style="color:#94A3B8;font-size:11px">'.$h($c->name).' — Yomara ERP · Réunions & webinaires</p></div>';

        return $out;
    }

    /** Envoi du compte rendu aux participants (internes, et externes si demandé). */
    public function send(Company $c, Meeting $m, bool $externals = true, ?int $userId = null): int
    {
        $r = DB::table('vis_reports')->where('meeting_id', $m->id)->first() ?? $this->generate($c, $m);
        $messaging = app(MessagingService::class);
        if (! ($messaging->available($c)['email'] ?? null)) {
            return 0;
        }
        $html = $this->html($c, $m, $r, true);
        $sent = 0;
        $q = Participant::where('meeting_id', $m->id)->whereNotNull('email')->whereNotNull('first_joined_at');
        if (! $externals || $m->isWebinar()) {
            $q->where(fn ($w) => $w->whereNotNull('user_id')->orWhereIn('role', ['host', 'cohost', 'panelist']));
        }
        foreach ($q->get() as $p) {
            try {
                if ($messaging->send($c, 'email', new Message(to: $p->email, toName: $p->name, subject: 'Compte rendu : '.$m->title, html: $html), 'visio', $userId)->ok) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                Log::warning('Visio : compte rendu non envoyé à '.$p->email.' : '.$e->getMessage());
            }
        }
        DB::table('vis_reports')->where('id', $r->id)->update(['sent_at' => now()]);

        return $sent;
    }
}
