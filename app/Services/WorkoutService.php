<?php

namespace App\Services;

use App\Models\Exercise;
use App\Models\SessionExercise;
use App\Models\SessionSet;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WorkoutDay;
use App\Models\WorkoutExercise;
use App\Models\WorkoutPlan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Regras de negócio de treinos (planejamento e execução). */
class WorkoutService
{
    /** Cria o plano já com os dias da divisão escolhida (ABC => A, B, C). */
    public function createPlan(User $user, string $name, string $split): WorkoutPlan
    {
        return DB::transaction(function () use ($user, $name, $split) {
            $plan = new WorkoutPlan(['name' => $name, 'split_type' => $split, 'active' => true]);
            $plan->user_id = $user->id;
            $plan->save();

            $labels = $split === 'custom' ? ['A'] : str_split(strtoupper($split));
            foreach ($labels as $i => $label) {
                $this->createDay($plan, $label, $i);
            }

            return $plan;
        });
    }

    public function createDay(WorkoutPlan $plan, ?string $label = null, ?int $position = null, ?string $name = null): WorkoutDay
    {
        $position ??= (int) $plan->days()->max('position') + 1;
        // Próxima letra livre quando não informada (A, B, C, ...).
        $label ??= $this->nextLabel($plan);

        $day = new WorkoutDay(['plan_id' => $plan->id, 'label' => $label, 'name' => $name, 'position' => $position]);
        $day->user_id = $plan->user_id;
        $day->save();

        return $day;
    }

    /** Copia um plano (dias e exercícios) para outro usuário — usado no link de compartilhamento. */
    public function copyPlan(WorkoutPlan $source, User $user): WorkoutPlan
    {
        $source->loadMissing('days.exercises');

        return DB::transaction(function () use ($source, $user) {
            $plan = new WorkoutPlan(['name' => $source->name, 'split_type' => $source->split_type, 'active' => true, 'notes' => $source->notes]);
            $plan->user_id = $user->id;
            $plan->save();

            foreach ($source->days as $d) {
                $day = $this->createDay($plan, $d->label, $d->position, $d->name);
                foreach ($d->exercises as $we) {
                    $item = new WorkoutExercise([
                        'workout_day_id' => $day->id,
                        'exercise_id' => $we->exercise_id,
                        'position' => $we->position,
                        'sets' => $we->sets,
                        'reps_min' => $we->reps_min,
                        'reps_max' => $we->reps_max,
                        // A carga é pessoal: quem recebe começa sem carga-alvo.
                        'target_load_kg' => null,
                        'rest_seconds' => $we->rest_seconds,
                        'notes' => $we->notes,
                    ]);
                    $item->user_id = $user->id;
                    $item->save();
                }
            }

            return $plan;
        });
    }

    /** Exclusão lógica em cascata (plano -> dias -> exercícios). */
    public function deletePlan(WorkoutPlan $plan): void
    {
        DB::transaction(function () use ($plan) {
            foreach ($plan->days as $day) {
                $this->deleteDay($day);
            }
            $plan->markDeleted();
        });
    }

    public function deleteDay(WorkoutDay $day): void
    {
        DB::transaction(function () use ($day) {
            $day->exercises->each->markDeleted();
            $day->markDeleted();
        });
    }

    /** Adiciona um exercício ao dia, com a sugestão da biblioteca como ponto de partida. */
    public function addExercise(WorkoutDay $day, Exercise $exercise, array $params = []): WorkoutExercise
    {
        $item = new WorkoutExercise([
            'workout_day_id' => $day->id,
            'exercise_id' => $exercise->id,
            'position' => (int) $day->exercises()->max('position') + 1,
            'sets' => $params['sets'] ?? $exercise->suggested_sets ?? 3,
            'reps_min' => $params['reps_min'] ?? $exercise->suggested_reps_min,
            'reps_max' => $params['reps_max'] ?? $exercise->suggested_reps_max,
            'target_load_kg' => $params['target_load_kg'] ?? null,
            'rest_seconds' => $params['rest_seconds'] ?? 90,
        ]);
        $item->user_id = $day->user_id;
        $item->save();

        return $item;
    }

