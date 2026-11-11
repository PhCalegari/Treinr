<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Meta nutricional diária, com histórico: vale a partir de valid_from até a próxima meta.
 *
 * @property Carbon $valid_from
 * @property int $kcal
 * @property int $protein_g
 * @property int $carbs_g
 * @property int $fat_g
 * @property int|null $bmr_estimate
 * @property int|null $tdee_estimate
 * @property float|null $activity_factor
 * @property string|null $formula
 * @property bool $manual_override
 */
class NutritionGoal extends Model
{
    use Syncable;

    protected $guarded = ['id', 'user_id', 'sync_version'];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date:Y-m-d',
            'kcal' => 'integer',
            'protein_g' => 'integer',
            'carbs_g' => 'integer',
            'fat_g' => 'integer',
            'bmr_estimate' => 'integer',
            'tdee_estimate' => 'integer',
            'activity_factor' => 'float',
            'manual_override' => 'boolean',
        ];
    }

    /** Meta vigente numa data: a de maior valid_from <= data (empate: a alterada por último). */
    public static function currentFor(int $userId, string $date): ?self
    {
        return self::ownedBy($userId)
            ->whereDate('valid_from', '<=', $date)
            ->orderByDesc('valid_from')
            ->orderByDesc('modified_at')
            ->first();
    }

    /** Metas que podem valer em algum dia do intervalo (para históricos). */
    public function scopeRelevantFor(Builder $q, int $userId, string $to): Builder
    {
        return $q->ownedBy($userId)->whereDate('valid_from', '<=', $to)->orderBy('valid_from')->orderBy('modified_at');
    }
}
