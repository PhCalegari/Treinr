<?php

namespace Tests\Feature\Nutrition;

use App\Models\MealItem;
use App\Models\MealPhotoAnalysis;
use App\Models\User;
use Database\Seeders\TacoFoodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MealPhotoTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://gemini.test/v1beta/models/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TacoFoodSeeder::class);
        Http::preventStrayRequests();
        config([
            'services.meal_ai.provider' => 'gemini',
            'services.gemini.api_key' => 'test-key',
            'services.gemini.models' => ['modelo-a', 'modelo-b'],
            'services.gemini.base_url' => 'https://gemini.test/v1beta',
            'services.meal_ai.daily_limit' => 3,
        ]);
    }

    /** JPEG mínimo com um bloco EXIF que contém uma "localização". */
    private function photo(): UploadedFile
    {
        $exif = "Exif\x00\x00GPS-SECRET-LOCATION";
        $jpeg = "\xFF\xD8"
            ."\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
            ."\xFF\xE1".pack('n', 2 + strlen($exif)).$exif
            ."\xFF\xC0\x00\x11\x08\x00\x10\x00\x20\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01"
            ."\xFF\xDA\x00\x0C\x03\x01\x00\x02\x11\x03\x11\x00\x3F\x00abc\xFF\xD9";

        return UploadedFile::fake()->createWithContent('prato.jpg', $jpeg);
    }

    private function geminiReply(): array
    {
        $result = [
            'is_food' => true,
            'notes' => '',
            'items' => [
                ['name' => 'Arroz branco cozido', 'search_term' => 'arroz branco cozido', 'grams' => 150, 'kcal' => 190, 'protein_g' => 4, 'carbs_g' => 42, 'fat_g' => 0.4, 'confidence' => 0.95],
                ['name' => 'Feijão carioca', 'search_term' => 'feijão carioca cozido', 'grams' => 100, 'kcal' => 80, 'protein_g' => 5, 'carbs_g' => 14, 'fat_g' => 0.5, 'confidence' => 0.9],
                ['name' => 'Molho especial', 'search_term' => 'molho especial da casa', 'grams' => 30, 'kcal' => 60, 'protein_g' => 0.5, 'carbs_g' => 3, 'fat_g' => 5, 'confidence' => 0.4],
            ],
        ];

        return [
            'candidates' => [['content' => ['parts' => [['text' => json_encode($result)]]]]],
            'usageMetadata' => ['totalTokenCount' => 1200],
            'modelVersion' => 'modelo-a',
        ];
    }

    public function test_analysis_suggests_items_matched_to_taco_without_storing_the_photo()
    {
        Http::fake([self::URL.'modelo-a:generateContent' => Http::response($this->geminiReply())]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('meal-photos.analyze'), ['photo' => $this->photo()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('is_food', true)
            ->assertJsonPath('remaining_today', 2)
            ->assertJsonCount(3, 'items');

        $items = collect($response->json('items'))->keyBy('name');
        $this->assertSame('Arroz, tipo 1, cozido', $items['Arroz branco cozido']['food']['name']); // "branco" descartado na busca
        $this->assertSame('Feijão, carioca, cozido', $items['Feijão carioca']['food']['name']);
        $this->assertNull($items['Molho especial']['food']); // sem TACO: usa a estimativa da IA
        $this->assertEquals(60, $items['Molho especial']['estimate']['kcal']);

        // A chave vai no cabeçalho, e a imagem sai sem os metadados.
        Http::assertSent(function (Request $r) {
            $image = base64_decode($r['contents'][0]['parts'][0]['inlineData']['data']);

            return $r->hasHeader('x-goog-api-key', 'test-key')
                && ! str_contains($image, 'GPS-SECRET')
                && $r['generationConfig']['responseMimeType'] === 'application/json';
        });

        $analysis = MealPhotoAnalysis::firstOrFail();
        $this->assertSame('completed', $analysis->status);
        $this->assertNull($analysis->image_path);
        $this->assertStringNotContainsString('inlineData', json_encode($analysis->raw_response));
    }

    public function test_falls_back_to_next_model_when_overloaded()
    {
        Http::fake([
            self::URL.'modelo-a:generateContent' => Http::response(['error' => ['message' => 'high demand']], 503),
            self::URL.'modelo-b:generateContent' => Http::response(['modelVersion' => 'modelo-b'] + $this->geminiReply()),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('meal-photos.analyze'), ['photo' => $this->photo()], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('modelo-b', MealPhotoAnalysis::firstOrFail()->model);
    }

    public function test_friendly_error_when_ai_is_unavailable_and_daily_limit()
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'quota']], 429)]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('meal-photos.analyze'), ['photo' => $this->photo()], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('errors.photo.0', fn ($m) => str_contains($m, 'sobrecarregado'));
        $this->assertSame('failed', MealPhotoAnalysis::firstOrFail()->status);

        // Limite diário (3 no teste): tentativas que falharam também contam.
        $this->post(route('meal-photos.analyze'), ['photo' => $this->photo()], ['Accept' => 'application/json']);
        $this->post(route('meal-photos.analyze'), ['photo' => $this->photo()], ['Accept' => 'application/json']);
        $this->post(route('meal-photos.analyze'), ['photo' => $this->photo()], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('errors.photo.0', fn ($m) => str_contains($m, 'limite'));
        $this->assertSame(3, MealPhotoAnalysis::count());
    }

    public function test_not_configured_and_invalid_file()
    {
        config(['services.gemini.api_key' => null]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('meal-photos.analyze'), ['photo' => $this->photo()], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.photo.0', fn ($m) => str_contains($m, 'não está configurada'));
        $this->post(route('meal-photos.analyze'), ['photo' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_confirm_adds_reviewed_items_to_the_diary_once()
    {
        Http::fake([self::URL.'modelo-a:generateContent' => Http::response($this->geminiReply())]);
        $user = User::factory()->create();
        $items = collect($this->actingAs($user)->post(route('meal-photos.analyze'), ['photo' => $this->photo()], ['Accept' => 'application/json'])->json('items'))->keyBy('name');
        $analysis = MealPhotoAnalysis::firstOrFail();

        // Outra pessoa não confirma a análise.
        $this->actingAs(User::factory()->create())->post(route('meal-photos.confirm', $analysis), [
            'meal_date' => '2026-11-03', 'meal_type' => 'lunch', 'items' => [['id' => $items['Feijão carioca']['id'], 'grams' => 100]],
        ])->assertNotFound();

        // O usuário corrige o arroz para 200 g, mantém o molho (60 g) e desmarca o feijão.
        $this->actingAs($user)->post(route('meal-photos.confirm', $analysis), [
            'meal_date' => '2026-11-03', 'meal_type' => 'lunch',
            'items' => [
                ['id' => $items['Arroz branco cozido']['id'], 'grams' => 200],
                ['id' => $items['Molho especial']['id'], 'grams' => 60],
            ],
        ])->assertRedirect(route('diary.index', ['date' => '2026-11-03']));

        $saved = MealItem::orderBy('food_name')->get();
        $this->assertCount(2, $saved);
        $this->assertSame(['photo', 'photo'], $saved->pluck('origin')->all());
        $this->assertSame([$analysis->id, $analysis->id], $saved->pluck('photo_analysis_id')->all());

        $rice = $saved->firstWhere('food_name', 'Arroz, tipo 1, cozido');
        $this->assertSame(256.0, $rice->kcal); // TACO: 128 kcal/100 g × 200 g
        $sauce = $saved->firstWhere('food_name', 'Molho especial');
        $this->assertSame(120.0, $sauce->kcal); // estimativa da IA (60 kcal em 30 g) × 2

        $this->assertSame('confirmed', $analysis->fresh()->status);
        $this->post(route('meal-photos.confirm', $analysis), [
            'meal_date' => '2026-11-03', 'meal_type' => 'lunch', 'items' => [['id' => $items['Feijão carioca']['id'], 'grams' => 100]],
        ])->assertSessionHasErrors('items');
        $this->assertSame(2, MealItem::count());
    }
}