    /** Sobe ou desce um exercício na ordem do dia. */
    public function move(WorkoutExercise $item, int $direction): void
    {
        DB::transaction(function () use ($item, $direction) {
            $siblings = WorkoutExercise::where('workout_day_id', $item->workout_day_id)->orderBy('position')->get()->values();
            $index = $siblings->search(fn ($s) => $s->id === $item->id);
            $target = $index + ($direction < 0 ? -1 : 1);
            if ($index === false || ! isset($siblings[$target])) {
                return;
            }
            $ordered = $siblings->all();
            [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];
            foreach ($ordered as $pos => $s) {
                if ($s->position !== $pos + 1) {
                    $s->position = $pos + 1;
                    $s->save();
                }
            }
        });
    }

    /**
     * Inicia uma sessão a partir de um dia do plano. As séries já vêm criadas,
     * com a carga da última vez que o exercício foi feito (ou a carga-alvo).
     */
    public function startSession(User $user, ?WorkoutDay $day): TrainingSession
    {
        return DB::transaction(function () use ($user, $day) {
            $session = new TrainingSession(['workout_day_id' => $day?->id, 'started_at' => now()]);
            $session->user_id = $user->id;
            $session->save();

            foreach ($day?->exercises ?? [] as $i => $planned) {
                $this->addExerciseToSession($session, $planned->exercise_id, $planned, $i + 1);
            }

            return $session;
        });
    }

    public function addExerciseToSession(TrainingSession $session, int $exerciseId, ?WorkoutExercise $planned = null, ?int $position = null): SessionExercise
    {
        $se = new SessionExercise([
            'session_id' => $session->id,
            'exercise_id' => $exerciseId,
            'workout_exercise_id' => $planned?->id,
            'position' => $position ?? (int) $session->exercises()->max('position') + 1,
            'completed' => false,
        ]);
        $se->user_id = $session->user_id;
        $se->save();

        $previous = $this->lastLoads($session->user_id, $exerciseId, $session->id);
        // Cardio/alongamento costuma ser uma série só (ex.: 10 min de bicicleta).
        $exercise = Exercise::with('category')->find($exerciseId);
        $default = $exercise?->trackingKind() === 'strength' ? 3 : ($exercise->suggested_sets ?? 1);
        $count = $planned?->sets ?? max(count($previous), $default);
        for ($n = 1; $n <= $count; $n++) {
            $this->createSet($se, $n, $previous[$n] ?? ($planned?->target_load_kg) ?? (end($previous) ?: null));
        }

        return $se;
    }

    public function createSet(SessionExercise $se, int $number, $load = null): SessionSet
    {
        $set = new SessionSet([
            'session_exercise_id' => $se->id,
            'set_number' => $number,
            'load_kg' => $load,
            'completed' => false,
        ]);
        $set->user_id = $se->user_id;
        $set->save();

        return $set;
    }

    /** Exclusão lógica da sessão inteira. */
    public function deleteSession(TrainingSession $session): void
    {
        DB::transaction(function () use ($session) {
            foreach ($session->exercises as $se) {
                $se->sets->each->markDeleted();
                $se->markDeleted();
            }
            $session->markDeleted();
        });
    }

    /**
     * Cargas da última sessão em que o exercício teve séries concluídas.
     *
     * @return array<int, string> número da série => carga
     */
    public function lastLoads(int $userId, int $exerciseId, ?string $exceptSessionId = null): array
    {
        $last = SessionExercise::ownedBy($userId)
            ->where('exercise_id', $exerciseId)
            ->when($exceptSessionId, fn ($q) => $q->where('session_id', '!=', $exceptSessionId))
            ->whereHas('sets', fn ($q) => $q->where('completed', true))
            ->join('training_sessions', 'training_sessions.id', '=', 'session_exercises.session_id')
            ->whereNull('training_sessions.deleted_at')
            ->orderByDesc('training_sessions.started_at')
            ->select('session_exercises.*')
            ->first();

        if (! $last) {
            return [];
        }

        return $last->sets()->where('completed', true)->whereNotNull('load_kg')->pluck('load_kg', 'set_number')->all();
    }

