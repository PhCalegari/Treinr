<?php

namespace Tests\Feature\Workouts;

use App\Models\Exercise;
use App\Models\User;
use App\Services\WorkoutService;
use Database\Seeders\ExerciseLibrarySeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkoutOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_workouts_screen_has_last_7_days_activity_and_recent()
    {
        $this->seed([ReferenceDataSeeder::class, ExerciseLibrarySeeder::class]);
        $svc = app(WorkoutService::class);
        $user = User::factory()->create();
        $plan = $svc->createPlan($user, 'ABC', 'abc');
        $dayA = $plan->days()->orderBy('position')->first();
        $bench = Exercise::where('external_id', 'Barbell_Bench_Press_-_Medium_Grip')->firstOrFail();
        $svc->addExercise($dayA, $bench, ['sets' => 3]);

        // Treino A há 2 dias (3 séries feitas, 50 min).
        Carbon::setTestNow('2026-11-08 18:00:00');
        $s = $svc->startSession($user, $dayA->load('exercises'));
        foreach ($s->exercises->first()->sets as $set) {
            $set->forceFill(['load_kg' => 40, 'reps' => 10, 'completed' => true])->save();
        }
        $s->forceFill(['finished_at' => now()->addMinutes(50)])->save();

        // Treino de 20 dias atrás fica fora dos 7 dias, mas entra na atividade.
        Carbon::setTestNow('2026-10-21 18:00:00');
        $old = $svc->startSession($user, null);
        $old->forceFill(['finished_at' => now()->addMinutes(30)])->save();

        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->actingAs($user)->get(route('workouts.index'))->assertInertia(fn (Assert $p) => $p
            ->has('week', 7)
            ->where('week.0.date', '2026-11-04')
            ->where('week.6.date', '2026-11-10')
            ->where('week.4.sets', 3)
            ->where('week.4.sessions.0.label', 'A')
            ->where('week.4.groups', fn ($g) => collect($g)->contains('CHEST'))
            ->where('week.6.sets', 0)
            ->where('activity.count', 2)
            ->where('activity.total_min', 80)
            ->where('recent.0.title', $dayA->fresh()->title())
            ->where('nextDayId', $plan->days()->orderBy('position')->skip(1)->first()->id));
    }
}
