<?php

namespace Tests\Feature\Workouts;

use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\WorkoutService;
use Database\Seeders\ExerciseLibrarySeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SharingTest extends TestCase
{
    use RefreshDatabase;

    private WorkoutService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, ExerciseLibrarySeeder::class]);
        $this->svc = app(WorkoutService::class);
    }

    private function planFor(User $user): WorkoutPlan
    {
        $plan = $this->svc->createPlan($user, 'Hipertrofia ABCD', 'abcd');
        $bench = Exercise::where('external_id', 'Barbell_Bench_Press_-_Medium_Grip')->firstOrFail();
        $this->svc->addExercise($plan->days()->first(), $bench, ['sets' => 4, 'reps_min' => 8, 'reps_max' => 12, 'target_load_kg' => 60]);

        return $plan;
    }

    public function test_owner_creates_link_and_anyone_can_view_it()
    {
        $owner = User::factory()->create(['name' => 'Pedro Calegari']);
        $plan = $this->planFor($owner);

        $this->actingAs($owner)->post(route('workouts.share', $plan))->assertRedirect();
        $token = $plan->fresh()->share_token;
        $this->assertSame(24, strlen($token));

        // Pedir de novo mantém o mesmo link.
        $this->post(route('workouts.share', $plan));
        $this->assertSame($token, $plan->fresh()->share_token);

        $this->get(route('workouts.show', $plan))->assertInertia(fn (Assert $p) => $p->where('plan.share_url', route('shared-plans.show', $token)));

        // Visitante sem login vê o plano.
        auth()->logout();
        $this->get(route('shared-plans.show', $token))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('shared/Plan')
            ->where('author', 'Pedro')
            ->where('loggedIn', false)
            ->has('plan.days', 4)
            ->where('plan.days.0.exercises.0.sets', 4));

        $this->get(route('shared-plans.show', 'naoexiste'))->assertNotFound();
    }

    public function test_other_user_copies_plan_without_personal_load()
    {
        $owner = User::factory()->create();
        $plan = $this->planFor($owner);
        $this->actingAs($owner)->post(route('workouts.share', $plan));
        $token = $plan->fresh()->share_token;

        $friend = User::factory()->create();
        $this->actingAs($friend)->post(route('shared-plans.copy', $token))->assertRedirect();

        $copy = WorkoutPlan::ownedBy($friend->id)->with('days.exercises')->firstOrFail();
        $this->assertSame('Hipertrofia ABCD', $copy->name);
        $this->assertCount(4, $copy->days);
        $item = $copy->days->first()->exercises->first();
        $this->assertSame(4, $item->sets);
        $this->assertNull($item->target_load_kg);
        $this->assertSame($friend->id, $item->user_id);
    }

    public function test_only_owner_can_share_and_disabling_kills_link()
    {
        $owner = User::factory()->create();
        $plan = $this->planFor($owner);

        $this->actingAs(User::factory()->create())->post(route('workouts.share', $plan))->assertNotFound();

        $this->actingAs($owner)->post(route('workouts.share', $plan));
        $token = $plan->fresh()->share_token;
        $this->delete(route('workouts.unshare', $plan));
        $this->assertNull($plan->fresh()->share_token);
        $this->get(route('shared-plans.show', $token))->assertNotFound();
    }

    public function test_finished_session_without_completed_sets_still_opens()
    {
        $user = User::factory()->create();
        $s = $this->svc->startSession($user, null);
        $s->forceFill(['finished_at' => now()])->save();

        $this->actingAs($user)->get(route('sessions.show', $s))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('session.share.distribution', [])->where('session.share.records', 0));
    }

    public function test_page_headers_stay_small_for_nginx()
    {
        // O nginx do servidor recusa respostas com cabeçalhos acima de 4 KB (erro 502).
        $user = User::factory()->create();
        $this->planFor($user);
        $res = $this->actingAs($user)->get(route('workouts.index'))->assertOk();
        $size = collect($res->headers->allPreserveCaseWithoutCookies())
            ->map(fn ($values, $name) => strlen($name) + array_sum(array_map('strlen', $values)))->sum();
        $this->assertLessThan(2500, $size, "Cabeçalhos com {$size} bytes");
    }

    public function test_finished_session_has_share_card_with_records_and_distribution()
    {
        $user = User::factory()->create(['name' => 'Ana Souza', 'sex' => 'female']);
        $bench = Exercise::where('external_id', 'Barbell_Bench_Press_-_Medium_Grip')->firstOrFail();

        $done = function (string $date, float $load) use ($user, $bench) {
            Carbon::setTestNow($date.' 18:00:00');
            $s = $this->svc->startSession($user, null);
            $se = $this->svc->addExerciseToSession($s, $bench->id);
            foreach ($se->sets as $set) {
                $set->forceFill(['load_kg' => $load, 'reps' => 10, 'completed' => true])->save();
            }
            $s->forceFill(['finished_at' => now()->addHour()])->save();

            return $s;
        };
        $done('2026-11-01', 40);
        $today = $done('2026-11-08', 45);

        $this->actingAs($user)->patch(route('sessions.update', $today), ['finished' => true])
            ->assertRedirect(route('sessions.show', [$today, 'share' => 1]));

        $this->get(route('sessions.show', $today))->assertInertia(fn (Assert $p) => $p
            ->where('session.share.author', 'Ana')
            ->where('session.share.sex', 'female')
            ->where('session.share.records', 1)
            ->where('session.share.groups', fn ($g) => collect($g)->contains('CHEST'))
            ->where('session.share.distribution.0.sets', 3)
            ->where('session.share.exercises.0.sets', 3));
    }
}
