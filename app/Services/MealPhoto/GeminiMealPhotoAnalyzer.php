<?php

namespace App\Services\MealPhoto;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Análise de foto com o Google Gemini (API generateContent, saída em JSON com esquema).
 * Documentação: https://ai.google.dev/gemini-api/docs
 *
 * No plano gratuito o Google pode usar o conteúdo enviado para melhorar os produtos dele;
 * o app avisa o usuário antes do envio.
 */
class GeminiMealPhotoAnalyzer implements MealPhotoAnalyzer
{
    private const MAX_ITEMS = 15;

    private const PROMPT = <<<'TXT'
        Você é um assistente de nutrição de um app brasileiro. Analise a foto de uma refeição.

        Para cada alimento ou bebida VISÍVEL:
        - name: nome curto em português do Brasil (ex.: "Arroz branco cozido", "Feijão carioca", "Bife grelhado").
        - search_term: 2 a 4 palavras genéricas para buscar na Tabela TACO, no estilo
          "arroz cozido", "feijão carioca cozido", "frango peito grelhado", "alface", "batata frita". Sem marcas.
          Use os nomes da TACO: "arroz cozido" (não "arroz branco"), "carne bovina" + corte quando der ("contra-filé grelhado"),
          peixe pelo nome ("tilápia", "pescada frita"); para salada, um termo por vegetal principal ("alface", "tomate").
        - grams: peso estimado da porção visível, em gramas (ml para bebidas). Use o prato, talheres e copos como referência.
        - kcal, protein_g, carbs_g, fat_g: valores estimados PARA ESSA PORÇÃO (não por 100 g).
        - confidence: de 0 a 1, o quanto você tem certeza da identificação.

        Regras:
        - Separe itens diferentes (arroz e feijão são dois itens; salada mista pode ser um item).
        - Não invente o que não aparece. Molhos e óleo só se estiverem visíveis.
        - Se a foto não mostrar comida, responda is_food = false e items vazio.
        - notes: uma frase curta em português se houver algo a avisar (ex.: "porção parcialmente coberta"), senão vazio.
        TXT;

