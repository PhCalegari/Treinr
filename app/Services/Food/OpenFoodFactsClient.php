<?php

namespace App\Services\Food;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente da Open Food Facts (https://openfoodfacts.github.io/openfoodfacts-server/api/).
 * Dados sob ODbL (banco) / DbCL (conteúdo); exige atribuição no app.
 *
 * Retorna alimentos já normalizados, com valores por 100 g (ou 100 ml):
 *   ['code', 'name', 'brand', 'unit', 'kcal', 'protein', 'carbs', 'fat']
 * Produtos sem calorias informadas são descartados (não dá para registrar no diário).
 * Falhas de rede/serviço não derrubam o app: busca devolve null e produto devolve false.
 */
class OpenFoodFactsClient
{
    private const FIELDS = 'code,product_name,product_name_pt,generic_name_pt,brands,quantity,serving_size,serving_quantity,nutriments';

    /** @return list<array>|null null = serviço indisponível (diferente de "nenhum resultado") */
    public function search(string $query, int $limit = 20): ?array
    {
        try {
            $response = $this->http()->get(config('services.openfoodfacts.search_url'), [
                'q' => $query,
                'page_size' => $limit,
                'langs' => 'pt,en',
                'fields' => self::FIELDS,
            ]);
            if (! $response->successful()) {
                Log::warning('Open Food Facts: busca falhou', ['status' => $response->status()]);

                return null;
            }

            return collect($response->json('hits', []))
                ->map(fn (array $p) => $this->normalize($p))
                ->filter()
                ->values()
                ->all();
        } catch (Throwable $e) {
            Log::warning('Open Food Facts: busca indisponível', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array|null|false null = produto não encontrado / sem dados; false = serviço indisponível
     */
    public function product(string $barcode): array|null|false
    {
        try {
            $response = $this->http()->get(config('services.openfoodfacts.product_url').'/'.rawurlencode($barcode), [
                'fields' => self::FIELDS,
            ]);
            if ($response->status() === 404) {
                return null;
            }
            if (! $response->successful()) {
                Log::warning('Open Food Facts: produto falhou', ['status' => $response->status()]);

                return false;
            }
            $product = $response->json('product');

            return is_array($product) ? $this->normalize($product + ['code' => $barcode]) : null;
        } catch (Throwable $e) {
            Log::warning('Open Food Facts: produto indisponível', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function normalize(array $p): ?array
    {
        $n = $p['nutriments'] ?? [];
        $kcal = $this->number($n['energy-kcal_100g'] ?? null);
        if ($kcal === null && ($kj = $this->number($n['energy-kj_100g'] ?? $n['energy_100g'] ?? null)) !== null) {
            $kcal = $kj / 4.184; // converte kJ -> kcal
        }

        $name = trim((string) ($p['product_name_pt'] ?? '') ?: (string) ($p['product_name'] ?? '') ?: (string) ($p['generic_name_pt'] ?? ''));
        $code = (string) ($p['code'] ?? '');

        if ($kcal === null || $name === '' || $code === '') {
            return null;
        }

        // Dados da comunidade às vezes vêm errados (ex.: 12000 kcal/100 g). Descarta o impossível:
        // nada passa de ~900 kcal/100 g (gordura pura) e nenhum macro passa de 100 g em 100 g.
        $protein = $this->number($n['proteins_100g'] ?? null) ?? 0;
        $carbs = $this->number($n['carbohydrates_100g'] ?? null) ?? 0;
        $fat = $this->number($n['fat_100g'] ?? null) ?? 0;
        if ($kcal > 900 || $protein > 100 || $carbs > 100 || $fat > 100 || $protein + $carbs + $fat > 105) {
            return null;
        }

        $brands = $p['brands'] ?? null;
        $brand = is_array($brands) ? ($brands[0] ?? null) : (is_string($brands) ? trim(explode(',', $brands)[0]) : null);
        $quantity = mb_strtolower((string) ($p['quantity'] ?? ''));
        $unit = preg_match('/\d\s*(ml|l|litros?)\b/u', $quantity) ? 'ml' : 'g';

        return [
            'code' => $code,
            'name' => mb_substr($name, 0, 255),
            'brand' => $brand ? mb_substr($brand, 0, 255) : null,
            'unit' => $unit,
            'kcal' => round(max(0, $kcal), 2),
            'protein' => round(max(0, $this->number($n['proteins_100g'] ?? null) ?? 0), 2),
            'carbs' => round(max(0, $this->number($n['carbohydrates_100g'] ?? null) ?? 0), 2),
            'fat' => round(max(0, $this->number($n['fat_100g'] ?? null) ?? 0), 2),
            'serving' => $this->serving($p),
        ];
    }

    /**
     * Porção informada no rótulo ("1 fatia (25 g)") -> ['label' => '1 fatia', 'grams' => 25].
     */
    private function serving(array $p): ?array
    {
        $grams = $this->number($p['serving_quantity'] ?? null);
        if ($grams === null || $grams <= 0 || $grams > 2000) {
            return null;
        }
        // Tira o "(25 g)" do fim; se sobrar só número/unidade, usa um nome genérico.
        $label = trim(preg_replace('/\s*\(.*\)\s*$/u', '', (string) ($p['serving_size'] ?? '')));
        $label = preg_replace('/\bservings?\b/iu', 'porção', $label); // alguns rótulos vêm em inglês
        if ($label === '' || preg_match('/^[\d.,\s]*(g|ml|gr|gramas|porção)?$/iu', $label)) {
            $label = 'porção do rótulo';
        }

        return ['label' => mb_substr(mb_strtolower($label), 0, 60), 'grams' => round($grams, 1)];
    }

    private function http(): PendingRequest
    {
        $request = Http::withUserAgent(config('services.openfoodfacts.user_agent'))
            ->acceptJson()
            ->timeout(config('services.openfoodfacts.timeout', 8))
            ->connectTimeout(5)
            ->retry(1, 300, throw: false);

        if ($ca = config('services.openfoodfacts.ca_bundle')) {
            $request = $request->withOptions(['verify' => $ca]);
        }

        return $request;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
