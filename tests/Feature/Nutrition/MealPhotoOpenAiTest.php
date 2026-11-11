<?php

namespace Tests\Feature\Nutrition;

use App\Models\MealPhotoAnalysis;
use App\Models\User;
use Database\Seeders\TacoFoodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MealPhotoOpenAiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://openai.test/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TacoFoodSeeder::class);
        Http::preventStrayRequests();
        config([
            'services.meal_ai.provider' => 'openai',
            'services.openai.api_key' => 'sk-test',
            'services.openai.models' => ['gpt-mini-teste'],
            'services.openai.base_url' => 'https://openai.test/v1',
            'services.openai.reasoning_effort' => 'none',
        ]);
    }

    private function photo(): UploadedFile
    {
        $jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
            ."\xFF\xC0\x00\x11\x08\x00\x10\x00\x20\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01"
            ."\xFF\xDA\x00\x0C\x03\x01\x00\x02\x11\x03\x11\x00\x3F\x00abc\xFF\xD9";

        return UploadedFile::fake()->createWithContent('prato.jpg', $jpeg);
    }

    private function reply(array $content, string $finish = 'stop'): array
    {
        return [
            'model' => 'gpt-mini-teste-2026',
            'choices' => [['message' => ['role' => 'assistant', 'content' => json_encode($content), 'refusal' => null], 'finish_reason' => $finish]],
            'usage' => ['prompt_tokens' => 562, 'completion_tokens' => 120],
        ];
    }

    public function test_compact_answer_is_translated_and_matched_to_taco()
    {
        Http::fake([self::URL => Http::response($this->reply(['f' => true, 'i' => [
            ['n' => 'arroz', 't' => 'arroz cozido', 'g' => 150, 'k' => 190, 'p' => 4, 'c' => 42, 'l' => 0.4, 's' => 0.95],
            ['n' => 'suco', 't' => 'suco de fruta', 'g' => 200, 'k' => 100, 'p' => 1, 'c' => 24, 'l' => 0, 's' => 0.5],
            ['n' => 'copo', 't' => 'copo', 'g' => 0, 'k' => 0, 'p' => 0, 'c' => 0, 'l' => 0, 's' => 0.2], // 0 g: ignorado
        ]]))]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('meal-photos.analyze'), ['photo' => $this->photo()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.name', 'Arroz')
            ->assertJsonPath('items.0.confidence', 0.95)
            ->assertJsonPath('items.0.estimate.kcal', 190)
            ->assertJsonPath('items.0.food.name', 'Arroz, tipo 1, cozido')
            ->assertJsonPath('items.1.food', null); // "suco de fruta" não vira "fruta-pão"

        // Pedido enxuto: imagem em detail "low", sem raciocínio, saída limitada e esquema estrito.
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer sk-test')
            && $r['messages'][1]['content'][0]['image_url']['detail'] === 'low'
            && str_starts_with($r['messages'][1]['content'][0]['image_url']['url'], 'data:image/jpeg;base64,')
            && $r['reasoning_effort'] === 'none'
            && $r['max_completion_tokens'] === 600
            && $r['response_format']['json_schema']['strict'] === true);

        $analysis = MealPhotoAnalysis::firstOrFail();
        $this->assertSame('gpt-mini-teste-2026', $analysis->model);
        $this->assertSame(562, $analysis->raw_response['usage']['prompt_tokens']); // consumo fica registrado
        $this->assertNotNull($response->json('items.0.id'));
    }

    public function test_quota_and_truncated_answers_fail_gracefully()
    {
        $user = User::factory()->create();

        Http::fake([self::URL => Http::response(['error' => ['code' => 'insufficient_quota', 'message' => 'quota']], 429)]);
        $this->actingAs($user)->post(route('meal-photos.analyze'), ['photo' => $this->photo()], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.photo.0', fn ($m) => str_contains($m, 'limite de uso'));

        Http::fake([self::URL => Http::response($this->reply(['f' => true, 'i' => []], 'length'))]);
        $this->post(route('meal-photos.analyze'), ['photo' => $this->photo()], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(['failed', 'failed'], MealPhotoAnalysis::pluck('status')->all());
    }

    public function test_diary_tells_the_page_which_provider_is_configured()
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('diary.index'))->assertInertia(fn ($p) => $p->where('photoAi', 'openai'));

        config(['services.openai.api_key' => null]);
        $this->get(route('diary.index'))->assertInertia(fn ($p) => $p->where('photoAi', null));
    }

    public function test_spoken_meal_uses_ibge_household_measures_and_saves_as_voice()
    {
        // "arroz e feijão, uma colher de cada, e um copo de leite" (a fala já chega transcrita pelo celular).
        Http::fake([self::URL => Http::response($this->reply(['i' => [
            ['n' => 'Arroz', 't' => 'arroz cozido', 'q' => 1, 'u' => 'colher de servir', 'g' => 40, 'k' => 50, 'p' => 1, 'c' => 11, 'l' => 0],
            ['n' => 'Feijão', 't' => 'feijão carioca cozido', 'q' => 2, 'u' => 'concha', 'g' => 250, 'k' => 190, 'p' => 12, 'c' => 34, 'l' => 1],
            ['n' => 'Leite', 't' => 'leite', 'q' => 1, 'u' => 'copo', 'g' => 200, 'k' => 120, 'p' => 6, 'c' => 10, 'l' => 6],
            ['n' => 'Molho da casa', 't' => 'molho especial', 'q' => 30, 'u' => 'g', 'g' => 30, 'k' => 60, 'p' => 0, 'c' => 3, 'l' => 5],
        ]]))]);
        $user = User::factory()->create();

        $items = collect($this->actingAs($user)->postJson(route('meal-texts.analyze'), ['text' => 'arroz e feijão, uma colher de cada, e um copo de leite'])
            ->assertOk()
            ->assertJsonPath('input_type', 'text')
            ->json('items'))->keyBy('name');

        // Gramas pela medida do IBGE do alimento da TACO (não pelo chute da IA).
        $this->assertSame(['label' => 'colher de servir', 'count' => 1, 'grams_each' => 45], $items['Arroz']['portion']);
        $this->assertEquals(45, $items['Arroz']['grams']);
        $this->assertEquals(280, $items['Feijão']['grams']); // 2 × concha (140 g)
        // "copo" genérico = copo americano; leite comum (não canjica nem leite em pó).
        $this->assertSame('Leite, de vaca, integral', $items['Leite']['food']['name']);
        $this->assertSame('copo americano', $items['Leite']['portion']['label']);
        $this->assertNull($items['Molho da casa']['portion']); // veio em gramas
        $this->assertNull($items['Molho da casa']['food']);

        // Só o texto foi para a IA, sem imagem.
        Http::assertSent(fn (Request $r) => $r['messages'][1]['content'] === 'arroz e feijão, uma colher de cada, e um copo de leite'
            && $r['response_format']['json_schema']['schema']['properties']['i']['items']['properties']['u']['enum'] === \App\Services\MealPhoto\MealPhotoAnalyzer::UNITS);

        // Confirma: arroz ajustado para 2 colheres; feijão com gramas digitados (perde a medida).
        $analysis = MealPhotoAnalysis::firstOrFail();
        $this->post(route('meal-photos.confirm', $analysis), [
            'meal_date' => now()->toDateString(), 'meal_type' => 'lunch',
            'items' => [
                ['id' => $items['Arroz']['id'], 'grams' => 90, 'portion_count' => 2],
                ['id' => $items['Feijão']['id'], 'grams' => 200, 'portion_count' => null],
            ],
        ])->assertRedirect();

        $saved = \App\Models\MealItem::orderBy('food_name')->get()->keyBy('food_name');
        $this->assertSame('voice', $saved['Arroz, tipo 1, cozido']->origin);
        $this->assertSame('colher de servir', $saved['Arroz, tipo 1, cozido']->portion_label);
        $this->assertSame(2.0, $saved['Arroz, tipo 1, cozido']->portion_count);
        $this->assertNull($saved['Feijão, carioca, cozido']->portion_label);
        $this->assertSame(200.0, $saved['Feijão, carioca, cozido']->quantity);
    }

    public function test_spoken_meal_validation_and_daily_limit()
    {
        config(['services.meal_ai.daily_limit' => 1]);
        Http::fake([self::URL => Http::response($this->reply(['i' => []]))]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('meal-texts.analyze'), ['text' => 'a'])->assertStatus(422);
        $this->postJson(route('meal-texts.analyze'), ['text' => 'hoje não comi nada'])->assertOk()->assertJsonCount(0, 'items');
        $this->postJson(route('meal-texts.analyze'), ['text' => 'arroz e feijão'])
            ->assertStatus(422)->assertJsonPath('errors.text.0', fn ($m) => str_contains($m, 'limite'));
    }
}
