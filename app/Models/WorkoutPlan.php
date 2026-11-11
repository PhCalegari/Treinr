<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plano de treino (ex.: "ABC hipertrofia"), com seus dias A, B, C...
 *
 * @property string $name
 * @property string $split_type
 * @property bool $active
 * @property string|null $notes
 */
class WorkoutPlan extends Model
{
    use Syncable;

    public const SPLITS = ['a', 'ab', 'abc', 'abcd', 'abcde', 'custom'];

    protected $guarded = ['id', 'user_id', 'sync_version'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function days(): HasMany
    {
        return $this->hasMany(WorkoutDay::class, 'plan_id')->orderBy('position');
    }
}
