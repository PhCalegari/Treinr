<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Medida caseira de um alimento ("concha" = 140 g).
 *
 * @property int $id
 * @property int $food_id
 * @property string $label
 * @property float $grams
 * @property string $source
 * @property int $position
 */
class FoodPortion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['grams' => 'float'];
    }
}
