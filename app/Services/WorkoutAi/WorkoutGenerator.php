<?php

namespace App\Services\WorkoutAi;

use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutGeneration;
use App\Models\WorkoutPlan;
use App\Services\Ai\JsonAi;
use App\Services\MealPhoto\MealAiException;
use App\Services\WorkoutService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * "Personal trainer" com IA: a pessoa descreve o que quer e a IA monta o plano usando
 * SOMENTE exercícios da biblioteca (catálogo enviado no pedido, filtrado por equipamento e nível).
 * Nada é salvo até a pessoa revisar; o plano salvo é um plano comum, editável.
 */
class WorkoutGenerator
{
    public const PLACES = [
        'gym' => ['label' => 'Academia completa', 'equipment' => ['barbell', 'dumbbell', 'cable', 'machine', 'body only', 'e-z curl bar', 'kettlebells', 'bands', 'exercise ball', 'medicine ball', 'other']],
        'home' => ['label' => 'Casa com halteres/elásticos', 'equipment' => ['dumbbell', 'body only', 'bands', 'kettlebells', 'exercise ball']],
        'bodyweight' => ['label' => 'Só o peso do corpo', 'equipment' => ['body only']],
    ];

    public const DURATIONS = [30, 45, 60, 90];

    /** Exercícios por treino conforme o tempo disponível. */
    private const EXERCISES_FOR = [30 => '4 a 5', 45 => '5 a 6', 60 => '6 a 8', 90 => '8 a 10'];

    private const GOALS = ['hypertrophy' => 'hipertrofia (ganhar massa muscular)', 'weight_loss' => 'emagrecimento', 'maintenance' => 'manutenção e saúde'];

    private const LEVELS = ['beginner' => 'iniciante', 'intermediate' => 'intermediário', 'advanced' => 'avançado'];

    public function __construct(private readonly JsonAi $ai, private readonly WorkoutService $workouts) {}

    public function usedToday(User $user): int
    {
        return WorkoutGeneration::where('user_id', $user->id)->where('created_at', '>=', now()->startOfDay())->count();
    }

    /**
     * Catálogo enviado à IA: exercícios de força/cardio/pliometria com o equipamento disponível,
     * agrupados pelo grupo muscular principal. Iniciante não recebe os de nível avançado.
     *
     * @return Collection<int, array{id: int, name: string, group: string}>
     */
    public function catalog(string $place, string $level): Collection
    {
        $allowed = self::PLACES[$place]['equipment'];

        return Exercise::query()
            ->whereNull('created_by_user_id')
            ->where('active', true)
            ->whereHas('category', fn ($q) => $q->whereIn('code', ['strength', 'powerlifting', 'plyometrics', 'cardio']))
            ->when($level === 'beginner', fn ($q) => $q->where(fn ($w) => $w->where('level', '!=', 'advanced')->orWhereNull('level')))
            // Todo equipamento do exercício precisa estar disponível.
            ->whereDoesntHave('equipment', fn ($q) => $q->whereNotIn('code', $allowed))
            ->with(['muscles' => fn ($q) => $q->wherePivot('role', 'primary')->with('group')])
            ->get()
            ->map(fn (Exercise $e) => [
                'id' => $e->id,
                'name' => $e->displayName(),
                'group' => $e->muscles->pluck('group.name_pt')->filter()->first() ?? 'Outros',
            ])
            ->sortBy(['group', 'name'])
            ->values();
    }

