<?php

namespace Tests\Feature\Nutrition;

use App\Models\Food;
use App\Models\User;
use Database\Seeders\TacoFoodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TacoFoodsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TacoFoodSeeder::class);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['hits' => []])]); // OFF sem resultados
    }

    public function test_imports_taco_with_official_values_and_is_idempotent()
    {
        $this->seed(TacoFoodSeeder::class);

        $this->assertSame(593, Food::where('source', 'taco')->count()); // 591 + 2 leites com valores da USDA
        $milk = Food::where('name', 'Leite, de vaca, integral')->firstOrFail();
        $this->assertSame(61.0, $milk->kcal_per_100);
        $this->assertContains('copo americano', $milk->portions->pluck('label')->all());

        $beans = Food::where('source', 'taco')->where('name', 'Feijão, carioca, cozido')->firstOrFail();
        $this->assertSame(76.0, $beans->kcal_per_100);
        $this->assertSame(4.8, $beans->protein_per_100);
        $this->assertSame(13.6, $beans->carbs_per_100);
        $this->assertSame('Leguminosas e derivados', $beans->category);

        $chicken = Food::where('name', 'Frango, peito, sem pele, grelhado')->firstOrFail();
        $this->assertSame(159.0, $chicken->kcal_per_100);
        $this->assertSame(32.0, $chicken->protein_per_100);
    }

    public function test_search_matches_words_in_any_order_and_prefers_cooked()
    {
        $user = User::factory()->create();

        $names = fn (string $q) => collect($this->actingAs($user)->getJson(route('foods.search', ['q' => $q]))->json('items'))->pluck('name');

        $this->assertSame('Arroz, tipo 1, cozido', $names('arroz cozido')->first());
        $this->assertContains('Frango, peito, sem pele, grelhado', $names('frango grelhado')->all());
        // "feijão": versões cozidas antes das cruas.
        $beans = $names('feijão')->values();
        $firstRaw = $beans->search(fn ($n) => str_ends_with($n, ', cru'));
        $lastCooked = $beans->filter(fn ($n) => str_contains($n, 'cozido'))->keys()->last();
        $this->assertLessThan($firstRaw, $lastCooked);
    }

    public function test_own_foods_come_before_taco()
    {
        $user = User::factory()->create();
        Food::create(['source' => 'custom', 'name' => 'Arroz da vovó cozido', 'unit' => 'g', 'created_by_user_id' => $user->id,
            'kcal_per_100' => 140, 'protein_per_100' => 2, 'carbs_per_100' => 30, 'fat_per_100' => 1]);

        $items = $this->actingAs($user)->getJson(route('foods.search', ['q' => 'arroz cozido']))->json('items');

        $this->assertSame('Arroz da vovó cozido', $items[0]['name']);
        $this->assertSame('taco', $items[1]['source']);
    }

    public function test_household_measures_from_ibge_are_attached_to_taco_foods()
    {
        $rice = Food::where('name', 'Arroz, tipo 1, cozido')->firstOrFail();
        $portions = $rice->portions->pluck('grams', 'label');

        $this->assertSame(25.0, $portions['colher de sopa']);
        $this->assertSame(45.0, $portions['colher de servir']);
        $this->assertArrayNotHasKey('grama', $portions->all());

        $beans = Food::where('name', 'Feijão, carioca, cozido')->firstOrFail();
        $this->assertSame(140.0, $beans->portions->firstWhere('label', 'concha')->grams);

        // A busca devolve as medidas junto com o alimento.
        $user = User::factory()->create();
        $item = collect($this->actingAs($user)->getJson(route('foods.search', ['q' => 'feijão carioca cozido']))->json('items'))
            ->firstWhere('name', 'Feijão, carioca, cozido');
        $this->assertContains('concha', array_column($item['portions'], 'label'));
    }

    public function test_diary_item_keeps_the_household_measure_used()
    {
        $user = User::factory()->create();
        $beans = Food::where('name', 'Feijão, carioca, cozido')->firstOrFail();

        $this->actingAs($user)->post(route('diary-items.store'), [
            'meal_date' => '2026-11-03', 'meal_type' => 'lunch', 'food_id' => $beans->id,
            'quantity' => 280, 'portion_label' => 'concha', 'portion_count' => 2,
        ])->assertSessionHasNoErrors();

        $item = \App\Models\MealItem::firstOrFail();
        $this->assertSame('concha', $item->portion_label);
        $this->assertSame(2.0, $item->portion_count);
        $this->assertSame(212.8, $item->kcal); // 76 kcal/100 g × 280 g

        // O diário mostra a medida usada.
        $this->get(route('diary.index', ['date' => '2026-11-03']))->assertInertia(fn ($p) => $p
            ->where('meals.2.items.0.portion_label', 'concha'));

        // Corrigir os gramas à mão desfaz a medida.
        $this->patch(route('diary-items.update', $item), ['quantity' => 200]);
        $this->assertNull($item->fresh()->portion_label);
    }

    public function test_label_serving_size_from_open_food_facts()
    {
        $client = app(\App\Services\Food\OpenFoodFactsClient::class);
        $base = ['code' => '9', 'product_name' => 'Pão de forma', 'nutriments' => ['energy-kcal_100g' => 250]];

        $this->assertSame(['label' => '2 fatias', 'grams' => 50.0], $client->normalize($base + ['serving_size' => '2 fatias (50 g)', 'serving_quantity' => 50])['serving']);
        $this->assertSame('porção do rótulo', $client->normalize($base + ['serving_size' => '30 g', 'serving_quantity' => '30'])['serving']['label']);
        $this->assertSame('porção do rótulo', $client->normalize($base + ['serving_size' => '1 serving (50 g)', 'serving_quantity' => 50])['serving']['label']);
        $this->assertNull($client->normalize($base)['serving']);
    }

    public function test_impossible_open_food_facts_values_are_discarded()
    {
        $client = app(\App\Services\Food\OpenFoodFactsClient::class);

        $this->assertNull($client->normalize(['code' => '1', 'product_name' => 'Ovo frito', 'nutriments' => ['energy-kcal_100g' => 12000]]));
        $this->assertNull($client->normalize(['code' => '2', 'product_name' => 'X', 'nutriments' => ['energy-kcal_100g' => 300, 'proteins_100g' => 150]]));
        $this->assertNotNull($client->normalize(['code' => '3', 'product_name' => 'Azeite', 'nutriments' => ['energy-kcal_100g' => 884, 'fat_100g' => 100]]));
    }
}
