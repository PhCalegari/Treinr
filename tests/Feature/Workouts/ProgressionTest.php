<?php

namespace Tests\Feature\Workouts;

use App\Models\Exercise;
use App\Models\User;
use App\Services\WorkoutService;
use Database\Seeders\ExerciseLibrarySeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ProgressionTest extends TestCase
{
    use RefreshDatabase;

    private WorkoutService $svc;

    private User $user;

    private Exercise $bench;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, ExerciseLibrarySeeder::class]);
        $this->svc = app(WorkoutService::class);
        $this->user = User::factory()->create();
        $this->bench = Exercise::where('external_id', 'Barbell_Bench_Press_-_Medium_Grip')->firstOrFail();
    }

    /** Treino finalizado numa data, com as séries (carga, repetições). */
    private function past(string $date, array $sets): void
    {
        Carbon::setTestNow($date.' 18:00:00');
        $s = $this->svc->startSession($this->user, null);
        $se = $this->svc->addExerciseToSession($s, $this->bench->id);
        $se->sets()->get()->each->delete();
        foreach ($sets as $i => [$load, $reps]) {
            $set = $this->svc->createSet($se, $i + 1, $load);
            $set->forceFill(['reps' => $reps, 'completed' => true])->save();
        }
        $s->forceFill(['finished_at' => now()])->save();
    }

    public function test_hitting_max_reps_on_all_sets_suggests_more_load()
    {
        $this->past('2026-10-20', [[20, 10], [20, 9], [20, 8]]);
        $this->past('2026-10-27', [[20, 12], [20, 12], [20, 12]]);
        Carbon::setTestNow('2026-11-03 18:00:00');

        $p = $this->svc->progression($this->user->id, $this->bench->id, null, 12);

        $this->assertSame('2026-10-27', $p['last']['date']);
        $this->assertSame(7, $p['last']['days_ago']);
        $this->assertSame([12, 12, 12], array_column($p['last']['sets']->all(), 'reps'));
        $this->assertSame(22.5, $p['suggestion']['load_kg']); // +2,5 kg
        $this->assertNull($p['suggestion']['reps']);
        $this->assertSame([20.0, 20.0], array_column($p['trend']->all(), 'value'));
    }

    public function test_missing_reps_keeps_load_and_asks_one_more_rep()
    {
        $this->past('2026-10-20', [[20, 12], [20, 12], [20, 12]]);
        $this->past('2026-10-27', [[22.5, 9], [22.5, 8], [22.5, 7]]);
        Carbon::setTestNow('2026-11-03 18:00:00');

        $p = $this->svc->progression($this->user->id, $this->bench->id, null, 12);

        $this->assertSame(22.5, $p['suggestion']['load_kg']);
        $this->assertSame(10, $p['suggestion']['reps']); // melhor série (9) + 1
        $this->assertSame(2.5, $p['change']);
        $this->assertSame('2026-10-20', $p['since']);

        // Carga leve sobe de 1 em 1 kg.
        $this->past('2026-10-30', [[6, 15], [6, 15]]);
        Carbon::setTestNow('2026-11-03 18:00:00');
        $this->assertSame(7.0, $this->svc->progression($this->user->id, $this->bench->id, null, 15)['suggestion']['load_kg']);
    }

    public function test_session_screen_receives_progression_and_ignores_current_session()
    {
        $this->past('2026-10-27', [[20, 12], [20, 12], [20, 12]]);
        Carbon::setTestNow('2026-11-03 18:00:00');
        $today = $this->svc->startSession($this->user, null);
        $se = $this->svc->addExerciseToSession($today, $this->bench->id);
        $se->sets()->first()->forceFill(['load_kg' => 50, 'reps' => 5, 'completed' => true])->save();

        $this->actingAs($this->user)->get(route('sessions.show', $today))->assertInertia(fn ($p) => $p
            ->where('session.exercises.0.progression.last.date', '2026-10-27')
            ->where('session.exercises.0.progression.suggestion.load_kg', 22.5));

        // Exercício nunca feito: sem painel.
        $this->assertNull($this->svc->progression($this->user->id, 999999));
    }

    public function test_cardio_records_time_and_distance_instead_of_load()
    {
        $cardio = Exercise::whereHas('category', fn ($q) => $q->where('code', 'cardio'))->firstOrFail();
        $this->assertSame('cardio', $cardio->trackingKind());

        Carbon::setTestNow('2026-10-27 18:00:00');
        $past = $this->svc->startSession($this->user, null);
        $se = $this->svc->addExerciseToSession($past, $cardio->id);
        $set = $se->sets()->first();
        $this->actingAs($this->user)
            ->patch(route('session-sets.update', $set), ['duration_sec' => 1200, 'distance_km' => 3.2, 'completed' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame(1200, $set->fresh()->duration_sec);
        $this->assertSame(3.2, $set->fresh()->distance_km);
        $past->forceFill(['finished_at' => now()])->save();

        Carbon::setTestNow('2026-11-03 18:00:00');
        $today = $this->svc->startSession($this->user, null);
        $this->svc->addExerciseToSession($today, $cardio->id);

        $this->get(route('sessions.show', $today))->assertInertia(fn ($p) => $p
            ->where('session.exercises.0.kind', 'cardio')
            ->where('session.exercises.0.progression.unit', 'min')
            ->where('session.exercises.0.progression.suggestion', null)
            ->where('session.exercises.0.progression.last.sets.0.distance_km', 3.2));
    }
}
