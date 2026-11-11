<?php

namespace Tests\Feature\Workouts;

use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutGeneration;
use App\Models\WorkoutPlan;
use App\Services\WorkoutAi\WorkoutGenerator;
use Database\Seeders\ExerciseLibrarySeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkoutAiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://openai.test/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, ExerciseLibrarySeeder::class]);
        Http::preventStrayRequests();
        config([
            'services.meal_ai.provider' => 'openai',
            'services.openai.api_key' => 'sk-test',
            'services.openai.models' => ['gpt-mini-teste'],
            'services.openai.base_url' => 'https://openai.test/v1',
            'services.workout_ai.daily_limit' => 2,
        ]);
    }

    private function reply(array $content): array
    {
        return [
            'model' => 'gpt-mini-teste',
            'choices' => [['message' => ['content' => json_encode($content)], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 7000, 'completion_tokens' => 900],
        ];
    }

    private function exerciseId(string $externalId): int
    {
        return Exercise::where('external_id', $externalId)->value('id');
    }

    private function input(array $extra = []): array
    {
        return ['goal' => 'hypertrophy', 'level' => 'intermediate', 'days' => 2, 'minutes' => 45, 'place' => 'gym',
            'request' => 'quero focar em glúteo, tenho dor no joelho', ...$extra];
    }

    public function test_catalog_respects_equipment_and_level()
    {
        $gen = app(WorkoutGenerator::class);
        $bench = $this->exerciseId('Barbell_Bench_Press_-_Medium_Grip');

        $this->assertTrue($gen->catalog('gym', 'intermediate')->contains('id', $bench));
        $this->assertFalse($gen->catalog('bodyweight', 'intermediate')->contains('id', $bench)); // precisa de barra
        $this->assertFalse($gen->catalog('gym', 'beginner')->contains(fn ($e) => Exercise::find($e['id'])->level === 'advanced'));
        $this->assertTrue($gen->catalog('bodyweight', 'beginner')->every(fn ($e) => Exercise::find($e['id'])->equipment->every(fn ($q) => $q->code === 'body only')));
    }

    public function test_generates_a_validated_preview_without_saving_a_plan()
    {
        $bench = $this->exerciseId('Barbell_Bench_Press_-_Medium_Grip');
        $squat = $this->exerciseId('Barbell_Full_Squat');
        Http::fake([self::URL => Http::response($this->reply([
            'p' => 'Plano glúteo', 'o' => 'Aqueça 10 minutos.',
            'd' => [
                ['n' => 'Inferiores', 'e' => [
                    ['i' => $squat, 's' => 4, 'a' => 8, 'b' => 12, 'r' => 90, 'n' => 'Desça controlando'],
                    ['i' => 999999, 's' => 3, 'a' => 10, 'b' => 12, 'r' => 60, 'n' => ''],    // não existe: ignorado
                    ['i' => $squat, 's' => 3, 'a' => 10, 'b' => 12, 'r' => 60, 'n' => ''],    // repetido no dia: ignorado
                ]],
                ['n' => 'Superiores', 'e' => [
                    ['i' => $bench, 's' => 20, 'a' => 12, 'b' => 6, 'r' => 5000, 'n' => ''],   // números fora da faixa: ajustados
                ]],
                ['n' => 'Dia extra', 'e' => [['i' => $bench, 's' => 3, 'a' => 8, 'b' => 12, 'r' => 60, 'n' => '']]], // pediu 2 dias
            ],
        ]))]);
        $user = User::factory()->create();

        $res = $this->actingAs($user)->postJson(route('workouts.ai.generate'), $this->input())
            ->assertOk()
            ->assertJsonPath('name', 'Plano glúteo')
            ->assertJsonCount(2, 'days')
            ->assertJsonCount(1, 'days.0.exercises')
            ->assertJsonPath('days.0.exercises.0.notes', 'Desça controlando')
            ->assertJsonPath('days.1.label', 'B')
            ->assertJsonPath('days.1.exercises.0.sets', 6)
            ->assertJsonPath('days.1.exercises.0.reps_min', 12)
            ->assertJsonPath('days.1.exercises.0.reps_max', 12)
            ->assertJsonPath('days.1.exercises.0.rest_seconds', 300)
            ->assertJsonPath('remaining', 1);
        $this->assertNotNull($res->json('days.0.exercises.0.name'));

        // O pedido leva o catálogo, o pedido da pessoa e o esquema estrito.
        Http::assertSent(fn (Request $r) => str_contains($r['messages'][0]['content'], "{$bench} ")
            && str_contains($r['messages'][1]['content'], 'dor no joelho')
            && $r['response_format']['json_schema']['strict'] === true);

        $this->assertSame(0, WorkoutPlan::count());
        $this->assertSame(7000, WorkoutGeneration::firstOrFail()->usage['prompt_tokens']);
    }

    public function test_saves_the_reviewed_plan_and_only_the_owner_can()
    {
        $bench = $this->exerciseId('Barbell_Bench_Press_-_Medium_Grip');
        $squat = $this->exerciseId('Barbell_Full_Squat');
        Http::fake([self::URL => Http::response($this->reply(['p' => 'Plano', 'o' => '', 'd' => [
            ['n' => 'A', 'e' => [['i' => $squat, 's' => 3, 'a' => 8, 'b' => 12, 'r' => 90, 'n' => '']]],
        ]]))]);
        $user = User::factory()->create();
        $id = $this->actingAs($user)->postJson(route('workouts.ai.generate'), $this->input(['days' => 1]))->json('id');
        $generation = WorkoutGeneration::findOrFail($id);

        $payload = ['name' => 'Meu plano revisado', 'days' => [
            ['name' => 'Pernas', 'exercises' => [
                ['exercise_id' => $bench, 'sets' => 4, 'reps_min' => 6, 'reps_max' => 8, 'rest_seconds' => 120, 'notes' => 'Trocado por mim'],
                ['exercise_id' => $squat, 'sets' => 3, 'reps_min' => 10, 'reps_max' => 12, 'rest_seconds' => 90, 'notes' => null],
            ]],
            ['name' => 'Extra', 'exercises' => [['exercise_id' => $squat, 'sets' => 2, 'reps_min' => 12, 'reps_max' => 15, 'rest_seconds' => 60]]],
        ]];

        $this->actingAs(User::factory()->create())->post(route('workouts.ai.save', $generation), $payload)->assertNotFound();

        $response = $this->actingAs($user)->post(route('workouts.ai.save', $generation), $payload);
        $plan = WorkoutPlan::with('days.exercises')->firstOrFail();
        $response->assertRedirect(route('workouts.show', $plan));

        $this->assertSame('Meu plano revisado', $plan->name);
        $this->assertSame('ab', $plan->split_type);
        $this->assertSame(['Pernas', 'Extra'], $plan->days->sortBy('position')->pluck('name')->all());
        $first = $plan->days->firstWhere('name', 'Pernas')->exercises->sortBy('position')->first();
        $this->assertSame($bench, $first->exercise_id);
        $this->assertSame(4, $first->sets);
        $this->assertSame('Trocado por mim', $first->notes);
        $this->assertSame('saved', $generation->fresh()->status);

        // Dia sem exercícios é recusado.
        $this->post(route('workouts.ai.save', $generation), ['name' => 'X', 'days' => [['name' => 'Vazio', 'exercises' => []]]])
            ->assertSessionHasErrors('days.0.exercises');
    }

    public function test_daily_limit_and_ai_failures()
    {
        Http::fake([self::URL => Http::response(['error' => ['message' => 'overloaded']], 503)]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('workouts.ai.generate'), $this->input())
            ->assertStatus(422)->assertJsonPath('errors.request.0', fn ($m) => str_contains($m, 'sobrecarregado'));
        $this->postJson(route('workouts.ai.generate'), $this->input())->assertStatus(422);
        $this->postJson(route('workouts.ai.generate'), $this->input())
            ->assertStatus(422)->assertJsonPath('errors.request.0', fn ($m) => str_contains($m, 'hoje'));
        $this->assertSame(['failed', 'failed'], WorkoutGeneration::pluck('status')->all());

        $this->postJson(route('workouts.ai.generate'), $this->input(['days' => 9, 'minutes' => 20]))->assertStatus(422)->assertJsonValidationErrors(['days', 'minutes']);
    }

    public function test_page_renders_with_profile_defaults()
    {
        $user = User::factory()->create(['goal' => 'weight_loss', 'experience_level' => 'beginner']);

        $this->actingAs($user)->get(route('workouts.ai'))->assertInertia(fn ($p) => $p
            ->component('workouts/AiCreate')
            ->where('defaults.goal', 'weight_loss')
            ->where('defaults.level', 'beginner')
            ->where('remaining', 2)
            ->where('aiEnabled', true));
    }
}
