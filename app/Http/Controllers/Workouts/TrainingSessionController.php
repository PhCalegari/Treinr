<?php

namespace App\Http\Controllers\Workouts;

use App\Http\Controllers\Concerns\ChecksOwnership;
use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Models\SessionExercise;
use App\Models\SessionSet;
use App\Models\TrainingSession;
use App\Models\WorkoutDay;
use App\Services\WorkoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TrainingSessionController extends Controller
{
    use ChecksOwnership;

    public function __construct(private readonly WorkoutService $workouts) {}

    /** Histórico de treinos. */
    public function index(Request $request): Response
    {
        $sessions = TrainingSession::ownedBy($request->user()->id)
            ->with(['day', 'exercises.sets'])
            ->orderByDesc('started_at')
            ->paginate(20)
            ->through(fn (TrainingSession $s) => $this->summary($s));

        return Inertia::render('sessions/Index', ['sessions' => $sessions]);
    }

    /** Inicia um treino (de um dia do plano ou livre). Só pode haver um em andamento. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['workout_day_id' => ['nullable', 'uuid']]);
        $user = $request->user();

        $open = TrainingSession::ownedBy($user->id)->whereNull('finished_at')->first();
        if ($open) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('Você já tem um treino em andamento.')]);

            return to_route('sessions.show', $open);
        }

        $day = null;
        if (! empty($data['workout_day_id'])) {
            $day = WorkoutDay::with('exercises')->findOrFail($data['workout_day_id']);
            $this->own($day);
        }

        $session = $this->workouts->startSession($user, $day);

        return to_route('sessions.show', $session);
    }

    public function show(TrainingSession $session): Response
    {
        $this->own($session);
        $session->load(['day', 'exercises.exercise.media', 'exercises.exercise.category', 'exercises.exercise.muscles.group', 'exercises.planned', 'exercises.sets']);

        return Inertia::render('sessions/Show', [
            'session' => [
                ...$this->summary($session),
                'notes' => $session->notes,
                'share' => $session->isFinished() ? $this->shareCard($session) : null,
                'exercises' =>$session->exercises->map(function (SessionExercise $se) use ($session) {
                    $previous = $this->workouts->lastLoads($session->user_id, $se->exercise_id, $session->id);

                    return [
                        'id' => $se->id,
                        'exercise_id' => $se->exercise_id,
                        'name' => $se->exercise->displayName(),
                        'image' => $se->exercise->media->first()?->url,
                        // Quadros do movimento (início/fim), alternados no exercício aberto.
                        'frames' => $se->exercise->media->pluck('url')->take(2)->values(),
                        'muscle_groups' => $se->exercise->muscles->where('pivot.role', 'primary')
                            ->pluck('group')->filter()->unique('id')->pluck('name_pt')->values(),
                        // Janela "Como fazer": desenho dos músculos e passo a passo (em português quando houver).
                        'groups' => $se->exercise->muscles->where('pivot.role', 'primary')->pluck('group.code')->filter()->unique()->values(),
                        'secondary_groups' => $se->exercise->muscles->where('pivot.role', 'secondary')->pluck('group.code')->filter()->unique()->values(),
                        'instructions' => array_values(array_filter(explode("\n", (string) ($se->exercise->instructions_pt ?: $se->exercise->instructions)))),
                        'completed' => $se->completed,
                        'target' => $se->planned ? [
                            'reps_min' => $se->planned->reps_min,
                            'reps_max' => $se->planned->reps_max,
                            'rest_seconds' => $se->planned->rest_seconds,
                            'notes' => $se->planned->notes,
                        ] : null,
                        'previous' => $previous,
                        // strength (carga e reps), cardio (tempo e distância) ou time (só tempo).
                        'kind' => $se->exercise->trackingKind(),
                        'target_duration_sec' => $se->exercise->suggested_duration_sec,
                        // Última vez, evolução da carga e meta de hoje (progressão de carga).
                        'progression' => $this->workouts->progression($session->user_id, $se->exercise_id, $session->id, $se->planned?->reps_max, $se->exercise->trackingKind()),
                        'sets' => $se->sets->map(fn (SessionSet $s) => [
                            'id' => $s->id,
                            'set_number' => $s->set_number,
                            'reps' => $s->reps,
                            'load_kg' => $s->load_kg !== null ? (float) $s->load_kg : null,
                            'duration_sec' => $s->duration_sec,
                            'distance_km' => $s->distance_km,
                            'completed' => $s->completed,
                        ]),
                    ];
                }),
            ],
        ]);
    }

    /** Finaliza (ou reabre) o treino e salva observações. */
    public function update(Request $request, TrainingSession $session): RedirectResponse
    {
        $this->own($session);
        $data = $request->validate([
            'finished' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        if (array_key_exists('notes', $data)) {
            $session->notes = $data['notes'];
        }
        if (array_key_exists('finished', $data)) {
            $session->finished_at = $data['finished'] ? now() : null;
        }
        $session->save();

        // Ao finalizar, abre o resumo com o cartão para compartilhar.
        return $session->isFinished() ? to_route('sessions.show', [$session, 'share' => 1]) : back();
    }

    public function destroy(TrainingSession $session): RedirectResponse
    {
        $this->own($session);
        $session->load('exercises.sets');
        $this->workouts->deleteSession($session);

        return to_route('sessions.index');
    }

    /** Adiciona um exercício avulso ao treino em andamento. */
    public function addExercise(Request $request, TrainingSession $session): RedirectResponse
    {
        $this->own($session);
        $data = $request->validate(['exercise_id' => ['required', 'integer', Rule::exists('exercises', 'id')]]);
        $exercise = Exercise::query()->visibleTo($request->user())->findOrFail($data['exercise_id']);
        $this->workouts->addExerciseToSession($session, $exercise->id);

        return back();
    }

    /**
     * Dados do cartão para compartilhar o treino feito (estilo Hevy): músculos trabalhados,
     * séries por músculo (principal = 1, secundário = 0,5) e recordes de carga.
     */
    private function shareCard(TrainingSession $session): array
    {
        $owner = \App\Models\User::find($session->user_id);
        $dist = [];
        $DIST = [];
        $primary = collect();
        $secondary = collect();
        $records = 0;
        $exercises = [];

        foreach ($session->exercises as $se) {
            $done = $se->sets->where('completed', true);
            if ($done->isEmpty()) {
                continue;
            }
            $n = $done->count();
            $exercises[] = ['name' => $se->exercise->displayName(), 'sets' => $n];
            foreach ($se->exercise->muscles as $m) {
                if (! $m->group) {
                    continue;
                }
                $isPrimary = $m->pivot->role === 'primary';
                ($isPrimary ? $primary : $secondary)->push($m->group->code);
                $key = $m->group->code;
                $dist[$key] ??= ['name' => $m->group->name_pt, 'sets' => 0.0, 'counted' => []];
                // Um exercício conta uma vez por grupo (o maior papel vale).
                $weight = $isPrimary ? 1.0 : 0.5;
                $prev = $dist[$key]['counted'][$se->id] ?? 0;
                if ($weight > $prev) {
                    $dist[$key]['sets'] += ($weight - $prev) * $n;
                    $dist[$key]['counted'][$se->id] = $weight;
                }
            }

            // Recorde: maior carga já usada nesse exercício (precisa ter feito antes).
            $best = (float) $done->max('load_kg');
            if ($best > 0) {
                $before = \App\Models\SessionSet::query()
                    ->where('session_sets.user_id', $session->user_id)
                    ->where('session_sets.completed', true)
                    ->join('session_exercises', 'session_exercises.id', '=', 'session_sets.session_exercise_id')
                    ->join('training_sessions', 'training_sessions.id', '=', 'session_exercises.session_id')
                    ->whereNull('session_exercises.deleted_at')
                    ->whereNull('training_sessions.deleted_at')
                    ->where('session_exercises.exercise_id', $se->exercise_id)
                    ->where('training_sessions.started_at', '<', $session->started_at)
                    ->max('session_sets.load_kg');
                if ($before !== null && $best > (float) $before) {
                    $records++;
                }
            }
        }

        $distribution = collect($dist)
            ->map(fn ($d) => ['name' => $d['name'], 'sets' => round($d['sets'], 1)])
            ->sortByDesc('sets')->values();

        return [
            'author' => \Illuminate\Support\Str::of((string) $owner?->name)->before(' ')->value(),
            'sex' => $owner?->sex?->value,
            'groups' => $primary->unique()->values(),
            'secondary' => $secondary->unique()->diff($primary)->values(),
            'distribution' => $distribution,
            'records' => $records,
            'exercises' => $exercises,
        ];
    }

    /** Resumo usado no histórico e no topo do treino. */
    private function summary(TrainingSession $s): array
    {
        $sets = $s->exercises->flatMap->sets->where('completed', true);

        return [
            'id' => $s->id,
            'title' => $s->day?->title() ?? 'Treino livre',
            'started_at' => $s->started_at->toIso8601String(),
            'finished_at' => $s->finished_at?->toIso8601String(),
            'duration_min' => $s->finished_at ? (int) round($s->started_at->diffInMinutes($s->finished_at)) : null,
            'exercises_count' => $s->exercises->count(),
            'completed_sets' => $sets->count(),
            'volume_kg' => round($sets->sum(fn ($set) => (float) $set->load_kg * (int) $set->reps), 1),
        ];
    }
}
