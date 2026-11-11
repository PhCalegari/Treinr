<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exercício planejado em um dia do treino, com séries, repetições, carga e descanso.
 *
 * @property string $workout_day_id
 * @property int $exercise_id
 * @property int $position
 * @property int $sets
 * @property int|null $reps_min
 * @property int|null $reps_max
 * @property string|null $target_load_kg
 * @property int|null $rest_seconds
 * @property string|null $notes
 */
class WorkoutExercise extends Model
{
    use Syncable;

    protected $guarded = ['id', 'user_id', 'sync_version'];

    protected function casts(): array
    {
        return ['target_load_kg' => 'decimal:2'];
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(WorkoutDay::class, 'workout_day_id');
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }
}
