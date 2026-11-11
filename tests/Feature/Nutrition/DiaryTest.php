<?php

namespace Tests\Feature\Nutrition;

use App\Models\Food;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DiaryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Http::preventStrayRequests(); // nenhum teste chama a internet de verdade
    }

    private function fakeOff(): void
    {
        Http::fake([
            'search.openfoodfacts.org/*' => Http::response(['hits' => [
                ['code' => '7891000100103', 'product_name' => 'Leite Condensado', 'brands' => ['Moça'], 'quantity' => '395 g',
                    'nutriments' => ['energy-kcal_100g' => 325, 'proteins_100g' => 7.5, 'carbohydrates_100g' => 55, 'fat_100g' => 8]],
                ['code' => '0000000000001', 'product_name' => 'Sem calorias', 'nutriments' => []], // descartado
            ]]),
            'world.openfoodfacts.org/api/v2/product/7896004000855*' => Http::response(['product' => [
                'product_name' => 'Leite Integral', 'brands' => 'Itambé', 'quantity' => '1 L',
                'nutriments' => ['energy-kj_100g' => 250, 'proteins_100g' => 3, 'carbohydrates_100g' => 4.5, 'fat_100g' => 3],
            ]]),
            'world.openfoodfacts.org/api/v2/product/*' => Http::response(['status' => 0], 404),
        ]);
    }

    public function test_diary_shows_six_meals_with_zero_totals()
    {
        $this->actingAs($this->user)->get(route('diary.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('diary/Index')
                ->has('meals', 6)
                ->where('meals.0.label', 'Café da manhã')
                ->where('meals.5.label', 'Ceia')
                ->where('totals.kcal', 0));
    }

    public function test_search_combines_off_results_and_caches_them()
    {
        $this->fakeOff();

        $this->actingAs($this->user)->getJson(route('foods.search', ['q' => 'leite']))
            ->assertOk()
            ->assertJsonPath('online', true)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.name', 'Leite Condensado')
            ->assertJsonPath('items.0.brand', 'Moça')
            ->assertJsonPath('items.0.per_100.kcal', 325);

        $this->assertDatabaseHas('foods', ['source' => 'open_food_facts', 'external_code' => '7891000100103']);
    }

    public function test_search_works_with_local_cache_when_off_is_down()
    {
        Food::create(['source' => 'open_food_facts', 'external_code' => '1', 'name' => 'Leite em pó', 'unit' => 'g',
            'kcal_per_100' => 500, 'protein_per_100' => 25, 'carbs_per_100' => 38, 'fat_per_100' => 26, 'fetched_at' => now()]);
        Http::fake(['*' => Http::response('erro', 503)]);

        $this->actingAs($this->user)->getJson(route('foods.search', ['q' => 'leite']))
            ->assertOk()
            ->assertJsonPath('online', false)
            ->assertJsonPath('items.0.name', 'Leite em pó');
    }

    public function test_barcode_lookup_converts_kj_and_detects_ml()
    {
        $this->fakeOff();

        $this->actingAs($this->user)->getJson(route('foods.barcode', '7896004000855'))
            ->assertOk()
            ->assertJsonPath('food.name', 'Leite Integral')
            ->assertJsonPath('food.unit', 'ml')
            ->assertJsonPath('food.per_100.kcal', 59.75); // 250 kJ / 4.184

        // Segunda consulta usa o cache (sem nova chamada à OFF).
        Http::fake(['*' => Http::response('não deveria chamar', 500)]);
        $this->getJson(route('foods.barcode', '7896004000855'))->assertOk();
    }

    public function test_unknown_and_invalid_barcodes()
    {
        $this->fakeOff();
        $this->actingAs($this->user)->getJson(route('foods.barcode', '7890000000000'))->assertNotFound();
        $this->getJson(route('foods.barcode', '12ab'))->assertStatus(422); // formato inválido
    }

    public function test_add_food_from_catalog_computes_values_for_quantity()
    {
        $food = Food::create(['source' => 'custom', 'name' => 'Arroz cozido', 'unit' => 'g', 'created_by_user_id' => $this->user->id,
            'kcal_per_100' => 128, 'protein_per_100' => 2.5, 'carbs_per_100' => 28.1, 'fat_per_100' => 0.2]);

        $this->actingAs($this->user)->post(route('diary-items.store'), [
            'meal_date' => '2026-11-03', 'meal_type' => 'lunch', 'food_id' => $food->id, 'quantity' => 150,
        ])->assertRedirect(route('diary.index', ['date' => '2026-11-03']));

        $item = MealItem::firstOrFail();
        $this->assertSame(192.0, $item->kcal);       // 128 × 1,5
        $this->assertSame(3.75, $item->protein_g);
        $this->assertSame('search', $item->origin);
        $this->assertSame(Meal::idFor($this->user->id, '2026-11-03', 'lunch'), $item->meal_id);
    }

    public function test_manual_entry_meal_totals_and_day_totals()
    {
        $this->actingAs($this->user);
        $this->post(route('diary-items.store'), [
            'meal_date' => '2026-11-03', 'meal_type' => 'breakfast', 'food_name' => 'Pão francês', 'quantity' => 50,
            'kcal' => 150, 'protein_g' => 4, 'carbs_g' => 29, 'fat_g' => 1.5,
        ])->assertSessionHasNoErrors();
        $this->post(route('diary-items.store'), [
            'meal_date' => '2026-11-03', 'meal_type' => 'breakfast', 'food_name' => 'Café com leite', 'quantity' => 200, 'unit' => 'ml',
            'kcal' => 80, 'protein_g' => 4, 'carbs_g' => 6, 'fat_g' => 4,
        ]);
        $this->post(route('diary-items.store'), [
            'meal_date' => '2026-11-03', 'meal_type' => 'dinner', 'food_name' => 'Frango grelhado', 'quantity' => 120,
            'kcal' => 200, 'protein_g' => 38,
        ]);

        // Mesma refeição: um só registro de "café da manhã" no dia.
        $this->assertSame(2, Meal::count());

        $this->get(route('diary.index', ['date' => '2026-11-03']))->assertInertia(fn (Assert $p) => $p
            ->where('meals.0.totals.kcal', 230)
            ->has('meals.0.items', 2)
            ->where('meals.4.totals.protein_g', 38)
            ->where('totals.kcal', 430)
            ->where('totals.protein_g', 46)
            ->missing('recent'));
    }

    public function test_change_quantity_rescales_values_and_move_meal()
    {
        $this->actingAs($this->user)->post(route('diary-items.store'), [
            'meal_date' => '2026-11-03', 'meal_type' => 'lunch', 'food_name' => 'Feijão', 'quantity' => 100, 'kcal' => 76, 'protein_g' => 4.8,
        ]);
        $item = MealItem::firstOrFail();

        $this->patch(route('diary-items.update', $item), ['quantity' => 150])->assertSessionHasNoErrors();
        $this->assertSame(114.0, $item->fresh()->kcal);
        $this->assertSame(7.2, $item->fresh()->protein_g);

        $this->patch(route('diary-items.update', $item), ['meal_type' => 'dinner']);
        $this->assertSame(Meal::idFor($this->user->id, '2026-11-03', 'dinner'), $item->fresh()->meal_id);
    }

    public function test_validation_and_ownership()
    {
        $this->actingAs($this->user)->post(route('diary-items.store'), [
            'meal_date' => '2026-11-03', 'meal_type' => 'brunch', 'quantity' => -1,
        ])->assertSessionHasErrors(['meal_type', 'quantity', 'food_name', 'kcal']);

        $this->post(route('diary-items.store'), [
            'meal_date' => '2026-11-03', 'meal_type' => 'lunch', 'food_name' => 'X', 'quantity' => 10, 'kcal' => 10,
        ]);
        $item = MealItem::firstOrFail();

        $other = User::factory()->create();
        $this->actingAs($other)->patch(route('diary-items.update', $item), ['quantity' => 999])->assertNotFound();
        $this->delete(route('diary-items.destroy', $item))->assertNotFound();
        $this->get(route('diary.index', ['date' => '2026-11-03']))->assertInertia(fn (Assert $p) => $p->where('totals.kcal', 0));

        // Alimento próprio de outra pessoa não pode ser usado.
        $private = Food::create(['source' => 'custom', 'name' => 'Receita secreta', 'unit' => 'g', 'created_by_user_id' => $this->user->id,
            'kcal_per_100' => 100, 'protein_per_100' => 0, 'carbs_per_100' => 0, 'fat_per_100' => 0]);
        $this->post(route('diary-items.store'), [
            'meal_date' => '2026-11-03', 'meal_type' => 'lunch', 'food_id' => $private->id, 'quantity' => 100,
        ])->assertNotFound();
    }

    public function test_delete_item()
    {
        $this->actingAs($this->user)->post(route('diary-items.store'), [
            'meal_date' => '2026-11-03', 'meal_type' => 'supper', 'food_name' => 'Iogurte', 'quantity' => 170, 'kcal' => 100,
        ]);
        $item = MealItem::firstOrFail();
        $this->delete(route('diary-items.destroy', $item));
        $this->assertNull(MealItem::find($item->id));
        $this->assertNotNull(\DB::table('meal_items')->where('id', $item->id)->value('deleted_at'));
    }
}
