<?php

namespace App\Http\Controllers\Nutrition;

use App\Enums\Goal;
use App\Http\Controllers\Controller;
use App\Models\NutritionGoal;
use App\Services\Nutrition\EnergyCalculator;
use App\Services\Nutrition\GoalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GoalController extends Controller
{
    public function __construct(private readonly GoalService $goals) {}

    /** Metas: calculadora (TMB/GET), meta vigente e histórico diário/semanal. */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $today = now()->toDateString();
        $inputs = $this->goals->inputs($user, $today);
        $days = $this->goals->dailyHistory($user, now()->subDays(27)->toDateString(), $today);

        return Inertia::render('nutrition/Goals', [
            'today' => $today,
            'inputs' => $inputs,
            'profileGoal' => $user->goal?->value,
            'levels' => collect(EnergyCalculator::ACTIVITY_LEVELS)->map(fn ($l, $key) => ['value' => $key, ...$l])->values(),
            'estimates' => $this->goals->estimates($inputs),
            'current' => GoalService::present(NutritionGoal::currentFor($user->id, $today)),
            'goalHistory' => NutritionGoal::ownedBy($user->id)->orderByDesc('valid_from')->orderByDesc('modified_at')
                ->limit(10)->get()->map(fn ($g) => GoalService::present($g)),
            'days' => $days,
            'weeks' => GoalService::weekly($days),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $manual = $request->boolean('manual');
        $data = $request->validate([
            'goal' => ['required', Rule::enum(Goal::class)],
            'activity_level' => ['required', Rule::in(array_keys(EnergyCalculator::ACTIVITY_LEVELS))],
            'valid_from' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:'.now()->addDays(60)->toDateString()],
            'manual' => ['boolean'],
            'kcal' => [Rule::requiredIf($manual), 'nullable', 'integer', 'between:800,6000'],
            'protein_g' => [Rule::requiredIf($manual), 'nullable', 'integer', 'between:0,500'],
            'carbs_g' => [Rule::requiredIf($manual), 'nullable', 'integer', 'between:0,1000'],
            'fat_g' => [Rule::requiredIf($manual), 'nullable', 'integer', 'between:0,400'],
        ], [], ['kcal' => 'calorias', 'protein_g' => 'proteínas', 'carbs_g' => 'carboidratos', 'fat_g' => 'gorduras', 'valid_from' => 'início']);

        $this->goals->save($request->user(), $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Meta salva.']);

        return to_route('goals.index');
    }
}
