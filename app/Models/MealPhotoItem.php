<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alimento que a IA identificou na foto. est_* = estimativa da própria IA para a porção;
 * matched_food_id = alimento da TACO correspondente (quando encontrado, os valores vêm dele).
 *
 * @property string $detected_name
 * @property float $estimated_grams
 * @property float|null $confidence
 * @property int|null $matched_food_id
 */
class MealPhotoItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'estimated_grams' => 'float',
            'confidence' => 'float',
            'est_kcal' => 'float',
            'est_protein_g' => 'float',
            'est_carbs_g' => 'float',
            'est_fat_g' => 'float',
            'portion_count' => 'float',
        ];
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class, 'matched_food_id');
    }
}
