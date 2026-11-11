<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $session_id
 * @property int $exercise_id
 * @property string|null $workout_exercise_id
 * @property int $position
 * @property bool $completed
 */
class SessionExercise extends Model
{
    use Syncable;

    protected $guarded = ['id', 'user_id', 'sync_version'];

    protected function casts(): array
    {
        return ['completed' => 'boolean'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'session_id');
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function planned(): BelongsTo
    {
        return $this->belongsTo(WorkoutExercise::class, 'workout_exercise_id');
    }

    public function sets(): HasMany
    {
        return $this->hasMany(SessionSet::class)->orderBy('set_number');
    }
}
