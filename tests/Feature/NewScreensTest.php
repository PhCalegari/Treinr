<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\ProgressEntry;
use App\Models\User;
use App\Services\WorkoutService;
use Database\Seeders\ExerciseLibrarySeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Recuperação muscular, primeiros passos, última carga no plano e painel de erros. */
class NewScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, ExerciseLibrarySeeder::class]);
    }

    public function test_recovery_marks_recently_trained_muscle_as_fatigued_and_recovers_with_time()
    {
        $svc = app(WorkoutService::class);
        $user = User::factory()->create();
        $bench = Exercise::where('external_id', 'Barbell_Bench_Press_-_Medium_Grip')->firstOrFail();

        Carbon::setTestNow('2026-11-10 10:00:00');
        $s = $svc->startSession($user, null);
        $se = $svc->addExerciseToSession($s, $bench->id);
        foreach (range(4, 8) as $n) {
            $svc->createSet($se, $n);
        }
        $se->sets()->update(['completed' => true, 'completed_at' => now(), 'load_kg' => 50, 'reps' => 10]);

        Carbon::setTestNow('2026-11-10 12:00:00');
        $chest = fn () => collect(app(\App\Services\WorkoutOverview::class)->recovery($user->id))->firstWhere('code', 'CHEST');
        $this->assertSame('fatigued', $chest()['status']);
        $this->assertGreaterThan(48, $chest()['hours_to_95']);
        $this->assertSame('fresh', collect(app(\App\Services\WorkoutOverview::class)->recovery($user->id))->firstWhere('code', 'CALVES')['status']);

        Carbon::setTestNow('2026-11-14 12:00:00');
        $this->assertSame('fresh', $chest()['status']);

        $this->actingAs($user)->get(route('workouts.recovery'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('workouts/Recovery')->has('muscles', 11));
    }

    public function test_onboarding_saves_profile_and_first_weight()
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('onboarding'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('onboarding/Setup'));

        $this->post(route('onboarding.store'), [
            'sex' => 'female', 'birth_date' => '1995-05-20', 'height_cm' => 165,
            'weight_kg' => 61.5, 'goal' => 'weight_loss', 'experience_level' => 'beginner',
        ])->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertSame('female', $user->sex->value);
        $this->assertSame('beginner', $user->experience_level->value);
        $this->assertEquals(61.5, ProgressEntry::ownedBy($user->id)->first()->weight_kg);

        $this->post(route('onboarding.store'), ['sex' => 'x'])->assertSessionHasErrors(['sex', 'birth_date', 'height_cm', 'goal', 'experience_level']);
    }

    public function test_plan_shows_last_best_set_per_exercise()
    {
        $svc = app(WorkoutService::class);
        $user = User::factory()->create();
        $plan = $svc->createPlan($user, 'AB', 'ab');
        $bench = Exercise::where('external_id', 'Barbell_Bench_Press_-_Medium_Grip')->firstOrFail();
        $svc->addExercise($plan->days()->first(), $bench);

        $s = $svc->startSession($user, null);
        $se = $svc->addExerciseToSession($s, $bench->id);
        $sets = $se->sets()->orderBy('set_number')->get();
        $sets[0]->forceFill(['load_kg' => 40, 'reps' => 12, 'completed' => true])->save();
        $sets[1]->forceFill(['load_kg' => 45, 'reps' => 8, 'completed' => true])->save();

        $this->actingAs($user)->get(route('workouts.show', $plan))->assertInertia(fn (Assert $p) => $p
            ->where('plan.days.0.exercises.0.last.load_kg', 45)
            ->where('plan.days.0.exercises.0.last.reps', 8));
    }

    public function test_admin_sees_grouped_server_errors_and_others_cannot()
    {
        $admin = User::factory()->create(['name' => 'Admin Teste']);
        $admin->forceFill(['role' => 'admin'])->save();
        $log = storage_path('logs/laravel-'.now()->format('Y-m-d').'.log');
        File::ensureDirectoryExists(dirname($log));
        $existing = File::exists($log) ? File::get($log) : null;
        $stamp = now()->format('Y-m-d H:i:s');
        File::append($log, "[{$stamp}] testing.ERROR: Falha de teste 123 {\"userId\":1}\n[{$stamp}] testing.ERROR: Falha de teste 456 {\"userId\":2}\n");

        try {
            $this->actingAs($admin)->get(route('admin.errors'))->assertOk()->assertInertia(fn (Assert $p) => $p
                ->component('admin/Errors')
                ->where('errors', fn ($errors) => collect($errors)->contains(fn ($e) => str_starts_with($e['message'], 'Falha de teste') && $e['count'] === 2)));

            $this->actingAs(User::factory()->create())->get(route('admin.errors'))->assertForbidden();
        } finally {
            $existing === null ? File::delete($log) : File::put($log, $existing);
        }
    }
}
