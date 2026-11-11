<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um dia do plano (treino A, B...).
 *
 * @property string $plan_id
 * @property string $label
 * @property string|null $name
 * @property int $position
 */
class WorkoutDay extends Model
{
    use Syncable;

    protected $guarded = ['id', 'user_id', 'sync_version'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WorkoutPlan::class, 'plan_id');
    }

    public function exercises(): HasMany
    {
        return $this->hasMany(WorkoutExercise::class)->orderBy('position');
    }

    /** "Treino A — Peito e tríceps" */
    public function title(): string
    {
        return 'Treino '.$this->label.($this->name ? ' — '.$this->name : '');
    }
}
