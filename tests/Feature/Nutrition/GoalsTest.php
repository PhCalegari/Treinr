<?php

namespace Tests\Feature\Nutrition;

use App\Enums\Goal;
use App\Enums\Sex;
use App\Models\NutritionGoal;
use App\Models\ProgressEntry;
use App\Models\User;
use App\Services\Food\DiaryService;
use App\Services\Nutrition\EnergyCalculator;
use App\Services\Nutrition\GoalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class GoalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-03 10:00:00');
    }

    private function athlete(): User
    {
        $user = User::factory()->create([
            'sex' => 'male', 'birth_date' => '1996-01-10', 'height_cm' => 180, 'goal' => 'maintenance',
        ]); // 30 anos em 03/11/2026
        $entry = new ProgressEntry(['entry_date' => '2026-11-01', 'weight_kg' => 80]);
        $entry->user_id = $user->id;
        $entry->save();

        return $user;
    }

    public function test_bmr_formulas()
    {
        $this->assertEqualsWithDelta(1780, EnergyCalculator::mifflinStJeor(Sex::Male, 80, 180, 30), 0.01);
        $this->assertEqualsWithDelta(1320.25, EnergyCalculator::mifflinStJeor(Sex::Female, 60, 165, 30), 0.01);
        $this->assertEqualsWithDelta(1752.4, EnergyCalculator::katchMcArdle(80, 20), 0.01);
    }

    public function test_estimate_by_goal_and_macros()
    {
        $e = EnergyCalculator::estimate(Sex::Male, 80, 180, 30, 1.55, Goal::Maintenance);
        $this->assertSame(1780, $e['bmr']);
        $this->assertSame(2759, $e['tdee']);
        $this->assertSame(2760, $e['kcal']);
        $this->assertSame(128, $e['protein_g']); // 1,6 g/kg
        $this->assertSame(77, $e['fat_g']);      // 25% das kcal
        $this->assertSame(389, $e['carbs_g']);   // o restante
        $this->assertSame('mifflin_st_jeor', $e['formula']);

        $this->assertSame(2210, EnergyCalculator::estimate(Sex::Male, 80, 180, 30, 1.55, Goal::WeightLoss)['kcal']); // −20%
        $this->assertSame(3030, EnergyCalculator::estimate(Sex::Male, 80, 180, 30, 1.55, Goal::Hypertrophy)['kcal']); // +10% (276), arredonda de 10 em 10

        $this->assertSame('katch_mcardle', EnergyCalculator::estimate(Sex::Male, 80, 180, 30, 1.55, Goal::Maintenance, 20)['formula']);
    }

    public function test_estimate_never_goes_below_safe_minimum()
    {
        $e = EnergyCalculator::estimate(Sex::Female, 45, 150, 60, 1.2, Goal::WeightLoss);
        $this->assertSame(1200, $e['kcal']);
        $this->assertNotEmpty($e['warnings']);
    }

    public function test_protein_uses_reference_weight_when_bmi_is_high()
    {
        // 120 kg e 1,70 m: usa o peso de IMC 25 (72,25 kg) × 1,8 g/kg.
        $this->assertSame(130, EnergyCalculator::estimate(Sex::Male, 120, 170, 40, 1.375, Goal::Hypertrophy)['protein_g']);
    }

    public function test_goals_page_lists_missing_data()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('goals.index'))->assertInertia(fn (AssertableInertia $p) => $p
            ->component('nutrition/Goals')
            ->where('estimates', null)
            ->where('inputs.missing', ['sex', 'age', 'height_cm', 'weight_kg']));

        // Sem dados, só aceita meta manual.
        $this->post(route('goals.store'), ['goal' => 'maintenance', 'activity_level' => 'moderate'])->assertSessionHasErrors('goal');
        $this->post(route('goals.store'), [
            'goal' => 'maintenance', 'activity_level' => 'moderate', 'manual' => true,
            'kcal' => 2000, 'protein_g' => 120, 'carbs_g' => 220, 'fat_g' => 70,
        ])->assertSessionHasNoErrors();
        $this->assertSame(2000, NutritionGoal::firstOrFail()->kcal);
        $this->assertTrue(NutritionGoal::firstOrFail()->manual_override);
    }

    public function test_saved_goal_uses_server_side_estimate_and_replaces_same_day()
    {
        $user = $this->athlete();

        $this->actingAs($user)->get(route('goals.index'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('inputs.weight_kg', 80)
            ->where('estimates.maintenance.moderate.kcal', 2760));

        // Valores enviados sem "manual" são ignorados: vale o cálculo do servidor.
        $this->post(route('goals.store'), ['goal' => 'weight_loss', 'activity_level' => 'moderate', 'kcal' => 900])->assertSessionHasNoErrors();
        $goal = NutritionGoal::firstOrFail();
        $this->assertSame(2210, $goal->kcal);
        $this->assertSame(1780, $goal->bmr_estimate);
        $this->assertSame(1.55, $goal->activity_factor);
        $this->assertSame(Goal::WeightLoss, $user->fresh()->goal);

        $this->post(route('goals.store'), ['goal' => 'maintenance', 'activity_level' => 'moderate'])->assertSessionHasNoErrors();
        $this->assertSame(1, NutritionGoal::count());
        $this->assertSame(2760, NutritionGoal::firstOrFail()->kcal);
    }

    public function test_manual_values_are_validated()
    {
        $user = $this->athlete();
        $this->actingAs($user)->post(route('goals.store'), [
            'goal' => 'maintenance', 'activity_level' => 'moderate', 'manual' => true, 'kcal' => 300, 'protein_g' => 100, 'carbs_g' => 10, 'fat_g' => 10,
        ])->assertSessionHasErrors('kcal');
    }

    public function test_diary_compares_each_day_with_the_goal_valid_then()
    {
        $user = $this->athlete();
        $this->makeGoal($user, '2026-10-01', 2500);
        $this->makeGoal($user, '2026-11-01', 2000);
        $this->eat($user, '2026-10-30', 2400);
        $this->eat($user, '2026-11-02', 1500);
        $this->eat($user, '2026-11-02', 300);

        $this->actingAs($user)->get(route('diary.index', ['date' => '2026-10-30']))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('goal.kcal', 2500));
        $this->get(route('diary.index', ['date' => '2026-11-02']))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('goal.kcal', 2000)->where('totals.kcal', 1800));

        $days = collect(app(GoalService::class)->dailyHistory($user, '2026-10-27', '2026-11-03'))->keyBy('date');
        $this->assertSame(2500, $days['2026-10-30']['goal_kcal']);
        $this->assertSame(2400.0, $days['2026-10-30']['kcal']);
        $this->assertSame(1800.0, $days['2026-11-02']['kcal']);
        $this->assertSame(2000, $days['2026-11-02']['goal_kcal']);
        $this->assertFalse($days['2026-10-31']['logged']);

        // Semana de 26/10 a 01/11: só o dia 30 tem registro.
        $weeks = collect(GoalService::weekly($days->values()->all()))->keyBy('week_start');
        $this->assertSame(1, $weeks['2026-10-26']['days_logged']);
        $this->assertSame(2400, $weeks['2026-10-26']['avg_kcal']);
        $this->assertSame(1800, $weeks['2026-11-02']['avg_kcal']);
    }

    public function test_dashboard_shows_real_data()
    {
        $user = $this->athlete();
        $this->makeGoal($user, '2026-11-01', 2000);
        $this->eat($user, '2026-11-03', 650);

        $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Dashboard')
            ->where('nutrition.totals.kcal', 650)
            ->where('nutrition.goal.kcal', 2000)
            ->where('progress.weight_kg', 80)
            ->where('training.open', null));
    }

    private function makeGoal(User $user, string $from, int $kcal): void
    {
        $g = new NutritionGoal(['valid_from' => $from, 'kcal' => $kcal, 'protein_g' => 100, 'carbs_g' => 250, 'fat_g' => 60]);
        $g->user_id = $user->id;
        $g->save();
    }

    private function eat(User $user, string $date, float $kcal): void
    {
        app(DiaryService::class)->addItem($user, [
            'meal_date' => $date, 'meal_type' => 'lunch', 'quantity' => 100,
            'food_name' => 'Teste', 'kcal' => $kcal, 'protein_g' => 10, 'carbs_g' => 10, 'fat_g' => 10,
        ]);
    }
}
