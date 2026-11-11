<?php

namespace App\Services\MealPhoto;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Análise de foto com a OpenAI (Chat Completions + saída estruturada em JSON).
 *
 * Feita para gastar poucos tokens:
 * - foto pequena (o navegador envia no máximo 512 px; nestes modelos o custo da imagem
 *   cresce com o número de pixels) e detail "low";
 * - instrução curta e campos de 1 letra na resposta (n, t, g...), traduzidos aqui;
 * - sem raciocínio (reasoning_effort "none") e limite de tokens de saída.
 * Medido: ~440 tokens de entrada e ~290 de saída por foto.
 */
class OpenAiMealPhotoAnalyzer implements MealPhotoAnalyzer
{
    private const MAX_ITEMS = 15;

    private const PROMPT = 'Foto de refeição. Liste só comidas e bebidas visíveis. n=nome pt-BR curto; '
        .'t=termo de busca na Tabela TACO (ex.: "arroz cozido", "feijão carioca cozido", "frango peito grelhado"); '
        .'g=gramas (ml) estimadas; k,p,c,l=kcal, proteína, carboidrato e gordura da porção; s=certeza 0-1. f=false se não houver comida.';

    /** Texto ditado/digitado: q=quantidade, u=medida (lista fixa), g=gramas totais estimadas. */
    private const TEXT_PROMPT = 'Refeição descrita pelo usuário (pt-BR, pode ter erros de ditado). Liste cada comida/bebida citada. '
        .'n=nome curto pt-BR; t=termo de busca na Tabela TACO (ex.: "arroz cozido", "feijão carioca cozido", "frango peito grelhado"); '
        .'q=quantidade; u=medida; g=gramas (ml) totais; k,p,c,l=kcal, proteína, carboidrato e gordura totais. '
        .'"uma colher de cada" vale para todos os itens. "colher" sem tipo = colher de servir (arroz, feijão, carnes, massas, purê) '
        .'ou colher de sopa (farofa, açúcar, granola, molhos). Sem quantidade dita: 1 porção comum. Lista vazia se não houver comida.';

    public function analyze(string $imageBytes, string $mimeType): array
    {
        $messages = [
            ['role' => 'system', 'content' => self::PROMPT],
            ['role' => 'user', 'content' => [[
                'type' => 'image_url',
                'image_url' => ['url' => "data:{$mimeType};base64,".base64_encode($imageBytes), 'detail' => 'low'],
            ]]],
        ];

        return $this->call($messages, self::schema(), fn (Response $r, string $m) => $this->parse($r, $m));
    }

    public function analyzeText(string $text): array
    {
        $messages = [
            ['role' => 'system', 'content' => self::TEXT_PROMPT],
            ['role' => 'user', 'content' => $text],
        ];

        return $this->call($messages, self::textSchema(), fn (Response $r, string $m) => $this->parseText($r, $m));
    }

    /** Envia para a OpenAI, trocando de modelo se um estiver sobrecarregado. */
    private function call(array $messages, array $schema, callable $parse): array
    {
        $key = config('services.openai.api_key');
        $models = config('services.openai.models');
        if (! $key || ! $models) {
            throw new MealAiException('OpenAI não configurada', 'A análise com IA não está configurada neste servidor.');
        }

        $lastError = 'sem modelos';
        foreach ($models as $model) {
            try {
                $response = $this->request($model, $key, $this->body($model, $messages, $schema));
            } catch (ConnectionException $e) {
                $lastError = "{$model}: {$e->getMessage()}";

                continue;
            }

            if ($response->status() === 429 && $response->json('error.code') === 'insufficient_quota') {
                throw new MealAiException('OpenAI sem créditos', 'A análise com IA está indisponível (limite de uso da conta atingido). Registre pela busca.');
            }
            if (in_array($response->status(), [429, 500, 502, 503, 504], true)) {
                $lastError = "{$model}: HTTP {$response->status()} ".mb_substr((string) $response->json('error.message'), 0, 200);
                Log::info('OpenAI indisponível, tentando o próximo modelo', ['model' => $model, 'status' => $response->status()]);

                continue;
            }
            if (! $response->successful()) {
                throw new MealAiException("{$model}: HTTP {$response->status()} ".mb_substr((string) $response->json('error.message'), 0, 300));
            }

            return $parse($response, $model);
        }

        throw new MealAiException("OpenAI indisponível ({$lastError})", 'O serviço de análise está sobrecarregado. Tente de novo em alguns minutos ou registre manualmente.');
    }

