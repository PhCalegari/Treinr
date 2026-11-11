<?php

namespace App\Http\Controllers\Workouts;

use App\Enums\ExperienceLevel;
use App\Enums\Goal;
use App\Http\Controllers\Concerns\ChecksOwnership;
use App\Http\Controllers\Controller;
use App\Models\WorkoutGeneration;
use App\Services\WorkoutAi\WorkoutGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** "Criar treino com IA": formulário, geração (prévia) e salvar como plano. */
class WorkoutAiController extends Controller
{
    use ChecksOwnership;

    public function __construct(private readonly WorkoutGenerator $generator) {}

    public function create(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('workouts/AiCreate', [
            'defaults' => [
                'goal' => $user->goal?->value ?? 'hypertrophy',
                'level' => $user->experience_level?->value ?? 'beginner',
                'days' => 3,
                'minutes' => 60,
                'place' => 'gym',
            ],
            'places' => collect(WorkoutGenerator::PLACES)->map(fn ($p, $k) => ['value' => $k, 'label' => $p['label']])->values(),
            'durations' => WorkoutGenerator::DURATIONS,
            'remaining' => max(0, (int) config('services.workout_ai.daily_limit', 5) - $this->generator->usedToday($user)),
            'aiEnabled' => filled(config('services.'.config('services.meal_ai.provider').'.api_key')),
        ]);
    }

    /** Pede o treino à IA e devolve a prévia (nada é salvo como plano ainda). */
    public function generate(Request $request): JsonResponse
    {
        set_time_limit(150);
        $data = $request->validate([
            'goal' => ['required', Rule::enum(Goal::class)],
            'level' => ['required', Rule::enum(ExperienceLevel::class)],
            'days' => ['required', 'integer', 'between:1,6'],
            'minutes' => ['required', 'integer', Rule::in(WorkoutGenerator::DURATIONS)],
            'place' => ['required', Rule::in(array_keys(WorkoutGenerator::PLACES))],
            'request' => ['nullable', 'string', 'max:800'],
        ], [], ['request' => 'pedido', 'days' => 'dias', 'minutes' => 'tempo']);

        $generation = $this->generator->generate($request->user(), $data);

        return response()->json([
            ...WorkoutGenerator::present($generation),
            'remaining' => max(0, (int) config('services.workout_ai.daily_limit', 5) - $this->generator->usedToday($request->user())),
        ]);
    }

    /** Salva a versão revisada como um plano comum. */
    public function save(Request $request, WorkoutGeneration $generation): RedirectResponse
    {
        $this->own($generation);
        $plan = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'days' => ['required', 'array', 'min:1', 'max:7'],
            'days.*.name' => ['required', 'string', 'max:120'],
            'days.*.exercises' => ['required', 'array', 'min:1', 'max:15'],
            'days.*.exercises.*.exercise_id' => ['required', 'integer'],
            'days.*.exercises.*.sets' => ['required', 'integer', 'between:1,10'],
            'days.*.exercises.*.reps_min' => ['nullable', 'integer', 'between:1,100'],
            'days.*.exercises.*.reps_max' => ['nullable', 'integer', 'between:1,100'],
            'days.*.exercises.*.rest_seconds' => ['nullable', 'integer', 'between:0,600'],
            'days.*.exercises.*.notes' => ['nullable', 'string', 'max:500'],
        ], [
            'days.*.exercises.required' => 'Cada treino precisa de pelo menos um exercício.',
            'days.*.exercises.min' => 'Cada treino precisa de pelo menos um exercício.',
        ], ['name' => 'nome do plano']);

        $model = $this->generator->save($request->user(), $generation, $plan);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Treino salvo! Já pode começar.']);

        return to_route('workouts.show', $model);
    }
}
