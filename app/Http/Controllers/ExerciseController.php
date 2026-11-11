<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\Exercise;
use App\Models\ExerciseCategory;
use App\Models\MuscleGroup;
use App\Services\ExerciseSearch;
use App\Services\WorkoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ExerciseController extends Controller
{
    public function __construct(private readonly ExerciseSearch $search) {}

    /** Lista com busca e filtros (grupo muscular, equipamento, categoria, nível). */
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'group' => ['nullable', 'string', 'exists:muscle_groups,code'],
            'equipment' => ['nullable', 'string', 'exists:equipment,code'],
            'category' => ['nullable', 'string', 'exists:exercise_categories,code'],
            'level' => ['nullable', Rule::in(['beginner', 'intermediate', 'advanced'])],
        ]);

        $query = Exercise::query()
            ->visibleTo($request->user())
            ->with(['category', 'media', 'muscles.group'])
            ->when($filters['group'] ?? null, fn ($q, $group) => $q->whereHas('muscles', fn ($m) => $m
                ->where('exercise_muscles.role', 'primary')
                ->whereHas('group', fn ($g) => $g->where('code', $group))))
            ->when($filters['equipment'] ?? null, fn ($q, $code) => $q->whereHas('equipment', fn ($e) => $e->where('code', $code)))
            ->when($filters['category'] ?? null, fn ($q, $code) => $q->whereHas('category', fn ($c) => $c->where('code', $code)))
            ->when($filters['level'] ?? null, fn ($q, $level) => $q->where('level', $level));

        if (! empty($filters['q'])) {
            $this->search->apply($query, $filters['q']);
        }

        $exercises = $query->orderByRaw('COALESCE(name_pt, name)')
            ->paginate(24)
            ->withQueryString()
            ->through(fn (Exercise $e) => $this->summary($e));

        return Inertia::render('exercises/Index', [
            'exercises' => $exercises,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'group' => $filters['group'] ?? '',
                'equipment' => $filters['equipment'] ?? '',
                'category' => $filters['category'] ?? '',
                'level' => $filters['level'] ?? '',
            ],
            'options' => $this->filterOptions(),
        ]);
    }

    public function show(Request $request, Exercise $exercise, WorkoutService $workouts): Response
    {
        abort_unless(
            Exercise::query()->visibleTo($request->user())->whereKey($exercise->id)->exists(),
            404
        );

        $exercise->load(['category', 'media', 'muscles.group', 'equipment']);

        return Inertia::render('exercises/Show', [
            'exercise' => $this->detail($exercise),
            // Histórico do próprio usuário com este exercício (mais recente primeiro).
            'history' => $workouts->exerciseHistory($request->user()->id, $exercise->id),
        ]);
    }

    /** Busca rápida em JSON para o seletor de exercícios ao montar/executar um treino. */
    public function search(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'group' => ['nullable', 'string', 'exists:muscle_groups,code'],
        ]);

        $query = Exercise::query()->visibleTo($request->user())
            ->with(['media', 'muscles.group', 'category'])
            ->when($filters['group'] ?? null, fn ($q, $group) => $q->whereHas('muscles', fn ($m) => $m
                ->where('exercise_muscles.role', 'primary')
                ->whereHas('group', fn ($g) => $g->where('code', $group))));
        if (! empty($filters['q'])) {
            $this->search->apply($query, $filters['q']);
        }

        return response()->json(
            $query->orderByRaw('COALESCE(name_pt, name)')->limit(30)->get()->map(fn (Exercise $e) => $this->summary($e))
        );
    }

    /**
     * Biblioteca completa em um JSON compacto, para o app guardar e usar offline.
     * Responde 304 quando o aparelho já tem a versão atual (ETag).
     */
    public function library(Request $request): JsonResponse
    {
        $user = $request->user();
        $version = md5(
            (string) Exercise::query()->visibleTo($user)->max('updated_at')
            .'|'.Exercise::query()->visibleTo($user)->count()
        );
        $etag = '"'.$version.'"';

        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304)->setEtag($version);
        }

        $exercises = Exercise::query()->visibleTo($user)
            ->with(['category', 'media', 'muscles.group', 'equipment'])
            ->orderBy('id')
            ->get()
            ->map(fn (Exercise $e) => $this->detail($e));

        return response()->json([
            'version' => $version,
            'groups' => MuscleGroup::orderBy('position')->get(['code', 'name_pt']),
            'exercises' => $exercises,
        ])->setEtag($version);
    }

    /** Dados resumidos para a lista. */
    private function summary(Exercise $e): array
    {
        return [
            'id' => $e->id,
            'name' => $e->displayName(),
            'original_name' => $e->name_pt ? $e->name : null,
            'category' => $e->category?->name_pt,
            'level' => $e->level,
            'image' => $e->media->firstWhere('media_type', 'image')?->url,
            'primary_groups' => $this->groupsFor($e, 'primary'),
        ];
    }

    /** Dados completos para o detalhe (e para a cópia offline). */
    private function detail(Exercise $e): array
    {
        $muscles = fn (string $role) => $e->muscles
            ->where('pivot.role', $role)
            ->map(fn ($m) => ['code' => $m->code, 'name' => $m->name_pt, 'group' => $m->group?->name_pt])
            ->values();

        $instructions = $e->instructions_pt ?: $e->instructions;

        return [
            ...$this->summary($e),
            'category_code' => $e->category?->code,
            'mechanic' => $e->mechanic,
            'force_type' => $e->force_type,
            'instructions' => $instructions ? array_values(array_filter(explode("\n", $instructions))) : [],
            'instructions_language' => $e->instructions_pt ? 'pt' : 'en',
            'suggested' => [
                'sets' => $e->suggested_sets,
                'reps_min' => $e->suggested_reps_min,
                'reps_max' => $e->suggested_reps_max,
                'duration_sec' => $e->suggested_duration_sec,
            ],
            'primary_muscles' => $muscles('primary'),
            'secondary_muscles' => $muscles('secondary'),
            'secondary_groups' => array_values(array_diff($this->groupsFor($e, 'secondary'), $this->groupsFor($e, 'primary'))),
            'equipment' => $e->equipment->pluck('name_pt')->values(),
            'images' => $e->media->where('media_type', 'image')->pluck('url')->values(),
            'videos' => $e->media->where('media_type', 'video')->pluck('url')->values(),
            'attribution' => $e->media->first()?->attribution
                ?? ($e->source === 'free_exercise_db' ? 'Free Exercise DB (Unlicense)' : null),
            'is_custom' => $e->created_by_user_id !== null,
        ];
    }

    /** @return list<string> nomes dos grupos musculares, sem repetição, na ordem do app */
    private function groupsFor(Exercise $e, string $role): array
    {
        return $e->muscles
            ->where('pivot.role', $role)
            ->pluck('group')
            ->filter()
            ->unique('id')
            ->sortBy('position')
            ->pluck('name_pt')
            ->values()
            ->all();
    }

    private function filterOptions(): array
    {
        return [
            'groups' => MuscleGroup::orderBy('position')->get(['code', 'name_pt']),
            'equipment' => Equipment::orderBy('name_pt')->get(['code', 'name_pt']),
            'categories' => ExerciseCategory::orderBy('name_pt')->get(['code', 'name_pt']),
        ];
    }
}
