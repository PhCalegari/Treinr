<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alimento consumido. Os valores nutricionais são do consumo (já calculados
 * para a quantidade), copiados no momento do registro.
 *
 * @property string $meal_id
 * @property int|null $food_id
 * @property string $food_name
 * @property float $quantity
 * @property string $unit
 * @property string|null $portion_label
 * @property float|null $portion_count
 * @property float $kcal
 * @property float $protein_g
 * @property float $carbs_g
 * @property float $fat_g
 * @property string $origin
 */
class MealItem extends Model
{
    use Syncable;

    public const ORIGINS = ['manual', 'search', 'barcode', 'photo', 'voice'];

    protected $guarded = ['id', 'user_id', 'sync_version'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'kcal' => 'float',
            'protein_g' => 'float',
            'carbs_g' => 'float',
            'fat_g' => 'float',
            'portion_count' => 'float',
        ];
    }

    public function meal(): BelongsTo
    {
        return $this->belongsTo(Meal::class);
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }
}
