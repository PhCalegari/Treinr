<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $name_pt
 * @property int $category_id
 * @property string|null $level
 * @property string|null $mechanic
 * @property string|null $force_type
 * @property string|null $instructions
 * @property string|null $instructions_pt
 * @property int|null $suggested_sets
 * @property int|null $suggested_reps_min
 * @property int|null $suggested_reps_max
 * @property int|null $suggested_duration_sec
 * @property string $source
 * @property string|null $external_id
 * @property string|null $license
 * @property int|null $created_by_user_id
 * @property bool $active
 */
class Exercise extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExerciseCategory::class, 'category_id');
    }

    public function muscles(): BelongsToMany
    {
        return $this->belongsToMany(Muscle::class, 'exercise_muscles')->withPivot('role');
    }

    public function equipment(): BelongsToMany
    {
        return $this->belongsToMany(Equipment::class, 'exercise_equipment');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ExerciseMedia::class)->orderBy('position');
    }

    /** Exercícios ativos da biblioteca oficial + os criados pelo próprio usuário. */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        return $query->where('active', true)->where(function (Builder $q) use ($user) {
            $q->whereNull('created_by_user_id');
            if ($user) {
                $q->orWhere('created_by_user_id', $user->id);
            }
        });
    }

    /**
     * Como o exercício é registrado no treino:
     *   strength = carga e repetições; cardio = tempo e distância; time = só tempo (alongamento).
     */
    public function trackingKind(): string
    {
        return match ($this->category?->code) {
            'cardio' => 'cardio',
            'stretching' => 'time',
            default => 'strength',
        };
    }

    /** Nome exibido: tradução quando existir, senão o original. */
    public function displayName(): string
    {
        return $this->name_pt ?: $this->name;
    }
}
