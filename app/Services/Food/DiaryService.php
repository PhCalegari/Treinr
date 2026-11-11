<?php

namespace App\Services\Food;

use App\Models\Food;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Diário alimentar: registrar, corrigir e somar o que foi consumido. */
class DiaryService
{
    /** Valores para uma quantidade, a partir dos valores por 100 g/ml. */
    public static function forQuantity(array $per100, float $quantity): array
    {
        $f = $quantity / 100;

        return [
            'kcal' => round($per100['kcal'] * $f, 2),
            'protein_g' => round(($per100['protein'] ?? 0) * $f, 2),
            'carbs_g' => round(($per100['carbs'] ?? 0) * $f, 2),
            'fat_g' => round(($per100['fat'] ?? 0) * $f, 2),
        ];
    }

    /** Busca (ou cria) a refeição do dia, reaproveitando uma refeição excluída antes. */
    public function mealFor(User $user, string $date, string $type): Meal
    {
        $id = Meal::idFor($user->id, $date, $type);
        $meal = Meal::withoutGlobalScope('not_deleted')->find($id);

        if (! $meal) {
            $meal = new Meal(['meal_date' => $date, 'meal_type' => $type]);
            $meal->id = $id;
            $meal->user_id = $user->id;
            $meal->save();
        } elseif ($meal->deleted_at) {
            $meal->deleted_at = null;
            $meal->save();
        }

        return $meal;
    }

    /**
     * Adiciona um item. Duas formas:
     * - de um alimento do catálogo (food_id): valores calculados pela quantidade;
     * - manual: o usuário informa os valores totais da porção consumida.
     */
    public function addItem(User $user, array $data): MealItem
    {
        return DB::transaction(function () use ($user, $data) {
            $meal = $this->mealFor($user, $data['meal_date'], $data['meal_type']);
            $quantity = (float) $data['quantity'];

            if (! empty($data['food_id'])) {
                $food = Food::query()->visibleTo($user)->findOrFail($data['food_id']);
                $values = self::forQuantity([
                    'kcal' => $food->kcal_per_100,
                    'protein' => $food->protein_per_100,
                    'carbs' => $food->carbs_per_100,
                    'fat' => $food->fat_per_100,
                ], $quantity);
                $name = $food->brand ? "{$food->name} ({$food->brand})" : $food->name;
                $unit = $food->unit;
            } else {
                $values = [
                    'kcal' => (float) $data['kcal'],
                    'protein_g' => (float) ($data['protein_g'] ?? 0),
                    'carbs_g' => (float) ($data['carbs_g'] ?? 0),
                    'fat_g' => (float) ($data['fat_g'] ?? 0),
                ];
                $name = $data['food_name'];
                $unit = $data['unit'] ?? 'g';
                $food = null;
            }

            $item = new MealItem([
                'meal_id' => $meal->id,
                'food_id' => $food?->id,
                'food_name' => mb_substr($name, 0, 255),
                'quantity' => $quantity,
                'unit' => $unit,
                // Medida caseira usada (só exibição): "2 × concha".
                'portion_label' => $data['portion_label'] ?? null,
                'portion_count' => isset($data['portion_label']) ? ($data['portion_count'] ?? 1) : null,
                'origin' => $data['origin'] ?? ($food ? 'search' : 'manual'),
                'photo_analysis_id' => $data['photo_analysis_id'] ?? null,
                ...$values,
            ]);
            $item->user_id = $user->id;
            $item->save();

            return $item;
        });
    }

    /** Muda a quantidade: valores são recalculados na mesma proporção. */
    public function changeQuantity(MealItem $item, float $quantity, ?string $portionLabel = null, ?float $portionCount = null): MealItem
    {
        $ratio = $item->quantity > 0 ? $quantity / $item->quantity : 0;
        foreach (['kcal', 'protein_g', 'carbs_g', 'fat_g'] as $field) {
            $item->{$field} = round($item->{$field} * $ratio, 2);
        }
        $item->quantity = $quantity;
        // Gramas digitados direto: a medida caseira anterior deixa de valer.
        $item->portion_label = $portionLabel;
        $item->portion_count = $portionLabel ? ($portionCount ?? 1) : null;
        $item->save();

        return $item;
    }

    /** Move o item para outra refeição (outro tipo e/ou dia). */
    public function moveItem(MealItem $item, User $user, string $date, string $type): MealItem
    {
        $item->meal_id = $this->mealFor($user, $date, $type)->id;
        $item->save();

        return $item;
    }

    /** Totais de uma lista de itens. */
    public static function totals(iterable $items): array
    {
        $t = ['kcal' => 0.0, 'protein_g' => 0.0, 'carbs_g' => 0.0, 'fat_g' => 0.0];
        foreach ($items as $i) {
            foreach ($t as $k => $_) {
                $t[$k] += (float) $i->{$k};
            }
        }

        return array_map(fn ($v) => round($v, 1), $t);
    }

    /**
     * Alimentos usados recentemente (para adicionar de novo com 1 toque),
     * com valores por 100 derivados do último registro.
     */
    public function recentFoods(User $user, int $limit = 20): array
    {
        return MealItem::ownedBy($user->id)
            ->with('food.portions')
            ->where('quantity', '>', 0)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->unique(fn ($i) => mb_strtolower($i->food_name))
            ->take($limit)
            ->map(fn (MealItem $i) => [
                'food_id' => $i->food_id,
                'source' => 'recent',
                'code' => null,
                'name' => $i->food_name,
                'brand' => null,
                'unit' => $i->unit,
                'last_quantity' => $i->quantity,
                'last_portion' => $i->portion_label ? ['label' => $i->portion_label, 'count' => $i->portion_count] : null,
                'portions' => $i->food?->portions->map(fn ($p) => ['label' => $p->label, 'grams' => $p->grams, 'source' => $p->source])->values()->all() ?? [],
                'per_100' => [
                    'kcal' => round($i->kcal * 100 / $i->quantity, 2),
                    'protein' => round($i->protein_g * 100 / $i->quantity, 2),
                    'carbs' => round($i->carbs_g * 100 / $i->quantity, 2),
                    'fat' => round($i->fat_g * 100 / $i->quantity, 2),
                ],
            ])
            ->values()
            ->all();
    }
}
