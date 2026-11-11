<?php

namespace Tests\Feature\Workouts;

use App\Models\Exercise;
use App\Models\SessionSet;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutPlan;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkoutFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Exercise $bench;

    private Exercise $squat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->user = User::factory()->create();
        $this->bench = Exercise::where('external_id', 'Barbell_Bench_Press_-_Medium_Grip')->firstOrFail();
        $this->squat = Exercise::where('external_id', 'Barbell_Full_Squat')->firstOrFail();
    }

    private function createPlan(string $split = 'abc'): WorkoutPlan
    {
        $this->actingAs($this->user)->post(route('workouts.store'), ['name' => 'Meu ABC', 'split_type' => $split]);

        return WorkoutPlan::where('user_id', $this->user->id)->latest('created_at')->firstOrFail();
    }

    public function test_creating_an_abc_plan_creates_days_a_b_c()
    {
        $plan = $this->createPlan('abc');

        $this->assertSame(['A', 'B', 'C'], $plan->days->pluck('label')->all());
        $this->assertNotNull($plan->sync_version);
        $this->assertTrue(\Illuminate\Support\Str::isUuid($plan->id));
    }

    public function test_add_configure_reorder_and_remove_exercises()
    {
        $day = $this->createPlan()->days->first();

        $this->post(route('workout-exercises.store', $day), ['exercise_id' => $this->bench->id])->assertSessionHasNoErrors();
        $this->post(route('workout-exercises.store', $day), [
            'exercise_id' => $this->squat->id, 'sets' => 4, 'reps_min' => 6, 'reps_max' => 8, 'target_load_kg' => 80, 'rest_seconds' => 120,
        ])->assertSessionHasNoErrors();

        $items = $day->exercises()->get();
        $this->assertSame([$this->bench->id, $this->squat->id], $items->pluck('exercise_id')->all());
        $this->assertSame(3, $items[0]->sets);                 // sugestão da biblioteca
        $this->assertSame('80.00', $items[1]->target_load_kg);

        // Reordenar: agachamento sobe.
        $this->post(route('workout-exercises.move', $items[1]), ['direction' => 'up']);
        $this->assertSame([$this->squat->id, $this->bench->id], $day->exercises()->pluck('exercise_id')->all());

        // Editar e validar faixa de repetições.
        $this->patch(route('workout-exercises.update', $items[0]), ['reps_min' => 12, 'reps_max' => 8])
            ->assertSessionHasErrors('reps_max');
        $this->patch(route('workout-exercises.update', $items[0]), ['sets' => 5])->assertSessionHasNoErrors();
        $this->assertSame(5, $items[0]->fresh()->sets);

        // Remover (exclusão lógica: some da lista, mas fica registrada para a sincronização).
        $this->delete(route('workout-exercises.destroy', $items[0]));
        $this->assertSame([$this->squat->id], $day->exercises()->pluck('exercise_id')->all());
        $this->assertDatabaseHas('workout_exercises', ['id' => $items[0]->id]);
        $this->assertNotNull(\DB::table('workout_exercises')->where('id', $items[0]->id)->value('deleted_at'));
    }

    public function test_full_session_flow_with_history_and_load_prefill()
    {
        $day = $this->createPlan()->days->first();
        $this->post(route('workout-exercises.store', $day), ['exercise_id' => $this->bench->id, 'sets' => 2, 'target_load_kg' => 40]);

        // 1º treino: séries vêm com a carga-alvo.
        $this->post(route('sessions.store'), ['workout_day_id' => $day->id])->assertRedirect();
        $session = TrainingSession::where('user_id', $this->user->id)->firstOrFail();
        $sets = $session->exercises->first()->sets;
        $this->assertCount(2, $sets);
        $this->assertSame('40.00', $sets[0]->load_kg);

        // Não deixa abrir um segundo treino ao mesmo tempo.
        $this->post(route('sessions.store'), ['workout_day_id' => $day->id])->assertRedirect(route('sessions.show', $session));
        $this->assertSame(1, TrainingSession::where('user_id', $this->user->id)->count());

        $this->patch(route('session-sets.update', $sets[0]), ['reps' => 10, 'load_kg' => 45, 'completed' => true]);
        $this->patch(route('session-sets.update', $sets[1]), ['reps' => 8, 'load_kg' => 50, 'completed' => true]);
        $this->assertTrue($session->exercises->first()->fresh()->completed);

        $this->get(route('sessions.show', $session))->assertInertia(fn (Assert $p) => $p
            ->component('sessions/Show')
            ->where('session.completed_sets', 2)
            ->where('session.volume_kg', 850)); // 10×45 + 8×50

        $this->patch(route('sessions.update', $session), ['finished' => true])->assertRedirect(route('sessions.show', [$session, 'share' => 1]));
        $this->assertNotNull($session->fresh()->finished_at);

        // 2º treino: séries vêm com as cargas usadas da última vez.
        $this->post(route('sessions.store'), ['workout_day_id' => $day->id]);
        $second = TrainingSession::where('user_id', $this->user->id)->whereNull('finished_at')->firstOrFail();
        $this->assertSame(['45.00', '50.00'], $second->exercises->first()->sets->pluck('load_kg')->all());

        // Histórico por exercício.
        $this->get(route('exercises.show', $this->bench))->assertInertia(fn (Assert $p) => $p
            ->has('history', 1)
            ->where('history.0.best_load_kg', 50)
            ->where('history.0.volume_kg', 850));

        // Histórico geral.
        $this->get(route('sessions.index'))->assertInertia(fn (Assert $p) => $p->where('sessions.total', 2));
    }

    public function test_free_session_add_exercise_and_extra_set()
    {
        $this->actingAs($this->user)->post(route('sessions.store'), []);
        $session = TrainingSession::where('user_id', $this->user->id)->firstOrFail();
        $this->assertNull($session->workout_day_id);

        $this->post(route('sessions.exercises.store', $session), ['exercise_id' => $this->squat->id]);
        $se = $session->exercises()->firstOrFail();
        $this->assertCount(3, $se->sets);

        $this->post(route('session-sets.store', $se));
        $this->assertCount(4, $se->fresh()->sets);
    }

    public function test_users_cannot_touch_each_others_workouts()
    {
        $plan = $this->createPlan();
        $day = $plan->days->first();
        $this->post(route('workout-exercises.store', $day), ['exercise_id' => $this->bench->id]);
        $item = WorkoutExercise::firstOrFail();
        $this->post(route('sessions.store'), ['workout_day_id' => $day->id]);
        $set = SessionSet::firstOrFail();

        $intruder = User::factory()->create();
        $this->actingAs($intruder);

        $this->get(route('workouts.show', $plan))->assertNotFound();
        $this->patch(route('workouts.update', $plan), ['name' => 'Hackeado'])->assertNotFound();
        $this->delete(route('workouts.destroy', $plan))->assertNotFound();
        $this->post(route('workout-exercises.store', $day), ['exercise_id' => $this->bench->id])->assertNotFound();
        $this->patch(route('workout-exercises.update', $item), ['sets' => 9])->assertNotFound();
        $this->patch(route('session-sets.update', $set), ['reps' => 99])->assertNotFound();
        $this->post(route('sessions.store'), ['workout_day_id' => $day->id])->assertNotFound();

        $this->assertSame('Meu ABC', $plan->fresh()->name);
        $this->assertNull($set->fresh()->reps);
    }

    public function test_deleting_a_plan_soft_deletes_days_and_exercises()
    {
        $plan = $this->createPlan('ab');
        $this->post(route('workout-exercises.store', $plan->days->first()), ['exercise_id' => $this->bench->id]);

        $this->delete(route('workouts.destroy', $plan))->assertRedirect(route('workouts.index'));

        $this->assertNull(WorkoutPlan::find($plan->id));
        $this->assertSame(0, \DB::table('workout_days')->where('plan_id', $plan->id)->whereNull('deleted_at')->count());
        $this->assertSame(0, \DB::table('workout_exercises')->whereNull('deleted_at')->count());
    }

    public function test_sync_version_increases_on_every_change()
    {
        $plan = $this->createPlan();
        $v1 = $plan->sync_version;
        $this->patch(route('workouts.update', $plan), ['name' => 'Novo nome']);
        $this->assertGreaterThan($v1, $plan->fresh()->sync_version);
    }
}