    public function generate(User $user, array $input): WorkoutGeneration
    {
        $limit = (int) config('services.workout_ai.daily_limit', 5);
        if ($this->usedToday($user) >= $limit) {
            throw ValidationException::withMessages(['request' => "Você já montou {$limit} treinos com IA hoje. Tente de novo amanhã."]);
        }

        $catalog = $this->catalog($input['place'], $input['level']);
        $generation = new WorkoutGeneration(['status' => 'pending', 'request' => $input, 'provider' => config('services.meal_ai.provider')]);
        $generation->user_id = $user->id;
        $generation->save();

        try {
            $answer = $this->ai->ask($this->systemPrompt($catalog), $this->userPrompt($user, $input), self::schema(), 2500, 100);
        } catch (MealAiException $e) {
            Log::warning('Treino com IA falhou', ['generation' => $generation->id, 'error' => $e->getMessage()]);
            $generation->update(['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 500)]);
            throw ValidationException::withMessages(['request' => $e->userMessage]);
        }

        $plan = $this->clean($answer['data'], $catalog->pluck('id')->flip(), (int) $input['days']);
        if (! $plan['days']) {
            $generation->update(['status' => 'failed', 'error_message' => 'Resposta sem exercícios válidos', 'usage' => $answer['usage']]);
            throw ValidationException::withMessages(['request' => 'Não foi possível montar o treino agora. Tente descrever de outro jeito.']);
        }

        $generation->update(['status' => 'completed', 'model' => mb_substr($answer['model'], 0, 80), 'result' => $plan, 'usage' => $answer['usage']]);

        return $generation;
    }

    /**
     * Regras + catálogo ficam no início e se repetem entre pedidos com o mesmo filtro:
     * a OpenAI reaproveita esse trecho (cache de prompt), o que barateia os pedidos seguintes.
     */
    private function systemPrompt(Collection $catalog): string
    {
        $lines = $catalog->groupBy('group')
            ->map(fn ($items, $group) => "## {$group}\n".$items->map(fn ($e) => "{$e['id']} {$e['name']}")->implode("\n"))
            ->implode("\n");

        return <<<TXT
        Você é um personal trainer brasileiro. Monte um plano de musculação semanal.
        Regras:
        - Use SOMENTE exercícios do catálogo abaixo, pelo número (i). Nunca invente exercício.
        - Um treino (dia) por dia de treino pedido; nomeie cada um pelo foco (ex.: "Peito e tríceps", "Pernas e glúteos", "Corpo inteiro").
        - Divisão: 2 dias = corpo inteiro ou AB; 3 = ABC ou corpo inteiro; 4+ = divisão por grupos. Equilibre os grupos na semana.
        - Comece cada treino pelos exercícios multiarticulares e termine pelos isolados.
        - Respeite limitações e dores: evite exercícios que sobrecarreguem a região citada e prefira alternativas seguras.
        - Iniciante: mais máquinas e exercícios básicos, 2 a 3 séries. Intermediário/avançado: 3 a 4 séries.
        - Hipertrofia: 8–12 repetições, descanso 60–90 s (básicos pesados 90–120 s). Emagrecimento: 12–15 repetições, descanso 30–60 s,
          pode incluir pliometria/cardio no fim. Manutenção: 10–12 repetições.
        - Exercícios isométricos ou de tempo (prancha): use reps como segundos.
        - p = nome curto do plano; o = observação geral curta (aquecimento, progressão), até 200 caracteres.
        - Em cada exercício: s séries, a e b repetições mínima e máxima, r descanso em segundos, n dica curta (até 80 caracteres) ou "".

        Catálogo (número nome):
        {$lines}
        TXT;
    }

    private function userPrompt(User $user, array $input): string
    {
        $profile = array_filter([
            'objetivo' => self::GOALS[$input['goal']] ?? null,
            'nível' => self::LEVELS[$input['level']] ?? null,
            'sexo' => $user->sex?->value === 'female' ? 'feminino' : ($user->sex?->value === 'male' ? 'masculino' : null),
            'idade' => $user->age,
        ]);

        return "Perfil: ".collect($profile)->map(fn ($v, $k) => "{$k} {$v}")->implode(', ').".\n"
            ."Dias de treino por semana: {$input['days']}. Tempo por treino: {$input['minutes']} minutos "
            .'('.self::EXERCISES_FOR[$input['minutes']].' exercícios por treino).'."\n"
            .'Local: '.self::PLACES[$input['place']]['label'].".\n"
            .'Pedido da pessoa: '.(trim($input['request'] ?? '') ?: 'nenhum detalhe extra').'.';
    }

    /** Esquema curto (menos tokens de saída). */
    public static function schema(): array
    {
        $int = ['type' => 'integer'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['p', 'o', 'd'],
            'properties' => [
                'p' => ['type' => 'string'],
                'o' => ['type' => 'string'],
                'd' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['n', 'e'],
                    'properties' => [
                        'n' => ['type' => 'string'],
                        'e' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['i', 's', 'a', 'b', 'r', 'n'],
                            'properties' => ['i' => $int, 's' => $int, 'a' => $int, 'b' => $int, 'r' => $int, 'n' => ['type' => 'string']],
                        ]],
                    ],
                ]],
            ],
        ];
    }

    /**
     * Valida a resposta: só exercícios do catálogo, sem repetir no mesmo dia, números em faixas seguras.
     *
     * @return array{name: string, notes: string, days: list<array{name: string, exercises: list<array>}>}
     */
    private function clean(array $data, Collection $allowedIds, int $daysWanted): array
    {
        $days = collect($data['d'] ?? [])
            ->filter(fn ($d) => is_array($d))
            ->take(min(7, max(1, $daysWanted)))
            ->map(function (array $d, int $i) use ($allowedIds) {
                $exercises = collect($d['e'] ?? [])
                    ->filter(fn ($e) => is_array($e) && isset($e['i']) && $allowedIds->has((int) $e['i']))
                    ->unique(fn ($e) => (int) $e['i'])
                    ->take(12)
                    ->map(function (array $e) {
                        $min = self::clamp($e['a'] ?? 8, 1, 60);
                        $max = max($min, self::clamp($e['b'] ?? $min, 1, 60));

                        return [
                            'exercise_id' => (int) $e['i'],
                            'sets' => self::clamp($e['s'] ?? 3, 1, 6),
                            'reps_min' => $min,
                            'reps_max' => $max,
                            'rest_seconds' => self::clamp($e['r'] ?? 90, 15, 300),
                            'notes' => mb_substr(trim((string) ($e['n'] ?? '')), 0, 120) ?: null,
                        ];
                    })
                    ->values()
                    ->all();

                return ['name' => mb_substr(trim((string) ($d['n'] ?? '')), 0, 60) ?: 'Treino '.chr(65 + $i), 'exercises' => $exercises];
            })
            ->filter(fn ($d) => $d['exercises'])
            ->values()
            ->all();

        return [
            'name' => mb_substr(trim((string) ($data['p'] ?? '')), 0, 80) ?: 'Treino montado pela IA',
            'notes' => mb_substr(trim((string) ($data['o'] ?? '')), 0, 300),
            'days' => $days,
        ];
    }

    private static function clamp(mixed $v, int $min, int $max): int
    {
        return (int) min($max, max($min, is_numeric($v) ? (int) round((float) $v) : $min));
    }

    /** Monta a prévia com nome, foto e grupos de cada exercício. */
    public static function present(WorkoutGeneration $generation): array
    {
        $plan = $generation->result;
        $ids = collect($plan['days'])->flatMap(fn ($d) => array_column($d['exercises'], 'exercise_id'))->unique();
        $info = Exercise::with(['media', 'muscles' => fn ($q) => $q->wherePivot('role', 'primary')->with('group')])
            ->whereIn('id', $ids)->get()->keyBy('id');

        return [
            'id' => $generation->id,
            'name' => $plan['name'],
            'notes' => $plan['notes'],
            'days' => collect($plan['days'])->map(fn ($d, $i) => [
                'label' => chr(65 + $i),
                'name' => $d['name'],
                'exercises' => collect($d['exercises'])->map(fn ($e) => [
                    ...$e,
                    'name' => $info[$e['exercise_id']]?->displayName(),
                    'image' => $info[$e['exercise_id']]?->media->first()?->url,
                    'groups' => $info[$e['exercise_id']]?->muscles->pluck('group.name_pt')->filter()->unique()->values() ?? [],
                ])->values(),
            ])->values(),
        ];
    }

    /**
     * Salva o plano revisado pela pessoa (pode ter trocado/removido exercícios e ajustado números).
     *
     * @param  array{name: string, days: list<array{name: string, exercises: list<array>}>}  $plan
     */
    public function save(User $user, WorkoutGeneration $generation, array $plan): WorkoutPlan
    {
        if (! in_array($generation->status, ['completed', 'saved'], true)) {
            throw ValidationException::withMessages(['plan' => 'Este treino não pode mais ser salvo.']);
        }
        $visible = Exercise::query()->visibleTo($user)
            ->whereIn('id', collect($plan['days'])->flatMap(fn ($d) => array_column($d['exercises'], 'exercise_id')))
            ->get()->keyBy('id');

        return DB::transaction(function () use ($user, $generation, $plan, $visible) {
            $splits = [1 => 'a', 2 => 'ab', 3 => 'abc', 4 => 'abcd', 5 => 'abcde'];
            $model = new WorkoutPlan([
                'name' => mb_substr($plan['name'], 0, 120),
                'split_type' => $splits[count($plan['days'])] ?? 'custom',
                'active' => true,
                'notes' => ($generation->result['notes'] ?? null) ?: null,
            ]);
            $model->user_id = $user->id;
            $model->save();

            foreach (array_values($plan['days']) as $i => $day) {
                $workoutDay = $this->workouts->createDay($model, chr(65 + $i), $i, mb_substr($day['name'], 0, 120));
                foreach ($day['exercises'] as $e) {
                    $exercise = $visible->get($e['exercise_id']);
                    if (! $exercise) {
                        continue;
                    }
                    $item = $this->workouts->addExercise($workoutDay, $exercise, [
                        'sets' => $e['sets'], 'reps_min' => $e['reps_min'], 'reps_max' => $e['reps_max'], 'rest_seconds' => $e['rest_seconds'],
                    ]);
                    if (! empty($e['notes'])) {
                        $item->notes = mb_substr($e['notes'], 0, 500);
                        $item->save();
                    }
                }
            }
            $generation->update(['status' => 'saved', 'plan_id' => $model->id]);

            return $model;
        });
    }
}