    public function analyzeText(string $text): array
    {
        $num = ['type' => 'NUMBER'];
        $body = [
            'systemInstruction' => ['parts' => [['text' => 'Refeição descrita pelo usuário (pt-BR, pode ter erros de ditado). Liste cada comida/bebida citada. '
                .'name: nome curto; search_term: termo de busca na Tabela TACO ("arroz cozido", "feijão carioca cozido"); quantity e unit: '
                .'quantidade e medida ditas; grams: gramas (ml) totais; kcal, protein_g, carbs_g, fat_g: totais. "uma colher de cada" vale para todos. '
                .'"colher" sem tipo = colher de servir (arroz, feijão, carnes, massas) ou colher de sopa (farofa, açúcar, granola, molhos). '
                .'Sem quantidade dita: 1 porção comum.']]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $text]]]],
            'generationConfig' => [
                'temperature' => 0.2,
                'responseMimeType' => 'application/json',
                'responseSchema' => ['type' => 'OBJECT', 'properties' => ['items' => ['type' => 'ARRAY', 'items' => [
                    'type' => 'OBJECT',
                    'properties' => ['name' => ['type' => 'STRING'], 'search_term' => ['type' => 'STRING'], 'quantity' => $num,
                        'unit' => ['type' => 'STRING', 'enum' => MealPhotoAnalyzer::UNITS], 'grams' => $num, 'kcal' => $num,
                        'protein_g' => $num, 'carbs_g' => $num, 'fat_g' => $num],
                    'required' => ['name', 'search_term', 'quantity', 'unit', 'grams', 'kcal', 'protein_g', 'carbs_g', 'fat_g'],
                ]]], 'required' => ['items']],
                ...(config('services.gemini.thinking_level') ? ['thinkingConfig' => ['thinkingLevel' => config('services.gemini.thinking_level')]] : []),
            ],
        ];

        return $this->send($body, function (Response $response, string $model) {
            $text = collect($response->json('candidates.0.content.parts', []))->reject(fn ($p) => ($p['thought'] ?? false) === true)->pluck('text')->implode('');
            $data = json_decode(trim($text), true);
            if (! is_array($data)) {
                throw new MealAiException("Gemini: resposta fora do formato ({$model})");
            }
            $items = collect($data['items'] ?? [])
                ->filter(fn ($i) => is_array($i) && trim((string) ($i['name'] ?? '')) !== '' && (float) ($i['grams'] ?? 0) > 0)
                ->take(self::MAX_ITEMS)
                ->map(function (array $i) {
                    $unit = in_array($i['unit'] ?? null, MealPhotoAnalyzer::UNITS, true) ? $i['unit'] : 'porção';

                    return [
                        'name' => mb_substr(trim($i['name']), 0, 120),
                        'search_term' => mb_substr(trim((string) ($i['search_term'] ?? $i['name'])), 0, 80),
                        'grams' => self::clamp($i['grams'], 1, 3000),
                        'kcal' => self::clamp($i['kcal'] ?? 0, 0, 5000),
                        'protein_g' => self::clamp($i['protein_g'] ?? 0, 0, 300),
                        'carbs_g' => self::clamp($i['carbs_g'] ?? 0, 0, 600),
                        'fat_g' => self::clamp($i['fat_g'] ?? 0, 0, 300),
                        'confidence' => null,
                        'portion' => in_array($unit, ['g', 'ml'], true) ? null : ['label' => $unit, 'count' => self::clamp($i['quantity'] ?? 1, 0.25, 50)],
                    ];
                })->values()->all();

            return ['model' => (string) ($response->json('modelVersion') ?: $model), 'is_food' => count($items) > 0, 'notes' => null, 'items' => $items,
                'raw' => ['result' => $data, 'usage' => $response->json('usageMetadata'), 'model' => $response->json('modelVersion')]];
        });
    }

    public function analyze(string $imageBytes, string $mimeType): array
    {
        $body = [
            'systemInstruction' => ['parts' => [['text' => self::PROMPT]]],
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['inlineData' => ['mimeType' => $mimeType, 'data' => base64_encode($imageBytes)]],
                    ['text' => 'Identifique os alimentos desta refeição e estime as porções.'],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0.2,
                'responseMimeType' => 'application/json',
                'responseSchema' => self::schema(),
                // Menos raciocínio = resposta mais rápida (o plano gratuito já é lento em horários de pico).
                ...(config('services.gemini.thinking_level') ? ['thinkingConfig' => ['thinkingLevel' => config('services.gemini.thinking_level')]] : []),
            ],
        ];

        return $this->send($body, fn (Response $r, string $m) => $this->parse($r, $m));
    }

    /** Envia para o Gemini, trocando de modelo se um estiver sobrecarregado. */
    private function send(array $body, callable $parse): array
    {
        $key = config('services.gemini.api_key');
        $models = config('services.gemini.models');
        if (! $key || ! $models) {
            throw new MealAiException('Gemini não configurado', 'A análise com IA não está configurada neste servidor.');
        }

        $lastError = 'sem modelos';
        foreach ($models as $model) {
            try {
                $response = $this->request($model, $key, $body);
            } catch (ConnectionException $e) {
                $lastError = "{$model}: {$e->getMessage()}";

                continue;
            }

            // Sobrecarga ou cota: tenta o próximo modelo da lista.
            if (in_array($response->status(), [429, 500, 503, 504], true)) {
                $lastError = "{$model}: HTTP {$response->status()} ".mb_substr((string) $response->json('error.message'), 0, 200);
                Log::info('Gemini indisponível, tentando o próximo modelo', ['model' => $model, 'status' => $response->status()]);

                continue;
            }
            if (! $response->successful()) {
                throw new MealAiException("{$model}: HTTP {$response->status()} ".mb_substr((string) $response->json('error.message'), 0, 300));
            }

            return $parse($response, $model);
        }

        throw new MealAiException("Gemini indisponível ({$lastError})", 'O serviço de análise está sobrecarregado ou atingiu o limite de uso. Tente de novo em alguns minutos ou registre manualmente.');
    }

    private function request(string $model, string $key, array $body): Response
    {
        $request = Http::withHeaders(['x-goog-api-key' => $key])
            ->acceptJson()
            ->timeout(config('services.meal_ai.timeout', 45))
            ->connectTimeout(8);
        if ($ca = config('services.meal_ai.ca_bundle')) {
            $request = $request->withOptions(['verify' => $ca]);
        }

        return $request->post(rtrim(config('services.gemini.base_url'), '/')."/models/{$model}:generateContent", $body);
    }

    private function parse(Response $response, string $model): array
    {
        if ($reason = $response->json('promptFeedback.blockReason')) {
            throw new MealAiException("Gemini bloqueou a imagem: {$reason}", 'A foto não pôde ser analisada. Tente outra foto ou registre manualmente.');
        }

        // Junta as partes de texto da resposta (ignora "pensamentos" do modelo, se vierem).
        $text = collect($response->json('candidates.0.content.parts', []))
            ->reject(fn ($p) => ($p['thought'] ?? false) === true)
            ->pluck('text')->filter()->implode('');
        $data = json_decode(trim($text), true);
        if (! is_array($data)) {
            throw new MealAiException("Gemini: resposta fora do formato ({$model})");
        }

        $items = collect($data['items'] ?? [])
            ->filter(fn ($i) => is_array($i) && trim((string) ($i['name'] ?? '')) !== '')
            ->take(self::MAX_ITEMS)
            ->map(fn (array $i) => [
                'name' => mb_substr(trim((string) $i['name']), 0, 120),
                'search_term' => mb_substr(trim((string) ($i['search_term'] ?? $i['name'])), 0, 80),
                'grams' => self::clamp($i['grams'] ?? 0, 1, 2000),
                'kcal' => self::clamp($i['kcal'] ?? 0, 0, 3000),
                'protein_g' => self::clamp($i['protein_g'] ?? 0, 0, 300),
                'carbs_g' => self::clamp($i['carbs_g'] ?? 0, 0, 500),
                'fat_g' => self::clamp($i['fat_g'] ?? 0, 0, 300),
                'confidence' => isset($i['confidence']) && is_numeric($i['confidence']) ? self::clamp($i['confidence'], 0, 1) : null,
            ])
            ->values()
            ->all();

        return [
            'model' => (string) ($response->json('modelVersion') ?: $model),
            'is_food' => (bool) ($data['is_food'] ?? count($items) > 0),
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'items' => $items,
            // Guardamos só o resultado e o consumo, nunca a imagem.
            'raw' => ['result' => $data, 'usage' => $response->json('usageMetadata'), 'model' => $response->json('modelVersion')],
        ];
    }

    private static function clamp(mixed $v, float $min, float $max): float
    {
        return round(min($max, max($min, is_numeric($v) ? (float) $v : 0)), 2);
    }

    /** Esquema da resposta (subconjunto OpenAPI aceito pelo Gemini). */
    private static function schema(): array
    {
        $number = ['type' => 'NUMBER'];

        return [
            'type' => 'OBJECT',
            'properties' => [
                'is_food' => ['type' => 'BOOLEAN'],
                'items' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'name' => ['type' => 'STRING'],
                            'search_term' => ['type' => 'STRING'],
                            'grams' => $number,
                            'kcal' => $number,
                            'protein_g' => $number,
                            'carbs_g' => $number,
                            'fat_g' => $number,
                            'confidence' => $number,
                        ],
                        'required' => ['name', 'search_term', 'grams', 'kcal', 'protein_g', 'carbs_g', 'fat_g', 'confidence'],
                    ],
                ],
                'notes' => ['type' => 'STRING'],
            ],
            'required' => ['is_food', 'items'],
        ];
    }
}
