<?php

namespace App\Services\Nutrition;

use App\Enums\Goal;
use App\Enums\Sex;
use App\Models\MealItem;
use App\Models\NutritionGoal;
use App\Models\ProgressEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoalService
{
    /** O % de gordura só entra na conta se for recente. */
    private const BODY_FAT_MAX_AGE_DAYS = 180;

    /**
     * Dados usados na estimativa e o que falta preencher.
     *
     * @return array{sex: ?string, age: ?int, height_cm: ?float, weight_kg: ?float, weight_date: ?string, body_fat_pct: ?float, body_fat_date: ?string, missing: list<string>}
     */
    public function inputs(User $user, ?string $date = null): array
    {
        $date ??= now()->toDateString();
        $weight = ProgressEntry::latestWith($user->id, 'weight_kg', $date);
        $fat = ProgressEntry::latestWith($user->id, 'body_fat_pct', $date);
        if ($fat && Carbon::parse($fat->entry_date)->diffInDays(Carbon::parse($date)) > self::BODY_FAT_MAX_AGE_DAYS) {
            $fat = null;
        }

        $inputs = [
            'sex' => $user->sex?->value,
            'age' => $user->birth_date ? (int) $user->birth_date->diffInYears(Carbon::parse($date)) : null,
            'height_cm' => $user->height_cm !== null ? (float) $user->height_cm : null,
            'weight_kg' => $weight?->weight_kg,
            'weight_date' => $weight?->entry_date?->toDateString(),
            'body_fat_pct' => $fat?->body_fat_pct,
            'body_fat_date' => $fat?->entry_date?->toDateString(),
        ];
        $inputs['missing'] = array_values(array_keys(array_filter(
            ['sex' => $inputs['sex'], 'age' => $inputs['age'], 'height_cm' => $inputs['height_cm'], 'weight_kg' => $inputs['weight_kg']],
            fn ($v) => $v === null,
        )));

        return $inputs;
    }

    /**
     * Estimativa para cada objetivo e nível de atividade (a tela troca sem ir ao servidor).
     *
     * @return array<string, array<string, array>>|null null quando faltam dados
     */
    public function estimates(array $inputs): ?array
    {
        if ($inputs['missing']) {
            return null;
        }
        $result = [];
        foreach (Goal::cases() as $goal) {
            foreach (EnergyCalculator::ACTIVITY_LEVELS as $level => $info) {
                $result[$goal->value][$level] = EnergyCalculator::estimate(
                    Sex::from($inputs['sex']), $inputs['weight_kg'], $inputs['height_cm'], $inputs['age'],
                    $info['factor'], $goal, $inputs['body_fat_pct'],
                );
            }
        }

        return $result;
    }

    /**
     * Salva a meta. Sem ajuste manual, os valores vêm da estimativa calculada aqui no servidor.
     * Uma meta por data de início: salvar de novo na mesma data substitui.
     */
    public function save(User $user, array $data): NutritionGoal
    {
        $goal = Goal::from($data['goal']);
        $level = $data['activity_level'];
        $validFrom = $data['valid_from'] ?? now()->toDateString();

        $estimate = ($all = $this->estimates($this->inputs($user, $validFrom))) ? $all[$goal->value][$level] : null;
        $manual = (bool) ($data['manual'] ?? false);
        if (! $manual && ! $estimate) {
            throw ValidationException::withMessages(['goal' => 'Complete o perfil e registre o peso para calcular a meta, ou informe os valores manualmente.']);
        }

        $values = $manual
            ? ['kcal' => (int) $data['kcal'], 'protein_g' => (int) $data['protein_g'], 'carbs_g' => (int) $data['carbs_g'], 'fat_g' => (int) $data['fat_g']]
            : array_intersect_key($estimate, array_flip(['kcal', 'protein_g', 'carbs_g', 'fat_g']));

        return DB::transaction(function () use ($user, $goal, $level, $validFrom, $estimate, $manual, $values) {
            $user->forceFill(['goal' => $goal])->save();

            $record = NutritionGoal::ownedBy($user->id)->whereDate('valid_from', $validFrom)->first()
                ?? new NutritionGoal(['valid_from' => $validFrom]);
            $record->user_id = $user->id;
            $record->fill([
                ...$values,
                'bmr_estimate' => $estimate['bmr'] ?? null,
                'tdee_estimate' => $estimate['tdee'] ?? null,
                'activity_factor' => EnergyCalculator::activityFactor($level),
                'formula' => $estimate['formula'] ?? null,
                'manual_override' => $manual,
            ])->save();

            return $record;
        });
    }

    public static function present(?NutritionGoal $goal): ?array
    {
        if (! $goal) {
            return null;
        }

        return [
            'id' => $goal->id,
            'valid_from' => $goal->valid_from->toDateString(),
            'kcal' => $goal->kcal,
            'protein_g' => $goal->protein_g,
            'carbs_g' => $goal->carbs_g,
            'fat_g' => $goal->fat_g,
            'bmr_estimate' => $goal->bmr_estimate,
            'tdee_estimate' => $goal->tdee_estimate,
            'activity_level' => EnergyCalculator::levelFor($goal->activity_factor),
            'formula' => $goal->formula,
            'manual_override' => $goal->manual_override,
        ];
    }

    /**
     * Consumo de cada dia do intervalo e a meta que valia naquele dia.
     *
     * @return list<array{date: string, kcal: float, protein_g: float, carbs_g: float, fat_g: float, logged: bool, goal_kcal: ?int}>
     */
    public function dailyHistory(User $user, string $from, string $to): array
    {
        $rows = MealItem::ownedBy($user->id)
            ->join('meals', 'meals.id', '=', 'meal_items.meal_id')
            ->whereNull('meals.deleted_at')
            ->whereDate('meals.meal_date', '>=', $from)
            ->whereDate('meals.meal_date', '<=', $to)
            ->groupByRaw('date(meals.meal_date)')
            ->selectRaw('date(meals.meal_date) as day, sum(meal_items.kcal) as kcal, sum(meal_items.protein_g) as protein_g, sum(meal_items.carbs_g) as carbs_g, sum(meal_items.fat_g) as fat_g')
            ->get()
            ->keyBy(fn ($r) => (string) $r->day);

        $goals = NutritionGoal::query()->relevantFor($user->id, $to)->get();

        $days = [];
        for ($d = Carbon::parse($from); $d->lte(Carbon::parse($to)); $d->addDay()) {
            $key = $d->toDateString();
            $row = $rows->get($key);
            // Metas em ordem crescente: a última que já começou é a vigente.
            $goal = $goals->last(fn (NutritionGoal $g) => $g->valid_from->toDateString() <= $key);
            $days[] = [
                'date' => $key,
                'kcal' => round((float) ($row->kcal ?? 0)),
                'protein_g' => round((float) ($row->protein_g ?? 0), 1),
                'carbs_g' => round((float) ($row->carbs_g ?? 0), 1),
                'fat_g' => round((float) ($row->fat_g ?? 0), 1),
                'logged' => $row !== null,
                'goal_kcal' => $goal?->kcal,
            ];
        }

        return $days;
    }

    /**
     * Médias por semana (segunda a domingo), contando só os dias com registro.
     *
     * @return list<array{week_start: string, days_logged: int, avg_kcal: ?int, avg_protein_g: ?float, avg_goal_kcal: ?int}>
     */
    public static function weekly(array $days): array
    {
        return collect($days)
            ->groupBy(fn ($d) => Carbon::parse($d['date'])->startOfWeek(Carbon::MONDAY)->toDateString())
            ->map(function ($week, $start) {
                $logged = $week->where('logged', true);
                $goals = $logged->pluck('goal_kcal')->filter();

                return [
                    'week_start' => $start,
                    'days_logged' => $logged->count(),
                    'avg_kcal' => $logged->count() ? (int) round($logged->avg('kcal')) : null,
                    'avg_protein_g' => $logged->count() ? round($logged->avg('protein_g'), 1) : null,
                    'avg_goal_kcal' => $goals->count() ? (int) round($goals->avg()) : null,
                ];
            })
            ->values()
            ->all();
    }
}
