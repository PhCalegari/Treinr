<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Série executada: repetições e carga reais.
 *
 * @property string $session_exercise_id
 * @property int $set_number
 * @property int|null $reps
 * @property string|null $load_kg
 * @property int|null $duration_sec
 * @property bool $completed
 * @property Carbon|null $completed_at
 */
class SessionSet extends Model
{
    use Syncable;

    protected $guarded = ['id', 'user_id', 'sync_version'];

    protected function casts(): array
    {
        return ['completed' => 'boolean', 'completed_at' => 'datetime', 'load_kg' => 'decimal:2', 'duration_sec' => 'integer', 'distance_km' => 'float'];
    }

    public function sessionExercise(): BelongsTo
    {
        return $this->belongsTo(SessionExercise::class);
    }
}