    /**
     * Histórico de um exercício: uma linha por sessão, com séries e melhor carga.
     */
    /**
     * Resumo para buscar progressão de carga num exercício: a última vez que foi feito
     * (série a série), a carga máxima dos últimos treinos e a meta de hoje.
     *
     * Meta (progressão dupla): se na última vez todas as séries chegaram ao máximo de repetições
     * do alvo, sobe a carga (+2,5 kg; +1 kg em cargas leves); senão, mantém a carga e busca +1 repetição.
     */
    public function progression(int $userId, int $exerciseId, ?string $exceptSessionId = null, ?int $targetRepsMax = null, string $kind = 'strength'): ?array
    {
        $timed = $kind !== 'strength';
        $history = $this->exerciseHistory($userId, $exerciseId, 12)
            ->reject(fn ($h) => $h['session_id'] === $exceptSessionId)
            // Só conta treino com carga ou repetições anotadas (marcar "feito" sem anotar não ajuda a progredir).
            ->filter(fn ($h) => collect($h['sets'])->contains(fn ($s) => ($s['load_kg'] ?? 0) > 0 || ($s['reps'] ?? 0) > 0 || ($s['duration_sec'] ?? 0) > 0 || ($s['distance_km'] ?? 0) > 0))
            ->take(8)
            ->values();
        if ($history->isEmpty()) {
            return null;
        }

        $last = $history->first();
        $loads = collect($last['sets'])->pluck('load_kg')->filter(fn ($l) => $l !== null);
        $reps = collect($last['sets'])->pluck('reps')->filter(fn ($r) => $r !== null);
        $topLoad = $loads->max();

        $suggestion = null;
        // Cardio/alongamento não tem meta de carga.
        if (! $timed && $topLoad !== null && $topLoad > 0 && $reps->isNotEmpty()) {
            $goal = $targetRepsMax ?: 12;
            $hitAll = collect($last['sets'])->every(fn ($s) => $s['reps'] !== null && $s['reps'] >= $goal);
            if ($hitAll) {
                $step = $topLoad < 10 ? 1 : 2.5;
                $suggestion = ['load_kg' => round($topLoad + $step, 2), 'reps' => null,
                    'reason' => "Você fez {$goal}+ repetições em todas as séries: suba a carga."];
            } else {
                $suggestion = ['load_kg' => (float) $topLoad, 'reps' => min($goal, (int) $reps->max() + 1),
                    'reason' => 'Mantenha a carga e tente uma repetição a mais.'];
            }
        }

        // Evolução, do treino mais antigo ao mais recente: carga máxima (força) ou minutos totais (cardio/alongamento).
        $trend = $history->reverse()->values()
            ->map(fn ($h) => ['date' => $h['date'], 'value' => $timed
                ? round(collect($h['sets'])->sum('duration_sec') / 60, 1)
                : (float) $h['best_load_kg']])
            ->filter(fn ($p) => $p['value'] > 0)
            ->values();

        return [
            'last' => [
                'date' => $last['date'],
                'days_ago' => (int) \Illuminate\Support\Carbon::parse($last['date'])->startOfDay()->diffInDays(now()->startOfDay()),
                'sets' => $last['sets'],
            ],
            'unit' => $timed ? 'min' : 'kg',
            'trend' => $trend,
            'change' => $trend->count() > 1 ? round($trend->last()['value'] - $trend->first()['value'], 2) : null,
            'since' => $trend->count() > 1 ? $trend->first()['date'] : null,
            'suggestion' => $suggestion,
        ];
    }

    public function exerciseHistory(int $userId, int $exerciseId, int $limit = 30): Collection
    {
        return SessionExercise::ownedBy($userId)
            ->where('exercise_id', $exerciseId)
            ->join('training_sessions', 'training_sessions.id', '=', 'session_exercises.session_id')
            ->whereNull('training_sessions.deleted_at')
            ->orderByDesc('training_sessions.started_at')
            ->select('session_exercises.*', 'training_sessions.started_at as session_started_at')
            ->with(['sets' => fn ($q) => $q->where('completed', true)])
            ->limit($limit)
            ->get()
            ->filter(fn ($se) => $se->sets->isNotEmpty())
            ->map(fn ($se) => [
                'session_id' => $se->session_id,
                'date' => \Illuminate\Support\Carbon::parse($se->session_started_at)->toDateString(),
                'sets' => $se->sets->map(fn ($s) => ['reps' => $s->reps, 'load_kg' => $s->load_kg !== null ? (float) $s->load_kg : null,
                    'duration_sec' => $s->duration_sec, 'distance_km' => $s->distance_km])->values(),
                'best_load_kg' => $se->sets->max(fn ($s) => (float) $s->load_kg),
                'volume_kg' => round($se->sets->sum(fn ($s) => (float) $s->load_kg * (int) $s->reps), 1),
            ])
            ->values();
    }

    private function nextLabel(WorkoutPlan $plan): string
    {
        $used = $plan->days()->pluck('label')->all();
        foreach (range('A', 'Z') as $letter) {
            if (! in_array($letter, $used, true)) {
                return $letter;
            }
        }

        return (string) (count($used) + 1);
    }
}
