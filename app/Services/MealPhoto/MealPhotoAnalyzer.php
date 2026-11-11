<?php

namespace App\Services\MealPhoto;

/**
 * Provedor de IA que olha a foto de uma refeição e estima os alimentos.
 * Trocar de provedor (Gemini, Claude...) = nova implementação + MEAL_AI_PROVIDER no .env.
 */
interface MealPhotoAnalyzer
{
    /**
     * @return array{
     *     model: string,
     *     is_food: bool,
     *     notes: ?string,
     *     items: list<array{name: string, search_term: string, grams: float, kcal: float, protein_g: float, carbs_g: float, fat_g: float, confidence: ?float}>,
     *     raw: array
     * }
     *
     * @throws MealAiException
     */
    public function analyze(string $imageBytes, string $mimeType): array;

    /**
     * Lê uma refeição descrita em texto (ditada ou digitada): "arroz e feijão, uma colher de cada".
     * Mesmo formato de analyze(), e cada item traz também a medida falada:
     *   'portion' => ['label' => 'colher de servir', 'count' => 1.0] (ou null quando veio em g/ml).
     *
     * @throws MealAiException
     */
    public function analyzeText(string $text): array;

    /** Medidas que a IA pode usar na resposta (as mesmas da tabela de medidas do IBGE). */
    public const UNITS = ['g', 'ml', 'colher de sopa', 'colher de servir', 'colher de chá', 'concha', 'escumadeira', 'unidade',
        'fatia', 'pedaço', 'copo', 'xícara', 'prato', 'porção'];
}
