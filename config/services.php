<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],


    // Open Food Facts: consultado só pelo servidor. A API exige um User-Agent que identifique o app.
    // Limites públicos: ~100 req/min (produto) e ~10 req/min (busca). Resultados ficam em cache na tabela foods.
    'openfoodfacts' => [
        'user_agent' => env('OFF_USER_AGENT', 'Treinr/0.1 (contato não configurado)'),
        'product_url' => env('OFF_PRODUCT_URL', 'https://world.openfoodfacts.org/api/v2/product'),
        'search_url' => env('OFF_SEARCH_URL', 'https://search.openfoodfacts.org/search'),
        'timeout' => (int) env('OFF_TIMEOUT', 8),
        'cache_days' => (int) env('OFF_CACHE_DAYS', 30),
        // Arquivo de certificados para HTTPS, se o PHP local não tiver um configurado (comum no Windows).
        'ca_bundle' => env('HTTP_CA_BUNDLE'),
    ],

    // Análise de foto do prato. Só o servidor fala com a IA; a chave nunca vai para o navegador.
    'meal_ai' => [
        'provider' => env('MEAL_AI_PROVIDER', 'gemini'),
        'daily_limit' => (int) env('MEAL_AI_DAILY_LIMIT', 30), // análises por usuário por dia
        'timeout' => (int) env('MEAL_AI_TIMEOUT', 40), // por modelo
        'ca_bundle' => env('HTTP_CA_BUNDLE'),
    ],

    // Treino montado pela IA (usa o mesmo provedor e a mesma chave de MEAL_AI_PROVIDER).
    'workout_ai' => [
        'daily_limit' => (int) env('WORKOUT_AI_DAILY_LIMIT', 5), // treinos por usuário por dia
    ],

    // Apoio ao desenvolvedor via PIX (aparece de vez em quando no app). Sem chave = desligado.
    'pix' => [
        'key' => env('PIX_KEY'),
        'name' => env('PIX_NAME', 'Treinr'),
        'city' => env('PIX_CITY', 'SAO PAULO'),
        'description' => env('PIX_DESCRIPTION', 'Apoio ao Treinr'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'models' => array_values(array_filter(array_map('trim', explode(',', (string) env('OPENAI_MODELS', 'gpt-5.4-mini'))))),
        'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'none'), // sem raciocínio = menos tokens
        'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS', 600),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        // Em ordem de preferência; se um estiver sobrecarregado (503/429), tenta o próximo.
        'models' => array_values(array_filter(array_map('trim', explode(',', (string) env('GEMINI_MODELS', 'gemini-3.5-flash-lite,gemini-3.5-flash'))))),
        'thinking_level' => env('GEMINI_THINKING_LEVEL', 'low'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
    ],

];
