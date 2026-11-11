<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Uma execução real de treino.
 *
 * @property string|null $workout_day_id
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property string|null $notes
 */
class TrainingSession extends Model
{
    use Syncable;

    protected $guarded = ['id', 'user_id', 'sync_version'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(WorkoutDay::class, 'workout_day_id');
    }

    public function exercises(): HasMany
    {
        return $this->hasMany(SessionExercise::class, 'session_id')->orderBy('position');
    }

    public function isFinished(): bool
    {
        return $this->finished_at !== null;
    }
}
