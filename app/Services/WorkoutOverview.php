<?php

namespace App\Services;

use App\Models\SessionExercise;
use App\Models\TrainingSession;
use Illuminate\Support\Collection;

/** Resumo da tela "Treinos": últimos 7 dias (músculos por dia), atividade e treinos recentes. */
class WorkoutOverview
{
    /**
     * Últimos 7 dias (do mais antigo até hoje), cada um com os treinos e os músculos trabalhados.
     *
     * @return list<array<string, mixed>>
     */
    public function week(int $userId): array
    {
        $start = now()->subDays(6)->startOfDay();
        $sessions = TrainingSession::ownedBy($userId)
            ->where('started_at', '>=', $start)
            ->with(['day', 'exercises.sets', 'exercises.exercise.muscles.group'])
            ->orderBy('started_at')
            ->get();

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $start->copy()->addDays($i);
            $daySessions = $sessions->filter(fn ($s) => $s->started_at->isSameDay($date))->values();
            $days[] = ['date' => $date->toDateString(), ...$this->muscles($daySessions), 'sessions' => $daySessions->map(fn (TrainingSession $s) => [
                'id' => $s->id,
                'title' => $s->day?->title() ?? 'Treino livre',
                'label' => $s->day?->label ?? 'L',
                'finished' => $s->finished_at !== null,
            ])->all()];
        }

        return $days;
    }

    /**
     * Grupos principais e secundários dos exercícios com séries feitas, e total de séries.
     *
     * @param  Collection<int, TrainingSession>  $sessions
     * @return array{groups: list<string>, secondary: list<string>, sets: int}
     */
    private function muscles(Collection $sessions): array
    {
        $primary = collect();
        $secondary = collect();
        $sets = 0;
        foreach ($sessions as $s) {
            foreach ($s->exercises as $se) {
                /** @var SessionExercise $se */
                $done = $se->sets->where('completed', true)->count();
                if (! $done) {
                    continue;
                }
                $sets += $done;
                foreach ($se->exercise->muscles as $m) {
                    if ($m->group) {
                        ($m->pivot->role === 'primary' ? $primary : $secondary)->push($m->group->code);
                    }
                }
            }
        }
        $primary = $primary->unique()->values();

        return ['groups' => $primary->all(), 'secondary' => $secondary->unique()->diff($primary)->values()->all(), 'sets' => $sets];
    }

    /**
     * Atividade dos últimos 3 meses: horas acumuladas a cada treino finalizado.
     *
     * @return array{points: list<array{date: string, value: float}>, total_min: int, count: int}
     */
    public function activity(int $userId): array
    {
        $sessions = TrainingSession::ownedBy($userId)
            ->whereNotNull('finished_at')
            ->where('started_at', '>=', now()->subMonths(3)->startOfDay())
            ->orderBy('started_at')
            ->get(['id', 'started_at', 'finished_at']);

        $total = 0;
        $byDate = [];
        foreach ($sessions as $s) {
            $total += (int) round($s->started_at->diffInMinutes($s->finished_at));
            $byDate[$s->started_at->toDateString()] = round($total / 60, 1);
        }

        return [
            'points' => collect($byDate)->map(fn ($v, $d) => ['date' => $d, 'value' => $v])->values()->all(),
            'total_min' => $total,
            'count' => $sessions->count(),
        ];
    }

    /** Últimos treinos finalizados. */
    public function recent(int $userId, int $limit = 5): array
    {
        return TrainingSession::ownedBy($userId)
            ->whereNotNull('finished_at')
            ->with(['day', 'exercises.sets'])
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get()
            ->map(fn (TrainingSession $s) => [
                'id' => $s->id,
                'title' => $s->day?->title() ?? 'Treino livre',
                'date' => $s->started_at->toIso8601String(),
                'exercises' => $s->exercises->filter(fn ($se) => $se->sets->contains('completed', true))->count(),
                'duration_min' => (int) round($s->started_at->diffInMinutes($s->finished_at)),
            ])->all();
    }

    /**
     * Recuperação muscular (estimativa simples):
     * cada série feita soma fadiga ao grupo (principal 12%, auxiliar 6%) e a fadiga cai pela metade
     * a cada ~17 h (grupos grandes ~20 h). Um treino pesado (≈100%) volta a 95% em uns 3 dias.
     *
     * @return list<array{code: string, name: string, fatigue: int, hours_to_95: int, last_trained: string|null, status: string}>
     */
    public function recovery(int $userId): array
    {
        $sets = \App\Models\SessionSet::ownedBy($userId)
            ->where('completed', true)
            ->whereHas('sessionExercise.session', fn ($q) => $q->where('started_at', '>=', now()->subDays(10)))
            ->with(['sessionExercise.session', 'sessionExercise.exercise.muscles.group'])
            ->get();

        $big = ['QUADRICEPS', 'HAMSTRINGS', 'GLUTES', 'BACK', 'CHEST'];
        $fatigue = [];
        $last = [];
        foreach ($sets as $set) {
            $se = $set->sessionExercise;
            if (! $se?->exercise || ! $se->session) {
                continue;
            }
            $when = $set->completed_at ?? $se->session->started_at;
            $hours = max(0, $when->diffInMinutes(now()) / 60);
            // Um exercício conta uma vez por grupo (vale o papel mais forte).
            $weights = [];
            foreach ($se->exercise->muscles as $m) {
                if ($m->group) {
                    $w = $m->pivot->role === 'primary' ? 12 : 6;
                    $weights[$m->group->code] = max($weights[$m->group->code] ?? 0, $w);
                }
            }
            foreach ($weights as $code => $w) {
                $tau = in_array($code, $big, true) ? 29 : 24; // horas (meia-vida ≈ tau × 0,69)
                $fatigue[$code] = ($fatigue[$code] ?? 0) + $w * exp(-$hours / $tau);
                $last[$code] = max($last[$code] ?? $when, $when);
            }
        }

        return \App\Models\MuscleGroup::query()->orderBy('position')->get()
            ->map(function ($g) use ($fatigue, $last, $big) {
                $f = min(100, $fatigue[$g->code] ?? 0);
                $tau = in_array($g->code, $big, true) ? 29 : 24;

                return [
                    'code' => $g->code,
                    'name' => $g->name_pt,
                    'fatigue' => (int) round($f),
                    // Horas até a fadiga cair para 5% (= 95% recuperado).
                    'hours_to_95' => $f > 5 ? (int) ceil($tau * log($f / 5)) : 0,
                    'last_trained' => isset($last[$g->code]) ? $last[$g->code]->toIso8601String() : null,
                    'status' => $f >= 60 ? 'fatigued' : ($f >= 20 ? 'recovering' : 'fresh'),
                ];
            })->values()->all();
    }

    /** Dia que vem a seguir no rodízio do plano ativo (A → B → C → A...). */
    public function nextDayId(int $userId): ?string
    {
        $last = TrainingSession::ownedBy($userId)->whereNotNull('workout_day_id')->latest('started_at')->first();
        $plan = \App\Models\WorkoutPlan::ownedBy($userId)->where('active', true)->with('days')->orderByDesc('modified_at')->first();
        $days = $plan?->days->sortBy('position')->values();
        if (! $days || $days->isEmpty()) {
            return null;
        }
        $index = $last ? $days->search(fn ($d) => $d->id === $last->workout_day_id) : false;

        return ($index === false ? $days->first() : $days[($index + 1) % $days->count()])->id;
    }

}
