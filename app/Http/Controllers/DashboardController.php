<?php

namespace App\Http\Controllers;

use App\Models\Meal;
use App\Models\NutritionGoal;
use App\Models\TrainingSession;
use App\Models\WorkoutPlan;
use App\Services\Food\DiaryService;
use App\Services\Nutrition\GoalService;
use App\Services\Progress\ProgressService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Painel inicial: calorias de hoje, próximo treino e peso. Só dados reais do usuário. */
class DashboardController extends Controller
{
    public function __invoke(Request $request, ProgressService $progress): Response
    {
        $user = $request->user();
        $today = now()->toDateString();

        $items = Meal::ownedBy($user->id)->whereDate('meal_date', $today)->with('items')->get()->flatMap->items;

        $open = TrainingSession::ownedBy($user->id)->whereNull('finished_at')->with('day')->first();
        $last = TrainingSession::ownedBy($user->id)->whereNotNull('finished_at')->with('day')->orderByDesc('started_at')->first();
        $weekCount = TrainingSession::ownedBy($user->id)->whereNotNull('finished_at')
            ->where('started_at', '>=', now()->startOfWeek())->count();

        return Inertia::render('Dashboard', [
            'nutrition' => [
                'totals' => DiaryService::totals($items),
                'goal' => GoalService::present(NutritionGoal::currentFor($user->id, $today)),
            ],
            'training' => [
                'open' => $open ? ['id' => $open->id, 'title' => $open->day?->title() ?? 'Treino livre'] : null,
                'next' => $this->nextDay($user->id, $last),
                'last' => $last ? ['title' => $last->day?->title() ?? 'Treino livre', 'date' => $last->started_at->toDateString()] : null,
                'week_count' => $weekCount,
            ],
            'progress' => $progress->overview($user)['summary'],
            'weekMuscles' => $this->weekMuscles($user->id),
        ]);
    }

    /**
     * Séries concluídas na semana (desde segunda) por grupo muscular principal,
     * para o boneco da tela inicial. Ordem dos grupos no app.
     *
     * @return list<array{code: string, name: string, sets: int}>
     */
    private function weekMuscles(int $userId): array
    {
        $sets = \App\Models\SessionSet::ownedBy($userId)
            ->where('completed', true)
            ->whereHas('sessionExercise.session', fn ($q) => $q->where('started_at', '>=', now()->startOfWeek()))
            ->with('sessionExercise.exercise.muscles.group')
            ->get();

        $count = [];
        $groups = [];
        foreach ($sets as $set) {
            $primary = $set->sessionExercise?->exercise?->muscles->where('pivot.role', 'primary')->pluck('group')->filter()->unique('id') ?? collect();
            foreach ($primary as $g) {
                $count[$g->code] = ($count[$g->code] ?? 0) + 1;
                $groups[$g->code] = $g;
            }
        }

        return collect($groups)->sortBy('position')
            ->map(fn ($g) => ['code' => $g->code, 'name' => $g->name_pt, 'sets' => $count[$g->code]])
            ->values()->all();
    }

    /** Próximo dia da divisão do plano ativo, seguindo o rodízio a partir do último treino. */
    private function nextDay(int $userId, ?TrainingSession $last): ?array
    {
        $plan = WorkoutPlan::ownedBy($userId)->where('active', true)->with('days')->orderByDesc('modified_at')->first();
        $days = $plan?->days->sortBy('position')->values();
        if (! $days || $days->isEmpty()) {
            return null;
        }
        $index = $last ? $days->search(fn ($d) => $d->id === $last->workout_day_id) : false;
        $next = $index === false ? $days->first() : $days[($index + 1) % $days->count()];

        return ['id' => $next->id, 'title' => $next->title(), 'plan' => $plan->name];
    }
}
