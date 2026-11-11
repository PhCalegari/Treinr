<?php

namespace App\Services\Food;

use App\Models\Food;
use App\Models\FoodPortion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Busca de alimentos, nesta ordem:
 *   1. alimentos do próprio usuário;
 *   2. TACO (alimentos brasileiros genéricos e preparações caseiras);
 *   3. produtos industrializados (cache local + Open Food Facts).
 * Tudo o que vem da OFF é guardado no catálogo local.
 */
class FoodCatalog
{
    /** Palavras ignoradas na busca ("arroz de forno" busca "arroz" e "forno"). */
    public const STOPWORDS = ['de', 'da', 'do', 'das', 'dos', 'com', 'e', 'em', 'a', 'o'];

    public function __construct(private readonly OpenFoodFactsClient $off) {}

    /**
     * @return array{items: list<array>, online: bool}
     */
    public function search(User $user, string $query): array
    {
        $words = $this->words($query);
        $local = $this->localMatches($user, $query);

        // A OFF limita ~10 buscas/min por servidor: guarda a resposta de cada termo por 24 h.
        $key = 'off-search:'.md5(implode(' ', $words));
        $remote = Cache::get($key);
        if ($remote === null) {
            $remote = $this->off->search($query);
            if ($remote !== null) {
                Cache::put($key, $remote, now()->addDay());
            }
        }
        $cached = $this->cacheMany($remote ?? []);

        $items = $local->concat($cached)->unique('id')->values()->map->toOption()->all();

        return ['items' => $items, 'online' => $remote !== null];
    }

    /**
     * Busca só no banco local (alimentos do usuário, TACO e produtos já guardados).
     * $source limita a uma origem (ex.: 'taco' na análise de foto, que não usa a OFF).
     *
     * @return Collection<int, Food>
     */
    public function localMatches(User $user, string $query, int $limit = 25, ?string $source = null): Collection
    {
        $words = $this->words($query);
        if (! $words) {
            return new Collection;
        }

        return Food::query()->visibleTo($user)
            ->when($source, fn ($q) => $q->where('source', $source))
            ->where(function ($q) use ($words, $query) {
                // Todas as palavras precisam aparecer no nome (em qualquer ordem): "arroz cozido"
                // encontra "Arroz, tipo 1, cozido". A marca também conta.
                $q->where(function ($all) use ($words) {
                    foreach ($words as $w) {
                        $all->where('name', 'like', '%'.$w.'%');
                    }
                })->orWhere('brand', 'like', '%'.$query.'%');
            })
            ->orderByRaw("CASE WHEN created_by_user_id IS NOT NULL THEN 0 WHEN source = 'taco' THEN 1 ELSE 2 END")
            // Nome que começa pela 1ª palavra buscada vem antes: "leite" acha "Leite, de vaca" antes de "Canjica, com leite".
            ->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', [$words[0].'%'])
            // Versões cruas por último: no dia a dia registramos o feijão cozido, não o cru.
            ->orderByRaw("CASE WHEN name LIKE '%, cru' OR name LIKE '%, crua' OR name LIKE '%, cru,%' OR name LIKE '%, crua,%' THEN 1 ELSE 0 END")
            ->orderByRaw('LENGTH(name)') // nomes curtos primeiro: o alimento mais genérico aparece antes
            ->limit($limit)
            ->with('portions')
            ->get();
    }

    /** @return list<string> */
    private function words(string $query): array
    {
        $words = preg_split('/[\s,]+/u', mb_strtolower(trim($query))) ?: [];
        $words = array_values(array_filter($words, fn ($w) => $w !== '' && ! in_array($w, self::STOPWORDS, true)));

        // Ignora gênero/plural no fim das palavras: "moida" encontra "moído", "cozidas" encontra "cozido".
        $words = array_map(fn ($w) => mb_strlen($w) >= 5 ? preg_replace('/(as|os|a|o|s)$/u', '', $w) : $w, $words);

        return $words ?: [mb_strtolower(trim($query))];
    }

    /**
     * @return array{food: ?array, status: 'found'|'not_found'|'unavailable'}
     */
    public function byBarcode(string $barcode): array
    {
        $cached = Food::where('source', 'open_food_facts')->where('external_code', $barcode)->first();
        $fresh = $cached && $cached->fetched_at?->gt(now()->subDays(config('services.openfoodfacts.cache_days', 30)));
        if ($fresh) {
            return ['food' => $cached->toOption(), 'status' => 'found'];
        }

        $product = $this->off->product($barcode);
        if ($product === false) {
            // Sem conexão com a OFF: usa o cache antigo, se houver.
            return $cached ? ['food' => $cached->toOption(), 'status' => 'found'] : ['food' => null, 'status' => 'unavailable'];
        }
        if ($product === null) {
            return ['food' => null, 'status' => 'not_found'];
        }

        return ['food' => $this->cacheOne($product)->toOption(), 'status' => 'found'];
    }

    public function createCustom(User $user, array $data): Food
    {
        return Food::create([
            'source' => 'custom',
            'external_code' => null,
            'name' => $data['name'],
            'brand' => $data['brand'] ?? null,
            'unit' => $data['unit'] ?? 'g',
            'kcal_per_100' => $data['kcal'],
            'protein_per_100' => $data['protein'] ?? 0,
            'carbs_per_100' => $data['carbs'] ?? 0,
            'fat_per_100' => $data['fat'] ?? 0,
            'created_by_user_id' => $user->id,
        ]);
    }

    /** @param list<array> $products */
    private function cacheMany(array $products): Collection
    {
        return collect($products)->map(fn (array $p) => $this->cacheOne($p));
    }

    private function cacheOne(array $p): Food
    {
        $food = Food::updateOrCreate(
            ['source' => 'open_food_facts', 'external_code' => $p['code']],
            [
                'name' => $p['name'],
                'brand' => $p['brand'],
                'unit' => $p['unit'],
                'kcal_per_100' => $p['kcal'],
                'protein_per_100' => $p['protein'],
                'carbs_per_100' => $p['carbs'],
                'fat_per_100' => $p['fat'],
                'fetched_at' => now(),
            ],
        );

        // Porção do rótulo vira uma medida do produto (substitui a anterior).
        FoodPortion::where('food_id', $food->id)->where('source', 'label')->delete();
        if (! empty($p['serving'])) {
            FoodPortion::create([
                'food_id' => $food->id,
                'label' => $p['serving']['label'],
                'grams' => $p['serving']['grams'],
                'source' => 'label',
            ]);
        }

        return $food->load('portions');
    }
}
