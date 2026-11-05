<?php

namespace App\Modules\Ia;

use Illuminate\Support\Facades\Http;

/**
 * Appel d'un modèle de langage avec outils (fonctions) : API Anthropic (Messages) ou API compatible OpenAI
 * (OpenAI, Mistral, DeepSeek, Groq, Ollama…). La boucle exécute les outils demandés par le modèle puis lui
 * renvoie leurs résultats, jusqu'à la réponse finale.
 */
class AiClient
{
    public const MAX_STEPS = 6;

    public function __construct(public string $provider, public string $apiKey, public string $model, public ?string $baseUrl = null) {}

    public static function defaultModel(string $provider): string
    {
        return $provider === 'anthropic' ? 'claude-haiku-5-5' : 'gpt-4o-mini';
    }

    /**
     * @param  array  $messages  [['role' => 'user'|'assistant', 'content' => string], …]
     * @param  array  $tools  [['name', 'description', 'parameters' => JSON schema], …]
     * @param  callable  $run  fn(string $name, array $args): array|string
     * @return array{text: string, calls: array, tokens_in: int, tokens_out: int}
     */
    public function chat(string $system, array $messages, array $tools = [], ?callable $run = null, int $maxTokens = 1500): array
    {
        return $this->provider === 'anthropic'
            ? $this->anthropic($system, $messages, $tools, $run, $maxTokens)
            : $this->openai($system, $messages, $tools, $run, $maxTokens);
    }

    protected function fail(\Illuminate\Http\Client\Response $r): never
    {
        $msg = $r->json('error.message') ?? $r->json('message') ?? mb_substr($r->body(), 0, 200);
        throw new \DomainException(match ($r->status()) {
            401, 403 => 'Clé du fournisseur d\'IA refusée : vérifiez-la dans les paramètres de l\'assistant.',
            429 => 'Le fournisseur d\'IA limite le nombre de demandes : réessayez dans une minute.',
            default => 'Le fournisseur d\'IA a répondu une erreur ('.$r->status().') : '.$msg,
        });
    }

    protected function anthropic(string $system, array $messages, array $tools, ?callable $run, int $maxTokens): array
    {
        $url = rtrim($this->baseUrl ?: 'https://api.anthropic.com', '/').'/v1/messages';
        $msgs = array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']], $messages);
        $defs = array_map(fn ($t) => ['name' => $t['name'], 'description' => $t['description'], 'input_schema' => $t['parameters']], $tools);
        $calls = [];
        $in = $out = 0;
        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $body = ['model' => $this->model, 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => $msgs];
            if ($defs) {
                $body['tools'] = $defs;
            }
            $r = Http::withOptions($this->baseUrl ? \App\Support\UrlGuard::pinned($url) : [])->timeout(90)->withHeaders(['x-api-key' => $this->apiKey, 'anthropic-version' => '2023-06-01'])->acceptJson()->asJson()->post($url, $body);
            if (! $r->successful()) {
                $this->fail($r);
            }
            $in += (int) $r->json('usage.input_tokens');
            $out += (int) $r->json('usage.output_tokens');
            $content = $r->json('content') ?? [];
            $uses = array_values(array_filter($content, fn ($b) => ($b['type'] ?? '') === 'tool_use'));
            if (! $uses || ! $run || $r->json('stop_reason') !== 'tool_use') {
                $text = trim(implode("\n", array_map(fn ($b) => $b['text'] ?? '', array_filter($content, fn ($b) => ($b['type'] ?? '') === 'text'))));

                return ['text' => $text, 'calls' => $calls, 'tokens_in' => $in, 'tokens_out' => $out];
            }
            $msgs[] = ['role' => 'assistant', 'content' => $content];
            $results = [];
            foreach ($uses as $u) {
                $res = $this->runTool($run, $u['name'], (array) ($u['input'] ?? []), $calls);
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $u['id'], 'content' => $res];
            }
            $msgs[] = ['role' => 'user', 'content' => $results];
        }

        return ['text' => 'Je n\'ai pas pu terminer l\'analyse : la question demande trop d\'étapes. Pouvez-vous la préciser ?', 'calls' => $calls, 'tokens_in' => $in, 'tokens_out' => $out];
    }

    protected function openai(string $system, array $messages, array $tools, ?callable $run, int $maxTokens): array
    {
        $url = rtrim($this->baseUrl ?: 'https://api.openai.com/v1', '/').'/chat/completions';
        $msgs = array_merge([['role' => 'system', 'content' => $system]], array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']], $messages));
        $defs = array_map(fn ($t) => ['type' => 'function', 'function' => ['name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['parameters']]], $tools);
        $calls = [];
        $in = $out = 0;
        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $body = ['model' => $this->model, 'max_tokens' => $maxTokens, 'messages' => $msgs];
            if ($defs) {
                $body['tools'] = $defs;
            }
            $r = Http::withOptions($this->baseUrl ? \App\Support\UrlGuard::pinned($url) : [])->timeout(90)->withToken($this->apiKey)->acceptJson()->asJson()->post($url, $body);
            if (! $r->successful()) {
                $this->fail($r);
            }
            $in += (int) $r->json('usage.prompt_tokens');
            $out += (int) $r->json('usage.completion_tokens');
            $msg = $r->json('choices.0.message') ?? [];
            $toolCalls = $msg['tool_calls'] ?? [];
            if (! $toolCalls || ! $run) {
                return ['text' => trim((string) ($msg['content'] ?? '')), 'calls' => $calls, 'tokens_in' => $in, 'tokens_out' => $out];
            }
            $msgs[] = ['role' => 'assistant', 'content' => $msg['content'] ?? null, 'tool_calls' => $toolCalls];
            foreach ($toolCalls as $tc) {
                $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
                $msgs[] = ['role' => 'tool', 'tool_call_id' => $tc['id'], 'content' => $this->runTool($run, $tc['function']['name'] ?? '', $args, $calls)];
            }
        }

        return ['text' => 'Je n\'ai pas pu terminer l\'analyse : la question demande trop d\'étapes. Pouvez-vous la préciser ?', 'calls' => $calls, 'tokens_in' => $in, 'tokens_out' => $out];
    }

    protected function runTool(callable $run, string $name, array $args, array &$calls): string
    {
        try {
            $res = $run($name, $args);
        } catch (\DomainException $e) {
            $res = ['erreur' => $e->getMessage()];
        }
        $calls[] = ['name' => $name, 'args' => $args];
        $json = is_string($res) ? $res : json_encode($res, JSON_UNESCAPED_UNICODE);

        return mb_substr($json, 0, 12000);
    }
}
