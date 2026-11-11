<?php

namespace App\Http\Controllers\Workouts;

use App\Http\Controllers\Concerns\ChecksOwnership;
use App\Http\Controllers\Controller;
use App\Models\TrainingSession;
use App\Models\WorkoutDay;
use App\Models\WorkoutExercise;
use App\Models\WorkoutPlan;
use App\Services\WorkoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WorkoutPlanController extends Controller
{
    use ChecksOwnership;

    public function __construct(
        private readonly WorkoutService $workouts,
        private readonly \App\Services\WorkoutOverview $overview,
    ) {}

    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        $plans = WorkoutPlan::ownedBy($userId)
            ->with('days')
            ->orderByDesc('active')->orderByDesc('created_at')
            ->get()
            ->map(fn (WorkoutPlan $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'split_type' => $p->split_type,
                'active' => $p->active,
                'days' => $p->days->map(fn (WorkoutDay $d) => ['id' => $d->id, 'label' => $d->label, 'name' => $d->name, 'title' => $d->title()]),
            ]);

        $open = TrainingSession::ownedBy($userId)->whereNull('finished_at')->with('day')->latest('started_at')->first();

        return Inertia::render('workouts/Index', [
            'plans' => $plans,
            'openSession' => $open ? [
                'id' => $open->id,
                'title' => $open->day?->title() ?? 'Treino livre',
                'started_at' => $open->started_at->toIso8601String(),
            ] : null,
            'splits' => WorkoutPlan::SPLITS,
            // Cabeçalho no estilo "seu plano": últimos 7 dias, atividade e recentes.
            'week' => $this->overview->week($userId),
            'activity' => $this->overview->activity($userId),
            'recent' => $this->overview->recent($userId),
            'nextDayId' => $this->overview->nextDayId($userId),
            // Atalho da recuperação: cor de cada grupo e quantos estão prontos.
            'recovery' => collect($this->overview->recovery($userId))->map(fn ($m) => ['code' => $m['code'], 'name' => $m['name'], 'status' => $m['status']])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'split_type' => ['required', Rule::in(WorkoutPlan::SPLITS)],
        ]);

        $plan = $this->workouts->createPlan($request->user(), $data['name'], $data['split_type']);

        return to_route('workouts.show', $plan);
    }

    public function show(WorkoutPlan $plan): Response
    {
        $this->own($plan);
        $plan->load(['days.exercises.exercise.media', 'days.exercises.exercise.category', 'days.exercises.exercise.muscles.group']);

        return Inertia::render('workouts/Show', [
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'split_type' => $plan->split_type,
                'active' => $plan->active,
                'notes' => $plan->notes,
                'share_url' => $plan->share_token ? route('shared-plans.show', $plan->share_token) : null,
                'days' => $plan->days->map(fn (WorkoutDay $d) => [
                    'id' => $d->id,
                    'label' => $d->label,
                    'name' => $d->name,
                    'title' => $d->title(),
                    // Grupos musculares trabalhados no dia (principais), na ordem do app.
                    'muscle_groups' => $d->exercises
                        ->flatMap(fn ($we) => $we->exercise->muscles->where('pivot.role', 'primary')->pluck('group'))
                        ->filter()->unique('id')->sortBy('position')->pluck('name_pt')->values(),
                    // Para o desenho dos músculos: código + nome, na ordem do app.
                    'muscles' => $d->exercises
                        ->flatMap(fn ($we) => $we->exercise->muscles->where('pivot.role', 'primary')->pluck('group'))
                        ->filter()->unique('id')->sortBy('position')
                        ->map(fn ($g) => ['code' => $g->code, 'name' => $g->name_pt])->values(),
                    'exercises' => $d->exercises->map(fn (WorkoutExercise $we) => [
                        'id' => $we->id,
                        'exercise_id' => $we->exercise_id,
                        'name' => $we->exercise->displayName(),
                        'image' => $we->exercise->media->first()?->url,
                        'groups' => $we->exercise->muscles->where('pivot.role', 'primary')->pluck('group.code')->filter()->unique()->values(),
                        'secondary_groups' => $we->exercise->muscles->where('pivot.role', 'secondary')->pluck('group.code')->filter()->unique()->values(),
                        'sets' => $we->sets,
                        'reps_min' => $we->reps_min,
                        'reps_max' => $we->reps_max,
                        'target_load_kg' => $we->target_load_kg !== null ? (float) $we->target_load_kg : null,
                        'rest_seconds' => $we->rest_seconds,
                        'notes' => $we->notes,
                        'kind' => $we->exercise->trackingKind(),
                        'duration_sec' => $we->exercise->suggested_duration_sec,
                        // Melhor série da última vez (para lembrar a carga sem abrir o treino).
                        'last' => $this->lastBest($plan->user_id, $we->exercise_id),
                    ]),
                ]),
            ],
        ]);
    }

    /** Melhor série da última sessão do exercício: maior carga (ou maior tempo, no cardio). */
    private function lastBest(int $userId, int $exerciseId): ?array
    {
        $h = $this->workouts->exerciseHistory($userId, $exerciseId, 3)->first();
        if (! $h) {
            return null;
        }
        $best = collect($h['sets'])->sortByDesc(fn ($s) => [($s['load_kg'] ?? 0), ($s['reps'] ?? 0), ($s['duration_sec'] ?? 0)])->first();

        return [
            'date' => $h['date'],
            'load_kg' => $best['load_kg'] ?? null,
            'reps' => $best['reps'] ?? null,
            'duration_sec' => $best['duration_sec'] ?? null,
            'distance_km' => $best['distance_km'] ?? null,
        ];
    }

    public function update(Request $request, WorkoutPlan $plan): RedirectResponse
    {
        $this->own($plan);
        $plan->fill($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'active' => ['sometimes', 'boolean'],
        ]))->save();

        return back();
    }

    public function destroy(WorkoutPlan $plan): RedirectResponse
    {
        $this->own($plan);
        $this->workouts->deletePlan($plan);

        return to_route('workouts.index');
    }
}
