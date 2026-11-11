<?php

namespace App\Services\Ai;

use App\Services\MealPhoto\MealAiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pedido de texto -> JSON estruturado para a IA configurada (MEAL_AI_PROVIDER: openai ou gemini).
 * A chave fica só no servidor. Se um modelo estiver sobrecarregado (429/5xx), tenta o próximo.
 *
 * O esquema é escrito no formato JSON Schema da OpenAI (strict); para o Gemini ele é convertido.
 */
class JsonAi
{
    /**
     * @return array{data: array, model: string, usage: array|null}
     *
     * @throws MealAiException
     */
    public function ask(string $system, string $user, array $schema, int $maxOutputTokens = 2000, int $timeout = 90): array
    {
        return config('services.meal_ai.provider') === 'gemini'
            ? $this->gemini($system, $user, $schema, $maxOutputTokens, $timeout)
            : $this->openai($system, $user, $schema, $maxOutputTokens, $timeout);
    }

    private function openai(string $system, string $user, array $schema, int $maxTokens, int $timeout): array
    {
        $key = config('services.openai.api_key');
        $models = config('services.openai.models');
        if (! $key || ! $models) {
            throw new MealAiException('OpenAI não configurada', 'A IA não está configurada neste servidor.');
        }

        return $this->tryModels($models, function (string $model) use ($key, $system, $user, $schema, $maxTokens, $timeout) {
            $body = [
                'model' => $model,
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
                'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'resposta', 'strict' => true, 'schema' => $schema]],
                'max_completion_tokens' => $maxTokens,
            ];
            if ($effort = config('services.openai.reasoning_effort')) {
                $body['reasoning_effort'] = $effort;
            }
            $response = $this->http($timeout)->withToken($key)
                ->post(rtrim(config('services.openai.base_url'), '/').'/chat/completions', $body);

            if ($response->status() === 429 && $response->json('error.code') === 'insufficient_quota') {
                throw new MealAiException('OpenAI sem créditos', 'A IA está indisponível (limite de uso da conta atingido).');
            }
            if (in_array($response->status(), [429, 500, 502, 503, 504], true)) {
                return null; // tenta o próximo modelo
            }
            if (! $response->successful()) {
                throw new MealAiException("{$model}: HTTP {$response->status()} ".mb_substr((string) $response->json('error.message'), 0, 300));
            }
            if ($response->json('choices.0.finish_reason') === 'length') {
                throw new MealAiException("{$model}: resposta cortada pelo limite de tokens", 'A resposta da IA ficou grande demais. Tente pedir menos dias ou menos exercícios.');
            }
            $data = json_decode((string) $response->json('choices.0.message.content'), true);
            if (! is_array($data)) {
                throw new MealAiException("{$model}: resposta fora do formato");
            }

            return ['data' => $data, 'model' => (string) ($response->json('model') ?: $model), 'usage' => $response->json('usage')];
        });
    }

    private function gemini(string $system, string $user, array $schema, int $maxTokens, int $timeout): array
    {
        $key = config('services.gemini.api_key');
        $models = config('services.gemini.models');
        if (! $key || ! $models) {
            throw new MealAiException('Gemini não configurado', 'A IA não está configurada neste servidor.');
        }

        return $this->tryModels($models, function (string $model) use ($key, $system, $user, $schema, $maxTokens, $timeout) {
            $response = $this->http($timeout)->withHeaders(['x-goog-api-key' => $key])
                ->post(rtrim(config('services.gemini.base_url'), '/')."/models/{$model}:generateContent", [
                    'systemInstruction' => ['parts' => [['text' => $system]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
                    'generationConfig' => [
                        'temperature' => 0.4,
                        'maxOutputTokens' => $maxTokens,
                        'responseMimeType' => 'application/json',
                        'responseSchema' => self::toGemini($schema),
                        ...(config('services.gemini.thinking_level') ? ['thinkingConfig' => ['thinkingLevel' => config('services.gemini.thinking_level')]] : []),
                    ],
                ]);
            if (in_array($response->status(), [429, 500, 503, 504], true)) {
                return null;
            }
            if (! $response->successful()) {
                throw new MealAiException("{$model}: HTTP {$response->status()} ".mb_substr((string) $response->json('error.message'), 0, 300));
            }
            $text = collect($response->json('candidates.0.content.parts', []))->reject(fn ($p) => ($p['thought'] ?? false) === true)->pluck('text')->implode('');
            $data = json_decode(trim($text), true);
            if (! is_array($data)) {
                throw new MealAiException("{$model}: resposta fora do formato");
            }

            return ['data' => $data, 'model' => (string) ($response->json('modelVersion') ?: $model), 'usage' => $response->json('usageMetadata')];
        });
    }

    /** Tenta cada modelo; null = sobrecarregado, segue para o próximo. */
    private function tryModels(array $models, callable $call): array
    {
        foreach ($models as $model) {
            try {
                $result = $call($model);
            } catch (ConnectionException $e) {
                Log::info('IA sem conexão, tentando o próximo modelo', ['model' => $model, 'error' => $e->getMessage()]);

                continue;
            }
            if ($result !== null) {
                return $result;
            }
            Log::info('IA sobrecarregada, tentando o próximo modelo', ['model' => $model]);
        }

        throw new MealAiException('IA indisponível em todos os modelos', 'O serviço de IA está sobrecarregado. Tente de novo em alguns minutos.');
    }

    private function http(int $timeout): PendingRequest
    {
        $request = Http::acceptJson()->timeout($timeout)->connectTimeout(8);
        if ($ca = config('services.meal_ai.ca_bundle')) {
            $request = $request->withOptions(['verify' => $ca]);
        }

        return $request;
    }

    /** JSON Schema (OpenAI) -> esquema do Gemini (tipos em maiúsculas, sem additionalProperties). */
    public static function toGemini(array $schema): array
    {
        $out = [];
        foreach ($schema as $key => $value) {
            if ($key === 'additionalProperties') {
                continue;
            }
            $out[$key] = match (true) {
                $key === 'type' && is_string($value) => strtoupper($value),
                $key === 'properties' && is_array($value) => array_map(fn ($p) => self::toGemini($p), $value),
                $key === 'items' && is_array($value) => self::toGemini($value),
                default => $value,
            };
        }

        return $out;
    }
}
