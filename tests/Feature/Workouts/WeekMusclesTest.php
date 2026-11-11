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

class WeekMusclesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_counts_completed_sets_per_muscle_group_this_week()
    {
        $this->seed([ReferenceDataSeeder::class, ExerciseLibrarySeeder::class]);
        Carbon::setTestNow('2026-11-11 10:00:00'); // quarta-feira
        $user = User::factory()->create();
        $svc = app(WorkoutService::class);
        $bench = Exercise::where('external_id', 'Barbell_Bench_Press_-_Medium_Grip')->firstOrFail();
        $squat = Exercise::where('external_id', 'Barbell_Full_Squat')->firstOrFail();

        // Semana passada: não conta.
        Carbon::setTestNow('2026-11-06 10:00:00');
        $old = $svc->startSession($user, null);
        $svc->addExerciseToSession($old, $squat->id)->sets->each(fn ($s) => $s->forceFill(['completed' => true])->save());

        // Esta semana: 3 séries de supino feitas, 1 de 3 de agachamento.
        Carbon::setTestNow('2026-11-10 18:00:00');
        $s = $svc->startSession($user, null);
        $svc->addExerciseToSession($s, $bench->id)->sets->each(fn ($set) => $set->forceFill(['completed' => true])->save());
        $svc->addExerciseToSession($s, $squat->id)->sets->first()->forceFill(['completed' => true])->save();

        Carbon::setTestNow('2026-11-11 10:00:00');
        $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn ($p) => $p
            ->where('weekMuscles', fn ($m) => collect($m)->pluck('sets', 'code')->all() === ['CHEST' => 3, 'QUADRICEPS' => 1]));
    }

    public function test_plan_page_sends_muscle_codes_for_the_drawing()
    {
        $this->seed([ReferenceDataSeeder::class, ExerciseLibrarySeeder::class]);
        $user = User::factory()->create();
        $svc = app(WorkoutService::class);
        $plan = $svc->createPlan($user, 'Plano', 'a');
        $svc->addExercise($plan->days()->first(), Exercise::where('external_id', 'Barbell_Bench_Press_-_Medium_Grip')->firstOrFail());

        $this->actingAs($user)->get(route('workouts.show', $plan))->assertInertia(fn ($p) => $p
            ->where('plan.days.0.muscles.0.code', 'CHEST')
            ->where('plan.days.0.exercises.0.groups', ['CHEST'])
            ->where('plan.days.0.exercises.0.secondary_groups', fn ($g) => in_array('TRICEPS', collect($g)->all(), true)));
    }
}