    private function body(string $model, array $messages, array $schema): array
    {
        $body = [
            'model' => $model,
            'messages' => $messages,
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'refeicao', 'strict' => true, 'schema' => $schema]],
            'max_completion_tokens' => (int) config('services.openai.max_output_tokens', 600),
        ];
        if ($effort = config('services.openai.reasoning_effort')) {
            $body['reasoning_effort'] = $effort;
        }

        return $body;
    }

    private function request(string $model, string $key, array $body): Response
    {
        $request = Http::withToken($key)
            ->acceptJson()
            ->timeout(config('services.meal_ai.timeout', 40))
            ->connectTimeout(8);
        if ($ca = config('services.meal_ai.ca_bundle')) {
            $request = $request->withOptions(['verify' => $ca]);
        }

        return $request->post(rtrim(config('services.openai.base_url'), '/').'/chat/completions', $body);
    }

    private function parse(Response $response, string $model): array
    {
        $message = $response->json('choices.0.message', []);
        if (! empty($message['refusal'])) {
            throw new MealAiException("OpenAI recusou: {$message['refusal']}", 'A foto não pôde ser analisada. Tente outra foto ou registre manualmente.');
        }
        if ($response->json('choices.0.finish_reason') === 'length') {
            throw new MealAiException("OpenAI: resposta cortada pelo limite de tokens ({$model})");
        }
        $data = json_decode((string) ($message['content'] ?? ''), true);
        if (! is_array($data)) {
            throw new MealAiException("OpenAI: resposta fora do formato ({$model})");
        }

        $items = collect($data['i'] ?? [])
            ->filter(fn ($i) => is_array($i) && trim((string) ($i['n'] ?? '')) !== '' && (float) ($i['g'] ?? 0) > 0)
            ->take(self::MAX_ITEMS)
            ->map(fn (array $i) => [
                'name' => mb_substr(mb_strtoupper(mb_substr(trim($i['n']), 0, 1)).mb_substr(trim($i['n']), 1), 0, 120),
                'search_term' => mb_substr(trim((string) ($i['t'] ?? $i['n'])), 0, 80),
                'grams' => self::clamp($i['g'], 1, 2000),
                'kcal' => self::clamp($i['k'] ?? 0, 0, 3000),
                'protein_g' => self::clamp($i['p'] ?? 0, 0, 300),
                'carbs_g' => self::clamp($i['c'] ?? 0, 0, 500),
                'fat_g' => self::clamp($i['l'] ?? 0, 0, 300),
                'confidence' => isset($i['s']) && is_numeric($i['s']) ? self::clamp($i['s'], 0, 1) : null,
            ])
            ->values()
            ->all();

        return [
            'model' => (string) ($response->json('model') ?: $model),
            // Há itens = há comida (o campo "f" às vezes vem errado mesmo com itens listados).
            'is_food' => count($items) > 0 || (bool) ($data['f'] ?? false),
            'notes' => null,
            'items' => $items,
            // Só o resultado e o consumo de tokens; nunca a imagem.
            'raw' => ['result' => ['is_food' => count($items) > 0 || (bool) ($data['f'] ?? false), 'items' => $data['i'] ?? []], 'usage' => $response->json('usage'), 'model' => $response->json('model')],
        ];
    }

    /** Resposta do texto ditado: inclui quantidade (q) e medida (u). */
    private function parseText(Response $response, string $model): array
    {
        $message = $response->json('choices.0.message', []);
        if (! empty($message['refusal']) || $response->json('choices.0.finish_reason') === 'length') {
            throw new MealAiException("OpenAI: texto não processado ({$model})", 'Não foi possível entender o texto. Tente descrever de outro jeito.');
        }
        $data = json_decode((string) ($message['content'] ?? ''), true);
        if (! is_array($data)) {
            throw new MealAiException("OpenAI: resposta fora do formato ({$model})");
        }

        $items = collect($data['i'] ?? [])
            ->filter(fn ($i) => is_array($i) && trim((string) ($i['n'] ?? '')) !== '' && (float) ($i['g'] ?? 0) > 0)
            ->take(self::MAX_ITEMS)
            ->map(function (array $i) {
                $unit = in_array($i['u'] ?? null, MealPhotoAnalyzer::UNITS, true) ? $i['u'] : 'porção';
                $count = self::clamp($i['q'] ?? 1, 0.25, 50);

                return [
                    'name' => mb_substr(mb_strtoupper(mb_substr(trim($i['n']), 0, 1)).mb_substr(trim($i['n']), 1), 0, 120),
                    'search_term' => mb_substr(trim((string) ($i['t'] ?? $i['n'])), 0, 80),
                    'grams' => self::clamp($i['g'], 1, 3000),
                    'kcal' => self::clamp($i['k'] ?? 0, 0, 5000),
                    'protein_g' => self::clamp($i['p'] ?? 0, 0, 300),
                    'carbs_g' => self::clamp($i['c'] ?? 0, 0, 600),
                    'fat_g' => self::clamp($i['l'] ?? 0, 0, 300),
                    'confidence' => null,
                    // Medida falada ("1 colher de servir"); g/ml não é medida caseira.
                    'portion' => in_array($unit, ['g', 'ml'], true) ? null : ['label' => $unit, 'count' => $count],
                ];
            })
            ->values()
            ->all();

        return [
            'model' => (string) ($response->json('model') ?: $model),
            'is_food' => count($items) > 0,
            'notes' => null,
            'items' => $items,
            'raw' => ['result' => ['is_food' => count($items) > 0, 'items' => $data['i'] ?? []], 'usage' => $response->json('usage'), 'model' => $response->json('model')],
        ];
    }

    /** Esquema do texto: nome, termo TACO, quantidade, medida (lista fixa) e valores totais. */
    private static function textSchema(): array
    {
        $num = ['type' => 'number'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['i'],
            'properties' => [
                'i' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['n', 't', 'q', 'u', 'g', 'k', 'p', 'c', 'l'],
                        'properties' => [
                            'n' => ['type' => 'string'], 't' => ['type' => 'string'], 'q' => $num,
                            'u' => ['type' => 'string', 'enum' => MealPhotoAnalyzer::UNITS],
                            'g' => $num, 'k' => $num, 'p' => $num, 'c' => $num, 'l' => $num,
                        ],
                    ],
                ],
            ],
        ];
    }

    private static function clamp(mixed $v, float $min, float $max): float
    {
        return round(min($max, max($min, is_numeric($v) ? (float) $v : 0)), 2);
    }

    /** Esquema estrito com nomes de 1 letra (menos tokens de saída). */
    private static function schema(): array
    {
        $num = ['type' => 'number'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['f', 'i'],
            'properties' => [
                'f' => ['type' => 'boolean'],
                'i' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['n', 't', 'g', 'k', 'p', 'c', 'l', 's'],
                        'properties' => ['n' => ['type' => 'string'], 't' => ['type' => 'string'], 'g' => $num, 'k' => $num, 'p' => $num, 'c' => $num, 'l' => $num, 's' => $num],
                    ],
                ],
            ],
        ];
    }
}
